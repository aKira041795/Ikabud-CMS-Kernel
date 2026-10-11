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
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
        // mkdir honours umask, which on shared hosting often yields 0755. The web user and the CLI
        // user may differ, so make the tree explicitly writable by the owner.
        @chmod($dir, 0777);
    }

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
    cacheGenerationStatsInc('cache_gen_rejects:' . cacheGenerationSafe($namespace));
    return false;
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
 * fetched. APCu is per-worker, so this reports the CURRENT worker only — correct for a CLI/diagnostic
 * call, not a fleet aggregate.
 *
 * @return array<string,array{reads:int,rejects:int,rate:float}>
 */
function cacheGenerationStats(): array
{
    $out = [];
    if (!function_exists('apcu_cache_info') || !function_exists('apcu_fetch')) {
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
        $out[$ns] ??= ['reads' => 0, 'rejects' => 0, 'rate' => 0.0];
        if ($field === 'cache_gen_reads') {
            $out[$ns]['reads'] = $value;
        } elseif ($field === 'cache_gen_rejects') {
            $out[$ns]['rejects'] = $value;
        }
    }
    foreach ($out as $ns => $row) {
        $out[$ns]['rate'] = $row['reads'] > 0 ? round(100 * $row['rejects'] / $row['reads'], 2) : 0.0;
    }
    return $out;
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
