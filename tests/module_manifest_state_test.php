<?php
declare(strict_types=1);

/**
 * The manifest fingerprint must be cheap AND correct, and "cheap" is the dangerous half.
 *
 * moduleManifestScanFingerprint() keys the discovery cache, and table ownership lives inside that
 * cached scan. On 2026-10-09 a live deploy changed module.json while the kernel served the previous
 * owns_tables from the cache, so ModuleDB::enforceAccess() denied dl_consignees -- a table the
 * DEPLOYED manifest declares -- and handleCashierLedger returned a hard 500.
 *
 * The fingerprint was re-walked on every request to prevent that. It now revalidates a cached tree
 * state with stat() instead, which is only safe if stat() sees everything the walk saw. These tests
 * attack exactly that, and every change-detection case is made deterministic by ageing the fixture's
 * directory mtimes before the scan, so the added file can never land in the recorded second.
 *
 * Failure mode guarded here is silent: a validation that returns true when it should return false
 * serves a stale scan and looks like a fast request.
 */

ob_start();
require_once __DIR__ . '/harness/TestHarness.php';
$h = new TestHarness('module-manifest-state', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';

// ── fixture helpers ─────────────────────────────────────────────────────────

function msFixtureDrop(): string
{
    return sys_get_temp_dir() . '/ikabud-manifest-state-' . getmypid() . '-' . uniqid('', true);
}

function msWrite(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $content);
}

function msRemove(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                msRemove($path . '/' . $entry);
            }
        }
        @chmod($path, 0777);
        rmdir($path);

        return;
    }
    @chmod($path, 0666);
    unlink($path);
}

/**
 * Push every directory's mtime into the past.
 *
 * Without this, an added file can land in the same second as the recorded mtime and the test would
 * be measuring the clock, not the code. Ageing makes every "a change is seen" assertion deterministic.
 */
function msAgeTree(string $dir, int $seconds = 10): void
{
    $now = time() - $seconds;
    $stack = [$dir];
    while ($stack !== []) {
        $current = array_pop($stack);
        @touch($current, $now);
        foreach (scandir($current) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $current . '/' . $entry;
            if (is_dir($path)) {
                $stack[] = $path;
            }
        }
    }
}

/** The implementation this one replaces, kept verbatim so equivalence can be asserted. */
function msOriginalGlobFingerprint(string $dir): string
{
    if (!is_dir($dir)) {
        return 'nodir';
    }

    $count = 0;
    $parts = [];
    foreach (['*', '*/*', '*/*/*'] as $pattern) {
        foreach ((array)glob($dir . '/' . $pattern . '/module.json') as $path) {
            if (preg_match('#\.bak_\d{8}_\d{6}#', $path) === 1) {
                continue;
            }
            $mtime = @filemtime($path);
            $size = @filesize($path);
            if ($mtime === false || $size === false) {
                return 'unstatable';
            }
            $count++;
            $parts[] = substr($path, strlen($dir) + 1) . ':' . $mtime . ':' . $size;
        }
    }

    if ($count === 0) {
        return 'empty';
    }
    sort($parts);

    return $count . '-' . substr(md5(implode('|', $parts)), 0, 12);
}

// ── fixture tree: modules/<id>/ and modules/<suite>/<id>/ ───────────────────

$root = msFixtureDrop();
msWrite($root . '/alpha/module.json', '{"id":"alpha"}');
msWrite($root . '/beta/module.json', '{"id":"beta"}');
msWrite($root . '/suite/one/module.json', '{"id":"one"}');
msWrite($root . '/suite/two/module.json', '{"id":"two"}');

msAgeTree($root);
$state = moduleManifestTreeState($root);

$h->test(
    'the walk finds manifests at depth 1 and depth 2',
    $state['fingerprint'] === msOriginalGlobFingerprint($root) && count($state['files']) === 4,
    "fingerprint={$state['fingerprint']} files=" . count($state['files'])
);
$h->test(
    'a freshly built state validates',
    moduleManifestStateIsCurrent($root, $state) === true
);
$h->test(
    'revalidation watches directories as well as files',
    count($state['dirs']) >= 4 && $state['dirs_complete'] === true,
    'dirs=' . count($state['dirs']) . ' complete=' . var_export($state['dirs_complete'], true)
);

// ── must-refuse: every way the tree can change ──────────────────────────────

// 1. an existing manifest is edited (mtime moves)
$victim = $root . '/alpha/module.json';
$originalMtime = (int)filemtime($victim);
touch($victim, $originalMtime + 7);
clearstatcache();
$h->test(
    'an edited manifest invalidates the state',
    moduleManifestStateIsCurrent($root, $state) === false
);
$edited = moduleManifestTreeState($root)['fingerprint'];
$h->test('an edited manifest changes the fingerprint', $edited !== $state['fingerprint'], "before={$state['fingerprint']} after={$edited}");
touch($victim, $originalMtime);
clearstatcache();
$h->test(
    'restoring the mtime restores the state and the fingerprint',
    moduleManifestStateIsCurrent($root, $state) === true
        && moduleManifestTreeState($root)['fingerprint'] === $state['fingerprint']
);

// 2. a manifest is ADDED to a directory that is already watched
msWrite($root . '/suite/three/module.json', '{"id":"three"}');
clearstatcache();
$h->test(
    'a manifest added to a new subdirectory invalidates the state',
    moduleManifestStateIsCurrent($root, $state) === false,
    'this is the case the cached scan would miss and the glob walk would catch'
);
msRemove($root . '/suite/three');
clearstatcache();

