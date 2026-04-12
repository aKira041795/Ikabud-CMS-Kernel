<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/guidance/book/api/booking';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';

function buildEmailTemplate(string $headline, string $content): string
{
    return '<h1>' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</h1>' . $content;
}

function sendEmail(string $to, string $subject, string $body, array $options = []): bool
{
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

        if (str_contains($line, 'trigger.execution') && str_contains($line, 'Capability not found: sms.send@1')) {
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
        $types = $db->query("SHOW TABLES LIKE 'gm_appointment_types'");
        $assignments = $db->query("SHOW TABLES LIKE 'gm_counselor_assignments'");
        $availability = $db->query("SHOW TABLES LIKE 'gm_counselor_availability'");
        return (bool)($users && $users->fetchColumn())
            && (bool)($appointments && $appointments->fetchColumn())
            && (bool)($types && $types->fetchColumn())
            && (bool)($assignments && $assignments->fetchColumn())
            && (bool)($availability && $availability->fetchColumn());
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

function ensureCollegeId(PDO $db, string $stamp): int
{
    $stmt = $db->query("SELECT id FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1");
    $collegeId = (int)($stmt ? ($stmt->fetchColumn() ?: 0) : 0);
    if ($collegeId > 0) {
        return $collegeId;
    }

    $insert = $db->prepare(
        'INSERT INTO gm_colleges (code, name, is_active, sort_order, created_at, updated_at) VALUES (?, ?, 1, 0, NOW(), NOW())'
    );
    $insert->execute(['PB' . substr($stamp, -4), 'Public Booking College ' . substr($stamp, -4)]);
    return (int)$db->lastInsertId();
}

function ensureAppointmentBookingSnapshotColumn(PDO $db): void
{
    $stmt = $db->query("SHOW COLUMNS FROM gm_appointments LIKE 'booking_snapshot_json'");
    if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
        return;
    }

    $db->exec("ALTER TABLE gm_appointments ADD COLUMN booking_snapshot_json LONGTEXT DEFAULT NULL AFTER request_message");
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
$counselorEmail = 'guidance-public-booking-counselor-' . $stamp . '@example.test';
$studentEmail = 'guidance-public-booking-student-' . $stamp . '@example.test';
$counselorId = 0;
$appointmentTypeId = 0;
$appointmentId = 0;

try {
    ensureAppointmentBookingSnapshotColumn($db);
    $db->beginTransaction();

    $collegeId = ensureCollegeId($db, $stamp);

    $userStmt = $db->prepare(
        'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );
    $userStmt->execute([$counselorEmail, password_hash('unused-password', PASSWORD_BCRYPT), 'Public', 'Counselor', 'counselor']);
    $counselorId = (int)$db->lastInsertId();

    $assignmentStmt = $db->prepare(
        'INSERT INTO gm_counselor_assignments (counselor_id, college_id, is_primary, is_active, assigned_at) VALUES (?, ?, 1, 1, NOW())'
    );
    $assignmentStmt->execute([$counselorId, $collegeId]);

    $availabilityStmt = $db->prepare(
        'INSERT INTO gm_counselor_availability (counselor_id, day_of_week, slot_index, is_available, start_time, end_time, created_at, updated_at) VALUES (?, ?, 1, 1, ?, ?, NOW(), NOW())'
    );
    $availabilityStmt->execute([$counselorId, 'monday', '09:00:00', '12:00:00']);

    $typeStmt = $db->prepare(
        'INSERT INTO gm_appointment_types (code, name, duration_minutes, requires_case, is_public, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, 0, 1, 1, 0, NOW(), NOW())'
    );
    $typeStmt->execute(['public-booking-' . substr($stamp, -6), 'Public Booking Test', 30]);
    $appointmentTypeId = (int)$db->lastInsertId();

    $payload = moduleWithContext('guidance', static function () use ($appointmentTypeId, $collegeId, $studentEmail, $counselorId): array {
        return guidanceResolvePublicBookingPayload([
            'student_name' => 'Public Booking Student',
            'student_email' => $studentEmail,
            'student_phone' => '09175550123',
            'student_id_number' => 'PB-2026-0001',
            'college_id' => (string)$collegeId,
            'year_level' => '4',
            'student_section' => 'Section C',
            'date_of_birth' => '2004-06-15',
            'gender' => 'female',
            'nationality' => 'Filipino',
            'civil_status' => 'single',
            'address' => '789 Public Booking Road',
            'scheduled_date' => '2026-04-27',
            'scheduled_time' => '09:00',
            'appointment_type_id' => (string)$appointmentTypeId,
            'purpose' => 'Public booking purpose',
            'message' => 'Public booking background',
            'is_urgent' => '1',
            'counselor_id' => (string)$counselorId,
        ]);
    });

    $appointmentId = moduleWithContext('guidance', static function () use ($payload): int {
        return guidanceCreatePublicBookingRecord($payload);
    });

    $appointmentStmt = $db->prepare('SELECT student_id, student_name, student_email, student_phone, student_college_id, student_year_level, purpose, request_message, is_urgent, booking_snapshot_json FROM gm_appointments WHERE id = ? LIMIT 1');
    $appointmentStmt->execute([$appointmentId]);
    $appointment = $appointmentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $snapshot = json_decode((string)($appointment['booking_snapshot_json'] ?? ''), true);

    t('public booking payload maps student_id_number into student_id', (string)($payload['student_id'] ?? '') === 'PB-2026-0001', json_encode($payload, JSON_UNESCAPED_SLASHES));
    t('public booking insert stores the appointment id', $appointmentId > 0, 'appointment_id=' . (string)$appointmentId);
    t('public booking insert persists canonical appointment fields', is_array($appointment)
        && (string)($appointment['student_id'] ?? '') === 'PB-2026-0001'
        && (string)($appointment['student_name'] ?? '') === 'Public Booking Student'
        && (string)($appointment['student_email'] ?? '') === $studentEmail
        && (string)($appointment['student_phone'] ?? '') === '09175550123'
        && (int)($appointment['student_college_id'] ?? 0) === $collegeId
        && (string)($appointment['student_year_level'] ?? '') === '4'
        && (string)($appointment['purpose'] ?? '') === 'Public booking purpose'
        && (string)($appointment['request_message'] ?? '') === 'Public booking background'
        && (int)($appointment['is_urgent'] ?? 0) === 1, json_encode($appointment, JSON_UNESCAPED_SLASHES));
    t('public booking insert persists the booking snapshot json', is_array($snapshot)
        && (string)($snapshot['student_id'] ?? '') === 'PB-2026-0001'
        && (string)($snapshot['student_section'] ?? '') === 'Section C'
        && (string)($snapshot['date_of_birth'] ?? '') === '2004-06-15'
        && (string)($snapshot['gender'] ?? '') === 'female'
        && (string)($snapshot['nationality'] ?? '') === 'Filipino'
        && (string)($snapshot['civil_status'] ?? '') === 'single'
        && (string)($snapshot['address'] ?? '') === '789 Public Booking Road'
        && (string)($snapshot['background_info'] ?? '') === 'Public booking background'
        && (int)($snapshot['is_urgent'] ?? 0) === 1, json_encode($snapshot, JSON_UNESCAPED_SLASHES));

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

    t('public booking snapshot checks leave app.log free of errors', unexpectedAppLogLines($appLog) === [], implode('; ', unexpectedAppLogLines($appLog)));
    t('public booking snapshot checks leave error.log empty', trim($errorLog) === '', trim($errorLog));
} finally {
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