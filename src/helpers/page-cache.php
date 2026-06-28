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

// ── Skip prefixes (single source of truth from shared config) ──
define('PAGE_CACHE_SKIP_PREFIXES', require __DIR__ . '/../../config/page-cache-prefixes.php');

// ── Cache version tracking ──
// Monotonically increasing counter bumped on every invalidation.
// Stored in APCu + file so both the fast-path (pre-bootstrap, APCu)
// and the full kernel (file fallback) can read it.

function pageCacheVersionKey(string $instance): string
{
    return 'pagecache:version:' . $instance;
}

function pageCacheVersionFile(string $instance): string
{
    return rtrim((string)(defined('STORAGE_PATH') ? STORAGE_PATH : (defined('BASE_PATH') ? BASE_PATH . '/storage' : dirname(__DIR__, 3) . '/storage')), '/') . '/cache/' . $instance . '/.cache_version';
}

function pageCacheReadVersion(string $instance): int
{
    if (function_exists('apcu_fetch')) {
        $v = apcu_fetch(pageCacheVersionKey($instance));
        if (is_int($v) && $v > 0) {
            return $v;
        }
    }
    $f = pageCacheVersionFile($instance);
    if (is_file($f)) {
        $v = (int)@file_get_contents($f);
        if ($v > 0) {
            return $v;
        }
    }
    return 0;
}

function pageCacheBumpVersion(string $instance): void
{
    $v = pageCacheReadVersion($instance) + 1;
    if (function_exists('apcu_store')) {
        apcu_store(pageCacheVersionKey($instance), $v, 86400);
    }
    $dir = dirname(pageCacheVersionFile($instance));
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(pageCacheVersionFile($instance), (string)$v);
}

// ── Flush file for mtime-based fast-path invalidation ──

function pageCacheFlushFilePath(string $instance): string
{
    return rtrim((string)(defined('STORAGE_PATH') ? STORAGE_PATH : (defined('BASE_PATH') ? BASE_PATH . '/storage' : dirname(__DIR__, 3) . '/storage')), '/') . '/cache/' . $instance . '/.flush';
}

function pageCacheTouchFlush(string $instance): void
{
    $f = pageCacheFlushFilePath($instance);
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @touch($f);
}

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

    // Do not cache pages that contain a CSRF token — the cached token
    // would be served to a different session, causing 419 on form submit.
    if (pageCacheHtmlHasCsrfToken($html)) {
        return;
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
        '_cache_version' => pageCacheReadVersion(pageCacheInstance()),
    ];
    app()->cache()->setWithTags(pageCacheInstance(), $key, $data, $tags, pageCacheTtlForModule($moduleId));
}

// ── CSRF token detection ─────────────────────────────────────────────

/**
 * Check if rendered HTML contains a CSRF token hidden field.
 * Pages with CSRF tokens must NOT be page-cached because the cached
 * token would be served to a different session, causing 419 on POST.
 *
 * Matches common CSRF field patterns:
 *   <input type="hidden" name="csrf_token" value="...">
 *   <input type="hidden" name="_token" value="...">
 *   <input type="hidden" name="ikabud_csrf" value="...">
 */
function pageCacheHtmlHasCsrfToken(string $html): bool
{
    // Fast pre-check: only scan if HTML contains a hidden input
    if (!str_contains($html, 'type="hidden"') && !str_contains($html, "type='hidden'")) {
        return false;
    }

    $patterns = [
        '/<input[^>]+name=["\'](?:csrf_token|_token|ikabud_csrf|csrf|__csrf)["\'][^>]*>/i',
        '/<input[^>]+name=["\']csrf_token["\'][^>]*>/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html)) {
            return true;
        }
    }

    return false;
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
    $instance = pageCacheInstance();
    pageCacheBumpVersion($instance);
    pageCacheTouchFlush($instance);
    return app()->cache()->clearByTags($instance, ['pagecache:module:' . $moduleId]);
}

/**
 * Invalidate a specific cached URL.
 */
function pageCacheInvalidateUrl(string $uri): int
{
    $instance = pageCacheInstance();
    pageCacheBumpVersion($instance);
    pageCacheTouchFlush($instance);
    return app()->cache()->clearByTags($instance, ['pagecache:uri:' . md5($uri)]);
}

/**
 * Flush the entire page cache (all modules, all URLs).
 */
function pageCacheFlushAll(): int
{
    pageCacheLockCleanup();
    $instance = pageCacheInstance();
    pageCacheBumpVersion($instance);
    pageCacheTouchFlush($instance);
    return app()->cache()->clearByTags($instance, ['pagecache:all']);
}

// ── Cache Warm-Up ────────────────────────────────────────────────────
//
// Proactively populates the page cache after content changes so the next
// real visitor gets an instant cache hit instead of a cold miss.

/**
 * Warm the page cache for a list of URL paths.
 *
 * Makes internal HTTP sub-requests to populate the page cache. Each URL
 * is fetched in sequence; failures are logged but do not throw.
 *
 * Usage after CMS content save/publish:
 *   pageCacheWarm(['/blog/my-post', '/about', '/']);
 *
 * @param list<string> $urls  URL paths relative to the app root (e.g. '/about')
 * @param int          $timeoutMs  Max time per URL before giving up
 * @return array{success: int, failed: int}  Count of warmed vs failed URLs
 */
function pageCacheWarm(array $urls, int $timeoutMs = 3000): array
{
    $success = 0;
    $failed = 0;
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : (external_base_url('') ?: 'http://localhost')), '/');

    foreach ($urls as $url) {
        $url = trim((string)$url);
        if ($url === '' || $url[0] !== '/') {
            $failed++;
            continue;
        }

        $fullUrl = $baseUrl . $url;
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => max(1, (int)($timeoutMs / 1000)),
                'header'  => "Accept: text/html\r\nUser-Agent: Ikabud-PageCache-Warmer/1.0\r\n",
                'ignore_errors' => true,
            ],
        ]);

        try {
            $result = @file_get_contents($fullUrl, false, $ctx);
            if ($result !== false && strlen($result) > 100) {
                $success++;
            } else {
                $failed++;
                if (function_exists('write_log')) {
                    write_log("pageCacheWarm failed for {$url}: empty or short response", 'warning');
                }
            }
        } catch (\Throwable $e) {
            $failed++;
            if (function_exists('write_log')) {
                write_log("pageCacheWarm failed for {$url}: " . $e->getMessage(), 'warning');
            }
        }
    }

    return ['success' => $success, 'failed' => $failed];
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
