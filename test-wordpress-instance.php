#!/usr/bin/env php
<?php
/**
 * Test WordPress Instance Deployment
 * 
 * This script tests deploying a WordPress instance through the Ikabud Kernel
 */

require __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\Kernel;
use IkabudKernel\CMS\CMSRegistry;
use IkabudKernel\CMS\Adapters\WordPressAdapter;

echo "=== WordPress Instance Deployment Test ===\n\n";

// 1. Boot the kernel
echo "1. Booting Ikabud Kernel...\n";
$kernel = Kernel::getInstance();
$kernel->boot();
echo "   ✓ Kernel booted successfully\n\n";

// 1.5. Cleanup previous test instance
echo "1.5. Cleaning up previous test instance...\n";
try {
    $db = $kernel->getDatabase();
    $db->exec("DELETE FROM instances WHERE instance_id = 'wp-test-001'");
    echo "   ✓ Cleaned up previous instance\n\n";
} catch (Exception $e) {
    echo "   ⚠ Cleanup warning: {$e->getMessage()}\n\n";
}

// 2. Check WordPress core files
echo "2. Checking WordPress core files...\n";
$wpCorePath = __DIR__ . '/shared-cores/wordpress';
if (!is_dir($wpCorePath)) {
    echo "   ✗ WordPress core not found at: $wpCorePath\n";
    echo "   Please download WordPress first.\n";
    exit(1);
}
echo "   ✓ WordPress core found at: $wpCorePath\n\n";

// 3. Create WordPress instance configuration
echo "3. Creating WordPress instance configuration...\n";
$instanceConfig = [
    'instance_id' => 'wp-test-001',
    'name' => 'Test WordPress Site',
    'cms_type' => 'wordpress',
    'domain' => 'wp-test.ikabud-kernel.test',
    'path' => '/instances/wp-test-001',
    'core_path' => $wpCorePath,
    'database_name' => 'ikabud_wp_test',
    'database_prefix' => 'wp_',
    'config' => [
        'WP_DEBUG' => true,
        'WP_DEBUG_LOG' => true,
        'WP_DEBUG_DISPLAY' => false,
    ],
];

echo "   Instance ID: {$instanceConfig['instance_id']}\n";
echo "   Name: {$instanceConfig['name']}\n";
echo "   Domain: {$instanceConfig['domain']}\n";
echo "   Database: {$instanceConfig['database_name']}\n\n";

// 4. Create database for WordPress
echo "4. Creating WordPress database...\n";
try {
    $db = $kernel->getDatabase();
    $dbName = $instanceConfig['database_name'];
    
    // Drop if exists (for testing)
    $db->exec("DROP DATABASE IF EXISTS `$dbName`");
    echo "   ✓ Dropped existing database (if any)\n";
    
    // Create new database
    $db->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "   ✓ Created database: $dbName\n\n";
} catch (Exception $e) {
    echo "   ✗ Database creation failed: {$e->getMessage()}\n";
    exit(1);
}

