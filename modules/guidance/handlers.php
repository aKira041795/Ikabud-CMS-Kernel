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

function guidanceGenerateCaseNumber(\Ikabud\Kernel\Contracts\DatabaseContract $db): string
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

function guidanceAllowedCaseCategories(): array
{
    return ['general', 'academic', 'behavioral', 'emotional', 'family', 'peer', 'career', 'crisis', 'special_needs', 'substance', 'other'];
}

function guidanceAllowedCaseSeverityLevels(): array
{
    return ['low', 'medium', 'high', 'critical'];
}

function guidanceNormalizeCaseReferralSource(?string $value): string
{
    $value = strtolower(trim((string)$value));
    return in_array($value, ['walk-in', 'follow-up', 'referred'], true) ? $value : 'walk-in';
}

function guidanceCounselorExists(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $counselorId): bool
{
    if ($counselorId < 1) {
        return false;
    }

    $stmt = $db->prepare("SELECT id FROM gm_users WHERE id = ? AND role = 'counselor' AND deleted_at IS NULL AND is_active = 1 LIMIT 1");
    $stmt->execute([$counselorId]);
    return (int)($stmt->fetchColumn() ?: 0) > 0;
}

function guidanceBuildCaseRecordPayload(array $input, int $counselorId, int $userId, bool $hasStudentStatus): array
{
    $studentEmail = trim((string)($input['student_email'] ?? ''));
    if ($studentEmail !== '' && !filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please enter a valid email address.');
    }

    $category = strtolower(trim((string)($input['category'] ?? 'general')));
    if (!in_array($category, guidanceAllowedCaseCategories(), true)) {
        $category = 'general';
    }

    $severity = strtolower(trim((string)($input['severity'] ?? 'medium')));
    if (!in_array($severity, guidanceAllowedCaseSeverityLevels(), true)) {
        $severity = 'medium';
    }

    $payload = [
        'student_id' => trim((string)($input['student_id'] ?? '')),
        'student_name' => trim((string)($input['student_name'] ?? '')),
        'student_grade' => trim((string)($input['student_grade'] ?? '')) ?: null,
        'student_section' => trim((string)($input['student_section'] ?? '')) ?: null,
        'date_of_birth' => trim((string)($input['date_of_birth'] ?? '')) ?: null,
        'gender' => trim((string)($input['gender'] ?? '')) ?: null,
        'nationality' => trim((string)($input['nationality'] ?? '')) ?: null,
        'civil_status' => trim((string)($input['civil_status'] ?? '')) ?: null,
        'address' => trim((string)($input['address'] ?? '')) ?: null,
        'student_mobile' => trim((string)($input['student_mobile'] ?? ($input['client_number'] ?? ''))) ?: null,
        'student_email' => $studentEmail !== '' ? $studentEmail : null,
        'college_id' => !empty($input['college_id']) ? (int)$input['college_id'] : null,
        'counselor_id' => $counselorId,
        'category' => $category,
        'severity' => $severity,
        'presenting_issue' => trim((string)($input['presenting_issue'] ?? '')),
        'background_info' => trim((string)($input['background_info'] ?? '')) ?: null,
        'is_urgent' => !empty($input['is_urgent']) ? 1 : 0,
        'is_confidential' => !empty($input['is_confidential']) ? 1 : 0,
        'parent_guardian_name' => trim((string)($input['parent_guardian_name'] ?? '')) ?: null,
        'parent_guardian_contact' => trim((string)($input['parent_guardian_contact'] ?? '')) ?: null,
        'emergency_contact_address' => trim((string)($input['emergency_contact_address'] ?? '')) ?: null,
        'referral_source' => guidanceNormalizeCaseReferralSource((string)($input['referral_source'] ?? 'walk-in')),
        'referred_by' => trim((string)($input['referred_by'] ?? '')) ?: null,
        'sync_id' => trim((string)($input['sync_id'] ?? '')) ?: uniqid('sync_', true),
        'created_by' => $userId,
        'last_modified_by' => $userId,
    ];

    if ($hasStudentStatus) {
        $payload['student_status'] = trim((string)($input['student_status'] ?? '')) ?: null;
    }

    return $payload;
}

function guidanceInsertCaseStatusHistory(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $caseId, ?string $previousStatus, string $newStatus, int $changedBy, ?string $notes = null): void
{
    $stmt = $db->prepare(
        'INSERT INTO gm_case_status_history (case_id, previous_status, new_status, changed_by, notes, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$caseId, $previousStatus, $newStatus, $changedBy > 0 ? $changedBy : null, $notes]);
}

function guidanceLogAudit(\Ikabud\Kernel\Contracts\DatabaseContract $db, string $action, string $tableName, int $recordId, ?array $oldData, ?array $newData, int $userId): void
{
    try {
        $db->prepare(
            'INSERT INTO gm_audit_logs (action, table_name, record_id, old_data, new_data, user_id, ip_address, user_agent, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $action,
            $tableName,
            $recordId > 0 ? $recordId : null,
            $oldData !== null ? json_encode($oldData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $newData !== null ? json_encode($newData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $userId > 0 ? $userId : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        app()->log('Audit log error: ' . $e->getMessage(), 'error');
    }
}

function guidanceCreateCaseInitialAppointment(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $caseId, array $caseData, array $input, int $userId): int
{
    $date = trim((string)($input['appointment_date'] ?? ''));
    $time = trim((string)($input['appointment_time'] ?? ''));
    $appointmentTypeId = (int)($input['appointment_type_id'] ?? 0);

    if ($date === '' || $time === '' || $appointmentTypeId < 1) {
        throw new RuntimeException('Initial appointment date, time, and session type are required.');
    }

    $typeStmt = $db->prepare('SELECT id, code, duration_minutes FROM gm_appointment_types WHERE id = ? AND is_active = 1 LIMIT 1');
    $typeStmt->execute([$appointmentTypeId]);
    $typeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($typeRow)) {
        throw new RuntimeException('Selected session type is invalid.');
    }

    $counselorId = (int)($caseData['counselor_id'] ?? 0);
    $duration = max(10, (int)($typeRow['duration_minutes'] ?? 30));
    if (guidanceAppointmentConflict($db, $counselorId, $date, $time, $duration)) {
        throw new RuntimeException('Time slot is not available.');
    }

    $purpose = trim((string)($input['appointment_purpose'] ?? ''));
    $stmt = $db->prepare(
        "INSERT INTO gm_appointments (case_id, counselor_id, student_name, student_email, student_phone, student_college_id, student_year_level,\n"
        . "scheduled_date, scheduled_time, duration_minutes, appointment_type, appointment_type_id, purpose, notes, location, status, created_by, last_modified_by, created_at, updated_at)\n"
        . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->execute([
        $caseId,
        $counselorId,
        (string)($caseData['student_name'] ?? ''),
        (string)($caseData['student_email'] ?? ''),
        (string)($caseData['student_mobile'] ?? ''),
        ($caseData['college_id'] ?? null),
        (string)($caseData['student_grade'] ?? ''),
        $date,
        $time,
        $duration,
        guidanceNormalizeAppointmentTypeValue((string)($typeRow['code'] ?? 'individual')),
        $appointmentTypeId,
        ($purpose !== '' ? $purpose : 'Initial Consultation'),
        null,
        null,
        'scheduled',
        $userId,
        $userId,
    ]);

    $appointmentId = (int)$db->lastInsertId();
    $clientNumber = trim((string)($caseData['student_mobile'] ?? ''));
    guidanceFireEvent('guidance.appointment.created', [
        'to' => $clientNumber,
        'appointment_id' => $appointmentId,
        'date' => $date,
        'time' => $time,
        'student_name' => (string)($caseData['student_name'] ?? ''),
        'recipient_name' => (string)($caseData['student_name'] ?? ''),
        'trigger_ref_id' => (string)$appointmentId,
    ]);

    return $appointmentId;
}

function apiGuidanceCreateCase(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    app()->csrfEnforce();
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);

    $input = guidanceInput();
    if (!is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }

    $validationErrors = guidanceValidateFormInput('case', $input);
    foreach (['appointment_type_id', 'appointment_date', 'appointment_time'] as $fieldName) {
        if (!array_key_exists($fieldName, $input) || trim((string)$input[$fieldName]) === '') {
            $validationErrors[] = ucfirst(str_replace('_', ' ', $fieldName)) . ' is required';
        }
    }
    if ($validationErrors !== []) {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $validationErrors[0], 'type' => 'error']]));
        echo '';
        return;
    }

    $db = guidanceDb();
    $hasStudentStatus = studentStatusCasesColumnExists($db);
    $counselorId = $isCounselor ? $userId : (int)($input['counselor_id'] ?? 0);

    try {
        if (!guidanceCounselorExists($db, $counselorId)) {
            http_response_code(422);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Selected counselor is invalid', 'type' => 'error']]));
            echo '';
            return;
        }

        $caseData = guidanceBuildCaseRecordPayload($input, $counselorId, $userId, $hasStudentStatus);

        $attempts = 0;
        do {
            $attempts++;
            $caseNumber = guidanceGenerateCaseNumber($db);
            try {
                $columns = ['case_number'];
                if ($hasStudentStatus) {
                    $columns = array_merge($columns, ['student_id', 'student_name', 'student_grade', 'student_status']);
                    $remainingColumns = ['student_section', 'date_of_birth', 'gender', 'nationality', 'civil_status', 'address', 'student_mobile', 'student_email', 'college_id', 'counselor_id', 'category', 'severity', 'presenting_issue', 'background_info', 'is_urgent', 'is_confidential', 'parent_guardian_name', 'parent_guardian_contact', 'emergency_contact_address', 'referral_source', 'referred_by', 'sync_id', 'created_by', 'last_modified_by'];
                    $columns = array_merge($columns, $remainingColumns);
                } else {
                    $columns = array_merge($columns, ['student_id', 'student_name', 'student_grade']);
                    $remainingColumns = ['student_section', 'date_of_birth', 'gender', 'nationality', 'civil_status', 'address', 'student_mobile', 'student_email', 'college_id', 'counselor_id', 'category', 'severity', 'presenting_issue', 'background_info', 'is_urgent', 'is_confidential', 'parent_guardian_name', 'parent_guardian_contact', 'emergency_contact_address', 'referral_source', 'referred_by', 'sync_id', 'created_by', 'last_modified_by'];
                    $columns = array_merge($columns, $remainingColumns);
                }

                $values = [$caseNumber];
                foreach (array_slice($columns, 1) as $column) {
                    $values[] = $caseData[$column] ?? null;
                }

                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $db->beginTransaction();
                $stmt = $db->prepare(
                    'INSERT INTO gm_cases (' . implode(', ', $columns) . ', created_at, updated_at) VALUES (' . $placeholders . ', NOW(), NOW())'
                );
                $stmt->execute($values);

                $caseId = (int)$db->lastInsertId();
                guidanceInsertCaseStatusHistory($db, $caseId, null, 'open', $userId, 'Case created');
                $appointmentId = guidanceCreateCaseInitialAppointment($db, $caseId, $caseData, $input, $userId);
                guidanceLogAudit($db, 'case.created', 'gm_cases', $caseId, null, array_merge($input, [
                    'case_number' => $caseNumber,
                    'appointment_id' => $appointmentId,
                ]), $userId);
                $db->commit();

                header('HX-Trigger: ' . json_encode([
                    'showToast' => ['message' => 'Case created successfully with the initial appointment scheduled', 'type' => 'success'],
                    'closeModal' => true,
                    'refreshCases' => true,
                ]));

                if (guidanceIsHtmx()) {
                    header('HX-Redirect: /admin/guidance/cases/' . $caseId);
                    echo '';
                    return;
                }

                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'id' => $caseId,
                        'case_number' => $caseNumber,
                        'appointment_id' => $appointmentId,
                    ],
                ], JSON_UNESCAPED_SLASHES);
                return;
            } catch (RuntimeException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $message = $e->getMessage();
                $status = str_contains(strtolower($message), 'time slot') ? 409 : 422;
                http_response_code($status);
                header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $message, 'type' => 'error']]));
                echo '';
                return;
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($attempts >= 3 || stripos($e->getMessage(), 'Duplicate') === false) {
                    throw $e;
                }
            }
        } while ($attempts < 3);

        throw new RuntimeException('Failed to generate unique case number');
    } catch (Throwable $e) {
        app()->log('Cases create error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create case', 'type' => 'error']]));
        echo '';
    }
}

function apiGuidanceUpdateCase(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    app()->csrfEnforce();

    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor';
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);
    $input = guidanceInput();

    if ($caseId < 1 || !is_array($input)) {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid case update request', 'type' => 'error']]));
        echo '';
        return;
    }

    $db = guidanceDb();
    $hasStudentStatus = studentStatusCasesColumnExists($db);

    $stmt = $db->prepare('SELECT * FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$caseId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($existing)) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case not found', 'type' => 'error']]));
        echo '';
        return;
    }

    if ($isCounselor && (int)($existing['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Access denied', 'type' => 'error']]));
        echo '';
        return;
    }

    $validationErrors = guidanceValidateFormInput('case', $input);
    if ($validationErrors !== []) {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $validationErrors[0], 'type' => 'error']]));
        echo '';
        return;
    }

    $counselorId = $isCounselor ? $userId : (int)($input['counselor_id'] ?? ($existing['counselor_id'] ?? 0));
    if (!guidanceCounselorExists($db, $counselorId)) {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Selected counselor is invalid', 'type' => 'error']]));
        echo '';
        return;
    }

    try {
        $caseData = guidanceBuildCaseRecordPayload($input, $counselorId, $userId, $hasStudentStatus);
        $allowedColumns = ['student_id', 'student_name', 'student_grade', 'student_section', 'date_of_birth', 'gender', 'nationality', 'civil_status', 'address', 'student_mobile', 'student_email', 'college_id', 'counselor_id', 'category', 'severity', 'presenting_issue', 'background_info', 'is_urgent', 'is_confidential', 'parent_guardian_name', 'parent_guardian_contact', 'emergency_contact_address', 'referral_source', 'referred_by'];
        if ($hasStudentStatus) {
            $allowedColumns[] = 'student_status';
        }

        $enabledFieldMap = [];
        foreach (guidanceGetFormFields('case') as $field) {
            $name = (string)($field['field_name'] ?? '');
            if ($name !== '') {
                $enabledFieldMap[$name] = $field;
            }
        }

        $updates = [];
        $values = [];
        foreach ($allowedColumns as $column) {
            $shouldUpdate = array_key_exists($column, $input);

            if (!$shouldUpdate && isset($enabledFieldMap[$column]) && (string)($enabledFieldMap[$column]['field_type'] ?? '') === 'checkbox') {
                $shouldUpdate = true;
            }

            if (!$shouldUpdate) {
                continue;
            }

            $updates[] = $column . ' = ?';
            $values[] = $caseData[$column] ?? null;
        }
        $updates[] = 'last_modified_by = ?';
        $values[] = $userId;
        $updates[] = 'updated_at = NOW()';
        $updates[] = 'version = version + 1';
        $values[] = $caseId;

        $updateStmt = $db->prepare('UPDATE gm_cases SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $updateStmt->execute($values);
        guidanceLogAudit($db, 'case.updated', 'gm_cases', $caseId, $existing, $input, $userId);
    } catch (RuntimeException $e) {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $e->getMessage(), 'type' => 'error']]));
        echo '';
        return;
    } catch (Throwable $e) {
        app()->log('Cases update error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to update case', 'type' => 'error']]));
        echo '';
        return;
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case updated successfully', 'type' => 'success'], 'closeModal' => true, 'refreshCases' => true]));
        header('HX-Refresh: true');
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceDeleteCase(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor']);
    app()->csrfEnforce();

    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);
    if ($caseId < 1) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case not found', 'type' => 'error']]));
        echo '';
        return;
    }

    $db = guidanceDb();

    try {
        $stmt = $db->prepare('UPDATE gm_cases SET deleted_at = NOW(), deleted_by = ?, last_modified_by = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$userId, $userId, $caseId]);
        if ($stmt->rowCount() < 1) {
            http_response_code(404);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case not found', 'type' => 'error']]));
            echo '';
            return;
        }
        guidanceLogAudit($db, 'case.deleted', 'gm_cases', $caseId, null, null, $userId);
    } catch (Throwable $e) {
        app()->log('Cases delete error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to delete case', 'type' => 'error']]));
        echo '';
        return;
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case deleted successfully', 'type' => 'success'], 'refreshCases' => true]));
        header('HX-Redirect: /admin/guidance/cases');
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceCloseCase(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    app()->csrfEnforce();

    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);
    $input = guidanceInput();
    $resolutionSummary = is_array($input)
        ? trim((string)($input['resolution_summary'] ?? 'Case closed'))
        : 'Case closed';

    $db = guidanceDb();
    $stmt = $db->prepare('SELECT id, counselor_id, status FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case not found', 'type' => 'error']]));
        echo '';
        return;
    }
    if ($role === 'counselor' && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Access denied', 'type' => 'error']]));
        echo '';
        return;
    }
    if ((string)($case['status'] ?? '') === 'closed') {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case is already closed', 'type' => 'error']]));
        echo '';
        return;
    }

    try {
        $db->beginTransaction();
        $updateStmt = $db->prepare('UPDATE gm_cases SET status = ?, resolution_summary = ?, closed_at = NOW(), closed_by = ?, last_modified_by = ?, updated_at = NOW(), version = version + 1 WHERE id = ?');
        $updateStmt->execute(['closed', $resolutionSummary !== '' ? $resolutionSummary : 'Case closed', $userId, $userId, $caseId]);
        guidanceInsertCaseStatusHistory($db, $caseId, (string)($case['status'] ?? 'open'), 'closed', $userId, $resolutionSummary !== '' ? $resolutionSummary : 'Case closed');
        guidanceLogAudit($db, 'case.closed', 'gm_cases', $caseId, null, [
            'resolution_summary' => $resolutionSummary,
        ], $userId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        app()->log('Cases close error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to close case', 'type' => 'error']]));
        echo '';
        return;
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case closed successfully', 'type' => 'success'], 'refreshCases' => true]));
        header('HX-Refresh: true');
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceReopenCase(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor']);
    app()->csrfEnforce();

    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);
    $input = guidanceInput();
    $reason = is_array($input) ? trim((string)($input['reason'] ?? 'Case reopened')) : 'Case reopened';

    $db = guidanceDb();
    $stmt = $db->prepare('SELECT id, status FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case) || (string)($case['status'] ?? '') !== 'closed') {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case not found or not closed', 'type' => 'error']]));
        echo '';
        return;
    }

    try {
        $db->beginTransaction();
        $updateStmt = $db->prepare('UPDATE gm_cases SET status = ?, closed_at = NULL, closed_by = NULL, last_modified_by = ?, updated_at = NOW(), version = version + 1 WHERE id = ?');
        $updateStmt->execute(['in_progress', $userId, $caseId]);
        guidanceInsertCaseStatusHistory($db, $caseId, 'closed', 'in_progress', $userId, $reason !== '' ? $reason : 'Case reopened');
        guidanceLogAudit($db, 'case.reopened', 'gm_cases', $caseId, null, [
            'reason' => $reason,
        ], $userId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        app()->log('Cases reopen error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to reopen case', 'type' => 'error']]));
        echo '';
        return;
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case reopened successfully', 'type' => 'success'], 'refreshCases' => true]));
        header('HX-Refresh: true');
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
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

function guidanceAppointmentConflict(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $counselorId, string $date, string $time, int $durationMinutes, int $excludeId = 0): bool
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

function guidanceNormalizeAppointmentTypeValue(?string $value): string
{
    $type = strtolower(trim((string)$value));
    return in_array($type, ['individual', 'group', 'parent', 'teacher', 'crisis', 'followup'], true)
        ? $type
        : 'individual';
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
    $date = (string)($input['scheduled_date'] ?? '');
    $time = (string)($input['scheduled_time'] ?? '');
    $duration = (int)($input['duration_minutes'] ?? 60);
    $appointmentTypeId = (int)($input['appointment_type_id'] ?? 0);
    $appointmentType = guidanceNormalizeAppointmentTypeValue((string)($input['appointment_type'] ?? 'individual'));
    $purpose = (string)($input['purpose'] ?? 'counseling');
    $notes = (string)($input['notes'] ?? '');
    $notes = guidanceEditorSanitizeHtml(guidanceEditorNormalizeHtml($notes, 'guidance.session'), 'guidance.session');
    $location = (string)($input['location'] ?? '');
    $status = (string)($input['status'] ?? 'scheduled');
    if (!in_array($status, ['pending', 'scheduled', 'confirmed'], true)) {
        $status = 'scheduled';
    }

    if ($caseId < 1 || $date === '' || $time === '') {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case, date, and time are required', 'type' => 'error']]));
        echo '';
        return;
    }

    $db = guidanceDb();

    try {
        $caseStmt = $db->prepare("SELECT student_name, student_email, student_mobile, college_id, student_grade, counselor_id FROM gm_cases WHERE id = ? LIMIT 1");
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($case)) {
            http_response_code(422);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid case', 'type' => 'error']]));
            echo '';
            return;
        }

        $caseCounselorId = (int)($case['counselor_id'] ?? 0);
        $counselorId = $isCounselor ? $userId : (int)($input['counselor_id'] ?? $caseCounselorId);

        if ($isCounselor && $caseCounselorId > 0 && $caseCounselorId !== $userId) {
            http_response_code(403);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Access denied', 'type' => 'error']]));
            echo '';
            return;
        }

        if ($counselorId < 1) {
            http_response_code(422);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Assign a counselor to the case before scheduling an appointment', 'type' => 'error']]));
            echo '';
            return;
        }

        if ($appointmentTypeId > 0) {
            $typeStmt = $db->prepare('SELECT name, duration_minutes FROM gm_appointment_types WHERE id = ? AND is_active = 1 LIMIT 1');
            $typeStmt->execute([$appointmentTypeId]);
            $type = $typeStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($type)) {
                http_response_code(422);
                header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid session type', 'type' => 'error']]));
                echo '';
                return;
            }
            $duration = max(10, (int)($type['duration_minutes'] ?? $duration));
        } else {
            $appointmentTypeId = 0;
        }

        if (guidanceAppointmentConflict($db, $counselorId, $date, $time, $duration)) {
            http_response_code(409);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Time slot is not available', 'type' => 'error']]));
            echo '';
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO gm_appointments (case_id, counselor_id, student_name, student_email, student_phone, student_college_id, student_year_level,\n"
            . "scheduled_date, scheduled_time, duration_minutes, appointment_type, appointment_type_id, purpose, notes, location, status, created_by, last_modified_by, created_at, updated_at)\n"
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
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
            $appointmentType,
            ($appointmentTypeId > 0 ? $appointmentTypeId : null),
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
    $stmt = $db->prepare("SELECT id, counselor_id, appointment_type, appointment_type_id, duration_minutes FROM gm_appointments WHERE id = ? LIMIT 1");
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
    $date = (string)($input['scheduled_date'] ?? '');
    $time = (string)($input['scheduled_time'] ?? '');
    $duration = (int)($input['duration_minutes'] ?? 60);
    $appointmentTypeId = array_key_exists('appointment_type_id', $input)
        ? (int)($input['appointment_type_id'] ?? 0)
        : (int)($existing['appointment_type_id'] ?? 0);
    $appointmentType = guidanceNormalizeAppointmentTypeValue((string)($input['appointment_type'] ?? ($existing['appointment_type'] ?? 'individual')));
    $purpose = (string)($input['purpose'] ?? 'counseling');
    $notes = (string)($input['notes'] ?? '');
    $notes = guidanceEditorSanitizeHtml(guidanceEditorNormalizeHtml($notes, 'guidance.session'), 'guidance.session');
    $location = (string)($input['location'] ?? '');
    $status = trim((string)($input['status'] ?? ''));
    if ($status !== '' && !in_array($status, ['pending', 'requested', 'confirmed', 'scheduled', 'rescheduled', 'completed', 'cancelled', 'no_show', 'rejected', 'waitlist'], true)) {
        $status = '';
    }

    if ($caseId < 1 || $date === '' || $time === '') {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case, date, and time are required', 'type' => 'error']]));
        echo '';
        return;
    }

    try {
        $caseStmt = $db->prepare("SELECT student_name, student_email, student_mobile, college_id, student_grade, counselor_id FROM gm_cases WHERE id = ? LIMIT 1");
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($case)) {
            http_response_code(422);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid case', 'type' => 'error']]));
            echo '';
            return;
        }

        $caseCounselorId = (int)($case['counselor_id'] ?? 0);
        $counselorId = $isCounselor ? $userId : (int)($input['counselor_id'] ?? ($caseCounselorId > 0 ? $caseCounselorId : ($existing['counselor_id'] ?? 0)));

        if ($isCounselor && $caseCounselorId > 0 && $caseCounselorId !== $userId) {
            http_response_code(403);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Access denied', 'type' => 'error']]));
            echo '';
            return;
        }

        if ($counselorId < 1) {
            http_response_code(422);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Assign a counselor to the case before scheduling an appointment', 'type' => 'error']]));
            echo '';
            return;
        }

        if ($appointmentTypeId > 0) {
            $typeStmt = $db->prepare('SELECT name, duration_minutes FROM gm_appointment_types WHERE id = ? LIMIT 1');
            $typeStmt->execute([$appointmentTypeId]);
            $type = $typeStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($type)) {
                http_response_code(422);
                header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid session type', 'type' => 'error']]));
                echo '';
                return;
            }
            $duration = max(10, (int)($type['duration_minutes'] ?? $duration));
        } else {
            $appointmentTypeId = 0;
        }

        if (guidanceAppointmentConflict($db, $counselorId, $date, $time, $duration, $id)) {
            http_response_code(409);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Time slot is not available', 'type' => 'error']]));
            echo '';
            return;
        }

        $sql = "UPDATE gm_appointments SET case_id = ?, counselor_id = ?, student_name = ?, student_email = ?, student_phone = ?, student_college_id = ?, student_year_level = ?,\n"
            . "scheduled_date = ?, scheduled_time = ?, duration_minutes = ?, appointment_type = ?, appointment_type_id = ?, purpose = ?, notes = ?, location = ?";
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
            $appointmentType,
            ($appointmentTypeId > 0 ? $appointmentTypeId : null),
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

function guidanceSetAppointmentStatus(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $id, string $newStatus, int $byUserId, array $allowedStatuses = []): bool
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
        'base_url' => '/guidance',
    ]);
}

