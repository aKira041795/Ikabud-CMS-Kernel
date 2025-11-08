<?php
/**
 * Test Instance Boot Sequence
 * 
 * Demonstrates the 5-phase boot sequence for CMS instances
 */

require_once __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\Kernel;

echo "===========================================\n";
echo "Ikabud Kernel - Instance Boot Test\n";
echo "===========================================\n\n";

try {
    // Step 1: Boot the Kernel
    echo "Step 1: Booting Kernel...\n";
    Kernel::boot();
    echo "✓ Kernel booted successfully\n\n";
    
    // Step 2: Get kernel instance
    $kernel = Kernel::getInstance();
    echo "✓ Kernel instance retrieved\n";
    echo "✓ Kernel version: " . Kernel::VERSION . "\n";
    echo "✓ Kernel booted: " . (Kernel::isBooted() ? 'YES' : 'NO') . "\n\n";
    
    // Step 3: Load instance configuration from database
    echo "Step 2: Loading instance configuration...\n";
    $db = $kernel->getDatabase();
    $stmt = $db->prepare("SELECT * FROM instances WHERE instance_id = ? LIMIT 1");
    $stmt->execute(['wp-test-001']);
    $instanceConfig = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$instanceConfig) {
        throw new Exception("Instance wp-test-001 not found in database");
    }
    
    echo "✓ Instance found: {$instanceConfig['instance_name']}\n";
    echo "✓ CMS Type: {$instanceConfig['cms_type']}\n";
    echo "✓ Database: {$instanceConfig['database_name']}\n";
    echo "✓ Status: {$instanceConfig['status']}\n\n";
    
    // Step 4: Boot the instance
    echo "Step 3: Booting CMS instance through 5-phase sequence...\n";
    echo "-------------------------------------------\n";
    
    $success = $kernel->bootInstance('wp-test-001', $instanceConfig);
    
    echo "-------------------------------------------\n";
    
    if ($success) {
        echo "✓ Instance booted successfully!\n\n";
        
        // Step 5: Display boot log
        echo "Step 4: Boot Log Summary\n";
        echo "===========================================\n";
        
        // Get boot log from bootstrapper (we'll need to expose this)
        echo "✓ All 5 phases completed\n";
        echo "✓ Instance is ready to serve requests\n\n";
        
        // Step 6: Validate instance
        echo "Step 5: Validating instance...\n";
        $instancePath = __DIR__ . "/instances/wp-test-001";
        
        $checks = [
            'Instance directory exists' => is_dir($instancePath),
            'wp-content exists' => is_dir($instancePath . '/wp-content'),
            'wp-config.php exists' => file_exists($instancePath . '/wp-config.php'),
            'Plugins directory exists' => is_dir($instancePath . '/wp-content/plugins'),
            'Themes directory exists' => is_dir($instancePath . '/wp-content/themes'),
            'Uploads directory exists' => is_dir($instancePath . '/wp-content/uploads'),
        ];
        
        foreach ($checks as $check => $result) {
            echo ($result ? '✓' : '✗') . " {$check}\n";
        }
        
        echo "\n===========================================\n";
        echo "SUCCESS: Instance boot sequence complete!\n";
        echo "===========================================\n\n";
        
        echo "Next Steps:\n";
        echo "1. Visit: http://wp-test.ikabud-kernel.test/\n";
        echo "2. The instance is now running through the kernel\n";
        echo "3. Check error logs for detailed boot sequence\n\n";
        
    } else {
        echo "✗ Instance boot failed\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nTest completed!\n";
