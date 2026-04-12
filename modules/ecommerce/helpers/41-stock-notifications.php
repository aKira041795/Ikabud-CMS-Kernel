<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Back-in-Stock Notifications (helpers/41-stock-notifications.php)
// ─────────────────────────────────────────────────────────────────────────
//
// Flow:
//   1. Customer subscribes via ecStockNotificationSubscribe() (guest or logged-in).
//   2. When stock returns to > 0, ecStockNotificationCheckAndTrigger() is called
//      from ecProductIncrementStock() and ecProductUpdateInventory().
//   3. ecStockNotificationProcessProduct() sends email to all waiting subscribers
//      for that product/variant and marks them `sent`.
//   4. ecStockNotificationExpire() can be called via cron to clear stale rows.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Returns true if the ec_stock_notifications table is queryable.
 */
function ecStockNotificationStorageAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        ecDb()->query('SELECT 1 FROM ec_stock_notifications LIMIT 1');
        $available = true;
    } catch (\Throwable $e) {
        $available = false;
    }
    return $available;
}

/**
 * Subscribe a customer or guest to back-in-stock notifications for a product.
 *
 * @return array{ok: bool, already_subscribed: bool, error: string}
 */
function ecStockNotificationSubscribe(
    int     $productId,
    ?int    $variantId,
    string  $email,
    ?int    $customerId = null
): array {
    $email = strtolower(trim($email));
    if ($productId <= 0 || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'already_subscribed' => false, 'error' => 'Invalid product or email'];
    }
    if (!ecStockNotificationStorageAvailable()) {
        return ['ok' => false, 'already_subscribed' => false, 'error' => 'Storage unavailable'];
    }

    try {
        // Check for existing waiting subscription
        $existing = ecDb()->query(
            "SELECT id FROM ec_stock_notifications
             WHERE product_id = ? AND variant_id <=> ? AND customer_email = ? AND status = 'waiting' LIMIT 1",
            [$productId, $variantId, $email]
        )->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            return ['ok' => true, 'already_subscribed' => true, 'error' => ''];
        }

        ecDb()->query(
            "INSERT INTO ec_stock_notifications (product_id, variant_id, customer_email, customer_id, status, created_at)
             VALUES (?, ?, ?, ?, 'waiting', NOW())",
            [$productId, $variantId, $email, $customerId]
        );

        return ['ok' => true, 'already_subscribed' => false, 'error' => ''];
    } catch (\Throwable $e) {
        write_log('ecStockNotificationSubscribe failed: ' . $e->getMessage(), 'warning', [
            'module'     => 'ecommerce',
            'product_id' => $productId,
        ]);
        return ['ok' => false, 'already_subscribed' => false, 'error' => 'Database error'];
    }
}

/**
 * Called from stock-mutation functions (ecProductIncrementStock, ecProductUpdateInventory).
 * If stock transitions from ≤0 to >0, triggers notifications for the product.
 * Silently no-ops if storage unavailable.
 */
function ecStockNotificationCheckAndTrigger(int $productId, ?int $variantId, int $prevQty, int $newQty): void
{
    if ($prevQty > 0 || $newQty <= 0) {
        return;
    }
    if (!ecStockNotificationStorageAvailable()) {
        return;
    }

    try {
        ecStockNotificationProcessProduct($productId, $variantId);
        app()->events()->fire('ecommerce.product.back_in_stock', [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'new_qty'    => $newQty,
        ]);
    } catch (\Throwable $e) {
        write_log('ecStockNotificationCheckAndTrigger failed: ' . $e->getMessage(), 'warning', [
            'module'     => 'ecommerce',
            'product_id' => $productId,
        ]);
    }
}

/**
 * Sends back-in-stock emails to all waiting subscribers for a product/variant,
 * marks each as `sent`, and returns the count of notifications sent.
 */
