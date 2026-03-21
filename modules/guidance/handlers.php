<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function guidanceGetSettingJson(string $key, array $default = []): array
{
    try {
        $stmt = guidanceDb()->prepare("SELECT setting_value FROM gm_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $raw = $stmt->fetchColumn();
        $parsed = json_decode((string)($raw ?: ''), true);
        return is_array($parsed) ? $parsed : $default;
    } catch (Throwable $e) {
        return $default;
    }

}

function apiGuidanceCaseOptions(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);

    $db = guidanceDb();
    try {
        $where = ["c.deleted_at IS NULL"];
        $params = [];
        if ($isCounselor) {
            $where[] = "c.counselor_id = ?";
            $params[] = $userId;
        }
        $q = "SELECT c.id, c.case_number, c.student_name FROM gm_cases c WHERE " . implode(' AND ', $where) . " ORDER BY c.created_at DESC LIMIT 200";
        $stmt = $db->prepare($q);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/html; charset=utf-8');
        echo '<option value="">Select case...</option>';
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $id = (int)($r['id'] ?? 0);
            $label = trim((string)($r['case_number'] ?? '') . ' — ' . (string)($r['student_name'] ?? ''));
            if ($id < 1) continue;
            echo '<option value="' . $id . '">' . htmlspecialchars($label) . '</option>';
        }
    } catch (Throwable $e) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<option value="">(failed to load cases)</option>';
    }
}

function guidanceGenerateCaseNumber(PDO $db): string
{
    $prefix = 'GC-' . date('Ymd') . '-';
    $stmt = $db->prepare("SELECT case_number FROM gm_cases WHERE case_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)($stmt->fetchColumn() ?: '');

    $next = 1;
    if ($last !== '' && preg_match('/-(\d+)$/', $last, $m) === 1) {
        $next = ((int)$m[1]) + 1;
    }

    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function apiGuidanceCreateCase(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);

    $input = guidanceInput();
    if (!is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }

    $studentName = trim((string)($input['student_name'] ?? ''));
    $studentId = trim((string)($input['student_id'] ?? ''));
    $clientNumber = trim((string)($input['client_number'] ?? ''));
    $category = (string)($input['category'] ?? 'general');
    $severity = (string)($input['severity'] ?? 'medium');
    $presentingIssue = trim((string)($input['presenting_issue'] ?? ''));
    $presentingIssue = guidanceEditorSanitizeHtml(guidanceEditorNormalizeHtml($presentingIssue, 'guidance.session'), 'guidance.session');
    $counselorId = $isCounselor ? $userId : (int)($input['counselor_id'] ?? 0);

    if ($studentName === '' || $studentId === '' || $clientNumber === '' || $presentingIssue === '' || $counselorId < 1) {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Student name, student ID, client number, counselor, and presenting issue are required', 'type' => 'error']]));
        echo '';
        return;
    }

    $allowedCategories = ['general', 'academic', 'behavioral', 'emotional', 'family', 'peer', 'career', 'crisis', 'special_needs', 'substance', 'other'];
    if (!in_array($category, $allowedCategories, true)) {
        $category = 'general';
    }

    $allowedSeverity = ['low', 'medium', 'high', 'critical'];
    if (!in_array($severity, $allowedSeverity, true)) {
        $severity = 'medium';
    }

    $db = guidanceDb();

    try {
        $checkCounselor = $db->prepare("SELECT id FROM gm_users WHERE id = ? AND role = 'counselor' AND deleted_at IS NULL AND is_active = 1 LIMIT 1");
        $checkCounselor->execute([$counselorId]);
        if ((int)$checkCounselor->fetchColumn() < 1) {
            http_response_code(422);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Selected counselor is invalid', 'type' => 'error']]));
            echo '';
            return;
        }

        $attempts = 0;
        do {
            $attempts++;
            $caseNumber = guidanceGenerateCaseNumber($db);
            try {
                $stmt = $db->prepare(
                    "INSERT INTO gm_cases (case_number, student_id, student_name, student_mobile, counselor_id, category, severity, presenting_issue, created_by, last_modified_by, created_at, updated_at)\n"
                    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
                );
                $stmt->execute([
                    $caseNumber,
                    $studentId,
                    $studentName,
                    $clientNumber,
                    $counselorId,
                    $category,
                    $severity,
                    $presentingIssue,
                    $userId,
                    $userId,
                ]);
                header('HX-Trigger: ' . json_encode([
                    'showToast' => ['message' => 'Case created successfully', 'type' => 'success'],
                    'refreshCases' => true,
                ]));
                echo '';
                return;
            } catch (PDOException $e) {
                if ($attempts >= 3 || stripos($e->getMessage(), 'Duplicate') === false) {
                    throw $e;
                }
            }
        } while ($attempts < 3);

        throw new RuntimeException('Failed to generate unique case number');
    } catch (Throwable $e) {
        http_response_code(500);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create case', 'type' => 'error']]));
        echo '';
    }
}

function modalGuidanceAppointmentNew(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $counselors = [];
    $tinyMceAssets = guidanceTinyMceAssets('guidance.session', 'default');
    $tinyMceConfig = guidanceTinyMceConfig('guidance.session', 'default', false);
    if ($role !== 'counselor') {
        try {
            $stmt = guidanceDb()->prepare("SELECT id, first_name, last_name FROM gm_users WHERE role = 'counselor' AND deleted_at IS NULL AND is_active = 1 ORDER BY first_name, last_name");
            $stmt->execute();
            $counselors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $counselors = [];
        }
    }

    echo guidanceRender('modules/guidance/modals/appointment-form.disyl', [
        'appointment' => [],
        'today' => date('Y-m-d'),
        'case_id' => '',
        'counselors' => $counselors,
        'user_role' => $role,
        'tinymce_assets' => $tinyMceAssets,
        'tinymce_config' => $tinyMceConfig,
    ]);
}

function modalGuidanceAppointmentEdit(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);
    $tinyMceAssets = guidanceTinyMceAssets('guidance.session', 'default');
    $tinyMceConfig = guidanceTinyMceConfig('guidance.session', 'default', false);
    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Appointment not found</div>';
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare("SELECT * FROM gm_appointments WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $appt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($appt)) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Appointment not found</div>';
        return;
    }
    if ($isCounselor && (int)($appt['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo '<div class="p-4 text-red-600">Access denied</div>';
        return;
    }

    $counselors = [];
    if (!$isCounselor) {
        try {
            $cStmt = $db->prepare("SELECT id, first_name, last_name FROM gm_users WHERE role = 'counselor' AND deleted_at IS NULL AND is_active = 1 ORDER BY first_name, last_name");
            $cStmt->execute();
            $counselors = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $counselors = [];
        }
    }

    echo guidanceRender('modules/guidance/modals/appointment-form.disyl', [
        'appointment' => $appt,
        'today' => date('Y-m-d'),
        'case_id' => (string)($appt['case_id'] ?? ''),
        'counselors' => $counselors,
        'user_role' => $role,
        'tinymce_assets' => $tinyMceAssets,
        'tinymce_config' => $tinyMceConfig,
    ]);
}

function modalGuidanceAppointmentDetail(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);
    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Appointment not found</div>';
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare(
        "SELECT a.*, c.case_number, c.student_name AS case_student_name, col.name AS college_name, "
        . "CONCAT(u.first_name,' ',u.last_name) AS counselor_name, at.name AS appointment_type "
        . "FROM gm_appointments a "
        . "LEFT JOIN gm_cases c ON a.case_id = c.id "
        . "LEFT JOIN gm_colleges col ON a.student_college_id = col.id "
        . "LEFT JOIN gm_users u ON a.counselor_id = u.id "
        . "LEFT JOIN gm_appointment_types at ON a.appointment_type_id = at.id "
        . "WHERE a.id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $appt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($appt)) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Appointment not found</div>';
        return;
    }
    if ($isCounselor && (int)($appt['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo '<div class="p-4 text-red-600">Access denied</div>';
        return;
    }

    if (empty($appt['student_name']) && !empty($appt['case_student_name'])) {
        $appt['student_name'] = $appt['case_student_name'];
    }

    echo guidanceRender('modules/guidance/modals/appointment-detail.disyl', [
        'appointment' => $appt,
        'case_notes' => [],
        'base_url' => '/admin/guidance/pages',
    ]);
}

function guidanceAppointmentConflict(PDO $db, int $counselorId, string $date, string $time, int $durationMinutes, int $excludeId = 0): bool
{
    $endTime = date('H:i:s', strtotime($time . ' +' . max(1, $durationMinutes) . ' minutes'));
    $startTime = date('H:i:s', strtotime($time));

    $sql = "SELECT COUNT(*) FROM gm_appointments\n"
        . "WHERE counselor_id = ? AND scheduled_date = ?\n"
        . "AND status IN ('scheduled','confirmed','pending')\n"
        . "AND (\n"
        . "    (scheduled_time < ? AND ADDTIME(scheduled_time, SEC_TO_TIME(duration_minutes*60)) > ?)\n"
        . ")";
    $params = [$counselorId, $date, $endTime, $startTime];
    if ($excludeId > 0) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return ((int)$stmt->fetchColumn()) > 0;
}

function apiGuidanceCreateAppointment(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);

    $input = guidanceInput();
    if (!is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }
    $caseId = (int)($input['case_id'] ?? 0);
    $counselorId = $isCounselor ? $userId : (int)($input['counselor_id'] ?? 0);
    $date = (string)($input['scheduled_date'] ?? '');
    $time = (string)($input['scheduled_time'] ?? '');
    $duration = (int)($input['duration_minutes'] ?? 60);
    $purpose = (string)($input['purpose'] ?? 'counseling');
    $notes = (string)($input['notes'] ?? '');
    $notes = guidanceEditorSanitizeHtml(guidanceEditorNormalizeHtml($notes, 'guidance.session'), 'guidance.session');
    $location = (string)($input['location'] ?? '');
    $status = (string)($input['status'] ?? 'scheduled');
    if (!in_array($status, ['pending', 'scheduled', 'confirmed'], true)) {
        $status = 'scheduled';
    }

    if ($caseId < 1 || $counselorId < 1 || $date === '' || $time === '') {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case, counselor, date, and time are required', 'type' => 'error']]));
        echo '';
        return;
    }

    $db = guidanceDb();
    if (guidanceAppointmentConflict($db, $counselorId, $date, $time, $duration)) {
        http_response_code(409);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Time slot is not available', 'type' => 'error']]));
        echo '';
        return;
    }

    try {
        $caseStmt = $db->prepare("SELECT student_name, student_email, student_mobile, college_id, student_grade FROM gm_cases WHERE id = ? LIMIT 1");
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($case)) {
            http_response_code(422);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid case', 'type' => 'error']]));
            echo '';
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO gm_appointments (case_id, counselor_id, student_name, student_email, student_phone, student_college_id, student_year_level,\n"
            . "scheduled_date, scheduled_time, duration_minutes, purpose, notes, location, status, created_by, last_modified_by, created_at, updated_at)\n"
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $caseId,
            $counselorId,
            (string)($case['student_name'] ?? ''),
            (string)($case['student_email'] ?? ''),
            (string)($case['student_mobile'] ?? ''),
            (int)($case['college_id'] ?? 0),
            (string)($case['student_grade'] ?? ''),
            $date,
            $time,
            $duration,
            $purpose,
            $notes,
            $location,
            $status,
            $userId,
            $userId,
        ]);

        $appointmentId = (int)$db->lastInsertId();
        $clientNumber = trim((string)($case['student_mobile'] ?? ''));
        guidanceFireEvent('guidance.appointment.created', [
            'to' => $clientNumber,
            'appointment_id' => $appointmentId,
            'date' => $date,
            'time' => $time,
            'student_name' => (string)($case['student_name'] ?? ''),
            'recipient_name' => (string)($case['student_name'] ?? ''),
            'trigger_ref_id' => (string)$appointmentId,
        ]);

        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment created', 'type' => 'success'], 'refreshAppointments' => true, 'refreshAppointmentsCalendar' => true]));
        echo '';
    } catch (Throwable $e) {
        http_response_code(500);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create appointment', 'type' => 'error']]));
        echo '';
    }
}

function apiGuidanceUpdateAppointment(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);
    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment not found', 'type' => 'error']]));
        echo '';
        return;
    }

    $input = guidanceInput();
    if (!is_array($input)) {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid request', 'type' => 'error']]));
        echo '';
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare("SELECT id, counselor_id FROM gm_appointments WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($existing)) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment not found', 'type' => 'error']]));
        echo '';
        return;
    }
    if ($isCounselor && (int)($existing['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Access denied', 'type' => 'error']]));
        echo '';
        return;
    }

    $caseId = (int)($input['case_id'] ?? 0);
    $counselorId = $isCounselor ? $userId : (int)($input['counselor_id'] ?? ($existing['counselor_id'] ?? 0));
    $date = (string)($input['scheduled_date'] ?? '');
    $time = (string)($input['scheduled_time'] ?? '');
    $duration = (int)($input['duration_minutes'] ?? 60);
    $purpose = (string)($input['purpose'] ?? 'counseling');
    $notes = (string)($input['notes'] ?? '');
    $notes = guidanceEditorSanitizeHtml(guidanceEditorNormalizeHtml($notes, 'guidance.session'), 'guidance.session');
    $location = (string)($input['location'] ?? '');
    $status = trim((string)($input['status'] ?? ''));
    if ($status !== '' && !in_array($status, ['pending', 'requested', 'confirmed', 'scheduled', 'rescheduled', 'completed', 'cancelled', 'no_show', 'rejected', 'waitlist'], true)) {
        $status = '';
    }

    if ($caseId < 1 || $counselorId < 1 || $date === '' || $time === '') {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case, counselor, date, and time are required', 'type' => 'error']]));
        echo '';
        return;
    }

    if (guidanceAppointmentConflict($db, $counselorId, $date, $time, $duration, $id)) {
        http_response_code(409);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Time slot is not available', 'type' => 'error']]));
        echo '';
        return;
    }

    try {
        $caseStmt = $db->prepare("SELECT student_name, student_email, student_mobile, college_id, student_grade FROM gm_cases WHERE id = ? LIMIT 1");
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($case)) {
            http_response_code(422);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid case', 'type' => 'error']]));
            echo '';
            return;
        }

        $sql = "UPDATE gm_appointments SET case_id = ?, counselor_id = ?, student_name = ?, student_email = ?, student_phone = ?, student_college_id = ?, student_year_level = ?,\n"
            . "scheduled_date = ?, scheduled_time = ?, duration_minutes = ?, purpose = ?, notes = ?, location = ?";
        $vals = [
            $caseId,
            $counselorId,
            (string)($case['student_name'] ?? ''),
            (string)($case['student_email'] ?? ''),
            (string)($case['student_mobile'] ?? ''),
            (int)($case['college_id'] ?? 0),
            (string)($case['student_grade'] ?? ''),
            $date,
            $time,
            $duration,
            $purpose,
            $notes,
            $location,
        ];
        if ($status !== '') {
            $sql .= ", status = ?";
            $vals[] = $status;
        }
        $sql .= ", last_modified_by = ?, updated_at = NOW() WHERE id = ?";
        $vals[] = $userId;
        $vals[] = $id;
        $uStmt = $db->prepare($sql);
        $uStmt->execute($vals);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment updated', 'type' => 'success'], 'refreshAppointments' => true, 'refreshAppointmentsCalendar' => true]));
        echo '';
    } catch (Throwable $e) {
        http_response_code(500);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to update appointment', 'type' => 'error']]));
        echo '';
    }
}

