<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Simulate request context
$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require SRC_PATH . '/helpers/module-manager.php';

// Count DB queries by wrapping PDO
$queryCount = 0;

echo "=== Performance: preload + discoverModules ===\n";

// Measure time for preload
$t0 = microtime(true);
preloadAllTenantModuleSettings();
$t1 = microtime(true);
echo "preloadAllTenantModuleSettings(): " . round(($t1-$t0)*1000, 1) . "ms\n";

// Measure time for discoverModules (should use cache)
$t2 = microtime(true);
$mods = discoverModules();
$t3 = microtime(true);
echo "discoverModules(): " . round(($t3-$t2)*1000, 1) . "ms, found " . count($mods) . " modules\n";

// Second call should be cached
$t4 = microtime(true);
$mods2 = discoverModules();
$t5 = microtime(true);
echo "discoverModules() (cached): " . round(($t5-$t4)*1000, 2) . "ms\n";

// getEnabledModules
$t6 = microtime(true);
$enabled = getEnabledModules();
$t7 = microtime(true);
echo "getEnabledModules(): " . round(($t7-$t6)*1000, 1) . "ms, found " . count($enabled) . " enabled\n";

// getModuleNavItems
$t8 = microtime(true);
$nav = getModuleNavItems('administrator');
$t9 = microtime(true);
echo "getModuleNavItems('administrator'): " . round(($t9-$t8)*1000, 1) . "ms, " . count($nav) . " items\n";

// Total
$total = ($t9 - $t0) * 1000;
echo "\nTotal: " . round($total, 1) . "ms\n";
if ($total < 50) {
    echo "PASS: Under 50ms threshold\n";
} elseif ($total < 200) {
    echo "OK: Under 200ms (acceptable)\n";
} else {
    echo "WARNING: Over 200ms, may need further optimization\n";
}
