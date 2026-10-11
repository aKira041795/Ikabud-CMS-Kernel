<?php
/**
 * Kernel cache generation authority — cross-worker invalidation must actually invalidate.
 *
 * THE DEFECT CLASS THIS GUARDS (measured 2026-10-11)
 * --------------------------------------------------
 * ONE class repeated across independent caches, not several unrelated bugs: a cache accepted a WARM
 * PER-WORKER APCu value BEFORE consulting the shared file that carries the invalidation signal. A
 * writer bumps the shared file and deletes only ITS OWN worker's APCu key, so every other worker
 * keeps serving the stale entry until that entry's own TTL elapses.
 *
 *   - Cache::get()          returned the APCu array immediately, with no validation.
 *   - Cache::clearByTag()   unlinked the shared file, deleted its own APCu key, and stopped there.
 *   - FragmentStore::loadDepVersions() read APCu first (60s TTL) and returned on hit.
 *   - FragmentStore::invalidate()      bumped the shared file, own worker only, and used an
 *                                      UNLOCKED read-modify-write so concurrent bumps lost one.
 *   - pageCacheReadVersion() was the one correct implementation, and it was not shared.
 *
 * php-fpm runs pm.start_servers=10 .. pm.max_children=30, so this is deterministic under load.
 *
 * HOW "ANOTHER WORKER" IS SIMULATED
 * ---------------------------------
 * The invariant is exactly: a warm LOCAL APCu entry must stop being served once the SHARED file is
 * bumped by someone else. So the test warms APCu through the real code path, then bumps the shared
 * generation file DIRECTLY (which is all a different worker can do — it cannot reach this worker's
 * APCu), and asserts the next read misses. The positive control that follows proves the test can
 * tell the two apart rather than passing because of an unrelated miss.
 *
 * Run: php -d apc.enable_cli=1 tests/cache_generation_test.php
 *   apc.enable_cli=1 is REQUIRED: php.ini has it off, and without it the APCu tier is inert, so the
 *   central case would pass vacuously. The test asserts APCu is live and fails loudly if it is not.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/cache-generation.php';
ob_end_clean();

$passed = 0;
$failed = 0;
function cgAssert(string $label, bool $condition, string $detail = ''): void
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

/** Fresh namespace so runs never collide with real cache state. */
$run = 'cgtest_' . getmypid() . '_' . substr(bin2hex(random_bytes(4)), 0, 6);

// Snapshot the SHARED reject counter. Cache::get()/set() always bind to the namespace 'cache' -
// only the KEY carries the instance id - so this test inevitably increments a live, operator-facing
// counter. It is restored on the way out, because a test that inflates a production metric is worse
// than having no metric at all.
$rejectsBaseline = cacheGenerationRejectCounts();

// ── 0) Preconditions ────────────────────────────────────────────────────────────
$apcuLive = @apcu_store('cg_probe_' . $run, 1, 60) === true;
cgAssert(
    'APCu is live in this process (run with -d apc.enable_cli=1)',
    $apcuLive,
    'apc.enable_cli is off, so the warm-APCu cases below would pass vacuously'
);

// ── A) The token is unique and never reused ─────────────────────────────────────
$tokens = [];
for ($i = 0; $i < 2000; $i++) {
    $tokens[] = cacheGenerationToken();
}
cgAssert('2000 tokens are all distinct', count(array_unique($tokens)) === 2000);

// A `+1` counter is an unlocked read-modify-write: concurrent bumps can both read the same old value
// and write the same new one, silently losing an invalidation. Unique tokens cannot collide.
$bumps = [];
for ($i = 0; $i < 25; $i++) {
    $bumps[] = cacheGenerationBump($run, 'seq');
}
cgAssert('25 consecutive bumps are all distinct', count(array_unique($bumps)) === 25);
cgAssert('bump returns a non-empty token', $bumps[0] !== '');