function apiGuidanceAppointmentsCalendar(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);
    $input = guidanceInput();

    $month = (string)($input['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));

    $db = guidanceDb();
    $where = ["a.scheduled_date BETWEEN ? AND ?"]; 
    $params = [$start, $end];
    if ($isCounselor) {
        $where[] = 'a.counselor_id = ?';
        $params[] = $userId;
    } elseif (!empty($input['counselor_id'])) {
        $where[] = 'a.counselor_id = ?';
        $params[] = (int)$input['counselor_id'];
    }

    $sql = "SELECT a.id, a.scheduled_date, a.scheduled_time, a.status, a.student_name\n"
        . "FROM gm_appointments a\n"
        . "WHERE " . implode(' AND ', $where) . "\n"
        . "ORDER BY a.scheduled_date, a.scheduled_time";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'month' => $month, 'appointments' => $rows]);
}

function guidanceSetAppointmentStatus(PDO $db, int $id, string $newStatus, int $byUserId, array $allowedStatuses = []): bool
{
    if (!empty($allowedStatuses)) {
        $stmt = $db->prepare("SELECT status FROM gm_appointments WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $cur = (string)($stmt->fetchColumn() ?: '');
        if (!in_array($cur, $allowedStatuses, true)) {
            return false;
        }
    }
    $stmt = $db->prepare("UPDATE gm_appointments SET status = ?, last_modified_by = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newStatus, $byUserId, $id]);
    return true;
}

function apiGuidanceCompleteAppointment(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);
    $id = (int)($params['id'] ?? 0);

    $db = guidanceDb();
    if ($isCounselor) {
        $chk = $db->prepare("SELECT counselor_id FROM gm_appointments WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() !== $userId) {
            http_response_code(403);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Access denied', 'type' => 'error']]));
            echo '';
            return;
        }
    }

    $ok = guidanceSetAppointmentStatus($db, $id, 'completed', $userId, ['scheduled', 'confirmed']);
    if (!$ok) {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid status transition', 'type' => 'error']]));
        echo '';
        return;
    }
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment completed', 'type' => 'success'], 'refreshAppointments' => true, 'refreshAppointmentsCalendar' => true]));
    echo '';
}

function apiGuidanceNoShowAppointment(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);
    $id = (int)($params['id'] ?? 0);

    $db = guidanceDb();
    if ($isCounselor) {
        $chk = $db->prepare("SELECT counselor_id FROM gm_appointments WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() !== $userId) {
            http_response_code(403);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Access denied', 'type' => 'error']]));
            echo '';
            return;
        }
    }

    $ok = guidanceSetAppointmentStatus($db, $id, 'no_show', $userId, ['scheduled', 'confirmed']);
    if (!$ok) {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid status transition', 'type' => 'error']]));
        echo '';
        return;
    }
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Marked as no show', 'type' => 'success'], 'refreshAppointments' => true, 'refreshAppointmentsCalendar' => true]));
    echo '';
}

function apiGuidanceCancelAppointment(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);
    $id = (int)($params['id'] ?? 0);

    $db = guidanceDb();
    if ($isCounselor) {
        $chk = $db->prepare("SELECT counselor_id FROM gm_appointments WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() !== $userId) {
            http_response_code(403);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Access denied', 'type' => 'error']]));
            echo '';
            return;
        }
    }

    $ok = guidanceSetAppointmentStatus($db, $id, 'cancelled', $userId, ['pending', 'scheduled', 'confirmed']);
    if (!$ok) {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid status transition', 'type' => 'error']]));
        echo '';
        return;
    }
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment cancelled', 'type' => 'success'], 'refreshAppointments' => true, 'refreshAppointmentsCalendar' => true]));
    echo '';
}

function guidanceCookieName(): string
{
    return 'guidance_staff_token';
}

function guidanceSetAuthCookie(string $token, int $expiresInSeconds = 86400): void
{
    $expiry = time() + $expiresInSeconds;
    setcookie(guidanceCookieName(), $token, [
        'expires' => $expiry,
        'path' => '/',
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Strict',
    ]);
}

function guidanceClearAuthCookie(): void
{
    setcookie(guidanceCookieName(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Strict',
    ]);
}

function guidanceUserFromCookie(): ?array
{
    $token = kernelCookie(guidanceCookieName());
    if (!is_string($token) || $token === '') {
        return null;
    }
    try {
        $payload = app()->jwt()->verify($token);
        if (!is_array($payload)) {
            return null;
        }
        if (($payload['source'] ?? '') !== 'guidance') {
            return null;
        }
        $id = (int)($payload['id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $stmt = guidanceDb()->prepare("SELECT id, email, first_name, last_name, role, is_active FROM gm_users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || empty($row['is_active'])) {
            return null;
        }
        $fullName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        return [
            'id' => (int)($row['id'] ?? 0),
            'username' => (string)($row['email'] ?? ''),
            'full_name' => $fullName !== '' ? $fullName : (string)($row['email'] ?? ''),
            'role' => (string)($row['role'] ?? 'counselor'),
            'source' => 'guidance',
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function guidanceRequireStaff(array $roles = ['admin', 'supervisor', 'counselor']): array
{
    $u = guidanceUserFromCookie();
    if (!$u) {
        guidanceRedirect('/guidance/login');
    }
    $role = (string)($u['role'] ?? '');
    if (!in_array($role, $roles, true)) {
        if (guidanceIsHtmx()) {
            http_response_code(403);
            echo '<div class="p-4 text-red-600">Access denied</div>';
            exit;
        }
        guidanceRedirect('/guidance/login');
    }
    return $u;
}

function pageGuidanceLogin(): void
{
    if (guidanceUserFromCookie()) {
        guidanceRedirect('/admin/guidance');
    }
    echo guidanceRender('modules/guidance/pages/login.disyl', [
        'page_title' => 'Guidance Sign In',
    ]);
}

function guidanceAuthLogin(): void
{
    header('Content-Type: application/json; charset=utf-8');
    $input = guidanceInput();
    $email = trim((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if ($email === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Email and password are required.']);
        return;
    }

    // Use capability pipeline, but enforce provider prefix to keep module auth isolated.
    $authRow = null;
    try {
        $authResult = app()->cap()->call('kernel.auth.authenticate@1', [
            'username' => '@guidance:' . $email,
            'password' => $password,
        ], ['mode' => 'pipeline']);
        if (is_array($authResult) && isset($authResult['user']) && is_array($authResult['user']) && (($authResult['source'] ?? '') === 'guidance')) {
            $authRow = $authResult['user'];
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Authentication failed.']);
        return;
    }

    if (!is_array($authRow)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid email or password.']);
        return;
    }

    $role = (string)($authRow['role'] ?? '');
    $idInt = (int)($authRow['id'] ?? 0);
    if ($idInt < 1) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid email or password.']);
        return;
    }

    $payload = [
        'sub' => $role . ':' . $idInt,
        'id' => $idInt,
        'username' => (string)($authRow['username'] ?? $email),
        'name' => (string)($authRow['full_name'] ?? $email),
        'role' => $role,
        'source' => 'guidance',
    ];
    $token = app()->jwt()->generate($payload);
    guidanceSetAuthCookie($token, (int)config('app.jwt.expiration', 86400));

    echo json_encode(['ok' => true, 'redirect' => '/admin/guidance']);
}

function guidanceLogout(): void
{
    guidanceClearAuthCookie();
    guidanceRedirect('/guidance/login');
}

function pageGuidancePublicBooking(): void
{
    $db = guidanceDb();

    $colleges = [];
    try {
        $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $colleges = [];
    }

    $types = [];
    try {
        $types = $db->query("SELECT id, code, name, duration_minutes, color FROM gm_appointment_types WHERE is_active = 1 AND is_public = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $types = [];
    }

    $settings = guidanceGetSettingJson('appointment_settings', []);
    $schoolInfo = guidanceGetSettingJson('school_info', []);

    $maxBookingDays = (int)($settings['max_booking_days_ahead'] ?? 14);
    if ($maxBookingDays < 1) {
        $maxBookingDays = 14;
    }

    echo guidanceRender('modules/guidance/pages/public-booking.disyl', [
        'colleges' => $colleges,
        'appointment_types' => $types,
        'settings' => $settings,
        'school_info' => $schoolInfo,
        'max_booking_days' => $maxBookingDays,
        'min_date' => date('Y-m-d'),
        'max_date' => date('Y-m-d', strtotime('+' . $maxBookingDays . ' days')),
        'booking_fields_html' => guidanceBookingBuildFieldsHtml($schoolInfo),
        'two_fa_booking' => '0',
        'base_url' => '/guidance',
    ]);
}

function apiGuidanceBookingSlots(): void
{
    $input = guidanceInput();
    $date = (string)($input['date'] ?? '');
    $collegeId = (int)($input['college_id'] ?? 0);
    $typeId = (int)($input['type_id'] ?? 0);

    if ($date === '' || $collegeId < 1) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Date and college are required']);
        return;
    }

    $db = guidanceDb();

    try {
        $apptSettings = guidanceGetSettingJson('appointment_settings', []);
        $maxBookingDays = (int)($apptSettings['max_booking_days_ahead'] ?? 14);
        if ($maxBookingDays < 1) {
            $maxBookingDays = 14;
        }

        $today = new DateTime(date('Y-m-d'));
        $selectedDate = new DateTime($date);
        $maxDate = (clone $today)->modify("+{$maxBookingDays} days");

        if ($selectedDate < $today || $selectedDate > $maxDate) {
            if (guidanceIsHtmx()) {
                echo '<div class="text-red-600 p-4 bg-red-50 rounded-lg"><i class="fas fa-calendar-times mr-2"></i>Date must be within the next ' . (int)$maxBookingDays . ' days</div>';
                return;
            }
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => "Date must be within the next {$maxBookingDays} days"]);
            return;
        }

        $blockedStmt = $db->prepare(
            "SELECT id, reason FROM gm_blocked_dates\n"
            . "WHERE blocked_date = ?\n"
            . "AND (counselor_id IS NULL OR counselor_id IN (\n"
            . "  SELECT ca.counselor_id FROM gm_counselor_assignments ca\n"
            . "  JOIN gm_users u ON ca.counselor_id = u.id\n"
            . "  WHERE ca.college_id = ? AND ca.is_active = 1 AND u.role != 'admin'\n"
            . "))\n"
            . "AND start_time IS NULL\n"
            . "LIMIT 1"
        );
        $blockedStmt->execute([$date, $collegeId]);
        $blocked = $blockedStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($blocked)) {
            $reason = htmlspecialchars((string)($blocked['reason'] ?? 'Unavailable'));
            if (guidanceIsHtmx()) {
                echo '<div class="text-red-600 p-4 bg-red-50 rounded-lg"><i class="fas fa-calendar-times mr-2"></i>This date is unavailable: ' . $reason . '</div>';
                return;
            }
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Date is blocked', 'reason' => (string)($blocked['reason'] ?? '')]);
            return;
        }

        $workingHours = guidanceGetSettingJson('working_hours', []);
        $dayOfWeek = strtolower($selectedDate->format('l'));
        if (!isset($workingHours[$dayOfWeek]) || $workingHours[$dayOfWeek] === null) {
            if (guidanceIsHtmx()) {
                echo '<div class="text-amber-600 p-4 bg-amber-50 rounded-lg"><i class="fas fa-info-circle mr-2"></i>The office is closed on this day.</div>';
                return;
            }
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Office is closed on this day']);
            return;
        }

        $startTime = (string)($workingHours[$dayOfWeek]['start'] ?? '08:00');
        $endTime = (string)($workingHours[$dayOfWeek]['end'] ?? '17:00');

        $slotDuration = (int)($apptSettings['default_duration_minutes'] ?? 30);
        if ($typeId > 0) {
            $typeStmt = $db->prepare('SELECT duration_minutes FROM gm_appointment_types WHERE id = ? LIMIT 1');
            $typeStmt->execute([$typeId]);
            $slotDuration = (int)($typeStmt->fetchColumn() ?: $slotDuration);
        }
        if ($slotDuration < 10) {
            $slotDuration = 30;
        }

        $bufferMinutes = (int)($apptSettings['buffer_minutes'] ?? 5);
        if ($bufferMinutes < 0) {
            $bufferMinutes = 0;
        }

        $counselorStmt = $db->prepare(
            "SELECT ca.counselor_id\n"
            . "FROM gm_counselor_assignments ca\n"
            . "JOIN gm_users u ON ca.counselor_id = u.id\n"
            . "WHERE ca.college_id = ? AND ca.is_active = 1 AND u.role != 'admin'"
        );
        $counselorStmt->execute([$collegeId]);
        $counselorIds = $counselorStmt->fetchAll(PDO::FETCH_COLUMN);
        $counselorIds = array_values(array_filter(array_map('intval', $counselorIds)));

        if (empty($counselorIds)) {
            if (guidanceIsHtmx()) {
                echo '<div class="text-amber-600 p-4 bg-amber-50 rounded-lg"><i class="fas fa-user-slash mr-2"></i>No counselor is assigned to this college yet. Please contact the guidance office.</div>';
                return;
            }
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'No counselor assigned to this college']);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($counselorIds), '?'));
        $existingStmt = $db->prepare(
            "SELECT scheduled_time, duration_minutes, counselor_id\n"
            . "FROM gm_appointments\n"
            . "WHERE counselor_id IN ({$placeholders})\n"
            . "AND scheduled_date = ?\n"
            . "AND status NOT IN ('cancelled', 'rejected')"
        );
        $existingStmt->execute(array_merge($counselorIds, [$date]));
        $existingAppointments = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

        $bookedSlots = [];
        foreach ($existingAppointments as $appt) {
            $cid = (int)($appt['counselor_id'] ?? 0);
            $start = strtotime((string)($appt['scheduled_time'] ?? '00:00'));
            $end = $start + (((int)($appt['duration_minutes'] ?? 30)) * 60);
            $bookedSlots[$cid][] = ['start' => $start, 'end' => $end];
        }

        $blockedTimesStmt = $db->prepare(
            "SELECT start_time, end_time, counselor_id FROM gm_blocked_dates\n"
            . "WHERE blocked_date = ?\n"
            . "AND start_time IS NOT NULL\n"
            . "AND (counselor_id IS NULL OR counselor_id IN ({$placeholders}))"
        );
        $blockedTimesStmt->execute(array_merge([$date], $counselorIds));
        $blockedTimes = $blockedTimesStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($blockedTimes as $blocked) {
            $cid = $blocked['counselor_id'] === null ? 'all' : (int)$blocked['counselor_id'];
            $start = strtotime((string)($blocked['start_time'] ?? '00:00'));
            $end = strtotime((string)($blocked['end_time'] ?? '00:00'));
            if ($cid === 'all') {
                foreach ($counselorIds as $c) {
                    $bookedSlots[(int)$c][] = ['start' => $start, 'end' => $end];
                }
            } else {
                $bookedSlots[(int)$cid][] = ['start' => $start, 'end' => $end];
            }
        }

        $slots = [];
        $currentTime = strtotime($startTime);
        $endTimeTs = strtotime($endTime);
        $slotSeconds = $slotDuration * 60;
        $bufferSeconds = $bufferMinutes * 60;

        while ($currentTime + $slotSeconds <= $endTimeTs) {
            $slotEnd = $currentTime + $slotSeconds;

            $availableCounselor = null;
            foreach ($counselorIds as $cid) {
                $cid = (int)$cid;
                $isAvailable = true;
                foreach (($bookedSlots[$cid] ?? []) as $booked) {
                    if ($currentTime < ($booked['end'] + $bufferSeconds) && $slotEnd > ($booked['start'] - $bufferSeconds)) {
                        $isAvailable = false;
                        break;
                    }
                }
                if ($isAvailable) {
                    $availableCounselor = $cid;
                    break;
                }
            }

            if ($availableCounselor !== null) {
                $slots[] = [
                    'time' => date('H:i', $currentTime),
                    'display' => date('g:i A', $currentTime),
                    'counselor_id' => $availableCounselor,
                ];
            }

            $currentTime += $slotSeconds;
        }

        if (guidanceIsHtmx()) {
            if (empty($slots)) {
                echo '<div class="text-amber-600 p-4 bg-amber-50 rounded-lg"><i class="fas fa-clock mr-2"></i>No available slots for this date. Please try another date.</div>';
                return;
            }
            echo '<div class="grid grid-cols-4 sm:grid-cols-6 gap-2">';
            foreach ($slots as $slot) {
                echo '<button type="button" class="slot-btn px-3 py-2 text-sm border border-gray-300 rounded-lg hover:bg-indigo-50 hover:border-indigo-500 focus:ring-2 focus:ring-indigo-500 transition-colors" data-time="' . htmlspecialchars((string)$slot['time']) . '" data-counselor="' . (int)$slot['counselor_id'] . '">' . htmlspecialchars((string)$slot['display']) . '</button>';
            }
            echo '</div>';
            echo '<input type="hidden" name="scheduled_time" id="selected-time" value="">';
            echo '<input type="hidden" name="counselor_id" id="selected-counselor" value="">';
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => $slots]);
    } catch (Throwable $e) {
        if (guidanceIsHtmx()) {
            http_response_code(500);
            echo '<div class="text-red-600 p-4 bg-red-50 rounded-lg"><i class="fas fa-triangle-exclamation mr-2"></i>Failed to load available slots</div>';
            return;
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to load available slots']);
    }
}

