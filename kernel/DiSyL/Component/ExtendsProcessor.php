<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Component;

/**
 * ExtendsProcessor — handles template inheritance ({extends}) plus the
 * standalone {block} and {debug} passes.
 *
 * Extracted from TemplateEngine as part of the D8 decomposition. Decoupled
 * via injected closures: the engine supplies resolveTemplatePath(),
 * readTemplateSource() (through SourceCache), resolveValue(), logError(),
 * the current template path, the extends cache directory, and the cache
 * enabled flag. Owns the cross-request extends cache (versioned, mtime
 * validated, atomic writes) and its chain-depth guard.
 */
final class ExtendsProcessor
{
    /** @var int Upper bound on {extends} chain depth (guards runaway inheritance) */
    private const EXTENDS_CHAIN_MAX = 20;

    /** @var int Max block-override merge passes over the root ancestor */
    private const MAX_BLOCK_MERGE_PASSES = 10;

    /**
     * @param callable(string): string        $resolveTemplatePath  Resolve a layout name to a filesystem path
     * @param callable(string): string|false  $readTemplateSource   Read template source via SourceCache
     * @param callable(mixed, array): mixed   $resolveValue         Resolve a path/expr against context
     * @param callable(string): void          $logError             Route an engine-level error log entry
     * @param callable(): ?string             $getCurrentTemplatePath
     * @param callable(): string              $getExtendsCacheDir
     * @param callable(): bool                $isCacheEnabled
     */
    public function __construct(
        private $resolveTemplatePath,
        private $readTemplateSource,
        private $resolveValue,
        private $logError,
        private $getCurrentTemplatePath,
        private $getExtendsCacheDir,
        private $isCacheEnabled,
    ) {
    }

    /**
     * Resolve {extends "layout"} inheritance: walk child → root, merge
     * {block} overrides (child wins), strip the directive, and cache the
     * merged result across requests (mtime-validated).
     */
    public function processExtends(string $content, array $context): string
    {
        $isHtmx = !empty($context['is_htmx']);

        if (!preg_match('/\{extends\s+"([^"]+)"\s*\}/', $content, $match)) {
            return $content;
        }

        if ($isHtmx) {
            // For HTMX: extract block content without any layout wrapping
            preg_match_all('/\{block\s+(?:"?(\w+)"?)\}(.*?)\{\/block\}/s', $content, $blocks, PREG_SET_ORDER);
            $blockContent = '';
            foreach ($blocks as $block) {
                $blockContent .= $block[2];
            }
            return preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $blockContent ?: $content);
        }

        // ── Cross-request extends resolution cache ──────────────────────
        // The extends chain resolution (file reads + regex block merging)
        // depends only on file contents, not runtime context.  Cache the
        // merged result keyed by template path, validated against the
        // mtime of every file in the chain.
        $currentTemplatePath = ($this->getCurrentTemplatePath)();
        $extendsCacheKey = null;
        if (($this->isCacheEnabled)() && $currentTemplatePath !== null) {
            $extendsCacheKey = $currentTemplatePath;
            $cached = $this->getExtendsCache($extendsCacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Walk the full inheritance chain from child → root, collecting each template.
        // $chain[0] is the child; last element is the first ancestor with no {extends}.
        $chain    = [];
        $seenPaths = [];
        $current  = $content;
        $chainDepth = 0;

        while (preg_match('/\{extends\s+"([^"]+)"\s*\}/', $current, $extMatch)) {
            if ($chainDepth >= self::EXTENDS_CHAIN_MAX) {
                ($this->logError)('Extends chain depth exceeded maximum (' . self::EXTENDS_CHAIN_MAX . ')');
                $current = preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $current);
                break;
            }

            $layoutName = $extMatch[1];
            $layoutPath = ($this->resolveTemplatePath)($layoutName);

            if (!file_exists($layoutPath)) {
                // Missing layout: strip directive and treat this as the root
                $current = preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $current);
                break;
            }

            $realPath = realpath($layoutPath) ?: $layoutPath;
            if (isset($seenPaths[$realPath])) {
                ($this->logError)("Circular {extends} detected: \"{$layoutName}\"");
                $current = preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $current);
                break;
            }

