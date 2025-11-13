<?php
/**
 * DiSyL Parser
 * 
 * Converts tokens into Abstract Syntax Tree (AST)
 * Implements recursive descent parsing for DiSyL v0.1 grammar
 * 
 * @version 0.1.0
 */

namespace IkabudKernel\Core\DiSyL;

use IkabudKernel\Core\DiSyL\Exceptions\ParserException;

class Parser
{
    private array $tokens;
    private int $position = 0;
    private int $length = 0;
    private array $errors = [];
    private array $tagStack = []; // Track open tags for better error messages
    
    /**
     * Parse tokens into AST
     * 
     * @param array<Token> $tokens Array of tokens from Lexer
     * @return array AST structure
     * @throws ParserException if parsing fails
     */
    public function parse(array $tokens): array
    {
        $this->tokens = $tokens;
        $this->length = count($tokens);
        $this->position = 0;
        $this->errors = [];
        
        $ast = [
            'type' => 'document',
            'version' => '0.1',
            'children' => [],
            'errors' => []
        ];
        
        // Parse all nodes until EOF
        $maxIterations = 10000; // Safety limit
        $iterations = 0;
        
        while (!$this->isAtEnd()) {
            // Safety check: prevent infinite loop
            if (++$iterations > $maxIterations) {
                $this->addError('Parser exceeded maximum iterations (possible infinite loop)', $this->peek());
                break;
            }
            
            $beforePos = $this->position;
            $node = $this->parseNode();
            
            if ($node !== null) {
                $ast['children'][] = $node;
            }
            
            // Critical: If position didn't advance, force it
            if ($this->position === $beforePos && !$this->isAtEnd()) {
                $this->addError('Parser stuck at position ' . $this->position, $this->peek());
                $this->advance(); // Force advance to prevent infinite loop
            }
        }
        
        $ast['errors'] = $this->errors;
        
        return $ast;
    }
    
    /**
     * Parse a single node (tag or text)
     */
    private function parseNode(): ?array
    {
        $current = $this->peek();
        
        if ($current === null) {
            return null;
        }
        
        // Handle text nodes
        if ($current->type === Token::TEXT) {
            return $this->parseText();
        }
        
        // Handle comment nodes
        if ($current->type === Token::COMMENT) {
            return $this->parseComment();
        }
        
        // Handle tag nodes
        if ($current->type === Token::LBRACE) {
            return $this->parseTag();
        }
        
        // Skip EOF
        if ($current->type === Token::EOF) {
            return null;
        }
        
        // Unexpected token
        $this->addError(
            sprintf('Unexpected token: %s', $current->type),
            $current
        );
        $this->advance();
        
        return null;
    }
    
    /**
     * Parse text node
     */
    private function parseText(): array
    {
        $token = $this->advance();
        
        return [
            'type' => 'text',
            'value' => $token->value,
            'loc' => $this->getLocation($token)
        ];
    }
    
    /**
     * Parse comment node
     */
    private function parseComment(): array
    {
        $token = $this->advance();
        
        return [
            'type' => 'comment',
            'value' => $token->value,
            'loc' => $this->getLocation($token)
        ];
    }
    
