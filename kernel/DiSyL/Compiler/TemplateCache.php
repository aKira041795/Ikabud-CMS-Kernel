<?php
/**
 * DiSyL v4.0 Template Cache
 * 
 * Manages compiled template caching for optimal performance.
 * 
 * @package Ikabud\Kernel\DiSyL\Compiler
 * @version 4.0.0
 */

namespace Ikabud\Kernel\DiSyL\Compiler;

use Ikabud\Kernel\DiSyL\v4\Parser;
use Ikabud\Kernel\DiSyL\v4\AST\DocumentNode;

/**
 * Template compilation and caching manager
 */
class TemplateCache
{
    private string $cacheDir;
    private Parser $parser;
    private TemplateCompiler $compiler;
    private bool $debug = false;
    
    /** @var array<string, CompiledTemplate> In-memory cache */
    private array $loaded = [];

    /** @var array<string, string> Component namespace → directory path */
    private array $componentDirs = [];
    
    public function __construct(string $cacheDir, bool $debug = false)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->debug = $debug;
        $this->parser = new Parser();
        $this->compiler = new TemplateCompiler();
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Get compiled template (from cache or compile fresh)
     */
    public function get(string $templatePath): CompiledTemplate
    {
        $className = $this->getClassName($templatePath);
        $cachePath = $this->getCachePath($className);
        $freshCompiledCode = null;
        
        // Developer escape hatch: ?disyl_nocache=1 forces full recompilation
        $forceRecompile = ($_GET['disyl_nocache'] ?? '') === '1';

        // Check in-memory cache first
        if (isset($this->loaded[$className]) && !$forceRecompile) {
            return $this->loaded[$className];
        }

        // Check if recompilation needed
        if ($forceRecompile || $this->needsRecompile($templatePath, $cachePath)) {
            if ($forceRecompile) {
                \write_log("Disyl cache: forced recompile via ?disyl_nocache=1 for '{$templatePath}'", 'info');
            }
            $freshCompiledCode = $this->compile($templatePath, $className, $cachePath);
        }
        
        // Load and instantiate
        if (file_exists($cachePath) && $freshCompiledCode === null) {
            require_once $cachePath;
        } else {
            if ($freshCompiledCode === null) {
                $freshCompiledCode = $this->compile($templatePath, $className, null);
            }
            // SECURITY NOTE: eval() is safe here because compiled code
            // is generated from template source by TemplateCompiler,
            // not from user input.
            eval("?>" . $freshCompiledCode);
        }
        $fullClassName = "Ikabud\Kernel\DiSyL\Compiled\\{$className}";
        
        $template = new $fullClassName();
        $this->loaded[$className] = $template;
        
        return $template;
    }
    
    /**
     * Compile a template from source
     */
    public function compileSource(string $source, string $name = 'Anonymous'): CompiledTemplate
    {
        $className = 'Template_' . md5($source);
        $cachePath = $this->getCachePath($className);
        
        if (isset($this->loaded[$className])) {
            return $this->loaded[$className];
        }
        
        if (!file_exists($cachePath)) {
            $ast = $this->parser->parse($source, $name);
            $code = $this->compiler->compile($ast, $className);
            
            $this->writeCache($cachePath, $code);
        }
        
        // Validate cache file integrity before executing
        if (file_exists($cachePath) && !$this->validateCacheFile($cachePath)) {
            // Regenerate from source
            $ast = $this->parser->parse($source, $name);
            $code = $this->compiler->compile($ast, $className);
            $this->writeCache($cachePath, $code);
            if (file_exists($cachePath) && !$this->validateCacheFile($cachePath)) {
                throw new \RuntimeException("Template cache validation failed for compiled source: {$name}");
            }
        }
        
        if (file_exists($cachePath)) {
            require_once $cachePath;
        } else {
            $ast = $this->parser->parse($source, $name);
            $code = $this->compiler->compile($ast, $className);
            // SECURITY NOTE: eval() is safe here because compiled code
            // is generated from template source by TemplateCompiler,
            // not from user input.
            eval("?>" . $code);
        }
        $fullClassName = "Ikabud\Kernel\DiSyL\Compiled\\{$className}";
        
        $template = new $fullClassName();
        $this->loaded[$className] = $template;
        
        return $template;
    }
    
