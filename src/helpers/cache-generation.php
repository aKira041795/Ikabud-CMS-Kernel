<?php

/**
 * Kernel cache generation authority — the single source of truth for cross-worker cache
 * invalidation.
 *
 * WHY THIS EXISTS (measured 2026-10-11)
 * -------------------------------------
 * The kernel had one defect class repeated across independent caches, not several unrelated bugs.
 * The class: a cache accepted a WARM, PER-WORKER APCu value BEFORE consulting the shared file that
 * carries the invalidation signal. A writer bumps the shared file and deletes only ITS OWN worker's
 * APCu key, so every other worker keeps serving the stale entry for the remainder of its TTL.
 *
 *   - Cache::get() returned the APCu array immediately with no generation check, while
 *     Cache::clearByTag() unlinked the shared file but deleted only the local APCu key.
 *   - FragmentStore::loadDepVersions() read APCu first (60s TTL) and returned on hit, while
 *     invalidate() bumped the shared file and deleted only the local APCu key.
 *   - pageCacheReadVersion() did it correctly (file first, APCu second). That implementation was a
 *     one-off, not shared.
 *
 * php-fpm runs pm.start_servers=10 .. pm.max_children=30 locally, so this is deterministic under
 * load, not an edge case. CLI cannot clear APCu at all: APCu is per-worker, so deleting files is not
 * clearing and `apcu_clear_cache()` from CLI only clears the CLI's own (empty) store.
 *
 * THE PROTOCOL
 * ------------
 *   1. Shared storage is authoritative. APCu is only an optimisation.
 *   2. A generation is an opaque, unique token. Validation is EQUALITY, never ordering.
 *   3. Bumps write a UNIQUE token via temp-file + rename(), so an interrupted write cannot publish
 *      a torn value and a bump can never be accidentally reused or go backwards.
 *   4. Reads consult the file FIRST. The value is deliberately NOT memoised in APCu: memoising a
 *      single tiny file read is what created the whole bug class, and it saves almost nothing
 *      compared with the payload read it guards.
 *   5. Absent generation (nothing was ever bumped) reads as ''. Entries stamped '' stay valid, so
 *      this is backward compatible with caches written before generations existed. The first bump
 *      mints a token and every older entry misses.
 *
 * A stale generation therefore cannot validate a newer payload: the stamp stored with the payload
 * is compared against the authority on every read.
 *
 * @see src/helpers/page-cache.php   — migrated consumer (page cache)
 * @see kernel/Cache.php             — migrated consumer (generic cache)
 * @see kernel/DiSyL/Cache/FragmentStore.php — migrated consumer (DiSyL fragments)
 */

if (!defined('CACHE_GENERATION_DEFAULT_KEY')) {
    define('CACHE_GENERATION_DEFAULT_KEY', 'default');
}

/**
 * Root directory for generation records.
 *
 * Kept beside the caches it governs under `storage/cache/generations/`, so a single
 * `rm -rf storage/cache` (or the `cache:clear` command) still removes everything consistently.
 */
function cacheGenerationRoot(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    if (defined('STORAGE_PATH')) {
        $base = (string)STORAGE_PATH;
    } elseif (defined('BASE_PATH')) {
        $base = (string)BASE_PATH . '/storage';
    } else {
        $base = dirname(__DIR__, 2) . '/storage';
    }
    $root = rtrim($base, '/') . '/cache/generations';
    return $root;
}

/**
 * Mint a fresh generation token.
 *
 * Unique, never reused, never going backwards — the token carries time for operator legibility and
 * random bytes for uniqueness. Two workers bumping in the same second must not produce the same
 * token, which is exactly why a `+1` counter (an unlocked read-modify-write) is NOT used:
 * concurrent bumps can both read the same old value and both write the same new one, silently
 * losing an invalidation.
 */
function cacheGenerationToken(): string
{
    try {
        $rand = bin2hex(random_bytes(6));
    } catch (\Throwable $e) {
        // random_bytes() can fail on a host with no usable CSPRNG. Uniqueness still matters more
        // than unpredictability here, so degrade rather than throw inside a cache path.
        $rand = bin2hex(pack('N', mt_rand())) . dechex((int)(microtime(true) * 1000000) & 0xffffff);
    }
    return dechex(time()) . '-' . $rand;
}

