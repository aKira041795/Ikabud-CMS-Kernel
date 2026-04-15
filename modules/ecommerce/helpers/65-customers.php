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

function ecStoreCustomerList(int $storeId, array $filters = []): array
{
    if ($storeId <= 0) {
        return ['items' => [], 'total' => 0];
    }

    $db = ecDb();
    $search = trim((string)($filters['search'] ?? ''));
    $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
    $offset = max(0, (int)($filters['offset'] ?? 0));

    try {
        $orderScope = ecStoreOrderScopePredicate('o', 'store_customer_scope_items');
        $itemScope = ecStoreOwnedLineItemPredicate('o', 'oi', 'store_customer_tagged_items');
        $orders = $db->query(
            "SELECT o.id AS order_id,
                    o.order_number,
                    o.customer_id,
                    o.guest_email,
                    o.guest_name,
                    o.created_at,
                    u.username,
                    u.email AS user_email,
                    u.display_name,
                    u.is_active,
                    u.created_at AS user_created_at,
                    (
                        SELECT COALESCE(SUM(oi.line_total), 0)
                        FROM ec_order_items oi
                        WHERE oi.order_id = o.id
                          AND {$itemScope['sql']}
                    ) AS order_total
             FROM ec_orders o
             LEFT JOIN cms_users u ON u.id = o.customer_id
             WHERE {$orderScope['sql']}
               AND o.status NOT IN ('cancelled')
             ORDER BY o.created_at DESC",
            array_merge(
                ecStoreScopeQueryParams($storeId, (int)$itemScope['params_per_store']),
                ecStoreScopeQueryParams($storeId, (int)$orderScope['params_per_store'])
            )
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return ['items' => [], 'total' => 0];
    }

    $grouped = [];
    foreach ($orders as $order) {
        $customerId = isset($order['customer_id']) && (int)$order['customer_id'] > 0 ? (int)$order['customer_id'] : null;
        $email = strtolower(trim((string)($order['user_email'] ?? $order['guest_email'] ?? '')));
        $key = $customerId !== null
            ? 'u:' . $customerId
            : 'g:' . ($email !== '' ? $email : 'order:' . (int)($order['order_id'] ?? 0));

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'customer_id' => $customerId,
                'username' => trim((string)($order['username'] ?? '')),
                'email' => $email,
                'display_name' => trim((string)($order['display_name'] ?? $order['guest_name'] ?? 'Customer')),
                'is_active' => isset($order['is_active']) ? (int)$order['is_active'] : 1,
                'created_at' => (string)($order['user_created_at'] ?? $order['created_at'] ?? ''),
                'last_order_number' => (string)($order['order_number'] ?? ''),
                'last_order_at' => (string)($order['created_at'] ?? ''),
                'order_count' => 0,
                'lifetime_value' => 0.0,
            ];
        }

        $grouped[$key]['order_count']++;
        $grouped[$key]['lifetime_value'] = round((float)$grouped[$key]['lifetime_value'] + (float)($order['order_total'] ?? 0), 2);
        if ((string)($order['created_at'] ?? '') > (string)$grouped[$key]['last_order_at']) {
            $grouped[$key]['last_order_at'] = (string)($order['created_at'] ?? '');
            $grouped[$key]['last_order_number'] = (string)($order['order_number'] ?? '');
        }
    }

    $rows = array_values(array_filter($grouped, static function (array $row) use ($search): bool {
        if ($search === '') {
            return true;
        }

        $needle = strtolower($search);
        return str_contains(strtolower((string)($row['email'] ?? '')), $needle)
            || str_contains(strtolower((string)($row['display_name'] ?? '')), $needle)
            || str_contains(strtolower((string)($row['username'] ?? '')), $needle)
            || str_contains(strtolower((string)($row['last_order_number'] ?? '')), $needle);
    }));

    usort($rows, static function (array $left, array $right): int {
        return strcmp((string)($right['last_order_at'] ?? ''), (string)($left['last_order_at'] ?? ''));
    });

    $total = count($rows);
    $rows = array_slice($rows, $offset, $limit);

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

// ─── Customer Address Book CRUD (Tier 3.2) ─────────────────────────────

function ecCustomerAddressGet(int $id, int $customerId): ?array
{
    if (!ecTableExists('ec_customer_addresses')) return null;
    $rows = ecDb()->query(
        'SELECT * FROM ec_customer_addresses WHERE id = ? AND user_id = ?',
        [$id, $customerId]
    );
    if ($rows instanceof \PDOStatement) $rows = $rows->fetchAll(\PDO::FETCH_ASSOC);
    return is_array($rows) && count($rows) > 0 ? $rows[0] : null;
}

