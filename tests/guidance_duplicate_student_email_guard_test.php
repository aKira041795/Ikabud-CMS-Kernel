<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/admin/guidance/api/cases';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];
$resultLines = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors, $resultLines;

    if ($ok) {
        $pass++;
        $resultLines[] = "  PASS {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    $resultLines[] = "  FAIL {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
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

function ensureAppointmentBookingSnapshotColumn(PDO $db): void
{
    $stmt = $db->query("SHOW COLUMNS FROM gm_appointments LIKE 'booking_snapshot_json'");
    if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
        return;
    }

    $db->exec("ALTER TABLE gm_appointments ADD COLUMN booking_snapshot_json LONGTEXT DEFAULT NULL AFTER request_message");
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

function buildCaseInput(int $counselorId, int $collegeId, string $studentEmail, string $stamp, string $studentName, string $syncSuffix): array
{
    return [
        '_token' => app()->csrfToken(),
        'counselor_id' => (string)$counselorId,
        'student_id' => 'STU-' . substr($stamp . $syncSuffix, -6),
        'student_first_name' => strtok($studentName, ' ') ?: $studentName,
        'student_last_name' => 'Fixture',
        'student_name' => $studentName,
        'student_grade' => '3',
        'student_status' => 'active',
        'student_section' => 'A',
        'date_of_birth' => '2005-01-01',
        'gender' => 'Female',
        'nationality' => 'Filipino',
        'civil_status' => 'single',
        'address' => '123 Test Street',
        'student_mobile' => '09171234567',
        'student_email' => $studentEmail,
        'college_id' => (string)$collegeId,
        'category' => 'general',
        'severity' => 'medium',
        'presenting_issue' => 'Duplicate email regression check',
        'background_info' => 'Background details',
        'is_urgent' => '0',
        'is_confidential' => '0',
        'parent_guardian_name' => 'Guardian Example',
        'parent_guardian_contact' => '09999999999',
        'emergency_contact_address' => '456 Emergency Street',
        'referral_source' => 'walk-in',
        'referred_by' => 'Self',
        'sync_id' => 'sync-' . $stamp . '-' . $syncSuffix,
    ];
}

function issueGuidanceCounselorToken(int $counselorId, string $email, string $name): string
{
    return app()->jwt()->generate([
        'sub' => 'counselor:' . $counselorId,
        'id' => $counselorId,
        'username' => $email,
        'name' => $name,
        'role' => 'counselor',
        'source' => 'guidance',
    ]);
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
$counselorEmail = 'guidance-duplicate-counselor-' . $stamp . '@example.test';
$studentEmail = 'guidance-duplicate-student-' . $stamp . '@example.test';
$counselorId = 0;
$appointmentId = 0;

try {
    ensureAppointmentBookingSnapshotColumn($db);
    $collegeId = ensureCollegeId($db, $stamp);

    $userStmt = $db->prepare(
        'INSERT INTO gm_users (email, password, first_name, last_name, role, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );
    $userStmt->execute([$counselorEmail, password_hash('unused-password', PASSWORD_BCRYPT), 'Duplicate', 'Counselor', 'counselor']);
    $counselorId = (int)$db->lastInsertId();

    $token = issueGuidanceCounselorToken($counselorId, $counselorEmail, 'Duplicate Counselor');
    $_COOKIE['guidance_staff_token'] = $token;
    $_SERVER['HTTP_COOKIE'] = 'guidance_staff_token=' . $token;

    $existingInput = buildCaseInput($counselorId, $collegeId, $studentEmail, $stamp, 'Existing Student', 'existing');
    $_SERVER['REQUEST_URI'] = '/admin/guidance/api/cases';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_POST = $existingInput;
    $_GET = [];
    $_REQUEST = $_POST;
    http_response_code(200);

    ob_start();
    moduleWithContext('guidance', static function (): void {
        apiGuidanceCreateCase();
    });
    $firstOutput = (string)ob_get_clean();
    $firstStatus = http_response_code();
    $firstDecoded = json_decode($firstOutput, true);

    $caseCountStmt = $db->prepare('SELECT COUNT(*) FROM gm_cases WHERE LOWER(TRIM(student_email)) = LOWER(TRIM(?)) AND deleted_at IS NULL');
    $caseCountStmt->execute([$studentEmail]);
    $afterFirstCreateCount = (int)($caseCountStmt->fetchColumn() ?: 0);

    t('first create-case request succeeds', $firstStatus === 200 && is_array($firstDecoded) && !empty($firstDecoded['success']), 'status=' . (string)$firstStatus . ' body=' . $firstOutput);
    t('first create-case request creates one active case for the email', $afterFirstCreateCount === 1, 'count=' . (string)$afterFirstCreateCount);

    $duplicateInput = buildCaseInput($counselorId, $collegeId, $studentEmail, $stamp, 'Duplicate Student', 'duplicate');
    $_POST = $duplicateInput;
    $_REQUEST = $_POST;
    http_response_code(200);

    ob_start();
    moduleWithContext('guidance', static function (): void {
        apiGuidanceCreateCase();
    });
    $duplicateOutput = (string)ob_get_clean();
    $duplicateStatus = http_response_code();
    $duplicateDecoded = json_decode($duplicateOutput, true);

    $caseCountStmt->execute([$studentEmail]);
    $afterDuplicateCreateCount = (int)($caseCountStmt->fetchColumn() ?: 0);

    t('duplicate create-case request returns HTTP 409', $duplicateStatus === 409, 'status=' . (string)$duplicateStatus . ' body=' . $duplicateOutput);
    t('duplicate create-case request returns a conflict error payload', is_array($duplicateDecoded)
        && (string)($duplicateDecoded['error'] ?? '') !== ''
        && str_contains((string)($duplicateDecoded['error'] ?? ''), 'Email already used by'), $duplicateOutput);
    t('duplicate create-case request does not insert a second active case', $afterDuplicateCreateCount === 1, 'count=' . (string)$afterDuplicateCreateCount);

    $bookingSnapshotJson = json_encode([
        'student_id' => 'APPROVAL-' . substr($stamp, -6),
        'student_mobile' => '09171234567',
        'college_id' => $collegeId,
        'student_grade' => '3',
        'student_section' => 'Section B',
        'date_of_birth' => '2005-04-20',
        'gender' => 'Female',
        'nationality' => 'Filipino',
        'civil_status' => 'single',
        'address' => '123 Approval Street',
        'presenting_issue' => 'Need guidance',
        'background_info' => 'Initial approval request',
        'is_urgent' => 1,
        'parent_guardian_name' => 'Approval Guardian',
        'parent_guardian_contact' => '09990001111',
        'emergency_contact_address' => '456 Approval Avenue',
    ], JSON_UNESCAPED_SLASHES);

    $appointmentStmt = $db->prepare(
        "INSERT INTO gm_appointments (\n"
        . " counselor_id, student_id, student_name, student_email, student_phone, student_college_id, student_year_level,\n"
        . " scheduled_date, scheduled_time, duration_minutes, appointment_type_id, purpose, status,\n"
        . " requested_by_student, request_message, booking_snapshot_json, is_urgent, created_by, last_modified_by, created_at, updated_at\n"
        . ") VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?, 0, 0, 0, NOW(), NOW())"
    );
    $appointmentStmt->execute([
        $counselorId,
        'Approval Duplicate Student',
        $studentEmail,
        '09171234567',
        $collegeId,
        '3',
        '2026-04-20',
        '09:30:00',
        30,
        null,
        'Need guidance',
        'Initial approval request',
        $bookingSnapshotJson,
    ]);
    $appointmentId = (int)$db->lastInsertId();

    $beforeApproveCaseCount = $afterDuplicateCreateCount;
    $_SERVER['REQUEST_URI'] = '/admin/guidance/api/appointments/' . $appointmentId . '/approve';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_POST = ['_token' => app()->csrfToken()];
    $_GET = [];
    $_REQUEST = $_POST;
    http_response_code(200);

    ob_start();
    moduleWithContext('guidance', static function () use ($appointmentId): void {
        apiGuidanceApproveAppointment(['id' => $appointmentId]);
    });
    $approveOutput = (string)ob_get_clean();
    $approveStatus = http_response_code();
    $approveDecoded = json_decode($approveOutput, true);

    $appointmentStateStmt = $db->prepare('SELECT status, case_id, approved_by FROM gm_appointments WHERE id = ? LIMIT 1');
    $appointmentStateStmt->execute([$appointmentId]);
    $appointmentState = $appointmentStateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $caseCountStmt->execute([$studentEmail]);
    $afterApproveCaseCount = (int)($caseCountStmt->fetchColumn() ?: 0);

    t('duplicate appointment approval returns HTTP 409', $approveStatus === 409, 'status=' . (string)$approveStatus . ' body=' . $approveOutput);
    t('duplicate appointment approval returns a duplicate email error payload', is_array($approveDecoded)
        && (string)($approveDecoded['error'] ?? '') !== ''
        && str_contains((string)($approveDecoded['error'] ?? ''), 'Email already used by'), $approveOutput);
    t('duplicate appointment approval does not create another case', $afterApproveCaseCount === $beforeApproveCaseCount, json_encode(['before' => $beforeApproveCaseCount, 'after' => $afterApproveCaseCount], JSON_UNESCAPED_SLASHES));
    t('duplicate appointment approval leaves the appointment unconfirmed', (string)($appointmentState['status'] ?? '') === 'pending'
        && (int)($appointmentState['case_id'] ?? 0) === 0
        && (int)($appointmentState['approved_by'] ?? 0) === 0, json_encode($appointmentState, JSON_UNESCAPED_SLASHES));

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

    t('duplicate email checks leave app.log free of errors', unexpectedAppLogLines($appLog) === [], implode('; ', unexpectedAppLogLines($appLog)));
    t('duplicate email checks leave error.log empty', trim($errorLog) === '', trim($errorLog));
} finally {
    unset($_COOKIE['guidance_staff_token'], $_SERVER['HTTP_COOKIE'], $_POST, $_GET, $_REQUEST);

    try {
        if ($appointmentId > 0) {
            $appointmentAuditDelete = $db->prepare("DELETE FROM gm_audit_logs WHERE table_name = 'gm_appointments' AND record_id = ?");
            $appointmentAuditDelete->execute([$appointmentId]);

            $appointmentDelete = $db->prepare('DELETE FROM gm_appointments WHERE id = ?');
            $appointmentDelete->execute([$appointmentId]);
        }

        $caseIdsStmt = $db->prepare('SELECT id FROM gm_cases WHERE LOWER(TRIM(student_email)) = LOWER(TRIM(?))');
        $caseIdsStmt->execute([$studentEmail]);
        $caseIds = array_values(array_map('intval', $caseIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

        if ($caseIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($caseIds), '?'));

            $historyDelete = $db->prepare('DELETE FROM gm_case_status_history WHERE case_id IN (' . $placeholders . ')');
            $historyDelete->execute($caseIds);

            $auditDelete = $db->prepare("DELETE FROM gm_audit_logs WHERE table_name = 'gm_cases' AND record_id IN (" . $placeholders . ')');
            $auditDelete->execute($caseIds);

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

foreach ($resultLines as $line) {
    echo $line;
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