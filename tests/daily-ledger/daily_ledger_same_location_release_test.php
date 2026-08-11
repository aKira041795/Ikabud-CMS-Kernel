<?php

declare(strict_types=1);

/**
 * Daily Ledger — Same-Location Internal Release Test
 *
 * Validates the canonical same-location eligibility resolver
 * (dl_resolveSameLocationEligibility) and the DR-less internal-release path in
 * dl_saveProductionRun / apiSaveProductionRun when the formal delivery workflow
 * is enabled.
 *
 * Integration mode — uses the real tenant DB. Seeds isolated branches/products
 * with high IDs (99xxx) and cleans up every seeded row afterward.
 *
 * Acceptance coverage:
 *   - eligible self-managed commissary/storefront, self-referencing assignment,
 *     product-level override, distinct assigned commissary, inactive branch,
 *     non-commissary branch, externally supplied branch
 *   - formal + eligible same-location + empty DR succeeds, no delivery/receiving
 *   - cross-location + empty DR rejected
 *   - addtl/commissary ledger increment exactly once; idempotent resave
 *   - quantity increase / decrease / delete reverse + reapply net quantities
 *   - same-location ↔ cross-location transitions (incl. received-delivery refusal)
 *   - branch authorization + closed-day gates
 *   - regression: formal delivery sync, derived-sales expression, adjustment addtl
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-same-location-release', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('modules/daily-ledger/helpers.php');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/entity-views.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

// The daily-ledger tenant (baron-001 / id 207) owns the applicationostest DB
// where the dl_* tables live. Establish the tenant + active module context so
// module()/module()->db() resolve exactly like a real daily-ledger request.
app()->tenant()->setTenantId(207);
$dlCtx = modulePushContext('daily-ledger');
if (!$dlCtx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}

/** @var \Ikabud\Kernel\Contracts\DatabaseContract $db */
$db = $dlCtx->db();

// Pre-clean: remove any rows left by a previously interrupted run so the test
// is idempotent. Branches/products use the reserved 99xxx test-id range.
$testBranchIdsSql = '99001,99002,99003,99004,99005,99006';
$cleanColMap = [
    'dl_daily_ledger' => 'branch_id',
    'dl_production_runs' => 'destination_branch_id',
    'dl_production_movements' => 'destination_branch_id',
    'dl_ledger_day_status' => 'branch_id',
    'dl_commissary_product_ledger' => 'commissary_branch_id',
];
foreach ($cleanColMap as $t => $col) {
    $db->execute("DELETE FROM {$t} WHERE {$col} IN ({$testBranchIdsSql})");
}
$db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE destination_id IN ({$testBranchIdsSql}))");
$db->execute("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN ({$testBranchIdsSql}))");
$db->execute("DELETE FROM dl_deliveries WHERE destination_id IN ({$testBranchIdsSql})");
$db->execute("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$testBranchIdsSql})");
$db->execute("DELETE FROM dl_branches WHERE id IN ({$testBranchIdsSql})");
$db->execute("DELETE FROM dl_products WHERE id IN (99001,99002,99003)");
$db->execute('DELETE FROM dl_users WHERE id = 999999');

// ─── Seed helpers ───────────────────────────────────────────────────────
$seedBranchIds = [];
$seedProductIds = [];
$seedRuleIds = [];
$cleanupTables = [
    'dl_daily_ledger', 'dl_commissary_product_ledger', 'dl_production_runs',
    'dl_production_movements', 'dl_delivery_items', 'dl_deliveries',
    'dl_branch_receiving_items', 'dl_branch_receivings',
    'dl_branch_product_supply_rules', 'dl_ledger_day_status',
];
$testDate = '2030-01-15'; // far future date — no interference with live data

/**
 * Seed a branch with an explicit high id. Returns the branch id.
 */
function dl_t_seedBranch(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $id, string $code, string $name, array $opts = []): int
{
    $stmt = $db->prepare(
        'INSERT INTO dl_branches
            (id, code, name, address, area, default_supply_mode, assigned_commissary_id, is_commissary, is_active)
         VALUES (:id, :code, :name, :addr, :area, :mode, :ac, :ic, :active)'
    );
    $stmt->execute([
        ':id'     => $id,
        ':code'   => $code,
        ':name'   => $name,
        ':addr'   => $opts['address'] ?? 'Test',
        ':area'   => $opts['area'] ?? null,
        ':mode'   => $opts['default_supply_mode'] ?? 'self_managed',
        ':ac'     => $opts['assigned_commissary_id'] ?? null,
        ':ic'     => (int)($opts['is_commissary'] ?? 0),
        ':active' => (int)($opts['is_active'] ?? 1),
    ]);
    return $id;
}

function dl_t_seedProduct(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $id, string $sku, string $name, float $price = 15.0): int
{
    $stmt = $db->prepare(
        'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
         VALUES (:id, :sku, :name, :price, 0, 1)'
    );
    $stmt->execute([':id' => $id, ':sku' => $sku, ':name' => $name, ':price' => $price]);
    return $id;
}

