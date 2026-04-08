<?php

require_once __DIR__ . '/../bootstrap.php';

// Prepare mock test to fail module authority 
require_once __DIR__ . '/../src/helpers/module-manager.php';

$app = app();

echo "Initial getEnabledModules call:\n";
try {
    $mods = getEnabledModules();
    echo "Found " . count($mods) . " modules.\n";
} catch (\Exception $e) {
    echo "Failed: " . $e->getMessage() . "\n";
}

echo "\nInjecting artificial conflict...\n";

// Force register authority manually
try {
    $app->entityAuthority()->registerAuthority('products', 'ecommerce');
    $app->entityAuthority()->registerAuthority('products', 'some_other_module');
    echo "FAIL: Expected exception not thrown.\n";
} catch (\RuntimeException $e) {
    echo "PASS: Caught expected exception: " . $e->getMessage() . "\n";
}

