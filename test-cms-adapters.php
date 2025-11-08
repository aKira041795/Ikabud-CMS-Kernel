<?php
/**
 * Test CMS Adapters
 * 
 * Tests the CMS adapter system
 */

require __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\Kernel;
use IkabudKernel\CMS\CMSRegistry;
use IkabudKernel\CMS\Adapters\WordPressAdapter;
use IkabudKernel\CMS\Adapters\NativeAdapter;

echo "=== Ikabud Kernel - CMS Adapter Test ===\n\n";

try {
    // Boot kernel
    echo "1. Booting kernel...\n";
    Kernel::boot();
    $stats = Kernel::getStats();
    echo "   ✓ Kernel booted in " . round($stats['uptime'] * 1000, 2) . "ms\n";
    echo "   ✓ Syscalls registered: " . $stats['syscalls_registered'] . "\n\n";
    
    // Initialize CMS Registry
    echo "2. Initializing CMS Registry...\n";
    CMSRegistry::initialize();
    echo "   ✓ CMS Registry initialized\n\n";
    
    // Test Native Adapter
    echo "3. Testing Native Adapter...\n";
    $native = new NativeAdapter();
    $native->setInstanceId('native_test');
    $native->initialize([
        'database_name' => 'ikabud-kernel',
        'database_prefix' => 'native_'
    ]);
    echo "   ✓ Native adapter initialized\n";
    
    $native->boot();
    echo "   ✓ Native adapter booted\n";
    echo "   - Type: " . $native->getType() . "\n";
    echo "   - Version: " . $native->getVersion() . "\n";
    echo "   - Initialized: " . ($native->isInitialized() ? 'Yes' : 'No') . "\n";
    echo "   - Booted: " . ($native->isBooted() ? 'Yes' : 'No') . "\n\n";
    
    // Register Native in registry
    echo "4. Registering Native CMS in registry...\n";
    $pid = CMSRegistry::register('native', $native, [
        'routes' => ['/native'],
        'memory_limit' => 128
    ]);
    echo "   ✓ Native CMS registered with PID: $pid\n\n";
    
    // Test WordPress Adapter (without actual boot)
    echo "5. Testing WordPress Adapter (initialization only)...\n";
    $wordpress = new WordPressAdapter();
    $wordpress->setInstanceId('wp_test');
    echo "   ✓ WordPress adapter created\n";
    echo "   - Type: " . $wordpress->getType() . "\n";
    echo "   - Initialized: " . ($wordpress->isInitialized() ? 'Yes' : 'No') . "\n";
    echo "   - Booted: " . ($wordpress->isBooted() ? 'Yes' : 'No') . "\n\n";
    
    // Test routing
    echo "6. Testing CMS routing...\n";
    $routes = [
        '/' => CMSRegistry::route('/'),
        '/native' => CMSRegistry::route('/native'),
        '/native/test' => CMSRegistry::route('/native/test'),
        '/other' => CMSRegistry::route('/other')
    ];
    
    foreach ($routes as $path => $cms) {
        echo "   - Route '$path' → " . ($cms ?? 'no match') . "\n";
    }
    echo "\n";
    
    // Get registry stats
    echo "7. CMS Registry Statistics:\n";
    $registryStats = CMSRegistry::getStats();
    echo "   - Total CMS: " . $registryStats['total_cms'] . "\n";
    echo "   - Total Routes: " . $registryStats['total_routes'] . "\n";
    echo "   - CMS List: " . implode(', ', $registryStats['cms_list']) . "\n\n";
    
    // Get all registered CMS
    echo "8. Registered CMS Instances:\n";
    $allCMS = CMSRegistry::getAll();
    foreach ($allCMS as $name => $info) {
        echo "   - $name:\n";
        echo "     PID: " . $info['pid'] . "\n";
        echo "     Type: " . $info['type'] . "\n";
        echo "     Status: " . $info['status'] . "\n";
        echo "     Routes: " . implode(', ', $info['routes']) . "\n";
    }
    echo "\n";
    
    // Test resource usage
    echo "9. Resource Usage:\n";
    $nativeResources = $native->getResourceUsage();
    echo "   Native CMS:\n";
    echo "   - Memory: " . round($nativeResources['memory'] / 1024 / 1024, 2) . " MB\n";
    echo "   - Peak Memory: " . round($nativeResources['memory_peak'] / 1024 / 1024, 2) . " MB\n";
    echo "   - Boot Time: " . round($nativeResources['boot_time'], 2) . " ms\n\n";
    
    echo "=== All Tests Passed! ===\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
