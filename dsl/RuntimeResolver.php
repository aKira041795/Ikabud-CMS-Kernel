<?php
/**
 * Runtime Resolver - Resolves dynamic placeholders
 * 
 * Resolves {GET:}, {POST:}, {ENV:}, {SESSION:}, {COOKIE:} placeholders
 * at runtime with proper sanitization
 * 
 * @version 1.1.0
 */

namespace IkabudKernel\DSL;

class RuntimeResolver
{
    private array $context = [];
    
    /**
     * Constructor
     */
    public function __construct(array $context = [])
    {
        $this->context = $context;
    }
    
    /**
     * Resolve placeholders in AST
     */
    public function resolve(array $ast): array
    {
        if (!isset($ast['attributes'])) {
            return $ast;
        }
        
        foreach ($ast['attributes'] as $key => $value) {
            $ast['attributes'][$key] = $this->resolveValue($value);
        }
        
        return $ast;
    }
    
    /**
     * Resolve a single value
     */
    private function resolveValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        
        // Check if it's a placeholder
        if (isset($value['type']) && $value['type'] === 'placeholder') {
            return $this->resolvePlaceholder($value);
        }
        
        // Recursively resolve arrays
        foreach ($value as $k => $v) {
            $value[$k] = $this->resolveValue($v);
        }
        
        return $value;
    }
    
    /**
     * Resolve placeholder
     */
    private function resolvePlaceholder(array $placeholder): mixed
    {
        $source = $placeholder['source'] ?? 'GET';
        $key = $placeholder['key'] ?? '';
        
        $value = null;
        
        switch ($source) {
            case 'GET':
                $value = $this->context['GET'][$key] ?? $_GET[$key] ?? null;
                break;
                
            case 'POST':
                $value = $this->context['POST'][$key] ?? $_POST[$key] ?? null;
                break;
                
            case 'ENV':
                $value = $this->context['ENV'][$key] ?? $_ENV[$key] ?? getenv($key) ?: null;
                break;
                
            case 'SESSION':
                $value = $this->context['SESSION'][$key] ?? $_SESSION[$key] ?? null;
                break;
                
            case 'COOKIE':
                $value = $this->context['COOKIE'][$key] ?? $_COOKIE[$key] ?? null;
                break;
        }
        
        // Sanitize value
        return $this->sanitize($value);
    }
    
    /**
     * Sanitize value
     */
    private function sanitize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        
        if (is_array($value)) {
            return array_map([$this, 'sanitize'], $value);
        }
        
        if (is_string($value)) {
            // Use htmlspecialchars for proper escaping
            // Preserves valid characters while preventing XSS
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        
        if (is_numeric($value)) {
            return $value;
        }
        
        if (is_bool($value)) {
            return $value;
        }
        
        return $value;
    }
    
    /**
     * Set context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }
    
    /**
     * Get context
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
