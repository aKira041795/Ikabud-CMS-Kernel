<?php

declare(strict_types=1);

ob_start();

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/admin/guidance/reminders';
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
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function rt(string $label, bool $ok, string $detail = ''): void
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

function reminderUnexpectedAppLogLines(string $content): array
{
    return array_values(array_filter(explode("\n", $content), static function (string $line): bool {
        if (trim($line) === '') {
            return false;
        }

        return str_contains($line, '[error]') || str_contains($line, '[critical]');
    }));
}

function reminderHasGuidanceTenantSchema(PDO $db): bool
{
    try {
        $users = $db->query("SHOW TABLES LIKE 'gm_users'");
        $appointments = $db->query("SHOW TABLES LIKE 'gm_appointments'");
        $settings = $db->query("SHOW TABLES LIKE 'gm_settings'");
        return (bool)($users && $users->fetchColumn())
            && (bool)($appointments && $appointments->fetchColumn())
            && (bool)($settings && $settings->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function reminderResolveGuidanceTenant(): array
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
        if (!$tenantDb instanceof PDO || !reminderHasGuidanceTenantSchema($tenantDb)) {
            continue;
        }

        return [
            'tenant_id' => $tenantId,
            'domain' => trim((string)($row['domain'] ?? '')),
        ];
    }

    return ['tenant_id' => 0, 'domain' => ''];
}

function reminderFetchAllAssoc(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function reminderInsertAssocRow(PDO $db, string $table, array $row): void
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

function reminderRestoreGuidanceSettingsRows(PDO $db, array $keys, array $rows): void
{
    if ($keys !== []) {
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $db->prepare('DELETE FROM gm_settings WHERE setting_key IN (' . $placeholders . ')')->execute($keys);
    }

    foreach ($rows as $row) {
        if (is_array($row)) {
            reminderInsertAssocRow($db, 'gm_settings', $row);
        }
    }
}

function reminderUpsertGuidanceSetting(PDO $db, string $key, string $value, string $type = 'string'): void
{
    $stmt = $db->prepare(
        'INSERT INTO gm_settings (setting_key, setting_value, setting_type, updated_by, updated_at) '
        . 'VALUES (?, ?, ?, NULL, NOW()) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), updated_at = NOW()'
    );
    $stmt->execute([$key, $value, $type]);
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

$tenant = reminderResolveGuidanceTenant();
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
$stamp = (string)time() . bin2hex(random_bytes(3));
$studentEmail = 'guidance-reminder-student-' . $stamp . '@example.test';
$counselorEmail = 'guidance-reminder-counselor-' . $stamp . '@example.test';
$settingKeys = ['appointment_settings', 'notification_settings', 'email_notifications', 'reminder_hours_before'];
$originalRows = reminderFetchAllAssoc(
    $db,
    'SELECT * FROM gm_settings WHERE setting_key IN (' . implode(', ', array_fill(0, count($settingKeys), '?')) . ') ORDER BY setting_key',
    $settingKeys
);
$counselorId = 0;
$dueAppointmentId = 0;
$laterAppointmentId = 0;

try {
    $db->beginTransaction();

    $userStmt = $db->prepare(
        'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );
    $userStmt->execute([$counselorEmail, password_hash('unused-password', PASSWORD_BCRYPT), 'Reminder', 'Counselor', 'counselor']);
    $counselorId = (int)$db->lastInsertId();

    reminderRestoreGuidanceSettingsRows($db, $settingKeys, []);
    reminderUpsertGuidanceSetting($db, 'appointment_settings', json_encode([
        'email_notifications' => '1',
        'reminder_hours_before' => 6,
    ], JSON_UNESCAPED_SLASHES), 'json');
    reminderUpsertGuidanceSetting($db, 'notification_settings', json_encode([
        'email_enabled' => true,
        'sms_enabled' => false,
        'appointment_reminder_hours' => 6,
    ], JSON_UNESCAPED_SLASHES), 'json');

    $appointmentStmt = $db->prepare(
        "INSERT INTO gm_appointments (\n"
        . " counselor_id, student_name, student_email, student_phone, scheduled_date, scheduled_time, duration_minutes, purpose, status, requested_by_student, created_by, last_modified_by, created_at, updated_at\n"
        . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 1, 0, 0, NOW(), NOW())"
    );
    $appointmentStmt->execute([$counselorId, 'Reminder Student', $studentEmail, '09171234567', '2026-04-20', '13:00:00', 30, 'Reminder Test']);
    $dueAppointmentId = (int)$db->lastInsertId();

    $appointmentStmt->execute([$counselorId, 'Later Student', 'later-' . $studentEmail, '09170000000', '2026-04-21', '09:00:00', 30, 'Later Reminder Test']);
    $laterAppointmentId = (int)$db->lastInsertId();

    $now = new DateTimeImmutable('2026-04-20 09:00:00');
    $due = moduleWithContext('guidance', static function () use ($now): array {
        return guidanceAppointmentsDueForReminder(guidanceDb(), $now, 10);
    });

    rt('reminder runtime returns appointments within the configured reminder window', count($due) === 1 && (int)($due[0]['id'] ?? 0) === $dueAppointmentId, json_encode($due, JSON_UNESCAPED_SLASHES));

    global $capturedEmails;
    $capturedEmails = [];
    $result = moduleWithContext('guidance', static function () use ($now): array {
        return guidanceProcessAppointmentReminders(guidanceDb(), $now, 10);
    });

    $reminderStateStmt = $db->prepare('SELECT reminder_sent_at FROM gm_appointments WHERE id = ? LIMIT 1');
    $reminderStateStmt->execute([$dueAppointmentId]);
    $reminderSentAt = (string)($reminderStateStmt->fetchColumn() ?: '');

    rt('reminder runtime sends one reminder email for due appointments', ($result['due'] ?? 0) === 1 && ($result['sent'] ?? 0) === 1 && count($capturedEmails) === 1, json_encode(['result' => $result, 'emails' => $capturedEmails], JSON_UNESCAPED_SLASHES));
    rt('reminder runtime uses the student email recipient and reminder subject', (string)($capturedEmails[0]['to'] ?? '') === $studentEmail
        && str_contains((string)($capturedEmails[0]['subject'] ?? ''), 'Appointment Reminder'), json_encode($capturedEmails[0] ?? [], JSON_UNESCAPED_SLASHES));
    rt('reminder runtime marks reminder_sent_at after delivery', $reminderSentAt !== '', 'reminder_sent_at=' . $reminderSentAt);

    reminderUpsertGuidanceSetting($db, 'appointment_settings', json_encode([
        'email_notifications' => '0',
        'reminder_hours_before' => 6,
    ], JSON_UNESCAPED_SLASHES), 'json');
    reminderUpsertGuidanceSetting($db, 'notification_settings', json_encode([
        'email_enabled' => false,
        'sms_enabled' => false,
        'appointment_reminder_hours' => 6,
    ], JSON_UNESCAPED_SLASHES), 'json');
    $db->prepare('UPDATE gm_appointments SET reminder_sent_at = NULL WHERE id = ?')->execute([$laterAppointmentId]);

    $capturedEmails = [];
    $dueWhenEmailDisabled = moduleWithContext('guidance', static function () use ($now): array {
        return guidanceAppointmentsDueForReminder(guidanceDb(), $now, 10);
    });

    rt('reminder runtime skips due reminders when email notifications are disabled', $dueWhenEmailDisabled === [], json_encode($dueWhenEmailDisabled, JSON_UNESCAPED_SLASHES));

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
    rt('reminder runtime checks leave app.log free of errors', reminderUnexpectedAppLogLines($appLog) === [], implode('; ', reminderUnexpectedAppLogLines($appLog)));
    rt('reminder runtime checks leave error.log empty', trim($errorLog) === '', trim($errorLog));
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    reminderRestoreGuidanceSettingsRows($db, $settingKeys, $originalRows);
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