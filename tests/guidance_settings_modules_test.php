<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/admin/guidance/settings';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'text/html';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

use Ikabud\Kernel\Database\MigrationRunner;

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  PASS {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  FAIL {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

function unexpectedAppLogLines(string $content): array
{
    return array_values(array_filter(explode("\n", $content), static function (string $line): bool {
        if (trim($line) === '') {
            return false;
        }

        return str_contains($line, '[error]') || str_contains($line, '[critical]');
    }));
}

function hasGuidanceTenantSchema(PDO $db): bool
{
    try {
        $users = $db->query("SHOW TABLES LIKE 'gm_users'");
        $settings = $db->query("SHOW TABLES LIKE 'gm_settings'");
        return (bool)($users && $users->fetchColumn()) && (bool)($settings && $settings->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function resolveGuidanceTenant(): array
{
    $controlDb = app()->controlDb();
    $stmt = $controlDb->query(
        "SELECT t.id, COALESCE(d.domain, '') AS domain\n"
        . "FROM kernel_tenants t\n"
        . "LEFT JOIN kernel_tenant_domains d ON d.tenant_id = t.id\n"
        . "WHERE t.status = 'active' AND t.entry_module_id = 'guidance'\n"
        . "ORDER BY t.id ASC"
    );
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($rows as $row) {
        $tenantId = (int)($row['id'] ?? 0);
        if ($tenantId <= 0) {
            continue;
        }

        $tenantDb = app()->dbForTenant($tenantId);
        if (!$tenantDb instanceof PDO || !hasGuidanceTenantSchema($tenantDb)) {
            continue;
        }

        return [
            'tenant_id' => $tenantId,
            'domain' => trim((string)($row['domain'] ?? '')),
        ];
    }

    return ['tenant_id' => 0, 'domain' => ''];
}

function fetchSingleAssoc(PDO $db, string $sql, array $params = []): ?array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function fetchAllAssoc(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function deleteRowsByConditions(PDO $db, string $table, array $conditions): void
{
    $clauses = [];
    $params = [];
    foreach ($conditions as $column => $value) {
        $placeholder = ':' . $column;
        $clauses[] = $column . ' = ' . $placeholder;
        $params[$placeholder] = $value;
    }

    $sql = 'DELETE FROM ' . $table . ' WHERE ' . implode(' AND ', $clauses);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

function insertAssocRow(PDO $db, string $table, array $row): void
{
    if ($row === []) {
        return;
    }

    $columns = array_keys($row);
    $placeholders = array_map(static function (string $column): string {
        return ':' . $column;
    }, $columns);

    $sql = 'INSERT INTO ' . $table
        . ' (' . implode(', ', $columns) . ')'
        . ' VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $db->prepare($sql);
    $params = [];
    foreach ($row as $column => $value) {
        $params[':' . $column] = $value;
    }
    $stmt->execute($params);
}

$modules = discoverModules();
$guidance = $modules['guidance'] ?? null;
$guidanceSms = $modules['guidance-sms'] ?? null;
if (!is_array($guidance)) {
    fwrite(STDERR, "Guidance module manifest not found.\n");
    exit(1);
}
if (!is_array($guidanceSms)) {
    fwrite(STDERR, "Guidance SMS add-on manifest not found.\n");
    exit(1);
}

loadModuleHelpers($guidance);
moduleWithContext('guidance', static function () use ($guidance): void {
    require_once (string)($guidance['_path'] ?? '') . '/handlers.php';
});

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$tenant = resolveGuidanceTenant();
$tenantId = (int)($tenant['tenant_id'] ?? 0);
$tenantDomain = trim((string)($tenant['domain'] ?? ''));

if ($tenantId <= 0) {
    fwrite(STDERR, "No active Guidance tenant database with the required schema is available.\n");
    exit(1);
}

$originalTenantId = app()->tenant()->current();
app()->tenant()->setTenantId($tenantId);
app()->reconnectDb();
invalidateModuleContextCache('guidance');

$tenantDb = app()->db();
$controlDb = app()->controlDb();
$runner = new MigrationRunner($controlDb);
$runner->migrate('_control');

$originalGuidanceEntitlement = fetchSingleAssoc(
    $controlDb,
    'SELECT * FROM ' . moduleTenantEntitlementsTable() . ' WHERE tenant_id = :tenant_id AND module_id = :module_id LIMIT 1',
    [':tenant_id' => $tenantId, ':module_id' => 'guidance']
);
$originalSmsCatalog = fetchSingleAssoc(
    $controlDb,
    'SELECT * FROM ' . moduleCatalogTable() . ' WHERE module_id = :module_id LIMIT 1',
    [':module_id' => 'guidance-sms']
);
$originalSmsEntitlement = fetchSingleAssoc(
    $controlDb,
    'SELECT * FROM ' . moduleTenantEntitlementsTable() . ' WHERE tenant_id = :tenant_id AND module_id = :module_id LIMIT 1',
    [':tenant_id' => $tenantId, ':module_id' => 'guidance-sms']
);
$originalSmsAccessRequest = fetchSingleAssoc(
    $controlDb,
    'SELECT * FROM ' . moduleAccessRequestsTable() . ' WHERE tenant_id = :tenant_id AND module_id = :module_id LIMIT 1',
    [':tenant_id' => $tenantId, ':module_id' => 'guidance-sms']
);

deleteRowsByConditions($controlDb, moduleAccessRequestsTable(), [
    'tenant_id' => $tenantId,
    'module_id' => 'guidance-sms',
]);
$originalSmsTenantSettings = fetchAllAssoc(
    $tenantDb,
    'SELECT * FROM ' . moduleTenantSettingsTable() . ' WHERE tenant_id = :tenant_id AND module_id = :module_id ORDER BY setting_key',
    [':tenant_id' => $tenantId, ':module_id' => 'guidance-sms']
);

$stamp = (string)time() . bin2hex(random_bytes(3));
$adminEmail = 'guidance-modules-admin-' . $stamp . '@example.test';
$adminId = 0;

try {
    $userStmt = $tenantDb->prepare(
        'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );
    $userStmt->execute([$adminEmail, password_hash('unused-password', PASSWORD_BCRYPT), 'Modules', 'Admin', 'admin']);
    $adminId = (int)$tenantDb->lastInsertId();

    $token = app()->jwt()->generate([
        'sub' => 'admin:' . $adminId,
        'id' => $adminId,
        'username' => $adminEmail,
        'name' => 'Modules Admin',
        'role' => 'admin',
        'source' => 'guidance',
    ]);

    $_COOKIE['guidance_staff_token'] = $token;
    $_SERVER['HTTP_COOKIE'] = 'guidance_staff_token=' . $token;

    $guidanceGranted = grantModuleEntitlementForTenant('guidance', $tenantId, [
        'status' => 'active',
        'tier' => 'free',
        'source' => 'guidance_settings_modules_test',
        'metadata' => ['via' => 'guidance_settings_modules_test'],
    ]);
    $catalogOk = upsertModuleCatalogEntry('guidance-sms', [
        'module_name' => 'Guidance SMS Addon',
        'approved_version' => (string)($guidanceSms['version'] ?? '1.0.0'),
        'install_path' => (string)($guidanceSms['_path'] ?? (modulesPath() . '/guidance-sms')),
        'source' => 'guidance_settings_modules_test',
        'approval_status' => 'approved',
        'commercial_mode' => 'paid',
    ]);
    $smsRevoked = revokeModuleEntitlementForTenant('guidance-sms', $tenantId, [
        'source' => 'guidance_settings_modules_test',
        'metadata' => ['via' => 'guidance_settings_modules_test', 'state' => 'revoked'],
    ]);
    disableModuleForTenant('guidance-sms', $tenantId);
    invalidateModuleCatalogCache();

    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidanceSettings();
    });
    $settingsPage = (string)ob_get_clean();

    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidanceSettingsModules();
    });
    $modulesPartial = (string)ob_get_clean();

    t('guidance entitlement can be granted for settings module checks', $guidanceGranted);
    t('guidance sms catalog entry can be approved for settings module checks', $catalogOk);
    t('guidance sms entitlement can be reset to revoked before access checks', $smsRevoked);
    t('settings navigation includes Modules section', str_contains($settingsPage, 'data-section="modules"'), 'Modules nav link missing');
    t('settings page renders Guidance SMS add-on card', str_contains($settingsPage, 'Guidance SMS Addon'), 'Guidance SMS add-on missing from settings page');
    t('paid guidance add-on prompts for access request before activation', str_contains($modulesPartial, 'Request Access') && str_contains($modulesPartial, 'requires tenant access'), $modulesPartial);

    $accessRequest = submitModuleAccessRequestForTenant('guidance-sms', $tenantId, [
        'requested_mode' => 'paid',
        'request_notes' => 'Need SMS delivery for appointment reminders.',
        'license_key' => 'GSMS-1234-ABCD-9999',
        'requested_by_user_id' => $adminId,
        'metadata' => ['via' => 'guidance_settings_modules_test'],
    ]);
    invalidateModuleCatalogCache();

    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidanceSettingsModules();
    });
    $pendingModulesPartial = (string)ob_get_clean();

    t('guidance add-on access request can be submitted', !empty($accessRequest['ok']), (string)($accessRequest['error'] ?? ''));
    t('module partial reflects pending access request state', str_contains($pendingModulesPartial, 'Pending') && str_contains($pendingModulesPartial, 'GSMS...9999'), $pendingModulesPartial);

    $smsGranted = grantModuleEntitlementForTenant('guidance-sms', $tenantId, [
        'status' => 'active',
        'tier' => 'paid',
        'source' => 'guidance_settings_modules_test',
        'metadata' => ['via' => 'guidance_settings_modules_test'],
    ]);
    enableModuleForTenant('guidance-sms', $tenantId);
    $savedSettings = moduleWithContext('guidance', static function (): array {
        return guidanceManagedModuleSaveSettings('guidance-sms', [
            'sms_gateway' => 'semaphore',
        ]);
    });

    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidanceSettingsModules();
    });
    $activeModulesPartial = (string)ob_get_clean();

    t('guidance sms entitlement can be granted after approval', $smsGranted);
    t('guidance sms settings save persists tenant value', (($savedSettings['sms_gateway'] ?? '') === 'semaphore'), json_encode($savedSettings, JSON_UNESCAPED_SLASHES));
    t('module partial reflects active add-on state after enable', str_contains($activeModulesPartial, 'Active') && str_contains($activeModulesPartial, 'Configure') && str_contains($activeModulesPartial, 'Deactivate'), $activeModulesPartial);

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

    t('guidance settings modules checks leave app.log free of errors', unexpectedAppLogLines($appLog) === [], implode('; ', unexpectedAppLogLines($appLog)));
    t('guidance settings modules checks leave error.log empty', trim($errorLog) === '', trim($errorLog));
} finally {
    unset($_COOKIE['guidance_staff_token'], $_SERVER['HTTP_COOKIE']);

    try {
        if ($adminId > 0) {
            $tenantDb->prepare('DELETE FROM gm_users WHERE id = ?')->execute([$adminId]);
        }
    } catch (Throwable $e) {
        // ignore cleanup failures in test teardown
    }

    try {
        deleteRowsByConditions($tenantDb, moduleTenantSettingsTable(), [
            'tenant_id' => $tenantId,
            'module_id' => 'guidance-sms',
        ]);
        foreach ($originalSmsTenantSettings as $row) {
            insertAssocRow($tenantDb, moduleTenantSettingsTable(), $row);
        }
    } catch (Throwable $e) {
        // ignore cleanup failures in test teardown
    }

    try {
        deleteRowsByConditions($controlDb, moduleAccessRequestsTable(), [
            'tenant_id' => $tenantId,
            'module_id' => 'guidance-sms',
        ]);
        if (is_array($originalSmsAccessRequest)) {
            insertAssocRow($controlDb, moduleAccessRequestsTable(), $originalSmsAccessRequest);
        }
    } catch (Throwable $e) {
        // ignore cleanup failures in test teardown
    }

    try {
        deleteRowsByConditions($controlDb, moduleTenantEntitlementsTable(), [
            'tenant_id' => $tenantId,
            'module_id' => 'guidance-sms',
        ]);
        if (is_array($originalSmsEntitlement)) {
            insertAssocRow($controlDb, moduleTenantEntitlementsTable(), $originalSmsEntitlement);
        }
    } catch (Throwable $e) {
        // ignore cleanup failures in test teardown
    }

    try {
        deleteRowsByConditions($controlDb, moduleCatalogTable(), [
            'module_id' => 'guidance-sms',
        ]);
        if (is_array($originalSmsCatalog)) {
            insertAssocRow($controlDb, moduleCatalogTable(), $originalSmsCatalog);
        }
    } catch (Throwable $e) {
        // ignore cleanup failures in test teardown
    }

    try {
        deleteRowsByConditions($controlDb, moduleTenantEntitlementsTable(), [
            'tenant_id' => $tenantId,
            'module_id' => 'guidance',
        ]);
        if (is_array($originalGuidanceEntitlement)) {
            insertAssocRow($controlDb, moduleTenantEntitlementsTable(), $originalGuidanceEntitlement);
        }
    } catch (Throwable $e) {
        // ignore cleanup failures in test teardown
    }

    invalidateModuleCatalogCache();
    app()->tenant()->setTenantId($originalTenantId);
    app()->reconnectDb();
    invalidateModuleContextCache('guidance');
}

echo "\n==========================================\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "==========================================\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo '  - ' . $error . "\n";
    }
}

exit($fail > 0 ? 1 : 0);