function apiGuidancePublicBooking(): void
{
    $input = guidanceInput();
    if (!is_array($input)) {
        $input = [];
    }

    $studentName = trim((string)($input['student_name'] ?? ''));
    $studentEmail = trim((string)($input['student_email'] ?? ''));
    $collegeId = (int)($input['college_id'] ?? 0);
    $yearLevel = trim((string)($input['year_level'] ?? ''));
    $studentPhone = trim((string)($input['student_phone'] ?? ''));

    $scheduledDate = trim((string)($input['scheduled_date'] ?? ''));
    $scheduledTime = trim((string)($input['scheduled_time'] ?? ''));
    $typeId = (int)($input['appointment_type_id'] ?? 0);
    $purpose = trim((string)($input['purpose'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));
    $isUrgent = !empty($input['is_urgent']) ? 1 : 0;

    if ($studentName === '' || $studentEmail === '' || $collegeId < 1 || $scheduledDate === '' || $scheduledTime === '' || $typeId < 1) {
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Please fill out all required fields', 'type' => 'error']]));
            http_response_code(400);
            echo '';
            return;
        }
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Missing required fields']);
        return;
    }

    if (!filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Please enter a valid email address', 'type' => 'error']]));
            http_response_code(400);
            echo '';
            return;
        }
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid email address']);
        return;
    }

    $db = guidanceDb();
    try {
        $typeStmt = $db->prepare('SELECT duration_minutes FROM gm_appointment_types WHERE id = ? LIMIT 1');
        $typeStmt->execute([$typeId]);
        $duration = (int)($typeStmt->fetchColumn() ?: 30);

        $counselorId = (int)($input['counselor_id'] ?? 0);
        if ($counselorId < 1) {
            $counselorStmt = $db->prepare(
                "SELECT ca.counselor_id\n"
                . "FROM gm_counselor_assignments ca\n"
                . "JOIN gm_users u ON ca.counselor_id = u.id\n"
                . "WHERE ca.college_id = ? AND ca.is_active = 1 AND u.role != 'admin'\n"
                . "ORDER BY ca.is_primary DESC\n"
                . "LIMIT 1"
            );
            $counselorStmt->execute([$collegeId]);
            $counselorId = (int)($counselorStmt->fetchColumn() ?: 0);
        }
        if ($counselorId < 1) {
            throw new RuntimeException('No counselor assigned to this college');
        }

        $checkStmt = $db->prepare(
            "SELECT COUNT(*) FROM gm_appointments\n"
            . "WHERE counselor_id = ?\n"
            . "AND scheduled_date = ?\n"
            . "AND scheduled_time = ?\n"
            . "AND status NOT IN ('cancelled', 'rejected')"
        );
        $checkStmt->execute([$counselorId, $scheduledDate, $scheduledTime]);
        if ((int)$checkStmt->fetchColumn() > 0) {
            if (guidanceIsHtmx()) {
                header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'This time slot is no longer available. Please select another time.', 'type' => 'error']]));
                http_response_code(409);
                echo '';
                return;
            }
            http_response_code(409);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Time slot no longer available']);
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO gm_appointments (\n"
            . " counselor_id, student_id, student_name, student_email, student_phone,\n"
            . " student_college_id, student_year_level, scheduled_date, scheduled_time,\n"
            . " duration_minutes, appointment_type_id, purpose, status,\n"
            . " requested_by_student, request_message, is_urgent, created_by, last_modified_by\n"
            . ") VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?, 0, 0)"
        );
        $stmt->execute([
            $counselorId,
            $studentName,
            $studentEmail,
            ($studentPhone !== '' ? $studentPhone : null),
            $collegeId,
            ($yearLevel !== '' ? $yearLevel : null),
            $scheduledDate,
            $scheduledTime,
            $duration,
            $typeId,
            ($purpose !== '' ? $purpose : null),
            ($message !== '' ? $message : null),
            $isUrgent,
        ]);
        $appointmentId = (int)$db->lastInsertId();

        guidanceFireEvent('guidance.booking.created', [
            'to' => $studentPhone,
            'appointment_id' => $appointmentId,
            'student_name' => $studentName,
            'student_email' => $studentEmail,
            'student_phone' => $studentPhone,
            'recipient_name' => $studentName,
            'trigger_ref_id' => (string)$appointmentId,
        ]);

        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode([
                'showToast' => [
                    'message' => 'Appointment request submitted! You will receive a confirmation email once approved.',
                    'type' => 'success',
                ],
                'bookingSuccess' => true,
            ]));
            echo guidanceRender('modules/guidance/partials/booking-success.disyl', [
                'appointment_id' => $appointmentId,
                'student_name' => $studentName,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => $scheduledTime,
                'student_email' => $studentEmail,
                'base_url' => '/guidance',
            ]);
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        http_response_code(201);
        echo json_encode(['success' => true, 'appointment_id' => $appointmentId]);
    } catch (Throwable $e) {
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create appointment. Please try again.', 'type' => 'error']]));
            http_response_code(500);
            echo '';
            return;
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to create appointment']);
    }
}

function guidanceBookingBuildFieldsHtml(array $schoolInfo = []): string
{
    $colleges = [];
    try {
        $stmt = guidanceDb()->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name");
        $colleges = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $colleges = [];
    }

    $options = '<option value="">Select College</option>';
    foreach ($colleges as $c) {
        $id = (int)($c['id'] ?? 0);
        $label = trim((string)($c['code'] ?? '') . ' - ' . (string)($c['name'] ?? ''));
        $options .= '<option value="' . $id . '">' . htmlspecialchars($label) . '</option>';
    }

    return ''
        . '<div>'
        . '  <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>'
        . '  <input type="text" name="student_name" required class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Your name">'
        . '</div>'
        . '<div>'
        . '  <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>'
        . '  <input type="email" name="student_email" required class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="you@example.com">'
        . '</div>'
        . '<div>'
        . '  <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>'
        . '  <input type="tel" name="student_phone" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Optional">'
        . '</div>'
        . '<div>'
        . '  <label class="block text-sm font-medium text-gray-700 mb-1">College *</label>'
        . '  <select name="college_id" required class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">'
        . $options
        . '  </select>'
        . '</div>'
        . '<div>'
        . '  <label class="block text-sm font-medium text-gray-700 mb-1">Year Level</label>'
        . '  <input type="text" name="year_level" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Optional">'
        . '</div>';
}

function pageGuidanceDashboard(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $role = (string)($user['role'] ?? '');
    $name = (string)($user['full_name'] ?? ($user['name'] ?? ($user['username'] ?? 'User')));
    $initials = '';
    if ($name !== '') {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = strtoupper(substr((string)($parts[0] ?? ''), 0, 1) . substr((string)($parts[1] ?? ''), 0, 1));
    }

    echo guidanceRender('modules/guidance/pages/dashboard.disyl', [
        'page_title' => 'Guidance',
        'base_url' => '/admin/guidance',
        'current_page' => 'dashboard',
        'user_name' => $name,
        'user_role' => $role,
        'user_initials' => $initials,
        'today_date' => date('M d, Y'),
        'hour' => (int)date('G'),
    ]);
}

function guidanceBasePageContext(array $user, string $pageTitle, string $currentPage): array
{
    $role = (string)($user['role'] ?? '');
    $name = (string)($user['full_name'] ?? ($user['name'] ?? ($user['username'] ?? 'User')));
    $initials = '';
    if ($name !== '') {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = strtoupper(substr((string)($parts[0] ?? ''), 0, 1) . substr((string)($parts[1] ?? ''), 0, 1));
    }

    return [
        'page_title' => $pageTitle,
        'base_url' => '/admin/guidance',
        'current_page' => $currentPage,
        'user_name' => $name,
        'user_role' => $role,
        'user_initials' => $initials,
        'today_date' => date('M d, Y'),
        'hour' => (int)date('G'),
    ];
}

function pageGuidanceCases(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $ctxUser = $user;
    $role = (string)($ctxUser['role'] ?? '');
    $counselors = [];
    if ($role !== 'counselor') {
        try {
            $stmt = guidanceDb()->prepare("SELECT id, first_name, last_name FROM gm_users WHERE role = 'counselor' AND deleted_at IS NULL ORDER BY first_name, last_name");
            $stmt->execute();
            $counselors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $counselors = [];
        }
    }

    echo guidanceRender('modules/guidance/pages/cases.disyl', array_merge(
        guidanceBasePageContext($ctxUser, 'Cases', 'cases'),
        ['counselors' => $counselors]
    ));
}

