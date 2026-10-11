<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Cache;

/**
 * DiSyL 4.3 — Fragment cache (file-backed v1).
 *
 * Stores rendered fragments on disk under `storage/cache/disyl-fragments/`
 * with a per-tenant prefix. Each fragment file is JSON:
 *
 *   { "body": "...", "expires_at": 0|int, "deps_hash": "sha256" }
 *
 * Dependency versions live in a single file `dep_versions.json` per tenant,
 * mapping tag → integer version. `invalidate(tag)` increments the version,
 * which causes the next `tryGet` to recompute `deps_hash` and treat the
 * fragment as a miss.
 *
 * APCu is consulted as a hot layer for the FRAGMENT PAYLOAD only. Dependency
 * versions are read from disk, uncached, because they ARE the invalidation
 * signal — see loadDepVersions() for why memoising them was a defect.
 *
 * TENANT SCOPE IS FAIL-CLOSED. The constructors here used to default
 * `$tenantId` to the literal '_global', and nothing ever called
 * TemplateEngine::setTenantId(), so every tenant's fragment output landed in
 * one shared namespace. A default that silently widens scope to everyone is
 * fail-OPEN: the caller who forgets the argument gets cross-tenant sharing
 * instead of an error. Callers now pass the tenant, or null/'' to resolve it
 * from the request.
 *
 * Out of scope (queued for 4.3.1):
 *   - DB-backed multi-tenant storage with `disyl_cache_fragments` table
 *   - Cross-process stampede protection beyond per-process APCu lock
 *   - Distributed dependency-version invalidation (multi-server)
 */
final class FragmentStore
{
    private string $root;
    private bool $apcu;

    public function __construct(?string $root = null)
    {
        $this->root = $root
            ?? (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../../../storage')
                . '/cache/disyl-fragments';
        if (!is_dir($this->root)) {
            @mkdir($this->root, 0775, true);
        }
        $this->apcu = function_exists('apcu_fetch') && function_exists('apcu_store')
            && \ini_get('apc.enabled') !== '0';

        // The generation authority is required defensively: this class is instantiated from
        // TemplateEngine, which CLI paths may reach without public/index.php having run.
        if (!function_exists('cacheGenerationLog')) {
            $gen = dirname(__DIR__, 3) . '/src/helpers/cache-generation.php';
            if (is_file($gen)) {
                require_once $gen;
            }
        }
    }

    /**
     * Resolve the tenant scope, fail-closed.
     *
     * null/'' resolve from the current request. A genuine no-tenant context (CLI, or a request with
     * no resolved tenant) becomes a self-describing `_unscoped` namespace rather than a silently
     * shared one. An explicit '_global' is preserved verbatim so already-written entries stay
     * reachable during rollout; new callers should pass the tenant instead.
     */
    private function tenantScope(?string $tenantId): string
    {
        if ($tenantId !== null && $tenantId !== '' && $tenantId !== '_global') {
            return $tenantId;
        }
        if ($tenantId === '_global') {
            return '_global';
        }
        $resolved = function_exists('cacheScopeTenant') ? cacheScopeTenant() : '';
        return $resolved !== '' ? $resolved : '_unscoped';
    }

    /** @param list<string> $deps */
    public function tryGet(string $key, array $deps, ?string $tenantId = null): ?string
    {
        $tenantId = $this->tenantScope($tenantId);
        $depsHash = $this->depsHash($deps, $tenantId);
        $apcKey = $this->apcKey($key, $tenantId);
        if ($this->apcu) {
            $entry = \apcu_fetch($apcKey, $ok);
            if ($ok && is_array($entry) && $this->valid($entry, $depsHash)) {
                return $entry['body'];
            }
        }
        $path = $this->path($key, $tenantId);
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if (!is_string($raw)) return null;
        $entry = json_decode($raw, true);
        if (!is_array($entry) || !$this->valid($entry, $depsHash)) return null;
        if ($this->apcu) {
            \apcu_store($apcKey, $entry, max(0, ($entry['expires_at'] ?? 0) - time()));
        }
        return $entry['body'];
    }

    /** @param list<string> $deps */
    public function put(string $key, string $body, array $deps, int $ttl, ?string $tenantId = null): void
    {
        if ($ttl < 0) return;
        $tenantId = $this->tenantScope($tenantId);
        $entry = [
            'body'       => $body,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
            'deps_hash'  => $this->depsHash($deps, $tenantId),
        ];
        $path = $this->path($key, $tenantId);
        @file_put_contents($path, json_encode($entry), LOCK_EX);
        if ($this->apcu) {
            \apcu_store($this->apcKey($key, $tenantId), $entry, $ttl > 0 ? $ttl : 0);
        }
    }

    /** @param list<string> $tags */
    public function invalidate(array $tags, ?string $tenantId = null): void
    {
        $tenantId = $this->tenantScope($tenantId);
        $file = $this->depFile($tenantId);

        // LOCKED read-modify-write. The previous implementation read the map, incremented it in
        // memory and wrote it back with no lock, so two workers invalidating different tags at the
        // same moment could each read the same map and one bump was silently lost - the loser's tag
        // then never invalidates again until its fragments expire. The lock spans read AND write,
        // and the write is truncated in place so no reader can see a torn value.
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            $this->logGen('frag-dep-open-failed', $tenantId);
            return;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            $this->logGen('frag-dep-lock-failed', $tenantId);
            return;
        }

        $raw = stream_get_contents($fp);
        $versions = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : [];
        if (!is_array($versions)) {
            $versions = [];
        }
        foreach ($tags as $tag) {
            $versions[$tag] = ($versions[$tag] ?? 0) + 1;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($versions));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        // Own worker only; correctness no longer depends on this because loadDepVersions() reads
        // the shared file.
        if ($this->apcu) {
            \apcu_delete($this->depApcKey($tenantId));
        }
    }

