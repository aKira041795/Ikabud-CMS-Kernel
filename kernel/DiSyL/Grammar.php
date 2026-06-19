<?php
/**
 * DiSyL Grammar v4.0.0
 *
 * Defines type constants, platform identifiers, and validation rules
 * for the DiSyL template language.
 *
 * The v4 type system is intentionally simple: string, integer, number,
 * boolean, array, object, mixed, null, callable, expression.  Advanced
 * types (union, generic, intersection, etc.) are planned for v11 — see
 * docs/kernel/disyl-grammar-v11-planned-types.md and Grammar/Planned.php.
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
    
    // ========== Planned / Roadmap ==========
    // v11+ keywords and type operators live in Grammar\Planned.
    // See docs/kernel/disyl-grammar-v11-planned-types.md for details.
}
