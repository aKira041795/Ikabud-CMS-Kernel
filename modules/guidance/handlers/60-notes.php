<?php
/**
 * Notes Route Handlers
 * 
 * @package Guidance\Routes
 */

function apiGuidanceListNotes(string $caseId): void {
    $user = guidanceUser();
    guidanceRequirePro();
    guidanceRequireStaff();
    $db = guidanceDb();
    
    try {
        $caseStmt = $db->prepare("SELECT counselor_id FROM gm_cases WHERE id = ?");
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch();
        
        if (!$case) {
            app()->json(['error' => 'Case not found'], 404);
        }
        if ($user['role'] === 'counselor' && $case['counselor_id'] != $user['sub']) {
            app()->json(['error' => 'Access denied'], 403);
        }
        
        $stmt = $db->prepare("
            SELECT n.*, CONCAT(u.first_name, ' ', u.last_name) as counselor_name
            FROM gm_counselor_notes n
            LEFT JOIN gm_users u ON n.counselor_id = u.id
            WHERE n.case_id = ?
            ORDER BY n.session_date DESC, n.created_at DESC
        ");
        $stmt->execute([$caseId]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (app()->isHtmx()) {
            echo guidanceRender('partials/notes-list.disyl', ['notes' => $notes]);
            exit;
        }
        app()->json(['success' => true, 'data' => $notes]);
    } catch (Exception $e) {
        app()->log('Notes list error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to fetch notes'], 500);
    }
}

function apiGuidanceCreateNote(string|int $caseId): void {
    $user = guidanceUser();
    guidanceRequirePro();
    guidanceRequireStaff();
    
    if (!in_array($user['role'], ['counselor', 'supervisor', 'admin'])) {
        app()->json(['error' => 'Permission denied'], 403);
    }
    
    $input = app()->input();
    
    $noteContent = $input['note_content'] ?? $input['content'] ?? '';
    if (empty($noteContent)) {
        app()->json(['error' => 'Note content is required'], 400);
    }
    
    try {
        $db = guidanceDb();
        
        $caseStmt = $db->prepare("SELECT id, counselor_id FROM gm_cases WHERE id = ? AND deleted_at IS NULL");
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch();
        
        if (!$case) {
            app()->json(['error' => 'Case not found'], 404);
        }
        
        $stmt = $db->prepare("
            INSERT INTO gm_counselor_notes (
                case_id, counselor_id, note_type, session_type, session_date, session_duration_minutes,
                note_content, intervention_used, student_response, risk_level, mood_assessment,
                action_taken,
                mse_appearance, mse_behavior, mse_speech, mse_emotions,
                mse_thinking, mse_cognition, mse_judgment, mse_reliability,
                case_predisposition, case_precipitating, case_perpetuating, case_protective,
                observation_recommendation,
                followup_required, followup_notes, is_confidential,
                sync_id, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $caseId,
            $user['sub'],
            $input['note_type'] ?? 'session',
            $input['session_type'] ?? 'walk-in',
            $input['session_date'] ?? $input['note_date'] ?? date('Y-m-d'),
            $input['session_duration_minutes'] ?? null,
            $noteContent,
            $input['intervention_used'] ?? null,
            $input['student_response'] ?? null,
            $input['risk_level'] ?? 'none',
            $input['mood_assessment'] ?? null,
            $input['action_taken'] ?? null,
            $input['mse_appearance'] ?? null,
            $input['mse_behavior'] ?? null,
            $input['mse_speech'] ?? null,
            $input['mse_emotions'] ?? null,
            $input['mse_thinking'] ?? null,
            $input['mse_cognition'] ?? null,
            $input['mse_judgment'] ?? null,
            $input['mse_reliability'] ?? null,
            $input['case_predisposition'] ?? null,
            $input['case_precipitating'] ?? null,
            $input['case_perpetuating'] ?? null,
            $input['case_protective'] ?? null,
            $input['observation_recommendation'] ?? null,
            $input['followup_required'] ?? 0,
            $input['followup_notes'] ?? null,
            $input['is_confidential'] ?? 0,
            $input['sync_id'] ?? uniqid('sync_', true),
            $user['sub'],
        ]);
        $noteId = $db->lastInsertId();
        
        // Update case activity timestamp
        $db->prepare("UPDATE gm_cases SET updated_at = NOW() WHERE id = ?")->execute([$caseId]);
        
        fireModuleHook('note.created', [
            'note_id' => $noteId,
            'case_id' => $caseId,
            'counselor_id' => $user['sub'],
            'note_type' => $input['note_type'] ?? 'session',
            'session_date' => $input['session_date'] ?? date('Y-m-d'),
            'risk_level' => $input['risk_level'] ?? 'none',
            'followup_required' => !empty($input['followup_required']),
        ]);
        
        if (app()->isHtmx()) {
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'Note added successfully', 'type' => 'success'], 'closeModal' => true, 'refreshNotes' => true]));
            header('HX-Refresh: true');
            echo '';
            return;
        }
        app()->json(['success' => true, 'data' => ['id' => $noteId]], 201);
    } catch (Exception $e) {
        app()->log('Notes create error: ' . $e->getMessage(), 'error');
        app()->json(['error' => 'Failed to create note'], 500);
    }
}
