<?php
/**
 * Appointments Route Handlers
 * 
 * @package Guidance\Routes
 */

function slugifyAppointmentTypeCode(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: 'session';
}

function buildAppointmentTypeCode(PDO $db, string $name, ?string $requestedCode = null, ?int $excludeId = null): string
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
    if (app()->isHtmx()) {
        http_response_code($status);
        header('HX-Reswap: none');
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $message, 'type' => 'error']]));
        echo '';
        exit;
    }

    app()->json(['error' => $message], $status);
}

function apiGuidanceListAppointmentTypes(): void
{
    $user = guidanceUser();
    guidanceRequireStaff();
    $db = guidanceDb();
    $context = $_GET['context'] ?? '';
    $includeInactive = !empty($_GET['include_inactive']) && ($user['role'] ?? '') === 'admin';

    $sql = 'SELECT * FROM gm_appointment_types';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, name';

    $types = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (app()->isHtmx() && $context === 'settings') {
        if (($user['role'] ?? '') !== 'admin') {
            appointmentTypeError('Access denied', 403);
        }

        echo app()->render('partials/appointment-types-settings.disyl', [
            'appointment_types' => $types,
        ]);
        return;
    }

    app()->json(['success' => true, 'data' => $types]);
}

function apiGuidanceCreateAppointmentType(): void
{
    app()->requireRole('admin');
    $db = guidanceDb();
    $input = app()->input();

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

    $code = buildAppointmentTypeCode($db, $name, $input['code'] ?? null);

    try {
        $stmt = $db->prepare('
            INSERT INTO gm_appointment_types (
                code, name, description, duration_minutes, color,
                requires_case, is_public, is_active, sort_order, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ');
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

    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Session type added', 'type' => 'success'],
            'refreshAppointmentTypes' => true,
        ]));
        echo '';
        return;
    }

    app()->json(['success' => true], 201);
}

function apiGuidanceUpdateAppointmentType(string $id): void
{
    app()->requireRole('admin');
    $db = guidanceDb();
    $input = app()->input();

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
        : (int) $existing['duration_minutes'];
    $sortOrder = array_key_exists('sort_order', $input)
        ? max(0, (int) $input['sort_order'])
        : (int) $existing['sort_order'];
    $color = trim((string) ($input['color'] ?? $existing['color']));
    $requiresCase = array_key_exists('requires_case', $input)
        ? (!empty($input['requires_case']) ? 1 : 0)
        : (int) $existing['requires_case'];
    $isPublic = array_key_exists('is_public', $input)
        ? (!empty($input['is_public']) ? 1 : 0)
        : (int) $existing['is_public'];
    $isActive = array_key_exists('is_active', $input)
        ? (!empty($input['is_active']) ? 1 : 0)
        : (int) $existing['is_active'];

    if ($name === '') {
        appointmentTypeError('Session type name is required', 400);
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        appointmentTypeError('Color must be a valid hex value', 400);
    }

    $code = buildAppointmentTypeCode($db, $name, $input['code'] ?? $existing['code'], (int) $id);

    try {
        $updateStmt = $db->prepare('
            UPDATE gm_appointment_types
            SET code = ?, name = ?, description = ?, duration_minutes = ?, color = ?,
                requires_case = ?, is_public = ?, is_active = ?, sort_order = ?, updated_at = NOW()
            WHERE id = ?
        ');
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

    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Session type updated', 'type' => 'success'],
            'refreshAppointmentTypes' => true,
        ]));
        echo '';
        return;
    }

    app()->json(['success' => true]);
}

function apiGuidanceDeleteAppointmentType(string $id): void
{
    app()->requireRole('admin');
    $db = guidanceDb();

    $usageStmt = $db->prepare('SELECT COUNT(*) FROM gm_appointments WHERE appointment_type_id = ?');
    $usageStmt->execute([$id]);
    if ((int) $usageStmt->fetchColumn() > 0) {
        appointmentTypeError('This session type is already in use. Deactivate it instead.', 409);
    }

    $stmt = $db->prepare('DELETE FROM gm_appointment_types WHERE id = ?');
    $stmt->execute([$id]);

    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode([
            'showToast' => ['message' => 'Session type deleted', 'type' => 'success'],
            'refreshAppointmentTypes' => true,
        ]));
        echo '';
        return;
    }

    app()->json(['success' => true]);
}

