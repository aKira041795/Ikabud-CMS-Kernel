<?php

declare(strict_types=1);

function palPageQuotationList(): void
{
    $u = palCurrentUser();
    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, ['current_user' => $u, 'page_title' => 'Quotations', 'page_content' => 'quotations-list']);
}

function palPageQuotationDetail(array $rp = []): void
{
    $u = palCurrentUser();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $s = new palQuotationService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
    $quotation = $s->get($id);
    if (!$quotation) { palJsonError('Quotation not found.', 404); return; }

    $db = palDb();
    $tid = (int)($u['tenant_id'] ?? 0);
    $clients = $db->prepare("SELECT id, name FROM pal_clients WHERE tenant_id = :tid AND is_active = 1 ORDER BY name");
    $clients->execute([':tid' => $tid]);
    $projects = $db->prepare("SELECT id, title, job_order_number FROM pal_projects WHERE tenant_id = :tid ORDER BY title");
    $projects->execute([':tid' => $tid]);

    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, [
        'current_user' => $u,
        'page_title' => 'Quotation #' . $quotation['quotation_number'],
        'page_content' => 'quotation-detail',
        'quotation' => $quotation,
        'clients' => $clients->fetchAll(PDO::FETCH_ASSOC),
        'projects' => $projects->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

function palPageQuotationForm(array $rp = []): void
{
    $u = palCurrentUser();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $quotation = null;
    if ($id > 0) {
        $s = new palQuotationService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $quotation = $s->get($id);
    }

    $db = palDb();
    $tid = (int)($u['tenant_id'] ?? 0);

    $clients = $db->prepare("SELECT id, name FROM pal_clients WHERE tenant_id = :tid AND is_active = 1 ORDER BY name");
    $clients->execute([':tid' => $tid]);

    $projects = $db->prepare("SELECT id, title, job_order_number FROM pal_projects WHERE tenant_id = :tid ORDER BY title");
    $projects->execute([':tid' => $tid]);

    $materials = $db->prepare("SELECT m.id, m.name, m.material_code, m.price_per_unit, m.price_per_sqft, 
                                      m.default_width, m.default_height, mc.name AS category_name
                               FROM pal_materials m 
                               LEFT JOIN pal_material_categories mc ON m.category_id = mc.id 
                               WHERE m.tenant_id = :tid AND m.is_active = 1 ORDER BY m.name");
    $materials->execute([':tid' => $tid]);

    $t = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($t, [
        'current_user' => $u,
        'page_title' => $quotation ? 'Edit Quotation' : 'Create Quotation',
        'page_content' => 'quotation-form',
        'quotation' => $quotation,
        'is_edit' => $quotation !== null,
        'clients' => $clients->fetchAll(PDO::FETCH_ASSOC),
        'projects' => $projects->fetchAll(PDO::FETCH_ASSOC),
        'materials' => $materials->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

function palApiQuotationStore(): void
{
    palResponseGuard(function () {
        $u = palCurrentUser();
        palEnforceCsrf();
        $s = new palQuotationService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);

        $items = [];
        if (!empty($_POST['items']) && is_string($_POST['items'])) {
            // JSON-serialized line items (matches the sales form contract)
            $decoded = json_decode($_POST['items'], true);
            $items = is_array($decoded) ? $decoded : [];
        } elseif (!empty($_POST['items']) && is_array($_POST['items'])) {
            $items = $_POST['items'];
        } elseif (!empty($_POST['particulars']) && is_array($_POST['particulars'])) {
            // Form-encoded line items
            foreach ($_POST['particulars'] as $i => $part) {
                if (empty(trim((string)$part))) continue;
                $items[] = [
                    'material_id' => $_POST['material_id'][$i] ?? null,
                    'particulars' => $part,
                    'width' => $_POST['width'][$i] ?? null,
                    'height' => $_POST['height'][$i] ?? null,
                    'uom' => $_POST['uom'][$i] ?? null,
                    'quantity' => $_POST['quantity'][$i] ?? 1,
                    'price_per_unit' => $_POST['price_per_unit'][$i] ?? 0,
                    'price_per_sqft' => $_POST['price_per_sqft'][$i] ?? null,
                ];
            }
        }

        $data = $_POST;
        $data['items'] = $items;

        $id = $s->create($data);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id]);
    });
}

function palApiQuotationUpdate(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $u = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid ID.'); return; }
        $s = new palQuotationService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);

        $items = [];
        if (!empty($_POST['items']) && is_string($_POST['items'])) {
            $decoded = json_decode($_POST['items'], true);
            $items = is_array($decoded) ? $decoded : [];
        } elseif (!empty($_POST['items']) && is_array($_POST['items'])) {
            $items = $_POST['items'];
        } elseif (!empty($_POST['particulars']) && is_array($_POST['particulars'])) {
            foreach ($_POST['particulars'] as $i => $part) {
                if (empty(trim((string)$part))) continue;
                $items[] = [
                    'material_id' => $_POST['material_id'][$i] ?? null,
                    'particulars' => $part,
                    'width' => $_POST['width'][$i] ?? null,
                    'height' => $_POST['height'][$i] ?? null,
                    'uom' => $_POST['uom'][$i] ?? null,
                    'quantity' => $_POST['quantity'][$i] ?? 1,
                    'price_per_unit' => $_POST['price_per_unit'][$i] ?? 0,
                    'price_per_sqft' => $_POST['price_per_sqft'][$i] ?? null,
                ];
            }
        }

        $data = $_POST;
        $data['items'] = $items;

        $s->update($id, $data);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

function palApiQuotationConvert(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $u = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid ID.'); return; }
        $s = new palQuotationService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $saleId = $s->convertToSale($id);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'sale_id' => $saleId]);
    });
}

function palApiQuotationConvertToProject(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $u = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid ID.'); return; }
        $s = new palQuotationService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $projectId = $s->convertToProject($id);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'project_id' => $projectId]);
    });
}

function palApiQuotationStatus(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $u = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { palJsonError('Invalid ID.'); return; }
        $status = $_POST['status'] ?? '';
        $allowed = ['draft', 'sent', 'approved', 'rejected', 'converted', 'expired'];
        if (!in_array($status, $allowed, true)) { palJsonError('Invalid status.'); return; }
        $s = new palQuotationService(palDb(), (int)($u['tenant_id'] ?? 0), (int)$u['id']);
        $s->updateStatus($id, $status);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}