/** Sanitise a namespace/key so it can never escape the generations tree. */
function cacheGenerationSafe(string $s): string
{
    $s = preg_replace('/[^A-Za-z0-9_.-]/', '_', $s) ?? '_';
    // A leading dot would create a hidden file and break the empty-string case.
    $s = ltrim($s, '.');
    // Defence in depth: the character class above already removes every path separator, so '../..'
    // becomes '.._..' and cannot traverse. Removing the dots as well means the invariant is
    // visible in the value itself rather than depending on the reader noticing that '/' is gone.
    $s = str_replace('..', '_', $s);
    return $s === '' ? '_' : substr($s, 0, 120);
}

/** Absolute path of the generation record for a namespace + key. */
function cacheGenerationPath(string $namespace, string $key = CACHE_GENERATION_DEFAULT_KEY): string
{
    return cacheGenerationRoot() . '/' . cacheGenerationSafe($namespace) . '/' . cacheGenerationSafe($key) . '.gen';
}

/**
 * Read the authoritative generation.
 *
 * FILE FIRST. Always. There is deliberately no APCu memo — see the file docblock, point 4.
 * Returned '' means "never bumped" and is a valid generation.
 */
function cacheGenerationRead(string $namespace, string $key = CACHE_GENERATION_DEFAULT_KEY): string
{
    $file = cacheGenerationPath($namespace, $key);
    if (!is_file($file)) {
        return '';
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw)) {
        // Unreadable but present: treat as unknown rather than inventing '' (which would validate
        // every old entry). A token that matches nothing forces a miss, which is fail-closed.
        return '!unreadable';
    }
    $raw = trim($raw);
    if ($raw === '') {
        return '!unreadable';
    }
    return $raw;
}

/**
 * Ensure a directory in the generations tree is writable by BOTH the web user and the CLI user.
 *
 * This is a DEPLOYMENT correctness requirement, not tidiness. mkdir() honours umask (002 on many
 * shared hosts yields 0775), and the directory that holds a namespace is what a bump must be able to
 * write to. If it is 0775 and owned by whichever user happened to run first, the OTHER user cannot
 * create a namespace directory at all - so a bump from the web fails while a bump from the CLI
 * succeeds, and invalidation silently stops crossing the worker boundary.
 *
 * That failure is invisible by design: a failed bump returns '' and every cache simply keeps serving
 * what it already had. On a live site the split is the norm (deploy over SSH as one user, serve as
 * www-data), so the tree is made explicitly world-writable up to - but not above - our own root.
 * `storage/` sits outside the document root, so this does not widen web exposure.
 */
function cacheGenerationEnsureDir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $root = cacheGenerationRoot();
    $current = $dir;
    while ($current !== '' && $current !== '/' && str_starts_with($current, $root)) {
        @chmod($current, 0777);
        if ($current === $root) {
            break;
        }
        $parent = dirname($current);
        if ($parent === $current) {
            break;
        }
        $current = $parent;
    }
}

/**
 * Bump the generation for a namespace + key and return the new token.
 *
 * Atomic: the token is written to a sibling temp file and rename()d into place. rename() is atomic
 * on POSIX, so a reader either sees the whole old token or the whole new one — never a truncated
 * file, and never a partially written value.
 */
function cacheGenerationBump(string $namespace, string $key = CACHE_GENERATION_DEFAULT_KEY): string
{
    $file = cacheGenerationPath($namespace, $key);
    $dir = dirname($file);
    cacheGenerationEnsureDir($dir);

    $token = cacheGenerationToken();
    $tmp = $file . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
    if (@file_put_contents($tmp, $token, LOCK_EX) === false) {
        cacheGenerationLog('bump-write-failed', $namespace, $key);
        @unlink($tmp);
        return '';
    }
    if (!@rename($tmp, $file)) {
        // rename() can fail across devices; fall back to a direct write under lock so the bump is
        // not silently lost.
        cacheGenerationLog('bump-rename-failed', $namespace, $key);
        $ok = @file_put_contents($file, $token, LOCK_EX) !== false;
        @unlink($tmp);
        return $ok ? $token : '';
    }
    @chmod($file, 0666);
    return $token;
}

