<?php
/**
 * DiSyL Engine v0.5.0
 * 
 * Orchestrates the DiSyL compilation and rendering pipeline:
 * Template → Lexer → Parser → Compiler → Renderer → HTML
 * 
 * This is the main entry point for DiSyL template processing.
 * 
 * Supports DiSyL v1.2.0 Grammar:
 * - Filter pipelines with type chain validation
 * - Named and positional filter arguments
 * - Platform compatibility checking
 * - Rich validation with source mapping
 * - Security validation (escaping warnings)
 * - Strict/lenient validation modes
 * 
 * Performance optimizations:
 * - APCu caching for compiled AST (fastest)
 * - In-memory LRU cache fallback
 * - File-based cache as last resort
 * - Grammar expression caching
 * 
 * @version 0.5.0
 */

namespace IkabudKernel\Core\DiSyL;

use IkabudKernel\Core\DiSyL\Lexer;
use IkabudKernel\Core\DiSyL\Parser;
use IkabudKernel\Core\DiSyL\Compiler;
use IkabudKernel\Core\DiSyL\Renderers\BaseRenderer;

class Engine
{
    private Lexer $lexer;
    private Parser $parser;
    private Compiler $compiler;
    private $cache; // Mixed type for compatibility
    private ?string $defaultCMSType = null; // Default CMS type if no header present
    private bool $strictMode = true;
    
    /** @var array In-memory LRU cache for compiled ASTs */
    private static array $memoryCache = [];
    
    /** @var int Maximum entries in memory cache */
    private const MAX_MEMORY_CACHE = 100;
    
    /** @var int APCu cache TTL in seconds (1 hour) */
    private const APCU_TTL = 3600;
    
    /** @var bool Whether APCu is available */
    private static ?bool $apcuAvailable = null;
    
    /**
     * Constructor
     * 
     * @param mixed $cache Optional cache instance
     * @param string|null $defaultCMSType Default CMS type for templates without header
     * @param bool $strictMode Whether to use strict validation mode
     */
    public function __construct($cache = null, ?string $defaultCMSType = null, bool $strictMode = true)
    {
        $this->lexer = new Lexer();
        $this->parser = new Parser();
        $this->compiler = new Compiler($cache);
        $this->cache = $cache;
        $this->defaultCMSType = $defaultCMSType;
        $this->strictMode = $strictMode;
        
        // Set compiler strict mode
        $this->compiler->setStrictMode($strictMode);
        
        // Check APCu availability once
        if (self::$apcuAvailable === null) {
            self::$apcuAvailable = function_exists('apcu_fetch') && apcu_enabled();
        }
    }
    
