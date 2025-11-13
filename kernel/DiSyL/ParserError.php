<?php
/**
 * DiSyL Parser Error
 * 
 * Represents a parsing error with helpful context and suggestions
 * 
 * @version 0.1.0
 */

namespace IkabudKernel\Core\DiSyL;

class ParserError
{
    public string $message;
    public int $line;
    public int $column;
    public string $severity; // 'error', 'warning'
    public ?string $suggestion;
    public ?string $code; // Error code for programmatic handling
    
    public function __construct(
        string $message,
        int $line,
        int $column,
        string $severity = 'error',
        ?string $suggestion = null,
        ?string $code = null
    ) {
        $this->message = $message;
        $this->line = $line;
        $this->column = $column;
        $this->severity = $severity;
        $this->suggestion = $suggestion;
        $this->code = $code;
    }
    
    /**
     * Format error for display
     */
    public function format(): string
    {
        $output = sprintf(
            "[%s] Line %d, Col %d: %s",
            strtoupper($this->severity),
            $this->line,
            $this->column,
            $this->message
        );
        
        if ($this->suggestion) {
            $output .= "\n  Suggestion: " . $this->suggestion;
        }
        
        if ($this->code) {
            $output .= sprintf(" [%s]", $this->code);
        }
        
        return $output;
    }
    
    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'line' => $this->line,
            'column' => $this->column,
            'severity' => $this->severity,
            'suggestion' => $this->suggestion,
            'code' => $this->code
        ];
    }
    
    /**
     * Create error for missing closing tag
     */
    public static function missingClosingTag(string $tagName, int $line, int $column): self
    {
        return new self(
            "Missing closing tag for {$tagName}",
            $line,
            $column,
            'error',
            "Add {/{$tagName}} before the end of file or parent closing tag",
            'E001'
        );
    }
    
    /**
     * Create error for mismatched closing tag
     */
    public static function mismatchedClosingTag(string $expected, string $got, int $line, int $column): self
    {
        return new self(
            "Mismatched closing tag",
            $line,
            $column,
            'error',
            "Expected {/{$expected}}, got {/{$got}}",
            'E002'
        );
    }
    
    /**
     * Create error for non-self-closing void component
     */
    public static function voidComponentNotSelfClosing(string $tagName, int $line, int $column): self
    {
        return new self(
            "{$tagName} must be self-closing",
            $line,
            $column,
            'error',
            "Use {$tagName} ... /} instead of {$tagName} ... }",
            'E003'
        );
    }
    
    /**
     * Create error for invalid attribute syntax
     */
    public static function invalidAttributeSyntax(string $attrName, int $line, int $column): self
    {
        return new self(
            "Invalid attribute syntax for '{$attrName}'",
            $line,
            $column,
            'error',
            "Attribute values must be quoted: {$attrName}=\"value\"",
            'E004'
        );
    }
    
    /**
     * Create error for unclosed expression
     */
    public static function unclosedExpression(int $line, int $column): self
    {
        return new self(
            "Unclosed expression",
            $line,
            $column,
            'error',
            "Expected } to close expression",
            'E005'
        );
    }
    
    /**
     * Create error for unexpected token
     */
    public static function unexpectedToken(string $tokenType, string $expected, int $line, int $column): self
    {
        return new self(
            "Unexpected token: {$tokenType}",
            $line,
            $column,
            'error',
            "Expected {$expected}",
            'E006'
        );
    }
    
    /**
     * Create warning for deprecated syntax
     */
    public static function deprecatedSyntax(string $old, string $new, int $line, int $column): self
    {
        return new self(
            "Deprecated syntax: {$old}",
            $line,
            $column,
            'warning',
            "Use {$new} instead",
            'W001'
        );
    }
    
    /**
     * Create error for parser stuck (infinite loop prevention)
     */
    public static function parserStuck(string $tagName, int $position, int $line, int $column): self
    {
        return new self(
            "Parser stuck while parsing {$tagName}",
            $line,
            $column,
            'error',
            "Check for missing closing tags or invalid syntax around position {$position}",
            'E007'
        );
    }
}
