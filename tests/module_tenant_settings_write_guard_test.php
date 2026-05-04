<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/modules';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function resolveAnyActiveTenantId(): int
{
    $controlDb = app()->controlDb();
    $stmt = $controlDb->query("SELECT id FROM kernel_tenants WHERE status = 'active' ORDER BY id ASC");
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($rows as $row) {
        $tenantId = (int)($row['id'] ?? 0);
        if ($tenantId <= 0) {
            continue;
        }

        $tenantDb = app()->dbForTenant($tenantId);
        if ($tenantDb instanceof PDO) {
            return $tenantId;
        }
    }

    return 0;
}

echo "\n=== MODULE TENANT SETTINGS WRITE GUARD ===\n\n";

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');

$tenantId = resolveAnyActiveTenantId();
if ($tenantId <= 0) {
    fwrite(STDERR, "No active tenant database is available for module tenant settings guard test.\n");
    exit(1);
}

$originalTenantId = app()->tenant()->current();
app()->tenant()->setTenantId($tenantId);
app()->reconnectDb();

$settingKey = 'module_write_guard_' . bin2hex(random_bytes(4));
$tenantDb = app()->dbForTenant($tenantId);

try {
    $sameTenantWriteOk = moduleWithContext('cms', static function () use ($tenantId, $settingKey): bool {
        return saveTenantModuleSettingsForTenant('cms', $tenantId, [$settingKey => 'allowed']);
    });
    t('module context can write explicit settings for the active tenant', $sameTenantWriteOk === true);

    $storedStmt = $tenantDb->prepare(
        'SELECT setting_value FROM ' . moduleTenantSettingsTable() . ' WHERE tenant_id = :tenant_id AND module_id = :module_id AND setting_key = :setting_key LIMIT 1'
    );
    $storedStmt->execute([
        ':tenant_id' => $tenantId,
        ':module_id' => 'cms',
        ':setting_key' => $settingKey,
    ]);
    $storedValue = $storedStmt->fetchColumn();
    t('same-tenant explicit write persists the requested value', $storedValue === '"allowed"', (string)$storedValue);

    $blockedTenantId = $tenantId + 999999;
    $crossTenantWriteOk = moduleWithContext('cms', static function () use ($blockedTenantId, $settingKey): bool {
        return saveTenantModuleSettingsForTenant('cms', $blockedTenantId, [$settingKey => 'blocked']);
    });
    t('module context cross-tenant explicit write is denied', $crossTenantWriteOk === false);

    $appLog = @file_get_contents($appLogPath) ?: '';
    t('blocked cross-tenant write is logged', str_contains($appLog, 'Blocked cross-tenant tenant_module_settings write from module context'), $appLog);

    $errorLog = @file_get_contents($errorLogPath) ?: '';
    t('write guard does not emit PHP errors', trim($errorLog) === '', trim($errorLog));
} finally {
    if ($tenantDb instanceof PDO) {
        $cleanup = $tenantDb->prepare(
            'DELETE FROM ' . moduleTenantSettingsTable() . ' WHERE tenant_id = :tenant_id AND module_id = :module_id AND setting_key = :setting_key'
        );
        $cleanup->execute([
            ':tenant_id' => $tenantId,
            ':module_id' => 'cms',
            ':setting_key' => $settingKey,
        ]);
    }

    app()->tenant()->setTenantId($originalTenantId);
    app()->reconnectDb();
}

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

exit(0);