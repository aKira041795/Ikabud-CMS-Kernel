<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// Load DiSyL entity view configs for guidance entities (cases, appointments)
\Ikabud\Kernel\DiSyL\TemplateEngine::loadViewConfigs(__DIR__ . '/helpers/views');

// module.license.activate@1 is auto-wired by the module manager from module.json.
// The callable guidance_cap_module_license_activate_1() is defined in helpers.php.
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

function guidanceNotificationRuntimeSettings(): array
{
    $appointmentSettings = guidanceGetSettingJson('appointment_settings', []);
    $legacySettings = guidanceGetSettingJson('notification_settings', []);

    $defaultChannel = !empty($legacySettings['sms_enabled']) ? 'email_and_sms' : 'email_only';
    $channel = trim((string)guidanceGetSetting(
        'notification_channel',
        (string)($appointmentSettings['notification_channel'] ?? $defaultChannel)
    ));
    if ($channel !== 'email_and_sms') {
        $channel = 'email_only';
    }

    $defaultEmailEnabled = array_key_exists('email_enabled', $legacySettings)
        ? (!empty($legacySettings['email_enabled']) ? '1' : '0')
        : (array_key_exists('email_notifications', $appointmentSettings)
            ? (!empty($appointmentSettings['email_notifications']) ? '1' : '0')
            : '1');
    $emailEnabledRaw = strtolower(trim((string)guidanceGetSetting('email_notifications', $defaultEmailEnabled)));
    $emailEnabled = in_array($emailEnabledRaw, ['1', 'true', 'yes', 'on'], true);

    $defaultReminderHours = array_key_exists('appointment_reminder_hours', $legacySettings)
        ? (int)($legacySettings['appointment_reminder_hours'] ?? 24)
        : (int)($appointmentSettings['reminder_hours_before'] ?? 24);
    $reminderHours = max(0, (int)guidanceGetSetting('reminder_hours_before', (string)$defaultReminderHours));

    return [
        'notification_channel' => $channel,
        'email_enabled' => $emailEnabled,
        'email_notifications' => $emailEnabled ? '1' : '0',
        'sms_enabled' => $channel === 'email_and_sms',
        'reminder_hours_before' => $reminderHours,
    ];
}

function guidanceSendNotificationEmail(string $email, string $subject, string $body, array $options = []): bool
{
    $email = trim($email);
    $subject = trim($subject);
    if ($email === '' || $subject === '' || trim($body) === '') {
        return false;
    }
    if (!guidanceNotificationRuntimeSettings()['email_enabled'] || !function_exists('sendEmail')) {
        return false;
    }

    return sendEmail($email, $subject, $body, $options);
}

function guidanceEmitAutomationEvent(string $event, array $payload = []): void
{
    $settings = guidanceNotificationRuntimeSettings();
    $payload['notification_channel'] = (string)$settings['notification_channel'];
    $payload['email_notifications'] = (string)$settings['email_notifications'];
    $payload['sms_enabled'] = $settings['sms_enabled'] ? '1' : '0';
    guidanceFireEvent($event, $payload);
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

function guidanceDefaultCaseSeverityLevels(): array
{
    return ['low', 'medium', 'high', 'critical'];
}

function guidanceAllowedCaseSeverityLevels(): array
{
    try {
        $config = getCaseSeverityConfig(guidanceDb());
        return $config['levels'];
    } catch (Throwable $e) {
        return guidanceDefaultCaseSeverityLevels();
    }
}

function normalizeCaseSeverityValue(?string $value): string
{
    $value = strtolower(trim((string) $value));
    if ($value === 'moderate') {
        $value = 'medium';
    }

    return in_array($value, guidanceDefaultCaseSeverityLevels(), true) ? $value : '';
}

function guidanceCaseSeverityLabel(string $value): string
{
    return match (normalizeCaseSeverityValue($value)) {
        'low' => 'Low Risk',
        'medium' => 'Moderate Risk',
        'high' => 'High Risk',
        'critical' => 'Critical',
        default => trim((string) $value),
    };
}

function guidanceNormalizeCaseReferralSource(?string $value): string
{
    $value = strtolower(trim((string)$value));
    $allowed = ['walk-in', 'follow-up', 'referred', 'self', 'teacher', 'staff', 'parent', 'others'];
    return in_array($value, $allowed, true) ? $value : 'self';
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

    // Compose student_name from separate first/last name fields when present
    $firstName = trim((string)($input['student_first_name'] ?? ''));
    $lastName  = trim((string)($input['student_last_name'] ?? ''));
    if ($firstName !== '' || $lastName !== '') {
        $studentName = trim($lastName . ($lastName !== '' && $firstName !== '' ? ', ' : '') . $firstName);
    } else {
        $studentName = trim((string)($input['student_name'] ?? ''));
    }

    $category = strtolower(trim((string)($input['category'] ?? 'general')));
    if (!in_array($category, guidanceAllowedCaseCategories(), true)) {
        $category = 'general';
    }

    // Multi-select categories — stored as JSON array; primary category derived from first item
    $rawCategories = $input['categories'] ?? null;
    if (is_array($rawCategories) && $rawCategories !== []) {
        $cleanCategories = array_values(array_unique(array_filter(array_map(
            static fn ($v) => strtolower(trim((string)$v)), $rawCategories
        ))));
        $categoriesJson = json_encode($cleanCategories, JSON_UNESCAPED_UNICODE);
        $firstCategory = $cleanCategories[0] ?? 'general';
        $category = in_array($firstCategory, guidanceAllowedCaseCategories(), true) ? $firstCategory : 'general';
    } elseif (is_string($rawCategories) && trim($rawCategories) !== '') {
        // Comma-separated fallback
        $cleanCategories = array_values(array_unique(array_filter(array_map(
            static fn ($v) => strtolower(trim($v)), explode(',', $rawCategories)
        ))));
        $categoriesJson = json_encode($cleanCategories, JSON_UNESCAPED_UNICODE);
        $firstCategory = $cleanCategories[0] ?? 'general';
        $category = in_array($firstCategory, guidanceAllowedCaseCategories(), true) ? $firstCategory : 'general';
    } else {
        $categoriesJson = null;
    }

    try {
        $severityConfig = getCaseSeverityConfig(guidanceDb());
        $allowedSeverityLevels = $severityConfig['levels'];
        $defaultSeverity = $severityConfig['default'] !== '' ? $severityConfig['default'] : 'medium';
    } catch (Throwable $e) {
        $allowedSeverityLevels = guidanceDefaultCaseSeverityLevels();
        $defaultSeverity = 'medium';
    }

    $severity = normalizeCaseSeverityValue((string)($input['severity'] ?? $defaultSeverity));
    if ($severity === '' || !in_array($severity, $allowedSeverityLevels, true)) {
        $severity = $defaultSeverity;
    }

    $sessionDate = trim((string)($input['session_date'] ?? '')) ?: null;

    $payload = [
        'student_id' => trim((string)($input['student_id'] ?? '')),
        'student_first_name' => $firstName ?: null,
        'student_last_name' => $lastName ?: null,
        'student_name' => $studentName,
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
        'categories' => $categoriesJson,
        'severity' => $severity,
        'presenting_issue' => trim((string)($input['presenting_issue'] ?? '')),
        'background_info' => trim((string)($input['background_info'] ?? '')) ?: null,
        'case_predisposition' => trim((string)($input['case_predisposition'] ?? '')) ?: null,
        'case_precipitating' => trim((string)($input['case_precipitating'] ?? '')) ?: null,
        'case_perpetuating' => trim((string)($input['case_perpetuating'] ?? '')) ?: null,
        'case_protective' => trim((string)($input['case_protective'] ?? '')) ?: null,
        'session_date' => $sessionDate,
        'mse_appearance' => trim((string)($input['mse_appearance'] ?? '')) ?: null,
        'mse_mood' => trim((string)($input['mse_mood'] ?? '')) ?: null,
        'mse_affect' => trim((string)($input['mse_affect'] ?? '')) ?: null,
        'mse_behavior' => trim((string)($input['mse_behavior'] ?? '')) ?: null,
        'mse_speech' => trim((string)($input['mse_speech'] ?? '')) ?: null,
        'mse_thought_process' => trim((string)($input['mse_thought_process'] ?? '')) ?: null,
        'mse_insight' => trim((string)($input['mse_insight'] ?? '')) ?: null,
        'mse_judgment' => trim((string)($input['mse_judgment'] ?? '')) ?: null,
        'mse_notes' => trim((string)($input['mse_notes'] ?? '')) ?: null,
        'is_urgent' => !empty($input['is_urgent']) ? 1 : 0,
        'is_confidential' => 1,
        'parent_guardian_name' => trim((string)($input['parent_guardian_name'] ?? '')) ?: null,
        'parent_guardian_contact' => trim((string)($input['parent_guardian_contact'] ?? '')) ?: null,
        'emergency_contact_address' => trim((string)($input['emergency_contact_address'] ?? '')) ?: null,
        'referral_source' => guidanceNormalizeCaseReferralSource((string)($input['referral_source'] ?? 'self')),
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
    guidanceEmitAutomationEvent('guidance.appointment.created', [
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

function guidanceResolveCaseSourceAppointment(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $appointmentId, bool $isCounselor, int $userId): ?array
{
    if ($appointmentId < 1) {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM gm_appointments WHERE id = ? LIMIT 1');
    $stmt->execute([$appointmentId]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($appointment)) {
        throw new RuntimeException('Appointment not found.');
    }

    $appointment = guidanceMergeAppointmentBookingSnapshot($appointment);

    if ($isCounselor && (int)($appointment['counselor_id'] ?? 0) !== $userId) {
        throw new RuntimeException('Access denied.');
    }

    if ((int)($appointment['case_id'] ?? 0) > 0) {
        throw new RuntimeException('This appointment is already linked to a case.');
    }

    $status = strtolower(trim((string)($appointment['status'] ?? '')));
    if ($status !== 'confirmed') {
        throw new RuntimeException('Only confirmed appointments can be converted into a case.');
    }

    return $appointment;
}

function guidanceBuildCasePrefillFromAppointment(array $appointment): array
{
    $appointment = guidanceMergeAppointmentBookingSnapshot($appointment);

    return [
        'student_id' => trim((string)($appointment['student_id'] ?? '')),
        'student_name' => trim((string)($appointment['student_name'] ?? '')),
        'student_email' => trim((string)($appointment['student_email'] ?? '')),
        'student_mobile' => trim((string)($appointment['student_mobile'] ?? ($appointment['student_phone'] ?? ''))),
        'college_id' => (int)($appointment['college_id'] ?? ($appointment['student_college_id'] ?? 0)),
        'student_grade' => trim((string)($appointment['student_grade'] ?? ($appointment['student_year_level'] ?? ''))),
        'student_section' => trim((string)($appointment['student_section'] ?? '')),
        'date_of_birth' => trim((string)($appointment['date_of_birth'] ?? '')),
        'gender' => trim((string)($appointment['gender'] ?? '')),
        'nationality' => trim((string)($appointment['nationality'] ?? '')),
        'civil_status' => trim((string)($appointment['civil_status'] ?? '')),
        'address' => trim((string)($appointment['address'] ?? '')),
        'counselor_id' => (int)($appointment['counselor_id'] ?? 0),
        'presenting_issue' => trim((string)($appointment['presenting_issue'] ?? ($appointment['purpose'] ?? ''))),
        'background_info' => trim((string)($appointment['background_info'] ?? ($appointment['request_message'] ?? ''))),
        'is_urgent' => !empty($appointment['is_urgent']) ? '1' : '0',
        'parent_guardian_name' => trim((string)($appointment['parent_guardian_name'] ?? '')),
        'parent_guardian_contact' => trim((string)($appointment['parent_guardian_contact'] ?? '')),
        'emergency_contact_address' => trim((string)($appointment['emergency_contact_address'] ?? '')),
    ];
}

function guidanceAppointmentBookingSnapshotColumnExists(\Ikabud\Kernel\Contracts\DatabaseContract $db, bool $refresh = false): bool
{
    static $exists = [];
    $tid = app()->tenant()->current();

    if (!$refresh && array_key_exists($tid, $exists)) {
        return $exists[$tid];
    }

    try {
        $stmt = $db->query("SHOW COLUMNS FROM gm_appointments LIKE 'booking_snapshot_json'");
        $exists[$tid] = (bool)($stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false);
    } catch (Throwable $e) {
        $exists[$tid] = false;
    }

    return $exists[$tid];
}

function guidanceEnsureAppointmentBookingSnapshotColumn(\Ikabud\Kernel\Contracts\DatabaseContract $db): bool
{
    static $attempted = [];
    $tid = app()->tenant()->current();

    if (guidanceAppointmentBookingSnapshotColumnExists($db)) {
        return true;
    }

    if (!empty($attempted[$tid])) {
        return false;
    }
    $attempted[$tid] = true;

    try {
        $db->exec("ALTER TABLE gm_appointments ADD COLUMN booking_snapshot_json LONGTEXT DEFAULT NULL AFTER request_message");
    } catch (Throwable $e) {
        app()->log('Guidance booking snapshot schema sync failed: ' . $e->getMessage(), 'warning');
    }

    return guidanceAppointmentBookingSnapshotColumnExists($db, true);
}

function guidanceBuildAppointmentBookingSnapshot(array $payload): array
{
    $snapshot = [
        'student_id' => trim((string)($payload['student_id'] ?? ($payload['student_id_number'] ?? ''))),
        'student_name' => trim((string)($payload['student_name'] ?? '')),
        'student_email' => trim((string)($payload['student_email'] ?? '')),
        'student_mobile' => trim((string)($payload['student_phone'] ?? ($payload['student_mobile'] ?? ''))),
        'college_id' => !empty($payload['college_id']) ? (int)$payload['college_id'] : null,
        'student_grade' => trim((string)($payload['year_level'] ?? ($payload['student_grade'] ?? ''))),
        'student_section' => trim((string)($payload['student_section'] ?? '')),
        'date_of_birth' => trim((string)($payload['date_of_birth'] ?? '')),
        'gender' => trim((string)($payload['gender'] ?? '')),
        'nationality' => trim((string)($payload['nationality'] ?? '')),
        'civil_status' => trim((string)($payload['civil_status'] ?? '')),
        'address' => trim((string)($payload['address'] ?? '')),
        'presenting_issue' => trim((string)($payload['purpose'] ?? ($payload['presenting_issue'] ?? ''))),
        'background_info' => trim((string)($payload['message'] ?? ($payload['background_info'] ?? ''))),
        'is_urgent' => !empty($payload['is_urgent']) ? 1 : 0,
        'parent_guardian_name' => trim((string)($payload['parent_guardian_name'] ?? '')),
        'parent_guardian_contact' => trim((string)($payload['parent_guardian_contact'] ?? '')),
        'emergency_contact_address' => trim((string)($payload['emergency_contact_address'] ?? '')),
    ];

    return array_filter($snapshot, static function ($value): bool {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return true;
    });
}

function guidanceDecodeAppointmentBookingSnapshot(array $appointment): array
{
    $raw = $appointment['booking_snapshot_json'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function guidanceMergeAppointmentBookingSnapshot(array $appointment): array
{
    $snapshot = guidanceDecodeAppointmentBookingSnapshot($appointment);
    if ($snapshot !== []) {
        $columnMap = [
            'student_id' => 'student_id',
            'student_name' => 'student_name',
            'student_email' => 'student_email',
            'student_phone' => 'student_mobile',
            'student_college_id' => 'college_id',
            'student_year_level' => 'student_grade',
            'purpose' => 'presenting_issue',
            'request_message' => 'background_info',
            'student_section' => 'student_section',
            'date_of_birth' => 'date_of_birth',
            'gender' => 'gender',
            'nationality' => 'nationality',
            'civil_status' => 'civil_status',
            'address' => 'address',
            'parent_guardian_name' => 'parent_guardian_name',
            'parent_guardian_contact' => 'parent_guardian_contact',
            'emergency_contact_address' => 'emergency_contact_address',
        ];

        foreach ($columnMap as $appointmentKey => $snapshotKey) {
            $existing = $appointment[$appointmentKey] ?? null;
            $incoming = $snapshot[$snapshotKey] ?? null;
            if (($existing === null || $existing === '' || $existing === '0') && $incoming !== null && $incoming !== '') {
                $appointment[$appointmentKey] = $incoming;
            }
        }

        if (!isset($appointment['is_urgent']) || (int)$appointment['is_urgent'] === 0) {
            $appointment['is_urgent'] = !empty($snapshot['is_urgent']) ? 1 : 0;
        }
    }

    $appointment['student_mobile'] = trim((string)($snapshot['student_mobile'] ?? ($appointment['student_phone'] ?? '')));
    $appointment['college_id'] = !empty($snapshot['college_id'])
        ? (int)$snapshot['college_id']
        : (!empty($appointment['student_college_id']) ? (int)$appointment['student_college_id'] : null);
    $appointment['student_grade'] = trim((string)($snapshot['student_grade'] ?? ($appointment['student_year_level'] ?? '')));
    $appointment['presenting_issue'] = trim((string)($snapshot['presenting_issue'] ?? ($appointment['purpose'] ?? '')));
    $appointment['background_info'] = trim((string)($snapshot['background_info'] ?? ($appointment['request_message'] ?? '')));

    return $appointment;
}

function guidanceMergeCaseInputWithSourceAppointment(array $input, array $appointment): array
{
    $prefill = guidanceBuildCasePrefillFromAppointment($appointment);

    foreach ($prefill as $fieldName => $value) {
        if ($fieldName === 'is_urgent') {
            if (!array_key_exists($fieldName, $input)) {
                $input[$fieldName] = $value;
            }
            continue;
        }

        if (!array_key_exists($fieldName, $input) || trim((string)$input[$fieldName]) === '') {
            $input[$fieldName] = $value;
        }
    }

    return $input;
}

function guidanceBuildAutoCaseInputFromAppointment(array $appointment): array
{
    $appointmentId = (int)($appointment['id'] ?? 0);
    $prefill = guidanceBuildCasePrefillFromAppointment($appointment);
    $existingBackground = trim((string)($prefill['background_info'] ?? ''));
    $sourceNote = $appointmentId > 0
        ? 'Automatically created when appointment #' . $appointmentId . ' was confirmed.'
        : 'Automatically created when the appointment was confirmed.';

    $prefill['background_info'] = $existingBackground !== ''
        ? $existingBackground . "\n\n" . $sourceNote
        : $sourceNote;

    return array_merge($prefill, [
        'student_id' => trim((string)($prefill['student_id'] ?? '')) ?: 'N/A',
        'student_name' => trim((string)($prefill['student_name'] ?? '')) ?: 'Unknown Student',
        'category' => 'general',
        'severity' => !empty($appointment['is_urgent']) ? 'high' : 'medium',
        'is_urgent' => !empty($appointment['is_urgent']) ? 1 : 0,
        'is_confidential' => 1,
        'referral_source' => 'walk-in',
        'sync_id' => uniqid('sync_', true),
    ]);
}

function guidanceCaseInsertColumns(bool $hasStudentStatus): array
{
    $base = [
        'case_number',
        'student_id',
        'student_first_name',
        'student_last_name',
        'student_name',
        'student_grade',
        'student_section',
        'date_of_birth',
        'gender',
        'nationality',
        'civil_status',
        'address',
        'student_mobile',
        'student_email',
        'college_id',
        'counselor_id',
        'category',
        'categories',
        'severity',
        'presenting_issue',
        'background_info',
        'case_predisposition',
        'case_precipitating',
        'case_perpetuating',
        'case_protective',
        'session_date',
        'mse_appearance',
        'mse_mood',
        'mse_affect',
        'mse_behavior',
        'mse_speech',
        'mse_thought_process',
        'mse_insight',
        'mse_judgment',
        'mse_notes',
        'is_urgent',
        'is_confidential',
        'parent_guardian_name',
        'parent_guardian_contact',
        'emergency_contact_address',
        'referral_source',
        'referred_by',
        'sync_id',
        'created_by',
        'last_modified_by',
    ];

    if ($hasStudentStatus) {
        array_splice($base, 5, 0, ['student_status']);
    }

    return $base;
}

function guidanceFindActiveCaseByStudentEmail($db, string $email, ?int $excludeCaseId = null): ?array
{
    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail === '') {
        return null;
    }

    $sql = 'SELECT id, case_number, student_name FROM gm_cases WHERE LOWER(TRIM(student_email)) = ? AND deleted_at IS NULL';
    $params = [$normalizedEmail];

    if ($excludeCaseId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeCaseId;
    }

    $sql .= ' LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($existing) ? $existing : null;
}

function guidanceFindReusableCaseByStudentEmail($db, string $email): ?array
{
    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail === '') {
        return null;
    }

    $approvedStmt = $db->prepare(
        'SELECT c.id, c.case_number, c.student_name, c.student_email, c.student_mobile, c.college_id, c.student_grade, c.counselor_id '
        . 'FROM gm_appointments a '
        . 'JOIN gm_cases c ON c.id = a.case_id AND c.deleted_at IS NULL '
        . 'WHERE LOWER(TRIM(a.student_email)) = ? '
        . "AND (a.approved_at IS NOT NULL OR a.status IN ('confirmed', 'scheduled', 'completed', 'no_show', 'rescheduled')) "
        . 'ORDER BY COALESCE(a.approved_at, a.updated_at, a.created_at) DESC, a.id DESC '
        . 'LIMIT 1'
    );
    $approvedStmt->execute([$normalizedEmail]);
    $approvedCase = $approvedStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($approvedCase)) {
        return $approvedCase;
    }

    $activeCase = guidanceFindActiveCaseByStudentEmail($db, $normalizedEmail);
    if (!is_array($activeCase)) {
        return null;
    }

    $caseStmt = $db->prepare(
        'SELECT id, case_number, student_name, student_email, student_mobile, college_id, student_grade, counselor_id '
        . 'FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $caseStmt->execute([(int)($activeCase['id'] ?? 0)]);
    $caseRow = $caseStmt->fetch(PDO::FETCH_ASSOC);

    return is_array($caseRow) ? $caseRow : null;
}

function guidanceDuplicateStudentEmailMessage(array $existingCase): string
{
    $studentName = trim((string)($existingCase['student_name'] ?? '')) ?: 'another student';
    $caseNumber = trim((string)($existingCase['case_number'] ?? '')) ?: 'an existing case';

    return 'Email already used by ' . $studentName . ' (' . $caseNumber . ')';
}

function guidanceIsDuplicateStudentEmailDbError(Throwable $e): bool
{
    return stripos($e->getMessage(), 'active student email must be unique') !== false;
}

function guidanceIsDuplicateStudentEmailMessage(string $message): bool
{
    return str_contains(strtolower($message), 'email already used');
}

function guidanceRespondCaseConflict(string $message, int $status = 409): void
{
    http_response_code($status);

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $message, 'type' => 'error']]), true, $status);
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8', true, $status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
}

function guidanceAutoCreateCaseFromAppointment(
    \Ikabud\Kernel\Contracts\DatabaseContract $db,
    array $appointment,
    int $userId
): array {
    $appointmentId = (int)($appointment['id'] ?? 0);
    $counselorId = (int)($appointment['counselor_id'] ?? 0);

    if (!guidanceCounselorExists($db, $counselorId)) {
        throw new RuntimeException('Assigned counselor is invalid.');
    }

    $hasStudentStatus = studentStatusCasesColumnExists($db);
    $caseData = guidanceBuildCaseRecordPayload(
        guidanceBuildAutoCaseInputFromAppointment($appointment),
        $counselorId,
        $userId,
        $hasStudentStatus
    );

    $duplicateCase = guidanceFindActiveCaseByStudentEmail($db, (string)($caseData['student_email'] ?? ''));
    if ($duplicateCase !== null) {
        throw new RuntimeException(guidanceDuplicateStudentEmailMessage($duplicateCase));
    }

    $attempts = 0;
    do {
        $attempts++;
        $caseNumber = guidanceGenerateCaseNumber($db);

        try {
            $columns = guidanceCaseInsertColumns($hasStudentStatus);
            $values = [$caseNumber];
            foreach (array_slice($columns, 1) as $column) {
                $values[] = $caseData[$column] ?? null;
            }

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $db->prepare(
                'INSERT INTO gm_cases (' . implode(', ', $columns) . ', created_at, updated_at) VALUES (' . $placeholders . ', NOW(), NOW())'
            );
            $stmt->execute($values);

            $caseId = (int)$db->lastInsertId();
            guidanceInsertCaseStatusHistory($db, $caseId, null, 'open', $userId, 'Case auto-created from confirmed appointment');
            guidanceLinkAppointmentToCase($db, $caseId, $caseData, $appointment, $userId);
            guidanceLogAudit($db, 'case.created', 'gm_cases', $caseId, null, [
                'case_number' => $caseNumber,
                'appointment_id' => $appointmentId,
                'auto_created_from_appointment' => true,
            ], $userId);

            return [
                'id' => $caseId,
                'case_number' => $caseNumber,
                'appointment_id' => $appointmentId,
            ];
        } catch (PDOException $e) {
            if (guidanceIsDuplicateStudentEmailDbError($e)) {
                $duplicateCase = guidanceFindActiveCaseByStudentEmail($db, (string)($caseData['student_email'] ?? ''));
                throw new RuntimeException($duplicateCase !== null
                    ? guidanceDuplicateStudentEmailMessage($duplicateCase)
                    : 'Email already used by another active student case');
            }
            if ($attempts >= 3 || stripos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }
        }
    } while ($attempts < 3);

    throw new RuntimeException('Failed to generate unique case number');
}

function guidanceLinkAppointmentToCase(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $caseId, array $caseData, array $appointment, int $userId): int
{
    $appointmentId = (int)($appointment['id'] ?? 0);
    if ($appointmentId < 1) {
        throw new RuntimeException('Appointment not found.');
    }

    $stmt = $db->prepare(
        'UPDATE gm_appointments SET case_id = ?, counselor_id = ?, student_name = ?, student_email = ?, student_phone = ?, '
        . 'student_college_id = ?, student_year_level = ?, last_modified_by = ?, updated_at = NOW() '
        . 'WHERE id = ?'
    );
    $stmt->execute([
        $caseId,
        (int)($caseData['counselor_id'] ?? 0),
        (string)($caseData['student_name'] ?? ''),
        (string)($caseData['student_email'] ?? ''),
        (string)($caseData['student_mobile'] ?? ''),
        ($caseData['college_id'] ?? null),
        (string)($caseData['student_grade'] ?? ''),
        $userId,
        $appointmentId,
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

    $db = guidanceDb();
    $sourceAppointmentId = (int)($input['appointment_id'] ?? 0);
    $sourceAppointment = null;
    if ($sourceAppointmentId > 0) {
        try {
            $sourceAppointment = guidanceResolveCaseSourceAppointment($db, $sourceAppointmentId, $isCounselor, $userId);
            $input = guidanceMergeCaseInputWithSourceAppointment($input, $sourceAppointment);
        } catch (RuntimeException $e) {
            http_response_code(422);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $e->getMessage(), 'type' => 'error']]));
            echo '';
            return;
        }
    }

    $validationErrors = guidanceValidateFormInput('case', $input);
    if ($validationErrors !== []) {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $validationErrors[0], 'type' => 'error']]));
        echo '';
        return;
    }

    $hasStudentStatus = studentStatusCasesColumnExists($db);
    $counselorId = $isCounselor ? $userId : (int)($input['counselor_id'] ?? (int)($sourceAppointment['counselor_id'] ?? 0));

    try {
        if (!guidanceCounselorExists($db, $counselorId)) {
            http_response_code(422);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Selected counselor is invalid', 'type' => 'error']]));
            echo '';
            return;
        }

        $caseData = guidanceBuildCaseRecordPayload($input, $counselorId, $userId, $hasStudentStatus);
        $duplicateCase = guidanceFindActiveCaseByStudentEmail($db, (string)($caseData['student_email'] ?? ''));
        if ($duplicateCase !== null) {
            guidanceRespondCaseConflict(guidanceDuplicateStudentEmailMessage($duplicateCase));
            return;
        }

        $attempts = 0;
        do {
            $attempts++;
            $caseNumber = guidanceGenerateCaseNumber($db);
            try {
                $columns = guidanceCaseInsertColumns($hasStudentStatus);

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
                $appointmentId = null;
                if (is_array($sourceAppointment)) {
                    $appointmentId = guidanceLinkAppointmentToCase($db, $caseId, $caseData, $sourceAppointment, $userId);
                    $successMessage = 'Case created successfully and linked to the confirmed appointment';
                } else {
                    $successMessage = 'Case created successfully';
                }
                guidanceLogAudit($db, 'case.created', 'gm_cases', $caseId, null, array_merge($input, [
                    'case_number' => $caseNumber,
                    'appointment_id' => $appointmentId,
                ]), $userId);
                $db->commit();

                header('HX-Trigger: ' . json_encode([
                    'showToast' => ['message' => $successMessage, 'type' => 'success'],
                    'closeModal' => true,
                    'refreshCases' => true,
                    'refreshAppointments' => true,
                ]));

                if (guidanceIsHtmx()) {
                    guidanceClearCaseStatsCache();
                    header('HX-Redirect: /admin/guidance/cases/' . $caseId);
                    echo '';
                    return;
                }

                guidanceClearCaseStatsCache();
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
                if (guidanceIsDuplicateStudentEmailMessage($message)) {
                    guidanceRespondCaseConflict($message, 409);
                    return;
                }
                $status = str_contains(strtolower($message), 'time slot') ? 409 : 422;
                http_response_code($status);
                header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $message, 'type' => 'error']]));
                echo '';
                return;
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if (guidanceIsDuplicateStudentEmailDbError($e)) {
                    $duplicateCase = guidanceFindActiveCaseByStudentEmail($db, (string)($caseData['student_email'] ?? ''));
                    $message = $duplicateCase !== null
                        ? guidanceDuplicateStudentEmailMessage($duplicateCase)
                        : 'Email already used by another active student case';
                    guidanceRespondCaseConflict($message, 409);
                    return;
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
        $duplicateCase = guidanceFindActiveCaseByStudentEmail($db, (string)($caseData['student_email'] ?? ''), $caseId);
        if ($duplicateCase !== null) {
            guidanceRespondCaseConflict(guidanceDuplicateStudentEmailMessage($duplicateCase));
            return;
        }

        $allowedColumns = ['student_id', 'student_first_name', 'student_last_name', 'student_name', 'student_grade', 'student_section', 'date_of_birth', 'gender', 'nationality', 'civil_status', 'address', 'student_mobile', 'student_email', 'college_id', 'counselor_id', 'category', 'categories', 'severity', 'presenting_issue', 'background_info', 'case_predisposition', 'case_precipitating', 'case_perpetuating', 'case_protective', 'session_date', 'mse_appearance', 'mse_mood', 'mse_affect', 'mse_behavior', 'mse_speech', 'mse_thought_process', 'mse_insight', 'mse_judgment', 'mse_notes', 'is_urgent', 'is_confidential', 'parent_guardian_name', 'parent_guardian_contact', 'emergency_contact_address', 'referral_source', 'referred_by'];
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

            if ($column === 'is_confidential') {
                $shouldUpdate = true;
            }

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
        guidanceClearCaseStatsCache();
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case updated successfully', 'type' => 'success'], 'closeModal' => true, 'refreshCases' => true]));
        header('HX-Refresh: true');
        echo '';
        return;
    }

    guidanceClearCaseStatsCache();
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
        guidanceClearCaseStatsCache();
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case deleted successfully', 'type' => 'success'], 'refreshCases' => true]));
        header('HX-Redirect: /admin/guidance/cases');
        echo '';
        return;
    }

    guidanceClearCaseStatsCache();
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
        guidanceClearCaseStatsCache();
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case closed successfully', 'type' => 'success'], 'refreshCases' => true]));
        header('HX-Refresh: true');
        echo '';
        return;
    }

    guidanceClearCaseStatsCache();
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

    $prefilledCaseId = isset($_GET['case_id']) ? (string)(int)$_GET['case_id'] : '';
    echo guidanceRender('modules/guidance/modals/appointment-form.disyl', [
        'appointment' => [],
        'today' => date('Y-m-d'),
        'case_id' => $prefilledCaseId,
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
        'can_mark_outcome' => guidanceAppointmentCanMarkOutcome(
            (string)($appt['status'] ?? ''),
            (string)($appt['scheduled_date'] ?? ''),
            (string)($appt['scheduled_time'] ?? '')
        ),
        'outcome_locked_reason' => 'Complete and No Show become available for past appointments once the scheduled date and time is reached.',
        'base_url' => '/admin/guidance',
    ]);
}

