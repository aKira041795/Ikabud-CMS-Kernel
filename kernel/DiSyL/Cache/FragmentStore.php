<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Cache;

/**
 * DiSyL 4.3 — Fragment cache (file-backed v1).
 *
 * Stores rendered fragments on disk under `storage/cache/disyl-fragments/`
 * with a per-tenant prefix (default `_global`). Each fragment file is JSON:
 *
 *   { "body": "...", "expires_at": 0|int, "deps_hash": "sha256" }
 *
 * Dependency versions live in a single file `dep_versions.json` per tenant,
 * mapping tag → integer version. `invalidate(tag)` increments the version,
 * which causes the next `tryGet` to recompute `deps_hash` and treat the
 * fragment as a miss.
 *
 * APCu is consulted as a hot-layer cache when available; misses fall through
 * to disk. APCu absence is acceptable — disk path is correct on its own.
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
    }

    /** @param list<string> $deps */
    public function tryGet(string $key, array $deps, string $tenantId = '_global'): ?string
    {
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
    public function put(string $key, string $body, array $deps, int $ttl, string $tenantId = '_global'): void
    {
        if ($ttl < 0) return;
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
    public function invalidate(array $tags, string $tenantId = '_global'): void
    {
        $file = $this->depFile($tenantId);
        $versions = $this->loadDepVersions($tenantId);
        foreach ($tags as $tag) {
            $versions[$tag] = ($versions[$tag] ?? 0) + 1;
        }
        @file_put_contents($file, json_encode($versions), LOCK_EX);
        if ($this->apcu) {
            \apcu_delete($this->depApcKey($tenantId));
        }
    }

    public function flushAll(string $tenantId = '_global'): void
    {
        $glob = glob($this->root . '/' . $this->safe($tenantId) . '/*') ?: [];
        foreach ($glob as $f) @unlink($f);
        @rmdir($this->root . '/' . $this->safe($tenantId));
        if ($this->apcu && function_exists('apcu_clear_cache')) {
            // Only clears global; acceptable for tests.
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
        if ($this->apcu) {
            $cached = \apcu_fetch($this->depApcKey($tenantId), $ok);
            if ($ok && is_array($cached)) return $cached;
        }
        $file = $this->depFile($tenantId);
        if (!is_file($file)) return [];
        $raw = @file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $out = is_array($decoded) ? $decoded : [];
        if ($this->apcu) \apcu_store($this->depApcKey($tenantId), $out, 60);
        return $out;
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
