<?php
/**
 * DiSyL Lexer (Tokenizer)
 * 
 * Converts DiSyL template string into tokens for parsing
 * Implements DiSyL v0.1 grammar specification
 * 
 * @version 0.1.0
 */

namespace IkabudKernel\Core\DiSyL;

use IkabudKernel\Core\DiSyL\Exceptions\LexerException;

class Lexer
{
    private string $input;
    private int $length;
    private int $position = 0;
    private int $line = 1;
    private int $column = 1;
    private bool $inTag = false;
    
    /**
     * Tokenize DiSyL template string
     * 
     * @param string $input DiSyL template
     * @return array<Token> Array of tokens
     * @throws LexerException if lexical error occurs
     */
    public function tokenize(string $input): array
    {
        $this->input = $input;
        $this->length = strlen($input);
        $this->position = 0;
        $this->line = 1;
        $this->column = 1;
        $this->inTag = false;
        
        $tokens = [];
        
        while ($this->position < $this->length) {
            $token = $this->nextToken();
            if ($token !== null) {
                $tokens[] = $token;
            }
        }
        
        // Add EOF token
        $tokens[] = new Token(
            Token::EOF,
            null,
            $this->line,
            $this->column,
            $this->position
        );
        
        return $tokens;
    }
    
    /**
     * Get next token from input
     */
    private function nextToken(): ?Token
    {
        // Skip whitespace in tag context
        if ($this->inTag) {
            $this->skipWhitespace();
        }
        
        if ($this->position >= $this->length) {
            return null;
        }
        
        $char = $this->peek();
        
        // Handle open brace
        if ($char === '{') {
            return $this->handleOpenBrace();
        }
        
        // Handle close brace
        if ($char === '}') {
            return $this->handleCloseBrace();
        }
        
        // Handle slash (for closing tags and self-closing) - only inside DiSyL tags
        if ($char === '/' && $this->inTag) {
            return $this->handleSlash();
        }
        
        // Handle equals - only inside DiSyL tags
        if ($char === '=' && $this->inTag) {
            return $this->handleEqual();
        }
        
        // Handle pipe (for filters) - only inside DiSyL tags
        if ($char === '|' && $this->inTag) {
            return $this->handlePipe();
        }
        
        // Handle colon (for filter params)
        if ($char === ':' && $this->inTag) {
            return $this->handleColon();
        }
        
        // Handle string (double or single quotes) - only inside DiSyL tags
        if (($char === '"' || $char === "'") && $this->inTag) {
            return $this->handleString($char);
        }
        
        // Handle number
        if (ctype_digit($char) || ($char === '-' && $this->isNumber($this->peek(1)))) {
            return $this->handleNumber();
        }
        
        // Handle identifier (tag names, attribute names, keywords) - only inside tags
        if ($this->inTag && (ctype_alpha($char) || $char === '_' || $char === ':')) {
            return $this->handleIdentifier();
        }
        
        // Handle text (outside tags or unrecognized characters)
        return $this->handleText();
    }
    
    /**
     * Handle open brace: { or {!--
     */
    private function handleOpenBrace(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $this->advance(); // consume '{'
        $this->inTag = true; // Enter tag context
        
        // Check for comment: {!--
        if ($this->peek() === '!' && $this->peek(1) === '-' && $this->peek(2) === '-') {
            return $this->handleComment($startLine, $startColumn, $startPosition);
        }
        
        return new Token(
            Token::LBRACE,
            '{',
            $startLine,
            $startColumn,
            $startPosition
        );
    }
    
    /**
     * Handle close brace: }
     */
    private function handleCloseBrace(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $this->advance(); // consume '}'
        $this->inTag = false; // Exit tag context
        
        return new Token(
            Token::RBRACE,
            '}',
            $startLine,
            $startColumn,
            $startPosition
        );
    }
    
    /**
     * Handle slash: /
     */
    private function handleSlash(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $this->advance(); // consume '/'
        
        return new Token(
            Token::SLASH,
            '/',
            $startLine,
            $startColumn,
            $startPosition
        );
    }
    
    /**
     * Handle equals: =
     */
    private function handleEqual(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $this->advance(); // consume =
        
        return new Token(
            Token::EQUAL,
            '=',
            $startLine,
            $startColumn,
            $startPosition
        );
    }
    
    /**
     * Handle pipe: | (for filters)
     */
    private function handlePipe(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $this->advance(); // consume |
        
        return new Token(
            Token::PIPE,
            '|',
            $startLine,
            $startColumn,
            $startPosition
        );
    }
    
