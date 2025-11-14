<?php
/**
 * DiSyL Lexer Tests
 * 
 * @version 0.1.0
 */

namespace Tests\DiSyL;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\DiSyL\Lexer;
use IkabudKernel\Core\DiSyL\Token;
use IkabudKernel\Core\DiSyL\Exceptions\LexerException;

class LexerTest extends TestCase
{
    private Lexer $lexer;
    
    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }
    
    /**
     * Test simple tag tokenization
     */
    public function testSimpleTag(): void
    {
        $input = '{ikb_section}';
        $tokens = $this->lexer->tokenize($input);
        
        $this->assertCount(4, $tokens); // LBRACE, IDENT, RBRACE, EOF
        $this->assertEquals(Token::LBRACE, $tokens[0]->type);
        $this->assertEquals(Token::IDENT, $tokens[1]->type);
        $this->assertEquals('ikb_section', $tokens[1]->value);
        $this->assertEquals(Token::RBRACE, $tokens[2]->type);
        $this->assertEquals(Token::EOF, $tokens[3]->type);
    }
    
    /**
     * Test closing tag tokenization
     */
    public function testClosingTag(): void
    {
        $input = '{/ikb_section}';
        $tokens = $this->lexer->tokenize($input);
        
        $this->assertCount(5, $tokens); // LBRACE, SLASH, IDENT, RBRACE, EOF
        $this->assertEquals(Token::LBRACE, $tokens[0]->type);
        $this->assertEquals(Token::SLASH, $tokens[1]->type);
        $this->assertEquals(Token::IDENT, $tokens[2]->type);
        $this->assertEquals('ikb_section', $tokens[2]->value);
        $this->assertEquals(Token::RBRACE, $tokens[3]->type);
    }
    
    /**
     * Test self-closing tag tokenization
     */
    public function testSelfClosingTag(): void
    {
        $input = '{ikb_card /}';
        $tokens = $this->lexer->tokenize($input);
        
        $this->assertCount(5, $tokens); // LBRACE, IDENT, SLASH, RBRACE, EOF
        $this->assertEquals(Token::LBRACE, $tokens[0]->type);
        $this->assertEquals(Token::IDENT, $tokens[1]->type);
        $this->assertEquals('ikb_card', $tokens[1]->value);
        $this->assertEquals(Token::SLASH, $tokens[2]->type);
        $this->assertEquals(Token::RBRACE, $tokens[3]->type);
    }
    
    /**
     * Test tag with string attribute
     */
    public function testTagWithStringAttribute(): void
    {
        $input = '{ikb_section title="Welcome"}';
        $tokens = $this->lexer->tokenize($input);
        
        $this->assertCount(7, $tokens); // LBRACE, IDENT, IDENT, EQUAL, STRING, RBRACE, EOF
        $this->assertEquals(Token::IDENT, $tokens[1]->type);
        $this->assertEquals('ikb_section', $tokens[1]->value);
        $this->assertEquals(Token::IDENT, $tokens[2]->type);
        $this->assertEquals('title', $tokens[2]->value);
        $this->assertEquals(Token::EQUAL, $tokens[3]->type);
        $this->assertEquals(Token::STRING, $tokens[4]->type);
        $this->assertEquals('Welcome', $tokens[4]->value);
    }
    
    /**
     * Test tag with number attribute
     */
    public function testTagWithNumberAttribute(): void
    {
        $input = '{ikb_query limit=10}';
        $tokens = $this->lexer->tokenize($input);
        
        $this->assertCount(7, $tokens); // LBRACE, IDENT, IDENT, EQUAL, NUMBER, RBRACE, EOF
        $this->assertEquals('limit', $tokens[2]->value);
        $this->assertEquals(Token::NUMBER, $tokens[4]->type);
        $this->assertEquals(10, $tokens[4]->value);
    }
    
    /**
     * Test tag with float attribute
     */
    public function testTagWithFloatAttribute(): void
    {
        $input = '{ikb_block gap=1.5}';
        $tokens = $this->lexer->tokenize($input);
        
        $this->assertEquals(Token::NUMBER, $tokens[4]->type);
        $this->assertEquals(1.5, $tokens[4]->value);
    }
    
    /**
     * Test tag with boolean attributes
     */
    public function testTagWithBooleanAttributes(): void
    {
        $input = '{ikb_image lazy=true responsive=false}';
        $tokens = $this->lexer->tokenize($input);
        
        // Find boolean tokens
        $boolTokens = array_filter($tokens, fn($t) => $t->type === Token::BOOL);
        $this->assertCount(2, $boolTokens);
        
        $values = array_map(fn($t) => $t->value, $boolTokens);
        $this->assertContains(true, $values);
        $this->assertContains(false, $values);
    }
    
    /**
     * Test tag with null attribute
     */
    public function testTagWithNullAttribute(): void
    {
        $input = '{ikb_card icon=null}';
        $tokens = $this->lexer->tokenize($input);
        
        $nullToken = array_filter($tokens, fn($t) => $t->type === Token::NULL);
        $this->assertCount(1, $nullToken);
        $this->assertNull(array_values($nullToken)[0]->value);
    }
    
    /**
     * Test tag with multiple attributes
     */
    public function testTagWithMultipleAttributes(): void
    {
        $input = '{ikb_section type="hero" title="Welcome" bg="#f0f0f0"}';
        $tokens = $this->lexer->tokenize($input);
        
        // Count attribute pairs (name=value)
        $equalTokens = array_filter($tokens, fn($t) => $t->type === Token::EQUAL);
        $this->assertCount(3, $equalTokens);
    }
    
    /**
     * Test text outside tags
     */
    public function testTextOutsideTags(): void
    {
        $input = 'Hello World';
        $tokens = $this->lexer->tokenize($input);
        
        $this->assertCount(2, $tokens); // TEXT, EOF
        $this->assertEquals(Token::TEXT, $tokens[0]->type);
        $this->assertEquals('Hello World', $tokens[0]->value);
    }
    
    /**
     * Test mixed text and tags
     */
    public function testMixedTextAndTags(): void
    {
        $input = 'Hello {ikb_text}World{/ikb_text}!';
        $tokens = $this->lexer->tokenize($input);
        
        $textTokens = array_filter($tokens, fn($t) => $t->type === Token::TEXT);
        $this->assertCount(3, $textTokens); // "Hello ", "World", "!"
    }
    
    /**
     * Test comment tokenization
     */
    public function testComment(): void
    {
        $input = '{!-- This is a comment --}';
        $tokens = $this->lexer->tokenize($input);
        
        $this->assertCount(2, $tokens); // COMMENT, EOF
        $this->assertEquals(Token::COMMENT, $tokens[0]->type);
        $this->assertEquals('This is a comment', $tokens[0]->value);
    }
    
    /**
     * Test string with escape sequences
     */
    public function testStringWithEscapes(): void
    {
        $input = '{ikb_text value="He said \"Hello\""}';
        $tokens = $this->lexer->tokenize($input);
        
        $stringToken = array_filter($tokens, fn($t) => $t->type === Token::STRING);
        $this->assertEquals('He said "Hello"', array_values($stringToken)[0]->value);
    }
    
    /**
     * Test line and column tracking
     */
    public function testLineAndColumnTracking(): void
    {
        $input = "Line 1\n{ikb_section}\nLine 3";
        $tokens = $this->lexer->tokenize($input);
        
        // Find the ikb_section token
        $sectionToken = array_filter($tokens, fn($t) => $t->value === 'ikb_section');
        $token = array_values($sectionToken)[0];
        
        $this->assertEquals(2, $token->line);
    }
    
    /**
     * Test unterminated string throws exception
     */
    public function testUnterminatedStringThrowsException(): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessage('Unterminated string');
        
        $input = '{ikb_text value="unterminated';
        $this->lexer->tokenize($input);
    }
    
    /**
     * Test negative numbers
     */
    public function testNegativeNumbers(): void
    {
        $input = '{ikb_block offset=-10}';
        $tokens = $this->lexer->tokenize($input);
        
        $numberToken = array_filter($tokens, fn($t) => $t->type === Token::NUMBER);
        $this->assertEquals(-10, array_values($numberToken)[0]->value);
    }
    
    /**
     * Test namespace-style identifiers
     */
    public function testNamespaceIdentifiers(): void
    {
        $input = '{custom:component}';
        $tokens = $this->lexer->tokenize($input);
        
        $identToken = array_filter($tokens, fn($t) => $t->type === Token::IDENT);
        $this->assertEquals('custom:component', array_values($identToken)[0]->value);
    }
    
    /**
     * Test hyphenated identifiers
     */
    public function testHyphenatedIdentifiers(): void
    {
        $input = '{ikb-custom-tag}';
        $tokens = $this->lexer->tokenize($input);
        
        $identToken = array_filter($tokens, fn($t) => $t->type === Token::IDENT);
        $this->assertEquals('ikb-custom-tag', array_values($identToken)[0]->value);
    }
}