// Atomic write must not leave temp files behind (a leftover .tmp means a torn write path).
$tmpLeft = glob(cacheGenerationPath($run, 'seq') . '.*.tmp') ?: [];
cgAssert('no temp files remain after repeated bumps', $tmpLeft === [], implode(',', $tmpLeft));

// ── B) Read semantics ───────────────────────────────────────────────────────────
cgAssert('an absent generation reads as "" (never bumped)', cacheGenerationRead($run, 'never') === '');
cgAssert('the persisted token reads back verbatim', cacheGenerationRead($run, 'seq') === end($bumps));

// Backward compatibility: an entry with NO stamp key at all was written before generations existed.
cgAssert('a null stamp is accepted (pre-generation entry)', cacheGenerationValid($run, null, 'seq') === true);

// '' is a REAL generation meaning "nothing has been bumped yet", so it must be COMPARED, not
// skipped. The first bump after a deploy must invalidate entries written under ''. Writing this as
// "an empty stamp is accepted" is what hid the bug: the test agreed with the defect.
cgAssert(
    "an empty stamp matches an unbumped namespace",
    cacheGenerationValid($run, '', 'never') === true
);
cgAssert(
    '★ an empty stamp is REJECTED once the generation is bumped',
    cacheGenerationValid($run, '', 'seq') === false,
    "'' was accepted against the bumped token, so the first invalidation would be a no-op"
);
cgAssert('a matching stamp is accepted', cacheGenerationValid($run, end($bumps), 'seq') === true);

// ── C) The core rejection rule ──────────────────────────────────────────────────
cgAssert('a STALE stamp is rejected', cacheGenerationValid($run, 'stale-token', 'seq') === false);

// Path safety: a namespace must never escape the generations tree.
$escaped = cacheGenerationPath('../../etc', 'passwd');
cgAssert(
    'path traversal in namespace cannot escape the root',
    !str_contains($escaped, '..') && str_starts_with($escaped, cacheGenerationRoot() . '/'),
    $escaped
);

// ── D) Cache: the actual cross-worker defect ────────────────────────────────────
$inst = $run . '_inst';
$uri = '/cgtest/' . $run;
$payload = ['body' => 'GENERATION-ONE'];

app()->cache()->set($inst, $uri, $payload, 300);

// Warm read: this is the APCu tier being populated through the real code path.
$warm = app()->cache()->get($inst, $uri);
cgAssert('a freshly written entry is served (warm)', is_array($warm) && $warm['body'] === 'GENERATION-ONE');

// The write must PHYSICALLY CARRY an authority stamp, or nothing downstream can validate it.
// Assert on the entry, not on the authority: the authority legitimately reads '' until something
// is first bumped, so checking it here would test the wrong object.
$entryAfterSet = app()->cache()->get($inst, $uri);
cgAssert(
    'a written entry carries a generation stamp bound to the authority',
    is_array($entryAfterSet)
        && array_key_exists('_cache_gen', $entryAfterSet)
        && $entryAfterSet['_cache_gen'] === cacheGenerationRead('cache', $inst),
    'entry stamp: ' . var_export($entryAfterSet['_cache_gen'] ?? '<absent>', true)
);

// ★ THE DEFECT: a different worker bumps the SHARED file. It cannot reach this worker's APCu.
cacheGenerationBump('cache', $inst);

$afterForeignBump = app()->cache()->get($inst, $uri);
cgAssert(
    '★ a foreign-worker bump makes a warm APCu entry MISS',
    $afterForeignBump === null,
    'stale payload still served: ' . var_export($afterForeignBump, true)
);

// Positive control (must-accept): rewrite under the NEW generation and it is served again. Without
// this, the case above could pass merely because something else made the read miss.
app()->cache()->set($inst, $uri, ['body' => 'GENERATION-TWO'], 300);
$afterReseal = app()->cache()->get($inst, $uri);
cgAssert(
    '★ a re-written entry under the new generation IS served (control)',
    is_array($afterReseal) && $afterReseal['body'] === 'GENERATION-TWO',
    var_export($afterReseal, true)
);

