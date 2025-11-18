<?php
/**
 * DiSyL CMS Header Declaration Tests
 * 
 * Tests for {ikb_cms} header declaration support
 * 
 * @version 0.6.0
 */

namespace Tests\DiSyL;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\DiSyL\Engine;
use IkabudKernel\Core\DiSyL\Lexer;
use IkabudKernel\Core\DiSyL\Parser;
use IkabudKernel\Core\DiSyL\Compiler;
use IkabudKernel\Core\DiSyL\CMSLoader;
use IkabudKernel\Core\DiSyL\CMSHeaderValidator;
use IkabudKernel\Core\DiSyL\ModularManifestLoader;

class CMSHeaderTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear any cached data
        CMSLoader::clearCache();
    }
    
    /**
     * Test valid CMS header declaration
     */
    public function testValidCMSHeader()
    {
        $template = '{ikb_cms type="drupal" set="components,filters" /}

{drupal_articles limit=6 /}';
        
        $lexer = new Lexer();
        $parser = new Parser();
        
        $tokens = $lexer->tokenize($template);
        $ast = $parser->parse($tokens);
        
        // Check that CMS header was parsed
        $this->assertNotNull($ast['cms_header']);
        $this->assertEquals('drupal', $ast['cms_header']['type']);
        $this->assertContains('components', $ast['cms_header']['sets']);
        $this->assertContains('filters', $ast['cms_header']['sets']);
    }
    
    /**
     * Test CMS header without set attribute
     */
    public function testCMSHeaderWithoutSet()
    {
        $template = '{ikb_cms type="wordpress" /}

{wp_posts limit=5 /}';
        
        $lexer = new Lexer();
        $parser = new Parser();
        
        $tokens = $lexer->tokenize($template);
        $ast = $parser->parse($tokens);
        
        $this->assertNotNull($ast['cms_header']);
        $this->assertEquals('wordpress', $ast['cms_header']['type']);
        $this->assertEmpty($ast['cms_header']['sets']);
    }
    
    /**
     * Test CMS header with comments before it
     */
    public function testCMSHeaderWithLeadingComments()
    {
        $template = '{!-- This is a Drupal template --}
{ikb_cms type="drupal" set="components" /}

{drupal_menu name="main" /}';
        
        $lexer = new Lexer();
        $parser = new Parser();
        
        $tokens = $lexer->tokenize($template);
        $ast = $parser->parse($tokens);
        
        $this->assertNotNull($ast['cms_header']);
        $this->assertEquals('drupal', $ast['cms_header']['type']);
    }
    
    /**
     * Test CMS header with whitespace before it
     */
    public function testCMSHeaderWithLeadingWhitespace()
    {
        $template = '

{ikb_cms type="joomla" /}

{joomla_articles /}';
        
        $lexer = new Lexer();
        $parser = new Parser();
        
        $tokens = $lexer->tokenize($template);
        $ast = $parser->parse($tokens);
        
        $this->assertNotNull($ast['cms_header']);
        $this->assertEquals('joomla', $ast['cms_header']['type']);
    }
    
    /**
     * Test missing type attribute
     */
    public function testMissingTypeAttribute()
    {
        $template = '{ikb_cms set="components" /}

{content /}';
        
        $lexer = new Lexer();
        $parser = new Parser();
        
        $tokens = $lexer->tokenize($template);
        $ast = $parser->parse($tokens);
        
        // Should have errors
        $this->assertNotEmpty($ast['errors']);
    }
    
    /**
     * Test invalid CMS type
     */
    public function testInvalidCMSType()
    {
        $template = '{ikb_cms type="invalid" /}';
        
        $engine = new Engine();
        $ast = $engine->compile($template);
        
        // Should have compilation errors
        $this->assertNotEmpty($ast['metadata']['errors']);
    }
    
    /**
     * Test invalid set name
     */
    public function testInvalidSetName()
    {
        $template = '{ikb_cms type="drupal" set="invalid_set" /}';
        
        $engine = new Engine();
        $ast = $engine->compile($template);
        
        // Should have warnings
        $this->assertNotEmpty($ast['metadata']['warnings']);
    }
    
    /**
     * Test multiple CMS types in same theme package
     */
    public function testMultipleCMSTypes()
    {
        $wordpressTemplate = '{ikb_cms type="wordpress" set="components" /}
{wp_posts limit=5 /}';
        
        $drupalTemplate = '{ikb_cms type="drupal" set="components" /}
{drupal_articles limit=6 /}';
        
        $engine = new Engine();
        
        // Compile WordPress template
        $wpAst = $engine->compile($wordpressTemplate);
        $this->assertEquals('wordpress', $wpAst['cms_header']['type']);
        
        // Compile Drupal template
        $drupalAst = $engine->compile($drupalTemplate);
        $this->assertEquals('drupal', $drupalAst['cms_header']['type']);
    }
    
    /**
     * Test generic CMS type
     */
    public function testGenericCMSType()
    {
        $template = '{ikb_cms type="generic" /}
{ikb_text value="Hello World" /}';
        
        $engine = new Engine();
        $ast = $engine->compile($template);
        
        $this->assertNotNull($ast['cms_header']);
        $this->assertEquals('generic', $ast['cms_header']['type']);
    }
    
    /**
     * Test template without CMS header (backward compatibility)
     */
    public function testTemplateWithoutHeader()
    {
        $template = '{ikb_text value="Hello World" /}';
        
        $engine = new Engine();
        $ast = $engine->compile($template);
        
        // Should compile successfully without header
        $this->assertNull($ast['cms_header']);
        $this->assertEmpty($ast['metadata']['errors']);
    }
    
    /**
     * Test default CMS type fallback
     */
    public function testDefaultCMSTypeFallback()
    {
        $template = '{wp_posts limit=5 /}';
        
        $engine = new Engine(null, 'wordpress');
        $ast = $engine->compile($template);
        
        // Should use default CMS type
        $this->assertNull($ast['cms_header']);
    }
    
    /**
     * Test CMS header overrides default
     */
    public function testCMSHeaderOverridesDefault()
    {
        $template = '{ikb_cms type="drupal" /}
{drupal_articles /}';
        
        $engine = new Engine(null, 'wordpress');
        $ast = $engine->compile($template);
        
        // Header should override default
        $this->assertNotNull($ast['cms_header']);
        $this->assertEquals('drupal', $ast['cms_header']['type']);
    }
    
    /**
     * Test CMSLoader validation
     */
    public function testCMSLoaderValidation()
    {
        $this->assertTrue(CMSLoader::isValidCMSType('wordpress'));
        $this->assertTrue(CMSLoader::isValidCMSType('drupal'));
        $this->assertTrue(CMSLoader::isValidCMSType('joomla'));
        $this->assertTrue(CMSLoader::isValidCMSType('generic'));
        $this->assertFalse(CMSLoader::isValidCMSType('invalid'));
        
        $this->assertTrue(CMSLoader::isValidSet('filters'));
        $this->assertTrue(CMSLoader::isValidSet('components'));
        $this->assertTrue(CMSLoader::isValidSet('hooks'));
        $this->assertFalse(CMSLoader::isValidSet('invalid'));
    }
    
    /**
     * Test CMSHeaderValidator
     */
    public function testCMSHeaderValidator()
    {
        $validHeader = [
            'type' => 'drupal',
            'sets' => ['components', 'filters']
        ];
        
        $ast = [
            'type' => 'document',
            'children' => [],
            'cms_header' => $validHeader
        ];
        
        $errors = CMSHeaderValidator::validate($validHeader, $ast);
        $this->assertEmpty($errors);
        
        $summary = CMSHeaderValidator::getSummary($validHeader, $ast);
        $this->assertTrue($summary['valid']);
        $this->assertTrue($summary['has_header']);
        $this->assertEquals('drupal', $summary['cms_type']);
    }
    
    /**
     * Test all valid set combinations
     */
    public function testAllValidSets()
    {
        $sets = ['filters', 'components', 'renderers', 'views', 'functions', 'hooks', 'context'];
        
        $template = '{ikb_cms type="drupal" set="' . implode(',', $sets) . '" /}';
        
        $lexer = new Lexer();
        $parser = new Parser();
        
        $tokens = $lexer->tokenize($template);
        $ast = $parser->parse($tokens);
        
        $this->assertNotNull($ast['cms_header']);
        $this->assertCount(count($sets), $ast['cms_header']['sets']);
        
        foreach ($sets as $set) {
            $this->assertContains($set, $ast['cms_header']['sets']);
        }
    }
    
    /**
     * Test case insensitivity of CMS type
     */
    public function testCMSTypeCaseInsensitivity()
    {
        $this->assertTrue(CMSLoader::isValidCMSType('WordPress'));
        $this->assertTrue(CMSLoader::isValidCMSType('DRUPAL'));
        $this->assertTrue(CMSLoader::isValidCMSType('Joomla'));
    }
}
