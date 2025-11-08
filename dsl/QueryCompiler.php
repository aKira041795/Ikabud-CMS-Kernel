<?php
/**
 * Query Compiler - Full compilation pipeline
 * 
 * Lexer → Parser → Resolver → Validator → Optimizer
 * Generates AST with caching support
 * 
 * @version 1.1.0
 */

namespace IkabudKernel\DSL;

class QueryCompiler
{
    private QueryLexer $lexer;
    private QueryParser $parser;
    private RuntimeResolver $resolver;
    private array $context = [];
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->lexer = new QueryLexer();
        $this->parser = new QueryParser();
        $this->resolver = new RuntimeResolver();
    }
    
    /**
     * Compile query string to AST
     */
    public function compile(string $query, array $context = []): array
    {
        $startTime = microtime(true);
        
        $this->context = $context;
        $this->resolver->setContext($context);
        
        // 1. Lexical analysis
        $tokens = $this->lexer->tokenize($query);
        
        // 2. Syntax analysis
        $ast = $this->parser->parse($tokens);
        
        // 3. Runtime resolution
        $ast = $this->resolver->resolve($ast);
        
        // 4. Validation
        $ast = $this->validate($ast);
        
        // 5. Apply defaults
        $ast = $this->applyDefaults($ast);
        
        // 6. Optimization
        $ast = $this->optimize($ast);
        
        // Add metadata
        $ast['metadata'] = [
            'compilation_time_ms' => (microtime(true) - $startTime) * 1000,
            'cache_key' => $this->generateCacheKey($query, $context)
        ];
        
        return $ast;
    }
    
    /**
     * Compile with debug info
     */
    public function compileWithDebug(string $query, array $context = []): array
    {
        $tokens = $this->lexer->tokenize($query);
        $astBeforeResolution = $this->parser->parse($tokens);
        
        $ast = $this->compile($query, $context);
        
        return [
            'ast' => $ast,
            'stats' => [
                'tokens' => $tokens,
                'ast_before_resolution' => $astBeforeResolution,
                'compilation_time_ms' => $ast['metadata']['compilation_time_ms']
            ],
            'grammar' => QueryGrammar::getAllParameters()
        ];
    }
    
    /**
     * Validate AST
     */
    private function validate(array $ast): array
    {
        $errors = $ast['errors'] ?? [];
        
        foreach ($ast['attributes'] as $key => $value) {
            if (!QueryGrammar::validate($key, $value)) {
                $errors[] = "Invalid value for parameter '{$key}'";
            }
        }
        
        // Check required parameters
        foreach (QueryGrammar::getAllParameters() as $name => $param) {
            if ($param['required'] && !isset($ast['attributes'][$name])) {
                $errors[] = "Required parameter '{$name}' is missing";
            }
        }
        
        $ast['errors'] = $errors;
        
        return $ast;
    }
    
    /**
     * Apply default values
     */
    private function applyDefaults(array $ast): array
    {
        foreach (QueryGrammar::getAllParameters() as $name => $param) {
            if (!isset($ast['attributes'][$name]) && isset($param['default'])) {
                $ast['attributes'][$name] = $param['default'];
            }
        }
        
        return $ast;
    }
    
    /**
     * Optimize AST
     */
    private function optimize(array $ast): array
    {
        // Normalize values
        foreach ($ast['attributes'] as $key => $value) {
            $ast['attributes'][$key] = QueryGrammar::normalize($key, $value);
        }
        
        return $ast;
    }
    
    /**
     * Generate cache key
     */
    private function generateCacheKey(string $query, array $context): string
    {
        $data = $query . serialize($context);
        return 'ikb_query_' . md5($data);
    }
    
    /**
     * Set context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
        $this->resolver->setContext($context);
    }
}