function guidanceBuildAuthSessionPayload(array $authRow, string $fallbackEmail): array
{
    $role = (string)($authRow['role'] ?? '');
    $userId = (int)($authRow['id'] ?? 0);
    if ($userId < 1 || $role === '') {
        throw new RuntimeException('Invalid authentication payload.');
    }

    return [
        'sub' => $role . ':' . $userId,
        'id' => $userId,
        'username' => (string)($authRow['username'] ?? $fallbackEmail),
        'name' => (string)($authRow['full_name'] ?? $fallbackEmail),
        'role' => $role,
        'source' => 'guidance',
    ];
}

function guidanceFinalizeAuthSession(array $payload): void
{
    $token = app()->jwt()->generate($payload);
    guidanceSetAuthCookie($token, (int)config('app.jwt.expiration', 86400));
}

function guidanceAuthenticateCredentials(string $email, string $password): ?array
{
    try {
        $authResult = app()->cap()->call('kernel.auth.authenticate@1', [
            'username' => '@guidance:' . $email,
            'password' => $password,
        ], ['mode' => 'pipeline']);
    } catch (Throwable $e) {
        throw new RuntimeException('Authentication failed.');
    }

    if (
        is_array($authResult)
        && isset($authResult['user'])
        && is_array($authResult['user'])
        && (($authResult['source'] ?? '') === 'guidance')
    ) {
        return $authResult['user'];
    }

    return null;
}

function guidanceOtpEnabled(string $settingKey): bool
{
    return guidanceIsPro() && guidanceGetSetting($settingKey, '0') === '1';
}

function guidanceOtpTicketTtlSeconds(): int
{
    return 600;
}

function guidanceOtpRequestIp(): string
{
    if (function_exists('clientIp')) {
        try {
            $ip = trim((string)clientIp());
            if ($ip !== '') {
                return $ip;
            }
        } catch (Throwable $e) {
        }
    }

    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return $ip !== '' ? $ip : 'unknown';
}

function guidanceOtpRateLimitAllowed(string $key, int $maxAttempts, int $windowSeconds): bool
{
    if (!function_exists('rateLimit')) {
        return true;
    }

    try {
        return rateLimit($key, $maxAttempts, $windowSeconds);
    } catch (Throwable $e) {
        return true;
    }
}

function guidanceOtpMaskedEmail(string $email): string
{
    $email = trim($email);
    if ($email === '' || !str_contains($email, '@')) {
        return $email;
    }

    [$local, $domain] = explode('@', $email, 2);
    $local = trim($local);
    $domain = trim($domain);
    if ($local === '') {
        return '***@' . $domain;
    }

    $visible = substr($local, 0, min(2, strlen($local)));
    return $visible . str_repeat('*', max(1, strlen($local) - strlen($visible))) . '@' . $domain;
}

function guidanceOtpTicket(string $kind, array $payload, int $ttlSeconds = 600): string
{
    $jwt = new \Ikabud\Kernel\JWT(null, max(60, $ttlSeconds));
    return $jwt->generate(array_merge($payload, [
        'otp_kind' => $kind,
        'otp_module' => 'guidance',
    ]));
}

function guidanceReadOtpTicket(string $token, string $expectedKind): ?array
{
    $payload = app()->jwt()->verify($token);
    if (!is_array($payload)) {
        return null;
    }

    if (($payload['otp_module'] ?? '') !== 'guidance') {
        return null;
    }

    if (($payload['otp_kind'] ?? '') !== $expectedKind) {
        return null;
    }

    return $payload;
}

function guidanceOtpPurposeCopy(string $purpose): array
{
    if ($purpose === 'booking') {
        return [
            'subject' => 'Your Guidance booking verification code',
            'headline' => 'Confirm Your Booking Request',
            'lead' => 'Use the verification code below to finish submitting your Guidance appointment request.',
        ];
    }

    return [
        'subject' => 'Your Guidance sign-in verification code',
        'headline' => 'Confirm Your Sign In',
        'lead' => 'Use the verification code below to finish signing in to the Guidance Monitoring System.',
    ];
}

function guidanceSendOtpEmail(string $email, string $purpose, string $code, int $ttlSeconds): void
{
    if (!function_exists('sendEmail') || !function_exists('buildEmailTemplate')) {
        throw new RuntimeException('Email delivery is not available for verification codes.');
    }

    $copy = guidanceOtpPurposeCopy($purpose);
    $minutes = max(1, (int)ceil($ttlSeconds / 60));
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $lead = htmlspecialchars($copy['lead'], ENT_QUOTES, 'UTF-8');

    $content = ''
        . '<p style="margin: 0 0 20px; color: #4b5563; font-size: 16px;">' . $lead . '</p>'
        . '<div style="margin: 0 0 24px; padding: 18px 20px; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 12px; text-align: center;">'
        . '  <div style="color: #4338ca; font-size: 12px; letter-spacing: 0.12em; text-transform: uppercase; font-weight: 700; margin-bottom: 8px;">Verification Code</div>'
        . '  <div style="font-size: 32px; line-height: 1; letter-spacing: 0.28em; font-weight: 800; color: #111827; font-family: monospace;">' . $safeCode . '</div>'
        . '</div>'
        . '<p style="margin: 0; color: #6b7280; font-size: 14px;">This code expires in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '. If you did not request it, you can ignore this email.</p>';

    $body = buildEmailTemplate($copy['headline'], $content);
    if (!sendEmail($email, $copy['subject'], $body)) {
        throw new RuntimeException('Failed to send the verification code.');
    }
}

function guidanceIssueOtpCode(string $email, string $purpose, int $ttlSeconds = 600): array
{
    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid email address is required for verification.');
    }

    $purpose = trim($purpose);
    if ($purpose === '') {
        throw new RuntimeException('Verification purpose is required.');
    }

    $db = guidanceDb();
    $db->prepare('DELETE FROM gm_otp_codes WHERE expires_at <= NOW() OR verified_at IS NOT NULL')->execute();
    $db->prepare('DELETE FROM gm_otp_codes WHERE email = ? AND purpose = ?')->execute([$normalizedEmail, $purpose]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->prepare(
        'INSERT INTO gm_otp_codes (email, code, purpose, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
    )->execute([$normalizedEmail, $code, $purpose, $ttlSeconds]);

    $otpId = (int)$db->lastInsertId();

    try {
        guidanceSendOtpEmail($normalizedEmail, $purpose, $code, $ttlSeconds);
    } catch (Throwable $e) {
        $db->prepare('DELETE FROM gm_otp_codes WHERE id = ?')->execute([$otpId]);
        throw $e;
    }

    return [
        'otp_id' => $otpId,
        'otp_email' => $normalizedEmail,
        'masked_email' => guidanceOtpMaskedEmail($normalizedEmail),
        'expires_in' => $ttlSeconds,
    ];
}

function guidanceCreateOtpChallenge(string $kind, string $email, string $purpose, array $ticketData, int $ttlSeconds = 600): array
{
    $otp = guidanceIssueOtpCode($email, $purpose, $ttlSeconds);
    $ticket = guidanceOtpTicket($kind, array_merge($ticketData, [
        'otp_id' => $otp['otp_id'],
        'otp_email' => $otp['otp_email'],
    ]), $ttlSeconds);

    return [
        'ticket' => $ticket,
        'masked_email' => $otp['masked_email'],
        'expires_in' => $otp['expires_in'],
    ];
}

function guidanceValidateOtpCode(int $otpId, string $email, string $purpose, string $code): array
{
    if ($otpId < 1) {
        throw new RuntimeException('Verification session expired. Please start again.');
    }

    $normalizedEmail = strtolower(trim($email));
    $submittedCode = preg_replace('/\D+/', '', trim($code)) ?? '';
    if ($submittedCode === '') {
        throw new RuntimeException('Verification code is required.');
    }

    $stmt = guidanceDb()->prepare(
        'SELECT id, email, code, purpose, expires_at, verified_at, attempts '
        . 'FROM gm_otp_codes WHERE id = ? AND email = ? AND purpose = ? LIMIT 1'
    );
    $stmt->execute([$otpId, $normalizedEmail, $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || !empty($row['verified_at'])) {
        throw new RuntimeException('Verification code expired. Please request a new one.');
    }

    $expiresAt = strtotime((string)($row['expires_at'] ?? '')) ?: 0;
    if ($expiresAt < time()) {
        guidanceDb()->prepare('DELETE FROM gm_otp_codes WHERE id = ?')->execute([$otpId]);
        throw new RuntimeException('Verification code expired. Please request a new one.');
    }

    $attempts = (int)($row['attempts'] ?? 0);
    if ($attempts >= 5) {
        throw new RuntimeException('Too many invalid attempts. Please request a new code.');
    }

    if (!hash_equals((string)($row['code'] ?? ''), $submittedCode)) {
        guidanceDb()->prepare('UPDATE gm_otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$otpId]);
        if (($attempts + 1) >= 5) {
            throw new RuntimeException('Too many invalid attempts. Please request a new code.');
        }
        throw new RuntimeException('Invalid verification code.');
    }

    return $row;
}

function guidanceConsumeOtpCode(int $otpId): void
{
    if ($otpId < 1) {
        return;
    }

    guidanceDb()->prepare('UPDATE gm_otp_codes SET verified_at = NOW() WHERE id = ? AND verified_at IS NULL')->execute([$otpId]);
}

function guidanceOtpErrorStatus(string $message): int
{
    $message = strtolower(trim($message));
    if (str_contains($message, 'too many')) {
        return 429;
    }
    if (str_contains($message, 'expired') || str_contains($message, 'session')) {
        return 400;
    }
    return 422;
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

    try {
        $authRow = guidanceAuthenticateCredentials($email, $password);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Authentication failed.']);
        return;
    }

    if (!is_array($authRow)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid email or password.']);
        return;
    }

    try {
        $sessionPayload = guidanceBuildAuthSessionPayload($authRow, $email);
    } catch (RuntimeException $e) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid email or password.']);
        return;
    }

    if (guidanceOtpEnabled('two_fa_login')) {
        $rateKey = 'guidance_login_otp_issue:' . guidanceOtpRequestIp() . ':' . sha1(strtolower($email));
        if (!guidanceOtpRateLimitAllowed($rateKey, 5, guidanceOtpTicketTtlSeconds())) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'Too many verification requests. Please wait a few minutes and try again.']);
            return;
        }

        try {
            $challenge = guidanceCreateOtpChallenge(
                'guidance_login_otp',
                $email,
                'login',
                [
                    'auth_payload' => $sessionPayload,
                    'redirect' => '/admin/guidance',
                ],
                guidanceOtpTicketTtlSeconds()
            );
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Unable to send a verification code right now. Please try again later.']);
            return;
        }

        echo json_encode([
            'ok' => false,
            'requires_otp' => true,
            'message' => 'We sent a verification code to ' . $challenge['masked_email'] . '.',
            'ticket' => $challenge['ticket'],
            'masked_email' => $challenge['masked_email'],
            'expires_in' => $challenge['expires_in'],
        ]);
        return;
    }

    guidanceFinalizeAuthSession($sessionPayload);

    echo json_encode([
        'ok' => true,
        'success' => true,
        'redirect' => '/admin/guidance',
    ]);
}

function guidanceAuthVerifyOtp(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $ticket = trim((string)guidanceInput('ticket', ''));
    $code = trim((string)guidanceInput('code', ''));
    if ($ticket === '' || $code === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Verification code is required.']);
        return;
    }

    $ticketPayload = guidanceReadOtpTicket($ticket, 'guidance_login_otp');
    if (!is_array($ticketPayload) || !is_array($ticketPayload['auth_payload'] ?? null)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Verification session expired. Please sign in again.']);
        return;
    }

    $rateKey = 'guidance_login_otp_verify:' . guidanceOtpRequestIp() . ':' . sha1((string)($ticketPayload['otp_email'] ?? ''));
    if (!guidanceOtpRateLimitAllowed($rateKey, 10, guidanceOtpTicketTtlSeconds())) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many verification attempts. Please request a new code.']);
        return;
    }

    try {
        guidanceValidateOtpCode(
            (int)($ticketPayload['otp_id'] ?? 0),
            (string)($ticketPayload['otp_email'] ?? ''),
            'login',
            $code
        );
        guidanceFinalizeAuthSession((array)$ticketPayload['auth_payload']);
        guidanceConsumeOtpCode((int)($ticketPayload['otp_id'] ?? 0));
    } catch (RuntimeException $e) {
        http_response_code(guidanceOtpErrorStatus($e->getMessage()));
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        return;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to verify the code right now.']);
        return;
    }

    echo json_encode([
        'ok' => true,
        'success' => true,
        'redirect' => (string)($ticketPayload['redirect'] ?? '/admin/guidance'),
    ]);
}