function pageGuidanceCaseView(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = is_array($user) ? (string)($user['role'] ?? '') : '';
    $userId = is_array($user) ? (int)($user['id'] ?? 0) : 0;

    $caseId = (int)($params['id'] ?? 0);
    if ($caseId < 1) {
        http_response_code(404);
        echo 'Case not found';
        return;
    }

    $db = guidanceDb();
    $where = 'id = :id AND deleted_at IS NULL';
    $q = [':id' => $caseId];
    if ($role === 'counselor') {
        $where .= ' AND counselor_id = :cid';
        $q[':cid'] = $userId;
    }

    $stmt = $db->prepare(
        "SELECT id, case_number, student_name, student_id, student_mobile, status, severity, category, presenting_issue, COALESCE(resolution_summary, '') AS notes, updated_at\n"
        . "FROM gm_cases\n"
        . "WHERE {$where}\n"
        . "LIMIT 1"
    );
    $stmt->execute($q);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($case)) {
        http_response_code(404);
        echo 'Case not found';
        return;
    }

    echo guidanceRender('modules/guidance/pages/case-view.disyl', array_merge(
        guidanceBasePageContext(is_array($user) ? $user : [], 'Case', 'cases'),
        ['case' => $case]
    ));
}

function modalGuidanceCaseNew(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $counselors = [];
    $tinyMceAssets = guidanceTinyMceAssets('guidance.session', 'default');
    $tinyMceConfig = guidanceTinyMceConfig('guidance.session', 'default', false);

    try {
        $stmt = guidanceDb()->prepare("SELECT id, first_name, last_name FROM gm_users WHERE role = 'counselor' AND deleted_at IS NULL AND is_active = 1 ORDER BY first_name, last_name");
        $stmt->execute();
        $counselors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $counselors = [];
    }

    echo guidanceRender('modules/guidance/modals/case-form.disyl', [
        'user_role' => $role,
        'user_id' => $userId,
        'counselors' => $counselors,
        'tinymce_assets' => $tinyMceAssets,
        'tinymce_config' => $tinyMceConfig,
    ]);
}

function pageGuidanceAppointments(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $ctxUser = $user;
    $role = (string)($ctxUser['role'] ?? '');
    $counselors = [];
    if ($role !== 'counselor') {
        try {
            $stmt = guidanceDb()->prepare("SELECT id, first_name, last_name FROM gm_users WHERE role = 'counselor' AND deleted_at IS NULL ORDER BY first_name, last_name");
            $stmt->execute();
            $counselors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $counselors = [];
        }
    }

    echo guidanceRender('modules/guidance/pages/appointments.disyl', array_merge(
        guidanceBasePageContext($ctxUser, 'Appointments', 'appointments'),
        ['counselors' => $counselors]
    ));
}

function apiGuidanceAppointments(): void
{
    $ctxUser = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $role = (string)($ctxUser['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($ctxUser['id'] ?? 0);
    $input = guidanceInput();

    $db = guidanceDb();

    try {
        $where = ['1=1'];
        $params = [];

        if ($isCounselor) {
            $where[] = 'a.counselor_id = ?';
            $params[] = $userId;
        } elseif (!empty($input['counselor_id'])) {
            $where[] = 'a.counselor_id = ?';
            $params[] = (int)$input['counselor_id'];
        }

        if (!empty($input['from'])) {
            $where[] = 'a.scheduled_date >= ?';
            $params[] = (string)$input['from'];
        }
        if (!empty($input['to'])) {
            $where[] = 'a.scheduled_date <= ?';
            $params[] = (string)$input['to'];
        }
        if (!empty($input['status'])) {
            $where[] = 'a.status = ?';
            $params[] = (string)$input['status'];
        }
        if (!empty($input['search'])) {
            $where[] = '(COALESCE(a.student_name, c.student_name) LIKE ? OR c.case_number LIKE ?)';
            $search = '%' . (string)$input['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $db->prepare(
            "SELECT a.*, LOWER(TRIM(a.status)) AS status_key,\n"
            . "       c.case_number, COALESCE(a.student_name, c.student_name) AS student_name,\n"
            . "       u.first_name AS counselor_first, u.last_name AS counselor_last\n"
            . "FROM gm_appointments a\n"
            . "LEFT JOIN gm_cases c ON a.case_id = c.id\n"
            . "LEFT JOIN gm_users u ON a.counselor_id = u.id\n"
            . "WHERE {$whereClause}\n"
            . "ORDER BY a.scheduled_date ASC, a.scheduled_time ASC"
        );
        $stmt->execute($params);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        foreach ($appointments as &$appt) {
            $appt['status_key'] = strtolower(trim((string)($appt['status_key'] ?? ($appt['status'] ?? ''))));
            $appt['counselor_name'] = trim((string)($appt['counselor_first'] ?? '') . ' ' . (string)($appt['counselor_last'] ?? ''));

            if (($appt['scheduled_date'] ?? '') === $today) {
                $appt['date_label'] = 'Today';
            } elseif (($appt['scheduled_date'] ?? '') === $tomorrow) {
                $appt['date_label'] = 'Tomorrow';
            } elseif (($appt['scheduled_date'] ?? '') === $yesterday) {
                $appt['date_label'] = 'Yesterday';
            } else {
                $appt['date_label'] = date('l, M j, Y', strtotime((string)($appt['scheduled_date'] ?? $today)));
            }
        }
        unset($appt);

        $rows = [];
        $lastDate = null;
        $dateCounts = array_count_values(array_column($appointments, 'scheduled_date'));
        foreach ($appointments as $appt) {
            $dateKey = (string)($appt['scheduled_date'] ?? '');
            if ($dateKey !== $lastDate) {
                $rows[] = [
                    'row_type' => 'date_header',
                    'date' => $dateKey,
                    'label' => (string)($appt['date_label'] ?? $dateKey),
                    'is_today' => ($dateKey === $today),
                    'is_past' => ($dateKey < $today),
                    'day_count' => $dateCounts[$dateKey] ?? 0,
                ];
                $lastDate = $dateKey;
            }
            $appt['row_type'] = 'appointment';
            $appt['is_past'] = ($dateKey < $today);
            $rows[] = $appt;
        }

        $statWhere = ['1=1'];
        $statParams = [];
        if ($isCounselor) {
            $statWhere[] = 'a.counselor_id = ?';
            $statParams[] = $userId;
        } elseif (!empty($input['counselor_id'])) {
            $statWhere[] = 'a.counselor_id = ?';
            $statParams[] = (int)$input['counselor_id'];
        }
        $statWhereStr = implode(' AND ', $statWhere);

        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekEnd = date('Y-m-d', strtotime('sunday this week'));

        $statsStmt = $db->prepare(
            "SELECT\n"
            . "SUM(CASE WHEN a.scheduled_date = ? AND a.status NOT IN ('cancelled','rejected') THEN 1 ELSE 0 END) AS today_count,\n"
            . "SUM(CASE WHEN a.scheduled_date BETWEEN ? AND ? AND a.status NOT IN ('cancelled','rejected') THEN 1 ELSE 0 END) AS week_count,\n"
            . "SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,\n"
            . "SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,\n"
            . "SUM(CASE WHEN a.status IN ('confirmed','scheduled') AND a.scheduled_date >= ? THEN 1 ELSE 0 END) AS upcoming_count\n"
            . "FROM gm_appointments a WHERE {$statWhereStr}"
        );
        $statsStmt->execute(array_merge([$today, $weekStart, $weekEnd, $today], $statParams));
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        $stats = is_array($stats) ? array_map('intval', $stats) : [];

        header('Content-Type: text/html; charset=utf-8');
        echo guidanceRender('modules/guidance/partials/appointments-list.disyl', [
            'appointments' => $appointments,
            'rows' => $rows,
            'stats' => $stats,
            'total' => count($appointments),
            'base_url' => '/admin/guidance',
        ]);
    } catch (Throwable $e) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(500);
        echo '<div class="p-6 text-sm text-red-600">Failed to fetch appointments</div>';
    }
}

function pageGuidanceReports(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $counselors = [];
    if ($role !== 'counselor') {
        try {
            $stmt = guidanceDb()->prepare("SELECT id, first_name, last_name FROM gm_users WHERE role = 'counselor' AND deleted_at IS NULL AND is_active = 1 ORDER BY first_name, last_name");
            $stmt->execute();
            $counselors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $counselors = [];
        }
    }

    echo guidanceRender('modules/guidance/pages/reports.disyl', array_merge(
        guidanceBasePageContext($user, 'Reports', 'reports'),
        ['counselors' => $counselors]
    ));
}

function guidanceSendDocx(string $downloadName, string $tmpPath): void
{
    if (!is_file($tmpPath)) {
        http_response_code(500);
        echo 'Failed to generate report';
        return;
    }

    $downloadName = trim($downloadName);
    if ($downloadName === '') {
        $downloadName = 'report.docx';
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Content-Length: ' . filesize($tmpPath));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    readfile($tmpPath);
    kernelDeletePath($tmpPath);
    exit;
}

function downloadGuidanceCaseSummaryDocx(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);

    $caseId = (int)($params['id'] ?? 0);
    if ($caseId < 1) {
        http_response_code(404);
        echo 'Case not found';
        return;
    }

    if (!class_exists('PhpOffice\\PhpWord\\PhpWord')) {
        http_response_code(500);
        echo 'DOCX generator not available';
        return;
    }

    $db = guidanceDb();
    $caseStmt = $db->prepare(
        "SELECT c.*, col.name AS college_name, CONCAT(u.first_name,' ',u.last_name) AS counselor_name\n"
        . "FROM gm_cases c\n"
        . "LEFT JOIN gm_colleges col ON c.college_id = col.id\n"
        . "LEFT JOIN gm_users u ON c.counselor_id = u.id\n"
        . "WHERE c.id = ? AND c.deleted_at IS NULL LIMIT 1"
    );
    $caseStmt->execute([$caseId]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        http_response_code(404);
        echo 'Case not found';
        return;
    }

    if ($isCounselor && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo 'Access denied';
        return;
    }

    $notesStmt = $db->prepare(
        "SELECT n.*, CONCAT(u.first_name,' ',u.last_name) AS author_name\n"
        . "FROM gm_counselor_notes n\n"
        . "LEFT JOIN gm_users u ON n.counselor_id = u.id\n"
        . "WHERE n.case_id = ?\n"
        . "ORDER BY n.created_at DESC\n"
        . "LIMIT 10"
    );
    $notesStmt->execute([$caseId]);
    $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

    $school = guidanceGetSettingJson('school_info', ['name' => '']);
    $schoolName = trim((string)($school['name'] ?? ''));

    $phpWord = new PhpWord();
    $phpWord->setDefaultFontName('Calibri');
    $phpWord->setDefaultFontSize(11);
    $section = $phpWord->addSection([
        'marginTop' => 900,
        'marginBottom' => 900,
        'marginLeft' => 900,
        'marginRight' => 900,
    ]);

    $title = 'Case Summary';
    if ($schoolName !== '') {
        $title = $schoolName . ' — ' . $title;
    }
    $section->addText($title, ['bold' => true, 'size' => 16]);
    $section->addText('Generated: ' . date('Y-m-d H:i'));
    $section->addTextBreak(1);

    $section->addText('Case Details', ['bold' => true, 'size' => 13]);
    $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'D1D5DB', 'cellMargin' => 80]);

    $rows = [
        ['Case #', (string)($case['case_number'] ?? '')],
        ['Student Name', (string)($case['student_name'] ?? '')],
        ['Student ID', (string)($case['student_id'] ?? '')],
        ['College', (string)($case['college_name'] ?? '')],
        ['Category', (string)($case['category'] ?? '')],
        ['Severity', (string)($case['severity'] ?? '')],
        ['Status', (string)($case['status'] ?? '')],
        ['Counselor', trim((string)($case['counselor_name'] ?? ''))],
        ['Urgent', !empty($case['is_urgent']) ? 'Yes' : 'No'],
        ['Confidential', !empty($case['is_confidential']) ? 'Yes' : 'No'],
        ['Created', (string)($case['created_at'] ?? '')],
        ['Updated', (string)($case['updated_at'] ?? '')],
    ];
    foreach ($rows as $r) {
        $table->addRow();
        $table->addCell(2400)->addText((string)$r[0], ['bold' => true]);
        $table->addCell(7200)->addText((string)$r[1]);
    }

    $section->addTextBreak(1);
    $section->addText('Presenting Issue', ['bold' => true, 'size' => 13]);
    $section->addText((string)($case['presenting_issue'] ?? ''));
    $section->addTextBreak(1);

    if (!empty($case['background_info'])) {
        $section->addText('Background Information', ['bold' => true, 'size' => 13]);
        $section->addText((string)$case['background_info']);
        $section->addTextBreak(1);
    }

    $section->addText('Recent Counselor Notes', ['bold' => true, 'size' => 13]);
    if (is_array($notes) && count($notes) > 0) {
        foreach ($notes as $n) {
            if (!is_array($n)) continue;
            $meta = trim((string)($n['author_name'] ?? ''));
            $created = (string)($n['created_at'] ?? '');
            $noteType = (string)($n['note_type'] ?? '');
            $line = trim($meta . ($meta !== '' ? ' — ' : '') . $created . ($noteType !== '' ? ' (' . $noteType . ')' : ''));
            $section->addText($line, ['bold' => true]);
            $section->addText((string)($n['note_content'] ?? ''));
            $section->addTextBreak(1);
        }
    } else {
        $section->addText('No notes available.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'gm_docx_');
    $tmpPath = $tmp ? ($tmp . '.docx') : (sys_get_temp_dir() . '/gm_' . uniqid() . '.docx');
    if ($tmp && is_file($tmp)) {
        kernelDeletePath($tmp);
    }

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($tmpPath);

    $safeCase = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($case['case_number'] ?? ('case-' . $caseId)));
    guidanceSendDocx('case-summary-' . $safeCase . '.docx', $tmpPath);
}

function downloadGuidanceAppointmentsDocx(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);
    $input = guidanceInput();

    if (!class_exists('PhpOffice\\PhpWord\\PhpWord')) {
        http_response_code(500);
        echo 'DOCX generator not available';
        return;
    }

    $from = (string)($input['from'] ?? '');
    $to = (string)($input['to'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        http_response_code(422);
        echo 'Invalid date range';
        return;
    }

    $fromTs = strtotime($from . ' 00:00:00');
    $toTs = strtotime($to . ' 23:59:59');
    if (!$fromTs || !$toTs || $toTs < $fromTs) {
        http_response_code(422);
        echo 'Invalid date range';
        return;
    }
    // Prevent unbounded exports (performance + accidental leaks)
    $maxDays = 93; // ~3 months
    $spanDays = (int)floor(($toTs - $fromTs) / 86400);
    if ($spanDays > $maxDays) {
        http_response_code(422);
        echo 'Date range too large';
        return;
    }

    $counselorId = null;
    if ($isCounselor) {
        $counselorId = $userId;
    } else {
        $cid = (int)($input['counselor_id'] ?? 0);
        if ($cid > 0) {
            $counselorId = $cid;
        }
    }

    $db = guidanceDb();
    $where = ["a.scheduled_date BETWEEN ? AND ?"]; 
    $params = [$from, $to];
    if ($counselorId !== null) {
        $where[] = "a.counselor_id = ?";
        $params[] = $counselorId;
    }

    $sql = "SELECT a.id, a.scheduled_date, a.scheduled_time, a.duration_minutes, a.status, a.student_name, a.purpose, a.location, c.case_number, CONCAT(u.first_name,' ',u.last_name) AS counselor_name\n"
        . "FROM gm_appointments a\n"
        . "LEFT JOIN gm_cases c ON a.case_id = c.id\n"
        . "LEFT JOIN gm_users u ON a.counselor_id = u.id\n"
        . "WHERE " . implode(' AND ', $where) . "\n"
        . "ORDER BY a.scheduled_date, a.scheduled_time";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $school = guidanceGetSettingJson('school_info', ['name' => '']);
    $schoolName = trim((string)($school['name'] ?? ''));

    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Calibri');
    $phpWord->setDefaultFontSize(11);
    $section = $phpWord->addSection([
        'marginTop' => 900,
        'marginBottom' => 900,
        'marginLeft' => 900,
        'marginRight' => 900,
        'orientation' => 'landscape',
    ]);

    $title = 'Appointments Report';
    if ($schoolName !== '') {
        $title = $schoolName . ' — ' . $title;
    }
    $section->addText($title, ['bold' => true, 'size' => 16]);
    $section->addText('Range: ' . $from . ' to ' . $to);
    $section->addText('Generated: ' . date('Y-m-d H:i'));
    $section->addTextBreak(1);

    $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'D1D5DB', 'cellMargin' => 70]);
    $table->addRow();
    $table->addCell(1200)->addText('Date', ['bold' => true]);
    $table->addCell(900)->addText('Time', ['bold' => true]);
    $table->addCell(2000)->addText('Student', ['bold' => true]);
    $table->addCell(1200)->addText('Case #', ['bold' => true]);
    $table->addCell(1800)->addText('Counselor', ['bold' => true]);
    $table->addCell(1100)->addText('Status', ['bold' => true]);
    $table->addCell(2800)->addText('Purpose', ['bold' => true]);
    $table->addCell(2000)->addText('Location', ['bold' => true]);

    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $table->addRow();
        $table->addCell(1500)->addText((string)($r['scheduled_date'] ?? ''));
        $table->addCell(1000)->addText(substr((string)($r['scheduled_time'] ?? ''), 0, 5));
        $table->addCell(2200)->addText((string)($r['student_name'] ?? ''));
        $table->addCell(1300)->addText((string)($r['case_number'] ?? ''));
        $table->addCell(1800)->addText((string)($r['counselor_name'] ?? ''));
        $table->addCell(1100)->addText((string)($r['status'] ?? ''));
        $table->addCell(2500)->addText((string)($r['purpose'] ?? ''));
        $table->addCell(2000)->addText((string)($r['location'] ?? ''));
    }

    $tmp = tempnam(sys_get_temp_dir(), 'gm_docx_');
    $tmpPath = $tmp ? ($tmp . '.docx') : (sys_get_temp_dir() . '/gm_' . uniqid() . '.docx');
    if ($tmp && is_file($tmp)) {
        kernelDeletePath($tmp);
    }

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($tmpPath);
    guidanceSendDocx('appointments-' . $from . '-to-' . $to . '.docx', $tmpPath);
}