function dl_t_seedSupplyRule(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, int $productId, string $sourceType, ?int $sourceId): int
{
    $stmt = $db->prepare(
        'INSERT INTO dl_branch_product_supply_rules (branch_id, product_id, supply_source_type, source_id, is_active)
         VALUES (:b, :p, :t, :s, 1)'
    );
    $stmt->execute([':b' => $branchId, ':p' => $productId, ':t' => $sourceType, ':s' => $sourceId]);
    return (int)$db->lastInsertId();
}

/**
 * Clean up every seeded row (and rows the scenarios created against seeds).
 */
function dl_t_cleanup(\Ikabud\Kernel\Contracts\DatabaseContract $db, array $branchIds, array $productIds, array $ruleIds): void
{
    if ($branchIds === []) {
        return;
    }
    $bPlaceholders = implode(',', array_fill(0, count($branchIds), '?'));
    $pPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
    $cleanColMap = [
        'dl_daily_ledger' => 'branch_id',
        'dl_production_runs' => 'destination_branch_id',
        'dl_production_movements' => 'destination_branch_id',
        'dl_ledger_day_status' => 'branch_id',
        'dl_commissary_product_ledger' => 'commissary_branch_id',
    ];
    foreach ($cleanColMap as $t => $col) {
        $db->prepare("DELETE FROM {$t} WHERE {$col} IN ({$bPlaceholders})")->execute($branchIds);
    }
    $db->prepare("DELETE FROM dl_delivery_items WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE destination_id IN ({$bPlaceholders}))")->execute($branchIds);
    $db->prepare("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN ({$bPlaceholders}))")->execute($branchIds);
    $db->prepare("DELETE FROM dl_deliveries WHERE destination_id IN ({$bPlaceholders})")->execute($branchIds);
    $db->prepare("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$bPlaceholders})")->execute($branchIds);
    if ($ruleIds !== []) {
        $rPlaceholders = implode(',', array_fill(0, count($ruleIds), '?'));
        $db->prepare("DELETE FROM dl_branch_product_supply_rules WHERE id IN ({$rPlaceholders})")->execute($ruleIds);
    }
    $db->prepare("DELETE FROM dl_branches WHERE id IN ({$bPlaceholders})")->execute($branchIds);
    $db->prepare("DELETE FROM dl_products WHERE id IN ({$pPlaceholders})")->execute($productIds);
    $db->execute('DELETE FROM dl_users WHERE id = 999999');
}

function dl_t_ledgerRow(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, int $productId, string $date): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d LIMIT 1'
    );
    $stmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function dl_t_commissaryRow(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, int $productId, string $date): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d LIMIT 1'
    );
    $stmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function dl_t_countRows(\Ikabud\Kernel\Contracts\DatabaseContract $db, string $table, string $where, array $params): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

// ─── Toggle formal delivery for the test tenant (restored at the end) ──
$prevFormalRaw = dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? '0';
dlPersistModuleSettings(['formal_delivery_workflow_enabled' => '1']);
dlModuleSettings(true);
$formalNow = dl_isFormalDeliveryEnabled();

$h->section('Fixture — formal delivery enabled');
$h->test('formal delivery flag enabled for test', $formalNow === true);

$adminUser = ['role' => 'admin', 'source' => 'daily-ledger', 'sub' => 'admin:1', 'id' => 1];
$picNoAccess = ['role' => 'production_in_charge', 'source' => 'daily-ledger', 'sub' => 'production_in_charge:999999', 'id' => 999999];
$supervisorUser = ['role' => 'supervisor', 'source' => 'daily-ledger', 'sub' => 'supervisor:999999', 'id' => 999999];

// ─── Seed the canonical fixture ─────────────────────────────────────────
// 99001: co-located commissary+storefront (self-managed, no assignment)
// 99002: co-located commissary+storefront (self-referencing assignment)
// 99003: non-commissary storefront (self-managed) — used for cross-location tests
// 99004: distinct supplying commissary (produces for 99003)
// 99005: inactive commissary
// 99006: commissary-supplied storefront assigned to 99004 (externally supplied)
$bSelf      = dl_t_seedBranch($db, 99001, 'TST-SELF', 'Self Commissary', ['default_supply_mode' => 'self_managed', 'is_commissary' => 1]);
$bSelfRef   = dl_t_seedBranch($db, 99002, 'TST-SELFREF', 'Self-Ref Commissary', ['default_supply_mode' => 'self_managed', 'is_commissary' => 1, 'assigned_commissary_id' => 99002]);
$bStore     = dl_t_seedBranch($db, 99003, 'TST-STORE', 'Plain Store', ['default_supply_mode' => 'self_managed', 'is_commissary' => 0]);
$bComm      = dl_t_seedBranch($db, 99004, 'TST-COMM', 'Distinct Commissary', ['default_supply_mode' => 'self_managed', 'is_commissary' => 1]);
$bInactive  = dl_t_seedBranch($db, 99005, 'TST-INACT', 'Inactive Commissary', ['default_supply_mode' => 'self_managed', 'is_commissary' => 1, 'is_active' => 0]);
// 99006 is a commissary whose products are (by default) supplied by the
// distinct commissary 99004 — NOT eligible unless a per-product override
// declares local_production for that product.
$bExtSupp   = dl_t_seedBranch($db, 99006, 'TST-EXT', 'Externally Supplied Commissary', ['default_supply_mode' => 'commissary_supplied', 'is_commissary' => 1, 'assigned_commissary_id' => 99004]);
$seedBranchIds = [$bSelf, $bSelfRef, $bStore, $bComm, $bInactive, $bExtSupp];