function guidanceAuthResendOtp(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $ticket = trim((string)guidanceInput('ticket', ''));
    if ($ticket === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Verification session expired. Please sign in again.']);
        return;
    }

    $ticketPayload = guidanceReadOtpTicket($ticket, 'guidance_login_otp');
    if (!is_array($ticketPayload) || !is_array($ticketPayload['auth_payload'] ?? null)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Verification session expired. Please sign in again.']);
        return;
    }

    $rateKey = 'guidance_login_otp_resend:' . guidanceOtpRequestIp() . ':' . sha1((string)($ticketPayload['otp_email'] ?? ''));
    if (!guidanceOtpRateLimitAllowed($rateKey, 3, guidanceOtpTicketTtlSeconds())) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many resend requests. Please wait before trying again.']);
        return;
    }

    try {
        $challenge = guidanceCreateOtpChallenge(
            'guidance_login_otp',
            (string)($ticketPayload['otp_email'] ?? ''),
            'login',
            [
                'auth_payload' => (array)$ticketPayload['auth_payload'],
                'redirect' => (string)($ticketPayload['redirect'] ?? '/admin/guidance'),
            ],
            guidanceOtpTicketTtlSeconds()
        );
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to resend the verification code right now.']);
        return;
    }

    echo json_encode([
        'ok' => false,
        'requires_otp' => true,
        'message' => 'A new verification code was sent to ' . $challenge['masked_email'] . '.',
        'ticket' => $challenge['ticket'],
        'masked_email' => $challenge['masked_email'],
        'expires_in' => $challenge['expires_in'],
    ]);
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

    $bookingFieldsHtml = guidanceBookingBuildFieldsHtml($schoolInfo);
    $bookingDetailFieldsHtml = guidanceRenderFormFields('booking', [], [], ['include_groups' => ['Appointment Details'], 'show_group_headings' => false]);

    echo guidanceRender('modules/guidance/pages/public-booking.disyl', [
        'colleges' => $colleges,
        'appointment_types' => $types,
        'settings' => $settings,
        'school_info' => $schoolInfo,
        'page_title' => 'Book an Appointment',
        'max_booking_days' => $maxBookingDays,
        'min_date' => date('Y-m-d'),
        'max_date' => date('Y-m-d', strtotime('+' . $maxBookingDays . ' days')),
        'booking_fields_html' => $bookingFieldsHtml,
        'booking_detail_fields_html' => $bookingDetailFieldsHtml,
        'two_fa_booking' => guidanceOtpEnabled('two_fa_booking') ? '1' : '0',
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
        echo json_encode(['error' => 'Date and college are required'], JSON_UNESCAPED_SLASHES);
        return;
    }

    try {
        $db = guidanceDb();
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
            echo json_encode(['error' => "Date must be within the next {$maxBookingDays} days"], JSON_UNESCAPED_SLASHES);
            return;
        }

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
            echo json_encode(['error' => 'No counselor assigned to this college'], JSON_UNESCAPED_SLASHES);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($counselorIds), '?'));

        $blockedStmt = $db->prepare(
            "SELECT counselor_id, reason FROM gm_blocked_dates\n"
            . "WHERE blocked_date = ?\n"
            . "AND start_time IS NULL\n"
            . "AND (counselor_id IS NULL OR counselor_id IN ({$placeholders}))"
        );
        $blockedStmt->execute(array_merge([$date], $counselorIds));
        $blockedRows = $blockedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $blockedCounselors = [];
        foreach ($blockedRows as $blockedRow) {
            if (($blockedRow['counselor_id'] ?? null) === null) {
                $reason = htmlspecialchars((string)($blockedRow['reason'] ?? 'Unavailable'));
                if (guidanceIsHtmx()) {
                    echo '<div class="text-red-600 p-4 bg-red-50 rounded-lg"><i class="fas fa-calendar-times mr-2"></i>This date is unavailable: ' . $reason . '</div>';
                    return;
                }
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Date is blocked', 'reason' => (string)($blockedRow['reason'] ?? '')], JSON_UNESCAPED_SLASHES);
                return;
            }
            $blockedCounselors[(int)$blockedRow['counselor_id']] = true;
        }

        $availableCounselorIds = array_values(array_filter($counselorIds, static function (int $counselorId) use ($blockedCounselors): bool {
            return !isset($blockedCounselors[$counselorId]);
        }));
        if (empty($availableCounselorIds)) {
            if (guidanceIsHtmx()) {
                echo '<div class="text-amber-600 p-4 bg-amber-50 rounded-lg"><i class="fas fa-user-slash mr-2"></i>No counselor is available on this date.</div>';
                return;
            }
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'No counselor is available on this date'], JSON_UNESCAPED_SLASHES);
            return;
        }

        $counselorSchedules = [];
        foreach ($availableCounselorIds as $counselorId) {
            $hours = guidanceGetCounselorAvailabilityForDate($db, (int)$counselorId, $date);
            if ($hours !== null) {
                $counselorSchedules[(int)$counselorId] = $hours;
            }
        }
        if (empty($counselorSchedules)) {
            if (guidanceIsHtmx()) {
                echo '<div class="text-amber-600 p-4 bg-amber-50 rounded-lg"><i class="fas fa-clock mr-2"></i>No counselor has availability configured for this day.</div>';
                return;
            }
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'No counselor is available on this day'], JSON_UNESCAPED_SLASHES);
            return;
        }

        $availablePlaceholders = implode(',', array_fill(0, count($availableCounselorIds), '?'));
        $existingStmt = $db->prepare(
            "SELECT scheduled_time, duration_minutes, counselor_id\n"
            . "FROM gm_appointments\n"
            . "WHERE counselor_id IN ({$availablePlaceholders})\n"
            . "AND scheduled_date = ?\n"
            . "AND status NOT IN ('cancelled', 'rejected')"
        );
        $existingStmt->execute(array_merge($availableCounselorIds, [$date]));
        $existingAppointments = $existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $bookedSlots = [];
        foreach ($existingAppointments as $appt) {
            $counselorId = (int)($appt['counselor_id'] ?? 0);
            $start = strtotime($date . ' ' . (string)($appt['scheduled_time'] ?? '00:00'));
            $end = $start + (((int)($appt['duration_minutes'] ?? 30)) * 60);
            $bookedSlots[$counselorId][] = ['start' => $start, 'end' => $end];
        }

        $blockedTimesStmt = $db->prepare(
            "SELECT start_time, end_time, counselor_id FROM gm_blocked_dates\n"
            . "WHERE blocked_date = ?\n"
            . "AND start_time IS NOT NULL\n"
            . "AND (counselor_id IS NULL OR counselor_id IN ({$availablePlaceholders}))"
        );
        $blockedTimesStmt->execute(array_merge([$date], $availableCounselorIds));
        $blockedTimes = $blockedTimesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($blockedTimes as $blocked) {
            $blockedCounselorId = ($blocked['counselor_id'] ?? null) === null ? 'all' : (int)$blocked['counselor_id'];
            $start = strtotime($date . ' ' . (string)($blocked['start_time'] ?? '00:00'));
            $end = strtotime($date . ' ' . (string)($blocked['end_time'] ?? '00:00'));
            if ($blockedCounselorId === 'all') {
                foreach (array_keys($counselorSchedules) as $scheduleCounselorId) {
                    $bookedSlots[(int)$scheduleCounselorId][] = ['start' => $start, 'end' => $end];
                }
            } else {
                $bookedSlots[(int)$blockedCounselorId][] = ['start' => $start, 'end' => $end];
            }
        }

        $slotSeconds = $slotDuration * 60;
        $bufferSeconds = $bufferMinutes * 60;
        $slotsByTime = [];

        foreach ($counselorSchedules as $counselorId => $schedule) {
            foreach ((array)($schedule['ranges'] ?? []) as $range) {
                $currentTime = strtotime($date . ' ' . (string)($range['start'] ?? '00:00'));
                $endTimeTs = strtotime($date . ' ' . (string)($range['end'] ?? '00:00'));

                while ($currentTime + $slotSeconds <= $endTimeTs) {
                    $slotEnd = $currentTime + $slotSeconds;
                    $isAvailable = true;
                    foreach (($bookedSlots[(int)$counselorId] ?? []) as $booked) {
                        if ($currentTime < ($booked['end'] + $bufferSeconds) && $slotEnd > ($booked['start'] - $bufferSeconds)) {
                            $isAvailable = false;
                            break;
                        }
                    }

                    if ($isAvailable) {
                        $timeKey = date('H:i', $currentTime);
                        if (!isset($slotsByTime[$timeKey])) {
                            $slotsByTime[$timeKey] = [
                                'time' => $timeKey,
                                'display' => date('g:i A', $currentTime),
                                'counselor_id' => (int)$counselorId,
                            ];
                        }
                    }

                    $currentTime += $slotSeconds;
                }
            }
        }

        ksort($slotsByTime);
        $slots = array_values($slotsByTime);

        if (guidanceIsHtmx()) {
            if (empty($slots)) {
                echo '<div class="text-amber-600 p-4 bg-amber-50 rounded-lg"><i class="fas fa-clock mr-2"></i>No available slots for this date. Please try another date or join the waitlist.</div>';
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
        echo json_encode(['success' => true, 'data' => $slots], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        app()->log('Booking get slots error: ' . $e->getMessage(), 'error');
        if (guidanceIsHtmx()) {
            http_response_code(500);
            echo '<div class="text-red-600 p-4 bg-red-50 rounded-lg"><i class="fas fa-triangle-exclamation mr-2"></i>Failed to load available slots</div>';
            return;
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to load available slots'], JSON_UNESCAPED_SLASHES);
    }
}

function guidanceResolvePublicBookingPayload(array $input): array
{
    $validationErrors = guidanceValidateFormInput('booking', $input);
    foreach (['scheduled_date', 'scheduled_time', 'appointment_type_id'] as $fieldName) {
        if (!array_key_exists($fieldName, $input) || trim((string)$input[$fieldName]) === '') {
            $validationErrors[] = ucfirst(str_replace('_', ' ', $fieldName)) . ' is required';
        }
    }

    if ($validationErrors !== []) {
        throw new RuntimeException($validationErrors[0]);
    }

    $studentName = trim((string)($input['student_name'] ?? ''));
    $studentEmail = trim((string)($input['student_email'] ?? ''));
    $collegeId = (int)($input['college_id'] ?? 0);
    $yearLevel = trim((string)($input['year_level'] ?? ''));
    $studentPhone = trim((string)($input['student_phone'] ?? ($input['student_mobile'] ?? '')));
    $scheduledDate = trim((string)($input['scheduled_date'] ?? ''));
    $scheduledTime = trim((string)($input['scheduled_time'] ?? ''));
    $typeId = (int)($input['appointment_type_id'] ?? 0);
    $purpose = trim((string)($input['purpose'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));
    $isUrgent = !empty($input['is_urgent']) ? 1 : 0;

    if (!filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please enter a valid email address.');
    }

    $db = guidanceDb();
    $typeStmt = $db->prepare(
        'SELECT id, duration_minutes FROM gm_appointment_types WHERE id = ? AND is_active = 1 AND is_public = 1 LIMIT 1'
    );
    $typeStmt->execute([$typeId]);
    $typeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($typeRow)) {
        throw new RuntimeException('Selected appointment type is no longer available.');
    }

    $duration = max(10, (int)($typeRow['duration_minutes'] ?? 30));

    $counselorId = (int)($input['counselor_id'] ?? 0);
    if ($counselorId > 0) {
        $assignedStmt = $db->prepare(
            "SELECT ca.counselor_id\n"
            . "FROM gm_counselor_assignments ca\n"
            . "JOIN gm_users u ON ca.counselor_id = u.id\n"
            . "WHERE ca.college_id = ? AND ca.counselor_id = ? AND ca.is_active = 1 AND u.role != 'admin'\n"
            . "LIMIT 1"
        );
        $assignedStmt->execute([$collegeId, $counselorId]);
        $counselorId = (int)($assignedStmt->fetchColumn() ?: 0);
        if ($counselorId < 1) {
            throw new RuntimeException('Selected counselor is not assigned to this college.');
        }
    } else {
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
        throw new RuntimeException('No counselor is assigned to this college.');
    }

    guidanceAssertPublicBookingSlotAvailable($db, $counselorId, $scheduledDate, $scheduledTime, $duration);

    return [
        'student_name' => $studentName,
        'student_email' => $studentEmail,
        'student_phone' => $studentPhone,
        'college_id' => $collegeId,
        'year_level' => $yearLevel,
        'scheduled_date' => $scheduledDate,
        'scheduled_time' => $scheduledTime,
        'appointment_type_id' => $typeId,
        'purpose' => $purpose,
        'message' => $message,
        'is_urgent' => $isUrgent,
        'counselor_id' => $counselorId,
        'duration_minutes' => $duration,
    ];
}

function guidanceAssertPublicBookingSlotAvailable(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $counselorId, string $scheduledDate, string $scheduledTime, int $durationMinutes = 30): void
{
    $availability = guidanceGetCounselorAvailabilityForDate($db, $counselorId, $scheduledDate);
    if ($availability === null) {
        throw new RuntimeException('This counselor is not available on the selected date.');
    }

    $slotStart = strtotime($scheduledDate . ' ' . $scheduledTime);
    if ($slotStart === false) {
        throw new RuntimeException('Invalid appointment time.');
    }
    $slotEnd = $slotStart + (max(10, $durationMinutes) * 60);

    $fitsAvailability = false;
    foreach ((array)($availability['ranges'] ?? []) as $range) {
        $rangeStart = strtotime($scheduledDate . ' ' . (string)($range['start'] ?? '00:00'));
        $rangeEnd = strtotime($scheduledDate . ' ' . (string)($range['end'] ?? '00:00'));
        if ($rangeStart === false || $rangeEnd === false) {
            continue;
        }
        if ($slotStart >= $rangeStart && $slotEnd <= $rangeEnd) {
            $fitsAvailability = true;
            break;
        }
    }

    if (!$fitsAvailability) {
        throw new RuntimeException('The selected time is outside counselor availability.');
    }

    $blockStmt = $db->prepare(
        "SELECT start_time, end_time FROM gm_blocked_dates\n"
        . "WHERE blocked_date = ?\n"
        . "AND (counselor_id IS NULL OR counselor_id = ?)"
    );
    $blockStmt->execute([$scheduledDate, $counselorId]);
    $blocks = $blockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($blocks as $block) {
        if (($block['start_time'] ?? null) === null) {
            throw new RuntimeException('This date is unavailable. Please select another time.');
        }

        $blockStart = strtotime($scheduledDate . ' ' . (string)($block['start_time'] ?? '00:00'));
        $blockEnd = strtotime($scheduledDate . ' ' . (string)($block['end_time'] ?? '00:00'));
        if ($blockStart === false || $blockEnd === false) {
            continue;
        }
        if ($slotStart < $blockEnd && $slotEnd > $blockStart) {
            throw new RuntimeException('This time slot is blocked. Please select another time.');
        }
    }

    $apptSettings = guidanceGetSettingJson('appointment_settings', []);
    $bufferMinutes = max(0, (int)($apptSettings['buffer_minutes'] ?? 5));
    $checkStmt = $db->prepare(
        "SELECT scheduled_time, duration_minutes FROM gm_appointments\n"
        . "WHERE counselor_id = ?\n"
        . "AND scheduled_date = ?\n"
        . "AND status NOT IN ('cancelled', 'rejected')"
    );
    $checkStmt->execute([$counselorId, $scheduledDate]);
    $appointments = $checkStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($appointments as $appointment) {
        $appointmentStart = strtotime($scheduledDate . ' ' . (string)($appointment['scheduled_time'] ?? '00:00'));
        if ($appointmentStart === false) {
            continue;
        }
        $appointmentEnd = $appointmentStart + (((int)($appointment['duration_minutes'] ?? 30)) * 60);
        if ($slotStart < ($appointmentEnd + ($bufferMinutes * 60)) && $slotEnd > ($appointmentStart - ($bufferMinutes * 60))) {
            throw new RuntimeException('This time slot is no longer available. Please select another time.');
        }
    }
}

function guidanceCreatePublicBookingRecord(array $payload): int
{
    $db = guidanceDb();
    guidanceAssertPublicBookingSlotAvailable(
        $db,
        (int)($payload['counselor_id'] ?? 0),
        (string)($payload['scheduled_date'] ?? ''),
        (string)($payload['scheduled_time'] ?? ''),
        (int)($payload['duration_minutes'] ?? 30)
    );

    $stmt = $db->prepare(
        "INSERT INTO gm_appointments (\n"
        . " counselor_id, student_id, student_name, student_email, student_phone,\n"
        . " student_college_id, student_year_level, scheduled_date, scheduled_time,\n"
        . " duration_minutes, appointment_type_id, purpose, status,\n"
        . " requested_by_student, request_message, is_urgent, created_by, last_modified_by\n"
        . ") VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?, 0, 0)"
    );
    $stmt->execute([
        (int)($payload['counselor_id'] ?? 0),
        (string)($payload['student_name'] ?? ''),
        (string)($payload['student_email'] ?? ''),
        (($payload['student_phone'] ?? '') !== '' ? (string)$payload['student_phone'] : null),
        (int)($payload['college_id'] ?? 0),
        (($payload['year_level'] ?? '') !== '' ? (string)$payload['year_level'] : null),
        (string)($payload['scheduled_date'] ?? ''),
        (string)($payload['scheduled_time'] ?? ''),
        (int)($payload['duration_minutes'] ?? 30),
        (int)($payload['appointment_type_id'] ?? 0),
        (($payload['purpose'] ?? '') !== '' ? (string)$payload['purpose'] : null),
        (($payload['message'] ?? '') !== '' ? (string)$payload['message'] : null),
        !empty($payload['is_urgent']) ? 1 : 0,
    ]);

    $appointmentId = (int)$db->lastInsertId();

    guidanceQueueCounselorNotification($db, (int)($payload['counselor_id'] ?? 0), $appointmentId, $payload);
    guidanceSendStudentBookingConfirmation($db, $appointmentId, $payload);

    guidanceFireEvent('guidance.booking.created', [
        'to' => (string)($payload['student_phone'] ?? ''),
        'appointment_id' => $appointmentId,
        'student_name' => (string)($payload['student_name'] ?? ''),
        'student_email' => (string)($payload['student_email'] ?? ''),
        'student_phone' => (string)($payload['student_phone'] ?? ''),
        'recipient_name' => (string)($payload['student_name'] ?? ''),
        'trigger_ref_id' => (string)$appointmentId,
    ]);

    return $appointmentId;
}

function guidanceQueueCounselorNotification(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $counselorId, int $appointmentId, array $payload): void
{
    if ($counselorId < 1) {
        return;
    }

    try {
        $message = 'New appointment request from ' . (string)($payload['student_name'] ?? 'Student')
            . ' for ' . date('M j, Y', strtotime((string)($payload['scheduled_date'] ?? date('Y-m-d'))));
        $dataJson = json_encode([
            'appointment_id' => $appointmentId,
            'student_name' => (string)($payload['student_name'] ?? ''),
        ], JSON_UNESCAPED_SLASHES);
        if ($dataJson === false) {
            $dataJson = '{}';
        }

        $db->prepare(
            'INSERT INTO gm_notifications (user_id, type, title, message, data, link) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $counselorId,
            'appointment_request',
            'New Appointment Request',
            $message,
            $dataJson,
            '/pages/appointments?highlight=' . $appointmentId,
        ]);
    } catch (Throwable $e) {
        app()->log('Booking: failed to queue counselor notification: ' . $e->getMessage(), 'error');
    }

    try {
        $stmt = $db->prepare('SELECT first_name, last_name, email FROM gm_users WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$counselorId]);
        $counselor = $stmt->fetch(PDO::FETCH_ASSOC);
        $email = trim((string)($counselor['email'] ?? ''));
        if ($email === '') {
            return;
        }

        $counselorName = trim((string)($counselor['first_name'] ?? '') . ' ' . (string)($counselor['last_name'] ?? ''));
        $content = '<p>A new appointment request has been submitted and requires your review.</p>'
            . '<p><strong>Student:</strong> ' . htmlspecialchars((string)($payload['student_name'] ?? '')) . '<br>'
            . '<strong>Email:</strong> ' . htmlspecialchars((string)($payload['student_email'] ?? '')) . '<br>'
            . '<strong>Phone:</strong> ' . htmlspecialchars((string)($payload['student_phone'] ?? '')) . '<br>'
            . '<strong>Date:</strong> ' . htmlspecialchars(date('F j, Y', strtotime((string)($payload['scheduled_date'] ?? date('Y-m-d'))))) . '<br>'
            . '<strong>Time:</strong> ' . htmlspecialchars(date('g:i A', strtotime((string)($payload['scheduled_time'] ?? '00:00')))) . '<br>'
            . '<strong>Purpose:</strong> ' . htmlspecialchars((string)($payload['purpose'] ?? '')) . '</p>'
            . '<p>Please log in to the Guidance system to approve or decline this request.</p>';
        $body = buildEmailTemplate('New Appointment Request', $content);
        sendEmail($email, 'New Appointment Request from ' . (string)($payload['student_name'] ?? 'Student'), $body);
    } catch (Throwable $e) {
        app()->log('Booking: failed to send counselor email: ' . $e->getMessage(), 'error');
    }
}

function guidanceSendStudentBookingConfirmation(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $appointmentId, array $payload): void
{
    $email = trim((string)($payload['student_email'] ?? ''));
    if ($email === '') {
        return;
    }

    try {
        $content = '<p>Dear ' . htmlspecialchars((string)($payload['student_name'] ?? 'Student')) . ',</p>'
            . '<p>Your appointment request has been received and is pending approval.</p>'
            . '<p><strong>Date:</strong> ' . htmlspecialchars(date('F j, Y', strtotime((string)($payload['scheduled_date'] ?? date('Y-m-d'))))) . '<br>'
            . '<strong>Time:</strong> ' . htmlspecialchars(date('g:i A', strtotime((string)($payload['scheduled_time'] ?? '00:00')))) . '</p>'
            . '<p>You will receive another email once your appointment is confirmed by a counselor.</p>'
            . '<p><strong>Reference:</strong> #' . $appointmentId . '</p>';
        $body = buildEmailTemplate('Appointment Request Received', $content);
        sendEmail($email, 'Appointment Request Received', $body);
    } catch (Throwable $e) {
        app()->log('Booking: failed to send student confirmation: ' . $e->getMessage(), 'error');
    }
}

function guidancePublicBookingSuccessPayload(array $payload, int $appointmentId): array
{
    return [
        'ok' => true,
        'success' => true,
        'appointment_id' => $appointmentId,
        'message' => 'Appointment request submitted! You will receive a confirmation email once approved.',
        'html' => guidanceRender('modules/guidance/partials/booking-success.disyl', [
            'appointment_id' => $appointmentId,
            'student_name' => (string)($payload['student_name'] ?? ''),
            'scheduled_date' => (string)($payload['scheduled_date'] ?? ''),
            'scheduled_time' => (string)($payload['scheduled_time'] ?? ''),
            'student_email' => (string)($payload['student_email'] ?? ''),
            'base_url' => '/guidance',
        ]),
    ];
}

function guidancePublicBookingErrorStatus(string $message): int
{
    $message = strtolower(trim($message));
    if (str_contains($message, 'no longer available')) {
        return 409;
    }
    if (str_contains($message, 'too many')) {
        return 429;
    }
    return 400;
}

function apiGuidancePublicBooking(): void
{
    header('Content-Type: application/json; charset=utf-8');
    $input = guidanceInput();
    if (!is_array($input)) {
        $input = [];
    }

    try {
        $payload = guidanceResolvePublicBookingPayload($input);

        if (guidanceOtpEnabled('two_fa_booking')) {
            $rateKey = 'guidance_booking_otp_issue:' . guidanceOtpRequestIp() . ':' . sha1(strtolower((string)$payload['student_email']));
            if (!guidanceOtpRateLimitAllowed($rateKey, 5, guidanceOtpTicketTtlSeconds())) {
                http_response_code(429);
                echo json_encode(['ok' => false, 'error' => 'Too many verification requests. Please wait a few minutes and try again.']);
                return;
            }

            $challenge = guidanceCreateOtpChallenge(
                'guidance_booking_otp',
                (string)$payload['student_email'],
                'booking',
                ['booking_payload' => $payload],
                guidanceOtpTicketTtlSeconds()
            );

            echo json_encode([
                'ok' => false,
                'requires_otp' => true,
                'message' => 'We sent a verification code to ' . $challenge['masked_email'] . '.',
                'ticket' => $challenge['ticket'],
                'masked_email' => $challenge['masked_email'],
                'expires_in' => $challenge['expires_in'],
            ]);
            return;
        }

        $appointmentId = guidanceCreatePublicBookingRecord($payload);
        http_response_code(201);
        echo json_encode(guidancePublicBookingSuccessPayload($payload, $appointmentId));
    } catch (RuntimeException $e) {
        http_response_code(guidancePublicBookingErrorStatus($e->getMessage()));
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to create appointment']);
    }
}

function apiGuidanceVerifyBookingOtp(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $ticket = trim((string)guidanceInput('ticket', ''));
    $code = trim((string)guidanceInput('code', ''));
    if ($ticket === '' || $code === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Verification code is required.']);
        return;
    }

    $ticketPayload = guidanceReadOtpTicket($ticket, 'guidance_booking_otp');
    $bookingPayload = is_array($ticketPayload['booking_payload'] ?? null) ? $ticketPayload['booking_payload'] : null;
    if (!is_array($ticketPayload) || !is_array($bookingPayload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Verification session expired. Please submit your booking again.']);
        return;
    }

    $rateKey = 'guidance_booking_otp_verify:' . guidanceOtpRequestIp() . ':' . sha1((string)($ticketPayload['otp_email'] ?? ''));
    if (!guidanceOtpRateLimitAllowed($rateKey, 10, guidanceOtpTicketTtlSeconds())) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many verification attempts. Please request a new code.']);
        return;
    }

    try {
        guidanceValidateOtpCode(
            (int)($ticketPayload['otp_id'] ?? 0),
            (string)($ticketPayload['otp_email'] ?? ''),
            'booking',
            $code
        );
        $appointmentId = guidanceCreatePublicBookingRecord($bookingPayload);
        guidanceConsumeOtpCode((int)($ticketPayload['otp_id'] ?? 0));
        http_response_code(201);
        echo json_encode(guidancePublicBookingSuccessPayload($bookingPayload, $appointmentId));
    } catch (RuntimeException $e) {
        $status = str_contains(strtolower($e->getMessage()), 'verification')
            ? guidanceOtpErrorStatus($e->getMessage())
            : guidancePublicBookingErrorStatus($e->getMessage());
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to create appointment']);
    }
}

function apiGuidanceResendBookingOtp(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $ticket = trim((string)guidanceInput('ticket', ''));
    if ($ticket === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Verification session expired. Please submit your booking again.']);
        return;
    }

    $ticketPayload = guidanceReadOtpTicket($ticket, 'guidance_booking_otp');
    $bookingPayload = is_array($ticketPayload['booking_payload'] ?? null) ? $ticketPayload['booking_payload'] : null;
    if (!is_array($ticketPayload) || !is_array($bookingPayload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Verification session expired. Please submit your booking again.']);
        return;
    }

    $rateKey = 'guidance_booking_otp_resend:' . guidanceOtpRequestIp() . ':' . sha1((string)($ticketPayload['otp_email'] ?? ''));
    if (!guidanceOtpRateLimitAllowed($rateKey, 3, guidanceOtpTicketTtlSeconds())) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many resend requests. Please wait before trying again.']);
        return;
    }

    try {
        $challenge = guidanceCreateOtpChallenge(
            'guidance_booking_otp',
            (string)($ticketPayload['otp_email'] ?? ''),
            'booking',
            ['booking_payload' => $bookingPayload],
            guidanceOtpTicketTtlSeconds()
        );
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to resend the verification code right now.']);
        return;
    }

    echo json_encode([
        'ok' => false,
        'requires_otp' => true,
        'message' => 'A new verification code was sent to ' . $challenge['masked_email'] . '.',
        'ticket' => $challenge['ticket'],
        'masked_email' => $challenge['masked_email'],
        'expires_in' => $challenge['expires_in'],
    ]);
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

    $html = guidanceRenderFormFields('booking', [], ['colleges' => $colleges], ['exclude_groups' => ['Appointment Details'], 'show_group_headings' => false]);
    if ($html !== '') {
        return $html;
    }

    $options = '<option value="">Select College</option>';
    foreach ($colleges as $c) {
        $id = (int)($c['id'] ?? 0);
        $label = trim((string)($c['code'] ?? '') . ' - ' . (string)($c['name'] ?? ''));
        $options .= '<option value="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
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
        . '  <select name="college_id" id="college-select" required class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">'
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

    echo guidanceRender('modules/guidance/pages/dashboard.disyl', guidanceBasePageContext($user, 'Guidance', 'dashboard'));
}

function guidanceBasePageContext(array $user, string $pageTitle, string $currentPage): array
{
    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $name = (string)($user['full_name'] ?? ($user['name'] ?? ($user['username'] ?? 'User')));
    $initials = '';
    if ($name !== '') {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = strtoupper(substr((string)($parts[0] ?? ''), 0, 1) . substr((string)($parts[1] ?? ''), 0, 1));
    }

    $notificationsCount = 0;
    if ($userId > 0) {
        try {
            $stmt = guidanceDb()->prepare('SELECT COUNT(*) FROM gm_notifications WHERE user_id = ? AND is_read = 0');
            $stmt->execute([$userId]);
            $notificationsCount = (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $notificationsCount = 0;
        }
    }

    return [
        'page_title' => $pageTitle,
        'base_url' => '/admin/guidance',
        'current_page' => $currentPage,
        'is_pro' => guidanceIsPro(),
        'user_name' => $name,
        'user_role' => $role,
        'user_initials' => $initials,
        'notifications_count' => $notificationsCount,
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
        [
            'case' => $case,
            'can_delete_case' => $role !== 'counselor',
            'show_case_notes' => guidanceIsPro(),
        ]
    ));
}

function apiGuidanceGetCase(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);

    if ($caseId < 1) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Case not found'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare(
        "SELECT c.*, CONCAT(u.first_name, ' ', u.last_name) AS counselor_name, col.code AS college_code, col.name AS college_name\n"
        . "FROM gm_cases c\n"
        . "LEFT JOIN gm_users u ON c.counselor_id = u.id\n"
        . "LEFT JOIN gm_colleges col ON c.college_id = col.id\n"
        . "WHERE c.id = ? AND c.deleted_at IS NULL\n"
        . "LIMIT 1"
    );
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($case)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Case not found'], JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($role === 'counselor' && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Access denied'], JSON_UNESCAPED_SLASHES);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $case], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceCaseHistory(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);

    if ($caseId < 1) {
        http_response_code(404);
        if (guidanceIsHtmx()) {
            echo '<div class="p-4 text-sm text-red-600">Case not found</div>';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Case not found'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $db = guidanceDb();
    $caseStmt = $db->prepare('SELECT counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $caseStmt->execute([$caseId]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        http_response_code(404);
        if (guidanceIsHtmx()) {
            echo '<div class="p-4 text-sm text-red-600">Case not found</div>';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Case not found'], JSON_UNESCAPED_SLASHES);
        return;
    }
    if ($role === 'counselor' && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        if (guidanceIsHtmx()) {
            echo '<div class="p-4 text-sm text-red-600">Access denied</div>';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Access denied'], JSON_UNESCAPED_SLASHES);
        return;
    }

    try {
        $stmt = $db->prepare(
            "SELECT h.*, CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name\n"
            . "FROM gm_case_status_history h\n"
            . "LEFT JOIN gm_users u ON h.changed_by = u.id\n"
            . "WHERE h.case_id = ?\n"
            . "ORDER BY h.created_at DESC, h.id DESC"
        );
        $stmt->execute([$caseId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        app()->log('Case history error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        if (guidanceIsHtmx()) {
            echo '<div class="p-4 text-sm text-red-600">Failed to load status history</div>';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to fetch case history'], JSON_UNESCAPED_SLASHES);
        return;
    }

    if (guidanceIsHtmx()) {
        header('Content-Type: text/html; charset=utf-8');
        echo guidanceRender('modules/guidance/partials/case-status-history.disyl', ['history' => $history]);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $history], JSON_UNESCAPED_SLASHES);
}

function modalGuidanceCaseNoteNew(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    guidanceRequirePro();

    $caseId = (int)($params['id'] ?? 0);
    if ($caseId < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Case not found</div>';
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare('SELECT id, counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Case not found</div>';
        return;
    }

    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    if ($role === 'counselor' && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo '<div class="p-4 text-red-600">Access denied</div>';
        return;
    }

    echo guidanceRender('modules/guidance/modals/note-form.disyl', [
        'case_id' => $caseId,
        'today' => date('Y-m-d'),
    ]);
}

function apiGuidanceCaseNotes(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    guidanceRequirePro();

    $caseId = (int)($params['id'] ?? 0);
    if ($caseId < 1) {
        http_response_code(404);
        if (guidanceIsHtmx()) {
            echo '<div class="p-6 text-sm text-red-600">Case not found</div>';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Case not found'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $db = guidanceDb();
    $caseStmt = $db->prepare('SELECT counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $caseStmt->execute([$caseId]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        http_response_code(404);
        if (guidanceIsHtmx()) {
            echo '<div class="p-6 text-sm text-red-600">Case not found</div>';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Case not found'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    if ($role === 'counselor' && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        if (guidanceIsHtmx()) {
            echo '<div class="p-6 text-sm text-red-600">Access denied</div>';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Access denied'], JSON_UNESCAPED_SLASHES);
        return;
    }

    try {
        $stmt = $db->prepare(
            "SELECT n.*, CONCAT(u.first_name, ' ', u.last_name) AS counselor_name\n"
            . "FROM gm_counselor_notes n\n"
            . "LEFT JOIN gm_users u ON n.counselor_id = u.id\n"
            . "WHERE n.case_id = ?\n"
            . "ORDER BY n.session_date DESC, n.created_at DESC"
        );
        $stmt->execute([$caseId]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        app()->log('Notes list error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        if (guidanceIsHtmx()) {
            echo '<div class="p-6 text-sm text-red-600">Failed to load notes</div>';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to fetch notes'], JSON_UNESCAPED_SLASHES);
        return;
    }

    if (guidanceIsHtmx()) {
        header('Content-Type: text/html; charset=utf-8');
        echo guidanceRender('modules/guidance/partials/notes-list.disyl', ['notes' => $notes]);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $notes], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceCreateCaseNote(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    guidanceRequirePro();
    app()->csrfEnforce();

    $caseId = (int)($params['id'] ?? 0);
    $input = guidanceInput();
    if ($caseId < 1 || !is_array($input)) {
        http_response_code(400);
        echo '';
        return;
    }

    $noteContent = trim((string)($input['note_content'] ?? ($input['content'] ?? '')));
    if ($noteContent === '') {
        http_response_code(422);
        header('HX-Reswap: none');
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Note content is required', 'type' => 'error']]));
        echo '';
        return;
    }

    $db = guidanceDb();
    $caseStmt = $db->prepare('SELECT id, counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $caseStmt->execute([$caseId]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        http_response_code(404);
        header('HX-Reswap: none');
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case not found', 'type' => 'error']]));
        echo '';
        return;
    }

    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    if ($role === 'counselor' && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        header('HX-Reswap: none');
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Access denied', 'type' => 'error']]));
        echo '';
        return;
    }

    try {
        $stmt = $db->prepare(
            "INSERT INTO gm_counselor_notes (\n"
            . "    case_id, counselor_id, note_type, session_type, session_date, session_duration_minutes,\n"
            . "    note_content, intervention_used, student_response, risk_level, mood_assessment,\n"
            . "    action_taken, mse_appearance, mse_behavior, mse_speech, mse_emotions,\n"
            . "    mse_thinking, mse_cognition, mse_judgment, mse_reliability,\n"
            . "    case_predisposition, case_precipitating, case_perpetuating, case_protective,\n"
            . "    observation_recommendation, followup_required, followup_notes, is_confidential,\n"
            . "    sync_id, created_by, created_at, updated_at\n"
            . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $caseId,
            $userId,
            (string)($input['note_type'] ?? 'session'),
            (string)($input['session_type'] ?? 'walk-in'),
            (string)($input['session_date'] ?? ($input['note_date'] ?? date('Y-m-d'))),
            (($input['session_duration_minutes'] ?? '') !== '' ? (int)$input['session_duration_minutes'] : null),
            $noteContent,
            (($input['intervention_used'] ?? '') !== '' ? (string)$input['intervention_used'] : null),
            (($input['student_response'] ?? '') !== '' ? (string)$input['student_response'] : null),
            (string)($input['risk_level'] ?? 'none'),
            (($input['mood_assessment'] ?? '') !== '' ? (string)$input['mood_assessment'] : null),
            (($input['action_taken'] ?? '') !== '' ? (string)$input['action_taken'] : null),
            (($input['mse_appearance'] ?? '') !== '' ? (string)$input['mse_appearance'] : null),
            (($input['mse_behavior'] ?? '') !== '' ? (string)$input['mse_behavior'] : null),
            (($input['mse_speech'] ?? '') !== '' ? (string)$input['mse_speech'] : null),
            (($input['mse_emotions'] ?? '') !== '' ? (string)$input['mse_emotions'] : null),
            (($input['mse_thinking'] ?? '') !== '' ? (string)$input['mse_thinking'] : null),
            (($input['mse_cognition'] ?? '') !== '' ? (string)$input['mse_cognition'] : null),
            (($input['mse_judgment'] ?? '') !== '' ? (string)$input['mse_judgment'] : null),
            (($input['mse_reliability'] ?? '') !== '' ? (string)$input['mse_reliability'] : null),
            (($input['case_predisposition'] ?? '') !== '' ? (string)$input['case_predisposition'] : null),
            (($input['case_precipitating'] ?? '') !== '' ? (string)$input['case_precipitating'] : null),
            (($input['case_perpetuating'] ?? '') !== '' ? (string)$input['case_perpetuating'] : null),
            (($input['case_protective'] ?? '') !== '' ? (string)$input['case_protective'] : null),
            (($input['observation_recommendation'] ?? '') !== '' ? (string)$input['observation_recommendation'] : null),
            !empty($input['followup_required']) ? 1 : 0,
            (($input['followup_notes'] ?? '') !== '' ? (string)$input['followup_notes'] : null),
            !empty($input['is_confidential']) ? 1 : 0,
            (($input['sync_id'] ?? '') !== '' ? (string)$input['sync_id'] : uniqid('sync_', true)),
            $userId,
        ]);

        $db->prepare('UPDATE gm_cases SET updated_at = NOW() WHERE id = ?')->execute([$caseId]);
    } catch (Throwable $e) {
        app()->log('Notes create error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('HX-Reswap: none');
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create note', 'type' => 'error']]));
        echo '';
        return;
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Note added successfully', 'type' => 'success'],
            'closeModal' => true,
            'refreshNotes' => true,
        ]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function modalGuidanceCaseNew(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $tinyMceAssets = guidanceTinyMceAssets('guidance.session', 'default');
    $tinyMceConfig = guidanceTinyMceConfig('guidance.session', 'default', false);

    $db = guidanceDb();
    $counselors = [];
    $colleges = [];

    try {
        $stmt = $db->prepare("SELECT id, first_name, last_name, CONCAT(first_name, ' ', last_name) AS name FROM gm_users WHERE role = 'counselor' AND deleted_at IS NULL AND is_active = 1 ORDER BY first_name, last_name");
        $stmt->execute();
        $counselors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $counselors = [];
    }

    try {
        $stmt = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name");
        $colleges = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $colleges = [];
    }

    echo guidanceRender('modules/guidance/modals/case-form.disyl', [
        'case' => [],
        'today' => date('Y-m-d'),
        'is_admin' => $role !== 'counselor',
        'user_role' => $role,
        'user_id' => $userId,
        'counselors' => $counselors,
        'dynamic_fields_html' => guidanceRenderFormFields('case', [], ['colleges' => $colleges]),
        'tinymce_assets' => $tinyMceAssets,
        'tinymce_config' => $tinyMceConfig,
    ]);
}

function modalGuidanceCaseEdit(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);

    if ($caseId < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Case not found</div>';
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare('SELECT * FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Case not found</div>';
        return;
    }

    if ($role === 'counselor' && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo '<div class="p-4 text-red-600">Access denied</div>';
        return;
    }

    $tinyMceAssets = guidanceTinyMceAssets('guidance.session', 'default');
    $tinyMceConfig = guidanceTinyMceConfig('guidance.session', 'default', false);
    $counselors = [];
    $colleges = [];

    try {
        $cStmt = $db->prepare("SELECT id, first_name, last_name, CONCAT(first_name, ' ', last_name) AS name FROM gm_users WHERE role = 'counselor' AND deleted_at IS NULL AND is_active = 1 ORDER BY first_name, last_name");
        $cStmt->execute();
        $counselors = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $counselors = [];
    }

    try {
        $colStmt = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name");
        $colleges = $colStmt ? ($colStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $colleges = [];
    }

    echo guidanceRender('modules/guidance/modals/case-form.disyl', [
        'case' => $case,
        'today' => date('Y-m-d'),
        'is_admin' => $role !== 'counselor',
        'user_role' => $role,
        'user_id' => $userId,
        'counselors' => $counselors,
        'dynamic_fields_html' => guidanceRenderFormFields('case', $case, ['colleges' => $colleges]),
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
            . "       u.first_name AS counselor_first, u.last_name AS counselor_last,\n"
            . "       COALESCE(t.name, a.appointment_type) AS type_name\n"
            . "FROM gm_appointments a\n"
            . "LEFT JOIN gm_cases c ON a.case_id = c.id\n"
            . "LEFT JOIN gm_users u ON a.counselor_id = u.id\n"
            . "LEFT JOIN gm_appointment_types t ON a.appointment_type_id = t.id\n"
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

        if (guidanceIsHtmx()) {
            header('Content-Type: text/html; charset=utf-8');
            echo guidanceRender('modules/guidance/partials/appointments-list.disyl', [
                'appointments' => $appointments,
                'rows' => $rows,
                'stats' => $stats,
                'total' => count($appointments),
                'base_url' => '/admin/guidance',
            ]);
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'data' => $appointments,
            'stats' => $stats,
            'total' => count($appointments),
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        app()->log('Appointments list error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        if (guidanceIsHtmx()) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<div class="p-6 text-sm text-red-600">Failed to fetch appointments</div>';
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Failed to fetch appointments'], JSON_UNESCAPED_SLASHES);
    }
}

function apiGuidanceAppointmentSlots(): void
{
    $ctxUser = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $role = (string)($ctxUser['role'] ?? '');
    $userId = (int)($ctxUser['id'] ?? 0);
    $input = guidanceInput();

    $date = trim((string)($input['date'] ?? ''));
    $requestedCounselorId = (int)($input['counselor_id'] ?? 0);
    $duration = (int)($input['duration'] ?? 30);
    if ($duration < 10) {
        $duration = 30;
    }

    if ($role === 'counselor') {
        $counselorId = $userId;
    } elseif ($requestedCounselorId > 0) {
        $counselorId = $requestedCounselorId;
    } elseif (in_array($role, ['supervisor'], true) && $userId > 0) {
        $counselorId = $userId;
    } else {
        $counselorId = 0;
    }

    if ($date === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Date is required'], JSON_UNESCAPED_SLASHES);
        return;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid date'], JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($counselorId < 1) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Select a counselor first'], JSON_UNESCAPED_SLASHES);
        return;
    }

    try {
        $db = guidanceDb();
        $apptSettings = guidanceGetSettingJson('appointment_settings', []);
        $bufferMinutes = (int)($apptSettings['buffer_minutes'] ?? 5);
        if ($bufferMinutes < 0) {
            $bufferMinutes = 0;
        }

        $blockedDayStmt = $db->prepare(
            "SELECT reason FROM gm_blocked_dates\n"
            . "WHERE blocked_date = ?\n"
            . "AND start_time IS NULL\n"
            . "AND (counselor_id IS NULL OR counselor_id = ?)\n"
            . 'LIMIT 1'
        );
        $blockedDayStmt->execute([$date, $counselorId]);
        if ($blockedDayStmt->fetch(PDO::FETCH_ASSOC)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_SLASHES);
            return;
        }

        $dayHours = guidanceGetCounselorAvailabilityForDate($db, $counselorId, $date);
        if ($dayHours === null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_SLASHES);
            return;
        }

        $existingStmt = $db->prepare(
            "SELECT scheduled_time, duration_minutes FROM gm_appointments\n"
            . "WHERE counselor_id = ? AND scheduled_date = ?\n"
            . "AND status IN ('scheduled', 'confirmed', 'pending')"
        );
        $existingStmt->execute([$counselorId, $date]);
        $existingAppointments = $existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $bookedSlots = [];
        foreach ($existingAppointments as $appt) {
            $start = strtotime($date . ' ' . (string)($appt['scheduled_time'] ?? '00:00:00'));
            $end = $start + (((int)($appt['duration_minutes'] ?? 30)) * 60);
            $bookedSlots[] = ['start' => $start, 'end' => $end];
        }

        $blockedTimesStmt = $db->prepare(
            "SELECT start_time, end_time FROM gm_blocked_dates\n"
            . "WHERE blocked_date = ?\n"
            . "AND start_time IS NOT NULL\n"
            . "AND (counselor_id IS NULL OR counselor_id = ?)"
        );
        $blockedTimesStmt->execute([$date, $counselorId]);
        foreach (($blockedTimesStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $blocked) {
            $start = strtotime($date . ' ' . (string)($blocked['start_time'] ?? '00:00:00'));
            $end = strtotime($date . ' ' . (string)($blocked['end_time'] ?? '00:00:00'));
            $bookedSlots[] = ['start' => $start, 'end' => $end];
        }

        $bufferSeconds = $bufferMinutes * 60;
        $slotSeconds = $duration * 60;
        $slots = [];

        foreach ((array)($dayHours['ranges'] ?? []) as $range) {
            $currentTime = strtotime($date . ' ' . (string)($range['start'] ?? '00:00:00'));
            $endTimeTs = strtotime($date . ' ' . (string)($range['end'] ?? '00:00:00'));

            while ($currentTime + $slotSeconds <= $endTimeTs) {
                $slotEnd = $currentTime + $slotSeconds;
                $available = true;

                foreach ($bookedSlots as $booked) {
                    if ($currentTime < ($booked['end'] + $bufferSeconds) && $slotEnd > ($booked['start'] - $bufferSeconds)) {
                        $available = false;
                        break;
                    }
                }

                if ($available) {
                    $slotKey = date('H:i:s', $currentTime);
                    $slots[$slotKey] = [
                        'time' => $slotKey,
                        'display' => date('g:i A', $currentTime),
                    ];
                }

                $currentTime += $slotSeconds;
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => array_values($slots)], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        app()->log('Appointment slots error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to load available slots'], JSON_UNESCAPED_SLASHES);
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

function guidanceReportsNormalizedDate(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    return $value;
}

function guidanceReportsSummaryData(array $user, string $startDate = '', string $endDate = ''): array
{
    $db = guidanceDb();
    $userId = (int)($user['id'] ?? 0);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor' && $userId > 0;

    $startDate = guidanceReportsNormalizedDate($startDate);
    $endDate = guidanceReportsNormalizedDate($endDate);
    if ($startDate !== '' && $endDate !== '' && strcmp($startDate, $endDate) > 0) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }
    $hasDateFilter = $startDate !== '' && $endDate !== '';

    $caseFilterSql = '';
    $caseParams = [];
    if ($isCounselor) {
        $caseFilterSql .= ' AND c.counselor_id = ?';
        $caseParams[] = $userId;
    }
    if ($hasDateFilter) {
        $caseFilterSql .= ' AND c.created_at BETWEEN ? AND ?';
        $caseParams[] = $startDate . ' 00:00:00';
        $caseParams[] = $endDate . ' 23:59:59';
    }

    $statusStmt = $db->prepare(
        'SELECT c.status, COUNT(*) AS count FROM gm_cases c WHERE c.deleted_at IS NULL'
        . $caseFilterSql . ' GROUP BY c.status'
    );
    $statusStmt->execute($caseParams);
    $byStatus = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $severityStmt = $db->prepare(
        "SELECT c.severity, COUNT(*) AS count FROM gm_cases c WHERE c.deleted_at IS NULL "
        . "AND c.status NOT IN ('closed', 'archived')" . $caseFilterSql . ' GROUP BY c.severity'
    );
    $severityStmt->execute($caseParams);
    $bySeverity = $severityStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $categoryStmt = $db->prepare(
        "SELECT COALESCE(NULLIF(c.category, ''), 'uncategorized') AS category_name, COUNT(*) AS count "
        . 'FROM gm_cases c WHERE c.deleted_at IS NULL' . $caseFilterSql
        . ' GROUP BY category_name ORDER BY count DESC'
    );
    $categoryStmt->execute($caseParams);
    $byCategory = $categoryStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $totalCases = array_sum(array_map('intval', $byCategory));

    $categoryItems = [];
    foreach ($byCategory as $categoryName => $count) {
        $countInt = (int)$count;
        $categoryItems[] = [
            'name' => ucfirst(str_replace('_', ' ', (string)$categoryName)),
            'count' => $countInt,
            'pct' => $totalCases > 0 ? (int)round(($countInt / $totalCases) * 100) : 0,
            'color' => 'indigo',
        ];
    }

    $apptFilterSql = '';
    $apptParams = [];
    if ($isCounselor) {
        $apptFilterSql .= ' AND a.counselor_id = ?';
        $apptParams[] = $userId;
    }
    if ($hasDateFilter) {
        $apptFilterSql .= ' AND a.scheduled_date BETWEEN ? AND ?';
        $apptParams[] = $startDate;
        $apptParams[] = $endDate;
    }

    $apptStatusStmt = $db->prepare(
        'SELECT a.status, COUNT(*) AS count FROM gm_appointments a WHERE 1=1'
        . $apptFilterSql . ' GROUP BY a.status'
    );
    $apptStatusStmt->execute($apptParams);
    $apptByStatus = $apptStatusStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $upcomingStmt = $db->prepare(
        "SELECT COUNT(*) FROM gm_appointments a WHERE a.status IN ('scheduled', 'confirmed') "
        . 'AND a.scheduled_date >= CURDATE() AND a.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)'
        . ($isCounselor ? ' AND a.counselor_id = ?' : '')
    );
    $upcomingStmt->execute($isCounselor ? [$userId] : []);
    $upcomingAppointments = (int)($upcomingStmt->fetchColumn() ?: 0);

    $notesFilterSql = '';
    $notesParams = [];
    if ($isCounselor) {
        $notesFilterSql .= ' AND n.counselor_id = ?';
        $notesParams[] = $userId;
    }
    if ($hasDateFilter) {
        $notesFilterSql .= ' AND n.session_date BETWEEN ? AND ?';
        $notesParams[] = $startDate;
        $notesParams[] = $endDate;
    }

    $notesStmt = $db->prepare('SELECT COUNT(*) FROM gm_counselor_notes n WHERE 1=1' . $notesFilterSql);
    $notesStmt->execute($notesParams);
    $totalNotes = (int)($notesStmt->fetchColumn() ?: 0);

    $sessionStmt = $db->prepare('SELECT COALESCE(SUM(n.session_duration_minutes), 0) FROM gm_counselor_notes n WHERE 1=1' . $notesFilterSql);
    $sessionStmt->execute($notesParams);
    $totalSessionMinutes = (int)($sessionStmt->fetchColumn() ?: 0);

    $riskStmt = $db->prepare(
        "SELECT n.risk_level, COUNT(*) AS count FROM gm_counselor_notes n WHERE n.risk_level IS NOT NULL "
        . "AND n.risk_level != '' AND n.risk_level != 'none'" . $notesFilterSql
        . ' GROUP BY n.risk_level ORDER BY count DESC'
    );
    $riskStmt->execute($notesParams);
    $riskDistribution = $riskStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $trendParams = [];
    $trendFilterSql = '';
    if ($isCounselor) {
        $trendFilterSql .= ' AND c.counselor_id = ?';
        $trendParams[] = $userId;
    }
    $trendStmt = $db->prepare(
        "SELECT DATE_FORMAT(c.created_at, '%Y-%m') AS month_label, COUNT(*) AS opened, "
        . "SUM(CASE WHEN c.status = 'closed' THEN 1 ELSE 0 END) AS closed "
        . 'FROM gm_cases c WHERE c.deleted_at IS NULL AND c.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)'
        . $trendFilterSql . ' GROUP BY month_label ORDER BY month_label ASC'
    );
    $trendStmt->execute($trendParams);
    $trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $monthlyTrend = [];
    foreach ($trendRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $monthLabel = (string)($row['month_label'] ?? '');
        $monthlyTrend[] = [
            'label' => $monthLabel !== '' ? date('M Y', strtotime($monthLabel . '-01')) : '',
            'opened' => (int)($row['opened'] ?? 0),
            'closed' => (int)($row['closed'] ?? 0),
        ];
    }

    $counselorStats = [];
    if (in_array($role, ['admin', 'supervisor'], true)) {
        $caseloadStmt = $db->prepare(
            "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, "
            . "COUNT(CASE WHEN c.status NOT IN ('closed', 'archived') THEN 1 END) AS active, "
            . "COUNT(CASE WHEN c.status = 'closed' THEN 1 END) AS closed, "
            . 'COUNT(c.id) AS total '
            . 'FROM gm_users u '
            . 'LEFT JOIN gm_cases c ON c.counselor_id = u.id AND c.deleted_at IS NULL '
            . "WHERE u.role IN ('counselor', 'supervisor') AND u.deleted_at IS NULL "
            . 'GROUP BY u.id, name ORDER BY active DESC'
        );
        $caseloadStmt->execute();
        $counselorStats = $caseloadStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $referralStmt = $db->prepare(
        "SELECT COALESCE(NULLIF(c.referral_source, ''), 'walk-in') AS referral_source, COUNT(*) AS count "
        . 'FROM gm_cases c WHERE c.deleted_at IS NULL' . $caseFilterSql
        . ' GROUP BY referral_source ORDER BY count DESC'
    );
    $referralStmt->execute($caseParams);
    $byReferral = $referralStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $activeCases = (int)($byStatus['open'] ?? 0) + (int)($byStatus['in_progress'] ?? 0) + (int)($byStatus['on_hold'] ?? 0);

    return [
        'summary' => [
            'total_cases' => $totalCases,
            'active_cases' => $activeCases,
            'closed_cases' => (int)($byStatus['closed'] ?? 0),
            'critical_cases' => (int)($bySeverity['critical'] ?? 0),
            'high_priority_cases' => (int)($bySeverity['high'] ?? 0),
            'upcoming_appointments' => $upcomingAppointments,
            'total_notes' => $totalNotes,
            'total_session_hours' => round($totalSessionMinutes / 60, 1),
        ],
        'by_status' => $byStatus,
        'by_severity' => $bySeverity,
        'by_category' => $byCategory,
        'category_items' => $categoryItems,
        'appointments' => [
            'completed' => (int)($apptByStatus['completed'] ?? 0),
            'scheduled' => (int)($apptByStatus['scheduled'] ?? 0) + (int)($apptByStatus['confirmed'] ?? 0),
            'pending' => (int)($apptByStatus['pending'] ?? 0),
            'no_show' => (int)($apptByStatus['no_show'] ?? 0),
            'cancelled' => (int)($apptByStatus['cancelled'] ?? 0) + (int)($apptByStatus['rejected'] ?? 0),
            'upcoming' => $upcomingAppointments,
        ],
        'risk_distribution' => $riskDistribution,
        'monthly_trend' => $monthlyTrend,
        'counselor_stats' => $counselorStats,
        'by_referral' => $byReferral,
        'has_date_filter' => $hasDateFilter,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];
}

function apiGuidanceReportsSummary(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    try {
        $data = guidanceReportsSummaryData(
            $user,
            (string)guidanceInput('start_date', ''),
            (string)guidanceInput('end_date', '')
        );

        if (guidanceIsHtmx()) {
            header('Content-Type: text/html; charset=utf-8');
            echo guidanceRender('modules/guidance/partials/reports-summary.disyl', $data);
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        app()->log('Reports summary error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to generate report'], JSON_UNESCAPED_SLASHES);
    }
}

function guidanceReportsWriteCsvSection($handle, string $title, array $headers, array $rows): void
{
    fputcsv($handle, [$title]);
    fputcsv($handle, $headers);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fputcsv($handle, []);
}

function apiGuidanceReportsExport(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    try {
        $data = guidanceReportsSummaryData(
            $user,
            (string)guidanceInput('start_date', ''),
            (string)guidanceInput('end_date', '')
        );

        $filename = 'guidance-reports';
        if (!empty($data['has_date_filter'])) {
            $filename .= '-' . preg_replace('/[^0-9\-]/', '', (string)$data['start_date'])
                . '-to-' . preg_replace('/[^0-9\-]/', '', (string)$data['end_date']);
        }
        $filename .= '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            throw new RuntimeException('Unable to open export stream.');
        }

        fputcsv($handle, ['Guidance Reports Export']);
        if (!empty($data['has_date_filter'])) {
            fputcsv($handle, ['Date Range', (string)$data['start_date'], (string)$data['end_date']]);
        }
        fputcsv($handle, []);

        guidanceReportsWriteCsvSection($handle, 'Summary', ['Metric', 'Value'], [
            ['Total Cases', (string)($data['summary']['total_cases'] ?? 0)],
            ['Active Cases', (string)($data['summary']['active_cases'] ?? 0)],
            ['Closed Cases', (string)($data['summary']['closed_cases'] ?? 0)],
            ['Critical Cases', (string)($data['summary']['critical_cases'] ?? 0)],
            ['High Priority Cases', (string)($data['summary']['high_priority_cases'] ?? 0)],
            ['Upcoming Appointments', (string)($data['summary']['upcoming_appointments'] ?? 0)],
            ['Session Notes', (string)($data['summary']['total_notes'] ?? 0)],
            ['Session Hours', (string)($data['summary']['total_session_hours'] ?? 0)],
        ]);

        $statusRows = [];
        foreach ((array)($data['by_status'] ?? []) as $label => $count) {
            $statusRows[] = [ucfirst(str_replace('_', ' ', (string)$label)), (string)$count];
        }
        guidanceReportsWriteCsvSection($handle, 'Cases by Status', ['Status', 'Count'], $statusRows);

        $severityRows = [];
        foreach ((array)($data['by_severity'] ?? []) as $label => $count) {
            $severityRows[] = [ucfirst((string)$label), (string)$count];
        }
        guidanceReportsWriteCsvSection($handle, 'Cases by Severity', ['Severity', 'Count'], $severityRows);

        $categoryRows = [];
        foreach ((array)($data['category_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $categoryRows[] = [
                (string)($item['name'] ?? ''),
                (string)($item['count'] ?? 0),
                (string)($item['pct'] ?? 0) . '%',
            ];
        }
        guidanceReportsWriteCsvSection($handle, 'Cases by Category', ['Category', 'Count', 'Share'], $categoryRows);

        $appointmentRows = [];
        foreach ((array)($data['appointments'] ?? []) as $label => $count) {
            $appointmentRows[] = [ucfirst(str_replace('_', ' ', (string)$label)), (string)$count];
        }
        guidanceReportsWriteCsvSection($handle, 'Appointments', ['Metric', 'Count'], $appointmentRows);

        $riskRows = [];
        foreach ((array)($data['risk_distribution'] ?? []) as $label => $count) {
            $riskRows[] = [ucfirst((string)$label), (string)$count];
        }
        guidanceReportsWriteCsvSection($handle, 'Risk Distribution', ['Risk Level', 'Count'], $riskRows);

        $trendRows = [];
        foreach ((array)($data['monthly_trend'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $trendRows[] = [
                (string)($row['label'] ?? ''),
                (string)($row['opened'] ?? 0),
                (string)($row['closed'] ?? 0),
            ];
        }
        guidanceReportsWriteCsvSection($handle, 'Monthly Trend', ['Month', 'Opened', 'Closed'], $trendRows);

        $referralRows = [];
        foreach ((array)($data['by_referral'] ?? []) as $label => $count) {
            $referralRows[] = [ucfirst(str_replace('_', ' ', (string)$label)), (string)$count];
        }
        guidanceReportsWriteCsvSection($handle, 'Referral Sources', ['Source', 'Count'], $referralRows);

        $caseloadRows = [];
        foreach ((array)($data['counselor_stats'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $caseloadRows[] = [
                (string)($row['name'] ?? ''),
                (string)($row['active'] ?? 0),
                (string)($row['closed'] ?? 0),
                (string)($row['total'] ?? 0),
            ];
        }
        if (!empty($caseloadRows)) {
            guidanceReportsWriteCsvSection($handle, 'Counselor Caseload', ['Counselor', 'Active', 'Closed', 'Total'], $caseloadRows);
        }

        fclose($handle);
        exit;
    } catch (Throwable $e) {
        app()->log('Reports export error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Failed to export reports';
        return;
    }
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
    $status = trim((string)($input['value'] ?? ($input['status'] ?? '')));
    if ($status === '') {
        foreach ($input as $key => $value) {
            if ($key === 'tracker_item_id') {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $candidate = trim((string)$value);
            if (in_array($candidate, ['pending', 'submitted', 'verified', 'rejected'], true)) {
                $status = $candidate;
                break;
            }
        }
    }
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

function guidanceTrackerFindRow(int $trackerId): ?array
{
    if ($trackerId < 1) {
        return null;
    }

    $stmt = guidanceDb()->prepare(
        "SELECT t.*, c.code AS college_code, c.name AS college_name\n"
        . "FROM gm_trackers t\n"
        . "LEFT JOIN gm_colleges c ON t.college_id = c.id\n"
        . "WHERE t.id = ? LIMIT 1"
    );
    $stmt->execute([$trackerId]);
    $tracker = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($tracker) ? $tracker : null;
}

function guidanceTrackerImportCacheDir(): string
{
    $cacheDir = STORAGE_PATH . '/cache';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        throw new RuntimeException('Unable to prepare tracker import cache.');
    }

    return $cacheDir;
}

function guidanceTrackerImportErrorResponse(string $message, int $status = 422): void
{
    http_response_code($status);

    if (guidanceIsHtmx()) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</div>';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $message,
    ]);
}

function guidanceTrackerSanitizeCustomFieldName(string $header): string
{
    $column = 'custom_' . preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($header)));
    $column = preg_replace('/_+/', '_', $column);
    $column = trim((string)$column, '_');
    if ($column === '' || $column === 'custom') {
        $column = 'custom_field';
    }

    return substr($column, 0, 64);
}

function apiGuidanceTrackerImportPreview(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor']);
    $trackerId = (int)($params['id'] ?? 0);
    if ($trackerId < 1 || guidanceTrackerFindRow($trackerId) === null) {
        guidanceTrackerImportErrorResponse('Tracker not found', 404);
        return;
    }

    if (empty($_FILES['csv_file']) || (int)($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        guidanceTrackerImportErrorResponse('Please upload a valid CSV file.');
        return;
    }

    $file = $_FILES['csv_file'];
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'txt'], true)) {
        guidanceTrackerImportErrorResponse('Only CSV files are accepted.');
        return;
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    $handle = $tmpName !== '' ? @fopen($tmpName, 'r') : false;
    if ($handle === false) {
        guidanceTrackerImportErrorResponse('Failed to read the uploaded CSV file.', 500);
        return;
    }

    $headers = fgetcsv($handle);
    if (!is_array($headers) || count($headers) < 1) {
        fclose($handle);
        guidanceTrackerImportErrorResponse('CSV file must contain a header row.');
        return;
    }

    $headers = array_map(static function ($header): string {
        $clean = preg_replace('/^\x{FEFF}/u', '', (string)$header);
        return trim((string)$clean);
    }, $headers);

    $previewRows = [];
    $rowCount = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $rowCount++;
        if (count($previewRows) < 5) {
            $previewRows[] = $row;
        }
    }
    fclose($handle);

    try {
        $cacheDir = guidanceTrackerImportCacheDir();
    } catch (Throwable $e) {
        guidanceTrackerImportErrorResponse('Unable to prepare the import cache.', 500);
        return;
    }

    $tempFile = 'tracker-import-' . $trackerId . '-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true) . '-' . mt_rand()), 0, 12) . '.csv';
    $tempPath = $cacheDir . '/' . $tempFile;
    if (!move_uploaded_file($tmpName, $tempPath) && !@copy($tmpName, $tempPath)) {
        guidanceTrackerImportErrorResponse('Failed to stage the CSV file for import.', 500);
        return;
    }

    $db = guidanceDb();
    $knownColumns = [
        'student_id' => ['student id', 'student_id', 'id number', 'id no', 'student number', 'stud id'],
        'student_name' => ['student name', 'student_name', 'name', 'full name', 'fullname'],
        'college_id' => ['college', 'college_id', 'department', 'dept'],
        'year_level' => ['year level', 'year_level', 'year', 'grade', 'grade level'],
        'section' => ['section', 'sec', 'class'],
        'email' => ['email', 'email address', 'e-mail'],
        'phone' => ['phone', 'phone number', 'mobile', 'contact', 'contact number', 'cellphone'],
        'notes' => ['notes', 'remarks', 'comment', 'comments'],
    ];

    $customFieldStmt = $db->prepare("SELECT column_name, display_label FROM gm_tracker_custom_fields WHERE tracker_id = ?");
    $customFieldStmt->execute([$trackerId]);
    foreach ($customFieldStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $field) {
        if (!is_array($field)) {
            continue;
        }
        $columnName = trim((string)($field['column_name'] ?? ''));
        $displayLabel = strtolower(trim((string)($field['display_label'] ?? '')));
        if ($columnName === '') {
            continue;
        }
        $knownColumns[$columnName] = array_values(array_filter([$displayLabel]));
    }

    $mapping = [];
    foreach ($headers as $index => $header) {
        $headerLower = strtolower(trim((string)$header));
        $match = null;

        foreach ($knownColumns as $dbColumn => $aliases) {
            foreach ($aliases as $alias) {
                similar_text($headerLower, $alias, $similarity);
                if ($headerLower === $alias || $similarity > 80) {
                    $match = $dbColumn;
                    break 2;
                }
            }
        }

        $mapping[] = [
            'csv_index' => $index,
            'csv_header' => $header,
            'suggested_column' => $match,
            'action' => $match !== null ? 'map' : 'create_new',
        ];
    }

    $payload = [
        'tracker_id' => $trackerId,
        'temp_file' => $tempFile,
        'headers' => $headers,
        'mapping' => $mapping,
        'preview_rows' => $previewRows,
        'total_rows' => $rowCount,
    ];

    if (!guidanceIsHtmx()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'data' => $payload,
        ]);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/tracker-import-mapping.disyl', $payload);
}

function apiGuidanceTrackerImportExecute(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor']);
    $trackerId = (int)($params['id'] ?? 0);
    if ($trackerId < 1 || guidanceTrackerFindRow($trackerId) === null) {
        guidanceTrackerImportErrorResponse('Tracker not found', 404);
        return;
    }

    $tempFile = basename((string)guidanceInput('temp_file', ''));
    $mappingRaw = guidanceInput('mapping', '[]');
    $mapping = is_string($mappingRaw) ? json_decode($mappingRaw, true) : $mappingRaw;
    if ($tempFile === '') {
        guidanceTrackerImportErrorResponse('Import session expired. Please upload the CSV again.');
        return;
    }
    if (!is_array($mapping) || $mapping === []) {
        guidanceTrackerImportErrorResponse('Invalid column mapping.');
        return;
    }

    $tempPath = STORAGE_PATH . '/cache/' . $tempFile;
    if (!is_file($tempPath)) {
        guidanceTrackerImportErrorResponse('Import session expired. Please upload the CSV again.');
        return;
    }

    $db = guidanceDb();

    $collegeLookup = [];
    foreach ($db->query("SELECT id, name, code FROM gm_colleges")?->fetchAll(PDO::FETCH_ASSOC) ?: [] as $college) {
        if (!is_array($college)) {
            continue;
        }
        $id = (int)($college['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $name = strtolower(trim((string)($college['name'] ?? '')));
        $code = strtolower(trim((string)($college['code'] ?? '')));
        if ($name !== '') {
            $collegeLookup[$name] = $id;
        }
        if ($code !== '') {
            $collegeLookup[$code] = $id;
        }
        if ($name !== '' && $code !== '') {
            $collegeLookup[$code . ' - ' . $name] = $id;
        }
    }

    $columnMap = [];
    $newColumns = [];
    foreach ($mapping as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $csvIndex = (int)($entry['csv_index'] ?? -1);
        $action = (string)($entry['action'] ?? 'skip');
        $csvHeader = trim((string)($entry['csv_header'] ?? ''));
        $dbColumn = trim((string)($entry['db_column'] ?? ($entry['suggested_column'] ?? '')));
        if ($csvIndex < 0 || $action === 'skip') {
            continue;
        }

        if ($action === 'create_new') {
            $columnName = guidanceTrackerSanitizeCustomFieldName($csvHeader);
            $existsStmt = $db->prepare("SELECT id FROM gm_tracker_custom_fields WHERE tracker_id = ? AND column_name = ? LIMIT 1");
            $existsStmt->execute([$trackerId, $columnName]);
            if (!$existsStmt->fetchColumn()) {
                try {
                    $db->exec("ALTER TABLE gm_tracker_students ADD COLUMN `{$columnName}` VARCHAR(255) DEFAULT NULL");
                } catch (Throwable $e) {
                    app()->log('Tracker import column creation warning: ' . $e->getMessage(), 'warning');
                }

                $registerStmt = $db->prepare(
                    "INSERT IGNORE INTO gm_tracker_custom_fields (tracker_id, column_name, display_label, field_type, source, created_at)\n"
                    . "VALUES (?, ?, ?, 'text', 'csv_import', NOW())"
                );
                $registerStmt->execute([$trackerId, $columnName, ($csvHeader !== '' ? $csvHeader : ucfirst(str_replace('_', ' ', $columnName)))]);
                $newColumns[] = $columnName;
            }

            $columnMap[$csvIndex] = $columnName;
            continue;
        }

        if ($dbColumn !== '') {
            $columnMap[$csvIndex] = $dbColumn;
        }
    }

    if ($columnMap === []) {
        guidanceTrackerImportErrorResponse('Map at least one CSV column before importing.');
        return;
    }

    $handle = @fopen($tempPath, 'r');
    if ($handle === false) {
        guidanceTrackerImportErrorResponse('Failed to reopen the staged CSV file.', 500);
        return;
    }

    fgetcsv($handle);

    $itemStmt = $db->prepare("SELECT id FROM gm_tracker_items WHERE tracker_id = ? ORDER BY sort_order, id");
    $itemStmt->execute([$trackerId]);
    $trackerItemIds = array_map(static fn ($row): int => (int)($row['id'] ?? 0), $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    $trackerItemIds = array_values(array_filter($trackerItemIds, static fn (int $id): bool => $id > 0));

    $imported = 0;
    $skipped = 0;
    $errors = [];
    $lineNumber = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $lineNumber++;
        if (empty(array_filter($row, static fn ($value): bool => trim((string)$value) !== ''))) {
            $skipped++;
            continue;
        }

        $studentData = [
            'tracker_id' => $trackerId,
        ];
        $hasStudentName = false;

        foreach ($columnMap as $csvIndex => $dbColumn) {
            $value = isset($row[$csvIndex]) ? trim((string)$row[$csvIndex]) : '';
            if ($dbColumn === 'college_id' && $value !== '') {
                $value = (string)($collegeLookup[strtolower($value)] ?? '');
            }
            if ($dbColumn === 'student_name' && $value !== '') {
                $hasStudentName = true;
            }
            $studentData[$dbColumn] = $value !== '' ? $value : null;
        }

        if (!$hasStudentName) {
            $skipped++;
            continue;
        }

        $studentData['created_at'] = date('Y-m-d H:i:s');
        $studentData['updated_at'] = date('Y-m-d H:i:s');

        try {
            $columns = array_keys($studentData);
            $sql = 'INSERT INTO gm_tracker_students (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $insertStmt = $db->prepare($sql);
            $insertStmt->execute(array_values($studentData));
            $trackerStudentId = (int)$db->lastInsertId();

            if ($trackerStudentId > 0 && $trackerItemIds !== []) {
                $submissionStmt = $db->prepare("INSERT IGNORE INTO gm_tracker_submissions (tracker_student_id, tracker_item_id, status, created_at, updated_at) VALUES (?, ?, 'pending', NOW(), NOW())");
                foreach ($trackerItemIds as $itemId) {
                    $submissionStmt->execute([$trackerStudentId, $itemId]);
                }
            }

            $imported++;
        } catch (Throwable $e) {
            $errors[] = 'Row ' . $lineNumber . ': ' . $e->getMessage();
            if (count($errors) >= 10) {
                break;
            }
        }
    }

    fclose($handle);
    @unlink($tempPath);

    $payload = [
        'tracker_id' => $trackerId,
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors,
        'new_columns' => $newColumns,
    ];

    if (!guidanceIsHtmx()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'data' => $payload,
        ]);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/tracker-import-result.disyl', $payload);
}

function apiGuidanceTrackerImportTemplate(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor']);
    $trackerId = (int)($params['id'] ?? 0);
    $tracker = guidanceTrackerFindRow($trackerId);
    if ($tracker === null) {
        http_response_code(404);
        echo 'Tracker not found';
        return;
    }

    $db = guidanceDb();
    $collegeRows = $db->query("SELECT code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name");
    $colleges = $collegeRows ? ($collegeRows->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($tracker['name'] ?? 'tracker')) . '_import_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        echo 'Failed to open export stream';
        return;
    }

    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Student ID', 'Student Name', 'College', 'Year Level', 'Section', 'Email', 'Phone']);

    $sampleCollege = 'CAS - College of Arts and Sciences';
    if (is_array($colleges) && $colleges !== [] && is_array($colleges[0])) {
        $sampleCollege = trim((string)($colleges[0]['code'] ?? '')) . ' - ' . trim((string)($colleges[0]['name'] ?? ''));
        $sampleCollege = trim($sampleCollege, ' -');
        if ($sampleCollege === '') {
            $sampleCollege = 'CAS - College of Arts and Sciences';
        }
    }

    fputcsv($output, ['2024-0001', 'Juan Dela Cruz', $sampleCollege, '1st Year', 'A', 'juan@example.com', '09171234567']);
    fputcsv($output, ['2024-0002', 'Maria Santos', $sampleCollege, '2nd Year', 'B', 'maria@example.com', '09181234567']);
    fputcsv($output, []);
    fputcsv($output, ['--- INSTRUCTIONS (delete these rows before importing) ---']);
    fputcsv($output, ['Student ID', 'School ID number (optional)']);
    fputcsv($output, ['Student Name', 'Full name (required)']);
    fputcsv($output, ['College', 'Must match one of the colleges below']);
    fputcsv($output, ['Year Level', 'Example: 1st Year, 2nd Year, Graduate, SHS-11']);
    fputcsv($output, ['Section', 'Section letter or name (optional)']);
    fputcsv($output, ['Email', 'Student email address (optional)']);
    fputcsv($output, ['Phone', 'Mobile number (optional)']);
    fputcsv($output, []);
    fputcsv($output, ['--- AVAILABLE COLLEGES ---']);
    foreach ($colleges as $college) {
        if (!is_array($college)) {
            continue;
        }
        fputcsv($output, [trim((string)($college['code'] ?? '')) . ' - ' . trim((string)($college['name'] ?? ''))]);
    }

    fclose($output);
    exit;
}

function apiGuidanceTrackerExport(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $trackerId = (int)($params['id'] ?? 0);
    $tracker = guidanceTrackerFindRow($trackerId);
    if ($tracker === null) {
        http_response_code(404);
        echo 'Tracker not found';
        return;
    }

    $db = guidanceDb();
    $itemsStmt = $db->prepare("SELECT id, name FROM gm_tracker_items WHERE tracker_id = ? ORDER BY sort_order, id");
    $itemsStmt->execute([$trackerId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $customFieldStmt = $db->prepare("SELECT column_name, display_label FROM gm_tracker_custom_fields WHERE tracker_id = ? ORDER BY id");
    $customFieldStmt->execute([$trackerId]);
    $customFields = $customFieldStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $studentStmt = $db->prepare(
        "SELECT ts.*, c.name AS college_name\n"
        . "FROM gm_tracker_students ts\n"
        . "LEFT JOIN gm_colleges c ON ts.college_id = c.id\n"
        . "WHERE ts.tracker_id = ?\n"
        . "ORDER BY ts.student_name, ts.id"
    );
    $studentStmt->execute([$trackerId]);
    $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $submissionMap = [];
    if ($students !== [] && $items !== []) {
        $submissionStmt = $db->prepare(
            "SELECT sub.tracker_student_id, sub.tracker_item_id, sub.status\n"
            . "FROM gm_tracker_submissions sub\n"
            . "JOIN gm_tracker_students ts ON ts.id = sub.tracker_student_id\n"
            . "JOIN gm_tracker_items ti ON ti.id = sub.tracker_item_id\n"
            . "WHERE ts.tracker_id = ? AND ti.tracker_id = ?"
        );
        $submissionStmt->execute([$trackerId, $trackerId]);
        foreach ($submissionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $submission) {
            if (!is_array($submission)) {
                continue;
            }
            $studentId = (int)($submission['tracker_student_id'] ?? 0);
            $itemId = (int)($submission['tracker_item_id'] ?? 0);
            if ($studentId < 1 || $itemId < 1) {
                continue;
            }
            if (!isset($submissionMap[$studentId])) {
                $submissionMap[$studentId] = [];
            }
            $submissionMap[$studentId][$itemId] = (string)($submission['status'] ?? 'pending');
        }
    }

    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($tracker['name'] ?? 'tracker')) . '_export.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        echo 'Failed to open export stream';
        return;
    }

    fwrite($output, "\xEF\xBB\xBF");

    $headerRow = ['Student ID', 'Student Name', 'College', 'Year Level', 'Section', 'Email', 'Phone'];
    foreach ($customFields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $headerRow[] = (string)($field['display_label'] ?? 'Custom Field');
    }
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $headerRow[] = (string)($item['name'] ?? 'Item') . ' (Status)';
    }
    $headerRow[] = 'Completion %';
    fputcsv($output, $headerRow);

    foreach ($students as $student) {
        if (!is_array($student)) {
            continue;
        }

        $row = [
            $student['student_id'] ?? '',
            $student['student_name'] ?? '',
            $student['college_name'] ?? '',
            $student['year_level'] ?? '',
            $student['section'] ?? '',
            $student['email'] ?? '',
            $student['phone'] ?? '',
        ];

        foreach ($customFields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $columnName = (string)($field['column_name'] ?? '');
            $row[] = $columnName !== '' ? ($student[$columnName] ?? '') : '';
        }

        $completed = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = (int)($item['id'] ?? 0);
            $status = $submissionMap[(int)($student['id'] ?? 0)][$itemId] ?? 'pending';
            $row[] = $status;
            if (in_array($status, ['submitted', 'verified'], true)) {
                $completed++;
            }
        }

        $row[] = count($items) > 0 ? round(($completed / count($items)) * 100) . '%' : 'N/A';
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
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

function guidanceSettingsEntitlementSummary(): array
{
    $tenantId = function_exists('moduleTenantSettingsTenantId') ? (int)(moduleTenantSettingsTenantId() ?? 0) : 0;
    $entitlement = $tenantId > 0 ? moduleTenantEntitlementStatus('guidance', $tenantId) : [
        'catalog_managed' => false,
        'required' => false,
        'allowed' => false,
        'approval_status' => 'unmanaged',
        'commercial_mode' => 'freemium',
        'entitlement_status' => 'unknown',
        'tier' => 'free',
        'reason' => 'tenant_context_missing',
    ];
    $request = $tenantId > 0 ? moduleLatestAccessRequestForTenant('guidance', $tenantId) : null;
    $licenseState = $tenantId > 0 ? moduleLicenseActivationStateForTenant('guidance', $tenantId) : [];
    $tier = strtolower(trim((string)($entitlement['tier'] ?? '')));
    if ($tier === '' || $tier === 'freemium') {
        $tier = 'free';
    }

    return [
        'tenant_id' => $tenantId,
        'manageable' => $tenantId > 0,
        'plan_tier' => $tier,
        'plan_label' => $tier === 'pro' ? 'Guidance Pro' : 'Guidance Free',
        'entitlement' => $entitlement,
        'request' => is_array($request) ? $request : [],
        'license_state' => is_array($licenseState) ? $licenseState : [],
    ];
}

function guidanceProAccessResponse(string $message, bool $ok = true, int $status = 200, array $extra = []): void
{
    if (app()->isHtmx()) {
        http_response_code($status);
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => $message, 'type' => $ok ? 'success' : 'error'],
            'refreshGuidanceProAccess' => true,
        ]));
        echo '';
        return;
    }

    $payload = array_merge(['success' => $ok, 'message' => $message], $extra);
    if (!$ok && !isset($payload['error'])) {
        $payload['error'] = $message;
    }
    app()->json($payload, $status);
}

function apiGuidanceRequestProAccess(): void
{
    $user = guidanceRequireStaff(['admin']);
    $summary = guidanceSettingsEntitlementSummary();
    if (empty($summary['manageable'])) {
        guidanceProAccessResponse('Guidance Pro licensing requires an active tenant context.', false, 400);
        return;
    }

    $currentTier = strtolower(trim((string)($summary['plan_tier'] ?? 'free')));
    if ($currentTier === 'pro') {
        guidanceProAccessResponse('Guidance Pro is already active for this tenant.');
        return;
    }

    $input = app()->input();
    $requestNotes = trim((string)($input['request_notes'] ?? ''));
    $licenseKey = trim((string)($input['license_key'] ?? ''));

    $result = submitModuleAccessRequestForTenant('guidance', (int)$summary['tenant_id'], [
        'requested_mode' => 'pro',
        'request_notes' => $requestNotes,
        'license_key' => $licenseKey,
        'requested_by_user_id' => (int)($user['id'] ?? 0),
        'metadata' => ['via' => 'guidanceApiRequestProAccess'],
    ]);

    if (empty($result['ok'])) {
        guidanceProAccessResponse((string)($result['error'] ?? 'Could not submit the Guidance Pro request.'), false, 400);
        return;
    }

    $existingRequest = $summary['request'];
    $requestStatus = strtolower(trim((string)($existingRequest['status'] ?? '')));
    $message = $requestStatus === 'pending'
        ? 'Guidance Pro request updated and queued for review.'
        : 'Guidance Pro request submitted for review.';

    guidanceProAccessResponse($message, true, 200, [
        'request' => $result['request'] ?? null,
    ]);
}

function guidanceManagedModules(): array
{
    $managed = [];

    foreach (discoverModules() as $moduleId => $manifest) {
        $resolvedId = trim((string)($manifest['id'] ?? $moduleId));
        if ($resolvedId === '' || $resolvedId === 'guidance') {
            continue;
        }

        $depends = is_array($manifest['depends'] ?? null) ? $manifest['depends'] : [];
        $depends = array_values(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, $depends)));

        if (!in_array('guidance', $depends, true) && !str_starts_with($resolvedId, 'guidance-')) {
            continue;
        }

        $managed[$resolvedId] = $manifest;
    }

    uasort($managed, static function (array $left, array $right): int {
        $leftName = (string)($left['name'] ?? $left['id'] ?? '');
        $rightName = (string)($right['name'] ?? $right['id'] ?? '');
        return strcasecmp($leftName, $rightName);
    });

    return $managed;
}

function guidanceManagedTenantId(): int
{
    $tenantId = function_exists('moduleTenantSettingsTenantId') ? (int)(moduleTenantSettingsTenantId() ?? 0) : 0;
    if ($tenantId > 0) {
        return $tenantId;
    }

    try {
        $tenantId = (int)(app()->tenant()->current() ?? 0);
    } catch (Throwable $e) {
        $tenantId = 0;
    }

    return $tenantId > 0 ? $tenantId : 0;
}

function guidanceManagedModule(string $moduleId): array
{
    $moduleId = trim($moduleId);
    $modules = guidanceManagedModules();

    if ($moduleId === '' || !isset($modules[$moduleId])) {
        throw new RuntimeException('Guidance add-on not found.');
    }

    return $modules[$moduleId];
}

function guidanceManagedModuleIsTruthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string)$value));
    return !in_array($normalized, ['', '0', 'false', 'off', 'no', 'null'], true);
}

function guidanceManagedModuleFieldOptions(array $field): array
{
    $rawOptions = $field['options'] ?? [];
    if (!is_array($rawOptions)) {
        return [];
    }

    $options = [];
    foreach ($rawOptions as $value => $option) {
        $optionValue = '';
        $optionLabel = '';

        if (is_int($value)) {
            if (is_string($option) || is_numeric($option)) {
                $optionValue = (string)$option;
                $optionLabel = (string)$option;
            } elseif (is_array($option)) {
                $optionValue = trim((string)($option['value'] ?? $option['key'] ?? ''));
                $optionLabel = trim((string)($option['label'] ?? $option['name'] ?? $optionValue));
            }
        } else {
            if (is_array($option)) {
                $optionValue = trim((string)($option['value'] ?? $value));
                $optionLabel = trim((string)($option['label'] ?? $option['name'] ?? $optionValue));
            } else {
                $optionValue = trim((string)$value);
                $optionLabel = trim((string)$option);
                if ($optionLabel === '') {
                    $optionLabel = $optionValue;
                }
            }
        }

        if ($optionValue === '') {
            continue;
        }

        $options[] = [
            'value' => $optionValue,
            'label' => $optionLabel !== '' ? $optionLabel : $optionValue,
        ];
    }

    return $options;
}

function guidanceManagedModuleEntitlementStatus(string $moduleId, array $manifest, int $tenantId, ?array $catalogEntry = null, ?array $entitlementRow = null): array
{
    if ($catalogEntry === null) {
        $catalogEntry = moduleCatalogEntry($moduleId);
    }

    $commercialMode = strtolower(trim((string)($catalogEntry['commercial_mode'] ?? ($manifest['commercial_mode'] ?? 'bundled'))));
    if ($commercialMode === '') {
        $commercialMode = 'bundled';
    }

    $status = [
        'catalog_managed' => is_array($catalogEntry),
        'required' => false,
        'allowed' => true,
        'approval_status' => is_array($catalogEntry)
            ? strtolower(trim((string)($catalogEntry['approval_status'] ?? 'pending')))
            : 'unmanaged',
        'commercial_mode' => $commercialMode,
        'entitlement_status' => 'not_required',
        'tier' => null,
        'reason' => '',
    ];

    $catalogApproved = is_array($catalogEntry)
        && ($status['approval_status'] ?? 'pending') === 'approved';
    if ($tenantId <= 0 || !$catalogApproved) {
        return $status;
    }

    $status['required'] = true;

    $row = $entitlementRow;
    if ($row === null) {
        $row = moduleTenantEntitlementRow($moduleId, $tenantId);
    }
    if (!is_array($row)) {
        $status['allowed'] = false;
        $status['entitlement_status'] = 'missing';
        $status['tier'] = moduleCatalogDefaultEntitlementTier($moduleId, $commercialMode);
        $status['reason'] = 'tenant_entitlement_missing';
        return $status;
    }

    $entitlementState = strtolower(trim((string)($row['status'] ?? 'active')));
    $expiresAt = trim((string)($row['expires_at'] ?? ''));
    if ($expiresAt !== '') {
        $expiresTs = strtotime($expiresAt);
        if ($expiresTs !== false && $expiresTs < time() && in_array($entitlementState, ['active', 'trial'], true)) {
            $entitlementState = 'expired';
        }
    }

    $status['tier'] = trim((string)($row['tier'] ?? moduleCatalogDefaultEntitlementTier($moduleId, $commercialMode)));
    $status['entitlement_status'] = $entitlementState !== '' ? $entitlementState : 'unknown';
    $status['allowed'] = in_array($entitlementState, ['active', 'trial'], true);
    $status['reason'] = $status['allowed'] ? '' : 'tenant_entitlement_' . $status['entitlement_status'];

    return $status;
}

function guidanceManagedModuleRenderSettings(array $manifest, array $settings): array
{
    $rendered = [];
    $fields = moduleEditableSettingsFields($manifest);

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $options = guidanceManagedModuleFieldOptions($field);
        if ($type === 'select' && $options === []) {
            $type = 'text';
        }

        $currentValue = array_key_exists($key, $settings) ? $settings[$key] : ($field['default'] ?? '');
        $isCheckbox = in_array($type, ['checkbox', 'bool', 'boolean'], true);
        $isSelect = ($type === 'select');
        $inputType = 'text';
        if (in_array($type, ['number', 'int', 'integer'], true)) {
            $inputType = 'number';
        } elseif ($type === 'email') {
            $inputType = 'email';
        } elseif ($type === 'password') {
            $inputType = 'password';
        }

        $renderedOptions = [];
        foreach ($options as $option) {
            $optionValue = (string)($option['value'] ?? '');
            if ($optionValue === '') {
                continue;
            }

            $renderedOptions[] = [
                'value' => $optionValue,
                'label' => (string)($option['label'] ?? $optionValue),
                'selected' => ((string)$currentValue === $optionValue),
            ];
        }

        $rendered[] = [
            'key' => $key,
            'label' => (string)($field['label'] ?? ucwords(str_replace(['_', '-'], ' ', $key))),
            'description' => (string)($field['description'] ?? ''),
            'default' => (string)($field['default'] ?? ''),
            'is_checkbox' => $isCheckbox,
            'is_select' => $isSelect,
            'is_input' => !$isCheckbox && !$isSelect,
            'input_type' => $inputType,
            'current_value' => $isCheckbox ? '' : (string)$currentValue,
            'is_checked' => $isCheckbox ? guidanceManagedModuleIsTruthy($currentValue) : false,
            'options' => $renderedOptions,
        ];
    }

    return $rendered;
}

function guidanceBuildManagedModuleCard(string $moduleId, array $manifest, int $tenantId, ?array $catalogEntry = null, ?array $entitlementRow = null): array
{
    $manageable = $tenantId > 0;
    if ($catalogEntry === null) {
        $catalogEntry = moduleCatalogEntry($moduleId);
    }
    if ($entitlementRow === null && $manageable) {
        $entitlementRow = moduleTenantEntitlementRow($moduleId, $tenantId);
    }
    $entitlement = guidanceManagedModuleEntitlementStatus($moduleId, $manifest, $tenantId, $catalogEntry, $entitlementRow);
    $isEnabled = $manageable ? isModuleEnabledForTenant($moduleId, $tenantId) : !empty($manifest['_enabled']);
    $request = $manageable ? moduleLatestAccessRequestForTenant($moduleId, $tenantId) : null;
    $licenseState = $manageable ? moduleLicenseActivationStateForTenant($moduleId, $tenantId) : [];
    $commercialMode = strtolower(trim((string)($manifest['commercial_mode'] ?? ($entitlement['commercial_mode'] ?? 'bundled'))));
    if ($commercialMode === '') {
        $commercialMode = strtolower(trim((string)($entitlement['commercial_mode'] ?? 'bundled')));
    }
    if ($commercialMode === '') {
        $commercialMode = 'bundled';
    }

    $allowSelfService = moduleCatalogModeAllowsSelfService($commercialMode);
    $accessBlocked = !empty($entitlement['required']) && empty($entitlement['allowed']);
    $settings = $manageable ? getModuleSettingsForTenant($moduleId, $tenantId) : getModuleSettings($moduleId);
    $renderedSettings = guidanceManagedModuleRenderSettings($manifest, is_array($settings) ? $settings : []);
    $canRequestAccess = $manageable && $accessBlocked && !$allowSelfService;
    $canEnable = $manageable && (!$accessBlocked || $allowSelfService);
    $canManageSettings = $manageable && (!$accessBlocked || $allowSelfService) && $renderedSettings !== [];
    $nav = is_array($manifest['nav'] ?? null) ? array_values($manifest['nav']) : [];
    $icon = trim((string)($manifest['icon'] ?? ''));
    if ($icon === '' && isset($nav[0]) && is_array($nav[0])) {
        $icon = trim((string)($nav[0]['icon'] ?? ''));
    }
    if ($icon === '' || !str_contains($icon, 'fa')) {
        $icon = 'fas fa-puzzle-piece';
    }

    $requestStatus = strtolower(trim((string)($request['status'] ?? '')));
    $requestLabel = $requestStatus !== '' ? ucwords(str_replace('_', ' ', $requestStatus)) : 'None';
    $licenseRef = trim((string)($request['license_ref'] ?? ($licenseState['license_ref'] ?? '')));

    return [
        'id' => $moduleId,
        'name' => (string)($manifest['name'] ?? $moduleId),
        'description' => (string)($manifest['description'] ?? ''),
        'version' => (string)($manifest['version'] ?? '0.0.0'),
        'author' => (string)($manifest['author'] ?? ''),
        'icon' => $icon,
        'commercial_mode' => $commercialMode,
        'commercial_mode_label' => ucwords(str_replace('_', ' ', $commercialMode)),
        'is_enabled' => $isEnabled,
        'manageable' => $manageable,
        'settings' => $renderedSettings,
        'card_state' => $isEnabled ? 'active' : ($accessBlocked ? 'blocked' : 'inactive'),
        'action_state' => ($manageable && $isEnabled) ? 'disable' : ($canEnable ? 'enable' : 'none'),
        'settings_state' => $canManageSettings ? 'available' : 'hidden',
        'access_state' => $accessBlocked ? ($allowSelfService ? 'self-service' : 'blocked') : 'none',
        'request_form_state' => $canRequestAccess ? 'available' : 'hidden',
        'catalog_managed' => !empty($entitlement['catalog_managed']),
        'catalog_status' => (string)($entitlement['approval_status'] ?? 'unmanaged'),
        'entitlement_required' => !empty($entitlement['required']),
        'entitlement_allowed' => !empty($entitlement['allowed']),
        'entitlement_status' => (string)($entitlement['entitlement_status'] ?? 'not_required'),
        'entitlement_reason' => (string)($entitlement['reason'] ?? ''),
        'access_blocked' => $accessBlocked,
        'access_will_self_activate' => $accessBlocked && $allowSelfService,
        'access_blocked_message' => $accessBlocked ? 'This add-on requires tenant access before it can be activated.' : '',
        'access_will_self_activate_message' => ($accessBlocked && $allowSelfService)
            ? 'Activating this add-on will automatically provision tenant access because it is available in self-service mode.'
            : '',
        'access_request_action' => $canRequestAccess ? '/api/modules/' . $moduleId . '/access-request' : '',
        'enable_action' => $canEnable ? '/api/modules/' . $moduleId . '/enable' : '',
        'disable_action' => ($manageable && $isEnabled) ? '/api/modules/' . $moduleId . '/disable' : '',
        'configure_label' => $canManageSettings ? 'Configure' : '',
        'can_request_access' => $canRequestAccess,
        'can_enable' => $canEnable,
        'can_manage_settings' => $canManageSettings,
        'request_status' => $requestStatus,
        'request_label' => $requestLabel,
        'request_notes' => (string)($request['request_notes'] ?? ''),
        'review_notes' => (string)($request['review_notes'] ?? ''),
        'license_ref' => $licenseRef,
    ];
}

function guidanceBuildManagedModuleList(): array
{
    $tenantId = guidanceManagedTenantId();
    $modules = [];

    foreach (guidanceManagedModules() as $moduleId => $manifest) {
        $catalogEntry = moduleCatalogEntry($moduleId);
        $entitlementRow = $tenantId > 0 ? moduleTenantEntitlementRow($moduleId, $tenantId) : null;
        $modules[] = guidanceBuildManagedModuleCard($moduleId, $manifest, $tenantId, $catalogEntry, $entitlementRow);
    }

    return $modules;
}

function guidanceManagedModuleList(): array
{
    return guidanceBuildManagedModuleList();
}

function guidanceManagedModuleSaveSettings(string $moduleId, array $input): array
{
    $manifest = guidanceManagedModule($moduleId);
    $tenantId = guidanceManagedTenantId();
    if ($tenantId <= 0) {
        throw new RuntimeException('Guidance add-on settings require an active tenant context.');
    }

    $catalogEntry = moduleCatalogEntry($moduleId);
    $entitlementRow = moduleTenantEntitlementRow($moduleId, $tenantId);
    $entitlement = guidanceManagedModuleEntitlementStatus($moduleId, $manifest, $tenantId, $catalogEntry, $entitlementRow);
    $commercialMode = strtolower(trim((string)($manifest['commercial_mode'] ?? ($entitlement['commercial_mode'] ?? 'bundled'))));
    $selfService = moduleCatalogModeAllowsSelfService($commercialMode);
    if (!empty($entitlement['required']) && empty($entitlement['allowed']) && !$selfService) {
        throw new RuntimeException('Request access to this add-on before saving its settings.');
    }

    $fields = moduleEditableSettingsFields($manifest);
    if ($fields === []) {
        throw new RuntimeException('This add-on does not expose editable settings.');
    }

    $existing = getModuleSettingsForTenant($moduleId, $tenantId);
    $newSettings = is_array($existing) ? $existing : [];
    $allowedKeys = [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $allowedKeys[$key] = $field;
    }

    foreach ($allowedKeys as $key => $field) {
        if (!array_key_exists($key, $input)) {
            continue;
        }

        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        $fieldOptions = guidanceManagedModuleFieldOptions($field);
        if ($type === 'select' && $fieldOptions === []) {
            $type = 'text';
        }

        $raw = $input[$key];
        if (in_array($type, ['checkbox', 'bool', 'boolean'], true)) {
            $newSettings[$key] = guidanceManagedModuleIsTruthy($raw);
            continue;
        }

        if (in_array($type, ['number', 'int', 'integer'], true)) {
            $newSettings[$key] = (string)(0 + (float)$raw);
            continue;
        }

        if ($type === 'select' && $fieldOptions !== []) {
            $allowedValues = [];
            foreach ($fieldOptions as $option) {
                $allowedValues[(string)($option['value'] ?? '')] = true;
            }

            $value = trim((string)$raw);
            if (!isset($allowedValues[$value])) {
                continue;
            }

            $newSettings[$key] = $value;
            continue;
        }

        $newSettings[$key] = trim((string)$raw);
    }

    if (!saveTenantModuleSettingsForTenant($moduleId, $tenantId, $newSettings)) {
        throw new RuntimeException('Failed to save add-on settings.');
    }

    return getModuleSettingsForTenant($moduleId, $tenantId);
}

function guidanceModuleManagementResponse(string $message, bool $ok = true, int $status = 200, array $extra = []): void
{
    if (guidanceIsHtmx()) {
        http_response_code($status);
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => $message, 'type' => $ok ? 'success' : 'error'],
            'refreshGuidanceModules' => true,
        ]));
        echo '';
        return;
    }

    $payload = array_merge(['success' => $ok, 'message' => $message], $extra);
    if (!$ok && !isset($payload['error'])) {
        $payload['error'] = $message;
    }

    app()->json($payload, $status);
}

