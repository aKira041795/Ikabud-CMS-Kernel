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
    // File first, APCu second. The version is memoised in APCu for 24h and APCu is per-worker,
    // so a memo written before a CLI purge would shadow the bumped file forever and the purge
    // would silently do nothing. One small read per lookup is far cheaper than that failure.
    $f = pageCacheVersionFile($instance);
    if (is_file($f)) {
        $v = (int)@file_get_contents($f);
        if ($v > 0) {
            return $v;
        }
    }
    if (function_exists('apcu_fetch')) {
        $v = apcu_fetch(pageCacheVersionKey($instance));
        if (is_int($v) && $v > 0) {
            return $v;
        }
    }
    return 0;
}

function pageCacheBumpVersion(string $instance): void
{
    // lean-ctx: known limitation, deliberately NOT changed on 2026-10-11.
    //
    // This is an unlocked read-modify-write (+1), so two concurrent purges can read the same value
    // and write the same new one, and a plain file_put_contents can in principle be observed
    // mid-write. The consequence would be a purge that fails to advance the version.
    //
    // It is NOT fixed here because the version is only ONE of two independent purge signals: every
    // purge path also calls pageCacheTouchFlush(), and pageCacheGet() rejects an entry whose
    // _cached_at_ts is not strictly newer than .flush mtime. Two same-second failures are required
    // for a stale serve, so the exposure is small.
    //
    // Converting this to the shared token authority (cacheGenerationToken() + atomic rename) is the
    // right fix, but NOT a local one: the value is also read by the pre-bootstrap fast path
    // (src/helpers/fast-path-cache.php:200 as (int)file_get_contents, compared at :219 and :267).
    // A token read through that (int) cast becomes 0, which makes the `$currentVersion > 0` guard
    // fail and SKIPS the version check entirely - silently disabling purge honouring on the path
    // that serves HTML before the kernel boots. That is the exact defect class fixed on the same
    // day, so it must be changed across all three consumers at once, with the fast path's own
    // tests as the gate.
    //
    // Upgrade trigger: any observed instance of a purge that did not take effect, or the fast path
    // gaining string-safe version handling for another reason.
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
    // Developer/debug bypass: force dynamic render when explicit nocache flag is present.
    if (!empty($_GET['disyl_nocache']) || !empty($_GET['nocache'])) {
        return false;
    }

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
    return 'page:v2:' . $origin . ':' . md5($raw);
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
    $instance = pageCacheInstance();
    $entry = app()->cache()->get($instance, $key);
    if (!is_array($entry) || !isset($entry['html'])) {
        return null;
    }

    // Honour the SAME purge signals as the pre-bootstrap fast path.
    //
    // THE DEFECT THIS FIXES: this layer had no version and no flush check at all, so it answered
    // every purge with its warm entry. Bumping `.cache_version` and touching `.flush` is the only
    // way a purge can reach every php-fpm worker - APCu is per-worker and cannot be cleared from
    // CLI - and the kernel layer simply ignored both signals. That is why `php ikabud cache:clear`
    // appeared to do nothing and a theme edit looked like it had not been saved.
    $currentVersion = pageCacheReadVersion($instance);
    if ($currentVersion > 0
        && isset($entry['_cache_version'])
        && (int)$entry['_cache_version'] !== $currentVersion
    ) {
        return null;
    }

    // An entry that cannot be dated cannot be proven to predate the last flush, so it is stale.
    // Note the explicit `$entryStamp === 0` branch: writing `$entryStamp > 0 && ...` would SKIP
    // the check for exactly the entries that need it most.
    // `>=` rather than `>`: file mtimes have one-second resolution, so an entry written in the
    // same second as the purge would otherwise be accepted and served stale until TTL.
    $entryStamp = (int)($entry['_cached_at_ts'] ?? 0);
    if ($entryStamp === 0 && isset($entry['cached_at'])) {
        $entryStamp = (int)strtotime((string)$entry['cached_at']);
    }
    $flushFile = pageCacheFlushFilePath($instance);
    if (is_file($flushFile) && ($entryStamp === 0 || filemtime($flushFile) >= $entryStamp)) {
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
        // Numeric write time. The pre-bootstrap fast path compares this against the `.flush`
        // mtime to decide whether a purge has happened since the entry was written, and that
        // comparison is the ONLY invalidation signal that crosses a php-fpm worker boundary
        // (APCu is per-worker and cannot be cleared from CLI). Without this key the check was
        // skipped entirely and every cached entry was served until TTL expired.
        '_cached_at_ts' => time(),
        'uri' => $uri,
        'module' => $moduleId,
        '_cache_version' => pageCacheReadVersion(pageCacheInstance()),
        // Header policy travels WITH the entry so a consumer that cannot boot the kernel can still
        // emit the same security headers. See pageCacheResponseHeaders().
        'headers' => pageCacheResponseHeaders(),
    ];
    app()->cache()->setWithTags(pageCacheInstance(), $key, $data, $tags, pageCacheTtlForModule($moduleId));
}