/**
 * Does a stamp stored with a payload still match the authority?
 *
 * A null/absent stamp means the entry predates generations: accept it (backward compatible).
 * An empty stamp is treated the same way for the same reason.
 */
function cacheGenerationValid(string $namespace, ?string $stamp, string $key = CACHE_GENERATION_DEFAULT_KEY): bool
{
    // null means the entry carries NO stamp at all, i.e. it was written before generations existed.
    // Accepting it is the backward-compatible path.
    if ($stamp === null) {
        return true;
    }
    cacheGenerationStatsInc('cache_gen_reads:' . cacheGenerationSafe($namespace));

    // '' IS a real generation - it means "nothing has been bumped yet" - so it must be COMPARED,
    // not skipped. Treating '' as "no stamp" made the first bump after a deploy a silent no-op:
    // every entry written before that first bump was stamped '' and every one of them validated
    // against the new token. Caught by tests/cache_generation_test.php.
    if ($stamp === cacheGenerationRead($namespace, $key)) {
        return true;
    }
    cacheGenerationStatsNoteReject($namespace);
    return false;
}

/**
 * Count a rejected stale read in a SHARED, fleet-visible place.
 *
 * Rejects are the rare, actionable event - they mean invalidation is actively preventing stale
 * content from being served - so a locked file write here costs nothing measurable. It is
 * file-backed rather than APCu-backed ON PURPOSE: APCu is per-worker and php.ini sets
 * apc.enable_cli=0, so an APCu-only reject counter is invisible to `php ikabud cache:stats` and
 * therefore invisible to the operator who is supposed to act on it.
 *
 * The READS denominator stays in APCu, because it increments on every read and must remain cheap.
 * It is reported as the current worker's own view, which the output says rather than quietly
 * presenting it as a fleet total.
 */
function cacheGenerationStatsNoteReject(string $namespace): void
{
    $file = cacheGenerationRejectFile();
    $dir = dirname($file);
    cacheGenerationEnsureDir($dir);
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }
    $raw = stream_get_contents($fp);
    $map = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : [];
    if (!is_array($map)) {
        $map = [];
    }
    $ns = cacheGenerationSafe($namespace);
    $map[$ns] = (int)($map[$ns] ?? 0) + 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($map));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

/** Path of the shared reject counter. */
function cacheGenerationRejectFile(): string
{
    return cacheGenerationRoot() . '/_rejects.json';
}

/**
 * Shared reject counts, namespace => count.
 *
 * Readable from ANY process, including CLI where APCu is disabled, which is the whole reason this
 * counter is not stored in APCu.
 *
 * @return array<string,int>
 */
function cacheGenerationRejectCounts(): array
{
    $file = cacheGenerationRejectFile();
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $ns => $n) {
        $out[(string)$ns] = (int)$n;
    }
    return $out;
}

/**
 * Increment a best-effort counter.
 *
 * apcu_inc() does not create a missing key, so seed it first. A lost counter is acceptable; a
 * counter that throws inside a cache path is not.
 */
function cacheGenerationStatsInc(string $key): void
{
    static $available = null;
    if ($available === null) {
        $available = function_exists('apcu_fetch') && function_exists('apcu_store');
    }
    if (!$available) {
        return;
    }
    if (@apcu_exists($key)) {
        @apcu_inc($key, 1, $ok, 300);
        return;
    }
    @apcu_store($key, 1, 300);
}

/**
 * Stale-rejection rate per namespace: rejects / reads, as a percentage.
 *
 * This is the ONE metric that distinguishes "invalidation is working" from "invalidation is merely
 * configured": a successful bump proves nothing on its own, and a hit rate cannot tell valid reuse
 * from stale reuse (chair consult 2026-10-11, Q7).
 *
 * apcu_cache_info() lists keys but not values, so the keys are enumerated and each value is then
 * fetched. REJECTS are shared (file-backed) and therefore fleet-visible; READS are APCu-backed and
 * therefore describe the current worker only. That split is deliberate and stated in the output.
 *
 * @return array<string,array{reads:int,rejects:int,rate:float|null}> A null rate means the
 *         denominator is unknown (no APCu in this process), NOT that the rate is zero.
 */