function pageGuidanceSettings(): void
{
    $user = guidanceRequireStaff(['admin']);
    $settings = guidanceGetAllSettings();
    $proAccess = guidanceSettingsEntitlementSummary();
    echo guidanceRender('modules/guidance/pages/settings.disyl', array_merge(
        guidanceBasePageContext($user, 'Settings', 'settings'),
        [
            'settings' => $settings,
            'is_pro' => guidanceIsPro(),
            'pro_access' => $proAccess,
            'guidance_modules' => guidanceBuildManagedModuleList(),
        ]
    ));
}

function pageGuidanceSettingsModules(): void
{
    guidanceRequireStaff(['admin']);

    echo guidanceRender('modules/guidance/partials/modules-list.disyl', [
        'modules' => guidanceBuildManagedModuleList(),
    ]);
}

function apiGuidanceEnableModule(array $params = []): void
{
    guidanceRequireStaff(['admin']);

    try {
        $manifest = guidanceManagedModule((string)($params['id'] ?? ''));
        $moduleId = (string)($manifest['id'] ?? '');
        $tenantId = guidanceManagedTenantId();
        if ($tenantId <= 0) {
            throw new RuntimeException('Guidance add-ons require an active tenant context.');
        }

        $catalogEntry = moduleCatalogEntry($moduleId);
        $entitlementRow = moduleTenantEntitlementRow($moduleId, $tenantId);
        $entitlement = guidanceManagedModuleEntitlementStatus($moduleId, $manifest, $tenantId, $catalogEntry, $entitlementRow);
        $commercialMode = strtolower(trim((string)($manifest['commercial_mode'] ?? ($entitlement['commercial_mode'] ?? 'bundled'))));
        if (!empty($entitlement['required']) && empty($entitlement['allowed'])) {
            if (moduleCatalogModeAllowsSelfService($commercialMode)) {
                $granted = ensureSelfServiceModuleEntitlementForTenant($moduleId, $tenantId, [
                    'source' => 'guidance_managed_module_enable',
                ]);
                invalidateModuleCatalogCache();
                $catalogEntry = moduleCatalogEntry($moduleId);
                $entitlementRow = moduleTenantEntitlementRow($moduleId, $tenantId);
                $entitlement = guidanceManagedModuleEntitlementStatus($moduleId, $manifest, $tenantId, $catalogEntry, $entitlementRow);
                if (!$granted || empty($entitlement['allowed'])) {
                    throw new RuntimeException('Could not provision access for this add-on.');
                }
            } else {
                $request = moduleLatestAccessRequestForTenant($moduleId, $tenantId) ?? [];
                $requestStatus = strtolower(trim((string)($request['status'] ?? '')));
                if ($requestStatus === 'pending') {
                    throw new RuntimeException('This add-on already has a pending access request.');
                }

                throw new RuntimeException('Request access to this add-on before activating it.');
            }
        }

        enableModuleForTenant($moduleId, $tenantId);
        guidanceModuleManagementResponse('Activated ' . (string)($manifest['name'] ?? $moduleId) . '.', true, 200, ['module_id' => $moduleId]);
    } catch (RuntimeException $e) {
        guidanceModuleManagementResponse($e->getMessage(), false, 400);
    }
}

