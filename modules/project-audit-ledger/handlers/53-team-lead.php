<?php

declare(strict_types=1);

/**
 * Resolve the AW tenant ID for capability calls.
 *
 * Priority:
 *   1. Explicit `aw_tenant_id` in PAL module settings (admin override).
 *   2. Auto-discover: scan all active tenants for `attendance_groups` table.
 *   3. Fall back to current PAL tenant.
 *
 * Result is cached in-process to avoid repeated tenant-DB scans.
 */
function palResolveAwTenantId(): int
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $tid = (int)(app()->tenant()->current() ?? 0);

    // 1. Explicit setting
    try {
        $settings = function_exists('getModuleSettings') ? getModuleSettings('project-audit-ledger') : [];
        $awTid = !empty($settings['aw_tenant_id']) ? (int)$settings['aw_tenant_id'] : 0;
        if ($awTid > 0) {
            $cached = $awTid;
            return $cached;
        }
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('palResolveAwTenantId: getModuleSettings failed: ' . $e->getMessage(), 'warning');
        }
    }

    // 2. Auto-discover: find a tenant that has attendance_groups with team lead data
    try {
        $cp = app()->controlDb(); // control-plane DB (not tenant DB)
        $tenants = $cp->query("SELECT id FROM kernel_tenants WHERE status = 'active' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tenants as $candidateTid) {
            $candidateTid = (int)$candidateTid;
            if ($candidateTid <= 0) continue;
            try {
                $cdb = app()->dbForTenant($candidateTid);
                if (!$cdb) continue;
                // Check for actual team-lead data, not just empty table
                $hasData = $cdb->query(
                    "SELECT 1 FROM attendance_groups WHERE pal_team_lead_email IS NOT NULL AND pal_team_lead_email != '' AND is_active = 1 LIMIT 1"
                )->fetchColumn();
                if ($hasData) {
                    if (function_exists('write_log')) {
                        write_log("palResolveAwTenantId: auto-discovered AW tenant {$candidateTid}", 'info');
                    }
                    $cached = $candidateTid;
                    return $cached;
                }
            } catch (\Throwable) {
                // This tenant doesn't have AW tables — try next
                continue;
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('palResolveAwTenantId: auto-discover scan failed: ' . $e->getMessage(), 'warning');
        }
    }

    // 3. Fallback
    if (function_exists('write_log')) {
        write_log('palResolveAwTenantId: no AW tenant found, falling back to PAL tenant ' . $tid, 'warning');
    }
    $cached = $tid;
    return $cached;
}

/**
 * Auto-provision a team lead in PAL from AW attendance_groups.
 * Called when a team lead authenticates via delegation but doesn't yet exist
 * in pal_team_leads. Reads name from employee_profiles (leader_profile_id)
 * and email from attendance_groups.pal_team_lead_email.
 *
 * Returns the pal_team_leads row (existing or newly created), or null on failure.
 */
