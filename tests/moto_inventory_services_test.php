<?php

declare(strict_types=1);

/**
 * Moto Inventory — Services Integration Test (disposable tenant DB).
 *
 * Covers catalog (brands/products/archive/restore), stock adjustments,
 * sale completion, undo/void, idempotency, insufficient stock, tenant
 * isolation, and audit persistence against a real MySQL tenant database.
 *
 * Run: php tests/moto_inventory_services_test.php
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/moto_inventory_test_helper.php';

// App bootstrap MUST run in global scope for $config visibility.
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/helpers.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/handlers.php';

$h = new TestHarness('moto-inventory-services', TestHarness::MODE_PURE);

$base = $h->basePath();
$h->fingerprint('modules/moto-inventory/services/CatalogService.php');
$h->fingerprint('modules/moto-inventory/services/StockService.php');
$h->fingerprint('modules/moto-inventory/services/SaleService.php');
$h->fingerprint('modules/moto-inventory/helpers.php');

$tenant = null;
try {
    $tenant = moto_test_create_tenant();
} catch (\Throwable $e) {
    $h->test('disposable tenant provisioned', false, $e->getMessage());
    $h->gap('Integration assertions require MySQL — skipped');
    $h->done();
}

$tid = $tenant['tenant_id'];
$pdo = $tenant['pdo'];
$ctx = moto_test_admin_ctx($tid);

// ── Branches & brands ──────────────────────────────────────────────
$h->section('Branches & brands');

$pdo->prepare('INSERT INTO moto_branches (tenant_id, branch_key, name) VALUES (:t, :k, :n)')
    ->execute([':t' => $tid, ':k' => 'main', ':n' => 'Main Branch']);
$branchId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO moto_branches (tenant_id, branch_key, name) VALUES (:t, :k, :n)')
    ->execute([':t' => $tid, ':k' => 'br2', ':n' => 'Branch Two']);
$branch2 = (int)$pdo->lastInsertId();
$h->test('two branches created', $branchId > 0 && $branch2 > 0);

$ctx['branch_ids'] = [$branchId];
$brand = CatalogService::createBrand($ctx, 'Yamaha');
$h->test('brand created', ($brand['id'] ?? 0) > 0);
$brandId = $brand['id'];

$dup = false;
try {
    CatalogService::createBrand($ctx, 'Yamaha');
} catch (\InvalidArgumentException $e) {
    $dup = true;
}
$h->test('duplicate brand rejected', $dup);

$renamed = CatalogService::renameBrand($ctx, $brandId, 'Yamaha Motors');
$h->test('brand renamed', ($renamed['name'] ?? '') === 'Yamaha Motors');
CatalogService::renameBrand($ctx, $brandId, 'Yamaha');

// ── Products & initial stock ───────────────────────────────────────
$h->section('Products & initial stock');

$p1 = CatalogService::createProduct($ctx, $branchId, ['brand_id' => $brandId, 'part_number' => 'P-001', 'description' => 'Brake Pad', 'cost' => 100, 'price' => 250, 'qty' => 10]);
$p2 = CatalogService::createProduct($ctx, $branchId, ['brand_id' => $brandId, 'part_number' => 'P-002', 'description' => 'Chain', 'cost' => 50, 'price' => 120, 'qty' => 5]);
$h->test('products created', ($p1['id'] ?? 0) > 0 && ($p2['id'] ?? 0) > 0);
$h->test('initial balance p1 = 10', (float)CatalogService::productById($ctx, $p1['id'], $branchId)['qty_on_hand'] === 10.0);
$h->test('movement ledger has seed entries', (int)$pdo->query("SELECT COUNT(*) FROM moto_stock_movements WHERE tenant_id = {$tid} AND movement_type = 'adjustment'")->fetchColumn() === 2);

$dupProduct = false;
try {
    CatalogService::createProduct($ctx, $branchId, ['brand_id' => $brandId, 'part_number' => 'P-001']);
} catch (\InvalidArgumentException $e) {
    $dupProduct = true;
}
$h->test('duplicate product within branch rejected', $dupProduct);

// Same business key in another branch is allowed.
$p1b2 = CatalogService::createProduct($ctx, $branch2, ['brand_id' => $brandId, 'part_number' => 'P-001', 'description' => 'Brake Pad B2', 'cost' => 90, 'price' => 240, 'qty' => 3]);
$h->test('same part allowed in another branch', ($p1b2['id'] ?? 0) > 0);

// ── Stock adjustments ──────────────────────────────────────────────
$h->section('Stock adjustments');

$adj = StockService::adjust($ctx, $branchId, $p1['id'], -2, 'damage', 'adj-1');
$h->test('adjust -2 yields new_qty 8', $adj['new_qty'] === 8.0);
$adjRepeat = StockService::adjust($ctx, $branchId, $p1['id'], -2, 'damage', 'adj-1');
$h->test('idempotent adjust returns original', (float)$adjRepeat['new_qty'] === 8.0 && (int)$adjRepeat['movement_id'] === (int)$adj['movement_id']);

$neg = false;
try {
    StockService::adjust($ctx, $branchId, $p2['id'], -500, 'wipeout');
} catch (\RuntimeException $e) {
    $neg = true;
}
$h->test('negative adjustment rejected (insufficient stock)', $neg);

$zero = false;
try {
    StockService::adjust($ctx, $branchId, $p2['id'], 0, 'no-op');
} catch (\InvalidArgumentException $e) {
    $zero = true;
}
$h->test('zero adjustment rejected', $zero);

$noReason = false;
try {
    StockService::adjust($ctx, $branchId, $p2['id'], 1, '');
} catch (\InvalidArgumentException $e) {
    $noReason = true;
}
$h->test('adjustment without reason rejected', $noReason);

// ── Sale completion ────────────────────────────────────────────────
$h->section('Sale completion');

$sale = SaleService::complete($ctx, $branchId, [
    ['product_id' => $p1['id'], 'qty' => 2, 'price' => 250],
    ['product_id' => $p2['id'], 'qty' => 1, 'price' => 120],
], 'John Doe', 'sale-1');
$h->test('sale completed with stable ref', ($sale['sale_id'] ?? 0) > 0 && str_starts_with($sale['sale_ref'] ?? '', 'S-'));
$h->test('sale total = 620', ($sale['total'] ?? 0) === 620.0);
$h->test('sale cost = 250 (200+50)', ($sale['cost'] ?? 0) === 250.0);
$h->test('sale profit = 370', ($sale['profit'] ?? 0) === 370.0);
$h->test('stock p1 = 6 after sale', (float)CatalogService::productById($ctx, $p1['id'], $branchId)['qty_on_hand'] === 6.0);
$h->test('stock p2 = 4 after sale', (float)CatalogService::productById($ctx, $p2['id'], $branchId)['qty_on_hand'] === 4.0);

$saleItems = (int)$pdo->query("SELECT COUNT(*) FROM moto_sale_items WHERE tenant_id = {$tid} AND sale_id = " . (int)$sale['sale_id'])->fetchColumn();
$h->test('sale items recorded (2)', $saleItems === 2);
$moves = (int)$pdo->query("SELECT COUNT(*) FROM moto_stock_movements WHERE tenant_id = {$tid} AND movement_type = 'sale'")->fetchColumn();
$h->test('sale movements recorded (2)', $moves === 2);

// ── Idempotency ────────────────────────────────────────────────────
$h->section('Idempotency');

$saleDup = SaleService::complete($ctx, $branchId, [
    ['product_id' => $p1['id'], 'qty' => 2, 'price' => 250],
    ['product_id' => $p2['id'], 'qty' => 1, 'price' => 120],
], 'John Doe', 'sale-1');
$h->test('duplicate idempotency key returns original sale', ($saleDup['sale_id'] ?? 0) === (int)$sale['sale_id']);
$h->test('stock unchanged by duplicate', (float)CatalogService::productById($ctx, $p1['id'], $branchId)['qty_on_hand'] === 6.0);

$misuse = false;
try {
    SaleService::complete($ctx, $branchId, [['product_id' => $p2['id'], 'qty' => 9, 'price' => 120]], null, 'sale-1');
} catch (\InvalidArgumentException $e) {
    $misuse = str_contains($e->getMessage(), 'different request payload');
}
$h->test('idempotency key reused with different payload rejected', $misuse);

// ── Idempotency claim + atomicity ──────────────────────────────────
$h->section('Idempotency claim + atomicity');

// Claim semantics: exactly one request owns the key.
$claimDb = moto_db($tid);
$claimDb->beginTransaction();
$firstClaim = moto_idem_claim($claimDb, $ctx, 'claim-1', 'test.op', ['x' => 1], $branchId);
$secondClaim = moto_idem_claim($claimDb, $ctx, 'claim-1', 'test.op', ['x' => 1], $branchId);
$h->test('first claim owns the key', $firstClaim === true);
$h->test('concurrent second claim loses (rowCount 0)', $secondClaim === false);
moto_idem_complete($claimDb, $ctx, 'claim-1', 'test.op', ['ok' => true], $branchId);
$claimDb->commit();
$claimFetched = moto_idem_fetch($ctx, 'claim-1', 'test.op', ['x' => 1], $branchId);
$h->test('completed claim response is deterministically fetchable', ($claimFetched['ok'] ?? null) === true);
$waitFetched = moto_idem_wait_fetch($ctx, 'claim-1', 'test.op', ['x' => 1], $branchId);
$h->test('concurrent retry deterministically receives the committed response', ($waitFetched['ok'] ?? null) === true);

// A failed mutation must leave NO audit and NO claim behind (atomic rollback),
// so a retry can reclaim the key.
$auditBeforeFail = (int)$pdo->query("SELECT COUNT(*) FROM moto_audit_log WHERE tenant_id = {$tid}")->fetchColumn();
$failKey = 'atomic-fail';
$failedAtomic = false;
try {
    SaleService::complete($ctx, $branchId, [['product_id' => $p2['id'], 'qty' => 99999, 'price' => 120]], null, $failKey);
} catch (\RuntimeException $e) {
    $failedAtomic = true;
}
$h->test('insufficient-stock sale with fresh key rejected', $failedAtomic);
$h->test('failed mutation writes no sale row', (int)$pdo->query("SELECT COUNT(*) FROM moto_sales WHERE tenant_id = {$tid} AND idempotency_key = '{$failKey}'")->fetchColumn() === 0);
$h->test('failed mutation writes no audit row', (int)$pdo->query("SELECT COUNT(*) FROM moto_audit_log WHERE tenant_id = {$tid}")->fetchColumn() === $auditBeforeFail);
$h->test('failed mutation releases the idempotency claim', (int)$pdo->query("SELECT COUNT(*) FROM moto_idempotency_keys WHERE tenant_id = {$tid} AND idempotency_key = '{$failKey}'")->fetchColumn() === 0);

// A successful mutation commits audit + idempotency response atomically.
$p3 = CatalogService::createProduct($ctx, $branchId, ['brand_id' => $brandId, 'part_number' => 'P-003', 'description' => 'Atomic Test Part', 'cost' => 10, 'price' => 40, 'qty' => 10]);
$saleAtomic = SaleService::complete($ctx, $branchId, [['product_id' => $p3['id'], 'qty' => 1, 'price' => 40]], null, 'atomic-ok');
$h->test('successful sale commits matching audit row', (int)$pdo->query("SELECT COUNT(*) FROM moto_audit_log WHERE tenant_id = {$tid} AND target_type = 'moto_sale' AND target_id = " . (int)$saleAtomic['sale_id'])->fetchColumn() === 1);
$h->test('successful sale records idempotency response', (int)$pdo->query("SELECT COUNT(*) FROM moto_idempotency_keys WHERE tenant_id = {$tid} AND idempotency_key = 'atomic-ok' AND response_payload IS NOT NULL")->fetchColumn() === 1);
$h->test('audit and idempotency commit together (no partial state)', (int)$pdo->query("SELECT COUNT(*) FROM moto_idempotency_keys WHERE tenant_id = {$tid} AND idempotency_key = 'atomic-ok'")->fetchColumn() === 1 && (int)$pdo->query("SELECT COUNT(*) FROM moto_audit_log WHERE tenant_id = {$tid} AND action = 'moto_inventory.sale.completed'")->fetchColumn() >= 2);

// ── Insufficient stock / override ──────────────────────────────────
$h->section('Insufficient stock');

$insuff = false;
try {
    SaleService::complete($ctx, $branchId, [['product_id' => $p2['id'], 'qty' => 999, 'price' => 120]], null, 'sale-2');
} catch (\RuntimeException $e) {
    $insuff = true;
}
$h->test('insufficient stock rejected', $insuff);

$overrideSale = SaleService::complete($ctx, $branchId, [['product_id' => $p2['id'], 'qty' => 999, 'price' => 120]], null, 'sale-3', true);
$h->test('override allows negative stock and flags it', ($overrideSale['override'] ?? false) === true);

// ── Undo (5-minute window) ─────────────────────────────────────────
$h->section('Undo (5-minute window)');

$h->test('undo override sale restores p2 by 999', SaleService::undoLatest($ctx, $branchId)['sale_id'] === (int)$overrideSale['sale_id']);
$h->test('p2 balance restored to 4 (pre-override-sale balance)', (float)CatalogService::productById($ctx, $p2['id'], $branchId)['qty_on_hand'] === 4.0);

// ── Privileged void ────────────────────────────────────────────────
$h->section('Privileged void');

$sale4 = SaleService::complete($ctx, $branchId, [['product_id' => $p1['id'], 'qty' => 1, 'price' => 250]], null, 'sale-4');
$v = SaleService::void($ctx, $branchId, $sale4['sale_id']);
$h->test('void marks sale voided', ($v['status'] ?? '') === 'voided');
$h->test('void restores p1 to 6 (5 + 1)', (float)CatalogService::productById($ctx, $p1['id'], $branchId)['qty_on_hand'] === 6.0);
$h->test('original sale retained (not deleted)', (int)$pdo->query("SELECT COUNT(*) FROM moto_sales WHERE tenant_id = {$tid} AND id = " . (int)$sale4['sale_id'] . " AND status = 'voided'")->fetchColumn() === 1);

$doubleVoid = false;
try {
    SaleService::void($ctx, $branchId, $sale4['sale_id']);
} catch (\RuntimeException $e) {
    $doubleVoid = true;
}
$h->test('double void rejected', $doubleVoid);

// ── Profit reversal ────────────────────────────────────────────────
$h->section('Profit reporting');

$profit = SaleService::profit($ctx, ['branch_id' => $branchId]);
$h->test('profit report excludes voided sales', ($profit['sales_count'] ?? 0) === 2); // sale-1 + atomic-ok
$h->test('profit total = 400 (370 + 30)', ($profit['profit'] ?? 0) === 400.0);

// ── Archive / restore / delete ─────────────────────────────────────
$h->section('Archive / restore / delete');

$arch = CatalogService::setProductArchived($ctx, $p1['id'], $branchId, true);
$h->test('product archived', ($arch['archived'] ?? false) === true);
$h->test('archived product excluded from active list', count(array_filter(CatalogService::products($ctx, ['branch_id' => $branchId])['rows'], static fn (array $r): bool => (int)$r['id'] === (int)$p1['id'])) === 0);

CatalogService::setProductArchived($ctx, $p1['id'], $branchId, false);
$h->test('product restored', (int)CatalogService::productById($ctx, $p1['id'], $branchId)['archived'] === 0);

// Product with movement history cannot be deleted.
$delBlocked = false;
try {
    CatalogService::deleteProduct($ctx, $p1['id'], $branchId);
} catch (\RuntimeException $e) {
    $delBlocked = true;
}
$h->test('product with history cannot be deleted', $delBlocked);

// ── Tenant isolation ───────────────────────────────────────────────
$h->section('Tenant isolation');

$tenant2 = moto_test_create_tenant();
$tid2 = $tenant2['tenant_id'];
$pdo2 = $tenant2['pdo'];
$ctxT2 = moto_test_admin_ctx($tid2);
$db2 = moto_db($tid2);

$db2->prepare('INSERT INTO moto_branches (tenant_id, branch_key, name) VALUES (:t, :k, :n)')->execute([':t' => $tid2, ':k' => 'main', ':n' => 'T2 Main']);
$b2 = (int)$db2->lastInsertId();
$db2->prepare('INSERT INTO moto_brands (tenant_id, name) VALUES (:t, :n)')->execute([':t' => $tid2, ':n' => 'Yamaha']);
$br2 = (int)$db2->lastInsertId();
$db2->prepare('INSERT INTO moto_products (tenant_id, branch_id, brand_id, part_number, qty_on_hand, cost, price) VALUES (:t, :b, :br, :p, 77, 1, 2)')->execute([':t' => $tid2, ':b' => $b2, ':br' => $br2, ':p' => 'P-001']);

$h->test('same business key isolated across tenants', (int)$pdo->query("SELECT COUNT(*) FROM moto_products WHERE tenant_id = {$tid} AND part_number = 'P-001'")->fetchColumn() === 2);
$h->test('tenant1 query does not see tenant2 products', (int)$pdo->query("SELECT COUNT(*) FROM moto_products WHERE part_number = 'P-001' AND qty_on_hand = 77")->fetchColumn() === 0);
// p2 lives only in tenant 1 (id 2); tenant 2 has a single product (id 1).
$cross = CatalogService::productById($ctxT2, $p2['id'], $b2);
$h->test('cross-tenant product lookup fails', $cross === null);

// ── Audit persistence ──────────────────────────────────────────────
$h->section('Audit persistence');

$auditCount = (int)$pdo->query("SELECT COUNT(*) FROM moto_audit_log WHERE tenant_id = {$tid}")->fetchColumn();
$h->test('audit entries persisted', $auditCount >= 10);
$audit = $pdo->query("SELECT * FROM moto_audit_log WHERE tenant_id = {$tid} AND action = 'moto_inventory.sale.completed' ORDER BY id DESC LIMIT 1")->fetch();
$h->test('sale audit has actor', is_array($audit) && ($audit['actor_name'] ?? '') === 'Test Admin');
$h->test('sale audit has branch + target', is_array($audit) && (int)$audit['branch_id'] === $branchId && ($audit['target_type'] ?? '') === 'moto_sale');
$h->test('sale audit has idempotency', is_array($audit) && ($audit['idempotency_key'] ?? '') !== '');

$h->test('idempotency table has sale-1', (int)$pdo->query("SELECT COUNT(*) FROM moto_idempotency_keys WHERE tenant_id = {$tid} AND idempotency_key = 'sale-1'")->fetchColumn() === 1);

$tenant2['cleanup']();
$tenant['cleanup']();
$h->done();
