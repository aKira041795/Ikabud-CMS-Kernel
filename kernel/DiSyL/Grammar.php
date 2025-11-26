<?php
/**
 * DiSyL Grammar v1.0.0
 * 
 * Formal grammar definitions and validation rules for DiSyL Language Specification.
 * Implements the EBNF grammar defined in DISYL_SYNTAX_REFERENCE.md
 * 
 * Features:
 * - Complete type system (primitives, complex, platform-specific)
 * - Filter pipeline validation with named/positional arguments
 * - Platform compatibility checking
 * - Union type support
 * - Component prop validation
 * - Slot definition validation
 * - Expression syntax validation
 * 
 * @version 1.0.0
 * @see DISYL_SYNTAX_REFERENCE.md
 */

namespace IkabudKernel\Core\DiSyL;

class Grammar
{
    // =========================================================================
    // PRIMITIVE TYPES (as per EBNF specification)
    // =========================================================================
    
    public const TYPE_STRING = 'string';
    public const TYPE_NUMBER = 'number';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_FLOAT = 'float';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_NULL = 'null';
    public const TYPE_ARRAY = 'array';
    public const TYPE_OBJECT = 'object';
    public const TYPE_ANY = 'any';
    
    // =========================================================================
    // EXTENDED TYPES (for visual builders and platform-specific features)
    // =========================================================================
    
    public const TYPE_URL = 'url';
    public const TYPE_IMAGE = 'image';
    public const TYPE_COLOR = 'color';
    public const TYPE_DATE = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_EMAIL = 'email';
    public const TYPE_PHONE = 'phone';
    public const TYPE_HTML = 'html';
    public const TYPE_MARKDOWN = 'markdown';
    public const TYPE_JSON = 'json';
    public const TYPE_EXPRESSION = 'expression';
    
    // =========================================================================
    // PLATFORM IDENTIFIERS
    // =========================================================================
    
    public const PLATFORM_WORDPRESS = 'wordpress';
    public const PLATFORM_JOOMLA = 'joomla';
    public const PLATFORM_DRUPAL = 'drupal';
    public const PLATFORM_IKABUD = 'ikabud';
    public const PLATFORM_GENERIC = 'generic';
    public const PLATFORM_REACT_NATIVE = 'react_native';
    public const PLATFORM_FLUTTER = 'flutter';
    public const PLATFORM_ELECTRON = 'electron';
    public const PLATFORM_TAURI = 'tauri';
    public const PLATFORM_IOS = 'ios';
    public const PLATFORM_ANDROID = 'android';
    public const PLATFORM_UNIVERSAL = '*';
    
    /** @var array All valid platform identifiers */
    public const VALID_PLATFORMS = [
        self::PLATFORM_WORDPRESS,
        self::PLATFORM_JOOMLA,
        self::PLATFORM_DRUPAL,
        self::PLATFORM_IKABUD,
        self::PLATFORM_GENERIC,
        self::PLATFORM_REACT_NATIVE,
        self::PLATFORM_FLUTTER,
        self::PLATFORM_ELECTRON,
        self::PLATFORM_TAURI,
        self::PLATFORM_IOS,
        self::PLATFORM_ANDROID,
        self::PLATFORM_UNIVERSAL,
    ];
    
    /** @var array Platform categories */
    public const PLATFORM_CATEGORIES = [
        'web' => [self::PLATFORM_WORDPRESS, self::PLATFORM_JOOMLA, self::PLATFORM_DRUPAL, self::PLATFORM_IKABUD, self::PLATFORM_GENERIC],
        'mobile' => [self::PLATFORM_REACT_NATIVE, self::PLATFORM_FLUTTER, self::PLATFORM_IOS, self::PLATFORM_ANDROID],
        'desktop' => [self::PLATFORM_ELECTRON, self::PLATFORM_TAURI],
        'universal' => [self::PLATFORM_UNIVERSAL],
    ];
    
    // =========================================================================
    // REGEX PATTERNS (from EBNF lexical grammar)
    // =========================================================================
    
    /** @var string Identifier pattern: letter or underscore, followed by letters, digits, underscore, hyphen, dot */
    public const PATTERN_IDENTIFIER = '/^[a-zA-Z_][a-zA-Z0-9_\-\.]*$/';
    
    /** @var string Namespaced identifier: optional namespace with colon */
    public const PATTERN_NAMESPACED_ID = '/^(?:[a-zA-Z_][a-zA-Z0-9_]*:)?[a-zA-Z_][a-zA-Z0-9_\-\.]*$/';
    
