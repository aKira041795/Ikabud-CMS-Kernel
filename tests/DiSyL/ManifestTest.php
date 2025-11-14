<?php
/**
 * DiSyL Manifest Tests
 * 
 * Tests manifest loading, caching, and validation
 * 
 * @version 0.5.0
 */

namespace Tests\DiSyL;

use PHPUnit\Framework\TestCase;
use IkabudKernel\Core\DiSyL\ModularManifestLoader;

class ManifestTest extends TestCase
{
    protected static $loader;
    
    public static function setUpBeforeClass(): void
    {
        ModularManifestLoader::init('full', 'wordpress');
        self::$loader = ModularManifestLoader::class;
    }
    
    /**
     * Test manifest initialization
     */
    public function testManifestInitialization()
    {
        $this->assertEquals('0.4.0', self::$loader::getVersion());
        $this->assertEquals('full', self::$loader::getCurrentProfile());
    }
    
    /**
     * Test loaded manifests
     */
    public function testLoadedManifests()
    {
        $manifests = self::$loader::getLoadedManifests();
        
        $this->assertIsArray($manifests);
        $this->assertNotEmpty($manifests);
        $this->assertContains('Core/filters.manifest.json', $manifests);
    }
    
    /**
     * Test manifest is loaded
     */
    public function testManifestIsLoaded()
    {
        $this->assertTrue(self::$loader::isManifestLoaded('Core/filters.manifest.json'));
        $this->assertFalse(self::$loader::isManifestLoaded('nonexistent.json'));
    }
    
    /**
     * Test manifest info
     */
    public function testManifestInfo()
    {
        $info = self::$loader::getManifestInfo('Core/filters.manifest.json');
        
        $this->assertNotNull($info);
        $this->assertArrayHasKey('version', $info);
        $this->assertArrayHasKey('type', $info);
        $this->assertArrayHasKey('description', $info);
        $this->assertEquals('filters', $info['type']);
    }
    
    /**
     * Test supported CMS
     */
    public function testSupportedCMS()
    {
        $cms = self::$loader::getSupportedCMS();
        
        $this->assertIsArray($cms);
        $this->assertContains('wordpress', $cms);
    }
    
    /**
     * Test namespace registry
     */
    public function testNamespaceRegistry()
    {
        $namespace = self::$loader::resolveNamespace('core:text');
        $this->assertNotNull($namespace);
    }
    
    /**
     * Test registry loading
     */
    public function testRegistryLoading()
    {
        $registry = self::$loader::getRegistry();
        
        $this->assertIsArray($registry);
        $this->assertArrayHasKey('components', $registry);
        $this->assertArrayHasKey('filters', $registry);
    }
    
    /**
     * Test profile loading - minimal
     */
    public function testMinimalProfile()
    {
        ModularManifestLoader::reload('minimal', 'wordpress');
        
        $this->assertEquals('minimal', ModularManifestLoader::getCurrentProfile());
        
        // Reload full for other tests
        ModularManifestLoader::reload('full', 'wordpress');
    }
    
    /**
     * Test profile loading - headless
     */
    public function testHeadlessProfile()
    {
        ModularManifestLoader::reload('headless', 'wordpress');
        
        $this->assertEquals('headless', ModularManifestLoader::getCurrentProfile());
        
        // Reload full for other tests
        ModularManifestLoader::reload('full', 'wordpress');
    }
}
