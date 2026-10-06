<?php

declare(strict_types=1);

/**
 * Daily Ledger — G1 (additive report product filter) + G3 (import must not
 * re-assign 'specific' products) oracle.
 *
 * Every fixture is synthetic: branches 99731/99732 and products in the 997xx
 * range, all removed in finally. Real branch 8 is never used as a fixture.
 *
 * BASE-TREE DISCRIMINATION (observed by stashing the source fix and re-running):
 *   G1a  FAIL on base — an unassigned product holding ledger rows is absent.
 *   G1a2 FAIL on base — an INACTIVE unassigned product holding rows is absent.
 *   G1b  PASS on base — an assigned product with no rows is already offered.
 *   G1c  PASS on base — a product with neither is already absent.
 *   G1d  PASS on base — assigned+recorded behaviour/shape is unchanged.
 *   G3a  FAIL on base — the import creates the missing pair for a 'specific'
 *        product.
 *   G3b  PASS on base — an 'all_active' product is still assigned everywhere.
 *   G3c  PASS on base — INSERT IGNORE never reactivated an inactive pair.
 *
 * Tenant 207 (baronledger).
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-g1-g3-report-filter-and-import', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
// The suite runner deletes storage/modules.json per test, so the kernel registry
// rebuilds on the next bootstrap and writes this info line. It is test
// infrastructure, not behaviour under test; declare it so the log assertion
// points at genuinely unexpected lines (same pattern as disyl.compile.phases).
$h->allowLogLines('kernel_state_cache: module_registry rebuilt');
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/helpers/reporting.php');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('tests/daily-ledger/daily_ledger_g1_g3_harness.php');
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$_COOKIE[dlCookieName()] = app()->jwt()->generate([
    'sub' => 'admin:1',
    'id' => 1,
    'username' => 'g1g3-harness',
    'name' => 'G1G3 Harness',
    'role' => 'admin',
    'source' => 'daily-ledger',
]);

$store = 99731;
$comm = 99732;
$prodRowsOnly = 99731;      // active, unassigned, has a row  -> G1a
$prodRowsInactive = 99732;  // INACTIVE, unassigned, has a row -> G1a2
$prodAssigned = 99733;      // active, assigned, no row       -> G1b
$prodNeither = 99734;       // active, unassigned, no row     -> G1c
$prodNormal = 99735;        // active, assigned, has a row    -> G1d
$prodSpecA = 99736;         // specific, pair store only      -> G3a
$prodAllA = 99737;          // all_active, pair store only    -> G3b
$prodSpecInactive = 99738;  // specific, INACTIVE pair comm   -> G3c
$fixtureIds = [$prodRowsOnly, $prodRowsInactive, $prodAssigned, $prodNeither, $prodNormal, $prodSpecA, $prodAllA, $prodSpecInactive];
$fixtureBranches = "{$store},{$comm}";
$fixtureIdsCsv = implode(',', array_map('intval', $fixtureIds));

$rangeDate = '2020-05-15';
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-g1g3-');

$countTenant = static function () use ($db): array {
    return [
        'branches' => (int)$db->query('SELECT COUNT(*) FROM dl_branches')->fetchColumn(),
        'products' => (int)$db->query('SELECT COUNT(*) FROM dl_products')->fetchColumn(),
        'branch_products' => (int)$db->query('SELECT COUNT(*) FROM dl_branch_products')->fetchColumn(),
        'daily_ledger' => (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger')->fetchColumn(),
        'price_history' => (int)$db->query('SELECT COUNT(*) FROM dl_product_price_history')->fetchColumn(),
        'audit_logs' => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE module='daily-ledger'")->fetchColumn(),
    ];
};

$cleanup = static function () use ($db, $fixtureBranches, $fixtureIdsCsv): void {
    $pids = $db->query("SELECT id FROM dl_products WHERE id IN ({$fixtureIdsCsv}) OR sku LIKE 'G1G3-%'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($pids !== []) {
        $pidsCsv = implode(',', array_map('intval', $pids));
        $db->execute("DELETE FROM audit_logs WHERE module='daily-ledger' AND entity_type='product' AND entity_id IN ({$pidsCsv})");
        $db->execute("DELETE FROM dl_daily_ledger WHERE product_id IN ({$pidsCsv})");
        $db->execute("DELETE FROM dl_branch_products WHERE product_id IN ({$pidsCsv})");
        $db->execute("DELETE FROM dl_product_price_history WHERE product_id IN ({$pidsCsv})");
        $db->execute("DELETE FROM dl_products WHERE id IN ({$pidsCsv})");
    }
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$fixtureBranches})");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$fixtureBranches})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$fixtureBranches})");
};

$activatePair = static function (int $branchId, int $productId) use ($db): void {
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE is_active = 1')
        ->execute([$branchId, $productId]);
};
$hidePair = static function (int $branchId, int $productId) use ($db): void {
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,0) ON DUPLICATE KEY UPDATE is_active = 0')
        ->execute([$branchId, $productId]);
};
$pairActive = static function (int $branchId, int $productId) use ($db): int {
    $val = $db->query("SELECT is_active FROM dl_branch_products WHERE branch_id = {$branchId} AND product_id = {$productId}")->fetchColumn();
    return $val === false ? -1 : (int)$val;
};
$pairRowCount = static function (int $branchId, int $productId) use ($db): int {
    return (int)$db->query("SELECT COUNT(*) FROM dl_branch_products WHERE branch_id = {$branchId} AND product_id = {$productId}")->fetchColumn();
};
$activePairCount = static function (int $productId) use ($db): int {
    return (int)$db->query("SELECT COUNT(*) FROM dl_branch_products WHERE product_id = {$productId} AND is_active = 1")->fetchColumn();
};
$activeBranchCount = static function () use ($db): int {
    return (int)$db->query('SELECT COUNT(*) FROM dl_branches WHERE is_active = 1')->fetchColumn();
};
$ledgerRow = static function (int $branchId, int $productId, string $date, int $beg, int $end) use ($db): void {
    $db->prepare('INSERT INTO dl_daily_ledger (branch_id,product_id,ledger_date,shift,beg_bal,addtl,withdraw,bal_end,price_snapshot) VALUES (?,?,?,?,?,0,0,?,10)')
        ->execute([$branchId, $productId, $date, 'AM', $beg, $end]);
};
$runImport = static function (string $csv) use ($payloadFile): array {
    file_put_contents($payloadFile, json_encode(['csv' => $csv], JSON_THROW_ON_ERROR));
    $out = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_g1_g3_harness.php')
        . ' import_csv ' . escapeshellarg($payloadFile) . ' 2>/dev/null',
        $out,
        $code
    );
    return ['code' => $code, 'body' => json_decode(implode("\n", $out), true), 'raw' => implode("\n", $out)];
};

$cleanup();
$tenantBefore = $countTenant();

try {
    // ── Fixture ─────────────────────────────────────────────────────────────
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,0,1)')
        ->execute([$store, 'G1G3-STORE', 'G1G3 Store']);
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,1,1)')
        ->execute([$comm, 'G1G3-COMM', 'G1G3 Commissary']);
    // Names deliberately ASCII so the DB collation and PHP SORT_STRING agree,
    // which lets G1d assert the combined list is name-ordered.
    $names = [
        $prodRowsOnly => 'G1G3 Alpha Rows Only',
        $prodRowsInactive => 'G1G3 Bravo Rows Inactive',
        $prodAssigned => 'G1G3 Charlie Assigned',
        $prodNeither => 'G1G3 Delta Neither',
        $prodNormal => 'G1G3 Echo Normal',
        $prodSpecA => 'G1G3 Foxtrot Spec',
        $prodAllA => 'G1G3 Golf All',
        $prodSpecInactive => 'G1G3 Hotel Spec Inactive',
    ];
    $skus = [
        $prodRowsOnly => 'G1G3-ROWS',
        $prodRowsInactive => 'G1G3-ROWS-INACT',
        $prodAssigned => 'G1G3-ASSIGNED',
        $prodNeither => 'G1G3-NEITHER',
        $prodNormal => 'G1G3-NORMAL',
        $prodSpecA => 'G1G3-SPEC-A',
        $prodAllA => 'G1G3-ALL-A',
        $prodSpecInactive => 'G1G3-SPEC-B',
    ];
    foreach ($fixtureIds as $pid) {
        $active = $pid === $prodRowsInactive ? 0 : 1;
        $mode = $pid === $prodSpecA || $pid === $prodSpecInactive ? 'specific' : 'all_active';
        $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active,assignment_mode) VALUES (?,?,?,10,?,?)')
            ->execute([$pid, $skus[$pid], $names[$pid], $active, $mode]);
    }
    $activatePair($store, $prodAssigned);
    $activatePair($store, $prodNormal);
    $activatePair($store, $prodSpecA);
    $activatePair($store, $prodAllA);
    $activatePair($store, $prodSpecInactive);
    $hidePair($comm, $prodSpecInactive);

    // Ledger rows in the selected range.
    $ledgerRow($store, $prodRowsOnly, $rangeDate, 5, 5);
    $ledgerRow($store, $prodRowsInactive, $rangeDate, 5, 5);
    $ledgerRow($store, $prodNormal, $rangeDate, 5, 5);

    // ═════════════════════════════════════════════════════════════════════
    // G1 — the report product filter is additive
    // ═════════════════════════════════════════════════════════════════════
    $h->section('G1 additive report product filter');
    $filters = [
        'date_from' => $rangeDate,
        'date_to' => $rangeDate,
        'branch_id' => 0,
        'product_id' => 0,
        'shift' => '',
        'pending_rows_mode' => 'include',
        'accessible_branch_ids' => [$store, $comm],
    ];
    $result = dl_reportFilterProducts($db, $filters);
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $result);
    $inResult = static fn(int $pid): bool => in_array($pid, $ids, true);
    $offered = static fn(int $pid): string => 'offered=' . json_encode($ids) . ' expected ' . $pid;

    $h->test(
        'G1a an unassigned product with rows in range IS offered (base tree: absent)',
        $inResult($prodRowsOnly),
        $offered($prodRowsOnly)
    );
    $h->test(
        'G1a2 an INACTIVE unassigned product with rows in range IS offered (no is_active filter on the row set)',
        $inResult($prodRowsInactive),
        $offered($prodRowsInactive)
    );
    $h->test(
        'G1b an assigned product with NO rows in range is STILL offered (additive, not a replacement)',
        $inResult($prodAssigned),
        $offered($prodAssigned)
    );
    $h->test(
        'G1c a product with neither assignment nor rows is NOT offered',
        !$inResult($prodNeither),
        'offered=' . json_encode($ids)
    );
    $h->test(
        'G1d the assigned+recorded product is offered exactly once (de-duplicated)',
        count(array_keys($ids, $prodNormal, true)) === 1,
        'offered=' . json_encode($ids)
    );
    $normalRow = null;
    foreach ($result as $r) {
        if ((int)$r['id'] === $prodNormal) { $normalRow = $r; break; }
    }
    $normalKeys = $normalRow !== null ? array_keys($normalRow) : [];
    sort($normalKeys);
    $h->test(
        'G1d the return shape is exactly (id, name, sku)',
        $normalKeys === ['id', 'name', 'sku'],
        json_encode($normalRow)
    );
    $h->test(
        'G1d the SKU and name values are preserved',
        $normalRow !== null && (string)$normalRow['sku'] === 'G1G3-NORMAL' && (string)$normalRow['name'] === 'G1G3 Echo Normal',
        json_encode($normalRow)
    );
    $h->test(
        'G1d no duplicate ids in the combined list',
        count($ids) === count(array_unique($ids)),
        'ids=' . json_encode($ids)
    );
    $orderedNames = array_map(static fn(array $r): string => (string)$r['name'], $result);
    $sortedNames = $orderedNames;
    sort($sortedNames, SORT_STRING);
    $h->test(
        'G1d the combined list is ordered by name',
        $orderedNames === $sortedNames,
        'names=' . json_encode($orderedNames)
    );

    // ═════════════════════════════════════════════════════════════════════
    // G3 — the CSV import must not re-assign 'specific' products
    // ═════════════════════════════════════════════════════════════════════
    $h->section('G3 import leaves specific products alone');
    $beforeAllActive = $activePairCount($prodAllA);
    $beforeSpecA = $pairActive($comm, $prodSpecA);
    $beforeSpecInactive = $pairActive($comm, $prodSpecInactive);
    $h->test('G3 fixture: the specific product has no comm pair before the import', $pairRowCount($comm, $prodSpecA) === 0, 'rows=' . $pairRowCount($comm, $prodSpecA));

    $csv = "Name,Price,SKU\n"
        . "G1G3 Foxtrot Spec,21,G1G3-SPEC-A\n"
        . "G1G3 Golf All,22,G1G3-ALL-A\n"
        . "G1G3 Hotel Spec Inactive,23,G1G3-SPEC-B\n"
        . "G1G3 Brand New All,24,G1G3-NEW-ALL\n";
    $import = $runImport($csv);
    $h->test(
        'G3 the products import succeeds',
        ($import['body']['ok'] ?? false) === true
            && str_contains((string)($import['body']['message'] ?? ''), '1 created')
            && str_contains((string)($import['body']['message'] ?? ''), '3 updated'),
        $import['raw']
    );

    // G3a — the specific product with no comm row must STILL have no row.
    $h->test(
        'G3a a specific product with no row for branch B STILL has no row after the import (base tree creates one)',
        $pairRowCount($comm, $prodSpecA) === 0 && $pairActive($comm, $prodSpecA) === -1,
        'rows=' . $pairRowCount($comm, $prodSpecA) . ' is_active=' . $pairActive($comm, $prodSpecA)
    );

    // G3b — an all_active existing product is still assigned everywhere.
    $h->test(
        'G3b an all_active product is still assigned to all active branches by the import',
        $activePairCount($prodAllA) === $activeBranchCount() && $pairActive($comm, $prodAllA) === 1,
        'pairs=' . $activePairCount($prodAllA) . ' active_branches=' . $activeBranchCount() . ' comm=' . $pairActive($comm, $prodAllA)
    );
    $newId = (int)$db->query("SELECT id FROM dl_products WHERE sku = 'G1G3-NEW-ALL'")->fetchColumn();
    $h->test(
        'G3b a NEW CSV product (defaults to all_active) is still assigned to all active branches',
        $newId > 0 && $activePairCount($newId) === $activeBranchCount(),
        'new_id=' . $newId . ' pairs=' . ($newId > 0 ? $activePairCount($newId) : -1) . ' active_branches=' . $activeBranchCount()
    );

    // G3c — an existing INACTIVE pair is not reactivated.
    $h->test(
        'G3c an existing INACTIVE pair for a specific product is still INACTIVE after the import',
        $beforeSpecInactive === 0 && $pairActive($comm, $prodSpecInactive) === 0,
        'before=' . $beforeSpecInactive . ' after=' . $pairActive($comm, $prodSpecInactive)
    );

    $h->test('G3 the specific fixture kept its only assigned pair (store) untouched', $pairActive($store, $prodSpecA) === 1, 'store=' . $pairActive($store, $prodSpecA));
    $h->test('G3 the all_active fixture had no store pair removed', $pairActive($store, $prodAllA) === 1, 'store=' . $pairActive($store, $prodAllA));
} finally {
    $cleanup();
    if (is_file($payloadFile)) {
        unlink($payloadFile);
    }
}

$tenantAfter = $countTenant();
$h->section('Tenant datum queried before and after');
$h->test(
    'all fixture tables are back to their pre-test counts (cleanup removes every synthetic row)',
    $tenantAfter === $tenantBefore,
    'before=' . json_encode($tenantBefore) . ' after=' . json_encode($tenantAfter)
);
echo '  measured tenant counts: before=' . json_encode($tenantBefore) . ' after=' . json_encode($tenantAfter) . PHP_EOL;

// ── LIVE branch-8 report-filter count (read-only) ──────────────────────────
$h->section('LIVE branch 8 report product-filter count (read-only)');
$liveDate = dl_businessDate();
$liveFrom = (new \DateTimeImmutable($liveDate))->modify('-364 days')->format('Y-m-d');
$beforeCount = (int)$db->query(
    'SELECT COUNT(DISTINCT p.id)
       FROM dl_products p
       JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.is_active = 1
      WHERE p.is_active = 1 AND bp.branch_id = 8'
)->fetchColumn();
$liveProducts = dl_reportFilterProducts($db, [
    'date_from' => $liveFrom,
    'date_to' => $liveDate,
    'branch_id' => 0,
    'product_id' => 0,
    'shift' => '',
    'pending_rows_mode' => 'include',
    'accessible_branch_ids' => [8],
]);
$afterCount = count($liveProducts);
echo "  LIVE branch 8 product filter (window {$liveFrom}..{$liveDate}): before(base assignment set)={$beforeCount} after(additive)={$afterCount}" . PHP_EOL;
$h->test(
    'LIVE branch 8 counts are reported (no-op expected: tenant 207 has zero hidden pairs)',
    $beforeCount > 0 && $afterCount >= $beforeCount,
    "before={$beforeCount} after={$afterCount}"
);

$h->done();