    /**
     * Handle colon: : (for filter params)
     */
    private function handleColon(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $this->advance(); // consume :
        
        return new Token(
            Token::COLON,
            ':',
            $startLine,
            $startColumn,
            $startPosition
        );
    }
    
    /**
     * Handle string: "value" or 'value'
     * Supports escape sequences: \n, \t, \r, \\, \", \', \{, \}
     */
    private function handleString(string $quote = '"'): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $this->advance(); // consume opening quote
        
        $value = '';
        
        while ($this->position < $this->length && $this->peek() !== $quote) {
            $char = $this->peek();
            
            // Handle escape sequences
            if ($char === '\\') {
                $this->advance(); // consume backslash
                if ($this->position >= $this->length) {
                    throw new LexerException(
                        'Unterminated escape sequence in string',
                        $this->line,
                        $this->column,
                        $this->position
                    );
                }
                
                $escaped = $this->peek();
                $value .= match($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '\\' => '\\',
                    '"' => '"',
                    "'" => "'",
                    '{' => '{',
                    '}' => '}',
                    '0' => "\0",
                    default => $escaped // Unknown escape, keep as-is
                };
                $this->advance();
            } else {
                $value .= $char;
                $this->advance();
            }
        }
        
        if ($this->position >= $this->length) {
            throw new LexerException(
                "Unterminated string (expected closing {$quote})",
                $startLine,
                $startColumn,
                $startPosition
            );
        }
        
        $this->advance(); // consume closing quote
        
