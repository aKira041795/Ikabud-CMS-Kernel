<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/admin/guidance/settings';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'text/html';

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

function upsertGuidanceSetting(PDO $db, string $key, string $value): void
{
    $stmt = $db->prepare(
        'INSERT INTO gm_settings (setting_key, setting_value, setting_type, updated_by, updated_at) '
        . 'VALUES (?, ?, ?, NULL, NOW()) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), updated_at = NOW()'
    );
    $stmt->execute([$key, $value, 'string']);
}

function restoreGuidanceSettingsRows(PDO $db, array $rows): void
{
    $db->prepare("DELETE FROM gm_settings WHERE setting_key IN ('two_fa_login', 'two_fa_booking')")->execute();
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        insertAssocRow($db, 'gm_settings', $row);
    }
}

$modules = discoverModules();
$guidance = $modules['guidance'] ?? null;
if (!is_array($guidance)) {
    fwrite(STDERR, "Guidance module manifest not found.\n");
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

$originalGuidanceEntitlement = fetchSingleAssoc(
    $controlDb,
    'SELECT * FROM ' . moduleTenantEntitlementsTable() . ' WHERE tenant_id = :tenant_id AND module_id = :module_id LIMIT 1',
    [':tenant_id' => $tenantId, ':module_id' => 'guidance']
);
$originalSettingsRows = fetchAllAssoc(
    $tenantDb,
    "SELECT * FROM gm_settings WHERE setting_key IN ('two_fa_login', 'two_fa_booking') ORDER BY setting_key"
);

$stamp = (string)time() . bin2hex(random_bytes(3));
$adminEmail = 'guidance-2fa-admin-' . $stamp . '@example.test';
$adminId = 0;

try {
    $userStmt = $tenantDb->prepare(
        'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );
    $userStmt->execute([$adminEmail, password_hash('unused-password', PASSWORD_BCRYPT), 'TwoFA', 'Admin', 'admin']);
    $adminId = (int)$tenantDb->lastInsertId();

    $token = app()->jwt()->generate([
        'sub' => 'admin:' . $adminId,
        'id' => $adminId,
        'username' => $adminEmail,
        'name' => 'TwoFA Admin',
        'role' => 'admin',
        'source' => 'guidance',
    ]);

    $_COOKIE['guidance_staff_token'] = $token;
    $_SERVER['HTTP_COOKIE'] = 'guidance_staff_token=' . $token;

    upsertGuidanceSetting($tenantDb, 'two_fa_login', '1');
    upsertGuidanceSetting($tenantDb, 'two_fa_booking', '1');

    $freeGranted = grantModuleEntitlementForTenant('guidance', $tenantId, [
        'status' => 'active',
        'tier' => 'free',
        'source' => 'guidance_2fa_settings_gate_test',
        'metadata' => ['via' => 'guidance_2fa_settings_gate_test', 'tier' => 'free'],
    ]);
    invalidateModuleContextCache('guidance');

    $freeSettings = moduleWithContext('guidance', static function (): array {
        return guidanceGetAllSettings();
    });
    $freeLoginOtpEnabled = moduleWithContext('guidance', static function (): bool {
        return guidanceOtpEnabled('two_fa_login');
    });
    $freeBookingOtpEnabled = moduleWithContext('guidance', static function (): bool {
        return guidanceOtpEnabled('two_fa_booking');
    });

    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidanceSettings();
    });
    $freeSettingsPage = (string)ob_get_clean();

    $_SERVER['REQUEST_URI'] = '/guidance/book';
    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidancePublicBooking();
    });
    $freeBookingPage = (string)ob_get_clean();

    $proGranted = grantModuleEntitlementForTenant('guidance', $tenantId, [
        'status' => 'active',
        'tier' => 'pro',
        'source' => 'guidance_2fa_settings_gate_test',
        'metadata' => ['via' => 'guidance_2fa_settings_gate_test', 'tier' => 'pro'],
    ]);
    invalidateModuleContextCache('guidance');

    $proLoginOtpEnabled = moduleWithContext('guidance', static function (): bool {
        return guidanceOtpEnabled('two_fa_login');
    });
    $proBookingOtpEnabled = moduleWithContext('guidance', static function (): bool {
        return guidanceOtpEnabled('two_fa_booking');
    });

    $_SERVER['REQUEST_URI'] = '/admin/guidance/settings';
    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidanceSettings();
    });
    $proSettingsPage = (string)ob_get_clean();

    $_SERVER['REQUEST_URI'] = '/guidance/book';
    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidancePublicBooking();
    });
    $proBookingPage = (string)ob_get_clean();

    t('guidance free entitlement can be granted for 2FA gate checks', $freeGranted);
    t('guidance settings persist admin two_fa_login toggle', (($freeSettings['two_fa_login'] ?? '') === '1'), json_encode($freeSettings, JSON_UNESCAPED_SLASHES));
    t('guidance settings persist admin two_fa_booking toggle', (($freeSettings['two_fa_booking'] ?? '') === '1'), json_encode($freeSettings, JSON_UNESCAPED_SLASHES));
    t('free tier ignores stored staff 2FA toggle', $freeLoginOtpEnabled === false);
    t('free tier ignores stored booking 2FA toggle', $freeBookingOtpEnabled === false);
    t('free settings page shows Guidance Pro upgrade state for 2FA', str_contains($freeSettingsPage, 'Two-factor authentication is available on Guidance Pro.'), $freeSettingsPage);
    t('free public booking page hides OTP verification notice', !str_contains($freeBookingPage, 'Email verification is enabled. A 6-digit code will be sent to the student email before the booking request is submitted.'), $freeBookingPage);

    t('guidance pro entitlement can be granted for 2FA gate checks', $proGranted);
    t('pro tier enables stored staff 2FA toggle', $proLoginOtpEnabled === true);
    t('pro tier enables stored booking 2FA toggle', $proBookingOtpEnabled === true);
    t('pro settings page reflects checked staff 2FA toggle', str_contains($proSettingsPage, 'name="two_fa_login" value="1" checked'), $proSettingsPage);
    t('pro settings page reflects checked booking 2FA toggle', str_contains($proSettingsPage, 'name="two_fa_booking" value="1" checked'), $proSettingsPage);
    t('pro public booking page shows OTP verification notice', str_contains($proBookingPage, 'Email verification is enabled. A 6-digit code will be sent to the student email before the booking request is submitted.'), $proBookingPage);

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

    t('guidance 2FA gate checks leave app.log free of errors', unexpectedAppLogLines($appLog) === [], implode('; ', unexpectedAppLogLines($appLog)));
    t('guidance 2FA gate checks leave error.log empty', trim($errorLog) === '', trim($errorLog));
} finally {
    unset($_COOKIE['guidance_staff_token'], $_SERVER['HTTP_COOKIE']);
    $_SERVER['REQUEST_URI'] = '/admin/guidance/settings';

    try {
        restoreGuidanceSettingsRows($tenantDb, $originalSettingsRows);
    } catch (Throwable $e) {
        // ignore cleanup failures in test teardown
    }

    try {
        $controlDb->prepare('DELETE FROM ' . moduleTenantEntitlementsTable() . ' WHERE tenant_id = :tenant_id AND module_id = :module_id')
            ->execute([':tenant_id' => $tenantId, ':module_id' => 'guidance']);
        if (is_array($originalGuidanceEntitlement)) {
            insertAssocRow($controlDb, moduleTenantEntitlementsTable(), $originalGuidanceEntitlement);
        }
        invalidateModuleCatalogCache();
    } catch (Throwable $e) {
        // ignore cleanup failures in test teardown
    }

    try {
        if ($adminId > 0) {
            $tenantDb->prepare('DELETE FROM gm_users WHERE id = ?')->execute([$adminId]);
        }
    } catch (Throwable $e) {
        // ignore cleanup failures in test teardown
    }

    app()->tenant()->setTenantId($originalTenantId);
    app()->reconnectDb();
    invalidateModuleContextCache('guidance');
}

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);