function guidanceAppointmentScheduledAtReached(string $date, string $time): bool
{
    $date = trim($date);
    $time = trim($time);
    if ($date === '') {
        return false;
    }
    if ($time === '') {
        $time = '00:00:00';
    }

    try {
        $scheduledAt = new DateTimeImmutable($date . ' ' . $time);
        $now = new DateTimeImmutable('now');
    } catch (Throwable $e) {
        return false;
    }

    return $scheduledAt <= $now;
}

function guidanceAppointmentCanMarkOutcome(string $status, string $date, string $time): bool
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['pending', 'scheduled', 'confirmed'], true)) {
        return false;
    }

    return guidanceAppointmentScheduledAtReached($date, $time);
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

function guidanceNormalizeNoteSessionType(?string $value, string $default = 'walk-in'): string
{
    $sessionType = trim((string)$value);
    if ($sessionType !== '') {
        return $sessionType;
    }

    $fallback = trim($default);
    return $fallback !== '' ? $fallback : 'walk-in';
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
        guidanceEmitAutomationEvent('guidance.appointment.created', [
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
    app()->csrfEnforce();
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

    $ok = guidanceSetAppointmentStatus($db, $id, 'completed', $userId, ['pending', 'scheduled', 'confirmed']);
    if (!$ok) {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid status transition', 'type' => 'error']]));
        echo '';
        return;
    }
    guidanceClearAppointmentStatsCache();
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment completed', 'type' => 'success'], 'refreshAppointments' => true, 'refreshAppointmentsCalendar' => true]));
    echo '';
}

function apiGuidanceNoShowAppointment(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    app()->csrfEnforce();
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

    $ok = guidanceSetAppointmentStatus($db, $id, 'no_show', $userId, ['pending', 'scheduled', 'confirmed']);
    if (!$ok) {
        http_response_code(422);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid status transition', 'type' => 'error']]));
        echo '';
        return;
    }
    guidanceClearAppointmentStatsCache();
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Marked as no show', 'type' => 'success'], 'refreshAppointments' => true, 'refreshAppointmentsCalendar' => true]));
    echo '';
}

function apiGuidanceCancelAppointment(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    app()->csrfEnforce();
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
    guidanceClearAppointmentStatsCache();
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
        'forgot_password_endpoint' => '/api/v1/guidance/auth/forgot-password',
        'app_name' => guidanceGetSetting('app_name', 'Guidance Monitoring System') ?: 'Guidance Monitoring System',
    ]);
}

function guidanceBuildAuthSessionPayload(array $authRow, string $fallbackIdentity): array
{
    $role = (string)($authRow['role'] ?? '');
    $userId = (int)($authRow['id'] ?? 0);
    if ($userId < 1 || $role === '') {
        throw new RuntimeException('Invalid authentication payload.');
    }

    return [
        'sub' => $role . ':' . $userId,
        'id' => $userId,
        'username' => (string)($authRow['username'] ?? $fallbackIdentity),
        'name' => (string)($authRow['full_name'] ?? $fallbackIdentity),
        'role' => $role,
        'source' => 'guidance',
    ];
}

function guidanceFinalizeAuthSession(array $payload): void
{
    $token = app()->jwt()->generate($payload);
    guidanceSetAuthCookie($token, (int)config('app.jwt.expiration', 86400));
}

