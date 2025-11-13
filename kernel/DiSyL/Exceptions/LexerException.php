<?php
/**
 * DiSyL Lexer Exception
 * 
 * Thrown when lexical analysis encounters an error
 * 
 * @version 0.1.0
 */

namespace IkabudKernel\Core\DiSyL\Exceptions;

use Exception;

class LexerException extends Exception
{
    private int $lexerLine;
    private int $lexerColumn;
    private int $lexerPosition;
    
    /**
     * Constructor
     */
    public function __construct(
        string $message,
        int $line = 1,
        int $column = 1,
        int $position = 0
    ) {
        $this->lexerLine = $line;
        $this->lexerColumn = $column;
        $this->lexerPosition = $position;
        
        $fullMessage = sprintf(
            '%s at line %d, column %d (position %d)',
            $message,
            $line,
            $column,
            $position
        );
        
        parent::__construct($fullMessage);
    }
    
    /**
     * Get lexer line number
     */
    public function getLexerLine(): int
    {
        return $this->lexerLine;
    }
    
    /**
     * Get lexer column number
     */
    public function getLexerColumn(): int
    {
        return $this->lexerColumn;
    }
    
    /**
     * Get lexer position
     */
    public function getLexerPosition(): int
    {
        return $this->lexerPosition;
    }
}
