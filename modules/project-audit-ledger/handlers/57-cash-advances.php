<?php

declare(strict_types=1);

function palPageCashAdvanceList(): void
{
    $u = palCurrentUser();
    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, ['current_user' => $u, 'page_title' => 'Cash Advances', 'page_content' => 'cash-advances-list']);
}

function palPageCashAdvanceForm(): void
{
    $u = palCurrentUser();
    $db = palDb();
    $tid = (int)($u['tenant_id'] ?? 0);
    $teamLeads = $db->prepare("SELECT id, name FROM pal_team_leads WHERE tenant_id = :tid AND is_active = 1 ORDER BY name");
    $teamLeads->execute([':tid' => $tid]);
    $projects = $db->prepare("SELECT id, title, job_order_number FROM pal_projects WHERE tenant_id = :tid ORDER BY title");
    $projects->execute([':tid' => $tid]);

    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, [
        'current_user' => $u,
        'page_title' => 'New Cash Advance',
        'page_content' => 'cash-advance-form',
        'team_leads' => $teamLeads->fetchAll(PDO::FETCH_ASSOC),
        'projects' => $projects->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

function palPageCashAdvanceDetail(array $rp = []): void
{
    $u = palCurrentUser(['admin', 'supervisor']);
    $id = (int)($rp['id'] ?? 0);
    if ($id <= 0) { echo 'Invalid ID.'; return; }

    $db = palDb();
    $tid = (int)(app()->tenant()->current() ?? 0);

    $stmt = $db->prepare("
        SELECT ca.*, tl.name AS team_lead_name, tl.email AS team_lead_email,
               p.title AS project_title, p.job_order_number,
               a.id AS approval_id, a.decision AS approval_decision,
               a.reviewer_id AS approval_reviewer_id, a.decision_date AS approval_date,
               a.remarks AS approval_remarks
        FROM pal_cash_advances ca
        LEFT JOIN pal_team_leads tl ON ca.team_lead_id = tl.id AND tl.tenant_id = ca.tenant_id
        LEFT JOIN pal_projects p ON ca.project_id = p.id AND p.tenant_id = ca.tenant_id
        LEFT JOIN pal_approvals a ON a.entity_type = 'cash_advance' AND a.entity_id = ca.id AND a.tenant_id = ca.tenant_id
        WHERE ca.id = :id AND ca.tenant_id = :tid
        LIMIT 1
    ");
    $stmt->execute([':id' => $id, ':tid' => $tid]);
    $ca = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ca) { echo 'Cash advance not found.'; return; }

    // Reviewer name (if approved)
    $ca['reviewer_name'] = null;
    if (!empty($ca['approval_reviewer_id'])) {
        $rv = $db->prepare('SELECT full_name FROM pal_users WHERE id = :uid LIMIT 1');
        $rv->execute([':uid' => (int)$ca['approval_reviewer_id']]);
        $ca['reviewer_name'] = $rv->fetchColumn() ?: null;
    }

    // Audit trail for this cash advance (lifecycle history)
    $auditStmt = $db->prepare("
        SELECT a.action, a.actor_user_id, a.entity_id, a.created_at, a.metadata_json,
               actuser.full_name AS actor_name
        FROM pal_audit_logs a
        LEFT JOIN pal_users actuser ON a.actor_user_id = actuser.id
        WHERE a.tenant_id = :tid
          AND (a.entity_type = 'pal_cash_advances' AND a.entity_id = :eid
               OR a.entity_type = 'cash_advance' AND a.entity_id = :eid2
               OR (a.entity_type = 'pal_approvals' AND a.metadata_json LIKE :m))
        ORDER BY a.created_at ASC
    ");
    $auditStmt->execute([
        ':tid' => $tid,
        ':eid' => (string)$id,
        ':eid2' => (string)$id,
        ':m' => '%cash_advance%',
    ]);
    $auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

    // AW sync reference (best-effort; only when a separate AW tenant exists)
    $awSync = null;
    try {
        $awTid = palResolveAwTenantId();
        if ($awTid > 0 && $awTid !== $tid) {
            $awDb = app()->dbForTenant($awTid);
            if ($awDb) {
                $awStmt = $awDb->prepare("SELECT advance_id, amount, balance, status, request_date, notes FROM cash_advances WHERE notes LIKE :n ORDER BY advance_id DESC LIMIT 1");
                $awStmt->execute([':n' => "PAL cash advance #{$id}%"]);
                $awSync = $awStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('palPageCashAdvanceDetail: AW sync lookup skipped: ' . $e->getMessage(), 'warning');
        }
    }

    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, [
        'current_user' => $u,
        'page_title' => 'CA-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
        'page_content' => 'cash-advance-detail',
        'ca' => $ca,
        'audit_entries' => $auditRows,
        'aw_sync' => $awSync,
    ]);
}

function palApiCashAdvanceStore(): void
{
    palResponseGuard(function () {
        $u = palCurrentUser();
        palEnforceCsrf();
        $s = new palCashAdvanceService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $id = $s->create($_POST);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id]);
    });
}

function palApiCashAdvanceApprove(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $u = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid ID.'); return; }
        $s = new palCashAdvanceService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $s->approve($id);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

function palApiCashAdvanceSettle(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $u = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid ID.'); return; }
        $s = new palCashAdvanceService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $s->settle($id);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

function palApiCashAdvanceVoid(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $u = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid ID.'); return; }
        $s = new palCashAdvanceService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $s->void($id);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}