function pageGuidanceTrackers(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    echo guidanceRender('modules/guidance/pages/trackers.disyl', guidanceBasePageContext($user, 'Student Tracker', 'trackers'));
}

function apiGuidanceTrackers(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $db = guidanceDb();
    try {
        $stmt = $db->query(
            "SELECT t.*, c.name AS college_name,\n"
            . "(SELECT COUNT(*) FROM gm_tracker_students s WHERE s.tracker_id = t.id) AS student_count\n"
            . "FROM gm_trackers t\n"
            . "LEFT JOIN gm_colleges c ON t.college_id = c.id\n"
            . "ORDER BY t.is_active DESC, t.updated_at DESC"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $rows = [];
    }

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/trackers-table.disyl', [
        'trackers' => $rows,
        'base_url' => '/admin/guidance',
    ]);
}

function modalGuidanceTrackerNew(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor']);
    $db = guidanceDb();
    $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
    echo guidanceRender('modules/guidance/modals/tracker-form.disyl', [
        'tracker' => ['is_active' => '1'],
        'colleges' => $colleges,
    ]);
}

function modalGuidanceTrackerEdit(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Tracker not found</div>';
        return;
    }
    $db = guidanceDb();
    $stmt = $db->prepare("SELECT * FROM gm_trackers WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $tracker = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($tracker)) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Tracker not found</div>';
        return;
    }
    $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
    echo guidanceRender('modules/guidance/modals/tracker-form.disyl', [
        'tracker' => $tracker,
        'colleges' => $colleges,
    ]);
}

function apiGuidanceCreateTracker(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor']);
    $userId = (int)($user['id'] ?? 0);
    $input = guidanceInput();
    if (!is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Name is required', 'type' => 'error']]));
        echo '';
        return;
    }
    $academicYear = trim((string)($input['academic_year'] ?? ''));
    $collegeId = (int)($input['college_id'] ?? 0);
    $description = trim((string)($input['description'] ?? ''));
    $isActive = !empty($input['is_active']) ? 1 : 0;

    $db = guidanceDb();
    $stmt = $db->prepare(
        "INSERT INTO gm_trackers (name, description, academic_year, college_id, is_active, created_by, created_at, updated_at)\n"
        . "VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->execute([
        $name,
        ($description !== '' ? $description : null),
        ($academicYear !== '' ? $academicYear : null),
        ($collegeId > 0 ? $collegeId : null),
        $isActive,
        $userId,
    ]);

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Tracker created', 'type' => 'success'], 'refreshTrackers' => true]));
    echo '';
}

function apiGuidanceUpdateTracker(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);
    $input = guidanceInput();
    if ($id < 1 || !is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Name is required', 'type' => 'error']]));
        echo '';
        return;
    }
    $academicYear = trim((string)($input['academic_year'] ?? ''));
    $collegeId = (int)($input['college_id'] ?? 0);
    $description = trim((string)($input['description'] ?? ''));
    $isActive = !empty($input['is_active']) ? 1 : 0;

    $db = guidanceDb();
    $stmt = $db->prepare(
        "UPDATE gm_trackers SET name = ?, description = ?, academic_year = ?, college_id = ?, is_active = ?, updated_at = NOW() WHERE id = ?"
    );
    $stmt->execute([
        $name,
        ($description !== '' ? $description : null),
        ($academicYear !== '' ? $academicYear : null),
        ($collegeId > 0 ? $collegeId : null),
        $isActive,
        $id,
    ]);

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Tracker updated', 'type' => 'success'], 'refreshTrackers' => true]));
    echo '';
}

function apiGuidanceDeleteTracker(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Tracker not found', 'type' => 'error']]));
        echo '';
        return;
    }
    $db = guidanceDb();
    $db->prepare("DELETE FROM gm_trackers WHERE id = ?")->execute([$id]);
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Tracker deleted', 'type' => 'success'], 'refreshTrackers' => true]));
    echo '';
}

function pageGuidanceTrackerView(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        echo 'Tracker not found';
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare("SELECT t.*, c.name AS college_name FROM gm_trackers t LEFT JOIN gm_colleges c ON t.college_id = c.id WHERE t.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $tracker = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($tracker)) {
        http_response_code(404);
        echo 'Tracker not found';
        return;
    }

    $itemsStmt = $db->prepare("SELECT * FROM gm_tracker_items WHERE tracker_id = ? ORDER BY sort_order, id");
    $itemsStmt->execute([$id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $studentsStmt = $db->prepare(
        "SELECT s.*, c.name AS college_name\n"
        . "FROM gm_tracker_students s\n"
        . "LEFT JOIN gm_colleges c ON s.college_id = c.id\n"
        . "WHERE s.tracker_id = ?\n"
        . "ORDER BY s.student_name, s.id"
    );
    $studentsStmt->execute([$id]);
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $submissionsMap = [];
    if (count($students) > 0 && count($items) > 0) {
        $subStmt = $db->prepare(
            "SELECT sub.tracker_student_id, sub.tracker_item_id, sub.status\n"
            . "FROM gm_tracker_submissions sub\n"
            . "JOIN gm_tracker_students s ON sub.tracker_student_id = s.id\n"
            . "JOIN gm_tracker_items i ON sub.tracker_item_id = i.id\n"
            . "WHERE s.tracker_id = ? AND i.tracker_id = ?"
        );
        $subStmt->execute([$id, $id]);
        $subs = $subStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($subs as $row) {
            if (!is_array($row)) continue;
            $sid = (int)($row['tracker_student_id'] ?? 0);
            $iid = (int)($row['tracker_item_id'] ?? 0);
            if ($sid < 1 || $iid < 1) continue;
            if (!isset($submissionsMap[$sid])) $submissionsMap[$sid] = [];
            $submissionsMap[$sid][$iid] = (string)($row['status'] ?? 'pending');
        }
    }

    $ctx = array_merge(
        guidanceBasePageContext($user, 'Tracker', 'trackers'),
        [
            'tracker' => $tracker,
            'items' => $items,
            'students' => $students,
            'submissions' => $submissionsMap,
        ]
    );
    echo guidanceRender('modules/guidance/pages/tracker-view.disyl', $ctx);
}

function modalGuidanceTrackerStudentNew(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $trackerId = (int)($params['id'] ?? 0);
    if ($trackerId < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Tracker not found</div>';
        return;
    }
    $db = guidanceDb();
    $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
    echo guidanceRender('modules/guidance/modals/tracker-student-form.disyl', [
        'tracker_id' => $trackerId,
        'colleges' => $colleges,
    ]);
}

function modalGuidanceTrackerStudentView(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $trackerId = (int)($params['id'] ?? 0);
    $studentId = (int)($params['studentId'] ?? 0);
    if ($trackerId < 1 || $studentId < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Student not found</div>';
        return;
    }
    $db = guidanceDb();
    $stmt = $db->prepare(
        "SELECT s.*, c.name AS college_name\n"
        . "FROM gm_tracker_students s\n"
        . "LEFT JOIN gm_colleges c ON s.college_id = c.id\n"
        . "WHERE s.id = ? AND s.tracker_id = ? LIMIT 1"
    );
    $stmt->execute([$studentId, $trackerId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($student)) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Student not found</div>';
        return;
    }
    echo guidanceRender('modules/guidance/modals/tracker-student-view.disyl', [
        'student' => $student,
    ]);
}

function apiGuidanceTrackerStudents(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $trackerId = (int)($params['id'] ?? 0);
    if ($trackerId < 1) {
        http_response_code(404);
        echo '';
        return;
    }
    $db = guidanceDb();
    $stmt = $db->prepare("SELECT * FROM gm_tracker_students WHERE tracker_id = ? ORDER BY student_name, id");
    $stmt->execute([$trackerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $rows]);
}

function apiGuidanceTrackerItems(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $trackerId = (int)($params['id'] ?? 0);
    if ($trackerId < 1) {
        http_response_code(404);
        echo '';
        return;
    }
    $db = guidanceDb();
    $stmt = $db->prepare("SELECT * FROM gm_tracker_items WHERE tracker_id = ? ORDER BY sort_order, id");
    $stmt->execute([$trackerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $rows]);
}

function apiGuidanceCreateTrackerStudent(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $trackerId = (int)($params['id'] ?? 0);
    $input = guidanceInput();
    if ($trackerId < 1 || !is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }
    $name = trim((string)($input['student_name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Student name is required', 'type' => 'error']]));
        echo '';
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare(
        "INSERT INTO gm_tracker_students (tracker_id, student_id, student_name, college_id, year_level, section, email, phone, notes, created_at, updated_at)\n"
        . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->execute([
        $trackerId,
        (($sid = trim((string)($input['student_id'] ?? ''))) !== '' ? $sid : null),
        $name,
        (($cid = (int)($input['college_id'] ?? 0)) > 0 ? $cid : null),
        (($yl = trim((string)($input['year_level'] ?? ''))) !== '' ? $yl : null),
        (($sec = trim((string)($input['section'] ?? ''))) !== '' ? $sec : null),
        (($email = trim((string)($input['email'] ?? ''))) !== '' ? $email : null),
        (($phone = trim((string)($input['phone'] ?? ''))) !== '' ? $phone : null),
        (($notes = trim((string)($input['notes'] ?? ''))) !== '' ? $notes : null),
    ]);

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Student added', 'type' => 'success'], 'closeModal' => true]));
    header('HX-Redirect: /admin/guidance/pages/trackers/' . $trackerId);
    echo '';
}

function apiGuidanceCreateTrackerItem(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor']);
    $trackerId = (int)($params['id'] ?? 0);
    $input = guidanceInput();
    if ($trackerId < 1 || !is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Item name is required', 'type' => 'error']]));
        echo '';
        return;
    }
    $db = guidanceDb();
    $stmt = $db->prepare(
        "INSERT INTO gm_tracker_items (tracker_id, name, description, is_required, sort_order, deadline, created_at)\n"
        . "VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $trackerId,
        $name,
        (($desc = trim((string)($input['description'] ?? ''))) !== '' ? $desc : null),
        (!empty($input['is_required']) ? 1 : 0),
        (int)($input['sort_order'] ?? 0),
        (($dl = trim((string)($input['deadline'] ?? ''))) !== '' ? $dl : null),
    ]);

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Item added', 'type' => 'success'], 'closeModal' => true]));
    header('HX-Redirect: /admin/guidance/pages/trackers/' . $trackerId);
    echo '';
}

function apiGuidanceUpdateTrackerItem(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor']);
    $trackerId = (int)($params['id'] ?? 0);
    $itemId = (int)($params['itemId'] ?? 0);
    $input = guidanceInput();
    if ($trackerId < 1 || $itemId < 1 || !is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Item name is required', 'type' => 'error']]));
        echo '';
        return;
    }
    $db = guidanceDb();
    $stmt = $db->prepare(
        "UPDATE gm_tracker_items SET name = ?, description = ?, is_required = ?, sort_order = ?, deadline = ?\n"
        . "WHERE id = ? AND tracker_id = ?"
    );
    $stmt->execute([
        $name,
        (($desc = trim((string)($input['description'] ?? ''))) !== '' ? $desc : null),
        (!empty($input['is_required']) ? 1 : 0),
        (int)($input['sort_order'] ?? 0),
        (($dl = trim((string)($input['deadline'] ?? ''))) !== '' ? $dl : null),
        $itemId,
        $trackerId,
    ]);

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Item updated', 'type' => 'success'], 'closeModal' => true]));
    header('HX-Redirect: /admin/guidance/pages/trackers/' . $trackerId);
    echo '';
}

function apiGuidanceDeleteTrackerItem(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    $trackerId = (int)($params['id'] ?? 0);
    $itemId = (int)($params['itemId'] ?? 0);
    if ($trackerId < 1 || $itemId < 1) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Item not found', 'type' => 'error']]));
        echo '';
        return;
    }
    $db = guidanceDb();
    $db->prepare("DELETE FROM gm_tracker_items WHERE id = ? AND tracker_id = ?")->execute([$itemId, $trackerId]);
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Item deleted', 'type' => 'success']]));
    echo '';
}

