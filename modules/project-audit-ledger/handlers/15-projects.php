<?php

declare(strict_types=1);

/**
 * Page: Project List
 */
function palPageProjectList(): void
{
    $user = palCurrentUser();
    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => 'Job Orders',
        'page_content' => 'projects-list',
    ]);
}

/**
 * Page: Project Form (Create/Edit)
 */
function palPageProjectForm(array $rp = []): void
{
    $user = palCurrentUser();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $svc = new palProjectService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);

    $project = null;
    $isEdit = false;
    if ($id > 0) {
        $project = $svc->get($id);
        $isEdit = true;
    }

    $db = palDb();
    $tid = (int)($user['tenant_id'] ?? 0);
    $s1 = $db->prepare('SELECT id, name FROM pal_clients WHERE tenant_id = :tid AND is_active = 1 ORDER BY name');
    $s1->execute([':tid' => $tid]);
    $clients = $s1->fetchAll(PDO::FETCH_ASSOC);
    $s2 = $db->prepare('SELECT id, name FROM pal_project_types WHERE tenant_id = :tid AND is_active = 1 ORDER BY name');
    $s2->execute([':tid' => $tid]);
    $types = $s2->fetchAll(PDO::FETCH_ASSOC);
    $s3 = $db->prepare('SELECT id, name FROM pal_team_leads WHERE tenant_id = :tid AND is_active = 1 ORDER BY name');
    $s3->execute([':tid' => $tid]);
    $teamLeads = $s3->fetchAll(PDO::FETCH_ASSOC);
    $s4 = $db->prepare("SELECT m.id, m.name, m.material_code, m.price_per_unit, m.price_per_sqft, 
                               m.default_width, m.default_height, mc.name AS category_name
                        FROM pal_materials m 
                        LEFT JOIN pal_material_categories mc ON m.category_id = mc.id 
                        WHERE m.tenant_id = :tid AND m.is_active = 1 ORDER BY m.name");
    $s4->execute([':tid' => $tid]);
    $materials = $s4->fetchAll(PDO::FETCH_ASSOC);

    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => $isEdit ? 'Edit Job Order' : 'New Job Order',
        'page_content' => 'project-form',
        'project' => $project,
        'is_edit' => $isEdit,
        'clients' => $clients,
        'project_types' => $types,
        'team_leads' => $teamLeads,
        'materials' => $materials,
    ]);
}

/**
 * Page: Project Detail
 */
function palPageProjectDetail(array $rp = []): void
{
    $user = palCurrentUser();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $svc = new palProjectService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
    $project = $svc->get($id);

    if ($project === null) {
        palJsonError('Project not found.', 404);
        return;
    }

    $costSvc = new palProjectCostService(palDb(), (int)($user['tenant_id'] ?? 0));
    $costs = $costSvc->getCostBreakdown($id);
    $profitability = $costSvc->getProfitability($id);
    $budget = $costSvc->getBudgetStatus($id);

    // Fetch line-item history for business owner view — use project's tenant
    $db = palDb();
    $tid = (int)($project['tenant_id'] ?? app()->tenant()->current() ?? $user['tenant_id'] ?? 0);
    $expRows = $db->prepare("SELECT e.*, ec.name AS category_name FROM pal_expenses e LEFT JOIN pal_expense_categories ec ON e.category_id = ec.id WHERE e.project_id = :pid AND e.tenant_id = :tid ORDER BY e.expense_date DESC, e.created_at DESC LIMIT 30");
    $expRows->execute([':pid' => $id, ':tid' => $tid]);
    $colRows = $db->prepare("SELECT c.*, s.sales_number FROM pal_collections c LEFT JOIN pal_sales s ON c.sales_id = s.id WHERE c.project_id = :pid AND c.tenant_id = :tid ORDER BY c.created_at DESC LIMIT 30");
    $colRows->execute([':pid' => $id, ':tid' => $tid]);
    $poRows = $db->prepare("SELECT p.*, s.name AS supplier_name FROM pal_purchases p LEFT JOIN pal_suppliers s ON p.supplier_id = s.id WHERE p.project_id = :pid AND p.tenant_id = :tid ORDER BY p.created_at DESC LIMIT 20");
    $poRows->execute([':pid' => $id, ':tid' => $tid]);

    $fabAmount = 0;
    if (!empty($project['fabrication_alloc_pct']) && (float)$project['fabrication_alloc_pct'] > 0) {
        $fabAmount = round((float)($project['contract_amount'] ?? 0) * (float)$project['fabrication_alloc_pct'] / 100, 2);
    }

    // Running totals: base is collected (if any) or contract (assumed collectible)
    $contractAmt = (float)($project['contract_amount'] ?? 0);
    $spentAmt = (float)($costs['total_cost'] ?? 0);
    $collectedAmt = (float)($profitability['total_collected'] ?? 0);
    $baseAmount = $collectedAmt > 0 ? $collectedAmt : $contractAmt;
    $runAfterFab = $baseAmount - $fabAmount;
    $runAfterSpent = $runAfterFab - $spentAmt;
    $runAfterCollected = $contractAmt + $collectedAmt;  // for display: Budget + Collected
    $netProfit = $runAfterSpent;
    $remainingCollectible = max(0, $contractAmt - $collectedAmt);

    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => 'JO: ' . ($project['title'] ?? 'Project'),
        'page_content' => 'project-detail',
        'project' => $project,
        'costs' => $costs,
        'profitability' => $profitability,
        'budget' => $budget,
        'fabrication_amount' => $fabAmount,
        'run_after_collected' => $runAfterCollected,
        'net_profit' => $netProfit,
        'run_after_fab' => $runAfterFab,
        'run_after_spent' => $runAfterSpent,
        'remaining_collectible' => $remainingCollectible,
        'net_profit' => $netProfit,
        'expense_history' => $expRows->fetchAll(PDO::FETCH_ASSOC),
        'collection_history' => $colRows->fetchAll(PDO::FETCH_ASSOC),
        'purchase_history' => $poRows->fetchAll(PDO::FETCH_ASSOC),
        'attachments_html' => palRenderAttachments('project', $id, $tid),
        'po_images_html' => palRenderPoImages($id, $tid),
    ]);
}

