<?php
/**
 * Generation-bound memos — a per-worker memo must not outlive a shared configuration change.
 *
 * THE DEFECT CLASS THIS GUARDS (measured 2026-10-11)
 * --------------------------------------------------
 * The same class as tests/cache_generation_test.php, in three places that were missed the first time
 * because their staleness came from a TTL rather than from a broken read order:
 *
 *   - src/helpers/module-registry.php      APCu 3600s; writer deleted only its OWN worker's key
 *   - kernel/Contracts/ReadContractRegistry.php  APCu 3600s; no invalidation path at all
 *   - kernel/Http/TenantEntryRouter.php    APCu 3600s; no invalidation path at all
 *
 * So a module enable/disable, a migration, or a routing change was visible to exactly one worker -
 * the one that performed it - and to no other worker for up to an hour. Deleting APCu keys cannot
 * fix this: APCu is per-worker, so a delete only ever reaches the deleter. The only signal that
 * crosses a worker boundary is a bump of a shared generation.
 *
 * The assertions are OUTCOME level: "after a shared bump, the memo is no longer usable", not "a
 * particular helper was called".
 *
 * Run: php -d apc.enable_cli=1 tests/cache_memo_generation_test.php
 *   apc.enable_cli=1 is REQUIRED. php.ini defaults it to 0, and without it every memo case would
 *   pass vacuously because APCu stores nothing. The test asserts APCu is live and fails loudly.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

ob_start();
require $root . '/bootstrap.php';
require_once BASE_PATH . '/src/helpers/cache-generation.php';
// The consumer assertions below need the module registry, whose path helper lives in module-manager.
if (!function_exists('moduleRegistryPath')) {
    require_once BASE_PATH . '/src/helpers/module-manager.php';
}
if (!function_exists('readModuleRegistry')) {
    require_once BASE_PATH . '/src/helpers/module-registry.php';
}
ob_end_clean();

$passed = 0;
$failed = 0;
function cmgAssert(string $label, bool $condition, string $detail = ''): void
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

$run = 'cgmemo_' . getmypid() . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
$memoKey = $run . ':value';

// Snapshot the shared configuration generation. Bumping is idempotent and only forces recomputation,
// so this is cosmetic hygiene rather than a correctness requirement - but a test should leave the
// generation it found.
$genBefore = cacheConfigGeneration();
// The tenant-routing generation is a SEPARATE key; snapshot it too so the test leaves the same
// state it found (bumping is idempotent, but a test should not move a production counter).
$routingGenBefore = cacheGenerationRead(CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_CONFIG_TENANT_ROUTING);

// The memo cases below deliberately trigger rejections, which land in the SHARED, operator-facing
// counter. Snapshot and restore it, because a test that inflates a production metric is worse than
// having no metric at all.
$rejectsBaseline = cacheGenerationRejectCounts();

// ── 0) Preconditions ────────────────────────────────────────────────────────────
cmgAssert(
    'APCu is live in this process (run with -d apc.enable_cli=1)',
    @apcu_store($run . ':probe', 1, 60) === true,
    'apc.enable_cli is off, so every memo case below would pass vacuously'
);

// ── A) Round trip ───────────────────────────────────────────────────────────────
cacheMemoWrite($memoKey, ['answer' => 42], 300);
$read = cacheMemoRead($memoKey);
cmgAssert(
    'a written memo reads back',
    is_array($read) && ($read['answer'] ?? null) === 42,
    var_export($read, true)
);

// ── B) The memo is BOUND, not merely stored ─────────────────────────────────────
$raw = @apcu_fetch($memoKey);
cmgAssert(
    'the stored entry carries a generation stamp',
    is_array($raw) && array_key_exists('__gen', $raw),
    var_export($raw, true)
);
cmgAssert(
    'the stamp equals the current configuration generation',
    is_array($raw) && ($raw['__gen'] ?? null) === cacheConfigGeneration()
);

// ── C) THE CORE CASE: a shared bump kills the local memo ────────────────────────
// This simulates another worker (or the CLI) publishing a new generation: the shared file moves and
// this worker's APCu is NOT touched, exactly as happens with php-fpm's 10..30 workers.
$newGen = cacheConfigGenerationBump();
cmgAssert('bumping produces a new, non-empty token', $newGen !== '' && $newGen !== $genBefore);

$afterBump = cacheMemoRead($memoKey);
cmgAssert(
    '★ a foreign-generation bump makes the warm memo MISS',
    $afterBump === null,
    'stale value still served: ' . var_export($afterBump, true)
);

// Positive control (must-accept): re-writing under the new generation is readable again. Without
// this, the case above could pass merely because something else broke memo reads entirely.
cacheMemoWrite($memoKey, ['answer' => 43], 300);
$rescoped = cacheMemoRead($memoKey);
cmgAssert(
    '★ a memo re-written under the new generation IS served (control)',
    is_array($rescoped) && ($rescoped['answer'] ?? null) === 43,
    var_export($rescoped, true)
);

// ── D) Legacy unstamped entries are evicted, not trusted ────────────────────────
$legacyKey = $run . ':legacy';
@apcu_store($legacyKey, ['cols' => ['stale']], 300);   // no __gen wrapper
cmgAssert('an unstamped legacy memo is not served', cacheMemoRead($legacyKey) === null);
@apcu_fetch($legacyKey, $stillThere);
cmgAssert('an unstamped legacy memo is evicted so it is rewritten stamped', $stillThere === false);

// ── E) A real consumer's memo is generation-bound ───────────────────────────────
// Outcome level: the module registry's own cache key must become unusable after a bump, so the
// consumer is forced back to the authoritative file. This asserts nothing about internals - only
// that the entry the consumer relies on stops working.
$registryBefore = readModuleRegistry();
$registryMemoWarm = @apcu_fetch('ikabud:module_registry_v1');
cmgAssert(
    'readModuleRegistry() stores a generation-bound memo',
    is_array($registryMemoWarm) && array_key_exists('__gen', $registryMemoWarm),
    var_export($registryMemoWarm, true)
);
cmgAssert('readModuleRegistry() still returns the registry', is_array($registryBefore));

cacheConfigGenerationBump();
// Single, falsifiable condition. An earlier version of this assertion OR-ed in
// "the stamp no longer equals the current generation", which is true precisely BECAUSE the entry is
// stale - so it passed even with the binding check removed. A guard whose condition cannot be false
// is not a guard; the must-refuse run caught it.
$registryMemoReadable = cacheMemoRead('ikabud:module_registry_v1');
cmgAssert(
    '★ the module registry memo is REJECTED after a bump (reaches every worker)',
    $registryMemoReadable === null,
    'the registry memo survived a configuration change: ' . var_export($registryMemoReadable, true)
);

// ── F) These memos are visible in the operator metric ──────────────────────────
$stats = cacheGenerationStats();
cmgAssert(
    'config-generation rejects are reported under the kernel_config namespace',
    isset($stats[CACHE_GENERATION_CONFIG_NAMESPACE]) && $stats[CACHE_GENERATION_CONFIG_NAMESPACE]['rejects'] >= 1,
    var_export($stats[CACHE_GENERATION_CONFIG_NAMESPACE] ?? null, true)
);

// ── G) Negative caches: a stored null is a RESULT, not a miss ──────────────────
// The tenant-host memo deliberately caches null for an unmapped host. cacheMemoRead() cannot express
// that, because it returns null for both "absent" and "stale" - which is why the pair reader exists.
// Without it, binding that memo would silently discard the negative cache and send every unmapped
// host back to the control database on every single request.
$negKey = $run . ':negative';
cacheMemoWrite($negKey, null, 300, CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_CONFIG_TENANT_ROUTING);
[$negFound, $negValue] = cacheMemoReadPair($negKey, CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_CONFIG_TENANT_ROUTING);
cmgAssert(
    '★ a cached null is FOUND (negative cache preserved, not treated as a miss)',
    $negFound === true && $negValue === null,
    'found=' . var_export($negFound, true)
);

cacheGenerationBump(CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_CONFIG_TENANT_ROUTING);
[$negFoundAfter] = cacheMemoReadPair($negKey, CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_CONFIG_TENANT_ROUTING);
cmgAssert('★ a cached null is REJECTED once the routing generation moves', $negFoundAfter === false);

// The pair reader must not trust an unstamped legacy entry either.
@apcu_store($run . ':legacy_pair', ['tenant_id' => 1], 300);
[$legacyFound] = cacheMemoReadPair($run . ':legacy_pair', CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_CONFIG_TENANT_ROUTING);
cmgAssert('an unstamped legacy entry is not found by the pair reader', $legacyFound === false);

// ── H) The real mutator publishes a generation ─────────────────────────────────
// Outcome level: after the kernel's own invalidation entry point runs, the routing generation must
// have MOVED, because that value is the only thing the other php-fpm workers can observe. Deleting
// APCu keys reaches the calling worker and nothing else.
$routingGenPre = cacheGenerationRead(CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_CONFIG_TENANT_ROUTING);
\Ikabud\Kernel\TenantResolver::clearControlHostCache();
$routingGenPost = cacheGenerationRead(CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_CONFIG_TENANT_ROUTING);
cmgAssert(
    '★ clearing the control-host cache publishes a new routing generation',
    $routingGenPost !== '' && $routingGenPost !== $routingGenPre,
    "before={$routingGenPre} after={$routingGenPost}"
);

// ── Cleanup ────────────────────────────────────────────────────────────────────
@apcu_delete($run . ':probe');
@apcu_delete($memoKey);
@apcu_delete($legacyKey);

// Restore the generation the test found, if one existed before it ran.
if ($genBefore !== '') {
    @file_put_contents(cacheGenerationPath(CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_DEFAULT_KEY), $genBefore, LOCK_EX);
}
// Same for the tenant-routing key: if the test created it, remove it rather than leave a counter that
// did not exist before.
if ($routingGenBefore !== '') {
    @file_put_contents(cacheGenerationPath(CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_CONFIG_TENANT_ROUTING), $routingGenBefore, LOCK_EX);
}

// Restore the SHARED reject counter: drop namespaces the test created and put pre-existing ones
// back to their baseline counts.
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
// ONLY assertions about the namespace this test OWNS - see the note in cache_generation_test.php.
// The counter is a shared file that a live worker can increment at any moment, so asserting
// invariants over AMBIENT namespaces makes the result depend on timing rather than on the code.
cmgAssert(
    'the test leaves none of its own namespaces in the shared counter',
    !array_key_exists($run, $restored),
    'remaining: ' . implode(',', array_keys($restored))
);
$dropped = [];
foreach ($rejectsBaseline as $ns => $_) {
    if (!array_key_exists($ns, $restored)) {
        $dropped[] = $ns;
    }
}
cmgAssert(
    'the test does not DELETE a pre-existing counter namespace',
    $dropped === [],
    implode(', ', $dropped)
);

$leaked = glob(dirname(cacheGenerationRoot()) . '/' . $run . '*') ?: [];
cmgAssert('the test leaves no cache directories behind', $leaked === [], implode(',', $leaked));

echo "\n============================================================\n";
echo "Cache memo generation tests: {$passed} passed, {$failed} failed\n";
echo "============================================================\n";

exit($failed === 0 ? 0 : 1);
