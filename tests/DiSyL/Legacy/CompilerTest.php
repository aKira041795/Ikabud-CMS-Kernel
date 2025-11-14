<?php
/**
 * DiSyL Compiler Tests
 * 
 * @version 0.1.0
 */

namespace Tests\DiSyL;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\DiSyL\Lexer;
use IkabudKernel\Core\DiSyL\Parser;
use IkabudKernel\Core\DiSyL\Compiler;
use IkabudKernel\Core\DiSyL\ComponentRegistry;

class CompilerTest extends TestCase
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
     * Helper: Compile DiSyL template string
     */
    private function compileTemplate(string $template): array
    {
        $tokens = $this->lexer->tokenize($template);
        $ast = $this->parser->parse($tokens);
        return $this->compiler->compile($ast);
    }
    
    /**
     * Test basic compilation
     */
    public function testBasicCompilation(): void
    {
        $ast = $this->compileTemplate('{ikb_section}');
        
        $this->assertEquals('document', $ast['type']);
        $this->assertArrayHasKey('metadata', $ast);
        $this->assertArrayHasKey('compilation_time_ms', $ast['metadata']);
        $this->assertArrayHasKey('cache_key', $ast['metadata']);
    }
    
    /**
     * Test default value application
     */
    public function testDefaultValueApplication(): void
    {
        $ast = $this->compileTemplate('{ikb_section}');
        
        $section = $ast['children'][0];
        $this->assertEquals('content', $section['attrs']['type']); // default
        $this->assertEquals('transparent', $section['attrs']['bg']); // default
        $this->assertEquals('normal', $section['attrs']['padding']); // default
    }
    
    /**
     * Test attribute validation
     */
    public function testAttributeValidation(): void
    {
        // Valid attributes
        $ast = $this->compileTemplate('{ikb_section type="hero"}');
        $this->assertEmpty($this->compiler->getErrors());
        
        // Invalid enum value
        $ast = $this->compileTemplate('{ikb_section type="invalid"}');
        $this->assertNotEmpty($this->compiler->getErrors());
    }
    
    /**
     * Test required attribute validation
     */
    public function testRequiredAttributeValidation(): void
    {
        // Missing required attributes
        $ast = $this->compileTemplate('{ikb_image}');
        $errors = $this->compiler->getErrors();
        
        $this->assertNotEmpty($errors);
        // Should have errors for missing 'src' and 'alt'
        $this->assertGreaterThanOrEqual(2, count($errors));
    }
    
    /**
     * Test range validation
     */
    public function testRangeValidation(): void
    {
        // Valid range
        $ast = $this->compileTemplate('{ikb_block cols=5}');
        $this->assertEmpty($this->compiler->getErrors());
        
        // Out of range
        $ast = $this->compileTemplate('{ikb_block cols=15}');
        $this->assertNotEmpty($this->compiler->getErrors());
    }
    
    /**
     * Test unknown component warning
     */
    public function testUnknownComponentWarning(): void
    {
        $ast = $this->compileTemplate('{unknown_component}');
        
        $warnings = $this->compiler->getWarnings();
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Unknown component', $warnings[0]['message']);
    }
    
    /**
     * Test leaf component with children warning
     */
    public function testLeafComponentWithChildrenWarning(): void
    {
        $ast = $this->compileTemplate('{ikb_image src="test.jpg" alt="Test"}Child{/ikb_image}');
        
        $warnings = $this->compiler->getWarnings();
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('leaf', $warnings[0]['message']);
    }
    
    /**
     * Test optimization: empty text node removal
     */
    public function testOptimizationEmptyTextRemoval(): void
    {
        $template = '{ikb_section}   {/ikb_section}';
        $ast = $this->compileTemplate($template);
        
        $section = $ast['children'][0];
        // Empty/whitespace-only text nodes should be removed
        $textNodes = array_filter(
            $section['children'],
            fn($c) => $c['type'] === 'text'
        );
        $this->assertEmpty($textNodes);
    }
    
    /**
     * Test optimization: consecutive text node merging
     */
    public function testOptimizationTextMerging(): void
    {
        // This would require a specific AST structure
        // For now, just verify optimization runs
        $ast = $this->compileTemplate('{ikb_section}Hello World{/ikb_section}');
        $this->assertArrayHasKey('metadata', $ast);
    }
    
    /**
     * Test nested component validation
     */
    public function testNestedComponentValidation(): void
    {
        $template = '{ikb_section type="hero"}
            {ikb_block cols=3}
                {ikb_card title="Test"}
            {/ikb_block}
        {/ikb_section}';
        
        $ast = $this->compileTemplate($template);
        
        // Should apply defaults at all levels
        $section = $ast['children'][0];
        $this->assertEquals('hero', $section['attrs']['type']);
        $this->assertEquals('transparent', $section['attrs']['bg']); // default
        
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
        $this->assertEquals(1, $block['attrs']['gap']); // default
    }
    
    /**
     * Test compilation metadata
     */
    public function testCompilationMetadata(): void
    {
        $ast = $this->compileTemplate('{ikb_section}');
        
        $metadata = $ast['metadata'];
        $this->assertArrayHasKey('compilation_time_ms', $metadata);
        $this->assertArrayHasKey('cache_key', $metadata);
        $this->assertArrayHasKey('version', $metadata);
        $this->assertArrayHasKey('compiled_at', $metadata);
        $this->assertArrayHasKey('errors', $metadata);
        $this->assertArrayHasKey('warnings', $metadata);
        
        $this->assertEquals('0.1', $metadata['version']);
        $this->assertIsFloat($metadata['compilation_time_ms']);
        $this->assertIsInt($metadata['compiled_at']);
    }
    
    /**
     * Test hasErrors method
     */
    public function testHasErrors(): void
    {
        // Valid template
        $ast = $this->compileTemplate('{ikb_section}');
        $this->assertFalse($this->compiler->hasErrors());
        
        // Invalid template
        $ast = $this->compileTemplate('{ikb_section type="invalid"}');
        $this->assertTrue($this->compiler->hasErrors());
    }
    
    /**
     * Test hasWarnings method
     */
    public function testHasWarnings(): void
    {
        // No warnings
        $ast = $this->compileTemplate('{ikb_section}');
        $this->assertFalse($this->compiler->hasWarnings());
        
        // With warning
        $ast = $this->compileTemplate('{unknown_component}');
        $this->assertTrue($this->compiler->hasWarnings());
    }
    
    /**
     * Test getErrors method
     */
    public function testGetErrors(): void
    {
        $ast = $this->compileTemplate('{ikb_section type="invalid"}');
        $errors = $this->compiler->getErrors();
        
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('type', $errors[0]);
        $this->assertArrayHasKey('message', $errors[0]);
        $this->assertEquals('error', $errors[0]['type']);
    }
    
    /**
     * Test getWarnings method
     */
    public function testGetWarnings(): void
    {
        $ast = $this->compileTemplate('{unknown_component}');
        $warnings = $this->compiler->getWarnings();
        
        $this->assertIsArray($warnings);
        $this->assertNotEmpty($warnings);
        $this->assertArrayHasKey('type', $warnings[0]);
        $this->assertArrayHasKey('message', $warnings[0]);
        $this->assertEquals('warning', $warnings[0]['type']);
    }
    
    /**
     * Test complex template compilation
     */
    public function testComplexTemplateCompilation(): void
    {
        $template = '
        {ikb_section type="hero" title="Welcome"}
            {ikb_container width="lg"}
                {ikb_block cols=2 gap=2}
                    {ikb_card title="Card 1" variant="elevated" /}
                    {ikb_card title="Card 2" variant="outlined" /}
                {/ikb_block}
            {/ikb_container}
        {/ikb_section}
        ';
        
        $ast = $this->compileTemplate($template);
        
        $this->assertFalse($this->compiler->hasErrors());
        $this->assertArrayHasKey('metadata', $ast);
        
        // Verify structure is maintained
        $section = $ast['children'][0];
        $this->assertEquals('ikb_section', $section['name']);
        $this->assertEquals('hero', $section['attrs']['type']);
    }
}
