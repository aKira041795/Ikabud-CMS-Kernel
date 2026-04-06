<?php
/**
 * Cases Route Handlers
 * 
 * @package Guidance\Routes
 */

function casesHasStudentStatusColumn(PDO $db): bool {
    static $hasColumn = [];
    $tid = app()->tenant()->current();

    if (array_key_exists($tid, $hasColumn)) {
        return $hasColumn[$tid];
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute(['gm_cases', 'student_status']);
    $hasColumn[$tid] = ((int) $stmt->fetchColumn()) > 0;

    return $hasColumn[$tid];
}

function apiGuidanceListCases(): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $db = guidanceDb();
    
    try {
        $where = [];
        $params = [];
        
        // Show deleted cases only when explicitly requested
        if (!empty($_GET['show_deleted']) && $_GET['show_deleted'] === 'only') {
            $where[] = "c.deleted_at IS NOT NULL";
        } elseif (!empty($_GET['show_deleted']) && $_GET['show_deleted'] === 'all') {
            // No deleted_at filter — show everything
        } else {
            $where[] = "c.deleted_at IS NULL";
        }
        
        if ($user['role'] === 'counselor') {
            // Counselors can only see cases assigned to them OR cases from their assigned colleges
            $assignedColleges = $db->prepare("SELECT college_id FROM gm_counselor_assignments WHERE counselor_id = ? AND is_active = 1");
            $assignedColleges->execute([$user['sub']]);
            $collegeIds = $assignedColleges->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($collegeIds)) {
                $placeholders = implode(',', array_fill(0, count($collegeIds), '?'));
                $where[] = "(c.counselor_id = ? OR c.college_id IN ({$placeholders}))";
                $params[] = $user['sub'];
                $params = array_merge($params, $collegeIds);
            } else {
                $where[] = "c.counselor_id = ?";
                $params[] = $user['sub'];
            }
        }
        
        if (!empty($_GET['status'])) {
            $where[] = "c.status = ?";
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['severity'])) {
            $where[] = "c.severity = ?";
            $params[] = $_GET['severity'];
        }
        if (!empty($_GET['category'])) {
            $where[] = "c.category = ?";
            $params[] = $_GET['category'];
        }
        if (!empty($_GET['search'])) {
            $where[] = "(c.student_name LIKE ? OR c.case_number LIKE ? OR c.presenting_issue LIKE ?)";
            $search = '%' . $_GET['search'] . '%';
            $params = array_merge($params, [$search, $search, $search]);
        }
        if (!empty($_GET['counselor_id']) && $user['role'] !== 'counselor') {
            $where[] = "c.counselor_id = ?";
            $params[] = (int) $_GET['counselor_id'];
        }
        
        $whereClause = implode(' AND ', $where);
        
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int) ($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $countStmt = $db->prepare("SELECT COUNT(*) FROM gm_cases c WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        
        $stmt = $db->prepare("
            SELECT c.*, CONCAT(u.first_name, ' ', u.last_name) as counselor_name,
                   col.code as college_code, col.name as college_name
            FROM gm_cases c
            LEFT JOIN gm_users u ON c.counselor_id = u.id
            LEFT JOIN gm_colleges col ON c.college_id = col.id
            WHERE {$whereClause}
            ORDER BY c.is_urgent DESC, c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // For cases without college_id, resolve from counselor's assignments
        $counselorIds = array_unique(array_filter(array_column($cases, 'counselor_id')));
        $counselorCollegeMap = [];
        if (!empty($counselorIds)) {
            $ph = implode(',', array_fill(0, count($counselorIds), '?'));
            $caStmt = $db->prepare("
                SELECT ca.counselor_id, GROUP_CONCAT(col.code ORDER BY col.sort_order SEPARATOR ', ') as codes,
                       GROUP_CONCAT(col.name ORDER BY col.sort_order SEPARATOR ', ') as names
                FROM gm_counselor_assignments ca
                JOIN gm_colleges col ON ca.college_id = col.id AND col.is_active = 1
                WHERE ca.counselor_id IN ({$ph}) AND ca.is_active = 1
                GROUP BY ca.counselor_id
            ");
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
            // Split comma-separated codes into array for template iteration
            $caseRow['college_codes'] = !empty($caseRow['college_code'])
                ? array_map('trim', explode(',', $caseRow['college_code']))
                : [];
        }
        unset($caseRow);
        
        // Summary stats (role-aware, ignores filters)
        $statRoleWhere = ["c.deleted_at IS NULL"];
        $statRoleParams = [];
        if ($user['role'] === 'counselor') {
            $statRoleWhere[] = "c.counselor_id = ?";
            $statRoleParams[] = $user['sub'];
        }
        $statRoleStr = implode(' AND ', $statRoleWhere);
        $statsStmt = $db->prepare("SELECT
            COUNT(*) AS total_cases,
            SUM(CASE WHEN c.status = 'open' THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN c.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
            SUM(CASE WHEN c.status = 'on_hold' THEN 1 ELSE 0 END) AS on_hold_count,
            SUM(CASE WHEN c.status = 'closed' THEN 1 ELSE 0 END) AS closed_count,
            SUM(CASE WHEN c.severity IN ('critical','high') THEN 1 ELSE 0 END) AS high_severity_count,
            SUM(CASE WHEN c.is_urgent = 1 AND c.status != 'closed' THEN 1 ELSE 0 END) AS urgent_count
            FROM gm_cases c WHERE {$statRoleStr}");
        $statsStmt->execute($statRoleParams);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        $stats = array_map('intval', $stats);
        
        if (app()->isHtmx()) {
            $totalPages = (int) ceil($total / $limit);
            echo app()->render('partials/cases-table.disyl', [
                'cases' => $cases,
                'stats' => $stats,
                'pagination' => [
                    'total' => $total,
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'from' => $offset + 1,
                    'to' => min($offset + $limit, $total),
                    'has_prev' => $page > 1,
                    'has_next' => $page < $totalPages,
                    'prev_page' => $page - 1,
                    'next_page' => $page + 1,
                ],
            ]);
            exit;
        }
        
        app()->json([
            'success' => true,
            'data' => $cases,
            'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => ceil($total / $limit)],
        ]);
    } catch (Exception $e) {
        app()->log('Cases list error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to fetch cases'], 500);
    }
}

function apiGuidanceGetCase(string $id): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $db = guidanceDb();
    
    try {
        $stmt = $db->prepare("
            SELECT c.*, CONCAT(u.first_name, ' ', u.last_name) as counselor_name
            FROM gm_cases c
            LEFT JOIN gm_users u ON c.counselor_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$case) {
            app()->json(['error' => 'Case not found'], 404);
            return;
        }
        
        if ($user['role'] === 'counselor' && $case['counselor_id'] != $user['sub']) {
            app()->json(['error' => 'Access denied'], 403);
        }
        
        // Enrich with related counts and upcoming appointments
        $notesStmt = $db->prepare("SELECT COUNT(*) FROM gm_counselor_notes WHERE case_id = ?");
        $notesStmt->execute([$id]);
        $case['notes_count'] = (int) $notesStmt->fetchColumn();
        
        $apptStmt = $db->prepare("
            SELECT * FROM gm_appointments 
            WHERE case_id = ? AND scheduled_date >= CURDATE() AND status IN ('scheduled', 'confirmed')
            ORDER BY scheduled_date, scheduled_time LIMIT 5
        ");
        $apptStmt->execute([$id]);
        $case['upcoming_appointments'] = $apptStmt->fetchAll();
        
        app()->json(['success' => true, 'data' => $case]);
    } catch (Exception $e) {
        app()->log('Cases get error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to fetch case'], 500);
    }
}

function apiGuidanceCreateCase(): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    
    if (!in_array($user['role'], ['counselor', 'supervisor', 'admin'])) {
        app()->json(['error' => 'Permission denied'], 403);
    }
    
    $input = app()->input();
    
    // Validate required fields from dynamic form config
    require_once __DIR__ . '/../helpers/form-fields.php';
    $validationErrors = validateFormInput('case', $input);
    if (!empty($validationErrors)) {
        app()->json(['error' => $validationErrors[0]], 400);
        return;
    }
    
    try {
        $db = guidanceDb();
        $hasStudentStatus = casesHasStudentStatusColumn($db);
        
        // Generate case number (race-safe: use MAX instead of COUNT)
        $year = date('Y');
        $prefix = "GC-{$year}-";
        $stmt = $db->prepare("SELECT case_number FROM gm_cases WHERE case_number LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;
        $caseNumber = sprintf('%s%04d', $prefix, $seq);
        
        $columns = [
            'case_number', 'student_id', 'student_name', 'student_grade',
        ];
        if ($hasStudentStatus) {
            $columns[] = 'student_status';
        }
        $columns = array_merge($columns, [
            'student_section', 'date_of_birth', 'gender', 'nationality', 'civil_status', 'address', 'student_mobile', 'student_email',
            'college_id', 'counselor_id', 'category', 'severity', 'presenting_issue', 'background_info',
            'is_urgent', 'is_confidential', 'parent_guardian_name', 'parent_guardian_contact', 'emergency_contact_address',
            'referral_source', 'referred_by', 'sync_id', 'created_by', 'created_at', 'updated_at',
        ]);

        $placeholders = array_fill(0, count($columns) - 2, '?');
        $placeholders[] = 'NOW()';
        $placeholders[] = 'NOW()';

        $values = [
            $caseNumber,
            $input['student_id'],
            $input['student_name'],
            $input['student_grade'] ?? null,
        ];
        if ($hasStudentStatus) {
            $values[] = $input['student_status'] ?? null;
        }
        $values = array_merge($values, [
            $input['student_section'] ?? null,
            !empty($input['date_of_birth']) ? $input['date_of_birth'] : null,
            $input['gender'] ?? null,
            $input['nationality'] ?? null,
            $input['civil_status'] ?? null,
            $input['address'] ?? null,
            $input['student_mobile'] ?? null,
            $input['student_email'] ?? null,
            !empty($input['college_id']) ? (int)$input['college_id'] : null,
            $input['counselor_id'] ?? $user['sub'],
            $input['category'] ?? 'general',
            $input['severity'] ?? 'medium',
            $input['presenting_issue'],
            $input['background_info'] ?? null,
            $input['is_urgent'] ?? 0,
            $input['is_confidential'] ?? 0,
            $input['parent_guardian_name'] ?? null,
            $input['parent_guardian_contact'] ?? null,
            $input['emergency_contact_address'] ?? null,
            $input['referral_source'] ?? 'walk-in',
            $input['referred_by'] ?? null,
            $input['sync_id'] ?? uniqid('sync_', true),
            $user['sub'],
        ]);

        $stmt = $db->prepare(
            'INSERT INTO gm_cases (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($values);
        $caseId = $db->lastInsertId();
        
        logAudit($db, 'case.created', 'gm_cases', $caseId, null, $input, $user['sub']);
        
        // Fire module hooks (e.g. SMS notification to counselor)
        if (function_exists('fireModuleHook')) {
            fireModuleHook('case.created', [
                'case_id' => $caseId,
                'case_number' => $caseNumber,
                'student_name' => $input['student_name'],
                'student_mobile' => $input['student_mobile'] ?? '',
                'counselor_id' => $input['counselor_id'] ?? $user['sub'],
                'category' => $input['category'] ?? 'general',
                'severity' => $input['severity'] ?? 'medium',
            ]);
        }
        
        // Auto-create initial appointment if date/time provided
        $appointmentCreated = false;
        if (!empty($input['appointment_date']) && !empty($input['appointment_time'])) {
            $duration = 30;
            if (!empty($input['appointment_type_id'])) {
                $typeStmt = $db->prepare("SELECT duration_minutes FROM gm_appointment_types WHERE id = ?");
                $typeStmt->execute([$input['appointment_type_id']]);
                $typeRow = $typeStmt->fetch();
                if ($typeRow) $duration = (int) $typeRow['duration_minutes'];
            }
            
            $db->prepare("
                INSERT INTO gm_appointments (
                    case_id, counselor_id, scheduled_date, scheduled_time, 
                    purpose, status, appointment_type_id, student_name, student_email,
                    duration_minutes, created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 'confirmed', ?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([
                $caseId,
                $input['counselor_id'] ?? $user['sub'],
                $input['appointment_date'],
                $input['appointment_time'],
                $input['appointment_purpose'] ?? 'Initial Consultation',
                $input['appointment_type_id'] ?? null,
                $input['student_name'],
                $input['student_email'] ?? null,
                $duration,
                $user['sub'],
            ]);
            $appointmentCreated = true;
        }
        
        if (app()->isHtmx()) {
            $message = "Case {$caseNumber} created successfully";
            if ($appointmentCreated) $message .= " with appointment scheduled";
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $message, 'type' => 'success'], 'closeModal' => true, 'refreshCases' => true]));
            header('HX-Redirect: /cases/' . $caseId);
            echo '';
            return;
        }
        
        app()->json(['success' => true, 'data' => ['id' => $caseId, 'case_number' => $caseNumber, 'appointment_created' => $appointmentCreated]], 201);
    } catch (Exception $e) {
        app()->log('Cases create error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to create case'], 500);
    }
}