function ecCustomerAddressCreate(int $customerId, array $data): array
{
    if (!ecTableExists('ec_customer_addresses')) {
        return ['ok' => false, 'error' => 'Address table not available'];
    }

    $label = trim((string)($data['label'] ?? ''));
    $firstName = trim((string)($data['first_name'] ?? ''));
    $lastName = trim((string)($data['last_name'] ?? ''));
    $company = trim((string)($data['company'] ?? ''));
    $addressLine1 = trim((string)($data['address_line_1'] ?? ''));
    $addressLine2 = trim((string)($data['address_line_2'] ?? ''));
    $city = trim((string)($data['city'] ?? ''));
    $state = trim((string)($data['state'] ?? ''));
    $postalCode = trim((string)($data['postal_code'] ?? ''));
    $country = trim((string)($data['country'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $isDefault = !empty($data['is_default']);

    if ($addressLine1 === '' || $city === '' || $country === '') {
        return ['ok' => false, 'error' => 'Address line 1, city, and country are required'];
    }

    if ($isDefault) {
        ecDb()->execute(
            'UPDATE ec_customer_addresses SET is_default = 0 WHERE user_id = ?',
            [$customerId]
        );
    }

    ecDb()->execute(
        'INSERT INTO ec_customer_addresses
            (user_id, label, first_name, last_name, company,
             address_line_1, address_line_2, city, state, postal_code, country, phone,
             is_default, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
        [
            $customerId, $label ?: null, $firstName, $lastName, $company ?: null,
            $addressLine1, $addressLine2 ?: null, $city, $state, $postalCode, $country, $phone ?: null,
            $isDefault ? 1 : 0,
        ]
    );

    $id = ecDb()->lastInsertId();
    return ['ok' => true, 'id' => (int)$id];
}

function ecCustomerAddressUpdate(int $id, int $customerId, array $data): bool
{
    if (!ecTableExists('ec_customer_addresses')) return false;
    $existing = ecCustomerAddressGet($id, $customerId);
    if (!$existing) return false;

    $fields = ['label', 'first_name', 'last_name', 'company', 'address_line_1',
               'address_line_2', 'city', 'state', 'postal_code', 'country', 'phone'];
    $sets = [];
    $params = [];

    foreach ($fields as $field) {
        if (array_key_exists($field, $data)) {
            $sets[] = "{$field} = ?";
            $params[] = trim((string)$data[$field]) ?: null;
        }
    }

    if (isset($data['is_default'])) {
        if (!empty($data['is_default'])) {
            ecDb()->execute(
                'UPDATE ec_customer_addresses SET is_default = 0 WHERE user_id = ?',
                [$customerId]
            );
        }
        $sets[] = 'is_default = ?';
        $params[] = !empty($data['is_default']) ? 1 : 0;
    }

    if (empty($sets)) return false;

    $sets[] = 'updated_at = NOW()';
    $params[] = $id;
    $params[] = $customerId;

    $affected = ecDb()->execute(
        'UPDATE ec_customer_addresses SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?',
        $params
    );

    return $affected > 0;
}

function ecCustomerAddressDelete(int $id, int $customerId): bool
{
    if (!ecTableExists('ec_customer_addresses')) return false;
    $affected = ecDb()->execute(
        'DELETE FROM ec_customer_addresses WHERE id = ? AND user_id = ?',
        [$id, $customerId]
    );
    return $affected > 0;
}

function ecCustomerAddressSetDefault(int $id, int $customerId): bool
{
    if (!ecTableExists('ec_customer_addresses')) return false;
    $existing = ecCustomerAddressGet($id, $customerId);
    if (!$existing) return false;

    ecDb()->execute(
        'UPDATE ec_customer_addresses SET is_default = 0 WHERE user_id = ?',
        [$customerId]
    );
    ecDb()->execute(
        'UPDATE ec_customer_addresses SET is_default = 1, updated_at = NOW() WHERE id = ? AND user_id = ?',
        [$id, $customerId]
    );
    return true;
}

function ecCustomerDefaultAddress(int $customerId): ?array
{
    if (!ecTableExists('ec_customer_addresses')) return null;
    $rows = ecDb()->query(
        'SELECT * FROM ec_customer_addresses WHERE user_id = ? AND is_default = 1 LIMIT 1',
        [$customerId]
    );
    if ($rows instanceof \PDOStatement) $rows = $rows->fetchAll(\PDO::FETCH_ASSOC);
    return is_array($rows) && count($rows) > 0 ? $rows[0] : null;
}

function ecCheckoutPrefillAddress(int $customerId): ?array
{
    $address = ecCustomerDefaultAddress($customerId);
    if (!$address) {
        $all = ecCustomerAddresses($customerId);
        $address = !empty($all) ? $all[0] : null;
    }
    return $address;
}
