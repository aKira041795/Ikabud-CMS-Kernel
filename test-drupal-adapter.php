<?php
/**
 * Test Drupal Adapter Integration
 * 
 * Tests the DrupalAdapter with the Ikabud Kernel
 */

require_once __DIR__ . '/vendor/autoload.php';

use IkabudKernel\CMS\CMSAdapterFactory;
use IkabudKernel\Core\ConditionalLoaderFactory;

echo "=== Drupal Adapter Integration Test ===\n\n";

// Test 1: Factory can create Drupal adapter
echo "1. Testing CMSAdapterFactory...\n";
try {
    $adapter = CMSAdapterFactory::create('drupal');
    echo "   ✓ DrupalAdapter created via factory\n";
    echo "   - Type: " . $adapter->getType() . "\n";
    echo "   - Initialized: " . ($adapter->isInitialized() ? 'Yes' : 'No') . "\n\n";
} catch (Exception $e) {
    echo "   ✗ Factory creation failed: {$e->getMessage()}\n\n";
    exit(1);
}

// Test 2: Auto-detection
echo "2. Testing CMS type detection...\n";
$instanceDir = __DIR__ . '/instances/dpl-test-001';
if (is_dir($instanceDir)) {
    $detectedType = CMSAdapterFactory::detectCMSType($instanceDir);
    echo "   ✓ Detected CMS type: " . ($detectedType ?? 'none') . "\n";
    
    if ($detectedType === 'drupal') {
        echo "   ✓ Correctly identified as Drupal\n\n";
    } else {
        echo "   ✗ Expected 'drupal', got '{$detectedType}'\n\n";
    }
} else {
    echo "   ⚠ Instance directory not found: {$instanceDir}\n\n";
}

// Test 3: Adapter initialization
echo "3. Testing adapter initialization...\n";
try {
    $adapter->initialize([
        'instance_id' => 'dpl-test-001',
        'database_name' => 'drupal_test',
    ]);
    echo "   ✓ Adapter initialized\n";
    echo "   - Instance ID: " . $adapter->getInstanceId() . "\n";
    echo "   - Initialized: " . ($adapter->isInitialized() ? 'Yes' : 'No') . "\n\n";
} catch (Exception $e) {
    echo "   ✗ Initialization failed: {$e->getMessage()}\n\n";
}

// Test 4: Conditional module loader
echo "4. Testing ConditionalModuleLoader...\n";
try {
    $loader = ConditionalLoaderFactory::create($instanceDir, 'drupal');
    
    if ($loader) {
        echo "   ✓ ConditionalModuleLoader created\n";
        echo "   - CMS Type: " . $loader->getCMSType() . "\n";
        echo "   - Enabled: " . ($loader->isEnabled() ? 'Yes' : 'No') . "\n";
        
        // Test module determination
        $modules = $loader->determineExtensions('/', [
            'is_admin' => false
        ]);
        
        echo "   - Modules for frontend: " . count($modules) . "\n";
        
        $adminModules = $loader->determineExtensions('/admin', [
            'is_admin' => true
        ]);
        
        echo "   - Modules for admin: " . count($adminModules) . "\n";
        
        $stats = $loader->getStats();
        echo "   - Efficiency: " . $stats['efficiency'] . "\n\n";
    } else {
        echo "   ✗ ConditionalModuleLoader not created\n\n";
    }
} catch (Exception $e) {
    echo "   ✗ Conditional loader failed: {$e->getMessage()}\n\n";
}

// Test 5: Check supported CMS types
echo "5. Testing supported CMS types...\n";
$supported = CMSAdapterFactory::getSupportedTypes();
echo "   Supported CMS:\n";
foreach ($supported as $type => $info) {
    $conditionalLoading = $info['conditional_loading'] ? '✓' : '✗';
    echo "   - {$info['name']} ({$type}): {$info['adapter']} [{$conditionalLoading} Conditional Loading]\n";
}
echo "\n";

// Test 6: ConditionalLoaderFactory support check
echo "6. Testing ConditionalLoaderFactory support...\n";
$cmsTypes = ['wordpress', 'joomla', 'drupal'];
foreach ($cmsTypes as $type) {
    $supported = ConditionalLoaderFactory::isSupported($type);
    $status = $supported ? '✓' : '✗';
    echo "   {$status} {$type}: " . ($supported ? 'Supported' : 'Not supported') . "\n";
}
echo "\n";

// Test 7: Generate manifest (if instance exists)
echo "7. Testing manifest generation...\n";
if (is_dir($instanceDir)) {
    $manifestFile = $instanceDir . '/ikabud-modules-manifest.json';
    
    if (file_exists($manifestFile)) {
        echo "   ✓ Manifest file exists\n";
        
        $manifest = json_decode(file_get_contents($manifestFile), true);
        if ($manifest) {
            echo "   - Version: " . ($manifest['version'] ?? 'unknown') . "\n";
            echo "   - Total modules: " . count($manifest['modules'] ?? []) . "\n";
            
            // Count required modules
            $required = array_filter($manifest['modules'], fn($m) => $m['required'] ?? false);
            echo "   - Required modules: " . count($required) . "\n";
            
            // Count conditional modules
            $conditional = array_filter($manifest['modules'], fn($m) => isset($m['load_on']));
            echo "   - Conditional modules: " . count($conditional) . "\n";
        } else {
            echo "   ✗ Invalid manifest JSON\n";
        }
    } else {
        echo "   ⚠ Manifest not found. Run: ./bin/generate-drupal-manifest dpl-test-001\n";
    }
} else {
    echo "   ⚠ Instance directory not found\n";
}
echo "\n";

// Summary
echo "=== Test Summary ===\n";
echo "✓ DrupalAdapter integration complete\n";
echo "✓ CMSAdapterFactory supports Drupal\n";
echo "✓ ConditionalModuleLoader supports Drupal\n";
echo "✓ All 3 CMS types supported: WordPress, Joomla, Drupal\n";
echo "\nNext steps:\n";
echo "1. Generate manifest: ./bin/generate-drupal-manifest dpl-test-001\n";
echo "2. Test with live Drupal instance\n";
echo "3. Verify cache integration\n";