function guidanceAuthenticateCredentials(string $identity, string $password): ?array
{
    try {
        $authResult = app()->cap()->call('kernel.auth.authenticate@1', [
            'username' => '@guidance:' . $identity,
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
    $db->prepare('DELETE FROM gm_otp_codes WHERE expires_at <= ? OR verified_at IS NOT NULL')->execute([date('Y-m-d H:i:s')]);
    $db->prepare('DELETE FROM gm_otp_codes WHERE email = ? AND purpose = ?')->execute([$normalizedEmail, $purpose]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->prepare(
        'INSERT INTO gm_otp_codes (email, code, purpose, expires_at) VALUES (?, ?, ?, ?)'
    )->execute([$normalizedEmail, $code, $purpose, date('Y-m-d H:i:s', time() + $ttlSeconds)]);

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
    $identity = trim((string)($input['identity'] ?? $input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if ($identity === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username or email and password are required.']);
        return;
    }

    try {
        $authRow = guidanceAuthenticateCredentials($identity, $password);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Authentication failed.']);
        return;
    }

    if (!is_array($authRow)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or email/password combination.']);
        return;
    }

    $canonicalEmail = strtolower(trim((string)($authRow['username'] ?? '')));
    if ($canonicalEmail === '' || !filter_var($canonicalEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or email/password combination.']);
        return;
    }

    try {
        $sessionPayload = guidanceBuildAuthSessionPayload($authRow, $canonicalEmail);
    } catch (RuntimeException $e) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid username or email/password combination.']);
        return;
    }

    if (guidanceOtpEnabled('two_fa_login')) {
        $rateKey = 'guidance_login_otp_issue:' . guidanceOtpRequestIp() . ':' . sha1($canonicalEmail);
        if (!guidanceOtpRateLimitAllowed($rateKey, 5, guidanceOtpTicketTtlSeconds())) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'Too many verification requests. Please wait a few minutes and try again.']);
            return;
        }

        try {
            $challenge = guidanceCreateOtpChallenge(
                'guidance_login_otp',
                $canonicalEmail,
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
    $studentId = trim((string)($input['student_id'] ?? ($input['student_id_number'] ?? '')));
    $collegeId = (int)($input['college_id'] ?? 0);
    $yearLevel = trim((string)($input['year_level'] ?? ''));
    $studentSection = trim((string)($input['student_section'] ?? ''));
    $studentPhone = trim((string)($input['student_phone'] ?? ($input['student_mobile'] ?? '')));
    $dateOfBirth = trim((string)($input['date_of_birth'] ?? ''));
    $gender = trim((string)($input['gender'] ?? ''));
    $nationality = trim((string)($input['nationality'] ?? ''));
    $civilStatus = trim((string)($input['civil_status'] ?? ''));
    $address = trim((string)($input['address'] ?? ''));
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
        'student_id' => $studentId,
        'student_name' => $studentName,
        'student_email' => $studentEmail,
        'student_phone' => $studentPhone,
        'college_id' => $collegeId,
        'year_level' => $yearLevel,
        'student_section' => $studentSection,
        'date_of_birth' => $dateOfBirth,
        'gender' => $gender,
        'nationality' => $nationality,
        'civil_status' => $civilStatus,
        'address' => $address,
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

    $bookingSnapshotJson = null;
    $hasSnapshotColumn = guidanceEnsureAppointmentBookingSnapshotColumn($db);
    if ($hasSnapshotColumn) {
        $bookingSnapshot = guidanceBuildAppointmentBookingSnapshot($payload);
        if ($bookingSnapshot !== []) {
            $encodedSnapshot = json_encode($bookingSnapshot, JSON_UNESCAPED_SLASHES);
            if (is_string($encodedSnapshot) && $encodedSnapshot !== '') {
                $bookingSnapshotJson = $encodedSnapshot;
            }
        }
    }

    $columns = [
        'counselor_id',
        'student_id',
        'student_name',
        'student_email',
        'student_phone',
        'student_college_id',
        'student_year_level',
        'scheduled_date',
        'scheduled_time',
        'duration_minutes',
        'appointment_type_id',
        'purpose',
        'status',
        'requested_by_student',
        'request_message',
        'is_urgent',
        'created_by',
        'last_modified_by',
    ];
    $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', "'pending'", '1', '?', '?', '0', '0'];
    $values = [
        (int)($payload['counselor_id'] ?? 0),
        (($payload['student_id'] ?? '') !== '' ? (string)$payload['student_id'] : null),
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
    ];

    if ($hasSnapshotColumn) {
        $columns[] = 'booking_snapshot_json';
        $placeholders[] = '?';
        $values[] = $bookingSnapshotJson;
    }

    $stmt = $db->prepare(
        "INSERT INTO gm_appointments (\n"
        . ' ' . implode(', ', $columns) . "\n"
        . ") VALUES (" . implode(', ', $placeholders) . ')'
    );
    $stmt->execute($values);

    $appointmentId = (int)$db->lastInsertId();

    guidanceQueueCounselorNotification($db, (int)($payload['counselor_id'] ?? 0), $appointmentId, $payload);
    guidanceSendStudentBookingConfirmation($db, $appointmentId, $payload);

    guidanceEmitAutomationEvent('guidance.booking.created', [
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
        if (!function_exists('buildEmailTemplate')) {
            return;
        }

        $body = buildEmailTemplate('New Appointment Request', $content);
        guidanceSendNotificationEmail($email, 'New Appointment Request from ' . (string)($payload['student_name'] ?? 'Student'), $body);
    } catch (Throwable $e) {
        app()->log('Booking: failed to send counselor email: ' . $e->getMessage(), 'error');
    }
}

function guidanceRenderEmailTemplateString(string $template, array $variables): string
{
    if ($template === '' || $variables === []) {
        return $template;
    }

    $replacements = [];
    foreach ($variables as $key => $value) {
        $replacements['{' . $key . '}'] = (string)$value;
    }

    return strtr($template, $replacements);
}

function guidanceRenderEmailTemplateHtml(string $text): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $text);
    $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;
    $normalized = trim($normalized);
    if ($normalized === '') {
        return '';
    }

    $paragraphs = preg_split("/\n{2,}/", $normalized) ?: [];
    $html = [];
    foreach ($paragraphs as $paragraph) {
        $trimmed = trim((string)$paragraph);
        if ($trimmed === '') {
            continue;
        }
        $html[] = '<p>' . nl2br(htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8'), false) . '</p>';
    }

    return implode('', $html);
}

function guidanceSendAppointmentTemplateEmail(string $templateKey, string $email, array $variables): bool
{
    $email = trim($email);
    if ($email === '' || !function_exists('buildEmailTemplate')) {
        return false;
    }

    $templates = guidanceEmailTemplates();
    $template = $templates[$templateKey] ?? null;
    if (!is_array($template)) {
        return false;
    }

    $subject = trim(guidanceRenderEmailTemplateString((string)($template['subject'] ?? ''), $variables));
    $bodyText = guidanceRenderEmailTemplateString((string)($template['body'] ?? ''), $variables);
    $bodyHtml = guidanceRenderEmailTemplateHtml($bodyText);
    if ($subject === '' || $bodyHtml === '') {
        return false;
    }

    return guidanceSendNotificationEmail($email, $subject, buildEmailTemplate($subject, $bodyHtml));
}

function guidanceSendStudentBookingConfirmation(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $appointmentId, array $payload): void
{
    $email = trim((string)($payload['student_email'] ?? ''));
    if ($email === '') {
        return;
    }

    try {
        guidanceSendAppointmentTemplateEmail('booking_received', $email, [
            'student_name' => (string)($payload['student_name'] ?? 'Student'),
            'date' => date('F j, Y', strtotime((string)($payload['scheduled_date'] ?? date('Y-m-d')))),
            'time' => date('g:i A', strtotime((string)($payload['scheduled_time'] ?? '00:00'))),
            'location' => (string)($payload['location'] ?? 'Guidance Office'),
            'reason' => '',
            'appointment_id' => (string)$appointmentId,
        ]);
    } catch (Throwable $e) {
        app()->log('Booking: failed to send student confirmation: ' . $e->getMessage(), 'error');
    }
}

function guidanceAppointmentsDueForReminder(\Ikabud\Kernel\Contracts\DatabaseContract $db, ?DateTimeInterface $now = null, int $limit = 100): array
{
    $settings = guidanceNotificationRuntimeSettings();
    if (!$settings['email_enabled'] || $settings['reminder_hours_before'] < 1) {
        return [];
    }

    $limit = max(1, min(500, $limit));
    $nowAt = $now instanceof DateTimeInterface
        ? DateTimeImmutable::createFromInterface($now)
        : new DateTimeImmutable('now');
    $windowEnd = $nowAt->modify('+' . (int)$settings['reminder_hours_before'] . ' hours');

    try {
        $stmt = $db->prepare(
            "SELECT id, student_name, student_email, student_phone, scheduled_date, scheduled_time, duration_minutes, location, status\n"
            . "FROM gm_appointments\n"
            . "WHERE status IN ('confirmed', 'scheduled', 'rescheduled')\n"
            . "AND reminder_sent_at IS NULL\n"
            . "AND TIMESTAMP(scheduled_date, scheduled_time) > ?\n"
            . "AND TIMESTAMP(scheduled_date, scheduled_time) <= ?\n"
            . "ORDER BY scheduled_date ASC, scheduled_time ASC\n"
            . 'LIMIT ' . $limit
        );
        $stmt->execute([
            $nowAt->format('Y-m-d H:i:s'),
            $windowEnd->format('Y-m-d H:i:s'),
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        app()->log('Appointments reminder due query error: ' . $e->getMessage(), 'error');
        return [];
    }
}

function guidanceSendAppointmentReminder(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $appointmentId, ?DateTimeInterface $now = null): bool
{
    if ($appointmentId < 1) {
        return false;
    }

    $dueIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), guidanceAppointmentsDueForReminder($db, $now, 500));
    if (!in_array($appointmentId, $dueIds, true)) {
        return false;
    }

    try {
        $stmt = $db->prepare(
            "SELECT id, student_name, student_email, scheduled_date, scheduled_time, duration_minutes, location\n"
            . 'FROM gm_appointments WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$appointmentId]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($appointment)) {
            return false;
        }

        $email = trim((string)($appointment['student_email'] ?? ''));
        if ($email === '') {
            return false;
        }

        $studentName = trim((string)($appointment['student_name'] ?? 'Student'));
        $scheduledDate = date('F j, Y', strtotime((string)($appointment['scheduled_date'] ?? date('Y-m-d'))));
        $scheduledTime = date('g:i A', strtotime((string)($appointment['scheduled_time'] ?? '00:00')));
        $location = trim((string)($appointment['location'] ?? '')) !== ''
            ? (string)$appointment['location']
            : 'Guidance Office';

        $content = '<p>This is a reminder that you have an upcoming appointment.</p>'
            . '<p><strong>Date:</strong> ' . htmlspecialchars($scheduledDate, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Time:</strong> ' . htmlspecialchars($scheduledTime, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Location:</strong> ' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>If you need to reschedule, please contact the Guidance Office as soon as possible.</p>';
        $body = function_exists('buildEmailTemplate')
            ? buildEmailTemplate('Appointment Reminder', $content)
            : $content;

        if (!guidanceSendNotificationEmail($email, 'Appointment Reminder for ' . $studentName, $body)) {
            return false;
        }

        $db->prepare('UPDATE gm_appointments SET reminder_sent_at = NOW(), updated_at = NOW() WHERE id = ? AND reminder_sent_at IS NULL')
            ->execute([$appointmentId]);
        return true;
    } catch (Throwable $e) {
        app()->log('Appointments reminder send error: ' . $e->getMessage(), 'error');
        return false;
    }
}

function guidanceProcessAppointmentReminders(\Ikabud\Kernel\Contracts\DatabaseContract $db, ?DateTimeInterface $now = null, int $limit = 100): array
{
    $due = guidanceAppointmentsDueForReminder($db, $now, $limit);
    $sent = 0;
    $failed = 0;

    foreach ($due as $appointment) {
        $appointmentId = (int)($appointment['id'] ?? 0);
        if ($appointmentId < 1) {
            continue;
        }

        if (guidanceSendAppointmentReminder($db, $appointmentId, $now)) {
            $sent++;
        } else {
            $failed++;
        }
    }

    return ['due' => count($due), 'sent' => $sent, 'failed' => $failed];
}

function guidancePublicBookingSuccessPayload(array $payload, int $appointmentId): array
{
    $rawDate = (string)($payload['scheduled_date'] ?? '');
    $rawTime = (string)($payload['scheduled_time'] ?? '');
    $scheduledDateFmt = $rawDate !== '' ? date('F j, Y', strtotime($rawDate)) : '';
    $scheduledTimeFmt = $rawTime !== '' ? date('g:i A', strtotime($rawTime)) : '';
    $notificationSettings = guidanceNotificationRuntimeSettings();
    $studentEmail = (string)($payload['student_email'] ?? '');
    $confirmationNotice = $notificationSettings['email_enabled']
        ? ('A confirmation email will be sent to ' . $studentEmail . ' once your appointment is approved.')
        : 'Your request has been submitted for review. Guidance staff will confirm the appointment once it is approved.';
    $message = $notificationSettings['email_enabled']
        ? 'Appointment request submitted! You will receive a confirmation email once approved.'
        : 'Appointment request submitted! Guidance staff will review it and confirm the appointment once approved.';

    return [
        'ok' => true,
        'success' => true,
        'appointment_id' => $appointmentId,
        'message' => $message,
        'html' => guidanceRender('modules/guidance/partials/booking-success.disyl', [
            'appointment_id' => $appointmentId,
            'student_name' => (string)($payload['student_name'] ?? ''),
            'scheduled_date' => $scheduledDateFmt,
            'scheduled_time' => $scheduledTimeFmt,
            'student_email' => $studentEmail,
            'confirmation_notice' => $confirmationNotice,
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
    app()->csrfEnforce();
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
        guidanceClearAppointmentStatsCache();
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
    app()->csrfEnforce();

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
        guidanceClearAppointmentStatsCache();
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
    app()->csrfEnforce();

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
        'app_name' => guidanceGetSetting('app_name', 'Guidance Monitoring System') ?: 'Guidance Monitoring System',
        'user_name' => $name,
        'user_role' => $role,
        'user_initials' => $initials,
        'notifications_count' => $notificationsCount,
        'today_date' => date('M d, Y'),
        'hour' => (int)date('G'),
        'is_htmx' => guidanceIsHtmx(),
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

function pageGuidanceCaseNew(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $db     = guidanceDb();

    $counselors       = [];
    $colleges         = [];
    $appointmentTypes = [];
    $severityConfig   = guidanceGetCaseSeverityOptionsForForm($db);

    if ($role !== 'counselor') {
        try {
            $stmt = $db->prepare(
                "SELECT id, first_name, last_name, CONCAT(first_name, ' ', last_name) AS name
                 FROM gm_users WHERE role = 'counselor' AND deleted_at IS NULL AND is_active = 1
                 ORDER BY first_name, last_name"
            );
            $stmt->execute();
            $counselors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $counselors = [];
        }
    }

    try {
        $stmt = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name");
        $colleges = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $colleges = [];
    }

    try {
        $stmt = $db->query("SELECT id, name, duration_minutes FROM gm_appointment_types WHERE is_active = 1 ORDER BY sort_order, name");
        $appointmentTypes = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $appointmentTypes = [];
    }

    echo guidanceRender('modules/guidance/pages/case-new.disyl', array_merge(
        guidanceBasePageContext($user, 'Students', 'cases'),
        [
            'counselors'       => $counselors,
            'colleges'         => $colleges,
            'appointment_types' => $appointmentTypes,
            'severity_levels'  => $severityConfig['options'],
            'severity_default' => $severityConfig['default'],
            'user_role'        => $role,
            'is_admin'         => $role !== 'counselor',
            'today'            => date('Y-m-d'),
        ]
    ));
}

function pageGuidanceCaseView(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);

    $caseId = (int)($params['id'] ?? 0);
    if ($caseId < 1) {
        http_response_code(404);
        echo 'Case not found';
        return;
    }

    $db    = guidanceDb();
    $where = 'c.id = :id AND c.deleted_at IS NULL';
    $q     = [':id' => $caseId];
    if ($role === 'counselor') {
        $where    .= ' AND c.counselor_id = :cid';
        $q[':cid'] = $userId;
    }

    $stmt = $db->prepare(
        "SELECT c.*, CONCAT(u.first_name, ' ', u.last_name) AS counselor_name,\n"
        . "       col.name AS college_name, col.code AS college_code\n"
        . "FROM gm_cases c\n"
        . "LEFT JOIN gm_users u ON c.counselor_id = u.id\n"
        . "LEFT JOIN gm_colleges col ON c.college_id = col.id\n"
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

    // Age from date_of_birth
    $ageStr = '';
    if (!empty($case['date_of_birth'])) {
        try {
            $age    = (int)(new DateTimeImmutable())->diff(new DateTimeImmutable($case['date_of_birth']))->y;
            $ageStr = "({$age})";
        } catch (\Exception $e) {
            $ageStr = '';
        }
    }

    // Recent appointments (last 5)
    $apptStmt = $db->prepare(
        "SELECT a.id, a.scheduled_date, a.scheduled_time, a.duration_minutes, a.status, a.purpose,\n"
        . "       at.name AS type_name, CONCAT(u.first_name, ' ', u.last_name) AS counselor_name\n"
        . "FROM gm_appointments a\n"
        . "LEFT JOIN gm_appointment_types at ON a.appointment_type_id = at.id\n"
        . "LEFT JOIN gm_users u ON a.counselor_id = u.id\n"
        . "WHERE a.case_id = ?\n"
        . "ORDER BY a.scheduled_date DESC, a.scheduled_time DESC\n"
        . "LIMIT 5"
    );
    $apptStmt->execute([$caseId]);
    $recentSessions = $apptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($recentSessions as &$s) {
        $d = $s['scheduled_date'] ?? date('Y-m-d');
        $t = $s['scheduled_time'] ?? '00:00:00';
        try {
            $dt                  = new DateTimeImmutable($d . ' ' . $t);
            $s['formatted_date'] = $dt->format('M d, Y');
            $s['formatted_time'] = $dt->format('g:i A');
            $dur                 = (int)($s['duration_minutes'] ?? 30);
            $s['formatted_end']  = $dt->modify("+{$dur} minutes")->format('g:i A');
        } catch (\Exception $e) {
            $s['formatted_date'] = $d;
            $s['formatted_time'] = $t;
            $s['formatted_end']  = '';
        }
    }
    unset($s);

    $acStmt = $db->prepare("SELECT COUNT(*) FROM gm_appointments WHERE case_id = ?");
    $acStmt->execute([$caseId]);
    $apptCount = (int)$acStmt->fetchColumn();

    // Next upcoming appointment
    $nextStmt = $db->prepare(
        "SELECT a.id, a.scheduled_date, a.scheduled_time, a.duration_minutes, a.status, a.purpose,\n"
        . "       at.name AS type_name, CONCAT(u.first_name, ' ', u.last_name) AS counselor_name\n"
        . "FROM gm_appointments a\n"
        . "LEFT JOIN gm_appointment_types at ON a.appointment_type_id = at.id\n"
        . "LEFT JOIN gm_users u ON a.counselor_id = u.id\n"
        . "WHERE a.case_id = ? AND a.scheduled_date >= CURDATE()\n"
        . "  AND a.status IN ('pending','requested','scheduled','confirmed','rescheduled')\n"
        . "ORDER BY a.scheduled_date ASC, a.scheduled_time ASC\n"
        . "LIMIT 1"
    );
    $nextStmt->execute([$caseId]);
    $nextAppointment = $nextStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (is_array($nextAppointment)) {
        $d = $nextAppointment['scheduled_date'] ?? date('Y-m-d');
        $t = $nextAppointment['scheduled_time'] ?? '00:00:00';
        try {
            $dt                               = new DateTimeImmutable($d . ' ' . $t);
            $nextAppointment['formatted_date'] = $dt->format('F j, Y');
            $nextAppointment['formatted_dow']  = $dt->format('D');
            $nextAppointment['formatted_time'] = $dt->format('g:i A');
            $dur                               = (int)($nextAppointment['duration_minutes'] ?? 30);
            $nextAppointment['formatted_end']  = $dt->modify("+{$dur} minutes")->format('g:i A');
        } catch (\Exception $e) {
            $nextAppointment['formatted_date'] = $d;
            $nextAppointment['formatted_dow']  = '';
            $nextAppointment['formatted_time'] = $t;
            $nextAppointment['formatted_end']  = '';
        }
    }

    // Recent notes (2 for overview)
    $notesStmt = $db->prepare(
        "SELECT n.id, n.note_content, n.session_date, n.created_at,\n"
        . "       CONCAT(u.first_name, ' ', u.last_name) AS counselor_name\n"
        . "FROM gm_counselor_notes n\n"
        . "LEFT JOIN gm_users u ON n.counselor_id = u.id\n"
        . "WHERE n.case_id = ?\n"
        . "ORDER BY n.created_at DESC\n"
        . "LIMIT 2"
    );
    $notesStmt->execute([$caseId]);
    $recentNotes = $notesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ncStmt = $db->prepare("SELECT COUNT(*) FROM gm_counselor_notes WHERE case_id = ?");
    $ncStmt->execute([$caseId]);
    $notesCount = (int)$ncStmt->fetchColumn();

    // Recent documents (3 for overview)
    $docsStmt = $db->prepare(
        "SELECT a.id, a.file_name, a.file_type, a.uploaded_at,\n"
        . "       CONCAT(u.first_name, ' ', u.last_name) AS uploader_name\n"
        . "FROM gm_attachments a\n"
        . "LEFT JOIN gm_users u ON a.uploaded_by = u.id\n"
        . "WHERE a.case_id = ? AND a.deleted_at IS NULL\n"
        . "ORDER BY a.uploaded_at DESC\n"
        . "LIMIT 3"
    );
    $docsStmt->execute([$caseId]);
    $recentDocuments = $docsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dcStmt = $db->prepare("SELECT COUNT(*) FROM gm_attachments WHERE case_id = ? AND deleted_at IS NULL");
    $dcStmt->execute([$caseId]);
    $docsCount = (int)$dcStmt->fetchColumn();

    echo guidanceRender('modules/guidance/pages/case-view.disyl', array_merge(
        guidanceBasePageContext($user, 'Student Profile', 'cases'),
        [
            'case'             => $case,
            'age_str'          => $ageStr,
            'recent_sessions'  => $recentSessions,
            'appt_count'       => $apptCount,
            'next_appointment' => $nextAppointment,
            'recent_notes'     => $recentNotes,
            'notes_count'      => $notesCount,
            'recent_documents' => $recentDocuments,
            'docs_count'       => $docsCount,
            'can_delete_case'  => $role !== 'counselor',
            'show_case_notes'  => guidanceIsPro(),
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

function apiGuidanceCaseAppointments(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);

    if ($caseId < 1) {
        http_response_code(404);
        echo '';
        return;
    }

    $db     = guidanceDb();
    $csStmt = $db->prepare("SELECT id, counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $csStmt->execute([$caseId]);
    $caseRow = $csStmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($caseRow)) {
        http_response_code(404);
        echo '<div class="p-8 text-center text-gray-400">Case not found.</div>';
        return;
    }
    if ($role === 'counselor' && (int)($caseRow['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo '<div class="p-8 text-center text-red-400">Access denied.</div>';
        return;
    }

    $input = guidanceInput();
    $page = max(1, (int)($input['page'] ?? 1));
    $filter = strtolower(trim((string)($input['filter'] ?? 'all')));
    $allowedFilters = [
        'all' => 'All',
        'upcoming' => 'Upcoming',
        'completed' => 'Completed (Attended)',
        'no_show' => 'Did Not Show Up',
        'cancelled' => 'Cancelled',
    ];
    if (!isset($allowedFilters[$filter])) {
        $filter = 'all';
    }

    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    $whereSql = 'a.case_id = ?';
    $whereParams = [$caseId];
    if ($filter === 'upcoming') {
        $whereSql .= " AND LOWER(TRIM(a.status)) IN ('scheduled', 'confirmed', 'pending', 'requested', 'rescheduled') AND a.scheduled_date >= CURDATE()";
    } elseif ($filter === 'completed') {
        $whereSql .= " AND LOWER(TRIM(a.status)) = 'completed'";
    } elseif ($filter === 'no_show') {
        $whereSql .= " AND LOWER(TRIM(a.status)) = 'no_show'";
    } elseif ($filter === 'cancelled') {
        $whereSql .= " AND LOWER(TRIM(a.status)) = 'cancelled'";
    }

    $ctStmt = $db->prepare("SELECT COUNT(*) FROM gm_appointments a WHERE {$whereSql}");
    $ctStmt->execute($whereParams);
    $total      = (int)$ctStmt->fetchColumn();
    $totalPages = (int)ceil($total / max(1, $perPage));

    $aStmt = $db->prepare(
        "SELECT a.id, a.scheduled_date, a.scheduled_time, a.duration_minutes, a.status, LOWER(TRIM(a.status)) AS status_key, a.purpose,\n"
        . "       at.name AS type_name, CONCAT(u.first_name, ' ', u.last_name) AS counselor_name\n"
        . "FROM gm_appointments a\n"
        . "LEFT JOIN gm_appointment_types at ON a.appointment_type_id = at.id\n"
        . "LEFT JOIN gm_users u ON a.counselor_id = u.id\n"
        . "WHERE {$whereSql}\n"
        . "ORDER BY a.scheduled_date DESC, a.scheduled_time DESC\n"
        . "LIMIT {$perPage} OFFSET {$offset}"
    );
    $aStmt->execute($whereParams);
    $appointments = $aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($appointments as &$s) {
        $s['status_key'] = strtolower(trim((string)($s['status_key'] ?? ($s['status'] ?? ''))));
        $d = $s['scheduled_date'] ?? date('Y-m-d');
        $t = $s['scheduled_time'] ?? '00:00:00';
        try {
            $dt                  = new DateTimeImmutable($d . ' ' . $t);
            $s['formatted_date'] = $dt->format('M d, Y');
            $s['formatted_time'] = $dt->format('g:i A');
            $dur                 = (int)($s['duration_minutes'] ?? 30);
            $s['formatted_end']  = $dt->modify("+{$dur} minutes")->format('g:i A');
        } catch (\Exception $e) {
            $s['formatted_date'] = $d;
            $s['formatted_time'] = $t;
            $s['formatted_end']  = '';
        }
    }
    unset($s);

    $from = $total > 0 ? $offset + 1 : 0;
    $to   = min($offset + $perPage, $total);

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/case-appointments-tab.disyl', [
        'appointments' => $appointments,
        'total'        => $total,
        'page'         => $page,
        'total_pages'  => $totalPages,
        'from'         => $from,
        'to'           => $to,
        'case_id'      => $caseId,
        'base_url'     => '/admin/guidance',
        'current_filter' => $filter,
        'current_filter_label' => $allowedFilters[$filter],
    ]);
}

function apiGuidanceCaseSessionRecords(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);

    if ($caseId < 1) {
        http_response_code(404);
        echo '';
        return;
    }

    $db      = guidanceDb();
    $csStmt  = $db->prepare("SELECT id, counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $csStmt->execute([$caseId]);
    $caseRow = $csStmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($caseRow)) {
        http_response_code(404);
        echo '<div class="p-8 text-center text-gray-400">Case not found.</div>';
        return;
    }
    if ($role === 'counselor' && (int)($caseRow['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo '<div class="p-8 text-center text-red-400">Access denied.</div>';
        return;
    }

    $page    = max(1, (int)(guidanceInput()['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    // Total and per-status counts for stats row
        $statsSql = "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS cnt_completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cnt_cancelled,
                SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS cnt_no_show
                FROM gm_appointments WHERE case_id = ?
                    AND status IN ('completed','no_show','cancelled')";
    $stRow = $db->prepare($statsSql);
    $stRow->execute([$caseId]);
    $stats = $stRow->fetch(PDO::FETCH_ASSOC) ?: [];

    $total      = (int)($stats['total'] ?? 0);
    $totalPages = (int)ceil($total / max(1, $perPage));

    $aStmt = $db->prepare(
        "SELECT a.id, a.scheduled_date, a.scheduled_time, a.duration_minutes, a.status, a.purpose, a.location,\n"
        . "       at.name AS type_name, CONCAT(u.first_name, ' ', u.last_name) AS counselor_name\n"
        . "FROM gm_appointments a\n"
        . "LEFT JOIN gm_appointment_types at ON a.appointment_type_id = at.id\n"
        . "LEFT JOIN gm_users u ON a.counselor_id = u.id\n"
        . "WHERE a.case_id = ?\n"
        . "  AND a.status IN ('completed','no_show','cancelled')\n"
        . "ORDER BY a.scheduled_date DESC, a.scheduled_time DESC\n"
        . "LIMIT {$perPage} OFFSET {$offset}"
    );
    $aStmt->execute([$caseId]);
    $sessions = $aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($sessions as &$s) {
        $d = $s['scheduled_date'] ?? date('Y-m-d');
        $t = $s['scheduled_time'] ?? '00:00:00';
        try {
            $dt                  = new DateTimeImmutable($d . ' ' . $t);
            $s['formatted_date'] = $dt->format('M d, Y');
            $s['formatted_time'] = $dt->format('g:i A');
            $dur                 = (int)($s['duration_minutes'] ?? 30);
            $s['formatted_end']  = $dt->modify("+{$dur} minutes")->format('g:i A');
        } catch (\Exception $e) {
            $s['formatted_date'] = $d;
            $s['formatted_time'] = $t;
            $s['formatted_end']  = '';
        }
    }
    unset($s);

    $from = $total > 0 ? $offset + 1 : 0;
    $to   = min($offset + $perPage, $total);

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/case-session-records-tab.disyl', [
        'sessions'        => $sessions,
        'total'           => $total,
        'page'            => $page,
        'total_pages'     => $totalPages,
        'from'            => $from,
        'to'              => $to,
        'cnt_completed'   => (int)($stats['cnt_completed'] ?? 0),
        'cnt_cancelled'   => (int)($stats['cnt_cancelled'] ?? 0),
        'cnt_no_show'     => (int)($stats['cnt_no_show'] ?? 0),
        'case_id'         => $caseId,
        'base_url'        => '/admin/guidance',
    ]);
}

function apiGuidanceCaseSessionDetail(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);
    $apptId = (int)($params['apptId'] ?? 0);

    if ($caseId < 1 || $apptId < 1) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-gray-400">Not found.</div>';
        return;
    }

    $db      = guidanceDb();
    $csStmt  = $db->prepare("SELECT id, counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $csStmt->execute([$caseId]);
    $caseRow = $csStmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($caseRow)) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-gray-400">Case not found.</div>';
        return;
    }
    if ($role === 'counselor' && (int)($caseRow['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo '<div class="p-6 text-sm text-red-400">Access denied.</div>';
        return;
    }

    $aStmt = $db->prepare(
        "SELECT a.id, a.scheduled_date, a.scheduled_time, a.duration_minutes, a.status, a.purpose, a.location,\n"
        . "       at.name AS type_name, CONCAT(u.first_name, ' ', u.last_name) AS counselor_name\n"
        . "FROM gm_appointments a\n"
        . "LEFT JOIN gm_appointment_types at ON a.appointment_type_id = at.id\n"
        . "LEFT JOIN gm_users u ON a.counselor_id = u.id\n"
        . "WHERE a.id = ? AND a.case_id = ? LIMIT 1"
    );
    $aStmt->execute([$apptId, $caseId]);
    $appt = $aStmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($appt)) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-gray-400">Session not found.</div>';
        return;
    }

    // Format date/time
    $d = $appt['scheduled_date'] ?? date('Y-m-d');
    $t = $appt['scheduled_time'] ?? '00:00:00';
    try {
        $dt                       = new DateTimeImmutable($d . ' ' . $t);
        $appt['formatted_date']   = $dt->format('M d, Y');
        $appt['formatted_time']   = $dt->format('g:i A');
        $dur                      = (int)($appt['duration_minutes'] ?? 30);
        $appt['formatted_end']    = $dt->modify("+{$dur} minutes")->format('g:i A');
        $appt['duration_label']   = $dur . ' minute' . ($dur !== 1 ? 's' : '');
    } catch (\Exception $e) {
        $appt['formatted_date']  = $d;
        $appt['formatted_time']  = $t;
        $appt['formatted_end']   = '';
        $appt['duration_label']  = ($appt['duration_minutes'] ?? '') . ' mins';
    }

    // Linked counselor note (prefer appointment_id match, else most recent for the date)
    $note = [];
    try {
        $nStmt = $db->prepare(
            "SELECT n.id, n.note_content, n.observation_recommendation, n.risk_level, n.session_type\n"
            . "FROM gm_counselor_notes n\n"
            . "WHERE n.case_id = ? AND (n.appointment_id = ? OR n.session_date = ?)\n"
            . "ORDER BY (n.appointment_id = ?) DESC, n.created_at DESC LIMIT 1"
        );
        $nStmt->execute([$caseId, $apptId, $d, $apptId]);
        $note = $nStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\Exception $e) {
        // note is optional — ignore
    }

    // Attachments (linked to appointment or falling back to most recent case docs)
    $attachments = [];
    try {
        $attStmt = $db->prepare(
            "SELECT a.id, a.file_name, a.file_type, a.file_size, a.uploaded_at,\n"
            . "       CONCAT(u.first_name, ' ', u.last_name) AS uploader_name\n"
            . "FROM gm_attachments a\n"
            . "LEFT JOIN gm_users u ON a.uploaded_by = u.id\n"
            . "WHERE a.case_id = ? AND a.deleted_at IS NULL\n"
            . "ORDER BY a.uploaded_at DESC LIMIT 3"
        );
        $attStmt->execute([$caseId]);
        $rows = $attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $attachments = array_map(static function (array $row): array {
            $bytes = (int)($row['file_size'] ?? 0);
            if ($bytes <= 0) {
                $row['file_size_label'] = '';
            } elseif ($bytes < 1024) {
                $row['file_size_label'] = $bytes . ' B';
            } elseif ($bytes < 1048576) {
                $row['file_size_label'] = round($bytes / 1024) . ' KB';
            } else {
                $row['file_size_label'] = round($bytes / 1048576, 1) . ' MB';
            }
            return $row;
        }, $rows);
    } catch (\Exception $e) {
        // attachments optional
    }

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/case-session-detail.disyl', [
        'appt'        => $appt,
        'note'        => $note,
        'attachments' => $attachments,
        'case_id'     => $caseId,
        'base_url'    => '/admin/guidance',
    ]);
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

    $appointmentId = max(0, (int)guidanceInput('appointment_id', 0));
    $sessionType = guidanceNormalizeNoteSessionType((string)guidanceInput('session_type', ''));
    if ($sessionType === 'walk-in' && trim((string)guidanceInput('session_type', '')) === '' && $appointmentId > 0) {
        try {
            $appointmentStmt = $db->prepare(
                'SELECT COALESCE(at.name, a.appointment_type, "") AS session_type '
                . 'FROM gm_appointments a '
                . 'LEFT JOIN gm_appointment_types at ON a.appointment_type_id = at.id '
                . 'WHERE a.id = ? AND a.case_id = ? LIMIT 1'
            );
            $appointmentStmt->execute([$appointmentId, $caseId]);
            $appointmentSessionType = trim((string)($appointmentStmt->fetchColumn() ?: ''));
            if ($appointmentSessionType !== '') {
                $sessionType = $appointmentSessionType;
            }
        } catch (Throwable $e) {
            // Keep the safe fallback when the linked appointment cannot be resolved.
        }
    }

    $sessionDate = (string)guidanceInput('session_date', date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
        $sessionDate = date('Y-m-d');
    }

    $followupRequired = !empty(guidanceInput('followup_required', 0));

    echo guidanceRender('modules/guidance/modals/note-form.disyl', [
        'case_id' => $caseId,
        'today' => date('Y-m-d'),
        'session_type' => $sessionType,
        'session_date' => $sessionDate,
        'appointment_id' => $appointmentId,
        'followup_required' => $followupRequired,
        'base_url' => '/admin/guidance',
    ]);
}

function modalGuidanceCaseNoteEdit(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    guidanceRequirePro();
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);
    $noteId = (int)($params['noteId'] ?? 0);

    if ($caseId < 1 || $noteId < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Not found</div>';
        return;
    }

    $db     = guidanceDb();
    $csStmt = $db->prepare('SELECT id, counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $csStmt->execute([$caseId]);
    $case   = $csStmt->fetch(PDO::FETCH_ASSOC);
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

    $nStmt = $db->prepare('SELECT * FROM gm_counselor_notes WHERE id = ? AND case_id = ? LIMIT 1');
    $nStmt->execute([$noteId, $caseId]);
    $note = $nStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($note)) {
        http_response_code(404);
        echo '<div class="p-4 text-red-600">Note not found</div>';
        return;
    }

    echo guidanceRender('modules/guidance/modals/note-form-edit.disyl', [
        'note'    => $note,
        'case_id' => $caseId,
        'note_id' => $noteId,
        'today'   => date('Y-m-d'),
        'base_url' => '/admin/guidance',
    ]);
}

function apiGuidanceCaseNoteUpdate(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    guidanceRequirePro();
    app()->csrfEnforce();
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);
    $noteId = (int)($params['noteId'] ?? 0);

    if ($caseId < 1 || $noteId < 1) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Not found'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $db     = guidanceDb();
    $csStmt = $db->prepare('SELECT id, counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $csStmt->execute([$caseId]);
    $case   = $csStmt->fetch(PDO::FETCH_ASSOC);
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

    $nStmt = $db->prepare('SELECT id FROM gm_counselor_notes WHERE id = ? AND case_id = ? LIMIT 1');
    $nStmt->execute([$noteId, $caseId]);
    if (!$nStmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Note not found'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $input = (array)(app()->input() ?? []);
    $noteContent = trim((string)($input['note_content'] ?? ''));
    if ($noteContent === '') {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Note content is required'], JSON_UNESCAPED_SLASHES);
        return;
    }

    $sessionType = guidanceNormalizeNoteSessionType((string)($input['session_type'] ?? 'walk-in'));
    $riskLevel = in_array($input['risk_level'] ?? '', ['none', 'low', 'moderate', 'high', 'critical'], true)
        ? $input['risk_level']
        : 'none';
    $sessionDate = !empty($input['session_date']) ? $input['session_date'] : date('Y-m-d');

    $upStmt = $db->prepare(
        "UPDATE gm_counselor_notes SET\n"
        . "  session_type = ?, session_date = ?, session_duration_minutes = ?,\n"
        . "  note_content = ?, intervention_used = ?, student_response = ?,\n"
        . "  risk_level = ?, mood_assessment = ?, action_taken = ?,\n"
        . "  mse_appearance = ?, mse_behavior = ?, mse_speech = ?, mse_emotions = ?,\n"
        . "  mse_thinking = ?, mse_cognition = ?, mse_judgment = ?, mse_reliability = ?,\n"
        . "  case_predisposition = ?, case_precipitating = ?, case_perpetuating = ?, case_protective = ?,\n"
        . "  observation_recommendation = ?,\n"
        . "  followup_required = ?, followup_notes = ?, is_confidential = ?,\n"
        . "  updated_at = NOW()\n"
        . "WHERE id = ? AND case_id = ?"
    );
    $upStmt->execute([
        $sessionType,
        $sessionDate,
        !empty($input['session_duration_minutes']) ? (int)$input['session_duration_minutes'] : null,
        $noteContent,
        ($input['intervention_used'] ?? '') !== '' ? (string)$input['intervention_used'] : null,
        ($input['student_response'] ?? '') !== '' ? (string)$input['student_response'] : null,
        $riskLevel,
        ($input['mood_assessment'] ?? '') !== '' ? (string)$input['mood_assessment'] : null,
        ($input['action_taken'] ?? '') !== '' ? (string)$input['action_taken'] : null,
        ($input['mse_appearance'] ?? '') !== '' ? (string)$input['mse_appearance'] : null,
        ($input['mse_behavior'] ?? '') !== '' ? (string)$input['mse_behavior'] : null,
        ($input['mse_speech'] ?? '') !== '' ? (string)$input['mse_speech'] : null,
        ($input['mse_emotions'] ?? '') !== '' ? (string)$input['mse_emotions'] : null,
        ($input['mse_thinking'] ?? '') !== '' ? (string)$input['mse_thinking'] : null,
        ($input['mse_cognition'] ?? '') !== '' ? (string)$input['mse_cognition'] : null,
        ($input['mse_judgment'] ?? '') !== '' ? (string)$input['mse_judgment'] : null,
        ($input['mse_reliability'] ?? '') !== '' ? (string)$input['mse_reliability'] : null,
        ($input['case_predisposition'] ?? '') !== '' ? (string)$input['case_predisposition'] : null,
        ($input['case_precipitating'] ?? '') !== '' ? (string)$input['case_precipitating'] : null,
        ($input['case_perpetuating'] ?? '') !== '' ? (string)$input['case_perpetuating'] : null,
        ($input['case_protective'] ?? '') !== '' ? (string)$input['case_protective'] : null,
        ($input['observation_recommendation'] ?? '') !== '' ? (string)$input['observation_recommendation'] : null,
        !empty($input['followup_required']) ? 1 : 0,
        ($input['followup_notes'] ?? '') !== '' ? (string)$input['followup_notes'] : null,
        !empty($input['is_confidential']) ? 1 : 0,
        $noteId,
        $caseId,
    ]);

    $db->prepare("UPDATE gm_cases SET updated_at = NOW() WHERE id = ?")->execute([$caseId]);

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast'   => ['message' => 'Note updated successfully', 'type' => 'success'],
            'closeModal'  => true,
            'refreshNotes' => true,
            'refreshCasePanel' => [
                'caseId' => $caseId,
                'pane' => 'view',
                'noteId' => $noteId,
            ],
        ]));
        http_response_code(200);
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

// ---------------------------------------------------------------------------
// Documents tab helpers
// ---------------------------------------------------------------------------

function guidanceCategoryFromFilename(string $filename): string
{
    $lower = strtolower($filename);
    if (preg_match('/intake|assessment|initial/', $lower)) return 'intake_assessment';
    if (preg_match('/session|note/', $lower))              return 'session_notes';
    if (preg_match('/plan|goal/', $lower))                 return 'plans_goals';
    if (preg_match('/report/', $lower))                    return 'reports';
    if (preg_match('/consent|form/', $lower))              return 'forms_consent';
    if (preg_match('/letter|correspondence/', $lower))     return 'correspondence';
    return 'other';
}

function guidanceCategoryLabel(string $cat): string
{
    $map = [
        'intake_assessment' => 'Intake & Assessment',
        'session_notes'     => 'Session Notes',
        'plans_goals'       => 'Plans & Goals',
        'reports'           => 'Reports',
        'forms_consent'     => 'Forms & Consent',
        'correspondence'    => 'Correspondence',
        'other'             => 'Other',
    ];
    return $map[$cat] ?? 'Other';
}

function guidanceCategoryBadgeClass(string $cat): string
{
    $map = [
        'intake_assessment' => 'bg-blue-100 text-blue-700',
        'session_notes'     => 'bg-teal-100 text-teal-700',
        'plans_goals'       => 'bg-purple-100 text-purple-700',
        'reports'           => 'bg-orange-100 text-orange-700',
        'forms_consent'     => 'bg-green-100 text-green-700',
        'correspondence'    => 'bg-indigo-100 text-indigo-700',
        'other'             => 'bg-gray-100 text-gray-600',
    ];
    return $map[$cat] ?? 'bg-gray-100 text-gray-600';
}

function guidanceDocFileIcon(string $fileType): array
{
    $lower = strtolower(trim($fileType, '.'));
    if ($lower === 'pdf') return ['icon' => 'fa-file-pdf', 'color' => 'text-red-500'];
    if (in_array($lower, ['doc', 'docx'], true)) return ['icon' => 'fa-file-word', 'color' => 'text-blue-500'];
    if (in_array($lower, ['xls', 'xlsx', 'csv'], true)) return ['icon' => 'fa-file-excel', 'color' => 'text-green-500'];
    if (in_array($lower, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) return ['icon' => 'fa-file-image', 'color' => 'text-purple-500'];
    return ['icon' => 'fa-file', 'color' => 'text-gray-400'];
}

function guidanceDocFileSizeLabel(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// ---------------------------------------------------------------------------
// Documents tab — list
// ---------------------------------------------------------------------------

function apiGuidanceCaseDocuments(array $params = []): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);

    if ($caseId < 1) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-red-600">Case not found</div>';
        return;
    }

    $db     = guidanceDb();
    $csStmt = $db->prepare('SELECT id, counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $csStmt->execute([$caseId]);
    $case   = $csStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-red-600">Case not found</div>';
        return;
    }
    if ($role === 'counselor' && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo '<div class="p-6 text-sm text-red-600">Access denied</div>';
        return;
    }

    // Valid categories
    $validCategories = ['intake_assessment', 'session_notes', 'plans_goals', 'reports', 'forms_consent', 'correspondence', 'other'];

    $filterCat = isset($_GET['folder']) && in_array($_GET['folder'], $validCategories, true)
        ? $_GET['folder']
        : 'all';
    $search    = trim((string)($_GET['q'] ?? ''));
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $perPage   = 8;

    // Fetch all docs (for sidebar counts + storage) — no pagination here
    $allStmt = $db->prepare(
        "SELECT a.id, a.file_name, a.file_type, a.file_size, a.file_category, a.description,\n"
        . "       a.uploaded_at, a.file_path,\n"
        . "       CONCAT(u.first_name, ' ', u.last_name) AS uploader_name\n"
        . "FROM gm_attachments a\n"
        . "LEFT JOIN gm_users u ON a.uploaded_by = u.id\n"
        . "WHERE a.case_id = ? AND a.deleted_at IS NULL\n"
        . "ORDER BY a.uploaded_at DESC"
    );
    $allStmt->execute([$caseId]);
    $allDocs = $allStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Derive category for each row (fallback from filename if column is 'other' or empty)
    foreach ($allDocs as &$doc) {
        $cat = trim((string)($doc['file_category'] ?? ''));
        if ($cat === '' || $cat === 'other') {
            $inferred = guidanceCategoryFromFilename((string)$doc['file_name']);
            if ($inferred !== 'other') {
                $doc['file_category'] = $inferred;
            } else {
                $doc['file_category'] = 'other';
            }
        }
        $doc['category_label'] = guidanceCategoryLabel($doc['file_category']);
        $doc['category_badge'] = guidanceCategoryBadgeClass($doc['file_category']);
        $fileInfo              = guidanceDocFileIcon((string)($doc['file_type'] ?? ''));
        $doc['file_icon']      = $fileInfo['icon'];
        $doc['file_icon_color'] = $fileInfo['color'];
        $doc['file_size_label'] = guidanceDocFileSizeLabel((int)($doc['file_size'] ?? 0));
        $doc['uploaded_at_fmt'] = !empty($doc['uploaded_at']) ? date('M j, Y', strtotime($doc['uploaded_at'])) : '—';
        $doc['uploaded_at_time'] = !empty($doc['uploaded_at']) ? date('g:i A', strtotime($doc['uploaded_at'])) : '';
    }
    unset($doc);

    // Category counts for sidebar
    $catCounts = array_fill_keys($validCategories, 0);
    $totalStorage = 0;
    foreach ($allDocs as $d) {
        $c = $d['file_category'];
        if (isset($catCounts[$c])) $catCounts[$c]++;
        $totalStorage += (int)($d['file_size'] ?? 0);
    }
    $totalDocs = count($allDocs);

    // Apply folder + search filter for the paginated list
    $filtered = array_filter($allDocs, function ($d) use ($filterCat, $search) {
        if ($filterCat !== 'all' && $d['file_category'] !== $filterCat) return false;
        if ($search !== '') {
            $hay = strtolower((string)$d['file_name'] . ' ' . (string)$d['description'] . ' ' . (string)$d['uploader_name']);
            if (strpos($hay, strtolower($search)) === false) return false;
        }
        return true;
    });
    $filtered    = array_values($filtered);
    $totalFound  = count($filtered);
    $totalPages  = max(1, (int)ceil($totalFound / $perPage));
    $page        = min($page, $totalPages);
    $offset      = ($page - 1) * $perPage;
    $pageDocs    = array_slice($filtered, $offset, $perPage);

    // Storage bar: 500 MB limit
    $storageLimitBytes = 500 * 1048576;
    $storagePct = $storageLimitBytes > 0 ? min(100, round(($totalStorage / $storageLimitBytes) * 100, 1)) : 0;
    $storageMb  = round($totalStorage / 1048576, 1);

    $allCategories = [
        ['key' => 'intake_assessment', 'label' => 'Intake & Assessment', 'desc' => 'Initial assessments and intake paperwork', 'color' => 'bg-blue-500'],
        ['key' => 'session_notes',     'label' => 'Session Notes',       'desc' => 'Notes from counseling sessions',           'color' => 'bg-teal-500'],
        ['key' => 'plans_goals',       'label' => 'Plans & Goals',       'desc' => 'Treatment plans and goal tracking',         'color' => 'bg-purple-500'],
        ['key' => 'reports',           'label' => 'Reports',             'desc' => 'Progress reports and evaluations',          'color' => 'bg-orange-500'],
        ['key' => 'forms_consent',     'label' => 'Forms & Consent',     'desc' => 'Consent forms and legal documents',         'color' => 'bg-green-500'],
        ['key' => 'other',             'label' => 'Other',               'desc' => 'Miscellaneous documents',                   'color' => 'bg-gray-400'],
    ];

    $folders = [['key' => 'all', 'label' => 'All Documents', 'count' => $totalDocs, 'icon' => 'fa-folder-open']];
    foreach ($allCategories as $cat) {
        $folders[] = [
            'key'   => $cat['key'],
            'label' => $cat['label'],
            'count' => $catCounts[$cat['key']] ?? 0,
            'icon'  => 'fa-folder',
        ];
    }

    echo guidanceRender('modules/guidance/partials/case-documents-tab.disyl', [
        'case_id'       => $caseId,
        'docs'          => $pageDocs,
        'all_docs_count' => $totalDocs,
        'total_found'   => $totalFound,
        'page'          => $page,
        'per_page'      => $perPage,
        'total_pages'   => $totalPages,
        'filter_cat'    => $filterCat,
        'search'        => $search,
        'page_start'    => $totalFound > 0 ? ($page - 1) * $perPage + 1 : 0,
        'page_end'      => min($page * $perPage, $totalFound),
        'cat_counts'    => $catCounts,
        'folders'       => $folders,
        'total_storage_bytes' => $totalStorage,
        'storage_mb'    => $storageMb,
        'storage_pct'   => $storagePct,
        'all_categories' => $allCategories,
        'valid_categories' => $validCategories,
    ]);
}

// ---------------------------------------------------------------------------
// Documents tab — upload modal (GET)
// ---------------------------------------------------------------------------

function modalGuidanceCaseDocumentUpload(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $caseId = (int)($params['id'] ?? 0);
    if ($caseId < 1) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-red-600">Case not found</div>';
        return;
    }
    $db     = guidanceDb();
    $csStmt = $db->prepare('SELECT id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $csStmt->execute([$caseId]);
    if (!$csStmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-red-600">Case not found</div>';
        return;
    }
    echo guidanceRender('modules/guidance/modals/document-upload-form.disyl', [
        'case_id'   => $caseId,
        'csrf_token' => app()->csrfToken(),
    ]);
}

// ---------------------------------------------------------------------------
// Documents tab — upload handler (POST)
// ---------------------------------------------------------------------------

function apiGuidanceCaseDocumentUpload(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    app()->csrfEnforce();
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);

    if ($caseId < 1) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-red-600">Case not found</div>';
        return;
    }

    $db     = guidanceDb();
    $csStmt = $db->prepare('SELECT id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $csStmt->execute([$caseId]);
    if (!$csStmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-red-600">Case not found</div>';
        return;
    }

    if (empty($_FILES['document_file']) || (int)($_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo '<div class="p-4 text-sm text-red-600">Please select a file to upload.</div>';
        return;
    }

    $file    = $_FILES['document_file'];
    $origName = basename((string)($file['name'] ?? ''));
    $tmpName  = (string)($file['tmp_name'] ?? '');
    $fileSize = (int)($file['size'] ?? 0);

    // Validate size: 50 MB limit
    if ($fileSize > 50 * 1048576) {
        http_response_code(422);
        echo '<div class="p-4 text-sm text-red-600">File exceeds the 50 MB upload limit.</div>';
        return;
    }

    // Validate extension
    $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'png', 'jpg', 'jpeg', 'gif', 'txt'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        http_response_code(422);
        echo '<div class="p-4 text-sm text-red-600">File type .' . htmlspecialchars($ext) . ' is not allowed.</div>';
        return;
    }

    // Validate MIME via finfo
    $finfo    = new \finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpName);
    $allowedMime = [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv', 'text/plain',
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
    ];
    if (!in_array($mimeType, $allowedMime, true)) {
        http_response_code(422);
        echo '<div class="p-4 text-sm text-red-600">File type not permitted.</div>';
        return;
    }

    // Sanitize filename
    $safeName   = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $origName);
    $safeName   = ltrim($safeName, '.');
    $uniqueName = date('Ymd_His') . '_' . $safeName;

    $uploadDir = STORAGE_PATH . '/guidance/uploads/cases/' . $caseId;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true)) {
        http_response_code(500);
        echo '<div class="p-4 text-sm text-red-600">Could not create upload directory.</div>';
        return;
    }

    $destPath = $uploadDir . '/' . $uniqueName;
    if (!move_uploaded_file($tmpName, $destPath)) {
        http_response_code(500);
        echo '<div class="p-4 text-sm text-red-600">Upload failed. Please try again.</div>';
        return;
    }

    $validCategories = ['intake_assessment', 'session_notes', 'plans_goals', 'reports', 'forms_consent', 'correspondence', 'other'];
    $category = in_array($_POST['file_category'] ?? '', $validCategories, true)
        ? $_POST['file_category']
        : guidanceCategoryFromFilename($origName);
    $description = trim((string)($_POST['description'] ?? ''));
    $description = $description !== '' ? substr($description, 0, 255) : null;

    // Relative path stored (from STORAGE_PATH)
    $relPath = 'guidance/uploads/cases/' . $caseId . '/' . $uniqueName;

    $insStmt = $db->prepare(
        "INSERT INTO gm_attachments\n"
        . "  (case_id, file_name, file_path, file_type, file_size, file_category, description, uploaded_by, uploaded_at)\n"
        . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $insStmt->execute([$caseId, $origName, $relPath, $ext, $fileSize, $category, $description, $userId]);

    $db->prepare("UPDATE gm_cases SET updated_at = NOW() WHERE id = ?")->execute([$caseId]);

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast'      => ['message' => 'Document uploaded successfully', 'type' => 'success'],
            'closeModal'     => true,
            'refreshDocuments' => true,
        ]));
        http_response_code(200);
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
}

// ---------------------------------------------------------------------------
// Documents tab — download handler (GET)
// ---------------------------------------------------------------------------

function apiGuidanceCaseDocumentDownload(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);
    $docId  = (int)($params['docId'] ?? 0);

    if ($caseId < 1 || $docId < 1) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $db     = guidanceDb();
    $csStmt = $db->prepare('SELECT id, counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $csStmt->execute([$caseId]);
    $case   = $csStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        http_response_code(404);
        echo 'Case not found';
        return;
    }
    if ($role === 'counselor' && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo 'Access denied';
        return;
    }

    $dStmt = $db->prepare("SELECT file_name, file_path, file_type, file_size FROM gm_attachments WHERE id = ? AND case_id = ? AND deleted_at IS NULL LIMIT 1");
    $dStmt->execute([$docId, $caseId]);
    $doc   = $dStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($doc)) {
        http_response_code(404);
        echo 'Document not found';
        return;
    }

    $filePath = STORAGE_PATH . '/' . ltrim((string)$doc['file_path'], '/');
    if (!is_file($filePath)) {
        http_response_code(404);
        echo 'File not found on server';
        return;
    }

    $fileName = basename((string)$doc['file_name']);
    $mimeMap  = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv'  => 'text/csv',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'txt'  => 'text/plain',
    ];
    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mime     = $mimeMap[$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-store');
    readfile($filePath);
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

    $search  = trim((string)($_GET['q'] ?? ''));
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    $noteTypeLabels = [
        'session'      => 'Progress Note',
        'phone'        => 'Phone Note',
        'observation'  => 'Observation',
        'consultation' => 'Consultation',
        'followup'     => 'Follow-up Note',
        'referral'     => 'Referral Note',
        'other'        => 'General Note',
    ];

    $whereClause = 'n.case_id = ?';
    $bindings    = [$caseId];
    if ($search !== '') {
        $whereClause .= ' AND n.note_content LIKE ?';
        $bindings[]   = '%' . $search . '%';
    }

    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM gm_counselor_notes n WHERE {$whereClause}");
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT n.id, n.note_type, n.session_type, n.session_date, n.note_content,\n"
            . "       n.risk_level, n.is_confidential, n.observation_recommendation, n.created_at,\n"
            . "       CONCAT(u.first_name, ' ', u.last_name) AS counselor_name,\n"
            . "       (SELECT a2.scheduled_time FROM gm_appointments a2\n"
            . "        WHERE a2.case_id = n.case_id AND a2.scheduled_date = n.session_date\n"
            . "        ORDER BY a2.id LIMIT 1) AS apt_time,\n"
            . "       (SELECT at2.name FROM gm_appointments a2\n"
            . "        LEFT JOIN gm_appointment_types at2 ON a2.appointment_type_id = at2.id\n"
            . "        WHERE a2.case_id = n.case_id AND a2.scheduled_date = n.session_date\n"
            . "        ORDER BY a2.id LIMIT 1) AS apt_type_name\n"
            . "FROM gm_counselor_notes n\n"
            . "LEFT JOIN gm_users u ON n.counselor_id = u.id\n"
            . "WHERE {$whereClause}\n"
            . "ORDER BY n.session_date DESC, n.created_at DESC\n"
            . "LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($bindings);
        $rawNotes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

    $notes = array_map(static function (array $n) use ($noteTypeLabels): array {
        $raw = trim((string)($n['note_content'] ?? ''));
        // First line as title, capped at 60 chars
        $firstLine = explode("\n", $raw)[0];
        $n['note_title'] = mb_strlen($firstLine) > 60
            ? mb_substr($firstLine, 0, 57) . '...'
            : $firstLine;
        $n['note_preview'] = mb_strlen($raw) > 80
            ? mb_substr($raw, 0, 77) . '...'
            : $raw;
        $n['note_type_label'] = $noteTypeLabels[$n['note_type'] ?? ''] ?? ucfirst((string)($n['note_type'] ?? ''));
        try {
            $dt                   = new DateTimeImmutable((string)($n['session_date'] ?? $n['created_at'] ?? 'now'));
            $n['formatted_date']  = $dt->format('M d, Y');
            $n['formatted_time']  = '';
            if (!empty($n['apt_time'])) {
                $at = new DateTimeImmutable($n['session_date'] . ' ' . $n['apt_time']);
                $n['formatted_time'] = $at->format('g:i A');
            }
        } catch (\Exception $e) {
            $n['formatted_date'] = (string)($n['session_date'] ?? '');
            $n['formatted_time'] = '';
        }
        return $n;
    }, $rawNotes);

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    $from       = $total > 0 ? $offset + 1 : 0;
    $to         = min($offset + $perPage, $total);

    if (guidanceIsHtmx()) {
        header('Content-Type: text/html; charset=utf-8');
        echo guidanceRender('modules/guidance/partials/case-notes-tab.disyl', [
            'notes'       => $notes,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
            'from'        => $from,
            'to'          => $to,
            'search'      => $search,
            'case_id'     => $caseId,
            'base_url'    => '/admin/guidance',
        ]);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $notes], JSON_UNESCAPED_SLASHES);
}

