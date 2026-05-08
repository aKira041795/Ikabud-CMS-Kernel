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

function resolveAnyReadableTenantId(): int
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

echo "\n=== MODULE TENANT SETTINGS READ GUARD ===\n\n";

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');

$tenantId = resolveAnyReadableTenantId();
if ($tenantId <= 0) {
    fwrite(STDERR, "No active tenant database is available for module tenant settings read guard test.\n");
    exit(1);
}

$originalTenantId = app()->tenant()->current();
app()->tenant()->setTenantId($tenantId);
app()->reconnectDb();

try {
    $sameTenantSettings = moduleWithContext('cms', static function () use ($tenantId): array {
        return readTenantModuleSettingsForTenant('cms', $tenantId);
    });
    t('module context can read explicit settings for the active tenant', is_array($sameTenantSettings));

    @file_put_contents($appLogPath, '');
    $blockedTenantId = $tenantId + 999999;
    $blockedSettings = moduleWithContext('cms', static function () use ($blockedTenantId): array {
        return readTenantModuleSettingsForTenant('cms', $blockedTenantId);
    });
    $blockedLog = @file_get_contents($appLogPath) ?: '';
    t('module context cross-tenant explicit read is denied', $blockedSettings === [], json_encode($blockedSettings, JSON_UNESCAPED_SLASHES));
    t('blocked cross-tenant read is logged', str_contains($blockedLog, 'Blocked cross-tenant tenant_module_settings read from module context'), $blockedLog);

    @file_put_contents($appLogPath, '');
    $allowedSettings = moduleWithContext('guidance', static function () use ($blockedTenantId): array {
        return readTenantModuleSettingsForTenant('ecommerce', $blockedTenantId);
    });
    $allowedLog = @file_get_contents($appLogPath) ?: '';
    t('guidance can still perform allowed ecommerce cross-tenant read', $allowedSettings === [], json_encode($allowedSettings, JSON_UNESCAPED_SLASHES));
    t('allowed ecommerce cross-tenant read does not trigger the guard log', !str_contains($allowedLog, 'Blocked cross-tenant tenant_module_settings read from module context'), $allowedLog);

    $errorLog = @file_get_contents($errorLogPath) ?: '';
    t('read guard does not emit PHP errors', trim($errorLog) === '', trim($errorLog));
} finally {
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