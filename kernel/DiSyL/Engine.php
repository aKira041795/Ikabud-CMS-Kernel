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
    
    /**
     * Constructor
     * 
     * @param mixed $cache Optional cache instance
     */
    public function __construct($cache = null)
    {
        $this->lexer = new Lexer();
        $this->parser = new Parser();
        $this->compiler = new Compiler($cache);
        $this->cache = $cache;
    }
    
    /**
     * Compile a DiSyL template to AST
     * 
     * @param string $template Template content
     * @return array Compiled AST
     */
    public function compile(string $template): array
    {
        // Check cache first
        if ($this->cache !== null) {
            $cacheKey = 'disyl_ast_' . md5($template);
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        // Lexical analysis
        $tokens = $this->lexer->tokenize($template);
        
        // Syntax analysis
        $ast = $this->parser->parse($tokens);
        
        // Semantic analysis and optimization
        $compiled = $this->compiler->compile($ast);
        
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
     * @return string Rendered HTML
     */
    public function compileAndRender(string $template, BaseRenderer $renderer, array $context = []): string
    {
        $ast = $this->compile($template);
        return $this->render($ast, $renderer, $context);
    }
    
    /**
     * Load and compile a template file
     * 
     * @param string $templatePath Path to template file
     * @return array Compiled AST
     * @throws \Exception If template file not found
     */
    public function compileFile(string $templatePath): array
    {
        if (!file_exists($templatePath)) {
            throw new \Exception("Template file not found: {$templatePath}");
        }
        
        $template = file_get_contents($templatePath);
        return $this->compile($template);
    }
    
    /**
     * Load, compile, and render a template file
     * 
     * @param string $templatePath Path to template file
     * @param BaseRenderer $renderer Renderer instance
     * @param array $context Rendering context
     * @return string Rendered HTML
     */
    public function renderFile(string $templatePath, BaseRenderer $renderer, array $context = []): string
    {
        $ast = $this->compileFile($templatePath);
        return $this->render($ast, $renderer, $context);
    }
}