function guidanceBuildCaseNoteDetailContext($db, int $caseId, int $noteId): ?array
{
    $nStmt = $db->prepare(
        "SELECT n.*,\n"
        . "       CONCAT(u.first_name, ' ', u.last_name) AS counselor_name,\n"
        . "       (SELECT a2.id FROM gm_appointments a2\n"
        . "        WHERE a2.case_id = n.case_id AND a2.scheduled_date = n.session_date\n"
        . "        ORDER BY a2.id LIMIT 1) AS apt_id,\n"
        . "       (SELECT a2.scheduled_time FROM gm_appointments a2\n"
        . "        WHERE a2.case_id = n.case_id AND a2.scheduled_date = n.session_date\n"
        . "        ORDER BY a2.id LIMIT 1) AS apt_time,\n"
        . "       (SELECT a2.duration_minutes FROM gm_appointments a2\n"
        . "        WHERE a2.case_id = n.case_id AND a2.scheduled_date = n.session_date\n"
        . "        ORDER BY a2.id LIMIT 1) AS apt_duration,\n"
        . "       (SELECT a2.purpose FROM gm_appointments a2\n"
        . "        WHERE a2.case_id = n.case_id AND a2.scheduled_date = n.session_date\n"
        . "        ORDER BY a2.id LIMIT 1) AS apt_purpose,\n"
        . "       (SELECT a2.location FROM gm_appointments a2\n"
        . "        WHERE a2.case_id = n.case_id AND a2.scheduled_date = n.session_date\n"
        . "        ORDER BY a2.id LIMIT 1) AS apt_location,\n"
        . "       (SELECT at2.name FROM gm_appointments a2\n"
        . "        LEFT JOIN gm_appointment_types at2 ON a2.appointment_type_id = at2.id\n"
        . "        WHERE a2.case_id = n.case_id AND a2.scheduled_date = n.session_date\n"
        . "        ORDER BY a2.id LIMIT 1) AS apt_type_name\n"
        . "FROM gm_counselor_notes n\n"
        . "LEFT JOIN gm_users u ON n.counselor_id = u.id\n"
        . "WHERE n.id = ? AND n.case_id = ? LIMIT 1"
    );
    $nStmt->execute([$noteId, $caseId]);
    $note = $nStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($note)) {
        return null;
    }

    $noteTypeLabels = [
        'session' => 'Progress Note',
        'phone' => 'Phone Note',
        'observation' => 'Observation',
        'consultation' => 'Consultation',
        'followup' => 'Follow-up Note',
        'referral' => 'Referral Note',
        'other' => 'General Note',
    ];
    $note['note_type_label'] = $noteTypeLabels[$note['note_type'] ?? ''] ?? ucfirst((string)($note['note_type'] ?? ''));

    $raw = trim((string)($note['note_content'] ?? ''));
    $firstLine = explode("\n", $raw)[0];
    $note['note_title'] = mb_strlen($firstLine) > 80 ? mb_substr($firstLine, 0, 77) . '...' : $firstLine;

    try {
        $dt = new DateTimeImmutable((string)($note['session_date'] ?? $note['created_at'] ?? 'now'));
        $note['formatted_date'] = $dt->format('M d, Y');
        $note['formatted_created'] = '';
        try {
            $ct = new DateTimeImmutable((string)($note['created_at'] ?? 'now'));
            $note['formatted_created'] = $ct->format('M d, Y \a\t g:i A');
        } catch (\Exception $e) {
            $note['formatted_created'] = '';
        }
        $note['formatted_apt_time'] = '';
        $note['formatted_apt_end'] = '';
        if (!empty($note['apt_time'])) {
            $at = new DateTimeImmutable($note['session_date'] . ' ' . $note['apt_time']);
            $note['formatted_apt_time'] = $at->format('g:i A');
            $dur = (int)($note['apt_duration'] ?? 0);
            if ($dur > 0) {
                $note['formatted_apt_end'] = $at->modify("+{$dur} minutes")->format('g:i A');
            }
        }
    } catch (\Exception $e) {
        $note['formatted_date'] = (string)($note['session_date'] ?? '');
        $note['formatted_created'] = '';
        $note['formatted_apt_time'] = '';
        $note['formatted_apt_end'] = '';
    }

    $attachments = [];
    try {
        $attStmt = $db->prepare(
            "SELECT a.id, a.file_name, a.file_type, a.file_size, a.uploaded_at,\n"
            . "       CONCAT(u2.first_name, ' ', u2.last_name) AS uploader_name\n"
            . "FROM gm_attachments a\n"
            . "LEFT JOIN gm_users u2 ON a.uploaded_by = u2.id\n"
            . "WHERE a.case_id = ? AND a.deleted_at IS NULL\n"
            . "ORDER BY a.uploaded_at DESC LIMIT 5"
        );
        $attStmt->execute([$caseId]);
        $rows = $attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $attachments = array_map(static function (array $row): array {
            $bytes = (int)($row['file_size'] ?? 0);
            if ($bytes <= 0) {
                $row['file_size_label'] = '';
            } elseif ($bytes < 1024) {
                $row['file_size_label'] = $bytes . ' B';
            } elseif ($bytes < 1048576) {
                $row['file_size_label'] = round($bytes / 1024) . ' KB';
            } else {
                $row['file_size_label'] = round($bytes / 1048576, 1) . ' MB';
            }
            return $row;
        }, $rows);
    } catch (\Exception $e) {
        $attachments = [];
    }

    return [
        'note' => $note,
        'attachments' => $attachments,
    ];
}

