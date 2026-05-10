<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Kernel — Full-Page Output Cache
//
// Caches the complete HTML response for public GET requests from
// unauthenticated visitors.  Sits above per-handler query caches (CMS
// and ecommerce cache helpers) and short-circuits the entire handler
// execution on a cache hit.
//
// Integration point: executeModuleHandler() in module-manager.php wraps
// the ob_start/ob_end_flush block with pageCacheBefore/pageCacheAfter.
//
// Invalidation: coarse tag-based — every CMS content mutation flushes
// all CMS page cache entries; every ecommerce product/category mutation
// flushes all ecommerce page cache entries.  Fine-grained per-URL tags
// are also stored so future surgical invalidation is possible.
// ─────────────────────────────────────────────────────────────────────────

define('PAGE_CACHE_INSTANCE', 'pagecache');
define('PAGE_CACHE_TTL', 300); // 5 minutes — default for most pages

// ── Per-module TTL overrides (seconds) ───────────────────────────────
// Static/CMS pages change infrequently and are event-invalidated on edit,
// so they get a long TTL.  Product listings change more often (price,
// stock, reviews) and ecommerce invalidation is coarser-grained, so a
// shorter TTL provides a better freshness/performance balance.
define('PAGE_CACHE_MODULE_TTLS', [
    'cms'        => 600,  // 10 min — static pages, blog posts
    'ecommerce'  => 180,  // 3 min — product listings, shop pages
]);

// ── Routes that must NEVER be page-cached ────────────────────────────
// Session-dependent, user-specific, or mutation-triggering pages.
define('PAGE_CACHE_SKIP_PREFIXES', [
    '/api/',
    '/admin/',
    '/login',
    '/logout',
    '/register',
    '/lock.php',
    '/superadmin',
    '/ecommerce/cart',
    '/ecommerce/checkout',
    '/ecommerce/my-orders',
    '/ecommerce/my-wishlist',
    '/ecommerce/recover-cart',
    '/ecommerce/compare',
    '/ecommerce/admin',
    '/ecommerce/store-admin',
    '/cms/login',
    '/cms/register',
    '/cms/admin',
    '/cms/auth',
    '/portal',
]);

// ── Instance & TTL ───────────────────────────────────────────────────

function pageCacheInstance(): string
{
    $tid = app()->tenant()->current();
    return $tid !== null ? (PAGE_CACHE_INSTANCE . '_t' . $tid) : PAGE_CACHE_INSTANCE;
}

function pageCacheTtl(): int
{
    static $ttl = null;
    if ($ttl !== null) {
        return $ttl;
    }
    $ttl = PAGE_CACHE_TTL;
    return $ttl;
}

/**
 * Get the TTL for a specific module, falling back to the default.
 */
function pageCacheTtlForModule(string $moduleId): int
{
    if ($moduleId !== '' && isset(PAGE_CACHE_MODULE_TTLS[$moduleId])) {
        return PAGE_CACHE_MODULE_TTLS[$moduleId];
    }
    return pageCacheTtl();
}

// ── Eligibility check ────────────────────────────────────────────────

/**
 * Determine whether the current request is eligible for page caching.
 *
 * Criteria:
 *  1. GET request only
 *  2. No authenticated user (kernel or module cookies)
 *  3. URI not in the skip list
 *  4. Not an AJAX/fetch request expecting JSON
 */
function pageCacheShouldCache(string $uri, string $moduleId = ''): bool
{
    // 1. GET only
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return false;
    }

    // 2. Skip if user is authenticated (kernel cookie)
    if (!app()->cache()->shouldCache($uri)) {
        return false;
    }

    // 3. Check module-specific auth cookie (e.g. cms_token)
    if ($moduleId !== '') {
        $modules = function_exists('getEnabledModules') ? getEnabledModules() : [];
        $moduleCookieName = (string)($modules[$moduleId]['auth_cookie'] ?? '');
        if ($moduleCookieName !== '' && !empty($_COOKIE[$moduleCookieName])) {
            return false;
        }
    }

    // 4. Skip blacklisted prefixes
    foreach (PAGE_CACHE_SKIP_PREFIXES as $prefix) {
        if (str_starts_with($uri, $prefix)) {
            return false;
        }
    }

    // 5. Skip AJAX requests expecting JSON
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    if ($accept !== '' && str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
        return false;
    }

    return true;
}

// ── Cache key ────────────────────────────────────────────────────────

/**
 * Build a deterministic cache key from the request URI + query string.
 */
function pageCacheKey(string $uri): string
{
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    $raw = $uri;
    if ($qs !== '') {
        // Sort query params for determinism
        parse_str($qs, $params);
        ksort($params);
        $raw .= '?' . http_build_query($params);
    }

    // Include BASE_URL origin so multi-domain tenants get separate entries
    $origin = defined('BASE_URL') ? md5(rtrim((string)BASE_URL, '/')) : '0';
    return 'page:' . $origin . ':' . md5($raw);
}

// ── Tags ─────────────────────────────────────────────────────────────

/**
 * Build cache tags for a page entry.
 *
 * Every entry gets:
 *  - pagecache:all          (for full flush)
 *  - pagecache:module:{id}  (for per-module flush)
 *  - pagecache:uri:{hash}   (for surgical per-URL invalidation)
 */
function pageCacheTags(string $uri, string $moduleId): array
{
    $tags = ['pagecache:all'];
    if ($moduleId !== '') {
        $tags[] = 'pagecache:module:' . $moduleId;
    }
    $tags[] = 'pagecache:uri:' . md5($uri);
    return $tags;
}