function palAutoProvisionTeamLead(string $email): ?array
{
    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);

    // Already exists?
    $stmt = $db->prepare("SELECT id, name, email FROM pal_team_leads WHERE email = :email AND tenant_id = :tid AND is_active = 1 LIMIT 1");
    $stmt->execute([':email' => $email, ':tid' => $tid]);
    $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($existing) {
        return ['team_lead_id' => (int)$existing['id'], 'name' => $existing['name'], 'email' => $existing['email'], 'role' => 'team_lead', 'source' => 'pal-team-lead'];
    }

    // Look up name from AW
    $displayName = $email;
    try {
        $awTid = palResolveAwTenantId();
        $awDb = app()->dbForTenant($awTid);
        if ($awDb) {
            $awInfo = $awDb->prepare("
                SELECT COALESCE(CONCAT_WS(' ', NULLIF(ep.first_name,''), NULLIF(ep.last_name,'')), ag.pal_team_lead_email) AS full_name
                FROM attendance_groups ag
                LEFT JOIN employee_profiles ep ON ag.leader_profile_id = ep.profile_id AND ag.tenant_id = ep.tenant_id
                WHERE LOWER(ag.pal_team_lead_email) = :email AND ag.tenant_id = :awtid AND ag.is_active = 1
                LIMIT 1
            ");
            $awInfo->execute([':email' => strtolower($email), ':awtid' => $awTid]);
            $name = $awInfo->fetchColumn();
            if ($name && $name !== '') {
                $displayName = $name;
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('palAutoProvisionTeamLead: AW name lookup failed: ' . $e->getMessage(), 'warning');
        }
    }

    // Insert
    $db->prepare("INSERT INTO pal_team_leads (tenant_id, name, email, is_active, created_at) VALUES (:tid, :name, :email, 1, NOW())")
       ->execute([':tid' => $tid, ':name' => $displayName, ':email' => $email]);
    $newId = (int)$db->lastInsertId();

    if (function_exists('write_log')) {
        write_log("palAutoProvisionTeamLead: created team_lead id={$newId} email={$email}", 'info');
    }

    return ['team_lead_id' => $newId, 'name' => $displayName, 'email' => $email, 'role' => 'team_lead', 'source' => 'pal-team-lead'];
}

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
        SELECT COUNT(*)
        FROM pal_mobilization_requests mr
        LEFT JOIN pal_approvals a ON a.id = mr.approval_id AND a.tenant_id = mr.tenant_id
        WHERE mr.team_lead_id = :tlid AND mr.tenant_id = :tid
          AND COALESCE(a.decision, mr.status) = 'pending'
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
        if ($projectId === null) { palJsonError('Project is required.'); return; }
        if ($projectId !== null) {
            $projectCheck = $db->prepare("SELECT 1 FROM pal_projects WHERE id = :pid AND tenant_id = :tid AND fabrication_team_lead_id = :tlid LIMIT 1");
            $projectCheck->execute([':pid' => $projectId, ':tid' => $tid, ':tlid' => $tlId]);
            if (!$projectCheck->fetchColumn()) {
                palJsonError('Selected project is not assigned to you.');
                return;
            }
        }

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
        SELECT mr.*, p.title AS project_title, p.job_order_number,
               COALESCE(a.decision, mr.status) AS status
        FROM pal_mobilization_requests mr
        LEFT JOIN pal_projects p ON mr.project_id = p.id AND p.tenant_id = mr.tenant_id
        LEFT JOIN pal_approvals a ON a.id = mr.approval_id AND a.tenant_id = mr.tenant_id
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
                $awTid = palResolveAwTenantId();
                $result = app()->cap()->call('attendance_wage.team_attendance.summary@1', [
                    'tenant_id' => (string)$awTid,
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
                } elseif (function_exists('write_log')) {
                    $errMsg = is_array($result) ? ($result['error'] ?? 'unknown') : 'non-array response';
                    write_log('pal_mob_form: AW capability returned not-ok (aw_tenant=' . $awTid . ', group=' . $attGroupId . '): ' . $errMsg, 'warning');
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
    $tid = 0;
    $tlEmail = '';
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
        if ($projectId === null) { palJsonError('Project is required.'); return; }
        if ($projectId !== null) {
            $projectCheck = $db->prepare("SELECT 1 FROM pal_projects WHERE id = :pid AND tenant_id = :tid AND fabrication_team_lead_id = :tlid LIMIT 1");
            $projectCheck->execute([':pid' => $projectId, ':tid' => $tid, ':tlid' => $tlId]);
            if (!$projectCheck->fetchColumn()) {
                palJsonError('Selected project is not assigned to you.');
                return;
            }
        }

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
                $awTid = palResolveAwTenantId();
                $result = app()->cap()->call('attendance_wage.team_attendance.summary@1', [
                    'tenant_id' => (string)$awTid,
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
                    if (function_exists('write_log')) {
                        write_log('pal_mob_store: AW revalidation failed (aw_tenant=' . $awTid . ', pal_tenant=' . $tid . ', tl=' . hash('sha256', $tlEmail) . ', group=' . $attGroupId . '): ' . $errMsg, 'error');
                    }
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
                    if (function_exists('write_log')) {
                        write_log('pal_mob_store: group auth mismatch (aw_tenant=' . $awTid . ', pal_tenant=' . $tid . ', tl=' . hash('sha256', $tlEmail) . ', group=' . $attGroupId . ')', 'warning');
                    }
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
                    write_log('pal_mob_store: capability revalidation exception (pal_tenant=' . $tid . ', tl=' . hash('sha256', $tlEmail) . ', group=' . $attGroupId . '): ' . $e->getMessage(), 'error');
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

            // Write approval_id back to the mobilization request for traceability
            $updApproval = $db->prepare("UPDATE pal_mobilization_requests SET approval_id = :aid WHERE id = :id AND tenant_id = :tid");
            $updApproval->execute([':aid' => $approvalId, ':id' => $mobId, ':tid' => $tid]);

            $db->commit();

            palAudit('pal.mobilization.requested', 0, 'pal_mobilization_requests', (string)$mobId,
                null, [
                    'amount' => $amount,
                    'team_lead_id' => $tlId,
                    'attendance_group_id' => $attGroupId,
                    'attendance_date_from' => $attDateFrom,
                    'attendance_date_to' => $attDateTo,
                    'evidence_hash' => $attendanceEvidenceHash,
                    'approval_id' => $approvalId,
                ]);
            palFireEvent('pal.mobilization.requested', ['mobilization_id' => $mobId, 'amount' => $amount]);

            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'id' => $mobId]);
        } catch (Throwable $e) {
            $db->rollBack();
            if (function_exists('write_log')) {
                write_log('pal_mob_store: DB transaction failed (pal_tenant=' . $tid . ', tl=' . hash('sha256', $tlEmail) . ', amount=' . $amount . '): ' . $e->getMessage(), 'error');
            }
            palJsonError('Failed to save mobilization request. Please try again.');
        }
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('pal_mob_store: unexpected error (pal_tenant=' . $tid . '): ' . $e->getMessage(), 'error');
        }
        palJsonError('Failed to submit request.');
    }
}