function apiGuidanceListAppointments(): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $db = guidanceDb();
    
    try {
        $where = ["1=1"];
        $params = [];
        
        // Role-based filtering
        if ($user['role'] === 'counselor') {
            $where[] = "a.counselor_id = ?";
            $params[] = $user['sub'];
        } elseif (!empty($_GET['counselor_id'])) {
            $where[] = "a.counselor_id = ?";
            $params[] = (int) $_GET['counselor_id'];
        }
        
        if (!empty($_GET['from'])) {
            $where[] = "a.scheduled_date >= ?";
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[] = "a.scheduled_date <= ?";
            $params[] = $_GET['to'];
        }
        if (!empty($_GET['status'])) {
            $where[] = "a.status = ?";
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['search'])) {
            $where[] = "(COALESCE(a.student_name, c.student_name) LIKE ? OR c.case_number LIKE ?)";
            $search = '%' . $_GET['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }
        
        $whereClause = implode(' AND ', $where);
        
        $stmt = $db->prepare("
            SELECT a.*, LOWER(TRIM(a.status)) AS status_key,
                   c.case_number, COALESCE(a.student_name, c.student_name) AS student_name,
                   u.first_name AS counselor_first, u.last_name AS counselor_last,
                   COALESCE(t.name, a.appointment_type) AS type_name
            FROM gm_appointments a
            LEFT JOIN gm_cases c ON a.case_id = c.id
            LEFT JOIN gm_users u ON a.counselor_id = u.id
            LEFT JOIN gm_appointment_types t ON a.appointment_type_id = t.id
            WHERE {$whereClause}
            ORDER BY a.scheduled_date ASC, a.scheduled_time ASC
        ");
        $stmt->execute($params);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Normalize status_key and add counselor_name
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        foreach ($appointments as &$appt) {
            $appt['status_key'] = strtolower(trim($appt['status_key'] ?? $appt['status'] ?? ''));
            $appt['counselor_name'] = trim(($appt['counselor_first'] ?? '') . ' ' . ($appt['counselor_last'] ?? ''));
            // Relative date label
            if ($appt['scheduled_date'] === $today) {
                $appt['date_label'] = 'Today';
            } elseif ($appt['scheduled_date'] === $tomorrow) {
                $appt['date_label'] = 'Tomorrow';
            } elseif ($appt['scheduled_date'] === $yesterday) {
                $appt['date_label'] = 'Yesterday';
            } else {
                $appt['date_label'] = date('l, M j, Y', strtotime($appt['scheduled_date']));
            }
        }
        unset($appt);
        
        // Build flat rows: interleave date_header rows with appointment rows
        // This avoids nested {each} which the template engine can't handle
        $rows = [];
        $lastDate = null;
        $dateCounts = array_count_values(array_column($appointments, 'scheduled_date'));
        foreach ($appointments as $appt) {
            $dateKey = $appt['scheduled_date'];
            if ($dateKey !== $lastDate) {
                $rows[] = [
                    'row_type' => 'date_header',
                    'date' => $dateKey,
                    'label' => $appt['date_label'],
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
        
        // Summary stats (unfiltered by status/search, but respecting role + date range + counselor)
        $statWhere = ["1=1"];
        $statParams = [];
        if ($user['role'] === 'counselor') {
            $statWhere[] = "a.counselor_id = ?";
            $statParams[] = $user['sub'];
        } elseif (!empty($_GET['counselor_id'])) {
            $statWhere[] = "a.counselor_id = ?";
            $statParams[] = (int) $_GET['counselor_id'];
        }
        $statWhereStr = implode(' AND ', $statWhere);
        
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekEnd = date('Y-m-d', strtotime('sunday this week'));
        
        $statsStmt = $db->prepare("SELECT
            SUM(CASE WHEN a.scheduled_date = ? AND a.status NOT IN ('cancelled','rejected') THEN 1 ELSE 0 END) AS today_count,
            SUM(CASE WHEN a.scheduled_date BETWEEN ? AND ? AND a.status NOT IN ('cancelled','rejected') THEN 1 ELSE 0 END) AS week_count,
            SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN a.status IN ('confirmed','scheduled') AND a.scheduled_date >= ? THEN 1 ELSE 0 END) AS upcoming_count
            FROM gm_appointments a WHERE {$statWhereStr}");
        $statsStmt->execute(array_merge([$today, $weekStart, $weekEnd, $today], $statParams));
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        $stats = array_map('intval', $stats);
        
        if (app()->isHtmx()) {
            echo app()->render('partials/appointments-list.disyl', [
                'appointments' => $appointments,
                'rows' => $rows,
                'stats' => $stats,
                'total' => count($appointments),
            ]);
            return;
        }
        app()->json(['success' => true, 'data' => $appointments, 'stats' => $stats]);
    } catch (Exception $e) {
        app()->log('Appointments list error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to fetch appointments'], 500);
    }
}

function apiGuidanceCreateAppointment(): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $input = app()->input();
    $db = guidanceDb();
    
    if (empty($input['scheduled_date']) || empty($input['scheduled_time'])) {
        app()->json(['error' => 'Date and time are required'], 400);
    }
    
    try {
        $counselorId = $input['counselor_id'] ?? $user['sub'];
        $appointmentTypeId = !empty($input['appointment_type_id']) ? (int) $input['appointment_type_id'] : null;
        $duration = !empty($input['duration_minutes']) ? (int) $input['duration_minutes'] : 30;

        if ($appointmentTypeId) {
            $typeStmt = $db->prepare('SELECT duration_minutes FROM gm_appointment_types WHERE id = ? AND is_active = 1 LIMIT 1');
            $typeStmt->execute([$appointmentTypeId]);
            $typeDuration = $typeStmt->fetchColumn();
            if ($typeDuration) {
                $duration = (int) $typeDuration;
            }
        }
        
        // Check for conflicts (also check 'confirmed' status, not just 'scheduled')
        $conflictStmt = $db->prepare("
            SELECT id FROM gm_appointments 
            WHERE counselor_id = ? AND scheduled_date = ? AND status IN ('scheduled', 'confirmed')
            AND (
                (scheduled_time <= ? AND ADDTIME(scheduled_time, SEC_TO_TIME(duration_minutes * 60)) > ?)
                OR (scheduled_time < ADDTIME(?, SEC_TO_TIME(? * 60)) AND scheduled_time >= ?)
            )
        ");
        $conflictStmt->execute([
            $counselorId, $input['scheduled_date'],
            $input['scheduled_time'], $input['scheduled_time'],
            $input['scheduled_time'], $duration, $input['scheduled_time']
        ]);
        
        if ($conflictStmt->fetch()) {
            app()->json(['error' => 'Time slot conflicts with existing appointment'], 409);
        }
        
        $stmt = $db->prepare("
            INSERT INTO gm_appointments (
                case_id, student_id, counselor_id, scheduled_date, scheduled_time,
                duration_minutes, appointment_type, appointment_type_id, purpose, location, status,
                notes, sync_id, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $input['case_id'] ?? null,
            $input['student_id'] ?? null,
            $counselorId,
            $input['scheduled_date'],
            $input['scheduled_time'],
            $duration,
            $input['appointment_type'] ?? 'individual',
            $appointmentTypeId,
            $input['purpose'] ?? null,
            $input['location'] ?? 'Guidance Office',
            $input['notes'] ?? null,
            $input['sync_id'] ?? uniqid('sync_', true),
            $user['sub'],
        ]);
        $apptId = $db->lastInsertId();
        
        // If linked to a case, update the case's updated_at to reflect activity
        if (!empty($input['case_id'])) {
            $db->prepare("UPDATE gm_cases SET updated_at = NOW() WHERE id = ?")->execute([$input['case_id']]);
        }
        
        fireModuleHook('appointment.created', [
            'appointment_id' => $apptId,
            'counselor_id' => $counselorId,
            'student_name' => $input['student_name'] ?? '',
            'scheduled_date' => date('F j, Y', strtotime($input['scheduled_date'])),
            'scheduled_time' => date('g:i A', strtotime($input['scheduled_time'])),
            'purpose' => $input['purpose'] ?? '',
            'case_id' => $input['case_id'] ?? null,
            'created_by' => $user['sub'],
        ]);
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment scheduled successfully', 'type' => 'success'], 'closeModal' => true]));
            header('HX-Refresh: true');
            echo '';
            return;
        }
        app()->json(['success' => true, 'data' => ['id' => $apptId]], 201);
    } catch (Exception $e) {
        app()->log('Appointments create error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to create appointment'], 500);
    }
}

function apiGuidanceUpdateAppointment(string $id): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $input = app()->input();
    $db = guidanceDb();
    
    try {
        $allowed = ['scheduled_date', 'scheduled_time', 'duration_minutes', 'appointment_type', 'appointment_type_id',
                    'purpose', 'location', 'notes', 'status'];
        $updates = [];
        $values = [];
        
        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $updates[] = "{$field} = ?";
                $values[] = $input[$field];
            }
        }
        
        if (empty($updates)) {
            app()->json(['error' => 'No valid fields to update'], 400);
        }
        
        $updates[] = "last_modified_by = ?";
        $values[] = $user['sub'];
        $updates[] = "updated_at = NOW()";
        $values[] = $id;
        
        $stmt = $db->prepare("UPDATE gm_appointments SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($values);
        
        if ($stmt->rowCount() === 0) {
            app()->json(['error' => 'Appointment not found'], 404);
        }
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment updated', 'type' => 'success'], 'closeModal' => true]));
            header('HX-Refresh: true');
            echo '';
            return;
        }
        app()->json(['success' => true, 'message' => 'Appointment updated']);
    } catch (Exception $e) {
        app()->log('Appointments update error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to update appointment'], 500);
    }
}