    /**
     * Set validation mode
     * 
     * @param bool $strict True for strict mode (errors block), false for lenient (warnings only)
     * @return self
     */
    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;
        $this->compiler->setStrictMode($strict);
        return $this;
    }
    
    /**
     * Get validation result from last compilation
     * 
     * @return ValidationResult|null
     */
    public function getValidationResult(): ?ValidationResult
    {
        return $this->compiler->getValidationResult();
    }
    
    /**
     * Check if last compilation had errors
     * 
     * @return bool
     */
    public function hasErrors(): bool
    {
        return $this->compiler->hasErrors();
    }
    
    /**
     * Check if last compilation had warnings
     * 
     * @return bool
     */
    public function hasWarnings(): bool
    {
        return $this->compiler->hasWarnings();
    }
    
    /**
     * Get errors from last compilation
     * 
     * @return array
     */
    public function getErrors(): array
    {
        return $this->compiler->getErrors();
    }
    
    /**
     * Get warnings from last compilation
     * 
     * @return array
     */
    public function getWarnings(): array
    {
        return $this->compiler->getWarnings();
    }
    
    /**
     * Compile a DiSyL template to AST
     * 
     * Uses multi-tier caching strategy:
     * 1. In-memory cache (fastest, per-request)
     * 2. APCu cache (fast, shared across requests)
     * 3. File cache (slower, persistent)
     * 
     * @param string $template Template content
     * @param array $context Optional compilation context
     * @return array Compiled AST
     */
    public function compile(string $template, array $context = []): array
    {
        $cacheKey = $this->generateCacheKey($template, $context);
        
        // Tier 1: Check in-memory cache (fastest)
        if (isset(self::$memoryCache[$cacheKey])) {
            return self::$memoryCache[$cacheKey];
        }
        
        // Tier 2: Check APCu cache (fast, shared)
        if (self::$apcuAvailable) {
            $cached = apcu_fetch($cacheKey, $success);
            if ($success && is_array($cached)) {
                // Promote to memory cache
                $this->addToMemoryCache($cacheKey, $cached);
                return $cached;
            }
        }
        
        // Tier 3: Check file cache (slower, persistent)
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null && is_array($cached)) {
                // Promote to faster caches
                $this->addToMemoryCache($cacheKey, $cached);
                if (self::$apcuAvailable) {
                    apcu_store($cacheKey, $cached, self::APCU_TTL);
                }
                return $cached;
            }
        }
        
        // Cache miss - compile the template
        $compiled = $this->doCompile($template, $context);
        
        // Store in all cache tiers
        $this->addToMemoryCache($cacheKey, $compiled);
        
        if (self::$apcuAvailable) {
            apcu_store($cacheKey, $compiled, self::APCU_TTL);
        }
        
        if ($this->cache !== null) {
            $this->cache->set($cacheKey, $compiled, self::APCU_TTL);
        }
        
        return $compiled;
    }
    
    /**
     * Perform actual template compilation
     * 
     * @param string $template Template content
     * @param array $context Compilation context
     * @return array Compiled AST
     */
    private function doCompile(string $template, array $context): array
    {
        // Lexical analysis
        $tokens = $this->lexer->tokenize($template);
        
        // Syntax analysis
        $ast = $this->parser->parse($tokens);
        
        // Add default CMS type to context if no header present
        if (!isset($ast['cms_header']) && $this->defaultCMSType !== null) {
            $context['cms_type'] = $context['cms_type'] ?? $this->defaultCMSType;
        }
        
        // Semantic analysis and optimization
        return $this->compiler->compile($ast, $context);
    }
    
    /**
     * Generate cache key for template
     * 
     * @param string $template Template content
     * @param array $context Compilation context
     * @return string Cache key
     */
    private function generateCacheKey(string $template, array $context): string
    {
        // Use xxhash if available (faster), fallback to md5
        $data = $template . ($context ? json_encode($context) : '');
        return 'disyl_ast_' . md5($data);
    }
    
    /**
     * Add compiled AST to memory cache with LRU eviction
     * 
     * @param string $key Cache key
     * @param array $value Compiled AST
     */
    private function addToMemoryCache(string $key, array $value): void
    {
        // LRU eviction - remove oldest entry if at capacity
        if (count(self::$memoryCache) >= self::MAX_MEMORY_CACHE) {
            array_shift(self::$memoryCache);
        }
        
        self::$memoryCache[$key] = $value;
    }
    
    /**
     * Clear all caches (useful for development)
     * 
     * @param bool $includeApcu Whether to clear APCu cache
     */
    public static function clearCache(bool $includeApcu = true): void
    {
        self::$memoryCache = [];
        
        // Clear Grammar and Compiler caches
        Grammar::clearCache();
        Compiler::clearCache();
        
        if ($includeApcu && self::$apcuAvailable) {
            // Clear only DiSyL keys
            $iterator = new \APCUIterator('/^disyl_ast_/', APC_ITER_KEY);
            foreach ($iterator as $item) {
                apcu_delete($item['key']);
            }
        }
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public static function getCacheStats(): array
    {
        $stats = [
            'memory_cache_size' => count(self::$memoryCache),
            'memory_cache_max' => self::MAX_MEMORY_CACHE,
            'apcu_available' => self::$apcuAvailable ?? false,
        ];
        
        if (self::$apcuAvailable) {
            $apcuInfo = apcu_cache_info(true);
            $stats['apcu_entries'] = $apcuInfo['num_entries'] ?? 0;
            $stats['apcu_memory_size'] = $apcuInfo['mem_size'] ?? 0;
        }
        
        return $stats;
    }
    
    /**
     * Render a compiled AST to HTML
     * 
     * @param array $ast Compiled AST
     * @param BaseRenderer $renderer Renderer instance
     * @param array $context Rendering context
     * @return string Rendered HTML
     */
    public function render(array $ast, BaseRenderer $renderer, array $context = []): string
    {
        return $renderer->render($ast, $context);
    }
    
    /**
     * Compile and render a template in one step
     * 
     * @param string $template Template content
     * @param BaseRenderer $renderer Renderer instance
     * @param array $context Rendering context
     * @param array $compileContext Optional compilation context
     * @return string Rendered HTML
     */
    public function compileAndRender(string $template, BaseRenderer $renderer, array $context = [], array $compileContext = []): string
    {
        $ast = $this->compile($template, $compileContext);
        return $this->render($ast, $renderer, $context);
    }
    
    /**
     * Load and compile a template file
     * 
     * @param string $templatePath Path to template file
     * @param array $context Optional compilation context
     * @return array Compiled AST
     * @throws \Exception If template file not found
     */
    public function compileFile(string $templatePath, array $context = []): array
    {
        if (!file_exists($templatePath)) {
            throw new \Exception("Template file not found: {$templatePath}");
        }
        
        $template = file_get_contents($templatePath);
        return $this->compile($template, $context);
    }
    
    /**
     * Load, compile, and render a template file
     * 
     * @param string $templatePath Path to template file
     * @param BaseRenderer $renderer Renderer instance
     * @param array $context Rendering context
     * @param array $compileContext Optional compilation context
     * @return string Rendered HTML
     */
    public function renderFile(string $templatePath, BaseRenderer $renderer, array $context = [], array $compileContext = []): string
    {
        $ast = $this->compileFile($templatePath, $compileContext);
        return $this->render($ast, $renderer, $context);
    }
    
    /**
     * Set default CMS type
     * 
     * @param string|null $cmsType CMS type (wordpress, drupal, joomla, generic)
     */
    public function setDefaultCMSType(?string $cmsType): void
    {
        $this->defaultCMSType = $cmsType;
    }
    
    /**
     * Get default CMS type
     * 
     * @return string|null Current default CMS type
     */
    public function getDefaultCMSType(): ?string
    {
        return $this->defaultCMSType;
    }
}