$p1 = dl_t_seedProduct($db, 99001, 'TST-PROD-1', 'Test Pandesal', 15.0);
$p2 = dl_t_seedProduct($db, 99002, 'TST-PROD-2', 'Test Cake Slice', 25.0);
$p3 = dl_t_seedProduct($db, 99003, 'TST-PROD-3', 'Test Loaf', 40.0);
$seedProductIds = [$p1, $p2, $p3];

// Product-level override: 99006 (a commissary supplied by 99004 by default)
// gets a local_production override for p1 → that product becomes eligible even
// though the branch default is commissary_supplied.
$ruleLocal = dl_t_seedSupplyRule($db, $bExtSupp, $p1, 'local_production', null);
$seedRuleIds = [$ruleLocal];

// ─── Section 1: Eligibility resolver decisions ──────────────────────────
$h->section('Eligibility resolver — canonical decisions');

$d1 = dl_resolveSameLocationEligibility($bSelf, $p1);
$h->test('self-managed commissary, no assignment → eligible', !empty($d1['same_location']));
$h->test('self-managed eligible resolves source to itself', ($d1['source_branch_id'] ?? null) === $bSelf);

$d2 = dl_resolveSameLocationEligibility($bSelfRef, $p2);
$h->test('self-referencing assigned commissary → eligible', !empty($d2['same_location']));
$h->test('self-referencing eligible resolves source to itself', ($d2['source_branch_id'] ?? null) === $bSelfRef);

$d3 = dl_resolveSameLocationEligibility($bStore, $p1);
$h->test('non-commissary storefront → NOT eligible', empty($d3['same_location']));
$h->test('non-commissary reason is not_commissary', ($d3['reason'] ?? '') === 'not_commissary');

$d4 = dl_resolveSameLocationEligibility($bInactive, $p1);
$h->test('inactive commissary → NOT eligible', empty($d4['same_location']));
$h->test('inactive reason is branch_inactive', ($d4['reason'] ?? '') === 'branch_inactive');

$d5 = dl_resolveSameLocationEligibility($bExtSupp, $p1);
$h->test('commissary with local_production override → eligible', !empty($d5['same_location']));
$d5b = dl_resolveSameLocationEligibility($bExtSupp, $p2);
$h->test('commissary supplied by distinct commissary → NOT eligible', empty($d5b['same_location']));
$h->test('distinct-supply reason is supplied_by_distinct_commissary', ($d5b['reason'] ?? '') === 'supplied_by_distinct_commissary');

$d6 = dl_resolveSameLocationEligibility(0, $p1);
$h->test('missing branch → NOT eligible', empty($d6['same_location']));

$d7 = dl_resolveSameLocationEligibility(999999, $p1);
$h->test('nonexistent branch → NOT eligible', empty($d7['same_location']));
$h->test('nonexistent branch reason branch_not_found', ($d7['reason'] ?? '') === 'branch_not_found');

// A distinct supplying commissary assigned to a co-located store:
$d8 = dl_resolveSameLocationEligibility($bComm, $p1);
$h->test('distinct commissary (self) → eligible', !empty($d8['same_location']));
// Branch 99003 assigned to 99004 would NOT be eligible — but 99003 has no
// assigned_commissary, so use a hybrid branch scenario via the override check.
$h->test('resolver returns branch metadata', is_array($d8['branch']));

// ─── Section 1b: Batch map matches the per-product resolver ─────────────
$h->section('Batch eligibility map — consistency with resolver');

