<?php
/**
 * DiSyL Component Parser v1.0.0
 * 
 * Parses component blocks from DiSyL v3.1 grammar.
 * Works with the main Parser to handle {component}...{/component} blocks.
 * 
 * @version 1.0.0
 */

namespace Ikabud\Kernel\DiSyL\Component;

use Ikabud\Kernel\DiSyL\Token;
use Ikabud\Kernel\DiSyL\ExpressionParser;
use Ikabud\Kernel\DiSyL\Exceptions\ParserException;

class ComponentParser
{
    /** @var array Token stream */
    private array $tokens;
    
    /** @var int Current position */
    private int $position = 0;
    
    /** @var int Token count */
    private int $length = 0;
    
    /** @var ExpressionParser Expression parser */
    private ExpressionParser $exprParser;
    
    /** @var array Collected errors */
    private array $errors = [];
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->exprParser = new ExpressionParser();
    }
    
    /**
     * Parse a component block from tokens
     * 
     * Expects tokens starting after {component has been consumed
     * 
     * @param array $tokens Token stream
     * @param int $startPos Starting position (after {component)
     * @return array Component AST node
     */
    public function parse(array $tokens, int $startPos = 0): array
    {
        $this->tokens = $tokens;
        $this->position = $startPos;
        $this->length = count($tokens);
        $this->errors = [];
        
        return $this->parseComponentBlock();
    }
    
    /**
     * Parse component block: {component Name extends Parent}...{/component}
     */
    private function parseComponentBlock(): array
    {
        $startToken = $this->peek();
        
        // Parse component name
        $nameToken = $this->expect(Token::IDENT, 'Expected component name');
        $name = $nameToken->value;
        
        // Check for extends
        $extends = null;
        if ($this->check(Token::IDENT) && $this->peek()->value === 'extends') {
            $this->advance(); // consume 'extends'
            $extendsToken = $this->expect(Token::IDENT, 'Expected parent component name');
            $extends = $extendsToken->value;
        }
        
        // Consume closing brace of opening tag
        $this->expect(Token::RBRACE, 'Expected } after component declaration');
        
        // Parse component body
        $body = $this->parseComponentBody($name);
        
        return [
            'type' => 'ComponentDeclaration',
            'name' => $name,
            'extends' => $extends,
            'props' => $body['props'],
            'slots' => $body['slots'],
            'state' => $body['state'],
            'computed' => $body['computed'],
            'watchers' => $body['watchers'],
            'eventHandlers' => $body['eventHandlers'],
            'methods' => $body['methods'],
            'template' => $body['template'],
            'style' => $body['style'],
            'client' => $body['client'],
            'decorators' => $body['decorators'],
            'loc' => $this->getLocation($startToken),
        ];
    }
    
    /**
     * Parse component body until {/component}
     */
    private function parseComponentBody(string $componentName): array
    {
        $body = [
            'props' => [],
            'slots' => [],
            'state' => [],
            'computed' => [],
            'watchers' => [],
            'eventHandlers' => [],
            'methods' => [],
            'template' => null,
            'style' => null,
            'client' => null,
            'decorators' => [],
        ];
        
        while (!$this->isAtEnd()) {
            // Check for closing tag
            if ($this->isClosingTag('component')) {
                $this->consumeClosingTag('component');
                break;
            }
            
            // Must be opening brace for a block
            if (!$this->check(Token::LBRACE)) {
                $this->advance(); // Skip unexpected token
                continue;
            }
            
            $this->advance(); // consume {
            
            // Get block type
            if (!$this->check(Token::IDENT)) {
                $this->addError('Expected block type identifier');
                continue;
            }
            
            $blockType = $this->peek()->value;
            $this->advance(); // consume block type
            
            switch ($blockType) {
                case 'props':
                    $body['props'] = $this->parsePropsBlock();
                    break;
                    
                case 'slots':
                    $body['slots'] = $this->parseSlotsBlock();
                    break;
                    
                case 'state':
                    $body['state'] = array_merge($body['state'], $this->parseStateBlock());
                    break;
                    
                case 'computed':
                    $computed = $this->parseComputedDecl();
                    if ($computed) {
                        $body['computed'][] = $computed;
                    }
                    break;
                    
                case 'watch':
                    $watcher = $this->parseWatchDecl();
                    if ($watcher) {
                        $body['watchers'][] = $watcher;
                    }
                    break;
                    
                case 'on':
                    $handler = $this->parseEventHandler();
                    if ($handler) {
                        $body['eventHandlers'][] = $handler;
                    }
                    break;
                    
                case 'func':
                    $method = $this->parseFunctionDecl();
                    if ($method) {
                        $body['methods'][] = $method;
                    }
                    break;
                    
                case 'template':
                    $body['template'] = $this->parseTemplateBlock();
                    break;
                    
                case 'style':
                    $body['style'] = $this->parseStyleBlock();
                    break;
                    
                case 'client':
                    $body['client'] = $this->parseClientBlock();
                    break;
                    
                case 'prop':
                    // Inline prop declaration
                    $prop = $this->parsePropDecl();
                    if ($prop) {
                        $body['props'][] = $prop;
                    }
                    break;
                    
                default:
                    // Unknown block, skip to closing brace
                    $this->skipToClosingBrace();
            }
        }
        
        return $body;
    }
    
    /**
     * Parse props block: {props}...{/props}
     */
    private function parsePropsBlock(): array
    {
        $this->expect(Token::RBRACE, 'Expected } after props');
        
        $props = [];
        
        while (!$this->isAtEnd() && !$this->isClosingTag('props')) {
            if ($this->check(Token::LBRACE)) {
                $this->advance(); // consume {
                
                if ($this->check(Token::IDENT) && $this->peek()->value === 'prop') {
                    $this->advance(); // consume 'prop'
                    $prop = $this->parsePropDecl();
                    if ($prop) {
                        $props[] = $prop;
                    }
                } else {
                    $this->skipToClosingBrace();
                }
            } else {
                $this->advance();
            }
        }
        
        $this->consumeClosingTag('props');
        
        return $props;
    }
    
    /**
     * Parse single prop declaration: {prop name: type = default required}
     */
    private function parsePropDecl(): ?array
    {
        // Get prop name
        $nameToken = $this->expect(Token::IDENT, 'Expected prop name');
        $name = $nameToken->value;
        
        $optional = false;
        $type = null;
        $defaultValue = null;
        $required = false;
        
        // Check for optional marker (?)
        if ($this->check(Token::QUESTION)) {
            $this->advance();
            $optional = true;
        }
        
        // Check for type annotation
        if ($this->check(Token::COLON)) {
            $this->advance();
            $type = $this->parseTypeAnnotation();
        }
        
        // Check for default value
        if ($this->check(Token::EQUAL)) {
            $this->advance();
            $defaultValue = $this->parseExpressionUntil([Token::RBRACE, Token::IDENT]);
        }
        
        // Check for 'required' keyword
        if ($this->check(Token::IDENT) && $this->peek()->value === 'required') {
            $this->advance();
            $required = true;
        }
        
        $this->expect(Token::RBRACE, 'Expected } after prop declaration');
        
        return [
            'name' => $name,
            'type' => $type,
            'optional' => $optional,
            'defaultValue' => $defaultValue,
            'required' => $required,
        ];
    }
    
    /**
     * Parse slots block: {slots}...{/slots}
     */
    private function parseSlotsBlock(): array
    {
        $this->expect(Token::RBRACE, 'Expected } after slots');
        
        $slots = [];
        
        while (!$this->isAtEnd() && !$this->isClosingTag('slots')) {
            if ($this->check(Token::LBRACE)) {
                $this->advance(); // consume {
                
                if ($this->check(Token::IDENT) && $this->peek()->value === 'slot') {
                    $this->advance(); // consume 'slot'
                    $slot = $this->parseSlotDecl();
                    if ($slot) {
                        $slots[] = $slot;
                    }
                } else {
                    $this->skipToClosingBrace();
                }
            } else {
                $this->advance();
            }
        }
        
        $this->consumeClosingTag('slots');
        
        return $slots;
    }
    
    /**
     * Parse slot declaration: {slot name(params)}
     */
    private function parseSlotDecl(): ?array
    {
        $nameToken = $this->expect(Token::IDENT, 'Expected slot name');
        $name = $nameToken->value;
        
        $params = [];
        
        // Check for parameters
        if ($this->check(Token::LPAREN)) {
            $this->advance();
            $params = $this->parseParameterList();
            $this->expect(Token::RPAREN, 'Expected ) after slot parameters');
        }
        
        $this->expect(Token::RBRACE, 'Expected } after slot declaration');
        
        return [
            'name' => $name,
            'params' => $params,
        ];
    }
    
    /**
     * Parse state block: {state}...{/state}
     */
    private function parseStateBlock(): array
    {
        $this->expect(Token::RBRACE, 'Expected } after state');
        
        $state = [];
        
        while (!$this->isAtEnd() && !$this->isClosingTag('state')) {
            if ($this->check(Token::LBRACE)) {
                $this->advance(); // consume {
                
                if ($this->check(Token::IDENT) && $this->peek()->value === 'let') {
                    $this->advance(); // consume 'let'
                    $stateVar = $this->parseStateVar();
                    if ($stateVar) {
                        $state[] = $stateVar;
                    }
                } else {
                    $this->skipToClosingBrace();
                }
            } else {
                $this->advance();
            }
        }
        
        $this->consumeClosingTag('state');
        
        return $state;
    }
    
    /**
     * Parse state variable: {let name: type = value}
     */
    private function parseStateVar(): ?array
    {
        $nameToken = $this->expect(Token::IDENT, 'Expected state variable name');
        $name = $nameToken->value;
        
        $type = null;
        $init = null;
        
        // Type annotation
        if ($this->check(Token::COLON)) {
            $this->advance();
            $type = $this->parseTypeAnnotation();
        }
        
        // Initial value (required for state)
        if ($this->check(Token::EQUAL)) {
            $this->advance();
            $init = $this->parseExpressionUntil([Token::RBRACE]);
        }
        
        $this->expect(Token::RBRACE, 'Expected } after state declaration');
        
        return [
            'name' => $name,
            'type' => $type,
            'init' => $init,
        ];
    }
    
    /**
     * Parse computed declaration: {computed name = expression}
     */
    private function parseComputedDecl(): ?array
    {
        $nameToken = $this->expect(Token::IDENT, 'Expected computed property name');
        $name = $nameToken->value;
        
        $type = null;
        
        // Optional type annotation
        if ($this->check(Token::COLON)) {
            $this->advance();
            $type = $this->parseTypeAnnotation();
        }
        
        $this->expect(Token::EQUAL, 'Expected = after computed property name');
        
        $expression = $this->parseExpressionUntil([Token::RBRACE]);
        
        $this->expect(Token::RBRACE, 'Expected } after computed declaration');
        
        return [
            'name' => $name,
            'type' => $type,
            'expression' => $expression,
        ];
    }
    
    /**
     * Parse watch declaration: {watch expression, {options}}...{/watch}
     */
    private function parseWatchDecl(): ?array
    {
        $expression = $this->parseExpressionUntil([Token::COMMA, Token::RBRACE]);
        
        $options = [];
        
        // Check for options
        if ($this->check(Token::COMMA)) {
            $this->advance();
            $options = $this->parseWatchOptions();
        }
        
        $this->expect(Token::RBRACE, 'Expected } after watch declaration');
        
        // Parse watch body
        $body = $this->parseBlockContent('watch');
        
        return [
            'expression' => $expression,
            'options' => $options,
            'body' => $body,
        ];
    }
    
    /**
     * Parse watch options: {immediate: true, deep: false}
     */
    private function parseWatchOptions(): array
    {
        $options = [];
        
        if ($this->check(Token::LBRACE)) {
            $this->advance();
            
            while (!$this->isAtEnd() && !$this->check(Token::RBRACE)) {
                if ($this->check(Token::IDENT)) {
                    $key = $this->advance()->value;
                    $this->expect(Token::COLON, 'Expected : after option name');
                    
                    if ($this->check(Token::BOOL)) {
                        $options[$key] = $this->advance()->value;
                    } else {
                        $this->advance(); // skip value
                    }
                }
                
                if ($this->check(Token::COMMA)) {
                    $this->advance();
                }
            }
            
            $this->expect(Token::RBRACE, 'Expected } after watch options');
        }
        
        return $options;
    }
    
    /**
     * Parse event handler: {on eventName(params)}...{/on}
     */
    private function parseEventHandler(): ?array
    {
        $eventToken = $this->expect(Token::IDENT, 'Expected event name');
        $event = $eventToken->value;
        
        $params = [];
        
        if ($this->check(Token::LPAREN)) {
            $this->advance();
            $params = $this->parseParameterList();
            $this->expect(Token::RPAREN, 'Expected ) after event parameters');
        }
        
        $this->expect(Token::RBRACE, 'Expected } after event handler declaration');
        
        $body = $this->parseBlockContent('on');
        
        return [
            'event' => $event,
            'params' => $params,
            'body' => $body,
        ];
    }
    
    /**
     * Parse function declaration: {func name(params): returnType}...{/func}
     */
    private function parseFunctionDecl(): ?array
    {
        $nameToken = $this->expect(Token::IDENT, 'Expected function name');
        $name = $nameToken->value;
        
        $params = [];
        $returnType = null;
        
        if ($this->check(Token::LPAREN)) {
            $this->advance();
            $params = $this->parseParameterList();
            $this->expect(Token::RPAREN, 'Expected ) after parameters');
        }
        
        if ($this->check(Token::COLON)) {
            $this->advance();
            $returnType = $this->parseTypeAnnotation();
        }
        
        $this->expect(Token::RBRACE, 'Expected } after function declaration');
        
        $body = $this->parseBlockContent('func');
        
        return [
            'name' => $name,
            'params' => $params,
            'returnType' => $returnType,
            'body' => $body,
        ];
    }
    
    /**
     * Parse template block: {template}...{/template}
     */
    private function parseTemplateBlock(): ?array
    {
        $this->expect(Token::RBRACE, 'Expected } after template');
        
        $content = $this->parseBlockContent('template');
        
        return [
            'content' => $content,
        ];
    }
    
    /**
     * Parse style block: {style scoped}...{/style}
     */
    private function parseStyleBlock(): ?array
    {
        $scoped = false;
        $global = false;
        
        if ($this->check(Token::IDENT)) {
            $modifier = $this->peek()->value;
            if ($modifier === 'scoped') {
                $scoped = true;
                $this->advance();
            } elseif ($modifier === 'global') {
                $global = true;
                $this->advance();
            }
        }
        
        $this->expect(Token::RBRACE, 'Expected } after style');
        
        // Collect raw style content until {/style}
        $content = $this->collectRawContent('style');
        
        return [
            'content' => $content,
            'scoped' => $scoped,
            'global' => $global,
        ];
    }
    
    /**
     * Parse client block: {client}...{/client}
     */
    private function parseClientBlock(): ?array
    {
        $this->expect(Token::RBRACE, 'Expected } after client');
        
        // Collect raw JavaScript content until {/client}
        $content = $this->collectRawContent('client');
        
        return [
            'content' => $content,
        ];
    }
    
    /**
     * Parse parameter list: name: type, name2: type2
     */
    private function parseParameterList(): array
    {
        $params = [];
        
        while (!$this->isAtEnd() && !$this->check(Token::RPAREN)) {
            if ($this->check(Token::IDENT)) {
                $name = $this->advance()->value;
                $type = null;
                $defaultValue = null;
                
                if ($this->check(Token::COLON)) {
                    $this->advance();
                    $type = $this->parseTypeAnnotation();
                }
                
                if ($this->check(Token::EQUAL)) {
                    $this->advance();
                    $defaultValue = $this->parseExpressionUntil([Token::COMMA, Token::RPAREN]);
                }
                
                $params[] = [
                    'name' => $name,
                    'type' => $type,
                    'defaultValue' => $defaultValue,
                ];
            }
            
            if ($this->check(Token::COMMA)) {
                $this->advance();
            } else {
                break;
            }
        }
        
        return $params;
    }
    
    /**
     * Parse type annotation (simplified)
     */
    private function parseTypeAnnotation(): ?array
    {
        if (!$this->check(Token::IDENT)) {
            return null;
        }
        
        $typeName = $this->advance()->value;
        $typeArgs = [];
        
        // Check for generic type arguments
        if ($this->check(Token::LT)) {
            $this->advance();
            
            while (!$this->isAtEnd() && !$this->check(Token::GT)) {
                $typeArgs[] = $this->parseTypeAnnotation();
                
                if ($this->check(Token::COMMA)) {
                    $this->advance();
                } else {
                    break;
                }
            }
            
            if ($this->check(Token::GT)) {
                $this->advance();
            }
        }
        
        // Check for array type
        $isArray = false;
        if ($this->check(Token::LBRACKET)) {
            $this->advance();
            $this->expect(Token::RBRACKET, 'Expected ] for array type');
            $isArray = true;
        }
        
        return [
            'type' => 'TypeAnnotation',
            'name' => $typeName,
            'typeArgs' => $typeArgs,
            'isArray' => $isArray,
        ];
    }
    
    /**
     * Parse expression until one of the stop tokens
     */
    private function parseExpressionUntil(array $stopTokens): array
    {
        $tokens = [];
        $depth = 0;
        
        while (!$this->isAtEnd()) {
            $current = $this->peek();
            
            // Track nesting
            if (in_array($current->type, [Token::LBRACE, Token::LPAREN, Token::LBRACKET])) {
                $depth++;
            } elseif (in_array($current->type, [Token::RBRACE, Token::RPAREN, Token::RBRACKET])) {
                if ($depth === 0 && in_array($current->type, $stopTokens)) {
                    break;
                }
                $depth--;
            }
            
            if ($depth === 0 && in_array($current->type, $stopTokens)) {
                break;
            }
            
            $tokens[] = $this->advance();
        }
        
        if (empty($tokens)) {
            return ['type' => 'literal', 'value' => null];
        }
        
        // Add EOF for expression parser
        $tokens[] = new Token(Token::EOF, null, 1, 1, 0);
        
        try {
            return $this->exprParser->parse($tokens);
        } catch (\Exception $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Parse block content until closing tag
     */
    private function parseBlockContent(string $blockName): array
    {
        $content = [];
        
        while (!$this->isAtEnd() && !$this->isClosingTag($blockName)) {
            $content[] = $this->advance();
        }
        
        $this->consumeClosingTag($blockName);
        
        return $content;
    }
    
    /**
     * Collect raw content until closing tag (for style/client blocks)
     */
    private function collectRawContent(string $blockName): string
    {
        $content = '';
        
        while (!$this->isAtEnd() && !$this->isClosingTag($blockName)) {
            $token = $this->advance();
            $content .= $token->value ?? '';
        }
        
        $this->consumeClosingTag($blockName);
        
        return $content;
    }
    
    /**
     * Check if at closing tag
     */
    private function isClosingTag(string $name): bool
    {
        if (!$this->check(Token::LBRACE)) {
            return false;
        }
        
        $next = $this->peek(1);
        if ($next?->type !== Token::SLASH) {
            return false;
        }
        
        $nameToken = $this->peek(2);
        return $nameToken?->type === Token::IDENT && $nameToken->value === $name;
    }
    
    /**
     * Consume closing tag
     */
    private function consumeClosingTag(string $name): void
    {
        if ($this->isClosingTag($name)) {
            $this->advance(); // {
            $this->advance(); // /
            $this->advance(); // name
            $this->expect(Token::RBRACE, "Expected } after /{$name}");
        }
    }
    
    /**
     * Skip to closing brace
     */
    private function skipToClosingBrace(): void
    {
        $depth = 1;
        
        while (!$this->isAtEnd() && $depth > 0) {
            $token = $this->advance();
            if ($token->type === Token::LBRACE) {
                $depth++;
            } elseif ($token->type === Token::RBRACE) {
                $depth--;
            }
        }
    }
    
    // =========================================================================
    // TOKEN HELPERS
    // =========================================================================
    
    private function peek(int $offset = 0): ?Token
    {
        $pos = $this->position + $offset;
        return $pos < $this->length ? $this->tokens[$pos] : null;
    }
    
    private function advance(): ?Token
    {
        if ($this->isAtEnd()) {
            return null;
        }
        return $this->tokens[$this->position++];
    }
    
    private function check(string $type): bool
    {
        $token = $this->peek();
        return $token !== null && $token->type === $type;
    }
    
    private function expect(string $type, string $message): Token
    {
        $token = $this->peek();
        
        if ($token === null || $token->type !== $type) {
            $actual = $token?->type ?? 'EOF';
            throw new ParserException(
                "{$message}, got {$actual}",
                $token?->line ?? 1,
                $token?->column ?? 1,
                $token?->position ?? $this->position
            );
        }
        
        return $this->advance();
    }
    
    private function isAtEnd(): bool
    {
        $token = $this->peek();
        return $token === null || $token->type === Token::EOF;
    }
    
    private function getLocation(?Token $token): array
    {
        return [
            'line' => $token?->line ?? 1,
            'column' => $token?->column ?? 1,
            'position' => $token?->position ?? 0,
        ];
    }
    
    private function addError(string $message, ?Token $token = null): void
    {
        $this->errors[] = [
            'message' => $message,
            'line' => $token?->line ?? 1,
            'column' => $token?->column ?? 1,
        ];
    }
    
    /**
     * Get parsing errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * Get current position
     */
    public function getPosition(): int
    {
        return $this->position;
    }
}
