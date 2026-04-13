<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Multi-Store Context Resolution (helpers/38-stores.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Returns true if the ec_stores table exists and is queryable.
 */
function ecStoreStorageAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        ecDb()->query('SELECT 1 FROM ec_stores LIMIT 1');
        $available = true;
    } catch (\Throwable $e) {
        $available = false;
    }
    return $available;
}

/**
 * Returns true when more than one active store row exists.
 * When false the system behaves as a single-store deployment and
 * all store-aware code paths are effectively no-ops.
 */
function ecStoreIsMultiStoreActive(): bool
{
    if (!ecStoreStorageAvailable()) {
        return false;
    }
    try {
        return (int)ecDb()->query('SELECT COUNT(*) FROM ec_stores WHERE is_active = 1')->fetchColumn() > 1;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Returns the default store row (is_default = 1), cached per request.
 * Returns null when no default store is configured or storage is unavailable.
 */
function ecStoreDefault(): ?array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache === false ? null : $cache;
    }
    if (!ecStoreStorageAvailable()) {
        $cache = false;
        return null;
    }
    try {
        $row = ecDb()->query(
            'SELECT * FROM ec_stores WHERE is_default = 1 AND is_active = 1 LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        $cache = $row ?: false;
        return $row ?: null;
    } catch (\Throwable $e) {
        $cache = false;
        return null;
    }
}

/**
 * Returns an active store row by slug, or null if not found.
 */
function ecStoreBySlug(string $slug): ?array
{
    if (!ecStoreStorageAvailable() || $slug === '') {
        return null;
    }
    try {
        $row = ecDb()->query(
            'SELECT * FROM ec_stores WHERE slug = ? AND is_active = 1 LIMIT 1',
            [$slug]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Returns an active store row by ID, or null if not found.
 */
function ecStoreById(int $id): ?array
{
    if (!ecStoreStorageAvailable() || $id <= 0) {
        return null;
    }
    try {
        $row = ecDb()->query(
            'SELECT * FROM ec_stores WHERE id = ? AND is_active = 1 LIMIT 1',
            [$id]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Resolves the active store context for the current request, cached per request.
 *
 * Resolution order:
 *   1. ?store=<slug>     — query param (useful for preview/API calls)
 *   2. X-Store-Slug      — request header (useful for headless / mobile clients)
 *   3. Default store     — row with is_default = 1
 *   4. null              — no store configured; system operates in single-store mode
 *
 * When null is returned, all existing catalog and order queries behave exactly
 * as before — the context system is fully transparent in single-store mode.
 */
function ecStoreResolveContext(): ?array
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved === false ? null : $resolved;
    }
    if (!ecStoreStorageAvailable()) {
        $resolved = false;
        return null;
    }

    // 1. Query param
    $slug = trim((string)($_GET['store'] ?? ''));
    if ($slug !== '') {
        $store = ecStoreBySlug($slug);
        if ($store) {
            $resolved = $store;
            return $store;
        }
    }

    // 2. Request header
    $headerSlug = trim((string)($_SERVER['HTTP_X_STORE_SLUG'] ?? ''));
    if ($headerSlug !== '') {
        $store = ecStoreBySlug($headerSlug);
        if ($store) {
            $resolved = $store;
            return $store;
        }
    }

    // 3. Default store
    $default  = ecStoreDefault();
    $resolved = $default ?? false;
    return $default;
}

/**
 * Returns the ec_store_product_overrides row for a given store + product, or null.
 */
function ecStoreProductOverride(int $storeId, int $productId): ?array
{
    if (!ecStoreStorageAvailable() || $storeId <= 0 || $productId <= 0) {
        return null;
    }
    try {
        $row = ecDb()->query(
            'SELECT * FROM ec_store_product_overrides WHERE store_id = ? AND product_id = ? LIMIT 1',
            [$storeId, $productId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Applies a store's product overrides (price, sale_price, visibility) to a
 * raw product array (as used inside ecBuildStorefrontCatalogItem).
 *
 * Returns the (potentially modified) product array, or null when the product
 * should be hidden in this store (is_visible = 0).
 */
function ecStoreApplyProductOverrides(array $product, array $store): ?array
{
    $override = ecStoreProductOverride((int)$store['id'], (int)($product['id'] ?? 0));
    if ($override === null) {
        return $product;
    }
    if (!(bool)$override['is_visible']) {
        return null;
    }
    if ($override['price_override'] !== null) {
        $product['price']      = (float)$override['price_override'];
        $product['sale_price'] = null;
        if (is_array($product['pricing'] ?? null)) {
            $product['pricing']['price'] = (float)$override['price_override'];
        }
    }
    if ($override['sale_price_override'] !== null) {
        $product['sale_price'] = (float)$override['sale_price_override'];
        if (is_array($product['pricing'] ?? null)) {
            $product['pricing']['sale_price'] = (float)$override['sale_price_override'];
        }
    }
    return $product;
}

/**
 * Returns the preferred active inventory source for a store (lowest priority value),
 * or null when none configured (fall back to local stock).
 */
function ecStoreInventorySource(int $storeId): ?array
{
    if (!ecStoreStorageAvailable() || $storeId <= 0) {
        return null;
    }
    try {
        $row = ecDb()->query(
            'SELECT * FROM ec_store_inventory_sources WHERE store_id = ? AND is_active = 1 ORDER BY priority ASC LIMIT 1',
            [$storeId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Phase 7C: Save (upsert) the inventory source for a store.
 * $source_type: 'local' | 'wms'
 * $warehouse_id: required when source_type = 'wms', else null
 */
function ecStoreSaveInventorySource(int $storeId, string $sourceType, ?int $warehouseId): array
{
    if (!ecStoreStorageAvailable() || $storeId <= 0) {
        return ['ok' => false, 'error' => 'Storage unavailable'];
    }
    $sourceType = in_array($sourceType, ['local', 'wms'], true) ? $sourceType : 'local';
    if ($sourceType === 'wms' && ($warehouseId === null || $warehouseId <= 0)) {
        return ['ok' => false, 'error' => 'A warehouse ID is required for WMS source type.'];
    }
    try {
        // Deactivate existing sources first, then upsert the active one.
        ecDb()->execute(
            'UPDATE ec_store_inventory_sources SET is_active = 0 WHERE store_id = ?',
            [$storeId]
        );
        ecDb()->execute(
            'INSERT INTO ec_store_inventory_sources (store_id, source_type, warehouse_id, is_active, priority)
             VALUES (?, ?, ?, 1, 1)
             ON DUPLICATE KEY UPDATE source_type = VALUES(source_type), warehouse_id = VALUES(warehouse_id), is_active = 1',
            [$storeId, $sourceType, $sourceType === 'wms' ? $warehouseId : null]
        );
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Phase 7F: returns store IDs whose active WMS inventory source maps to the given warehouse.
 * Used when WMS pushes a product update to resolve which stores should get an
 * ec_store_product_overrides visibility record for that product.
 *
 * @return int[]
 */
function ecStoresByWarehouseId(int $warehouseId): array
{
    if (!ecStoreStorageAvailable() || $warehouseId <= 0) {
        return [];
    }
    try {
        $rows = ecDb()->query(
            'SELECT store_id FROM ec_store_inventory_sources WHERE warehouse_id = ? AND source_type = ? AND is_active = 1',
            [$warehouseId, 'wms']
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_map('intval', $rows ?: []));
    } catch (\Throwable $e) {
        return [];
    }
}

// ── Admin CRUD helpers ────────────────────────────────────────────────────

/**
 * Returns a paginated list of stores for the admin panel.
 * Options: search (string), active_only (bool), limit (int), offset (int).
 *
 * @return array{items: array[], total: int}
 */
function ecStoreList(array $options = []): array
{
    if (!ecStoreStorageAvailable()) {
        return ['items' => [], 'total' => 0];
    }
    $search     = trim((string)($options['search'] ?? ''));
    $activeOnly = !empty($options['active_only']);
    $limit      = max(1, (int)($options['limit'] ?? 50));
    $offset     = max(0, (int)($options['offset'] ?? 0));

    $where  = [];
    $params = [];
    if ($search !== '') {
        $where[]  = '(name LIKE ? OR code LIKE ? OR slug LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($activeOnly) {
        $where[] = 'is_active = 1';
    }
    $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $total = (int)ecDb()->query(
            "SELECT COUNT(*) FROM ec_stores {$whereClause}", $params
        )->fetchColumn();

        $items = ecDb()->query(
            "SELECT * FROM ec_stores {$whereClause} ORDER BY is_default DESC, name ASC LIMIT {$limit} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC);

        return ['items' => is_array($items) ? $items : [], 'total' => $total];
    } catch (\Throwable $e) {
        return ['items' => [], 'total' => 0];
    }
}

/**
 * Normalises input and generates a URL-safe slug from a store name or code.
 */
function ecStoreSlugify(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    return trim($slug, '-');
}

/**
 * Creates a new store row. Returns ['ok' => bool, 'id' => int, 'error' => string].
 *
 * Required keys: name, code.
 * Optional keys: slug (auto-generated), description, is_active, is_default, settings_json.
 */
function ecStoreCreate(array $data): array
{
    if (!ecStoreStorageAvailable()) {
        return ['ok' => false, 'id' => 0, 'error' => 'Store storage unavailable'];
    }
    $name = trim((string)($data['name'] ?? ''));
    $code = trim((string)($data['code'] ?? ''));
    if ($name === '' || $code === '') {
        return ['ok' => false, 'id' => 0, 'error' => 'Name and code are required'];
    }
    $slug        = ecStoreSlugify((string)($data['slug'] ?? '') ?: $code);
    $description = trim((string)($data['description'] ?? ''));
    $isActive    = (int)(bool)($data['is_active'] ?? true);
    $isDefault   = (int)(bool)($data['is_default'] ?? false);
    $settings    = $data['settings_json'] ?? null;
    if (is_array($settings)) {
        $settings = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    try {
        if ($isDefault) {
            ecDb()->execute('UPDATE ec_stores SET is_default = 0');
        }
        ecDb()->execute(
            'INSERT INTO ec_stores (code, name, slug, description, is_active, is_default, settings_json) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$code, $name, $slug, $description ?: null, $isActive, $isDefault, $settings]
        );
        $id = (int)ecDb()->lastInsertId();
        return ['ok' => true, 'id' => $id, 'error' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'id' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Updates an existing store. Returns ['ok' => bool, 'error' => string].
 */
function ecStoreUpdate(int $id, array $data): array
{
    if (!ecStoreStorageAvailable() || $id <= 0) {
        return ['ok' => false, 'error' => 'Invalid store'];
    }
    $name = trim((string)($data['name'] ?? ''));
    $code = trim((string)($data['code'] ?? ''));
    if ($name === '' || $code === '') {
        return ['ok' => false, 'error' => 'Name and code are required'];
    }
    $slug        = ecStoreSlugify((string)($data['slug'] ?? '') ?: $code);
    $description = trim((string)($data['description'] ?? ''));
    $isActive    = (int)(bool)($data['is_active'] ?? true);
    $isDefault   = (int)(bool)($data['is_default'] ?? false);
    $settings    = $data['settings_json'] ?? null;
    if (is_array($settings)) {
        $settings = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    try {
        if ($isDefault) {
            ecDb()->execute('UPDATE ec_stores SET is_default = 0 WHERE id != ?', [$id]);
        }
        ecDb()->execute(
            'UPDATE ec_stores SET code=?, name=?, slug=?, description=?, is_active=?, is_default=?, settings_json=?, updated_at=NOW() WHERE id=?',
            [$code, $name, $slug, $description ?: null, $isActive, $isDefault, $settings, $id]
        );
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Deletes a store by ID.
 * Returns ['ok' => bool, 'error' => string].
 * Refuses to delete the last active store or the default store with orders.
 */
function ecStoreDelete(int $id): array
{
    if (!ecStoreStorageAvailable() || $id <= 0) {
        return ['ok' => false, 'error' => 'Invalid store'];
    }
    try {
        $row = ecDb()->query('SELECT * FROM ec_stores WHERE id = ? LIMIT 1', [$id])->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'Store not found'];
        }
        if ((int)$row['is_default'] === 1) {
            return ['ok' => false, 'error' => 'Cannot delete the default store. Assign another store as default first.'];
        }
        ecDb()->execute('DELETE FROM ec_stores WHERE id = ?', [$id]);
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Sets a store as the default, unsetting any previous default.
 * Returns ['ok' => bool, 'error' => string].
 */
function ecStoreSetDefault(int $id): array
{
    if (!ecStoreStorageAvailable() || $id <= 0) {
        return ['ok' => false, 'error' => 'Invalid store'];
    }
    try {
        ecDb()->execute('UPDATE ec_stores SET is_default = 0');
        ecDb()->execute('UPDATE ec_stores SET is_default = 1 WHERE id = ?', [$id]);
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ── Phase 4: Store user / owner management ───────────────────────────────

/**
 * Returns the users assigned to a store (with their roles).
 * Returns rows: [['user_id', 'role', 'created_at'], ...]
 */
function ecStoreUserList(int $storeId): array
{
    if (!ecStoreStorageAvailable() || $storeId <= 0) {
        return [];
    }
    try {
        return ecDb()->query(
            'SELECT user_id, role, created_at FROM ec_store_users WHERE store_id = ? ORDER BY role ASC, created_at ASC',
            [$storeId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Assigns a user to a store with the given role ('owner' | 'manager').
 * Upserts — updates role if the assignment already exists.
 */
function ecStoreUserAssign(int $storeId, int $userId, string $role = 'manager'): array
{
    if (!ecStoreStorageAvailable() || $storeId <= 0 || $userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid store or user'];
    }
    $role = in_array($role, ['owner', 'manager'], true) ? $role : 'manager';
    try {
        ecDb()->execute(
            'INSERT INTO ec_store_users (store_id, user_id, role) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role)',
            [$storeId, $userId, $role]
        );
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Removes a user's assignment from a store.
 */
function ecStoreUserRemove(int $storeId, int $userId): array
{
    if (!ecStoreStorageAvailable() || $storeId <= 0 || $userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid store or user'];
    }
    try {
        ecDb()->execute(
            'DELETE FROM ec_store_users WHERE store_id = ? AND user_id = ?',
            [$storeId, $userId]
        );
        return ['ok' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