$allPids = [$p1, $p2, $p3];
foreach (['self-managed commissary' => $bSelf, 'self-ref commissary' => $bSelfRef, 'plain store' => $bStore, 'distinct commissary' => $bComm, 'inactive commissary' => $bInactive, 'override commissary' => $bExtSupp] as $label => $bid) {
    $map = dl_buildSameLocationEligibilityMap($db, $bid, $allPids);
    $anyEligible = false;
    foreach ($allPids as $pid) {
        $resolver = dl_resolveSameLocationEligibility($bid, $pid);
        $expected = !empty($resolver['same_location']);
        $actual = ($map['products'][$pid] ?? null) === true;
        $h->test("batch map matches resolver: {$label} × product {$pid}", $expected === $actual);
        if ($actual) {
            $anyEligible = true;
        }
    }
    $h->test("batch map eligible flag is any-product: {$label}", $map['eligible'] === $anyEligible);
    $h->test("batch map exposes branch metadata: {$label}", is_array($map['branch']) || $map['branch_id'] === $bid);
}
$h->test('batch map empty product list returns no products', (dl_buildSameLocationEligibilityMap($db, $bSelf, [])['products'] ?? null) === []);
$h->test('batch map empty branch id returns no products', (dl_buildSameLocationEligibilityMap($db, 0, [$p1])['products'] ?? null) === []);

// ─── Section 2: Formal + eligible same-location + empty DR ──────────────
$h->section('Formal delivery + same-location internal release');

// Clean slate for the date/branch/product
$db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id = {$bSelf} AND ledger_date = '{$testDate}'");
$db->execute("DELETE FROM dl_production_runs WHERE destination_branch_id = {$bSelf} AND ledger_date = '{$testDate}'");
$db->execute("DELETE FROM dl_daily_ledger WHERE branch_id = {$bSelf} AND product_id = {$p1} AND ledger_date = '{$testDate}'");
$db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$bSelf} AND product_id = {$p1} AND ledger_date = '{$testDate}'");

$runInput = [
    'date' => $testDate,
    'product_id' => $p1,
    'baker_name' => 'TEST BAKER',
    'yield_qty' => 100,
    'kilo_qty' => 10,
    'destination_branch_id' => $bSelf,
    'dr_number' => '',
];

$res = dl_saveProductionRun($adminUser, $runInput);
$h->test('same-location release succeeds', !empty($res['ok']));
$h->test('result flags same_location_internal_release', !empty($res['same_location_internal_release']));
$h->test('result has a movement id', (int)($res['movement_id'] ?? 0) > 0);

$ledger = dl_t_ledgerRow($db, $bSelf, $p1, $testDate);
$h->test('addtl incremented exactly once (100)', ($ledger['addtl'] ?? null) === 100);
$h->test('derived sales = beg_bal + addtl - withdraw - bal_end', (int)($ledger['sales'] ?? -1) === (0 + 100 - 0 - 0));

$comm = dl_t_commissaryRow($db, $bSelf, $p1, $testDate);
$h->test('commissary produced = 100', ($comm['produced_qty'] ?? null) === 100);
$h->test('commissary dispatched = 100 (not left dispatchable)', ($comm['dispatched_qty'] ?? null) === 100);
$h->test('commissary remaining = 0', (($comm['produced_qty'] ?? 0) - ($comm['dispatched_qty'] ?? 0) - ($comm['wastage_qty'] ?? 0)) === 0);

// No delivery / receiving / manual-adjustment rows
$h->test('no dl_deliveries created', dl_t_countRows($db, 'dl_deliveries', 'destination_id = ? AND delivery_date = ?', [$bSelf, $testDate]) === 0);
$h->test('no dl_branch_receivings created', dl_t_countRows($db, 'dl_branch_receivings', 'branch_id = ? AND received_ledger_date = ?', [$bSelf, $testDate]) === 0);

// Movement linked + provenance
$movStmt = $db->prepare('SELECT movement_type, flow_mode, dr_number, source_payload, destination_branch_id, quantity FROM dl_production_movements WHERE id = ? LIMIT 1');
$movStmt->execute([(int)$res['movement_id']]);
$mov = $movStmt->fetch(PDO::FETCH_ASSOC);
$h->test('movement is an output', ($mov['movement_type'] ?? '') === 'output');
$h->test('movement dr_number is NULL', $mov['dr_number'] === null);
$payload = json_decode((string)($mov['source_payload'] ?? '{}'), true);
$h->test('movement provenance same_location_internal_release', !empty($payload['same_location_internal_release']));
$h->test('movement quantity is 100', (int)($mov['quantity'] ?? 0) === 100);

$runStmt = $db->prepare('SELECT commissary_movement_id FROM dl_production_runs WHERE ledger_date = ? AND product_id = ? AND destination_branch_id = ? LIMIT 1');
$runStmt->execute([$testDate, $p1, $bSelf]);
$h->test('run links commissary_movement_id', (int)$runStmt->fetchColumn() === (int)$res['movement_id']);

// ─── Section 3: Idempotent identical resave ─────────────────────────────
$h->section('Idempotency — identical resave');