function apiGuidanceCaseNoteDetail(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);
    $noteId = (int)($params['noteId'] ?? 0);

    if ($caseId < 1 || $noteId < 1) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-gray-400">Not found.</div>';
        return;
    }

    $db = guidanceDb();
    $csStmt = $db->prepare("SELECT id, counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $csStmt->execute([$caseId]);
    $caseRow = $csStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($caseRow)) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-gray-400">Case not found.</div>';
        return;
    }
    if ($role === 'counselor' && (int)($caseRow['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo '<div class="p-6 text-sm text-red-400">Access denied.</div>';
        return;
    }

    $noteContext = guidanceBuildCaseNoteDetailContext($db, $caseId, $noteId);
    if (!is_array($noteContext)) {
        http_response_code(404);
        echo '<div class="p-6 text-sm text-gray-400">Note not found.</div>';
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/case-note-detail.disyl', [
        'note' => $noteContext['note'],
        'attachments' => $noteContext['attachments'],
        'case_id' => $caseId,
        'base_url' => '/admin/guidance',
        'panel_back_href' => guidanceInput('panel') ? '/admin/guidance/api/cases/' . $caseId . '/panel?pane=overview' : null,
        'add_note_href' => guidanceInput('panel') ? '/admin/guidance/api/cases/' . $caseId . '/panel?pane=create' : null,
    ]);
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
            . "    case_id, appointment_id, counselor_id, note_type, session_type, session_date, session_duration_minutes,\n"
            . "    note_content, intervention_used, student_response, risk_level, mood_assessment,\n"
            . "    action_taken, mse_appearance, mse_behavior, mse_speech, mse_emotions,\n"
            . "    mse_thinking, mse_cognition, mse_judgment, mse_reliability,\n"
            . "    case_predisposition, case_precipitating, case_perpetuating, case_protective,\n"
            . "    observation_recommendation, followup_required, followup_notes, is_confidential,\n"
            . "    sync_id, created_by, created_at, updated_at\n"
            . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $caseId,
            (($input['appointment_id'] ?? '') !== '' ? (int)$input['appointment_id'] : null),
            $userId,
            (string)($input['note_type'] ?? 'session'),
            guidanceNormalizeNoteSessionType((string)($input['session_type'] ?? 'walk-in')),
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

        $newNoteId = (int)$db->lastInsertId();

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
            'refreshCasePanel' => [
                'caseId' => $caseId,
                'pane' => 'view',
                'noteId' => $newNoteId ?? 0,
            ],
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
    $sourceAppointment = null;
    $casePrefill = [];

    $sourceAppointmentId = (int)guidanceInput('appointment_id', 0);
    if ($sourceAppointmentId > 0) {
        try {
            $sourceAppointment = guidanceResolveCaseSourceAppointment($db, $sourceAppointmentId, $role === 'counselor', $userId);
            $casePrefill = guidanceBuildCasePrefillFromAppointment($sourceAppointment);
        } catch (RuntimeException $e) {
            http_response_code(422);
            echo '<div class="p-4 text-red-600">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
            return;
        }
    }

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

    $studentStatusConfig = guidanceGetStudentStatusOptionsForForm($db, (string)($casePrefill['student_status'] ?? ''));
    if (empty($casePrefill['student_status']) && $studentStatusConfig['default'] !== '') {
        $casePrefill['student_status'] = $studentStatusConfig['default'];
    }
    $severityConfig = guidanceGetCaseSeverityOptionsForForm($db, (string)($casePrefill['severity'] ?? ''));
    if (empty($casePrefill['severity']) && $severityConfig['default'] !== '') {
        $casePrefill['severity'] = $severityConfig['default'];
    }

    echo guidanceRender('modules/guidance/modals/case-form.disyl', [
        'case' => $casePrefill,
        'today' => date('Y-m-d'),
        'is_admin' => $role !== 'counselor',
        'user_role' => $role,
        'user_id' => $userId,
        'counselors' => $counselors,
        'colleges' => $colleges,
        'student_statuses' => $studentStatusConfig['statuses'],
        'severity_levels' => $severityConfig['options'],
        'severity_default' => $severityConfig['default'],
        'case_categories_json' => '[]',
        'dynamic_fields_html' => guidanceRenderFormFields('case', $casePrefill, ['colleges' => $colleges]),
        'source_appointment' => $sourceAppointment ?? [],
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

    if (empty($case['student_first_name']) && empty($case['student_last_name'])) {
        $studentName = trim((string)($case['student_name'] ?? ''));
        if ($studentName !== '') {
            if (str_contains($studentName, ',')) {
                [$lastName, $firstName] = array_map('trim', explode(',', $studentName, 2));
            } else {
                $parts = preg_split('/\s+/', $studentName) ?: [];
                if (count($parts) > 1) {
                    $lastName = (string)array_pop($parts);
                    $firstName = trim(implode(' ', $parts));
                } else {
                    $firstName = $studentName;
                    $lastName = '';
                }
            }

            $case['student_first_name'] = $firstName ?? '';
            $case['student_last_name'] = $lastName ?? '';
        }
    }

    $gender = strtolower(trim((string)($case['gender'] ?? '')));
    $case['gender'] = match ($gender) {
        'male' => 'Male',
        'female' => 'Female',
        'other', 'prefer not to say' => 'Prefer not to say',
        default => (string)($case['gender'] ?? ''),
    };

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

    $studentStatusConfig = guidanceGetStudentStatusOptionsForForm($db, (string)($case['student_status'] ?? ''));
    $severityConfig = guidanceGetCaseSeverityOptionsForForm($db, (string)($case['severity'] ?? ''));

    echo guidanceRender('modules/guidance/modals/case-form.disyl', [
        'case' => $case,
        'today' => date('Y-m-d'),
        'is_admin' => $role !== 'counselor',
        'user_role' => $role,
        'user_id' => $userId,
        'counselors' => $counselors,
        'colleges' => $colleges,
        'student_statuses' => $studentStatusConfig['statuses'],
        'severity_levels' => $severityConfig['options'],
        'severity_default' => $severityConfig['default'],
        'case_categories_json' => (function() use ($case): string {
            $raw = $case['categories'] ?? null;
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            }
            $legacyCategory = trim((string)($case['category'] ?? ''));
            if ($legacyCategory !== '') {
                return json_encode([$legacyCategory], JSON_UNESCAPED_UNICODE);
            }
            return '[]';
        })(),
        'dynamic_fields_html' => guidanceRenderFormFields('case', $case, ['colleges' => $colleges]),
        'tinymce_assets' => $tinyMceAssets,
        'tinymce_config' => $tinyMceConfig,
    ]);
}

function pageGuidanceCalendar(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role = (string)($user['role'] ?? '');
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

    echo guidanceRender('modules/guidance/pages/calendar.disyl', array_merge(
        guidanceBasePageContext($user, 'Calendar', 'calendar'),
        [
            'counselors'   => $counselors,
            'current_month' => date('Y-m'),
        ]
    ));
}

function pageGuidanceAlerts(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $userId = (int)($user['id'] ?? 0);

    $stats = ['total' => 0, 'unread' => 0, 'today' => 0, 'this_week' => 0];
    try {
        $db = guidanceDb();
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS total,
             SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread,
             SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
             SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS this_week
             FROM gm_notifications WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats = [
            'total'     => (int)($row['total']     ?? 0),
            'unread'    => (int)($row['unread']    ?? 0),
            'today'     => (int)($row['today']     ?? 0),
            'this_week' => (int)($row['this_week'] ?? 0),
        ];
    } catch (Throwable $e) {
        app()->log('Alerts stats error: ' . $e->getMessage(), 'error');
    }

    echo guidanceRender('pages/alerts.disyl', guidanceBasePageContext($user, 'Alerts', 'alerts') + [
        'stats' => $stats,
    ]);
}

function guidanceNotificationMeta(array &$n): void
{
    $type = (string)($n['type'] ?? '');
    if (str_starts_with($type, 'appointment')) {
        $n['type_label']   = 'Appointment';
        $n['type_badge']   = 'bg-blue-50 text-blue-700';
        $n['type_icon']    = 'fa-calendar-alt';
        $n['type_icon_bg'] = 'bg-blue-50 text-blue-500';
    } elseif (str_starts_with($type, 'case') || str_starts_with($type, 'student') || $type === 'urgent') {
        $n['type_label']   = 'Student';
        $n['type_badge']   = 'bg-purple-50 text-purple-700';
        $n['type_icon']    = 'fa-user-graduate';
        $n['type_icon_bg'] = 'bg-purple-50 text-purple-500';
    } elseif (str_starts_with($type, 'session') || str_starts_with($type, 'followup')) {
        $n['type_label']   = 'Session';
        $n['type_badge']   = 'bg-amber-50 text-amber-700';
        $n['type_icon']    = 'fa-clock';
        $n['type_icon_bg'] = 'bg-amber-50 text-amber-600';
    } elseif (str_starts_with($type, 'system')) {
        $n['type_label']   = 'System';
        $n['type_badge']   = 'bg-gray-100 text-gray-600';
        $n['type_icon']    = 'fa-cog';
        $n['type_icon_bg'] = 'bg-gray-100 text-gray-500';
    } else {
        $n['type_label']   = ucfirst(str_replace('_', ' ', $type)) ?: 'Notice';
        $n['type_badge']   = 'bg-gray-100 text-gray-600';
        $n['type_icon']    = 'fa-bell';
        $n['type_icon_bg'] = 'bg-gray-100 text-gray-500';
    }

    $ts   = strtotime((string)($n['created_at'] ?? ''));
    $diff = $ts ? (time() - $ts) : 0;
    if ($diff < 60)          $n['time_ago'] = 'just now';
    elseif ($diff < 3600)    $n['time_ago'] = floor($diff / 60) . ' min ago';
    elseif ($diff < 86400)   $n['time_ago'] = floor($diff / 3600) . ' hours ago';
    elseif ($diff < 172800)  $n['time_ago'] = date('M j \a\t g:i A', $ts);
    else                     $n['time_ago'] = date('M j, Y', $ts);
}

function apiGuidanceAlertsList(): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $userId = (int)($user['id'] ?? 0);
    $input  = guidanceInput();

    $tab    = (string)($input['tab']  ?? 'all');
    $sort   = (string)($input['sort'] ?? 'newest');
    $page   = max(1, (int)($input['page'] ?? 1));
    $perPage = 10;
    $offset  = ($page - 1) * $perPage;

    $where  = ['user_id = ?'];
    $params = [$userId];

    // All appended strings are static — no user input reaches $where directly
    switch ($tab) {
        case 'unread':        $where[] = 'is_read = 0';                                                   break;
        case 'appointments':  $where[] = "type LIKE 'appointment%'";                                      break;
        case 'system':        $where[] = "type LIKE 'system%'";                                           break;
        case 'students':      $where[] = "(type LIKE 'case%' OR type LIKE 'student%' OR type = 'urgent')"; break;
        case 'sessions':      $where[] = "(type LIKE 'session%' OR type LIKE 'followup%')";               break;
    }

    $orderBy  = $sort === 'oldest' ? 'created_at ASC' : 'created_at DESC';
    $whereSql = implode(' AND ', $where);

    $notifications = [];
    $total = 0;
    try {
        $db = guidanceDb();

        $countStmt = $db->prepare("SELECT COUNT(*) FROM gm_notifications WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT id, type, title, message, link, is_read, created_at
             FROM gm_notifications
             WHERE {$whereSql}
             ORDER BY {$orderBy}
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        app()->log('Alerts list error: ' . $e->getMessage(), 'error');
    }

    foreach ($notifications as &$n) {
        guidanceNotificationMeta($n);
    }
    unset($n);

    $totalPages = max(1, (int)ceil($total / $perPage));
    $from = $total > 0 ? $offset + 1 : 0;
    $to   = min($total, $offset + $perPage);

    // Build page-link window: first, last, ±1 around current, ellipsis gaps
    $pageLinks = [];
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1) {
            $pageLinks[] = ['num' => $i, 'active' => $i === $page, 'ellipsis' => false];
        } elseif (count($pageLinks) > 0 && !$pageLinks[count($pageLinks) - 1]['ellipsis']) {
            $pageLinks[] = ['num' => null, 'active' => false, 'ellipsis' => true];
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('partials/alerts-list.disyl', [
        'notifications' => $notifications,
        'total'         => $total,
        'total_pages'   => $totalPages,
        'current_page'  => $page,
        'prev_page'     => max(1, $page - 1),
        'next_page'     => min($totalPages, $page + 1),
        'has_prev'      => $page > 1,
        'has_next'      => $page < $totalPages,
        'from'          => $from,
        'to'            => $to,
        'page_links'    => $pageLinks,
        'base_url'      => '/admin/guidance',
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

    // Session Records mode: use sr_* params and restrict to historical statuses
    $isSessionRecords = !empty($input['session_records']);
    if ($isSessionRecords) {
        // Remap sr_* filter params (sr_counselor → counselor_id is a name mismatch — handle explicitly)
        foreach (['search','status','purpose','from','to'] as $k) {
            if (!isset($input[$k]) && isset($input['sr_' . $k])) {
                $input[$k] = $input['sr_' . $k];
            }
        }
        if (!isset($input['counselor_id']) && !empty($input['sr_counselor'])) {
            $input['counselor_id'] = $input['sr_counselor'];
        }
    }

    $db = guidanceDb();

    try {
        $where = ['1=1'];
        $params = [];

        // In session records mode, restrict to documented historical outcomes only.
        if ($isSessionRecords && empty($input['status'])) {
            $where[] = "a.status IN ('completed','no_show','cancelled')";
        } elseif ($isSessionRecords && !empty($input['status'])) {
            $where[] = 'a.status = ?';
            $params[] = (string)$input['status'];
        }

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
        // Status filter — only add if not already handled above by session_records mode
        if (!$isSessionRecords && !empty($input['status'])) {
            $where[] = 'a.status = ?';
            $params[] = (string)$input['status'];
        }
        if ($isSessionRecords && !empty($input['purpose'])) {
            $where[] = 'a.appointment_type_id = ?';
            $params[] = (int)$input['purpose'];
        }
        if (!empty($input['search'])) {
            $where[] = '(COALESCE(a.student_name, c.student_name) LIKE ? OR c.case_number LIKE ?)';
            $search = '%' . (string)$input['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $whereClause = implode(' AND ', $where);

        // Session records pagination
        $srPage    = $isSessionRecords ? max(1, (int)($input['sr_page'] ?? 1)) : 1;
        $srPerPage = 10;
        $srOffset  = ($srPage - 1) * $srPerPage;
        $srTotal   = 0;
        if ($isSessionRecords) {
            $countStmt = $db->prepare("SELECT COUNT(*) FROM gm_appointments a LEFT JOIN gm_cases c ON a.case_id = c.id LEFT JOIN gm_users u ON a.counselor_id = u.id LEFT JOIN gm_appointment_types t ON a.appointment_type_id = t.id WHERE {$whereClause}");
            $countStmt->execute($params);
            $srTotal = (int)$countStmt->fetchColumn();
        }

        $limitClause = $isSessionRecords ? " LIMIT {$srPerPage} OFFSET {$srOffset}" : '';

        $stmt = $db->prepare(
            "SELECT a.*, LOWER(TRIM(a.status)) AS status_key,\n"
            . "       c.case_number, COALESCE(a.student_name, c.student_name) AS student_name,\n"
            . "       c.student_grade, c.student_status AS case_student_status,\n"
            . "       u.first_name AS counselor_first, u.last_name AS counselor_last,\n"
            . "       COALESCE(t.name, a.appointment_type) AS type_name,\n"
            . "       col.code AS college_code\n"
            . "FROM gm_appointments a\n"
            . "LEFT JOIN gm_cases c ON a.case_id = c.id\n"
            . "LEFT JOIN gm_colleges col ON c.college_id = col.id\n"
            . "LEFT JOIN gm_users u ON a.counselor_id = u.id\n"
            . "LEFT JOIN gm_appointment_types t ON a.appointment_type_id = t.id\n"
            . "WHERE {$whereClause}\n"
            . "ORDER BY a.scheduled_date DESC, a.scheduled_time DESC{$limitClause}"
        );
        $stmt->execute($params);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        foreach ($appointments as &$appt) {
            $appt['status_key'] = strtolower(trim((string)($appt['status_key'] ?? ($appt['status'] ?? ''))));
            $appt['counselor_name'] = trim((string)($appt['counselor_first'] ?? '') . ' ' . (string)($appt['counselor_last'] ?? ''));
            $appt['can_mark_outcome'] = guidanceAppointmentCanMarkOutcome(
                (string)($appt['status_key'] ?? ''),
                (string)($appt['scheduled_date'] ?? ''),
                (string)($appt['scheduled_time'] ?? '')
            );
            $appt['outcome_locked_reason'] = 'Available for past appointments once the scheduled date and time has been reached.';

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
            $template = $isSessionRecords
                ? 'modules/guidance/partials/session-records-list.disyl'
                : 'modules/guidance/partials/appointments-list.disyl';
            echo guidanceRender($template, [
                'appointments' => $appointments,
                'rows' => $rows,
                'stats' => $stats,
                'total' => $isSessionRecords ? $srTotal : count($appointments),
                'sr_page' => $srPage,
                'sr_per_page' => $srPerPage,
                'sr_total' => $srTotal,
                'sr_from' => $srTotal > 0 ? ($srPage - 1) * $srPerPage + 1 : 0,
                'sr_to' => min($srPage * $srPerPage, $srTotal),
                'sr_page_count' => $srTotal > 0 ? (int)ceil($srTotal / $srPerPage) : 1,
                'sr_pages' => (function(int $cur, int $last): array {
                    if ($last <= 7) { return range(1, $last); }
                    $pages = [1];
                    if ($cur > 3) { $pages[] = '...'; }
                    for ($i = max(2, $cur - 1); $i <= min($last - 1, $cur + 1); $i++) { $pages[] = $i; }
                    if ($cur < $last - 2) { $pages[] = '...'; }
                    $pages[] = $last;
                    return $pages;
                })($srPage, $srTotal > 0 ? (int)ceil($srTotal / $srPerPage) : 1),
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

    $colleges = [];
    try {
        $cStmt = guidanceDb()->prepare("SELECT id, code, name FROM gm_colleges ORDER BY name ASC");
        $cStmt->execute();
        $colleges = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $colleges = [];
    }

    echo guidanceRender('modules/guidance/pages/reports.disyl', array_merge(
        guidanceBasePageContext($user, 'Reports', 'reports'),
        ['counselors' => $counselors, 'colleges' => $colleges]
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

function guidanceReportsSummaryData(array $user, string $startDate = '', string $endDate = '', int $collegeId = 0, string $yearLevel = '', string $studentStatus = '', int $counselorId = 0): array
{
    $db = guidanceDb();
    $userId = (int)($user['id'] ?? 0);
    $role = (string)($user['role'] ?? '');
    $isCounselor = $role === 'counselor' && $userId > 0;
    // If logged in as counselor, always scope to self regardless of filter
    if ($isCounselor) {
        $counselorId = $userId;
    }

    $startDate = guidanceReportsNormalizedDate($startDate);
    $endDate = guidanceReportsNormalizedDate($endDate);
    if ($startDate !== '' && $endDate !== '' && strcmp($startDate, $endDate) > 0) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }
    $hasDateFilter = $startDate !== '' && $endDate !== '';

    $caseFilterSql = '';
    $caseParams = [];
    if ($counselorId > 0) {
        $caseFilterSql .= ' AND c.counselor_id = ?';
        $caseParams[] = $counselorId;
    }
    if ($hasDateFilter) {
        $caseFilterSql .= ' AND c.created_at BETWEEN ? AND ?';
        $caseParams[] = $startDate . ' 00:00:00';
        $caseParams[] = $endDate . ' 23:59:59';
    }
    if ($collegeId > 0) {
        $caseFilterSql .= ' AND c.college_id = ?';
        $caseParams[] = $collegeId;
    }
    if ($yearLevel !== '') {
        $caseFilterSql .= ' AND c.student_grade = ?';
        $caseParams[] = $yearLevel;
    }
    if ($studentStatus !== '') {
        $caseFilterSql .= ' AND c.student_status = ?';
        $caseParams[] = $studentStatus;
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
    if ($counselorId > 0) {
        $apptFilterSql .= ' AND a.counselor_id = ?';
        $apptParams[] = $counselorId;
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
        . ($counselorId > 0 ? ' AND a.counselor_id = ?' : '')
    );
    $upcomingStmt->execute($counselorId > 0 ? [$counselorId] : []);
    $upcomingAppointments = (int)($upcomingStmt->fetchColumn() ?: 0);

    $notesFilterSql = '';
    $notesParams = [];
    if ($counselorId > 0) {
        $notesFilterSql .= ' AND n.counselor_id = ?';
        $notesParams[] = $counselorId;
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
    if ($counselorId > 0) {
        $trendFilterSql .= ' AND c.counselor_id = ?';
        $trendParams[] = $counselorId;
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

    // ── New overview data ────────────────────────────────────────────────

    // Probationary student count
    $probStmt = $db->prepare(
        "SELECT COUNT(*) FROM gm_cases c WHERE c.deleted_at IS NULL AND c.student_status = 'probationary'" . $caseFilterSql
    );
    $probStmt->execute($caseParams);
    $probationaryCount = (int)($probStmt->fetchColumn() ?: 0);

    // New cases in last 7 days (delta indicator)
    $deltaQ = "SELECT COUNT(*) FROM gm_cases c WHERE c.deleted_at IS NULL AND c.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
        . ($counselorId > 0 ? ' AND c.counselor_id = ?' : '');
    $deltaStmt = $db->prepare($deltaQ);
    $deltaStmt->execute($counselorId > 0 ? [$counselorId] : []);
    $deltaLastWeek = (int)($deltaStmt->fetchColumn() ?: 0);

    // Sessions by appointment status (for Sessions by Status donut)
    $sessStatusStmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END)                     AS completed,
            SUM(CASE WHEN a.status = 'no_show'   THEN 1 ELSE 0 END)                     AS no_show,
            SUM(CASE WHEN a.status IN ('cancelled','rejected') THEN 1 ELSE 0 END)       AS cancelled,
            SUM(CASE WHEN a.status IN ('scheduled','confirmed','in_progress') THEN 1 ELSE 0 END) AS in_progress,
            COUNT(*) AS total
         FROM gm_appointments a WHERE 1=1" . $apptFilterSql
    );
    $sessStatusStmt->execute($apptParams);
    $sessStatusRow = $sessStatusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $sessTotal = (int)($sessStatusRow['total'] ?? 0);

    // Weekly session trend (last 5 weeks, for Sessions Over Time line chart)
    $weeklyParams = $counselorId > 0 ? [$counselorId] : [];
    $weeklyStmt   = $db->prepare(
        "SELECT YEARWEEK(a.scheduled_date, 1) AS wk,
                MIN(a.scheduled_date) AS week_start,
                COUNT(*) AS cnt
         FROM gm_appointments a
         WHERE a.scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 5 WEEK)"
        . ($counselorId > 0 ? ' AND a.counselor_id = ?' : '') .
        " GROUP BY wk ORDER BY wk ASC"
    );
    $weeklyStmt->execute($weeklyParams);
    $weeklyRows  = $weeklyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $weeklyTrend = [];
    foreach ($weeklyRows as $wr) {
        $ws            = (string)($wr['week_start'] ?? '');
        $weeklyTrend[] = [
            'label' => $ws !== '' ? date('M j', strtotime($ws)) . '–' . date('M j', strtotime($ws . ' +6 days')) : '',
            'count' => (int)($wr['cnt'] ?? 0),
        ];
    }

    // Top 5 students by session count (respects all active filters)
    $topStudentsParams = $counselorId > 0 ? [$counselorId] : [];
    $topStmt           = $db->prepare(
        "SELECT c.id, c.student_name, c.case_number, c.student_grade, c.severity, c.student_status,
                COALESCE(col.code,'') AS college_code,
                COUNT(n.id) AS session_count,
                MAX(n.session_date) AS last_session
         FROM gm_cases c
         LEFT JOIN gm_counselor_notes n ON n.case_id = c.id
         LEFT JOIN gm_colleges col ON c.college_id = col.id
         WHERE c.deleted_at IS NULL"
        . ($counselorId > 0 ? ' AND c.counselor_id = ?' : '') .
        " GROUP BY c.id, c.student_name, c.case_number, c.student_grade, c.severity, c.student_status, col.code
          ORDER BY session_count DESC LIMIT 5"
    );
    $topStmt->execute($topStudentsParams);
    $topStudentsRaw = $topStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $topStudents    = [];
    foreach ($topStudentsRaw as $rank => $ts) {
        $ts['rank']    = $rank + 1;
        $topStudents[] = $ts;
    }

    // Low/moderate/high risk percentages
    $lowRisk      = (int)($bySeverity['low'] ?? 0);
    $moderateRisk = (int)($bySeverity['medium'] ?? 0);
    $highRisk     = (int)($bySeverity['high'] ?? 0) + (int)($bySeverity['critical'] ?? 0);

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
        'students' => [
            'total'             => $totalCases,
            'low_risk'          => $lowRisk,
            'low_risk_pct'      => $totalCases > 0 ? round(($lowRisk / $totalCases) * 100, 1) : 0,
            'moderate_risk'     => $moderateRisk,
            'moderate_risk_pct' => $totalCases > 0 ? round(($moderateRisk / $totalCases) * 100, 1) : 0,
            'high_risk'         => $highRisk,
            'high_risk_pct'     => $totalCases > 0 ? round(($highRisk / $totalCases) * 100, 1) : 0,
            'probationary'      => $probationaryCount,
            'probationary_pct'  => $totalCases > 0 ? round(($probationaryCount / $totalCases) * 100, 1) : 0,
            'delta_last_week'   => $deltaLastWeek,
        ],
        'sessions_by_status' => [
            'completed'  => (int)($sessStatusRow['completed']  ?? 0),
            'no_show'    => (int)($sessStatusRow['no_show']    ?? 0),
            'cancelled'  => (int)($sessStatusRow['cancelled']  ?? 0),
            'in_progress'=> (int)($sessStatusRow['in_progress']?? 0),
            'total'      => $sessTotal,
        ],
        'weekly_trend'      => $weeklyTrend,
        'trend_labels_json' => json_encode(array_column($weeklyTrend, 'label'), JSON_UNESCAPED_UNICODE),
        'trend_counts_json' => json_encode(array_column($weeklyTrend, 'count')),
        'top_students'      => $topStudents,
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
            (string)guidanceInput('end_date', ''),
            (int)guidanceInput('college_id', 0),
            trim((string)guidanceInput('year_level', '')),
            trim((string)guidanceInput('student_status', '')),
            (int)guidanceInput('counselor_id', 0)
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
            (string)guidanceInput('end_date', ''),
            (int)guidanceInput('college_id', 0),
            trim((string)guidanceInput('year_level', '')),
            trim((string)guidanceInput('student_status', '')),
            (int)guidanceInput('counselor_id', 0)
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
    }
}

// ── Reports Tab: Student Reports ──────────────────────────────────────────────
function apiGuidanceReportStudents(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $db   = guidanceDb();

    $startDate    = trim((string)guidanceInput('start_date', ''));
    $endDate      = trim((string)guidanceInput('end_date', ''));
    $collegeId    = (int)guidanceInput('college_id', 0);
    $yearLevel    = trim((string)guidanceInput('year_level', ''));
    $studentStatus = trim((string)guidanceInput('student_status', ''));
    $counselorId  = (int)guidanceInput('counselor_id', 0);
    $role         = (string)($user['role'] ?? '');
    $userId       = (int)($user['id'] ?? 0);
    if ($role === 'counselor' && $userId > 0) {
        $counselorId = $userId;
    }

    $where  = ['c.deleted_at IS NULL'];
    $params = [];
    if ($counselorId > 0)   { $where[] = 'c.counselor_id = ?';    $params[] = $counselorId; }
    if ($startDate !== '' && $endDate !== '') {
        $where[] = 'c.created_at BETWEEN ? AND ?';
        $params[] = $startDate . ' 00:00:00';
        $params[] = $endDate . ' 23:59:59';
    }
    if ($collegeId > 0)     { $where[] = 'c.college_id = ?';      $params[] = $collegeId; }
    if ($yearLevel !== '')  { $where[] = 'c.student_grade = ?';    $params[] = $yearLevel; }
    if ($studentStatus !== '') { $where[] = 'c.student_status = ?'; $params[] = $studentStatus; }

    $sql = "SELECT c.id, c.case_number, c.student_name, c.student_grade, c.student_status,
                   c.severity, c.status,
                   COALESCE(col.code,'—') AS college_code,
                   CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS counselor_name,
                   COUNT(n.id) AS session_count,
                   MAX(n.session_date) AS last_session,
                   c.created_at
            FROM gm_cases c
            LEFT JOIN gm_colleges col ON c.college_id = col.id
            LEFT JOIN gm_users u ON c.counselor_id = u.id
            LEFT JOIN gm_counselor_notes n ON n.case_id = c.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY c.id, c.case_number, c.student_name, c.student_grade, c.student_status,
                     c.severity, c.status, col.code, u.first_name, u.last_name, c.created_at
            ORDER BY c.created_at DESC LIMIT 200";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/reports-students.disyl', [
        'students'       => $students,
        'has_date_filter' => $startDate !== '' && $endDate !== '',
        'start_date'     => $startDate,
        'end_date'       => $endDate,
    ]);
}

// ── Reports Tab: Session Reports ──────────────────────────────────────────────
function apiGuidanceReportSessions(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $db   = guidanceDb();

    $startDate   = trim((string)guidanceInput('start_date', ''));
    $endDate     = trim((string)guidanceInput('end_date', ''));
    $collegeId   = (int)guidanceInput('college_id', 0);
    $yearLevel   = trim((string)guidanceInput('year_level', ''));
    $counselorId = (int)guidanceInput('counselor_id', 0);
    $role        = (string)($user['role'] ?? '');
    $userId      = (int)($user['id'] ?? 0);
    if ($role === 'counselor' && $userId > 0) {
        $counselorId = $userId;
    }

    $where  = ['1=1'];
    $params = [];
    if ($counselorId > 0) { $where[] = 'n.counselor_id = ?'; $params[] = $counselorId; }
    if ($startDate !== '' && $endDate !== '') {
        $where[] = 'n.session_date BETWEEN ? AND ?';
        $params[] = $startDate;
        $params[] = $endDate;
    }
    if ($collegeId > 0)    { $where[] = 'c.college_id = ?';  $params[] = $collegeId; }
    if ($yearLevel !== '') { $where[] = 'c.student_grade = ?'; $params[] = $yearLevel; }

    $sql = "SELECT n.id, n.session_date, n.session_type, n.note_type,
                   n.session_duration_minutes, n.risk_level,
                   c.student_name, c.case_number, c.student_grade, c.student_status,
                   COALESCE(col.code,'—') AS college_code,
                   CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS counselor_name
            FROM gm_counselor_notes n
            LEFT JOIN gm_cases c ON n.case_id = c.id
            LEFT JOIN gm_colleges col ON c.college_id = col.id
            LEFT JOIN gm_users u ON n.counselor_id = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY n.session_date DESC LIMIT 200";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/reports-sessions.disyl', [
        'sessions'       => $sessions,
        'has_date_filter' => $startDate !== '' && $endDate !== '',
        'start_date'     => $startDate,
        'end_date'       => $endDate,
    ]);
}

// ── Reports Tab: Appointment Reports ─────────────────────────────────────────
function apiGuidanceReportAppointments(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $db   = guidanceDb();

    $startDate   = trim((string)guidanceInput('start_date', ''));
    $endDate     = trim((string)guidanceInput('end_date', ''));
    $collegeId   = (int)guidanceInput('college_id', 0);
    $counselorId = (int)guidanceInput('counselor_id', 0);
    $role        = (string)($user['role'] ?? '');
    $userId      = (int)($user['id'] ?? 0);
    if ($role === 'counselor' && $userId > 0) {
        $counselorId = $userId;
    }

    $where  = ['1=1'];
    $params = [];
    if ($counselorId > 0) { $where[] = 'a.counselor_id = ?'; $params[] = $counselorId; }
    if ($startDate !== '' && $endDate !== '') {
        $where[] = 'a.scheduled_date BETWEEN ? AND ?';
        $params[] = $startDate;
        $params[] = $endDate;
    }
    if ($collegeId > 0) { $where[] = 'c.college_id = ?'; $params[] = $collegeId; }

    $sql = "SELECT a.id, a.scheduled_date, a.scheduled_time, a.status,
                   COALESCE(a.student_name, c.student_name, '—') AS student_name,
                   c.case_number, c.student_grade,
                   COALESCE(col.code,'—') AS college_code,
                   CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS counselor_name
            FROM gm_appointments a
            LEFT JOIN gm_cases c ON a.case_id = c.id
            LEFT JOIN gm_colleges col ON c.college_id = col.id
            LEFT JOIN gm_users u ON a.counselor_id = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.scheduled_date DESC, a.scheduled_time DESC LIMIT 200";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Status summary counts
    $counts = ['completed' => 0, 'scheduled' => 0, 'confirmed' => 0,
               'no_show' => 0, 'cancelled' => 0];
    foreach ($appointments as $a) {
        $s = (string)($a['status'] ?? '');
        if (isset($counts[$s])) {
            $counts[$s]++;
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/reports-appointments.disyl', [
        'appointments'   => $appointments,
        'counts'         => $counts,
        'has_date_filter' => $startDate !== '' && $endDate !== '',
        'start_date'     => $startDate,
        'end_date'       => $endDate,
    ]);
}

// ── Reports Tab: Risk & Status Reports ───────────────────────────────────────
function apiGuidanceReportRisk(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $db   = guidanceDb();

    $startDate    = trim((string)guidanceInput('start_date', ''));
    $endDate      = trim((string)guidanceInput('end_date', ''));
    $collegeId    = (int)guidanceInput('college_id', 0);
    $counselorId  = (int)guidanceInput('counselor_id', 0);
    $role         = (string)($user['role'] ?? '');
    $userId       = (int)($user['id'] ?? 0);
    if ($role === 'counselor' && $userId > 0) {
        $counselorId = $userId;
    }

    $caseWhere = ['c.deleted_at IS NULL'];
    $caseParams = [];
    if ($counselorId > 0) { $caseWhere[] = 'c.counselor_id = ?'; $caseParams[] = $counselorId; }
    if ($startDate !== '' && $endDate !== '') {
        $caseWhere[] = 'c.created_at BETWEEN ? AND ?';
        $caseParams[] = $startDate . ' 00:00:00';
        $caseParams[] = $endDate . ' 23:59:59';
    }
    if ($collegeId > 0) { $caseWhere[] = 'c.college_id = ?'; $caseParams[] = $collegeId; }

    // Status breakdown
    $stmt = $db->prepare("SELECT c.student_status, COUNT(*) AS cnt FROM gm_cases c WHERE "
        . implode(' AND ', $caseWhere) . " GROUP BY c.student_status ORDER BY cnt DESC");
    $stmt->execute($caseParams);
    $byStatus = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Severity breakdown
    $stmt = $db->prepare("SELECT c.severity, COUNT(*) AS cnt FROM gm_cases c WHERE "
        . implode(' AND ', $caseWhere) . " GROUP BY c.severity ORDER BY cnt DESC");
    $stmt->execute($caseParams);
    $bySeverity = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Risk level from notes
    $noteWhere = ["n.risk_level IS NOT NULL AND n.risk_level != '' AND n.risk_level != 'none'"];
    $noteParams = [];
    if ($counselorId > 0) { $noteWhere[] = 'n.counselor_id = ?'; $noteParams[] = $counselorId; }
    if ($startDate !== '' && $endDate !== '') {
        $noteWhere[] = 'n.session_date BETWEEN ? AND ?';
        $noteParams[] = $startDate;
        $noteParams[] = $endDate;
    }
    $stmt = $db->prepare("SELECT n.risk_level, COUNT(*) AS cnt FROM gm_counselor_notes n WHERE "
        . implode(' AND ', $noteWhere) . " GROUP BY n.risk_level ORDER BY cnt DESC");
    $stmt->execute($noteParams);
    $byRisk = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // High-risk students (severity high/critical + active)
    $highWhere = array_merge($caseWhere, ["c.severity IN ('high','critical')", "c.status NOT IN ('closed','archived')"]);
    $stmt = $db->prepare(
        "SELECT c.id, c.case_number, c.student_name, c.student_grade, c.severity, c.student_status,
                COALESCE(col.code,'—') AS college_code,
                CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS counselor_name
         FROM gm_cases c
         LEFT JOIN gm_colleges col ON c.college_id = col.id
         LEFT JOIN gm_users u ON c.counselor_id = u.id
         WHERE " . implode(' AND ', $highWhere) . " ORDER BY c.severity DESC, c.created_at DESC LIMIT 50"
    );
    $stmt->execute($caseParams);
    $highRiskStudents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/reports-risk.disyl', [
        'by_status'         => $byStatus,
        'by_severity'       => $bySeverity,
        'by_risk'           => $byRisk,
        'high_risk_students' => $highRiskStudents,
        'has_date_filter'   => $startDate !== '' && $endDate !== '',
        'start_date'        => $startDate,
        'end_date'          => $endDate,
    ]);
}

// ── Reports Tab: Exported Reports ─────────────────────────────────────────────
function apiGuidanceReportExported(): void
{
    $user    = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $ctx     = guidanceBasePageContext($user, 'Reports', 'reports');
    $baseUrl = $ctx['base_url'] ?? '';

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/reports-exported.disyl', [
        'base_url' => $baseUrl,
    ]);
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
    $db   = guidanceDb();

    $colleges = [];
    try {
        $stmt = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name");
        $colleges = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $colleges = [];
    }

    $stats         = ['total' => 0, 'active' => 0, 'students' => 0, 'pending' => 0];
    $academicYears = [];
    try {
        $r = $db->query("SELECT COUNT(*) AS total, SUM(is_active) AS active FROM gm_trackers");
        if ($r) {
            $agg = $r->fetch(PDO::FETCH_ASSOC);
            $stats['total']  = (int)($agg['total'] ?? 0);
            $stats['active'] = (int)($agg['active'] ?? 0);
        }
        $r2 = $db->query("SELECT COUNT(*) FROM gm_tracker_students");
        $stats['students'] = (int)($r2 ? $r2->fetchColumn() : 0);
        $r3 = $db->query("SELECT COUNT(*) FROM gm_tracker_submissions WHERE status = 'pending'");
        $stats['pending'] = (int)($r3 ? $r3->fetchColumn() : 0);
        $r4 = $db->query("SELECT DISTINCT academic_year FROM gm_trackers WHERE academic_year IS NOT NULL ORDER BY academic_year DESC");
        $academicYears = $r4 ? $r4->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Throwable $e) {
        // leave defaults
    }

    echo guidanceRender('modules/guidance/pages/trackers.disyl', guidanceBasePageContext($user, 'Student Tracker', 'trackers') + [
        'colleges'       => $colleges,
        'stats'          => $stats,
        'academic_years' => $academicYears,
    ]);
}

function apiGuidanceTrackers(): void
{
    guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $db = guidanceDb();

    $search  = trim((string)($_GET['search'] ?? ''));
    $status  = trim((string)($_GET['status'] ?? ''));
    $acYear  = trim((string)($_GET['academic_year'] ?? ''));
    $sort    = trim((string)($_GET['sort'] ?? 'newest'));
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 10;

    $cacheKey = 'guidance_trackers_' . md5($search . '|' . $status . '|' . $acYear . '|' . $sort . '|' . $page);
    $cache    = app()->cache();
    $cached   = $cache->get('guidance', $cacheKey);
    if ($cached !== null) {
        header('Content-Type: text/html; charset=utf-8');
        echo $cached['html'];
        return;
    }

    $where  = [];
    $params = [];
    if ($search !== '') {
        $where[]  = "(t.name LIKE ? OR t.description LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    if ($status === 'active') {
        $where[] = "t.is_active = 1";
    } elseif ($status === 'inactive') {
        $where[] = "t.is_active = 0";
    }
    if ($acYear !== '') {
        $where[]  = "t.academic_year = ?";
        $params[] = $acYear;
    }
    $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $orderMap = [
        'newest'    => 't.updated_at DESC',
        'oldest'    => 't.updated_at ASC',
        'name_asc'  => 't.name ASC',
        'name_desc' => 't.name DESC',
    ];
    $orderBy = $orderMap[$sort] ?? 't.updated_at DESC';

    $rows       = [];
    $total      = 0;
    $totalPages = 1;
    try {
        $cStmt = $db->prepare("SELECT COUNT(*) FROM gm_trackers t $whereClause");
        $cStmt->execute($params);
        $total      = (int)$cStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        $sql = "SELECT t.*, c.name AS college_name,
            COALESCE((SELECT COUNT(*) FROM gm_tracker_students s WHERE s.tracker_id = t.id), 0) AS student_count,
            COALESCE((SELECT COUNT(*) FROM gm_tracker_items i WHERE i.tracker_id = t.id), 0) AS item_count,
            COALESCE((SELECT COUNT(*) FROM gm_tracker_submissions sub
                      JOIN gm_tracker_students ts ON sub.tracker_student_id = ts.id
                      WHERE ts.tracker_id = t.id AND sub.status = 'verified'), 0) AS submitted_count,
            COALESCE((SELECT COUNT(*) FROM gm_tracker_submissions sub
                      JOIN gm_tracker_students ts ON sub.tracker_student_id = ts.id
                      WHERE ts.tracker_id = t.id AND sub.status = 'pending'), 0) AS pending_count,
            COALESCE((SELECT COUNT(*) FROM gm_tracker_submissions sub
                      JOIN gm_tracker_students ts ON sub.tracker_student_id = ts.id
                      WHERE ts.tracker_id = t.id AND sub.status = 'submitted'), 0) AS in_review_count
            FROM gm_trackers t
            LEFT JOIN gm_colleges c ON t.college_id = c.id
            $whereClause
            ORDER BY $orderBy
            LIMIT $perPage OFFSET $offset";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // leave defaults
    }

    // Compute display fields per row
    $iconBgs     = ['bg-teal-500','bg-purple-500','bg-orange-500','bg-blue-500','bg-indigo-500','bg-rose-500'];
    $iconClasses = ['fa-file-alt','fa-user-graduate','fa-graduation-cap','fa-shield-alt','fa-exchange-alt','fa-clipboard-list'];
    foreach ($rows as &$row) {
        $sc  = (int)$row['student_count'];
        $ic  = (int)$row['item_count'];
        $sub = (int)$row['submitted_count'];   // verified
        $rev = (int)$row['in_review_count'];   // submitted (awaiting review)
        $pen = (int)$row['pending_count'];
        $totalSlots = max(1, $sc * max(1, $ic));
        $done = $sub + $rev;
        $row['progress_pct']  = $ic > 0 ? (int)round($done * 100 / $totalSlots) : 0;
        $row['progress_done'] = $done;
        $row['progress_total']= $sc;
        $idx = (int)$row['id'] % 6;
        $row['icon_bg']    = $iconBgs[$idx];
        $row['icon_class'] = $iconClasses[$idx];
        $row['updated_at_fmt'] = !empty($row['updated_at'])
            ? date('M j, Y g:i A', strtotime((string)$row['updated_at'])) : '—';
    }
    unset($row);

    // Pagination
    $offset    = ($page - 1) * $perPage;
    $from      = $total > 0 ? $offset + 1 : 0;
    $to        = min($offset + $perPage, $total);
    $prevPage  = $page > 1 ? $page - 1 : null;
    $nextPage  = $page < $totalPages ? $page + 1 : null;
    $wStart    = max(1, $page - 2);
    $wEnd      = min($totalPages, $page + 2);
    $pageLinks = [];
    if ($wStart > 1) {
        $pageLinks[] = ['num' => 1,    'active' => false, 'ellipsis' => false];
        if ($wStart > 2) {
            $pageLinks[] = ['num' => null, 'active' => false, 'ellipsis' => true];
        }
    }
    for ($i = $wStart; $i <= $wEnd; $i++) {
        $pageLinks[] = ['num' => $i, 'active' => ($i === $page), 'ellipsis' => false];
    }
    if ($wEnd < $totalPages) {
        if ($wEnd < $totalPages - 1) {
            $pageLinks[] = ['num' => null, 'active' => false, 'ellipsis' => true];
        }
        $pageLinks[] = ['num' => $totalPages, 'active' => false, 'ellipsis' => false];
    }

    $html = guidanceRender('modules/guidance/partials/trackers-table.disyl', [
        'trackers'     => $rows,
        'total'        => $total,
        'total_pages'  => $totalPages,
        'current_page' => $page,
        'prev_page'    => $prevPage,
        'next_page'    => $nextPage,
        'has_prev'     => $prevPage !== null,
        'has_next'     => $nextPage !== null,
        'from'         => $from,
        'to'           => $to,
        'page_links'   => $pageLinks,
        'base_url'     => '/admin/guidance',
    ]);
    $cache->setWithTags('guidance', $cacheKey, ['html' => $html], ['guidance:trackers'], 180);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}

function apiGuidanceToggleTracker(array $params = []): void
{
    guidanceRequireStaff(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);
    if ($id < 1) {
        http_response_code(404);
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Tracker not found', 'type' => 'error']]));
        echo '';
        return;
    }
    $db   = guidanceDb();
    $stmt = $db->prepare("UPDATE gm_trackers SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    guidanceClearTrackerCache();
    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Tracker updated', 'type' => 'success'], 'refreshTrackers' => true]));
    echo '';
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
    $trackerId = (int)$db->lastInsertId();

    // Insert initial document items if provided
    $items = is_array($input['items'] ?? null) ? $input['items'] : [];
    if ($trackerId > 0 && !empty($items)) {
        $itemStmt = $db->prepare(
            'INSERT INTO gm_tracker_items (tracker_id, name, is_required, sort_order, created_at) VALUES (?, ?, 1, ?, NOW())'
        );
        $sortOrder = 0;
        foreach ($items as $itemName) {
            $itemName = trim((string)$itemName);
            if ($itemName === '') {
                continue;
            }
            $itemStmt->execute([$trackerId, $itemName, $sortOrder++]);
        }
    }

    header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Tracker created', 'type' => 'success'], 'refreshTrackers' => true]));
    guidanceClearTrackerCache();
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
    guidanceClearTrackerCache();
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
    guidanceClearTrackerCache();
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
    if ($colleges === []) {
        fputcsv($output, ['No active colleges configured. Add colleges before importing students.']);
    }
    foreach ($colleges as $college) {
        if (!is_array($college)) {
            continue;
        }
        $collegeLabel = trim((string)($college['code'] ?? '')) . ' - ' . trim((string)($college['name'] ?? ''));
        $collegeLabel = trim($collegeLabel, ' -');
        if ($collegeLabel === '') {
            continue;
        }
        fputcsv($output, [$collegeLabel]);
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

/**
 * POST /admin/guidance/api/activate-license
 *
 * Immediate, self-service license key activation directly from the Guidance
 * admin settings page. No superadmin review required — the JWT is cryptographically
 * verified by guidanceVerifyLicenseJwt() using the bundled public key, and the
 * entitlement is granted on the spot.
 *
 * This is the primary upgrade path for the Guidance module. The superadmin
 * access-request flow (apiGuidanceRequestProAccess) remains available as a
 * fallback for manual provisioning.
 */
function apiGuidanceActivateLicense(): void
{
    $user = guidanceRequireStaff(['admin']);
    app()->csrfEnforce();

    $summary = guidanceSettingsEntitlementSummary();
    if (empty($summary['manageable'])) {
        guidanceProAccessResponse('License activation requires an active tenant context.', false, 400);
        return;
    }

    $licenseKey = trim((string)guidanceInput('license_key', ''));
    if ($licenseKey === '') {
        guidanceProAccessResponse('Please enter a license key.', false, 422);
        return;
    }
    // Reject absurdly large inputs before any crypto work
    if (strlen($licenseKey) > 8192) {
        guidanceProAccessResponse('License key is too long.', false, 422);
        return;
    }

    $tenantId = (int)$summary['tenant_id'];

    // Validate the JWT using the bundled RS256 public key.
    $verification = guidanceVerifyLicenseJwt($licenseKey, [
        'tenant_id' => $tenantId,
    ]);
    if (!($verification['ok'] ?? false)) {
        guidanceProAccessResponse((string)($verification['error'] ?? 'License key is invalid.'), false, 422);
        return;
    }

    $tier       = (string)$verification['tier'];
    $expiresAt  = (string)$verification['expires_at'];

    // Only allow known tiers — prevents "tier":"superadmin" escalation via crafted JWT.
    $allowedTiers = ['pro', 'basic', 'plus', 'enterprise'];
    if (!in_array($tier, $allowedTiers, true)) {
        guidanceProAccessResponse('License key specifies an unrecognised tier.', false, 422);
        return;
    }

    // JTI replay check — one JTI may only be bound to one tenant.
    $jti = (string)($verification['jti'] ?? '');
    if ($jti !== '') {
        $existingJtiTenant = guidanceLicenseJtiTenantBound($jti);
        if ($existingJtiTenant !== null && $existingJtiTenant !== $tenantId) {
            guidanceProAccessResponse('This license key has already been activated on another account.', false, 422);
            return;
        }
    }

    // Grant the entitlement directly — no superadmin approval step needed.
    $granted = grantModuleEntitlementForTenant('guidance', $tenantId, [
        'status'     => 'active',
        'tier'       => $tier,
        'expires_at' => $expiresAt !== '' ? $expiresAt : null,
        'source'     => 'license_jwt',
        'metadata'   => [
            'jti'          => $verification['jti'] ?? '',
            'activated_at' => date('Y-m-d H:i:s'),
            'activated_by' => (int)($user['id'] ?? 0),
            'via'          => 'apiGuidanceActivateLicense',
        ],
    ]);

    if (!$granted) {
        guidanceProAccessResponse('License validated but entitlement could not be saved. Please contact support.', false, 500);
        return;
    }

    // Also store the license activation state so the settings page reflects it.
    $licenseRef = substr($licenseKey, 0, 8) . '...' . substr($licenseKey, -6);
    saveTenantModuleSettings('guidance', [
        moduleLicenseActivationSettingsKey() => [
            'ok'           => true,
            'status'       => 'active',
            'provider'     => 'guidance',
            'tier'         => $tier,
            'expires_at'   => $expiresAt,
            'license_ref'  => $licenseRef,
            'activated_at' => date('Y-m-d H:i:s'),
            'jti'          => $verification['jti'] ?? '',
        ],
    ]);

    write_log('guidance.license.activate', 'info', [
        'tier'      => $tier,
        'expires_at'=> $expiresAt,
        'tenant_id' => $tenantId,
        'user_id'   => (int)($user['id'] ?? 0),
        'jti'       => $verification['jti'] ?? '',
        'issuer_host' => (string)($verification['issuer_host'] ?? ''),
    ]);

    $expiryNote = $expiresAt !== '' ? " (expires {$expiresAt})" : ' (perpetual)';
    guidanceProAccessResponse("Guidance " . ucfirst($tier) . " activated successfully{$expiryNote}.", true, 200, [
        'tier'       => $tier,
        'expires_at' => $expiresAt,
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

    // Merge the tenant-scoped module setting into page settings after normalizing it.
    $settings['license_store_url'] = guidanceLicenseStoreUrl((int)($proAccess['tenant_id'] ?? 0));

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

function guidanceResolvedAppointmentSettings(array $settings = []): array
{
    $resolved = guidanceGetSettingJson('appointment_settings', []);
    if (!is_array($resolved)) {
        $resolved = [];
    }

    $slotMinutes = (int)($settings['appointment_slot_minutes'] ?? 0);
    if ($slotMinutes > 0) {
        $resolved['default_duration_minutes'] = $slotMinutes;
    }

    $maxPerDay = (int)($settings['max_appointments_per_day'] ?? 0);
    if ($maxPerDay > 0) {
        $resolved['max_appointments_per_day'] = $maxPerDay;
    }

    $workingHoursStart = trim((string)($settings['working_hours_start'] ?? ''));
    if ($workingHoursStart !== '') {
        $resolved['working_hours_start'] = $workingHoursStart;
    }

    $workingHoursEnd = trim((string)($settings['working_hours_end'] ?? ''));
    if ($workingHoursEnd !== '') {
        $resolved['working_hours_end'] = $workingHoursEnd;
    }

    $reminderHours = (int)($settings['reminder_hours_before'] ?? 0);
    if ($reminderHours >= 0) {
        $resolved['reminder_hours_before'] = $reminderHours;
    }

    $notificationChannel = trim((string)($settings['notification_channel'] ?? ''));
    if ($notificationChannel !== '') {
        $resolved['notification_channel'] = $notificationChannel;
    }

    if (array_key_exists('email_notifications', $settings)) {
        $resolved['email_notifications'] = !empty($settings['email_notifications']) ? '1' : '0';
    }

    return $resolved;
}

function guidanceHydratePageSettings(array $settings, array $storedSettings = []): array
{
    $appointmentSettings = guidanceGetSettingJson('appointment_settings', []);
    $workingHours = guidanceGetSettingJson('working_hours', []);
    $legacyNotificationSettings = guidanceGetSettingJson('notification_settings', []);
    if (!is_array($appointmentSettings)) {
        $appointmentSettings = [];
    }
    if (!is_array($workingHours)) {
        $workingHours = [];
    }
    if (!is_array($legacyNotificationSettings)) {
        $legacyNotificationSettings = [];
    }

    if (array_key_exists('appointment_slot_minutes', $storedSettings)) {
        $appointmentSettings['default_duration_minutes'] = (int)($storedSettings['appointment_slot_minutes'] ?? 0);
    }
    if (array_key_exists('max_appointments_per_day', $storedSettings)) {
        $appointmentSettings['max_appointments_per_day'] = (int)($storedSettings['max_appointments_per_day'] ?? 0);
    }
    if (array_key_exists('working_hours_start', $storedSettings)) {
        $appointmentSettings['working_hours_start'] = (string)($storedSettings['working_hours_start'] ?? '');
    }
    if (array_key_exists('working_hours_end', $storedSettings)) {
        $appointmentSettings['working_hours_end'] = (string)($storedSettings['working_hours_end'] ?? '');
    }
    if (array_key_exists('notification_channel', $storedSettings)) {
        $appointmentSettings['notification_channel'] = (string)($storedSettings['notification_channel'] ?? '');
    }
    if (array_key_exists('email_notifications', $storedSettings)) {
        $appointmentSettings['email_notifications'] = (string)($storedSettings['email_notifications'] ?? '0');
    }
    if (array_key_exists('reminder_hours_before', $storedSettings)) {
        $appointmentSettings['reminder_hours_before'] = (int)($storedSettings['reminder_hours_before'] ?? 0);
    }

    $defaults = guidanceSettingsDefaults();
    $globalWeekdayHours = null;
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $weekday) {
        if (is_array($workingHours[$weekday] ?? null)) {
            $globalWeekdayHours = $workingHours[$weekday];
            break;
        }
    }

    if (!isset($settings['appointment_slot_minutes']) && isset($appointmentSettings['default_duration_minutes'])) {
        $settings['appointment_slot_minutes'] = (string)((int)$appointmentSettings['default_duration_minutes']);
    }
    if (!isset($settings['max_appointments_per_day']) && isset($appointmentSettings['max_appointments_per_day'])) {
        $settings['max_appointments_per_day'] = (string)((int)$appointmentSettings['max_appointments_per_day']);
    }
    if (!isset($settings['working_hours_start']) && is_array($globalWeekdayHours) && isset($globalWeekdayHours['start'])) {
        $settings['working_hours_start'] = (string)$globalWeekdayHours['start'];
    } elseif (!isset($settings['working_hours_start']) && isset($appointmentSettings['working_hours_start'])) {
        $settings['working_hours_start'] = (string)$appointmentSettings['working_hours_start'];
    }
    if (!isset($settings['working_hours_end']) && is_array($globalWeekdayHours) && isset($globalWeekdayHours['end'])) {
        $settings['working_hours_end'] = (string)$globalWeekdayHours['end'];
    } elseif (!isset($settings['working_hours_end']) && isset($appointmentSettings['working_hours_end'])) {
        $settings['working_hours_end'] = (string)$appointmentSettings['working_hours_end'];
    }
    if (!isset($settings['notification_channel']) && isset($appointmentSettings['notification_channel'])) {
        $settings['notification_channel'] = (string)$appointmentSettings['notification_channel'];
    } elseif (!isset($settings['notification_channel']) && array_key_exists('sms_enabled', $legacyNotificationSettings)) {
        $settings['notification_channel'] = !empty($legacyNotificationSettings['sms_enabled']) ? 'email_and_sms' : 'email_only';
    }
    if ((!isset($settings['email_notifications']) || (string)($settings['email_notifications'] ?? '') === (string)($defaults['email_notifications'] ?? '')) && isset($appointmentSettings['email_notifications'])) {
        $settings['email_notifications'] = (string)$appointmentSettings['email_notifications'];
    } elseif ((!isset($settings['email_notifications']) || (string)($settings['email_notifications'] ?? '') === (string)($defaults['email_notifications'] ?? '')) && array_key_exists('email_enabled', $legacyNotificationSettings)) {
        $settings['email_notifications'] = !empty($legacyNotificationSettings['email_enabled']) ? '1' : '0';
    }
    if ((!isset($settings['reminder_hours_before']) || (string)($settings['reminder_hours_before'] ?? '') === (string)($defaults['reminder_hours_before'] ?? '')) && isset($appointmentSettings['reminder_hours_before'])) {
        $settings['reminder_hours_before'] = (string)((int)$appointmentSettings['reminder_hours_before']);
    } elseif ((!isset($settings['reminder_hours_before']) || (string)($settings['reminder_hours_before'] ?? '') === (string)($defaults['reminder_hours_before'] ?? '')) && array_key_exists('appointment_reminder_hours', $legacyNotificationSettings)) {
        $settings['reminder_hours_before'] = (string)((int)($legacyNotificationSettings['appointment_reminder_hours'] ?? 24));
    }

    $settings['appointment_slot_minutes'] = (string)($settings['appointment_slot_minutes'] ?? '30');
    $settings['max_appointments_per_day'] = (string)($settings['max_appointments_per_day'] ?? '0');
    $settings['working_hours_start'] = (string)($settings['working_hours_start'] ?? '08:00');
    $settings['working_hours_end'] = (string)($settings['working_hours_end'] ?? '17:00');
    $settings['notification_channel'] = (string)($settings['notification_channel'] ?? 'email_only');
    $settings['email_notifications'] = (string)($settings['email_notifications'] ?? '1');
    $settings['retention_active_years'] = (string)($settings['retention_active_years'] ?? '7');
    $settings['retention_closed_years'] = (string)($settings['retention_closed_years'] ?? '5');
    $settings['reminder_hours_before'] = (string)($settings['reminder_hours_before'] ?? '24');
    $settings['app_country'] = (string)($settings['app_country'] ?? 'PH');
    $settings['app_region'] = (string)($settings['app_region'] ?? 'Manila');
    $settings['app_timezone'] = (string)($settings['app_timezone'] ?? 'Asia/Manila');
    $settings['ai_provider'] = (string)($settings['ai_provider'] ?? '');
    $settings['ai_api_key'] = (string)($settings['ai_api_key'] ?? '');
    $settings['ai_model'] = (string)($settings['ai_model'] ?? '');
    $settings['license_public_key_pem'] = (string)($settings['license_public_key_pem'] ?? '');

    return $settings;
}

function guidanceSettingsPersistableInput(array $input): array
{
    $persistable = $input;
    $persistable['appointment_settings'] = guidanceResolvedAppointmentSettings($input);
    $persistable['notification_settings'] = array_merge(
        guidanceGetSettingJson('notification_settings', []),
        [
            'email_enabled' => !empty($input['email_notifications']),
            'sms_enabled' => trim((string)($input['notification_channel'] ?? 'email_only')) === 'email_and_sms',
            'appointment_reminder_hours' => max(0, (int)($input['reminder_hours_before'] ?? 24)),
        ]
    );

    $workingHoursStart = trim((string)($input['working_hours_start'] ?? ''));
    $workingHoursEnd = trim((string)($input['working_hours_end'] ?? ''));
    if ($workingHoursStart !== '' && $workingHoursEnd !== '') {
        $weekdayHours = ['start' => $workingHoursStart, 'end' => $workingHoursEnd];
        $persistable['working_hours'] = [
            'monday' => $weekdayHours,
            'tuesday' => $weekdayHours,
            'wednesday' => $weekdayHours,
            'thursday' => $weekdayHours,
            'friday' => $weekdayHours,
            'saturday' => null,
            'sunday' => null,
        ];
    }

    return $persistable;
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

    $storedSettings = $settings;

    $defaults = guidanceSettingsDefaults();
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $settings)) {
            $settings[$k] = $v;
        }
    }

    return guidanceHydratePageSettings($settings, $storedSettings);
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
    app()->csrfEnforce();
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
        $input = guidanceSettingsPersistableInput($input);
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
    app()->csrfEnforce();

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
    app()->csrfEnforce();

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
    app()->csrfEnforce();

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

function caseSeverityError(string $message, int $status = 400): void
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

function ensureCaseSeverityField(\Ikabud\Kernel\Contracts\DatabaseContract $db): array
{
    $stmt = $db->prepare('SELECT * FROM gm_form_fields WHERE form_type = ? AND field_name = ? LIMIT 1');
    $stmt->execute(['case', 'severity']);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($field) {
        return $field;
    }

    $defaults = guidanceDefaultCaseSeverityLevels();
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
        'severity',
        'Severity',
        'select',
        'Case Details',
        json_encode($defaults, JSON_UNESCAPED_UNICODE),
        null,
        'medium',
        0,
        1,
        1,
        max(1, $nextSort),
        'half',
    ]);

    $stmt->execute(['case', 'severity']);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$field) {
        throw new RuntimeException('Failed to initialize case severity field');
    }

    return $field;
}

function getCaseSeverityConfig(\Ikabud\Kernel\Contracts\DatabaseContract $db): array
{
    $field = ensureCaseSeverityField($db);
    $configured = [];
    if (!empty($field['field_options'])) {
        $decoded = json_decode((string) $field['field_options'], true);
        $source = is_array($decoded) ? $decoded : explode(',', (string) $field['field_options']);
        foreach ($source as $option) {
            $normalized = normalizeCaseSeverityValue((string) $option);
            if ($normalized !== '' && !in_array($normalized, $configured, true)) {
                $configured[] = $normalized;
            }
        }
    }

    if ($configured === []) {
        $configured = guidanceDefaultCaseSeverityLevels();
    }

    $default = normalizeCaseSeverityValue((string) ($field['default_value'] ?? ''));
    if ($default === '' || !in_array($default, $configured, true)) {
        $default = $configured[0] ?? 'medium';
    }

    $usageCounts = [];
    try {
        $usageStmt = $db->query(
            "SELECT severity, COUNT(*) AS severity_count
             FROM gm_cases
             WHERE severity IS NOT NULL AND TRIM(severity) != ''
             GROUP BY severity"
        );
        foreach ($usageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $severity = normalizeCaseSeverityValue((string) ($row['severity'] ?? ''));
            if ($severity !== '') {
                $usageCounts[$severity] = (int) ($row['severity_count'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        $usageCounts = [];
    }

    $orderedValues = $configured;
    foreach (guidanceDefaultCaseSeverityLevels() as $severity) {
        if (!in_array($severity, $orderedValues, true)) {
            $orderedValues[] = $severity;
        }
    }

    $items = [];
    foreach ($orderedValues as $index => $severity) {
        $items[] = [
            'value' => $severity,
            'label' => guidanceCaseSeverityLabel($severity),
            'sort_order' => $index + 1,
            'is_enabled' => in_array($severity, $configured, true),
            'is_default' => $severity === $default,
            'in_use_count' => (int) ($usageCounts[$severity] ?? 0),
        ];
    }

    return [
        'field' => $field,
        'levels' => $configured,
        'default' => $default,
        'items' => $items,
    ];
}

function guidanceGetCaseSeverityOptionsForForm(\Ikabud\Kernel\Contracts\DatabaseContract $db, ?string $currentValue = null): array
{
    try {
        $config = getCaseSeverityConfig($db);
        $levels = $config['levels'];
        $default = $config['default'];
    } catch (Throwable $e) {
        $levels = guidanceDefaultCaseSeverityLevels();
        $default = 'medium';
    }

    $currentValue = normalizeCaseSeverityValue($currentValue);
    if ($currentValue !== '' && !in_array($currentValue, $levels, true)) {
        $levels[] = $currentValue;
    }

    $options = [];
    foreach ($levels as $level) {
        $options[] = [
            'value' => $level,
            'label' => guidanceCaseSeverityLabel($level),
        ];
    }

    return [
        'levels' => $levels,
        'default' => $default,
        'options' => $options,
    ];
}

function saveCaseSeverityConfig(\Ikabud\Kernel\Contracts\DatabaseContract $db, array $field, array $levels, ?string $defaultValue): array
{
    $normalized = [];
    foreach ($levels as $level) {
        $value = normalizeCaseSeverityValue((string) $level);
        if ($value !== '' && !in_array($value, $normalized, true)) {
            $normalized[] = $value;
        }
    }

    if ($normalized === []) {
        caseSeverityError('At least one severity level must remain enabled', 400);
    }

    $defaultValue = normalizeCaseSeverityValue((string) $defaultValue);
    if ($defaultValue === '' || !in_array($defaultValue, $normalized, true)) {
        $defaultValue = $normalized[0];
    }

    $stmt = $db->prepare('UPDATE gm_form_fields SET field_options = ?, default_value = ?, is_enabled = 1 WHERE id = ?');
    $stmt->execute([
        json_encode($normalized, JSON_UNESCAPED_UNICODE),
        $defaultValue,
        $field['id'],
    ]);

    return [
        'levels' => $normalized,
        'default' => $defaultValue,
    ];
}

function apiGuidanceListCaseSeverityLevels(): void
{
    guidanceRequireStaff(['admin']);
    $db = guidanceDb();
    $context = (string) guidanceInput('context', '');

    try {
        $config = getCaseSeverityConfig($db);
    } catch (Throwable $e) {
        app()->log('Case severity list error: ' . $e->getMessage(), 'error');
        caseSeverityError('Failed to load case severity settings', 500);
    }

    if (guidanceIsHtmx() && $context === 'settings') {
        echo guidanceRender('modules/guidance/partials/case-severity-settings.disyl', [
            'severity_levels' => $config['items'],
            'default_severity' => $config['default'],
        ]);
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $config['items'], 'default' => $config['default']], JSON_UNESCAPED_SLASHES);
}

function apiGuidanceUpdateCaseSeverityLevels(): void
{
    guidanceRequireStaff(['admin']);
    app()->csrfEnforce();
    $db = guidanceDb();
    $input = guidanceInput();

    if (!is_array($input)) {
        caseSeverityError('Invalid request payload', 400);
    }

    $enabledInput = $input['enabled_levels'] ?? [];
    if (!is_array($enabledInput)) {
        $enabledInput = $enabledInput === '' ? [] : [$enabledInput];
    }

    $sortOrders = is_array($input['sort_order'] ?? null) ? $input['sort_order'] : [];
    $enabled = [];
    foreach ($enabledInput as $level) {
        $normalized = normalizeCaseSeverityValue((string) $level);
        if ($normalized !== '' && !in_array($normalized, $enabled, true)) {
            $enabled[] = $normalized;
        }
    }

    usort($enabled, static function (string $left, string $right) use ($sortOrders): int {
        $leftSort = max(1, (int) ($sortOrders[$left] ?? PHP_INT_MAX));
        $rightSort = max(1, (int) ($sortOrders[$right] ?? PHP_INT_MAX));
        if ($leftSort === $rightSort) {
            return array_search($left, guidanceDefaultCaseSeverityLevels(), true) <=> array_search($right, guidanceDefaultCaseSeverityLevels(), true);
        }

        return $leftSort <=> $rightSort;
    });

    try {
        $config = getCaseSeverityConfig($db);
        $db->beginTransaction();
        saveCaseSeverityConfig($db, $config['field'], $enabled, (string) ($input['default_value'] ?? ''));
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        app()->log('Case severity update error: ' . $e->getMessage(), 'error');
        caseSeverityError('Failed to update case severity settings', 500);
    }

    if (guidanceIsHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Case severity settings updated', 'type' => 'success'],
            'refreshCaseSeverityLevels' => true,
        ]));
        echo '';
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
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
    static $exists = [];
    $tid = app()->tenant()->current();

    if (array_key_exists($tid, $exists)) {
        return $exists[$tid];
    }

    try {
        $stmt = $db->query("SHOW COLUMNS FROM gm_cases LIKE 'student_status'");
        $exists[$tid] = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $exists[$tid] = false;
    }

    return $exists[$tid];
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

function guidanceGetStudentStatusOptionsForForm(\Ikabud\Kernel\Contracts\DatabaseContract $db, ?string $currentValue = null): array
{
    try {
        $config = getStudentStatusConfig($db);
        $statuses = $config['statuses'];
        $default = $config['default'];
    } catch (Throwable $e) {
        $statuses = studentStatusDefaultOptions();
        $default = $statuses[0] ?? '';
    }

    $currentValue = normalizeStudentStatusLabel((string)$currentValue);
    if ($currentValue !== '' && !in_array($currentValue, $statuses, true)) {
        $statuses[] = $currentValue;
    }

    return [
        'statuses' => $statuses,
        'default' => $default,
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
    app()->csrfEnforce();
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
    app()->csrfEnforce();
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
    app()->csrfEnforce();
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

    $profileRole = (string)($row['role'] ?? ($user['role'] ?? 'counselor'));
    $canEditEmail = $profileRole !== 'admin';
    $showAvailabilityEditor = guidanceIsPro() && in_array($profileRole, ['counselor', 'supervisor'], true);
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
                'role' => $profileRole,
                'last_login_at' => $row['last_login_at'] ?? null,
            ],
            'can_edit_email' => $canEditEmail,
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

    $canEditEmail = (string)($currentUser['role'] ?? '') !== 'admin';
    $email = trim((string)($input['email'] ?? ''));
    $hasEmailChangeInput = $canEditEmail && array_key_exists('email', $input);

    if ($hasEmailChangeInput) {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            if (guidanceIsHtmx()) {
                header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Invalid email address.', 'type' => 'error']]));
                echo '';
                return;
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Invalid email address.'], JSON_UNESCAPED_SLASHES);
            return;
        }

        $dupStmt = $db->prepare('SELECT id FROM gm_users WHERE email = ? AND id != ? AND deleted_at IS NULL LIMIT 1');
        $dupStmt->execute([$email, $id]);
        if ($dupStmt->fetchColumn()) {
            http_response_code(409);
            if (guidanceIsHtmx()) {
                header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'A user with this email already exists.', 'type' => 'error']]));
                echo '';
                return;
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'A user with this email already exists.'], JSON_UNESCAPED_SLASHES);
            return;
        }
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
    if ($hasEmailChangeInput) {
        $updates[] = 'email = ?';
        $values[] = $email;
    }
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

    $cacheKey = $isCounselor ? 'guidance_dashboard_stats_c' . (int)$counselorId : 'guidance_dashboard_stats_all';
    $cache    = app()->cache();
    $cached   = $cache->get('guidance', $cacheKey);
    if ($cached !== null) {
        header('Content-Type: text/html; charset=utf-8');
        echo $cached['html'];
        return;
    }

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

    $html = guidanceRender('modules/guidance/partials/stats-cards.disyl', [
        'stats' => $stats,
        'base_url' => '/admin/guidance',
    ]);
    $cache->setWithTags('guidance', $cacheKey, ['html' => $html], ['guidance:stats'], 300);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}

