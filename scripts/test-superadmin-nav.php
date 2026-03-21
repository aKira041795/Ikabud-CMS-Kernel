<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../bootstrap.php';
require SRC_PATH . '/helpers/module-manager.php';

// Test 1: superadmin nav items should be kernel-only
echo "=== Test 1: Superadmin nav items ===\n";
$navItems = getModuleNavItems('superadmin');
echo "Count: " . count($navItems) . "\n";
foreach ($navItems as $item) {
    echo "  [{$item['module']}] {$item['label']} -> {$item['url']}\n";
}
$moduleItems = array_filter($navItems, fn($i) => $i['module'] !== '_kernel');
if (!empty($moduleItems)) {
    echo "FAIL: superadmin sees module nav items\n";
    exit(1);
}
echo "PASS: only kernel nav items\n";

// Test 2: superadmin home URL should be null (so pageHome redirect kicks in)
echo "\n=== Test 2: Superadmin home URL ===\n";
$homeUrl = getModuleHomeUrl('superadmin', ['source' => 'kernel', 'role' => 'superadmin']);
if ($homeUrl !== null) {
    echo "FAIL: home URL resolved to '{$homeUrl}' instead of null\n";
    exit(1);
}
echo "PASS: home URL is null (pageHome handles redirect)\n";

// Test 3: admin nav should still work (no regression)
echo "\n=== Test 3: Admin nav items ===\n";
$adminNav = getModuleNavItems('admin');
$kernelItems = array_filter($adminNav, fn($i) => $i['module'] === '_kernel');
echo "Total: " . count($adminNav) . ", Kernel: " . count($kernelItems) . "\n";
if (empty($kernelItems)) {
    echo "FAIL: admin has no kernel nav items\n";
    exit(1);
}
echo "PASS: admin nav intact\n";

// Test 4: CMS roles still get nav (no regression for module users)
echo "\n=== Test 4: CMS administrator nav ===\n";
$cmsNav = getModuleNavItems('administrator');
echo "Count: " . count($cmsNav) . "\n";
if (empty($cmsNav)) {
    echo "FAIL: CMS administrator has no nav items\n";
    exit(1);
}
echo "PASS: CMS administrator nav intact\n";

echo "\n=== ALL TESTS PASSED ===\n";
