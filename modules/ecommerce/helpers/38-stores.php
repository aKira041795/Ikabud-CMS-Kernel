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