$res2 = dl_saveProductionRun($adminUser, $runInput);
$h->test('identical resave succeeds', !empty($res2['ok']));
$h->test('identical resave keeps same movement id', (int)$res2['movement_id'] === (int)$res['movement_id']);
$ledger2 = dl_t_ledgerRow($db, $bSelf, $p1, $testDate);
$h->test('addtl still 100 after resave', ($ledger2['addtl'] ?? null) === 100);
$comm2 = dl_t_commissaryRow($db, $bSelf, $p1, $testDate);
$h->test('commissary produced still 100 after resave', ($comm2['produced_qty'] ?? null) === 100);
$h->test('commissary dispatched still 100 after resave', ($comm2['dispatched_qty'] ?? null) === 100);
$h->test('only one output movement exists', dl_t_countRows($db, 'dl_production_movements', 'destination_branch_id = ? AND ledger_date = ? AND movement_type = ?', [$bSelf, $testDate, 'output']) === 1);

// ─── Section 4: Quantity increase ───────────────────────────────────────
$h->section('Quantity change — increase');

$upInput = array_merge($runInput, ['yield_qty' => 150]);
$resUp = dl_saveProductionRun($adminUser, $upInput);
$h->test('increase succeeds', !empty($resUp['ok']));
$h->test('increase creates a NEW movement id', (int)$resUp['movement_id'] !== (int)$res['movement_id']);
$ledgerUp = dl_t_ledgerRow($db, $bSelf, $p1, $testDate);
$h->test('addtl = 150 after increase', ($ledgerUp['addtl'] ?? null) === 150);
$commUp = dl_t_commissaryRow($db, $bSelf, $p1, $testDate);
$h->test('commissary produced = 150 after increase', ($commUp['produced_qty'] ?? null) === 150);
$h->test('commissary dispatched = 150 after increase', ($commUp['dispatched_qty'] ?? null) === 150);
$h->test('reverse evidence exists for the prior movement', dl_t_countRows($db, 'dl_production_movements', 'reference_movement_id = ? AND movement_type = ?', [(int)$res['movement_id'], 'reverse']) === 1);

// ─── Section 5: Quantity decrease ───────────────────────────────────────
$h->section('Quantity change — decrease');

$downInput = array_merge($runInput, ['yield_qty' => 40]);
$resDown = dl_saveProductionRun($adminUser, $downInput);
$h->test('decrease succeeds', !empty($resDown['ok']));
$ledgerDown = dl_t_ledgerRow($db, $bSelf, $p1, $testDate);
$h->test('addtl = 40 after decrease', ($ledgerDown['addtl'] ?? null) === 40);
$commDown = dl_t_commissaryRow($db, $bSelf, $p1, $testDate);
$h->test('commissary produced = 40 after decrease', ($commDown['produced_qty'] ?? null) === 40);
$h->test('commissary dispatched = 40 after decrease', ($commDown['dispatched_qty'] ?? null) === 40);

// ─── Section 6: Delete reverses the release ─────────────────────────────
$h->section('Delete run reverses internal release');

$deleteInput = array_merge($runInput, ['yield_qty' => 0, 'kilo_qty' => 0, 'egg_qty' => 0, 'baker_name' => '']);
$resDel = dl_saveProductionRun($adminUser, $deleteInput);
$h->test('delete succeeds', !empty($resDel['ok']));
$ledgerDel = dl_t_ledgerRow($db, $bSelf, $p1, $testDate);
$h->test('addtl returns to 0 after delete', (int)($ledgerDel['addtl'] ?? 0) === 0);
$commDel = dl_t_commissaryRow($db, $bSelf, $p1, $testDate);
$h->test('commissary produced returns to 0 after delete', (int)($commDel['produced_qty'] ?? 0) === 0);
$h->test('commissary dispatched returns to 0 after delete', (int)($commDel['dispatched_qty'] ?? 0) === 0);
$h->test('delete leaves reverse evidence', dl_t_countRows($db, 'dl_production_movements', 'destination_branch_id = ? AND ledger_date = ? AND movement_type = ?', [$bSelf, $testDate, 'reverse']) >= 3);
$h->test('run row is gone', dl_t_countRows($db, 'dl_production_runs', 'ledger_date = ? AND product_id = ? AND destination_branch_id = ?', [$testDate, $p1, $bSelf]) === 0);

// ─── Section 7: Cross-location rejection + formal regression ────────────
$h->section('Cross-location — DR enforcement + formal delivery regression');

// Distinct supplying commissary path: dispatch from 99004 to 99003 (store) with
// empty DR must be REJECTED.
$crossInput = [
    'date' => $testDate,
    'product_id' => $p1,
    'baker_name' => 'TEST BAKER',
    'yield_qty' => 50,
    'kilo_qty' => 5,
    'destination_branch_id' => $bStore,
    'dr_number' => '',
];
$threw = null;
try {
    dl_saveProductionRun($adminUser, $crossInput);
} catch (\RuntimeException $e) {
    $threw = $e->getMessage();
}
$h->test('cross-location + empty DR rejected', $threw !== null && str_contains($threw, 'Delivery Receipt number is required'));
$h->test('rejected cross-location creates no delivery', dl_t_countRows($db, 'dl_deliveries', 'destination_id = ? AND delivery_date = ?', [$bStore, $testDate]) === 0);