function apiGuidanceCancelAppointment(string $id): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $input = app()->input();
    $db = guidanceDb();
    
    try {
        $stmt = $db->prepare("
            UPDATE gm_appointments 
            SET status = 'cancelled', cancellation_reason = ?, cancelled_at = NOW(),
                last_modified_by = ?, updated_at = NOW()
            WHERE id = ? AND status IN ('scheduled', 'confirmed', 'pending')
        ");
        $stmt->execute([$input['reason'] ?? 'Cancelled', $user['sub'], $id]);
        
        if ($stmt->rowCount() === 0) {
            app()->json(['error' => 'Appointment not found or already cancelled'], 400);
        }
        
        // Fetch appointment data for hook
        $apptStmt = $db->prepare("SELECT * FROM gm_appointments WHERE id = ?");
        $apptStmt->execute([$id]);
        $appt = $apptStmt->fetch(\PDO::FETCH_ASSOC);
        if ($appt) {
            fireModuleHook('appointment.cancelled', [
                'appointment_id' => $id,
                'counselor_id' => $appt['counselor_id'],
                'student_name' => $appt['student_name'] ?? '',
                'student_phone' => $appt['student_mobile'] ?? $appt['student_phone'] ?? '',
                'student_email' => $appt['student_email'] ?? '',
                'scheduled_date' => date('F j, Y', strtotime($appt['scheduled_date'])),
                'scheduled_time' => date('g:i A', strtotime($appt['scheduled_time'])),
                'reason' => $input['reason'] ?? 'Cancelled',
                'cancelled_by' => $user['sub'],
            ]);
        }
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment cancelled', 'type' => 'success'], 'refreshAppointments' => true]));
            handleListAppointments();
            return;
        }
        app()->json(['success' => true, 'message' => 'Appointment cancelled']);
    } catch (Exception $e) {
        app()->log('Appointments cancel error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to cancel appointment'], 500);
    }
}