        return new Token(
            Token::STRING,
            $value,
            $startLine,
            $startColumn,
            $startPosition
        );
    }
    
    /**
     * Handle number: 123, -456, 3.14
     */
    private function handleNumber(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $value = '';
        
        // Handle negative sign
        if ($this->peek() === '-') {
            $value .= '-';
            $this->advance();
        }
        
        // Collect digits
        while ($this->position < $this->length && ctype_digit($this->peek())) {
            $value .= $this->peek();
            $this->advance();
        }
        
        // Handle decimal point
        if ($this->peek() === '.' && ctype_digit($this->peek(1))) {
            $value .= '.';
            $this->advance();
            
            while ($this->position < $this->length && ctype_digit($this->peek())) {
                $value .= $this->peek();
                $this->advance();
            }
        }
        
        // Convert to number
        $numValue = str_contains($value, '.') ? (float)$value : (int)$value;
        
        return new Token(
            Token::NUMBER,
            $numValue,
            $startLine,
            $startColumn,
            $startPosition
        );
    }
    
    /**
     * Handle identifier: tag names, attribute names, keywords (true, false, null)
     */
    private function handleIdentifier(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $value = '';
        
        // Collect identifier characters: [A-Za-z0-9_:\-\.]
        while ($this->position < $this->length) {
            $char = $this->peek();
            if (ctype_alnum($char) || $char === '_' || $char === ':' || $char === '-' || $char === '.') {
                $value .= $char;
                $this->advance();
            } else {
                break;
            }
        }
        
        // Check for keywords
        if ($value === 'true') {
            return new Token(Token::BOOL, true, $startLine, $startColumn, $startPosition);
        } elseif ($value === 'false') {
            return new Token(Token::BOOL, false, $startLine, $startColumn, $startPosition);
        } elseif ($value === 'null') {
            return new Token(Token::NULL, null, $startLine, $startColumn, $startPosition);
        }
        
        return new Token(
            Token::IDENT,
            $value,
            $startLine,
            $startColumn,
            $startPosition
        );
    }
    
    /**
     * Peek at character at current position + offset
     */
    private function peek(int $offset = 0): ?string
    {
        $pos = $this->position + $offset;
        if ($pos >= $this->length) {
            return null;
        }
        return $this->input[$pos];
    }
    
    /**
     * Advance position and update line/column
     */
    private function advance(): void
    {
        if ($this->position >= $this->length) {
            return;
        }
        
        $char = $this->input[$this->position];
        
        if ($char === "\n") {
            $this->line++;
            $this->column = 1;
        } else {
            $this->column++;
        }
        
        $this->position++;
    }
    
    /**
     * Skip whitespace (only in tag context)
     * Preserves line tracking while skipping spaces and tabs
     */
    private function skipWhitespace(): void
    {
        while (!$this->isAtEnd()) {
            $char = $this->peek();
            
            if ($char === ' ' || $char === "\t") {
                $this->advance();
            } elseif ($char === "\r") {
                $this->advance();
                // Handle CRLF
                if ($this->peek() === "\n") {
                    $this->advance();
                }
            } elseif ($char === "\n") {
                $this->advance();
            } else {
                break;
            }
        }
    }
    
    /**
     * Check if at end of input
     */
    private function isAtEnd(): bool
    {
        return $this->position >= $this->length;
    }
    
    /**
     * Check if character is a number
     */
    private function isNumber(?string $char): bool
    {
        return $char !== null && ctype_digit($char);
    }
    
    /**
     * Handle text (outside tags)
     * Supports escaped braces: \{ and \}
     * Supports inline expressions: {item.title | upper}
     */
    private function handleText(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $value = '';
        
        while (!$this->isAtEnd() && $this->peek() !== '{') {
            $char = $this->peek();
            
            // Handle escaped braces in text
            if ($char === '\\') {
                $next = $this->peek(1);
                if ($next === '{' || $next === '}') {
                    $this->advance(); // consume backslash
                    $value .= $next; // add the brace
                    $this->advance(); // consume brace
                } else {
                    // Not an escape sequence, keep backslash
                    $value .= $char;
                    $this->advance();
                }
            } else {
                $value .= $char;
                $this->advance();
            }
        }
        
        // Loop to handle multiple inline expressions with filters in the same TEXT token
        while (!$this->isAtEnd() && $this->peek() === '{' && $this->peek(1) !== '!' && $this->peek(1) !== '/') {
            // Scan ahead to check if this contains a pipe (indicating a filter)
            $scanPos = $this->position + 1;
            $hasPipe = false;
            $scanDepth = 1;
            
            while ($scanPos < $this->length && $scanDepth > 0) {
                $scanChar = $this->input[$scanPos];
                if ($scanChar === '{') {
                    $scanDepth++;
                } else if ($scanChar === '}') {
                    $scanDepth--;
                } else if ($scanChar === '|' && $scanDepth === 1) {
                    // Check if this is a filter pipe (|) or logical OR (||)
                    $nextChar = $scanPos + 1 < $this->length ? $this->input[$scanPos + 1] : '';
                    $prevChar = $scanPos > 0 ? $this->input[$scanPos - 1] : '';
                    
                    // If it's || or |>, it's not a filter pipe
                    if ($nextChar !== '|' && $prevChar !== '|') {
                        $hasPipe = true;
                        break;
                    }
                }
                $scanPos++;
            }
            
            // If it has a pipe, it's an expression with filter - include it in text
            if ($hasPipe) {
                $braceDepth = 0;
                
                while (!$this->isAtEnd()) {
                    $char = $this->peek();
                    
                    if ($char === '{') {
                        $braceDepth++;
                        $value .= $char;
                        $this->advance();
                    } else if ($char === '}') {
                        $value .= $char;
                        $this->advance();
                        $braceDepth--;
                        
                        if ($braceDepth === 0) {
                            // Closed the expression, continue with text
                            break;
                        }
                    } else {
                        $value .= $char;
                        $this->advance();
                    }
                }
                
                // After handling inline expression, continue with more text if needed
                while (!$this->isAtEnd() && $this->peek() !== '{') {
                    $char = $this->peek();
                    
                    if ($char === '\\') {
                        $next = $this->peek(1);
                        if ($next === '{' || $next === '}') {
                            $this->advance();
                            $value .= $next;
                            $this->advance();
                        } else {
                            $value .= $char;
                            $this->advance();
                        }
                    } else {
                        $value .= $char;
                        $this->advance();
                    }
                }
            } else {
                // No pipe found, this is a regular DiSyL tag, stop here
                break;
            }
        }
        
        return new Token(
            Token::TEXT,
            $value,
            $startLine,
            $startColumn,
            $startPosition
        );
    }
    
    /**
     * Handle comment: {!-- comment --}
     * Supports multi-line comments
     */
    private function handleComment(int $startLine, int $startColumn, int $startPosition): Token
    {
        // Consume {!--
        $this->advance(); // !
        $this->advance(); // -
        $this->advance(); // -
        
        $value = '';
        $foundClosing = false;
        
        // Find closing --}
        while ($this->position < $this->length) {
            if ($this->peek() === '-' && $this->peek(1) === '-' && $this->peek(2) === '}') {
                $this->advance(); // -
                $this->advance(); // -
                $this->advance(); // }
                $this->inTag = false;
                $foundClosing = true;
                break;
            }
            $value .= $this->peek();
            $this->advance();
        }
        
        if (!$foundClosing) {
            throw new LexerException(
                'Unterminated comment (expected --})',
                $startLine,
                $startColumn,
                $startPosition
            );
        }
        
        return new Token(
            Token::COMMENT,
            trim($value),
            $startLine,
            $startColumn,
            $startPosition
        );
    }
}
