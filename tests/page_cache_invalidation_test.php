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

// ── Test-owned fixture instances ──────────────────────────────────────────────
// `pageCacheInstanceNames()` globs `<base>_t*` DIRECTORIES. On a machine with no tenant
// cache dirs - the normal state after `php ikabud cache:clear`, which removes them - the
// tenant-coverage assertion at (d) degenerates: `$expectedTenants` is empty and
// `array_diff([], [])` is trivially true. Section (g) then prints "the ordering guard is
// vacuous here" and skips itself. A guard that cannot fail is worse than no guard because
// it is trusted, so create the instances this test needs rather than inheriting whatever
// happens to be on disk. Removed again in the teardown below.
$fixtureBase = defined('PAGE_CACHE_INSTANCE') ? (string)PAGE_CACHE_INSTANCE : 'pagecache';
$fixtureInstances = [$fixtureBase . '_t901', $fixtureBase . '_t902'];
foreach ($fixtureInstances as $fixtureInstance) {
    @mkdir(STORAGE_PATH . '/cache/' . $fixtureInstance, 0775, true);
}
// The instances this test may write to: it created the fixtures, and it owns the base directory.
// A REAL tenant instance (`pagecache_t1` and friends) belongs to the web user, mode 755 - so a CLI
// run can neither write a marker into it nor remove a file from it, and whether one exists at all
// depends on which tenants happened to receive traffic. Asserting over those produces a false red
// that teaches nothing, and deleting them would be destructive to live state.
$ownedInstances = array_merge([$fixtureBase], $fixtureInstances);

echo "instance: {$instance}\n";
echo 'fixture instances: ' . implode(', ', $fixtureInstances) . "\n\n";

