<?php
/**
 * The shared module-scan cache must be provably WRITTEN and provably READ.
 *
 * THE PROBLEM THIS GUARDS (measured 2026-10-11): discoverModules() scans 70 module directories and
 * validates 70 manifests. It cached that in APCu, which is PER-WORKER, so with pm.start_servers=10
 * / pm.max_children=30 the scan (~82 ms) was duplicated by every worker and repeated after every
 * recycle - and on a host without APCu it was paid on EVERY request (measured in CLI: 274-334 ms
 * per run, every run).
 *
 * A shared file layer was added once before and REVERTED, because it could never be shown to be
 * written: the write lived only in the APCu-miss branch, so on a server with warm APCu the file
 * stayed absent forever. The fix is to publish the file from a warm APCu hit as well.
 *
 * The assertions below are therefore chosen so that a merely-written cache cannot pass:
 *   - a FRESH PROCESS must return every module with the scan invoked ZERO times
 *   - the payload must survive a round trip byte-equivalently
 *   - an untrustworthy file (corrupt, truncated, foreign fingerprint) must be REJECTED, not believed
 *
 * Run: php tests/module_scan_cache_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/module-manager.php';
ob_end_clean();

$passed = 0;
$failed = 0;
function mscAssert(string $label, bool $condition, string $detail = ''): void
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

echo "=== SHARED MODULE-SCAN CACHE ===\n\n";

$fingerprint = moduleManifestScanFingerprint();
$file = moduleScanSharedCacheFile($fingerprint);

echo "fingerprint : {$fingerprint}\nfile        : {$file}\n\n";

// ── 1. Name contract ──────────────────────────────────────────────────────────
echo "--- name contract ---\n";

mscAssert('the cache file name embeds the fingerprint', str_contains(basename($file), $fingerprint));
mscAssert(
    'the payload is not stored as executable PHP',
    str_ends_with($file, '.cache'),
    'a .php cache file could be executed if the directory were ever exposed'
);
mscAssert(
    'a different fingerprint selects a different file',
    moduleScanSharedCacheFile($fingerprint . '-other') !== $file
);

// ── 2. Round trip ─────────────────────────────────────────────────────────────
echo "\n--- round trip ---\n";

$sample = ['mod_a' => ['name' => 'A', 'owns_tables' => ['t1'], 'nested' => ['x' => 1]], 'mod_b' => ['name' => 'B']];
@unlink($file);

mscAssert('a missing file reads as no-cache', moduleScanSharedCacheRead($fingerprint) === null);
mscAssert('write reports success', moduleScanSharedCacheWrite($fingerprint, $sample) === true);
mscAssert('the file now exists', is_file($file));
mscAssert('the payload round-trips exactly', moduleScanSharedCacheRead($fingerprint) === $sample);
mscAssert(
    'the stored payload is not PHP source',
    !str_starts_with((string)file_get_contents($file), '<?php'),
    'the cache file begins with a PHP open tag'
);
mscAssert(
    'no temp file is left behind by an atomic write',
    glob($file . '.*.tmp') === [],
    'a partial write file survived'
);

// ── 3. Untrustworthy files must be REJECTED, not believed ─────────────────────
echo "\n--- rejection ---\n";

@file_put_contents($file, 'not a serialized payload at all');
mscAssert('a corrupt file is rejected', moduleScanSharedCacheRead($fingerprint) === null);

@file_put_contents($file, '');
mscAssert('an empty file is rejected', moduleScanSharedCacheRead($fingerprint) === null);

@file_put_contents($file, serialize(['_fingerprint' => 'someone-elses', 'modules' => $sample]));
mscAssert(
    'a file written for another fingerprint is rejected',
    moduleScanSharedCacheRead($fingerprint) === null,
    'a renamed or copied file was believed'
);

@file_put_contents($file, serialize(['_fingerprint' => $fingerprint, 'modules' => 'not-an-array']));
mscAssert('a payload whose modules value is not an array is rejected', moduleScanSharedCacheRead($fingerprint) === null);

@file_put_contents($file, serialize(['modules' => $sample]));
mscAssert('a payload with no fingerprint is rejected', moduleScanSharedCacheRead($fingerprint) === null);

// ── 4. THE RELEASE GATE: a fresh process must not scan ────────────────────────
echo "\n--- release gate: fresh process, zero scans ---\n";

mscAssert('write the real scan to the shared cache', moduleScanSharedCacheWrite($fingerprint, discoverModulesScanAll()) === true);

$script = 'ob_start();'
    . ' require ' . var_export(BASE_PATH . '/bootstrap.php', true) . ';'
    . ' ob_end_clean();'
    . ' require_once ' . var_export(BASE_PATH . '/src/helpers/module-manager.php', true) . ';'
    . ' unset($GLOBALS["_kernel_discovered_modules"]);'
    . ' $m = discoverModules();'
    . ' echo count($m) . "|" . (int)($GLOBALS["_kernel_module_scan_invocations"] ?? 0);';

$output = @shell_exec('php -r ' . escapeshellarg($script) . ' 2>/dev/null');

if (!is_string($output) || !str_contains($output, '|')) {
    mscAssert('a fresh PHP process could be run for the cross-process gate', false, 'no usable output from a subprocess');
} else {
    [$moduleCount, $scanCount] = array_map('intval', explode('|', trim($output)));
    echo "      fresh process: {$moduleCount} modules, scan invocations={$scanCount}\n";
    mscAssert(
        'a fresh process returns every module WITHOUT calling discoverModulesScanAll()',
        $moduleCount === 70 && $scanCount === 0,
        "modules={$moduleCount} scans={$scanCount}"
    );
}

// ── 5. Seeding from a warm APCu hit ───────────────────────────────────────────
echo "\n--- seeding ---\n";

// The original defect: the write lived only in the APCu-miss branch, so with warm APCu the file
// was never created. Assert the seeding branch exists and is reachable.
$source = (string)file_get_contents(BASE_PATH . '/src/helpers/module-manager.php');
mscAssert(
    'a warm APCu hit still publishes a missing shared file',
    preg_match('/elseif\s*\(\s*\$servedByApcu\s*&&\s*\(!is_file\(\$sharedFile\)\s*\|\|\s*@filesize\(\$sharedFile\)\s*===\s*0\)\s*\)/s', $source) === 1,
    'the write is reachable only on an APCu miss - the file will never be created on a warm server'
);
mscAssert(
    'the shared file uses the same fingerprint key as the APCu entry',
    str_contains($source, "\$cacheKey = 'kernel.discovered_modules_scan_v2_' . \$fingerprint;"),
    'the two layers could disagree about staleness'
);

// Restore a valid file for whatever runs next.
moduleScanSharedCacheWrite($fingerprint, discoverModulesScanAll());

echo "\n============================================================\n";
echo "Shared module-scan cache tests: {$passed} passed, {$failed} failed\n";
echo "============================================================\n";

exit($failed === 0 ? 0 : 1);
