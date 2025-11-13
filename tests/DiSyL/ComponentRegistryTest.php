<?php
/**
 * DiSyL Component Registry Tests
 * 
 * @version 0.1.0
 */

namespace Tests\DiSyL;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\DiSyL\ComponentRegistry;
use IkabudKernel\Core\DiSyL\Grammar;

class ComponentRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        // Core components are auto-registered
    }
    
    /**
     * Test component registration
     */
    public function testComponentRegistration(): void
    {
        ComponentRegistry::register('test_component', [
            'category' => ComponentRegistry::CATEGORY_UI,
            'description' => 'Test component',
            'attributes' => [
                'title' => ['type' => Grammar::TYPE_STRING]
            ]
        ]);
        
        $this->assertTrue(ComponentRegistry::has('test_component'));
    }
    
    /**
     * Test getting component definition
     */
    public function testGetComponentDefinition(): void
    {
        $component = ComponentRegistry::get('ikb_section');
        
        $this->assertNotNull($component);
        $this->assertEquals('ikb_section', $component['name']);
        $this->assertEquals(ComponentRegistry::CATEGORY_STRUCTURAL, $component['category']);
        $this->assertArrayHasKey('attributes', $component);
    }
    
    /**
     * Test getting non-existent component
     */
    public function testGetNonExistentComponent(): void
    {
        $component = ComponentRegistry::get('non_existent');
        $this->assertNull($component);
    }
    
    /**
     * Test has method
     */
    public function testHasMethod(): void
    {
        $this->assertTrue(ComponentRegistry::has('ikb_section'));
        $this->assertFalse(ComponentRegistry::has('non_existent'));
    }
    
    /**
     * Test getting all components
     */
    public function testGetAllComponents(): void
    {
        $all = ComponentRegistry::all();
        
        $this->assertIsArray($all);
        $this->assertGreaterThan(0, count($all));
        $this->assertArrayHasKey('ikb_section', $all);
    }
    
    /**
     * Test getting components by category
     */
    public function testGetByCategory(): void
    {
        $structural = ComponentRegistry::getByCategory(ComponentRegistry::CATEGORY_STRUCTURAL);
        
        $this->assertIsArray($structural);
        $this->assertArrayHasKey('ikb_section', $structural);
        $this->assertArrayHasKey('ikb_block', $structural);
        $this->assertArrayHasKey('ikb_container', $structural);
    }
    
    /**
     * Test core component: ikb_section
     */
    public function testIkbSectionComponent(): void
    {
        $component = ComponentRegistry::get('ikb_section');
        
        $this->assertEquals(ComponentRegistry::CATEGORY_STRUCTURAL, $component['category']);
        $this->assertFalse($component['leaf']);
        
        $attrs = $component['attributes'];
        $this->assertArrayHasKey('type', $attrs);
        $this->assertArrayHasKey('title', $attrs);
        $this->assertArrayHasKey('bg', $attrs);
        $this->assertArrayHasKey('padding', $attrs);
        
        // Check type attribute schema
        $this->assertEquals(Grammar::TYPE_STRING, $attrs['type']['type']);
        $this->assertEquals('content', $attrs['type']['default']);
        $this->assertContains('hero', $attrs['type']['enum']);
    }
    
    /**
     * Test core component: ikb_block
     */
    public function testIkbBlockComponent(): void
    {
        $component = ComponentRegistry::get('ikb_block');
        
        $this->assertEquals(ComponentRegistry::CATEGORY_STRUCTURAL, $component['category']);
        
        $attrs = $component['attributes'];
        $this->assertArrayHasKey('cols', $attrs);
        $this->assertArrayHasKey('gap', $attrs);
        $this->assertArrayHasKey('align', $attrs);
        
        // Check cols attribute
        $this->assertEquals(Grammar::TYPE_INTEGER, $attrs['cols']['type']);
        $this->assertEquals(1, $attrs['cols']['default']);
        $this->assertEquals(1, $attrs['cols']['min']);
        $this->assertEquals(12, $attrs['cols']['max']);
    }
    
    /**
     * Test core component: ikb_query
     */
    public function testIkbQueryComponent(): void
    {
        $component = ComponentRegistry::get('ikb_query');
        
        $this->assertEquals(ComponentRegistry::CATEGORY_DATA, $component['category']);
        
        $attrs = $component['attributes'];
        $this->assertArrayHasKey('type', $attrs);
        $this->assertArrayHasKey('limit', $attrs);
        $this->assertArrayHasKey('orderby', $attrs);
        $this->assertArrayHasKey('order', $attrs);
        
        // Check limit attribute
        $this->assertEquals(Grammar::TYPE_INTEGER, $attrs['limit']['type']);
        $this->assertEquals(10, $attrs['limit']['default']);
        $this->assertEquals(1, $attrs['limit']['min']);
        $this->assertEquals(100, $attrs['limit']['max']);
    }
    
    /**
     * Test core component: ikb_card
     */
    public function testIkbCardComponent(): void
    {
        $component = ComponentRegistry::get('ikb_card');
        
        $this->assertEquals(ComponentRegistry::CATEGORY_UI, $component['category']);
        
        $attrs = $component['attributes'];
        $this->assertArrayHasKey('title', $attrs);
        $this->assertArrayHasKey('image', $attrs);
        $this->assertArrayHasKey('link', $attrs);
        $this->assertArrayHasKey('variant', $attrs);
    }
    
    /**
     * Test core component: ikb_image
     */
    public function testIkbImageComponent(): void
    {
        $component = ComponentRegistry::get('ikb_image');
        
        $this->assertEquals(ComponentRegistry::CATEGORY_MEDIA, $component['category']);
        $this->assertTrue($component['leaf']); // Image is a leaf node
        
        $attrs = $component['attributes'];
        $this->assertArrayHasKey('src', $attrs);
        $this->assertArrayHasKey('alt', $attrs);
        $this->assertArrayHasKey('lazy', $attrs);
        
        // Check required attributes
        $this->assertTrue($attrs['src']['required']);
        $this->assertTrue($attrs['alt']['required']);
    }
    
    /**
     * Test core component: ikb_text
     */
    public function testIkbTextComponent(): void
    {
        $component = ComponentRegistry::get('ikb_text');
        
        $this->assertEquals(ComponentRegistry::CATEGORY_UI, $component['category']);
        
        $attrs = $component['attributes'];
        $this->assertArrayHasKey('size', $attrs);
        $this->assertArrayHasKey('weight', $attrs);
        $this->assertArrayHasKey('align', $attrs);
    }
    
    /**
     * Test control component: if
     */
    public function testIfComponent(): void
    {
        $component = ComponentRegistry::get('if');
        
        $this->assertEquals(ComponentRegistry::CATEGORY_CONTROL, $component['category']);
        
        $attrs = $component['attributes'];
        $this->assertArrayHasKey('condition', $attrs);
        $this->assertTrue($attrs['condition']['required']);
    }
    
    /**
     * Test control component: for
     */
    public function testForComponent(): void
    {
        $component = ComponentRegistry::get('for');
        
        $this->assertEquals(ComponentRegistry::CATEGORY_CONTROL, $component['category']);
        
        $attrs = $component['attributes'];
        $this->assertArrayHasKey('items', $attrs);
        $this->assertArrayHasKey('as', $attrs);
        $this->assertEquals('item', $attrs['as']['default']);
    }
    
    /**
     * Test control component: include
     */
    public function testIncludeComponent(): void
    {
        $component = ComponentRegistry::get('include');
        
        $this->assertEquals(ComponentRegistry::CATEGORY_CONTROL, $component['category']);
        $this->assertTrue($component['leaf']);
        
        $attrs = $component['attributes'];
        $this->assertArrayHasKey('template', $attrs);
        $this->assertTrue($attrs['template']['required']);
    }
    
    /**
     * Test getAttributeSchemas method
     */
    public function testGetAttributeSchemas(): void
    {
        $schemas = ComponentRegistry::getAttributeSchemas('ikb_section');
        
        $this->assertIsArray($schemas);
        $this->assertArrayHasKey('type', $schemas);
        $this->assertArrayHasKey('title', $schemas);
    }
    
    /**
     * Test getAttributeSchemas for non-existent component
     */
    public function testGetAttributeSchemasNonExistent(): void
    {
        $schemas = ComponentRegistry::getAttributeSchemas('non_existent');
        $this->assertEmpty($schemas);
    }
    
    /**
     * Test all core components are registered
     */
    public function testAllCoreComponentsRegistered(): void
    {
        $coreComponents = [
            'ikb_section',
            'ikb_block',
            'ikb_container',
            'ikb_query',
            'ikb_card',
            'ikb_image',
            'ikb_text',
            'if',
            'for',
            'include'
        ];
        
        foreach ($coreComponents as $name) {
            $this->assertTrue(
                ComponentRegistry::has($name),
                "Core component '$name' should be registered"
            );
        }
    }
    
    /**
     * Test component count
     */
    public function testComponentCount(): void
    {
        $all = ComponentRegistry::all();
        $this->assertGreaterThanOrEqual(10, count($all));
    }
}
