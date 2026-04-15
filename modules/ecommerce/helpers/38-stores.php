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
 * Returns true when multi-store has been enabled in settings AND more than one
 * active store exists. Use this everywhere instead of ecStoreIsMultiStoreActive()
 * so the admin toggle is respected.
 */
function ecIsMultiStoreEnabled(): bool
{
    return (bool)ecSettings('feature_multistore_enabled', true) && ecStoreIsMultiStoreActive();
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
    // Use a module-level global slot so ecStoreClearResolvedContext() can reset it
    // between test cases without reflection hacks on function-level static variables.
    if (array_key_exists('_ec_store_resolved_cache', $GLOBALS) && $GLOBALS['_ec_store_resolved_cache'] !== null) {
        return $GLOBALS['_ec_store_resolved_cache'] === false ? null : $GLOBALS['_ec_store_resolved_cache'];
    }

    if (!ecStoreStorageAvailable()) {
        $GLOBALS['_ec_store_resolved_cache'] = false;
        return null;
    }

    // 1. Query param
    $slug = trim((string)($_GET['store'] ?? ''));
    if ($slug !== '') {
        $store = ecStoreBySlug($slug);
        if ($store) {
            $GLOBALS['_ec_store_resolved_cache'] = $store;
            return $store;
        }
    }

    // 2. Request header
    $headerSlug = trim((string)($_SERVER['HTTP_X_STORE_SLUG'] ?? ''));
    if ($headerSlug !== '') {
        $store = ecStoreBySlug($headerSlug);
        if ($store) {
            $GLOBALS['_ec_store_resolved_cache'] = $store;
            return $store;
        }
    }

    // 3. Default store
    $default  = ecStoreDefault();
    $GLOBALS['_ec_store_resolved_cache'] = $default ?? false;
    return $default;
}

/**
 * Reset the ecStoreResolveContext() cache.
 *
 * Call this between test cases (or after mutating $_GET['store']) to prevent
 * the request-scoped singleton from carrying stale values across tests.
 */