            $seenPaths[$realPath] = true;
            $chain[] = $current;
            $layoutContent = ($this->readTemplateSource)($layoutPath);
            if ($layoutContent === false) {
                ($this->logError)("Failed to read layout: {$layoutName}");
                $current = preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $current);
                break;
            }
            $current = $layoutContent;
            $chainDepth++;
        }

        // $current is now the root ancestor. Collect block overrides from the chain
        // with child definitions winning over parent definitions (first one wins).
        $allBlocks = [];
        foreach ($chain as $template) {
            preg_match_all('/\{block\s+(?:"?(\w+)"?)\}(.*?)\{\/block\}/s', $template, $blocks, PREG_SET_ORDER);
            foreach ($blocks as $block) {
                if (!isset($allBlocks[$block[1]])) {
                    $allBlocks[$block[1]] = $block[2];
                }
            }
        }

        // Apply all collected overrides to the root ancestor in one pass.
        // Iterate until stable to handle multiple block levels in the ancestor itself.
        $result   = $current;
        for ($pass = 0; $pass < self::MAX_BLOCK_MERGE_PASSES; $pass++) {
            $new = preg_replace_callback(
                '/\{block\s+(?:"?(\w+)"?)\}(.*?)\{\/block\}/s',
                fn($m) => $allBlocks[$m[1]] ?? $m[2],
                $result
            );
            if ($new === $result) {
                break;
            }
            $result = $new;
        }

        $result = preg_replace('/\{extends\s+"[^"]+"\s*\}/', '', $result ?? $current);

        // Store in cross-request cache with all file dependencies
        if ($extendsCacheKey !== null && !empty($seenPaths)) {
            $deps = [];
            if (file_exists($extendsCacheKey)) {
                $deps[$extendsCacheKey] = filemtime($extendsCacheKey);
            }
            foreach ($seenPaths as $depPath => $_) {
                if (file_exists($depPath)) {
                    $deps[$depPath] = filemtime($depPath);
                }
            }
            $this->setExtendsCache($extendsCacheKey, $result, $deps);
        }

        return $result;
    }

    // ── Cross-request extends resolution cache ──────────────────────

    /**
     * Retrieve a cached extends-resolved template.
     *
     * The cache key includes the template's mtime so that any source change
     * naturally produces a new cache entry — no stale cache can be served.
     * Deps validation is a secondary safeguard for parent template changes.
     *
     * Returns null on miss or stale.
     */
    private function getExtendsCache(string $templatePath): ?string
    {
        $extendsCacheDir = ($this->getExtendsCacheDir)();

        // Versioned key: template path + current mtime ensures source changes
        // produce new cache entries. Old entries are cleaned up by TTL-based GC.
        $mtime = (int)@filemtime($templatePath);
        $versionedKey = $templatePath . '|' . $mtime;
        $cacheFile = $extendsCacheDir . '/' . md5($versionedKey) . '.cache';
        if (!file_exists($cacheFile)) {
            // Fallback: try the unversioned key (legacy cache files from before versioning)
            $legacyFile = $extendsCacheDir . '/' . md5($templatePath) . '.cache';
            if (file_exists($legacyFile)) {
                @unlink($legacyFile); // clean up legacy entry
            }
            return null;
        }

        $raw = @file_get_contents($cacheFile);
        if ($raw === false) {
            return null;
        }

        $entry = @unserialize($raw);
        if (!is_array($entry) || !isset($entry['content'], $entry['deps']) || !is_array($entry['deps'])) {
            @unlink($cacheFile);
            return null;
        }

        // Validate every dependency mtime
        foreach ($entry['deps'] as $depPath => $depMtime) {
            if (!file_exists($depPath) || filemtime($depPath) !== $depMtime) {
                @unlink($cacheFile);
                return null;
            }
        }

        return $entry['content'];
    }

    /**
     * Store an extends-resolved template in the cross-request cache.
     *
     * Uses atomic write (tmp + rename) to avoid serving partial content.
     *
     * @param array<string,int> $deps  Map of absolute-path → filemtime
     */
    private function setExtendsCache(string $templatePath, string $content, array $deps): void
    {
        if (empty($deps)) {
            return;
        }

        $extendsCacheDir = ($this->getExtendsCacheDir)();

        if (!is_dir($extendsCacheDir)) {
            @mkdir($extendsCacheDir, 0777, true);
        }

        // Versioned key: template path + mtime ensures source changes produce
        // new cache entries. Old entries cleaned by TTL-based GC.
        $mtime = (int)@filemtime($templatePath);
        $versionedKey = $templatePath . '|' . ($mtime > 0 ? $mtime : time());
        $cacheFile = $extendsCacheDir . '/' . md5($versionedKey) . '.cache';
        $tmpFile = $cacheFile . '.' . getmypid() . '.tmp';

        $ok = @file_put_contents($tmpFile, serialize([
            'content' => $content,
            'deps'    => $deps,
        ]));

        if ($ok !== false) {
            @rename($tmpFile, $cacheFile);
        } else {
            @unlink($tmpFile);
        }
    }

    /**
     * Process standalone blocks (strip {block} wrappers).
     */
    public function processBlocks(string $content, array $context): string
    {
        return preg_replace('/\{block\s+(?:"?\w+"?)\}(.*?)\{\/block\}/s', '$1', $content);
    }

    /**
     * Process {debug expr} — pretty-print any variable value for development.
     * Renders as a styled <pre> block with type info and formatted output.
     */
    public function processDebugTags(string $content, array $context): string
    {
        return preg_replace_callback(
            '/\{debug\s+([^}]+)\}/',
            function (array $m) use ($context): string {
                $expr = trim($m[1]);
                $value = ($this->resolveValue)($expr, $context);

                $type = gettype($value);
                if ($value === null) {
                    $dump = 'null';
                } elseif (is_bool($value)) {
                    $dump = $value ? 'true' : 'false';
                } elseif (is_array($value)) {
                    $dump = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } elseif (is_object($value)) {
                    $dump = get_class($value) . "\n" . json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } elseif (is_string($value) && strlen($value) > 500) {
                    $dump = substr($value, 0, 500) . '... (' . strlen($value) . ' chars)';
                } else {
                    $dump = var_export($value, true);
                }

                $safeExpr = htmlspecialchars($expr, ENT_QUOTES, 'UTF-8');
                $safeDump = htmlspecialchars($dump, ENT_QUOTES, 'UTF-8');
                $safeType = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');

                return '<pre class="ikb-debug my-2 p-3 bg-gray-900 text-green-400 text-xs rounded-lg overflow-x-auto font-mono">' . "\n"
                    . '<span class="text-gray-500">debug</span> <span class="text-yellow-300">' . $safeExpr . '</span> <span class="text-gray-500">:: ' . $safeType . '</span>' . "\n"
                    . $safeDump . "\n"
                    . '</pre>';
            },
            $content
        );
    }
}
