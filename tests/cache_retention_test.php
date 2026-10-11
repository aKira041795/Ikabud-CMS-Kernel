<?php
/**
 * Cache retention: the tree must be bounded without a per-write scan, and the retention metadata
 * must be writable by both the web user and the CLI user on a live install.
 *
 * WHY THIS EXISTS
 * Two deployment problems, both of which are invisible locally and both of which bite on a live
 * shared-host install:
 *
 * 1. RETENTION WAS UNBOUNDED IN PRACTICE. A prune command existed, but nothing invoked it and shared
 *    hosting has no system crontab, so the cache grew until something happened to be expired AND
 *    read. A live site on a disk quota can have sessions, logs and uploads broken by a cache that
 *    only ever grows.
 *
 * 2. THE RETENTION METADATA COULD BE UNWRITABLE. `storage/cache/generations` was created with
 *    mkdir(0777) masked by umask to 0775, and only the leaf was chmod'd. On the normal live split
 *    (deploy over SSH as one user, serve as www-data) the OTHER user cannot create a namespace
 *    directory underneath it at all - so generation bumps from the web fail while bumps from the CLI
 *    succeed, and invalidation silently stops crossing the worker boundary.
 *
 * The budget is deliberately enforced by a RATE-LIMITED sweep rather than per write: bounding on the
 * write path costs a full directory walk plus a filesize() per entry on every set().
 *
 * Run: php tests/cache_retention_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/cache-generation.php';
ob_end_clean();

use Ikabud\Kernel\Cache;

$passed = 0;
$failed = 0;
function crtAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
    } else {
        echo "FAIL: {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

$run = 'crt_' . getmypid() . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
$tmp = sys_get_temp_dir() . '/' . $run;
mkdir($tmp, 0777, true);

$rmrf = function (string $dir) use (&$rmrf): void {
    if (!is_dir($dir)) {
        return;
    }
    // GLOB_BRACE with {,.}* is required: glob('*') skips dotfiles, so a .cache_maintenance marker
    // would survive and leave the directory un-removable.
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        $base = basename($f);
        if ($base === '.' || $base === '..') {
            continue;
        }
        is_dir($f) ? $rmrf($f) : @unlink($f);
    }
    @rmdir($dir);
};
$instanceSize = function (string $dir): int {
    $total = 0;
    foreach (glob($dir . '/*/*.cache') ?: [] as $f) {
        $total += (int)@filesize($f);
    }
    return $total;
};

// ── A) The budget is enforced by maintenance, oldest-first ──────────────────────
$budgetMb = 1;
$cache = new Cache($tmp, $budgetMb);
$inst = 'retentioninst';

// Five large entries against a 1 MB budget. The payload must be INCOMPRESSIBLE: set() gzcompresses
// above COMPRESSION_THRESHOLD, and 300 KB of a repeated character collapses to ~2 KB on disk, so a
// naive fixture never exceeds the budget and the whole case passes vacuously.
// All entries are well inside their own TTL, so nothing here is prunable by expiry - everything
// removed is removed FOR SPACE, which is the point.
$written = [];
for ($i = 0; $i < 5; $i++) {
    $uri = '/retention/' . $i;
    $cache->set($inst, $uri, ['body' => bin2hex(random_bytes(400000))], 86400);
    $files = glob($tmp . '/' . $inst . '/*.cache') ?: [];
    usort($files, static fn(string $a, string $b): int => filemtime($a) <=> filemtime($b));
    $newest = end($files);
    if (is_string($newest)) {
        // Stagger mtimes so "oldest first" is unambiguous rather than resolving ties by chance.
        @touch($newest, time() - (600 - $i * 60));
        $written[$i] = $newest;
    }
}

// set() runs the sweep itself, which claims the interval. Clear the marker so the explicit call below
// is genuinely due rather than silently rate-limited.
@unlink($tmp . '/.cache_maintenance');

$beforeBytes = $instanceSize($tmp);
crtAssert(
    'the fixture exceeds the configured budget',
    $beforeBytes > $budgetMb * 1024 * 1024,
    "{$beforeBytes} bytes vs budget " . ($budgetMb * 1024 * 1024)
);

$swept = $cache->maintenanceIfDue();
crtAssert('maintenance ran when due', $swept === true);

