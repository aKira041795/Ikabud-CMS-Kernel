<?php
/**
 * Query Grammar - EBNF Specification for {ikb_query} DSL
 * 
 * Defines the formal grammar, parameters, and validation rules
 * Based on the enhanced DSL from the old implementation
 * 
 * @version 1.1.0
 */

namespace IkabudKernel\DSL;

class QueryGrammar
{
    /**
     * EBNF Grammar Specification
     */
    const GRAMMAR = <<<'EBNF'
query           := '{ikb_query' attributes '}'
attributes      := attribute (separator attribute)*
separator       := ',' | whitespace
attribute       := key '=' value
key             := identifier
value           := literal | json_object | quoted_string | placeholder
identifier      := [a-zA-Z_][a-zA-Z0-9_]*
literal         := number | boolean | string
number          := [0-9]+
boolean         := 'true' | 'false' | 'yes' | 'no'
string          := [a-zA-Z0-9_-]+
quoted_string   := '"' [^"]* '"' | "'" [^']* "'"
json_object     := '{' json_content '}'
placeholder     := '{' placeholder_type ':' identifier '}'
placeholder_type := 'GET' | 'POST' | 'ENV' | 'SESSION' | 'COOKIE'
whitespace      := [ \t\n\r]+
EBNF;

    /**
     * Parameter definitions
     */
    private static array $parameters = [
        // Core parameters
        'type' => [
            'required' => true,
            'type' => 'string',
            'default' => 'post',
            'values' => ['post', 'page', 'product', 'category', 'tag', 'user', 'external'],
            'description' => 'Content type to query'
        ],
        'limit' => [
            'required' => false,
            'type' => 'integer',
            'default' => 10,
            'min' => 1,
            'max' => 100,
            'description' => 'Maximum items to retrieve'
        ],
        'offset' => [
            'required' => false,
            'type' => 'integer',
            'default' => 0,
            'min' => 0,
            'description' => 'Number of items to skip'
        ],
        'as' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Variable name for result'
        ],
        
        // Filtering parameters
        'category' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Filter by category slug or ID'
        ],
        'not_category' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Exclude category'
        ],
        'tag' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Filter by tag slug'
        ],
        'not_tag' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Exclude tag'
        ],
        'author' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Filter by author'
        ],
        'status' => [
            'required' => false,
            'type' => 'string',
            'default' => 'publish',
            'values' => ['publish', 'draft', 'pending', 'any'],
            'description' => 'Post status'
        ],
        
        // Sorting parameters
        'orderby' => [
            'required' => false,
            'type' => 'string',
            'default' => 'date',
            'values' => ['date', 'title', 'rand', 'modified', 'author', 'id'],
            'description' => 'Sort field'
        ],
        'order' => [
            'required' => false,
            'type' => 'string',
            'default' => 'desc',
            'values' => ['asc', 'desc'],
            'description' => 'Sort direction'
        ],
        
        // Conditional parameters
        'if' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Conditional execution (e.g., "category=news")'
        ],
        'unless' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Negative conditional'
        ],
        
        // Presentation parameters
        'format' => [
            'required' => false,
            'type' => 'string',
            'default' => 'card',
            'values' => ['card', 'list', 'grid', 'hero', 'minimal', 'full', 'timeline', 'carousel', 'table', 'accordion'],
            'description' => 'Visual style'
        ],
        'layout' => [
            'required' => false,
            'type' => 'string',
            'default' => 'vertical',
            'values' => ['vertical', 'horizontal', 'grid-2', 'grid-3', 'grid-4', 'masonry', 'slider'],
            'description' => 'Structural arrangement'
        ],
        'columns' => [
            'required' => false,
            'type' => 'integer',
            'default' => 3,
            'min' => 1,
            'max' => 6,
            'description' => 'Grid columns'
        ],
        'gap' => [
            'required' => false,
            'type' => 'string',
            'default' => 'medium',
            'values' => ['none', 'small', 'medium', 'large'],
            'description' => 'Spacing between items'
        ],
        
        // Runtime parameters
        'cache' => [
            'required' => false,
            'type' => 'boolean',
            'default' => true,
            'description' => 'Enable caching'
        ],
        'cache_ttl' => [
            'required' => false,
            'type' => 'integer',
            'default' => 3600,
            'min' => 0,
            'description' => 'Cache TTL in seconds'
        ],
        'cache_key' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Custom cache key'
        ],
        'debug' => [
            'required' => false,
            'type' => 'boolean',
            'default' => false,
            'description' => 'Enable debug output'
        ],
        'lazy' => [
            'required' => false,
            'type' => 'boolean',
            'default' => false,
            'description' => 'Lazy load content'
        ],
        
        // CMS-specific
        'cms' => [
            'required' => false,
            'type' => 'string',
            'description' => 'Target CMS (wordpress, joomla, native)'
        ]
    ];
    
    /**
     * Get parameter definition
     */
    public static function getParameter(string $name): ?array
    {
        return self::$parameters[$name] ?? null;
    }
    
    /**
     * Get all parameters
     */
    public static function getAllParameters(): array
    {
        return self::$parameters;
    }
    
    /**
     * Validate parameter value
     */
    public static function validate(string $name, mixed $value): bool
    {
        $param = self::getParameter($name);
        
        if (!$param) {
            return false; // Unknown parameter
        }
        
        // Type validation
        switch ($param['type']) {
            case 'integer':
                if (!is_numeric($value)) {
                    return false;
                }
                $value = (int) $value;
                
                // Range validation
                if (isset($param['min']) && $value < $param['min']) {
                    return false;
                }
                if (isset($param['max']) && $value > $param['max']) {
                    return false;
                }
                break;
                
            case 'boolean':
                if (!in_array($value, [true, false, 'true', 'false', 'yes', 'no', 1, 0, '1', '0'], true)) {
                    return false;
                }
                break;
                
            case 'string':
                if (!is_string($value) && !is_numeric($value)) {
                    return false;
                }
                
                // Enum validation
                if (isset($param['values']) && !in_array($value, $param['values'], true)) {
                    return false;
                }
                break;
        }
        
        return true;
    }
    
    /**
     * Normalize parameter value
     */
    public static function normalize(string $name, mixed $value): mixed
    {
        $param = self::getParameter($name);
        
        if (!$param) {
            return $value;
        }
        
        // Type casting
        switch ($param['type']) {
            case 'integer':
                return (int) $value;
                
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                
            case 'string':
                return (string) $value;
        }
        
        return $value;
    }
    
    /**
     * Get default value for parameter
     */
    public static function getDefault(string $name): mixed
    {
        $param = self::getParameter($name);
        return $param['default'] ?? null;
    }
    
    /**
     * Check if parameter is required
     */
    public static function isRequired(string $name): bool
    {
        $param = self::getParameter($name);
        return $param['required'] ?? false;
    }
    
    /**
     * Get grammar specification
     */
    public static function getGrammar(): string
    {
        return self::GRAMMAR;
    }
    
    /**
     * Get supported placeholder types
     */
    public static function getPlaceholderTypes(): array
    {
        return ['GET', 'POST', 'ENV', 'SESSION', 'COOKIE'];
    }
    
    /**
     * Get supported operators
     */
    public static function getOperators(): array
    {
        return ['AND', 'OR', '=', '!=', '>', '<', '>=', '<='];
    }
}