// 5. Register instance in kernel database
echo "5. Registering instance in kernel database...\n";
try {
    $stmt = $db->prepare("
        INSERT INTO instances (
            instance_id, instance_name, cms_type, domain, path_prefix,
            database_name, database_prefix, config, status
        ) VALUES (
            :instance_id, :instance_name, :cms_type, :domain, :path_prefix,
            :database_name, :database_prefix, :config, 'active'
        )
    ");
    
    $stmt->execute([
        'instance_id' => $instanceConfig['instance_id'],
        'instance_name' => $instanceConfig['name'],
        'cms_type' => $instanceConfig['cms_type'],
        'domain' => $instanceConfig['domain'],
        'path_prefix' => $instanceConfig['path'],
        'database_name' => $instanceConfig['database_name'],
        'database_prefix' => $instanceConfig['database_prefix'],
        'config' => json_encode($instanceConfig['config']),
    ]);
    
    echo "   ✓ Instance registered in kernel database\n\n";
} catch (Exception $e) {
    echo "   ✗ Registration failed: {$e->getMessage()}\n";
    exit(1);
}

// 6. Create WordPress adapter
echo "6. Creating WordPress adapter...\n";
try {
    $adapter = new WordPressAdapter($wpCorePath);
    echo "   ✓ WordPress adapter created\n\n";
} catch (Exception $e) {
    echo "   ✗ Adapter creation failed: {$e->getMessage()}\n";
    exit(1);
}

// 7. Initialize WordPress instance
echo "7. Initializing WordPress instance...\n";
try {
    $adapter->initialize($instanceConfig);
    echo "   ✓ WordPress instance initialized\n\n";
} catch (Exception $e) {
    echo "   ✗ Initialization failed: {$e->getMessage()}\n";
    exit(1);
}

// 8. Register with CMS Registry
echo "8. Registering with CMS Registry...\n";
try {
    CMSRegistry::register(
        $instanceConfig['instance_id'],
        $adapter,
        $instanceConfig
    );
    echo "   ✓ Registered with CMS Registry\n\n";
} catch (Exception $e) {
    echo "   ✗ Registry registration failed: {$e->getMessage()}\n";
    exit(1);
}

// 9. Boot the WordPress instance
echo "9. Booting WordPress instance...\n";
try {
    $bootResult = CMSRegistry::boot($instanceConfig['instance_id']);
    echo "   ✓ WordPress instance booted\n";
    echo "   Boot time: {$bootResult['boot_time']}ms\n";
    echo "   PID: {$bootResult['pid']}\n\n";
} catch (Exception $e) {
    echo "   ✗ Boot failed: {$e->getMessage()}\n";
    exit(1);
}

// 10. Check instance status
echo "10. Checking instance status...\n";
try {
    $status = CMSRegistry::getStatus($instanceConfig['instance_id']);
    echo "   Status: {$status['status']}\n";
    echo "   Uptime: {$status['uptime']}s\n";
    echo "   Memory: " . number_format($status['memory_usage'] / 1024 / 1024, 2) . " MB\n\n";
} catch (Exception $e) {
    echo "   ✗ Status check failed: {$e->getMessage()}\n";
}

// 11. Test content query
echo "11. Testing content query...\n";
try {
    $posts = $adapter->getContent([
        'type' => 'post',
        'limit' => 5,
        'status' => 'any',
    ]);
    
    echo "   ✓ Query executed successfully\n";
    echo "   Posts found: " . count($posts) . "\n\n";
} catch (Exception $e) {
    echo "   ⚠ Query failed (expected if WordPress not installed): {$e->getMessage()}\n\n";
}

// 12. Get process information
echo "12. Getting process information...\n";
try {
    $processes = CMSRegistry::listProcesses();
    echo "   Total processes: {$processes['total']}\n";
    
    foreach ($processes['processes'] as $proc) {
        if ($proc['instance_id'] === $instanceConfig['instance_id']) {
            echo "   Process found:\n";
            echo "     - PID: {$proc['pid']}\n";
            echo "     - Status: {$proc['status']}\n";
            echo "     - CMS Type: {$proc['cms_type']}\n";
            echo "     - Boot Time: {$proc['boot_time']}ms\n";
        }
    }
    echo "\n";
} catch (Exception $e) {
    echo "   ✗ Process info failed: {$e->getMessage()}\n\n";
}

// 13. Summary
echo "=== Test Summary ===\n\n";
echo "✓ WordPress instance deployed successfully!\n\n";
echo "Instance Details:\n";
echo "  - ID: {$instanceConfig['instance_id']}\n";
echo "  - Name: {$instanceConfig['name']}\n";
echo "  - Domain: {$instanceConfig['domain']}\n";
echo "  - Database: {$instanceConfig['database']['name']}\n";
echo "  - Status: Running\n\n";

echo "Next Steps:\n";
echo "  1. Add virtual host for: {$instanceConfig['domain']}\n";
echo "  2. Add to /etc/hosts: 127.0.0.1 {$instanceConfig['domain']}\n";
echo "  3. Install WordPress via: http://{$instanceConfig['domain']}/wp-admin/install.php\n";
echo "  4. Access site: http://{$instanceConfig['domain']}\n\n";

echo "To shutdown instance:\n";
echo "  CMSRegistry::shutdown('{$instanceConfig['instance_id']}');\n\n";

echo "=== Test Complete ===\n";