/**
 * API: Project List
 */
function palApiProjectList(): void
{
    palResponseGuard(function (): void {
        $user = palCurrentUser();
        $svc = new palProjectService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
        $result = $svc->list($_GET);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'rows' => $result['rows'], 'total' => $result['total']]);
    });
}

/**
 * API: Create Project
 */
function palApiProjectStore(): void
{
    palResponseGuard(function (): void {
        $user = palCurrentUser();
        palEnforceCsrf();

        // Auto-generate project_id if not provided
        if (empty($_POST['project_id'])) {
            $_POST['project_id'] = 'P-' . date('Ymd') . '-' . bin2hex(random_bytes(3));
        }

        $svc = new palProjectService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
        $id = $svc->create($_POST);

        // Relink mockup attachment if uploaded
        $mockupId = !empty($_POST['mockup_attachment_id']) ? (int)$_POST['mockup_attachment_id'] : 0;
        if ($mockupId > 0) {
            $db = palDb();
            $db->prepare("UPDATE pal_attachments SET entity_id = :eid WHERE id = :aid AND tenant_id = :tid AND entity_id = 0")
                ->execute([':eid' => $id, ':aid' => $mockupId, ':tid' => (int)($user['tenant_id'] ?? 0)]);
        }

        palAudit('pal.project.created', (int)$user['id'], 'pal_projects', (string)$id, null, ['title' => $_POST['title'] ?? '']);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id]);
    });
}

/**
 * API: Update Project
 */
function palApiProjectUpdate(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $user = palCurrentUser();
        palEnforceCsrf();

        $id = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        $svc = new palProjectService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
        $svc->update($id, $_POST);

        palAudit('pal.project.updated', (int)$user['id'], 'pal_projects', (string)$id, null, ['updated_fields' => array_keys($_POST)]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

/**
 * API: Update Project Status
 */
function palApiProjectStatus(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $user = palCurrentUser();
        palEnforceCsrf();

        $id = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';

        $svc = new palProjectService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
        $svc->updateStatus($id, $status);

        $event = $status === 'completed' ? 'pal.project.completed' : 'pal.project.updated';
        palAudit($event, (int)$user['id'], 'pal_projects', (string)$id, null, ['status' => $status]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

/**
 * API: Project Cost Breakdown
 */
function palApiProjectCost(): void
{
    palResponseGuard(function (): void {
        $user = palCurrentUser();
        $id = (int)($_GET['id'] ?? 0);

        $costSvc = new palProjectCostService(palDb(), (int)($user['tenant_id'] ?? 0));
        $costs = $costSvc->getCostBreakdown($id);
        $profitability = $costSvc->getProfitability($id);
        $budget = $costSvc->getBudgetStatus($id);

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'costs' => $costs,
            'profitability' => $profitability,
            'budget' => $budget,
        ]);
    });
}
