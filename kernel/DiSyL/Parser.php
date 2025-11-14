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
        
        // Check for closing tag: {/tagname} - don't consume LBRACE yet
        if ($this->check(Token::LBRACE) && $this->checkNext(Token::SLASH)) {
            return $this->parseClosingTag($startToken);
        }
        
        // Consume opening brace for opening/self-closing tags
        $this->expect(Token::LBRACE, 'Expected opening brace');
        
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
            // Check if this is a registered component
            $isComponent = ComponentRegistry::has($tagName);
            
            if ($isComponent) {
                // For registered components without attributes, we need to check if there's
                // a matching closing tag. If not, treat as expression.
                // Look ahead to see if there's a closing tag
                $savedPos = $this->position;
                $hasClosingTag = false;
                $depth = 1;
                $lookAheadLimit = 100; // Don't look too far ahead
                $lookAheadCount = 0;
                
                while (!$this->isAtEnd() && $lookAheadCount < $lookAheadLimit) {
                    $lookAheadCount++;
                    $token = $this->peek();
                    
                    if ($token === null) break;
                    
                    // Check for opening tag with same name
                    if ($token->type === Token::LBRACE) {
                        $nextToken = $this->peek(1);
                        if ($nextToken && $nextToken->type === Token::IDENT && $nextToken->value === $tagName) {
                            $depth++;
                        } elseif ($nextToken && $nextToken->type === Token::SLASH) {
                            $nameToken = $this->peek(2);
                            if ($nameToken && $nameToken->type === Token::IDENT && $nameToken->value === $tagName) {
                                $depth--;
                                if ($depth === 0) {
                                    $hasClosingTag = true;
                                    break;
                                }
                            }
                        }
                    }
                    
                    $this->advance();
                }
                
                // Restore position
                $this->position = $savedPos;
                
                // If no closing tag found, treat as expression
                if (!$hasClosingTag) {
                    return [
                        'type' => 'expression',
                        'value' => $tagName,
                        'loc' => $this->getLocation($startToken)
                    ];
                }
            }
            
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
        // Expect to be at LBRACE
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
                $attrValue = $this->parseAttributeValue();
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
     * Parse attribute value with optional filters
     * Supports: "value", "{expr | filter}", "value | filter:param=val"
     */
    private function parseAttributeValue()
    {
        $token = $this->advance();
        $value = $token->value;
        
        // Simple approach: Check if string value contains {expr | filter} pattern
        if (is_string($value) && strpos($value, '{') === 0 && strpos($value, '|') !== false && substr($value, -1) === '}') {
            // Extract content between { and }
            $content = substr($value, 1, -1);
            
            // Find the pipe position
            $pipePos = strpos($content, '|');
            if ($pipePos !== false) {
                $baseExpr = '{' . trim(substr($content, 0, $pipePos)) . '}';
                $filterChain = trim(substr($content, $pipePos + 1));
                
                // Parse filters
                $filters = [];
                $filterParts = explode('|', $filterChain);
                
                foreach ($filterParts as $filterPart) {
                    $filterPart = trim($filterPart);
                    if (empty($filterPart)) continue;
                    
                    // Check for parameters: "truncate:length=100"
                    $params = [];
                    if (strpos($filterPart, ':') !== false) {
                        list($filterName, $paramStr) = explode(':', $filterPart, 2);
                        $filterName = trim($filterName);
                        
                        // Parse simple param=value
                        if (preg_match('/(\w+)=(\d+|["\']([^"\']+)["\'])/', $paramStr, $matches)) {
                            $paramName = $matches[1];
                            $paramValue = isset($matches[3]) ? $matches[3] : $matches[2];
                            $params[$paramName] = $paramValue;
                        }
                    } else {
                        $filterName = $filterPart;
                    }
                    
                    $filters[] = [
                        'name' => $filterName,
                        'params' => $params
                    ];
                }
                
                return [
                    'type' => 'filtered_expression',
                    'value' => $baseExpr,
                    'filters' => $filters,
                    'loc' => $this->getLocation($token)
                ];
            }
        }
        
        // Check for filters (pipe after value) - for non-string contexts
        if ($this->check(Token::PIPE)) {
            return $this->parseFilteredExpression($value, $token);
        }
        
        return $value;
    }
    
    /**
     * Parse filtered expression: value | filter1 | filter2:param=val
     */
    private function parseFilteredExpression($baseValue, Token $baseToken): array
    {
        $filters = [];
        
        while ($this->check(Token::PIPE)) {
            $this->advance(); // consume |
            
            // Get filter name
            if (!$this->check(Token::IDENT)) {
                $this->addError('Expected filter name after |', $this->peek());
                break;
            }
            
            $filterName = $this->advance()->value;
            $filterParams = [];
            
            // Check for filter parameters (colon)
            if ($this->check(Token::COLON)) {
                $this->advance(); // consume :
                $filterParams = $this->parseFilterParams();
            }
            
            $filters[] = [
                'name' => $filterName,
                'params' => $filterParams
            ];
        }
        
        return [
            'type' => 'filtered_expression',
            'value' => $baseValue,
            'filters' => $filters,
            'loc' => $this->getLocation($baseToken)
        ];
    }
    
    /**
     * Parse filter parameters: param1=val1,param2=val2
     */
    private function parseFilterParams(): array
    {
        $params = [];
        
        while (true) {
            // Get parameter name
            if (!$this->check(Token::IDENT)) {
                break;
            }
            
            $paramName = $this->advance()->value;
            
            // Expect equals
            if (!$this->check(Token::EQUAL)) {
                $this->addError("Expected = after filter parameter '{$paramName}'", $this->peek());
                break;
            }
            $this->advance(); // consume =
            
            // Get parameter value
            $paramValue = null;
            $valueToken = $this->peek();
            
            if ($valueToken && in_array($valueToken->type, [Token::STRING, Token::NUMBER, Token::BOOL])) {
                $paramValue = $this->advance()->value;
            } else {
                $this->addError("Expected value for filter parameter '{$paramName}'", $valueToken);
                break;
            }
            
            $params[$paramName] = $paramValue;
            
            // Check for more parameters (would need comma support in lexer)
            // For now, only support one parameter per filter
            break;
        }
        
        return $params;
    }
    
    /**
     * Get location info from token
     */
    private function getLocation(Token $token): array
    {
        return [
            'line' => $token->line,
            'column' => $token->column,
            'position' => $token->position
        ];
    }
}
