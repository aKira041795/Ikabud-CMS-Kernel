<?php
/**
 * DiSyL Integration Tests
 * 
 * End-to-end tests for the complete DiSyL pipeline:
 * Lexer → Parser → Compiler
 * 
 * @version 0.1.0
 */

namespace Tests\DiSyL;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\DiSyL\Lexer;
use IkabudKernel\Core\DiSyL\Parser;
use IkabudKernel\Core\DiSyL\Compiler;

class IntegrationTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;
    private Compiler $compiler;
    
    protected function setUp(): void
    {
        $this->lexer = new Lexer();
        $this->parser = new Parser();
        $this->compiler = new Compiler();
    }
    
    /**
     * Helper: Full pipeline compilation
     */
    private function fullPipeline(string $template): array
    {
        // Step 1: Lexer
        $tokens = $this->lexer->tokenize($template);
        
        // Step 2: Parser
        $ast = $this->parser->parse($tokens);
        
        // Step 3: Compiler
        $compiled = $this->compiler->compile($ast);
        
        return $compiled;
    }
    
    /**
     * Test simple template end-to-end
     */
    public function testSimpleTemplateEndToEnd(): void
    {
        $template = '{ikb_section type="hero" title="Welcome"}';
        $result = $this->fullPipeline($template);
        
        $this->assertEquals('document', $result['type']);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertFalse($this->compiler->hasErrors());
        
        $section = $result['children'][0];
        $this->assertEquals('ikb_section', $section['name']);
        $this->assertEquals('hero', $section['attrs']['type']);
        $this->assertEquals('Welcome', $section['attrs']['title']);
        $this->assertEquals('transparent', $section['attrs']['bg']); // default
    }
    
    /**
     * Test nested template end-to-end
     */
    public function testNestedTemplateEndToEnd(): void
    {
        $template = '
        {ikb_section type="content"}
            {ikb_block cols=3}
                {ikb_card title="Card 1" /}
                {ikb_card title="Card 2" /}
                {ikb_card title="Card 3" /}
            {/ikb_block}
        {/ikb_section}
        ';
        
        $result = $this->fullPipeline($template);
        
        $this->assertFalse($this->compiler->hasErrors());
        
        $section = $result['children'][0];
        $this->assertEquals('ikb_section', $section['name']);
        
        // Find block
        $block = null;
        foreach ($section['children'] as $child) {
            if ($child['type'] === 'tag' && $child['name'] === 'ikb_block') {
                $block = $child;
                break;
            }
        }
        
        $this->assertNotNull($block);
        $this->assertEquals(3, $block['attrs']['cols']);
        
        // Count cards
        $cards = array_filter(
            $block['children'],
            fn($c) => $c['type'] === 'tag' && $c['name'] === 'ikb_card'
        );
        $this->assertCount(3, $cards);
    }
    
    /**
     * Test query template end-to-end
     */
    public function testQueryTemplateEndToEnd(): void
    {
        $template = '
        {ikb_query type="post" limit=5 orderby="date" order="desc"}
            {ikb_card title="{item.title}" link="{item.url}" /}
        {/ikb_query}
        ';
        
        $result = $this->fullPipeline($template);
        
        $this->assertFalse($this->compiler->hasErrors());
        
        $query = $result['children'][0];
        $this->assertEquals('ikb_query', $query['name']);
        $this->assertEquals('post', $query['attrs']['type']);
        $this->assertEquals(5, $query['attrs']['limit']);
        $this->assertEquals('date', $query['attrs']['orderby']);
        $this->assertEquals('desc', $query['attrs']['order']);
    }
    
    /**
     * Test control structures end-to-end
     */
    public function testControlStructuresEndToEnd(): void
    {
        $template = '
        {if condition="user.loggedIn"}
            {ikb_text}Welcome back!{/ikb_text}
        {/if}
        
        {for items="posts" as="post"}
            {ikb_card title="{post.title}" /}
        {/for}
        ';
        
        $result = $this->fullPipeline($template);
        
        $this->assertFalse($this->compiler->hasErrors());
        
        // Find if statement
        $ifNode = null;
        foreach ($result['children'] as $child) {
            if ($child['type'] === 'tag' && $child['name'] === 'if') {
                $ifNode = $child;
                break;
            }
        }
        
        $this->assertNotNull($ifNode);
        $this->assertEquals('user.loggedIn', $ifNode['attrs']['condition']);
        
        // Find for loop
        $forNode = null;
        foreach ($result['children'] as $child) {
            if ($child['type'] === 'tag' && $child['name'] === 'for') {
                $forNode = $child;
                break;
            }
        }
        
        $this->assertNotNull($forNode);
        $this->assertEquals('posts', $forNode['attrs']['items']);
        $this->assertEquals('post', $forNode['attrs']['as']);
    }
    
    /**
     * Test image component end-to-end
     */
    public function testImageComponentEndToEnd(): void
    {
        $template = '{ikb_image src="logo.png" alt="Logo" width=200 height=100 lazy=true}';
        $result = $this->fullPipeline($template);
        
        $this->assertFalse($this->compiler->hasErrors());
        
        $image = $result['children'][0];
        $this->assertEquals('ikb_image', $image['name']);
        $this->assertEquals('logo.png', $image['attrs']['src']);
        $this->assertEquals('Logo', $image['attrs']['alt']);
        $this->assertEquals(200, $image['attrs']['width']);
        $this->assertEquals(100, $image['attrs']['height']);
        $this->assertTrue($image['attrs']['lazy']);
        $this->assertTrue($image['attrs']['responsive']); // default
    }
    
    /**
     * Test text component end-to-end
     */
    public function testTextComponentEndToEnd(): void
    {
        $template = '{ikb_text size="xl" weight="bold" align="center"}Hello World{/ikb_text}';
        $result = $this->fullPipeline($template);
        
        $this->assertFalse($this->compiler->hasErrors());
        
        $text = $result['children'][0];
        $this->assertEquals('ikb_text', $text['name']);
        $this->assertEquals('xl', $text['attrs']['size']);
        $this->assertEquals('bold', $text['attrs']['weight']);
        $this->assertEquals('center', $text['attrs']['align']);
    }
    
    /**
     * Test include component end-to-end
     */
    public function testIncludeComponentEndToEnd(): void
    {
        $template = '{include template="header.disyl"}';
        $result = $this->fullPipeline($template);
        
        $this->assertFalse($this->compiler->hasErrors());
        
        $include = $result['children'][0];
        $this->assertEquals('include', $include['name']);
        $this->assertEquals('header.disyl', $include['attrs']['template']);
    }
    
    /**
     * Test complex real-world template
     */
    public function testComplexRealWorldTemplate(): void
    {
        $template = '
        {ikb_section type="hero" bg="#f0f0f0" padding="large"}
            {ikb_container width="xl" center=true}
                {ikb_text size="2xl" weight="bold" align="center"}
                    Welcome to Our Site
                {/ikb_text}
                {ikb_text size="lg" align="center"}
                    Discover amazing content
                {/ikb_text}
            {/ikb_container}
        {/ikb_section}
        
        {ikb_section type="content"}
            {ikb_container width="lg"}
                {ikb_query type="post" limit=6 orderby="date"}
                    {ikb_block cols=3 gap=2}
                        {ikb_card 
                            title="{item.title}" 
                            image="{item.thumbnail}" 
                            link="{item.url}"
                            variant="elevated"
                        /}
                    {/ikb_block}
                {/ikb_query}
            {/ikb_container}
        {/ikb_section}
        
        {ikb_section type="footer" bg="#333" padding="normal"}
            {ikb_text size="sm" color="#fff" align="center"}
                © 2025 Company Name
            {/ikb_text}
        {/ikb_section}
        ';
        
        $result = $this->fullPipeline($template);
        
        $this->assertFalse($this->compiler->hasErrors());
        $this->assertArrayHasKey('metadata', $result);
        
        // Count sections
        $sections = array_filter(
            $result['children'],
            fn($c) => $c['type'] === 'tag' && $c['name'] === 'ikb_section'
        );
        $this->assertCount(3, $sections);
        
        // Verify compilation metadata
        $metadata = $result['metadata'];
        $this->assertIsFloat($metadata['compilation_time_ms']);
        $this->assertEmpty($metadata['errors']);
        $this->assertEmpty($metadata['warnings']);
    }
    
    /**
     * Test error handling end-to-end
     */
    public function testErrorHandlingEndToEnd(): void
    {
        // Invalid enum value
        $template = '{ikb_section type="invalid-type"}';
        $result = $this->fullPipeline($template);
        
        $this->assertTrue($this->compiler->hasErrors());
        $errors = $this->compiler->getErrors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('type', $errors[0]['message']);
    }
    
    /**
     * Test warning handling end-to-end
     */
    public function testWarningHandlingEndToEnd(): void
    {
        // Unknown component
        $template = '{custom_unknown_component}';
        $result = $this->fullPipeline($template);
        
        $this->assertTrue($this->compiler->hasWarnings());
        $warnings = $this->compiler->getWarnings();
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Unknown component', $warnings[0]['message']);
    }
    
    /**
     * Test performance with large template
     */
    public function testPerformanceWithLargeTemplate(): void
    {
        // Generate large template
        $cards = '';
        for ($i = 0; $i < 50; $i++) {
            $cards .= sprintf('{ikb_card title="Card %d" /}', $i);
        }
        
        $template = sprintf('
        {ikb_section}
            {ikb_block cols=4}
                %s
            {/ikb_block}
        {/ikb_section}
        ', $cards);
        
        $startTime = microtime(true);
        $result = $this->fullPipeline($template);
        $duration = (microtime(true) - $startTime) * 1000;
        
        $this->assertFalse($this->compiler->hasErrors());
        $this->assertLessThan(100, $duration); // Should compile in < 100ms
        
        // Verify compilation time is recorded
        $this->assertArrayHasKey('compilation_time_ms', $result['metadata']);
    }
}