function apiGuidanceDisableModule(array $params = []): void
{
    guidanceRequireStaff(['admin']);

    try {
        $manifest = guidanceManagedModule((string)($params['id'] ?? ''));
        $moduleId = (string)($manifest['id'] ?? '');
        $tenantId = guidanceManagedTenantId();
        if ($tenantId <= 0) {
            throw new RuntimeException('Guidance add-ons require an active tenant context.');
        }

        disableModuleForTenant($moduleId, $tenantId);
        guidanceModuleManagementResponse('Deactivated ' . (string)($manifest['name'] ?? $moduleId) . '.', true, 200, ['module_id' => $moduleId]);
    } catch (RuntimeException $e) {
        guidanceModuleManagementResponse($e->getMessage(), false, 400);
    }
}

function apiGuidanceUpdateModuleSettings(array $params = []): void
{
    guidanceRequireStaff(['admin']);

    try {
        $moduleId = (string)($params['id'] ?? '');
        $saved = guidanceManagedModuleSaveSettings($moduleId, (array)guidanceInput());
        guidanceModuleManagementResponse('Add-on settings saved successfully.', true, 200, [
            'module_id' => $moduleId,
            'settings' => $saved,
        ]);
    } catch (RuntimeException $e) {
        guidanceModuleManagementResponse($e->getMessage(), false, 400);
    }
}