function apiGuidanceRecentCases(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);

    $role = is_array($user) ? (string)($user['role'] ?? '') : '';
    $isCounselor = $role === 'counselor';
    $counselorId = $isCounselor && is_array($user) ? (int)($user['id'] ?? 0) : null;

    $db = guidanceDb();
    $filter = "c.deleted_at IS NULL";
    $params = [];
    if ($isCounselor && $counselorId) {
        $filter .= " AND c.counselor_id = ?";
        $params[] = $counselorId;
    }

    $stmt = $db->prepare(
        "SELECT c.id, c.case_number, c.student_name, c.student_id, c.status, c.severity, c.category,\n"
        . "       c.presenting_issue, c.updated_at, c.student_grade, c.student_status,\n"
        . "       SUBSTRING_INDEX(c.student_name, ' ', -1) AS last_name,\n"
        . "       TRIM(SUBSTRING_INDEX(c.student_name, ' ', 1)) AS first_name,\n"
        . "       col.code AS college_code,\n"
        . "       CONCAT(u.first_name, ' ', u.last_name) AS counselor_name\n"
        . "FROM gm_cases c\n"
        . "LEFT JOIN gm_users u ON c.counselor_id = u.id\n"
        . "LEFT JOIN gm_colleges col ON c.college_id = col.id\n"
        . "WHERE {$filter}\n"
        . "ORDER BY c.updated_at DESC\n"
        . "LIMIT 5"
    );
    $stmt->execute($params);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/html; charset=utf-8');
    $template = !empty(guidanceInput('entity')) ? 'modules/guidance/partials/recent-cases-entity.disyl' : 'modules/guidance/partials/recent-cases.disyl';
    echo guidanceRender($template, [
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

function apiGuidanceCaseStats(): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role    = (string)($user['role'] ?? '');
    $userId  = (int)($user['id'] ?? 0);
    $isCounselor = $role === 'counselor';

    $cacheKey = $isCounselor ? 'guidance_case_stats_c' . $userId : 'guidance_case_stats_all';
    $cache    = app()->cache();
    $cached   = $cache->get('guidance', $cacheKey);
    if ($cached !== null) {
        header('Content-Type: text/html; charset=utf-8');
        echo $cached['html'];
        return;
    }

    $db = guidanceDb();

    try {
        $roleWhere  = 'c.deleted_at IS NULL';
        $roleParams = [];
        if ($isCounselor) {
            $roleWhere   .= ' AND c.counselor_id = ?';
            $roleParams[] = $userId;
        }

        $stmt = $db->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN c.severity = 'low'                     THEN 1 ELSE 0 END) AS low_risk,
                SUM(CASE WHEN c.severity = 'medium'                  THEN 1 ELSE 0 END) AS moderate_risk,
                SUM(CASE WHEN c.severity IN ('high','critical')       THEN 1 ELSE 0 END) AS high_risk,
                SUM(CASE WHEN c.student_status = 'probationary'      THEN 1 ELSE 0 END) AS probationary
            FROM gm_cases c WHERE {$roleWhere}"
        );
        $stmt->execute($roleParams);
        $row   = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats = array_map('intval', $row);

        $html = guidanceRender('modules/guidance/partials/case-stats-sidebar.disyl', [
            'stats' => $stats,
        ]);
        $cache->setWithTags('guidance', $cacheKey, ['html' => $html], ['guidance:case-stats', 'guidance:stats'], 300);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    } catch (Throwable $e) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="p-4 text-sm text-red-500">Failed to load stats</div>';
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
    app()->csrfEnforce();
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
    $stmt = $db->prepare('SELECT * FROM gm_appointments WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $apptId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Appointment not found']);
        return;
    }

    $row = guidanceMergeAppointmentBookingSnapshot($row);

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

    $caseId = (int)($row['case_id'] ?? 0);
    $createdCase = null;
    $startedTransaction = !$db->inTransaction();

    try {
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        if ($caseId < 1) {
            $reusableCase = guidanceFindReusableCaseByStudentEmail($db, (string)($row['student_email'] ?? ''));
            if (is_array($reusableCase)) {
                guidanceLinkAppointmentToCase($db, (int)($reusableCase['id'] ?? 0), [
                    'counselor_id' => (int)($reusableCase['counselor_id'] ?? ($row['counselor_id'] ?? 0)),
                    'student_name' => (string)($row['student_name'] ?? ($reusableCase['student_name'] ?? '')),
                    'student_email' => (string)($row['student_email'] ?? ($reusableCase['student_email'] ?? '')),
                    'student_mobile' => (string)($row['student_mobile'] ?? ($reusableCase['student_mobile'] ?? '')),
                    'college_id' => $row['college_id'] ?? ($reusableCase['college_id'] ?? null),
                    'student_grade' => (string)($row['student_grade'] ?? ($reusableCase['student_grade'] ?? '')),
                ], $row, $userId);
                $caseId = (int)($reusableCase['id'] ?? 0);
            } else {
                $createdCase = guidanceAutoCreateCaseFromAppointment($db, $row, $userId);
                $caseId = (int)($createdCase['id'] ?? 0);
            }
        }

        $upd = $db->prepare(
            "UPDATE gm_appointments\n"
            . "SET status = 'confirmed', approved_at = NOW(), approved_by = :uid, rejected_at = NULL, rejected_by = NULL, rejection_reason = NULL\n"
            . "WHERE id = :id"
        );
        $upd->execute([':uid' => $userId, ':id' => $apptId]);

        if ($startedTransaction) {
            $db->commit();
        }
    } catch (RuntimeException $e) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        $status = guidanceIsDuplicateStudentEmailMessage($e->getMessage()) ? 409 : 422;
        header('Content-Type: application/json', true, $status);
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        return;
    } catch (Throwable $e) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        app()->log('Appointments approve error: ' . $e->getMessage(), 'error');
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to approve appointment']);
        return;
    }

    try {
        guidanceSendAppointmentTemplateEmail('booking_confirmed', trim((string)($row['student_email'] ?? '')), [
            'student_name' => (string)($row['student_name'] ?? 'Student'),
            'date' => date('F j, Y', strtotime((string)($row['scheduled_date'] ?? date('Y-m-d')))),
            'time' => date('g:i A', strtotime((string)($row['scheduled_time'] ?? '00:00'))),
            'location' => (string)(trim((string)($row['location'] ?? '')) !== '' ? $row['location'] : 'Guidance Office'),
            'reason' => '',
            'appointment_id' => (string)$apptId,
        ]);
    } catch (Throwable $e) {
        app()->log('Appointments approve email error: ' . $e->getMessage(), 'error');
    }

    if (guidanceIsHtmx()) {
        guidanceClearAppointmentStatsCache();
        guidanceClearCaseStatsCache();
        guidanceHtmxResponse([
            'trigger' => json_encode([
                'approvalChanged' => ['id' => $apptId, 'action' => 'approved', 'case_id' => $caseId],
                'refreshAppointments' => true,
                'refreshCases' => true,
            ]),
        ]);
        header('Content-Type: text/plain; charset=utf-8');
        echo '';
        return;
    }

    guidanceClearAppointmentStatsCache();
    guidanceClearCaseStatsCache();
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'case_id' => $caseId]);
}

