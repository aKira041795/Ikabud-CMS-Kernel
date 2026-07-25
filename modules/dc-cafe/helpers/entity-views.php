<?php
/**
 * DC Cafe — Entity View Capability Implementations
 *
 * Each function implements a capability registered in helpers.php.
 * Follows the daily-ledger entity-views pattern.
 */

declare(strict_types=1);

// ── Product Entity Views ─────────────────────────────────────────

function dc_cap_entity_list_product_1(array $params = []): array
{
    $db = dcDb();
    $storeId = (int) ($params['store_id'] ?? dcInput('store_id') ?? 1);

    $rows = $db->query(
        "SELECT p.product_id, p.name, p.base_price, p.is_variable, p.is_active,
                p.current_stock, p.has_stock, p.reorder_level,
                c.name AS category_name, c.category_id
         FROM dc_products p
         JOIN dc_categories c ON c.category_id = p.category_id
         WHERE p.store_id = ?
           AND (p.has_stock = 0 OR p.current_stock > 0)
         ORDER BY c.sort_order ASC, p.name ASC",
        [$storeId]
    )->fetchAll(\PDO::FETCH_ASSOC);

    return array_map(function ($row) {
        return [
            'id'        => (int) $row['product_id'],
            'name'      => $row['name'],
            'price'     => (float) $row['base_price'],
            'category'  => $row['category_name'],
            'category_id' => (int) $row['category_id'],
            'is_variable' => (bool) $row['is_variable'],
            'is_active'   => (bool) $row['is_active'],
            'current_stock' => (float) $row['current_stock'],
            'has_stock'     => (bool) $row['has_stock'],
            'reorder_level' => (float) $row['reorder_level'],
        ];
    }, $rows);
}

function dc_cap_entity_list_product_stock_1(array $params = []): array
{
    $db = dcDb();
    $storeId = (int) ($params['store_id'] ?? dcInput('store_id') ?? 1);

    $rows = $db->query(
        "SELECT p.product_id, p.name, p.base_price, p.is_variable, p.is_active,
                p.current_stock, p.has_stock, p.reorder_level,
                c.name AS category_name, c.category_id
         FROM dc_products p
         JOIN dc_categories c ON c.category_id = p.category_id
         WHERE p.store_id = ?
         ORDER BY c.sort_order ASC, p.name ASC",
        [$storeId]
    )->fetchAll(\PDO::FETCH_ASSOC);

    return array_map(function ($row) {
        return [
            'id'        => (int) $row['product_id'],
            'name'      => $row['name'],
            'price'     => (float) $row['base_price'],
            'category'  => $row['category_name'],
            'category_id' => (int) $row['category_id'],
            'is_variable' => (bool) $row['is_variable'],
            'is_active'   => (bool) $row['is_active'],
            'current_stock' => (float) $row['current_stock'],
            'has_stock'     => (bool) $row['has_stock'],
            'reorder_level' => (float) $row['reorder_level'],
        ];
    }, $rows);
}

function dc_cap_entity_get_product_1(array $params = []): ?array
{
    $db = dcDb();
    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) return null;

    $row = $db->query(
        "SELECT p.*, c.name AS category_name
         FROM dc_products p
         JOIN dc_categories c ON c.category_id = p.category_id
         WHERE p.product_id = ?",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$row) return null;

    return [
        'id'          => (int) $row['product_id'],
        'name'        => $row['name'],
        'description' => $row['description'],
        'price'       => (float) $row['base_price'],
        'category'    => $row['category_name'],
        'is_variable' => (bool) $row['is_variable'],
        'is_active'   => (bool) $row['is_active'],
    ];
}

// ── Order Entity Views ───────────────────────────────────────────

function dc_cap_entity_list_order_1(array $params = []): array
{
    $db = dcDb();
    $storeId = (int) ($params['store_id'] ?? dcInput('store_id') ?? 1);
    $limit = min((int) ($params['limit'] ?? 50), 200);
    $offset = (int) ($params['offset'] ?? 0);

    $rows = $db->query(
        "SELECT o.order_id, o.total_amount, o.discount_amount, o.status,
                o.transaction_date, pm.name AS payment_method,
                u.full_name AS cashier_name
         FROM dc_orders o
         JOIN dc_payment_methods pm ON pm.payment_method_id = o.payment_method_id
         JOIN dc_users u ON u.user_id = o.cashier_id
         WHERE o.store_id = ?
         ORDER BY o.transaction_date DESC
         LIMIT ? OFFSET ?",
        [$storeId, $limit, $offset]
    )->fetchAll(\PDO::FETCH_ASSOC);

    return array_map(function ($row) {
        return [
            'id'              => (int) $row['order_id'],
            'total'           => (float) $row['total_amount'],
            'discount'        => (float) $row['discount_amount'],
            'status'          => $row['status'],
            'payment_method'  => $row['payment_method'],
            'cashier'         => $row['cashier_name'],
            'date'            => $row['transaction_date'],
        ];
    }, $rows);
}