function apiGuidanceCompleteAppointment(string $id): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $db = guidanceDb();
    
    try {
        $stmt = $db->prepare("
            UPDATE gm_appointments 
            SET status = 'completed', last_modified_by = ?, updated_at = NOW()
            WHERE id = ? AND status IN ('scheduled', 'confirmed')
        ");
        $stmt->execute([$user['sub'], $id]);
        
        if ($stmt->rowCount() === 0) {
            app()->json(['error' => 'Appointment not found or already completed'], 400);
        }
        
        $apptStmt = $db->prepare("SELECT * FROM gm_appointments WHERE id = ?");
        $apptStmt->execute([$id]);
        $appt = $apptStmt->fetch(\PDO::FETCH_ASSOC);
        if ($appt) {
            fireModuleHook('appointment.completed', [
                'appointment_id' => $id,
                'counselor_id' => $appt['counselor_id'],
                'student_name' => $appt['student_name'] ?? '',
                'scheduled_date' => date('F j, Y', strtotime($appt['scheduled_date'])),
                'scheduled_time' => date('g:i A', strtotime($appt['scheduled_time'])),
                'completed_by' => $user['sub'],
            ]);
        }
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment marked as completed', 'type' => 'success'], 'refreshAppointments' => true]));
            handleListAppointments();
            return;
        }
        app()->json(['success' => true, 'message' => 'Appointment completed']);
    } catch (Exception $e) {
        app()->log('Appointments complete error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to complete appointment'], 500);
    }
}

