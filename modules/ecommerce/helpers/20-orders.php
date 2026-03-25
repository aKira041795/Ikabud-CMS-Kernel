<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Orders (helpers/20-orders.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Valid order status transitions (from → allowed next statuses).
 */
const EC_ORDER_STATUS_TRANSITIONS = [
    'pending'    => ['processing', 'cancelled'],
    'processing' => ['shipped',    'cancelled'],
    'shipped'    => ['delivered',  'cancelled'],
    'delivered'  => ['refunded'],
    'cancelled'  => [],
    'refunded'   => [],
];

/**
 * Create a new order from a validated checkout payload.
 *
 * @param array $data {
 *   cart_items, subtotal, discount_amount, tax_amount, shipping_amount, total,
 *   currency, coupon_code, shipping_rate_id, source,
 *   billing: {first_name, last_name, email, address_line1, ...},
 *   shipping: {first_name, last_name, address_line1, ...},
 *   customer_note, customer_id (optional), guest_email, guest_name
 * }
 * @return array  ['order_id' => int, 'order_number' => string, 'confirmation_token' => string]
 */
function ecOrderCreate(array $data): array
{
    $db = ecDb();

    $orderNumber  = ecOrderGenerateNumber();
    $token        = bin2hex(random_bytes(32));
    $customerId   = isset($data['customer_id']) ? (int)$data['customer_id'] : null;
    $source       = in_array($data['source'] ?? '', ['web', 'pos', 'api'], true) ? $data['source'] : 'web';
    $currency     = $data['currency'] ?? ecSettings('currency', 'USD');

    // Begin DB transaction
    $pdo = $db->pdo();
    $pdo->beginTransaction();

    try {
        // Insert order
        $db->execute(
            "INSERT INTO ec_orders (
                order_number, customer_id, guest_email, guest_name, source,
                status, payment_status, subtotal, discount_amount, tax_amount,
                shipping_amount, total, currency, coupon_code, customer_note,
                confirmation_token, placed_by_user_id, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $orderNumber,
                $customerId,
                $data['guest_email'] ?? null,
                $data['guest_name']  ?? null,
                $source,
                (float)($data['subtotal']         ?? 0),
                (float)($data['discount_amount']  ?? 0),
                (float)($data['tax_amount']        ?? 0),
                (float)($data['shipping_amount']   ?? 0),
                (float)($data['total']             ?? 0),
                $currency,
                $data['coupon_code'] ?? null,
                $data['customer_note'] ?? null,
                $token,
                $data['placed_by_user_id'] ?? null,
            ]
        );

        $orderId = (int)$db->lastInsertId();

        // Insert order items
        foreach ($data['cart_items'] ?? [] as $item) {
            $unitPrice = (float)($item['price_snapshot'] ?? 0);
            $qty       = max(1, (int)($item['qty'] ?? 1));
            $db->execute(
                "INSERT INTO ec_order_items (order_id, product_id, variant_id, product_title, sku, unit_price, qty, line_total, variant_label)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $orderId,
                    (int)$item['product_id'],
                    $item['variant_id'] ?? null,
                    $item['product_title'] ?? '',
                    $item['sku']           ?? '',
                    $unitPrice,
                    $qty,
                    round($unitPrice * $qty, 2),
                    $item['variant_label'] ?? null,
                ]
            );

            // Decrement stock
            ecProductDecrementStock((int)$item['product_id'], $qty);
        }

        // Insert address meta
        $addressFields = ['billing_first_name', 'billing_last_name', 'billing_email',
                          'billing_address_line1', 'billing_address_line2',
                          'billing_city', 'billing_state', 'billing_postal_code',
                          'billing_country', 'billing_phone',
                          'shipping_first_name', 'shipping_last_name',
                          'shipping_address_line1', 'shipping_address_line2',
                          'shipping_city', 'shipping_state', 'shipping_postal_code',
                          'shipping_country', 'shipping_rate_id', 'shipping_carrier'];

        $billing  = $data['billing']  ?? [];
        $shipping = $data['shipping'] ?? [];

        $meta = [];
        foreach ($billing as $k => $v) {
            $meta['billing_' . $k] = $v;
        }
        foreach ($shipping as $k => $v) {
            $meta['shipping_' . $k] = $v;
        }
        if (!empty($data['shipping_rate_id'])) {
            $meta['shipping_rate_id'] = $data['shipping_rate_id'];
        }

        foreach ($meta as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $db->execute(
                "INSERT IGNORE INTO ec_order_meta (order_id, meta_key, meta_value) VALUES (?, ?, ?)",
                [$orderId, $key, (string)$value]
            );
        }

        // Payment transaction record
        $db->execute(
            "INSERT INTO ec_payment_transactions (order_id, gateway, amount, currency, status, created_at, updated_at)
             VALUES (?, 'manual', ?, ?, 'pending', NOW(), NOW())",
            [$orderId, (float)($data['total'] ?? 0), $currency]
        );

        // Increment coupon uses
        if (!empty($data['coupon_code'])) {
            ecCouponUse((string)$data['coupon_code']);
        }

        $pdo->commit();

    } catch (\Throwable $e) {
        $pdo->rollBack();
        write_log('ecOrderCreate failed: ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'trace'  => $e->getTraceAsString(),
        ]);
        throw $e;
    }

    // Fire event
    try {
        app()->events()->publish('ecommerce.order.created', [
            'order_id'       => $orderId,
            'order_number'   => $orderNumber,
            'customer_email' => $data['guest_email'] ?? ($data['billing']['email'] ?? ''),
            'total'          => (float)($data['total'] ?? 0),
            'source'         => $source,
        ]);
    } catch (\Throwable $e) {
        write_log('ecommerce.order.created event error: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce']);
    }

    return [
        'order_id'           => $orderId,
        'order_number'       => $orderNumber,
        'confirmation_token' => $token,
    ];
}

