<?php

declare(strict_types=1);

/**
 * Moto Inventory — Sales Flow Acceptance Test (disposable tenant DB).
 *
 * End-to-end: cashier completes a sale with a stable receipt/reference,
 * undo within the window, privileged void, profit reversal, double-void
 * rejection, audit, and event emission. Verifies the exact acceptance
 * criteria for the sales lifecycle.
 *
 * Run: php tests/moto_inventory_sales_flow_test.php
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/moto_inventory_test_helper.php';

// App bootstrap MUST run in global scope for $config visibility.
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/helpers.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/handlers.php';

$h = new TestHarness('moto-inventory-sales-flow', TestHarness::MODE_PURE);

$base = $h->basePath();
$h->fingerprint('modules/moto-inventory/services/SaleService.php');
$h->fingerprint('modules/moto-inventory/services/StockService.php');

$tenant = null;
try {
    $tenant = moto_test_create_tenant();
} catch (\Throwable $e) {
    $h->test('disposable tenant provisioned', false, $e->getMessage());
    $h->gap('Sales flow integration requires MySQL — skipped');
    $h->done();
}

$tid = $tenant['tenant_id'];
$pdo = $tenant['pdo'];
$admin = moto_test_admin_ctx($tid);
$cashier = moto_test_cashier_ctx($tid);

$pdo->prepare('INSERT INTO moto_branches (tenant_id, branch_key, name) VALUES (:t, :k, :n)')->execute([':t' => $tid, ':k' => 'main', ':n' => 'Main']);
$branchId = (int)$pdo->lastInsertId();
$admin['branch_ids'] = [$branchId];
$cashier['branch_ids'] = [$branchId];

$brand = CatalogService::createBrand($admin, 'Honda');
$brandId = $brand['id'];
$p1 = CatalogService::createProduct($admin, $branchId, ['brand_id' => $brandId, 'part_number' => 'H-001', 'description' => 'Brake Shoe', 'cost' => 200, 'price' => 450, 'qty' => 20]);
$p2 = CatalogService::createProduct($admin, $branchId, ['brand_id' => $brandId, 'part_number' => 'H-002', 'description' => 'Clutch Cable', 'cost' => 120, 'price' => 280, 'qty' => 15]);

// ── Cashier completes a sale ───────────────────────────────────────
$h->section('Cashier completes a sale');

$sale = SaleService::complete($cashier, $branchId, [
    ['product_id' => $p1['id'], 'qty' => 3, 'price' => 450],
    ['product_id' => $p2['id'], 'qty' => 2, 'price' => 280],
], 'Tricycle Driver', 'flow-1');
$h->test('sale completes with stable reference', ($sale['sale_id'] ?? 0) > 0 && preg_match('/^S-\d{8}-\d{6}-[A-F0-9]{6}$/', (string)($sale['sale_ref'] ?? '')) === 1);
$h->test('total = 3*450 + 2*280 = 1910', ($sale['total'] ?? 0) === 1910.0);
$h->test('cost = 3*200 + 2*120 = 840', ($sale['cost'] ?? 0) === 840.0);
$h->test('profit = 1070', ($sale['profit'] ?? 0) === 1070.0);
$h->test('receipt payload present', isset($sale['receipt']['sale_ref']) && ($sale['receipt']['items'] ?? 0) === 2);
$h->test('cashier is recorded as creator', (int)$pdo->query("SELECT created_by FROM moto_sales WHERE id = " . (int)$sale['sale_id'])->fetchColumn() === 2);
$h->test('sale items persisted', (int)$pdo->query("SELECT COUNT(*) FROM moto_sale_items WHERE sale_id = " . (int)$sale['sale_id'])->fetchColumn() === 2);
$h->test('stock p1 = 17', (float)CatalogService::productById($admin, $p1['id'], $branchId)['qty_on_hand'] === 17.0);
$h->test('stock p2 = 13', (float)CatalogService::productById($admin, $p2['id'], $branchId)['qty_on_hand'] === 13.0);

// ── Cashier undo (within window) ───────────────────────────────────
$h->section('Cashier undo (within window)');

$sale2 = SaleService::complete($cashier, $branchId, [['product_id' => $p2['id'], 'qty' => 1, 'price' => 280]], null, 'flow-2');
$undo = SaleService::undoLatest($cashier, $branchId);
$h->test('undo targets the latest sale', ($undo['sale_id'] ?? 0) === (int)$sale2['sale_id']);
$h->test('undo restores p2 to 13 (12 + 1)', (float)CatalogService::productById($admin, $p2['id'], $branchId)['qty_on_hand'] === 13.0);
$h->test('undone sale is voided', $pdo->query("SELECT status FROM moto_sales WHERE id = " . (int)$sale2['sale_id'])->fetchColumn() === 'voided');

// ── Privileged void by admin ───────────────────────────────────────
$h->section('Privileged void');

$sale3 = SaleService::complete($cashier, $branchId, [['product_id' => $p1['id'], 'qty' => 2, 'price' => 450]], null, 'flow-3');
$voided = SaleService::void($admin, $branchId, $sale3['sale_id']);
$h->test('admin void succeeds', ($voided['status'] ?? '') === 'voided');
$h->test('void restores p1 to 17 (15 + 2)', (float)CatalogService::productById($admin, $p1['id'], $branchId)['qty_on_hand'] === 17.0);
$h->test('original sale not deleted', (int)$pdo->query("SELECT COUNT(*) FROM moto_sales WHERE id = " . (int)$sale3['sale_id'])->fetchColumn() === 1);

$double = false;
try {
    SaleService::void($admin, $branchId, $sale3['sale_id']);
} catch (\RuntimeException $e) {
    $double = true;
}
$h->test('double void rejected', $double);

// ── Cross-branch rejection ─────────────────────────────────────────
$h->section('Cross-branch & permission guards');

$pdo->prepare('INSERT INTO moto_branches (tenant_id, branch_key, name) VALUES (:t, :k, :n)')->execute([':t' => $tid, ':k' => 'br2', ':n' => 'Branch Two']);
$branch2 = (int)$pdo->lastInsertId();
$crossRejected = false;
try {
    SaleService::complete($cashier, $branchId, [
        ['product_id' => $p1['id'], 'qty' => 1, 'price' => 450],
        ['product_id' => $p1['id'], 'qty' => 1, 'price' => 450, 'branch_id' => $branch2],
    ], null, 'flow-4');
} catch (\InvalidArgumentException $e) {
    $crossRejected = true;
}
$h->test('cross-branch line rejected', $crossRejected);

$cashierNoVoid = false;
try {
    SaleService::void($cashier, $branchId, $sale['sale_id']);
} catch (\RuntimeException $e) {
    $cashierNoVoid = true;
}
$h->test('cashier cannot void (no void permission)', $cashierNoVoid);

// ── Profit report excludes voids ───────────────────────────────────
$h->section('Profit report');

$profit = SaleService::profit($admin, ['branch_id' => $branchId]);
$h->test('only completed sale counted (1)', ($profit['sales_count'] ?? 0) === 1);
$h->test('profit matches remaining completed sale (1070)', ($profit['profit'] ?? 0) === 1070.0);

// ── History reflects voided status ─────────────────────────────────
$h->section('History');

$history = SaleService::history($admin, ['branch_id' => $branchId]);
$byRef = [];
foreach ($history['rows'] as $row) {
    $byRef[$row['sale_ref']] = $row['status'];
}
$h->test('history shows completed sale', ($byRef[$sale['sale_ref']] ?? '') === 'completed');
$h->test('history shows voided sale', ($byRef[$sale2['sale_ref']] ?? '') === 'voided');
$h->test('history shows admin-voided sale', ($byRef[$sale3['sale_ref']] ?? '') === 'voided');
$h->test('history total = 3 sales', ($history['total'] ?? 0) === 3);

// ── Audit trail ────────────────────────────────────────────────────
$h->section('Audit trail');

$completedAudits = (int)$pdo->query("SELECT COUNT(*) FROM moto_audit_log WHERE tenant_id = {$tid} AND action = 'moto_inventory.sale.completed'")->fetchColumn();
$voidAudits = (int)$pdo->query("SELECT COUNT(*) FROM moto_audit_log WHERE tenant_id = {$tid} AND action = 'moto_inventory.sale.voided'")->fetchColumn();
$h->test('sale completed audits recorded (3)', $completedAudits === 3);
$h->test('sale voided audits recorded (2: undo + void)', $voidAudits === 2);

// ── Event emission ─────────────────────────────────────────────────
$h->section('Event emission');

$fired = [];
app()->events()->listen('moto_inventory.sale.completed', static function (array $p) use (&$fired): void {
    $fired[] = $p;
});
$tampered = SaleService::complete($cashier, $branchId, [['product_id' => $p1['id'], 'qty' => 1, 'price' => 1]], null, 'flow-5');
$h->test('client-provided sale price is ignored', ($tampered['total'] ?? 0) === 450.0);
$h->test('sale.completed event fired with payload', count($fired) === 1 && isset($fired[0]['sale_ref']) && ($fired[0]['total'] ?? 0) === 450.0);

$tenant['cleanup']();
$h->done();
