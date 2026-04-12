<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/admin/guidance/pages/appointments/1';
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
        $appointments = $db->query("SHOW TABLES LIKE 'gm_appointments'");
        $cases = $db->query("SHOW TABLES LIKE 'gm_cases'");
        return (bool)($users && $users->fetchColumn())
            && (bool)($appointments && $appointments->fetchColumn())
            && (bool)($cases && $cases->fetchColumn());
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
    $insert->execute(['TC' . substr($stamp, -4), 'Test College ' . substr($stamp, -4)]);
    return (int)$db->lastInsertId();
}

function buildConfirmedAppointmentCaseInput(array $requiredFields, int $appointmentId, int $counselorId, string $studentEmail, int $collegeId, string $stamp): array
{
    $input = [
        '_token' => app()->csrfToken(),
        'appointment_id' => (string)$appointmentId,
        'counselor_id' => (string)$counselorId,
        'student_id' => 'STU-' . substr($stamp, -6),
        'student_name' => 'Confirmed Student',
        'student_grade' => '3',
        'student_section' => 'A',
        'date_of_birth' => '2005-01-01',
        'gender' => 'Female',
        'nationality' => 'Filipino',
        'civil_status' => 'Single',
        'address' => '123 Test Street',
        'student_mobile' => '09171234567',
        'student_email' => $studentEmail,
        'college_id' => $collegeId > 0 ? (string)$collegeId : '',
        'category' => 'general',
        'severity' => 'medium',
        'presenting_issue' => 'Manual case creation from confirmed appointment',
        'background_info' => 'Background details',
        'is_urgent' => '0',
        'is_confidential' => '0',
        'parent_guardian_name' => 'Guardian Example',
        'parent_guardian_contact' => '09999999999',
        'emergency_contact_address' => '456 Emergency Street',
        'referral_source' => 'walk-in',
        'referred_by' => 'Self',
        'sync_id' => 'sync-' . $stamp,
        'student_status' => 'active',
    ];

    foreach ($requiredFields as $fieldName) {
        if (array_key_exists($fieldName, $input)) {
            continue;
        }
        $input[$fieldName] = 'Fixture value';
    }

    return $input;
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
$counselorEmail = 'guidance-create-case-counselor-' . $stamp . '@example.test';
$studentEmail = 'guidance-create-case-student-' . $stamp . '@example.test';
$counselorId = 0;
$appointmentId = 0;

try {
    $userStmt = $db->prepare(
        'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );
    $userStmt->execute([$counselorEmail, password_hash('unused-password', PASSWORD_BCRYPT), 'Create', 'Counselor', 'counselor']);
    $counselorId = (int)$db->lastInsertId();
    $collegeId = ensureCollegeId($db, $stamp);

    $appointmentStmt = $db->prepare(
        "INSERT INTO gm_appointments (\n"
        . " counselor_id, student_id, student_name, student_email, student_phone, student_college_id, student_year_level,\n"
        . " scheduled_date, scheduled_time, duration_minutes, appointment_type_id, purpose, status,\n"
        . " requested_by_student, request_message, is_urgent, created_by, last_modified_by, created_at, updated_at\n"
        . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 30, NULL, ?, 'confirmed', 1, ?, 0, 0, 0, NOW(), NOW())"
    );
    $appointmentStmt->execute([
        $counselorId,
        'STU-' . substr($stamp, -6),
        'Confirmed Student',
        $studentEmail,
        '09171234567',
        $collegeId,
        '3',
        '2026-04-24',
        '11:00:00',
        'Confirmed appointment purpose',
        'Ready for case creation',
    ]);
    $appointmentId = (int)$db->lastInsertId();

    $token = app()->jwt()->generate([
        'sub' => 'counselor:' . $counselorId,
        'id' => $counselorId,
        'username' => $counselorEmail,
        'name' => 'Create Counselor',
        'role' => 'counselor',
        'source' => 'guidance',
    ]);

    $_COOKIE['guidance_staff_token'] = $token;
    $_SERVER['HTTP_COOKIE'] = 'guidance_staff_token=' . $token;

    $_SERVER['REQUEST_URI'] = '/admin/guidance/pages/appointments/' . $appointmentId;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];

    ob_start();
    moduleWithContext('guidance', static function () use ($appointmentId): void {
        modalGuidanceAppointmentDetail(['id' => $appointmentId]);
    });
    $detailHtml = (string)ob_get_clean();

    $requiredFields = moduleWithContext('guidance', static function (): array {
        return guidanceGetRequiredFormFields('case');
    });
    $input = buildConfirmedAppointmentCaseInput($requiredFields, $appointmentId, $counselorId, $studentEmail, $collegeId, $stamp);

    $appointmentCountStmt = $db->query('SELECT COUNT(*) FROM gm_appointments');
    $beforeAppointmentCount = (int)($appointmentCountStmt ? ($appointmentCountStmt->fetchColumn() ?: 0) : 0);
    $caseCountStmt = $db->prepare('SELECT COUNT(*) FROM gm_cases WHERE student_email = ?');
    $caseCountStmt->execute([$studentEmail]);
    $beforeCaseCount = (int)($caseCountStmt->fetchColumn() ?: 0);

    $_SERVER['REQUEST_URI'] = '/admin/guidance/api/cases';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_POST = $input;
    $_GET = [];
    $_REQUEST = $_POST;
    http_response_code(200);

    ob_start();
    moduleWithContext('guidance', static function (): void {
        apiGuidanceCreateCase();
    });
    $output = (string)ob_get_clean();
    $status = http_response_code();
    $decoded = json_decode($output, true);

    $appointmentStateStmt = $db->prepare('SELECT case_id, status FROM gm_appointments WHERE id = ? LIMIT 1');
    $appointmentStateStmt->execute([$appointmentId]);
    $appointmentState = $appointmentStateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $caseId = (int)($appointmentState['case_id'] ?? 0);

    $caseStmt = $db->prepare('SELECT id, student_name, student_email, counselor_id FROM gm_cases WHERE id = ? LIMIT 1');
    $caseStmt->execute([$caseId]);
    $caseRow = $caseStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $appointmentCountStmt = $db->query('SELECT COUNT(*) FROM gm_appointments');
    $afterAppointmentCount = (int)($appointmentCountStmt ? ($appointmentCountStmt->fetchColumn() ?: 0) : 0);
    $caseCountStmt->execute([$studentEmail]);
    $afterCaseCount = (int)($caseCountStmt->fetchColumn() ?: 0);

    t('confirmed appointment detail shows create case action', str_contains($detailHtml, '/pages/cases/new?appointment_id=' . $appointmentId) && str_contains($detailHtml, 'Create Case'), $detailHtml);
    t('manual create-case flow returns HTTP 200', $status === 200, 'status=' . (string)$status . ' body=' . $output);
    t('manual create-case flow returns success payload', is_array($decoded) && !empty($decoded['success']), $output);
    t('manual create-case flow links the existing appointment to the new case', $caseId > 0 && (string)($appointmentState['status'] ?? '') === 'confirmed', json_encode($appointmentState, JSON_UNESCAPED_SLASHES));
    t('manual create-case flow creates exactly one new case', $afterCaseCount === ($beforeCaseCount + 1), json_encode(['before' => $beforeCaseCount, 'after' => $afterCaseCount], JSON_UNESCAPED_SLASHES));
    t('manual create-case flow does not create an extra appointment', $afterAppointmentCount === $beforeAppointmentCount, json_encode(['before' => $beforeAppointmentCount, 'after' => $afterAppointmentCount], JSON_UNESCAPED_SLASHES));
    t('manual create-case flow persists the case with appointment student data', is_array($caseRow)
        && (string)($caseRow['student_name'] ?? '') === 'Confirmed Student'
        && (string)($caseRow['student_email'] ?? '') === $studentEmail
        && (int)($caseRow['counselor_id'] ?? 0) === $counselorId, json_encode($caseRow, JSON_UNESCAPED_SLASHES));
    t('manual create-case flow reports the linked appointment id in the response', is_array($decoded) && (int)($decoded['data']['appointment_id'] ?? 0) === $appointmentId, $output);

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

    t('manual create-case checks leave app.log free of errors', unexpectedAppLogLines($appLog) === [], implode('; ', unexpectedAppLogLines($appLog)));
    t('manual create-case checks leave error.log empty', trim($errorLog) === '', trim($errorLog));
} finally {
    unset($_COOKIE['guidance_staff_token'], $_SERVER['HTTP_COOKIE'], $_POST, $_GET, $_REQUEST);

    try {
        $caseIdsStmt = $db->prepare('SELECT id FROM gm_cases WHERE student_email = ?');
        $caseIdsStmt->execute([$studentEmail]);
        $caseIds = array_values(array_map('intval', $caseIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

        if ($caseIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($caseIds), '?'));

            $historyDelete = $db->prepare('DELETE FROM gm_case_status_history WHERE case_id IN (' . $placeholders . ')');
            $historyDelete->execute($caseIds);

            $auditDelete = $db->prepare("DELETE FROM gm_audit_logs WHERE table_name = 'gm_cases' AND record_id IN (" . $placeholders . ')');
            $auditDelete->execute($caseIds);
        }

        if ($appointmentId > 0) {
            $appointmentAuditDelete = $db->prepare("DELETE FROM gm_audit_logs WHERE table_name = 'gm_appointments' AND record_id = ?");
            $appointmentAuditDelete->execute([$appointmentId]);

            $appointmentDelete = $db->prepare('DELETE FROM gm_appointments WHERE id = ?');
            $appointmentDelete->execute([$appointmentId]);
        }

        if ($caseIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($caseIds), '?'));
            $caseDelete = $db->prepare('DELETE FROM gm_cases WHERE id IN (' . $placeholders . ')');
            $caseDelete->execute($caseIds);
        }

        if ($counselorId > 0) {
            $userDelete = $db->prepare('DELETE FROM gm_users WHERE id = ?');
            $userDelete->execute([$counselorId]);
        }
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, 'Cleanup failed: ' . $cleanupError->getMessage() . "\n");
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