function dc_cap_entity_get_order_1(array $params = []): ?array
{
    $db = dcDb();
    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) return null;

    $order = $db->query(
        "SELECT o.*, pm.name AS payment_method, u.full_name AS cashier_name,
                s.shift_type, s.shift_start
         FROM dc_orders o
         JOIN dc_payment_methods pm ON pm.payment_method_id = o.payment_method_id
         JOIN dc_users u ON u.user_id = o.cashier_id
         JOIN dc_sessions s ON s.session_id = o.session_id
         WHERE o.order_id = ?",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$order) return null;

    $items = $db->query(
        "SELECT oi.*, p.name AS product_name, p.base_price
         FROM dc_order_items oi
         JOIN dc_products p ON p.product_id = oi.product_id
         WHERE oi.order_id = ?",
        [$id]
    )->fetchAll(\PDO::FETCH_ASSOC);

    return [
        'id'              => (int) $order['order_id'],
        'total'           => (float) $order['total_amount'],
        'original_amount' => $order['original_amount'] ? (float) $order['original_amount'] : null,
        'discount'        => (float) $order['discount_amount'],
        'discount_reason' => $order['discount_reason'],
        'payment_method'  => $order['payment_method'],
        'status'          => $order['status'],
        'cashier'         => $order['cashier_name'],
        'date'            => $order['transaction_date'],
        'shift'           => $order['shift_type'],
        'items'           => array_map(function ($item) {
            return [
                'id'           => (int) $item['item_id'],
                'product_id'   => (int) $item['product_id'],
                'product_name' => $item['product_name'],
                'quantity'     => (int) $item['quantity'],
                'unit_price'   => (float) $item['unit_price'],
                'total_price'  => (float) $item['total_price'],
                'customizations' => $item['customizations'] ? json_decode($item['customizations'], true) : null,
                'notes'        => $item['notes'],
            ];
        }, $items),
    ];
}

// ── Customer Entity Views ────────────────────────────────────────

function dc_cap_entity_list_customer_1(array $params = []): array
{
    $db = dcDb();
    $search = $params['phone'] ?? dcInput('phone') ?? '';
    $limit = 50;

    if ($search !== '') {
        $rows = $db->query(
            "SELECT * FROM dc_customers WHERE phone LIKE ? ORDER BY name ASC LIMIT ?",
            ["%{$search}%", $limit]
        )->fetchAll(\PDO::FETCH_ASSOC);
    } else {
        $rows = $db->query(
            "SELECT * FROM dc_customers ORDER BY created_at DESC LIMIT ?",
            [$limit]
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    return array_map(function ($row) {
        return [
            'id'      => (int) $row['customer_id'],
            'name'    => $row['name'],
            'phone'   => $row['phone'],
            'email'   => $row['email'],
            'points'  => (int) $row['points_balance'],
            'tier'    => $row['member_tier'],
            'joined'  => $row['created_at'],
        ];
    }, $rows);
}

function dc_cap_entity_get_customer_1(array $params = []): ?array
{
    $db = dcDb();
    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) return null;

    $row = $db->query("SELECT * FROM dc_customers WHERE customer_id = ?", [$id])->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;

    $orderCount = $db->query(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total
         FROM dc_orders WHERE customer_id = ? AND status = 'completed'",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);

    return [
        'id'              => (int) $row['customer_id'],
        'name'            => $row['name'],
        'phone'           => $row['phone'],
        'email'           => $row['email'],
        'points'          => (int) $row['points_balance'],
        'total_earned'    => (int) $row['total_points_earned'],
        'tier'            => $row['member_tier'],
        'order_count'     => (int) ($orderCount['cnt'] ?? 0),
        'total_spent'     => (float) ($orderCount['total'] ?? 0),
        'joined'          => $row['created_at'],
    ];
}

// ── Inventory Entity View ────────────────────────────────────────

function dc_cap_entity_list_inventory_1(array $params = []): array
{
    $db = dcDb();

    $rows = $db->query(
        "SELECT i.ingredient_id, i.name, i.unit, i.cost_per_unit,
                i.current_stock, i.reorder_level,
                s.name AS supplier_name,
                CASE WHEN i.current_stock <= i.reorder_level AND i.reorder_level > 0 THEN 1 ELSE 0 END AS is_low
         FROM dc_ingredients i
         LEFT JOIN dc_suppliers s ON s.supplier_id = i.supplier_id
         WHERE i.is_active = 1
         ORDER BY is_low DESC, i.name ASC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    return array_map(function ($row) {
        return [
            'id'            => (int) $row['ingredient_id'],
            'name'          => $row['name'],
            'unit'          => $row['unit'],
            'cost_per_unit' => (float) $row['cost_per_unit'],
            'stock'         => (float) $row['current_stock'],
            'reorder_level' => (float) $row['reorder_level'],
            'supplier'      => $row['supplier_name'],
            'is_low'        => (bool) $row['is_low'],
        ];
    }, $rows);
}

// ── Auth Capability ──────────────────────────────────────────────

function dc_cap_kernel_auth_authenticate_1(array $params = []): ?array
{
    $db = dcDb();
    $username = $params['username'] ?? '';
    $password = $params['password'] ?? '';

    if ($username === '' || $password === '') return null;

    $user = $db->query(
        "SELECT * FROM dc_users WHERE username = ? AND is_active = 1 AND deleted_at IS NULL",
        [$username]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) return null;

    return [
        'user_id'  => (int) $user['user_id'],
        'username' => $user['username'],
        'name'     => $user['full_name'],
        'role'     => $user['role'],
        'store_id' => $user['store_id'] ? (int) $user['store_id'] : null,
    ];
}
