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
    $projects = $db->prepare("SELECT id, title FROM pal_projects WHERE tenant_id = :tid ORDER BY title");
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
