<?php

declare(strict_types=1);

function palPageFabricationAllocation(): void {
    $u = palCurrentUser();
    $db = palDb();
    $tid = (int)($u['tenant_id'] ?? 0);

    // Projects with fabrication setup: SUM dispenses from multiple allocation rows
    $stmt = $db->prepare("
        SELECT p.id AS project_id, p.title AS project_title,
               p.fabrication_alloc_pct, p.fabrication_alloc_basis,
               p.contract_amount, p.start_date, p.target_completion_date,
               tl.name AS team_lead_name,
               ROUND(p.contract_amount * p.fabrication_alloc_pct / 100, 2) AS fab_budget,
               COALESCE(SUM(fa.approved_amount), 0) AS ca_dispensed,
               ROUND(p.contract_amount * p.fabrication_alloc_pct / 100, 2) - COALESCE(SUM(fa.approved_amount), 0) AS ca_remaining,
               COUNT(fa.id) AS dispense_count,
               MAX(fa.created_at) AS last_dispense_at
        FROM pal_projects p
        LEFT JOIN pal_team_leads tl ON p.fabrication_team_lead_id = tl.id
        LEFT JOIN pal_fabrication_allocations fa ON fa.project_id = p.id
        WHERE p.tenant_id = :tid
          AND p.fabrication_alloc_pct > 0
        GROUP BY p.id
        ORDER BY p.title ASC
    ");
    $stmt->execute([':tid' => $tid]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, [
        'current_user' => $u,
        'page_title' => 'Fabrication Dashboard',
        'page_content' => 'fabrication-allocations',
        'fabricationProjects' => $projects,
    ]);
}

function palPageFabricationDues(array $rp = []): void {
    $u = palCurrentUser();
    $pid = (int)($rp['projectId'] ?? $_GET['projectId'] ?? 0);
    $db = palDb();
    $tid = (int)($u['tenant_id'] ?? 0);

    $dues = $db->prepare("SELECT fwd.* FROM pal_fabrication_weekly_dues fwd WHERE fwd.project_id = :pid AND fwd.tenant_id = :tid ORDER BY fwd.week_number ASC");
    $dues->execute([':pid' => $pid, ':tid' => $tid]);

    $proj = $db->prepare("SELECT id, title, fabrication_alloc_pct, contract_amount, start_date, target_completion_date, fabrication_team_lead_id FROM pal_projects WHERE id = :id AND tenant_id = :tid");
    $proj->execute([':id' => $pid, ':tid' => $tid]);
    $pj = $proj->fetch(PDO::FETCH_ASSOC);

    $alloc = $db->prepare("SELECT * FROM pal_fabrication_allocations WHERE project_id = :pid AND tenant_id = :tid ORDER BY created_at DESC");
    $alloc->execute([':pid' => $pid, ':tid' => $tid]);
    $allocations = $alloc->fetchAll(PDO::FETCH_ASSOC);

    // All CA dispenses for this project (history)
    $dispenses = $db->prepare("
        SELECT fa.id, fa.approved_amount, fa.approval_reason, fa.created_at, fa.status,
               u.full_name AS dispensed_by_name
        FROM pal_fabrication_allocations fa
        LEFT JOIN pal_users u ON fa.created_by = u.id
        WHERE fa.project_id = :pid AND fa.tenant_id = :tid
        ORDER BY fa.created_at DESC
    ");
    $dispenses->execute([':pid' => $pid, ':tid' => $tid]);

    $totalDispensed = 0;
    foreach ($allocations as $a) { $totalDispensed += (float)($a['approved_amount'] ?? 0); }
    $fabBudget = $pj ? round((float)($pj['contract_amount'] ?? 0) * (float)($pj['fabrication_alloc_pct'] ?? 0) / 100, 2) : 0;

    $weeklyDuesRows = $dues->fetchAll(PDO::FETCH_ASSOC);

    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, [
        'current_user' => $u,
        'page_title' => ($pj ? $pj['title'] : 'Project') . ' — Weekly Dues',
        'page_content' => 'fabrication-dues',
        'dues' => $weeklyDuesRows,
        'weekly_dues' => $weeklyDuesRows,
        'project' => $pj,
        'project' => $pj,
        'allocation' => $allocations[0] ?? null,
        'dispenses' => $dispenses->fetchAll(PDO::FETCH_ASSOC),
        'total_dispensed' => $totalDispensed,
        'fab_budget' => $fabBudget,
        'remaining_budget' => max(0, $fabBudget - $totalDispensed),
        'first_allocation_id' => (int)(($allocations[0] ?? [])['id'] ?? 0),
        'project_id' => $pid,
    ]);
}

function palPageFabricationPaymentForm(): void {
    $u = palCurrentUser();
    $db = palDb();
    $tid = (int)($u['tenant_id'] ?? 0);

    $proj = $db->prepare("SELECT p.id, p.title, p.fabrication_alloc_pct, p.contract_amount, fa.id AS alloc_id, fa.calculated_amount FROM pal_projects p LEFT JOIN pal_fabrication_allocations fa ON fa.project_id = p.id WHERE p.tenant_id = :tid AND p.fabrication_alloc_pct > 0 AND fa.id IS NOT NULL ORDER BY p.title");
    $proj->execute([':tid' => $tid]);

    $leads = $db->prepare("SELECT id, name FROM pal_team_leads WHERE tenant_id = :tid AND is_active = 1 ORDER BY name");
    $leads->execute([':tid' => $tid]);

    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, [
        'current_user' => $u,
        'page_title' => 'Record Fabrication Payment',
        'page_content' => 'fabrication-payment-form',
        'projects' => $proj->fetchAll(PDO::FETCH_ASSOC),
        'team_leads' => $leads->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

function palApiFabricationAllocationStore(): void {
    palResponseGuard(function() {
        $u = palCurrentUser();
        palEnforceCsrf();
        $s = new palFabricationService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $id = $s->createAllocation($_POST);
        palAudit('pal.fabrication.allocation_created', (int)$u['id'], 'pal_fabrication_allocations', (string)$id, null, []);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id]);
    });
}

function palApiFabricationAllocationUpdate(array $rp = []): void {
    palResponseGuard(function() use ($rp) {
        $u = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid ID.'); return; }
        $s = new palFabricationService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $s->updateAllocation($id, $_POST);
        palAudit('pal.fabrication.allocation_updated', (int)$u['id'], 'pal_fabrication_allocations', (string)$id, null, []);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

function palApiFabricationDueStore(): void {
    palResponseGuard(function() {
        $u = palCurrentUser();
        palEnforceCsrf();
        $aid = (int)($_POST['allocation_id'] ?? 0);
        $weeks = [];
        if (isset($_POST['week_start']) && is_array($_POST['week_start'])) {
            foreach ($_POST['week_start'] as $i => $ws) {
                $weeks[] = ['start' => $ws, 'end' => ($_POST['week_end'][$i] ?? $ws), 'due_date' => ($_POST['due_date'][$i] ?? null)];
            }
        }
        $s = new palFabricationService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $s->generateWeeklyDues($aid, $weeks);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

function palApiFabricationPaymentStore(): void {
    palResponseGuard(function() {
        $u = palCurrentUser();
        palEnforceCsrf();
        $s = new palFabricationService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $id = $s->recordPayment($_POST);
        $s->submitPayment($id);
        palAudit('pal.fabrication.payment_recorded', (int)$u['id'], 'pal_fabrication_payments', (string)$id, null, []);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id]);
    });
}