    /**
     * Check if template needs recompilation
     */
    private function needsRecompile(string $templatePath, string $cachePath): bool
    {
        // Always recompile in debug mode
        if ($this->debug) {
            return true;
        }
        
        // Compile if cache doesn't exist
        if (!file_exists($cachePath)) {
            return true;
        }

        // Guard against non-existent template path
        if (!file_exists($templatePath)) {
            return true;
        }
        
        // Recompile if template is newer than cache
        if (filemtime($templatePath) > filemtime($cachePath)) {
            return true;
        }

        // Check {extends} parent templates: if any ancestor layout is newer
        // than the cache, recompile. This ensures layout changes propagate
        // to child templates without manual cache clearing.
        $source = @file_get_contents($templatePath);
        if ($source !== false) {
            // {extends} chain
            if (preg_match('/\{extends\s+"([^"]+)"\s*\}/', $source, $m)) {
                $parentPath = $this->resolveExtendsPath($templatePath, $m[1]);
                if ($parentPath !== null && file_exists($parentPath)) {
                    if (filemtime($parentPath) > filemtime($cachePath)) {
                        return true;
                    }
                    // Also check if the parent itself extends further (recursive scan).
                    // Limit depth to avoid infinite loops on circular extends.
                    $depth = 0;
                    $currentPath = $parentPath;
                    while ($depth < 10) {
                        $depth++;
                        $parentSource = @file_get_contents($currentPath);
                        if ($parentSource === false || !preg_match('/\{extends\s+"([^"]+)"\s*\}/', $parentSource, $pm)) {
                            break;
                        }
                        $grandparentPath = $this->resolveExtendsPath($currentPath, $pm[1]);
                        if ($grandparentPath === null || !file_exists($grandparentPath)) {
                            break;
                        }
                        if (filemtime($grandparentPath) > filemtime($cachePath)) {
                            return true;
                        }
                        $currentPath = $grandparentPath;
                    }
                }
            }

            // {include ...} directives — if any included template is newer than
            // the cache, recompile. This ensures edits to partials/page templates
            // propagate without manual cache clearing.
            // Matches: {include "path"} and {include "ns:path"}
            if (preg_match_all('/\{include\s+"([^"]+)"[^}]*\}/', $source, $includes)) {
                $dir = dirname($templatePath);
                foreach ($includes[1] as $incTarget) {
                    $incPath = $this->resolveIncludePath($dir, $incTarget);
                    if ($incPath !== null && file_exists($incPath)) {
                        if (filemtime($incPath) > filemtime($cachePath)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Resolve an {extends} path relative to the child template's directory.
     * Handles _cms_active_theme/ prefix and module: aliases.
     */
    private function resolveExtendsPath(string $childPath, string $extendsTarget): ?string
    {
        if (str_starts_with($extendsTarget, '/')) {
            return $extendsTarget;
        }
        if (str_starts_with($extendsTarget, '_cms_active_theme/') && function_exists('cmsResolveThemeTemplateAliasPath')) {
            $resolved = cmsResolveThemeTemplateAliasPath($extendsTarget);
            if ($resolved !== '') {
                return $resolved;
            }
        }
        // Resolve relative to the child template's directory
        $dir = dirname($childPath);
        $candidate = $dir . '/' . $extendsTarget;
        if (file_exists($candidate)) {
            return realpath($candidate) ?: $candidate;
        }
        return null;
    }

    /**
     * Resolve an {include} path relative to the parent template's directory.
     * Handles the same path forms as resolveExtendsPath plus namespaced
     * component references (e.g. "workbench:app_shell").
     *
     * Component namespace dirs can be registered via addComponentDirectory().
     */
    private function resolveIncludePath(string $parentDir, string $includeTarget): ?string
    {
        if (str_starts_with($includeTarget, '/')) {
            $candidate = $includeTarget;
            if (file_exists($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
            return null;
        }
        if (str_starts_with($includeTarget, '_cms_active_theme/') && function_exists('cmsResolveThemeTemplateAliasPath')) {
            $resolved = cmsResolveThemeTemplateAliasPath($includeTarget);
            if ($resolved !== '' && file_exists($resolved)) {
                return realpath($resolved) ?: $resolved;
            }
            return null;
        }
        // Namespaced component: "workbench:app_shell" → component dir + name.disyl
        if (preg_match('/^([a-z][a-z0-9_-]*):(.+)$/', $includeTarget, $nsMatch)) {
            $namespace = $nsMatch[1];
            $name = $nsMatch[2];
            if (isset($this->componentDirs[$namespace])) {
                $candidate = $this->componentDirs[$namespace] . '/' . $name;
                if (pathinfo($candidate, PATHINFO_EXTENSION) !== 'disyl') {
                    $candidate .= '.disyl';
                }
                if (file_exists($candidate)) {
                    return realpath($candidate) ?: $candidate;
                }
            }
            return null;
        }
        // Module alias: "modules/cms/..." — resolve relative to BASE_PATH
        if (str_starts_with($includeTarget, 'modules/') && defined('BASE_PATH')) {
            $candidate = BASE_PATH . '/' . $includeTarget;
            if (pathinfo($candidate, PATHINFO_EXTENSION) !== 'disyl') {
                $candidate .= '.disyl';
            }
            if (file_exists($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
            return null;
        }
        // Relative path (resolve relative to parent template's directory)
        $candidate = $parentDir . '/' . $includeTarget;
        if (pathinfo($candidate, PATHINFO_EXTENSION) !== 'disyl') {
            $candidate .= '.disyl';
        }
        if (file_exists($candidate)) {
            return realpath($candidate) ?: $candidate;
        }
        return null;
    }
    
    /**
     * Compile template and write to cache
     */
    private function compile(string $templatePath, string $className, ?string $cachePath): string
    {
        if (!file_exists($templatePath) || !is_readable($templatePath)) {
            throw new \RuntimeException("Template not found or not readable: {$templatePath}");
        }
        $source = file_get_contents($templatePath);

        // Strip {@var type $name} declarations — they're compile-time metadata,
        // not output. Handled here (compiled path) and in TemplateEngine::compile()
        // step 0a (interpreted path) to ensure consistency across both rendering modes.
        if (str_contains($source, '{@var ')) {
            $source = preg_replace('/\{@var\s+(\??\w+(?:<[^>]+>)?)\s+\$([a-zA-Z_]\w*)\s*\}/', '', $source);
        }

        $ast = $this->parser->parse($source, $templatePath);
        $code = $this->compiler->compile($ast, $className);

        if ($cachePath !== null) {
            $this->writeCache($cachePath, $code);
        }

        return $code;
    }
    
    /**
     * Write compiled code to cache file (atomic).
     * Prepends a sentinel comment so we can validate the file was generated by
     * this compiler before executing it (defense against cache-dir tampering).
     */
    private function writeCache(string $cachePath, string $code): bool
    {
        $normalizedCode = $this->normalizeCompiledCode($code);
        $sentinel = $this->buildSentinel($normalizedCode);
        $codeBody = $this->stripOpenTag($normalizedCode);
        $codeWithSentinel = "<?php // DISYL_CACHE_SENTINEL:{$sentinel}\n" . $codeBody;
        $tempPath = $cachePath . '.tmp.' . getmypid();
        
        if (@file_put_contents($tempPath, $codeWithSentinel, LOCK_EX) === false) {
            return false;
        }
        
        if (!rename($tempPath, $cachePath)) {
            @unlink($tempPath);
            return false;
        }
        
        // Invalidate opcache if available
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($cachePath, true);
        }

        return true;
    }

    /**
     * Build an HMAC-like sentinel for the compiled code.
     * Uses a per-process secret derived from the cache directory path so that
     * even without a shared secret, files cannot trivially be copied from
     * another installation or tampered with without invalidating the check.
     */
    private function buildSentinel(string $code): string
    {
        // Use a real application secret when available so that knowing the
        // cache directory path is not sufficient to forge a sentinel.
        $appSecret = $_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '';
        if ($appSecret === '') {
            // Fallback: derive from cache dir (legacy behavior, weaker).
            $appSecret = 'DISYL_CACHE_' . sha1($this->cacheDir);
        }
        return hash_hmac('sha256', $code, $appSecret);
    }

    /**
     * Normalize compiled PHP so sentinel generation and validation operate on a
     * stable representation with a single PHP open tag prefix.
     */
    private function normalizeCompiledCode(string $code): string
    {
        return "<?php\n" . $this->stripOpenTag($code);
    }

    /**
     * Remove a leading PHP open tag from compiled code.
     */
    private function stripOpenTag(string $code): string
    {
        if (str_starts_with($code, "<?php\r\n")) {
            return substr($code, 7);
        }

        if (str_starts_with($code, "<?php\n")) {
            return substr($code, 6);
        }

        if (str_starts_with($code, '<?php')) {
            return ltrim(substr($code, 5), "\r\n");
        }

        return $code;
    }

    /**
     * Validate a cache file's sentinel before executing it.
     * Returns true if the file looks safe to require.
     */
    private function validateCacheFile(string $cachePath): bool
    {
        $firstLine = '';
        $handle = @fopen($cachePath, 'r');
        if ($handle === false) {
            return false;
        }
        $firstLine = fgets($handle) ?: '';
        $rest = stream_get_contents($handle);
        fclose($handle);

        if (!preg_match('/^<\?php \/\/ DISYL_CACHE_SENTINEL:([a-f0-9]{64})/', $firstLine, $m)) {
            return false;
        }

        $storedSentinel = $m[1];
        $originalCode = $this->normalizeCompiledCode("<?php\n" . $rest);
        $expected = $this->buildSentinel($originalCode);

        return hash_equals($expected, $storedSentinel);
    }
    
    /**
     * Get cache file path for a class
     */
    private function getCachePath(string $className): string
    {
        return $this->cacheDir . '/' . $className . '.php';
    }
    
    /**
     * Generate class name from template path.
     * Includes compiler version so stale compiled files are bypassed on upgrade.
     */
    private function getClassName(string $templatePath): string
    {
        $version = TemplateCompiler::COMPILER_VERSION;
        $hash = md5($templatePath . ':v' . $version);
        $name = preg_replace('/[^a-zA-Z0-9]/', '_', basename($templatePath, '.disyl'));
        return 'Template_' . $name . '_v' . $version . '_' . substr($hash, 0, 8);
    }
    
    /**
     * Clear all cached templates
     */
    public function clear(): int
    {
        $count = 0;
        $files = glob($this->cacheDir . '/Template_*.php');
        
        foreach ($files as $file) {
            if (unlink($file)) {
                $count++;
            }
        }
        
        $this->loaded = [];
        
        return $count;
    }
    
    /**
     * Clear cache for specific template
     */
    public function clearTemplate(string $templatePath): bool
    {
        $className = $this->getClassName($templatePath);
        $cachePath = $this->getCachePath($className);
        
        unset($this->loaded[$className]);
        
        if (file_exists($cachePath)) {
            return unlink($cachePath);
        }
        
        return true;
    }
    
    /**
     * Remove stale compiled files that don't match the current compiler version
     * or are older than the given TTL (seconds). Returns count of removed files.
     */
    public function cleanup(int $ttlSeconds = 0): int
    {
        $files = glob($this->cacheDir . '/Template_*.php');
        if (!$files) {
            return 0;
        }

        $currentVersionTag = '_v' . TemplateCompiler::COMPILER_VERSION . '_';
        $removed = 0;
        $now = time();

        foreach ($files as $file) {
            $basename = basename($file);
            $stale = false;

            // Remove files from a different compiler version
            if (strpos($basename, $currentVersionTag) === false) {
                $stale = true;
            }

            // Remove files older than TTL (if TTL > 0)
            if (!$stale && $ttlSeconds > 0) {
                $age = $now - filemtime($file);
                if ($age > $ttlSeconds) {
                    $stale = true;
                }
            }

            if ($stale && @unlink($file)) {
                $removed++;
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file, true);
                }
            }
        }

        return $removed;
    }

    /**
     * Register a component directory for namespace resolution in include paths.
     * Enables {include "workbench:app_shell"} mtime tracking in needsRecompile().
     */
    public function addComponentDirectory(string $namespace, string $dir): void
    {
        $this->componentDirs[$namespace] = rtrim($dir, '/');
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $files = glob($this->cacheDir . '/Template_*.php');
        $totalSize = 0;
        
        foreach ($files as $file) {
            $totalSize += filesize($file);
        }
        
        return [
            'cache_dir' => $this->cacheDir,
            'cached_templates' => count($files),
            'total_size' => $totalSize,
            'loaded_in_memory' => count($this->loaded),
            'debug_mode' => $this->debug,
            'compiler_version' => TemplateCompiler::COMPILER_VERSION,
        ];
    }
}