    /** @var string Expression pattern: variable access with optional filters */
    public const PATTERN_EXPRESSION = '/^\{[^}]+\}$/';
    
    /** @var string Filter chain pattern */
    public const PATTERN_FILTER_CHAIN = '/\|[\s]*([a-zA-Z_][a-zA-Z0-9_]*)(?::([^|]*))?/';
    
    /** @var string URL pattern */
    public const PATTERN_URL = '/^(https?:\/\/|\/)[^\s]*$/i';
    
    /** @var string Email pattern */
    public const PATTERN_EMAIL = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
    
    /** @var string Color pattern (hex, rgb, rgba, hsl, named) */
    public const PATTERN_COLOR = '/^(#[0-9a-fA-F]{3,8}|rgb\(|rgba\(|hsl\(|hsla\(|[a-zA-Z]+).*$/';
    
    /** @var string Date pattern (ISO 8601) */
    public const PATTERN_DATE = '/^\d{4}-\d{2}-\d{2}$/';
    
    /** @var string DateTime pattern (ISO 8601) */
    public const PATTERN_DATETIME = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/';
    
    // =========================================================================
    // RESERVED KEYWORDS
    // =========================================================================
    
    /** @var array Control structure keywords */
    public const KEYWORDS_CONTROL = ['if', 'else', 'elseif', 'for', 'empty', 'switch', 'case', 'default'];
    
    /** @var array Boolean keywords */
    public const KEYWORDS_BOOLEAN = ['true', 'false'];
    
    /** @var array Null keyword */
    public const KEYWORDS_NULL = ['null'];
    
    /** @var array All reserved keywords */
    public const RESERVED_KEYWORDS = [
        ...self::KEYWORDS_CONTROL,
        ...self::KEYWORDS_BOOLEAN,
        ...self::KEYWORDS_NULL,
        'ikb_platform', 'ikb_cms', 'ikb_component', 'ikb_include',
        'props', 'prop', 'slots', 'slot', 'template',
    ];
    
    // =========================================================================
    // COMPONENT CATEGORIES
    // =========================================================================
    
    public const CATEGORY_LAYOUT = 'layout';
    public const CATEGORY_CONTENT = 'content';
    public const CATEGORY_INTERACTIVE = 'interactive';
    public const CATEGORY_MEDIA = 'media';
    public const CATEGORY_DATA = 'data';
    public const CATEGORY_CONTROL = 'control';
    public const CATEGORY_CMS = 'cms';
    public const CATEGORY_MOBILE = 'mobile';
    public const CATEGORY_DESKTOP = 'desktop';
    
    /**
     * Validate parameter value against schema
     * 
     * @param mixed $value Value to validate
     * @param array $schema Parameter schema
     * @return bool True if valid
     */
    public function validate(mixed $value, array $schema): bool
    {
        // Check required
        if (isset($schema['required']) && $schema['required'] === true) {
            if ($value === null) {
                return false;
            }
        }
        
        // Allow null if not required
        if ($value === null && (!isset($schema['required']) || $schema['required'] === false)) {
            return true;
        }
        
        // Check type
        if (isset($schema['type'])) {
            if (!$this->validateType($value, $schema['type'])) {
                return false;
            }
        }
        
        // Check enum
        if (isset($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                return false;
            }
        }
        
        // Check min/max for numbers
        if (is_numeric($value)) {
            if (isset($schema['min']) && $value < $schema['min']) {
                return false;
            }
            if (isset($schema['max']) && $value > $schema['max']) {
                return false;
            }
        }
        
        // Check minLength/maxLength for strings
        if (is_string($value)) {
            if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) {
                return false;
            }
            if (isset($schema['maxLength']) && strlen($value) > $schema['maxLength']) {
                return false;
            }
        }
        
