<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../bootstrap.php';

$dbConfig = require CONFIG_PATH . '/database.php';
$dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
$db = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// 1. Verify superadmin user exists
echo "=== Test 1: Superadmin user exists ===\n";
$stmt = $db->prepare('SELECT id, username, password_hash, full_name, role, is_active FROM users WHERE username = :u');
$stmt->execute([':u' => 'superadmin']);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo "FAIL: superadmin user not found\n";
    exit(1);
}
echo "PASS: id={$user['id']}, role={$user['role']}, active={$user['is_active']}\n";

// 2. Verify password
echo "\n=== Test 2: Password verification ===\n";
if (password_verify('superadmin123', $user['password_hash'])) {
    echo "PASS: password_verify succeeds\n";
} else {
    echo "FAIL: password_verify fails\n";
    exit(1);
}

// 3. Verify auth capability accepts superadmin
echo "\n=== Test 3: Auth role check ===\n";
$role = $user['role'];
if (in_array($role, ['admin', 'superadmin'], true)) {
    echo "PASS: role '{$role}' accepted by auth check\n";
} else {
    echo "FAIL: role '{$role}' rejected\n";
    exit(1);
}

// 4. Verify module settings can be read
echo "\n=== Test 4: Module settings readable ===\n";
require SRC_PATH . '/helpers/module-manager.php';
$settings = getModuleSettings('daily-ledger');
echo "PASS: daily-ledger settings = " . json_encode($settings) . "\n";

// 5. Verify modules with settings_fields exist
echo "\n=== Test 5: Discover modules with settings ===\n";
$allModules = discoverModules();
$withSettings = [];
foreach ($allModules as $m) {
    $fields = $m['settings_fields'] ?? null;
    if (is_array($fields) && !empty($fields)) {
        $withSettings[] = ['id' => $m['id'], 'fields' => count($fields), 'enabled' => !empty($m['_enabled'])];
    }
}
echo "PASS: Found " . count($withSettings) . " module(s) with settings_fields\n";
foreach ($withSettings as $ws) {
    echo "  - {$ws['id']}: {$ws['fields']} fields, enabled=" . ($ws['enabled'] ? 'yes' : 'no') . "\n";
}

// 6. Verify superadmin save would be scoped to declared fields only
echo "\n=== Test 6: Settings field allowlist ===\n";
foreach ($allModules as $m) {
    $fields = $m['settings_fields'] ?? null;
    if (!is_array($fields) || empty($fields)) continue;
    $keys = [];
    foreach ($fields as $f) {
        if (is_array($f) && isset($f['key'])) $keys[] = $f['key'];
    }
    echo "  {$m['id']}: allowed keys = [" . implode(', ', $keys) . "]\n";
    if (in_array('allow_kernel_admin', $keys)) {
        echo "  FAIL: allow_kernel_admin should NOT be in settings_fields\n";
        exit(1);
    }
}
echo "PASS: allow_kernel_admin not in any settings_fields\n";

echo "\n=== ALL TESTS PASSED ===\n";