function apiGuidanceRejectAppointment(array $params): void
{
    $user = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    app()->csrfEnforce();
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
    $stmt = $db->prepare(
        "SELECT id, counselor_id, status, case_id, student_name, student_email, scheduled_date, scheduled_time, location\n"
        . "FROM gm_appointments WHERE id = :id LIMIT 1"
    );
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

    try {
        guidanceSendAppointmentTemplateEmail('booking_rejected', trim((string)($row['student_email'] ?? '')), [
            'student_name' => (string)($row['student_name'] ?? 'Student'),
            'date' => date('F j, Y', strtotime((string)($row['scheduled_date'] ?? date('Y-m-d')))),
            'time' => date('g:i A', strtotime((string)($row['scheduled_time'] ?? '00:00'))),
            'location' => (string)(trim((string)($row['location'] ?? '')) !== '' ? $row['location'] : 'Guidance Office'),
            'reason' => $reason !== '' ? ('Reason: ' . $reason) : '',
            'appointment_id' => (string)$apptId,
        ]);
    } catch (Throwable $e) {
        app()->log('Appointments reject email error: ' . $e->getMessage(), 'error');
    }

    if (guidanceIsHtmx()) {
        guidanceClearAppointmentStatsCache();
        guidanceHtmxResponse([
            'trigger' => json_encode(['approvalChanged' => ['id' => $apptId, 'action' => 'rejected']]),
        ]);
        header('Content-Type: text/plain; charset=utf-8');
        echo '';
        return;
    }

    guidanceClearAppointmentStatsCache();
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}

// Password Reset Helpers
function guidancePasswordResetTokenHash(string $token): string {
    return hash('sha256', $token);
}

function guidanceExternalBaseUrl(): string
{
    return external_base_url((string)config('app.url', ''));
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

function guidanceResetTokenIsValid(string $token): bool
{
    if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return false;
    }

    return guidanceFindActivePasswordReset($token) !== null;
}

function guidancePasswordResetJson(array $payload, int $status = 200): void
{
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($payload);
}

function guidancePasswordResetSuccessPayload(string $message, array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'success' => true,
        'message' => $message,
    ], $extra);
}

function guidancePasswordResetError(string $message, int $status = 422): void
{
    guidancePasswordResetJson([
        'ok' => false,
        'success' => false,
        'error' => $message,
    ], $status);
}

function pageGuidanceForgotPassword(): void {
    if (guidanceUserFromCookie()) {
        guidanceRedirect('/admin/guidance');
    }
    echo guidanceRender('modules/guidance/pages/forgot-password.disyl', [
        'hide_sidebar' => true,
        'page_title' => 'Forgot Password',
        'base_url' => '/guidance',
        'forgot_password_endpoint' => '/api/v1/guidance/auth/forgot-password',
        'login_page_url' => '/guidance/login',
    ]);
}

function pageGuidanceResetPassword(): void {
    if (guidanceUserFromCookie()) {
        guidanceRedirect('/admin/guidance');
    }
    $token = trim((string)guidanceInput('token', ''));
    echo guidanceRender('modules/guidance/pages/reset-password.disyl', [
        'hide_sidebar' => true,
        'page_title' => 'Reset Password',
        'base_url' => '/guidance',
        'reset_password_endpoint' => '/api/v1/guidance/auth/reset-password',
        'login_page_url' => '/guidance/login',
        'reset_token' => $token,
        'token_valid' => guidanceResetTokenIsValid($token),
        'app_name' => guidanceGetSetting('app_name', 'Guidance Monitoring System') ?: 'Guidance Monitoring System',
    ]);
}

function apiGuidanceForgotPassword(): void {
    $policy = kernel_password_reset_policy();
    $ttlMinutes = max(1, (int)$policy['token_ttl_minutes']);
    $identity = trim((string)guidanceInput('identity', guidanceInput('email', '')));
    if ($identity === '') {
        guidancePasswordResetError('Username or email is required.');
        return;
    }

    $ip = clientIp();
    if (!rateLimit('guidance_forgot:' . $ip, (int)$policy['forgot_rate_limit_ip_max'], (int)$policy['forgot_rate_limit_window_seconds'])) {
        guidancePasswordResetError((string)$policy['forgot_rate_limit_message'], 429);
        return;
    }

    $successMsg = (string)$policy['forgot_success_message'];

    try {
                $user = guidanceFindActiveUserByIdentity($identity);

                if (is_array($user) && !empty($user['is_active'])) {
            $token = guidanceIssuePasswordResetToken((string)$user['email'], $ttlMinutes * 60);
            $resetUrl = guidanceExternalBaseUrl() . '/guidance/reset-password?token=' . urlencode($token);

            if (function_exists('sendEmail') && function_exists('buildEmailTemplate')) {
                $name = trim((string)($user['first_name'] ?? 'there'));
                $content = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">Hi ' . htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">A request was made to reset your Guidance password.</p>'
                    . '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">This link expires in ' . $ttlMinutes . ' minutes. If you did not request this, you can safely ignore this email.</p>';
                $body = buildEmailTemplate('Reset Your Guidance Password', $content, 'Reset Password', $resetUrl);
                $sent = sendEmail((string)$user['email'], 'Guidance Password Reset', $body);
                if (!$sent) {
                    write_log('guidance forgot-password email dispatch failed for user_id=' . (string)$user['id'], 'error');
                }
            }
        }

        guidancePasswordResetJson(guidancePasswordResetSuccessPayload($successMsg));
    } catch (Throwable $e) {
        write_log('guidance forgot-password failed: ' . $e->getMessage(), 'error');
        guidancePasswordResetError('Unable to process request right now.', 500);
    }
}

function apiGuidanceResetPassword(): void {
    $policy = kernel_password_reset_policy();
    $token = trim((string)guidanceInput('token', ''));
    $password = (string)guidanceInput('password', '');
    $confirm = (string)guidanceInput('confirm_password', guidanceInput('password_confirm', ''));

    if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        guidancePasswordResetError((string)$policy['invalid_token_message']);
        return;
    }

    if (strlen($password) < 8) {
        guidancePasswordResetError('Password must be at least 8 characters.');
        return;
    }

    if ($password !== $confirm) {
        guidancePasswordResetError('Passwords do not match.');
        return;
    }

    $ip = clientIp();
    if (!rateLimit('guidance_reset:' . $ip, (int)$policy['reset_rate_limit_ip_max'], (int)$policy['reset_rate_limit_window_seconds'])) {
        guidancePasswordResetError((string)$policy['reset_rate_limit_message'], 429);
        return;
    }

    try {
        $resetData = guidanceFindActivePasswordReset($token);
        if (!is_array($resetData)) {
            guidancePasswordResetError((string)$policy['invalid_token_message']);
            return;
        }

        $email = (string)($resetData['email'] ?? '');
        $stmt = guidanceDb()->prepare(
            'SELECT id
             FROM gm_users
             WHERE email = ?
               AND deleted_at IS NULL
               AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            guidancePasswordResetError('Account not found or inactive.');
            return;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        guidanceDb()->prepare('UPDATE gm_users SET password = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$hash, (int)$user['id']]);

        guidanceMarkPasswordResetUsed((int)$resetData['id']);

        guidancePasswordResetJson(guidancePasswordResetSuccessPayload(
            (string)$policy['reset_success_message'],
            ['redirect' => '/guidance/login']
        ));
    } catch (Throwable $e) {
        write_log('guidance reset-password failed: ' . $e->getMessage(), 'error');
        guidancePasswordResetError('Unable to reset password right now.', 500);
    }
}

// ---------------------------------------------------------------------------
// Session Records page handler
// ---------------------------------------------------------------------------

function pageGuidanceSessionRecords(): void
{
    guidanceRequireStaff();
    $ctxUser = guidanceUser();
    $db = guidanceDb();
    $counselors = [];
    try {
        $stmt = $db->query("SELECT id, first_name, last_name FROM gm_users WHERE role IN ('counselor','admin','supervisor') AND deleted_at IS NULL ORDER BY last_name, first_name");
        $counselors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // non-fatal
    }
    $appointmentTypes = [];
    try {
        $stmt = $db->query("SELECT id, code, name FROM gm_appointment_types WHERE is_active = 1 ORDER BY sort_order, name");
        $appointmentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // non-fatal
    }
    echo guidanceRender('modules/guidance/pages/session-records.disyl', array_merge(
        guidanceBasePageContext($ctxUser, 'Session Records', 'session-records'),
        [
            'counselors'        => $counselors,
            'appointment_types' => $appointmentTypes,
        ]
    ));
}

// ---------------------------------------------------------------------------
// Appointment summary stats (upcoming/completed/pending/cancelled+no-show)
// ---------------------------------------------------------------------------

function apiGuidanceAppointmentStats(): void
{
    guidanceRequireStaff();

    $cache    = app()->cache();
    $cacheKey = 'guidance_appt_stats';
    $cached   = $cache->get('guidance', $cacheKey);
    if ($cached !== null) {
        header('Content-Type: text/html; charset=utf-8');
        echo $cached['html'];
        return;
    }

    try {
        $db = guidanceDb();
        $stmt = $db->query("
            SELECT
                SUM(CASE WHEN status IN ('scheduled','confirmed') AND cancelled_at IS NULL THEN 1 ELSE 0 END) AS upcoming,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END)                                         AS completed,
                SUM(CASE WHEN status = 'pending' AND cancelled_at IS NULL THEN 1 ELSE 0 END)                   AS pending,
                SUM(CASE WHEN status IN ('no_show','cancelled') OR cancelled_at IS NOT NULL THEN 1 ELSE 0 END) AS cancelled_no_show
            FROM gm_appointments
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $upcoming        = (int)($row['upcoming'] ?? 0);
        $completed       = (int)($row['completed'] ?? 0);
        $pending         = (int)($row['pending'] ?? 0);
        $cancelledNoShow = (int)($row['cancelled_no_show'] ?? 0);

        $cards = [
            ['label' => 'Upcoming',            'count' => $upcoming,        'icon' => 'fa-calendar-check', 'bg' => 'bg-teal-100',   'text' => 'text-teal-700',   'num' => 'text-teal-800'],
            ['label' => 'Completed',           'count' => $completed,       'icon' => 'fa-check-circle',   'bg' => 'bg-green-100',  'text' => 'text-green-700',  'num' => 'text-green-800'],
            ['label' => 'Pending',             'count' => $pending,         'icon' => 'fa-hourglass-half', 'bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'num' => 'text-orange-800'],
            ['label' => 'Cancelled / No Show', 'count' => $cancelledNoShow, 'icon' => 'fa-times-circle',   'bg' => 'bg-red-100',    'text' => 'text-red-700',    'num' => 'text-red-800'],
        ];

        $subLabels = ['Upcoming' => 'appointments', 'Completed' => 'this month', 'Pending' => 'reschedules', 'Cancelled / No Show' => 'cancelled or no-show'];
        ob_start();
        foreach ($cards as $c) {
            $label    = htmlspecialchars($c['label']);
            $subLabel = htmlspecialchars($subLabels[$c['label']] ?? '');
            $count    = (int)$c['count'];
            echo <<<HTML
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex items-center gap-3 min-w-0">
                <div class="w-12 h-12 rounded-full {$c['bg']} flex items-center justify-center flex-shrink-0">
                    <i class="fas {$c['icon']} {$c['text']} text-xl"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-2xl font-bold {$c['num']} leading-none">{$count}</div>
                    <div class="text-xs font-medium text-gray-700 mt-0.5 truncate">{$label}</div>
                    <div class="text-xs text-gray-400 truncate">{$subLabel}</div>
                </div>
            </div>
HTML;
        }
        $html = ob_get_clean() ?: '';
        $cache->setWithTags('guidance', $cacheKey, ['html' => $html], ['guidance:appointment-stats', 'guidance:stats'], 180);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;

    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="text-red-500 text-sm p-4">Failed to load appointment stats.</div>';
    }
}

// ---------------------------------------------------------------------------
// Session Records summary stats (documented outcomes only)
// ---------------------------------------------------------------------------

function apiGuidanceSessionRecordStats(): void
{
    guidanceRequireStaff();

    $cache    = app()->cache();
    $cacheKey = 'guidance_session_record_stats';
    $cached   = $cache->get('guidance', $cacheKey);
    if ($cached !== null) {
        header('Content-Type: text/html; charset=utf-8');
        echo $cached['html'];
        return;
    }

    try {
        $db = guidanceDb();
        $stmt = $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END)   AS completed,
                SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END)     AS no_show,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END)   AS cancelled
            FROM gm_appointments
            WHERE status IN ('completed','no_show','cancelled')
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total      = (int)($row['total'] ?? 0);
        $completed  = (int)($row['completed'] ?? 0);
        $noShow     = (int)($row['no_show'] ?? 0);
        $cancelled  = (int)($row['cancelled'] ?? 0);
        $pct        = static fn(int $n, int $t): float => $t > 0 ? round($n / $t * 100, 1) : 0.0;

        $cards = [
            ['label' => 'Recorded Outcomes', 'count' => $total,     'pct' => null,                      'icon' => 'fa-clipboard-list', 'bg' => 'bg-blue-100',   'text' => 'text-blue-700',   'num' => 'text-blue-800'],
            ['label' => 'Went to Session',   'count' => $completed, 'pct' => $pct($completed, $total),  'icon' => 'fa-check-circle',   'bg' => 'bg-green-100',  'text' => 'text-green-700',  'num' => 'text-green-800'],
            ['label' => 'Did Not Show Up',   'count' => $noShow,    'pct' => $pct($noShow, $total),     'icon' => 'fa-user-times',     'bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'num' => 'text-orange-800'],
            ['label' => 'Cancelled',         'count' => $cancelled, 'pct' => $pct($cancelled, $total),  'icon' => 'fa-times-circle',   'bg' => 'bg-red-100',    'text' => 'text-red-700',    'num' => 'text-red-800'],
        ];

        ob_start();
        foreach ($cards as $c) {
            $label  = htmlspecialchars($c['label']);
            $count  = (int)$c['count'];
            $subText = $c['pct'] !== null
                ? '<div class="text-xs ' . $c['text'] . ' mt-0.5">' . $c['pct'] . '%</div>'
                : '<div class="text-xs text-gray-400 mt-0.5">All time</div>';
            echo <<<HTML
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex items-center gap-3 min-w-0">
                <div class="w-10 h-10 rounded-full {$c['bg']} flex items-center justify-center flex-shrink-0">
                    <i class="fas {$c['icon']} {$c['text']} text-base"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-xl font-bold {$c['num']} leading-none">{$count}</div>
                    <div class="text-xs text-gray-500 leading-tight mt-0.5 truncate">{$label}</div>
                    {$subText}
                </div>
            </div>
HTML;
        }
        $html = ob_get_clean() ?: '';
        $cache->setWithTags('guidance', $cacheKey, ['html' => $html], ['guidance:appointment-stats'], 300);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;

    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="text-red-500 text-sm p-4">Failed to load session stats.</div>';
    }
}