// ── Get / Set ────────────────────────────────────────────────────────

/**
 * Attempt to retrieve a cached full-page response.
 *
 * Returns ['html' => string, 'status' => int, 'etag' => string] or null.
 */
function pageCacheGet(string $uri): ?array
{
    $key = pageCacheKey($uri);
    $entry = app()->cache()->get(pageCacheInstance(), $key);
    if (!is_array($entry) || !isset($entry['html'])) {
        return null;
    }
    return $entry;
}

/**
 * Store a full-page response in the cache.
 */
function pageCacheSet(string $uri, string $html, string $moduleId, int $status = 200): void
{
    if ($status !== 200) {
        return; // Only cache successful responses
    }
    if (strlen($html) < 100) {
        return; // Don't cache trivially small responses (redirects, errors)
    }

    $etag = md5($html);
    $key = pageCacheKey($uri);
    $tags = pageCacheTags($uri, $moduleId);
    $data = [
        'html' => $html,
        'status' => $status,
        'etag' => $etag,
        'cached_at' => date('Y-m-d H:i:s'),
        'uri' => $uri,
        'module' => $moduleId,
    ];
    app()->cache()->setWithTags(pageCacheInstance(), $key, $data, $tags, pageCacheTtlForModule($moduleId));
}

// ── Serve from cache ─────────────────────────────────────────────────

/**
 * Serve a cached page directly. Returns true if served (caller should exit),
 * false if no cache entry exists.
 */
function pageCacheServe(string $uri): bool
{
    $entry = pageCacheGet($uri);
    if ($entry === null) {
        return false;
    }

    $etag = '"' . ($entry['etag'] ?? md5($entry['html'])) . '"';

    // ETag conditional: return 304 if client has current version
    $clientEtag = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($clientEtag === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: public, no-cache');
        header('X-Page-Cache: hit-304');
        return true;
    }

    // Serve full response
    http_response_code((int)($entry['status'] ?? 200));
    header('Content-Type: text/html; charset=UTF-8');
    header('ETag: ' . $etag);
    header('Cache-Control: public, no-cache');
    header('X-Page-Cache: hit');
    echo $entry['html'];
    return true;
}

// ── Invalidation ─────────────────────────────────────────────────────

/**
 * Invalidate all page cache entries for a specific module.
 */
function pageCacheInvalidateModule(string $moduleId): int
{
    if (!function_exists('app')) {
        return 0;
    }
    return app()->cache()->clearByTags(pageCacheInstance(), ['pagecache:module:' . $moduleId]);
}

/**
 * Invalidate a specific cached URL.
 */
function pageCacheInvalidateUrl(string $uri): int
{
    return app()->cache()->clearByTags(pageCacheInstance(), ['pagecache:uri:' . md5($uri)]);
}

/**
 * Flush the entire page cache (all modules, all URLs).
 */
function pageCacheFlushAll(): int
{
    // Clean up any stale lock files
    pageCacheLockCleanup();
    return app()->cache()->clearByTags(pageCacheInstance(), ['pagecache:all']);
}

// ── Stampede Protection ──────────────────────────────────────────────
//
// Prevents the "cache stampede" / "thundering herd" problem where
// multiple concurrent requests miss the cache simultaneously and all
// rebuild the same expensive page.  Uses flock() so the first request
// builds while others wait briefly for the fresh cache entry.

/**
 * Return the lock directory path.
 */
function pageCacheLockDir(): string
{
    return (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 2) . '/storage')
        . '/cache/page-locks';
}

/**
 * Try to acquire a non-blocking exclusive lock for a page URI.
 *
 * @return resource|false|null  resource on success (caller must release),
 *                              false if another process holds the lock,
 *                              null on I/O error.
 */
function pageCacheLockAcquire(string $uri): mixed
{
    $lockDir = pageCacheLockDir();
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0775, true);
    }

    $lockFile = $lockDir . '/' . md5($uri) . '.lock';
    $fp = @fopen($lockFile, 'c');
    if ($fp === false) {
        return null;
    }

    if (flock($fp, LOCK_EX | LOCK_NB)) {
        return $fp; // Acquired — caller should build the page
    }

    fclose($fp);
    return false; // Lock held by another process
}

/**
 * Release a previously acquired page-cache lock.
 */
function pageCacheLockRelease(mixed $fp): void
{
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Wait for another process to populate the page cache.
 *
 * Polls the cache every 50 ms up to $maxWaitMs.  Returns true if the
 * cache was populated within the wait window.
 */
function pageCacheLockWaitForCache(string $uri, int $maxWaitMs = 2000): bool
{
    $intervalUs = 50_000; // 50 ms
    $iterations = (int)ceil($maxWaitMs * 1000 / $intervalUs);

    for ($i = 0; $i < $iterations; $i++) {
        usleep($intervalUs);
        if (pageCacheGet($uri) !== null) {
            return true;
        }
    }
    return false;
}

/**
 * Remove stale lock files older than 30 seconds.
 */
function pageCacheLockCleanup(): void
{
    $lockDir = pageCacheLockDir();
    if (!is_dir($lockDir)) {
        return;
    }

    $cutoff = time() - 30;
    foreach (glob($lockDir . '/*.lock') as $file) {
        if (@filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

/**
 * Reset runtime state (for tests).
 */
function pageCacheResetRuntimeState(): void
{
    // Nothing stateful beyond the static $ttl in pageCacheTtl, but that's fine
    // for a 1-request lifecycle.
}
