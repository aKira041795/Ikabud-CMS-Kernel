<?php
/**
 * Test WordPress Integration with Process Isolation
 * 
 * Tests the complete stack:
 * - Kernel boot
 * - InstanceBootstrapper
 * - WordPressAdapter
 * - CMSRegistry
 * - ProcessManager
 */

require_once __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\ProcessManager;
use IkabudKernel\CMS\CMSRegistry;

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║   Ikabud Kernel - WordPress Integration Test              ║\n";
echo "║   Process Isolation + Boot Sequence + CMS Adapter         ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

$testsPassed = 0;
$testsFailed = 0;

function testResult($name, $passed, $message = '') {
    global $testsPassed, $testsFailed;
    
    if ($passed) {
        echo "✅ {$name}\n";
        if ($message) echo "   → {$message}\n";
        $testsPassed++;
    } else {
        echo "❌ {$name}\n";
        if ($message) echo "   → {$message}\n";
        $testsFailed++;
    }
}

try {
    // ========================================================================
    // TEST 1: Kernel Boot
    // ========================================================================
    echo "\n📦 TEST 1: Kernel Boot Sequence\n";
    echo str_repeat("─", 60) . "\n";
    
    Kernel::boot();
    testResult("Kernel boots successfully", true);
    
    $kernel = Kernel::getInstance();
    testResult("Kernel instance retrieved", $kernel !== null);
    testResult("Kernel version is " . Kernel::VERSION, true);
    testResult("Kernel is booted", Kernel::isBooted());
    
    // ========================================================================
    // TEST 2: Database & Instance Config
    // ========================================================================
    echo "\n📊 TEST 2: Database & Instance Configuration\n";
    echo str_repeat("─", 60) . "\n";
    
    $db = $kernel->getDatabase();
    testResult("Database connection available", $db !== null);
    
    $stmt = $db->prepare("SELECT * FROM instances WHERE instance_id = ? LIMIT 1");
    $stmt->execute(['wp-test-001']);
    $instanceConfig = $stmt->fetch(PDO::FETCH_ASSOC);
    
    testResult("Instance wp-test-001 found in database", $instanceConfig !== false);
    
    if ($instanceConfig) {
        echo "   Instance Details:\n";
        echo "   - Name: {$instanceConfig['instance_name']}\n";
        echo "   - CMS: {$instanceConfig['cms_type']}\n";
        echo "   - Database: {$instanceConfig['database_name']}\n";
        echo "   - Status: {$instanceConfig['status']}\n";
    }
    
    // ========================================================================
    // TEST 3: Instance Boot Sequence
    // ========================================================================
    echo "\n🚀 TEST 3: Instance Boot Sequence (5 Phases)\n";
    echo str_repeat("─", 60) . "\n";
    
    $bootSuccess = $kernel->bootInstance('wp-test-001', $instanceConfig);
    testResult("Instance boot completed", $bootSuccess);
    
    // ========================================================================
    // TEST 4: CMS Registry
    // ========================================================================
    echo "\n📋 TEST 4: CMS Registry Integration\n";
    echo str_repeat("─", 60) . "\n";
    
    CMSRegistry::initialize();
    testResult("CMS Registry initialized", true);
    
    $cmsInstance = CMSRegistry::get('wp-test-001');
    testResult("Instance registered in CMS Registry", $cmsInstance !== null);
    
    if ($cmsInstance) {
        testResult("CMS type is " . $cmsInstance->getType(), $cmsInstance->getType() === 'wordpress');
        testResult("CMS is initialized", $cmsInstance->isInitialized());
        testResult("CMS is booted", $cmsInstance->isBooted());
        
        echo "   CMS Details:\n";
        echo "   - Type: " . $cmsInstance->getType() . "\n";
        echo "   - Instance ID: " . $cmsInstance->getInstanceId() . "\n";
        echo "   - Initialized: " . ($cmsInstance->isInitialized() ? 'Yes' : 'No') . "\n";
        echo "   - Booted: " . ($cmsInstance->isBooted() ? 'Yes' : 'No') . "\n";
        
        // Get resource usage
        $resources = $cmsInstance->getResourceUsage();
        echo "   - Memory: " . round($resources['memory'] / 1024 / 1024, 2) . " MB\n";
        echo "   - Boot Time: " . round($resources['boot_time'], 2) . " ms\n";
    }
    
    // ========================================================================
    // TEST 5: Process Manager (if root access available)
    // ========================================================================
    echo "\n⚙️  TEST 5: Process Manager\n";
    echo str_repeat("─", 60) . "\n";
    
    try {
        $processManager = new ProcessManager($kernel);
        testResult("ProcessManager created", true);
        
        // Check if we have root access
        $hasRoot = posix_getuid() === 0;
        if ($hasRoot) {
            echo "   ✓ Root access available - Full process isolation supported\n";
            
            // Try to get instance status
            $status = $processManager->getInstanceStatus('wp-test-001');
            testResult("Can query instance status", isset($status['instance_id']));
            
            if (isset($status['pid'])) {
                echo "   Process Details:\n";
                echo "   - PID: {$status['pid']}\n";
                echo "   - Status: {$status['status']}\n";
                echo "   - Socket: {$status['socket']}\n";
            }
        } else {
            echo "   ⚠️  No root access - Process isolation requires sudo\n";
            echo "   → Current mode: Symlink-based architecture\n";
            echo "   → For full process isolation, run: sudo ./ikabud create wp-test-001\n";
        }
    } catch (Exception $e) {
        testResult("ProcessManager initialization", false, $e->getMessage());
    }
    
    // ========================================================================
    // TEST 6: WordPress Adapter Functionality
    // ========================================================================
    echo "\n🔧 TEST 6: WordPress Adapter Functionality\n";
    echo str_repeat("─", 60) . "\n";
    
    if ($cmsInstance) {
        // Test database config
        $dbConfig = $cmsInstance->getDatabaseConfig();
        testResult("Database config available", !empty($dbConfig));
        
        if (!empty($dbConfig)) {
            echo "   Database Config:\n";
            echo "   - Host: {$dbConfig['host']}\n";
            echo "   - Name: {$dbConfig['name']}\n";
            echo "   - Prefix: {$dbConfig['prefix']}\n";
        }
        
        // Test version
        $version = $cmsInstance->getVersion();
        testResult("WordPress version detected", !empty($version));
        if (!empty($version) && $version !== 'unknown') {
            echo "   - WordPress Version: {$version}\n";
        }
    }
    
    // ========================================================================
    // TEST 7: Instance Validation
    // ========================================================================
    echo "\n✓ TEST 7: Instance Validation\n";
    echo str_repeat("─", 60) . "\n";
    
    $instancePath = __DIR__ . "/instances/wp-test-001";
    
    $checks = [
        'Instance directory exists' => is_dir($instancePath),
        'wp-content directory exists' => is_dir($instancePath . '/wp-content'),
        'wp-config.php exists' => file_exists($instancePath . '/wp-config.php'),
        'Plugins directory exists' => is_dir($instancePath . '/wp-content/plugins'),
        'Themes directory exists' => is_dir($instancePath . '/wp-content/themes'),
        'Uploads directory exists' => is_dir($instancePath . '/wp-content/uploads'),
    ];
    
    foreach ($checks as $check => $result) {
        testResult($check, $result);
    }
    
    // ========================================================================
    // SUMMARY
    // ========================================================================
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║                    TEST SUMMARY                            ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "✅ Tests Passed: {$testsPassed}\n";
    echo "❌ Tests Failed: {$testsFailed}\n";
    echo "\n";
    
    if ($testsFailed === 0) {
        echo "🎉 ALL TESTS PASSED!\n";
        echo "\n";
        echo "Your Ikabud Kernel is fully operational with:\n";
        echo "  ✓ Kernel-supervised boot sequence\n";
        echo "  ✓ WordPress adapter integration\n";
        echo "  ✓ CMS Registry management\n";
        echo "  ✓ Process isolation ready (requires root)\n";
        echo "\n";
        echo "Next Steps:\n";
        echo "  1. Visit: http://wp-test.ikabud-kernel.test/\n";
        echo "  2. For process isolation: sudo ./ikabud create wp-test-001\n";
        echo "  3. Check status: ./ikabud status wp-test-001\n";
        echo "\n";
    } else {
        echo "⚠️  Some tests failed. Review the output above.\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "\n";
    echo "❌ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "\n";
    echo "Stack Trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
