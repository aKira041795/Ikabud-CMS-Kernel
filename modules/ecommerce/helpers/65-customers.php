<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Customer Helpers (helpers/65-customers.php)
//
// Customers are cms_users with role = 'customer'.
// Provides query helpers for admin customer management CRUD.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Paginated customer list with optional search.
 *
 * Returns ['items' => [...], 'total' => int]
 */
function ecCustomerList(array $filters = []): array
{
    $db     = ecDb();
    $search = trim((string)($filters['search'] ?? ''));
    $limit  = max(1, (int)($filters['limit'] ?? 25));
    $offset = max(0, (int)($filters['offset'] ?? 0));

    $where  = "u.role = 'customer'";
    $params = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where .= " AND (u.email LIKE ? OR u.display_name LIKE ? OR u.username LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $total = (int)$db->query(
        "SELECT COUNT(*) FROM cms_users u WHERE {$where}",
        $params
    )->fetchColumn();

    $rows = $db->query(
        "SELECT
             u.id,
             u.username,
             u.email,
             u.display_name,
             u.is_active,
             u.last_login_at,
             u.created_at,
             (SELECT COUNT(*) FROM ec_orders o WHERE o.customer_id = u.id) AS order_count,
             (SELECT SUM(o.total) FROM ec_orders o WHERE o.customer_id = u.id) AS lifetime_value
         FROM cms_users u
         WHERE {$where}
         ORDER BY u.created_at DESC
         LIMIT {$limit} OFFSET {$offset}",
        $params
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    return ['items' => $rows, 'total' => $total];
}

/**
 * Get a single customer by ID. Returns null if not found or not a customer.
 */
function ecCustomerGet(int $id): ?array
{
    $row = ecDb()->query(
        "SELECT
             u.id,
             u.username,
             u.email,
             u.display_name,
             u.is_active,
             u.last_login_at,
             u.created_at,
             u.updated_at,
             (SELECT COUNT(*) FROM ec_orders o WHERE o.customer_id = u.id) AS order_count,
             (SELECT SUM(o.total) FROM ec_orders o WHERE o.customer_id = u.id) AS lifetime_value
         FROM cms_users u
         WHERE u.id = ? AND u.role = 'customer'
         LIMIT 1",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Update a customer's editable fields.
 * Only allows: display_name, email, is_active.
 */
function ecCustomerUpdate(int $id, array $data): bool
{
    $db          = ecDb();
    $displayName = trim((string)($data['display_name'] ?? ''));
    $email       = strtolower(trim((string)($data['email'] ?? '')));
    $isActive    = isset($data['is_active']) ? (int)(bool)$data['is_active'] : null;

    $sets   = [];
    $params = [];

    if ($displayName !== '') {
        $sets[]   = 'display_name = ?';
        $params[] = $displayName;
    }
    if ($email !== '') {
        $sets[]   = 'email = ?';
        $params[] = $email;
    }
    if ($isActive !== null) {
        $sets[]   = 'is_active = ?';
        $params[] = $isActive;
    }

    if (empty($sets)) {
        return false;
    }

    $sets[]   = 'updated_at = NOW()';
    $params[] = $id;

    $affected = $db->execute(
        "UPDATE cms_users SET " . implode(', ', $sets) . " WHERE id = ? AND role = 'customer'",
        $params
    );

    return $affected > 0;
}

/**
 * Permanently delete a customer and their associated data.
 * Orders are detached (customer_id → NULL) to preserve order history.
 * Addresses are removed.
 */
function ecCustomerDelete(int $id): bool
{
    $db = ecDb();

    // Verify it's a customer before deleting
    $exists = (int)$db->query(
        "SELECT COUNT(*) FROM cms_users WHERE id = ? AND role = 'customer'",
        [$id]
    )->fetchColumn();

    if (!$exists) {
        return false;
    }

    // Detach orders (preserve history, just null the customer_id)
    $db->execute(
        "UPDATE ec_orders SET customer_id = NULL, updated_at = NOW() WHERE customer_id = ?",
        [$id]
    );

    // Remove saved addresses
    if (ecTableExists('ec_customer_addresses')) {
        $db->execute("DELETE FROM ec_customer_addresses WHERE user_id = ?", [$id]);
    }

    // Remove the user record
    $affected = $db->execute("DELETE FROM cms_users WHERE id = ? AND role = 'customer'", [$id]);

    return $affected > 0;
}

/**
 * Get saved addresses for a customer.
 */
function ecCustomerAddresses(int $customerId): array
{
    if (!ecTableExists('ec_customer_addresses')) {
        return [];
    }

    return ecDb()->query(
        "SELECT * FROM ec_customer_addresses WHERE user_id = ? ORDER BY is_default DESC, id ASC",
        [$customerId]
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}
