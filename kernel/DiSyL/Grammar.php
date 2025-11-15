<?php
/**
 * DiSyL Grammar
 * 
 * Defines validation rules and parameter schemas for DiSyL v0.2
 * 
 * Features:
 * - Filter pipeline validation
 * - Named and positional argument validation
 * - Control structure attribute validation
 * - Unicode character support
 * 
 * @version 0.3.0
 */

namespace IkabudKernel\Core\DiSyL;

class Grammar
{
    /**
     * Parameter type definitions
     */
    public const TYPE_STRING = 'string';
    public const TYPE_NUMBER = 'number';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_FLOAT = 'float';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_NULL = 'null';
    public const TYPE_ARRAY = 'array';
    public const TYPE_OBJECT = 'object';
    public const TYPE_ANY = 'any';
    
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
     * Validate value type
     */
    private function validateType(mixed $value, string $type): bool
    {
        return match($type) {
            self::TYPE_STRING => is_string($value),
            self::TYPE_NUMBER => is_numeric($value),
            self::TYPE_INTEGER => is_int($value),
            self::TYPE_FLOAT => is_float($value),
            self::TYPE_BOOLEAN => is_bool($value),
            self::TYPE_NULL => $value === null,
            self::TYPE_ARRAY => is_array($value),
            self::TYPE_OBJECT => is_object($value),
            self::TYPE_ANY => true,
            default => false
        };
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
}
