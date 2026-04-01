<?php
/**
 * DiSyL Grammar v2.0.0
 * 
 * Defines type constants, platform identifiers, and validation rules
 * for the DiSyL template language.
 * 
 * v2.0.0 (DiSyL v11):
 * - Advanced type system constants (generics, union, intersection)
 * - Pattern matching keywords
 * - Async/await keywords
 * - i18n keywords
 * - Plugin hooks
 * 
 * @package Ikabud\Kernel\DiSyL
 * @version 2.0.0
 */

namespace Ikabud\Kernel\DiSyL;

class Grammar
{
    // ========== Schema Version ==========
    public const SCHEMA_VERSION = '4.0.0';
    
    // ========== Type Constants ==========
    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_NUMBER = 'number';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_ARRAY = 'array';
    public const TYPE_OBJECT = 'object';
    public const TYPE_MIXED = 'mixed';
    public const TYPE_NULL = 'null';
    public const TYPE_CALLABLE = 'callable';
    public const TYPE_EXPRESSION = 'expression';
    
    // v11: Advanced Type Constants
    public const TYPE_NEVER = 'never';
    public const TYPE_UNKNOWN = 'unknown';
    public const TYPE_VOID = 'void';
    public const TYPE_ANY = 'any';
    public const TYPE_UNION = 'union';
    public const TYPE_INTERSECTION = 'intersection';
    public const TYPE_GENERIC = 'generic';
    public const TYPE_TUPLE = 'tuple';
    public const TYPE_LITERAL = 'literal';
    public const TYPE_TEMPLATE_LITERAL = 'template_literal';
    public const TYPE_CONDITIONAL = 'conditional';
    public const TYPE_MAPPED = 'mapped';
    public const TYPE_INFER = 'infer';
    
    // ========== Platform Constants ==========
    public const PLATFORM_UNIVERSAL = 'universal';
    public const PLATFORM_WORDPRESS = 'wordpress';
    public const PLATFORM_DRUPAL = 'drupal';
    public const PLATFORM_JOOMLA = 'joomla';
    public const PLATFORM_NATIVE = 'native';
    public const PLATFORM_IKABUD = 'ikabud';
    public const PLATFORM_STATIC = 'static';
    
    // ========== Component Categories ==========
    public const COMPONENT_CATEGORIES = [
        'structural',
        'data',
        'ui',
        'control',
        'media',
        'layout',
        'content',
        'interactive',
        'navigation',
        'form',
    ];
    
    // ========== Filter Categories ==========
    public const FILTER_CATEGORY_STRING = 'string';
    public const FILTER_CATEGORY_NUMBER = 'number';
    public const FILTER_CATEGORY_ARRAY = 'array';
    public const FILTER_CATEGORY_DATE = 'date';
    public const FILTER_CATEGORY_ESCAPE = 'escape';
    public const FILTER_CATEGORY_FORMAT = 'format';
    
    // ========== Validation ==========
    
    /**
     * Validate a value against a type
     */
    public static function validateType(mixed $value, string $type): bool
    {
        return match ($type) {
            self::TYPE_STRING => is_string($value),
            self::TYPE_INTEGER => is_int($value),
            self::TYPE_NUMBER => is_numeric($value),
            self::TYPE_BOOLEAN => is_bool($value),
            self::TYPE_ARRAY => is_array($value),
            self::TYPE_OBJECT => is_object($value) || is_array($value),
            self::TYPE_MIXED => true,
            self::TYPE_NULL => $value === null,
            self::TYPE_CALLABLE => is_callable($value),
            self::TYPE_EXPRESSION => is_string($value) || is_array($value),
            default => true,
        };
    }
    
    /**
     * Get all valid types
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_STRING,
            self::TYPE_INTEGER,
            self::TYPE_NUMBER,
            self::TYPE_BOOLEAN,
            self::TYPE_ARRAY,
            self::TYPE_OBJECT,
            self::TYPE_MIXED,
            self::TYPE_NULL,
            self::TYPE_CALLABLE,
            self::TYPE_EXPRESSION,
        ];
    }
    
    /**
     * Get all valid platforms
     */
    public static function getPlatforms(): array
    {
        return [
            self::PLATFORM_UNIVERSAL,
            self::PLATFORM_WORDPRESS,
            self::PLATFORM_DRUPAL,
            self::PLATFORM_JOOMLA,
            self::PLATFORM_NATIVE,
            self::PLATFORM_STATIC,
        ];
    }
    