function apiGuidanceNoShowAppointment(string $id): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $db = guidanceDb();
    
    try {
        $stmt = $db->prepare("
            UPDATE gm_appointments 
            SET status = 'no_show', last_modified_by = ?, updated_at = NOW()
            WHERE id = ? AND status IN ('scheduled', 'confirmed')
        ");
        $stmt->execute([$user['sub'], $id]);
        
        if ($stmt->rowCount() === 0) {
            app()->json(['error' => 'Appointment not found or already processed'], 400);
        }
        
        $apptStmt = $db->prepare("SELECT * FROM gm_appointments WHERE id = ?");
        $apptStmt->execute([$id]);
        $appt = $apptStmt->fetch(\PDO::FETCH_ASSOC);
        if ($appt) {
            fireModuleHook('appointment.noshow', [
                'appointment_id' => $id,
                'counselor_id' => $appt['counselor_id'],
                'student_name' => $appt['student_name'] ?? '',
                'student_phone' => $appt['student_mobile'] ?? $appt['student_phone'] ?? '',
                'student_email' => $appt['student_email'] ?? '',
                'scheduled_date' => date('F j, Y', strtotime($appt['scheduled_date'])),
                'scheduled_time' => date('g:i A', strtotime($appt['scheduled_time'])),
                'marked_by' => $user['sub'],
            ]);
        }
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment marked as no-show', 'type' => 'warning'], 'refreshAppointments' => true]));
            handleListAppointments();
            return;
        }
        app()->json(['success' => true, 'message' => 'Appointment marked as no-show']);
    } catch (Exception $e) {
        app()->log('Appointments no-show error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to mark no-show'], 500);
    }
}

function apiGuidanceGetSlots(): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $db = guidanceDb();
    require_once __DIR__ . '/../helpers/availability.php';
    
    $date = $_GET['date'] ?? date('Y-m-d');
    $requestedCounselorId = !empty($_GET['counselor_id']) ? (int) $_GET['counselor_id'] : null;
    $counselorId = $requestedCounselorId;
    if ($counselorId === null) {
        $counselorId = in_array($user['role'] ?? '', ['counselor', 'supervisor'], true)
            ? (int) $user['sub']
            : 0;
    }
    $duration = (int) ($_GET['duration'] ?? 30);
    
    try {
        if ($counselorId <= 0) {
            app()->json(['error' => 'Select a counselor first'], 400);
        }

        // Get booked slots (include confirmed + pending, not just scheduled)
        $stmt = $db->prepare("
            SELECT scheduled_time, duration_minutes 
            FROM gm_appointments 
            WHERE counselor_id = ? AND scheduled_date = ? AND status IN ('scheduled', 'confirmed', 'pending')
            ORDER BY scheduled_time
        ");
        $stmt->execute([$counselorId, $date]);
        $booked = $stmt->fetchAll();
        
        $dayHours = getCounselorAvailabilityForDate($db, $counselorId, $date);
        if (!$dayHours) {
            app()->json(['success' => true, 'data' => []]);
            return;
        }
        
        $slotsByTime = [];
        $slotSeconds = $duration * 60;

        foreach (($dayHours['ranges'] ?? []) as $range) {
            $workStart = strtotime($date . ' ' . $range['start']);
            $workEnd = strtotime($date . ' ' . $range['end']);

            for ($time = $workStart; $time + $slotSeconds <= $workEnd; $time += $slotSeconds) {
                $slotTime = date('H:i:s', $time);
                $slotEnd = $time + $slotSeconds;
                $available = true;
                
                foreach ($booked as $appt) {
                    $apptStart = strtotime($date . ' ' . $appt['scheduled_time']);
                    $apptEnd = $apptStart + ($appt['duration_minutes'] * 60);
                    if ($time < $apptEnd && $slotEnd > $apptStart) {
                        $available = false;
                        break;
                    }
                }

                if ($available) {
                    $slotsByTime[$slotTime] = ['time' => $slotTime, 'display' => date('g:i A', $time)];
                }
            }
        }

        ksort($slotsByTime);
        $slots = array_values($slotsByTime);
        
        app()->json(['success' => true, 'data' => $slots]);
    } catch (Exception $e) {
        app()->log('Appointments slots error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to get slots'], 500);
    }
}

/**
 * Approve a pending appointment — auto-creates a case if none linked
 */