    /**
     * Parse tag node (opening, closing, or self-closing)
     */
    private function parseTag(): ?array
    {
        $startToken = $this->peek();
        
        // Consume opening brace
        $this->expect(Token::LBRACE, 'Expected opening brace');
        
        // Check for closing tag: {/tagname}
        if ($this->check(Token::SLASH)) {
            return $this->parseClosingTag($startToken);
        }
        
        // Parse tag name
        $nameToken = $this->expect(Token::IDENT, 'Expected tag name');
        $tagName = $nameToken->value;
        
        // Parse attributes
        $attributes = $this->parseAttributes();
        
        // Check for self-closing: {tagname /}
        $selfClosing = false;
        if ($this->check(Token::SLASH)) {
            $this->advance(); // consume /
            $selfClosing = true;
        }
        
        // Consume closing brace
        $this->expect(Token::RBRACE, 'Expected closing brace');
        
        // If no attributes and immediately closed, treat as expression
        // e.g., {title} or {item.title}
        if (empty($attributes) && !$selfClosing) {
            // Check if this looks like an expression (no ikb_ prefix, lowercase, dots allowed)
            if (!str_starts_with($tagName, 'ikb_') && 
                (ctype_lower($tagName[0]) || strpos($tagName, '.') !== false)) {
                // Return as expression node (text type with expression marker)
                return [
                    'type' => 'expression',
                    'value' => $tagName,
                    'loc' => $this->getLocation($startToken)
                ];
            }
        }
        
        // If self-closing, return immediately
        if ($selfClosing) {
            return [
                'type' => 'tag',
                'name' => $tagName,
                'attrs' => $attributes,
                'children' => [],
                'self_closing' => true,
                'loc' => $this->getLocation($startToken)
            ];
        }
        
        // Parse children until we find matching closing tag
        $children = [];
        $this->tagStack[] = $tagName; // Push to stack
        $maxChildIterations = 1000; // Safety limit for child parsing
        $childIterations = 0;
        
        while (!$this->isAtEnd()) {
            // Safety check: prevent infinite loop in child parsing
            if (++$childIterations > $maxChildIterations) {
                $this->addError("Exceeded maximum child iterations for {$tagName} (possible infinite loop)", $this->peek());
                array_pop($this->tagStack);
                break;
            }
            // Check for closing tag
            if ($this->check(Token::LBRACE) && $this->checkNext(Token::SLASH)) {
                // Peek at the closing tag name
                $savedPos = $this->position;
                $this->advance(); // skip {
                $this->advance(); // skip /
                
                if ($this->check(Token::IDENT)) {
                    $closingName = $this->peek()->value;
                    $this->position = $savedPos; // restore position
                    
                    // If this is OUR closing tag, parse it and break
                    if ($closingName === $tagName) {
                        $closingTag = $this->parseClosingTag($startToken);
                        array_pop($this->tagStack); // Pop from stack
                        break;
                    }
                    // Mismatched closing tag - add helpful error
                    $token = $this->peek();
                    $this->addParserError(
                        ParserError::mismatchedClosingTag(
                            $tagName,
                            $closingName,
                            $token->line,
                            $token->column
                        )
                    );
                    $this->position = $savedPos;
                }
            }
            
            // Save position before parsing child
            $beforePos = $this->position;
            
            // Parse child node
            $child = $this->parseNode();
            if ($child !== null) {
                $children[] = $child;
            }
            
            // Safety check: if position didn't advance, break to prevent infinite loop
            if ($this->position === $beforePos) {
                $token = $this->peek();
                $this->addParserError(
                    ParserError::parserStuck(
                        $tagName,
                        $this->position,
                        $token?->line ?? 1,
                        $token?->column ?? 1
                    )
                );
                $this->advance(); // force advance
                break; // CRITICAL: Break the loop to prevent infinite loop
            }
        }
        
        // Check if we exited loop without finding closing tag
        if ($this->isAtEnd() && !empty($this->tagStack)) {
            $this->addParserError(
                ParserError::missingClosingTag(
                    $tagName,
                    $startToken->line,
                    $startToken->column
                )
            );
            array_pop($this->tagStack); // Clean up stack
        }
        
        return [
            'type' => 'tag',
            'name' => $tagName,
            'attrs' => $attributes,
            'children' => $children,
            'self_closing' => false,
            'loc' => $this->getLocation($startToken)
        ];
    }
    
    /**
     * Parse closing tag: {/tagname}
     */
    private function parseClosingTag(Token $startToken): ?array
    {
        // We're at LBRACE, next should be SLASH
        $this->expect(Token::LBRACE, 'Expected opening brace');
        $this->expect(Token::SLASH, 'Expected slash');
        
        $nameToken = $this->expect(Token::IDENT, 'Expected tag name');
        $tagName = $nameToken->value;
        
        $this->expect(Token::RBRACE, 'Expected closing brace');
        
        return [
            'type' => 'closing_tag',
            'name' => $tagName,
            'loc' => $this->getLocation($startToken)
        ];
    }
    