        // Check pattern for strings
        if (is_string($value) && isset($schema['pattern'])) {
            if (!preg_match($schema['pattern'], $value)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate value type (supports union types)
     * 
     * @param mixed $value Value to validate
     * @param string $type Type specification (can be union: "string|number")
     * @return bool True if valid
     */
    private function validateType(mixed $value, string $type): bool
    {
        // Handle union types (e.g., "string|number")
        if (str_contains($type, '|')) {
            $types = explode('|', $type);
            foreach ($types as $t) {
                if ($this->validateSingleType($value, trim($t))) {
                    return true;
                }
            }
            return false;
        }
        
        return $this->validateSingleType($value, $type);
    }
    
    /**
     * Validate against a single type
     */
    private function validateSingleType(mixed $value, string $type): bool
    {
        // Handle array types (e.g., "array<string>")
        if (preg_match('/^array<(.+)>$/', $type, $matches)) {
            if (!is_array($value)) {
                return false;
            }
            $itemType = $matches[1];
            foreach ($value as $item) {
                if (!$this->validateType($item, $itemType)) {
                    return false;
                }
            }
            return true;
        }
        
        return match($type) {
            // Primitive types
            self::TYPE_STRING => is_string($value),
            self::TYPE_NUMBER => is_numeric($value),
            self::TYPE_INTEGER => is_int($value) || (is_string($value) && ctype_digit($value)),
            self::TYPE_FLOAT => is_float($value) || is_numeric($value),
            self::TYPE_BOOLEAN => is_bool($value) || $value === 'true' || $value === 'false',
            self::TYPE_NULL => $value === null,
            self::TYPE_ARRAY => is_array($value),
            self::TYPE_OBJECT => is_object($value) || (is_array($value) && $this->isAssociativeArray($value)),
            self::TYPE_ANY => true,
            
            // Extended types
            self::TYPE_URL => is_string($value) && preg_match(self::PATTERN_URL, $value),
            self::TYPE_IMAGE => is_string($value) && (
                preg_match(self::PATTERN_URL, $value) || 
                preg_match('/\.(jpg|jpeg|png|gif|webp|svg|ico|bmp|tiff)$/i', $value)
            ),
            self::TYPE_COLOR => is_string($value) && preg_match(self::PATTERN_COLOR, $value),
            self::TYPE_DATE => is_string($value) && preg_match(self::PATTERN_DATE, $value),
            self::TYPE_DATETIME => is_string($value) && preg_match(self::PATTERN_DATETIME, $value),
            self::TYPE_EMAIL => is_string($value) && preg_match(self::PATTERN_EMAIL, $value),
            self::TYPE_PHONE => is_string($value) && preg_match('/^[\d\s\-\+\(\)]+$/', $value),
            self::TYPE_HTML => is_string($value),
            self::TYPE_MARKDOWN => is_string($value),
            self::TYPE_JSON => is_string($value) && json_decode($value) !== null,
            self::TYPE_EXPRESSION => is_string($value) && preg_match(self::PATTERN_EXPRESSION, $value),
            
            default => false
        };
    }
    
    /**
     * Check if array is associative (object-like)
     */
    private function isAssociativeArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
    
    /**
     * Normalize parameter value (apply defaults, coerce types)
     * 
     * @param mixed $value Value to normalize
     * @param array $schema Parameter schema
     * @return mixed Normalized value
     */
    public function normalize(mixed $value, array $schema): mixed
    {
        // Apply default if value is null
        if ($value === null && isset($schema['default'])) {
            return $schema['default'];
        }
        
        // Type coercion if specified
        if ($value !== null && isset($schema['type']) && isset($schema['coerce']) && $schema['coerce'] === true) {
            $value = $this->coerceType($value, $schema['type']);
        }
        
        return $value;
    }
    
    /**
     * Coerce value to specified type
     */
    private function coerceType(mixed $value, string $type): mixed
    {
        return match($type) {
            self::TYPE_STRING => (string)$value,
            self::TYPE_INTEGER => (int)$value,
            self::TYPE_FLOAT => (float)$value,
            self::TYPE_BOOLEAN => (bool)$value,
            self::TYPE_NUMBER => is_float($value) ? (float)$value : (int)$value,
            default => $value
        };
    }
    
    /**
     * Get validation error message
     * 
     * @param mixed $value Value that failed validation
     * @param array $schema Parameter schema
     * @param string $paramName Parameter name
     * @return string Error message
     */
    public function getValidationError(mixed $value, array $schema, string $paramName): string
    {
        // Check required
        if (isset($schema['required']) && $schema['required'] === true && $value === null) {
            return sprintf('Parameter "%s" is required', $paramName);
        }
        
        // Check type
        if (isset($schema['type']) && !$this->validateType($value, $schema['type'])) {
            return sprintf(
                'Parameter "%s" must be of type %s, got %s',
                $paramName,
                $schema['type'],
                gettype($value)
            );
        }
        
        // Check enum
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return sprintf(
                'Parameter "%s" must be one of [%s], got "%s"',
                $paramName,
                implode(', ', $schema['enum']),
                $value
            );
        }
        
        // Check min/max
        if (is_numeric($value)) {
            if (isset($schema['min']) && $value < $schema['min']) {
                return sprintf('Parameter "%s" must be >= %s', $paramName, $schema['min']);
            }
            if (isset($schema['max']) && $value > $schema['max']) {
                return sprintf('Parameter "%s" must be <= %s', $paramName, $schema['max']);
            }
        }
        
        // Check length
        if (is_string($value)) {
            if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) {
                return sprintf('Parameter "%s" must be at least %d characters', $paramName, $schema['minLength']);
            }
            if (isset($schema['maxLength']) && strlen($value) > $schema['maxLength']) {
                return sprintf('Parameter "%s" must be at most %d characters', $paramName, $schema['maxLength']);
            }
        }
        
        // Check pattern
        if (is_string($value) && isset($schema['pattern']) && !preg_match($schema['pattern'], $value)) {
            return sprintf('Parameter "%s" does not match required pattern', $paramName);
        }
        
        return sprintf('Parameter "%s" is invalid', $paramName);
    }
    
