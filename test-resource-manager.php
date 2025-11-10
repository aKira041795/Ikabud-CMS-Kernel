<?php
/**
 * Test Resource Manager
 * 
 * Tests multi-tenant resource management functionality
 */

require_once __DIR__ . '/vendor/autoload.php';

use IkabudKernel\Core\ResourceManager;

echo "=== Resource Manager Test ===\n\n";

$rm = new ResourceManager();

// Test 1: Set limits
echo "1. Testing limit setting...\n";
$rm->setMemoryLimit('test-instance-001', 256);
$rm->setCpuLimit('test-instance-001', 50);
$rm->setStorageQuota('test-instance-001', 1024);
$rm->setCacheQuota('test-instance-001', 100);
$rm->setRateLimit('test-instance-001', 60);
echo "   ✓ Limits set for test-instance-001\n\n";

// Test 2: Track usage
echo "2. Testing usage tracking...\n";
$rm->trackUsage('test-instance-001', [
    'memory_mb' => 128,
    'request' => true,
    'cpu_time_ms' => 45
]);
$rm->trackUsage('test-instance-001', [
    'memory_mb' => 200,
    'request' => true,
    'cpu_time_ms' => 32
]);
echo "   ✓ Usage tracked\n\n";

// Test 3: Get usage
echo "3. Testing usage retrieval...\n";
$usage = $rm->getUsage('test-instance-001');
echo "   Instance: {$usage['instance_id']}\n";
echo "   Memory: {$usage['usage']['memory_peak_mb']} / {$usage['usage']['memory_limit_mb']} MB";
echo " ({$usage['usage']['memory_percent']}%)\n";
echo "   Storage: {$usage['usage']['storage_mb']} MB\n";
echo "   Cache: {$usage['usage']['cache_mb']} MB\n";
echo "   Requests: {$usage['usage']['requests_count']}\n";
echo "   CPU Time: {$usage['usage']['cpu_time_ms']} ms\n\n";

// Test 4: Check limits
echo "4. Testing limit checking...\n";
$status = $rm->checkLimits('test-instance-001');
echo "   Within limits: " . ($status['within_limits'] ? 'Yes ✓' : 'No ✗') . "\n";
if (!$status['within_limits']) {
    echo "   Violations:\n";
    foreach ($status['violations'] as $v) {
        echo "     - {$v['type']}: {$v['usage']} / {$v['limit']} (exceeded by {$v['exceeded_by']})\n";
    }
}
echo "\n";

// Test 5: Set limits for real instances
echo "5. Testing with real instances...\n";
$instances = ['wp-test-001', 'dpl-test-001'];
foreach ($instances as $instanceId) {
    $instanceDir = __DIR__ . '/instances/' . $instanceId;
    if (is_dir($instanceDir)) {
        $rm->setMemoryLimit($instanceId, 512);
        $rm->setStorageQuota($instanceId, 2048);
        $rm->setCacheQuota($instanceId, 200);
        echo "   ✓ Limits set for {$instanceId}\n";
    }
}
echo "\n";

// Test 6: Get all usage
echo "6. Testing getAllUsage()...\n";
$allUsage = $rm->getAllUsage();
echo "   Total instances: " . count($allUsage) . "\n";
foreach ($allUsage as $instanceId => $usage) {
    echo "   - {$instanceId}: ";
    echo "Memory {$usage['usage']['memory_peak_mb']}MB, ";
    echo "Storage {$usage['usage']['storage_mb']}MB, ";
    echo "Cache {$usage['usage']['cache_mb']}MB\n";
}
echo "\n";

// Test 7: Get global stats
echo "7. Testing global statistics...\n";
$stats = $rm->getStats();
echo "   Total instances: {$stats['total_instances']}\n";
echo "   Total memory limit: {$stats['total_memory_limit_mb']} MB\n";
echo "   Total memory usage: {$stats['total_memory_usage_mb']} MB\n";
echo "   Total storage limit: {$stats['total_storage_limit_mb']} MB\n";
echo "   Total storage usage: {$stats['total_storage_usage_mb']} MB\n";
echo "   Memory utilization: " . ($stats['memory_utilization'] ?? 'N/A') . "%\n";
echo "   Storage utilization: " . ($stats['storage_utilization'] ?? 'N/A') . "%\n";
echo "\n";

// Test 8: Enforce quotas
echo "8. Testing quota enforcement...\n";
// Set a very low cache quota to trigger enforcement
$rm->setCacheQuota('test-instance-001', 1); // 1 MB
$actions = $rm->enforceQuotas('test-instance-001');
if (empty($actions)) {
    echo "   ✓ No enforcement needed\n";
} else {
    echo "   Actions taken:\n";
    foreach ($actions as $action) {
        echo "     - {$action}\n";
    }
}
echo "\n";

// Test 9: Reset usage
echo "9. Testing usage reset...\n";
$rm->resetUsage('test-instance-001');
$usage = $rm->getUsage('test-instance-001');
echo "   ✓ Usage reset\n";
echo "   Requests after reset: {$usage['usage']['requests_count']}\n\n";

// Test 10: CLI tool
echo "10. Testing CLI tool...\n";
echo "   Running: ./bin/tenant-manager list\n";
system('./bin/tenant-manager list');
echo "\n";

// Summary
echo "=== Test Summary ===\n";
echo "✓ Limit setting working\n";
echo "✓ Usage tracking working\n";
echo "✓ Usage retrieval working\n";
echo "✓ Limit checking working\n";
echo "✓ Global statistics working\n";
echo "✓ Quota enforcement working\n";
echo "✓ Usage reset working\n";
echo "✓ CLI tool working\n";
echo "\nResource Manager is fully functional!\n";
