<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'baronledger.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/daily-ledger/admin/settings';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/daily-ledger/helpers.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function dlSettingsCompareDisplay(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  [PASS] {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function dlDecodeRolePermissionsForTest(mixed $value): mixed
{
    if (!is_string($value) || $value === '') {
        return $value;
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $value;
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== DAILY LEDGER SETTINGS COMPARE TEST ===\n\n";

$originalSettings = getModuleSettings('daily-ledger');
$originalRolePermissions = $originalSettings['role_permissions'] ?? null;

$expectedRolePermissions = [
    'admin' => ['ledger.override', 'production.override'],
    'supervisor' => ['production.override', 'ledger.override'],
    'production_in_charge' => [],
    'cashier' => [],
];

$reorderedRolePermissions = [
    'cashier' => [],
    'production_in_charge' => [],
    'supervisor' => ['production.override', 'ledger.override'],
    'admin' => ['ledger.override', 'production.override'],
];

try {
    dlSettingsCompareDisplay(
        'associative role permissions compare equal despite key order drift',
        dlSettingValuesMatch($reorderedRolePermissions, $expectedRolePermissions),
        json_encode([$reorderedRolePermissions, $expectedRolePermissions], JSON_UNESCAPED_SLASHES)
    );

    dlSettingsCompareDisplay(
        'nested associative arrays normalize recursively',
        dlSettingValuesMatch(
            ['settings' => ['timezone' => 'Asia/Manila', 'region' => 'Mindanao']],
            ['settings' => ['region' => 'Mindanao', 'timezone' => 'Asia/Manila']]
        )
    );

    $persisted = dlPersistModuleSettings(['role_permissions' => $reorderedRolePermissions]);
    dlSettingsCompareDisplay('dlPersistModuleSettings accepts reordered role permissions payload', $persisted);

    $storedSettings = getModuleSettings('daily-ledger');
    $storedRolePermissions = dlDecodeRolePermissionsForTest($storedSettings['role_permissions'] ?? null);
    dlSettingsCompareDisplay(
        'stored role permissions still match normalized expectation',
        dlSettingValuesMatch($storedRolePermissions, $expectedRolePermissions),
        json_encode($storedRolePermissions, JSON_UNESCAPED_SLASHES)
    );
} finally {
    saveModuleSettings('daily-ledger', ['role_permissions' => $originalRolePermissions]);
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
dlSettingsCompareDisplay('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
dlSettingsCompareDisplay('no error.log errors', $errorLog === '', $errorLog);

echo "\n" . str_repeat('-', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    - {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);