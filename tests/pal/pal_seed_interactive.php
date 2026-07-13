<?php
/**
 * PAL Interactive Seed Script — Minimal draft project for browser lifecycle test.
 *
 * Creates only a client and a draft project. The browser test performs
 * all status transitions through the actual PAL UI.
 *
 * Usage:
 *   php tests/pal/pal_seed_interactive.php [--tenant=N]
 *
 * Output (stdout):
 *   {"ok":true,"tenant_id":999911,"client_id":1,"project_id":2,...}
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'palsystem.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'palsystem.test';

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../modules/project-audit-ledger/helpers.php';
require_once __DIR__ . '/../../modules/project-audit-ledger/handlers.php';

$tenantId = 999911;
$isCleanup = in_array('--cleanup', $argv ?? [], true);

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--tenant=')) $tenantId = (int) substr($arg, 9);
}
if ($tenantId === 999911 && getenv('PAL_TEST_TENANT')) $tenantId = (int) getenv('PAL_TEST_TENANT');

$db = app()->db();
$cleanupTables = ['pal_projects', 'pal_clients', 'pal_users', 'pal_sales', 'pal_sale_items',
    'pal_receivables', 'pal_receivable_payments', 'pal_collections', 'pal_approvals'];

foreach ($cleanupTables as $t) {
    try { $db->exec("DELETE FROM {$t} WHERE tenant_id = {$tenantId}"); } catch (\Throwable) {}
}

if ($isCleanup) {
    echo json_encode(['ok' => true, 'action' => 'cleanup', 'tenant_id' => $tenantId]) . "\n";
    exit(0);
}

// Seed: user + client + draft project only
$sc = 0;
$prefix = 'INT-' . date('Ymd');

// User
$sc++; $db->prepare("INSERT INTO pal_users (tenant_id, username, email, password_hash, full_name, role, is_active) VALUES (?,?,?,?,?,'admin',1)")
    ->execute([$tenantId, "intu{$sc}", "int{$sc}@seed.com", 'hash', "Interactive $sc"]);

// Client
$sc++; $db->prepare("INSERT INTO pal_clients (tenant_id, name, contact_person, email, phone, address, is_active) VALUES (?,?,?,?,?,?,1)")
    ->execute([$tenantId, "Interactive Client {$sc}", "Contact {$sc}", "ic{$sc}@seed.com", "0917{$sc}", "{$sc} Seed Street"]);
$clientId = (int)$db->lastInsertId();

// Draft project (contract type with items for full lifecycle)
$sc++; $db->prepare("INSERT INTO pal_projects (tenant_id, project_id, job_order_number, jo_type, title, client_id, contract_amount, estimated_cost, status, created_by) VALUES (?,?,?,'contract',?,?,?,?,'draft',1)")
    ->execute([$tenantId, "PJ-{$prefix}-{$sc}", "JO-{$prefix}-{$sc}", "Interactive Project {$sc}", $clientId, 150000, 90000]);
$projectId = (int)$db->lastInsertId();

// Add some line items
$sc++;
$db->prepare("INSERT INTO pal_project_items (tenant_id, project_id, material_id, particulars, width, height, quantity, sort_order) VALUES (?,?,0,?,0,0,1,1)")
    ->execute([$tenantId, $projectId, "Line item for interactive test {$sc}"]);

echo json_encode([
    'ok' => true,
    'tenant_id' => $tenantId,
    'prefix' => $prefix,
    'client_id' => $clientId,
    'project_id' => $projectId,
    'project_status' => 'draft',
], JSON_PRETTY_PRINT) . "\n";
exit(0);