function apiGuidanceApproveAppointment(int $id): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $db = guidanceDb();
    
    try {
        $stmt = $db->prepare("SELECT * FROM gm_appointments WHERE id = ?");
        $stmt->execute([$id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            app()->json(['error' => 'Appointment not found'], 404);
        }
        if ($appointment['status'] !== 'pending') {
            app()->json(['error' => 'Only pending appointments can be approved'], 400);
        }
        if ($user['role'] === 'counselor' && $appointment['counselor_id'] != $user['sub']) {
            app()->json(['error' => 'You can only approve your own appointments'], 403);
        }
        
        $db->beginTransaction();

        // Auto-create case if booking has no linked case
        $caseId = $appointment['case_id'] ?? null;
        if (!$caseId) {
            $year = date('Y');
            $prefix = "GC-{$year}-";
            $lastStmt = $db->prepare("SELECT case_number FROM gm_cases WHERE case_number LIKE ? ORDER BY id DESC LIMIT 1");
            $lastStmt->execute([$prefix . '%']);
            $last = $lastStmt->fetchColumn();
            $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;
            $caseNumber = sprintf('%s%04d', $prefix, $seq);

            $db->prepare("
                INSERT INTO gm_cases (
                    case_number, student_id, student_name, student_grade, student_section,
                    college_id, counselor_id, category, severity,
                    presenting_issue, is_urgent, is_confidential, sync_id, version,
                    created_by, last_modified_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'general', 'medium', ?, ?, 1, ?, 1, ?, ?, NOW(), NOW())
            ")->execute([
                $caseNumber,
                $appointment['student_id'] ?: 'N/A',
                $appointment['student_name'] ?: 'Unknown',
                $appointment['student_year_level'] ?? null,
                null,
                $appointment['student_college_id'] ?? null,
                $appointment['counselor_id'],
                $appointment['purpose'] ?: 'Appointment booking',
                (int) ($appointment['is_urgent'] ?? 0),
                uniqid('sync_', true),
                $user['sub'],
                $user['sub'],
            ]);
            $caseId = (int) $db->lastInsertId();
            $db->prepare("UPDATE gm_appointments SET case_id = ? WHERE id = ?")->execute([$caseId, $id]);
        }

        // Confirm the appointment
        $db->prepare("
            UPDATE gm_appointments SET status = 'confirmed', approved_at = NOW(), approved_by = ?, last_modified_by = ? WHERE id = ?
        ")->execute([$user['sub'], $user['sub'], $id]);

        $db->commit();
        
        // Send confirmation email to student
        if ($appointment['student_email']) {
            try {
                require_once __DIR__ . '/../helpers/mailer.php';
                sendAppointmentEmail('booking_confirmed', $appointment['student_email'], [
                    'student_name' => $appointment['student_name'],
                    'date' => date('F j, Y', strtotime($appointment['scheduled_date'])),
                    'time' => date('g:i A', strtotime($appointment['scheduled_time'])),
                    'location' => $appointment['location'] ?: 'Guidance Office',
                ], $appointment['student_name']);
            } catch (\Exception $e) {
                app()->log('Failed to send approval email to ' . $appointment['student_email'] . ': ' . $e->getMessage(), 'error');
            }
        }
        
        // Fire module hooks (e.g. SMS confirmation to student)
        // Only fire booking.confirmed — appointment.confirmed is a legacy hook that
        // sends the same SMS, causing duplicate messages to the student.
        if (function_exists('fireModuleHook')) {
            $hookData = [
                'appointment_id' => $id,
                'student_name' => $appointment['student_name'] ?? '',
                'student_phone' => $appointment['student_mobile'] ?? $appointment['student_phone'] ?? '',
                'student_email' => $appointment['student_email'] ?? '',
                'scheduled_date' => date('F j, Y', strtotime($appointment['scheduled_date'])),
                'scheduled_time' => date('g:i A', strtotime($appointment['scheduled_time'])),
                'location' => $appointment['location'] ?? 'Guidance Office',
                'counselor_id' => $appointment['counselor_id'],
                'case_id' => $caseId,
            ];
            fireModuleHook('booking.confirmed', $hookData);
        }
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment approved', 'type' => 'success'], 'refreshAppointments' => true]));
            handleListAppointments();
            return;
        }
        app()->json(['success' => true, 'message' => 'Appointment approved']);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        app()->log('Appointments approve error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to approve appointment'], 500);
    }
}

