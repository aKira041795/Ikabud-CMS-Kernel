<?php
/**
 * DiSyL Component Tests
 * 
 * Tests component loading, validation, and capabilities
 * 
 * @version 0.5.0
 */

namespace Tests\DiSyL;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\DiSyL\ModularManifestLoader;

class ComponentTest extends TestCase
{
    protected static $loader;
    
    public static function setUpBeforeClass(): void
    {
        ModularManifestLoader::init('full', 'wordpress');
        self::$loader = ModularManifestLoader::class;
    }
    
    /**
     * Test core components exist
     */
    public function testCoreComponentsExist()
    {
        $coreComponents = ['ikb_text', 'ikb_container', 'ikb_section', 'ikb_block', 'ikb_card'];
        
        foreach ($coreComponents as $componentName) {
            $component = self::$loader::getComponent($componentName);
            $this->assertNotNull($component, "Component '{$componentName}' should exist");
        }
    }
    
    /**
     * Test WordPress components exist
     */
    public function testWordPressComponentsExist()
    {
        $wpComponents = ['ikb_query', 'ikb_post_meta', 'ikb_menu', 'ikb_sidebar'];
        
        foreach ($wpComponents as $componentName) {
            $component = self::$loader::getComponent($componentName);
            $this->assertNotNull($component, "WordPress component '{$componentName}' should exist");
        }
    }
    
    /**
     * Test namespaced component lookup
     */
    public function testNamespacedComponentLookup()
    {
        $component = self::$loader::getComponent('core:text');
        $this->assertNotNull($component);
        $this->assertEquals('ikb_text', $component['full_name']);
    }
    
    /**
     * Test component capabilities
     */
    public function testComponentCapabilities()
    {
        $capabilities = self::$loader::getCapabilities('ikb_query');
        
        $this->assertNotNull($capabilities);
        $this->assertArrayHasKey('supports_children', $capabilities);
        $this->assertArrayHasKey('output_mode', $capabilities);
        $this->assertTrue($capabilities['supports_children']);
        $this->assertEquals('loop', $capabilities['output_mode']);
    }
    
    /**
     * Test component validation - valid
     */
    public function testComponentValidationValid()
    {
        $errors = self::$loader::validateComponent('ikb_query', ['type' => 'post'], true);
        $this->assertEmpty($errors);
    }
    
    /**
     * Test component validation - missing required attribute
     */
    public function testComponentValidationMissingRequired()
    {
        $errors = self::$loader::validateComponent('ikb_query', [], true);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Required attribute', $errors[0]);
    }
    
    /**
     * Test component validation - children not supported
     */
    public function testComponentValidationChildrenNotSupported()
    {
        $errors = self::$loader::validateComponent('ikb_include', [], true);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not support children', $errors[0]);
    }
    
    /**
     * Test component validation - unknown component
     */
    public function testComponentValidationUnknown()
    {
        $errors = self::$loader::validateComponent('nonexistent_component', [], false);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not found', $errors[0]);
    }
    
    /**
     * Test list components
     */
    public function testListComponents()
    {
        $components = self::$loader::listComponents();
        
        $this->assertIsArray($components);
        $this->assertNotEmpty($components);
        $this->assertGreaterThan(10, count($components));
    }
    
    /**
     * Test get components by namespace
     */
    public function testGetComponentsByNamespace()
    {
        $coreComponents = self::$loader::getComponentsByNamespace('core');
        
        $this->assertIsArray($coreComponents);
        $this->assertNotEmpty($coreComponents);
        
        foreach (array_keys($coreComponents) as $name) {
            $this->assertStringStartsWith('core:', $name);
        }
    }
    
    /**
     * Test component has method
     */
    public function testHasComponent()
    {
        $this->assertTrue(self::$loader::hasComponent('ikb_text'));
        $this->assertTrue(self::$loader::hasComponent('core:text'));
        $this->assertFalse(self::$loader::hasComponent('nonexistent'));
    }
    
    /**
     * Test component metadata
     */
    public function testComponentMetadata()
    {
        $component = self::$loader::getComponent('ikb_query');
        
        $this->assertArrayHasKey('namespace', $component);
        $this->assertArrayHasKey('category', $component);
        $this->assertArrayHasKey('tags', $component);
        $this->assertArrayHasKey('description', $component);
    }
    
    /**
     * Test base components
     */
    public function testBaseComponents()
    {
        $components = self::$loader::getComponents();
        
        $baseComponents = array_filter($components, function($component) {
            return isset($component['is_base']) && $component['is_base'];
        });
        
        $this->assertNotEmpty($baseComponents);
    }
    
    /**
     * Test component by category
     */
    public function testGetByCategory()
    {
        $layoutComponents = self::$loader::getByCategory('layout');
        
        $this->assertIsArray($layoutComponents);
        $this->assertNotEmpty($layoutComponents);
    }
}
