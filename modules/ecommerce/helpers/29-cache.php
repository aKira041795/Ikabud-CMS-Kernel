<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Persistent cache layer (helpers/60-cache.php)
//
// Thin wrapper around kernel Cache, mirroring the CMS pattern in
// modules/cms/helpers/60-cache.php.  Uses tenant-scoped instance IDs
// and tag-based invalidation.
//
// Cacheable:  product listings, product detail, category lists, store pages
// Not cached: cart, checkout, order pages, admin, authenticated mutations
// ─────────────────────────────────────────────────────────────────────────

define('EC_CACHE_INSTANCE', 'ec');
define('EC_CACHE_TTL', 300); // 5 minutes default

/**
 * Tenant-scoped cache instance name.
 */
function ecCacheInstance(): string
{
    $tid = app()->tenant()->current();
    return $tid !== null ? ('ec_t' . $tid) : EC_CACHE_INSTANCE;
}

/**
 * Read the configured TTL (request-cached to avoid repeated DB reads).
 */
function ecCacheTtl(): int
{
    $tid = app()->tenant()->current() ?? 0;
    $cacheKey = 'ec_cache_ttl_cached_t' . $tid;
    $valueKey = 'ec_cache_ttl_value_t' . $tid;
    if (!empty($GLOBALS[$cacheKey])) {
        return (int)($GLOBALS[$valueKey] ?? 0);
    }

    $ttl = (int)(ecSettings('cache_ttl') ?? EC_CACHE_TTL);
    if ($ttl < 0) {
        $ttl = 0;
    }
    $GLOBALS[$cacheKey] = true;
    $GLOBALS[$valueKey] = $ttl;
    return $ttl;
}

/**
 * Check if ecommerce caching is enabled.
 */
function ecCacheEnabled(): bool
{
    $enabled = (string)(ecSettings('cache_enabled') ?? '1');
    if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
        return false;
    }
    return ecCacheTtl() > 0;
}

/**
 * Get a cached ecommerce result. Returns null on miss.
 */
function ecCacheGet(string $cacheKey): ?array
{
    if (!ecCacheEnabled()) {
        return null;
    }
    return app()->cache()->get(ecCacheInstance(), $cacheKey);
}

/**
 * Store an ecommerce result in cache with tags.
 */
function ecCacheSet(string $cacheKey, array $data, array $tags = []): void
{
    if (!ecCacheEnabled()) {
        return;
    }
    app()->cache()->setWithTags(ecCacheInstance(), $cacheKey, $data, $tags, ecCacheTtl());
}

/**
 * Invalidate ecommerce cache entries by tags.
 */
function ecCacheInvalidateByTags(array $tags): int
{
    if (!function_exists('ecCacheInstance')) {
        return 0;
    }
    return app()->cache()->clearByTags(ecCacheInstance(), $tags);
}

/**
 * Flush all ecommerce cache.
 */
function ecCacheFlushAll(): int
{
    $count = app()->cache()->clear(ecCacheInstance());
    if (function_exists('pageCacheInvalidateModule')) {
        $count += pageCacheInvalidateModule('ecommerce');
    }
    return $count;
}

/**
 * Reset per-request TTL cache so settings changes take effect immediately.
 */
function ecCacheResetRuntimeState(): void
{
    $tid = app()->tenant()->current() ?? 0;
    $GLOBALS['ec_cache_ttl_cached_t' . $tid] = false;
    $GLOBALS['ec_cache_ttl_value_t' . $tid] = null;
}

// ── Tag builders ─────────────────────────────────────────────────────

/**
 * Build cache tags for a product.
 */
function ecCacheTagsForProduct(int $productId, ?string $slug = null): array
{
    $tags = ['ec:type:product', 'ec:catalog'];
    if ($productId > 0) {
        $tags[] = 'ec:product:' . $productId;
    }
    if ($slug !== null && $slug !== '') {
        $tags[] = 'ec:product:slug:' . $slug;
    }
    return $tags;
}

/**
 * Build cache tags for a product listing query.
 */
function ecCacheTagsForListing(?int $categoryId = null, ?int $storeId = null): array
{
    $tags = ['ec:type:product', 'ec:catalog'];
    if ($categoryId !== null && $categoryId > 0) {
        $tags[] = 'ec:category:' . $categoryId;
    }
    if ($storeId !== null && $storeId > 0) {
        $tags[] = 'ec:store:' . $storeId;
    }
    return $tags;
}

// ── Invalidation helpers ─────────────────────────────────────────────

/**
 * Invalidate cache after a product is created, updated, or deleted.
 */
function ecCacheInvalidateProduct(int $productId, ?string $slug = null): int
{
    $tags = ['ec:type:product', 'ec:catalog'];
    if ($productId > 0) {
        $tags[] = 'ec:product:' . $productId;
    }
    if ($slug !== null && $slug !== '') {
        $tags[] = 'ec:product:slug:' . $slug;
    }
    $count = ecCacheInvalidateByTags($tags);
    if (function_exists('pageCacheInvalidateModule')) {
        $count += pageCacheInvalidateModule('ecommerce');
    }
    return $count;
}

/**
 * Invalidate cache after a category changes.
 */
function ecCacheInvalidateCategory(int $categoryId = 0): int
{
    $tags = ['ec:categories', 'ec:catalog'];
    if ($categoryId > 0) {
        $tags[] = 'ec:category:' . $categoryId;
    }
    $count = ecCacheInvalidateByTags($tags);
    if (function_exists('pageCacheInvalidateModule')) {
        $count += pageCacheInvalidateModule('ecommerce');
    }
    return $count;
}

/**
 * Build a deterministic cache key from product-list filters.
 */
function ecCacheKeyForProductList(array $filters): string
{
    // Normalize to a stable, sorted representation
    ksort($filters);
    return 'ec:productlist:' . md5(serialize($filters));
}