function apiGuidanceRequestModuleAccess(array $params = []): void
{
    $user = guidanceRequireStaff(['admin']);

    try {
        $manifest = guidanceManagedModule((string)($params['id'] ?? ''));
        $moduleId = (string)($manifest['id'] ?? '');
        $tenantId = guidanceManagedTenantId();
        if ($tenantId <= 0) {
            throw new RuntimeException('Guidance add-on access requests require an active tenant context.');
        }

        $catalogEntry = moduleCatalogEntry($moduleId);
        $entitlementRow = moduleTenantEntitlementRow($moduleId, $tenantId);
        $entitlement = guidanceManagedModuleEntitlementStatus($moduleId, $manifest, $tenantId, $catalogEntry, $entitlementRow);
        if (!empty($entitlement['allowed'])) {
            guidanceModuleManagementResponse((string)($manifest['name'] ?? $moduleId) . ' is already available for this tenant.', true, 200, ['module_id' => $moduleId]);
            return;
        }

        $commercialMode = strtolower(trim((string)($manifest['commercial_mode'] ?? ($entitlement['commercial_mode'] ?? 'paid'))));
        if (moduleCatalogModeAllowsSelfService($commercialMode)) {
            $granted = ensureSelfServiceModuleEntitlementForTenant($moduleId, $tenantId, [
                'source' => 'guidance_managed_module_request_access',
                'granted_by_user_id' => (int)($user['id'] ?? 0),
            ]);
            invalidateModuleCatalogCache();
            if (!$granted) {
                throw new RuntimeException('Could not activate this add-on for the current tenant.');
            }

            guidanceModuleManagementResponse((string)($manifest['name'] ?? $moduleId) . ' is now available for this tenant.', true, 200, ['module_id' => $moduleId]);
            return;
        }

        $existingRequest = moduleLatestAccessRequestForTenant($moduleId, $tenantId) ?? [];

        $result = submitModuleAccessRequestForTenant($moduleId, $tenantId, [
            'requested_mode' => $commercialMode !== '' ? $commercialMode : 'paid',
            'request_notes' => trim((string)guidanceInput('request_notes', '')),
            'license_key' => trim((string)guidanceInput('license_key', '')),
            'requested_by_user_id' => (int)($user['id'] ?? 0),
            'metadata' => ['via' => 'guidanceApiRequestModuleAccess'],
        ]);

        if (empty($result['ok'])) {
            throw new RuntimeException((string)($result['error'] ?? 'Could not submit the add-on access request.'));
        }

        $existingStatus = strtolower(trim((string)($existingRequest['status'] ?? '')));
        $message = $existingStatus === 'pending'
            ? 'Add-on access request updated and queued for review.'
            : 'Add-on access request submitted for review.';

        guidanceModuleManagementResponse($message, true, 200, [
            'module_id' => $moduleId,
            'request' => $result['request'] ?? null,
        ]);
    } catch (RuntimeException $e) {
        guidanceModuleManagementResponse($e->getMessage(), false, 400);
    }
}