/**
 * Reject a pending appointment
 */
function apiGuidanceRejectAppointment(int $id): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $input = app()->input();
    $db = guidanceDb();
    $reason = $input['reason'] ?? '';
    
    try {
        $stmt = $db->prepare("SELECT * FROM gm_appointments WHERE id = ?");
        $stmt->execute([$id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            app()->json(['error' => 'Appointment not found'], 404);
        }
        if ($appointment['status'] !== 'pending') {
            app()->json(['error' => 'Only pending appointments can be rejected'], 400);
        }
        if ($user['role'] === 'counselor' && $appointment['counselor_id'] != $user['sub']) {
            app()->json(['error' => 'You can only reject your own appointments'], 403);
        }
        
        $db->prepare("
            UPDATE gm_appointments SET status = 'rejected', rejected_at = NOW(), rejected_by = ?, rejection_reason = ?, last_modified_by = ? WHERE id = ?
        ")->execute([$user['sub'], $reason, $user['sub'], $id]);
        
        // Send rejection email to student
        if ($appointment['student_email']) {
            try {
                require_once __DIR__ . '/../helpers/mailer.php';
                sendAppointmentEmail('booking_rejected', $appointment['student_email'], [
                    'student_name' => $appointment['student_name'],
                    'date' => date('F j, Y', strtotime($appointment['scheduled_date'])),
                    'time' => date('g:i A', strtotime($appointment['scheduled_time'])),
                    'reason' => $reason,
                ], $appointment['student_name']);
            } catch (\Exception $e) {
                app()->log('Failed to send rejection email to ' . $appointment['student_email'] . ': ' . $e->getMessage(), 'error');
            }
        }
        
        // Fire module hooks (e.g. SMS rejection notice to student)
        if (function_exists('fireModuleHook')) {
            fireModuleHook('booking.rejected', [
                'appointment_id' => $id,
                'student_name' => $appointment['student_name'] ?? '',
                'student_phone' => $appointment['student_mobile'] ?? $appointment['student_phone'] ?? '',
                'student_email' => $appointment['student_email'] ?? '',
                'scheduled_date' => date('F j, Y', strtotime($appointment['scheduled_date'])),
                'scheduled_time' => date('g:i A', strtotime($appointment['scheduled_time'])),
                'reason' => $reason,
            ]);
        }
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Appointment rejected', 'type' => 'warning'], 'refreshAppointments' => true]));
            handleListAppointments();
            return;
        }
        app()->json(['success' => true, 'message' => 'Appointment rejected']);
    } catch (Exception $e) {
        app()->log('Appointments reject error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to reject appointment'], 500);
    }
}

// ---------------------------------------------------------------------------
// Session Records page
// ---------------------------------------------------------------------------

function pageGuidanceSessionRecords(): void {
    guidanceRequireStaff();
    $db = guidanceDb();
    $counselors = [];
    try {
        $stmt = $db->query("SELECT id, first_name, last_name FROM gm_users WHERE role IN ('counselor','admin','supervisor') AND deleted_at IS NULL ORDER BY last_name, first_name");
        $counselors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // non-fatal; filter will just show no counselors
    }
    $appointmentTypes = [];
    try {
        $stmt = $db->query("SELECT code, name FROM gm_appointment_types WHERE deleted_at IS NULL ORDER BY name");
        $appointmentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // non-fatal
    }
    guidanceRender('pages/session-records.disyl', [
        'title'            => 'Session Records',
        'current_page'     => 'session-records',
        'counselors'       => $counselors,
        'appointment_types'=> $appointmentTypes,
    ]);
}

// ---------------------------------------------------------------------------
// Appointment summary stats card (upcoming/completed/pending counts)
// ---------------------------------------------------------------------------

