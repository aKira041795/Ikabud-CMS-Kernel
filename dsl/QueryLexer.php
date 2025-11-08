<?php
/**
 * Query Lexer - Tokenizes DSL query strings
 * 
 * Converts raw query string into tokens for parsing
 * Handles placeholders, JSON, quoted strings
 * 
 * @version 1.1.0
 */

namespace IkabudKernel\DSL;

class QueryLexer
{
    private string $input;
    private int $position = 0;
    private int $length;
    private array $tokens = [];
    
    /**
     * Tokenize input string
     */
    public function tokenize(string $input): array
    {
        $this->input = $input;
        $this->length = strlen($input);
        $this->position = 0;
        $this->tokens = [];
        
        while ($this->position < $this->length) {
            $this->skipWhitespace();
            
            if ($this->position >= $this->length) {
                break;
            }
            
            $char = $this->current();
            
            // Handle different token types
            if ($char === '{') {
                $this->tokenizePlaceholderOrJson();
            } elseif ($char === '"' || $char === "'") {
                $this->tokenizeQuotedString();
            } elseif ($char === '=' || $char === ',') {
                $this->tokens[] = ['type' => 'operator', 'value' => $char];
                $this->advance();
            } elseif (ctype_alpha($char) || $char === '_') {
                $this->tokenizeIdentifier();
            } elseif (ctype_digit($char)) {
                $this->tokenizeNumber();
            } else {
                $this->advance(); // Skip unknown characters
            }
        }
        
        return $this->tokens;
    }
    
    /**
     * Tokenize identifier (key names, boolean values)
     */
    private function tokenizeIdentifier(): void
    {
        $start = $this->position;
        
        while ($this->position < $this->length) {
            $char = $this->current();
            if (ctype_alnum($char) || $char === '_' || $char === '-') {
                $this->advance();
            } else {
                break;
            }
        }
        
        $value = substr($this->input, $start, $this->position - $start);
        
        // Check if it's a boolean
        if (in_array(strtolower($value), ['true', 'false', 'yes', 'no'])) {
            $this->tokens[] = ['type' => 'boolean', 'value' => $value];
        } else {
            $this->tokens[] = ['type' => 'identifier', 'value' => $value];
        }
    }
    
    /**
     * Tokenize number
     */
    private function tokenizeNumber(): void
    {
        $start = $this->position;
        
        while ($this->position < $this->length && ctype_digit($this->current())) {
            $this->advance();
        }
        
        $value = substr($this->input, $start, $this->position - $start);
        $this->tokens[] = ['type' => 'number', 'value' => $value];
    }
    
    /**
     * Tokenize quoted string
     */
    private function tokenizeQuotedString(): void
    {
        $quote = $this->current();
        $this->advance(); // Skip opening quote
        
        $start = $this->position;
        $escaped = false;
        
        while ($this->position < $this->length) {
            $char = $this->current();
            
            if ($escaped) {
                $escaped = false;
                $this->advance();
                continue;
            }
            
            if ($char === '\\') {
                $escaped = true;
                $this->advance();
                continue;
            }
            
            if ($char === $quote) {
                break;
            }
            
            $this->advance();
        }
        
        $value = substr($this->input, $start, $this->position - $start);
        $this->advance(); // Skip closing quote
        
        $this->tokens[] = ['type' => 'string', 'value' => $value];
    }
    
    /**
     * Tokenize placeholder or JSON object
     */
    private function tokenizePlaceholderOrJson(): void
    {
        $start = $this->position;
        $this->advance(); // Skip opening brace
        
        // Peek ahead to determine if it's a placeholder or JSON
        $peek = '';
        $tempPos = $this->position;
        while ($tempPos < $this->length && $this->input[$tempPos] !== ':' && $this->input[$tempPos] !== '}') {
            $peek .= $this->input[$tempPos];
            $tempPos++;
        }
        
        $peek = trim($peek);
        
        // Check if it's a placeholder (GET, POST, ENV, etc.)
        if (in_array($peek, QueryGrammar::getPlaceholderTypes())) {
            $this->tokenizePlaceholder($start);
        } else {
            $this->tokenizeJson($start);
        }
    }
    
    /**
     * Tokenize placeholder {GET:var}
     */
    private function tokenizePlaceholder(int $start): void
    {
        $depth = 1;
        
        while ($this->position < $this->length && $depth > 0) {
            $char = $this->current();
            
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }
            
            $this->advance();
        }
        
        $value = substr($this->input, $start, $this->position - $start);
        $this->tokens[] = ['type' => 'placeholder', 'value' => $value];
    }
    
    /**
     * Tokenize JSON object
     */
    private function tokenizeJson(int $start): void
    {
        $depth = 1;
        
        while ($this->position < $this->length && $depth > 0) {
            $char = $this->current();
            
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }
            
            $this->advance();
        }
        
        $value = substr($this->input, $start, $this->position - $start);
        $this->tokens[] = ['type' => 'json', 'value' => $value];
    }
    
    /**
     * Skip whitespace
     */
    private function skipWhitespace(): void
    {
        while ($this->position < $this->length && ctype_space($this->current())) {
            $this->advance();
        }
    }
    
    /**
     * Get current character
     */
    private function current(): string
    {
        return $this->input[$this->position] ?? '';
    }
    
    /**
     * Advance position
     */
    private function advance(): void
    {
        $this->position++;
    }
}