    public function flushAll(?string $tenantId = null): void
    {
        $tenantId = $this->tenantScope($tenantId);
        $glob = glob($this->root . '/' . $this->safe($tenantId) . '/*') ?: [];
        foreach ($glob as $f) @unlink($f);
        @rmdir($this->root . '/' . $this->safe($tenantId));
        if ($this->apcu) {
            \apcu_delete($this->depApcKey($tenantId));
        }
    }

    private function logGen(string $event, string $tenantId): void
    {
        if (function_exists('cacheGenerationLog')) {
            cacheGenerationLog($event, 'disyl-fragments', $tenantId);
        }
    }

    /** @param array<string,mixed> $entry */
    private function valid(array $entry, string $depsHash): bool
    {
        $exp = $entry['expires_at'] ?? 0;
        if ($exp !== 0 && $exp < time()) return false;
        return ($entry['deps_hash'] ?? '') === $depsHash;
    }

    /** @param list<string> $deps */
    private function depsHash(array $deps, string $tenantId): string
    {
        if ($deps === []) return 'nodeps';
        $versions = $this->loadDepVersions($tenantId);
        $parts = [];
        foreach ($deps as $tag) {
            $parts[] = $tag . '=' . ($versions[$tag] ?? 0);
        }
        return hash('sha256', implode(';', $parts));
    }

    /** @return array<string,int> */
    private function loadDepVersions(string $tenantId): array
    {
        // FILE FIRST, and deliberately MEMOISED NOWHERE. These versions ARE the invalidation
        // signal, so caching them per worker reproduced the exact defect this store was just fixed
        // for: a writer bumps the shared file and deletes only its own worker's APCu key, so every
        // other worker keeps re-validating stale entries against a stale version for the memo's
        // whole TTL. Measured 2026-10-11 (this site read depApcKey() before the file, with a 60s
        // TTL). One small JSON read per lookup is far cheaper than that failure.
        $file = $this->depFile($tenantId);
        if (!is_file($file)) return [];
        $raw = @file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function path(string $key, string $tenantId): string
    {
        $dir = $this->root . '/' . $this->safe($tenantId);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/' . hash('sha256', $key) . '.json';
    }

    private function depFile(string $tenantId): string
    {
        $dir = $this->root . '/' . $this->safe($tenantId);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/_dep_versions.json';
    }

    private function apcKey(string $key, string $tenantId): string
    {
        return 'disyl_frag:' . $tenantId . ':' . hash('sha256', $key);
    }

    private function depApcKey(string $tenantId): string
    {
        return 'disyl_frag_dv:' . $tenantId;
    }

    private function safe(string $s): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $s) ?? '_';
    }
}
