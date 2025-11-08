<?php
/**
 * Query Parser - Builds AST from tokens
 * 
 * Parses token stream into Abstract Syntax Tree
 * Validates syntax and structure
 * 
 * @version 1.1.0
 */

namespace IkabudKernel\DSL;

class QueryParser
{
    private array $tokens;
    private int $position = 0;
    private array $errors = [];
    
    /**
     * Parse tokens into AST
     */
    public function parse(array $tokens): array
    {
        $this->tokens = $tokens;
        $this->position = 0;
        $this->errors = [];
        
        $ast = [
            'type' => 'query',
            'attributes' => [],
            'errors' => []
        ];
        
        while ($this->position < count($this->tokens)) {
            $attribute = $this->parseAttribute();
            
            if ($attribute) {
                $ast['attributes'][$attribute['key']] = $attribute['value'];
            }
            
            // Skip commas
            if ($this->current() && $this->current()['type'] === 'operator' && $this->current()['value'] === ',') {
                $this->advance();
            }
        }
        
        $ast['errors'] = $this->errors;
        
        return $ast;
    }
    
    /**
     * Parse single attribute (key=value)
     */
    private function parseAttribute(): ?array
    {
        $token = $this->current();
        
        if (!$token || $token['type'] !== 'identifier') {
            return null;
        }
        
        $key = $token['value'];
        $this->advance();
        
        // Expect '='
        $operator = $this->current();
        if (!$operator || $operator['type'] !== 'operator' || $operator['value'] !== '=') {
            $this->errors[] = "Expected '=' after key '{$key}'";
            return null;
        }
        $this->advance();
        
        // Parse value
        $value = $this->parseValue();
        
        if ($value === null) {
            $this->errors[] = "Expected value after '{$key}='";
            return null;
        }
        
        return [
            'key' => $key,
            'value' => $value
        ];
    }
    
    /**
     * Parse value (string, number, boolean, placeholder, json)
     */
    private function parseValue(): mixed
    {
        $token = $this->current();
        
        if (!$token) {
            return null;
        }
        
        $this->advance();
        
        switch ($token['type']) {
            case 'string':
            case 'identifier':
                return $token['value'];
                
            case 'number':
                return (int) $token['value'];
                
            case 'boolean':
                return filter_var($token['value'], FILTER_VALIDATE_BOOLEAN);
                
            case 'placeholder':
                return $this->parsePlaceholder($token['value']);
                
            case 'json':
                return $this->parseJson($token['value']);
                
            default:
                $this->errors[] = "Unexpected token type: {$token['type']}";
                return null;
        }
    }
    
    /**
     * Parse placeholder {GET:var}
     */
    private function parsePlaceholder(string $value): array
    {
        // Remove braces
        $value = trim($value, '{}');
        
        // Split by colon
        $parts = explode(':', $value, 2);
        
        return [
            'type' => 'placeholder',
            'source' => $parts[0] ?? 'GET',
            'key' => $parts[1] ?? ''
        ];
    }
    
    /**
     * Parse JSON object
     */
    private function parseJson(string $value): mixed
    {
        $decoded = json_decode($value, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = "Invalid JSON: " . json_last_error_msg();
            return null;
        }
        
        return $decoded;
    }
    
    /**
     * Get current token
     */
    private function current(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }
    
    /**
     * Advance position
     */
    private function advance(): void
    {
        $this->position++;
    }
    
    /**
     * Get errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
