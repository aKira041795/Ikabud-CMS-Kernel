<?php
/**
 * DiSyL Filter Tests
 * 
 * Tests all core and WordPress filters
 * 
 * @version 0.5.0
 */

namespace Tests\DiSyL;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\DiSyL\ModularManifestLoader;

class FilterTest extends TestCase
{
    protected static $loader;
    
    public static function setUpBeforeClass(): void
    {
        // Initialize ModularManifestLoader
        ModularManifestLoader::init('full', 'wordpress');
        self::$loader = ModularManifestLoader::class;
    }
    
    /**
     * Test core filters exist
     */
    public function testCoreFiltersExist()
    {
        $coreFilters = ['upper', 'lower', 'capitalize', 'date', 'truncate', 'escape', 'json'];
        
        foreach ($coreFilters as $filterName) {
            $filter = self::$loader::getFilter($filterName);
            $this->assertNotNull($filter, "Filter '{$filterName}' should exist");
            $this->assertArrayHasKey('php', $filter, "Filter '{$filterName}' should have PHP implementation");
        }
    }
    
    /**
     * Test upper filter
     */
    public function testUpperFilter()
    {
        $result = self::$loader::applyFilter('upper', 'hello world', []);
        $this->assertEquals('HELLO WORLD', $result);
    }
    
    /**
     * Test lower filter
     */
    public function testLowerFilter()
    {
        $result = self::$loader::applyFilter('lower', 'HELLO WORLD', []);
        $this->assertEquals('hello world', $result);
    }
    
    /**
     * Test capitalize filter
     */
    public function testCapitalizeFilter()
    {
        $result = self::$loader::applyFilter('capitalize', 'hello world', []);
        $this->assertEquals('Hello World', $result);
    }
    
    /**
     * Test truncate filter
     */
    public function testTruncateFilter()
    {
        $text = 'This is a long text that should be truncated';
        $result = self::$loader::applyFilter('truncate', $text, ['length' => 10]);
        $this->assertStringContainsString('...', $result);
        $this->assertLessThanOrEqual(13, strlen($result)); // 10 + '...'
    }
    
    /**
     * Test escape filter
     */
    public function testEscapeFilter()
    {
        $result = self::$loader::applyFilter('escape', '<script>alert("xss")</script>', []);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }
    
    /**
     * Test json filter
     */
    public function testJsonFilter()
    {
        $data = ['key' => 'value', 'number' => 123];
        $result = self::$loader::applyFilter('json', $data, []);
        $this->assertJson($result);
        $decoded = json_decode($result, true);
        $this->assertEquals($data, $decoded);
    }
    
    /**
     * Test filter chaining
     */
    public function testFilterChaining()
    {
        $text = 'HELLO WORLD';
        $result = self::$loader::applyFilter('lower', $text, []);
        $result = self::$loader::applyFilter('capitalize', $result, []);
        $this->assertEquals('Hello World', $result);
    }
    
    /**
     * Test unknown filter returns original value
     */
    public function testUnknownFilterReturnsOriginal()
    {
        $original = 'test value';
        $result = self::$loader::applyFilter('nonexistent_filter', $original, []);
        $this->assertEquals($original, $result);
    }
    
    /**
     * Test WordPress filters exist
     */
    public function testWordPressFiltersExist()
    {
        $wpFilters = ['wp_kses_post', 'esc_html', 'esc_attr', 'esc_url', 'wp_trim_words'];
        
        foreach ($wpFilters as $filterName) {
            $filter = self::$loader::getFilter($filterName);
            $this->assertNotNull($filter, "WordPress filter '{$filterName}' should exist");
        }
    }
    
    /**
     * Test filter metadata
     */
    public function testFilterMetadata()
    {
        $filter = self::$loader::getFilter('truncate');
        
        $this->assertArrayHasKey('description', $filter);
        $this->assertArrayHasKey('category', $filter);
        $this->assertArrayHasKey('params', $filter);
        $this->assertArrayHasKey('length', $filter['params']);
    }
    
    /**
     * Test filter signature
     */
    public function testFilterSignature()
    {
        $signature = self::$loader::getFilterSignature('truncate');
        
        $this->assertNotNull($signature);
        $this->assertStringContainsString('truncate', $signature);
        $this->assertStringContainsString('length', $signature);
    }
}