function apiGuidanceUpdateCase(string $id): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $input = app()->input();
    $db = guidanceDb();
    
    try {
        $stmt = $db->prepare("SELECT * FROM gm_cases WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        $hasStudentStatus = casesHasStudentStatusColumn($db);
        
        if (!$existing) {
            app()->json(['error' => 'Case not found'], 404);
        }
        if ($user['role'] === 'counselor' && $existing['counselor_id'] != $user['sub']) {
            app()->json(['error' => 'Access denied'], 403);
        }
        
        $allowed = ['student_name', 'student_id', 'student_grade', 'student_section', 'college_id', 'category', 'severity',
                    'presenting_issue', 'background_info', 'is_urgent', 'is_confidential',
                    'parent_guardian_name', 'parent_guardian_contact', 'emergency_contact_address',
                    'next_followup_date', 'counselor_id',
                    'date_of_birth', 'gender', 'nationality', 'civil_status', 'address',
                    'student_mobile', 'student_email', 'referral_source', 'referred_by'];
        if ($hasStudentStatus) {
            $allowed[] = 'student_status';
        }
        
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
        $updates[] = "version = version + 1";
        $values[] = $id;
        
        $db->prepare("UPDATE gm_cases SET " . implode(', ', $updates) . " WHERE id = ?")->execute($values);
        logAudit($db, 'case.updated', 'gm_cases', $id, $existing, $input, $user['sub']);
        
        // Fire module hooks
        fireModuleHook('case.updated', [
            'case_id' => $id,
            'case_number' => $existing['case_number'],
            'student_name' => $input['student_name'] ?? $existing['student_name'],
            'counselor_id' => $input['counselor_id'] ?? $existing['counselor_id'],
            'changed_fields' => array_keys(array_intersect_key($input, array_flip($allowed))),
            'previous_counselor_id' => $existing['counselor_id'],
            'updated_by' => $user['sub'],
        ]);
        
        // Fire reassignment hook if counselor changed
        if (!empty($input['counselor_id']) && $input['counselor_id'] != $existing['counselor_id']) {
            fireModuleHook('case.reassigned', [
                'case_id' => $id,
                'case_number' => $existing['case_number'],
                'student_name' => $existing['student_name'],
                'previous_counselor_id' => $existing['counselor_id'],
                'new_counselor_id' => $input['counselor_id'],
                'reassigned_by' => $user['sub'],
            ]);
        }
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case updated successfully', 'type' => 'success'], 'closeModal' => true]));
            header('HX-Refresh: true');
            echo '';
            return;
        }
        app()->json(['success' => true, 'message' => 'Case updated']);
    } catch (Exception $e) {
        app()->log('Cases update error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to update case'], 500);
    }
}