function guidanceEmailTemplateDefaults(): array
{
    return [
        'booking_received' => [
            'subject' => 'Appointment Request Received',
            'body' => "Dear {student_name},\n\nYour appointment request has been received and is pending approval.\n\nDate: {date}\nTime: {time}\n\nYou will receive another email once your appointment is confirmed by a counselor.\n\nIf you need to make changes, please contact the Guidance Office directly.",
        ],
        'booking_confirmed' => [
            'subject' => 'Appointment Confirmed',
            'body' => "Dear {student_name},\n\nYour appointment has been confirmed!\n\nDate: {date}\nTime: {time}\nLocation: {location}\n\nPlease arrive 5-10 minutes early.\n\nIf you need to cancel or reschedule, please contact the Guidance Office as soon as possible.",
        ],
        'booking_rejected' => [
            'subject' => 'Appointment Request Update',
            'body' => "Dear {student_name},\n\nWe regret to inform you that your appointment request could not be accommodated.\n\nDate: {date}\nTime: {time}\n{reason}\n\nPlease feel free to book another appointment at a different time.",
        ],
    ];
}

function guidanceEmailTemplateSettingMap(): array
{
    return [
        'booking_received' => [
            'subject' => 'email_tpl_booking_received_subject',
            'body' => 'email_tpl_booking_received_body',
        ],
        'booking_confirmed' => [
            'subject' => 'email_tpl_booking_confirmed_subject',
            'body' => 'email_tpl_booking_confirmed_body',
        ],
        'booking_rejected' => [
            'subject' => 'email_tpl_booking_rejected_subject',
            'body' => 'email_tpl_booking_rejected_body',
        ],
    ];
}

function guidanceEmailTemplates(): array
{
    $templates = guidanceEmailTemplateDefaults();
    $settingMap = guidanceEmailTemplateSettingMap();
    $keys = [];

    foreach ($settingMap as $fieldMap) {
        foreach ($fieldMap as $settingKey) {
            $keys[] = $settingKey;
        }
    }

    if ($keys === []) {
        return $templates;
    }

    try {
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $stmt = guidanceDb()->prepare('SELECT setting_key, setting_value FROM gm_settings WHERE setting_key IN (' . $placeholders . ')');
        $stmt->execute($keys);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        foreach ($settingMap as $templateKey => $fieldMap) {
            foreach ($fieldMap as $field => $settingKey) {
                if (isset($rows[$settingKey]) && trim((string)$rows[$settingKey]) !== '') {
                    $templates[$templateKey][$field] = (string)$rows[$settingKey];
                }
            }
        }
    } catch (Throwable $e) {
        app()->log('Email templates load error: ' . $e->getMessage(), 'error');
    }

    return $templates;
}

function guidancePersistEmailTemplates(array $input, int $userId = 0): void
{
    $settingMap = guidanceEmailTemplateSettingMap();
    $defaults = guidanceEmailTemplateDefaults();
    $values = [];

    foreach ($settingMap as $templateKey => $fieldMap) {
        foreach ($fieldMap as $field => $settingKey) {
            if (!array_key_exists($settingKey, $input)) {
                throw new RuntimeException('Missing email template field: ' . $settingKey);
            }

            $rawValue = (string)$input[$settingKey];
            if ($field === 'subject') {
                $normalized = preg_replace('/[\r\n]+/', ' ', $rawValue) ?? '';
            } else {
                $normalized = str_replace(["\r\n", "\r"], "\n", $rawValue);
            }

            if (trim($normalized) === '') {
                throw new RuntimeException('Email template ' . str_replace('_', ' ', $templateKey) . ' ' . $field . ' is required.');
            }

            $fallback = (string)($defaults[$templateKey][$field] ?? '');
            $values[$settingKey] = $normalized !== '' ? $normalized : $fallback;
        }
    }

    $stmt = guidanceDb()->prepare(
        "INSERT INTO gm_settings (setting_key, setting_value, setting_type, updated_by, updated_at)\n"
        . "VALUES (?, ?, 'string', ?, NOW())\n"
        . "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type), updated_by = VALUES(updated_by), updated_at = NOW()"
    );

    foreach ($values as $settingKey => $value) {
        $stmt->execute([$settingKey, $value, $userId > 0 ? $userId : null]);
    }
}

function apiGuidanceGetEmailTemplates(): void
{
    guidanceRequireStaff(['admin']);
    guidanceRequirePro();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => guidanceEmailTemplates()], JSON_UNESCAPED_SLASHES);
}

function pageGuidanceEmailTemplates(): void
{
    $user = guidanceRequireStaff(['admin']);

    echo guidanceRender('modules/guidance/pages/email-templates.disyl', array_merge(
        guidanceBasePageContext($user, 'Email Templates', 'email-templates'),
        [
            'email_templates' => guidanceEmailTemplates(),
        ]
    ));
}

function apiGuidanceUpdateEmailTemplates(): void
{
    $user = guidanceRequireStaff(['admin']);
    guidanceRequirePro();

    $input = guidanceInput();
    if (!is_array($input) || $input === []) {
        if (guidanceIsHtmx()) {
            http_response_code(400);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'No email templates were submitted.', 'type' => 'error']]));
            echo '';
            return;
        }

        app()->json(['success' => false, 'error' => 'No email templates were submitted.'], 400);
    }

    try {
        guidancePersistEmailTemplates($input, (int)($user['id'] ?? 0));

        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Email templates saved successfully', 'type' => 'success']]));
            echo '';
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
    } catch (RuntimeException $e) {
        if (guidanceIsHtmx()) {
            http_response_code(400);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $e->getMessage(), 'type' => 'error']]));
            echo '';
            return;
        }

        app()->json(['success' => false, 'error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        app()->log('Email templates update error: ' . $e->getMessage(), 'error');

        if (guidanceIsHtmx()) {
            http_response_code(500);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to save email templates', 'type' => 'error']]));
            echo '';
            return;
        }

        app()->json(['success' => false, 'error' => 'Failed to save email templates'], 500);
    }
}

function pageGuidanceFormSettings(): void
{
    $user = guidanceRequireStaff(['admin']);

    echo guidanceRender('modules/guidance/pages/form-settings.disyl', guidanceBasePageContext($user, 'Form Settings', 'form-settings'));
}

function guidanceSupportedFormFieldTypes(): array
{
    return ['text', 'textarea', 'select', 'checkbox', 'date', 'email', 'tel', 'number', 'hidden'];
}

function guidanceNormalizeManagedFormType(string $formType): string
{
    $formType = strtolower(trim($formType));
    if (!in_array($formType, guidanceAllowedFormTypes(), true)) {
        guidanceFormFieldError('Unsupported form type', 400);
    }

    return $formType;
}

function guidanceNormalizeManagedFieldName(string $fieldName): string
{
    $fieldName = strtolower(trim($fieldName));
    $fieldName = preg_replace('/[^a-z0-9_]+/', '_', $fieldName) ?? '';
    $fieldName = trim($fieldName, '_');
    return $fieldName;
}

function guidanceFormFieldError(string $message, int $status = 400): void
{
    http_response_code($status);

    if (guidanceIsHtmx()) {
        header('HX-Reswap: none');
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $message, 'type' => 'error']]));
        echo '';
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function guidanceFetchManagedFormField(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $fieldId): ?array
{
    $stmt = $db->prepare('SELECT * FROM gm_form_fields WHERE id = ? LIMIT 1');
    $stmt->execute([$fieldId]);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($field) ? $field : null;
}

function apiGuidanceFormFields(): void
{
    guidanceRequireStaff(['admin']);
    guidanceRequirePro();

    $formType = guidanceNormalizeManagedFormType((string)guidanceInput('form_type', 'case'));
    $fields = [];

    try {
        $stmt = guidanceDb()->prepare('SELECT * FROM gm_form_fields WHERE form_type = ? ORDER BY sort_order, id');
        $stmt->execute([$formType]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        app()->log('Form fields list error: ' . $e->getMessage(), 'error');
        guidanceFormFieldError('Failed to load form fields', 500);
    }

    if (guidanceIsHtmx()) {
        header('Content-Type: text/html; charset=utf-8');
        echo guidanceRender('modules/guidance/partials/form-fields-table.disyl', [
            'form_type' => $formType,
            'fields' => $fields,
        ]);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $fields], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceCreateFormField(): void
{
    guidanceRequireStaff(['admin']);
    guidanceRequirePro();
    app()->csrfEnforce();

    $input = guidanceInput();
    if (!is_array($input)) {
        guidanceFormFieldError('Invalid form field payload', 400);
    }

    $formType = guidanceNormalizeManagedFormType((string)($input['form_type'] ?? ''));
    $fieldName = guidanceNormalizeManagedFieldName((string)($input['field_name'] ?? ''));
    $fieldLabel = trim((string)($input['field_label'] ?? ''));
    $fieldType = strtolower(trim((string)($input['field_type'] ?? 'text')));
    $fieldGroup = trim((string)($input['field_group'] ?? ''));
    $placeholder = trim((string)($input['placeholder'] ?? ''));
    $defaultValue = trim((string)($input['default_value'] ?? ''));
    $gridColumn = strtolower(trim((string)($input['grid_column'] ?? 'half')));

    if ($fieldName === '' || $fieldLabel === '') {
        guidanceFormFieldError('Field name and label are required', 400);
    }
    if (!in_array($fieldType, guidanceSupportedFormFieldTypes(), true)) {
        guidanceFormFieldError('Unsupported field type', 400);
    }
    if (!in_array($gridColumn, ['full', 'half'], true)) {
        $gridColumn = 'half';
    }

    $fieldOptions = guidanceNormalizeFormFieldOptions($input['field_options'] ?? null);

    $db = guidanceDb();

    try {
        $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM gm_form_fields WHERE form_type = ?');
        $sortStmt->execute([$formType]);
        $sortOrder = max(1, (int)($sortStmt->fetchColumn() ?: 1));

        $stmt = $db->prepare(
            'INSERT INTO gm_form_fields (form_type, field_name, field_label, field_type, field_group, field_options, placeholder, default_value, is_required, is_enabled, is_system, sort_order, grid_column, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $formType,
            $fieldName,
            $fieldLabel,
            $fieldType,
            ($fieldGroup !== '' ? $fieldGroup : null),
            $fieldOptions,
            ($placeholder !== '' ? $placeholder : null),
            ($defaultValue !== '' ? $defaultValue : null),
            !empty($input['is_required']) ? 1 : 0,
            array_key_exists('is_enabled', $input) ? (!empty($input['is_enabled']) ? 1 : 0) : 1,
            $sortOrder,
            $gridColumn,
        ]);

        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Form field added', 'type' => 'success'], 'refreshFormFields' => true]));
            echo '';
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
        return;
    } catch (Throwable $e) {
        app()->log('Form field create error: ' . $e->getMessage(), 'error');
        $message = stripos($e->getMessage(), 'Duplicate') !== false
            ? 'A form field with that name already exists for this form.'
            : 'Failed to create form field';
        guidanceFormFieldError($message, stripos($e->getMessage(), 'Duplicate') !== false ? 409 : 500);
    }
}

function apiGuidanceUpdateFormField(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    guidanceRequirePro();
    app()->csrfEnforce();

    $fieldId = (int)($params['id'] ?? 0);
    $input = guidanceInput();
    if ($fieldId < 1 || !is_array($input)) {
        guidanceFormFieldError('Form field not found', 404);
    }

    $db = guidanceDb();
    $existing = guidanceFetchManagedFormField($db, $fieldId);
    if (!is_array($existing)) {
        guidanceFormFieldError('Form field not found', 404);
    }

    $updates = [];
    $values = [];

    if (array_key_exists('field_label', $input)) {
        $fieldLabel = trim((string)$input['field_label']);
        if ($fieldLabel === '') {
            guidanceFormFieldError('Field label is required', 400);
        }
        $updates[] = 'field_label = ?';
        $values[] = $fieldLabel;
    }

    if (array_key_exists('placeholder', $input)) {
        $updates[] = 'placeholder = ?';
        $values[] = trim((string)$input['placeholder']) !== '' ? trim((string)$input['placeholder']) : null;
    }

    if (array_key_exists('field_group', $input)) {
        $updates[] = 'field_group = ?';
        $values[] = trim((string)$input['field_group']) !== '' ? trim((string)$input['field_group']) : null;
    }

    if (array_key_exists('default_value', $input)) {
        $updates[] = 'default_value = ?';
        $values[] = trim((string)$input['default_value']) !== '' ? trim((string)$input['default_value']) : null;
    }

    if (array_key_exists('field_options', $input)) {
        if (!empty($existing['is_system'])) {
            guidanceFormFieldError('System field options are managed separately', 409);
        }
        $updates[] = 'field_options = ?';
        $values[] = guidanceNormalizeFormFieldOptions($input['field_options']);
    }

    if (array_key_exists('is_required', $input)) {
        $updates[] = 'is_required = ?';
        $values[] = !empty($input['is_required']) ? 1 : 0;
    }

    if (array_key_exists('is_enabled', $input)) {
        $updates[] = 'is_enabled = ?';
        $values[] = !empty($input['is_enabled']) ? 1 : 0;
    }

    if (array_key_exists('grid_column', $input)) {
        $gridColumn = strtolower(trim((string)$input['grid_column']));
        $updates[] = 'grid_column = ?';
        $values[] = in_array($gridColumn, ['full', 'half'], true) ? $gridColumn : 'half';
    }

    if ($updates === []) {
        guidanceFormFieldError('No changes provided', 400);
    }

    try {
        $updates[] = 'updated_at = NOW()';
        $values[] = $fieldId;
        $stmt = $db->prepare('UPDATE gm_form_fields SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($values);
    } catch (Throwable $e) {
        app()->log('Form field update error: ' . $e->getMessage(), 'error');
        guidanceFormFieldError('Failed to update form field', 500);
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Form field updated', 'type' => 'success'], 'refreshFormFields' => true]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceDeleteFormField(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    guidanceRequirePro();
    app()->csrfEnforce();

    $fieldId = (int)($params['id'] ?? 0);
    if ($fieldId < 1) {
        guidanceFormFieldError('Form field not found', 404);
    }

    $db = guidanceDb();
    $existing = guidanceFetchManagedFormField($db, $fieldId);
    if (!is_array($existing)) {
        guidanceFormFieldError('Form field not found', 404);
    }
    if (!empty($existing['is_system'])) {
        guidanceFormFieldError('System fields cannot be deleted', 409);
    }

    try {
        $stmt = $db->prepare('DELETE FROM gm_form_fields WHERE id = ?');
        $stmt->execute([$fieldId]);
    } catch (Throwable $e) {
        app()->log('Form field delete error: ' . $e->getMessage(), 'error');
        guidanceFormFieldError('Failed to delete form field', 500);
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Form field deleted', 'type' => 'success'], 'refreshFormFields' => true]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceReorderFormFields(): void
{
    guidanceRequireStaff(['admin']);
    guidanceRequirePro();
    app()->csrfEnforce();

    $input = guidanceInput();
    if (!is_array($input)) {
        guidanceFormFieldError('Invalid reorder payload', 400);
    }

    $formType = guidanceNormalizeManagedFormType((string)($input['form_type'] ?? 'case'));
    $ids = $input['ids'] ?? ($input['field_ids'] ?? []);
    if (is_string($ids)) {
        $ids = array_values(array_filter(array_map('trim', explode(',', $ids)), static fn (string $value): bool => $value !== ''));
    }
    if (!is_array($ids) || $ids === []) {
        guidanceFormFieldError('No form fields provided for reorder', 400);
    }

    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $value): bool => $value > 0));
    if ($ids === []) {
        guidanceFormFieldError('No valid form fields provided for reorder', 400);
    }

    $db = guidanceDb();
    try {
        $stmt = $db->prepare('SELECT id FROM gm_form_fields WHERE form_type = ?');
        $stmt->execute([$formType]);
        $allowedIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        foreach ($ids as $fieldId) {
            if (!in_array($fieldId, $allowedIds, true)) {
                guidanceFormFieldError('Form field reorder payload does not match the selected form type', 400);
            }
        }

        $db->beginTransaction();
        $updateStmt = $db->prepare('UPDATE gm_form_fields SET sort_order = ?, updated_at = NOW() WHERE id = ?');
        foreach ($ids as $index => $fieldId) {
            $updateStmt->execute([$index + 1, $fieldId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        app()->log('Form field reorder error: ' . $e->getMessage(), 'error');
        guidanceFormFieldError('Failed to reorder form fields', 500);
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Form field order updated', 'type' => 'success'], 'refreshFormFields' => true]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function guidanceSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $manifest = discoverModules()['guidance'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = (string)$field['default'];
    }

    return $defaults;
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

    $defaults = guidanceSettingsDefaults();
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

function slugifyAppointmentTypeCode(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

    return trim($value, '-') ?: 'session';
}

function buildAppointmentTypeCode(\Ikabud\Kernel\Contracts\DatabaseContract $db, string $name, ?string $requestedCode = null, ?int $excludeId = null): string
{
    $baseCode = slugifyAppointmentTypeCode($requestedCode !== null && trim($requestedCode) !== '' ? $requestedCode : $name);
    $code = $baseCode;
    $suffix = 2;

    while (true) {
        if ($excludeId !== null) {
            $stmt = $db->prepare('SELECT id FROM gm_appointment_types WHERE code = ? AND id != ? LIMIT 1');
            $stmt->execute([$code, $excludeId]);
        } else {
            $stmt = $db->prepare('SELECT id FROM gm_appointment_types WHERE code = ? LIMIT 1');
            $stmt->execute([$code]);
        }

        if (!$stmt->fetchColumn()) {
            return $code;
        }

        $code = $baseCode . '-' . $suffix;
        $suffix++;
    }
}

function appointmentTypeError(string $message, int $status = 400): void
{
    if (guidanceIsHtmx()) {
        http_response_code($status);
        header('HX-Reswap: none');
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $message, 'type' => 'error']]));
        echo '';
        exit;
    }

    app()->json(['error' => $message], $status);
    exit;
}

function apiGuidanceListAppointmentTypes(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $db = guidanceDb();
    $context = (string) guidanceInput('context', '');
    $includeInactive = !empty(guidanceInput('include_inactive')) && (($user['role'] ?? '') === 'admin');

    $sql = 'SELECT * FROM gm_appointment_types';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, name';

    $types = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (guidanceIsHtmx() && $context === 'settings') {
        if (($user['role'] ?? '') !== 'admin') {
            appointmentTypeError('Access denied', 403);
        }
        guidanceRequirePro();
        echo guidanceRender('modules/guidance/partials/appointment-types-settings.disyl', [
            'appointment_types' => $types,
        ]);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $types]);
}

function apiGuidanceCreateAppointmentType(): void
{
    guidanceRequireStaff(['admin']);
    guidanceRequirePro();

    $db = guidanceDb();
    $input = guidanceInput();
    if (!is_array($input)) {
        appointmentTypeError('Invalid request payload', 400);
    }

    $name = trim((string) ($input['name'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $duration = max(5, (int) ($input['duration_minutes'] ?? 30));
    $sortOrder = max(0, (int) ($input['sort_order'] ?? 0));
    $color = trim((string) ($input['color'] ?? '#6366f1'));
    $requiresCase = !empty($input['requires_case']) ? 1 : 0;
    $isPublic = !empty($input['is_public']) ? 1 : 0;
    $isActive = !empty($input['is_active']) ? 1 : 0;

    if ($name === '') {
        appointmentTypeError('Session type name is required', 400);
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        appointmentTypeError('Color must be a valid hex value', 400);
    }

    $code = buildAppointmentTypeCode($db, $name, isset($input['code']) ? (string) $input['code'] : null);

    try {
        $stmt = $db->prepare(
            'INSERT INTO gm_appointment_types (
                code, name, description, duration_minutes, color,
                requires_case, is_public, is_active, sort_order, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $code,
            $name,
            $description !== '' ? $description : null,
            $duration,
            $color,
            $requiresCase,
            $isPublic,
            $isActive,
            $sortOrder,
        ]);
    } catch (PDOException $e) {
        app()->log('Appointment type create error: ' . $e->getMessage(), 'error');
        appointmentTypeError('Failed to create session type', 500);
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Session type added', 'type' => 'success'],
            'refreshAppointmentTypes' => true,
        ]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceUpdateAppointmentType(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    guidanceRequirePro();

    $db = guidanceDb();
    $input = guidanceInput();
    $id = (int) ($params['id'] ?? 0);

    if (!is_array($input) || $id < 1) {
        appointmentTypeError('Session type not found', 404);
    }

    $stmt = $db->prepare('SELECT * FROM gm_appointment_types WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        appointmentTypeError('Session type not found', 404);
    }

    $name = trim((string) ($input['name'] ?? $existing['name']));
    $description = trim((string) ($input['description'] ?? ($existing['description'] ?? '')));
    $duration = array_key_exists('duration_minutes', $input)
        ? max(5, (int) $input['duration_minutes'])
        : (int) ($existing['duration_minutes'] ?? 30);
    $sortOrder = array_key_exists('sort_order', $input)
        ? max(0, (int) $input['sort_order'])
        : (int) ($existing['sort_order'] ?? 0);
    $color = trim((string) ($input['color'] ?? $existing['color'] ?? '#6366f1'));
    $requiresCase = array_key_exists('requires_case', $input)
        ? (!empty($input['requires_case']) ? 1 : 0)
        : (int) ($existing['requires_case'] ?? 0);
    $isPublic = array_key_exists('is_public', $input)
        ? (!empty($input['is_public']) ? 1 : 0)
        : (int) ($existing['is_public'] ?? 0);
    $isActive = array_key_exists('is_active', $input)
        ? (!empty($input['is_active']) ? 1 : 0)
        : (int) ($existing['is_active'] ?? 0);

    if ($name === '') {
        appointmentTypeError('Session type name is required', 400);
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        appointmentTypeError('Color must be a valid hex value', 400);
    }

    $code = buildAppointmentTypeCode($db, $name, isset($input['code']) ? (string) $input['code'] : (string) ($existing['code'] ?? ''), $id);

    try {
        $updateStmt = $db->prepare(
            'UPDATE gm_appointment_types
             SET code = ?, name = ?, description = ?, duration_minutes = ?, color = ?,
                 requires_case = ?, is_public = ?, is_active = ?, sort_order = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $updateStmt->execute([
            $code,
            $name,
            $description !== '' ? $description : null,
            $duration,
            $color,
            $requiresCase,
            $isPublic,
            $isActive,
            $sortOrder,
            $id,
        ]);
    } catch (PDOException $e) {
        app()->log('Appointment type update error: ' . $e->getMessage(), 'error');
        appointmentTypeError('Failed to update session type', 500);
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Session type updated', 'type' => 'success'],
            'refreshAppointmentTypes' => true,
        ]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceDeleteAppointmentType(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    guidanceRequirePro();

    $db = guidanceDb();
    $id = (int) ($params['id'] ?? 0);
    if ($id < 1) {
        appointmentTypeError('Session type not found', 404);
    }

    $usageStmt = $db->prepare('SELECT COUNT(*) FROM gm_appointments WHERE appointment_type_id = ?');
    $usageStmt->execute([$id]);
    if ((int) $usageStmt->fetchColumn() > 0) {
        appointmentTypeError('This session type is already in use. Deactivate it instead.', 409);
    }

    $stmt = $db->prepare('DELETE FROM gm_appointment_types WHERE id = ?');
    $stmt->execute([$id]);

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Session type deleted', 'type' => 'success'],
            'refreshAppointmentTypes' => true,
        ]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function studentStatusError(string $message, int $status = 400): void
{
    if (guidanceIsHtmx()) {
        http_response_code($status);
        header('HX-Reswap: none');
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $message, 'type' => 'error']]));
        echo '';
        exit;
    }

    app()->json(['error' => $message], $status);
    exit;
}

function studentStatusDefaultOptions(): array
{
    return ['Active', 'At Risk', 'On Leave', 'Transferred', 'Dropped', 'Graduated'];
}

function normalizeStudentStatusLabel(string $label): string
{
    $label = trim($label);
    $label = preg_replace('/\s+/u', ' ', $label) ?? $label;

    return trim($label);
}

function normalizeStudentStatusOptions(array $options): array
{
    $normalized = [];
    $seen = [];

    foreach ($options as $option) {
        $label = normalizeStudentStatusLabel((string) $option);
        if ($label === '') {
            continue;
        }

        $key = function_exists('mb_strtolower')
            ? mb_strtolower($label, 'UTF-8')
            : strtolower($label);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $normalized[] = $label;
    }

    return array_values($normalized);
}

function studentStatusCasesColumnExists(\Ikabud\Kernel\Contracts\DatabaseContract $db): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = $db->query("SHOW COLUMNS FROM gm_cases LIKE 'student_status'");
        $exists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function ensureStudentStatusField(\Ikabud\Kernel\Contracts\DatabaseContract $db): array
{
    $stmt = $db->prepare('SELECT * FROM gm_form_fields WHERE form_type = ? AND field_name = ? LIMIT 1');
    $stmt->execute(['case', 'student_status']);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($field) {
        return $field;
    }

    $defaults = studentStatusDefaultOptions();
    $nextSort = (int) $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM gm_form_fields WHERE form_type = 'case'")->fetchColumn();

    $insert = $db->prepare(
        'INSERT INTO gm_form_fields (
            form_type, field_name, field_label, field_type, field_group,
            field_options, placeholder, default_value, is_required, is_enabled,
            is_system, sort_order, grid_column
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        'case',
        'student_status',
        'Student Status',
        'select',
        'Student Information',
        json_encode($defaults, JSON_UNESCAPED_UNICODE),
        null,
        $defaults[0],
        0,
        1,
        1,
        max(1, $nextSort),
        'half',
    ]);

    $stmt->execute(['case', 'student_status']);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$field) {
        throw new RuntimeException('Failed to initialize student status field');
    }

    return $field;
}