    /**
     * Validate all attributes against schemas
     * 
     * @param array $attributes Attributes to validate
     * @param array $schemas Attribute schemas
     * @return array Array of error messages (empty if valid)
     */
    public function validateAttributes(array $attributes, array $schemas): array
    {
        $errors = [];
        
        // Check each schema
        foreach ($schemas as $name => $schema) {
            $value = $attributes[$name] ?? null;
            
            if (!$this->validate($value, $schema)) {
                $errors[] = $this->getValidationError($value, $schema, $name);
            }
        }
        
        return $errors;
    }
    
    /**
     * Normalize all attributes
     * 
     * @param array $attributes Attributes to normalize
     * @param array $schemas Attribute schemas
     * @return array Normalized attributes
     */
    public function normalizeAttributes(array $attributes, array $schemas): array
    {
        $normalized = [];
        
        foreach ($schemas as $name => $schema) {
            $value = $attributes[$name] ?? null;
            $normalized[$name] = $this->normalize($value, $schema);
        }
        
        // Keep attributes not in schema
        foreach ($attributes as $name => $value) {
            if (!isset($schemas[$name])) {
                $normalized[$name] = $value;
            }
        }
        
        return $normalized;
    }
    
    // =========================================================================
    // PLATFORM VALIDATION
    // =========================================================================
    
    /**
     * Validate platform identifier
     * 
     * @param string $platform Platform identifier
     * @return bool True if valid
     */
    public function validatePlatform(string $platform): bool
    {
        return in_array($platform, self::VALID_PLATFORMS, true);
    }
    
    /**
     * Validate platform list (comma-separated)
     * 
     * @param string $platforms Comma-separated platform list
     * @return array Invalid platforms (empty if all valid)
     */
    public function validatePlatformList(string $platforms): array
    {
        $invalid = [];
        $list = array_map('trim', explode(',', $platforms));
        
        foreach ($list as $platform) {
            if (!$this->validatePlatform($platform)) {
                $invalid[] = $platform;
            }
        }
        
        return $invalid;
    }
    
    /**
     * Check if component is compatible with platform
     * 
     * @param string $component Component name (may be namespaced)
     * @param string $platform Target platform
     * @return bool True if compatible
     */
    public function isComponentCompatible(string $component, string $platform): bool
    {
        // Universal components (ikb_*) work everywhere
        if (str_starts_with($component, 'ikb_')) {
            return true;
        }
        
        // Check namespaced components
        if (str_contains($component, ':')) {
            [$namespace, $name] = explode(':', $component, 2);
            
            // Platform-specific namespaces
            $namespaceMap = [
                'wp' => [self::PLATFORM_WORDPRESS],
                'joomla' => [self::PLATFORM_JOOMLA],
                'drupal' => [self::PLATFORM_DRUPAL],
                'mobile' => [self::PLATFORM_REACT_NATIVE, self::PLATFORM_FLUTTER, self::PLATFORM_IOS, self::PLATFORM_ANDROID],
                'desktop' => [self::PLATFORM_ELECTRON, self::PLATFORM_TAURI],
            ];
            
            if (isset($namespaceMap[$namespace])) {
                return in_array($platform, $namespaceMap[$namespace], true) || $platform === self::PLATFORM_UNIVERSAL;
            }
        }
        
        // Default: assume compatible
        return true;
    }
    
    /**
     * Get platform category
     * 
     * @param string $platform Platform identifier
     * @return string|null Category or null if invalid
     */
    public function getPlatformCategory(string $platform): ?string
    {
        foreach (self::PLATFORM_CATEGORIES as $category => $platforms) {
            if (in_array($platform, $platforms, true)) {
                return $category;
            }
        }
        return null;
    }
    