function apiGuidanceSessionDetail(array $params = []): void
{
    $ctxUser = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role    = (string)($ctxUser['role'] ?? '');
    $userId  = (int)($ctxUser['id'] ?? 0);
    $apptId  = (int)($params['id'] ?? 0);

    if ($apptId < 1) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="p-4 text-sm text-gray-500">Session not found.</div>';
        return;
    }

    $db = guidanceDb();

    try {
        $whereExtra = '';
        $q = [$apptId];
        if ($role === 'counselor') {
            $whereExtra = ' AND a.counselor_id = ?';
            $q[] = $userId;
        }

        $stmt = $db->prepare(
            "SELECT a.*,
                    COALESCE(a.student_name, c.student_name) AS student_display,
                    c.case_number, c.student_grade, c.student_status,
                    col.code AS college_code, col.name AS college_name,
                    CONCAT(u.first_name,' ',u.last_name) AS counselor_name,
                    COALESCE(t.name, a.appointment_type) AS type_name
             FROM gm_appointments a
             LEFT JOIN gm_cases c ON a.case_id = c.id
             LEFT JOIN gm_colleges col ON c.college_id = col.id
             LEFT JOIN gm_users u ON a.counselor_id = u.id
             LEFT JOIN gm_appointment_types t ON a.appointment_type_id = t.id
             WHERE a.id = ?{$whereExtra}
             LIMIT 1"
        );
        $stmt->execute($q);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($appt)) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '<div class="p-4 text-sm text-gray-500">Session not found.</div>';
            return;
        }

        // Fetch latest counselor note for this case/appointment date
        $note = null;
        if (!empty($appt['case_id'])) {
            $noteStmt = $db->prepare(
                "SELECT note_content, observation_recommendation, session_type, risk_level, session_duration_minutes
                 FROM gm_counselor_notes
                 WHERE case_id = ? AND session_date = ?
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $noteStmt->execute([(int)$appt['case_id'], (string)($appt['scheduled_date'] ?? '')]);
            $note = $noteStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Fetch next appointment for same case
        $nextAppt = null;
        if (!empty($appt['case_id'])) {
            $nextStmt = $db->prepare(
                "SELECT scheduled_date, scheduled_time, COALESCE(t2.name, a2.appointment_type) AS type_name
                 FROM gm_appointments a2
                 LEFT JOIN gm_appointment_types t2 ON a2.appointment_type_id = t2.id
                 WHERE a2.case_id = ? AND a2.id != ?
                   AND a2.scheduled_date >= CURDATE()
                   AND a2.status NOT IN ('cancelled','rejected')
                 ORDER BY a2.scheduled_date ASC, a2.scheduled_time ASC
                 LIMIT 1"
            );
            $nextStmt->execute([(int)$appt['case_id'], $apptId]);
            $nextAppt = $nextStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo guidanceRender('modules/guidance/partials/session-detail-panel.disyl', [
            'appt'     => $appt,
            'note'     => $note,
            'next_appt' => $nextAppt,
            'base_url' => '/admin/guidance',
        ]);

    } catch (Throwable $e) {
        app()->log('Session detail error: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="p-4 text-sm text-red-500">Failed to load session details.</div>';
    }
}

function apiGuidanceCasePanel(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);
    if ($caseId < 1) { http_response_code(404); echo ''; return; }

    $db = guidanceDb();

    $whereExtra = '';
    $q          = [$caseId];
    if ($role === 'counselor') {
        $whereExtra = ' AND c.counselor_id = ?';
        $q[]        = $userId;
    }

    $stmt = $db->prepare(
        "SELECT c.*, col.code AS college_code, col.name AS college_name,
                CONCAT(u.first_name,' ',u.last_name) AS counselor_name
         FROM gm_cases c
         LEFT JOIN gm_colleges col ON c.college_id = col.id
         LEFT JOIN gm_users u ON c.counselor_id = u.id
         WHERE c.id = ? AND c.deleted_at IS NULL{$whereExtra}
         LIMIT 1"
    );
    $stmt->execute($q);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($case)) {
        http_response_code(404);
        echo '<div class="p-4 text-sm text-gray-500">Student not found.</div>';
        return;
    }

    $notes = [];
    try {
        $nStmt = $db->prepare(
            "SELECT n.*, CONCAT(u.first_name,' ',u.last_name) AS counselor_name
             FROM gm_counselor_notes n
             LEFT JOIN gm_users u ON n.counselor_id = u.id
             WHERE n.case_id = ?
             ORDER BY n.session_date DESC, n.created_at DESC"
        );
        $nStmt->execute([$caseId]);
        $notes = $nStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // non-fatal — show panel without history
    }

    $pane = strtolower(trim((string)guidanceInput('pane', 'overview')));
    if (!in_array($pane, ['overview', 'create', 'view'], true)) {
        $pane = 'overview';
    }

    $selectedNoteId = max(0, (int)(guidanceInput('note_id', guidanceInput('noteId', 0))));
    $selectedNote = null;
    $selectedAttachments = [];
    if ($pane === 'view' && $selectedNoteId > 0) {
        $noteContext = guidanceBuildCaseNoteDetailContext($db, $caseId, $selectedNoteId);
        if (is_array($noteContext)) {
            $selectedNote = $noteContext['note'] ?? null;
            $selectedAttachments = $noteContext['attachments'] ?? [];
        }
        if (!is_array($selectedNote)) {
            $pane = 'overview';
            $selectedNoteId = 0;
        }
    }

    $latestNote = $notes[0] ?? null;
    if (is_array($latestNote)) {
        $latestRaw = trim((string)($latestNote['note_content'] ?? ''));
        $latestFirstLine = trim(explode("\n", $latestRaw)[0] ?? '');
        $latestNote['note_title'] = $latestFirstLine !== ''
            ? (mb_strlen($latestFirstLine) > 60 ? mb_substr($latestFirstLine, 0, 57) . '...' : $latestFirstLine)
            : 'Session note';
        $latestNote['note_preview'] = $latestRaw !== ''
            ? (mb_strlen($latestRaw) > 120 ? mb_substr($latestRaw, 0, 117) . '...' : $latestRaw)
            : '';
    }

    $createDefaultRiskLevel = match ((string)($case['severity'] ?? 'low')) {
        'critical' => 'critical',
        'high' => 'high',
        'medium' => 'moderate',
        'moderate' => 'moderate',
        default => 'low',
    };

    $panelBaseHref = '/admin/guidance/api/cases/' . $caseId . '/panel';

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/case-detail-panel.disyl', [
        'case' => $case,
        'notes' => $notes,
        'today' => date('Y-m-d'),
        'base_url' => '/admin/guidance',
        'panel_mode' => $pane,
        'selected_note' => $selectedNote,
        'selected_note_id' => $selectedNoteId,
        'selected_attachments' => $selectedAttachments,
        'latest_note' => $latestNote,
        'session_count' => count($notes),
        'create_default_risk_level' => $createDefaultRiskLevel,
        'panel_overview_href' => $panelBaseHref . '?pane=overview',
        'panel_create_href' => $panelBaseHref . '?pane=create',
        'panel_target' => '#case-detail-panel',
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Case alerts tab
// ─────────────────────────────────────────────────────────────────────────────

function guidanceCaseAlertMeta(array &$a): void
{
    $level = (string)($a['level'] ?? 'low');
    switch ($level) {
        case 'critical':
            $a['icon'] = 'fa-skull-crossbones';
            $a['icon_bg'] = 'bg-red-100 text-red-600';
            $a['badge_class'] = 'bg-red-100 text-red-700 border border-red-200';
            $a['badge_label'] = 'Critical';
            break;
        case 'high':
            $a['icon'] = 'fa-exclamation-triangle';
            $a['icon_bg'] = 'bg-red-50 text-red-500';
            $a['badge_class'] = 'bg-red-50 text-red-700 border border-red-200';
            $a['badge_label'] = 'High Risk';
            break;
        case 'moderate':
            $a['icon'] = 'fa-exclamation-circle';
            $a['icon_bg'] = 'bg-amber-50 text-amber-500';
            $a['badge_class'] = 'bg-amber-50 text-amber-700 border border-amber-200';
            $a['badge_label'] = 'Moderate';
            break;
        default:
            $a['icon'] = 'fa-info-circle';
            $a['icon_bg'] = 'bg-blue-50 text-blue-500';
            $a['badge_class'] = 'bg-blue-50 text-blue-700 border border-blue-200';
            $a['badge_label'] = 'Info';
    }
    if ($a['type'] === 'urgent_appointment') {
        $a['icon']    = 'fa-calendar-exclamation';
        $a['icon_bg'] = 'bg-red-50 text-red-500';
    } elseif ($a['type'] === 'followup') {
        $a['icon']        = 'fa-clock';
        $a['icon_bg']     = 'bg-amber-50 text-amber-500';
        $a['badge_class'] = 'bg-amber-50 text-amber-700 border border-amber-200';
        $a['badge_label'] = 'Follow-up';
    }
    $ts   = strtotime((string)($a['created_at'] ?? ''));
    $diff = $ts ? (time() - $ts) : 0;
    if ($diff < 60)         $a['time_ago'] = 'just now';
    elseif ($diff < 3600)   $a['time_ago'] = floor($diff / 60) . ' min ago';
    elseif ($diff < 86400)  $a['time_ago'] = floor($diff / 3600) . ' hr ago';
    elseif ($diff < 604800) $a['time_ago'] = date('M j \a\t g:i A', $ts);
    else                    $a['time_ago'] = date('M j, Y', $ts);
    $a['ts_formatted'] = $ts ? date('M j, Y', $ts) : '';
}

function apiGuidanceCaseAlerts(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);

    if ($caseId < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-sm text-red-600">Case not found</div>';
        return;
    }

    $db = guidanceDb();

    $caseStmt = $db->prepare('SELECT counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $caseStmt->execute([$caseId]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        http_response_code(404);
        echo '<div class="p-4 text-sm text-red-600">Case not found</div>';
        return;
    }
    if ($role === 'counselor' && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo '<div class="p-4 text-sm text-red-600">Access denied</div>';
        return;
    }

    $alerts = [];
    try {
        // 1. Risk-level notes (moderate / high / critical)
        $rStmt = $db->prepare(
            "SELECT n.id, n.risk_level, n.note_type, n.note_content, n.session_date, n.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS counselor_name
             FROM gm_counselor_notes n
             LEFT JOIN gm_users u ON n.counselor_id = u.id
             WHERE n.case_id = ? AND n.risk_level IN ('moderate','high','critical')
             ORDER BY FIELD(n.risk_level,'critical','high','moderate'), n.created_at DESC
             LIMIT 50"
        );
        $rStmt->execute([$caseId]);
        foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $note) {
            $levelMap = ['critical' => 'critical', 'high' => 'high', 'moderate' => 'moderate'];
            $alerts[] = [
                'type'        => 'risk_note',
                'level'       => $levelMap[$note['risk_level']] ?? 'moderate',
                'title'       => ucfirst($note['risk_level']) . ' Risk — ' . ucfirst(str_replace('_', ' ', $note['note_type'])) . ' Note',
                'description' => $note['note_content'] ? mb_strimwidth($note['note_content'], 0, 180, '…') : '',
                'actor_name'  => trim((string)($note['counselor_name'] ?? '')),
                'created_at'  => $note['created_at'],
                'link'        => null,
            ];
        }

        // 2. Urgent appointments
        $uStmt = $db->prepare(
            "SELECT a.id, a.scheduled_date, a.scheduled_time, a.status, a.purpose, a.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS counselor_name
             FROM gm_appointments a
             LEFT JOIN gm_users u ON a.counselor_id = u.id
             WHERE a.case_id = ? AND a.is_urgent = 1
             ORDER BY a.scheduled_date DESC
             LIMIT 20"
        );
        $uStmt->execute([$caseId]);
        foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $appt) {
            $alerts[] = [
                'type'        => 'urgent_appointment',
                'level'       => 'high',
                'title'       => 'Urgent Appointment — ' . date('M j, Y', strtotime($appt['scheduled_date'])),
                'description' => $appt['purpose'] ? mb_strimwidth($appt['purpose'], 0, 180, '…') : 'No purpose specified',
                'actor_name'  => trim((string)($appt['counselor_name'] ?? '')),
                'created_at'  => $appt['scheduled_date'] . ' ' . $appt['scheduled_time'],
                'link'        => '/pages/appointments/' . $appt['id'],
            ];
        }

        // 3. Follow-up required notes
        $fStmt = $db->prepare(
            "SELECT n.id, n.followup_notes, n.session_date, n.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS counselor_name
             FROM gm_counselor_notes n
             LEFT JOIN gm_users u ON n.counselor_id = u.id
             WHERE n.case_id = ? AND n.followup_required = 1
             ORDER BY n.created_at DESC
             LIMIT 30"
        );
        $fStmt->execute([$caseId]);
        foreach ($fStmt->fetchAll(PDO::FETCH_ASSOC) as $note) {
            $sessLabel = $note['session_date'] ? ' (session ' . date('M j, Y', strtotime($note['session_date'])) . ')' : '';
            $alerts[] = [
                'type'        => 'followup',
                'level'       => 'moderate',
                'title'       => 'Follow-up Required' . $sessLabel,
                'description' => $note['followup_notes'] ? mb_strimwidth($note['followup_notes'], 0, 180, '…') : 'Follow-up was flagged on this session.',
                'actor_name'  => trim((string)($note['counselor_name'] ?? '')),
                'created_at'  => $note['created_at'],
                'link'        => null,
            ];
        }
    } catch (Throwable $e) {
        app()->log('Case alerts error: ' . $e->getMessage(), 'error');
    }

    // Sort: critical/high first, then by date desc
    usort($alerts, function ($a, $b) {
        $order = ['critical' => 0, 'high' => 1, 'moderate' => 2, 'low' => 3];
        $la = $order[$a['level']] ?? 9;
        $lb = $order[$b['level']] ?? 9;
        if ($la !== $lb) return $la - $lb;
        return strcmp($b['created_at'], $a['created_at']);
    });

    foreach ($alerts as &$alert) {
        guidanceCaseAlertMeta($alert);
    }
    unset($alert);

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/case-alerts-tab.disyl', [
        'alerts'  => $alerts,
        'case_id' => $caseId,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Case activity log tab
// ─────────────────────────────────────────────────────────────────────────────

function guidanceActivityEventMeta(array &$e): void
{
    switch ($e['event_type']) {
        case 'status_change':
            $e['icon'] = 'fa-arrows-rotate';
            $e['icon_bg'] = 'bg-indigo-50 text-indigo-500';
            $e['badge_label'] = 'Status';
            $e['badge_class'] = 'bg-indigo-50 text-indigo-700 border border-indigo-200';
            break;
        case 'note_added':
            $e['icon'] = 'fa-sticky-note';
            $e['icon_bg'] = 'bg-teal-50 text-teal-600';
            $e['badge_label'] = 'Note';
            $e['badge_class'] = 'bg-teal-50 text-teal-700 border border-teal-200';
            break;
        case 'appointment':
            $e['icon'] = 'fa-calendar-check';
            $e['icon_bg'] = 'bg-blue-50 text-blue-500';
            $e['badge_label'] = 'Appointment';
            $e['badge_class'] = 'bg-blue-50 text-blue-700 border border-blue-200';
            break;
        case 'document':
            $e['icon'] = 'fa-file-arrow-up';
            $e['icon_bg'] = 'bg-purple-50 text-purple-500';
            $e['badge_label'] = 'Document';
            $e['badge_class'] = 'bg-purple-50 text-purple-700 border border-purple-200';
            break;
        case 'case_audit':
            $e['icon'] = 'fa-shield-halved';
            $e['icon_bg'] = 'bg-gray-100 text-gray-500';
            $e['badge_label'] = 'Audit';
            $e['badge_class'] = 'bg-gray-100 text-gray-600 border border-gray-200';
            break;
        default:
            $e['icon'] = 'fa-circle-dot';
            $e['icon_bg'] = 'bg-gray-100 text-gray-400';
            $e['badge_label'] = 'Event';
            $e['badge_class'] = 'bg-gray-100 text-gray-600 border border-gray-200';
    }
    $ts   = strtotime((string)($e['ts'] ?? ''));
    $diff = $ts ? (time() - $ts) : 0;
    if ($diff < 60)         $e['time_ago'] = 'just now';
    elseif ($diff < 3600)   $e['time_ago'] = floor($diff / 60) . ' min ago';
    elseif ($diff < 86400)  $e['time_ago'] = floor($diff / 3600) . ' hr ago';
    elseif ($diff < 604800) $e['time_ago'] = date('M j \a\t g:i A', $ts);
    else                    $e['time_ago'] = date('M j, Y', $ts);
    $e['ts_formatted'] = $ts ? date('M j, Y \a\t g:i A', $ts) : '';
    $e['ts_date']      = $ts ? date('Y-m-d', $ts) : '';
}

function apiGuidanceCaseActivityLog(array $params = []): void
{
    $user   = guidanceRequireStaff(['admin', 'supervisor', 'counselor']);
    $role   = (string)($user['role'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $caseId = (int)($params['id'] ?? 0);

    if ($caseId < 1) {
        http_response_code(404);
        echo '<div class="p-4 text-sm text-red-600">Case not found</div>';
        return;
    }

    $db = guidanceDb();

    $caseStmt = $db->prepare('SELECT counselor_id, student_name FROM gm_cases WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $caseStmt->execute([$caseId]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($case)) {
        http_response_code(404);
        echo '<div class="p-4 text-sm text-red-600">Case not found</div>';
        return;
    }
    if ($role === 'counselor' && (int)($case['counselor_id'] ?? 0) !== $userId) {
        http_response_code(403);
        echo '<div class="p-4 text-sm text-red-600">Access denied</div>';
        return;
    }

    $events = [];
    try {
        // 1. Status changes
        $stmt = $db->prepare(
            "SELECT h.id, h.new_status, h.previous_status, h.notes, h.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS actor_name
             FROM gm_case_status_history h
             LEFT JOIN gm_users u ON h.changed_by = u.id
             WHERE h.case_id = ?
             ORDER BY h.created_at DESC LIMIT 100"
        );
        $stmt->execute([$caseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $prev = $row['previous_status'] ? ' (from ' . ucfirst($row['previous_status']) . ')' : '';
            $events[] = [
                'event_type' => 'status_change',
                'title'      => 'Status changed to ' . ucfirst((string)($row['new_status'] ?? '')),
                'detail'     => $row['notes'] ? mb_strimwidth($row['notes'], 0, 200, '…') : $prev,
                'actor_name' => trim((string)($row['actor_name'] ?? 'System')),
                'ts'         => $row['created_at'],
                'link'       => null,
            ];
        }

        // 2. Counselor notes
        $stmt = $db->prepare(
            "SELECT n.id, n.note_type, n.session_date, n.risk_level, n.note_content,
                    n.session_duration_minutes, n.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS actor_name
             FROM gm_counselor_notes n
             LEFT JOIN gm_users u ON n.created_by = u.id
             WHERE n.case_id = ?
             ORDER BY n.created_at DESC LIMIT 100"
        );
        $stmt->execute([$caseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $typeLabel = ucfirst(str_replace('_', ' ', (string)($row['note_type'] ?? 'session')));
            $dur   = (int)($row['session_duration_minutes'] ?? 0);
            $parts = [];
            if ($row['session_date']) {
                $parts[] = 'Session: ' . date('M j, Y', strtotime($row['session_date']));
            }
            if ($dur > 0) {
                $parts[] = $dur . ' min';
            }
            if (!empty($row['risk_level']) && $row['risk_level'] !== 'none') {
                $parts[] = ucfirst($row['risk_level']) . ' risk';
            }
            $events[] = [
                'event_type' => 'note_added',
                'title'      => $typeLabel . ' note added',
                'detail'     => $parts ? implode(' · ', $parts) : mb_strimwidth((string)($row['note_content'] ?? ''), 0, 120, '…'),
                'actor_name' => trim((string)($row['actor_name'] ?? '')),
                'ts'         => $row['created_at'],
                'link'       => '/pages/cases/' . $caseId . '/notes/' . $row['id'],
            ];
        }

        // 3. Appointments
        $stmt = $db->prepare(
            "SELECT a.id, a.status, a.appointment_type, a.scheduled_date, a.scheduled_time,
                    a.purpose, a.created_at, a.is_urgent,
                    CONCAT(u.first_name, ' ', u.last_name) AS actor_name
             FROM gm_appointments a
             LEFT JOIN gm_users u ON a.created_by = u.id
             WHERE a.case_id = ?
             ORDER BY a.created_at DESC LIMIT 100"
        );
        $stmt->execute([$caseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $apptType = ucfirst(str_replace('_', ' ', (string)($row['appointment_type'] ?? '')));
            $scheduled = $row['scheduled_date']
                ? date('M j, Y', strtotime($row['scheduled_date'])) . ' at ' . date('g:i A', strtotime($row['scheduled_time'] ?? '00:00'))
                : '';
            $parts = array_filter([$apptType, $scheduled, $row['is_urgent'] ? 'Urgent' : null]);
            $events[] = [
                'event_type' => 'appointment',
                'title'      => 'Appointment ' . ucfirst((string)($row['status'] ?? '')),
                'detail'     => implode(' · ', $parts),
                'actor_name' => trim((string)($row['actor_name'] ?? '')),
                'ts'         => $row['created_at'],
                'link'       => '/pages/appointments/' . $row['id'],
            ];
        }

        // 4. Document uploads
        $stmt = $db->prepare(
            "SELECT a.id, a.file_name, a.file_type, a.file_size, a.file_category,
                    a.description, a.uploaded_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS actor_name
             FROM gm_attachments a
             LEFT JOIN gm_users u ON a.uploaded_by = u.id
             WHERE a.case_id = ? AND a.deleted_at IS NULL
             ORDER BY a.uploaded_at DESC LIMIT 100"
        );
        $stmt->execute([$caseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sizeStr = '';
            $sz = (int)($row['file_size'] ?? 0);
            if ($sz > 0) {
                $sizeStr = $sz > 1048576
                    ? number_format($sz / 1048576, 1) . ' MB'
                    : number_format($sz / 1024, 1) . ' KB';
            }
            $catLabel = ucfirst(str_replace('_', ' ', (string)($row['file_category'] ?? 'other')));
            $parts = array_filter([$catLabel !== 'Other' ? $catLabel : null, $sizeStr]);
            $events[] = [
                'event_type' => 'document',
                'title'      => 'Document uploaded: ' . $row['file_name'],
                'detail'     => $row['description'] ?: implode(' · ', $parts),
                'actor_name' => trim((string)($row['actor_name'] ?? '')),
                'ts'         => $row['uploaded_at'],
                'link'       => null,
            ];
        }

        // 5. Audit log (case-level actions)
        $stmt = $db->prepare(
            "SELECT al.id, al.action, al.new_data, al.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS actor_name
             FROM gm_audit_logs al
             LEFT JOIN gm_users u ON al.user_id = u.id
             WHERE al.table_name = 'gm_cases' AND al.record_id = ?
             ORDER BY al.created_at DESC LIMIT 100"
        );
        $stmt->execute([$caseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $actionLabel = ucwords(str_replace(['.', '_'], [': ', ' '], (string)($row['action'] ?? '')));
            $events[] = [
                'event_type' => 'case_audit',
                'title'      => $actionLabel,
                'detail'     => '',
                'actor_name' => trim((string)($row['actor_name'] ?? 'System')),
                'ts'         => $row['created_at'],
                'link'       => null,
            ];
        }
    } catch (Throwable $e) {
        app()->log('Case activity log error: ' . $e->getMessage(), 'error');
    }

    // Sort all events newest first
    usort($events, fn ($a, $b) => strcmp($b['ts'], $a['ts']));

    foreach ($events as &$event) {
        guidanceActivityEventMeta($event);
    }
    unset($event);

    header('Content-Type: text/html; charset=utf-8');
    echo guidanceRender('modules/guidance/partials/case-activity-log-tab.disyl', [
        'events'  => $events,
        'case_id' => $caseId,
    ]);
}