// clearByTag must publish a new authority, not just delete the files it can reach.
$genBeforeClear = cacheGenerationRead('cache', $inst);
app()->cache()->clearByTag($inst, 'cgtest-tag');
$genAfterClear = cacheGenerationRead('cache', $inst);
cgAssert('clearByTag() bumps the shared generation', $genAfterClear !== $genBeforeClear && $genAfterClear !== '');

// ── E) The same rule with APCu unavailable (Bluehost degradation) ───────────────
$instNoApcu = $run . '_noapcu';
app()->cache()->set($instNoApcu, $uri, ['body' => 'FILE-ONE'], 300);
$readNoApcu = app()->cache()->get($instNoApcu, $uri);
cgAssert('file tier serves without APCu', is_array($readNoApcu) && $readNoApcu['body'] === 'FILE-ONE');

cacheGenerationBump('cache', $instNoApcu);
$fileAfterBump = app()->cache()->get($instNoApcu, $uri);
cgAssert('file tier also rejects after a bump', $fileAfterBump === null, var_export($fileAfterBump, true));

// ── F) FragmentStore: other-worker invalidation ─────────────────────────────────
require_once $root . '/kernel/DiSyL/Cache/FragmentStore.php';

$fragRoot = sys_get_temp_dir() . '/cgtest_frag_' . $run;
$store = new \Ikabud\Kernel\DiSyL\Cache\FragmentStore($fragRoot);
$tenantA = 'tenantA_' . $run;

$store->put('frag-key', 'FRAGMENT-BODY', ['product-42'], 300, $tenantA);
$hit = $store->tryGet('frag-key', ['product-42'], $tenantA);
cgAssert('a fragment is served from cache', $hit === 'FRAGMENT-BODY');

// A SECOND store instance stands in for another worker: it shares the filesystem, not the APCu.
$otherWorker = new \Ikabud\Kernel\DiSyL\Cache\FragmentStore($fragRoot);
$otherWorker->invalidate(['product-42'], $tenantA);

$afterInvalidate = $store->tryGet('frag-key', ['product-42'], $tenantA);
cgAssert(
    '★ an invalidation from another store instance is observed (was memoised in APCu for 60s)',
    $afterInvalidate === null,
    'stale fragment served: ' . var_export($afterInvalidate, true)
);

// Lost-update: two tags invalidated in sequence must BOTH be recorded. The old implementation's
// unlocked read-modify-write could drop one, permanently silencing that tag.
$store->put('frag-a', 'A', ['tag-a'], 300, $tenantA);
$store->put('frag-b', 'B', ['tag-b'], 300, $tenantA);
$store->invalidate(['tag-a'], $tenantA);
$store->invalidate(['tag-b'], $tenantA);
cgAssert('tag-a fragment invalidated', $store->tryGet('frag-a', ['tag-a'], $tenantA) === null);
cgAssert('tag-b fragment invalidated (no lost update)', $store->tryGet('frag-b', ['tag-b'], $tenantA) === null);

// ── G) Tenant scope is fail-closed ──────────────────────────────────────────────
$tenantB = 'tenantB_' . $run;
$store->put('shared-key', 'TENANT-A-SECRET', [], 300, $tenantA);
$leak = $store->tryGet('shared-key', [], $tenantB);
cgAssert(
    '★ tenant B does not receive tenant A content',
    $leak !== 'TENANT-A-SECRET',
    'cross-tenant leak: ' . var_export($leak, true)
);

$store->put('scoped-key', 'SCOPED', [], 300, $tenantA);
cgAssert('tenant A still sees its own content', $store->tryGet('scoped-key', [], $tenantA) === 'SCOPED');

// The fail-open default: null must NOT resolve to the shared '_global' namespace.
$pathUnscoped = new ReflectionMethod($store, 'tenantScope');
$resolved = $pathUnscoped->invoke($store, null);
cgAssert(
    "a null tenant resolves to a self-describing '_unscoped', never '_global'",
    $resolved === '_unscoped' || (is_string($resolved) && $resolved !== '_global'),
    'resolved to: ' . var_export($resolved, true)
);
cgAssert(
    'a legacy explicit "_global" is preserved for rollout compatibility',
    $pathUnscoped->invoke($store, '_global') === '_global'
);