    /**
     * Parse tag attributes
     */
    private function parseAttributes(): array
    {
        $attributes = [];
        
        // Parse attributes until we hit / or }
        while (!$this->isAtEnd() && 
               !$this->check(Token::SLASH) && 
               !$this->check(Token::RBRACE)) {
            
            // Attribute name
            if (!$this->check(Token::IDENT)) {
                break;
            }
            
            $nameToken = $this->advance();
            $attrName = $nameToken->value;
            
            // Expect equals
            if (!$this->check(Token::EQUAL)) {
                $this->addError(
                    sprintf('Expected = after attribute name "%s"', $attrName),
                    $this->peek()
                );
                break;
            }
            $this->advance(); // consume =
            
            // Attribute value
            $valueToken = $this->peek();
            
            if ($valueToken === null) {
                $this->addError(
                    sprintf('Expected value for attribute "%s"', $attrName),
                    $nameToken
                );
                break;
            }
            
            $attrValue = null;
            
            if ($valueToken->type === Token::STRING) {
                $attrValue = $this->advance()->value;
            } elseif ($valueToken->type === Token::NUMBER) {
                $attrValue = $this->advance()->value;
            } elseif ($valueToken->type === Token::BOOL) {
                $attrValue = $this->advance()->value;
            } elseif ($valueToken->type === Token::NULL) {
                $this->advance();
                $attrValue = null;
            } else {
                $this->addError(
                    sprintf(
                        'Invalid attribute value type: %s (expected STRING, NUMBER, BOOL, or NULL)',
                        $valueToken->type
                    ),
                    $valueToken
                );
                $this->advance();
                continue;
            }
            
            $attributes[$attrName] = $attrValue;
        }
        
        return $attributes;
    }
    
    /**
     * Get current token without advancing
     */
    private function peek(int $offset = 0): ?Token
    {
        $pos = $this->position + $offset;
        if ($pos >= $this->length) {
            return null;
        }
        return $this->tokens[$pos];
    }
    
    /**
     * Get current token and advance position
     */
    private function advance(): ?Token
    {
        if ($this->isAtEnd()) {
            return null;
        }
        
        $token = $this->tokens[$this->position];
        $this->position++;
        
        return $token;
    }
    
    /**
     * Check if current token matches type
     */
    private function check(string $type): bool
    {
        $token = $this->peek();
        return $token !== null && $token->type === $type;
    }
    
    /**
     * Check if next token matches type
     */
    private function checkNext(string $type): bool
    {
        $token = $this->peek(1);
        return $token !== null && $token->type === $type;
    }
    
    /**
     * Expect a token of specific type, throw error if not found
     */
    private function expect(string $type, string $message): Token
    {
        $token = $this->peek();
        
        if ($token === null) {
            $this->addParserError(
                ParserError::unexpectedToken(
                    'EOF',
                    $type,
                    1,
                    1
                )
            );
            throw new ParserException(
                $message . ' (unexpected end of input)',
                1,
                1,
                $this->position
            );
        }
        
        if ($token->type !== $type) {
            $this->addParserError(
                ParserError::unexpectedToken(
                    $token->type,
                    $type,
                    $token->line,
                    $token->column
                )
            );
            throw new ParserException(
                sprintf('%s, got %s', $message, $token->type),
                $token->line,
                $token->column,
                $token->position,
                $token->type
            );
        }
        
        return $this->advance();
    }
    
    /**
     * Check if we're at end of tokens
     */
    private function isAtEnd(): bool
    {
        return $this->position >= $this->length;
    }
    
    /**
     * Add error to error list
     */
    private function addError(string $message, ?Token $token = null): void
    {
        $line = $token?->line ?? 1;
        $column = $token?->column ?? 1;
        
        $this->errors[] = [
            'message' => $message,
            'line' => $line,
            'column' => $column,
            'position' => $token?->position ?? $this->position,
            'formatted' => sprintf("Line %d, Col %d: %s", $line, $column, $message)
        ];
    }
    
    /**
     * Add ParserError to error list
     */
    private function addParserError(ParserError $error): void
    {
        $this->errors[] = $error->toArray();
        $this->errors[count($this->errors) - 1]['formatted'] = $error->format();
    }
    
    /**
     * Get location info from token
     */
    private function getLocation(Token $token): array
    {
        return [
            'line' => $token->line,
            'column' => $token->column,
            'start' => $token->position,
            'end' => $token->position + strlen((string)$token->value)
        ];
    }
}
