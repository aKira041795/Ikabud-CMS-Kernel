<?php

declare(strict_types=1);

/**
 * Moto Inventory — Tenant Isolation / ModuleDB Boundary Test.
 *
 * Verifies cross-tenant data isolation, ModuleDB table-ownership
 * enforcement (undeclared/cross-module table access fails), and that
 * browser-supplied tenant/branch/role values are never trusted.
 *
 * Run: php tests/moto_inventory_tenant_isolation_test.php
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/moto_inventory_test_helper.php';

// App bootstrap MUST run in global scope for $config visibility.
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/helpers.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/handlers.php';

$h = new TestHarness('moto-inventory-tenant-isolation', TestHarness::MODE_PURE);

$base = $h->basePath();
$h->fingerprint('modules/moto-inventory/helpers.php');
$h->fingerprint('modules/moto-inventory/services/CatalogService.php');

$tenant = null;
try {
    $tenant = moto_test_create_tenant();
    $tenant2 = moto_test_create_tenant();
} catch (\Throwable $e) {
    $h->test('disposable tenants provisioned', false, $e->getMessage());
    $h->gap('Isolation integration requires MySQL — skipped');
    $h->done();
}

$tid1 = $tenant['tenant_id'];
$tid2 = $tenant2['tenant_id'];
$pdo1 = $tenant['pdo'];
$pdo2 = $tenant2['pdo'];
$ctx1 = moto_test_admin_ctx($tid1);
$ctx2 = moto_test_admin_ctx($tid2);

// Same business keys in both tenants.
foreach ([[$ctx1, $pdo1], [$ctx2, $pdo2]] as [$ctx, $pdo]) {
    $pdo->prepare('INSERT INTO moto_branches (tenant_id, branch_key, name) VALUES (:t, :k, :n)')->execute([':t' => $ctx['tenant_id'], ':k' => 'main', ':n' => 'Main']);
    $bid = (int)$pdo->lastInsertId();
    $ctx['branch_ids'] = [$bid];
    CatalogService::createBrand($ctx, 'Yamaha');
    CatalogService::createProduct($ctx, $bid, ['brand_id' => (int)$pdo->query("SELECT id FROM moto_brands WHERE tenant_id = {$ctx['tenant_id']} AND name = 'Yamaha'")->fetchColumn(), 'part_number' => 'SHARED-1', 'description' => 'Shared part', 'cost' => 10, 'price' => 25, 'qty' => 5]);
}

// A product that exists only in tenant 1.
$ctx1['branch_ids'] = [(int)$pdo1->query("SELECT id FROM moto_branches WHERE tenant_id = {$tid1} LIMIT 1")->fetchColumn()];
CatalogService::createProduct($ctx1, $ctx1['branch_ids'][0], [
    'brand_id' => (int)$pdo1->query("SELECT id FROM moto_brands WHERE tenant_id = {$tid1} AND name = 'Yamaha'")->fetchColumn(),
    'part_number' => 'T1-ONLY', 'description' => 'Tenant 1 only', 'cost' => 5, 'price' => 9, 'qty' => 1,
]);

$h->section('Duplicate business keys across tenants');

$c1 = (int)$pdo1->query("SELECT COUNT(*) FROM moto_products WHERE part_number = 'SHARED-1'")->fetchColumn();
$c2 = (int)$pdo2->query("SELECT COUNT(*) FROM moto_products WHERE part_number = 'SHARED-1'")->fetchColumn();
$h->test('both tenants have the identical business key', $c1 === 1 && $c2 === 1);

$c1Brand = (int)$pdo1->query("SELECT COUNT(*) FROM moto_brands WHERE name = 'Yamaha'")->fetchColumn();
$c2Brand = (int)$pdo2->query("SELECT COUNT(*) FROM moto_brands WHERE name = 'Yamaha'")->fetchColumn();
$h->test('brands isolated by tenant', $c1Brand === 1 && $c2Brand === 1);

$h->section('Cross-tenant reads are isolated');

// A business key that only exists in tenant 1 must not resolve in tenant 2.
$brand2Id = (int)$pdo2->query("SELECT id FROM moto_brands WHERE tenant_id = {$tid2} AND name = 'Yamaha'")->fetchColumn();
$b2Id = (int)$pdo2->query("SELECT id FROM moto_branches WHERE tenant_id = {$tid2} LIMIT 1")->fetchColumn();
$h->test('tenant1-only part key not found in tenant2', CatalogService::productByKey($ctx2, $b2Id, $brand2Id, 'T1-ONLY') === null);

// A tenant1 product id must not resolve inside tenant 2's branch.
$p1T1Id = (int)$pdo1->query("SELECT id FROM moto_products WHERE tenant_id = {$tid1} AND part_number = 'T1-ONLY'")->fetchColumn();
$h->test('tenant1 product id not found in tenant2', CatalogService::productById($ctx2, $p1T1Id, $b2Id) === null);

$tenant1Only = CatalogService::products($ctx1, [])['total'];
$tenant2Only = CatalogService::products($ctx2, [])['total'];
$h->test('each tenant lists only its own products', $tenant1Only === 2 && $tenant2Only === 1);

$h->section('ModuleDB table-ownership enforcement');

$db1 = moto_db($tid1);
$blocked = false;
try {
    $db1->query("SELECT * FROM kernel_users");
} catch (\Throwable $e) {
    $blocked = true;
}
$h->test('undeclared table access blocked', $blocked);

$blockedWrite = false;
try {
    $db1->query("UPDATE audit_logs SET action = 'x' WHERE id = 1");
} catch (\Throwable $e) {
    $blockedWrite = true;
}
$h->test('cross-module table write blocked', $blockedWrite);

$blockedDdl = false;
try {
    $db1->query("DROP TABLE moto_products");
} catch (\Throwable $e) {
    $blockedDdl = true;
}
$h->test('DDL blocked by ModuleDB', $blockedDdl);

$ownedRead = false;
try {
    $db1->query("SELECT COUNT(*) FROM moto_products WHERE tenant_id = {$tid1}");
    $ownedRead = true;
} catch (\Throwable $e) {
    $ownedRead = false;
}
$h->test('owned table read allowed', $ownedRead);

$h->section('Browser-supplied values are never trusted');

// A browser-forged tenant claim in the user payload has no effect: the
// role map is the only source of permissions.
$forged = moto_test_cashier_ctx($tid2, []);
$forged['user']['tenant_id'] = $tid1; // browser-forged tenant claim
$h->test('permission checks ignore browser tenant claim', !moto_has_permission('moto_inventory.manage', $forged['user']));

// Request input must never influence permission resolution or branch scope.
$forgedRequest = moto_test_cashier_ctx($tid1, []);
$_POST['role'] = 'admin';
$_POST['tenant_id'] = (string)$tid2;
$_POST['branch_id'] = '999';
$h->test('request input cannot grant manage permission', !moto_has_permission('moto_inventory.manage', $forgedRequest['user']));
$h->test('request input cannot change branch scope or unlock tenant-wide reads', (static function () use ($forgedRequest): bool {
    try { moto_resolve_branch_scope($forgedRequest, null, false); return false; } catch (\RuntimeException $e) { return $e->getMessage() === 'Branch access denied'; }
})());
unset($_POST['role'], $_POST['tenant_id'], $_POST['branch_id']);

$tenant2['cleanup']();
$tenant['cleanup']();
$h->done();