function apiGuidanceDeleteTrackerStudent(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor']);
    $trackerId = (int)($params['id'] ?? 0);
    $studentId = (int)($params['studentId'] ?? 0);
    if ($trackerId < 1 || $studentId < 1) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Student not found', 'type' => 'error']]));
        echo '';
        return;
    }
    $db = guidanceDb();
    $db->prepare("DELETE FROM gm_tracker_students WHERE id = ? AND tracker_id = ?")->execute([$studentId, $trackerId]);
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Student removed', 'type' => 'success']]));
    echo '';
}

function apiGuidanceSaveTrackerStudentSubmissions(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $trackerId = (int)($params['id'] ?? 0);
    $studentId = (int)($params['studentId'] ?? 0);
    if ($trackerId < 1 || $studentId < 1) {
        http_response_code(404);
        echo '';
        return;
    }

    $input = guidanceInput();
    if (!is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }

    $itemId = (int)($input['tracker_item_id'] ?? 0);
    $status = (string)($input['value'] ?? ($input['status'] ?? ''));
    if ($itemId < 1 || !in_array($status, ['pending', 'submitted', 'verified', 'rejected'], true)) {
        http_response_code(422);
        echo '';
        return;
    }

    $db = guidanceDb();

    // Ensure student belongs to tracker
    $chkS = $db->prepare("SELECT id FROM gm_tracker_students WHERE id = ? AND tracker_id = ? LIMIT 1");
    $chkS->execute([$studentId, $trackerId]);
    if (!$chkS->fetchColumn()) {
        http_response_code(404);
        echo '';
        return;
    }
    // Ensure item belongs to tracker
    $chkI = $db->prepare("SELECT id FROM gm_tracker_items WHERE id = ? AND tracker_id = ? LIMIT 1");
    $chkI->execute([$itemId, $trackerId]);
    if (!$chkI->fetchColumn()) {
        http_response_code(404);
        echo '';
        return;
    }

    $verifiedBy = null;
    if ($status === 'verified') {
        $verifiedBy = (int)($user['id'] ?? 0);
    }
    $submittedAt = null;
    if ($status === 'submitted' || $status === 'verified') {
        $submittedAt = date('Y-m-d H:i:s');
    }

    $stmt = $db->prepare(
        "INSERT INTO gm_tracker_submissions (tracker_student_id, tracker_item_id, status, submitted_at, verified_by, created_at, updated_at)\n"
        . "VALUES (?, ?, ?, ?, ?, NOW(), NOW())\n"
        . "ON DUPLICATE KEY UPDATE status = VALUES(status), submitted_at = VALUES(submitted_at), verified_by = VALUES(verified_by), updated_at = NOW()"
    );
    $stmt->execute([$studentId, $itemId, $status, $submittedAt, $verifiedBy]);

    header('Content-Type: text/plain; charset=utf-8');
    echo '';
}

function modalGuidanceTrackerItemNew(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor']);
    $trackerId = (int)($params['id'] ?? 0);
    if ($trackerId < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Tracker not found</div>';
        return;
    }
    echo guidanceRender('modules/guidance/modals/tracker-item-form.disyl', [
        'tracker_id' => $trackerId,
        'item' => ['is_required' => '1', 'sort_order' => '0'],
    ]);
}

function modalGuidanceTrackerItemEdit(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor']);
    $trackerId = (int)($params['id'] ?? 0);
    $itemId = (int)($params['itemId'] ?? 0);
    if ($trackerId < 1 || $itemId < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Item not found</div>';
        return;
    }
    $db = guidanceDb();
    $stmt = $db->prepare("SELECT * FROM gm_tracker_items WHERE id = ? AND tracker_id = ? LIMIT 1");
    $stmt->execute([$itemId, $trackerId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($item)) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Item not found</div>';
        return;
    }
    echo guidanceRender('modules/guidance/modals/tracker-item-form.disyl', [
        'tracker_id' => $trackerId,
        'item' => $item,
    ]);
}

function pageGuidanceUsers(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $ctxUser = $user;
    $role = (string)($ctxUser['role'] ?? '');
    $isAdmin = $role === 'admin';

    echo guidanceRender('modules/guidance/pages/users.disyl', array_merge(
        guidanceBasePageContext($ctxUser, $isAdmin ? 'Users' : 'My Account', 'users'),
        ['is_admin' => $isAdmin]
    ));
}

function pageGuidanceColleges(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor']);
    echo guidanceRender('modules/guidance/pages/colleges.disyl', guidanceBasePageContext($user, 'Colleges', 'colleges'));
}

function pageGuidanceSettings(): void
{
    $user = guidanceRequireStaff(['admin']);
    $settings = guidanceGetAllSettings();
    echo guidanceRender('modules/guidance/pages/settings.disyl', array_merge(
        guidanceBasePageContext($user, 'Settings', 'settings'),
        ['settings' => $settings]
    ));
}

function guidanceGetAllSettings(): array
{
    $db = guidanceDb();
    $settings = [];
    try {
        $stmt = $db->query("SELECT setting_key, setting_value, setting_type FROM gm_settings");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = (string)($row['setting_key'] ?? '');
            if ($key === '') continue;
            $value = $row['setting_value'] ?? null;
            $type = (string)($row['setting_type'] ?? 'string');
            switch ($type) {
                case 'json':
                    $parsed = json_decode((string)$value, true);
                    $settings[$key] = is_array($parsed) ? $parsed : [];
                    break;
                case 'boolean':
                    $settings[$key] = !empty($value) ? '1' : '0';
                    break;
                case 'integer':
                    $settings[$key] = (string)((int)$value);
                    break;
                default:
                    $settings[$key] = (string)($value ?? '');
            }
        }
    } catch (Throwable $e) {
        $settings = [];
    }

    $defaults = [
        'retention_active_years' => '7',
        'retention_closed_years' => '5',
        'reminder_hours_before' => '24',
        'email_notifications' => '1',
        'two_fa_login' => '0',
        'two_fa_booking' => '0',
        'app_country' => 'PH',
        'app_region' => 'Manila',
        'app_timezone' => 'Asia/Manila',
    ];
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $settings)) {
            $settings[$k] = $v;
        }
    }

    return $settings;
}

function apiGuidanceGetSettings(): void
{
    guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => guidanceGetAllSettings()]);
}

function apiGuidanceUpdateSettings(): void
{
    $user = guidanceRequireStaff(['admin']);
    $input = guidanceInput();
    if (!is_array($input) || empty($input)) {
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Settings saved', 'type' => 'success']]));
            echo '';
            return;
        }
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No settings provided']);
        return;
    }

    $db = guidanceDb();
    try {
        $stmt = $db->prepare(
            "INSERT INTO gm_settings (setting_key, setting_value, setting_type, updated_by, updated_at)\n"
            . "VALUES (?, ?, ?, ?, NOW())\n"
            . "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), updated_by = VALUES(updated_by), updated_at = NOW()"
        );
        $uid = is_array($user) ? (int)($user['id'] ?? 0) : 0;
        foreach ($input as $k => $v) {
            $key = trim((string)$k);
            if ($key === '') continue;
            $type = 'string';
            $store = $v;
            if (is_array($v)) {
                $type = 'json';
                $store = json_encode($v);
            } elseif (is_bool($v)) {
                $type = 'boolean';
                $store = $v ? '1' : '0';
            } elseif (is_int($v)) {
                $type = 'integer';
                $store = (string)$v;
            }
            $stmt->execute([$key, (string)($store ?? ''), $type, $uid]);
        }

        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Settings saved successfully', 'type' => 'success']]));
            echo '';
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        if (guidanceIsHtmx()) {
            http_response_code(500);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to save settings', 'type' => 'error']]));
            echo '';
            return;
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to update settings']);
    }
}

function guidanceIsValidRole(string $role): bool
{
    return in_array($role, ['admin', 'supervisor', 'counselor'], true);
}

function guidanceValidatePasswordStrength(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters';
    }
    return null;
}

function apiGuidanceUsers(): void
{
    $cu = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($cu['role'] ?? '');
    $isAdmin = $role === 'admin';
    $userId = (int)($cu['id'] ?? 0);
    $input = guidanceInput();

    $db = guidanceDb();
    $where = ['deleted_at IS NULL'];
    $params = [];

    if (!$isAdmin) {
        $where[] = 'id = ?';
        $params[] = $userId;
    } else {
        if (!empty($input['user_search'])) {
            $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR CONCAT(first_name,' ',last_name) LIKE ?)";
            $s = '%' . (string)$input['user_search'] . '%';
            $params = array_merge($params, [$s, $s, $s, $s]);
        }
        if (!empty($input['user_role'])) {
            $where[] = 'role = ?';
            $params[] = (string)$input['user_role'];
        }
    }
    $whereStr = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT id, email, first_name, last_name, role, is_active, created_at FROM gm_users WHERE {$whereStr} ORDER BY created_at DESC");
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $collegeMap = [];
    try {
        $caStmt = $db->query(
            "SELECT ca.counselor_id, c.code\n"
            . "FROM gm_counselor_assignments ca\n"
            . "JOIN gm_colleges c ON ca.college_id = c.id\n"
            . "WHERE ca.is_active = 1 AND c.is_active = 1\n"
            . "ORDER BY c.sort_order"
        );
        $allAssignments = $caStmt ? $caStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($allAssignments as $a) {
            if (!is_array($a)) continue;
            $cid = (int)($a['counselor_id'] ?? 0);
            $code = (string)($a['code'] ?? '');
            if ($cid > 0 && $code !== '') {
                $collegeMap[$cid][] = $code;
            }
        }
    } catch (Throwable $e) {
        $collegeMap = [];
    }

    foreach ($users as &$u) {
        $id = (int)($u['id'] ?? 0);
        $u['colleges'] = $collegeMap[$id] ?? [];
        $u['colleges_display'] = implode(', ', $u['colleges']);
    }
    unset($u);

    $roleStats = ['total' => count($users), 'admin' => 0, 'counselor' => 0, 'supervisor' => 0];
    if ($isAdmin) {
        $statStmt = $db->query("SELECT role, COUNT(*) AS cnt FROM gm_users WHERE deleted_at IS NULL GROUP BY role");
        $rows = $statStmt ? $statStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $rs) {
            if (!is_array($rs)) continue;
            $r = (string)($rs['role'] ?? '');
            $c = (int)($rs['cnt'] ?? 0);
            if (isset($roleStats[$r])) {
                $roleStats[$r] = $c;
            }
        }
        $roleStats['total'] = (int)$roleStats['admin'] + (int)$roleStats['counselor'] + (int)$roleStats['supervisor'];
    }

    if (guidanceIsHtmx()) {
        header('Content-Type: text/html; charset=utf-8');
        echo guidanceRender('modules/guidance/partials/users-table.disyl', [
            'users' => $users,
            'stats' => $roleStats,
            'result_count' => count($users),
            'current_user_id' => $userId,
            'is_admin' => $isAdmin,
        ]);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $users]);
}