/**
 * API: Admin approves mobilization
 * POST /api/v1/project-audit-ledger/mobilization/{id}/approve
 *
 * Updates both pal_mobilization_requests and the linked pal_approvals row.
 */
function palApiMobilizationApprove(array $rp = []): void
{
    $u = palCurrentUser(['admin', 'supervisor']);
    palEnforceCsrf();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) { palJsonError('Invalid ID.'); return; }

    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE pal_mobilization_requests SET status = 'approved', approved_by = :ub, approved_at = NOW() WHERE id = :id AND tenant_id = :tid AND status = 'pending'");
        $stmt->execute([':id' => $id, ':tid' => $tid, ':ub' => (int)$u['id']]);
        if ($stmt->rowCount() === 0) { $db->rollBack(); palJsonError('Request not found or already decided.'); return; }

        // Update the linked pal_approvals row
        palMobilizationSyncApproval($db, $tid, $id, 'approved', (int)$u['id']);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        if (function_exists('write_log')) { write_log('pal_mob_approve: ' . $e->getMessage(), 'error'); }
        palJsonError('Failed to approve request.');
        return;
    }

    palAudit('pal.mobilization.approved', (int)$u['id'], 'pal_mobilization_requests', (string)$id, null, []);
    palFireEvent('pal.mobilization.approved', ['mobilization_id' => $id]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}

/**
 * API: Admin rejects mobilization
 * POST /api/v1/project-audit-ledger/mobilization/{id}/reject
 *
 * Updates both pal_mobilization_requests and the linked pal_approvals row.
 */
function palApiMobilizationReject(array $rp = []): void
{
    $u = palCurrentUser(['admin', 'supervisor']);
    palEnforceCsrf();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) { palJsonError('Invalid ID.'); return; }

    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE pal_mobilization_requests SET status = 'rejected' WHERE id = :id AND tenant_id = :tid AND status = 'pending'");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        if ($stmt->rowCount() === 0) { $db->rollBack(); palJsonError('Request not found or already decided.'); return; }

        // Update the linked pal_approvals row
        palMobilizationSyncApproval($db, $tid, $id, 'rejected', (int)$u['id']);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        if (function_exists('write_log')) { write_log('pal_mob_reject: ' . $e->getMessage(), 'error'); }
        palJsonError('Failed to reject request.');
        return;
    }

    palAudit('pal.mobilization.rejected', (int)$u['id'], 'pal_mobilization_requests', (string)$id, null, []);
    palFireEvent('pal.mobilization.rejected', ['mobilization_id' => $id]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}

/**
 * API: Admin marks mobilization as disbursed
 * POST /api/v1/project-audit-ledger/mobilization/{id}/disburse
 *
 * Updates both pal_mobilization_requests and the linked pal_approvals row.
 */
