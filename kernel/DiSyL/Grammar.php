<?php
/**
 * DiSyL Grammar v4.0.0
 * 
 * Defines type constants, platform identifiers, and validation rules
 * for the DiSyL template language.
 * 
 * v4.0.0 (schema version aligned with TemplateEngine and v4 Parser):
 * - Core type system constants
 * - Platform identifiers
 * - v11 planned: advanced type system (generics, union, intersection)
 * - v11 planned: pattern matching, async/await, i18n keywords
 * - v11.1 planned: experimentation, cache, security, federation, AI keywords
 * 
 * @package Ikabud\Kernel\DiSyL
 * @version 4.0.0
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
            self::PLATFORM_IKABUD,
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
    
    // ========== v11+ PLANNED keywords ==========
    // Moved to kernel/DiSyL/Grammar/Planned.php in 4.0.0. Use
    // \Ikabud\Kernel\DiSyL\Grammar\Planned::* directly.
    
    // ========== v11: All Keywords ==========
    public static function getAllKeywords(): array
    {
        return Grammar\Planned::getAllV11Keywords();
    }
    
    // ========== Active Type Operators (none) ==========
    // v11 type operators are PLANNED — see Grammar\Planned::TYPE_OPERATORS.
    
    /**
     * @deprecated 4.0.0 use \Ikabud\Kernel\DiSyL\Grammar\Planned::isUtilityType()
     */
    public static function isUtilityType(string $type): bool
    {
        return Grammar\Planned::isUtilityType($type);
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
        return Grammar\Planned::getAllV11_1Keywords();
    }
}