function modalGuidanceUserNew(): void
{
    $user = guidanceRequireStaff(['admin']);
    $db = guidanceDb();
    $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
    echo guidanceRender('modules/guidance/modals/user-form.disyl', [
        'user' => [],
        'is_admin' => true,
        'is_self' => false,
        'colleges' => $colleges,
        'assigned_colleges_json' => '[]',
    ]);
}

function modalGuidanceUserEdit(array $params = []): void
{
    $cu = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($cu['role'] ?? '');
    $isAdmin = $role === 'admin';
    $currentId = (int)($cu['id'] ?? 0);

    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">User not found</div>';
        return;
    }

    if (!$isAdmin && $id !== $currentId) {
        http_response_code(403);
        echo '<div class="p-4 text-red-600">Access denied</div>';
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare("SELECT id, email, first_name, last_name, phone, role, is_active FROM gm_users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($user)) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">User not found</div>';
        return;
    }

    $colleges = [];
    $assignedIds = [];
    if ($isAdmin) {
        $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        $aStmt = $db->prepare("SELECT college_id FROM gm_counselor_assignments WHERE counselor_id = ? AND is_active = 1");
        $aStmt->execute([$id]);
        $assignedIds = array_map('intval', $aStmt->fetchAll(PDO::FETCH_COLUMN));
    }

    echo guidanceRender('modules/guidance/modals/user-form.disyl', [
        'user' => $user,
        'is_admin' => $isAdmin,
        'is_self' => $id === $currentId,
        'colleges' => $colleges,
        'assigned_colleges_json' => json_encode($assignedIds),
    ]);
}

function apiGuidanceCreateUser(): void
{
    $currentUser = guidanceRequireStaff(['admin']);
    $input = guidanceInput();
    if (!is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }

    $email = trim((string)($input['email'] ?? ''));
    $first = trim((string)($input['first_name'] ?? ''));
    $last = trim((string)($input['last_name'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $role = (string)($input['role'] ?? 'counselor');
    $password = (string)($input['password'] ?? '');

    if ($email === '' || $first === '' || $last === '') {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Email, first name, and last name are required', 'type' => 'error']]));
        echo '';
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid email address', 'type' => 'error']]));
        echo '';
        return;
    }
    if (!guidanceIsValidRole($role)) {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid role', 'type' => 'error']]));
        echo '';
        return;
    }
    if ($password === '') {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Password is required', 'type' => 'error']]));
        echo '';
        return;
    }
    $pwErr = guidanceValidatePasswordStrength($password);
    if ($pwErr) {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $pwErr, 'type' => 'error']]));
        echo '';
        return;
    }

    $db = guidanceDb();
    $dup = $db->prepare("SELECT id FROM gm_users WHERE email = ? AND deleted_at IS NULL LIMIT 1");
    $dup->execute([$email]);
    if ($dup->fetchColumn()) {
        http_response_code(409);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'A user with this email already exists', 'type' => 'error']]));
        echo '';
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO gm_users (email, password, first_name, last_name, phone, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
    $stmt->execute([$email, $hash, $first, $last, ($phone !== '' ? $phone : null), $role]);

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User created successfully', 'type' => 'success'], 'refreshUsers' => true]));
    echo '';
}

function apiGuidanceUpdateUser(array $params = []): void
{
    $cu = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($cu['role'] ?? '');
    $isAdmin = $role === 'admin';
    $currentId = (int)($cu['id'] ?? 0);

    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        echo '';
        return;
    }
    if (!$isAdmin && $id !== $currentId) {
        http_response_code(403);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Access denied', 'type' => 'error']]));
        echo '';
        return;
    }

    $input = guidanceInput();
    if (!is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }

    $db = guidanceDb();
    $updates = [];
    $values = [];

    if (!empty($input['email']) && $isAdmin) {
        $email = trim((string)$input['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid email address', 'type' => 'error']]));
            echo '';
            return;
        }
        $dup = $db->prepare("SELECT id FROM gm_users WHERE email = ? AND id != ? AND deleted_at IS NULL LIMIT 1");
        $dup->execute([$email, $id]);
        if ($dup->fetchColumn()) {
            http_response_code(409);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'A user with this email already exists', 'type' => 'error']]));
            echo '';
            return;
        }
        $updates[] = 'email = ?';
        $values[] = $email;
    }
    if (!empty($input['first_name'])) {
        $updates[] = 'first_name = ?';
        $values[] = trim((string)$input['first_name']);
    }
    if (!empty($input['last_name'])) {
        $updates[] = 'last_name = ?';
        $values[] = trim((string)$input['last_name']);
    }
    if (array_key_exists('phone', $input)) {
        $phone = trim((string)($input['phone'] ?? ''));
        $updates[] = 'phone = ?';
        $values[] = ($phone !== '' ? $phone : null);
    }
    if (!empty($input['role']) && $isAdmin) {
        $newRole = (string)$input['role'];
        if (!guidanceIsValidRole($newRole)) {
            http_response_code(400);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid role', 'type' => 'error']]));
            echo '';
            return;
        }
        if ($id === $currentId && $newRole !== 'admin') {
            http_response_code(400);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'You cannot change your own role', 'type' => 'error']]));
            echo '';
            return;
        }
        $updates[] = 'role = ?';
        $values[] = $newRole;
    }

    $password = trim((string)($input['password'] ?? ''));
    if ($password !== '') {
        $pwErr = guidanceValidatePasswordStrength($password);
        if ($pwErr) {
            http_response_code(400);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $pwErr, 'type' => 'error']]));
            echo '';
            return;
        }
        $updates[] = 'password = ?';
        $values[] = password_hash($password, PASSWORD_DEFAULT);
    }

    if (!empty($updates)) {
        $values[] = $id;
        $stmt = $db->prepare("UPDATE gm_users SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($values);
    }

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User updated successfully', 'type' => 'success'], 'refreshUsers' => true]));
    echo '';
}

function apiGuidanceDeleteUser(array $params = []): void
{
    $currentAdmin = guidanceRequireStaff(['admin']);
    $adminId = (int)($currentAdmin['id'] ?? 0);
    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User not found', 'type' => 'error']]));
        echo '';
        return;
    }
    if ($id === $adminId) {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'You cannot delete your own account', 'type' => 'error']]));
        echo '';
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare("UPDATE gm_users SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User deleted', 'type' => 'success'], 'refreshUsers' => true]));
    echo '';
}

function apiGuidanceToggleUserActive(array $params = []): void
{
    $currentAdmin = guidanceRequireStaff(['admin']);
    $adminId = (int)($currentAdmin['id'] ?? 0);
    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User not found', 'type' => 'error']]));
        echo '';
        return;
    }
    if ($id === $adminId) {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'You cannot deactivate your own account', 'type' => 'error']]));
        echo '';
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare("UPDATE gm_users SET is_active = IF(is_active=1,0,1) WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User status updated', 'type' => 'success'], 'refreshUsers' => true]));
    echo '';
}

function apiGuidanceSaveUserColleges(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User not found', 'type' => 'error']]));
        echo '';
        return;
    }

    $input = guidanceInput();
    $collegeIds = is_array($input) ? ($input['college_ids'] ?? []) : [];
    if (!is_array($collegeIds)) {
        $collegeIds = [];
    }
    $collegeIds = array_values(array_filter(array_map('intval', $collegeIds)));

    $db = guidanceDb();
    $db->prepare("UPDATE gm_counselor_assignments SET is_active = 0 WHERE counselor_id = ?")->execute([$id]);

    foreach ($collegeIds as $cid) {
        $existing = $db->prepare("SELECT id FROM gm_counselor_assignments WHERE counselor_id = ? AND college_id = ? LIMIT 1");
        $existing->execute([$id, $cid]);
        $rowId = $existing->fetchColumn();
        if ($rowId) {
            $db->prepare("UPDATE gm_counselor_assignments SET is_active = 1 WHERE id = ?")->execute([(int)$rowId]);
        } else {
            $db->prepare("INSERT INTO gm_counselor_assignments (counselor_id, college_id, is_active, assigned_at) VALUES (?, ?, 1, NOW())")->execute([$id, $cid]);
        }
    }

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'College assignments updated', 'type' => 'success'], 'refreshUsers' => true]));
    echo '';
}

function apiGuidanceColleges(): void
{
    guidanceRequireStaff(['admin', 'supervisor']);
    $db = guidanceDb();
    $stmt = $db->query(
        "SELECT c.*,\n"
        . "(SELECT COUNT(*) FROM gm_counselor_assignments ca JOIN gm_users u ON ca.counselor_id = u.id WHERE ca.college_id = c.id AND ca.is_active = 1 AND u.role != 'admin') as counselor_count\n"
        . "FROM gm_colleges c\n"
        . "ORDER BY c.sort_order, c.name"
    );
    $colleges = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (guidanceIsHtmx()) {
        header('Content-Type: text/html; charset=utf-8');
        echo guidanceRender('modules/guidance/partials/colleges-table.disyl', ['colleges' => $colleges]);
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $colleges]);
}

function modalGuidanceCollegeNew(): void
{
    guidanceRequireStaff(['admin']);
    echo guidanceRender('modules/guidance/modals/college-form.disyl', ['college' => [], 'assigned_counselors' => []]);
}

function modalGuidanceCollegeEdit(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    $id = (int)($params['id'] ?? 0);
    $db = guidanceDb();
    $stmt = $db->prepare("SELECT * FROM gm_colleges WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $college = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($college)) {
        echo '<div class="p-4 text-red-600">College not found</div>';
        return;
    }
    $cStmt = $db->prepare(
        "SELECT ca.counselor_id, CONCAT(u.first_name, ' ', u.last_name) AS name, u.email, ca.is_primary\n"
        . "FROM gm_counselor_assignments ca\n"
        . "JOIN gm_users u ON ca.counselor_id = u.id\n"
        . "WHERE ca.college_id = ? AND ca.is_active = 1 AND u.deleted_at IS NULL\n"
        . "ORDER BY u.first_name"
    );
    $cStmt->execute([$id]);
    $assigned = $cStmt->fetchAll(PDO::FETCH_ASSOC);
    echo guidanceRender('modules/guidance/modals/college-form.disyl', ['college' => $college, 'assigned_counselors' => $assigned]);
}

function apiGuidanceUpdateCollege(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    $id = (int)($params['id'] ?? 0);
    $input = guidanceInput();
    if (!is_array($input)) {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid request', 'type' => 'error']]));
        echo '';
        return;
    }
    $db = guidanceDb();
    $stmt = $db->prepare("UPDATE gm_colleges SET code = ?, name = ?, description = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([
        strtoupper((string)($input['code'] ?? '')),
        (string)($input['name'] ?? ''),
        (string)($input['description'] ?? ''),
        (int)($input['sort_order'] ?? 0),
        (int)($input['is_active'] ?? 1),
        $id,
    ]);
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'College updated', 'type' => 'success'], 'refreshColleges' => true]));
    echo '';
}

function apiGuidanceDeleteCollege(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'College not found', 'type' => 'error']]));
        echo '';
        return;
    }
    $db = guidanceDb();
    $db->prepare("UPDATE gm_colleges SET is_active = 0, updated_at = NOW() WHERE id = ?")->execute([$id]);
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'College deactivated', 'type' => 'success'], 'refreshColleges' => true]));
    echo '';
}