/**
 * Build the response security headers that must accompany a cached page.
 *
 * The pre-bootstrap fast path serves cached HTML WITHOUT booting the kernel, so it cannot call
 * SecurityHeaders itself. Storing the header set in the entry keeps the two paths from drifting:
 * what the fast path replays is what the kernel produced. A consumer that finds no stored header
 * policy must refuse to serve the entry rather than emit a page without CSP.
 *
 * Safe to cache because the policy is deterministic per scheme/host and deliberately carries no
 * CSP nonce (see SecurityHeaders::buildCspHeaderValue()).
 *
 * @return array<int,string>
 */
function pageCacheResponseHeaders(): array
{
    if (!class_exists('Ikabud\\Kernel\\Http\\SecurityHeaders')) {
        return [];
    }
    try {
        $headers = (new \Ikabud\Kernel\Http\SecurityHeaders())->headers();
    } catch (\Throwable $e) {
        return [];
    }

    // Never persist a header that could inject a second header line.
    return array_values(array_filter(
        is_array($headers) ? $headers : [],
        static fn ($h) => is_string($h) && $h !== '' && !str_contains($h, "\n") && !str_contains($h, "\r")
    ));
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
    // Fast pre-check: only scan if HTML contains a hidden input or a CSRF meta tag
    if (!str_contains($html, 'type="hidden"')
        && !str_contains($html, "type='hidden'")
        && !preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]*>/i', $html)) {
        return false;
    }

    $patterns = [
        '/<input[^>]+name=["\'](?:csrf_token|_token|ikabud_csrf|csrf|__csrf)["\'][^>]*>/i',
        '/<input[^>]+name=["\']csrf_token["\'][^>]*>/i',
        // HARPP (and future pages) expose the session token via a meta tag
        // (modules/harpp templates layout.disyl emits <meta name="csrf-token" ...>).
        '/<meta[^>]+name=["\']csrf-token["\'][^>]*>/i',
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

/**
 * List every page-cache instance that must be invalidated together: the base instance plus one
 * per tenant.
 *
 * MUST BE CALLED BEFORE ANY BULK FILE CLEAR. `Cache::clearAll()` deletes the tenant cache
 * directories, so globbing afterwards silently returns nothing and those tenants are never
 * purged - which is exactly how a purge can report success while stale pages keep being served.
 *
 * Symlinked directories are skipped: this function's output is used to WRITE marker files into
 * each name, and following a symlink would let a purge write outside the cache tree.
 *
 * @return string[]
 */
function pageCacheInstanceNames(): array
{
    $base = defined('PAGE_CACHE_INSTANCE') ? (string)PAGE_CACHE_INSTANCE : 'pagecache';
    $storagePath = rtrim((string)(defined('STORAGE_PATH')
        ? STORAGE_PATH
        : (defined('BASE_PATH') ? BASE_PATH . '/storage' : dirname(__DIR__, 3) . '/storage')), '/');

    $names = [$base];
    foreach (glob($storagePath . '/cache/' . $base . '_t*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (is_link($dir)) {
            continue;
        }
        $names[] = basename($dir);
    }
    // A tenant can be served before its cache dir exists; cover the current instance too.
    if (function_exists('pageCacheInstance')) {
        $names[] = pageCacheInstance();
    }

    return array_values(array_unique(array_filter($names, static fn ($i) => $i !== '')));
}

/**
 * Bump the version and touch the flush marker for each named instance.
 *
 * WHY BOTH MARKERS MATTER: `pageCacheFlushAll()` only invalidates `pageCacheInstance()`, which
 * from CLI is the tenant-less `pagecache` instance because there is no tenant context, so a CLI
 * purge used to leave `pagecache_t1` untouched. Deleting cache files is also NOT sufficient -
 * APCu is per-worker, cannot be cleared from CLI, and a warm entry survives the file being gone.
 * The `.flush` mtime is read fresh from disk by every worker on every request, which is what
 * actually crosses the worker boundary; the version catches workers that have a readable file.
 *
 * @param string[] $instances
 * @return array<string,array{version_before:int,version_after:int,flush_file:string}>
 */
function pageCacheInvalidateInstances(array $instances): array
{
    $report = [];
    foreach ($instances as $instance) {
        $before = pageCacheReadVersion($instance);
        pageCacheBumpVersion($instance);
        pageCacheTouchFlush($instance);
        $report[$instance] = [
            'version_before' => $before,
            'version_after' => pageCacheReadVersion($instance),
            'flush_file' => pageCacheFlushFilePath($instance),
        ];
    }

    return $report;
}

/**
 * Invalidate every page-cache instance in one call. Prefer capturing pageCacheInstanceNames()
 * BEFORE a bulk file clear and passing it to pageCacheInvalidateInstances().
 *
 * @return array<string,array{version_before:int,version_after:int,flush_file:string}>
 */
function pageCacheInvalidateAllInstances(): array
{
    return pageCacheInvalidateInstances(pageCacheInstanceNames());
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