function getStudentStatusConfig(\Ikabud\Kernel\Contracts\DatabaseContract $db): array
{
    $field = ensureStudentStatusField($db);

    $options = [];
    if (!empty($field['field_options'])) {
        $decoded = json_decode((string) $field['field_options'], true);
        if (is_array($decoded)) {
            $options = $decoded;
        } else {
            $options = array_map('trim', explode(',', (string) $field['field_options']));
        }
    }

    $statuses = normalizeStudentStatusOptions($options);
    $default = normalizeStudentStatusLabel((string) ($field['default_value'] ?? ''));
    if ($default === '' || !in_array($default, $statuses, true)) {
        $default = $statuses[0] ?? '';
    }

    $usageCounts = [];
    if (studentStatusCasesColumnExists($db)) {
        $usageStmt = $db->query(
            "SELECT student_status, COUNT(*) AS status_count
             FROM gm_cases
             WHERE student_status IS NOT NULL AND TRIM(student_status) != ''
             GROUP BY student_status"
        );
        foreach ($usageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $usageCounts[(string) $row['student_status']] = (int) $row['status_count'];
        }
    }

    $items = [];
    foreach ($statuses as $index => $status) {
        $items[] = [
            'id' => (string) $index,
            'name' => $status,
            'sort_order' => $index + 1,
            'is_default' => $status === $default,
            'in_use_count' => (int) ($usageCounts[$status] ?? 0),
        ];
    }

    return [
        'field' => $field,
        'statuses' => $statuses,
        'default' => $default,
        'items' => $items,
    ];
}

function studentStatusOptionExists(array $statuses, string $label, ?int $excludeIndex = null): bool
{
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($label, 'UTF-8')
        : strtolower($label);

    foreach ($statuses as $index => $status) {
        if ($excludeIndex !== null && $index === $excludeIndex) {
            continue;
        }

        $existing = function_exists('mb_strtolower')
            ? mb_strtolower((string) $status, 'UTF-8')
            : strtolower((string) $status);

        if ($existing === $normalized) {
            return true;
        }
    }

    return false;
}

function moveStudentStatusToPosition(array $statuses, int $index, int $sortOrder): array
{
    if (!isset($statuses[$index])) {
        return array_values($statuses);
    }

    $item = $statuses[$index];
    array_splice($statuses, $index, 1);

    $target = max(0, min(count($statuses), $sortOrder - 1));
    array_splice($statuses, $target, 0, [$item]);

    return array_values($statuses);
}

function saveStudentStatusConfig(\Ikabud\Kernel\Contracts\DatabaseContract $db, array $field, array $statuses, ?string $defaultValue): array
{
    $statuses = normalizeStudentStatusOptions($statuses);
    if (empty($statuses)) {
        studentStatusError('At least one student status is required', 400);
    }

    $defaultValue = normalizeStudentStatusLabel((string) $defaultValue);
    if ($defaultValue === '' || !in_array($defaultValue, $statuses, true)) {
        $defaultValue = $statuses[0];
    }

    $stmt = $db->prepare('UPDATE gm_form_fields SET field_options = ?, default_value = ?, is_enabled = 1 WHERE id = ?');
    $stmt->execute([
        json_encode($statuses, JSON_UNESCAPED_UNICODE),
        $defaultValue,
        $field['id'],
    ]);

    return [
        'statuses' => $statuses,
        'default' => $defaultValue,
    ];
}

function apiGuidanceListStudentStatuses(): void
{
    guidanceRequireStaff(['admin']);
    $db = guidanceDb();
    $context = (string) guidanceInput('context', '');

    try {
        $config = getStudentStatusConfig($db);
    } catch (Throwable $e) {
        app()->log('Student status list error: ' . $e->getMessage(), 'error');
        studentStatusError('Failed to load student statuses', 500);
    }

    if (guidanceIsHtmx() && $context === 'settings') {
        echo guidanceRender('modules/guidance/partials/student-statuses-settings.disyl', [
            'student_statuses' => $config['items'],
            'default_student_status' => $config['default'],
            'next_sort_order' => count($config['items']) + 1,
        ]);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $config['items'], 'default' => $config['default']], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceCreateStudentStatus(): void
{
    guidanceRequireStaff(['admin']);
    $db = guidanceDb();
    $input = guidanceInput();
    if (!is_array($input)) {
        studentStatusError('Invalid request payload', 400);
    }

    $name = normalizeStudentStatusLabel((string) ($input['name'] ?? ''));
    $sortOrder = max(1, (int) ($input['sort_order'] ?? PHP_INT_MAX));
    $setDefault = !empty($input['is_default']);

    if ($name === '') {
        studentStatusError('Student status name is required', 400);
    }

    try {
        $config = getStudentStatusConfig($db);
        if (studentStatusOptionExists($config['statuses'], $name)) {
            studentStatusError('That student status already exists', 409);
        }

        $statuses = $config['statuses'];
        $target = max(0, min(count($statuses), $sortOrder - 1));
        array_splice($statuses, $target, 0, [$name]);
        $default = $setDefault ? $name : ($config['default'] !== '' ? $config['default'] : $name);

        $db->beginTransaction();
        saveStudentStatusConfig($db, $config['field'], $statuses, $default);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        app()->log('Student status create error: ' . $e->getMessage(), 'error');
        studentStatusError('Failed to create student status', 500);
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Student status added', 'type' => 'success'],
            'refreshStudentStatuses' => true,
        ]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceUpdateStudentStatus(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    $db = guidanceDb();
    $input = guidanceInput();
    $index = (int) ($params['id'] ?? -1);

    if (!is_array($input) || $index < 0) {
        studentStatusError('Student status not found', 404);
    }

    try {
        $config = getStudentStatusConfig($db);
        if (!isset($config['statuses'][$index])) {
            studentStatusError('Student status not found', 404);
        }

        $oldName = $config['statuses'][$index];
        $name = normalizeStudentStatusLabel((string) ($input['name'] ?? $oldName));
        $sortOrder = array_key_exists('sort_order', $input)
            ? max(1, (int) $input['sort_order'])
            : ($index + 1);
        $setDefault = !empty($input['is_default']);

        if ($name === '') {
            studentStatusError('Student status name is required', 400);
        }

        if (studentStatusOptionExists($config['statuses'], $name, $index)) {
            studentStatusError('That student status already exists', 409);
        }

        $statuses = $config['statuses'];
        $statuses[$index] = $name;
        $statuses = moveStudentStatusToPosition($statuses, $index, $sortOrder);

        $default = $config['default'];
        if ($setDefault || $default === '') {
            $default = $name;
        } elseif ($default === $oldName) {
            $default = $name;
        }

        $db->beginTransaction();

        if (studentStatusCasesColumnExists($db) && $oldName !== $name) {
            $caseStmt = $db->prepare('UPDATE gm_cases SET student_status = ? WHERE student_status = ?');
            $caseStmt->execute([$name, $oldName]);
        }

        saveStudentStatusConfig($db, $config['field'], $statuses, $default);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        app()->log('Student status update error: ' . $e->getMessage(), 'error');
        studentStatusError('Failed to update student status', 500);
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Student status updated', 'type' => 'success'],
            'refreshStudentStatuses' => true,
        ]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceDeleteStudentStatus(array $params = []): void
{
    guidanceRequireStaff(['admin']);
    $db = guidanceDb();
    $index = (int) ($params['id'] ?? -1);

    if ($index < 0) {
        studentStatusError('Student status not found', 404);
    }

    try {
        $config = getStudentStatusConfig($db);
        if (!isset($config['statuses'][$index])) {
            studentStatusError('Student status not found', 404);
        }

        if (count($config['statuses']) <= 1) {
            studentStatusError('At least one student status must remain', 400);
        }

        $name = $config['statuses'][$index];
        if (studentStatusCasesColumnExists($db)) {
            $usageStmt = $db->prepare('SELECT COUNT(*) FROM gm_cases WHERE student_status = ?');
            $usageStmt->execute([$name]);
            if ((int) $usageStmt->fetchColumn() > 0) {
                studentStatusError('This student status is already in use. Rename it instead.', 409);
            }
        }

        $statuses = $config['statuses'];
        array_splice($statuses, $index, 1);
        $default = $config['default'] === $name ? ($statuses[0] ?? '') : $config['default'];

        $db->beginTransaction();
        saveStudentStatusConfig($db, $config['field'], $statuses, $default);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        app()->log('Student status delete error: ' . $e->getMessage(), 'error');
        studentStatusError('Failed to delete student status', 500);
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Student status deleted', 'type' => 'success'],
            'refreshStudentStatuses' => true,
        ]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
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

function apiGuidanceGetUserColleges(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor']);

    $userId = (int)($params['id'] ?? 0);
    if ($userId < 1) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'User not found'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $db = guidanceDb();
    $stmt = $db->prepare(
        "SELECT c.id, c.code, c.name\n"
        . "FROM gm_colleges c\n"
        . "JOIN gm_counselor_assignments ca ON c.id = ca.college_id\n"
        . "WHERE ca.counselor_id = ? AND ca.is_active = 1 AND c.is_active = 1\n"
        . "ORDER BY c.sort_order, c.name"
    );
    $stmt->execute([$userId]);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_SLASHES);
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

function apiGuidanceCreateCollege(): void
{
    guidanceRequireStaff(['admin']);
    $input = guidanceInput();
    if (!is_array($input)) {
        http_response_code(400);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid request', 'type' => 'error']]));
        echo '';
        return;
    }

    $code = strtoupper(trim((string)($input['code'] ?? '')));
    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $sortOrder = (int)($input['sort_order'] ?? 0);

    if ($code === '' || $name === '') {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Code and name are required', 'type' => 'error']]));
        echo '';
        return;
    }

    try {
        $db = guidanceDb();
        $stmt = $db->prepare('INSERT INTO gm_colleges (code, name, description, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())');
        $stmt->execute([$code, $name, ($description !== '' ? $description : null), $sortOrder]);
        $newId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        app()->log('College create error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to create college', 'type' => 'error']]));
        echo '';
        return;
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'College created', 'type' => 'success'],
            'closeModal' => true,
            'refreshColleges' => true,
        ]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'id' => $newId], JSON_UNESCAPED_SLASHES);
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
            $stmt = guidanceDb()->prepare("SELECT id, email, first_name, last_name, phone, role, last_login_at FROM gm_users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $row = null;
        }
    }
    if (!is_array($row)) {
        $row = [
            'id' => $id,
            'email' => (string)($user['username'] ?? ''),
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'role' => (string)($user['role'] ?? 'counselor'),
            'last_login_at' => null,
        ];
    }

    $showAvailabilityEditor = guidanceIsPro() && in_array((string)($row['role'] ?? ''), ['counselor', 'supervisor'], true);
    $availability = [];
    if ($showAvailabilityEditor && $id > 0) {
        try {
            $availability = guidanceGetMergedCounselorAvailability(guidanceDb(), $id);
        } catch (Throwable $e) {
            $availability = [];
        }
    }

    echo guidanceRender('modules/guidance/pages/profile.disyl', array_merge(
        guidanceBasePageContext(is_array($user) ? $user : [], 'Profile', 'profile'),
        [
            'profile' => [
                'id' => (int)($row['id'] ?? $id),
                'email' => (string)($row['email'] ?? ''),
                'first_name' => (string)($row['first_name'] ?? ''),
                'last_name' => (string)($row['last_name'] ?? ''),
                'phone' => (string)($row['phone'] ?? ''),
                'role' => (string)($row['role'] ?? ($user['role'] ?? 'counselor')),
                'last_login_at' => $row['last_login_at'] ?? null,
            ],
            'availability' => $availability,
            'show_availability_editor' => $showAvailabilityEditor,
        ]
    ));
}

function apiGuidanceUpdateOwnProfile(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $id = (int)($user['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(403);
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Not authenticated', 'type' => 'error']]));
            echo '';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not authenticated'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $input = guidanceInput();
    app()->csrfEnforce();
    if (!is_array($input)) {
        http_response_code(400);
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid request', 'type' => 'error']]));
            echo '';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid request'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $first = trim((string)($input['first_name'] ?? ''));
    $last = trim((string)($input['last_name'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $currentPassword = (string)($input['current_password'] ?? '');
    $newPassword = (string)($input['new_password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');
    $hasPasswordChange = ($currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '');

    if ($first === '' || $last === '') {
        http_response_code(422);
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'First and last name are required.', 'type' => 'error']]));
            echo '';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'First and last name are required.'], JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($hasPasswordChange && ($currentPassword === '' || $newPassword === '' || $confirmPassword === '')) {
        http_response_code(422);
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Current, new, and confirm password are required.', 'type' => 'error']]));
            echo '';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Current, new, and confirm password are required.'], JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($hasPasswordChange && $newPassword !== $confirmPassword) {
        http_response_code(422);
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Passwords do not match.', 'type' => 'error']]));
            echo '';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Passwords do not match.'], JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($hasPasswordChange) {
        $pwError = guidanceValidatePasswordStrength($newPassword);
        if ($pwError !== null) {
            http_response_code(422);
            if (guidanceIsHtmx()) {
                header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $pwError, 'type' => 'error']]));
                echo '';
                return;
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $pwError], JSON_UNESCAPED_SLASHES);
            return;
        }
    }

    $db = guidanceDb();
    $currentUserStmt = $db->prepare('SELECT email, password, role FROM gm_users WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $currentUserStmt->execute([$id]);
    $currentUser = $currentUserStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($currentUser)) {
        http_response_code(404);
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Profile not found', 'type' => 'error']]));
            echo '';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Profile not found'], JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($hasPasswordChange && !password_verify($currentPassword, (string)($currentUser['password'] ?? ''))) {
        http_response_code(422);
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Current password is incorrect.', 'type' => 'error']]));
            echo '';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $updates = ['first_name = ?', 'last_name = ?', 'phone = ?'];
    $values = [$first, $last, ($phone !== '' ? $phone : null)];
    if ($hasPasswordChange) {
        $updates[] = 'password = ?';
        $values[] = password_hash($newPassword, PASSWORD_DEFAULT);
    }
    $updates[] = 'updated_at = NOW()';
    $values[] = $id;

    try {
        $sql = 'UPDATE gm_users SET ' . implode(', ', $updates) . ' WHERE id = ? AND deleted_at IS NULL';
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
    } catch (Throwable $e) {
        app()->log('Profile update error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to update profile', 'type' => 'error']]));
            echo '';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Failed to update profile'], JSON_UNESCAPED_SLASHES);
        return;
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Profile updated successfully', 'type' => 'success']]));
        header('HX-Refresh: true');
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceUpdateOwnAvailability(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    guidanceRequirePro();

    $role = (string)($user['role'] ?? '');
    if (!in_array($role, ['counselor', 'supervisor'], true)) {
        http_response_code(403);
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Access denied', 'type' => 'error']]));
            echo '';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Access denied'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $userId = (int)($user['id'] ?? 0);
    $input = guidanceInput();
    app()->csrfEnforce();
    $availabilityInput = [];
    if (is_array($input) && is_array($input['availability'] ?? null)) {
        $availabilityInput = $input['availability'];
    }

    try {
        guidanceSaveCounselorAvailability(guidanceDb(), $userId, $availabilityInput);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $e->getMessage(), 'type' => 'error']]));
            echo '';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
        return;
    } catch (Throwable $e) {
        app()->log('Profile availability update error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        if (guidanceIsHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Failed to update availability', 'type' => 'error']]));
            echo '';
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to update availability'], JSON_UNESCAPED_SLASHES);
        return;
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Availability updated successfully', 'type' => 'success']]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => 'Availability updated'], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceListNotifications(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $userId = (int)($user['id'] ?? 0);

    try {
        $db = guidanceDb();
        $stmt = $db->prepare(
            'SELECT id, type, title, message, link, is_read, created_at FROM gm_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20'
        );
        $stmt->execute([$userId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countStmt = $db->prepare('SELECT COUNT(*) FROM gm_notifications WHERE user_id = ? AND is_read = 0');
        $countStmt->execute([$userId]);
        $unreadCount = (int)($countStmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        app()->log('Notifications list error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to load notifications'], JSON_UNESCAPED_SLASHES);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'data' => $notifications,
        'unread_count' => $unreadCount,
    ], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceMarkNotificationsRead(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $userId = (int)($user['id'] ?? 0);
    $input = guidanceInput();
    $notificationId = is_array($input) ? (int)($input['id'] ?? 0) : 0;

    try {
        $db = guidanceDb();
        if ($notificationId > 0) {
            $stmt = $db->prepare('UPDATE gm_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?');
            $stmt->execute([$notificationId, $userId]);
        } else {
            $stmt = $db->prepare('UPDATE gm_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0');
            $stmt->execute([$userId]);
        }
    } catch (Throwable $e) {
        app()->log('Notifications mark-read error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Failed to update notifications'], JSON_UNESCAPED_SLASHES);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
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

function apiGuidanceTodayAppointments(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $role = is_array($user) ? (string)($user['role'] ?? '') : '';
    $isCounselor = $role === 'counselor';
    $counselorId = $isCounselor && is_array($user) ? (int)($user['id'] ?? 0) : null;

    $db = guidanceDb();
    $today = date('Y-m-d');
    $whereClause = "a.scheduled_date = ? AND a.status NOT IN ('cancelled', 'no_show')";
    $params = [$today];
    if ($isCounselor && $counselorId) {
        $whereClause .= ' AND a.counselor_id = ?';
        $params[] = $counselorId;
    }

    $stmt = $db->prepare(
        "SELECT a.*, COALESCE(a.student_name, c.student_name) AS student_name, "
        . "COALESCE(t.name, a.purpose, '') AS appointment_type, "
        . "COALESCE(t.duration_minutes, a.duration_minutes, 0) AS duration_minutes\n"
        . "FROM gm_appointments a\n"
        . "LEFT JOIN gm_cases c ON a.case_id = c.id\n"
        . "LEFT JOIN gm_appointment_types t ON a.appointment_type_id = t.id\n"
        . "WHERE {$whereClause}\n"
        . "ORDER BY a.scheduled_time ASC"
    );
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/today-schedule.disyl', [
        'appointments' => $appointments,
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

// Password Reset Helpers
function guidancePasswordResetTokenHash(string $token): string {
    return hash('sha256', $token);
}

function guidanceIssuePasswordResetToken(string $email, int $ttlSeconds = 3600): string {
    $token = bin2hex(random_bytes(32));
    $db = guidanceDb();
    $db->prepare('UPDATE gm_password_resets SET used_at = NOW() WHERE email = ? AND used_at IS NULL')->execute([$email]);
    $db->prepare(
        'INSERT INTO gm_password_resets (email, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
    )->execute([$email, guidancePasswordResetTokenHash($token), $ttlSeconds]);
    return $token;
}

function guidanceFindActivePasswordReset(string $token): ?array {
    $stmt = guidanceDb()->prepare(
        'SELECT * FROM gm_password_resets WHERE token = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([guidancePasswordResetTokenHash($token)]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);
    return $reset ?: null;
}

function guidanceMarkPasswordResetUsed(int|string $id): void {
    guidanceDb()->prepare('UPDATE gm_password_resets SET used_at = NOW() WHERE id = ?')->execute([$id]);
}

function pageGuidanceForgotPassword(): void {
    if (guidanceUserFromCookie()) {
        guidanceRedirect('/admin/guidance');
    }
    echo guidanceRender('modules/guidance/pages/forgot-password.disyl', [
        'hide_sidebar' => true,
        'page_title' => 'Forgot Password',
        'base_url' => '/guidance',
    ]);
}

function pageGuidanceResetPassword(): void {
    if (guidanceUserFromCookie()) {
        guidanceRedirect('/admin/guidance');
    }
    $token = guidanceInput('token', '');
    echo guidanceRender('modules/guidance/pages/reset-password.disyl', [
        'hide_sidebar' => true,
        'page_title' => 'Reset Password',
        'base_url' => '/guidance',
        'reset_token' => $token,
    ]);
}

function apiGuidanceForgotPassword(): void {
    try {
        $email = trim(guidanceInput('email', ''));
        if (empty($email)) {
            throw new Exception("Email is required");
        }

        $ip = clientIp();
        if (!rateLimit('guidance_forgot_' . $ip, 3, 900)) {
            throw new Exception("Too many reset requests. Please try again later.");
        }

        $successMsg = 'If an account with that email exists, a password reset link has been sent.';
        
        $stmt = guidanceDb()->prepare("SELECT id, first_name FROM gm_users WHERE email = ? AND deleted_at IS NULL AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            app()->json(['success' => true, 'message' => $successMsg]);
            return;
        }
        
        $token = guidanceIssuePasswordResetToken($email, 3600);
        // Let's use the current host if possible or just relative base + protocol
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $resetUrl = $scheme . "://" . $host . '/guidance/reset-password?token=' . $token;
        
        if (function_exists('sendEmail') && function_exists('buildEmailTemplate')) {
            $content = "
            <p style=\"margin: 0 0 20px; color: #4b5563; font-size: 16px;\">
                Someone requested a password reset for your Guidance Monitoring System account.
            </p>
            <p style=\"margin: 0 0 20px; color: #4b5563; font-size: 16px;\">
                If you did not request this, you can ignore this email.
                This link expires in 1 hour.
            </p>";
            $body = buildEmailTemplate(
                'Reset Your Password',
                $content,
                'Reset Password',
                $resetUrl
            );
            sendEmail($email, 'Password Reset Request', $body);
        } else {
            // fallback if mailer is missing
            error_log("Cannot send password reset email: Mailer helpers missing.");
        }
        
        app()->json(['success' => true, 'message' => $successMsg]);
    } catch (Throwable $e) {
        app()->json(['error' => $e->getMessage()], 400);
    }
}

function apiGuidanceResetPassword(): void {
    try {
        $token = guidanceInput('token', '');
        $password = guidanceInput('password', '');
        $confirm = guidanceInput('password_confirm', '');
        
        if (empty($token)) throw new Exception('Invalid or missing token.');
        if (empty($password)) throw new Exception('Password cannot be empty.');
        if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters.');
        if ($password !== $confirm) throw new Exception('Passwords do not match.');

        $ip = clientIp();
        if (!rateLimit('guidance_reset_' . $ip, 5, 900)) {
            throw new Exception('Too many attempts. Please try again later.');
        }

        $resetData = guidanceFindActivePasswordReset($token);
        if (!$resetData) {
            throw new Exception('Invalid or expired reset token. Please request a new one.');
        }

        $email = $resetData['email'];
        
        // Find user to get ID and update kernel credential if attached
        $stmt = guidanceDb()->prepare("SELECT id FROM gm_users WHERE email = ? AND deleted_at IS NULL AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new Exception('Account not found or inactive.');
        }
        
        $hash = password_hash($password, PASSWORD_DEFAULT);
        guidanceDb()->prepare('UPDATE gm_users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
                   ->execute([$hash, $user['id']]);
                   
        // also try kernel update if we can
        try {
            app()->cap()->invoke('kernel.auth.updatePassword', [
                'username' => '@guidance:' . $email,
                'password' => $password
            ]);
        } catch (Throwable $e) {
            // Ignore error here
        }

        guidanceMarkPasswordResetUsed($resetData['id']);
        
        app()->json([
            'success' => true,
            'message' => 'Password reset successfully. You can now log in.'
        ]);
        
    } catch (Throwable $e) {
        app()->json(['error' => $e->getMessage()], 400);
    }
}
