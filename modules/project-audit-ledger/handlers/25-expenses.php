<?php

declare(strict_types=1);

function palPageExpenseList(): void
{
    $user = palCurrentUser();
    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => 'Expenses',
        'page_content' => 'expenses-list',
    ]);
}

function palPageExpenseForm(array $rp = []): void
{
    $user = palCurrentUser();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $expense = null;
    if ($id > 0) {
        $svc = new palExpenseService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
        $expense = $svc->get($id);
    }

    $db = palDb();
    $tid = (int)($user['tenant_id'] ?? 0);
    $cats = $db->prepare('SELECT id, name FROM pal_expense_categories WHERE tenant_id = :tid AND is_active = 1 ORDER BY name');
    $cats->execute([':tid' => $tid]);
    $projects = $db->prepare('SELECT id, title FROM pal_projects WHERE tenant_id = :tid ORDER BY title LIMIT 200');
    $projects->execute([':tid' => $tid]);
    $suppliers = $db->prepare('SELECT id, name FROM pal_suppliers WHERE tenant_id = :tid AND is_active = 1 ORDER BY name');
    $suppliers->execute([':tid' => $tid]);

    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => $expense ? 'Edit Expense' : 'Create Expense',
        'page_content' => 'expense-form',
        'expense' => $expense,
        'is_edit' => $expense !== null,
        'categories' => $cats->fetchAll(PDO::FETCH_ASSOC),
        'projects' => $projects->fetchAll(PDO::FETCH_ASSOC),
        'suppliers' => $suppliers->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

function palPageExpenseDetail(array $rp = []): void
{
    $user = palCurrentUser();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $svc = new palExpenseService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
    $expense = $svc->get($id);

    if ($expense === null) {
        palJsonError('Expense not found.', 404);
        return;
    }

    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => 'Expense #' . $expense['expense_number'],
        'page_content' => 'expense-detail',
        'expense' => $expense,
    ]);
}

// ── API ──

function palApiExpenseStore(): void
{
    palResponseGuard(function (): void {
        $user = palCurrentUser();
        palEnforceCsrf();
        $svc = new palExpenseService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
        $id = $svc->create($_POST);
        palAudit('pal.expense.created', (int)$user['id'], 'pal_expenses', (string)$id, null, ['amount' => $_POST['amount'] ?? 0]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id]);
    });
}

function palApiExpenseUpdate(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $user = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid ID.'); return; }
        $svc = new palExpenseService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
        $svc->update($id, $_POST);
        palAudit('pal.expense.updated', (int)$user['id'], 'pal_expenses', (string)$id, null, ['updated_fields' => array_keys($_POST)]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

function palApiExpenseSubmit(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $user = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        $svc = new palExpenseService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
        $approvalId = $svc->submit($id);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'approval_id' => $approvalId]);
    });
}

// Approve/reject now goes through ApprovalService via palApiApprovalDecide
