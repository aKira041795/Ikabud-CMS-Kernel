<?php
/**
 * DiSyL Grammar v1.2.0
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
 * - Rich error objects with source mapping
 * - Strict/lenient validation modes
 * - Expression caching for performance
 * - Filter type chain validation
 * 
 * @version 1.2.0
 * @see DISYL_SYNTAX_REFERENCE.md
 */

namespace IkabudKernel\Core\DiSyL;

/**
 * Validation error with source mapping
 */
class ValidationError implements \JsonSerializable
{
    public function __construct(
        public readonly string $message,
        public readonly string $code,
        public readonly ?string $nodeType = null,
        public readonly ?string $tagName = null,
        public readonly ?int $line = null,
        public readonly ?int $column = null,
        public readonly ?string $snippet = null,
        public readonly string $severity = 'error' // 'error', 'warning', 'info'
    ) {}
    
    public function jsonSerialize(): array
    {
        return array_filter([
            'message' => $this->message,
            'code' => $this->code,
            'nodeType' => $this->nodeType,
            'tagName' => $this->tagName,
            'line' => $this->line,
            'column' => $this->column,
            'snippet' => $this->snippet,
            'severity' => $this->severity,
        ], fn($v) => $v !== null);
    }
    
    public function __toString(): string
    {
        $loc = $this->line ? " at line {$this->line}" : '';
        $loc .= $this->column ? ":{$this->column}" : '';
        return "[{$this->code}] {$this->message}{$loc}";
    }
}

/**
 * Validation result container
 */
class ValidationResult implements \JsonSerializable
{
    /** @var ValidationError[] */
    private array $errors = [];
    
    /** @var ValidationError[] */
    private array $warnings = [];
    
    public function addError(ValidationError $error): self
    {
        if ($error->severity === 'warning') {
            $this->warnings[] = $error;
        } else {
            $this->errors[] = $error;
        }
        return $this;
    }
    
    public function addErrorFromArray(array $data): self
    {
        return $this->addError(new ValidationError(
            $data['message'] ?? 'Unknown error',
            $data['code'] ?? 'UNKNOWN',
            $data['nodeType'] ?? null,
            $data['tagName'] ?? null,
            $data['line'] ?? null,
            $data['column'] ?? null,
            $data['snippet'] ?? null,
            $data['severity'] ?? 'error'
        ));
    }
    
    public function merge(ValidationResult $other): self
    {
        $this->errors = array_merge($this->errors, $other->errors);
        $this->warnings = array_merge($this->warnings, $other->warnings);
        return $this;
    }
    
