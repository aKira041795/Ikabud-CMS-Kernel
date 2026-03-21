<?php

declare(strict_types=1);

/**
 * Return a tenant-scoped CMS cache instance name.
 * Falls back to the global CMS_CACHE_INSTANCE when multi-tenancy is off.
 */
function cmsCacheInstance(): string
{
    $tid = app()->tenant()->current();
    return $tid !== null ? ('cms_t' . $tid) : CMS_CACHE_INSTANCE;
}

function cmsCacheTtl(): int
{
    $tid = cmsRuntimeTenantId();
    $cacheKey = 'cms_cache_ttl_cached_t' . $tid;
    $valueKey = 'cms_cache_ttl_value_t' . $tid;
    if (!empty($GLOBALS[$cacheKey])) {
        return (int) ($GLOBALS[$valueKey] ?? 0);
    }

    $settings = readCmsSettings();
    $ttl = (int)($settings['cache_ttl'] ?? CMS_CACHE_TTL);
    if ($ttl < 0) $ttl = 0;
    $GLOBALS[$cacheKey] = true;
    $GLOBALS[$valueKey] = $ttl;
    return $ttl;
}

function cmsResetCacheRuntimeState(): void
{
    $tid = cmsRuntimeTenantId();
    $GLOBALS['cms_cache_ttl_cached_t' . $tid] = false;
    $GLOBALS['cms_cache_ttl_value_t' . $tid] = null;
}

/**
 * Check if CMS caching is enabled.
 */

function cmsCacheEnabled(): bool
{
    $settings = readCmsSettings();
    $enabled = (string)($settings['cache_enabled'] ?? '1');
    if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
        return false;
    }

    return cmsCacheTtl() > 0;
}

/**
 * Get a cached CMS page/query result.
 * Returns null on miss.
 */

function cmsCacheGet(string $cacheKey): ?array
{
    if (!cmsCacheEnabled()) return null;
    return app()->cache()->get(cmsCacheInstance(), $cacheKey);
}

/**
 * Store a CMS page/query result in cache with tags.
 */

function cmsCacheSet(string $cacheKey, array $data, array $tags = []): void
{
    if (!cmsCacheEnabled()) return;
    app()->cache()->setWithTags(cmsCacheInstance(), $cacheKey, $data, $tags);
}

/**
 * Invalidate CMS cache entries by tags.
 * Called when content changes (create, update, delete, publish).
 */

function cmsCacheInvalidateByTags(array $tags): int
{
    return app()->cache()->clearByTags(cmsCacheInstance(), $tags);
}

/**
 * Invalidate all CMS cache.
 */

function cmsCacheFlushAll(): int
{
    return app()->cache()->clear(cmsCacheInstance());
}

/**
 * Clear compiled DiSyL template cache so updated theme/layout files are recompiled.
 */
function cmsTemplateCacheFlush(): void
{
    $cacheDir = rtrim((string)(defined('STORAGE_PATH') ? STORAGE_PATH : BASE_PATH . '/storage'), '/') . '/cache/disyl';
    if (!is_dir($cacheDir)) {
        return;
    }

    $files = glob($cacheDir . '/*');
    if (!is_array($files)) {
        return;
    }

    foreach ($files as $file) {
        if (is_file($file)) {
            kernelDeletePath($file);
        }
    }
}

/**
 * Build cache tags for a content item.
 */

function cmsCacheTagsForContent(array $content): array
{
    $tags = [];
    $id   = (int)($content['id'] ?? 0);
    $type = (string)($content['type'] ?? 'post');
    $slug = (string)($content['slug'] ?? '');

    if ($id > 0)    $tags[] = 'cms:content:' . $id;
    if ($type)      $tags[] = 'cms:type:' . $type;
    if ($slug)      $tags[] = 'cms:' . $type . ':' . $slug;

    // List pages that should also be invalidated
    if ($type === 'post') {
        $tags[] = 'cms:home';
        $tags[] = 'cms:api:posts';
    } elseif ($type === 'page') {
        $tags[] = 'cms:api:pages';
    }

    // Sitemap should reflect all content changes
    $tags[] = 'cms:sitemap';

    return $tags;
}

/**
 * Invalidate cache for a specific content item and related listing pages.
 */

function cmsCacheInvalidateContent(array $content): int
{
    $tags = cmsCacheTagsForContent($content);
    if (empty($tags)) return 0;
    return cmsCacheInvalidateByTags($tags);
}

/**
 * Send ETag and Last-Modified headers for a cached response.
 * Returns true if the client has a fresh copy (304 can be sent).
 */