$afterBytes = $instanceSize($tmp);
crtAssert(
    '★ maintenance brings the tree under the budget',
    $afterBytes <= $budgetMb * 1024 * 1024,
    "{$afterBytes} bytes still exceed the budget"
);
crtAssert(
    'the oldest entry is the one evicted (LRU, not newest-first)',
    $afterBytes < $beforeBytes && !is_file($written[0]),
    'oldest surviving: ' . var_export(is_file($written[0]), true)
);
crtAssert('the newest entry survives', is_file($written[4]));

// ── B) Rate limiting: the hot path is one stat, not a scan ──────────────────────
crtAssert('a second call inside the interval does not sweep', $cache->maintenanceIfDue() === false);
crtAssert('the interval marker exists', is_file($tmp . '/.cache_maintenance'));
$bytesAfterSecond = $instanceSize($tmp);
crtAssert(
    'the second call changed nothing',
    $bytesAfterSecond === $afterBytes,
    "{$afterBytes} -> {$bytesAfterSecond}"
);

// ── C) Must-refuse control: with NO budget, nothing is evicted for space ───────
// Without this, case A could pass because the sweep deletes aggressively for some unrelated reason.
$tmpNoBudget = $tmp . '_nobudget';
mkdir($tmpNoBudget, 0777, true);
$cacheNoBudget = new Cache($tmpNoBudget, 0);
for ($i = 0; $i < 5; $i++) {
    $cacheNoBudget->set($inst, '/nb/' . $i, ['body' => bin2hex(random_bytes(400000))], 86400);
}
@unlink($tmpNoBudget . '/.cache_maintenance');
$nbBefore = $instanceSize($tmpNoBudget);
$cacheNoBudget->maintenanceIfDue();
$nbAfter = $instanceSize($tmpNoBudget);
crtAssert(
    '★ control: with no budget configured, maintenance evicts nothing for space',
    $nbAfter === $nbBefore,
    "{$nbBefore} -> {$nbAfter} (entries were evicted without a budget)"
);

// ── D) Structural files must survive a sweep ───────────────────────────────────
$keepDir = $tmpNoBudget . '/' . $inst;
@file_put_contents($keepDir . '/.keep', '');
@file_put_contents($keepDir . '/.tag_abc.idx', 'x');
$cacheNoBudget->maintenanceIfDue();
crtAssert('a .keep placeholder survives', is_file($keepDir . '/.keep'));
crtAssert('a tag index survives', is_file($keepDir . '/.tag_abc.idx'));

// ── E) The generations tree is writable by other users (live deploy) ───────────
// Simulate the live condition: the root exists but is NOT world-writable, and the namespace dir is
// absent. A bump must still succeed and must leave every level writable, or the other user on the
// box can never publish a generation.
$genRoot = cacheGenerationRoot();
$probeNs = 'deployprobe_' . substr($run, -6);
@chmod($genRoot, 0755);
$bumped = cacheGenerationBump($probeNs, 'k');
crtAssert('★ a bump succeeds when the root is not world-writable', $bumped !== '');

$mode = static fn(string $p): string => is_dir($p) ? substr(sprintf('%o', fileperms($p)), -3) : 'n/a';
crtAssert(
    '★ the generations root is left world-writable',
    $mode($genRoot) === '777',
    'mode=' . $mode($genRoot)
);
crtAssert(
    '★ the namespace directory is world-writable (so the other user can write into it)',
    $mode($genRoot . '/' . $probeNs) === '777',
    'mode=' . $mode($genRoot . '/' . $probeNs)
);
crtAssert(
    'the generation file itself is group/other writable',
    substr(sprintf('%o', fileperms($genRoot . '/' . $probeNs . '/k.gen')), -3) === '666'
);

// ── Cleanup ────────────────────────────────────────────────────────────────────
$rmrf($tmp);
$rmrf($tmpNoBudget);
$rmrf($genRoot . '/' . $probeNs);
crtAssert('the test leaves no temp directories behind', !is_dir($tmp) && !is_dir($tmpNoBudget));

echo "\n============================================================\n";
echo "Cache retention tests: {$passed} passed, {$failed} failed\n";
echo "============================================================\n";

exit($failed === 0 ? 0 : 1);