function palApiMobilizationDisburse(array $rp = []): void
{
    $u = palCurrentUser(['admin']);
    palEnforceCsrf();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) { palJsonError('Invalid ID.'); return; }

    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE pal_mobilization_requests SET status = 'disbursed', disbursed_at = NOW() WHERE id = :id AND tenant_id = :tid AND status = 'approved'");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
        if ($stmt->rowCount() === 0) { $db->rollBack(); palJsonError('Request not found, not yet approved, or already disbursed.'); return; }

        // Disbursement is a post-approval action — does not change the approval decision.
        // The pal_approvals row stays as 'approved'.

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        if (function_exists('write_log')) { write_log('pal_mob_disburse: ' . $e->getMessage(), 'error'); }
        palJsonError('Failed to mark as disbursed.');
        return;
    }

    palAudit('pal.mobilization.disbursed', (int)$u['id'], 'pal_mobilization_requests', (string)$id, null, []);
    palFireEvent('pal.mobilization.disbursed', ['mobilization_id' => $id]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}

/**
 * Sync pal_approvals row for a mobilization request decision.
 * Finds the pending approval linked via approval_id or entity_type+entity_id
 * and updates its decision.
 */
function palMobilizationSyncApproval(PDO $db, int $tenantId, int $mobId, string $decision, int $reviewerId): void
{
    // Try via approval_id link first
    $mob = $db->prepare("SELECT approval_id FROM pal_mobilization_requests WHERE id = :id AND tenant_id = :tid");
    $mob->execute([':id' => $mobId, ':tid' => $tenantId]);
    $approvalId = $mob->fetchColumn();

    if ($approvalId && (int)$approvalId > 0) {
        $upd = $db->prepare("UPDATE pal_approvals SET decision = :dec, reviewer_id = :rv, decision_date = NOW() WHERE id = :id AND tenant_id = :tid AND decision = 'pending'");
        $upd->execute([':dec' => $decision, ':rv' => $reviewerId, ':id' => (int)$approvalId, ':tid' => $tenantId]);
    } else {
        // Fallback: find by entity_type + entity_id
        $upd = $db->prepare("UPDATE pal_approvals SET decision = :dec, reviewer_id = :rv, decision_date = NOW() WHERE entity_type = 'mobilization' AND entity_id = :eid AND tenant_id = :tid AND decision = 'pending'");
        $upd->execute([':dec' => $decision, ':rv' => $reviewerId, ':eid' => $mobId, ':tid' => $tenantId]);
    }

    $affected = $upd->rowCount();
    if (function_exists('write_log')) {
        write_log("pal_mob_sync_approval: mob={$mobId} decision={$decision} approvals_updated={$affected}", 'info');
    }
    if ($affected === 0) {
        throw new RuntimeException('No pending mobilization approval row was updated.');
    }
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

/**
 * Admin: Mobilization detail page
 * GET /admin/project-audit-ledger/mobilization/{id}
 */
function palPageMobilizationDetail(array $rp = []): void
{
    $u = palCurrentUser(['admin', 'supervisor']);
    $id = (int)($rp['id'] ?? 0);
    if ($id <= 0) { echo 'Invalid ID.'; return; }

    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);

    $stmt = $db->prepare("
        SELECT mr.*, tl.name AS team_lead_name, tl.email AS team_lead_email,
               p.title AS project_title, p.job_order_number
        FROM pal_mobilization_requests mr
        LEFT JOIN pal_team_leads tl ON mr.team_lead_id = tl.id
        LEFT JOIN pal_projects p ON mr.project_id = p.id
        WHERE mr.id = :id AND mr.tenant_id = :tid
    ");
    $stmt->execute([':id' => $id, ':tid' => $tid]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) { echo 'Mobilization request not found.'; return; }

    // Resolve attendance group name from AW tenant
    $request['attendance_group_name'] = null;
    if (!empty($request['attendance_group_id'])) {
        try {
            $awTid = palResolveAwTenantId();
            $gnDb = ($awTid > 0) ? app()->dbForTenant($awTid) : null;
            if ($gnDb) {
                $name = $gnDb->query(
                    "SELECT name FROM attendance_groups WHERE group_id = " . (int)$request['attendance_group_id'] . " AND tenant_id = " . $awTid . " LIMIT 1"
                )->fetchColumn();
                if ($name && is_string($name) && $name !== '') {
                    $request['attendance_group_name'] = $name;
                }
            }
        } catch (\Throwable) {}
    }

    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, [
        'current_user' => $u,
        'page_title' => 'MOB-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
        'page_content' => 'mobilization-detail',
        'request' => $request,
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

    $awTid = palResolveAwTenantId();

    try {
        $result = app()->cap()->call('attendance_wage.team_attendance.summary@1', [
            'tenant_id' => (string)$awTid,
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