    public function isValid(): bool
    {
        return empty($this->errors);
    }
    
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }
    
    /** @return ValidationError[] */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /** @return ValidationError[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
    
    /** @return string[] Simple error messages */
    public function getErrorMessages(): array
    {
        return array_map(fn($e) => $e->message, $this->errors);
    }
    
    public function jsonSerialize(): array
    {
        return [
            'valid' => $this->isValid(),
            'errorCount' => count($this->errors),
            'warningCount' => count($this->warnings),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}

class Grammar
{
    // =========================================================================
    // SCHEMA VERSION & VALIDATION MODES
    // =========================================================================
    
    /** Schema version for component/filter manifests */
    public const SCHEMA_VERSION = '1.2.0';
    
    /** Validation mode: strict (blocks on errors) */
    public const MODE_STRICT = 'strict';
    
    /** Validation mode: lenient (warnings only, allows publishing) */
    public const MODE_LENIENT = 'lenient';
    
    /** @var string Current validation mode */
    private string $mode = self::MODE_STRICT;
    
    /** @var array Expression parse cache */
    private static array $expressionCache = [];
    
    /** @var array Component schema cache */
    private static array $componentSchemaCache = [];
    
    /** @var int Max cache size */
    private const MAX_CACHE_SIZE = 1000;
    
    /**
     * Set validation mode
     */
    public function setMode(string $mode): self
    {
        if (!in_array($mode, [self::MODE_STRICT, self::MODE_LENIENT], true)) {
            throw new \InvalidArgumentException("Invalid mode: {$mode}");
        }
        $this->mode = $mode;
        return $this;
    }
    
    /**
     * Get current validation mode
     */
    public function getMode(): string
    {
        return $this->mode;
    }
    
    /**
     * Check if in strict mode
     */
    public function isStrict(): bool
    {
        return $this->mode === self::MODE_STRICT;
    }
    
    /**
     * Clear all caches
     */
    public static function clearCache(): void
    {
        self::$expressionCache = [];
        self::$componentSchemaCache = [];
    }
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
    public function validateType(mixed $value, string $type): bool
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
    
    // =========================================================================
    // COMPONENT VALIDATION (Registry Integration)
    // =========================================================================
    
    /**
     * Validate component props against registry schema
     * 
     * @param string $componentName Component name (e.g., 'ikb_card', 'wp:query')
     * @param array $attributes Provided attributes
     * @return array Validation errors (empty if valid)
     */
    public function validateComponentProps(string $componentName, array $attributes): array
    {
        $errors = [];
        
        // Parse namespaced component
        $parsed = $this->parseNamespacedIdentifier($componentName);
        $lookupName = $parsed['namespace'] ? $componentName : $componentName;
        
        // Get component schema from registry
        if (!ComponentRegistry::has($lookupName)) {
            // Try without namespace prefix for universal components
            if ($parsed['namespace'] && ComponentRegistry::has($parsed['name'])) {
                $lookupName = $parsed['name'];
            } else {
                // Unknown component - not necessarily an error (could be custom)
                return [];
            }
        }
        
        $schema = ComponentRegistry::getAttributeSchemas($lookupName);
        
        // Check required props
        foreach ($schema as $propName => $propSchema) {
            if (isset($propSchema['required']) && $propSchema['required'] === true) {
                if (!isset($attributes[$propName]) || $attributes[$propName] === null) {
                    $errors[] = sprintf(
                        'Component "%s" requires prop "%s"',
                        $componentName,
                        $propName
                    );
                }
            }
        }
        
        // Validate provided props
        foreach ($attributes as $propName => $value) {
            if (isset($schema[$propName])) {
                if (!$this->validate($value, $schema[$propName])) {
                    $errors[] = $this->getValidationError($value, $schema[$propName], $propName);
                }
            }
            // Unknown props are allowed (for extensibility)
        }
        
        return $errors;
    }
    
    /**
     * Validate component slots
     * 
     * @param string $componentName Component name
     * @param array $slots Provided slots ['name' => content]
     * @return array Validation errors
     */
    public function validateSlots(string $componentName, array $slots): array
    {
        $errors = [];
        
        $component = ComponentRegistry::get($componentName);
        if (!$component) {
            return [];
        }
        
        // Check if component allows children (not a leaf)
        if (isset($component['leaf']) && $component['leaf'] === true) {
            if (!empty($slots)) {
                $errors[] = sprintf(
                    'Component "%s" is a leaf component and cannot have children/slots',
                    $componentName
                );
            }
        }
        
        // Check required slots if defined
        if (isset($component['slots'])) {
            foreach ($component['slots'] as $slotName => $slotDef) {
                if (isset($slotDef['required']) && $slotDef['required'] === true) {
                    if (!isset($slots[$slotName])) {
                        $errors[] = sprintf(
                            'Component "%s" requires slot "%s"',
                            $componentName,
                            $slotName
                        );
                    }
                }
            }
        }
        
        return $errors;
    }
    
    // =========================================================================
    // CMS/PLATFORM DECLARATION VALIDATION
    // =========================================================================
    
    /**
     * Validate CMS declaration attributes
     * 
     * @param array $attrs Declaration attributes
     * @return array Validation errors
     */
    public function validateCMSDeclaration(array $attrs): array
    {
        $errors = [];
        
        // Type is required
        if (!isset($attrs['type']) || empty($attrs['type'])) {
            $errors[] = 'CMS declaration requires "type" attribute';
        } elseif (!$this->validatePlatform($attrs['type'])) {
            $errors[] = sprintf('Invalid CMS type: "%s"', $attrs['type']);
        }
        
        // Validate 'set' attribute if present
        if (isset($attrs['set'])) {
            $validSets = ['components', 'filters', 'hooks', 'functions', 'all'];
            $sets = array_map('trim', explode(',', $attrs['set']));
            foreach ($sets as $set) {
                if (!in_array($set, $validSets, true)) {
                    $errors[] = sprintf('Invalid set value: "%s"', $set);
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate platform declaration attributes (new ikb_platform syntax)
     * 
     * @param array $attrs Platform declaration attributes
     * @return array Validation errors
     */
    public function validatePlatformDeclaration(array $attrs): array
    {
        $errors = [];
        
        // Type is required
        if (!isset($attrs['type']) || empty($attrs['type'])) {
            $errors[] = 'Platform declaration requires "type" attribute';
        } else {
            $validTypes = ['web', 'mobile', 'desktop', 'universal'];
            if (!in_array($attrs['type'], $validTypes, true)) {
                $errors[] = sprintf('Invalid platform type: "%s". Must be one of: %s', 
                    $attrs['type'], implode(', ', $validTypes));
            }
        }
        
        // Validate targets if present
        if (isset($attrs['targets'])) {
            $invalidPlatforms = $this->validatePlatformList($attrs['targets']);
            foreach ($invalidPlatforms as $invalid) {
                $errors[] = sprintf('Invalid target platform: "%s"', $invalid);
            }
        }
        
        // Validate fallback if present
        if (isset($attrs['fallback']) && !$this->validatePlatform($attrs['fallback'])) {
            $errors[] = sprintf('Invalid fallback platform: "%s"', $attrs['fallback']);
        }
        
        // Validate version if present
        if (isset($attrs['version']) && !preg_match('/^\d+\.\d+(\.\d+)?$/', $attrs['version'])) {
            $errors[] = sprintf('Invalid version format: "%s". Expected: X.Y or X.Y.Z', $attrs['version']);
        }
        
        // Validate features if present
        if (isset($attrs['features'])) {
            $validFeatures = ['components', 'filters', 'queries', 'slots', 'expressions'];
            $features = array_map('trim', explode(',', $attrs['features']));
            foreach ($features as $feature) {
                if (!in_array($feature, $validFeatures, true)) {
                    $errors[] = sprintf('Invalid feature: "%s"', $feature);
                }
            }
        }
        
        return $errors;
    }
    
    // =========================================================================
    // STRUCTURAL VALIDATION (Tags, Templates)
    // =========================================================================
    
    /**
     * Validate tag structure
     * 
     * @param array $tag Tag node from AST
     * @return array Validation errors
     */
    public function validateTag(array $tag): array
    {
        $errors = [];
        
        // Must have a name
        if (!isset($tag['name']) || empty($tag['name'])) {
            $errors[] = 'Tag must have a name';
            return $errors;
        }
        
        $tagName = $tag['name'];
        
        // Validate tag name syntax
        if (!$this->validateNamespacedIdentifier($tagName)) {
            $errors[] = sprintf('Invalid tag name: "%s"', $tagName);
        }
        
        // Check for reserved keywords used as tag names
        $parsed = $this->parseNamespacedIdentifier($tagName);
        if ($parsed['namespace'] === null && $this->isReservedKeyword($tagName)) {
            // Control structures are allowed
            if (!in_array($tagName, self::KEYWORDS_CONTROL, true)) {
                $errors[] = sprintf('Cannot use reserved keyword "%s" as tag name', $tagName);
            }
        }
        
        // Validate attributes if present
        if (isset($tag['attrs']) && is_array($tag['attrs'])) {
            $propErrors = $this->validateComponentProps($tagName, $tag['attrs']);
            $errors = array_merge($errors, $propErrors);
        }
        
        // Validate self-closing vs block tags
        if (isset($tag['selfClosing']) && $tag['selfClosing'] === true) {
            // Self-closing tags should not have children
            if (isset($tag['children']) && !empty($tag['children'])) {
                $errors[] = sprintf('Self-closing tag "%s" cannot have children', $tagName);
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate template structure
     * 
     * @param array $ast Full AST
     * @return array Validation errors
     */
    public function validateStructure(array $ast): array
    {
        $errors = [];
        
        // Must be a document node
        if (!isset($ast['type']) || $ast['type'] !== 'document') {
            $errors[] = 'AST root must be a document node';
            return $errors;
        }
        
        // Track open tags for matching
        $tagStack = [];
        
        // Validate children recursively
        if (isset($ast['children'])) {
            $errors = array_merge($errors, $this->validateNodes($ast['children'], $tagStack));
        }
        
        // Check for unclosed tags
        if (!empty($tagStack)) {
            foreach ($tagStack as $unclosed) {
                $errors[] = sprintf('Unclosed tag: "%s" at line %d', 
                    $unclosed['name'], $unclosed['line'] ?? 0);
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate array of nodes recursively
     */
    private function validateNodes(array $nodes, array &$tagStack): array
    {
        $errors = [];
        
        foreach ($nodes as $node) {
            if (!isset($node['type'])) {
                continue;
            }
            
            switch ($node['type']) {
                case 'tag':
                    $errors = array_merge($errors, $this->validateTag($node));
                    
                    // Recurse into children
                    if (isset($node['children'])) {
                        $errors = array_merge($errors, $this->validateNodes($node['children'], $tagStack));
                    }
                    break;
                    
                case 'expression':
                    if (isset($node['value'])) {
                        $exprErrors = $this->validateExpression('{' . $node['value'] . '}');
                        $errors = array_merge($errors, $exprErrors);
                    }
                    break;
                    
                case 'text':
                    // Text nodes are always valid
                    break;
                    
                case 'comment':
                    // Comments are always valid
                    break;
            }
        }
        
        return $errors;
    }
    
    // =========================================================================
    // FILTER REGISTRY & VALIDATION
    // =========================================================================
    
    /** @var array Registered filters with their schemas */
    private static array $filters = [];
    
    /** @var bool Whether core filters are registered */
    private static bool $filtersInitialized = false;
    
    /**
     * Register a filter
     * 
     * @param string $name Filter name
     * @param array $definition Filter definition
     */
    public static function registerFilter(string $name, array $definition): void
    {
        self::$filters[$name] = array_merge([
            'name' => $name,
            'description' => '',
            'params' => [],
            'returnType' => self::TYPE_STRING,
            'platforms' => [self::PLATFORM_UNIVERSAL],
        ], $definition);
    }
    
    /**
     * Check if filter exists
     */
    public static function hasFilter(string $name): bool
    {
        self::initializeFilters();
        return isset(self::$filters[$name]);
    }
    
    /**
     * Get filter definition
     */
    public static function getFilter(string $name): ?array
    {
        self::initializeFilters();
        return self::$filters[$name] ?? null;
    }
    
    /**
     * Initialize core filters
     */
    private static function initializeFilters(): void
    {
        if (self::$filtersInitialized) {
            return;
        }
        
        // Security filters
        self::registerFilter('esc_html', [
            'description' => 'Escape HTML entities',
            'params' => [],
            'returnType' => self::TYPE_STRING,
            'platforms' => [self::PLATFORM_UNIVERSAL],
        ]);
        
        self::registerFilter('esc_url', [
            'description' => 'Escape and validate URL',
            'params' => [],
            'returnType' => self::TYPE_URL,
            'platforms' => [self::PLATFORM_UNIVERSAL],
        ]);
        
        self::registerFilter('esc_attr', [
            'description' => 'Escape HTML attribute',
            'params' => [],
            'returnType' => self::TYPE_STRING,
            'platforms' => [self::PLATFORM_UNIVERSAL],
        ]);
        
        self::registerFilter('strip_tags', [
            'description' => 'Remove HTML tags',
            'params' => [
                'allowed' => ['type' => self::TYPE_STRING, 'required' => false],
            ],
            'returnType' => self::TYPE_STRING,
            'platforms' => [self::PLATFORM_UNIVERSAL],
        ]);
        
        // Text manipulation
        self::registerFilter('upper', [
            'description' => 'Convert to uppercase',
            'params' => [],
            'returnType' => self::TYPE_STRING,
        ]);
        
        self::registerFilter('lower', [
            'description' => 'Convert to lowercase',
            'params' => [],
            'returnType' => self::TYPE_STRING,
        ]);
        
        self::registerFilter('capitalize', [
            'description' => 'Capitalize first letter',
            'params' => [],
            'returnType' => self::TYPE_STRING,
        ]);
        
        self::registerFilter('truncate', [
            'description' => 'Truncate text to length',
            'params' => [
                'length' => ['type' => self::TYPE_INTEGER, 'required' => true, 'min' => 1],
                'append' => ['type' => self::TYPE_STRING, 'required' => false, 'default' => '...'],
            ],
            'returnType' => self::TYPE_STRING,
        ]);
        
        self::registerFilter('trim', [
            'description' => 'Trim whitespace',
            'params' => [],
            'returnType' => self::TYPE_STRING,
        ]);
        
        // Date formatting
        self::registerFilter('date', [
            'description' => 'Format date',
            'params' => [
                'format' => ['type' => self::TYPE_STRING, 'required' => true],
            ],
            'returnType' => self::TYPE_STRING,
        ]);
        
        // Number formatting
        self::registerFilter('number_format', [
            'description' => 'Format number',
            'params' => [
                'decimals' => ['type' => self::TYPE_INTEGER, 'required' => false, 'default' => 0],
                'dec_point' => ['type' => self::TYPE_STRING, 'required' => false, 'default' => '.'],
                'thousands_sep' => ['type' => self::TYPE_STRING, 'required' => false, 'default' => ','],
            ],
            'returnType' => self::TYPE_STRING,
        ]);
        
        // Logic
        self::registerFilter('default', [
            'description' => 'Default value if empty',
            'params' => [
                'value' => ['type' => self::TYPE_ANY, 'required' => true],
            ],
            'returnType' => self::TYPE_ANY,
        ]);
        
        // JSON
        self::registerFilter('json', [
            'description' => 'JSON encode',
            'params' => [],
            'returnType' => self::TYPE_STRING,
        ]);
        
        // WordPress-specific
        self::registerFilter('wp_trim_words', [
            'description' => 'Trim to word count (WordPress)',
            'params' => [
                'num_words' => ['type' => self::TYPE_INTEGER, 'required' => true, 'min' => 1],
                'more' => ['type' => self::TYPE_STRING, 'required' => false, 'default' => '...'],
            ],
            'returnType' => self::TYPE_STRING,
            'platforms' => [self::PLATFORM_WORDPRESS],
        ]);
        
        self::registerFilter('wp_kses_post', [
            'description' => 'Sanitize allowing safe HTML (WordPress)',
            'params' => [],
            'returnType' => self::TYPE_HTML,
            'platforms' => [self::PLATFORM_WORDPRESS],
        ]);
        
        // Drupal-specific
        self::registerFilter('t', [
            'description' => 'Translation (Drupal)',
            'params' => [
                'args' => ['type' => self::TYPE_ARRAY, 'required' => false],
            ],
            'returnType' => self::TYPE_STRING,
            'platforms' => [self::PLATFORM_DRUPAL],
        ]);
        
        self::$filtersInitialized = true;
    }
    
    /**
     * Validate a complete filter chain
     * 
     * @param string $expression Expression with filters
     * @param string|null $platform Target platform for compatibility check
     * @param string|null $inputType Initial input type for type chain validation
     * @return array Validation errors
     */
    public function validateFilterChain(string $expression, ?string $platform = null, ?string $inputType = null): array
    {
        $errors = [];
        $filters = $this->parseFilterChain($expression);
        $currentType = $inputType ?? self::TYPE_ANY;
        
        foreach ($filters as $index => $filter) {
            $filterName = $filter['name'];
            
            // Check if filter exists
            if (!self::hasFilter($filterName)) {
                $errors[] = sprintf('Unknown filter: "%s"', $filterName);
                continue;
            }
            
            $filterDef = self::getFilter($filterName);
            
            // Check platform compatibility
            if ($platform !== null && $platform !== self::PLATFORM_UNIVERSAL) {
                $supportedPlatforms = $filterDef['platforms'] ?? [self::PLATFORM_UNIVERSAL];
                if (!in_array(self::PLATFORM_UNIVERSAL, $supportedPlatforms, true) &&
                    !in_array($platform, $supportedPlatforms, true)) {
                    $errors[] = sprintf(
                        'Filter "%s" is not available on platform "%s"',
                        $filterName,
                        $platform
                    );
                }
            }
            
            // Type chain validation: check if current type is compatible with filter input
            if ($currentType !== self::TYPE_ANY && isset($filterDef['inputType'])) {
                $expectedInput = $filterDef['inputType'];
                if ($expectedInput !== self::TYPE_ANY && !$this->isTypeCompatible($currentType, $expectedInput)) {
                    $errors[] = sprintf(
                        'Filter "%s" expects input type "%s" but received "%s" from previous filter',
                        $filterName,
                        $expectedInput,
                        $currentType
                    );
                }
            }
            
            // Update current type for next filter in chain
            $currentType = $filterDef['returnType'] ?? self::TYPE_ANY;
            
            // Validate filter arguments (positional and named)
            $args = $filter['args'];
            $params = $filterDef['params'] ?? [];
            $paramNames = array_keys($params);
            $positionalIndex = 0;
            
            foreach ($params as $paramName => $paramSchema) {
                // Try named argument first, then positional
                $value = $args[$paramName] ?? $args[$positionalIndex] ?? null;
                
                // Check required params
                if (isset($paramSchema['required']) && $paramSchema['required'] === true) {
                    if ($value === null) {
                        $errors[] = sprintf(
                            'Filter "%s" requires parameter "%s"',
                            $filterName,
                            $paramName
                        );
                    }
                }
                
                // Validate param value if provided
                if ($value !== null && !$this->validate($value, $paramSchema)) {
                    $errors[] = sprintf(
                        'Invalid value for filter "%s" parameter "%s"',
                        $filterName,
                        $paramName
                    );
                }
                
                $positionalIndex++;
            }
        }
        
        return $errors;
    }
    
    /**
     * Check if two types are compatible
     */
    private function isTypeCompatible(string $sourceType, string $targetType): bool
    {
        // ANY accepts anything
        if ($targetType === self::TYPE_ANY || $sourceType === self::TYPE_ANY) {
            return true;
        }
        
        // Exact match
        if ($sourceType === $targetType) {
            return true;
        }
        
        // String-like types are compatible
        $stringLike = [self::TYPE_STRING, self::TYPE_HTML, self::TYPE_MARKDOWN, self::TYPE_URL, self::TYPE_EMAIL];
        if (in_array($sourceType, $stringLike, true) && in_array($targetType, $stringLike, true)) {
            return true;
        }
        
        // Number types are compatible
        $numberLike = [self::TYPE_NUMBER, self::TYPE_INTEGER, self::TYPE_FLOAT];
        if (in_array($sourceType, $numberLike, true) && in_array($targetType, $numberLike, true)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Validate filter chain with rich error objects
     * 
     * @param string $expression Expression with filters
     * @param string|null $platform Target platform
     * @param string|null $inputType Initial input type
     * @return ValidationResult
     */
    public function validateFilterChainRich(string $expression, ?string $platform = null, ?string $inputType = null): ValidationResult
    {
        $result = new ValidationResult();
        $filters = $this->parseFilterChain($expression);
        $currentType = $inputType ?? self::TYPE_ANY;
        
        foreach ($filters as $index => $filter) {
            $filterName = $filter['name'];
            
            if (!self::hasFilter($filterName)) {
                $result->addError(new ValidationError(
                    sprintf('Unknown filter: "%s"', $filterName),
                    'UNKNOWN_FILTER',
                    'filter',
                    $filterName
                ));
                continue;
            }
            
            $filterDef = self::getFilter($filterName);
            
            // Platform check
            if ($platform !== null && $platform !== self::PLATFORM_UNIVERSAL) {
                $supportedPlatforms = $filterDef['platforms'] ?? [self::PLATFORM_UNIVERSAL];
                if (!in_array(self::PLATFORM_UNIVERSAL, $supportedPlatforms, true) &&
                    !in_array($platform, $supportedPlatforms, true)) {
                    $result->addError(new ValidationError(
                        sprintf('Filter "%s" is not available on platform "%s"', $filterName, $platform),
                        'PLATFORM_INCOMPATIBLE',
                        'filter',
                        $filterName,
                        severity: $this->isStrict() ? 'error' : 'warning'
                    ));
                }
            }
            
            // Type chain validation
            if ($currentType !== self::TYPE_ANY && isset($filterDef['inputType'])) {
                $expectedInput = $filterDef['inputType'];
                if ($expectedInput !== self::TYPE_ANY && !$this->isTypeCompatible($currentType, $expectedInput)) {
                    $result->addError(new ValidationError(
                        sprintf('Filter "%s" expects type "%s" but received "%s"', $filterName, $expectedInput, $currentType),
                        'TYPE_MISMATCH',
                        'filter',
                        $filterName,
                        severity: $this->isStrict() ? 'error' : 'warning'
                    ));
                }
            }
            
            $currentType = $filterDef['returnType'] ?? self::TYPE_ANY;
        }
        
        return $result;
    }
    
    // =========================================================================
    // EXPRESSION PARSING (AST-level)
    // =========================================================================
    
    /**
     * Parse expression into components (with caching)
     * 
     * @param string $expression Expression string (with or without braces)
     * @param bool $useCache Whether to use cache (default: true)
     * @return array Parsed expression structure
     */
    public function parseExpression(string $expression, bool $useCache = true): array
    {
        // Check cache first
        $cacheKey = self::SCHEMA_VERSION . ':' . $expression;
        if ($useCache && isset(self::$expressionCache[$cacheKey])) {
            return self::$expressionCache[$cacheKey];
        }
        
        // Remove braces if present
        $expr = trim($expression);
        if (str_starts_with($expr, '{') && str_ends_with($expr, '}')) {
            $expr = substr($expr, 1, -1);
        }
        $expr = trim($expr);
        
        $result = [
            'raw' => $expression,
            'variable' => null,
            'path' => [],
            'filters' => [],
            'errors' => [],
            'hasEscaping' => false, // Security flag
        ];
        
        // Split by pipe to separate variable from filters
        $parts = preg_split('/\s*\|\s*/', $expr, 2);
        $variablePart = trim($parts[0]);
        $filterPart = $parts[1] ?? null;
        
        // Parse variable path (e.g., "user.profile.name" or "items[0].title")
        $result['path'] = $this->parseVariablePath($variablePart);
        $result['variable'] = $result['path'][0]['name'] ?? null;
        
        // Parse filters if present
        if ($filterPart !== null) {
            $result['filters'] = $this->parseFilterChain('|' . $filterPart);
            
            // Check for escaping filters (security)
            $escapingFilters = ['esc_html', 'esc_attr', 'esc_url', 'strip_tags', 'wp_kses_post'];
            foreach ($result['filters'] as $filter) {
                if (in_array($filter['name'], $escapingFilters, true)) {
                    $result['hasEscaping'] = true;
                    break;
                }
            }
        }
        
        // Cache result (with size limit)
        if ($useCache) {
            if (count(self::$expressionCache) >= self::MAX_CACHE_SIZE) {
                // Evict oldest entries (simple FIFO)
                self::$expressionCache = array_slice(self::$expressionCache, (int)(self::MAX_CACHE_SIZE / 2), null, true);
            }
            self::$expressionCache[$cacheKey] = $result;
        }
        
        return $result;
    }
    
    /**
     * Parse variable path with dot notation and array access
     * 
     * @param string $path Variable path (e.g., "user.profile.name", "items[0].title")
     * @return array Path segments
     */
    public function parseVariablePath(string $path): array
    {
        $segments = [];
        $current = '';
        $inBracket = false;
        
        for ($i = 0; $i < strlen($path); $i++) {
            $char = $path[$i];
            
            if ($char === '[') {
                if ($current !== '') {
                    $segments[] = ['type' => 'property', 'name' => $current];
                    $current = '';
                }
                $inBracket = true;
            } elseif ($char === ']') {
                if ($inBracket) {
                    // Array access - could be index or key
                    $index = trim($current, '"\'');
                    $segments[] = [
                        'type' => is_numeric($index) ? 'index' : 'key',
                        'value' => is_numeric($index) ? (int)$index : $index,
                    ];
                    $current = '';
                    $inBracket = false;
                }
            } elseif ($char === '.' && !$inBracket) {
                if ($current !== '') {
                    $segments[] = ['type' => 'property', 'name' => $current];
                    $current = '';
                }
            } elseif ($char === '?' && isset($path[$i + 1]) && $path[$i + 1] === '.') {
                // Safe navigation operator (?.)
                if ($current !== '') {
                    $segments[] = ['type' => 'property', 'name' => $current, 'safe' => true];
                    $current = '';
                }
                $i++; // Skip the dot
            } else {
                $current .= $char;
            }
        }
        
        // Add final segment
        if ($current !== '') {
            $segments[] = ['type' => 'property', 'name' => $current];
        }
        
        return $segments;
    }
    
    /**
     * Validate parsed expression
     * 
     * @param array $parsedExpr Parsed expression from parseExpression()
     * @param string|null $platform Target platform
     * @return array Validation errors
     */
    public function validateParsedExpression(array $parsedExpr, ?string $platform = null): array
    {
        $errors = [];
        
        // Must have a variable
        if (empty($parsedExpr['variable'])) {
            $errors[] = 'Expression must reference a variable';
        }
        
        // Validate variable name
        if ($parsedExpr['variable'] && !$this->validateIdentifier($parsedExpr['variable'])) {
            $errors[] = sprintf('Invalid variable name: "%s"', $parsedExpr['variable']);
        }
        
        // Validate each path segment
        foreach ($parsedExpr['path'] as $segment) {
            if ($segment['type'] === 'property') {
                if (!$this->validateIdentifier($segment['name'])) {
                    $errors[] = sprintf('Invalid property name: "%s"', $segment['name']);
                }
            }
        }
        
        // Validate filters
        if (!empty($parsedExpr['filters'])) {
            $filterExpr = '|' . implode('|', array_map(fn($f) => $f['name'], $parsedExpr['filters']));
            $filterErrors = $this->validateFilterChain($filterExpr, $platform);
            $errors = array_merge($errors, $filterErrors);
        }
        
        return $errors;
    }
    
    // =========================================================================
    // SECURITY VALIDATION
    // =========================================================================
    
    /** @var array Types that require explicit escaping */
    private const UNSAFE_OUTPUT_TYPES = [self::TYPE_HTML, self::TYPE_STRING, self::TYPE_ANY];
    
    /** @var array Escaping filters */
    private const ESCAPING_FILTERS = ['esc_html', 'esc_attr', 'esc_url', 'strip_tags', 'wp_kses_post', 'htmlspecialchars'];
    
    /**
     * Check if expression has proper escaping for output context
     * 
     * @param string $expression Expression to check
     * @param string $context Output context ('html', 'attr', 'url', 'js')
     * @return ValidationResult
     */
    public function validateSecureOutput(string $expression, string $context = 'html'): ValidationResult
    {
        $result = new ValidationResult();
        $parsed = $this->parseExpression($expression);
        
        // Check if expression has escaping
        if (!$parsed['hasEscaping']) {
            $requiredFilter = match($context) {
                'html' => 'esc_html',
                'attr' => 'esc_attr',
                'url' => 'esc_url',
                'js' => 'json',
                default => 'esc_html',
            };
            
            $result->addError(new ValidationError(
                sprintf('Expression "%s" should use %s filter for %s context', 
                    $expression, $requiredFilter, $context),
                'MISSING_ESCAPING',
                'expression',
                null,
                severity: $this->isStrict() ? 'error' : 'warning'
            ));
        }
        
        return $result;
    }
    
    /**
     * Validate TYPE_HTML prop has explicit safe marking
     * 
     * @param string $propName Property name
     * @param array $schema Property schema
     * @param mixed $value Property value
     * @return ValidationResult
     */
    public function validateHtmlProp(string $propName, array $schema, mixed $value): ValidationResult
    {
        $result = new ValidationResult();
        
        $type = $schema['type'] ?? self::TYPE_STRING;
        
        if ($type === self::TYPE_HTML) {
            // Check if explicitly marked safe
            if (!isset($schema['safe']) || $schema['safe'] !== true) {
                $result->addError(new ValidationError(
                    sprintf('HTML prop "%s" should be marked as safe: true or use wp_kses_post filter', $propName),
                    'UNSAFE_HTML_PROP',
                    'prop',
                    $propName,
                    severity: 'warning'
                ));
            }
        }
        
        return $result;
    }
    
    // =========================================================================
    // RICH VALIDATION API (for Visual Builder & IDE integration)
    // =========================================================================
    
    /**
     * Validate full template with rich error reporting
     * 
     * @param array $ast Full AST
     * @param string|null $platform Target platform
     * @return ValidationResult
     */
    public function validateTemplateRich(array $ast, ?string $platform = null): ValidationResult
    {
        $result = new ValidationResult();
        
        // Validate structure
        $structureErrors = $this->validateStructure($ast);
        foreach ($structureErrors as $error) {
            $result->addError(new ValidationError(
                $error,
                'STRUCTURE_ERROR',
                'document'
            ));
        }
        
        // Validate nodes recursively with rich errors
        if (isset($ast['children'])) {
            $this->validateNodesRich($ast['children'], $result, $platform);
        }
        
        return $result;
    }
    
    /**
     * Validate nodes with rich error reporting
     */
    private function validateNodesRich(array $nodes, ValidationResult $result, ?string $platform): void
    {
        foreach ($nodes as $node) {
            if (!isset($node['type'])) {
                continue;
            }
            
            $line = $node['line'] ?? null;
            $column = $node['column'] ?? null;
            
            switch ($node['type']) {
                case 'tag':
                    $tagName = $node['name'] ?? 'unknown';
                    
                    // Validate tag
                    $tagErrors = $this->validateTag($node);
                    foreach ($tagErrors as $error) {
                        $result->addError(new ValidationError(
                            $error,
                            'TAG_ERROR',
                            'tag',
                            $tagName,
                            $line,
                            $column
                        ));
                    }
                    
                    // Check platform compatibility
                    if ($platform && !$this->isComponentCompatible($tagName, $platform)) {
                        $result->addError(new ValidationError(
                            sprintf('Component "%s" is not compatible with platform "%s"', $tagName, $platform),
                            'PLATFORM_INCOMPATIBLE',
                            'tag',
                            $tagName,
                            $line,
                            $column,
                            severity: $this->isStrict() ? 'error' : 'warning'
                        ));
                    }
                    
                    // Recurse into children
                    if (isset($node['children'])) {
                        $this->validateNodesRich($node['children'], $result, $platform);
                    }
                    break;
                    
                case 'expression':
                    $exprValue = $node['value'] ?? '';
                    $parsed = $this->parseExpression('{' . $exprValue . '}');
                    
                    // Validate expression
                    $exprErrors = $this->validateParsedExpression($parsed, $platform);
                    foreach ($exprErrors as $error) {
                        $result->addError(new ValidationError(
                            $error,
                            'EXPRESSION_ERROR',
                            'expression',
                            null,
                            $line,
                            $column,
                            $exprValue
                        ));
                    }
                    
                    // Security warning for unescaped output
                    if (!$parsed['hasEscaping'] && $this->isStrict()) {
                        $result->addError(new ValidationError(
                            sprintf('Expression "%s" has no escaping filter', $exprValue),
                            'MISSING_ESCAPING',
                            'expression',
                            null,
                            $line,
                            $column,
                            $exprValue,
                            'warning'
                        ));
                    }
                    break;
            }
        }
    }
    
    /**
     * Validate component props with rich error reporting
     * 
     * @param string $componentName Component name
     * @param array $attributes Provided attributes
     * @param int|null $line Line number
     * @param int|null $column Column number
     * @return ValidationResult
     */
    public function validateComponentPropsRich(
        string $componentName, 
        array $attributes, 
        ?int $line = null, 
        ?int $column = null
    ): ValidationResult {
        $result = new ValidationResult();
        
        $errors = $this->validateComponentProps($componentName, $attributes);
        foreach ($errors as $error) {
            $result->addError(new ValidationError(
                $error,
                'PROP_ERROR',
                'tag',
                $componentName,
                $line,
                $column
            ));
        }
        
        // Check for HTML props without safe marking
        $schema = $this->getComponentSchema($componentName);
        foreach ($schema as $propName => $propSchema) {
            if (isset($attributes[$propName])) {
                $htmlResult = $this->validateHtmlProp($propName, $propSchema, $attributes[$propName]);
                $result->merge($htmlResult);
            }
        }
        
        return $result;
    }
    
    /**
     * Get component schema (cached)
     */
    private function getComponentSchema(string $componentName): array
    {
        if (isset(self::$componentSchemaCache[$componentName])) {
            return self::$componentSchemaCache[$componentName];
        }
        
        $schema = ComponentRegistry::getAttributeSchemas($componentName);
        
        // Cache with size limit
        if (count(self::$componentSchemaCache) >= self::MAX_CACHE_SIZE) {
            self::$componentSchemaCache = array_slice(self::$componentSchemaCache, (int)(self::MAX_CACHE_SIZE / 2), null, true);
        }
        self::$componentSchemaCache[$componentName] = $schema;
        
        return $schema;
    }
    
    // =========================================================================
    // VISUAL BUILDER API
    // =========================================================================
    
    /**
     * Get all available components for Visual Builder
     * 
     * @param string|null $platform Filter by platform
     * @param string|null $category Filter by category
     * @return array Component list with metadata
     */
    public static function getAvailableComponents(?string $platform = null, ?string $category = null): array
    {
        $components = ComponentRegistry::all();
        $result = [];
        
        foreach ($components as $name => $def) {
            // Filter by category
            if ($category !== null && ($def['category'] ?? '') !== $category) {
                continue;
            }
            
            // Filter by platform (if component has platform restrictions)
            if ($platform !== null && isset($def['platforms'])) {
                if (!in_array(self::PLATFORM_UNIVERSAL, $def['platforms'], true) &&
                    !in_array($platform, $def['platforms'], true)) {
                    continue;
                }
            }
            
            $result[] = [
                'name' => $name,
                'category' => $def['category'] ?? 'ui',
                'description' => $def['description'] ?? '',
                'props' => array_map(fn($schema, $propName) => [
                    'name' => $propName,
                    'type' => $schema['type'] ?? 'string',
                    'required' => $schema['required'] ?? false,
                    'default' => $schema['default'] ?? null,
                    'enum' => $schema['enum'] ?? null,
                    'description' => $schema['description'] ?? '',
                ], $def['attributes'] ?? [], array_keys($def['attributes'] ?? [])),
                'leaf' => $def['leaf'] ?? false,
                'slots' => $def['slots'] ?? [],
            ];
        }
        
        return $result;
    }
    
    /**
     * Get all available filters for Visual Builder
     * 
     * @param string|null $platform Filter by platform
     * @return array Filter list with metadata
     */
    public static function getAvailableFilters(?string $platform = null): array
    {
        self::initializeFilters();
        $result = [];
        
        foreach (self::$filters as $name => $def) {
            // Filter by platform
            if ($platform !== null) {
                $supportedPlatforms = $def['platforms'] ?? [self::PLATFORM_UNIVERSAL];
                if (!in_array(self::PLATFORM_UNIVERSAL, $supportedPlatforms, true) &&
                    !in_array($platform, $supportedPlatforms, true)) {
                    continue;
                }
            }
            
            $result[] = [
                'name' => $name,
                'description' => $def['description'] ?? '',
                'params' => array_map(fn($schema, $paramName) => [
                    'name' => $paramName,
                    'type' => $schema['type'] ?? 'string',
                    'required' => $schema['required'] ?? false,
                    'default' => $schema['default'] ?? null,
                ], $def['params'] ?? [], array_keys($def['params'] ?? [])),
                'returnType' => $def['returnType'] ?? 'string',
                'platforms' => $def['platforms'] ?? [self::PLATFORM_UNIVERSAL],
            ];
        }
        
        return $result;
    }
    
    /**
     * Export JSON Schema for Visual Builder integration
     * 
     * @return array JSON Schema compatible structure
     */
    public static function exportJsonSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'DiSyL Component Schema',
            'version' => self::SCHEMA_VERSION,
            'components' => self::getAvailableComponents(),
            'filters' => self::getAvailableFilters(),
            'platforms' => self::VALID_PLATFORMS,
            'platformCategories' => self::PLATFORM_CATEGORIES,
            'types' => [
                'primitive' => [self::TYPE_STRING, self::TYPE_NUMBER, self::TYPE_INTEGER, self::TYPE_FLOAT, self::TYPE_BOOLEAN, self::TYPE_NULL],
                'complex' => [self::TYPE_ARRAY, self::TYPE_OBJECT],
                'extended' => [self::TYPE_URL, self::TYPE_IMAGE, self::TYPE_COLOR, self::TYPE_DATE, self::TYPE_DATETIME, self::TYPE_EMAIL, self::TYPE_PHONE, self::TYPE_HTML, self::TYPE_MARKDOWN, self::TYPE_JSON, self::TYPE_EXPRESSION],
            ],
        ];
    }
}
