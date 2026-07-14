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
    $s1 = $db->prepare('SELECT id, name, contact_person, email, phone, address FROM pal_clients WHERE tenant_id = :tid AND is_active = 1 ORDER BY name');
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

    // Fetch existing mockup attachment for edit mode
    $mockupId = 0;
    $mockupUrl = '';
    if ($isEdit && $id > 0) {
        $attStmt = $db->prepare("SELECT id, file_path FROM pal_attachments WHERE tenant_id = :tid AND entity_type = 'project' AND entity_id = :eid AND description = 'Mockup image' ORDER BY created_at DESC LIMIT 1");
        $attStmt->execute([':tid' => $tid, ':eid' => $id]);
        $att = $attStmt->fetch(PDO::FETCH_ASSOC);
        if ($att) {
            $mockupId = (int)$att['id'];
            $mockupUrl = '/' . $att['file_path'];
        }
    }

    palRender($template, [
        'current_user' => $user,
        'prepared_by' => $user['full_name'] ?? $user['username'] ?? 'Unknown',
        'page_title' => $isEdit ? 'Edit Job Order' : 'New Job Order',
        'page_content' => 'project-form',
        'project' => $project,
        'is_edit' => $isEdit,
        'clients' => $clients,
        'project_types' => $types,
        'team_leads' => $teamLeads,
        'materials' => $materials,
        'mockup_attachment_id' => $mockupId,
        'mockup_url' => $mockupUrl,
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

    // Fetch mockup image for detail view
    $mockupUrl = '';
    $mStmt = $db->prepare("SELECT file_path FROM pal_attachments WHERE tenant_id = :tid AND entity_type = 'project' AND entity_id = :eid AND description = 'Mockup image' ORDER BY created_at DESC LIMIT 1");
    $mStmt->execute([':tid' => $tid, ':eid' => $id]);
    $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);
    if ($mRow) {
        $mockupUrl = '/' . $mRow['file_path'];
    }

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
        'mockup_url' => $mockupUrl,
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

        // If submitted with status, run workflow + create approval
        $newStatus = $_POST['status'] ?? null;
        if ($newStatus && $newStatus !== 'draft') {
            try {
                $wf = new palJobOrderWorkflow(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
                $wf->apply($id, $newStatus);
                if ($newStatus === 'pending') {
                    palCreateApproval('project', $id, (int)$user['id'], 'draft', 'pending_approval');
                }
            } catch (\Throwable $e) {
                write_log('pal.project.store.workflow_failed', 'warning', [
                    'project_id' => $id, 'to' => $newStatus, 'error' => $e->getMessage(),
                ]);
            }
        }

        // Relink mockup attachment if uploaded
        $mockupId = !empty($_POST['mockup_attachment_id']) ? (int)$_POST['mockup_attachment_id'] : 0;
        if ($mockupId > 0) {
            $db = palDb();
            $db->prepare("UPDATE pal_attachments SET entity_id = :eid WHERE id = :aid AND tenant_id = :tid AND entity_id = 0")
                ->execute([':eid' => $id, ':aid' => $mockupId, ':tid' => (int)($user['tenant_id'] ?? 0)]);
            // Move file on disk from project/0/ to project/{id}/
            $oldDir = PUBLIC_PATH . '/uploads/pal/' . (int)($user['tenant_id'] ?? 0) . '/project/0';
            $newDir = PUBLIC_PATH . '/uploads/pal/' . (int)($user['tenant_id'] ?? 0) . '/project/' . $id;
            if (is_dir($oldDir) && !is_dir($newDir)) {
                @rename($oldDir, $newDir);
            }
        }

        palAudit('pal.project.created', (int)$user['id'], 'pal_projects', (string)$id, null, ['title' => $_POST['title'] ?? '']);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id, 'redirect' => '/admin/project-audit-ledger/projects/' . $id]);
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
        $newStatus = $_POST['status'] ?? null;
        $svc = new palProjectService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);

        // Get current status before update for workflow detection
        $project = $svc->get($id);
        $oldStatus = $project ? ($project['status'] ?? '') : '';

        $svc->update($id, $_POST);

        // If status changed, run through workflow engine to create approvals, fire events
        if ($newStatus && $newStatus !== $oldStatus) {
            try {
                $wf = new palJobOrderWorkflow(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
                $wf->apply($id, $newStatus);

                // Create approval record for status transitions that need review
                if ($newStatus === 'pending') {
                    palCreateApproval('project', $id, (int)$user['id'], $oldStatus, 'pending_approval');
                }
            } catch (\Throwable $e) {
                // Workflow transition may be invalid — status already updated above,
                // but workflow side-effects (approval, events) won't fire.
                write_log('pal.project.update.workflow_failed', 'warning', [
                    'project_id' => $id, 'from' => $oldStatus, 'to' => $newStatus,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Relink mockup attachment if uploaded (entity_id=0 → real project id)
        $mockupId = !empty($_POST['mockup_attachment_id']) ? (int)$_POST['mockup_attachment_id'] : 0;
        if ($mockupId > 0) {
            $db = palDb();
            $tid = (int)($user['tenant_id'] ?? 0);
            $db->prepare("UPDATE pal_attachments SET entity_id = :eid WHERE id = :aid AND tenant_id = :tid AND entity_id = 0")
                ->execute([':eid' => $id, ':aid' => $mockupId, ':tid' => $tid]);
            // Update file_path and move file on disk from project/0/ to project/{id}/
            $db->prepare("UPDATE pal_attachments SET file_path = REPLACE(file_path, '/project/0/', :np) WHERE id = :aid AND tenant_id = :tid AND file_path LIKE '%/project/0/%'")
                ->execute([':np' => '/project/' . $id . '/', ':aid' => $mockupId, ':tid' => $tid]);
            $oldDir = PUBLIC_PATH . '/uploads/pal/' . $tid . '/project/0';
            $newDir = PUBLIC_PATH . '/uploads/pal/' . $tid . '/project/' . $id;
            if (is_dir($oldDir) && !is_dir($newDir)) {
                @rename($oldDir, $newDir);
            }
        }

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
        $project = $svc->get($id);
        $oldStatus = $project ? ($project['status'] ?? '') : '';

        if ($status === 'completed') {
            $svc->completeProject($id);
            palAudit('pal.project.completed', (int)$user['id'], 'pal_projects', (string)$id, null, [
                'status' => $status, 'auto_invoiced' => true,
            ]);
        } else {
            $svc->updateStatus($id, $status);

            // Run workflow and create approval record when status changes
            if ($status && $status !== $oldStatus) {
                try {
                    $wf = new palJobOrderWorkflow(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
                    $wf->apply($id, $status);

                    if ($status === 'pending') {
                        palCreateApproval('project', $id, (int)$user['id'], $oldStatus, 'pending_approval');
                    }
                } catch (\Throwable $e) {
                    write_log('pal.project.status.workflow_failed', 'warning', [
                        'project_id' => $id, 'from' => $oldStatus, 'to' => $status,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            palAudit('pal.project.status_changed', (int)$user['id'], 'pal_projects', (string)$id, null, ['status' => $status]);
        }

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

/**
 * API: Send project detail as email to client
 * POST /api/v1/project-audit-ledger/projects/{id}/email
 */
function palApiProjectSendEmail(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $user = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid project ID.'); return; }

        $svc = new palProjectService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
        $project = $svc->get($id);
        if (!$project) { palJsonError('Project not found.', 404); return; }

        $clientEmail = $project['client_email'] ?? '';
        if ($clientEmail === '' || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            palJsonError('Client has no valid email address on file.');
            return;
        }

        $tid = (int)($user['tenant_id'] ?? 0);
        $db = palDb();
        $settings = palSettings();

        // Mockup URL
        $mockupUrl = '';
        $mockupStmt = $db->prepare("SELECT file_path FROM pal_attachments WHERE tenant_id = :tid AND entity_type = 'project' AND entity_id = :eid AND description = 'Mockup image' ORDER BY created_at DESC LIMIT 1");
        $mockupStmt->execute([':tid' => $tid, ':eid' => $id]);
        $mockup = $mockupStmt->fetch(PDO::FETCH_ASSOC);
        if ($mockup) {
            $mockupUrl = '/' . $mockup['file_path'];
        }

        // Render email via DiSyL template
        $companyName = $settings['company_name'] ?? 'ZAP-ARTS';
        $joNum = $project['job_order_number'] ?? $project['project_id'] ?? '';
        $subject = 'Job Order Summary — ' . $joNum . ' — ' . $companyName;

        $template = __DIR__ . '/../templates/project-audit-ledger/_email_job_order.disyl';
        $body = app()->render($template, [
            'company_name'    => $companyName,
            'client_name'     => $project['client_name'] ?? 'Valued Client',
            'jo_number'       => $joNum,
            'project_title'   => $project['title'] ?? '',
            'scope_of_work'   => $project['scope_of_work'] ?? '',
            'contract_amount' => number_format((float)($project['contract_amount'] ?? 0), 2),
            'status'          => $project['status'] ?? '',
            'items'           => $project['items'] ?? [],
            'mockup_url'      => $mockupUrl,
        ]);

        $sent = sendEmail($clientEmail, $subject, $body);
        if (!$sent) {
            palJsonError('Failed to send email. Check SMTP configuration.');
            return;
        }

        palAudit('pal.project.emailed', (int)$user['id'], 'pal_projects', (string)$id, null, ['to' => $clientEmail]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'to' => $clientEmail]);
    });
}

/**
 * API: Get project items (JO line items) for auto-populating sales/quotations
 * GET /api/v1/project-audit-ledger/projects/{id}/items
 */
function palApiProjectItems(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $user = palCurrentUser();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid project ID.'); return; }

        $svc = new palProjectService(palDb(), (int)($user['tenant_id'] ?? 0), (int)$user['id']);
        $project = $svc->get($id);

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'items' => $project['items'] ?? [],
            'contract_amount' => $project['contract_amount'] ?? 0,
            'installation_charge' => $project['installation_charge'] ?? 0,
            'mobilization_charge' => $project['mobilization_charge'] ?? 0,
            'other_charges' => $project['other_charges'] ?? 0,
            'scope_of_work' => $project['scope_of_work'] ?? null,
            'mode_of_payment' => $project['mode_of_payment'] ?? null,
            'client_id' => $project['client_id'] ?? null,
        ]);
    });
}
