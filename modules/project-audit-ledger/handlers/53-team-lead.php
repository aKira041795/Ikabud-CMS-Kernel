<?php

declare(strict_types=1);

/**
 * Team Lead Dashboard
 * GET /admin/project-audit-ledger/team-lead
 */
function palPageTeamLeadDashboard(): void
{
    $tl = palTeamLeadGuard();
    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $tlId = (int)$tl['team_lead_id'];

    // Get assigned projects with fab info
    $projStmt = $db->prepare("
        SELECT p.id, p.title, p.job_order_number, p.contract_amount, p.status,
               (p.contract_amount * COALESCE(p.fabrication_alloc_pct, 0) / 100) AS fab_budget,
               COALESCE((SELECT SUM(fa.approved_amount) FROM pal_fabrication_allocations fa WHERE fa.project_id = p.id AND fa.tenant_id = p.tenant_id), 0) AS fab_dispensed
        FROM pal_projects p
        WHERE p.fabrication_team_lead_id = :tlid AND p.tenant_id = :tid
          AND p.status IN ('pending','approved','started','ongoing','completed')
        ORDER BY p.created_at DESC
        LIMIT 20
    ");
    $projStmt->execute([':tlid' => $tlId, ':tid' => $tid]);
    $projects = $projStmt->fetchAll(PDO::FETCH_ASSOC);

    // Count active fabrications
    $fabCount = 0;
    foreach ($projects as $p) {
        if ($p['fab_budget'] > 0) $fabCount++;
    }

    // Count pending approvals (CA + mobilization)
    $pendStmt = $db->prepare("
        SELECT COUNT(*) FROM pal_cash_advances
        WHERE team_lead_id = :tlid AND tenant_id = :tid AND status = 'pending'
    ");
    $pendStmt->execute([':tlid' => $tlId, ':tid' => $tid]);
    $pendingCount = (int)$pendStmt->fetchColumn();

    $mobStmt = $db->prepare("
        SELECT COUNT(*) FROM pal_mobilization_requests
        WHERE team_lead_id = :tlid AND tenant_id = :tid AND status = 'pending'
    ");
    $mobStmt->execute([':tlid' => $tlId, ':tid' => $tid]);
    $pendingCount += (int)$mobStmt->fetchColumn();

    $t = __DIR__ . '/../templates/project-audit-ledger/team-lead-shell.disyl';
    palRender($t, [
        'current_user' => $tl,
        'tl' => $tl,
        'page_title' => 'Team Lead Dashboard',
        'page_content' => 'team-lead-dashboard',
        'project_count' => count($projects),
        'fab_count' => $fabCount,
        'pending_count' => $pendingCount,
        'projects' => $projects,
    ]);
}

/**
 * Team Lead Fabrication View
 * GET /admin/project-audit-ledger/team-lead/fabrication
 */
function palPageTeamLeadFabrication(): void
{
    $tl = palTeamLeadGuard();
    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $tlId = (int)$tl['team_lead_id'];

    // Get projects with fabrication allocations
    $projStmt = $db->prepare("
        SELECT p.id, p.title, p.job_order_number, p.contract_amount, p.status,
               p.fabrication_alloc_pct, p.fabrication_alloc_basis,
               (p.contract_amount * COALESCE(p.fabrication_alloc_pct, 0) / 100) AS fab_budget,
               COALESCE((SELECT SUM(fa.approved_amount) FROM pal_fabrication_allocations fa WHERE fa.project_id = p.id AND fa.tenant_id = p.tenant_id), 0) AS total_dispensed
        FROM pal_projects p
        WHERE p.fabrication_team_lead_id = :tlid AND p.tenant_id = :tid
          AND p.status IN ('pending','approved','started','ongoing')
        ORDER BY p.created_at DESC
    ");
    $projStmt->execute([':tlid' => $tlId, ':tid' => $tid]);
    $projects = $projStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get weekly dues for each project
    foreach ($projects as &$p) {
        $dueStmt = $db->prepare("
            SELECT id, week_number, due_amount, paid_amount, balance, due_date, status
            FROM pal_fabrication_weekly_dues
            WHERE project_id = :pid AND tenant_id = :tid
            ORDER BY week_number DESC
            LIMIT 10
        ");
        $dueStmt->execute([':pid' => $p['id'], ':tid' => $tid]);
        $p['weekly_dues'] = $dueStmt->fetchAll(PDO::FETCH_ASSOC);
        $p['remaining'] = max(0, $p['fab_budget'] - $p['total_dispensed']);
    }
    unset($p);

    $t = __DIR__ . '/../templates/project-audit-ledger/team-lead-shell.disyl';
    palRender($t, [
        'current_user' => $tl,
        'tl' => $tl,
        'page_title' => 'My Fabrications',
        'page_content' => 'team-lead-fabrication',
        'projects' => $projects,
    ]);
}

/**
 * Team Lead Cash Advances
 * GET /admin/project-audit-ledger/team-lead/cash-advances
 */
function palPageTeamLeadCashAdvances(): void
{
    $tl = palTeamLeadGuard();
    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $tlId = (int)$tl['team_lead_id'];

    $stmt = $db->prepare("
        SELECT ca.*, p.title AS project_title
        FROM pal_cash_advances ca
        LEFT JOIN pal_projects p ON ca.project_id = p.id
        WHERE ca.team_lead_id = :tlid AND ca.tenant_id = :tid
        ORDER BY ca.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([':tlid' => $tlId, ':tid' => $tid]);
    $advances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $t = __DIR__ . '/../templates/project-audit-ledger/team-lead-shell.disyl';
    palRender($t, [
        'current_user' => $tl,
        'tl' => $tl,
        'page_title' => 'My Cash Advances',
        'page_content' => 'team-lead-ca-list',
        'advances' => $advances,
    ]);
}

/**
 * Team Lead Cash Advance Form
 * GET /admin/project-audit-ledger/team-lead/cash-advances/create
 */
function palPageTeamLeadCashAdvanceForm(): void
{
    $tl = palTeamLeadGuard();
    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $tlId = (int)$tl['team_lead_id'];

    $projects = $db->prepare("
        SELECT id, title, job_order_number
        FROM pal_projects
        WHERE fabrication_team_lead_id = :tlid AND tenant_id = :tid
          AND status IN ('pending','approved','started','ongoing')
        ORDER BY title
    ");
    $projects->execute([':tlid' => $tlId, ':tid' => $tid]);

    $t = __DIR__ . '/../templates/project-audit-ledger/team-lead-shell.disyl';
    palRender($t, [
        'current_user' => $tl,
        'tl' => $tl,
        'page_title' => 'Request Cash Advance',
        'page_content' => 'team-lead-ca-form',
        'projects' => $projects->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

/**
 * API: Team Lead submits CA request
 * POST /api/v1/project-audit-ledger/tl/cash-advances
 */
function palApiTeamLeadCashAdvanceStore(): void
{
    try {
        $tl = palTeamLeadGuard();
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $tlId = (int)$tl['team_lead_id'];

        $amount = (float)($_POST['amount'] ?? 0);
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $description = $_POST['description'] ?? null;

        if ($amount <= 0) { palJsonError('Amount is required.'); return; }

        $db->beginTransaction();
        try {
            // Insert CA record
            $stmt = $db->prepare("INSERT INTO pal_cash_advances (tenant_id, team_lead_id, project_id, amount, advance_date, description, status, created_by)
                                  VALUES (:t, :tl, :pj, :amt, CURDATE(), :desc, 'pending', :cb)");
            $stmt->execute([':t' => $tid, ':tl' => $tlId, ':pj' => $projectId, ':amt' => $amount, ':desc' => $description, ':cb' => 0]);
            $caId = (int)$db->lastInsertId();

            // Create approval record
            $approvalId = palCreateApproval('cash_advance', $caId, 0, 'pending', 'pending_approval');

            $db->commit();

            palAudit('pal.cash_advance.requested', 0, 'pal_cash_advances', (string)$caId,
                null, ['amount' => $amount, 'team_lead_id' => $tlId]);
            palFireEvent('pal.ca.requested', ['cash_advance_id' => $caId, 'amount' => $amount]);

            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'id' => $caId]);
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (Throwable $e) {
        palJsonError('Failed to submit request.');
    }
}

// ── Mobilization Handlers ──

/**
 * Team Lead Mobilization List
 * GET /admin/project-audit-ledger/team-lead/mobilization
 */
function palPageTeamLeadMobilization(): void
{
    $tl = palTeamLeadGuard();
    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $tlId = (int)$tl['team_lead_id'];

    $stmt = $db->prepare("
        SELECT mr.*, p.title AS project_title
        FROM pal_mobilization_requests mr
        LEFT JOIN pal_projects p ON mr.project_id = p.id
        WHERE mr.team_lead_id = :tlid AND mr.tenant_id = :tid
        ORDER BY mr.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([':tlid' => $tlId, ':tid' => $tid]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $t = __DIR__ . '/../templates/project-audit-ledger/team-lead-shell.disyl';
    palRender($t, [
        'current_user' => $tl,
        'tl' => $tl,
        'page_title' => 'Mobilization Requests',
        'page_content' => 'team-lead-mobilization-list',
        'requests' => $requests,
    ]);
}

/**
 * Team Lead Mobilization Form
 * GET /admin/project-audit-ledger/team-lead/mobilization/create
 *
 * Accepts optional attendance context from AW team-lead dashboard:
 *   ?attendance_group_id={}&date_from={}&date_to={}
 * When present, calls AW capability for wage/evidence summary to display
 * alongside the amount/purpose form fields.
 */
function palPageTeamLeadMobilizationForm(): void
{
    $tl = palTeamLeadGuard();
    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $tlId = (int)$tl['team_lead_id'];
    $tlEmail = $tl['email'] ?? '';

    $projects = $db->prepare("
        SELECT id, title, job_order_number
        FROM pal_projects
        WHERE fabrication_team_lead_id = :tlid AND tenant_id = :tid
          AND status IN ('pending','approved','started','ongoing')
        ORDER BY title
    ");
    $projects->execute([':tlid' => $tlId, ':tid' => $tid]);

    // Attendance context from AW dashboard (optional)
    $attGroupId = !empty($_GET['attendance_group_id']) ? (int)$_GET['attendance_group_id'] : null;
    $attDateFrom = $_GET['date_from'] ?? '';
    $attDateTo = $_GET['date_to'] ?? '';
    $attendanceSummary = null;

    if ($attGroupId !== null && $attGroupId > 0 && $attDateFrom !== '' && $attDateTo !== '') {
        // Validate date format
        $dFrom = \DateTime::createFromFormat('Y-m-d', $attDateFrom);
        $dTo = \DateTime::createFromFormat('Y-m-d', $attDateTo);
        if ($dFrom && $dTo) {
            try {
                $result = app()->cap()->call('attendance_wage.team_attendance.summary@1', [
                    'tenant_id' => (string)$tid,
                    'team_lead_email' => $tlEmail,
                    'date_from' => $attDateFrom,
                    'date_to' => $attDateTo,
                    'group_id' => $attGroupId,
                ], [
                    'caller' => ['module' => 'project-audit-ledger'],
                    'mode' => 'first',
                ]);

                if (is_array($result) && !empty($result['ok'])) {
                    $attendanceSummary = $result;
                }
            } catch (\Throwable $e) {
                if (function_exists('write_log')) {
                    write_log('pal_mob_form: capability call failed: ' . $e->getMessage(), 'warning');
                }
            }
        }
    }

    $t = __DIR__ . '/../templates/project-audit-ledger/team-lead-shell.disyl';
    palRender($t, [
        'current_user' => $tl,
        'tl' => $tl,
        'page_title' => 'Request Mobilization',
        'page_content' => 'team-lead-mobilization-form',
        'projects' => $projects->fetchAll(PDO::FETCH_ASSOC),
        'attendance_group_id' => $attGroupId,
        'attendance_date_from' => $attDateFrom,
        'attendance_date_to' => $attDateTo,
        'attendance_summary' => $attendanceSummary,
    ]);
}

/**
 * API: Team Lead submits mobilization request
 * POST /api/v1/project-audit-ledger/tl/mobilization
 *
 * Revalidates AW attendance summary server-side before creating the request.
 * Persists the attendance/wage snapshot with the mobilization record.
 */
function palApiTeamLeadMobilizationStore(): void
{
    try {
        $tl = palTeamLeadGuard();
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $tlId = (int)$tl['team_lead_id'];
        $tlEmail = $tl['email'] ?? '';

        $amount = (float)($_POST['amount'] ?? 0);
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $purpose = $_POST['purpose'] ?? null;
        $description = $_POST['description'] ?? null;

        // Attendance context from form (carried from AW dashboard)
        $attGroupId = !empty($_POST['attendance_group_id']) ? (int)$_POST['attendance_group_id'] : null;
        $attDateFrom = $_POST['attendance_date_from'] ?? '';
        $attDateTo = $_POST['attendance_date_to'] ?? '';

        if ($amount <= 0) { palJsonError('Amount is required.'); return; }

        // If attendance context was provided, revalidate via AW capability server-side
        $attendanceSummaryJson = null;
        $attendanceEvidenceHash = null;
        $capabilityProvider = null;

        if ($attGroupId !== null && $attGroupId > 0 && $attDateFrom !== '' && $attDateTo !== '') {
            // Validate date format
            $dFrom = \DateTime::createFromFormat('Y-m-d', $attDateFrom);
            $dTo = \DateTime::createFromFormat('Y-m-d', $attDateTo);
            if (!$dFrom || !$dTo) {
                palJsonError('Invalid attendance date range.');
                return;
            }

            try {
                $result = app()->cap()->call('attendance_wage.team_attendance.summary@1', [
                    'tenant_id' => (string)$tid,
                    'team_lead_email' => $tlEmail,
                    'date_from' => $attDateFrom,
                    'date_to' => $attDateTo,
                    'group_id' => $attGroupId,
                ], [
                    'caller' => ['module' => 'project-audit-ledger'],
                    'mode' => 'first',
                ]);

                if (!is_array($result) || empty($result['ok'])) {
                    $errMsg = is_array($result) ? ($result['error'] ?? 'Attendance data unavailable') : 'Invalid capability response';
                    palJsonError($errMsg);
                    return;
                }

                // Verify the team lead is authorized for this group
                $groups = $result['groups'] ?? [];
                $groupMatch = false;
                foreach ($groups as $g) {
                    if ((int)$g['group_id'] === $attGroupId) {
                        $groupMatch = true;
                        break;
                    }
                }
                if (!$groupMatch) {
                    palJsonError('You are not authorized to request mobilization for this attendance group.');
                    return;
                }

                // Store the snapshot
                $attendanceSummaryJson = json_encode([
                    'groups' => $result['groups'] ?? [],
                    'employee_summary' => $result['employee_summary'] ?? [],
                    'totals' => $result['totals'] ?? [],
                    'evidence' => $result['evidence'] ?? [],
                ]);
                $attendanceEvidenceHash = hash('sha256', $attendanceSummaryJson);
                $capabilityProvider = 'attendance_wage.team_attendance.summary@1';
            } catch (\Throwable $e) {
                if (function_exists('write_log')) {
                    write_log('pal_mob_store: capability revalidation failed: ' . $e->getMessage(), 'error');
                }
                palJsonError('Unable to verify attendance data. Please try again.');
                return;
            }
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO pal_mobilization_requests
                (tenant_id, team_lead_id, project_id, attendance_group_id, attendance_date_from, attendance_date_to,
                 attendance_summary_json, attendance_evidence_hash, attendance_capability_provider,
                 amount, request_date, purpose, description, status, created_by)
                VALUES (:t, :tl, :pj, :agid, :adf, :adt, :asj, :aeh, :acp, :amt, CURDATE(), :pur, :desc, 'pending', :cb)");
            $stmt->execute([
                ':t' => $tid, ':tl' => $tlId, ':pj' => $projectId,
                ':agid' => $attGroupId, ':adf' => $attDateFrom !== '' ? $attDateFrom : null,
                ':adt' => $attDateTo !== '' ? $attDateTo : null,
                ':asj' => $attendanceSummaryJson, ':aeh' => $attendanceEvidenceHash,
                ':acp' => $capabilityProvider,
                ':amt' => $amount, ':pur' => $purpose, ':desc' => $description, ':cb' => 0,
            ]);
            $mobId = (int)$db->lastInsertId();

            // Create approval record
            $approvalId = palCreateApproval('mobilization', $mobId, 0, 'pending', 'pending_approval');

            $db->commit();

            palAudit('pal.mobilization.requested', 0, 'pal_mobilization_requests', (string)$mobId,
                null, [
                    'amount' => $amount,
                    'team_lead_id' => $tlId,
                    'attendance_group_id' => $attGroupId,
                    'attendance_date_from' => $attDateFrom,
                    'attendance_date_to' => $attDateTo,
                    'evidence_hash' => $attendanceEvidenceHash,
                ]);
            palFireEvent('pal.mobilization.requested', ['mobilization_id' => $mobId, 'amount' => $amount]);

            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'id' => $mobId]);
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (Throwable $e) {
        palJsonError('Failed to submit request.');
    }
}

/**
 * API: Admin approves mobilization
 * POST /api/v1/project-audit-ledger/mobilization/{id}/approve
 */
function palApiMobilizationApprove(array $rp = []): void
{
    $u = palCurrentUser(['admin', 'supervisor']);
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) { palJsonError('Invalid ID.'); return; }

    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $stmt = $db->prepare("UPDATE pal_mobilization_requests SET status = 'approved', approved_by = :ub, approved_at = NOW() WHERE id = :id AND tenant_id = :tid");
    $stmt->execute([':id' => $id, ':tid' => $tid, ':ub' => (int)$u['id']]);

    palAudit('pal.mobilization.approved', (int)$u['id'], 'pal_mobilization_requests', (string)$id, null, []);
    palFireEvent('pal.mobilization.approved', ['mobilization_id' => $id]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}

/**
 * API: Admin rejects mobilization
 * POST /api/v1/project-audit-ledger/mobilization/{id}/reject
 */
function palApiMobilizationReject(array $rp = []): void
{
    $u = palCurrentUser(['admin', 'supervisor']);
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) { palJsonError('Invalid ID.'); return; }

    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $stmt = $db->prepare("UPDATE pal_mobilization_requests SET status = 'rejected' WHERE id = :id AND tenant_id = :tid");
    $stmt->execute([':id' => $id, ':tid' => $tid]);

    palAudit('pal.mobilization.rejected', (int)$u['id'], 'pal_mobilization_requests', (string)$id, null, []);
    palFireEvent('pal.mobilization.rejected', ['mobilization_id' => $id]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}

/**
 * API: Admin marks mobilization as disbursed
 * POST /api/v1/project-audit-ledger/mobilization/{id}/disburse
 */
function palApiMobilizationDisburse(array $rp = []): void
{
    $u = palCurrentUser(['admin']);
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) { palJsonError('Invalid ID.'); return; }

    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $stmt = $db->prepare("UPDATE pal_mobilization_requests SET status = 'disbursed', disbursed_at = NOW() WHERE id = :id AND tenant_id = :tid");
    $stmt->execute([':id' => $id, ':tid' => $tid]);

    palAudit('pal.mobilization.disbursed', (int)$u['id'], 'pal_mobilization_requests', (string)$id, null, []);
    palFireEvent('pal.mobilization.disbursed', ['mobilization_id' => $id]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}

// ── Admin Mobilization List ──

/**
 * Admin: Mobilization list page
 * GET /admin/project-audit-ledger/mobilization
 */
function palPageMobilizationList(): void
{
    $u = palCurrentUser(['admin', 'supervisor']);
    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, [
        'current_user' => $u,
        'page_title' => 'Mobilization Requests',
        'page_content' => 'mobilization-list',
    ]);
}

// ── Attendance View (via reads_tables) ──

/**
 * Team Lead Attendance View
 * GET /admin/project-audit-ledger/team-lead/attendance
 *
 * Uses the AW capability attendance_wage.team_attendance.summary@1
 * instead of direct AW table SQL. Falls back to empty state when
 * capability is unavailable (AW migrations not applied, groups not configured, etc.)
 */
function palPageTeamLeadAttendance(): void
{
    $tl = palTeamLeadGuard();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $tlEmail = $tl['email'] ?? '';

    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? date('Y-m-t');

    // Validate date format — fall back to defaults on invalid input
    if (!DateTime::createFromFormat('Y-m-d', $dateFrom)) {
        $dateFrom = date('Y-m-01');
    }
    if (!DateTime::createFromFormat('Y-m-d', $dateTo)) {
        $dateTo = date('Y-m-t');
    }

    // Call AW capability for team attendance summary
    $attendance = [];
    $groups = [];
    $employeeSummary = [];
    $totals = ['total_hours' => 0, 'total_computed_wages' => 0, 'record_count' => 0];

    try {
        $result = app()->cap()->call('attendance_wage.team_attendance.summary@1', [
            'tenant_id' => (string)$tid,
            'team_lead_email' => $tlEmail,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ], [
            'caller' => ['module' => 'project-audit-ledger'],
            'mode' => 'first',
        ]);

        if (is_array($result) && !empty($result['ok'])) {
            $groups = $result['groups'] ?? [];
            $attendance = $result['attendance'] ?? [];
            $employeeSummary = $result['employee_summary'] ?? [];
            $totals = $result['totals'] ?? $totals;
        } else {
            // Controlled unavailable state — capability returned ok=false (e.g. no groups, missing migrations)
            if (function_exists('write_log')) {
                $errMsg = is_array($result) ? ($result['error'] ?? 'unknown') : 'non-array result';
                write_log('pal_attendance_bridge: capability returned unavailable: ' . $errMsg, 'info');
            }
        }
    } catch (\Throwable $e) {
        // Capability not registered or provider threw — controlled fallback
        if (function_exists('write_log')) {
            write_log('pal_attendance_bridge: capability call failed: ' . $e->getMessage(), 'warning');
        }
        $attendance = [];
        $groups = [];
    }

    $t = __DIR__ . '/../templates/project-audit-ledger/team-lead-shell.disyl';
    palRender($t, [
        'current_user' => $tl,
        'tl' => $tl,
        'page_title' => 'Team Attendance',
        'page_content' => 'team-lead-attendance',
        'groups' => $groups,
        'attendance' => $attendance,
        'employee_summary' => $employeeSummary,
        'totals' => $totals,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);
}
