<?php
/**
 * DiSyL Source Cache
 *
 * Reads and caches template/include source files for the TemplateEngine:
 * in-memory per-request caches plus an APCu cross-request cache keyed by
 * path+mtime. Extracted from TemplateEngine (D8 refactor) so the
 * source-reading layer can be shared by the render pipeline, include
 * resolution, and extends/layout processing without duplicating cache state.
 *
 * Behavior is identical to the previous TemplateEngine::readTemplateSource /
 * readIncludeSource implementations; the cache-metrics counters still
 * increment the TemplateEngine aggregate via the injected closure.
 *
 * @package Ikabud\Kernel\DiSyL\Cache
 */

namespace Ikabud\Kernel\DiSyL\Cache;

final class SourceCache
{
    /** Max in-memory source entries before LRU-style eviction. */
    private const TEMPLATE_SOURCE_CACHE_MAX = 100;

    /** APCu cross-request TTL for cached source content (seconds). */
    private const APCU_TTL = 300;

    private bool $enabled;

    /** @var array<string, string> path => content */
    private array $templateSourceCache = [];

    /** @var array<string, string> path => content */
    private array $includeSourceCache = [];

    /** @var callable(string): void Increments a TemplateEngine cache-metric counter. */
    private $incrementMetric;

    /** @var callable(): bool Whether APCu is available and enabled. */
    private $hasApcu;

    public function __construct(bool $enabled, callable $incrementMetric, callable $hasApcu)
    {
        $this->enabled = $enabled;
        $this->incrementMetric = $incrementMetric;
        $this->hasApcu = $hasApcu;
    }

    /**
     * Read a top-level template source file (validates path/readability).
     */
    public function readTemplate(string $templatePath): string|false
    {
        if ($templatePath === '' || !is_file($templatePath) || !is_readable($templatePath)) {
            return false;
        }
        return $this->read($templatePath, true);
    }

    /**
     * Read an include source file (caller has already resolved the path).
     */
    public function readInclude(string $includePath): string|false
    {
        return $this->read($includePath, false);
    }

    private function read(string $path, bool $isTemplate): string|false
    {
        $cache = $isTemplate ? $this->templateSourceCache : $this->includeSourceCache;

        if ($this->enabled && isset($cache[$path])) {
            ($this->incrementMetric)('source_hits');
            return $cache[$path];
        }

        if ($this->enabled && ($this->hasApcu)()) {
            $mtime = (int)@filemtime($path);
            $apcuKey = 'disyl:source:' . md5($path . '|' . $mtime);
            $cached = apcu_fetch($apcuKey, $ok);
            if ($ok && is_string($cached)) {
                ($this->incrementMetric)('source_hits');
                $this->store($isTemplate, $path, $cached);
                return $cached;
            }
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return false;
        }
        ($this->incrementMetric)('source_misses');

        if ($this->enabled) {
            $this->store($isTemplate, $path, $content);
            if (($this->hasApcu)()) {
                $mtime = (int)@filemtime($path);
                $apcuKey = 'disyl:source:' . md5($path . '|' . $mtime);
                apcu_store($apcuKey, $content, self::APCU_TTL);
            }
        }

        return $content;
    }

    private function store(bool $isTemplate, string $path, string $content): void
    {
        if ($isTemplate) {
            if (count($this->templateSourceCache) >= self::TEMPLATE_SOURCE_CACHE_MAX) {
                reset($this->templateSourceCache);
                unset($this->templateSourceCache[key($this->templateSourceCache)]);
            }
            $this->templateSourceCache[$path] = $content;
        } else {
            if (count($this->includeSourceCache) >= self::TEMPLATE_SOURCE_CACHE_MAX) {
                reset($this->includeSourceCache);
                unset($this->includeSourceCache[key($this->includeSourceCache)]);
            }
            $this->includeSourceCache[$path] = $content;
        }
    }

    /** Clear the in-memory source caches. */
    public function reset(): void
    {
        $this->templateSourceCache = [];
        $this->includeSourceCache = [];
    }
}
