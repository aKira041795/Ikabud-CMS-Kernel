<?php

declare(strict_types=1);

/**
 * PAL Basic Fixture — seeds minimal data for Workbench contract tests.
 *
 * SAFE by default: checks for existing admin user, skips if present.
 * Use --force to destroy and recreate all data (WARNING: deletes existing records).
 *
 * Usage:
 *   php tests/pal/fixtures/basic.php --tenant=ID [--force]
 *
 * Creates:
 *   1 admin user (paladmin / pAl123456)
 *   1 client
 *   1 project (draft)
 *
 * @see modules/project-audit-ledger/test-contract.json
 */

$tenantId = 0;
$force = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    }
    if ($arg === '--force') {
        $force = true;
    }
}

if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php tests/pal/fixtures/basic.php --tenant=ID [--force]\n");
    exit(1);
}

$base = dirname(__DIR__, 3);
require_once $base . '/bootstrap.php';

$db = app()->dbForTenant($tenantId);
if ($db === null) {
    fwrite(STDERR, "Tenant {$tenantId} not found.\n");
    exit(1);
}

// Check if admin already exists — safe default: skip if present
$check = $db->prepare("SELECT id FROM pal_users WHERE tenant_id = ? AND username = 'paladmin' LIMIT 1");
$check->execute([$tenantId]);
$existingAdmin = $check->fetchColumn();

if ($existingAdmin !== false && $existingAdmin > 0) {
    echo "Admin user 'paladmin' already exists (id={$existingAdmin}). Use --force to reset.\n";
    exit(0);
}

$owns = [
    'pal_users', 'pal_clients', 'pal_projects', 'pal_project_items',
    'pal_expenses', 'pal_expense_categories', 'pal_approvals', 'pal_audit_logs',
    'pal_sales', 'pal_sale_items', 'pal_receivables', 'pal_receivable_payments',
    'pal_fabrication_allocations', 'pal_fabrication_weekly_dues', 'pal_fabrication_payments',
    'pal_team_leads', 'pal_collections', 'pal_settings', 'pal_project_types',
    'pal_materials', 'pal_suppliers', 'pal_inventory_balances', 'pal_inventory_movements',
    'pal_material_issuances', 'pal_material_issuance_items', 'pal_purchases', 'pal_purchase_items',
    'pal_cash_advances', 'pal_quotations', 'pal_quotation_items', 'pal_otp_codes',
    'pal_mobilization_requests', 'pal_material_returns', 'pal_material_categories',
    'pal_units', 'pal_inventory_locations', 'pal_attachments', 'pal_report_exports',
];

// Clean slate — only with --force
if ($force) {
    echo "WARNING: --force mode — deleting all PAL data for tenant {$tenantId}\n";
    foreach ($owns as $t) {
        try { $db->exec("DELETE FROM {$t} WHERE tenant_id = {$tenantId}"); } catch (\Throwable $e) {}
    }
}

// Admin user (INSERT IGNORE in case of race)
$stmt = $db->prepare("INSERT IGNORE INTO pal_users (tenant_id, username, email, password_hash, full_name, role, is_active) VALUES (?,?,?,?,?,'admin',1)");
$stmt->execute([$tenantId, 'paladmin', 'paladmin@test.local', password_hash('pAl123456', PASSWORD_BCRYPT), 'PAL Admin']);
$adminId = (int) $db->lastInsertId();
if ($adminId === 0) {
    // Already exists — fetch existing
    $check->execute([$tenantId]);
    $adminId = (int) $check->fetchColumn();
}

// Client (INSERT IGNORE)
$stmt = $db->prepare("INSERT IGNORE INTO pal_clients (tenant_id, name, contact_person, email, phone, address, is_active) VALUES (?,?,?,?,?,?,1)");
$stmt->execute([$tenantId, 'Test Client', 'Contact Person', 'client@test.local', '1234567890', 'Test Address']);
$clientId = (int) $db->lastInsertId();
if ($clientId === 0) {
    $c = $db->prepare("SELECT id FROM pal_clients WHERE tenant_id = ? AND name = 'Test Client' LIMIT 1");
    $c->execute([$tenantId]);
    $clientId = (int) $c->fetchColumn();
}

// Project (draft) — INSERT IGNORE
$stmt = $db->prepare("INSERT IGNORE INTO pal_projects (tenant_id, project_id, job_order_number, jo_type, title, client_id, contract_amount, estimated_cost, status, created_by) VALUES (?,?,?,'contract',?,?,?,?,'draft',?)");
$stmt->execute([$tenantId, 'BASIC-FIXTURE-001', 'JO-BASIC-001', 'Basic Fixture Project', $clientId, 100000, 60000, $adminId]);
$projectId = (int) $db->lastInsertId();
if ($projectId === 0) {
    $p = $db->prepare("SELECT id FROM pal_projects WHERE tenant_id = ? AND project_id = 'BASIC-FIXTURE-001' LIMIT 1");
    $p->execute([$tenantId]);
    $projectId = (int) $p->fetchColumn();
}

echo "Fixtures ready for tenant {$tenantId}: user={$adminId}, client={$clientId}, project={$projectId}\n";
