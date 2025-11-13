<?php
/**
 * DiSyL Compiler Exception
 * 
 * Thrown when compilation encounters an error
 * 
 * @version 0.1.0
 */

namespace IkabudKernel\Core\DiSyL\Exceptions;

use Exception;

class CompilerException extends Exception
{
    private ?string $componentName;
    private ?array $astNode;
    
    /**
     * Constructor
     */
    public function __construct(
        string $message,
        ?string $componentName = null,
        ?array $astNode = null,
        int $code = 0,
        ?Exception $previous = null
    ) {
        $this->componentName = $componentName;
        $this->astNode = $astNode;
        
        $fullMessage = $message;
        
        if ($componentName !== null) {
            $fullMessage .= sprintf(' [component: %s]', $componentName);
        }
        
        if ($astNode !== null && isset($astNode['loc'])) {
            $fullMessage .= sprintf(
                ' at line %d, column %d',
                $astNode['loc']['line'] ?? 0,
                $astNode['loc']['column'] ?? 0
            );
        }
        
        parent::__construct($fullMessage, $code, $previous);
    }
    
    /**
     * Get component name that caused the error
     */
    public function getComponentName(): ?string
    {
        return $this->componentName;
    }
    
    /**
     * Get AST node that caused the error
     */
    public function getAstNode(): ?array
    {
        return $this->astNode;
    }
}
