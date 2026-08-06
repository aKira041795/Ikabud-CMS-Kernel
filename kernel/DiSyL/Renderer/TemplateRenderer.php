<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Renderer;

/**
 * TemplateRenderer — output-cache + metrics + fast-fingerprint machinery for
 * the DiSyL render path (D8 decomposition, partial increment).
 *
 * Extracted from TemplateEngine. Owns the per-worker render cache state and
 * counters that were formerly private properties/statics on the engine:
 *   - in-memory output cache (bounded by OUTPUT_CACHE_MAX)
 *   - shared (APCu) output-cache TTL
 *   - aggregate cache hit/miss/compile metrics
 *   - the fast context fingerprint used to build output-cache keys without
 *     serializing non-serializable context payloads
 *
 * The top-level render()/renderString() orchestration and the interpreted
 * evaluator cluster (compile(), processControlStructures(), ...) remain in
 * TemplateEngine as the documented "render core" per the D8 refactor plan;
 * the engine delegates cache-key, metrics, and TTL operations to this class.
 */
final class TemplateRenderer
{
    /** @var int Maximum number of entries in the in-memory output cache */
    public const OUTPUT_CACHE_MAX = 200;

    /** @var int Maximum nesting depth for the fast output-cache key path before falling back to serialize() */
    public const OUTPUT_CACHE_KEY_FAST_DEPTH = 8;

    /** @var int Emit cache metrics log every N renders */
    private const CACHE_METRICS_LOG_INTERVAL = 100;

    /** @var array<string, string> In-memory output cache keyed by mem key */
    private array $outputCache = [];

    /** @var int Shared APCu rendered-output cache TTL (seconds). 0 = disabled. */
    private int $sharedOutputCacheTtl = 0;

    /** @var array<string, int> Aggregate cache metrics for the current FPM worker */
    private static array $cacheMetrics = [
        'output_hits' => 0,
        'output_misses' => 0,
        'source_hits' => 0,
        'source_misses' => 0,
        'compiles' => 0,
    ];

    /** @var int Render calls since last metrics log */
    private static int $rendersSinceMetricsLog = 0;

    /** @var bool Whether the cache authority warning has already been emitted this process. */
    private static bool $cacheAuthorityWarningEmitted = false;

    /** Build an in-memory output-cache key (fast fingerprint, else serialize fallback). */
    public function buildOutputCacheKey(string $templatePath, array $context): string
    {
        $fastFingerprint = $this->tryBuildFastContextFingerprint($context);
        if ($fastFingerprint !== null) {
            return $templatePath . '|' . $fastFingerprint;
        }

        try {
            return $templatePath . '|' . md5(serialize($context));
        } catch (\Throwable $e) {
            // Non-serializable context payloads (e.g. closures) should not explode render path.
            return $templatePath . '|uncacheable|' . md5(spl_object_hash($this) . '|' . (string)microtime(true));
        }
    }

    /** Build the cross-request (APCu) shared output-cache key, mtime-versioned. */
    public function buildSharedOutputCacheKey(string $templatePath, array $context): string
    {
        $mtime = (int)@filemtime($templatePath);
        return 'disyl:render:' . md5($templatePath . '|' . $mtime . '|' . $this->buildOutputCacheKey($templatePath, $context));
    }

    /** Shared APCu output-cache TTL (seconds). 0 = disabled. */
    public function sharedOutputCacheTtl(): int
    {
        return $this->sharedOutputCacheTtl;
    }

    /** Set the shared (APCu) output-cache TTL, emitting the authority warning once. */
    public function setSharedOutputCacheTtl(int $seconds): void
    {
        $this->sharedOutputCacheTtl = max(0, $seconds);
        if ($this->sharedOutputCacheTtl > 0 && !self::$cacheAuthorityWarningEmitted) {
            self::$cacheAuthorityWarningEmitted = true;
            if (function_exists('write_log')) {
                write_log('disyl.cache.authority_warning', 'warning', [
                    'shared_output_ttl' => $this->sharedOutputCacheTtl,
                    'message' => 'Shared output cache is active. Ensure it does not overlap with handler-level page caches to avoid stale content.',
                ]);
            }
        }
    }

    /** Fetch a memoized output string, or null on miss. */
    public function outputCacheGet(string $key): ?string
    {
        return $this->outputCache[$key] ?? null;
    }

    /** Whether a memoized output string exists for the given key. */
    public function hasOutputCacheKey(string $key): bool
    {
        return isset($this->outputCache[$key]);
    }

