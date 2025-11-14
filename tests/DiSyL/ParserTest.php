<?php
/**
 * DiSyL Parser Tests
 * 
 * @version 0.1.0
 */

namespace Tests\DiSyL;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\DiSyL\Lexer;
use IkabudKernel\Core\DiSyL\Parser;
use IkabudKernel\Core\DiSyL\Exceptions\ParserException;

class ParserTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;
    
    protected function setUp(): void
    {
        $this->lexer = new Lexer();
        $this->parser = new Parser();
    }
    
    /**
     * Helper: Parse DiSyL template string
     */
    private function parseTemplate(string $template): array
    {
        $tokens = $this->lexer->tokenize($template);
        return $this->parser->parse($tokens);
    }
    
    /**
     * Test simple tag parsing
     */
    public function testSimpleTag(): void
    {
        $ast = $this->parseTemplate('{ikb_section /}');
        
        $this->assertEquals('document', $ast['type']);
        $this->assertCount(1, $ast['children']);
        
        $tag = $ast['children'][0];
        $this->assertEquals('tag', $tag['type']);
        $this->assertEquals('ikb_section', $tag['name']);
        $this->assertEmpty($tag['attrs']);
        $this->assertEmpty($tag['children']);
        $this->assertTrue($tag['self_closing']);
    }
    
    /**
     * Test self-closing tag
     */
    public function testSelfClosingTag(): void
    {
        $ast = $this->parseTemplate('{ikb_card /}');
        
        $tag = $ast['children'][0];
        $this->assertEquals('ikb_card', $tag['name']);
        $this->assertTrue($tag['self_closing']);
        $this->assertEmpty($tag['children']);
    }
    
    /**
     * Test tag with opening and closing
     */
    public function testOpeningAndClosingTag(): void
    {
        $ast = $this->parseTemplate('{ikb_section}{/ikb_section}');
        
        $tag = $ast['children'][0];
        $this->assertEquals('ikb_section', $tag['name']);
        $this->assertFalse($tag['self_closing']);
        $this->assertEmpty($tag['children']);
    }
    
    /**
     * Test tag with text content
     */
    public function testTagWithTextContent(): void
    {
        $ast = $this->parseTemplate('{ikb_text}Hello World{/ikb_text}');
        
        $tag = $ast['children'][0];
        $this->assertEquals('ikb_text', $tag['name']);
        $this->assertCount(1, $tag['children']);
        
        $textNode = $tag['children'][0];
        $this->assertEquals('text', $textNode['type']);
        $this->assertEquals('Hello World', $textNode['value']);
    }
    
    /**
     * Test nested tags
     */
    public function testNestedTags(): void
    {
        $ast = $this->parseTemplate('{ikb_section}{ikb_block}{/ikb_block}{/ikb_section}');
        
        $section = $ast['children'][0];
        $this->assertEquals('ikb_section', $section['name']);
        $this->assertCount(1, $section['children']);
        
        $block = $section['children'][0];
        $this->assertEquals('ikb_block', $block['name']);
    }
    
    /**
     * Test deeply nested tags
     */
    public function testDeeplyNestedTags(): void
    {
        $template = '{ikb_section}{ikb_block}{ikb_card}{/ikb_card}{/ikb_block}{/ikb_section}';
        $ast = $this->parseTemplate($template);
        
        $section = $ast['children'][0];
        $block = $section['children'][0];
        $card = $block['children'][0];
        
        $this->assertEquals('ikb_section', $section['name']);
        $this->assertEquals('ikb_block', $block['name']);
        $this->assertEquals('ikb_card', $card['name']);
    }
    
    /**
     * Test tag with single attribute
     */
    public function testTagWithSingleAttribute(): void
    {
        $ast = $this->parseTemplate('{ikb_section title="Welcome"}');
        
        $tag = $ast['children'][0];
        $this->assertArrayHasKey('title', $tag['attrs']);
        $this->assertEquals('Welcome', $tag['attrs']['title']);
    }
    
    /**
     * Test tag with multiple attributes
     */
    public function testTagWithMultipleAttributes(): void
    {
        $ast = $this->parseTemplate('{ikb_section type="hero" title="Welcome" bg="#f0f0f0"}');
        
        $tag = $ast['children'][0];
        $this->assertCount(3, $tag['attrs']);
        $this->assertEquals('hero', $tag['attrs']['type']);
        $this->assertEquals('Welcome', $tag['attrs']['title']);
        $this->assertEquals('#f0f0f0', $tag['attrs']['bg']);
    }
    
    /**
     * Test tag with number attribute
     */
    public function testTagWithNumberAttribute(): void
    {
        $ast = $this->parseTemplate('{ikb_query limit=10}');
        
        $tag = $ast['children'][0];
        $this->assertEquals(10, $tag['attrs']['limit']);
        $this->assertIsInt($tag['attrs']['limit']);
    }
    
    /**
     * Test tag with float attribute
     */
    public function testTagWithFloatAttribute(): void
    {
        $ast = $this->parseTemplate('{ikb_block gap=1.5}');
        
        $tag = $ast['children'][0];
        $this->assertEquals(1.5, $tag['attrs']['gap']);
        $this->assertIsFloat($tag['attrs']['gap']);
    }
    
    /**
     * Test tag with boolean attributes
     */
    public function testTagWithBooleanAttributes(): void
    {
        $ast = $this->parseTemplate('{ikb_image lazy=true responsive=false}');
        
        $tag = $ast['children'][0];
        $this->assertTrue($tag['attrs']['lazy']);
        $this->assertFalse($tag['attrs']['responsive']);
    }
    
    /**
     * Test tag with null attribute
     */
    public function testTagWithNullAttribute(): void
    {
        $ast = $this->parseTemplate('{ikb_card icon=null}');
        
        $tag = $ast['children'][0];
        $this->assertArrayHasKey('icon', $tag['attrs']);
        $this->assertNull($tag['attrs']['icon']);
    }
    
    /**
     * Test text outside tags
     */
    public function testTextOutsideTags(): void
    {
        $ast = $this->parseTemplate('Hello World');
        
        $this->assertCount(1, $ast['children']);
        $textNode = $ast['children'][0];
        $this->assertEquals('text', $textNode['type']);
        $this->assertEquals('Hello World', $textNode['value']);
    }
    
    /**
     * Test mixed text and tags
     */
    public function testMixedTextAndTags(): void
    {
        $ast = $this->parseTemplate('Before {ikb_text}Middle{/ikb_text} After');
        
        $this->assertCount(3, $ast['children']);
        $this->assertEquals('text', $ast['children'][0]['type']);
        $this->assertEquals('Before ', $ast['children'][0]['value']);
        $this->assertEquals('tag', $ast['children'][1]['type']);
        $this->assertEquals('text', $ast['children'][2]['type']);
        $this->assertEquals(' After', $ast['children'][2]['value']);
    }
    
    /**
     * Test comment parsing
     */
    public function testComment(): void
    {
        $ast = $this->parseTemplate('{!-- This is a comment --}');
        
        $this->assertCount(1, $ast['children']);
        $comment = $ast['children'][0];
        $this->assertEquals('comment', $comment['type']);
        $this->assertEquals('This is a comment', $comment['value']);
    }
    
    /**
     * Test multiple siblings
     */
    public function testMultipleSiblings(): void
    {
        $ast = $this->parseTemplate('{ikb_card /}{ikb_card /}{ikb_card /}');
        
        $this->assertCount(3, $ast['children']);
        foreach ($ast['children'] as $child) {
            $this->assertEquals('tag', $child['type']);
            $this->assertEquals('ikb_card', $child['name']);
        }
    }
    
    /**
     * Test complex nested structure
     */
    public function testComplexNestedStructure(): void
    {
        $template = '{ikb_section type="hero"}
            {ikb_block cols=3}
                {ikb_card title="Card 1" /}
                {ikb_card title="Card 2" /}
                {ikb_card title="Card 3" /}
            {/ikb_block}
        {/ikb_section}';
        
        $ast = $this->parseTemplate($template);
        
        $section = $ast['children'][0];
        $this->assertEquals('ikb_section', $section['name']);
        $this->assertEquals('hero', $section['attrs']['type']);
        
        // Find the block (skip text nodes)
        $block = null;
        foreach ($section['children'] as $child) {
            if ($child['type'] === 'tag' && $child['name'] === 'ikb_block') {
                $block = $child;
                break;
            }
        }
        
        $this->assertNotNull($block);
        $this->assertEquals(3, $block['attrs']['cols']);
        
        // Count card tags (skip text nodes)
        $cards = array_filter($block['children'], fn($c) => $c['type'] === 'tag');
        $this->assertCount(3, $cards);
    }
    
    /**
     * Test location tracking
     */
    public function testLocationTracking(): void
    {
        $ast = $this->parseTemplate('{ikb_section}');
        
        $tag = $ast['children'][0];
        $this->assertArrayHasKey('loc', $tag);
        $this->assertArrayHasKey('line', $tag['loc']);
        $this->assertArrayHasKey('column', $tag['loc']);
        $this->assertArrayHasKey('position', $tag['loc']);
        
        $this->assertEquals(1, $tag['loc']['line']);
        $this->assertEquals(1, $tag['loc']['column']);
    }
    
    /**
     * Test AST structure
     */
    public function testASTStructure(): void
    {
        $ast = $this->parseTemplate('{ikb_section}');
        
        $this->assertArrayHasKey('type', $ast);
        $this->assertArrayHasKey('version', $ast);
        $this->assertArrayHasKey('children', $ast);
        $this->assertArrayHasKey('errors', $ast);
        
        $this->assertEquals('document', $ast['type']);
        $this->assertEquals('0.1', $ast['version']);
        $this->assertIsArray($ast['children']);
        $this->assertIsArray($ast['errors']);
    }
    
    /**
     * Test empty template
     */
    public function testEmptyTemplate(): void
    {
        $ast = $this->parseTemplate('');
        
        $this->assertEquals('document', $ast['type']);
        $this->assertEmpty($ast['children']);
        // Parser doesn't track errors in AST, they're thrown as exceptions
    }
    
    /**
     * Test whitespace handling
     */
    public function testWhitespaceHandling(): void
    {
        $template = "
        {ikb_section}
            Content
        {/ikb_section}
        ";
        
        $ast = $this->parseTemplate($template);
        
        // Should have text nodes for whitespace
        $this->assertGreaterThan(0, count($ast['children']));
    }
    
    /**
     * Test mismatched tags (error handling)
     */
    public function testMismatchedTags(): void
    {
        $this->expectException(\IkabudKernel\Core\DiSyL\Exceptions\ParserException::class);
        $this->expectExceptionMessage('Expected opening brace');
        
        $ast = $this->parseTemplate('{ikb_section}{/ikb_block}');
    }
    
    /**
     * Test unclosed tag
     */
    public function testUnclosedTag(): void
    {
        $ast = $this->parseTemplate('{ikb_section}');
        
        // Tag should be parsed but marked as self-closing since no closing tag found
        $tag = $ast['children'][0];
        $this->assertEquals('ikb_section', $tag['name']);
    }
    
    /**
     * Test multiple root-level tags
     */
    public function testMultipleRootLevelTags(): void
    {
        $ast = $this->parseTemplate('{ikb_section /}{ikb_block /}');
        
        $this->assertCount(2, $ast['children']);
        $this->assertEquals('ikb_section', $ast['children'][0]['name']);
        $this->assertEquals('ikb_block', $ast['children'][1]['name']);
    }
    
    /**
     * Test tag with expression-like attribute (future feature)
     */
    public function testTagWithExpressionAttribute(): void
    {
        // For now, expressions are stored as strings
        $ast = $this->parseTemplate('{ikb_card title="{item.title}"}');
        
        $tag = $ast['children'][0];
        $this->assertEquals('{item.title}', $tag['attrs']['title']);
    }
}
