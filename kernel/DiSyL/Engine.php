<?php
/**
 * DiSyL Engine
 * 
 * Orchestrates the DiSyL compilation and rendering pipeline:
 * Template → Lexer → Parser → Compiler → Renderer → HTML
 * 
 * This is the main entry point for DiSyL template processing.
 * 
 * Supports DiSyL v0.2 grammar:
 * - Filter pipelines with multiple arguments
 * - Named and positional filter arguments
 * - Enhanced expression evaluation
 * - Unicode support
 * 
 * @version 0.3.0
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
    
    /**
     * Constructor
     * 
     * @param mixed $cache Optional cache instance
     * @param string|null $defaultCMSType Default CMS type for templates without header
     */
    public function __construct($cache = null, ?string $defaultCMSType = null)
    {
        $this->lexer = new Lexer();
        $this->parser = new Parser();
        $this->compiler = new Compiler($cache);
        $this->cache = $cache;
        $this->defaultCMSType = $defaultCMSType;
    }
    
    /**
     * Compile a DiSyL template to AST
     * 
     * @param string $template Template content
     * @param array $context Optional compilation context
     * @return array Compiled AST
     */
    public function compile(string $template, array $context = []): array
    {
        // Check cache first
        if ($this->cache !== null) {
            $cacheKey = 'disyl_ast_' . md5($template . json_encode($context));
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        // Lexical analysis
        $tokens = $this->lexer->tokenize($template);
        
        // Syntax analysis
        $ast = $this->parser->parse($tokens);
        
        // Add default CMS type to context if no header present
        if (!isset($ast['cms_header']) && $this->defaultCMSType !== null) {
            $context['cms_type'] = $context['cms_type'] ?? $this->defaultCMSType;
        }
        
        // Semantic analysis and optimization
        $compiled = $this->compiler->compile($ast, $context);
        
        // Cache result
        if ($this->cache !== null) {
            $this->cache->set($cacheKey, $compiled, 3600); // 1 hour
        }
        
        return $compiled;
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
