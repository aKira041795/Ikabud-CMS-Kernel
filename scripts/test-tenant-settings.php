<?php
/**
 * Test superadmin tenant-scoped settings functions.
 */

$_SERVER['HTTP_HOST'] = 'baronbakeshop.localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../src/helpers/module-manager.php';

// ── Test 1: New helper functions exist ────────────────────────────────────

echo "=== Test 1: New helper functions exist ===\n";
$funcs = [
    'readTenantModuleSettingsForTenant',
    'saveTenantModuleSettingsForTenant',
    'getModuleSettingsForTenant',
];
$allExist = true;
foreach ($funcs as $fn) {
    if (!function_exists($fn)) {
        echo "FAIL: function {$fn}() not found\n";
        $allExist = false;
    }
}
if ($allExist) {
    echo "PASS: All tenant-scoped helpers exist\n";
}

// ── Test 2: Multi-tenant mode check ──────────────────────────────────────

echo "\n=== Test 2: Multi-tenant mode check ===\n";
$mtEnabled = moduleTenantSettingsModeEnabled();
echo "Multi-tenant mode: " . ($mtEnabled ? 'ENABLED' : 'DISABLED') . "\n";

// ── Test 3: Read settings for explicit tenant (boundary: non-existent) ───

echo "\n=== Test 3: Read settings for non-existent tenant ===\n";
$result = readTenantModuleSettingsForTenant('contact-form', 99999);
echo "Result for tenant 99999: " . json_encode($result) . "\n";
echo (is_array($result) ? 'PASS' : 'FAIL') . ": Returns array\n";

// ── Test 4: Write + read round-trip for explicit tenant ──────────────────

echo "\n=== Test 4: Write + read round-trip ===\n";
$testTenantId = 1;
$testModuleId = 'contact-form';
$testSettings = ['_sa_test_key' => 'test_value_' . time()];

$writeOk = saveTenantModuleSettingsForTenant($testModuleId, $testTenantId, $testSettings);
echo "Write result: " . ($writeOk ? 'OK' : 'FAILED') . "\n";

if ($writeOk) {
    $readBack = readTenantModuleSettingsForTenant($testModuleId, $testTenantId);
    if (($readBack['_sa_test_key'] ?? '') === $testSettings['_sa_test_key']) {
        echo "PASS: Read-back matches written value\n";
    } else {
        echo "FAIL: Read-back mismatch. Got: " . json_encode($readBack) . "\n";
    }

    // Clean up test key
    $GLOBALS['_kernel_db_unguarded'] = true;
    try {
        $db = app()->db();
        $stmt = $db->prepare(
            'DELETE FROM tenant_module_settings WHERE tenant_id = :tid AND module_id = :mid AND setting_key = :skey'
        );
        $stmt->execute([':tid' => $testTenantId, ':mid' => $testModuleId, ':skey' => '_sa_test_key']);
        echo "Cleanup: test key removed\n";
    } catch (Throwable $e) {
        echo "Cleanup note: " . $e->getMessage() . "\n";
    } finally {
        $GLOBALS['_kernel_db_unguarded'] = false;
    }
} else {
    echo "SKIP: Write failed (table may not exist in single-tenant mode)\n";
}

// ── Test 5: getModuleSettingsForTenant merges correctly ──────────────────

echo "\n=== Test 5: getModuleSettingsForTenant merges lifecycle keys ===\n";
$merged = getModuleSettingsForTenant('daily-ledger', $testTenantId);
echo "Merged keys: " . implode(', ', array_keys($merged)) . "\n";
// Should NOT contain internal _ prefixed keys
$hasInternal = false;
foreach (array_keys($merged) as $k) {
    if (str_starts_with($k, '_')) {
        $hasInternal = true;
        echo "FAIL: Internal key '{$k}' leaked\n";
    }
}
if (!$hasInternal) {
    echo "PASS: No internal _-prefixed keys leaked\n";
}

// ── Test 6: Boundary — empty module ID ───────────────────────────────────

echo "\n=== Test 6: Boundary checks ===\n";
$r1 = readTenantModuleSettingsForTenant('', 1);
$r2 = readTenantModuleSettingsForTenant('contact-form', 0);
$r3 = saveTenantModuleSettingsForTenant('', 1, ['k' => 'v']);
$r4 = saveTenantModuleSettingsForTenant('contact-form', 0, ['k' => 'v']);
echo "Empty module read: " . json_encode($r1) . " — " . (empty($r1) ? 'PASS' : 'FAIL') . "\n";
echo "Zero tenant read: " . json_encode($r2) . " — " . (empty($r2) ? 'PASS' : 'FAIL') . "\n";
echo "Empty module write: " . ($r3 ? 'FAIL' : 'PASS') . "\n";
echo "Zero tenant write: " . ($r4 ? 'FAIL' : 'PASS') . "\n";

echo "\n=== ALL TESTS COMPLETE ===\n";