    /** Store an output string, evicting the oldest entry when the cache is full. */
    public function outputCacheSet(string $key, string $value): void
    {
        if (count($this->outputCache) >= self::OUTPUT_CACHE_MAX) {
            reset($this->outputCache);
            unset($this->outputCache[key($this->outputCache)]);
        }
        $this->outputCache[$key] = $value;
    }

    /** Return aggregate cache hit/miss counters for the current FPM worker. */
    public static function getCacheMetrics(): array
    {
        return self::$cacheMetrics;
    }

    /** Reset aggregate cache counters. */
    public static function resetCacheMetrics(): void
    {
        self::$cacheMetrics = array_map(fn() => 0, self::$cacheMetrics);
        self::$rendersSinceMetricsLog = 0;
        self::$cacheAuthorityWarningEmitted = false;
    }

    /** Increment an aggregate cache/compile counter (used by compile() and SourceCache). */
    public static function incrementMetric(string $metric): void
    {
        self::$cacheMetrics[$metric] = (self::$cacheMetrics[$metric] ?? 0) + 1;
    }

    /** Emit a periodic cache metrics log entry. */
    public function logCacheMetricsPeriodic(): void
    {
        if (++self::$rendersSinceMetricsLog < self::CACHE_METRICS_LOG_INTERVAL) {
            return;
        }
        self::$rendersSinceMetricsLog = 0;
        if (function_exists('write_log')) {
            $m = self::$cacheMetrics;
            $totalOutput = $m['output_hits'] + $m['output_misses'];
            $totalSource = $m['source_hits'] + $m['source_misses'];
            write_log('disyl.cache.metrics', 'info', [
                'output_hit_pct' => $totalOutput > 0 ? round($m['output_hits'] / $totalOutput * 100, 1) : null,
                'source_hit_pct' => $totalSource > 0 ? round($m['source_hits'] / $totalSource * 100, 1) : null,
                'compiles' => $m['compiles'],
                'output_hits' => $m['output_hits'],
                'output_misses' => $m['output_misses'],
                'source_hits' => $m['source_hits'],
                'source_misses' => $m['source_misses'],
            ]);
        }
    }

    /**
     * Attempt a fast context fingerprint (md5 over hashed values). Returns
     * null when the context contains a value the fast path cannot hash, in
     * which case the caller falls back to serialize().
     */
    private function tryBuildFastContextFingerprint(array $context): ?string
    {
        $hash = hash_init('md5');
        if (!$this->hashContextValue($hash, $context, 0)) {
            return null;
        }

        return hash_final($hash);
    }

    /**
     * Hash a context value into the running md5 context. Returns false when
     * the value is not fast-hashable (beyond depth, closures, resources).
     */
    private function hashContextValue($hash, mixed $value, int $depth): bool
    {
        if ($depth > self::OUTPUT_CACHE_KEY_FAST_DEPTH) {
            return false;
        }

        if ($value === null || is_scalar($value)) {
            hash_update($hash, serialize($value));
            return true;
        }

        if (is_array($value)) {
            hash_update($hash, 'a' . count($value) . '{');
            foreach ($value as $key => $item) {
                if (!$this->hashContextValue($hash, $key, $depth + 1)) {
                    return false;
                }
                if (!$this->hashContextValue($hash, $item, $depth + 1)) {
                    return false;
                }
            }
            hash_update($hash, '}');
            return true;
        }

        if ($value instanceof \DateTimeInterface) {
            hash_update($hash, 'dt:' . get_class($value) . ':' . $value->format(\DateTimeInterface::ATOM));
            return true;
        }

        if ($value instanceof \JsonSerializable) {
            hash_update($hash, 'js:' . get_class($value) . '{');
            $ok = $this->hashContextValue($hash, $value->jsonSerialize(), $depth + 1);
            hash_update($hash, '}');
            return $ok;
        }

        if ($value instanceof \Stringable) {
            hash_update($hash, 'st:' . get_class($value) . ':' . (string)$value);
            return true;
        }

        if ($value instanceof \UnitEnum) {
            hash_update($hash, 'en:' . get_class($value) . ':' . $value->name);
            return true;
        }

        if ($value instanceof \Closure || is_resource($value)) {
            return false;
        }

        if (is_object($value)) {
            try {
                $serialized = serialize($value);
            } catch (\Throwable $e) {
                return false;
            }

            hash_update($hash, 'ob:' . $serialized);
            return true;
        }

        return false;
    }
}
