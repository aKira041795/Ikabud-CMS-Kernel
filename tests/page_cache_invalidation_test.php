<?php
/**
 * Page-cache invalidation must actually invalidate.
 *
 * THE DEFECTS THIS GUARDS (measured 2026-10-11):
 *
 * 1. `pageCacheGet()` — the layer that actually serves a cached CMS page — had NO version
 *    check and NO flush check. `php ikabud cache:clear` bumped `.cache_version` and touched
 *    `.flush`, and this layer ignored both, so a purge appeared to do nothing and a theme
 *    edit looked like it had never been saved.
 *
 * 2. `pageCacheSet()` never wrote `_cached_at_ts`, and the pre-bootstrap fast path tested
 *    `!isset($cached['_cached_at_ts'])` as an ACCEPT condition. That is true for every entry
 *    the writer produced, so the `.flush` comparison — the only purge signal that crosses a
 *    php-fpm worker boundary, since APCu is per-worker and cannot be cleared from CLI — was
 *    skipped unconditionally.
 *
 * 3. `pageCacheFlushAll()` only invalidated `pageCacheInstance()`, which from CLI is the
 *    tenant-less `pagecache` instance. A CLI purge therefore never touched `pagecache_t1`.
 *
 * 4. `pageCacheReadVersion()` consulted APCu first, memoised for 24h, so a bumped version
 *    FILE never reached a worker holding an older memo.
 *
 * The assertions are OUTCOME level: "after a purge signal, the entry is no longer served",
 * not "a particular key was written".
 *
 * Run: php tests/page_cache_invalidation_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/page-cache.php';
ob_end_clean();

$passed = 0;
$failed = 0;
function pciAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? "\n      {$detail}" : '') . "\n";
    $failed++;
}

echo "=== PAGE CACHE INVALIDATION ===\n\n";

$instance = pageCacheInstance();
$uri = '/__page-cache-invalidation-test__';
$key = pageCacheKey($uri);

echo "instance: {$instance}\n\n";

// ── Baseline: a written entry is served ───────────────────────────────────────
app()->cache()->clearByUrlPattern($instance, $uri);
pageCacheSet($uri, str_repeat('A', 200), 'test', 200);

$entry = pageCacheGet($uri);
pciAssert('a written entry is served', is_array($entry) && isset($entry['html']));

// ── The writer must stamp the entry ───────────────────────────────────────────
$raw = app()->cache()->get($instance, $key);
pciAssert(
    'pageCacheSet() stamps the entry with a numeric write time',
    is_array($raw) && isset($raw['_cached_at_ts']) && (int)$raw['_cached_at_ts'] > 0,
    'keys: ' . implode(',', array_keys(is_array($raw) ? $raw : []))
);

// ── a) Version bump alone must invalidate ─────────────────────────────────────
pageCacheSet($uri, str_repeat('B', 200), 'test', 200);
$versionBefore = pageCacheReadVersion($instance);
pageCacheBumpVersion($instance);
pciAssert(
    'the version actually advanced',
    pageCacheReadVersion($instance) > $versionBefore,
    "before={$versionBefore} after=" . pageCacheReadVersion($instance)
);
pciAssert('a version bump makes the entry unservable', pageCacheGet($uri) === null);

// ── b) A flush touch alone must invalidate ────────────────────────────────────
// Write under the CURRENT version so only the flush signal can reject it. This is the case
// that used to pass silently: the entry was undatable, so every purge missed it.
pageCacheSet($uri, str_repeat('C', 200), 'test', 200);
pciAssert('the entry is servable again under the new version', pageCacheGet($uri) !== null);

$flushFile = pageCacheFlushFilePath($instance);
$future = time() + 5;
@touch($flushFile, $future);
clearstatcache(true, $flushFile);
pciAssert('a flush marker newer than the entry makes it unservable', pageCacheGet($uri) === null);
@touch($flushFile, time() - 3600); // restore to a harmless past value
clearstatcache(true, $flushFile);

// ── c) An undatable entry must not be trusted ─────────────────────────────────
pageCacheSet($uri, str_repeat('D', 200), 'test', 200);
$undatable = app()->cache()->get($instance, $key);
if (is_array($undatable)) {
    unset($undatable['_cached_at_ts'], $undatable['cached_at']);
    app()->cache()->set($instance, $key, $undatable);
    @touch($flushFile, time() + 5);
    clearstatcache(true, $flushFile);
    pciAssert(
        'an entry that cannot be dated is treated as stale, not trusted',
        pageCacheGet($uri) === null
    );
    @touch($flushFile, time() - 3600);
    clearstatcache(true, $flushFile);
} else {
    pciAssert('the entry could be read back for the undatable case', false);
}

// ── d) The purge must cover every instance, not just the current one ──────────
$report = pageCacheInvalidateAllInstances();
pciAssert('the purge reports at least the base instance', $report !== [], 'empty report');

$base = defined('PAGE_CACHE_INSTANCE') ? (string)PAGE_CACHE_INSTANCE : 'pagecache';
$expectedTenants = [];
foreach (glob(STORAGE_PATH . '/cache/' . $base . '_t*', GLOB_ONLYDIR) ?: [] as $dir) {
    $expectedTenants[] = basename($dir);
}
$missing = array_diff($expectedTenants, array_keys($report));
pciAssert(
    'the purge covers every tenant page-cache instance on disk',
    $missing === [],
    'missing: ' . implode(', ', $missing)
);
echo '      (instances: ' . implode(', ', array_keys($report)) . ")\n";

// ── e) Every purge must leave BOTH markers in place ───────────────────────────
// The fast path treats a missing `.flush` as "accept", so a purge that deletes the marker
// and does not re-create it would leave every cached entry permanently valid.
$markersOk = true;
foreach (array_keys($report) as $inst) {
    if (!is_file(pageCacheFlushFilePath($inst))) {
        $markersOk = false;
    }
}
pciAssert('every purged instance has a flush marker afterwards', $markersOk);

// ── g) A captured instance list must survive its directories being deleted ────
// This is the flaw `php ikabud cache:clear` had: it called clearAll() first, which removes the
// tenant cache directories, and only then enumerated them - so the glob found nothing and those
// tenants were never purged while the command reported success.
$captured = pageCacheInstanceNames();
$tenantInstances = array_values(array_filter($captured, static fn ($i) => $i !== $base));

if ($tenantInstances === []) {
    echo "      (no tenant instances on disk; the ordering guard is vacuous here)\n";
} else {
    foreach ($tenantInstances as $inst) {
        $dir = STORAGE_PATH . '/cache/' . $inst;
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    $afterRemoval = pageCacheInvalidateInstances($captured);
    $uncovered = array_values(array_diff($captured, array_keys($afterRemoval)));
    pciAssert(
        'a pre-captured instance list still purges after the directories are removed',
        $uncovered === [],
        'not covered: ' . implode(', ', $uncovered)
    );

    $recreated = true;
    foreach ($captured as $inst) {
        if (!is_file(pageCacheFlushFilePath($inst))) {
            $recreated = false;
        }
    }
    pciAssert('purging a removed instance re-creates its flush marker', $recreated);
}

// ── f) The entry is gone after the purge ──────────────────────────────────────
pciAssert('a purged entry is no longer served', pageCacheGet($uri) === null);

app()->cache()->clearByUrlPattern($instance, $uri);

echo "\n============================================================\n";
echo "Page cache invalidation tests: {$passed} passed, {$failed} failed\n";
echo "============================================================\n";

exit($failed === 0 ? 0 : 1);