    /**
     * Check if platform is valid
     */
    public static function isValidPlatform(string $platform): bool
    {
        return in_array($platform, self::getPlatforms(), true);
    }
    
    // ========== v11: Pattern Matching Keywords (PLANNED — not yet implemented in TemplateEngine) ==========
    public const PATTERN_KEYWORDS = [
        'match', 'when', 'endmatch', 'endwhen',
        'case', 'default', 'guard', 'if',
    ];
    
    // ========== v11: Async Keywords (PLANNED — not yet implemented in TemplateEngine) ==========
    public const ASYNC_KEYWORDS = [
        'await', 'endawait', 'loading', 'catch',
        'parallel', 'endparallel', 'fetch', 'then',
        'suspense', 'endsuspense', 'fallback',
    ];
    
    // ========== v11: i18n Keywords (PLANNED — not yet implemented in TemplateEngine) ==========
    public const I18N_KEYWORDS = [
        'trans', 'endtrans', 'plural', 'context',
    ];
    
    // ========== v11: All Keywords ==========
    public static function getAllKeywords(): array
    {
        return array_merge(
            self::PATTERN_KEYWORDS,
            self::ASYNC_KEYWORDS,
            self::I18N_KEYWORDS
        );
    }
    
    // ========== v11: Type Operators ==========
    public const TYPE_OPERATORS = [
        '|' => 'union',
        '&' => 'intersection',
        '?' => 'optional',
        '!' => 'non_null',
        '...' => 'spread',
        'extends' => 'constraint',
        'infer' => 'infer',
        'keyof' => 'keyof',
        'typeof' => 'typeof',
        'readonly' => 'readonly',
    ];
    
    // ========== v11: Built-in Utility Types ==========
    public const UTILITY_TYPES = [
        'Partial',      // Make all properties optional
        'Required',     // Make all properties required
        'Readonly',     // Make all properties readonly
        'Pick',         // Pick subset of properties
        'Omit',         // Omit subset of properties
        'Record',       // Create object type with keys and values
        'Exclude',      // Exclude types from union
        'Extract',      // Extract types from union
        'NonNullable',  // Remove null and undefined
        'ReturnType',   // Get return type of function
        'Parameters',   // Get parameter types of function
        'Awaited',      // Unwrap Promise type
    ];
    
    // ========== v11.1: Experimentation Keywords (PLANNED — not yet implemented) ==========
    public const EXPERIMENT_KEYWORDS = [
        'experiment',
        'variant',
        'endexperiment',
        'convert',
    ];
    
    // ========== v11.1: Cache Keywords (PLANNED — not yet implemented) ==========
    public const CACHE_KEYWORDS = [
        'cache',
        'endcache',
        'depends_on',
        'invalidate',
        'ttl',
    ];
    
    // ========== v11.1: Security Keywords (PLANNED — not yet implemented) ==========
    public const SECURITY_KEYWORDS = [
        'sandbox',
        'endsandbox',
        'trusted',
        'untrusted',
    ];
    
    // ========== v11.1: Federation Keywords (PLANNED — not yet implemented) ==========
    public const FEDERATION_KEYWORDS = [
        'federated_query',
        'remote',
        'aggregate',
    ];
    
    // ========== v11.1: AI Keywords (PLANNED — not yet implemented) ==========
    public const AI_KEYWORDS = [
        'ai_generate',
        'ai_query',
        'ai_complete',
        'ai_optimize',
    ];
    
    /**
     * Check if a type is a utility type
     */
    public static function isUtilityType(string $type): bool
    {
        return in_array($type, self::UTILITY_TYPES, true);
    }
    
    /**
     * Get all advanced types
     */
    public static function getAdvancedTypes(): array
    {
        return [
            self::TYPE_NEVER,
            self::TYPE_UNKNOWN,
            self::TYPE_VOID,
            self::TYPE_ANY,
            self::TYPE_UNION,
            self::TYPE_INTERSECTION,
            self::TYPE_GENERIC,
            self::TYPE_TUPLE,
            self::TYPE_LITERAL,
            self::TYPE_TEMPLATE_LITERAL,
            self::TYPE_CONDITIONAL,
            self::TYPE_MAPPED,
            self::TYPE_INFER,
        ];
    }
    
    /**
     * Get all v11.1 keywords
     */
    public static function getV11Keywords(): array
    {
        return array_merge(
            self::EXPERIMENT_KEYWORDS,
            self::CACHE_KEYWORDS,
            self::SECURITY_KEYWORDS,
            self::FEDERATION_KEYWORDS,
            self::AI_KEYWORDS
        );
    }
}
