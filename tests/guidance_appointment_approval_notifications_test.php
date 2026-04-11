<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/admin/guidance/api/appointments/1/approve';
$_SERVER['REQUEST_METHOD'] = 'POST';
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
        $appointments = $db->query("SHOW TABLES LIKE 'gm_appointments'");
        $settings = $db->query("SHOW TABLES LIKE 'gm_settings'");
        return (bool)($users && $users->fetchColumn())
            && (bool)($appointments && $appointments->fetchColumn())
            && (bool)($settings && $settings->fetchColumn());
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
$tenantDomain = trim((string)($tenant['domain'] ?? ''));

if ($tenantId <= 0) {
    fwrite(STDERR, "No active Guidance tenant database with the required schema is available.\n");
    exit(1);
}

$originalTenantId = app()->tenant()->current();
app()->tenant()->setTenantId($tenantId);
app()->reconnectDb();
invalidateModuleContextCache('guidance');

if ($tenantDomain !== '') {
    $_SERVER['HTTP_HOST'] = $tenantDomain;
}

$db = app()->db();
$stamp = (string)time() . bin2hex(random_bytes(3));
$counselorEmail = 'guidance-approval-counselor-' . $stamp . '@example.test';
$studentEmail = 'guidance-approval-student-' . $stamp . '@example.test';
$counselorId = 0;
$appointmentId = 0;

try {
    $db->beginTransaction();

    $userStmt = $db->prepare(
        'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );
    $userStmt->execute([$counselorEmail, password_hash('unused-password', PASSWORD_BCRYPT), 'Approve', 'Counselor', 'counselor']);
    $counselorId = (int)$db->lastInsertId();

    moduleWithContext('guidance', static function () use ($counselorId): void {
        guidancePersistEmailTemplates([
            'email_tpl_booking_received_subject' => 'Request received for {student_name}',
            'email_tpl_booking_received_body' => "Received for {date} at {time}.",
            'email_tpl_booking_confirmed_subject' => 'Approved appointment for {student_name}',
            'email_tpl_booking_confirmed_body' => "Approved on {date} at {time}.\nLocation: {location}.\nReference: {appointment_id}",
            'email_tpl_booking_rejected_subject' => 'Rejected appointment for {student_name}',
            'email_tpl_booking_rejected_body' => "Could not approve {date} at {time}.\n{reason}",
        ], $counselorId);
    });

    $appointmentStmt = $db->prepare(
        "INSERT INTO gm_appointments (\n"
        . " counselor_id, student_id, student_name, student_email, student_phone, student_college_id, student_year_level,\n"
        . " scheduled_date, scheduled_time, duration_minutes, appointment_type_id, purpose, status,\n"
        . " requested_by_student, request_message, is_urgent, created_by, last_modified_by, created_at, updated_at\n"
        . ") VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?, 0, 0, 0, NOW(), NOW())"
    );
    $appointmentStmt->execute([
        $counselorId,
        'Approval Student',
        $studentEmail,
        '09171234567',
        0,
        '3',
        '2026-04-20',
        '09:30:00',
        30,
        null,
        'Need guidance',
        'Initial approval request',
    ]);
    $appointmentId = (int)$db->lastInsertId();

    $beforeCaseCountStmt = $db->prepare('SELECT COUNT(*) FROM gm_cases WHERE student_email = ?');
    $beforeCaseCountStmt->execute([$studentEmail]);
    $beforeCaseCount = (int)($beforeCaseCountStmt->fetchColumn() ?: 0);

    $token = app()->jwt()->generate([
        'sub' => 'counselor:' . $counselorId,
        'id' => $counselorId,
        'username' => $counselorEmail,
        'name' => 'Approve Counselor',
        'role' => 'counselor',
        'source' => 'guidance',
    ]);

    $_COOKIE['guidance_staff_token'] = $token;
    $_SERVER['HTTP_COOKIE'] = 'guidance_staff_token=' . $token;
    $_SERVER['REQUEST_URI'] = '/admin/guidance/api/appointments/' . $appointmentId . '/approve';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_POST = [];
    $_GET = [];
    $_REQUEST = [];
    http_response_code(200);

    ob_start();
    moduleWithContext('guidance', static function () use ($appointmentId): void {
        apiGuidanceApproveAppointment(['id' => $appointmentId]);
    });
    $output = (string)ob_get_clean();
    $status = http_response_code();
    $decoded = json_decode($output, true);

    $appointmentStateStmt = $db->prepare('SELECT status, case_id, approved_by FROM gm_appointments WHERE id = ? LIMIT 1');
    $appointmentStateStmt->execute([$appointmentId]);
    $appointmentState = $appointmentStateStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $afterCaseCountStmt = $db->prepare('SELECT COUNT(*) FROM gm_cases WHERE student_email = ?');
    $afterCaseCountStmt->execute([$studentEmail]);
    $afterCaseCount = (int)($afterCaseCountStmt->fetchColumn() ?: 0);

    global $capturedEmails;
    $email = $capturedEmails[0] ?? null;

    t('counselor approval returns HTTP 200', $status === 200, 'status=' . (string)$status . ' body=' . $output);
    t('counselor approval returns success payload', is_array($decoded) && !empty($decoded['ok']), $output);
    t('counselor approval confirms the appointment', (string)($appointmentState['status'] ?? '') === 'confirmed', json_encode($appointmentState, JSON_UNESCAPED_SLASHES));
    t('counselor approval records the approving counselor', (int)($appointmentState['approved_by'] ?? 0) === $counselorId, json_encode($appointmentState, JSON_UNESCAPED_SLASHES));
    t('counselor approval does not auto-create a case', $beforeCaseCount === $afterCaseCount && (int)($appointmentState['case_id'] ?? 0) === 0, json_encode(['before' => $beforeCaseCount, 'after' => $afterCaseCount, 'appointment' => $appointmentState], JSON_UNESCAPED_SLASHES));
    t('counselor approval sends one client email', is_array($email) && count($capturedEmails) === 1, json_encode($capturedEmails, JSON_UNESCAPED_SLASHES));
    t('counselor approval email targets the client address', is_array($email) && (string)($email['to'] ?? '') === $studentEmail, json_encode($email, JSON_UNESCAPED_SLASHES));
    t('counselor approval email uses the configured confirmation subject', is_array($email) && (string)($email['subject'] ?? '') === 'Approved appointment for Approval Student', json_encode($email, JSON_UNESCAPED_SLASHES));
    t('counselor approval email body interpolates the configured template', is_array($email)
        && str_contains((string)($email['body'] ?? ''), 'Approved on April 20, 2026 at 9:30 AM.')
        && str_contains((string)($email['body'] ?? ''), 'Location: Guidance Office.')
        && str_contains((string)($email['body'] ?? ''), 'Reference: ' . (string)$appointmentId)
        && !str_contains((string)($email['body'] ?? ''), '{student_name}'), json_encode($email, JSON_UNESCAPED_SLASHES));

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

    t('approval notification checks leave app.log free of errors', unexpectedAppLogLines($appLog) === [], implode('; ', unexpectedAppLogLines($appLog)));
    t('approval notification checks leave error.log empty', trim($errorLog) === '', trim($errorLog));
} finally {
    unset($_COOKIE['guidance_staff_token'], $_SERVER['HTTP_COOKIE'], $_POST, $_GET, $_REQUEST);

    if ($db->inTransaction()) {
        $db->rollBack();
    }

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