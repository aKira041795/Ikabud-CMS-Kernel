<?php
/**
 * DiSyL Grammar v4.0.0
 *
 * Defines type constants, platform identifiers, keyword registries, and
 * validation rules for the DiSyL template language.
 *
 * All keyword constants here are backed by runtime implementations in
 * TemplateEngine::evaluateStructureBody().  Aspirational / future items
 * (type operators, utility types) live in Grammar\Planned.php.
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
    public const TYPE_FLOAT = 'float';           // alias for number, more intuitive for templates

    // ========== Platform Constants ==========
    public const PLATFORM_UNIVERSAL = 'universal';
    public const PLATFORM_WORDPRESS = 'wordpress';
    public const PLATFORM_DRUPAL = 'drupal';
    public const PLATFORM_JOOMLA = 'joomla';
    public const PLATFORM_NATIVE = 'native';
    public const PLATFORM_IKABUD = 'ikabud';
    public const PLATFORM_STATIC = 'static';

    // ── Bridge (frontend framework) identifiers ──
    // These identify which JS framework bridge to use when rendering
    // {ikb_component} and {state} blocks. Each maps to a Bridge class.
    public const BRIDGE_REACT = 'react';
    public const BRIDGE_ALPINE = 'alpine';
    public const BRIDGE_HTMX = 'htmx';
    public const BRIDGE_CUSTOM = 'custom';
    
    // ========== Declaration Keywords ==========
    public const KEYWORD_VAR = '@var';  // {@var type $name} — variable type declaration (v4.9+)

    // ── Expression Keywords (evaluated at template-expression level) ──
    public const KW_KEYOF = 'keyof';    // {keyof entity_type.view} — resolved entity view field list

    // ========== Structure Body Keywords (v4.3+) ==========
    // All keywords below are wired in TemplateEngine::evaluateStructureBody().
    // Each constant maps to a dedicated evaluator method.

    // ── Cache ──
    public const KW_CACHE = 'cache';
    public const KW_ENDCACHE = 'endcache';
    public const KW_DEPENDS_ON = 'depends_on';
    public const KW_INVALIDATE = 'invalidate';
    public const KW_TTL = 'ttl';
    public const CACHE_KEYWORDS = [
        'cache', 'endcache', 'depends_on', 'invalidate', 'ttl',
    ];

    // ── Security / Sandbox ──
    public const KW_SANDBOX = 'sandbox';
    public const KW_ENDSANDBOX = 'endsandbox';
    public const KW_TRUSTED = 'trusted';
    public const KW_UNTRUSTED = 'untrusted';
    public const SECURITY_KEYWORDS = [
        'sandbox', 'endsandbox', 'trusted', 'untrusted',
    ];

    // ── Experimentation ──
    public const KW_EXPERIMENT = 'experiment';
    public const KW_VARIANT = 'variant';
    public const KW_ENDEXPERIMENT = 'endexperiment';
    public const KW_CONVERT = 'convert';
    public const EXPERIMENT_KEYWORDS = [
        'experiment', 'variant', 'endexperiment', 'convert',
    ];

    // ── Internationalization ──
    public const KW_TRANS = 'trans';
    public const KW_ENDTRANS = 'endtrans';
    public const KW_PLURAL = 'plural';
    public const KW_CONTEXT = 'context';
    public const I18N_KEYWORDS = [
        'trans', 'endtrans', 'plural', 'context',
    ];

    // ── Async / Concurrency ──
    public const KW_AWAIT = 'await';
    public const KW_ENDAWAIT = 'endawait';
    public const KW_LOADING = 'loading';
    public const KW_CATCH = 'catch';
    public const KW_PARALLEL = 'parallel';
    public const KW_ENDPARALLEL = 'endparallel';
    public const KW_FETCH = 'fetch';
    public const KW_THEN = 'then';
    public const KW_SUSPENSE = 'suspense';
    public const KW_ENDSUSPENSE = 'endsuspense';
    public const KW_FALLBACK = 'fallback';
    public const ASYNC_KEYWORDS = [
        'await', 'endawait', 'loading', 'catch',
        'parallel', 'endparallel', 'fetch', 'then',
        'suspense', 'endsuspense', 'fallback',
    ];

    // ── Pattern Matching ──
    public const KW_MATCH = 'match';
    public const KW_WHEN = 'when';
    public const KW_DEFAULT = 'default';

    // ── Federation ──
    public const KW_FEDERATED_QUERY = 'federated_query';
    public const KW_REMOTE = 'remote';
    public const KW_AGGREGATE = 'aggregate';
    public const FEDERATION_KEYWORDS = [
        'federated_query', 'remote', 'aggregate',
    ];

    // ── AI ──
    public const KW_AI_GENERATE = 'ai_generate';
    public const KW_AI_QUERY = 'ai_query';
    public const KW_AI_COMPLETE = 'ai_complete';
    public const KW_AI_OPTIMIZE = 'ai_optimize';
    public const AI_KEYWORDS = [
        'ai_generate', 'ai_query', 'ai_complete', 'ai_optimize',
    ];
    
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
    
    /**
     * Get all declaration keywords
     */
    public static function getKeywords(): array
    {
        return [
            self::KEYWORD_VAR,
        ];
    }

    /**
     * Get all structure-body keywords wired in evaluateStructureBody().
     * These are the keywords whose evaluator methods exist and are dispatched.
     */
    public static function getStructureBodyKeywords(): array
    {
        return array_merge(
            self::CACHE_KEYWORDS,
            self::SECURITY_KEYWORDS,
            self::EXPERIMENT_KEYWORDS,
            self::I18N_KEYWORDS,
            self::ASYNC_KEYWORDS,
            self::FEDERATION_KEYWORDS,
            self::AI_KEYWORDS,
            [
                self::KW_MATCH,
                self::KW_WHEN,
                self::KW_DEFAULT,
            ]
        );
    }

    /**
     * Validate a {@var} declaration type string.
     *
     * Accepts PHP-style type hints: string, ?string, int, float, bool,
     * array, array<K,V>, object, mixed, null, callable.
     */
    public static function validateVarDeclaration(string $type, string $name): bool
    {
        if (!preg_match('/^[a-zA-Z_]\w*$/', $name)) {
            return false;
        }
        // Strip nullable prefix and generic suffix for base type check
        $base = ltrim($type, '?');
        $base = preg_replace('/<.*>$/', '', $base);
        return in_array($base, [
            'string', 'int', 'integer', 'float', 'number',
            'bool', 'boolean', 'array', 'object', 'mixed',
            'null', 'callable', 'expression',
        ], true);
    }

    // ========== Validation ==========
    
    /**
     * Validate a value against a type. Supports nullable prefix `?`.
     */
    public static function validateType(mixed $value, string $type): bool
    {
        // Nullable types: ?string, ?int, etc.
        if (str_starts_with($type, '?')) {
            if ($value === null || $value === '') {
                return true;
            }
            $type = substr($type, 1);
        }

        return match ($type) {
            self::TYPE_STRING => is_string($value),
            self::TYPE_INTEGER => is_int($value),
            self::TYPE_NUMBER, self::TYPE_FLOAT => is_numeric($value),
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
            self::TYPE_FLOAT,
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
}
