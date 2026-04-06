<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Public My Orders Handler (handlers/25-public-orders.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /my-orders  — customer order history
 */
function ecPublicMyOrders(): void
{
    $user = app()->user();
    if (!$user || !in_array($user['role'] ?? '', ['subscriber', 'customer', 'editor', 'administrator'], true)) {
        header('Location: /cms/login?redirect=' . urlencode('/ecommerce/my-orders'));
        exit;
    }

    $page  = max(1, (int)(ecInput()['page'] ?? 1));
    $limit = 10;

    $result = ecCustomerOrders((int)$user['id'], $limit, ($page - 1) * $limit);

    ecRender('modules/ecommerce/public/my-orders.disyl', [
        'page_title'  => 'My Orders',
        'orders'      => $result['items'],
        'total'       => $result['total'],
        'page'        => $page,
        'total_pages' => (int)ceil($result['total'] / $limit),
        'user'        => $user,
    ]);
}

/**
 * GET /my-orders/{id}  — single order detail for customer
 */
function ecPublicOrderDetail(array $params = []): void
{
    $user = app()->user();
    if (!$user) {
        header('Location: /cms/login?redirect=' . urlencode('/ecommerce/my-orders'));
        exit;
    }

    $orderId = (int)($params['id'] ?? 0);
    $order   = ecOrderGet($orderId, (int)$user['id']);

    if (!$order) {
        http_response_code(404);
        ecRender('pages/404.disyl', ['page_title' => 'Order Not Found']);
        return;
    }

    ecRender('modules/ecommerce/public/order-detail.disyl', [
        'page_title' => 'Order ' . $order['order_number'],
        'order'      => $order,
        'user'       => $user,
    ]);
}

/**
 * Token-based digital license download.
 *
 * GET /ecommerce/download/{token}
 *
 * The download_token is a 64-char hex string (32 random bytes) issued at
 * purchase time. No authentication required — the token itself proves
 * entitlement. Records downloaded_at on first access.
 */
function ecPublicDownloadLicense(array $params): void
{
    $token = trim((string)($params['token'] ?? ''));

    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        http_response_code(404);
        ecRender('pages/404.disyl', ['page_title' => 'Download Not Found']);
        return;
    }

    $db  = ecDb();
    $row = $db->query(
        "SELECT id, target_module, target_tier, license_key, status, downloaded_at
           FROM ec_order_licenses WHERE download_token = ? LIMIT 1",
        [$token]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$row || $row['status'] !== 'active') {
        http_response_code(404);
        ecRender('pages/404.disyl', ['page_title' => 'Download Not Found']);
        return;
    }

    // Record first download timestamp via CMS context (CMS-owned table write).
    if (!$row['downloaded_at']) {
        try {
            moduleWithContext('cms', static function () use ($row): void {
                cmsDb()->execute(
                    "UPDATE ec_order_licenses SET downloaded_at = NOW() WHERE id = ?",
                    [(int)$row['id']]
                );
            });
        } catch (\Throwable $e) {
            write_log('ecPublicDownloadLicense: could not record download: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce']);
        }
    }

    $module   = preg_replace('/[^a-z0-9_\-]/i', '', (string)($row['target_module'] ?? 'module'));
    $filename = 'license-' . $module . '.jwt';

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $row['license_key'];
    exit;
}
