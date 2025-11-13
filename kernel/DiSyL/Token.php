<?php
/**
 * DiSyL Token
 * 
 * Represents a single token in the DiSyL lexical analysis
 * 
 * @version 0.1.0
 */

namespace IkabudKernel\Core\DiSyL;

class Token
{
    // Token types
    public const LBRACE = 'LBRACE';           // {
    public const RBRACE = 'RBRACE';           // }
    public const SLASH = 'SLASH';             // /
    public const IDENT = 'IDENT';             // tag names, attribute names
    public const EQUAL = 'EQUAL';             // =
    public const STRING = 'STRING';           // "value"
    public const NUMBER = 'NUMBER';           // 123, 3.14
    public const BOOL = 'BOOL';               // true, false
    public const NULL = 'NULL';               // null
    public const TEXT = 'TEXT';               // raw text outside tags
    public const COMMENT = 'COMMENT';         // {!-- comment --}
    public const EOF = 'EOF';                 // end of file
    
    public string $type;
    public mixed $value;
    public int $line;
    public int $column;
    public int $position;
    
    /**
     * Constructor
     */
    public function __construct(
        string $type,
        mixed $value,
        int $line = 1,
        int $column = 1,
        int $position = 0
    ) {
        $this->type = $type;
        $this->value = $value;
        $this->line = $line;
        $this->column = $column;
        $this->position = $position;
    }
    
    /**
     * Convert token to string for debugging
     */
    public function __toString(): string
    {
        $value = is_string($this->value) ? '"' . $this->value . '"' : $this->value;
        return sprintf(
            'Token(%s, %s, line=%d, col=%d)',
            $this->type,
            $value,
            $this->line,
            $this->column
        );
    }
    
    /**
     * Convert token to array
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'value' => $this->value,
            'line' => $this->line,
            'column' => $this->column,
            'position' => $this->position
        ];
    }
}
