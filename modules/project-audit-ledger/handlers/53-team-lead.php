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
          AND p.status IN ('approved','in_progress','on_hold','completed')
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
          AND p.status IN ('approved','in_progress','on_hold')
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
          AND status IN ('approved','in_progress','on_hold')
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
 */
function palPageTeamLeadMobilizationForm(): void
{
    $tl = palTeamLeadGuard();
    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $tlId = (int)$tl['team_lead_id'];

    $projects = $db->prepare("
        SELECT id, title, job_order_number
        FROM pal_projects
        WHERE fabrication_team_lead_id = :tlid AND tenant_id = :tid
          AND status IN ('approved','in_progress','on_hold')
        ORDER BY title
    ");
    $projects->execute([':tlid' => $tlId, ':tid' => $tid]);

    $t = __DIR__ . '/../templates/project-audit-ledger/team-lead-shell.disyl';
    palRender($t, [
        'current_user' => $tl,
        'tl' => $tl,
        'page_title' => 'Request Mobilization',
        'page_content' => 'team-lead-mobilization-form',
        'projects' => $projects->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

/**
 * API: Team Lead submits mobilization request
 * POST /api/v1/project-audit-ledger/tl/mobilization
 */
function palApiTeamLeadMobilizationStore(): void
{
    try {
        $tl = palTeamLeadGuard();
        $db = palDb();
        $tid = (int)(app()->tenant()->current() ?? 0);
        $tlId = (int)$tl['team_lead_id'];

        $amount = (float)($_POST['amount'] ?? 0);
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $purpose = $_POST['purpose'] ?? null;
        $description = $_POST['description'] ?? null;

        if ($amount <= 0) { palJsonError('Amount is required.'); return; }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO pal_mobilization_requests (tenant_id, team_lead_id, project_id, amount, request_date, purpose, description, status, created_by)
                                  VALUES (:t, :tl, :pj, :amt, CURDATE(), :pur, :desc, 'pending', :cb)");
            $stmt->execute([':t' => $tid, ':tl' => $tlId, ':pj' => $projectId, ':amt' => $amount, ':pur' => $purpose, ':desc' => $description, ':cb' => 0]);
            $mobId = (int)$db->lastInsertId();

            // Create approval record
            $approvalId = palCreateApproval('mobilization', $mobId, 0, 'pending', 'pending_approval');

            $db->commit();

            palAudit('pal.mobilization.requested', 0, 'pal_mobilization_requests', (string)$mobId,
                null, ['amount' => $amount, 'team_lead_id' => $tlId]);
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

// ── Attendance View (via reads_tables) ──

/**
 * Team Lead Attendance View
 * GET /admin/project-audit-ledger/team-lead/attendance
 */
function palPageTeamLeadAttendance(): void
{
    $tl = palTeamLeadGuard();
    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);
    $tlEmail = $tl['email'] ?? '';

    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? date('Y-m-t');

    // Query attendance via reads_tables bridge
    // Bridge: attendance_groups.pal_team_lead_email = tl.email
    // Then join through group_members → attendance_records
    $attendance = [];
    $groups = [];

    try {
        // Get groups this team lead owns (via pal_team_lead_email bridge)
        $grpStmt = $db->prepare("
            SELECT group_id, name FROM attendance_groups
            WHERE pal_team_lead_email = :email AND tenant_id = :tid AND is_active = 1
        ");
        $grpStmt->execute([':email' => $tlEmail, ':tid' => $tid]);
        $groups = $grpStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($groups)) {
            $groupIds = array_column($groups, 'group_id');
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

            $attStmt = $db->prepare("
                SELECT 
                    ar.id, ar.clock_in, ar.clock_out,
                    ROUND(TIMESTAMPDIFF(MINUTE, ar.clock_in, ar.clock_out) / 60.0, 2) AS hours_worked,
                    ar.status,
                    CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, '')) AS employee_name,
                    ep.position, ep.employee_number, ep.profile_id,
                    ag.name AS group_name
                FROM attendance_records ar
                JOIN attendance_group_members agm ON ar.user_id = (
                    SELECT au.id FROM attendance_wage_users au
                    JOIN employee_profiles ep2 ON au.id = ep2.user_id
                    WHERE ep2.profile_id = agm.profile_id AND ep2.tenant_id = agm.tenant_id
                    LIMIT 1
                )
                JOIN employee_profiles ep ON agm.profile_id = ep.profile_id AND agm.tenant_id = ep.tenant_id
                JOIN attendance_groups ag ON agm.group_id = ag.group_id AND agm.tenant_id = ag.tenant_id
                WHERE agm.group_id IN ({$placeholders})
                  AND agm.tenant_id = :tid2
                  AND ar.clock_in >= :df
                  AND ar.clock_in <= :dt
                ORDER BY ar.clock_in DESC
                LIMIT 200
            ");

            $params = array_merge($groupIds, [$tid, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            // Rebuild with named params
            $namedParams = [];
            $namedPlaceholders = [];
            foreach ($groupIds as $i => $gid) {
                $pname = ':gid' . $i;
                $namedPlaceholders[] = $pname;
                $namedParams[$pname] = $gid;
            }
            $namedParams[':tid2'] = $tid;
            $namedParams[':df'] = $dateFrom . ' 00:00:00';
            $namedParams[':dt'] = $dateTo . ' 23:59:59';

            $sql = "
                SELECT 
                    ar.id, ar.clock_in, ar.clock_out,
                    ROUND(TIMESTAMPDIFF(MINUTE, ar.clock_in, ar.clock_out) / 60.0, 2) AS hours_worked,
                    ar.status,
                    CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, '')) AS employee_name,
                    ep.position, ep.employee_number, ep.profile_id,
                    ag.name AS group_name
                FROM attendance_records ar
                JOIN attendance_group_members agm ON ar.user_id = (
                    SELECT au.id FROM attendance_wage_users au
                    JOIN employee_profiles ep2 ON au.id = ep2.user_id
                    WHERE ep2.profile_id = agm.profile_id AND ep2.tenant_id = agm.tenant_id
                    LIMIT 1
                )
                JOIN employee_profiles ep ON agm.profile_id = ep.profile_id AND agm.tenant_id = ep.tenant_id
                JOIN attendance_groups ag ON agm.group_id = ag.group_id AND agm.tenant_id = ag.tenant_id
                WHERE agm.group_id IN (" . implode(',', $namedPlaceholders) . ")
                  AND agm.tenant_id = :tid2
                  AND ar.clock_in >= :df
                  AND ar.clock_in <= :dt
                ORDER BY ar.clock_in DESC
                LIMIT 200
            ";

            $attStmt = $db->prepare($sql);
            $attStmt->execute($namedParams);
            $attendance = $attStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        // Tables may not exist yet or no group configured
        $attendance = [];
    }

    $t = __DIR__ . '/../templates/project-audit-ledger/team-lead-shell.disyl';
    palRender($t, [
        'current_user' => $tl,
        'tl' => $tl,
        'page_title' => 'Team Attendance',
        'page_content' => 'team-lead-attendance',
        'groups' => $groups,
        'attendance' => $attendance,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);
}
