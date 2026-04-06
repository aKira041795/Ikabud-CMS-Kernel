<?php
/**
 * Student Tracker Route Handlers
 * 
 * Manages document/requirement trackers, student enrollment,
 * submission tracking, CSV import with dynamic column mapping.
 * 
 * @package Guidance\Routes
 */

// ============================================================
// PAGE HANDLERS
// ============================================================

/**
 * Tracker list page
 */
function apiGuidanceTrackersPage(): void
{
    $app = app();
    $user = $app->requireAuth();
    $db = $app->db();
    
    $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll();
    
    echo $app->render('pages/trackers', [
        'current_page' => 'trackers',
        'page_title' => 'Student Tracker',
        'colleges' => $colleges,
    ]);
}

/**
 * Tracker detail page
 */
function apiGuidanceTrackerDetailPage(string $trackerId): void
{
    $app = app();
    $user = $app->requireAuth();
    
    $db = $app->db();
    $stmt = $db->prepare("SELECT t.*, c.code as college_code, c.name as college_name FROM gm_trackers t LEFT JOIN gm_colleges c ON t.college_id = c.id WHERE t.id = ?");
    $stmt->execute([$trackerId]);
    $tracker = $stmt->fetch();
    
    if (!$tracker) {
        $app->redirect('/trackers');
    }
    
    $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll();
    
    echo $app->render('pages/tracker-detail', [
        'current_page' => 'trackers',
        'page_title' => $tracker['name'],
        'tracker' => $tracker,
        'colleges' => $colleges,
    ]);
}

// ============================================================
// API HANDLERS
// ============================================================

/**
 * List trackers (with stats)
 */
