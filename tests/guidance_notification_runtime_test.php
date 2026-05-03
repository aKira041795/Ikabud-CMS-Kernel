<?php

declare(strict_types=1);

ob_start();

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/admin/guidance/settings';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'application/json';

$capturedEmails = [];

function buildEmailTemplate(string $headline, string $content): string
{
    return '<h1>' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</h1>' . $content;
}

function sendEmail(string $to, string $subject, string $body, array $options = []): bool
{
    global $capturedEmails;
    $capturedEmails[] = compact('to', 'subject', 'body', 'options');
    return true;
}

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/EventTriggers.php';
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

function unexpectedRuntimeAppLogLines(string $content): array
{
    return array_values(array_filter(explode("\n", $content), static function (string $line): bool {
        if (trim($line) === '') {
            return false;
        }

        if (str_contains($line, 'trigger.execution') && str_contains($line, 'Capability not found: sms.send@1')) {
            return false;
        }

        return str_contains($line, '[error]') || str_contains($line, '[critical]');
    }));
}

function runtimeHasGuidanceTenantSchema(PDO $db): bool
{
    try {
        $settings = $db->query("SHOW TABLES LIKE 'gm_settings'");
        return (bool)($settings && $settings->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function runtimeResolveGuidanceTenant(): array
{
    $controlDb = app()->controlDb();
    $stmt = $controlDb->query(
        "SELECT t.id, COALESCE(d.domain, '') AS domain\n"
        . "FROM kernel_tenants t\n"
        . "LEFT JOIN kernel_tenant_domains d ON d.tenant_id = t.id\n"
        . "WHERE t.status = 'active' AND t.entry_module_id = 'guidance'\n"
        . 'ORDER BY t.id ASC'
    );
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($rows as $row) {
        $tenantId = (int)($row['id'] ?? 0);
        if ($tenantId <= 0) {
            continue;
        }

        $tenantDb = app()->dbForTenant($tenantId);
        if (!$tenantDb instanceof PDO || !runtimeHasGuidanceTenantSchema($tenantDb)) {
            continue;
        }

        return [
            'tenant_id' => $tenantId,
            'domain' => trim((string)($row['domain'] ?? '')),
        ];
    }

    return ['tenant_id' => 0, 'domain' => ''];
}

function runtimeFetchAllAssoc(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function runtimeInsertAssocRow(PDO $db, string $table, array $row): void
{
    if ($row === []) {
        return;
    }

    $columns = array_keys($row);
    $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
    $stmt = $db->prepare(
        'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );

    $params = [];
    foreach ($row as $column => $value) {
        $params[':' . $column] = $value;
    }

    $stmt->execute($params);
}

function runtimeRestoreGuidanceSettingsRows(PDO $db, array $keys, array $rows): void
{
    if ($keys !== []) {
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $db->prepare('DELETE FROM gm_settings WHERE setting_key IN (' . $placeholders . ')')->execute($keys);
    }

    foreach ($rows as $row) {
        if (is_array($row)) {
            runtimeInsertAssocRow($db, 'gm_settings', $row);
        }
    }
}

function runtimeUpsertGuidanceSetting(PDO $db, string $key, string $value, string $type = 'string'): void
{
    $stmt = $db->prepare(
        'INSERT INTO gm_settings (setting_key, setting_value, setting_type, updated_by, updated_at) '
        . 'VALUES (?, ?, ?, NULL, NOW()) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), updated_at = NOW()'
    );
    $stmt->execute([$key, $value, $type]);
}

$captureFile = STORAGE_PATH . '/cache/guidance_runtime_trigger_capture.jsonl';
@unlink($captureFile);

function test_guidance_runtime_capture_capability(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $file = STORAGE_PATH . '/cache/guidance_runtime_trigger_capture.jsonl';
    $row = [
        'capability_id' => $capabilityId,
        'provider_id' => $providerId,
        'payload' => $payload,
    ];
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    return ['ok' => true];
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

try {
    app()->capabilities()->register('test.guidance.runtime.capture@1', 'tests', 'test_guidance_runtime_capture_capability', 1, ['first']);
} catch (Throwable $e) {
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$tenant = runtimeResolveGuidanceTenant();
$tenantId = (int)($tenant['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    fwrite(STDERR, "No active Guidance tenant database with the required schema is available.\n");
    exit(1);
}

$originalTenantId = app()->tenant()->current();
app()->tenant()->setTenantId($tenantId);
app()->reconnectDb();
invalidateModuleContextCache('guidance');

$db = app()->db();
$settingKeys = ['appointment_settings', 'notification_settings', 'notification_channel', 'email_notifications', 'reminder_hours_before'];
$originalRows = runtimeFetchAllAssoc(
    $db,
    'SELECT * FROM gm_settings WHERE setting_key IN (' . implode(', ', array_fill(0, count($settingKeys), '?')) . ') ORDER BY setting_key',
    $settingKeys
);

try {
    runtimeRestoreGuidanceSettingsRows($db, $settingKeys, []);

    $db->prepare("DELETE FROM kernel_event_triggers WHERE capability_id = 'test.guidance.runtime.capture@1' AND event_key = 'guidance.booking.created'")
        ->execute();
    $db->prepare(
        'INSERT INTO kernel_event_triggers '
        . '(module, event_key, capability_id, provider, is_enabled, priority, template, max_per_minute, retry_count, timeout_ms, meta, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([
        'guidance',
        'guidance.booking.created',
        'test.guidance.runtime.capture@1',
        'tests',
        1,
        1,
        null,
        null,
        0,
        5000,
        json_encode(['marker' => 'guidance.booking.created'], JSON_UNESCAPED_SLASHES),
    ]);

    runtimeUpsertGuidanceSetting($db, 'appointment_settings', json_encode([
        'notification_channel' => 'email_only',
        'email_notifications' => '0',
        'reminder_hours_before' => 6,
    ], JSON_UNESCAPED_SLASHES), 'json');
    runtimeUpsertGuidanceSetting($db, 'notification_settings', json_encode([
        'email_enabled' => false,
        'sms_enabled' => false,
        'appointment_reminder_hours' => 6,
    ], JSON_UNESCAPED_SLASHES), 'json');

    global $capturedEmails;
    $capturedEmails = [];

    $sentWhenDisabled = moduleWithContext('guidance', static function (): bool {
        return guidanceSendAppointmentTemplateEmail('booking_received', 'student-disabled@example.test', [
            'student_name' => 'Disabled Student',
            'date' => 'April 20, 2026',
            'time' => '9:00 AM',
            'location' => 'Guidance Office',
            'reason' => '',
            'appointment_id' => '1001',
        ]);
    });

    $disabledPayload = moduleWithContext('guidance', static function (): array {
        return guidancePublicBookingSuccessPayload([
            'student_name' => 'Disabled Student',
            'student_email' => 'student-disabled@example.test',
            'scheduled_date' => '2026-04-20',
            'scheduled_time' => '09:00',
        ], 1001);
    });

    t('notification runtime disables booking emails when email_notifications is off', $sentWhenDisabled === false && count($capturedEmails) === 0, json_encode($capturedEmails, JSON_UNESCAPED_SLASHES));
    t('notification runtime disables email-specific booking success copy when email notifications are off', !str_contains((string)($disabledPayload['message'] ?? ''), 'confirmation email')
        && !str_contains((string)($disabledPayload['html'] ?? ''), 'confirmation email'), (string)($disabledPayload['html'] ?? ''));

    runtimeUpsertGuidanceSetting($db, 'appointment_settings', json_encode([
        'notification_channel' => 'email_and_sms',
        'email_notifications' => '1',
        'reminder_hours_before' => 6,
    ], JSON_UNESCAPED_SLASHES), 'json');
    runtimeUpsertGuidanceSetting($db, 'notification_settings', json_encode([
        'email_enabled' => true,
        'sms_enabled' => true,
        'appointment_reminder_hours' => 6,
    ], JSON_UNESCAPED_SLASHES), 'json');

    $capturedEmails = [];
    $sentWhenEnabled = moduleWithContext('guidance', static function (): bool {
        return guidanceSendAppointmentTemplateEmail('booking_received', 'student-enabled@example.test', [
            'student_name' => 'Enabled Student',
            'date' => 'April 20, 2026',
            'time' => '9:00 AM',
            'location' => 'Guidance Office',
            'reason' => '',
            'appointment_id' => '1002',
        ]);
    });
    moduleWithContext('guidance', static function (): void {
        guidanceEmitAutomationEvent('guidance.booking.created', [
            'appointment_id' => 1002,
            'student_name' => 'Enabled Student',
            'student_email' => 'student-enabled@example.test',
            'student_phone' => '09171234567',
            'trigger_ref_id' => '1002',
        ]);
    });

    $lines = is_file($captureFile) ? file($captureFile, FILE_IGNORE_NEW_LINES) : [];
    $capturedPayload = null;
    foreach ($lines as $line) {
        $decoded = json_decode((string)$line, true);
        $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
        if (($payload['trigger_event'] ?? '') === 'guidance.booking.created') {
            $capturedPayload = $payload;
            break;
        }
    }

    t('notification runtime sends appointment template email when email notifications are enabled', $sentWhenEnabled === true && count($capturedEmails) === 1, json_encode($capturedEmails, JSON_UNESCAPED_SLASHES));
    t('notification runtime emits business events with notification channel context attached', is_array($capturedPayload)
        && (string)($capturedPayload['notification_channel'] ?? '') === 'email_and_sms'
        && (string)($capturedPayload['email_notifications'] ?? '') === '1'
        && (string)($capturedPayload['sms_enabled'] ?? '') === '1', json_encode($capturedPayload, JSON_UNESCAPED_SLASHES));

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
    t('notification runtime checks leave app.log free of errors', unexpectedRuntimeAppLogLines($appLog) === [], implode('; ', unexpectedRuntimeAppLogLines($appLog)));
    t('notification runtime checks leave error.log empty', trim($errorLog) === '', trim($errorLog));
} finally {
    try {
        $db->prepare("DELETE FROM kernel_event_triggers WHERE capability_id = 'test.guidance.runtime.capture@1' AND event_key = 'guidance.booking.created'")
            ->execute();
    } catch (Throwable $e) {
    }

    runtimeRestoreGuidanceSettingsRows($db, $settingKeys, $originalRows);
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

ob_end_flush();
exit($fail > 0 ? 1 : 0);