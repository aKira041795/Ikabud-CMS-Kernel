<?php
/**
 * DiSyL Parser Exception
 * 
 * Thrown when parsing encounters an error
 * 
 * @version 0.1.0
 */

namespace IkabudKernel\Core\DiSyL\Exceptions;

use Exception;

class ParserException extends Exception
{
    private int $parserLine;
    private int $parserColumn;
    private int $parserPosition;
    private ?string $tokenType;
    
    /**
     * Constructor
     */
    public function __construct(
        string $message,
        int $line = 1,
        int $column = 1,
        int $position = 0,
        ?string $tokenType = null
    ) {
        $this->parserLine = $line;
        $this->parserColumn = $column;
        $this->parserPosition = $position;
        $this->tokenType = $tokenType;
        
        $fullMessage = sprintf(
            '%s at line %d, column %d (position %d)',
            $message,
            $line,
            $column,
            $position
        );
        
        if ($tokenType !== null) {
            $fullMessage .= sprintf(' [token: %s]', $tokenType);
        }
        
        parent::__construct($fullMessage);
    }
    
    /**
     * Get parser line number
     */
    public function getParserLine(): int
    {
        return $this->parserLine;
    }
    
    /**
     * Get parser column number
     */
    public function getParserColumn(): int
    {
        return $this->parserColumn;
    }
    
    /**
     * Get parser position
     */
    public function getParserPosition(): int
    {
        return $this->parserPosition;
    }
    
    /**
     * Get token type that caused the error
     */
    public function getTokenType(): ?string
    {
        return $this->tokenType;
    }
}