function apiListTrackers(): void
{
    $app = app();
    $user = $app->requireAuth();
    $db = $app->db();
    
    $search = $app->input('search', '');
    $status = $app->input('status', '');
    
    $sql = "SELECT t.*, 
                u.first_name as creator_first, u.last_name as creator_last,
                c.name as college_name,
                (SELECT COUNT(*) FROM gm_tracker_students ts WHERE ts.tracker_id = t.id) as student_count,
                (SELECT COUNT(*) FROM gm_tracker_items ti WHERE ti.tracker_id = t.id) as item_count
            FROM gm_trackers t
            LEFT JOIN gm_users u ON t.created_by = u.id
            LEFT JOIN gm_colleges c ON t.college_id = c.id
            WHERE 1=1";
    $params = [];
    
    if ($search) {
        $sql .= " AND (t.name LIKE ? OR t.description LIKE ? OR t.academic_year LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    
    if ($status === 'active') {
        $sql .= " AND t.is_active = 1";
    } elseif ($status === 'inactive') {
        $sql .= " AND t.is_active = 0";
    }
    
    // Role-based filtering: counselors see only their college's trackers
    if ($user['role'] === 'counselor') {
        $collegeIds = [];
        $cStmt = $db->prepare("SELECT college_id FROM gm_counselor_assignments WHERE counselor_id = ?");
        $cStmt->execute([$user['sub']]);
        while ($row = $cStmt->fetch()) {
            $collegeIds[] = $row['college_id'];
        }
        if (!empty($collegeIds)) {
            $placeholders = implode(',', array_fill(0, count($collegeIds), '?'));
            $sql .= " AND (t.college_id IS NULL OR t.college_id IN ({$placeholders}))";
            $params = array_merge($params, $collegeIds);
        }
    }
    
    $sql .= " ORDER BY t.is_active DESC, t.created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $trackers = $stmt->fetchAll();
    
    // Calculate completion stats for each tracker
    foreach ($trackers as &$t) {
        $t['completion'] = getTrackerCompletion($db, $t['id']);
    }
    
    if ($app->isHtmx()) {
        echo $app->render('partials/tracker-list', ['trackers' => $trackers]);
    } else {
        $app->json(['trackers' => $trackers]);
    }
}

/**
 * Create tracker
 */
function apiCreateTracker(): void
{
    $app = app();
    $user = $app->requireAnyRole('admin', 'supervisor');
    $db = $app->db();
    
    $name = trim($app->input('name', ''));
    $description = trim($app->input('description', ''));
    $academicYear = trim($app->input('academic_year', ''));
    $collegeId = $app->input('college_id') ?: null;
    
    if (!$name) {
        $app->json(['error' => 'Tracker name is required'], 422);
    }
    
    $stmt = $db->prepare("
        INSERT INTO gm_trackers (name, description, academic_year, college_id, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$name, $description ?: null, $academicYear ?: null, $collegeId, $user['sub']]);
    $trackerId = $db->lastInsertId();
    
    // Create default items if provided
    $items = $app->input('items', []);
    if (is_array($items)) {
        $iStmt = $db->prepare("INSERT INTO gm_tracker_items (tracker_id, name, is_required, sort_order) VALUES (?, ?, 1, ?)");
        foreach ($items as $i => $itemName) {
            $itemName = trim($itemName);
            if ($itemName) {
                $iStmt->execute([$trackerId, $itemName, $i]);
            }
        }
    }
    
    if ($app->isHtmx()) {
        header('HX-Trigger: refreshTrackers');
        header('HX-Reswap: none');
        echo '<div class="p-3 bg-green-50 text-green-700 rounded-lg text-sm">Tracker created successfully</div>';
    } else {
        $app->json(['success' => true, 'id' => $trackerId]);
    }
}

/**
 * Update tracker
 */
function apiUpdateTracker(string $trackerId): void
{
    $app = app();
    $user = $app->requireAnyRole('admin', 'supervisor');
    $db = $app->db();
    
    $name = trim($app->input('name', ''));
    $description = trim($app->input('description', ''));
    $academicYear = trim($app->input('academic_year', ''));
    $collegeId = $app->input('college_id') ?: null;
    $isActive = $app->input('is_active', 1);
    
    if (!$name) {
        $app->json(['error' => 'Tracker name is required'], 422);
    }
    
    $stmt = $db->prepare("
        UPDATE gm_trackers SET name = ?, description = ?, academic_year = ?, college_id = ?, is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([$name, $description ?: null, $academicYear ?: null, $collegeId, $isActive, $trackerId]);
    
    if ($app->isHtmx()) {
        header('HX-Trigger: refreshTrackers');
        header('HX-Reswap: none');
    }
    $app->json(['success' => true]);
}

/**
 * Delete tracker
 */
function apiDeleteTracker(string $trackerId): void
{
    $app = app();
    $user = $app->requireRole('admin');
    $db = $app->db();
    
    // Drop dynamic columns first
    $customFields = $db->prepare("SELECT column_name FROM gm_tracker_custom_fields WHERE tracker_id = ?");
    $customFields->execute([$trackerId]);
    while ($cf = $customFields->fetch()) {
        try {
            $db->exec("ALTER TABLE gm_tracker_students DROP COLUMN `{$cf['column_name']}`");
        } catch (\Exception $e) {
            // Column may already be gone
        }
    }
    
    $stmt = $db->prepare("DELETE FROM gm_trackers WHERE id = ?");
    $stmt->execute([$trackerId]);
    
    if ($app->isHtmx()) {
        header('HX-Trigger: refreshTrackers');
        header('HX-Reswap: none');
    }
    $app->json(['success' => true]);
}

// ============================================================
// TRACKER ITEMS (Required Documents)
// ============================================================

/**
 * List items for a tracker
 */
function apiListTrackerItems(string $trackerId): void
{
    $app = app();
    $app->requireAuth();
    $db = $app->db();
    
    $stmt = $db->prepare("SELECT * FROM gm_tracker_items WHERE tracker_id = ? ORDER BY sort_order, id");
    $stmt->execute([$trackerId]);
    $items = $stmt->fetchAll();
    
    if ($app->isHtmx()) {
        echo $app->render('partials/tracker-items', ['items' => $items, 'tracker_id' => $trackerId]);
    } else {
        $app->json(['items' => $items]);
    }
}

/**
 * Add item to tracker
 */
function apiAddTrackerItem(string $trackerId): void
{
    $app = app();
    $app->requireAnyRole('admin', 'supervisor');
    $db = $app->db();
    
    $name = trim($app->input('name', ''));
    $description = trim($app->input('description', ''));
    $isRequired = $app->input('is_required', 1);
    $deadline = $app->input('deadline') ?: null;
    
    if (!$name) {
        $app->json(['error' => 'Item name is required'], 422);
    }
    
    // Get next sort order
    $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM gm_tracker_items WHERE tracker_id = ?");
    $stmt->execute([$trackerId]);
    $sortOrder = (int) $stmt->fetchColumn();
    
    $stmt = $db->prepare("
        INSERT INTO gm_tracker_items (tracker_id, name, description, is_required, sort_order, deadline)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$trackerId, $name, $description ?: null, $isRequired, $sortOrder, $deadline]);
    $itemId = $db->lastInsertId();
    
    // Create pending submissions for all existing students in this tracker
    $students = $db->prepare("SELECT id FROM gm_tracker_students WHERE tracker_id = ?");
    $students->execute([$trackerId]);
    $subStmt = $db->prepare("INSERT IGNORE INTO gm_tracker_submissions (tracker_student_id, tracker_item_id) VALUES (?, ?)");
    while ($s = $students->fetch()) {
        $subStmt->execute([$s['id'], $itemId]);
    }
    
    if ($app->isHtmx()) {
        header('HX-Trigger: refreshItems, refreshStudents');
        header('HX-Reswap: none');
    }
    $app->json(['success' => true, 'id' => $itemId]);
}

/**
 * Delete item from tracker
 */
function apiDeleteTrackerItem(string $trackerId, string $itemId): void
{
    $app = app();
    $app->requireAnyRole('admin', 'supervisor');
    $db = $app->db();
    
    $stmt = $db->prepare("DELETE FROM gm_tracker_items WHERE id = ? AND tracker_id = ?");
    $stmt->execute([$itemId, $trackerId]);
    
    if ($app->isHtmx()) {
        header('HX-Trigger: refreshItems, refreshStudents');
        header('HX-Reswap: none');
    }
    $app->json(['success' => true]);
}

// ============================================================
// STUDENTS
// ============================================================

/**
 * List students in a tracker (with submission status)
 */
function apiListTrackerStudents(string $trackerId): void
{
    $app = app();
    $app->requireAuth();
    $db = $app->db();
    
    $search = $app->input('search', '');
    $college = $app->input('college_id', '');
    $page = max(1, (int) $app->input('page', 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;
    
    // Get tracker items
    $itemStmt = $db->prepare("SELECT * FROM gm_tracker_items WHERE tracker_id = ? ORDER BY sort_order, id");
    $itemStmt->execute([$trackerId]);
    $items = $itemStmt->fetchAll();
    
    // Get custom fields
    $cfStmt = $db->prepare("SELECT * FROM gm_tracker_custom_fields WHERE tracker_id = ? ORDER BY id");
    $cfStmt->execute([$trackerId]);
    $customFields = $cfStmt->fetchAll();
    
    // Build student query
    $sql = "SELECT ts.*, c.name as college_name FROM gm_tracker_students ts
            LEFT JOIN gm_colleges c ON ts.college_id = c.id
            WHERE ts.tracker_id = ?";
    $params = [$trackerId];
    
    if ($search) {
        $sql .= " AND (ts.student_name LIKE ? OR ts.student_id LIKE ? OR ts.email LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    if ($college) {
        $sql .= " AND ts.college_id = ?";
        $params[] = $college;
    }
    
    // Count total
    $countSql = str_replace("SELECT ts.*, c.name as college_name", "SELECT COUNT(*)", $sql);
    $countSql = str_replace("LEFT JOIN gm_colleges c ON ts.college_id = c.id", "", $countSql);
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    
    $sql .= " ORDER BY ts.student_name ASC LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
    
    // Load submissions for each student, keyed by item_id for easy template lookup
    foreach ($students as &$student) {
        $subStmt = $db->prepare("
            SELECT sub.*, ti.name as item_name 
            FROM gm_tracker_submissions sub
            JOIN gm_tracker_items ti ON sub.tracker_item_id = ti.id
            WHERE sub.tracker_student_id = ?
            ORDER BY ti.sort_order, ti.id
        ");
        $subStmt->execute([$student['id']]);
        $rawSubs = $subStmt->fetchAll();
        
        // Build keyed map: item_id => submission row
        $subsMap = [];
        $completed = 0;
        foreach ($rawSubs as $sub) {
            $subsMap[$sub['tracker_item_id']] = $sub;
            if (in_array($sub['status'], ['submitted', 'verified'])) {
                $completed++;
            }
        }
        // Build per-item cell data for the template (avoids nested loop+if in DiSyL)
        $cells = [];
        $totalItems = count($items);
        foreach ($items as $item) {
            $sub = $subsMap[$item['id']] ?? null;
            $cells[] = [
                'item_id' => $item['id'],
                'student_id' => $student['id'],
                'status' => $sub ? $sub['status'] : 'pending',
            ];
        }
        $student['cells'] = $cells;
        
        // Calculate completion
        $student['completion'] = $totalItems > 0 ? round(($completed / $totalItems) * 100) : 0;
        $student['completed_count'] = $completed;
        $student['total_items'] = $totalItems;
    }
    
    $pages = ceil($total / $perPage);
    
    if ($app->isHtmx()) {
        echo $app->render('partials/tracker-students', [
            'students' => $students,
            'items' => $items,
            'custom_fields' => $customFields,
            'tracker_id' => $trackerId,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    } else {
        $app->json([
            'students' => $students,
            'items' => $items,
            'custom_fields' => $customFields,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }
}

/**
 * Add student to tracker
 */
function apiAddTrackerStudent(string $trackerId): void
{
    $app = app();
    $app->requireAuth();
    $db = $app->db();
    
    $studentName = trim($app->input('student_name', ''));
    $studentId = trim($app->input('student_id', ''));
    $collegeId = $app->input('college_id') ?: null;
    $yearLevel = trim($app->input('year_level', ''));
    $section = trim($app->input('section', ''));
    $email = trim($app->input('email', ''));
    $phone = trim($app->input('phone', ''));
    
    if (!$studentName) {
        $app->json(['error' => 'Student name is required'], 422);
    }
    
    $stmt = $db->prepare("
        INSERT INTO gm_tracker_students (tracker_id, student_id, student_name, college_id, year_level, section, email, phone)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$trackerId, $studentId ?: null, $studentName, $collegeId, $yearLevel ?: null, $section ?: null, $email ?: null, $phone ?: null]);
    $tsId = $db->lastInsertId();
    
    // Create pending submissions for all tracker items
    $items = $db->prepare("SELECT id FROM gm_tracker_items WHERE tracker_id = ?");
    $items->execute([$trackerId]);
    $subStmt = $db->prepare("INSERT INTO gm_tracker_submissions (tracker_student_id, tracker_item_id) VALUES (?, ?)");
    while ($item = $items->fetch()) {
        $subStmt->execute([$tsId, $item['id']]);
    }
    
    fireModuleHook('tracker.student.added', [
        'tracker_id' => $trackerId,
        'tracker_student_id' => $tsId,
        'student_name' => $studentName,
        'student_id' => $studentId,
        'college_id' => $collegeId,
        'email' => $email,
        'phone' => $phone,
    ]);
    
    if ($app->isHtmx()) {
        header('HX-Trigger: refreshStudents');
        header('HX-Reswap: none');
    }
    $app->json(['success' => true, 'id' => $tsId]);
}

/**
 * Update submission status (toggle or set)
 */
function apiUpdateSubmission(string $trackerId): void
{
    $app = app();
    $app->requireAuth();
    $db = $app->db();
    $user = $app->user();
    
    $studentId = $app->input('tracker_student_id');
    $itemId = $app->input('tracker_item_id');
    $status = $app->input('status', 'submitted');
    $remarks = trim($app->input('remarks', ''));
    
    if (!$studentId || !$itemId) {
        $app->json(['error' => 'Student and item are required'], 422);
    }
    
    // Upsert submission
    $stmt = $db->prepare("
        INSERT INTO gm_tracker_submissions (tracker_student_id, tracker_item_id, status, submitted_at, verified_by, remarks)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            status = VALUES(status),
            submitted_at = IF(VALUES(status) IN ('submitted','verified'), COALESCE(submitted_at, NOW()), submitted_at),
            verified_by = IF(VALUES(status) = 'verified', VALUES(verified_by), verified_by),
            remarks = IF(VALUES(remarks) != '', VALUES(remarks), remarks),
            updated_at = NOW()
    ");
    
    $submittedAt = in_array($status, ['submitted', 'verified']) ? date('Y-m-d H:i:s') : null;
    $verifiedBy = $status === 'verified' ? $user['sub'] : null;
    
    $stmt->execute([$studentId, $itemId, $status, $submittedAt, $verifiedBy, $remarks ?: null]);
    
    fireModuleHook('tracker.submission.updated', [
        'tracker_id' => $trackerId,
        'tracker_student_id' => $studentId,
        'tracker_item_id' => $itemId,
        'status' => $status,
        'updated_by' => $user['sub'],
    ]);
    
    if ($app->isHtmx()) {
        header('HX-Trigger: refreshStudents');
        header('HX-Reswap: none');
    }
    $app->json(['success' => true]);
}

/**
 * Bulk update submissions (mark multiple students for an item)
 */
function apiBulkUpdateSubmissions(string $trackerId): void
{
    $app = app();
    $app->requireAuth();
    $db = $app->db();
    $user = $app->user();
    
    $studentIds = $app->input('student_ids', []);
    $itemId = $app->input('tracker_item_id');
    $status = $app->input('status', 'submitted');
    
    if (empty($studentIds) || !$itemId) {
        $app->json(['error' => 'Students and item are required'], 422);
    }
    
    $stmt = $db->prepare("
        INSERT INTO gm_tracker_submissions (tracker_student_id, tracker_item_id, status, submitted_at, verified_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), submitted_at = COALESCE(submitted_at, VALUES(submitted_at)),
            verified_by = IF(VALUES(status) = 'verified', VALUES(verified_by), verified_by), updated_at = NOW()
    ");
    
    $now = date('Y-m-d H:i:s');
    $count = 0;
    foreach ($studentIds as $sid) {
        $stmt->execute([$sid, $itemId, $status, $now, $status === 'verified' ? $user['sub'] : null]);
        $count++;
    }
    
    if ($app->isHtmx()) {
        header('HX-Trigger: refreshStudents');
        header('HX-Reswap: none');
    }
    $app->json(['success' => true, 'updated' => $count]);
}

/**
 * Delete student from tracker
 */
function apiDeleteTrackerStudent(string $trackerId, string $studentId): void
{
    $app = app();
    $app->requireAnyRole('admin', 'supervisor');
    $db = $app->db();
    
    $stmt = $db->prepare("DELETE FROM gm_tracker_students WHERE id = ? AND tracker_id = ?");
    $stmt->execute([$studentId, $trackerId]);
    
    if ($app->isHtmx()) {
        header('HX-Trigger: refreshStudents');
        header('HX-Reswap: none');
    }
    $app->json(['success' => true]);
}

// ============================================================
// CSV IMPORT
// ============================================================

/**
 * CSV Import Preview — parse headers, suggest column mapping
 */
function apiTrackerImportPreview(string $trackerId): void
{
    $app = app();
    $app->requireAnyRole('admin', 'supervisor');
    $db = $app->db();
    
    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $app->json(['error' => 'Please upload a valid CSV file'], 422);
    }
    
    $file = $_FILES['csv_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'])) {
        $app->json(['error' => 'Only CSV files are accepted'], 422);
    }
    
    // Parse CSV
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        $app->json(['error' => 'Failed to read CSV file'], 500);
    }
    
    // Read headers
    $headers = fgetcsv($handle);
    if (!$headers || count($headers) < 1) {
        fclose($handle);
        $app->json(['error' => 'CSV file has no headers'], 422);
    }
    
    // Clean BOM and whitespace
    $headers = array_map(function($h) {
        return trim(preg_replace('/^\x{FEFF}/u', '', $h));
    }, $headers);
    
    // Read preview rows (first 5)
    $previewRows = [];
    $rowCount = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $rowCount++;
        if (count($previewRows) < 5) {
            $previewRows[] = $row;
        }
    }
    fclose($handle);
    
    // Save temp file for later execution
    $tempPath = STORAGE_PATH . '/cache/import_' . md5($trackerId . time()) . '.csv';
    move_uploaded_file($file['tmp_name'], $tempPath);
    
    // Known DB columns for mapping suggestions
    $knownColumns = [
        'student_id' => ['student id', 'student_id', 'id number', 'id no', 'student number', 'stud id'],
        'student_name' => ['student name', 'student_name', 'name', 'full name', 'fullname'],
        'year_level' => ['year level', 'year_level', 'year', 'grade', 'grade level'],
        'section' => ['section', 'sec', 'class'],
        'email' => ['email', 'email address', 'e-mail'],
        'phone' => ['phone', 'phone number', 'mobile', 'contact', 'contact number', 'cellphone'],
        'notes' => ['notes', 'remarks', 'comment', 'comments'],
    ];
    
    // Get existing custom fields for this tracker
    $cfStmt = $db->prepare("SELECT column_name, display_label FROM gm_tracker_custom_fields WHERE tracker_id = ?");
    $cfStmt->execute([$trackerId]);
    $existingCustom = $cfStmt->fetchAll();
    foreach ($existingCustom as $cf) {
        $knownColumns[$cf['column_name']] = [strtolower($cf['display_label'])];
    }
    
    // Get college names for mapping
    $colleges = $db->query("SELECT id, name, code FROM gm_colleges ORDER BY name")->fetchAll();
    $knownColumns['college_id'] = ['college', 'college_id', 'department', 'dept'];
    
    // Auto-map headers to DB columns
    $mapping = [];
    foreach ($headers as $i => $header) {
        $headerLower = strtolower(trim($header));
        $matched = null;
        
        foreach ($knownColumns as $dbCol => $aliases) {
            foreach ($aliases as $alias) {
                if ($headerLower === $alias || similar_text($headerLower, $alias, $pct) && $pct > 80) {
                    $matched = $dbCol;
                    break 2;
                }
            }
        }
        
        $mapping[] = [
            'csv_index' => $i,
            'csv_header' => $header,
            'suggested_column' => $matched,
            'action' => $matched ? 'map' : 'create_new', // map, create_new, skip
        ];
    }
    
    $result = [
        'temp_file' => basename($tempPath),
        'headers' => $headers,
        'mapping' => $mapping,
        'preview_rows' => $previewRows,
        'total_rows' => $rowCount,
        'known_columns' => array_keys($knownColumns),
        'colleges' => $colleges,
    ];
    
    if ($app->isHtmx()) {
        echo $app->render('partials/tracker-import-mapping', $result + ['tracker_id' => $trackerId]);
    } else {
        $app->json($result);
    }
}

/**
 * CSV Import Execute — apply mapping and import rows
 */
function apiTrackerImportExecute(string $trackerId): void
{
    $app = app();
    $app->requireAnyRole('admin', 'supervisor');
    $db = $app->db();
    
    $tempFile = $app->input('temp_file', '');
    $mappingJson = $app->input('mapping', '[]');
    
    $tempPath = STORAGE_PATH . '/cache/' . basename($tempFile);
    if (!file_exists($tempPath)) {
        $app->json(['error' => 'Import session expired. Please upload the CSV again.'], 422);
    }
    
    $mapping = is_string($mappingJson) ? json_decode($mappingJson, true) : $mappingJson;
    if (!is_array($mapping) || empty($mapping)) {
        $app->json(['error' => 'Invalid column mapping'], 422);
    }
    
    // Get colleges for name-to-id resolution
    $colleges = [];
    foreach ($db->query("SELECT id, name, code FROM gm_colleges")->fetchAll() as $c) {
        $colleges[strtolower($c['name'])] = $c['id'];
        $colleges[strtolower($c['code'])] = $c['id'];
    }
    
    // Process mapping: create new columns if needed
    $columnMap = []; // csv_index => db_column_name
    $newColumns = [];
    
    foreach ($mapping as $m) {
        $csvIndex = (int) $m['csv_index'];
        $action = $m['action'] ?? 'skip';
        $dbColumn = $m['db_column'] ?? $m['suggested_column'] ?? '';
        $csvHeader = $m['csv_header'] ?? '';
        
        if ($action === 'skip') {
            continue;
        }
        
        if ($action === 'create_new') {
            // Sanitize column name
            $colName = 'custom_' . preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($csvHeader)));
            $colName = preg_replace('/_+/', '_', $colName);
            $colName = substr($colName, 0, 64);
            
            // Check if column already exists
            $existsStmt = $db->prepare("SELECT id FROM gm_tracker_custom_fields WHERE tracker_id = ? AND column_name = ?");
            $existsStmt->execute([$trackerId, $colName]);
            
            if (!$existsStmt->fetch()) {
                // Add column to gm_tracker_students
                try {
                    $db->exec("ALTER TABLE gm_tracker_students ADD COLUMN `{$colName}` VARCHAR(255) DEFAULT NULL");
                } catch (\Exception $e) {
                    // Column may already exist from a previous import
                }
                
                // Register in custom fields
                $regStmt = $db->prepare("
                    INSERT IGNORE INTO gm_tracker_custom_fields (tracker_id, column_name, display_label, field_type, source)
                    VALUES (?, ?, ?, 'text', 'csv_import')
                ");
                $regStmt->execute([$trackerId, $colName, $csvHeader]);
                $newColumns[] = $colName;
            }
            
            $columnMap[$csvIndex] = $colName;
        } else {
            // Map to existing column
            $columnMap[$csvIndex] = $dbColumn;
        }
    }
    
    // Read CSV and import
    $handle = fopen($tempPath, 'r');
    $headers = fgetcsv($handle); // Skip header row
    
    // Clean BOM
    if ($headers) {
        $headers[0] = preg_replace('/^\x{FEFF}/u', '', $headers[0]);
    }
    
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    // Get tracker items for auto-creating submissions
    $itemIds = $db->prepare("SELECT id FROM gm_tracker_items WHERE tracker_id = ?");
    $itemIds->execute([$trackerId]);
    $trackerItemIds = array_column($itemIds->fetchAll(), 'id');
    
    while (($row = fgetcsv($handle)) !== false) {
        if (empty(array_filter($row))) {
            $skipped++;
            continue; // Skip empty rows
        }
        
        // Build insert data
        $data = ['tracker_id' => $trackerId];
        $hasName = false;
        
        foreach ($columnMap as $csvIdx => $dbCol) {
            $value = isset($row[$csvIdx]) ? trim($row[$csvIdx]) : '';
            
            if ($dbCol === 'student_name' && $value) {
                $hasName = true;
            }
            
            // Resolve college name to ID
            if ($dbCol === 'college_id' && $value) {
                $resolved = $colleges[strtolower($value)] ?? null;
                $value = $resolved;
            }
            
            $data[$dbCol] = $value !== '' ? $value : null;
        }
        
        if (!$hasName) {
            $skipped++;
            continue; // Must have student name
        }
        
        // Insert student
        try {
            $cols = array_keys($data);
            $placeholders = array_fill(0, count($cols), '?');
            $sql = "INSERT INTO gm_tracker_students (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($data));
            $tsId = $db->lastInsertId();
            
            // Create pending submissions for all items
            $subStmt = $db->prepare("INSERT INTO gm_tracker_submissions (tracker_student_id, tracker_item_id) VALUES (?, ?)");
            foreach ($trackerItemIds as $tiId) {
                $subStmt->execute([$tsId, $tiId]);
            }
            
            $imported++;
        } catch (\Exception $e) {
            $errors[] = "Row " . ($imported + $skipped + count($errors) + 2) . ": " . $e->getMessage();
            if (count($errors) > 10) break; // Stop after too many errors
        }
    }
    
    fclose($handle);
    @unlink($tempPath); // Clean up temp file
    
    $result = [
        'success' => true,
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors,
        'new_columns' => $newColumns,
    ];
    
    if ($app->isHtmx()) {
        header('HX-Trigger: refreshStudents, refreshItems');
        echo $app->render('partials/tracker-import-result', $result);
    } else {
        $app->json($result);
    }
}

/**
 * CSV Import Template — download a blank CSV with correct headers for mass student import
 */
function apiTrackerImportTemplate(string $trackerId): void
{
    $app = app();
    $app->requireAuth();
    $db = $app->db();
    
    // Get tracker
    $tracker = $db->prepare("SELECT * FROM gm_trackers WHERE id = ?");
    $tracker->execute([$trackerId]);
    $tracker = $tracker->fetch();
    if (!$tracker) {
        $app->json(['error' => 'Tracker not found'], 404);
    }
    
    // Get colleges for reference sheet
    $colleges = $db->query("SELECT code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll();
    
    // Build CSV
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tracker['name']) . '_import_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Write BOM for Excel compatibility
    fwrite($output, "\xEF\xBB\xBF");
    
    // Header row — matches the import column mapping expectations
    $headerRow = ['Student ID', 'Student Name', 'College', 'Year Level', 'Section', 'Email', 'Phone'];
    fputcsv($output, $headerRow);
    
    // Sample rows to guide users
    $sampleCollege = !empty($colleges) ? $colleges[0]['code'] . ' - ' . $colleges[0]['name'] : 'CAS - College of Arts and Sciences';
    fputcsv($output, ['2024-0001', 'Juan Dela Cruz', $sampleCollege, '1st Year', 'A', 'juan@email.com', '09171234567']);
    fputcsv($output, ['2024-0002', 'Maria Santos', $sampleCollege, '2nd Year', 'B', 'maria@email.com', '09181234567']);
    
    // Instructions row
    fputcsv($output, []);
    fputcsv($output, ['--- INSTRUCTIONS (delete these rows before importing) ---']);
    fputcsv($output, ['Student ID', 'School ID number (optional)']);
    fputcsv($output, ['Student Name', 'Full name (required)']);
    fputcsv($output, ['College', 'Must match one of the colleges below']);
    fputcsv($output, ['Year Level', 'One of: 1st Year, 2nd Year, 3rd Year, 4th Year, 5th Year, Graduate, SHS-11, SHS-12']);
    fputcsv($output, ['Section', 'Section letter or name (optional)']);
    fputcsv($output, ['Email', 'Student email address (optional)']);
    fputcsv($output, ['Phone', 'Mobile number (optional)']);
    fputcsv($output, []);
    fputcsv($output, ['--- AVAILABLE COLLEGES ---']);
    foreach ($colleges as $c) {
        fputcsv($output, [$c['code'] . ' - ' . $c['name']]);
    }
    
    fclose($output);
    exit;
}

/**
 * CSV Export
 */
function apiTrackerExport(string $trackerId): void
{
    $app = app();
    $app->requireAuth();
    $db = $app->db();
    
    // Get tracker
    $tracker = $db->prepare("SELECT * FROM gm_trackers WHERE id = ?");
    $tracker->execute([$trackerId]);
    $tracker = $tracker->fetch();
    if (!$tracker) {
        $app->json(['error' => 'Tracker not found'], 404);
    }
    
    // Get items
    $items = $db->prepare("SELECT * FROM gm_tracker_items WHERE tracker_id = ? ORDER BY sort_order, id");
    $items->execute([$trackerId]);
    $items = $items->fetchAll();
    
    // Get custom fields
    $cfStmt = $db->prepare("SELECT * FROM gm_tracker_custom_fields WHERE tracker_id = ? ORDER BY id");
    $cfStmt->execute([$trackerId]);
    $customFields = $cfStmt->fetchAll();
    
    // Get students with submissions
    $students = $db->prepare("SELECT ts.*, c.name as college_name FROM gm_tracker_students ts LEFT JOIN gm_colleges c ON ts.college_id = c.id WHERE ts.tracker_id = ? ORDER BY ts.student_name");
    $students->execute([$trackerId]);
    $students = $students->fetchAll();
    
    // Build CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $tracker['name']) . '_export.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header row
    $headerRow = ['Student ID', 'Student Name', 'College', 'Year Level', 'Section', 'Email', 'Phone'];
    foreach ($customFields as $cf) {
        $headerRow[] = $cf['display_label'];
    }
    foreach ($items as $item) {
        $headerRow[] = $item['name'] . ' (Status)';
    }
    $headerRow[] = 'Completion %';
    fputcsv($output, $headerRow);
    
    // Data rows
    foreach ($students as $student) {
        $row = [
            $student['student_id'] ?? '',
            $student['student_name'],
            $student['college_name'] ?? '',
            $student['year_level'] ?? '',
            $student['section'] ?? '',
            $student['email'] ?? '',
            $student['phone'] ?? '',
        ];
        
        // Custom fields
        foreach ($customFields as $cf) {
            $row[] = $student[$cf['column_name']] ?? '';
        }
        
        // Submissions
        $subStmt = $db->prepare("SELECT sub.status FROM gm_tracker_submissions sub WHERE sub.tracker_student_id = ? AND sub.tracker_item_id = ?");
        $completed = 0;
        foreach ($items as $item) {
            $subStmt->execute([$student['id'], $item['id']]);
            $sub = $subStmt->fetch();
            $status = $sub ? $sub['status'] : 'pending';
            $row[] = $status;
            if (in_array($status, ['submitted', 'verified'])) $completed++;
        }
        
        $row[] = count($items) > 0 ? round(($completed / count($items)) * 100) . '%' : 'N/A';
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// ============================================================
// HELPERS
// ============================================================

/**
 * Get overall completion stats for a tracker
 */
function getTrackerCompletion(\PDO $db, int $trackerId): array
{
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT ts.id) as total_students,
            COUNT(sub.id) as total_submissions,
            SUM(CASE WHEN sub.status IN ('submitted', 'verified') THEN 1 ELSE 0 END) as completed_submissions,
            SUM(CASE WHEN sub.status = 'verified' THEN 1 ELSE 0 END) as verified_submissions
        FROM gm_tracker_students ts
        LEFT JOIN gm_tracker_submissions sub ON sub.tracker_student_id = ts.id
        WHERE ts.tracker_id = ?
    ");
    $stmt->execute([$trackerId]);
    $row = $stmt->fetch();
    
    $totalSubs = (int) $row['total_submissions'];
    $completedSubs = (int) $row['completed_submissions'];
    
    return [
        'total_students' => (int) $row['total_students'],
        'total_submissions' => $totalSubs,
        'completed_submissions' => $completedSubs,
        'verified_submissions' => (int) $row['verified_submissions'],
        'percentage' => $totalSubs > 0 ? round(($completedSubs / $totalSubs) * 100) : 0,
    ];
}