// Same-location eligible branch + provided DR → self-delivery refused
$selfDrInput = array_merge($runInput, ['yield_qty' => 60, 'dr_number' => 'DR-TEST-1']);
$threw2 = null;
try {
    dl_saveProductionRun($adminUser, $selfDrInput);
} catch (\RuntimeException $e) {
    $threw2 = $e->getMessage();
}
$h->test('eligible same-location + DR provided → rejected (no self-delivery)', $threw2 !== null && str_contains($threw2, 'Internal release'));

// Cross-location WITH a DR creates a formal delivery (regression)
$crossDrInput = array_merge($crossInput, ['dr_number' => 'DR-TEST-2', 'destination_branch_id' => $bStore]);
$resCross = dl_saveProductionRun($adminUser, $crossDrInput);
$h->test('cross-location + DR succeeds', !empty($resCross['ok']));
$delCount = dl_t_countRows($db, 'dl_deliveries', 'destination_id = ? AND delivery_date = ? AND dr_number = ?', [$bStore, $testDate, 'DR-TEST-2']);
$h->test('cross-location creates a posted delivery', $delCount === 1);
$h->test('cross-location keeps NO bridge movement', ($resCross['movement_id'] ?? null) === null);

// ─── Section 8: Transitions ─────────────────────────────────────────────
$h->section('Transitions — same-location ↔ cross-location');

// A run row is keyed by (date, product, destination). A same↔cross transition
// on ONE run happens when the destination stays the same but the product's
// supply rule flips eligibility (the DR is added/removed on that branch).
// Use product p2 at the co-located commissary 99001 (eligible by default).

$db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id = {$bSelf} AND ledger_date = '{$testDate}'");
$db->execute("DELETE FROM dl_production_runs WHERE destination_branch_id = {$bSelf} AND ledger_date = '{$testDate}'");
$db->execute("DELETE FROM dl_daily_ledger WHERE branch_id = {$bSelf} AND product_id = {$p2} AND ledger_date = '{$testDate}'");
$db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$bSelf} AND product_id = {$p2} AND ledger_date = '{$testDate}'");
$db->execute("DELETE FROM dl_deliveries WHERE destination_id = {$bSelf} AND delivery_date = '{$testDate}'");
$db->execute("DELETE FROM dl_branch_receivings WHERE branch_id = {$bSelf} AND received_ledger_date = '{$testDate}'");

// 8a. Start as a same-location internal release for p2 at 99001.
$transInput = [
    'date' => $testDate,
    'product_id' => $p2,
    'baker_name' => 'TEST BAKER',
    'yield_qty' => 60,
    'kilo_qty' => 6,
    'destination_branch_id' => $bSelf,
    'dr_number' => '',
];
$resA = dl_saveProductionRun($adminUser, $transInput);
$h->test('transition: initial same-location release succeeds', !empty($resA['ok']));
$ledgerA = dl_t_ledgerRow($db, $bSelf, $p2, $testDate);
$h->test('transition: addtl = 60', ($ledgerA['addtl'] ?? null) === 60);

// 8b. Flip p2 at 99001 to commissary-supplied (from 99004) → not eligible →
// saving with a DR must reverse the internal release and create a formal delivery.
$ruleTrans = dl_t_seedSupplyRule($db, $bSelf, $p2, 'commissary', $bComm);
$seedRuleIds[] = $ruleTrans;

$transDrInput = array_merge($transInput, ['dr_number' => 'DR-TEST-5']);
$resB = dl_saveProductionRun($adminUser, $transDrInput);
$h->test('transition: same→cross succeeds', !empty($resB['ok']));
$ledgerB = dl_t_ledgerRow($db, $bSelf, $p2, $testDate);
$h->test('transition: internal release reversed (addtl 0)', (int)($ledgerB['addtl'] ?? -1) === 0);
$h->test('transition: formal delivery created for DR-TEST-5', dl_t_countRows($db, 'dl_deliveries', 'destination_id = ? AND delivery_date = ? AND dr_number = ?', [$bSelf, $testDate, 'DR-TEST-5']) === 1);

// 8c. Post a receiving on DR-TEST-5, restore p2 eligibility (remove override),
// then attempt to convert back to same-location → must refuse (received delivery).
$rcvStmt = $db->prepare(
    'INSERT INTO dl_branch_receivings (branch_id, origin_type, origin_id, delivery_id, dr_number, received_ledger_date, status)
     VALUES (:b, "commissary", :o, :d, :dr, :date, "posted")'
);
$deliveryId5 = (int)$db->query("SELECT id FROM dl_deliveries WHERE dr_number = 'DR-TEST-5' AND destination_id = {$bSelf} AND delivery_date = '{$testDate}' LIMIT 1")->fetchColumn();
$rcvStmt->execute([':b' => $bSelf, ':o' => $bComm, ':d' => $deliveryId5, ':dr' => 'DR-TEST-5', ':date' => $testDate]);