// ── Baseline: a written entry is served ───────────────────────────────────────
// Normalise the flush marker BEFORE the baseline write, so this test starts from a state it
// owns rather than inheriting one.
//
// Without this it cannot be run twice in a row. Section (g) purges the base instance, which
// touches `.flush` to `time()`; the next run then writes its baseline entry inside that same
// second, and pageCacheGet deliberately treats an entry written in the same second as the flush
// as stale - mtimes have one-second resolution, so same-second is genuinely ambiguous and
// rejecting is the safer choice (see the `>=` comment in pageCacheGet). The failure was
// therefore the test being non-repeatable, NOT the freshness rule being wrong: fixing the
// comparison to `>` would have made a purge in the same second as a build serve that build
// stale until TTL.
$flushFile = pageCacheFlushFilePath($instance);
@touch($flushFile, time() - 3600);
clearstatcache(true, $flushFile);

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
// Tokens are opaque strings compared by EQUALITY, not integers compared by ordering. Asserting `>`
// would encode the old counter contract and fail the moment the value became a token - and it would
// also be asserting the wrong property, since a lost bump is exactly what uniqueness fixes.
pciAssert(
    'the version actually advanced',
    pageCacheReadVersion($instance) !== $versionBefore,
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
$markerChecked = [];
foreach (array_keys($report) as $inst) {
    if (!in_array($inst, $ownedInstances, true)) {
        continue;
    }
    $markerChecked[] = $inst;
    if (!is_file(pageCacheFlushFilePath($inst))) {
        $markersOk = false;
    }
}
pciAssert(
    'every purged instance has a flush marker afterwards',
    $markersOk,
    'checked: ' . implode(', ', $markerChecked)
);

// ── g) A captured instance list must survive its directories being deleted ────
// This is the flaw `php ikabud cache:clear` had: it called clearAll() first, which removes the
// tenant cache directories, and only then enumerated them - so the glob found nothing and those
// tenants were never purged while the command reported success.
//
// Removal is restricted to the fixtures. Deleting a real tenant directory would be destructive to
// live state, and it fails silently anyway when the directory belongs to the web user - so the
// guard has to act on state it controls to prove anything. Because the fixtures always exist this
// branch no longer needs the "vacuous here" skip it used to print.
$captured = array_merge([$fixtureBase], $fixtureInstances);
foreach ($fixtureInstances as $inst) {
    $dir = STORAGE_PATH . '/cache/' . $inst;
    // GLOB_BRACE for `.*` is required: the purge writes `.cache_version` and `.flush`,
    // and plain `glob('dir/*')` does not match dotfiles, so those two survive and the
    // rmdir below fails silently - leaving the directory in place while this section
    // claims to have removed it.
    foreach (glob($dir . '/{*,.*}', GLOB_BRACE) ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
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

// ── f) The entry is gone after the purge ──────────────────────────────────────
pciAssert('a purged entry is no longer served', pageCacheGet($uri) === null);

app()->cache()->clearByUrlPattern($instance, $uri);

// ── h) Stale page locks must not accumulate forever ───────────────────────────
// Exercised against a directory this test OWNS. The live lock directory is created by the web user
// (www-data, mode 755) and is legitimately not writable from the CLI: writing there returns false,
// which made the "stale lock removed" assertion pass VACUOUSLY - the file was never created - while
// "fresh lock kept" failed for the same reason. A guard must not be satisfiable by its own setup
// failing, so the fixtures are written to a temp directory and their creation is asserted first.
$lockDir = sys_get_temp_dir() . '/ikabud-page-lock-test-' . getmypid();
@mkdir($lockDir, 0775, true);
$staleLock = $lockDir . '/pctest-stale.lock';
$freshLock = $lockDir . '/pctest-fresh.lock';

$staleWritten = @file_put_contents($staleLock, '') !== false;
$freshWritten = @file_put_contents($freshLock, '') !== false;
pciAssert(
    'the lock fixtures were created (so the sweep assertions are not vacuous)',
    $staleWritten && $freshWritten
);

@touch($staleLock, time() - 7200);
pageCacheSweepStaleLocks(3600, $lockDir);

pciAssert('a stale page lock is removed', !is_file($staleLock));
pciAssert('a page lock still in use is kept', is_file($freshLock));

// Must-refuse: the rate limiter has to stop an immediate second sweep, so a lock created moments ago
// cannot be collected by a sweep that is already due.
@file_put_contents($staleLock, '');
@touch($staleLock, time() - 7200);
$secondPass = pageCacheSweepStaleLocks(3600, $lockDir);
pciAssert(
    'a second sweep within the interval removes nothing',
    $secondPass === 0 && is_file($staleLock) && is_file($freshLock),
    "sweep returned {$secondPass}"
);

// ── i) A purge that could not take effect must say so ─────────────────────────
// `pageCacheBumpVersion()` returns void and `@`-suppresses every write, so a purge run by a user
// who does not own the instance directory writes nothing and says nothing. `php ikabud cache:clear`
// then printed "✓ Page cache pagecache_t1: version (none yet) → , flush marker touched" - a green
// tick over a purge that never happened, on the one signal that stops a warm APCu worker serving
// pre-hash HTML. `ok` is what lets the command refuse to tick it.
$landedInstance = $fixtureBase . '_t903';
$blockedInstance = $fixtureBase . '_t904';
$landedDir = STORAGE_PATH . '/cache/' . $landedInstance;
$blockedDir = STORAGE_PATH . '/cache/' . $blockedInstance;
@mkdir($landedDir, 0775, true);
@mkdir($blockedDir, 0775, true);
// Make the bump unable to land. The directory is ours, so this works; a write needs write
// permission on the DIRECTORY, not on the file.
@chmod($blockedDir, 0555);

$purgeReport = pageCacheInvalidateInstances([$landedInstance, $blockedInstance]);

pciAssert(
    'a purge that landed reports ok',
    !empty($purgeReport[$landedInstance]['ok']),
    'version read back: ' . var_export($purgeReport[$landedInstance]['version_after'] ?? null, true)
);
pciAssert(
    'a purge that could NOT land reports not-ok (this is what printed a false green)',
    empty($purgeReport[$blockedInstance]['ok']),
    'version read back: ' . var_export($purgeReport[$blockedInstance]['version_after'] ?? null, true)
);

@chmod($blockedDir, 0775);

// ── Teardown: remove only the fixture instances this test created ─────────────
// Section (g) already deletes them; tolerate that. Never touch a real tenant's directory -
// only the two names this test minted.
foreach ($fixtureInstances as $fixtureInstance) {
    $fixtureDir = STORAGE_PATH . '/cache/' . $fixtureInstance;
    foreach (glob($fixtureDir . '/{*,.*}', GLOB_BRACE) ?: [] as $fixtureFile) {
        if (is_file($fixtureFile)) {
            @unlink($fixtureFile);
        }
    }
    @rmdir($fixtureDir);
}

foreach ([$landedInstance => $landedDir, $blockedInstance => $blockedDir] as $leftoverDir) {
    foreach (glob($leftoverDir . '/{*,.*}', GLOB_BRACE) ?: [] as $leftoverFile) {
        if (is_file($leftoverFile)) {
            @unlink($leftoverFile);
        }
    }
    @chmod($leftoverDir, 0775);
    @rmdir($leftoverDir);
}

// Lock fixtures: remove the temp lock directory this test created, so no fixture state is left on
// disk. The live lock directory is never touched - it is not writable from here, and nothing this
// test does should depend on that changing.
foreach (glob($lockDir . '/{*,.*}', GLOB_BRACE) ?: [] as $lockFile) {
    if (is_file($lockFile)) {
        @unlink($lockFile);
    }
}
@rmdir($lockDir);

echo "\n============================================================\n";
echo "Page cache invalidation tests: {$passed} passed, {$failed} failed\n";
echo "============================================================\n";

exit($failed === 0 ? 0 : 1);