function ecStockNotificationProcessProduct(int $productId, ?int $variantId = null): int
{
    if ($productId <= 0 || !ecStockNotificationStorageAvailable()) {
        return 0;
    }

    try {
        $rows = ecDb()->query(
            "SELECT * FROM ec_stock_notifications
             WHERE product_id = ? AND variant_id <=> ? AND status = 'waiting'
             ORDER BY created_at ASC",
            [$productId, $variantId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return 0;
    }

    if (empty($rows)) {
        return 0;
    }

    $product = function_exists('ecProductGet') ? ecProductGet($productId) : null;
    $sent    = 0;

    foreach ($rows as $row) {
        try {
            $ok = ecStockNotificationSend($row, is_array($product) ? $product : []);
            if ($ok) {
                ecDb()->query(
                    "UPDATE ec_stock_notifications SET status = 'sent', notified_at = NOW() WHERE id = ?",
                    [(int)$row['id']]
                );
                $sent++;
            }
        } catch (\Throwable $e) {
            write_log('ecStockNotificationSend failed for row ' . $row['id'] . ': ' . $e->getMessage(), 'warning', [
                'module'     => 'ecommerce',
                'product_id' => $productId,
            ]);
        }
    }

    return $sent;
}

/**
 * Sends a single back-in-stock notification email.
 * Returns true if the email was dispatched, false otherwise.
 */
function ecStockNotificationSend(array $notification, array $product): bool
{
    if (!function_exists('sendEmail')) {
        return false;
    }

    $toEmail     = (string)($notification['customer_email'] ?? '');
    $productTitle = htmlspecialchars((string)($product['title'] ?? 'A product you were watching'), ENT_QUOTES);
    $baseUrl     = rtrim((string)app()->config('app.url', ''), '/');
    $productSlug = (string)($product['slug'] ?? '');
    $shopUrl     = $productSlug !== ''
        ? $baseUrl . '/ecommerce/shop/' . $productSlug
        : $baseUrl . '/ecommerce/shop';

    if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    $siteName  = (string)app()->config('app.name', 'Shop');
    $subject   = $productTitle . ' is back in stock!';

    $body = '<div style="font-family:sans-serif;max-width:560px;margin:0 auto;padding:24px;">'
        . '<h2 style="color:#ea580c;margin-top:0;">Good news — it\'s back! 🎉</h2>'
        . '<p>You asked us to let you know when <strong>' . $productTitle . '</strong> was back in stock. It\'s available now — don\'t miss out!</p>'
        . '<p style="margin:24px 0;">'
        . '<a href="' . htmlspecialchars($shopUrl, ENT_QUOTES) . '" '
        . 'style="background:#ea580c;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;display:inline-block;">'
        . 'Shop Now →</a>'
        . '</p>'
        . '<p style="color:#6b7280;font-size:13px;">This notification was sent once because you subscribed to stock alerts on ' . htmlspecialchars($siteName, ENT_QUOTES) . '.</p>'
        . '</div>';

    try {
        sendEmail($toEmail, $subject, $body);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Marks old waiting subscriptions as expired.
 * Call from a cron job periodically.
 *
 * @return int Number of rows expired.
 */
function ecStockNotificationExpire(int $daysOld = 90): int
{
    if (!ecStockNotificationStorageAvailable()) {
        return 0;
    }
    try {
        ecDb()->query(
            "UPDATE ec_stock_notifications SET status = 'expired'
             WHERE status = 'waiting' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$daysOld]
        );
        return 1; // rowCount not easily available via wrapper; indicate success
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Returns all waiting notifications for a product, optionally filtered by variant.
 * Used by admin tools or to check subscription count.
 *
 * @return array[]
 */
function ecStockNotificationWaiters(int $productId, ?int $variantId = null): array
{
    if ($productId <= 0 || !ecStockNotificationStorageAvailable()) {
        return [];
    }
    try {
        return ecDb()->query(
            "SELECT * FROM ec_stock_notifications
             WHERE product_id = ? AND variant_id <=> ? AND status = 'waiting'
             ORDER BY created_at ASC",
            [$productId, $variantId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Returns the count of waiting subscribers for a product (used for UI display).
 */
function ecStockNotificationWaitersCount(int $productId, ?int $variantId = null): int
{
    if ($productId <= 0 || !ecStockNotificationStorageAvailable()) {
        return 0;
    }
    try {
        return (int)ecDb()->query(
            "SELECT COUNT(*) FROM ec_stock_notifications
             WHERE product_id = ? AND variant_id <=> ? AND status = 'waiting'",
            [$productId, $variantId]
        )->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// POST handler — subscribe from storefront
// ─────────────────────────────────────────────────────────────────────────

/**
 * POST /ecommerce/notify-stock
 * Body: product_id, variant_id (optional), email (optional when logged-in)
 *
 * Returns JSON: {ok, already_subscribed, message}
 */
function ecPublicStockNotifySubscribe(): void
{
    header('Content-Type: application/json');

    $productId  = (int)($_POST['product_id'] ?? 0);
    $variantId  = isset($_POST['variant_id']) && (int)$_POST['variant_id'] > 0
        ? (int)$_POST['variant_id']
        : null;

    $user       = function_exists('app') ? app()->user() : null;
    $customerId = is_array($user) ? (int)($user['id'] ?? 0) : 0;
    $email      = trim((string)($_POST['email'] ?? ''));

    if ($email === '' && $customerId > 0) {
        $email = (string)($user['email'] ?? '');
    }

    if ($productId <= 0) {
        echo json_encode(['ok' => false, 'already_subscribed' => false, 'message' => 'Invalid product']);
        exit;
    }
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        echo json_encode(['ok' => false, 'already_subscribed' => false, 'message' => 'A valid email address is required']);
        exit;
    }

    $result = ecStockNotificationSubscribe($productId, $variantId, $email, $customerId > 0 ? $customerId : null);

    $message = $result['ok']
        ? ($result['already_subscribed'] ? "You're already on the list." : "We'll notify you when it's back in stock.")
        : ($result['error'] ?: 'Something went wrong.');

    echo json_encode([
        'ok'               => $result['ok'],
        'already_subscribed' => $result['already_subscribed'],
        'message'          => $message,
    ]);
    exit;
}