function apiGuidanceDeleteCase(string $id): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    
    if (!in_array($user['role'], ['supervisor', 'admin'])) {
        app()->json(['error' => 'Permission denied'], 403);
    }
    
    try {
        $db = guidanceDb();
        $stmt = $db->prepare("UPDATE gm_cases SET deleted_at = NOW(), deleted_by = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$user['sub'], $id]);
        
        if ($stmt->rowCount() === 0) {
            app()->json(['error' => 'Case not found'], 404);
        }
        
        logAudit($db, 'case.deleted', 'gm_cases', $id, null, null, $user['sub']);
        
        fireModuleHook('case.deleted', [
            'case_id' => $id,
            'deleted_by' => $user['sub'],
        ]);
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case deleted successfully', 'type' => 'success']]));
            header('HX-Redirect: /cases');
            echo '';
            return;
        }
        app()->json(['success' => true, 'message' => 'Case deleted']);
    } catch (Exception $e) {
        app()->log('Cases delete error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to delete case'], 500);
    }
}

function apiGuidanceCloseCase(string $id): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    $input = app()->input();
    $resolutionSummary = $input['resolution_summary'] ?? 'Case closed';
    
    try {
        $db = guidanceDb();
        
        // Get current status before closing
        $currentStmt = $db->prepare("SELECT status FROM gm_cases WHERE id = ? AND deleted_at IS NULL AND status != 'closed'");
        $currentStmt->execute([$id]);
        $currentStatus = $currentStmt->fetchColumn();
        
        if (!$currentStatus) {
            app()->json(['error' => 'Case not found or already closed'], 400);
        }
        
        $db->prepare("
            UPDATE gm_cases SET status = 'closed', resolution_summary = ?, closed_at = NOW(), closed_by = ?,
                last_modified_by = ?, updated_at = NOW(), version = version + 1
            WHERE id = ?
        ")->execute([$resolutionSummary, $user['sub'], $user['sub'], $id]);
        
        $db->prepare("
            INSERT INTO gm_case_status_history (case_id, previous_status, new_status, changed_by, notes, created_at)
            VALUES (?, ?, 'closed', ?, ?, NOW())
        ")->execute([$id, $currentStatus, $user['sub'], $resolutionSummary]);
        
        logAudit($db, 'case.closed', 'gm_cases', $id, null, $input, $user['sub']);
        
        fireModuleHook('case.closed', [
            'case_id' => $id,
            'previous_status' => $currentStatus,
            'resolution_summary' => $resolutionSummary,
            'closed_by' => $user['sub'],
        ]);
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case closed successfully', 'type' => 'success']]));
            header('HX-Redirect: /cases');
            echo '';
            return;
        }
        app()->json(['success' => true, 'message' => 'Case closed']);
    } catch (Exception $e) {
        app()->log('Cases close error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to close case'], 500);
    }
}