$db->prepare('DELETE FROM dl_branch_product_supply_rules WHERE id = ?')->execute([$ruleTrans]);

$threw3 = null;
try {
    dl_saveProductionRun($adminUser, $transInput); // empty DR again → same-location
} catch (\RuntimeException $e) {
    $threw3 = $e->getMessage();
}
$h->test('transition: received cross-location → same-location refused', $threw3 !== null && str_contains($threw3, 'already has a receiving'));
$ledgerC = dl_t_ledgerRow($db, $bSelf, $p2, $testDate);
$h->test('transition: refused save leaves addtl untouched (0)', (int)($ledgerC['addtl'] ?? -1) === 0);
$h->test('transition: refused save leaves run dr intact', (function () use ($db, $bSelf, $p2, $testDate) {
    $s = $db->prepare('SELECT dr_number FROM dl_production_runs WHERE ledger_date = ? AND product_id = ? AND destination_branch_id = ? LIMIT 1');
    $s->execute([$testDate, $p2, $bSelf]);
    return (string)$s->fetchColumn() === 'DR-TEST-5';
})());
// Full rollback of the failed transaction: no new output movement, no commissary delta.
$h->test('transition: refused transaction rolls back (no new output movement)', dl_t_countRows($db, 'dl_production_movements', 'destination_branch_id = ? AND product_id = ? AND ledger_date = ? AND movement_type = ?', [$bSelf, $p2, $testDate, 'output']) === 1);
$commC = dl_t_commissaryRow($db, $bSelf, $p2, $testDate);
$h->test('transition: refused transaction rolls back (commissary unchanged, produced 0)', (int)($commC['produced_qty'] ?? -1) === 0);
$h->test('transition: refused transaction rolls back (commissary dispatched 0)', (int)($commC['dispatched_qty'] ?? -1) === 0);

// 8d. Retry after the failed transaction: void the receiving, then convert to
// same-location → succeeds and applies the internal release exactly once.
$db->execute("UPDATE dl_branch_receivings SET status = 'voided' WHERE delivery_id = {$deliveryId5}");
$resRetry = dl_saveProductionRun($adminUser, $transInput);
$h->test('transition: retry after voided receiving succeeds', !empty($resRetry['ok']));
$ledgerD = dl_t_ledgerRow($db, $bSelf, $p2, $testDate);
$h->test('transition: retry applies addtl exactly once (60)', (int)($ledgerD['addtl'] ?? -1) === 60);
$commD = dl_t_commissaryRow($db, $bSelf, $p2, $testDate);
$h->test('transition: retry records commissary produced 60', (int)($commD['produced_qty'] ?? -1) === 60);
$h->test('transition: retry records commissary dispatched 60', (int)($commD['dispatched_qty'] ?? -1) === 60);
$voidedChk = $db->prepare('SELECT status FROM dl_deliveries WHERE id = ? LIMIT 1');
$voidedChk->execute([$deliveryId5]);
$h->test('transition: retry voids the old DR-TEST-5 delivery', ($voidedChk->fetchColumn() ?: 'draft') === 'voided');

// Clean transition rows (remove the DR-TEST-5 delivery + receiving before cleanup).
$db->execute("DELETE FROM dl_deliveries WHERE destination_id = {$bSelf} AND delivery_date = '{$testDate}'");

// ─── Section 9: Authorization + day-state gates ─────────────────────────
$h->section('Authorization and day-state gates');

// Out-of-scope branch for a production_in_charge with no assigned branches.
$threw4 = null;
try {
    dl_saveProductionRun($picNoAccess, $runInput);
} catch (\RuntimeException $e) {
    $threw4 = $e->getMessage();
}
$h->test('production_in_charge without access → rejected', $threw4 !== null && str_contains($threw4, 'not allowed for this user'));

// Inactive branch same-location → rejected (resolver returns branch_inactive →
// falls back to the required-DR branch → not eligible → empty DR rejected).
$threw5 = null;
try {
    dl_saveProductionRun($adminUser, array_merge($runInput, ['destination_branch_id' => $bInactive]));
} catch (\RuntimeException $e) {
    $threw5 = $e->getMessage();
}
$h->test('inactive branch same-location → rejected', $threw5 !== null);

