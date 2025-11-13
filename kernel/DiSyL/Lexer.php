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
        
        // Handle slash (for closing tags and self-closing)
        if ($char === '/') {
            return $this->handleSlash();
        }
        
        // Handle equals
        if ($char === '=') {
            return $this->handleEqual();
        }
        
        // Handle string (double quotes)
        if ($char === '"') {
            return $this->handleString();
        }
        
        // Handle number
        if (ctype_digit($char) || ($char === '-' && $this->isNumber($this->peek(1)))) {
            return $this->handleNumber();
        }
        
        // Handle identifier (tag names, attribute names, keywords)
        if (ctype_alpha($char) || $char === '_' || $char === ':') {
            return $this->handleIdentifier();
        }
        
        // Handle text (outside tags)
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
        
        $this->advance(); // consume '='
        
        return new Token(
            Token::EQUAL,
            '=',
            $startLine,
            $startColumn,
            $startPosition
        );
    }
    
    /**
     * Handle string: "value"
     */
    private function handleString(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $this->advance(); // consume opening "
        
        $value = '';
        
        while ($this->position < $this->length && $this->peek() !== '"') {
            $char = $this->peek();
            
            // Handle escape sequences
            if ($char === '\\' && $this->peek(1) === '"') {
                $this->advance(); // consume \
                $value .= '"';
                $this->advance(); // consume "
            } elseif ($char === '\\' && $this->peek(1) === '\\') {
                $this->advance(); // consume \
                $value .= '\\';
                $this->advance(); // consume \
            } elseif ($char === '\\' && $this->peek(1) === 'n') {
                $this->advance(); // consume \
                $value .= "\n";
                $this->advance(); // consume n
            } elseif ($char === '\\' && $this->peek(1) === 't') {
                $this->advance(); // consume \
                $value .= "\t";
                $this->advance(); // consume t
            } else {
                $value .= $char;
                $this->advance();
            }
        }
        
        if ($this->position >= $this->length) {
            throw new LexerException(
                'Unterminated string',
                $startLine,
                $startColumn,
                $startPosition
            );
        }
        
        $this->advance(); // consume closing "
        
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
     * Handle text (outside tags)
     */
    private function handleText(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $startPosition = $this->position;
        
        $value = '';
        
        // Collect text until we hit an opening brace
        while ($this->position < $this->length && $this->peek() !== '{') {
            $value .= $this->peek();
            $this->advance();
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
     */
    private function handleComment(int $startLine, int $startColumn, int $startPosition): Token
    {
        // We've already consumed '{', now consume '!--'
        $this->advance(); // !
        $this->advance(); // -
        $this->advance(); // -
        
        $value = '';
        
        // Collect comment text until we find '--}'
        while ($this->position < $this->length) {
            if ($this->peek() === '-' && $this->peek(1) === '-' && $this->peek(2) === '}') {
                $this->advance(); // -
                $this->advance(); // -
                $this->advance(); // }
                break;
            }
            $value .= $this->peek();
            $this->advance();
        }
        
        return new Token(
            Token::COMMENT,
            trim($value),
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
     * Skip whitespace characters
     */
    private function skipWhitespace(): void
    {
        while ($this->position < $this->length && ctype_space($this->peek())) {
            $this->advance();
        }
    }
    
    /**
     * Check if character is a number
     */
    private function isNumber(?string $char): bool
    {
        return $char !== null && ctype_digit($char);
    }
    
}