function cacheGenerationStats(): array
{
    $out = [];

    // Rejects come from the SHARED file so they survive a worker boundary and are visible from CLI.
    foreach (cacheGenerationRejectCounts() as $ns => $n) {
        // rate defaults to null, NOT 0.0: see the docblock - 0% beside a non-zero reject count reads
        // as healthy and would hide the signal.
        $out[$ns] ??= ['reads' => 0, 'rejects' => 0, 'rate' => null];
        $out[$ns]['rejects'] = $n;
    }

    // Reads come from this worker's APCu: the denominator increments on every read, so it must
    // stay cheap, and it is reported as a per-worker view rather than a fleet total.
    if (!function_exists('apcu_cache_info') || !function_exists('apcu_fetch')) {
        foreach ($out as $ns => $row) {
            // No denominator available: report null, never 0.0. A rate of "0%" beside a non-zero
            // reject count reads as healthy and would hide exactly the signal being measured.
            $out[$ns]['rate'] = null;
        }
        return $out;
    }
    $info = @apcu_cache_info();
    if (!is_array($info) || !isset($info['cache_list']) || !is_array($info['cache_list'])) {
        return $out;
    }
    foreach ($info['cache_list'] as $row) {
        $key = (string)($row['info'] ?? $row['key'] ?? '');
        if (!str_starts_with($key, 'cache_gen_')) {
            continue;
        }
        $parts = explode(':', $key, 2);
        $field = $parts[0];
        $ns = $parts[1] ?? '';
        $value = @apcu_fetch($key);
        if (!is_int($value)) {
            continue;
        }
        $out[$ns] ??= ['reads' => 0, 'rejects' => 0, 'rate' => null];
        // Only reads are APCu-backed here. Rejects come from the shared file above and must not be
        // overwritten by a per-worker value.
        if ($field === 'cache_gen_reads') {
            $out[$ns]['reads'] = $value;
        }
    }
    foreach ($out as $ns => $row) {
        $out[$ns]['rate'] = $row['reads'] > 0
            ? round(100 * $row['rejects'] / $row['reads'], 2)
            : null;
    }
    return $out;
}

// ── Generation-bound memos ──────────────────────────────────────────────────────
//
// The rule at the top of this file ("shared storage is authoritative, APCu is only an
// optimisation") applies to EVERY per-worker memo, not just the caches that were fixed on
// 2026-10-11. Three kernel memos still fronted a shared source with a 1-hour TTL and no
// revalidation - the module registry, the schema read contracts, and the tenant entry landing -
// so a module enable/disable, a migration, or a routing change could be invisible to every
// worker except the one that performed it, for an hour.
//
// Fixing those three by hand would leave the next memo free to make the same mistake. These two
// helpers make the correct behaviour the DEFAULT: a memo is stored with the generation it was
// written under and is discarded the moment that generation moves. Callers cannot forget, because
// there is no unbound variant to reach for.

/** Namespace holding kernel configuration generations (module set, routes, schema, tenant config). */
const CACHE_GENERATION_CONFIG_NAMESPACE = 'kernel_config';

/**
 * Read a per-worker APCu memo bound to a shared generation.
 *
 * Returns null when the memo is absent, unstamped, or was written under a generation that has since
 * moved. Callers recompute from the authoritative source on null. A legacy unstamped entry is
 * evicted rather than trusted, so it is rewritten stamped on the next store.
 */
