<?php

declare(strict_types=1);

/**
 * The module-discovery cache key must be invalidated by a DEPLOY, not by a timer.
 *
 * Why this exists: table ownership lives inside the cached manifest. On 2026-10-09 a live deploy
 * changed module.json, but the kernel kept serving the previous owns_tables from the 300s APCu entry,
 * so ModuleDB::enforceAccess() denied dl_consignees -- a table the DEPLOYED manifest declares -- and
 * handleCashierLedger returned a hard 500. Deleting the cache fixed it, which proved the mechanism.
 *
 * These tests pin the properties that make that impossible:
 *   A  the fingerprint SEES every manifest the real scan can see (or a manifest can be edited blind)
 *   B  an edit CHANGES it (must-refuse: a stale key would keep serving stale ownership)
 *   C  no edit leaves it UNCHANGED (must-allow: an unstable key would defeat the cache entirely)
 *   D  the discovery cache key actually uses it (the helper cannot become orphaned)
 *   E  it stays cheap (it must not degrade into the recursive validating scan it guards)
 */
ob_start();
require_once __DIR__ . '/harness/TestHarness.php';
$h = new TestHarness('module-manifest-cache-fingerprint', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';

$modulesDir = modulesPath();

// ── A — the fingerprint must see every manifest the scan sees ────────────────
// Count them independently of the fingerprint: a raw recursive walk that applies the same
// backup-tree exclusion discoverModulesScanAll() uses.
$scanSeen = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modulesDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getFilename() !== 'module.json') {
        continue;
    }
    $path = $file->getPathname();
    if (preg_match('#\.bak_\d{8}_\d{6}#', $path) === 1) {
        continue;
    }
    $scanSeen[] = $path;
}
sort($scanSeen);

$fingerprint = moduleManifestScanFingerprint();
$fpCount = (int)explode('-', $fingerprint)[0];

$h->test(
    'A fingerprint counts every manifest discovery can see (nothing is edited blind)',
    $fingerprint !== 'nodir' && $fingerprint !== 'unstatable' && $fpCount === count($scanSeen),
    "fingerprint={$fingerprint} fpCount={$fpCount} scanSeen=" . count($scanSeen)
);

// The depth bound is the one place the fingerprint could go blind, so assert it explicitly.
$deeper = glob($modulesDir . '/*/*/*/*/module.json');
$h->test(
    'A no manifest nests deeper than the fingerprint covers (depth bound still valid)',
    is_array($deeper) && count($deeper) === 0,
    'manifests deeper than modules/*/*/*/module.json: ' . json_encode($deeper)
);

// ── C — must-allow: with no edit the key is stable, so the cache can actually hit ──
$again = moduleManifestScanFingerprint();
$h->test(
    'C fingerprint is stable when nothing changed (the cache can still hit)',
    $again === $fingerprint,
    "first={$fingerprint} second={$again}"
);

// ── B — must-refuse: an edit must change the key ─────────────────────────────
// Touch a real manifest's mtime and restore it. mtime only: no content is written.
$victim = $scanSeen[0] ?? null;
$originalMtime = $victim !== null ? filemtime($victim) : false;
$h->test(
    'B a manifest to touch exists for the mutation below',
    $victim !== null && $originalMtime !== false,
    'victim=' . var_export($victim, true)
);

$mutated = $fingerprint;
if ($victim !== null && $originalMtime !== false) {
    touch($victim, $originalMtime + 5);
    clearstatcache(true, $victim);
    $mutated = moduleManifestScanFingerprint();
    $h->test(
        'B an edited manifest CHANGES the key (a deploy invalidates on the next request)',
        $mutated !== $fingerprint,
        "before={$fingerprint} after={$mutated}"
    );

    // Restore, so the tree is left exactly as found.
    touch($victim, $originalMtime);
    clearstatcache(true, $victim);
    $restored = moduleManifestScanFingerprint();
    $h->test(
        'B restoring the mtime restores the key (the change was the mtime, not noise)',
        $restored === $fingerprint,
        "restored={$restored} expected={$fingerprint}"
    );
}

// ── D — the cache key must actually use it ───────────────────────────────────
$source = (string)file_get_contents($base . '/src/helpers/module-manager.php');
// Read the statement that actually calls the fingerprint (it spans lines, and other $cacheKey
// assignments exist elsewhere in the file, so anchor on the call rather than on the variable).
$keyStmt = null;
if (preg_match('/\$cacheKey\s*=\s*[^;]*moduleManifestScanFingerprint[^;]*;/s', $source, $m) === 1) {
    $keyStmt = $m[0];
}
$h->test(
    'D discoverModules() builds its cache key from the fingerprint',
    $keyStmt !== null
        && strpos($keyStmt, "'kernel.discovered_modules_scan_v2_'") !== false
        && strpos($keyStmt, 'moduleManifestScanFingerprint()') !== false,
    'cache key statement: ' . var_export($keyStmt, true)
);
$h->test(
    'D the old unfingerprinted v1 key is gone (it was the outage)',
    strpos($source, "'kernel.discovered_modules_scan_v1'") === false,
    'no code may reintroduce the unfingerprinted key'
);
$h->test(
    'D the fingerprint is skipped when there is no cache to key',
    $keyStmt !== null && strpos($keyStmt, 'apcuEnabled') !== false,
    'computing it without APCu is pure added cost'
);

// ── F — the kernel state invalidation must still reach the discovery key ─────
// invalidateKernelStateCache() runs on module enable/disable/version change. It used to delete the
// discovery key BY NAME; once the key gained a fingerprint that delete would silently miss and leave
// a stale scan cached, so assert it deletes the fingerprinted form.
$cacheSource = (string)file_get_contents($base . '/kernel/Cache.php');
$h->test(
    'F invalidateKernelStateCache() deletes the fingerprinted discovery key',
    preg_match(
        '/apcu_delete\(\s*\'kernel\.discovered_modules_scan_v2_\'\s*\.\s*moduleManifestScanFingerprint\(\)/',
        $cacheSource
    ) === 1,
    'a by-name delete of the old key silently misses once the key is fingerprinted'
);

// ── E — it must stay cheap ───────────────────────────────────────────────────
// Generous bound: this guards against the helper degrading into the recursive PARSE+VALIDATE walk
// (~700ms) it exists to protect, not against ordinary machine load.
$started = microtime(true);
for ($i = 0; $i < 20; $i++) {
    moduleManifestScanFingerprint();
}
$perCallMs = (microtime(true) - $started) / 20 * 1000;
$h->test(
    'E fingerprint stays a stat-only scan, well under the validation cost it guards',
    $perCallMs < 250,
    sprintf('%.2f ms/call (20 calls)', $perCallMs)
);

$h->done();
