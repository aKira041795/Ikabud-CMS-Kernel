<?php

declare(strict_types=1);

/**
 * Page: Client List
 */
function palPageClientList(): void
{
    $user = palCurrentUser();
    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => 'Clients',
        'page_content' => 'clients-list',
    ]);
}

/**
 * Page: Client Form (Create/Edit)
 */
function palPageClientForm(array $rp = []): void
{
    $user = palCurrentUser();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $client = null;
    if ($id > 0) {
        $stmt = palDb()->prepare('SELECT * FROM pal_clients WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $user['tenant_id'] ?? 0]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => $client ? 'Edit Client' : 'Create Client',
        'page_content' => 'client-form',
        'client' => $client,
        'is_edit' => $client !== null,
    ]);
}

/**
 * Page: Client Detail (with linked projects)
 */
function palPageClientDetail(array $rp = []): void
{
    $user = palCurrentUser();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $db = palDb();
    $stmt = $db->prepare('SELECT * FROM pal_clients WHERE id = :id AND tenant_id = :tenant_id');
    $stmt->execute([':id' => $id, ':tenant_id' => $user['tenant_id'] ?? 0]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) { palJsonError('Client not found.', 404); return; }

    $ps = $db->prepare('SELECT id, title, status, contract_amount, start_date, target_completion_date FROM pal_projects WHERE client_id = :cid AND tenant_id = :tid ORDER BY created_at DESC');
    $ps->execute([':cid' => $id, ':tid' => $user['tenant_id'] ?? 0]);
    $projects = $ps->fetchAll(PDO::FETCH_ASSOC);

    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => $client['name'],
        'page_content' => 'client-detail',
        'client' => $client,
        'clientProjects' => $projects,
    ]);
}

/**
 * Page: Supplier List
 */
function palPageSupplierList(): void
{
    $user = palCurrentUser();
    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => 'Suppliers',
        'page_content' => 'suppliers-list',
    ]);
}

/**
 * Page: Supplier Form (Create/Edit)
 */
function palPageSupplierForm(array $rp = []): void
{
    $user = palCurrentUser();
    $id = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $supplier = null;
    if ($id > 0) {
        $stmt = palDb()->prepare('SELECT * FROM pal_suppliers WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $user['tenant_id'] ?? 0]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => $supplier ? 'Edit Supplier' : 'Create Supplier',
        'page_content' => 'supplier-form',
        'supplier' => $supplier,
        'is_edit' => $supplier !== null,
    ]);
}

// ── API handlers ──

function palApiClientStore(): void
{
    palResponseGuard(function (): void {
        $user = palCurrentUser();
        palEnforceCsrf();
        $db = palDb();
        $stmt = $db->prepare(
            'INSERT INTO pal_clients (tenant_id, name, contact_person, email, phone, address, notes, created_by)
             VALUES (:tenant_id, :name, :contact_person, :email, :phone, :address, :notes, :created_by)'
        );
        $stmt->execute([
            ':tenant_id' => $user['tenant_id'] ?? 0,
            ':name' => $_POST['name'] ?? '',
            ':contact_person' => $_POST['contact_person'] ?? null,
            ':email' => $_POST['email'] ?? null,
            ':phone' => $_POST['phone'] ?? null,
            ':address' => $_POST['address'] ?? null,
            ':notes' => $_POST['notes'] ?? null,
            ':created_by' => (int)$user['id'],
        ]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    });
}

function palApiClientUpdate(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $user = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        $db = palDb();
        $stmt = $db->prepare(
            'UPDATE pal_clients SET name = :name, contact_person = :contact_person, email = :email,
             phone = :phone, address = :address, notes = :notes, updated_by = :updated_by
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            ':name' => $_POST['name'] ?? '',
            ':contact_person' => $_POST['contact_person'] ?? null,
            ':email' => $_POST['email'] ?? null,
            ':phone' => $_POST['phone'] ?? null,
            ':address' => $_POST['address'] ?? null,
            ':notes' => $_POST['notes'] ?? null,
            ':updated_by' => (int)$user['id'],
            ':id' => $id,
            ':tenant_id' => $user['tenant_id'] ?? 0,
        ]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

function palApiSupplierStore(): void
{
    palResponseGuard(function (): void {
        $user = palCurrentUser();
        palEnforceCsrf();
        $db = palDb();
        $stmt = $db->prepare(
            'INSERT INTO pal_suppliers (tenant_id, name, contact_person, email, phone, address, payment_terms, created_by)
             VALUES (:tenant_id, :name, :contact_person, :email, :phone, :address, :payment_terms, :created_by)'
        );
        $stmt->execute([
            ':tenant_id' => $user['tenant_id'] ?? 0,
            ':name' => $_POST['name'] ?? '',
            ':contact_person' => $_POST['contact_person'] ?? null,
            ':email' => $_POST['email'] ?? null,
            ':phone' => $_POST['phone'] ?? null,
            ':address' => $_POST['address'] ?? null,
            ':payment_terms' => $_POST['payment_terms'] ?? null,
            ':created_by' => (int)$user['id'],
        ]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    });
}

function palApiSupplierUpdate(array $rp = []): void
{
    palResponseGuard(function () use ($rp): void {
        $user = palCurrentUser();
        palEnforceCsrf();
        $id = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        $db = palDb();
        $stmt = $db->prepare(
            'UPDATE pal_suppliers SET name = :name, contact_person = :contact_person, email = :email,
             phone = :phone, address = :address, payment_terms = :payment_terms, updated_by = :updated_by
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            ':name' => $_POST['name'] ?? '',
            ':contact_person' => $_POST['contact_person'] ?? null,
            ':email' => $_POST['email'] ?? null,
            ':phone' => $_POST['phone'] ?? null,
            ':address' => $_POST['address'] ?? null,
            ':payment_terms' => $_POST['payment_terms'] ?? null,
            ':updated_by' => (int)$user['id'],
            ':id' => $id,
            ':tenant_id' => $user['tenant_id'] ?? 0,
        ]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}