function cacheMemoRead(string $apcuKey, string $namespace = CACHE_GENERATION_CONFIG_NAMESPACE, string $key = CACHE_GENERATION_DEFAULT_KEY): mixed
{
    if (!function_exists('apcu_fetch')) {
        return null;
    }
    $entry = @apcu_fetch($apcuKey, $ok);
    if (!$ok || !is_array($entry) || !array_key_exists('__gen', $entry)) {
        if ($ok) {
            // Present but unstamped: written before this rule existed, so its staleness is unknown.
            @apcu_delete($apcuKey);
        }
        return null;
    }

    // cacheGenerationValid() also records the read/reject metric, so every memo using this helper
    // shows up in `php ikabud cache:stats` under its namespace.
    if (!cacheGenerationValid($namespace, (string)$entry['__gen'], $key)) {
        @apcu_delete($apcuKey);
        return null;
    }

    return $entry['__value'] ?? null;
}

/** Store a per-worker APCu memo together with the generation it was written under. */
function cacheMemoWrite(string $apcuKey, mixed $value, int $ttl, string $namespace = CACHE_GENERATION_CONFIG_NAMESPACE, string $key = CACHE_GENERATION_DEFAULT_KEY): void
{
    if (!function_exists('apcu_store')) {
        return;
    }
    @apcu_store($apcuKey, [
        '__gen' => cacheGenerationRead($namespace, $key),
        '__value' => $value,
    ], $ttl);
}

/**
 * Publish a new configuration generation.
 *
 * MUST be called by anything that changes the module set, the route map, the schema, or tenant
 * configuration. It is the only invalidation signal that reaches every php-fpm worker: those
 * changes are handled by deleting APCu keys, and APCu is per-worker, so a delete only ever affects
 * the worker that performed it - including `php ikabud cache:clear` from CLI, whose APCu is a
 * different (and usually empty) store entirely.
 *
 * Idempotent and safe to over-call: bumping does not corrupt anything, it only forces memos to be
 * recomputed.
 */
function cacheConfigGenerationBump(): string
{
    return cacheGenerationBump(CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_DEFAULT_KEY);
}

/** Current configuration generation ('' when nothing has been bumped yet). */
function cacheConfigGeneration(): string
{
    return cacheGenerationRead(CACHE_GENERATION_CONFIG_NAMESPACE, CACHE_GENERATION_DEFAULT_KEY);
}

/**
 * Resolve the tenant scope for a cache operation — FAIL CLOSED.
 *
 * The defect this closes: FragmentStore defaulted `$tenantId` to the literal '_global', and nothing
 * ever called TemplateEngine::setTenantId(), so every tenant's fragment output was keyed into one
 * shared namespace. A default that silently widens scope to "everyone" is fail-OPEN: the caller
 * that forgets the argument gets cross-tenant sharing instead of an error.
 *
 * Order: explicit argument, then the app's current tenant, then '' (explicitly unscoped). An empty
 * result is a legitimate single-tenant/CLI state, NOT a licence to share across tenants — callers
 * that need tenant isolation must treat '' as "no tenant" and use the unscoped namespace only when
 * the content is genuinely tenant-independent.
 */
function cacheScopeTenant(?string $explicit = null): string
{
    if ($explicit !== null && $explicit !== '') {
        return $explicit;
    }
    if (function_exists('app')) {
        try {
            $tenant = app()->tenant();
            if ($tenant !== null && method_exists($tenant, 'current')) {
                $cur = $tenant->current();
                if ($cur !== null && $cur !== '') {
                    return (string)$cur;
                }
            }
        } catch (\Throwable $e) {
            // A cache lookup must never take down a request. Fall through to the unscoped value.
        }
    }
    return '';
}

/**
 * Namespace for a tenant-scoped cache. Empty tenant yields an explicit, self-describing
 * `_unscoped` namespace rather than a bare shared one, so a stray global cache is visible in the
 * filesystem and in metrics instead of looking legitimate.
 */
function cacheScopeNamespace(string $base, ?string $tenant = null): string
{
    $t = cacheScopeTenant($tenant);
    return $t !== '' ? $base . '_t' . $t : $base . '_unscoped';
}

/** Best-effort operational log. Never throws, never logs payload content. */
function cacheGenerationLog(string $event, string $namespace, string $key = ''): void
{
    if (function_exists('write_log')) {
        @write_log('[cache-generation] ' . $event . ' ns=' . $namespace . ' key=' . $key, 'warning');
    }
}