// 3. a whole new first-level directory appears
msWrite($root . '/gamma/module.json', '{"id":"gamma"}');
clearstatcache();
$h->test(
    'a new module directory at the top level invalidates the state',
    moduleManifestStateIsCurrent($root, $state) === false
);
$h->test(
    'and the fingerprint changes with it',
    moduleManifestTreeState($root)['fingerprint'] !== $state['fingerprint']
);
msRemove($root . '/gamma');
clearstatcache();

// 4. a manifest is DELETED
msRemove($root . '/beta/module.json');
clearstatcache();
$h->test(
    'a deleted manifest invalidates the state',
    moduleManifestStateIsCurrent($root, $state) === false
);
msWrite($root . '/beta/module.json', '{"id":"beta"}');
msAgeTree($root);
clearstatcache();
$h->test(
    'the fixture is back to the recorded state',
    moduleManifestStateIsCurrent($root, $state) === true
);

// ── the documented depth bound, and the exclusions ──────────────────────────

msWrite($root . '/a/b/c/d/module.json', '{"id":"too-deep"}');
msAgeTree($root);
clearstatcache();
$tooDeep = moduleManifestTreeState($root);
$h->test(
    'a manifest deeper than the documented bound is not seen (the bound is real, not assumed)',
    count($tooDeep['files']) === 4 && $tooDeep['fingerprint'] === msOriginalGlobFingerprint($root),
    'files=' . count($tooDeep['files'])
);
msRemove($root . '/a');

msWrite($root . '/.hidden/module.json', '{"id":"hidden"}');
msAgeTree($root);
clearstatcache();
$h->test(
    'a dotted directory is ignored, as glob("*") ignores it',
    moduleManifestTreeState($root)['fingerprint'] === $state['fingerprint']
);
msRemove($root . '/.hidden');

msWrite($root . '/zeta.bak_20260101_000000/module.json', '{"id":"backup"}');
msAgeTree($root);
clearstatcache();
$h->test(
    'a backup tree is skipped, as discoverModulesScanAll() skips it',
    moduleManifestTreeState($root)['fingerprint'] === $state['fingerprint']
);
msRemove($root . '/zeta.bak_20260101_000000');

// ── cacheability: the second-granularity guard ──────────────────────────────

$fresh = moduleManifestTreeState($root);
$h->test(
    'a state whose directory mtimes are the current second is NOT cacheable',
    moduleManifestStateIsCacheable($fresh, time()) === false,
    'filemtime() is second-granularity: a change in the recorded second leaves the mtime unchanged'
);
$h->test(
    'the same state becomes cacheable once its mtimes are in the past',
    moduleManifestStateIsCacheable($fresh, time() + 5) === true
);
$h->test(
    'a state built from an incomplete walk is never cacheable',
    moduleManifestStateIsCacheable(['dirs_complete' => false, 'fingerprint' => 'x', 'dirs' => ['.' => 1]], time() + 100) === false
);
$h->test(
    'an unstatable fingerprint is never cacheable',
    moduleManifestStateIsCacheable(['dirs_complete' => true, 'fingerprint' => 'unstatable', 'dirs' => ['.' => 1]], time() + 100) === false
);

// ── unreadable directory: must still produce a usable fingerprint ───────────

$locked = $root . '/suite/locked';
if (is_dir($locked)) {
    msRemove($locked);
}
mkdir($locked, 0777, true);
msWrite($locked . '/module.json', '{"id":"locked"}');
msAgeTree($root);
@chmod($locked, 0000);
clearstatcache();
$probe = @opendir($locked);
$couldLock = $probe === false;
if ($probe !== false) {
    closedir($probe);
}

if ($couldLock) {
    $blocked = moduleManifestTreeState($root);
    $h->test(
        'an unreadable directory reports an incomplete walk rather than throwing',
        $blocked['dirs_complete'] === false
    );
    $h->test(
        'and an incomplete walk is refused for caching (one slow request must not become permanent)',
        moduleManifestStateIsCacheable($blocked) === false
    );
    @chmod($locked, 0777);
    $h->test(
        'the fingerprint is still produced once the directory is readable again',
        is_string(moduleManifestTreeState($root)['fingerprint'])
    );
} else {
    $h->test('unreadable-directory case skipped (running with permission to read a 0000 dir)', true);
}

msRemove($root);

// ── equivalence with the shipped algorithm, on the REAL tree ────────────────

$real = modulesPath();
$realState = moduleManifestTreeState($real);
$h->test(
    'on the real modules tree the new walk reproduces the old glob fingerprint exactly',
    $realState['fingerprint'] === msOriginalGlobFingerprint($real),
    "new={$realState['fingerprint']} old=" . msOriginalGlobFingerprint($real)
);
$h->test(
    'and the live entry point returns that same fingerprint',
    moduleManifestScanFingerprint() === $realState['fingerprint'],
    'entry=' . moduleManifestScanFingerprint()
);
$h->test(
    'the real state is complete, so it can actually be cached',
    $realState['dirs_complete'] === true && $realState['files'] !== [],
    'dirs=' . count($realState['dirs']) . ' files=' . count($realState['files'])
);

$h->done();
