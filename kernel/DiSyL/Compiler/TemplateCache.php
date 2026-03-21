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
        
        // Check in-memory cache first
        if (isset($this->loaded[$className])) {
            return $this->loaded[$className];
        }
        
        // Check if recompilation needed
        if ($this->needsRecompile($templatePath, $cachePath)) {
            $this->compile($templatePath, $className, $cachePath);
        }
        
        // Load and instantiate
        require_once $cachePath;
        $fullClassName = "Ikabud\Kernel\\Core\\DiSyL\\Compiled\\{$className}";
        
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
        
        require_once $cachePath;
        $fullClassName = "Ikabud\Kernel\\Core\\DiSyL\\Compiled\\{$className}";
        
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
        
        // Recompile if template is newer than cache
        return filemtime($templatePath) > filemtime($cachePath);
    }
    
    /**
     * Compile template and write to cache
     */
    private function compile(string $templatePath, string $className, string $cachePath): void
    {
        $source = file_get_contents($templatePath);
        $ast = $this->parser->parse($source, $templatePath);
        $code = $this->compiler->compile($ast, $className);
        
        $this->writeCache($cachePath, $code);
    }
    
    /**
     * Write compiled code to cache file (atomic)
     */
    private function writeCache(string $cachePath, string $code): void
    {
        $tempPath = $cachePath . '.tmp.' . getmypid();
        
        if (file_put_contents($tempPath, $code, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write template cache: {$cachePath}");
        }
        
        if (!rename($tempPath, $cachePath)) {
            @unlink($tempPath);
            throw new \RuntimeException("Failed to rename template cache: {$cachePath}");
        }
        
        // Invalidate opcache if available
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($cachePath, true);
        }
    }
    
    /**
     * Get cache file path for a class
     */
    private function getCachePath(string $className): string
    {
        return $this->cacheDir . '/' . $className . '.php';
    }
    
    /**
     * Generate class name from template path
     */
    private function getClassName(string $templatePath): string
    {
        $hash = md5($templatePath);
        $name = preg_replace('/[^a-zA-Z0-9]/', '_', basename($templatePath, '.disyl'));
        return 'Template_' . $name . '_' . substr($hash, 0, 8);
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
        ];
    }
}
