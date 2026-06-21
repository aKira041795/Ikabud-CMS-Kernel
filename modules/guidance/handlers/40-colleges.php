<?php
/**
 * College Route Handlers
 * 
 * @package Guidance\Routes
 */

function apiGuidanceListColleges(): void {
    guidanceUser();
    $db = guidanceDb();
    $stmt = $db->query("
        SELECT c.*, 
               (SELECT COUNT(*) FROM gm_counselor_assignments ca JOIN gm_users u ON ca.counselor_id = u.id WHERE ca.college_id = c.id AND ca.is_active = 1 AND u.role != 'admin') as counselor_count
        FROM gm_colleges c 
        ORDER BY c.sort_order, c.name
    ");
    $colleges = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (app()->isHtmx()) {
        echo guidanceRender('partials/colleges-table.disyl', ['colleges' => $colleges]);
    } else {
        app()->json(['success' => true, 'data' => $colleges]);
    }
}

function apiGuidanceGetCollege(string $id): void {
    app()->requireRole('admin');
    $db = guidanceDb();
    $stmt = $db->prepare("SELECT * FROM gm_colleges WHERE id = ?");
    $stmt->execute([$id]);
    $college = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$college) { app()->json(['error' => 'College not found'], 404); return; }
    app()->json(['success' => true, 'data' => $college]);
}

function apiGuidanceCreateCollege(): void {
    app()->requireRole('admin');
    $input = app()->input();
    if (empty($input['code']) || empty($input['name'])) {
        app()->json(['error' => 'Code and name are required'], 400);
        return;
    }
    $db = guidanceDb();
    $stmt = $db->prepare("INSERT INTO gm_colleges (code, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([strtoupper($input['code']), $input['name'], $input['description'] ?? '', (int)($input['sort_order'] ?? 0)]);
    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'College created', 'type' => 'success'], 'refreshColleges' => true]));
        echo '';
    } else {
        app()->json(['success' => true, 'id' => $db->lastInsertId()]);
    }
}

function apiGuidanceUpdateCollege(string $id): void {
    app()->requireRole('admin');
    $input = app()->input();
    $db = guidanceDb();
    $stmt = $db->prepare("UPDATE gm_colleges SET code = ?, name = ?, description = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([
        strtoupper($input['code'] ?? ''), $input['name'] ?? '', $input['description'] ?? '',
        (int)($input['sort_order'] ?? 0), (int)($input['is_active'] ?? 1), $id
    ]);
    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'College updated', 'type' => 'success'], 'refreshColleges' => true]));
        echo '';
    } else {
        app()->json(['success' => true]);
    }
}

function apiGuidanceDeleteCollege(string $id): void {
    app()->requireRole('admin');
    $db = guidanceDb();
    $db->prepare("UPDATE gm_colleges SET is_active = 0, updated_at = NOW() WHERE id = ?")->execute([$id]);
    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'College deactivated', 'type' => 'success'], 'refreshColleges' => true]));
        echo '';
    } else {
        app()->json(['success' => true]);
    }
}

function apiGuidanceModalEditCollege(string $id): void {
    app()->requireRole('admin');
    $db = guidanceDb();
    $stmt = $db->prepare("SELECT * FROM gm_colleges WHERE id = ?");
    $stmt->execute([$id]);
    $college = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$college) {
        echo '<div class="p-4 text-red-600">College not found</div>';
        return;
    }
    // Get assigned counselors
    $cStmt = $db->prepare("
        SELECT ca.counselor_id, CONCAT(u.first_name, ' ', u.last_name) as name, u.email, ca.is_primary
        FROM gm_counselor_assignments ca
        JOIN gm_users u ON ca.counselor_id = u.id
        WHERE ca.college_id = ? AND ca.is_active = 1 AND u.deleted_at IS NULL
        ORDER BY u.first_name
    ");
    $cStmt->execute([$college['id']]);
    $assignedCounselors = $cStmt->fetchAll(PDO::FETCH_ASSOC);
    echo guidanceRender('modals/college-form.disyl', ['college' => $college, 'assigned_counselors' => $assignedCounselors]);
}

function apiGuidanceGetUserColleges(string $userId): void {
    guidanceUser();
    $db = guidanceDb();
    $stmt = $db->prepare("
        SELECT c.id, c.code, c.name 
        FROM gm_colleges c
        JOIN gm_counselor_assignments ca ON c.id = ca.college_id
        WHERE ca.counselor_id = ? AND ca.is_active = 1 AND c.is_active = 1
        ORDER BY c.sort_order, c.name
    ");
    $stmt->execute([$userId]);
    app()->json(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function apiGuidanceSaveUserColleges(string $userId): void {
    app()->requireRole('admin');
    $input = app()->input();
    $collegeIds = $input['college_ids'] ?? [];
    $db = guidanceDb();
    $currentUser = app()->user();
    
    // Deactivate all current assignments
    $db->prepare("UPDATE gm_counselor_assignments SET is_active = 0 WHERE counselor_id = ?")->execute([$userId]);
    
    // Insert/reactivate selected colleges
    if (!empty($collegeIds)) {
        foreach ($collegeIds as $collegeId) {
            $existing = $db->prepare("SELECT id FROM gm_counselor_assignments WHERE counselor_id = ? AND college_id = ?");
            $existing->execute([$userId, $collegeId]);
            if ($row = $existing->fetch()) {
                $db->prepare("UPDATE gm_counselor_assignments SET is_active = 1, assigned_by = ? WHERE id = ?")->execute([$currentUser['sub'], $row['id']]);
            } else {
                $db->prepare("INSERT INTO gm_counselor_assignments (counselor_id, college_id, is_active, assigned_by, assigned_at) VALUES (?, ?, 1, ?, NOW())")
                   ->execute([$userId, $collegeId, $currentUser['sub']]);
            }
        }
    }
    
    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'College assignments updated', 'type' => 'success'], 'refreshUsers' => true]));
        echo '';
    } else {
        app()->json(['success' => true]);
    }
}