function apiGuidanceReopenCase(string $id): void {
    $user = guidanceUser();
    guidanceRequireStaff();
    
    if (!in_array($user['role'], ['supervisor', 'admin'])) {
        if (app()->isHtmx()) {
            http_response_code(403);
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Only supervisors can reopen cases', 'type' => 'error']]));
            echo '';
            return;
        }
        app()->json(['error' => 'Only supervisors can reopen cases'], 403);
    }
    
    $input = app()->input();
    
    try {
        $db = guidanceDb();
        
        $stmt = $db->prepare("
            UPDATE gm_cases SET status = 'in_progress', closed_at = NULL, closed_by = NULL,
                last_modified_by = ?, updated_at = NOW(), version = version + 1
            WHERE id = ? AND deleted_at IS NULL AND status = 'closed'
        ");
        $stmt->execute([$user['sub'], $id]);
        
        if ($stmt->rowCount() === 0) {
            if (app()->isHtmx()) {
                http_response_code(400);
                header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case not found or not closed', 'type' => 'error']]));
                echo '';
                return;
            }
            app()->json(['error' => 'Case not found or not closed'], 400);
        }
        
        $db->prepare("
            INSERT INTO gm_case_status_history (case_id, previous_status, new_status, changed_by, notes, created_at)
            VALUES (?, 'closed', 'in_progress', ?, ?, NOW())
        ")->execute([$id, $user['sub'], $input['reason'] ?? 'Case reopened']);
        
        logAudit($db, 'case.reopened', 'gm_cases', $id, null, $input, $user['sub']);
        
        fireModuleHook('case.reopened', [
            'case_id' => $id,
            'reason' => $input['reason'] ?? 'Case reopened',
            'reopened_by' => $user['sub'],
        ]);
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Case reopened successfully', 'type' => 'success']]));
            header('HX-Refresh: true');
            echo '';
            return;
        }
        app()->json(['success' => true, 'message' => 'Case reopened']);
    } catch (Exception $e) {
        app()->log('Cases reopen error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to reopen case'], 500);
    }
}

function logAudit(PDO $db, string $action, string $table, $recordId, ?array $oldData, ?array $newData, int $userId): void {
    try {
        $db->prepare("
            INSERT INTO gm_audit_logs (action, table_name, record_id, old_data, new_data, user_id, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $action, $table, $recordId,
            $oldData ? json_encode($oldData) : null,
            $newData ? json_encode($newData) : null,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Exception $e) {
        app()->log('Audit log error: ' . $e->getMessage(), 'error');
    }
}