function ecStoreClearResolvedContext(): void
{
    $GLOBALS['_ec_store_resolved_cache'] = null;
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

function ecStoreApplyPricingCurrencyOverride(array $pricing, $store): array
{
    if ($pricing === [] || !function_exists('ecStoreSettingsArray')) {
        return $pricing;
    }

    $storeSettings = ecStoreSettingsArray($store);
    $storeCurrency = function_exists('ecCurrencyNormalizeCode')
        ? ecCurrencyNormalizeCode($storeSettings['currency'] ?? '')
        : trim((string)($storeSettings['currency'] ?? ''));
    if ($storeCurrency === '') {
        return $pricing;
    }

    $pricing['currency'] = $storeCurrency;
    return $pricing;
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
 * Returns the visible product IDs explicitly assigned to a store.
 *
 * @return int[]
 */
function ecStoreAssignedProductIds(int $storeId): array
{
    if (!ecStoreStorageAvailable() || $storeId <= 0) {
        return [];
    }

    try {
        $rows = ecDb()->query(
            'SELECT product_id FROM ec_store_product_overrides WHERE store_id = ? AND is_visible = 1',
            [$storeId]
        )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    return array_values(array_unique(array_map('intval', $rows)));
}

function ecStoreOwnsProduct(int $storeId, int $productId): bool
{
    if ($storeId <= 0 || $productId <= 0) {
        return false;
    }

    try {
        return (int)ecDb()->query(
            'SELECT COUNT(*) FROM ec_store_product_overrides WHERE store_id = ? AND product_id = ? AND is_visible = 1',
            [$storeId, $productId]
        )->fetchColumn() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

function ecStoreCategoryList(int $storeId): array
{
    if (!ecStoreStorageAvailable() || $storeId <= 0) {
        return [];
    }

    $categoryWhere = ecHasCmsCategoryTaxonomy() ? "WHERE cat.taxonomy = 'product' OR cat.taxonomy IS NULL" : '';

    try {
        return ecDb()->query(
            "SELECT cat.id, cat.name, cat.slug, COUNT(DISTINCT c.id) AS product_count
             FROM cms_categories cat
             INNER JOIN cms_content_categories cc ON cc.category_id = cat.id
             INNER JOIN cms_content c ON c.id = cc.content_id AND c.type = 'product' AND c.deleted_at IS NULL
             INNER JOIN ec_store_product_overrides store_po ON store_po.product_id = c.id AND store_po.store_id = ? AND store_po.is_visible = 1
             {$categoryWhere}
             GROUP BY cat.id, cat.name, cat.slug
             ORDER BY product_count DESC, cat.name ASC",
            [$storeId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function ecStoreInventoryWarehouseOptions(): array
{
    try {
        return ecDb()->query(
            'SELECT id, code, name FROM wms_warehouses WHERE deleted_at IS NULL AND COALESCE(is_active, 1) = 1 ORDER BY name ASC'
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function ecStoreBrandingColumnsAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    if (!ecStoreStorageAvailable()) {
        $available = false;
        return false;
    }

    try {
        ecDb()->query('SELECT banner_image_id, logo_image_id, announcement FROM ec_stores WHERE 1 = 0');
        $available = true;
    } catch (\Throwable $e) {
        $available = false;
    }

    return $available;
}

function ecStoreSettingsArray(array|int|null $store): array
{
    if (is_int($store)) {
        $store = ecStoreById($store);
    }

    if (!is_array($store)) {
        return [];
    }

    $rawSettings = trim((string)($store['settings_json'] ?? ''));
    if ($rawSettings === '') {
        return [];
    }

    $decoded = json_decode($rawSettings, true);
    return is_array($decoded) ? $decoded : [];
}

function ecStoreSetting(array|int|null $store, string $key, mixed $default = null): mixed
{
    $settings = ecStoreSettingsArray($store);
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

/**
 * Resolve a setting with store → global → default fallback.
 *
 * When a store has an explicit override for $key in its settings_json that is
 * non-empty, that value wins.  Otherwise falls back to the global
 * ecSettings($key), then to $default.
 */
function ecStoreAwareSetting(string $key, array|int|null $store = null, mixed $default = null): mixed
{
    if ($store !== null) {
        $storeValue = ecStoreSetting($store, $key);
        if ($storeValue !== null && $storeValue !== '') {
            return $storeValue;
        }
    }

    $global = ecSettings($key);
    if ($global !== null && $global !== '' && $global !== false) {
        return $global;
    }

    return $default;
}

/**
 * Convenience: resolve currency symbol with store → global → '$' fallback.
 */
function ecStoreAwareCurrencySymbol(array|int|null $store = null): string
{
    return (string)ecStoreAwareSetting('currency_symbol', $store, '$');
}

/**
 * Convenience: resolve currency code with store → global → 'USD' fallback.
 */
function ecStoreAwareCurrencyCode(array|int|null $store = null): string
{
    return (string)ecStoreAwareSetting('currency', $store, 'USD');
}

function ecStoreSettingsJsonFromInput(array $input): ?string
{
    $settings = [];
    $fields = ['currency', 'currency_symbol', 'timezone', 'tax_rate', 'checkout_note',
               'shop_page_title', 'payment_method_label', 'order_number_prefix',
               'admin_email', 'admin_notification_email', 'store_url'];
    $shippingTextFields = ['shipping_label', 'shipping_carrier', 'shipping_estimated_days', 'shipping_default_country'];
    $shippingNumberFields = ['shipping_flat_rate', 'shipping_free_above'];
    $integerFields = ['products_per_page' => [4, 100], 'low_stock_threshold' => [0, 999]];
    $booleanFields = ['guest_checkout', 'require_account_for_digital'];
    $themeOptions = ['orange', 'indigo', 'emerald', 'rose'];
    $bannerModeOptions = ['show', 'hide'];
    $socialModeOptions = ['custom', 'hide'];
    $hoursModeOptions = ['custom', 'hide'];

    foreach ($fields as $field) {
        $value = trim((string)($input['setting_' . $field] ?? ''));
        if ($value !== '') {
            $settings[$field] = $value;
        }
    }

    // PEM key fields: preserve newlines, only store when non-empty.
    $pemValue = trim((string)($input['setting_license_private_key_pem'] ?? ''));
    if ($pemValue !== '') {
        $settings['license_private_key_pem'] = $pemValue;
    }

    // Integer fields with min/max clamping
    foreach ($integerFields as $field => [$min, $max]) {
        $value = trim((string)($input['setting_' . $field] ?? ''));
        if ($value !== '' && is_numeric($value)) {
            $settings[$field] = max($min, min($max, (int)$value));
        }
    }

    // Boolean fields: '1' = override-on, '0' = override-off, '' = inherit global
    foreach ($booleanFields as $field) {
        $raw = $input['setting_' . $field] ?? null;
        if ($raw !== null && $raw !== '') {
            $settings[$field] = in_array((string)$raw, ['1', 'on', 'true', 'yes'], true) ? '1' : '0';
        }
    }

    $shippingMode = trim((string)($input['setting_shipping_mode'] ?? ''));
    if (in_array($shippingMode, ['flat', 'table'], true)) {
        $settings['shipping_mode'] = $shippingMode;
    }

    foreach ($shippingTextFields as $field) {
        $value = trim((string)($input['setting_' . $field] ?? ''));
        if ($value !== '') {
            $settings[$field] = $value;
        }
    }

    foreach ($shippingNumberFields as $field) {
        $value = trim((string)($input['setting_' . $field] ?? ''));
        if ($value !== '' && is_numeric($value)) {
            $settings[$field] = round((float)$value, 2);
        }
    }

    $tableRateRules = trim((string)($input['setting_shipping_table_rate_rules'] ?? ''));
    if ($tableRateRules !== '') {
        $settings['shipping_table_rate_rules'] = $tableRateRules;
    }

    $theme = trim((string)($input['setting_storefront_theme'] ?? ''));
    if (in_array($theme, $themeOptions, true)) {
        $settings['storefront_theme'] = $theme;
    }

    $bannerMode = trim((string)($input['setting_store_banner_mode'] ?? ''));
    if (in_array($bannerMode, $bannerModeOptions, true)) {
        $settings['store_banner_mode'] = $bannerMode;
    }

    // Only persist banner detail fields when the store actively uses its own banner.
    if (($settings['store_banner_mode'] ?? '') === 'show') {
        foreach (['store_banner_headline', 'store_banner_subtext', 'store_banner_image_url', 'store_banner_cta_text', 'store_banner_cta_url'] as $field) {
            $value = trim((string)($input['setting_' . $field] ?? ''));
            if ($value !== '') {
                $settings[$field] = $value;
            }
        }
    }

    $socialMode = trim((string)($input['setting_social_links_mode'] ?? ''));
    if (in_array($socialMode, $socialModeOptions, true)) {
        $settings['social_links_mode'] = $socialMode;
    }

    // Only persist social URLs when the store actively uses its own links.
    if (($settings['social_links_mode'] ?? '') === 'custom') {
        foreach (['social_facebook', 'social_instagram', 'social_tiktok', 'social_twitter', 'social_youtube'] as $field) {
            $value = trim((string)($input['setting_' . $field] ?? ''));
            if ($value !== '') {
                $settings[$field] = $value;
            }
        }
    }

    $hoursMode = trim((string)($input['setting_store_hours_mode'] ?? ''));
    if (in_array($hoursMode, $hoursModeOptions, true)) {
        $settings['store_hours_mode'] = $hoursMode;
        if ($hoursMode === 'custom') {
            $hoursInput = $input['setting_store_hours'] ?? [];
            $hours = [];
            foreach (['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
                $dayInput = is_array($hoursInput[$day] ?? null) ? $hoursInput[$day] : [];
                $hours[$day] = [
                    'open' => !empty($dayInput['open']),
                    'from' => preg_replace('/[^0-9:]/', '', (string)($dayInput['from'] ?? '09:00')),
                    'to' => preg_replace('/[^0-9:]/', '', (string)($dayInput['to'] ?? '17:00')),
                ];
            }
            $settings['store_hours'] = $hours;
        }
    }

    return $settings !== []
        ? json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;
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
    $announcement = trim((string)($data['announcement'] ?? ''));
    $bannerImageId = max(0, (int)($data['banner_image_id'] ?? 0)) ?: null;
    $logoImageId = max(0, (int)($data['logo_image_id'] ?? 0)) ?: null;
    $settings    = $data['settings_json'] ?? null;
    if (is_array($settings)) {
        $settings = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    try {
        if ($isDefault) {
            ecDb()->execute('UPDATE ec_stores SET is_default = 0');
        }
        if (ecStoreBrandingColumnsAvailable()) {
            ecDb()->execute(
                'INSERT INTO ec_stores (code, name, slug, description, is_active, is_default, banner_image_id, logo_image_id, announcement, settings_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$code, $name, $slug, $description ?: null, $isActive, $isDefault, $bannerImageId, $logoImageId, $announcement ?: null, $settings]
            );
        } else {
            ecDb()->execute(
                'INSERT INTO ec_stores (code, name, slug, description, is_active, is_default, settings_json) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$code, $name, $slug, $description ?: null, $isActive, $isDefault, $settings]
            );
        }
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
    $announcement = trim((string)($data['announcement'] ?? ''));
    $bannerImageId = max(0, (int)($data['banner_image_id'] ?? 0)) ?: null;
    $logoImageId = max(0, (int)($data['logo_image_id'] ?? 0)) ?: null;
    $settings    = $data['settings_json'] ?? null;
    if (is_array($settings)) {
        $settings = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    try {
        if ($isDefault) {
            ecDb()->execute('UPDATE ec_stores SET is_default = 0 WHERE id != ?', [$id]);
        }
        if (ecStoreBrandingColumnsAvailable()) {
            ecDb()->execute(
                'UPDATE ec_stores SET code=?, name=?, slug=?, description=?, is_active=?, is_default=?, banner_image_id=?, logo_image_id=?, announcement=?, settings_json=?, updated_at=NOW() WHERE id=?',
                [$code, $name, $slug, $description ?: null, $isActive, $isDefault, $bannerImageId, $logoImageId, $announcement ?: null, $settings, $id]
            );
        } else {
            ecDb()->execute(
                'UPDATE ec_stores SET code=?, name=?, slug=?, description=?, is_active=?, is_default=?, settings_json=?, updated_at=NOW() WHERE id=?',
                [$code, $name, $slug, $description ?: null, $isActive, $isDefault, $settings, $id]
            );
        }
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
 * Joins cms_users to include display_name, email, and username when available.
 * Returns rows: [['user_id', 'role', 'created_at', 'display_name', 'email', 'username'], ...]
 */
function ecStoreUserList(int $storeId): array
{
    if (!ecStoreStorageAvailable() || $storeId <= 0) {
        return [];
    }
    // Probe for cms_users table — may not exist in all deployments.
    $hasCmsUsers = false;
    try {
        ecDb()->query('SELECT 1 FROM cms_users LIMIT 1');
        $hasCmsUsers = true;
    } catch (\Throwable $ignored) {}

    try {
        if ($hasCmsUsers) {
            return ecDb()->query(
                'SELECT su.user_id, su.role, su.created_at,
                        COALESCE(u.display_name, u.username, CONCAT("#", su.user_id)) AS display_name,
                        u.email, u.username
                 FROM ec_store_users su
                 LEFT JOIN cms_users u ON u.id = su.user_id
                 WHERE su.store_id = ?
                 ORDER BY FIELD(su.role, "owner", "manager", "supervisor"), su.created_at ASC',
                [$storeId]
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
        return ecDb()->query(
            'SELECT user_id, role, created_at FROM ec_store_users WHERE store_id = ? ORDER BY role ASC, created_at ASC',
            [$storeId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Assigns a user to a store with the given role ('owner' | 'manager' | 'supervisor').
 * Upserts — updates role if the assignment already exists.
 */
function ecStoreUserAssign(int $storeId, int $userId, string $role = 'manager'): array
{
    if (!ecStoreStorageAvailable() || $storeId <= 0 || $userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid store or user'];
    }
    $role = in_array($role, ['owner', 'manager', 'supervisor'], true) ? $role : 'manager';
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
 * Returns all stores a user is assigned to, along with their role and basic store info.
 * Used by the "My Stores" page to list stores for the logged-in store user.
 *
 * @return array[] Each element: ['store_id', 'role', 'created_at', 'name', 'slug', 'code', 'is_active', 'description']
 */
function ecStoresForUser(int $userId): array
{
    if (!ecStoreStorageAvailable() || $userId <= 0) {
        return [];
    }
    try {
        return ecDb()->query(
            'SELECT su.store_id, su.role, su.created_at,
                    s.name, s.slug, s.code, s.is_active, s.description
             FROM ec_store_users su
             JOIN ec_stores s ON s.id = su.store_id
             WHERE su.user_id = ?
             ORDER BY FIELD(su.role, "owner", "manager", "supervisor"), s.name ASC',
            [$userId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Returns the preferred store-admin landing path for a user.
 * - No assignments: null
 * - One assignment: direct store dashboard
 * - Multiple assignments: my-stores chooser
 */
function ecStoreHomePathForUser(int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }

    try {
        $rows = ecDb()->query(
            'SELECT su.store_id
             FROM ec_store_users su
             JOIN ec_stores s ON s.id = su.store_id
             WHERE su.user_id = ?
             ORDER BY FIELD(su.role, "owner", "manager", "supervisor"), s.name ASC
             LIMIT 2',
            [$userId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return null;
    }

    if ($rows === []) {
        return null;
    }

    if (count($rows) === 1) {
        return '/ecommerce/store-admin/' . (int)($rows[0]['store_id'] ?? 0);
    }

    return '/ecommerce/my-stores';
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

