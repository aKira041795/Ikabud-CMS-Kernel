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
            // Move file on disk from project/0/ to project/{id}/
            $oldDir = PUBLIC_PATH . '/uploads/pal/' . (int)($user['tenant_id'] ?? 0) . '/project/0';
            $newDir = PUBLIC_PATH . '/uploads/pal/' . (int)($user['tenant_id'] ?? 0) . '/project/' . $id;
            if (is_dir($oldDir) && !is_dir($newDir)) {
                @rename($oldDir, $newDir);
            }
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

        // ── Build email body ──
        $companyName = $settings['company_name'] ?? 'ZAP-ARTS';
        $projectTitle = htmlspecialchars($project['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $joNum = htmlspecialchars($project['job_order_number'] ?? $project['project_id'] ?? '', ENT_QUOTES, 'UTF-8');
        $clientName = htmlspecialchars($project['client_name'] ?? 'Valued Client', ENT_QUOTES, 'UTF-8');
        $scopeOfWork = htmlspecialchars($project['scope_of_work'] ?? '', ENT_QUOTES, 'UTF-8');
        $contractAmount = number_format((float)($project['contract_amount'] ?? 0), 2);
        $status = htmlspecialchars($project['status'] ?? '', ENT_QUOTES, 'UTF-8');
        $joType = $project['jo_type'] ?? 'items';

        $items = $project['items'] ?? [];

        // Build BOM table
        $bomHtml = '';
        if (!empty($items)) {
            $bomHtml = '<table style="width:100%;border-collapse:collapse;margin-top:12px;font-size:13px;">'
                . '<thead><tr style="background:#f3f4f6;">'
                . '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #e5e7eb;">#</th>'
                . '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #e5e7eb;">Material</th>'
                . '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #e5e7eb;">Particulars</th>'
                . '<th style="padding:8px 12px;text-align:center;border-bottom:2px solid #e5e7eb;">W×H</th>'
                . '<th style="padding:8px 12px;text-align:center;border-bottom:2px solid #e5e7eb;">QTY</th>'
                . '<th style="padding:8px 12px;text-align:right;border-bottom:2px solid #e5e7eb;">Price/Unit</th>'
                . '<th style="padding:8px 12px;text-align:right;border-bottom:2px solid #e5e7eb;">Total</th>'
                . '</tr></thead><tbody>';
            foreach ($items as $item) {
                $matName = htmlspecialchars($item['material_name'] ?? $item['particulars'] ?? '', ENT_QUOTES, 'UTF-8');
                $part = htmlspecialchars($item['particulars'] ?? '', ENT_QUOTES, 'UTF-8');
                $wh = ($item['width'] && $item['height']) ? htmlspecialchars((string)$item['width'], ENT_QUOTES, 'UTF-8') . '×' . htmlspecialchars((string)$item['height'], ENT_QUOTES, 'UTF-8') : '—';
                $qty = number_format((float)($item['quantity'] ?? 1), 2);
                $ppu = '₱' . number_format((float)($item['price_per_unit'] ?? 0), 2);
                $lineTotal = '₱' . number_format((float)($item['line_total'] ?? 0), 2);
                $bomHtml .= '<tr style="border-bottom:1px solid #e5e7eb;">'
                    . '<td style="padding:6px 12px;text-align:center;">' . ((int)($item['sort_order'] ?? 0)) . '</td>'
                    . '<td style="padding:6px 12px;">' . $matName . '</td>'
                    . '<td style="padding:6px 12px;">' . $part . '</td>'
                    . '<td style="padding:6px 12px;text-align:center;">' . $wh . '</td>'
                    . '<td style="padding:6px 12px;text-align:center;">' . $qty . '</td>'
                    . '<td style="padding:6px 12px;text-align:right;">' . $ppu . '</td>'
                    . '<td style="padding:6px 12px;text-align:right;font-weight:bold;">' . $lineTotal . '</td>'
                    . '</tr>';
            }
            // Subtotal
            $subtotal = 0;
            foreach ($items as $item) { $subtotal += (float)($item['line_total'] ?? 0); }
            $bomHtml .= '<tr style="background:#f9fafb;font-weight:bold;">'
                . '<td colspan="6" style="padding:8px 12px;text-align:right;">Subtotal:</td>'
                . '<td style="padding:8px 12px;text-align:right;">₱' . number_format($subtotal, 2) . '</td>'
                . '</tr></tbody></table>';
        }

        // Mockup image
        $mockupHtml = '';
        $mockupStmt = $db->prepare("SELECT file_path FROM pal_attachments WHERE tenant_id = :tid AND entity_type = 'project' AND entity_id = :eid AND description = 'Mockup image' ORDER BY created_at DESC LIMIT 1");
        $mockupStmt->execute([':tid' => $tid, ':eid' => $id]);
        $mockup = $mockupStmt->fetch(PDO::FETCH_ASSOC);
        if ($mockup) {
            $mockupUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'palsystem.test') . '/' . $mockup['file_path'];
            $mockupHtml = '<p><strong>Design Mockup:</strong></p><p><a href="' . htmlspecialchars($mockupUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank"><img src="' . htmlspecialchars($mockupUrl, ENT_QUOTES, 'UTF-8') . '" style="max-width:400px;border:1px solid #e5e7eb;border-radius:8px;"></a></p>';
        }

        $content = '<p>Hi ' . $clientName . ',</p>'
            . '<p>Here is the summary of your Job Order with <strong>' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <tr><td style="padding:4px 12px;font-weight:bold;color:#374151;">JO Number:</td><td style="padding:4px 12px;">' . $joNum . '</td></tr>
                <tr><td style="padding:4px 12px;font-weight:bold;color:#374151;">Project:</td><td style="padding:4px 12px;">' . $projectTitle . '</td></tr>
                <tr><td style="padding:4px 12px;font-weight:bold;color:#374151;">Scope:</td><td style="padding:4px 12px;">' . ($scopeOfWork ?: '—') . '</td></tr>
                <tr><td style="padding:4px 12px;font-weight:bold;color:#374151;">Total Amount:</td><td style="padding:4px 12px;font-weight:bold;color:#059669;">₱' . $contractAmount . '</td></tr>
                <tr><td style="padding:4px 12px;font-weight:bold;color:#374151;">Status:</td><td style="padding:4px 12px;">' . ucfirst($status) . '</td></tr>
              </table>'
            . ($bomHtml ? '<h3 style="margin-top:20px;">📋 Bill of Materials</h3>' . $bomHtml : '')
            . ($mockupHtml ? '<div style="margin-top:20px;">' . $mockupHtml . '</div>' : '')
            . '<p style="margin-top:20px;color:#6b7280;font-size:12px;">This is an automated message from ' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '. For inquiries, please contact us directly.</p>';

        $subject = 'Job Order Summary — ' . $joNum . ' — ' . $companyName;
        $body = buildEmailTemplate('Job Order Summary: ' . $joNum, $content);

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