// Closed day: supervisor (no production.override) on a closed day → rejected.
// Give the supervisor explicit access to branch 99001 so the day-state gate
// (not the branch-authorization gate) is what rejects the save.
$db->execute("INSERT INTO dl_users (id, username, password_hash, full_name, role) VALUES (999999, 'tst-supervisor', 'x', 'Test Supervisor', 'supervisor') ON DUPLICATE KEY UPDATE full_name='Test Supervisor'");
$db->execute("INSERT INTO dl_user_branches (user_id, branch_id) VALUES (999999, {$bSelf})");
$db->execute("INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES ({$bSelf}, '{$testDate}', 'closed')
           ON DUPLICATE KEY UPDATE status = 'closed'");
$threw6 = null;
try {
    dl_saveProductionRun($supervisorUser, $runInput);
} catch (\RuntimeException $e) {
    $threw6 = $e->getMessage();
}
$h->test('supervisor on closed day → rejected', $threw6 !== null && str_contains($threw6, 'Day is closed'));

// Admin (production.override) can still save on a closed day.
$db->execute("UPDATE dl_ledger_day_status SET status = 'closed' WHERE branch_id = {$bSelf} AND ledger_date = '{$testDate}'");
$resClosedAdmin = dl_saveProductionRun($adminUser, $runInput);
$h->test('admin with production.override can save on closed day', !empty($resClosedAdmin['ok']));

// ─── Section 10: Regression — manual adjustment addtl path ──────────────
$h->section('Regression — adjustment addtl + derived sales');

// adjustment_add is a variance-correction path via dl_applyLedgerDelta('addtl')
// (the same primitive the Stock Adjustment modal uses server-side).
$adjRes = dl_applyLedgerDelta($bSelf, $p1, $testDate, 25, 1, 'addtl');
$h->test('manual adjustment_add still increments addtl', ($adjRes['addtl'] ?? null) === 125);
$ledgerAdj = dl_t_ledgerRow($db, $bSelf, $p1, $testDate);
$h->test('derived sales recomputed after adjustment', (int)($ledgerAdj['sales'] ?? -1) === (int)($ledgerAdj['beg_bal'] ?? 0) + (int)($ledgerAdj['addtl'] ?? 0) - (int)($ledgerAdj['withdraw'] ?? 0) - (int)($ledgerAdj['bal_end'] ?? 0));
$h->test('adjustment does not create a delivery', dl_t_countRows($db, 'dl_deliveries', 'destination_id = ? AND delivery_date = ? AND dr_number = ?', [$bSelf, $testDate, 'ADJ']) === 0);

// ─── Section 11: CSV product import — malformed rows are skipped, not fatal ──
$h->section('CSV product import — malformed row resilience');

// The import loop wraps dl_normalizeProductCsvRow() in a per-row try/catch so a
// malformed cell skips only its own row (counted) instead of aborting the whole
// upload and leaving partial rows committed. The helper itself is the unit we
// can exercise without an HTTP request (App::json() exits the process).
$okParsed = dl_normalizeProductCsvRow(['name' => 'CSV Bread', 'price' => '120.50', 'sku' => 'X-1', 'category' => 'bread', 'batch_egg_qty' => '12', 'batch_input_qty' => '1.5', 'output_pieces_per_batch' => '100']);
$h->test('valid row parses name', ($okParsed['name'] ?? '') === 'CSV Bread');
$h->test('valid row parses price', ($okParsed['price'] ?? null) === 120.5);
$h->test('valid row parses batch_egg_qty', ($okParsed['batch_egg_qty'] ?? null) === 12.0);
$h->test('valid row parses batch_input_qty', ($okParsed['batch_input_qty'] ?? null) === 1.5);
$h->test('valid row parses oppb', ($okParsed['oppb'] ?? null) === 100);
$h->test('valid row defaults category to bread', ($okParsed['category'] ?? '') === 'bread');

$threwPrice = null;
try { dl_normalizeProductCsvRow(['name' => 'Bad', 'price' => 'oops']); } catch (\RuntimeException $e) { $threwPrice = $e->getMessage(); }
$h->test('non-numeric price throws (row gets skipped)', $threwPrice !== null && str_contains($threwPrice, 'numeric'));

$threwEggs = null;
try { dl_normalizeProductCsvRow(['name' => 'Bad', 'price' => '10', 'batch_egg_qty' => 'abc']); } catch (\RuntimeException $e) { $threwEggs = $e->getMessage(); }
$h->test('non-numeric batch cell throws (row gets skipped)', $threwEggs !== null && str_contains($threwEggs, 'numeric'));

$threwName = null;
try { dl_normalizeProductCsvRow(['name' => '', 'price' => '10']); } catch (\RuntimeException $e) { $threwName = $e->getMessage(); }
$h->test('empty name throws (row gets skipped)', $threwName !== null && str_contains($threwName, 'name/price'));

// ─── Cleanup + restore ──────────────────────────────────────────────────
dl_t_cleanup($db, $seedBranchIds, $seedProductIds, $seedRuleIds);

try {
    dlPersistModuleSettings(['formal_delivery_workflow_enabled' => $prevFormalRaw]);
    dlModuleSettings(true);
} catch (\Throwable $e) {
    $h->gap('restore formal delivery setting: ' . $e->getMessage());
}

$h->section('Cleanup verification');
$h->test('seed branches removed', dl_t_countRows($db, 'dl_branches', 'id IN (99001,99002,99003,99004,99005,99006)', []) === 0);
$h->test('seed products removed', dl_t_countRows($db, 'dl_products', 'id IN (99001,99002,99003)', []) === 0);

$h->done();
