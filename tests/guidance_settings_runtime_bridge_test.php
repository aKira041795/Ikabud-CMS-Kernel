<?php

declare(strict_types=1);

ob_start();

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

function upsertGuidanceSetting(PDO $db, string $key, string $value, string $type = 'string'): void
{
    $stmt = $db->prepare(
        'INSERT INTO gm_settings (setting_key, setting_value, setting_type, updated_by, updated_at) '
        . 'VALUES (?, ?, ?, NULL, NOW()) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), updated_at = NOW()'
    );
    $stmt->execute([$key, $value, $type]);
}

function restoreGuidanceSettingsRows(PDO $db, array $keys, array $rows): void
{
    if ($keys !== []) {
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $db->prepare('DELETE FROM gm_settings WHERE setting_key IN (' . $placeholders . ')')->execute($keys);
    }

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

$settingKeys = [
    'appointment_settings',
    'notification_settings',
    'appointment_slot_minutes',
    'max_appointments_per_day',
    'working_hours_start',
    'working_hours_end',
    'working_hours',
    'notification_channel',
    'email_notifications',
    'reminder_hours_before',
    'license_public_key_pem',
];

$originalRows = fetchAllAssoc(
    $tenantDb,
    'SELECT * FROM gm_settings WHERE setting_key IN (' . implode(', ', array_fill(0, count($settingKeys), '?')) . ') ORDER BY setting_key',
    $settingKeys
);

try {
    restoreGuidanceSettingsRows($tenantDb, $settingKeys, []);

    upsertGuidanceSetting($tenantDb, 'appointment_settings', json_encode([
        'max_booking_days_ahead' => 14,
        'default_duration_minutes' => 45,
        'buffer_minutes' => 7,
        'max_appointments_per_day' => 9,
        'notification_channel' => 'email_and_sms',
        'email_notifications' => '0',
        'reminder_hours_before' => 12,
    ], JSON_UNESCAPED_SLASHES), 'json');
    upsertGuidanceSetting($tenantDb, 'notification_settings', json_encode([
        'email_enabled' => false,
        'sms_enabled' => true,
        'appointment_reminder_hours' => 12,
    ], JSON_UNESCAPED_SLASHES), 'json');
    upsertGuidanceSetting($tenantDb, 'working_hours', json_encode([
        'monday' => ['start' => '09:00', 'end' => '16:30'],
        'tuesday' => ['start' => '09:00', 'end' => '16:30'],
        'wednesday' => ['start' => '09:00', 'end' => '16:30'],
        'thursday' => ['start' => '09:00', 'end' => '16:30'],
        'friday' => ['start' => '09:00', 'end' => '16:30'],
        'saturday' => null,
        'sunday' => null,
    ], JSON_UNESCAPED_SLASHES), 'json');
    upsertGuidanceSetting($tenantDb, 'license_public_key_pem', "-----BEGIN PUBLIC KEY-----\nTESTKEY\n-----END PUBLIC KEY-----");

    $settings = moduleWithContext('guidance', static function (): array {
        return guidanceGetAllSettings();
    });

    t('guidance settings hydrate slot duration from legacy appointment settings', ($settings['appointment_slot_minutes'] ?? '') === '45', json_encode($settings, JSON_UNESCAPED_SLASHES));
    t('guidance settings hydrate max appointments per day from legacy appointment settings', ($settings['max_appointments_per_day'] ?? '') === '9', json_encode($settings, JSON_UNESCAPED_SLASHES));
    t('guidance settings hydrate working hours start from legacy appointment settings', ($settings['working_hours_start'] ?? '') === '09:00', json_encode($settings, JSON_UNESCAPED_SLASHES));
    t('guidance settings hydrate working hours end from legacy appointment settings', ($settings['working_hours_end'] ?? '') === '16:30', json_encode($settings, JSON_UNESCAPED_SLASHES));
    t('guidance settings hydrate notification channel from legacy appointment settings', ($settings['notification_channel'] ?? '') === 'email_and_sms', json_encode($settings, JSON_UNESCAPED_SLASHES));
    t('guidance settings hydrate reminder hours from legacy appointment settings', ($settings['reminder_hours_before'] ?? '') === '12', json_encode($settings, JSON_UNESCAPED_SLASHES));
    t('guidance settings expose license public key override', str_contains((string)($settings['license_public_key_pem'] ?? ''), 'TESTKEY'), (string)($settings['license_public_key_pem'] ?? ''));

    $persistable = moduleWithContext('guidance', static function (): array {
        return guidanceSettingsPersistableInput([
            'appointment_slot_minutes' => '35',
            'max_appointments_per_day' => '6',
            'working_hours_start' => '08:30',
            'working_hours_end' => '15:45',
            'notification_channel' => 'email_only',
            'email_notifications' => '1',
            'reminder_hours_before' => '18',
        ]);
    });

    $mirrored = $persistable['appointment_settings'] ?? [];
    $mirroredNotificationSettings = $persistable['notification_settings'] ?? [];
    $mirroredWorkingHours = $persistable['working_hours'] ?? [];
    t('guidance settings bridge mirrors slot duration into appointment settings runtime shape', (int)($mirrored['default_duration_minutes'] ?? 0) === 35, json_encode($mirrored, JSON_UNESCAPED_SLASHES));
    t('guidance settings bridge mirrors max appointments per day into appointment settings runtime shape', (int)($mirrored['max_appointments_per_day'] ?? 0) === 6, json_encode($mirrored, JSON_UNESCAPED_SLASHES));
    t('guidance settings bridge mirrors working hours start into appointment settings runtime shape', (string)($mirrored['working_hours_start'] ?? '') === '08:30', json_encode($mirrored, JSON_UNESCAPED_SLASHES));
    t('guidance settings bridge mirrors working hours end into appointment settings runtime shape', (string)($mirrored['working_hours_end'] ?? '') === '15:45', json_encode($mirrored, JSON_UNESCAPED_SLASHES));
    t('guidance settings bridge preserves existing booking horizon from legacy appointment settings', (int)($mirrored['max_booking_days_ahead'] ?? 0) === 14, json_encode($mirrored, JSON_UNESCAPED_SLASHES));
    t('guidance settings bridge preserves existing buffer minutes from legacy appointment settings', (int)($mirrored['buffer_minutes'] ?? 0) === 7, json_encode($mirrored, JSON_UNESCAPED_SLASHES));
    t('guidance settings bridge mirrors notification controls into legacy notification_settings runtime shape', !empty($mirroredNotificationSettings['email_enabled'])
        && empty($mirroredNotificationSettings['sms_enabled'])
        && (int)($mirroredNotificationSettings['appointment_reminder_hours'] ?? 0) === 18, json_encode($mirroredNotificationSettings, JSON_UNESCAPED_SLASHES));
    t('guidance settings bridge mirrors working hours into the live working_hours runtime shape', (string)($mirroredWorkingHours['monday']['start'] ?? '') === '08:30'
        && (string)($mirroredWorkingHours['friday']['end'] ?? '') === '15:45'
        && (($mirroredWorkingHours['saturday'] ?? null) === null), json_encode($mirroredWorkingHours, JSON_UNESCAPED_SLASHES));

    ob_start();
    moduleWithContext('guidance', static function (): void {
        pageGuidanceSettings();
    });
    $settingsPage = (string)ob_get_clean();

    t('guidance settings page renders the license verification key field', str_contains($settingsPage, 'License Verify Key (Public PEM)'), $settingsPage);
    t('guidance settings page renders the stored license verification key', str_contains($settingsPage, 'TESTKEY'), $settingsPage);
} finally {
    restoreGuidanceSettingsRows($tenantDb, $settingKeys, $originalRows);
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
        echo "  - {$error}\n";
    }
}

ob_end_flush();

exit($fail > 0 ? 1 : 0);