function apiGuidanceAppointmentStats(): void {
    guidanceRequireStaff();
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

        // Render stat cards HTML for HTMX target
        $upcoming       = (int)($row['upcoming'] ?? 0);
        $completed      = (int)($row['completed'] ?? 0);
        $pending        = (int)($row['pending'] ?? 0);
        $cancelledNoShow= (int)($row['cancelled_no_show'] ?? 0);

        echo '<div class="grid grid-cols-2 md:grid-cols-4 gap-4">';
        $cards = [
            ['label' => 'Upcoming',              'count' => $upcoming,        'icon' => 'fa-calendar-check', 'bg' => 'bg-teal-100',   'text' => 'text-teal-700',   'num' => 'text-teal-800'],
            ['label' => 'Completed',             'count' => $completed,       'icon' => 'fa-check-circle',  'bg' => 'bg-green-100',  'text' => 'text-green-700',  'num' => 'text-green-800'],
            ['label' => 'Pending',               'count' => $pending,         'icon' => 'fa-hourglass-half','bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'num' => 'text-orange-800'],
            ['label' => 'Cancelled / No Show',   'count' => $cancelledNoShow, 'icon' => 'fa-times-circle',  'bg' => 'bg-red-100',    'text' => 'text-red-700',    'num' => 'text-red-800'],
        ];
        foreach ($cards as $c) {
            $label = htmlspecialchars($c['label']);
            $count = $c['count'];
            echo <<<HTML
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex items-center gap-4">
                <div class="w-12 h-12 rounded-full {$c['bg']} flex items-center justify-center flex-shrink-0">
                    <i class="fas {$c['icon']} {$c['text']} text-xl"></i>
                </div>
                <div>
                    <div class="text-2xl font-bold {$c['num']}">{$count}</div>
                    <div class="text-xs text-gray-500 mt-0.5">{$label}</div>
                </div>
            </div>
HTML;
        }
        echo '</div>';

    } catch (Throwable $e) {
        echo '<div class="text-red-500 text-sm p-4">Failed to load appointment stats.</div>';
    }
}

// ---------------------------------------------------------------------------
// Session Records summary stats (completed/no-show/cancelled/in-progress)
// ---------------------------------------------------------------------------

function apiGuidanceSessionRecordStats(): void {
    guidanceRequireStaff();
    try {
        $db = guidanceDb();
        $stmt = $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END)   AS completed,
                SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END)     AS no_show,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END)   AS cancelled,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress
            FROM gm_appointments
            WHERE status IN ('completed','no_show','cancelled','in_progress')
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total      = (int)($row['total'] ?? 0);
        $completed  = (int)($row['completed'] ?? 0);
        $noShow     = (int)($row['no_show'] ?? 0);
        $cancelled  = (int)($row['cancelled'] ?? 0);
        $inProgress = (int)($row['in_progress'] ?? 0);
        $pct        = fn(int $n) => $total > 0 ? round($n / $total * 100, 1) : 0;

        $cards = [
            ['label' => 'Total Sessions',    'count' => $total,      'pct' => null,           'icon' => 'fa-clipboard-list', 'bg' => 'bg-blue-100',   'text' => 'text-blue-700',   'num' => 'text-blue-800'],
            ['label' => 'Went to Session',   'count' => $completed,  'pct' => $pct($completed),'icon' => 'fa-check-circle',  'bg' => 'bg-green-100',  'text' => 'text-green-700',  'num' => 'text-green-800'],
            ['label' => 'Did Not Show Up',   'count' => $noShow,     'pct' => $pct($noShow),   'icon' => 'fa-user-times',    'bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'num' => 'text-orange-800'],
            ['label' => 'Cancelled',         'count' => $cancelled,  'pct' => $pct($cancelled),'icon' => 'fa-times-circle',  'bg' => 'bg-red-100',    'text' => 'text-red-700',    'num' => 'text-red-800'],
            ['label' => 'In Progress',       'count' => $inProgress, 'pct' => $pct($inProgress),'icon' => 'fa-circle',       'bg' => 'bg-purple-100', 'text' => 'text-purple-700', 'num' => 'text-purple-800'],
        ];
        echo '<div class="grid grid-cols-2 md:grid-cols-5 gap-4">';
        foreach ($cards as $c) {
            $label = htmlspecialchars($c['label']);
            $count = $c['count'];
            $pctStr = $c['pct'] !== null ? '<div class="text-xs ' . $c['text'] . ' mt-0.5">' . $c['pct'] . '% of total</div>' : '';
            echo <<<HTML
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full {$c['bg']} flex items-center justify-center flex-shrink-0">
                    <i class="fas {$c['icon']} {$c['text']} text-base"></i>
                </div>
                <div>
                    <div class="text-xl font-bold {$c['num']}">{$count}</div>
                    <div class="text-xs text-gray-500 leading-tight">{$label}</div>
                    {$pctStr}
                </div>
            </div>
HTML;
        }
        echo '</div>';

    } catch (Throwable $e) {
        echo '<div class="text-red-500 text-sm p-4">Failed to load session stats.</div>';
    }
}