/**
 * Fetch a single order with items and meta.
 * ACL: admins see all; customers see own orders; guests need confirmation token.
 */
function ecOrderGet(int $id, ?int $customerId = null, ?string $token = null): ?array
{
    $db = ecDb();

    try {
        $where  = 'o.id = ?';
        $params = [$id];

        if ($customerId !== null) {
            $where  .= ' AND (o.customer_id = ? OR o.confirmation_token = ?)';
            $params[] = $customerId;
            $params[] = $token ?? '';
        } elseif ($token !== null) {
            $where  .= ' AND o.confirmation_token = ?';
            $params[] = $token;
        }

        $order = $db->query(
            "SELECT o.* FROM ec_orders o WHERE $where LIMIT 1",
            $params
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
        }

        $order['items'] = $db->query(
            "SELECT * FROM ec_order_items WHERE order_id = ? ORDER BY id ASC",
            [$id]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $metaRows = $db->query(
            "SELECT meta_key, meta_value FROM ec_order_meta WHERE order_id = ?",
            [$id]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $order['meta'] = [];
        foreach ($metaRows as $m) {
            $order['meta'][$m['meta_key']] = $m['meta_value'];
        }

        $order['payment'] = $db->query(
            "SELECT * FROM ec_payment_transactions WHERE order_id = ? ORDER BY id DESC LIMIT 1",
            [$id]
        )->fetch(\PDO::FETCH_ASSOC) ?: null;

        return $order;
    } catch (\Throwable $e) {
        write_log('ecOrderGet error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        return null;
    }
}

/**
 * List orders (admin).
 *
 * @return array  ['items' => [...], 'total' => int]
 */
function ecOrderList(array $filters = []): array
{
    $db     = ecDb();
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[]  = 'status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['payment_status'])) {
        $where[]  = 'payment_status = ?';
        $params[] = $filters['payment_status'];
    }
    if (!empty($filters['source'])) {
        $where[]  = 'source = ?';
        $params[] = $filters['source'];
    }
    if (!empty($filters['search'])) {
        $where[]  = '(order_number LIKE ? OR guest_email LIKE ? OR guest_name LIKE ?)';
        $s        = '%' . $filters['search'] . '%';
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }
    if (!empty($filters['date_from'])) {
        $where[]  = 'DATE(created_at) >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[]  = 'DATE(created_at) <= ?';
        $params[] = $filters['date_to'];
    }

    $limit  = min(100, max(1, (int)($filters['limit']  ?? 25)));
    $offset = max(0, (int)($filters['offset'] ?? 0));

    $whereStr = implode(' AND ', $where);

    try {
        $total = (int)$db->query("SELECT COUNT(*) FROM ec_orders WHERE $whereStr", $params)->fetchColumn();
        $rows  = $db->query(
            "SELECT id, order_number, customer_id, guest_email, guest_name, source, status, payment_status, total, currency, created_at
             FROM ec_orders
             WHERE $whereStr
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return ['items' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        return ['items' => [], 'total' => 0];
    }
}

/**
 * Update an order's status.
 */
function ecOrderUpdateStatus(int $orderId, string $newStatus, ?string $note = null): bool
{
    $order = ecOrderGet($orderId);
    if (!$order) {
        return false;
    }

    $current  = $order['status'];
    $allowed  = EC_ORDER_STATUS_TRANSITIONS[$current] ?? [];
    if (!in_array($newStatus, $allowed, true)) {
        return false;
    }

    $db = ecDb();
    $db->execute("UPDATE ec_orders SET status = ?, updated_at = NOW() WHERE id = ?", [$newStatus, $orderId]);

    if ($note !== null) {
        $db->execute(
            "INSERT INTO ec_order_meta (order_id, meta_key, meta_value) VALUES (?, 'admin_note', ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            [$orderId, $note]
        );
    }

    // Fire status-specific events
    $eventKey = match ($newStatus) {
        'shipped'   => 'ecommerce.order.shipped',
        'cancelled' => 'ecommerce.order.cancelled',
        default     => null,
    };
    if ($eventKey) {
        try {
            app()->events()->publish($eventKey, ['order_id' => $orderId, 'order_number' => $order['order_number']]);
        } catch (\Throwable $e) {}
    }

    return true;
}

/**
 * Update payment status (e.g. manual mark-as-paid).
 */
function ecOrderMarkPaid(int $orderId): void
{
    $db = ecDb();
    $db->execute("UPDATE ec_orders SET payment_status = 'paid', updated_at = NOW() WHERE id = ?", [$orderId]);
    $db->execute("UPDATE ec_payment_transactions SET status = 'succeeded', updated_at = NOW() WHERE order_id = ? AND status = 'pending'", [$orderId]);

    $order = ecOrderGet($orderId);
    if ($order) {
        try {
            app()->events()->publish('ecommerce.order.paid', [
                'order_id'     => $orderId,
                'order_number' => $order['order_number'],
                'total'        => $order['total'],
            ]);
        } catch (\Throwable $e) {}
    }
}

/**
 * Generate the next sequential order number.
 * Format: {PREFIX}-{YYYY}-{0001}
 */
function ecOrderGenerateNumber(): string
{
    $prefix = strtoupper(ecSettings('order_number_prefix', 'EC'));
    $year   = date('Y');

    try {
        $count = (int)ecDb()->query(
            "SELECT COUNT(*) FROM ec_orders WHERE YEAR(created_at) = ?",
            [$year]
        )->fetchColumn();
    } catch (\Throwable $e) {
        $count = 0;
    }

    return $prefix . '-' . $year . '-' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * List orders for a specific customer.
 */
function ecCustomerOrders(int $customerId, int $limit = 20, int $offset = 0): array
{
    try {
        $total = (int)ecDb()->query(
            "SELECT COUNT(*) FROM ec_orders WHERE customer_id = ?",
            [$customerId]
        )->fetchColumn();

        $rows = ecDb()->query(
            "SELECT id, order_number, status, payment_status, total, currency, created_at
             FROM ec_orders WHERE customer_id = ?
             ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$customerId, $limit, $offset]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return ['items' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        return ['items' => [], 'total' => 0];
    }
}