    // =========================================================================
    // IDENTIFIER VALIDATION
    // =========================================================================
    
    /**
     * Validate identifier syntax
     * 
     * @param string $identifier Identifier to validate
     * @return bool True if valid
     */
    public function validateIdentifier(string $identifier): bool
    {
        return (bool) preg_match(self::PATTERN_IDENTIFIER, $identifier);
    }
    
    /**
     * Validate namespaced identifier
     * 
     * @param string $identifier Namespaced identifier (e.g., "wp:query")
     * @return bool True if valid
     */
    public function validateNamespacedIdentifier(string $identifier): bool
    {
        return (bool) preg_match(self::PATTERN_NAMESPACED_ID, $identifier);
    }
    
    /**
     * Check if identifier is a reserved keyword
     * 
     * @param string $identifier Identifier to check
     * @return bool True if reserved
     */
    public function isReservedKeyword(string $identifier): bool
    {
        return in_array(strtolower($identifier), self::RESERVED_KEYWORDS, true);
    }
    
    /**
     * Parse namespaced identifier
     * 
     * @param string $identifier Namespaced identifier
     * @return array ['namespace' => string|null, 'name' => string]
     */
    public function parseNamespacedIdentifier(string $identifier): array
    {
        if (str_contains($identifier, ':')) {
            [$namespace, $name] = explode(':', $identifier, 2);
            return ['namespace' => $namespace, 'name' => $name];
        }
        return ['namespace' => null, 'name' => $identifier];
    }
    
    // =========================================================================
    // COMPONENT PROP VALIDATION (for visual builders)
    // =========================================================================
    