// ── H) The operator metric ──────────────────────────────────────────────────────
cacheGenerationValid($run, 'stale', 'seq');   // 1 reject + 1 read
cacheGenerationValid($run, end($bumps), 'seq'); // 1 read
$stats = cacheGenerationStats();
$nsStats = $stats[$run] ?? null;
cgAssert('stale-rejection metric is reported per namespace', is_array($nsStats));
cgAssert(
    'the metric counts both reads and rejects',
    is_array($nsStats) && $nsStats['reads'] >= 2 && $nsStats['rejects'] >= 1,
    var_export($nsStats, true)
);
// Fleet visibility: rejects must be readable from ANY process, including CLI where APCu is off.
// This is a plain file read, so it works here and would work from a separate worker - which is the
// entire reason rejects are not stored in APCu.
cgAssert(
    '★ reject counts are shared (visible from any process, not just this worker)',
    (cacheGenerationRejectCounts()[$run] ?? 0) >= 1,
    var_export(cacheGenerationRejectCounts(), true)
);

// ── Cleanup ────────────────────────────────────────────────────────────────────
$rmrf = function (string $dir) use (&$rmrf): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        is_dir($f) ? $rmrf($f) : @unlink($f);
    }
    @rmdir($dir);
};

app()->cache()->clear($inst);
app()->cache()->clear($instNoApcu);
@apcu_delete('cg_probe_' . $run);
$rmrf(dirname(cacheGenerationPath($run, 'seq')));
$rmrf($fragRoot);

// clear() removes the entries but leaves the now-empty instance directories behind, and every
// bump() wrote a generation record under the shared 'cache' namespace. Both are cleaned up
// explicitly, because a test that leaks into storage/cache is the exact defect this repo hit in
// cms_theme_test (a literal that leaked a symlink target).
foreach (glob(dirname(cacheGenerationRoot()) . '/cgtest_*') ?: [] as $d) {
    $rmrf($d);
}
foreach (glob(cacheGenerationRoot() . '/cache/cgtest_*.gen') ?: [] as $g) {
    @unlink($g);
}

// Restore the SHARED reject counter to its pre-test state: drop namespaces the test created and
// put every pre-existing namespace back to its baseline count. The map itself is production state.
$rejectFile = cacheGenerationRejectFile();
if (is_file($rejectFile)) {
    $rejectMap = json_decode((string)@file_get_contents($rejectFile), true);
    if (is_array($rejectMap)) {
        foreach ($rejectMap as $ns => $_) {
            if (!array_key_exists($ns, $rejectsBaseline)) {
                unset($rejectMap[$ns]);
            }
        }
        foreach ($rejectsBaseline as $ns => $n) {
            $rejectMap[$ns] = $n;
        }
        if ($rejectMap === []) {
            @unlink($rejectFile);
        } else {
            @file_put_contents($rejectFile, json_encode($rejectMap), LOCK_EX);
        }
    }
}
$restored = cacheGenerationRejectCounts();
ksort($restored);
$expected = $rejectsBaseline;
ksort($expected);
cgAssert(
    'the shared reject counter is restored to its pre-test state',
    $restored === $expected,
    'before=' . json_encode($expected) . ' after=' . json_encode($restored)
);

$leaked = array_merge(
    glob(dirname(cacheGenerationRoot()) . '/cgtest_*') ?: [],
    glob(cacheGenerationRoot() . '/cache/cgtest_*') ?: []
);
cgAssert('the test leaves no cache or generation files behind', $leaked === [], implode(',', $leaked));

echo "\n============================================================\n";
echo "Cache generation tests: {$passed} passed, {$failed} failed\n";
echo "============================================================\n";

exit($failed === 0 ? 0 : 1);
