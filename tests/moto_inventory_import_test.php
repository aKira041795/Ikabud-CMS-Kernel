<?php

declare(strict_types=1);

/**
 * Moto Inventory — Import/Export Integration Test (disposable tenant DB).
 *
 * Exercises the server-side XLSX parser, header auto-mapping, staging,
 * atomic commit, idempotent repeat commit, error report, and safe
 * versioned export/import — against real files and a real MySQL tenant DB.
 *
 * Run: php tests/moto_inventory_import_test.php
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/moto_inventory_test_helper.php';
require_once __DIR__ . '/moto_inventory_xlsx_helper.php';

// App bootstrap MUST run in global scope for $config visibility.
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/helpers.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/handlers.php';

$h = new TestHarness('moto-inventory-import', TestHarness::MODE_PURE);

$base = $h->basePath();
$h->fingerprint('modules/moto-inventory/services/ImportService.php');

$tenant = null;
try {
    $tenant = moto_test_create_tenant();
} catch (\Throwable $e) {
    $h->test('disposable tenant provisioned', false, $e->getMessage());
    $h->gap('Import integration requires MySQL — skipped');
    $h->done();
}

$tid = $tenant['tenant_id'];
$pdo = $tenant['pdo'];
$ctx = moto_test_admin_ctx($tid);

$pdo->prepare('INSERT INTO moto_branches (tenant_id, branch_key, name) VALUES (:t, :k, :n)')->execute([':t' => $tid, ':k' => 'main', ':n' => 'Main']);
$branchId = (int)$pdo->lastInsertId();
$ctx['branch_ids'] = [$branchId];
$brand = CatalogService::createBrand($ctx, 'Yamaha');
$brandId = $brand['id'];

$tmpDir = sys_get_temp_dir() . '/moto_import_' . substr(bin2hex(random_bytes(4)), 0, 6);
@mkdir($tmpDir, 0777, true);

// ── XLSX parsing ───────────────────────────────────────────────────
$h->section('XLSX parsing');

$goodFile = $tmpDir . '/good.xlsx';
moto_test_build_xlsx($goodFile, [
    ['Part No.', 'Description', 'Cost', 'Sell Price', 'Qty'],
    ['P-100', 'Brake Pad', '120', '300', '5'],
    ['P-101', 'Chain', '80', '200', '8'],
    ['P-102', 'Spark Plug', '60', '150', '10'],
]);

$parsed = ImportService::parseWorkbook($goodFile);
$h->test('workbook parses with one sheet', count($parsed['sheets']) === 1 && ($parsed['sheets'][0]['name'] ?? '') === 'Parts');
$sheetPath = 'xl/' . ltrim((string)($parsed['sheets'][0]['path'] ?? 'worksheets/sheet1.xml'), '/');
$h->test('sheet grid has 4 rows (1 header + 3 data)', count($parsed['grids'][$sheetPath] ?? []) === 4);

$mapping = ImportService::guessMappingFromHeaders($parsed['grids'][$sheetPath][0] ?? []);
$h->test('auto-mapping detects part_number', ($mapping['part_number'] ?? null) === 0);
$h->test('auto-mapping detects description', ($mapping['description'] ?? null) === 1);
$h->test('auto-mapping detects cost', ($mapping['cost'] ?? null) === 2);
$h->test('auto-mapping detects price', ($mapping['price'] ?? null) === 3);
$h->test('auto-mapping detects qty', ($mapping['qty'] ?? null) === 4);

// ── Malformed file rejection ───────────────────────────────────────
$h->section('Malformed file rejection');

$badFile = $tmpDir . '/bad.txt';
file_put_contents($badFile, 'this is not a zip file');
$rejected = false;
try {
    ImportService::parseWorkbook($badFile);
} catch (\InvalidArgumentException $e) {
    $rejected = str_contains($e->getMessage(), 'zip signature');
}
$h->test('non-zip file rejected', $rejected);

$tooBig = $tmpDir . '/big.xlsx';
moto_test_build_xlsx($tooBig, [['x']]);
file_put_contents($tooBig, str_pad('', ImportService::MAX_FILE_SIZE + 10, 'a'));
$sizeRejected = false;
try {
    ImportService::parseWorkbook($tooBig);
} catch (\InvalidArgumentException $e) {
    $sizeRejected = str_contains($e->getMessage(), 'maximum size');
}
$h->test('oversized file rejected', $sizeRejected);

$wrongExt = $tmpDir . '/bad.xls';
file_put_contents($wrongExt, 'x');
$extRejected = false;
try {
    ImportService::stage($ctx, $branchId, $brandId, $wrongExt, 'bad.xls', 'application/octet-stream', $mapping);
} catch (\InvalidArgumentException $e) {
    $extRejected = true;
}
$h->test('non-.xlsx extension rejected', $extRejected);

// ── Staging (preview, no inventory change) ─────────────────────────
$h->section('Staging');

$stage = ImportService::stage($ctx, $branchId, $brandId, $goodFile, 'good.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, 0, 0, 1, 'imp-1');
$h->test('stage returns import id', ($stage['import_id'] ?? 0) > 0);
$h->test('stage preview new count = 3', ($stage['new_count'] ?? 0) === 3);
$h->test('stage has no errors', ($stage['errors'] ?? []) === []);
$h->test('staging does not change inventory', (int)$pdo->query("SELECT COUNT(*) FROM moto_products WHERE tenant_id = {$tid}")->fetchColumn() === 0);

$importId = $stage['import_id'];

// ── Commit ─────────────────────────────────────────────────────────
$h->section('Commit');

$commit = ImportService::commit($ctx, $importId, true);
$h->test('commit succeeds', ($commit['status'] ?? '') === 'committed' && ($commit['new_count'] ?? 0) === 3);
$h->test('products created after commit', (int)$pdo->query("SELECT COUNT(*) FROM moto_products WHERE tenant_id = {$tid}")->fetchColumn() === 3);
$h->test('qty applied from import', (float)$pdo->query("SELECT qty_on_hand FROM moto_products WHERE tenant_id = {$tid} AND part_number = 'P-100'")->fetchColumn() === 5.0);
$h->test('cost applied from import', (float)$pdo->query("SELECT cost FROM moto_products WHERE tenant_id = {$tid} AND part_number = 'P-100'")->fetchColumn() === 120.0);
$h->test('import movement recorded', (int)$pdo->query("SELECT COUNT(*) FROM moto_stock_movements WHERE tenant_id = {$tid} AND movement_type = 'import'")->fetchColumn() === 3);

$repeat = ImportService::commit($ctx, $importId, true);
$h->test('repeat commit is idempotent', ($repeat['status'] ?? '') === 'committed');
$h->test('repeat commit does not duplicate products', (int)$pdo->query("SELECT COUNT(*) FROM moto_products WHERE tenant_id = {$tid}")->fetchColumn() === 3);

// ── Duplicate handling + error report ──────────────────────────────
$h->section('Duplicate handling & error report');

$dupFile = $tmpDir . '/dup.xlsx';
moto_test_build_xlsx($dupFile, [
    ['Part No.', 'Description', 'Cost', 'Sell Price', 'Qty'],
    ['P-100', 'Brake Pad v2', '130', '320', '6'],   // existing → update
    ['P-200', 'New Part', '50', '110', '2'],        // new
    ['P-100', 'Brake Pad dup', '1', '1', '1'],      // duplicate within file
]);
$dupStage = ImportService::stage($ctx, $branchId, $brandId, $dupFile, 'dup.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, 0, 0, 1, 'imp-2');
$h->test('duplicate within file flagged as error', count(array_filter($dupStage['rows'] ?? [], static fn (array $r): bool => $r['validation_status'] === 'error')) === 1);
$h->test('existing/new counts split correctly', ($dupStage['existing_count'] ?? 0) === 1 && ($dupStage['new_count'] ?? 0) === 1);

// Commit with validation errors must be rejected.
$commitRejected = false;
try {
    ImportService::commit($ctx, $dupStage['import_id'], true);
} catch (\RuntimeException $e) {
    $commitRejected = true;
}
$h->test('commit with validation errors rejected', $commitRejected);

$csv = ImportService::errorReport($ctx, $dupStage['import_id']);
$h->test('error report CSV includes row header', str_starts_with($csv, 'Row,Part Number,Description,Errors'));
$h->test('error report CSV names the duplicate part', str_contains($csv, 'P-100'));

// ── Versioned export / import ──────────────────────────────────────
$h->section('Versioned export / import');

$export = ImportService::export($ctx, $branchId, 'full');
$h->test('export has version 1 and module', ($export['version'] ?? '') === '1' && ($export['module'] ?? '') === 'moto-inventory');
$h->test('export lists brands', count($export['data']['brands'] ?? []) === 1);
$h->test('export lists products with business keys', count($export['data']['products'] ?? []) === 3);
$h->test('export never leaks internal ids', !preg_match('/"id"\s*:/', (string)json_encode($export['data'])));
$h->test('export never leaks audit rows', !isset($export['data']['audit']));

// Import into a second branch (fresh scope).
$pdo->prepare('INSERT INTO moto_branches (tenant_id, branch_key, name) VALUES (:t, :k, :n)')->execute([':t' => $tid, ':k' => 'br2', ':n' => 'Branch Two']);
$branch2 = (int)$pdo->lastInsertId();
$imported = ImportService::importBackup($ctx, $branch2, $export, 'bk-1');
$h->test('backup import creates products in target branch', ($imported['products_created'] ?? 0) === 3);
$h->test('backup import reuses brands', ($imported['brands_created'] ?? 0) === 0);
$h->test('branch2 got the products', (int)$pdo->query("SELECT COUNT(*) FROM moto_products WHERE tenant_id = {$tid} AND branch_id = {$branch2}")->fetchColumn() === 3);
$h->test('backup import repeat is idempotent', ($imported2 = ImportService::importBackup($ctx, $branch2, $export, 'bk-1')) && ($imported2['products_created'] ?? 0) === 3);

// Legacy restore shape must be refused.
$legacy = ['version' => '1', 'module' => 'moto-inventory', 'stores' => ['parts' => []], 'data' => []];
$legacyRejected = false;
try {
    ImportService::importBackup($ctx, $branch2, $legacy, 'bk-2');
} catch (\InvalidArgumentException $e) {
    $legacyRejected = true;
}
$h->test('legacy full-DB restore shape refused', $legacyRejected);

// Wrong module payload refused.
$wrongModule = ['version' => '1', 'module' => 'faztsale', 'data' => ['brands' => [], 'products' => []]];
$wrongRejected = false;
try {
    ImportService::importBackup($ctx, $branch2, $wrongModule, 'bk-3');
} catch (\InvalidArgumentException $e) {
    $wrongRejected = true;
}
$h->test('foreign module backup refused', $wrongRejected);

// ── Branch/brand spoofing ──────────────────────────────────────────
$h->section('Branch / brand spoofing');

$spoofed = false;
$cashier = moto_test_cashier_ctx($tid, [$branchId]);
try {
    // Cashier cannot manage imports (no manage permission).
    moto_require_permission($cashier, 'moto_inventory.manage');
} catch (\RuntimeException $e) {
    $spoofed = true;
}
$h->test('cashier denied import management', $spoofed);

$outOfScope = false;
try {
    // Admin without assignment to branch2 cannot write there.
    $limited = moto_test_admin_ctx($tid, [$branchId]);
    $limited['view_all_branches'] = false;
    moto_require_write_branch($limited, $branch2);
} catch (\RuntimeException $e) {
    $outOfScope = true;
}
$h->test('unassigned branch write denied', $outOfScope);

// Cleanup
@unlink($goodFile);
@unlink($badFile);
@unlink($tooBig);
@unlink($wrongExt);
@unlink($dupFile);
@rmdir($tmpDir);
$tenant['cleanup']();
$h->done();