function pageGuidanceProfile(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $id = (int)($user['id'] ?? 0);
    $row = null;
    if ($id > 0) {
        try {
            $stmt = guidanceDb()->prepare("SELECT id, email, first_name, last_name, phone FROM gm_users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $row = null;
        }
    }
    if (!is_array($row)) {
        $row = [
            'email' => (string)($user['username'] ?? ''),
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
        ];
    }

    echo guidanceRender('modules/guidance/pages/profile.disyl', array_merge(
        guidanceBasePageContext(is_array($user) ? $user : [], 'Profile', 'profile'),
        [
            'email' => (string)($row['email'] ?? ''),
            'first_name' => (string)($row['first_name'] ?? ''),
            'last_name' => (string)($row['last_name'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
        ]
    ));
}

function apiGuidanceUpdateOwnProfile(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');

    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $id = (int)($user['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        return;
    }

    $input = guidanceInput();
    app()->csrfEnforce();

    $first = trim((string)($input['first_name'] ?? ''));
    $last = trim((string)($input['last_name'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($first === '' || $last === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'First and last name are required.']);
        return;
    }
    if ($password !== '' && strlen($password) < 8) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters.']);
        return;
    }

    $updates = ['first_name = ?', 'last_name = ?', 'phone = ?'];
    $values = [$first, $last, ($phone !== '' ? $phone : null)];
    if ($password !== '') {
        $updates[] = 'password = ?';
        $values[] = password_hash($password, PASSWORD_DEFAULT);
    }
    $values[] = $id;

    try {
        $sql = 'UPDATE gm_users SET ' . implode(', ', $updates) . ' WHERE id = ? AND deleted_at IS NULL';
        $stmt = guidanceDb()->prepare($sql);
        $stmt->execute($values);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to update profile']);
        return;
    }

    echo json_encode(['ok' => true]);
}

function apiGuidanceDashboardStats(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $role = is_array($user) ? (string)($user['role'] ?? '') : '';
    $isCounselor = $role === 'counselor';
    $counselorId = $isCounselor && is_array($user) ? (int)($user['id'] ?? 0) : null;

    $db = guidanceDb();
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $weekStart = date('Y-m-d', strtotime('monday this week'));

    $caseFilter = "deleted_at IS NULL";
    $caseParams = [];
    if ($isCounselor && $counselorId) {
        $caseFilter .= " AND counselor_id = ?";
        $caseParams[] = $counselorId;
    }

    $stats = [];

    $stmt = $db->prepare("SELECT COUNT(*) FROM gm_cases WHERE status IN ('open', 'in_progress') AND {$caseFilter}");
    $stmt->execute($caseParams);
    $stats['active_cases'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM gm_cases WHERE severity = 'critical' AND status NOT IN ('closed', 'archived') AND {$caseFilter}");
    $stmt->execute($caseParams);
    $stats['critical_cases'] = (int)$stmt->fetchColumn();

    $aptFilter = "a.scheduled_date = ? AND a.status NOT IN ('cancelled', 'no_show', 'pending')";
    $aptParams = [$today];
    if ($isCounselor && $counselorId) {
        $aptFilter .= " AND a.counselor_id = ?";
        $aptParams[] = $counselorId;
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM gm_appointments a WHERE {$aptFilter}");
    $stmt->execute($aptParams);
    $stats['today_appointments'] = (int)$stmt->fetchColumn();

    $pendFilter = "a.status = 'pending'";
    $pendParams = [];
    if ($isCounselor && $counselorId) {
        $pendFilter .= " AND a.counselor_id = ?";
        $pendParams[] = $counselorId;
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM gm_appointments a WHERE {$pendFilter}");
    $stmt->execute($pendParams);
    $stats['pending_approvals'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM gm_cases WHERE created_at >= ? AND {$caseFilter}");
    $stmt->execute(array_merge([$monthStart], $caseParams));
    $stats['cases_this_month'] = (int)$stmt->fetchColumn();

    $sessFilter = "n.session_date >= ?";
    $sessParams = [$weekStart];
    if ($isCounselor && $counselorId) {
        $sessFilter .= " AND n.counselor_id = ?";
        $sessParams[] = $counselorId;
    }
    $stmt = $db->prepare("SELECT COALESCE(SUM(n.session_duration_minutes), 0) FROM gm_counselor_notes n WHERE {$sessFilter}");
    $stmt->execute($sessParams);
    $stats['session_hours_week'] = round((int)$stmt->fetchColumn() / 60, 1);

    $stmt = $db->prepare("SELECT COUNT(*) FROM gm_cases WHERE next_followup_date < ? AND next_followup_date IS NOT NULL AND status IN ('open', 'in_progress') AND {$caseFilter}");
    $stmt->execute(array_merge([$today], $caseParams));
    $stats['overdue_followups'] = (int)$stmt->fetchColumn();

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/stats-cards.disyl', [
        'stats' => $stats,
        'base_url' => '/admin/guidance',
    ]);
}

function apiGuidanceRecentCases(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $role = is_array($user) ? (string)($user['role'] ?? '') : '';
    $isCounselor = $role === 'counselor';
    $counselorId = $isCounselor && is_array($user) ? (int)($user['id'] ?? 0) : null;

    $db = guidanceDb();
    $filter = "deleted_at IS NULL";
    $params = [];
    if ($isCounselor && $counselorId) {
        $filter .= " AND counselor_id = ?";
        $params[] = $counselorId;
    }

    $stmt = $db->prepare(
        "SELECT id, case_number, student_name, student_id, status, severity, category, presenting_issue, updated_at\n"
        . "FROM gm_cases\n"
        . "WHERE {$filter}\n"
        . "ORDER BY updated_at DESC\n"
        . "LIMIT 5"
    );
    $stmt->execute($params);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/recent-cases.disyl', [
        'cases' => $cases,
        'base_url' => '/admin/guidance',
    ]);
}

function apiGuidanceCases(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $ctxUser = is_array($user) ? $user : [];
    $role = (string)($ctxUser['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($ctxUser['id'] ?? 0);
    $input = guidanceInput();

    $db = guidanceDb();

    try {
        $where = [];
        $params = [];

        $showDeleted = (string)($input['show_deleted'] ?? '');
        if ($showDeleted === 'only') {
            $where[] = 'c.deleted_at IS NOT NULL';
        } elseif ($showDeleted === 'all') {
            // no filter
        } else {
            $where[] = 'c.deleted_at IS NULL';
        }

        if ($isCounselor) {
            $assignedCollegesStmt = $db->prepare('SELECT college_id FROM gm_counselor_assignments WHERE counselor_id = ? AND is_active = 1');
            $assignedCollegesStmt->execute([$userId]);
            $collegeIds = $assignedCollegesStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($collegeIds)) {
                $placeholders = implode(',', array_fill(0, count($collegeIds), '?'));
                $where[] = "(c.counselor_id = ? OR c.college_id IN ({$placeholders}))";
                $params[] = $userId;
                $params = array_merge($params, $collegeIds);
            } else {
                $where[] = 'c.counselor_id = ?';
                $params[] = $userId;
            }
        }

        if (!empty($input['status'])) {
            $where[] = 'c.status = ?';
            $params[] = (string)$input['status'];
        }
        if (!empty($input['severity'])) {
            $where[] = 'c.severity = ?';
            $params[] = (string)$input['severity'];
        }
        if (!empty($input['category'])) {
            $where[] = 'c.category = ?';
            $params[] = (string)$input['category'];
        }
        if (!empty($input['search'])) {
            $where[] = '(c.student_name LIKE ? OR c.case_number LIKE ? OR c.presenting_issue LIKE ?)';
            $search = '%' . (string)$input['search'] . '%';
            $params = array_merge($params, [$search, $search, $search]);
        }
        if (!empty($input['counselor_id']) && !$isCounselor) {
            $where[] = 'c.counselor_id = ?';
            $params[] = (int)$input['counselor_id'];
        }

        $whereClause = implode(' AND ', $where);
        if ($whereClause === '') {
            $whereClause = '1=1';
        }

        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(100, max(10, (int)($input['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $countStmt = $db->prepare("SELECT COUNT(*) FROM gm_cases c WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT c.*, CONCAT(u.first_name, ' ', u.last_name) as counselor_name,\n"
            . "       col.code as college_code, col.name as college_name\n"
            . "FROM gm_cases c\n"
            . "LEFT JOIN gm_users u ON c.counselor_id = u.id\n"
            . "LEFT JOIN gm_colleges col ON c.college_id = col.id\n"
            . "WHERE {$whereClause}\n"
            . "ORDER BY c.is_urgent DESC, c.created_at DESC\n"
            . "LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counselorIds = array_unique(array_filter(array_column($cases, 'counselor_id')));
        $counselorCollegeMap = [];
        if (!empty($counselorIds)) {
            $ph = implode(',', array_fill(0, count($counselorIds), '?'));
            $caStmt = $db->prepare(
                "SELECT ca.counselor_id, GROUP_CONCAT(col.code ORDER BY col.sort_order SEPARATOR ', ') as codes,\n"
                . "       GROUP_CONCAT(col.name ORDER BY col.sort_order SEPARATOR ', ') as names\n"
                . "FROM gm_counselor_assignments ca\n"
                . "JOIN gm_colleges col ON ca.college_id = col.id AND col.is_active = 1\n"
                . "WHERE ca.counselor_id IN ({$ph}) AND ca.is_active = 1\n"
                . "GROUP BY ca.counselor_id"
            );
            $caStmt->execute(array_values($counselorIds));
            foreach ($caStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counselorCollegeMap[$row['counselor_id']] = ['codes' => $row['codes'], 'names' => $row['names']];
            }
        }
        foreach ($cases as &$caseRow) {
            if (empty($caseRow['college_code']) && !empty($caseRow['counselor_id']) && isset($counselorCollegeMap[$caseRow['counselor_id']])) {
                $caseRow['college_code'] = $counselorCollegeMap[$caseRow['counselor_id']]['codes'];
                $caseRow['college_name'] = $counselorCollegeMap[$caseRow['counselor_id']]['names'];
            }
            $caseRow['college_codes'] = !empty($caseRow['college_code'])
                ? array_map('trim', explode(',', (string)$caseRow['college_code']))
                : [];
        }
        unset($caseRow);

        $statRoleWhere = ['c.deleted_at IS NULL'];
        $statRoleParams = [];
        if ($isCounselor) {
            $statRoleWhere[] = 'c.counselor_id = ?';
            $statRoleParams[] = $userId;
        }
        $statRoleStr = implode(' AND ', $statRoleWhere);

        $statsStmt = $db->prepare(
            "SELECT\n"
            . "COUNT(*) AS total_cases,\n"
            . "SUM(CASE WHEN c.status = 'open' THEN 1 ELSE 0 END) AS open_count,\n"
            . "SUM(CASE WHEN c.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,\n"
            . "SUM(CASE WHEN c.status = 'on_hold' THEN 1 ELSE 0 END) AS on_hold_count,\n"
            . "SUM(CASE WHEN c.status = 'closed' THEN 1 ELSE 0 END) AS closed_count,\n"
            . "SUM(CASE WHEN c.severity IN ('critical','high') THEN 1 ELSE 0 END) AS high_severity_count,\n"
            . "SUM(CASE WHEN c.is_urgent = 1 AND c.status != 'closed' THEN 1 ELSE 0 END) AS urgent_count\n"
            . "FROM gm_cases c WHERE {$statRoleStr}"
        );
        $statsStmt->execute($statRoleParams);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        $stats = is_array($stats) ? array_map('intval', $stats) : [];

        $totalPages = (int)ceil($total / $limit);
        $pagination = [
            'total' => $total,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'from' => $total > 0 ? ($offset + 1) : 0,
            'to' => min($offset + $limit, $total),
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'prev_page' => $page - 1,
            'next_page' => $page + 1,
        ];

        header('Content-Type: text/html; charset=utf-8');
        echo guidanceRender('modules/guidance/partials/cases-table.disyl', [
            'cases' => $cases,
            'stats' => $stats,
            'pagination' => $pagination,
            'base_url' => '/admin/guidance',
        ]);
    } catch (Throwable $e) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(500);
        echo '<div class="p-6 text-sm text-red-600">Failed to fetch cases</div>';
    }
}

function apiGuidancePendingAppointments(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $role = is_array($user) ? (string)($user['role'] ?? '') : '';
    $isCounselor = $role === 'counselor';
    $counselorId = $isCounselor && is_array($user) ? (int)($user['id'] ?? 0) : null;

    $db = guidanceDb();

    $whereClause = "a.status = 'pending'";
    $qParams = [];
    if ($isCounselor && $counselorId) {
        $whereClause .= " AND a.counselor_id = ?";
        $qParams[] = $counselorId;
    }

    $stmt = $db->prepare(
        "SELECT a.*, t.name as type_name, t.duration_minutes\n"
        . "FROM gm_appointments a\n"
        . "LEFT JOIN gm_appointment_types t ON a.appointment_type_id = t.id\n"
        . "WHERE {$whereClause}\n"
        . "ORDER BY a.scheduled_date ASC, a.scheduled_time ASC\n"
        . "LIMIT 10"
    );
    $stmt->execute($qParams);
    $pendingAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/pending-approvals-widget.disyl', [
        'pending_appointments' => $pendingAppointments,
        'base_url' => '/admin/guidance',
    ]);
}

function apiGuidanceApproveAppointment(array $params): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = is_array($user) ? (string)($user['role'] ?? '') : '';
    $userId = is_array($user) ? (int)($user['id'] ?? 0) : 0;

    $apptId = (int)($params['id'] ?? 0);
    if ($apptId < 1) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid appointment id']);
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare("SELECT id, counselor_id, status FROM gm_appointments WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $apptId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Appointment not found']);
        return;
    }

    if ($role === 'counselor' && (int)($row['counselor_id'] ?? 0) !== $userId) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        return;
    }

    $status = (string)($row['status'] ?? '');
    if ($status !== 'pending' && $status !== 'requested') {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Appointment is not pending']);
        return;
    }

    $upd = $db->prepare(
        "UPDATE gm_appointments\n"
        . "SET status = 'confirmed', approved_at = NOW(), approved_by = :uid, rejected_at = NULL, rejected_by = NULL, rejection_reason = NULL\n"
        . "WHERE id = :id"
    );
    $upd->execute([':uid' => $userId, ':id' => $apptId]);

    if (guidanceIsHtmx()) {
        guidanceHtmxResponse([
            'trigger' => json_encode(['approvalChanged' => ['id' => $apptId, 'action' => 'approved']]),
        ]);
        header('Content-Type: text/plain; charset=utf-8');
        echo '';
        return;
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}

function apiGuidanceRejectAppointment(array $params): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = is_array($user) ? (string)($user['role'] ?? '') : '';
    $userId = is_array($user) ? (int)($user['id'] ?? 0) : 0;

    $apptId = (int)($params['id'] ?? 0);
    if ($apptId < 1) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid appointment id']);
        return;
    }

    $reason = '';
    $input = guidanceInput();
    if (is_array($input)) {
        $reason = trim((string)($input['reason'] ?? ''));
    }

    $db = guidanceDb();
    $stmt = $db->prepare("SELECT id, counselor_id, status FROM gm_appointments WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $apptId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Appointment not found']);
        return;
    }

    if ($role === 'counselor' && (int)($row['counselor_id'] ?? 0) !== $userId) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        return;
    }

    $status = (string)($row['status'] ?? '');
    if ($status !== 'pending' && $status !== 'requested') {
        header('Content-Type: application/json');
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Appointment is not pending']);
        return;
    }

    $upd = $db->prepare(
        "UPDATE gm_appointments\n"
        . "SET status = 'rejected', rejected_at = NOW(), rejected_by = :uid, rejection_reason = :reason\n"
        . "WHERE id = :id"
    );
    $upd->execute([':uid' => $userId, ':reason' => $reason !== '' ? $reason : null, ':id' => $apptId]);

    if (guidanceIsHtmx()) {
        guidanceHtmxResponse([
            'trigger' => json_encode(['approvalChanged' => ['id' => $apptId, 'action' => 'rejected']]),
        ]);
        header('Content-Type: text/plain; charset=utf-8');
        echo '';
        return;
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}