    /**
     * Validate component prop definition
     * 
     * @param array $prop Prop definition
     * @return array Validation errors (empty if valid)
     */
    public function validatePropDefinition(array $prop): array
    {
        $errors = [];
        
        // Name is required
        if (!isset($prop['name']) || empty($prop['name'])) {
            $errors[] = 'Prop must have a name';
        } elseif (!$this->validateIdentifier($prop['name'])) {
            $errors[] = sprintf('Invalid prop name: %s', $prop['name']);
        }
        
        // Type must be valid if specified
        if (isset($prop['type'])) {
            $validTypes = [
                self::TYPE_STRING, self::TYPE_NUMBER, self::TYPE_INTEGER, self::TYPE_FLOAT,
                self::TYPE_BOOLEAN, self::TYPE_ARRAY, self::TYPE_OBJECT,
                self::TYPE_URL, self::TYPE_IMAGE, self::TYPE_COLOR, self::TYPE_DATE,
                self::TYPE_EMAIL, self::TYPE_HTML, self::TYPE_MARKDOWN, self::TYPE_JSON,
            ];
            
            // Handle union types
            $types = explode('|', $prop['type']);
            foreach ($types as $type) {
                $type = trim($type);
                // Handle array<T> syntax
                if (preg_match('/^array<.+>$/', $type)) {
                    continue;
                }
                if (!in_array($type, $validTypes, true)) {
                    $errors[] = sprintf('Invalid prop type: %s', $type);
                }
            }
        }
        
        // Default value must match type if both specified
        if (isset($prop['type']) && isset($prop['default'])) {
            if (!$this->validateType($prop['default'], $prop['type'])) {
                $errors[] = sprintf('Default value does not match type %s', $prop['type']);
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate slot definition
     * 
     * @param array $slot Slot definition
     * @return array Validation errors (empty if valid)
     */
    public function validateSlotDefinition(array $slot): array
    {
        $errors = [];
        
        // Name is required
        if (!isset($slot['name']) || empty($slot['name'])) {
            $errors[] = 'Slot must have a name';
        } elseif (!$this->validateIdentifier($slot['name'])) {
            $errors[] = sprintf('Invalid slot name: %s', $slot['name']);
        }
        
        return $errors;
    }
    
    // =========================================================================
    // FILTER VALIDATION
    // =========================================================================
    
    /**
     * Parse filter chain from expression
     * 
     * @param string $expression Expression with filters (e.g., "value | filter1 | filter2:arg")
     * @return array Parsed filters
     */
    public function parseFilterChain(string $expression): array
    {
        $filters = [];
        
        if (preg_match_all(self::PATTERN_FILTER_CHAIN, $expression, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $filter = [
                    'name' => $match[1],
                    'args' => [],
                ];
                
                // Parse arguments if present
                if (isset($match[2]) && !empty($match[2])) {
                    $filter['args'] = $this->parseFilterArgs($match[2]);
                }
                
                $filters[] = $filter;
            }
        }
        
        return $filters;
    }
    
    /**
     * Parse filter arguments
     * 
     * @param string $argsString Arguments string (e.g., "length=100,append='...'")
     * @return array Parsed arguments
     */
    public function parseFilterArgs(string $argsString): array
    {
        $args = [];
        $parts = preg_split('/,(?=(?:[^"\']*["\'][^"\']*["\'])*[^"\']*$)/', $argsString);
        
        foreach ($parts as $index => $part) {
            $part = trim($part);
            
            // Named argument (key=value)
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(.+)$/', $part, $match)) {
                $args[$match[1]] = $this->parseValue($match[2]);
            } else {
                // Positional argument
                $args[$index] = $this->parseValue($part);
            }
        }
        
        return $args;
    }
    
    /**
     * Parse a literal value
     * 
     * @param string $value Value string
     * @return mixed Parsed value
     */
    private function parseValue(string $value): mixed
    {
        $value = trim($value);
        
        // String (quoted)
        if (preg_match('/^["\'](.*)["\']\s*$/', $value, $match)) {
            return $match[1];
        }
        
        // Boolean
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        
        // Null
        if ($value === 'null') return null;
        
        // Number
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        
        // Expression or identifier
        return $value;
    }
    
    // =========================================================================
    // EXPRESSION VALIDATION
    // =========================================================================
    
    /**
     * Validate expression syntax
     * 
     * @param string $expression Expression to validate
     * @return array Validation errors (empty if valid)
     */
    public function validateExpression(string $expression): array
    {
        $errors = [];
        
        // Must be wrapped in braces
        if (!str_starts_with($expression, '{') || !str_ends_with($expression, '}')) {
            $errors[] = 'Expression must be wrapped in { }';
            return $errors;
        }
        
        // Extract inner content
        $inner = substr($expression, 1, -1);
        
        // Check for balanced braces
        $depth = 0;
        for ($i = 0; $i < strlen($inner); $i++) {
            if ($inner[$i] === '{') $depth++;
            if ($inner[$i] === '}') $depth--;
            if ($depth < 0) {
                $errors[] = 'Unbalanced braces in expression';
                break;
            }
        }
        
        if ($depth !== 0) {
            $errors[] = 'Unbalanced braces in expression';
        }
        
        return $errors;
    }
    
    // =========================================================================
    // SCHEMA GENERATION (for visual builders)
    // =========================================================================
    
    /**
     * Generate JSON schema for component props
     * 
     * @param array $props Array of prop definitions
     * @return array JSON Schema compatible object
     */
    public function generatePropsSchema(array $props): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
        
        foreach ($props as $prop) {
            $propSchema = [
                'type' => $this->mapTypeToJsonSchema($prop['type'] ?? 'string'),
            ];
            
            if (isset($prop['label'])) {
                $propSchema['title'] = $prop['label'];
            }
            
            if (isset($prop['default'])) {
                $propSchema['default'] = $prop['default'];
            }
            
            if (isset($prop['enum'])) {
                $propSchema['enum'] = $prop['enum'];
            }
            
            if (isset($prop['min'])) {
                $propSchema['minimum'] = $prop['min'];
            }
            
            if (isset($prop['max'])) {
                $propSchema['maximum'] = $prop['max'];
            }
            
            $schema['properties'][$prop['name']] = $propSchema;
            
            if (isset($prop['required']) && $prop['required']) {
                $schema['required'][] = $prop['name'];
            }
        }
        
        return $schema;
    }
    
    /**
     * Map DiSyL type to JSON Schema type
     */
    private function mapTypeToJsonSchema(string $type): string
    {
        return match($type) {
            self::TYPE_STRING, self::TYPE_URL, self::TYPE_IMAGE, 
            self::TYPE_COLOR, self::TYPE_DATE, self::TYPE_EMAIL,
            self::TYPE_HTML, self::TYPE_MARKDOWN => 'string',
            self::TYPE_NUMBER, self::TYPE_FLOAT => 'number',
            self::TYPE_INTEGER => 'integer',
            self::TYPE_BOOLEAN => 'boolean',
            self::TYPE_ARRAY => 'array',
            self::TYPE_OBJECT, self::TYPE_JSON => 'object',
            self::TYPE_NULL => 'null',
            default => 'string',
        };
    }
}
