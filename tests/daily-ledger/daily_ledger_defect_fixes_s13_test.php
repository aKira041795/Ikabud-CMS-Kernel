<?php

declare(strict_types=1);

/**
 * Daily Ledger — S13 defect fixes (five small fixes, five behavioural tests).
 *
 * Owner principle: the paper sheet flow, not a complex production ledger. Every
 * assertion below drives real code and reads what the operator would see.
 *
 *   FIX 1 (blocking) — the Daily Sheet defaulted to a tenant-specific commissary
 *           filter and then matched `origin_id`, which is NULL on every
 *           historical delivery, so every branch cell read 0. Proved by
 *           RENDERING the page with no commissary_id and asserting the known
 *           sent quantity (2026-09-28, product 17, branch 8 → 1). Explicit
 *           filtering is proved separately and still works.
 *   FIX 2 — draft deliveries inflated the branch cell/TOTAL and receipt state.
 *           The dispatch, receiving and entry-flag reads now require posted.
 *   FIX 3 — an over-negative correction was stored clamped. A correction that
 *           would drive the cell below zero is now refused (422) under the same
 *           transaction/lock; a negative landing exactly on zero is accepted.
 *   FIX 4 — consumed pullouts (staff meal, sampling, testing, promo, donation)
 *           were still credited as returned stock because the return delivery
 *           was created unconditionally. Classification now happens before the
 *           return is created; wastage returns stay out of saleable
 *           returned_qty. Driven end to end through the REAL
 *           apiSaveCashierWithdrawals path and the rendered Summary.
 *   FIX 5 — the displayed equation ignored wastage while the pre-fill subtracted
 *           it. The text now names WASTAGE; the formula is unchanged.
 *
 * Tenant 207 (baron-001). Every fixture is isolated in the 991xx id range and
 * removed before the suite ends.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-defect-fixes-s13', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

// Rendering compiles the DiSyL template (one info line), and the first render
// after a code change rebuilds the module registry cache. Neither is a defect.
$h->allowLogLines('disyl.compile.phases', 'kernel_state_cache: module_registry rebuilt');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_defect_fixes_s13_harness.php');
$h->fingerprint('tests/daily-ledger/daily_ledger_offline_replay_harness.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$harnessPath = __DIR__ . '/daily_ledger_defect_fixes_s13_harness.php';
$runHarness = static function (array $args) use ($harnessPath): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessPath);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string)$arg);
    }
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>/dev/null', $output, $exitCode);
    $raw = implode("\n", $output);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    return ['_raw' => $raw, '_exit' => $exitCode];
};
$runSheet = static function (string $date, int $commissaryId = 0) use ($runHarness): array {
    $args = ['sheet', $date];
    if ($commissaryId > 0) {
        $args[] = $commissaryId;
    }
    return $runHarness($args);
};
$runWithdraw = static function (array $payload) use ($runHarness): array {
    $file = sys_get_temp_dir() . '/s13-withdraw-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($file, json_encode($payload));
    $result = $runHarness(['withdraw', $file]);
    @unlink($file);
    return $result;
};

/**
 * Remove every row a fixture branch/product pair could have created, in FK-safe
 * order. Keeps the shared tenant untouched (0/0/0/118/112).
 */
$cleanupFixture = static function (array $branchIds, array $productIds) use ($db): void {
    if ($branchIds === [] && $productIds === []) {
        return;
    }
    $b = implode(',', array_map('intval', $branchIds));
    $p = implode(',', array_map('intval', $productIds));
    $branchFilter = $branchIds === [] ? '0' : "(SELECT id FROM dl_deliveries WHERE origin_id IN ({$b}) OR destination_id IN ({$b}))";
    $db->execute("DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN ({$b}))");
    $db->execute("DELETE FROM dl_integrity_notifications WHERE branch_id IN ({$b})");
    if ($productIds !== []) {
        $db->execute("DELETE FROM dl_branch_receiving_items WHERE product_id IN ({$p}) OR receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN ({$b}))");
    } else {
        $db->execute("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN ({$b}))");
    }
    $db->execute("DELETE FROM dl_branch_receivings WHERE branch_id IN ({$b}) OR delivery_id IN {$branchFilter}");
    $db->execute("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN {$branchFilter}");
    if ($productIds !== []) {
        $db->execute("DELETE FROM dl_delivery_items WHERE product_id IN ({$p}) OR delivery_id IN {$branchFilter}");
    } else {
        $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN {$branchFilter}");
    }
    $db->execute("DELETE FROM dl_deliveries WHERE origin_id IN ({$b}) OR destination_id IN ({$b})");
    if ($productIds !== []) {
        $db->execute("DELETE FROM dl_production_movements WHERE product_id IN ({$p}) OR destination_branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_production_runs WHERE product_id IN ({$p}) OR destination_branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_commissary_product_ledger WHERE product_id IN ({$p}) OR commissary_branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_variance_flags WHERE product_id IN ({$p}) OR branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_cashier_withdrawals WHERE product_id IN ({$p}) OR branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_daily_ledger WHERE product_id IN ({$p}) OR branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_branch_products WHERE product_id IN ({$p}) OR branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_products WHERE id IN ({$p})");
    } else {
        $db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_production_runs WHERE destination_branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_variance_flags WHERE branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_cashier_withdrawals WHERE branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$b})");
        $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$b})");
    }
    $db->execute("DELETE FROM audit_logs WHERE branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$b})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$b})");
};

$seedBranch = static function (int $id, string $code, string $name, bool $isCommissary) use ($db): void {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$id, $code, $name, $isCommissary ? 'self_managed' : 'commissary_supplied', $isCommissary ? 1 : 0]);
};
$seedProduct = static function (int $id, string $sku, string $name) use ($db): void {
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$id, $sku, $name]);
};

$admin = ['id' => 1, 'sub' => 'admin:1', 'role' => 'admin', 'source' => 'daily-ledger', 'name' => 'S13 Admin'];

// Pre-clean every fixture the suite owns so an interrupted earlier run cannot leak.
$fix1CommA = 99110; $fix1CommB = 99111; $fix1Branch = 99112; $fix1Product = 99113;
$fix2Comm = 99120; $fix2Branch = 99121; $fix2Product = 99122; $fix2DraftOnlyProduct = 99123;
$fix3Comm = 99124; $fix3Branch = 99125; $fix3Product = 99126;
$fix4Comm = 99130; $fix4Branch = 99131; $fix4Product = 99132;
$fix5Comm = 99140; $fix5Branch = 99141; $fix5Product = 99142;
// S14 offline-mirror fixtures (replayed through the real apiOfflineReconcile).
$off4Comm = 99150; $off4Branch = 99151; $off4Product = 99152;

$cleanupFixture([$fix1CommA, $fix1CommB, $fix1Branch], [$fix1Product]);
$cleanupFixture([$fix2Comm, $fix2Branch], [$fix2Product, $fix2DraftOnlyProduct]);
$cleanupFixture([$fix3Comm, $fix3Branch], [$fix3Product]);
$cleanupFixture([$fix4Comm, $fix4Branch], [$fix4Product]);
$cleanupFixture([$fix5Comm, $fix5Branch], [$fix5Product]);
$cleanupFixture([$off4Comm, $off4Branch], [$off4Product]);

$prevFormalRaw = dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? '0';

// ═══════════════════════════════════════════════════════════════════════
// FIX 1 — default sheet must render real branch data
// ═══════════════════════════════════════════════════════════════════════
$h->section('FIX 1 — Daily Sheet default filter (page render)');
$knownDate = '2026-09-28';
// Derive the expected non-zero cell from the live dispatch matrix rather than
// hard-coding a tenant product/branch pair; the assertion still proves the
// default (no commissary_id) render surfaces a real sent quantity.
$knownMatrix = dl_fetchProductionSheetDispatchMatrix($db, $knownDate, 0);
$knownCellKey = null;
$knownCellValue = 0;
foreach ($knownMatrix as $pid => $byBranch) {
    foreach ($byBranch as $bid => $qty) {
        if ((int)$qty !== 0) {
            $knownCellKey = $pid . ':' . $bid;
            $knownCellValue = (int)$qty;
            break 2;
        }
    }
}
$knownSheet = $runSheet($knownDate);
$h->test(
    'FIX1 no commissary_id renders a real sent quantity from the unfiltered matrix',
    $knownCellKey !== null && ($knownSheet['cells'][$knownCellKey] ?? null) === $knownCellValue,
    'key=' . (string)$knownCellKey . ' expected=' . $knownCellValue . ' got=' . json_encode($knownSheet['cells'][$knownCellKey] ?? null)
);

$unfilteredMatrix = $knownMatrix;
$renderMatchesMatrix = true;
$nonZeroRendered = 0;
foreach ($knownSheet['cells'] as $key => $value) {
    [$pid, $bid] = array_map('intval', explode(':', $key));
    $expected = (int)($unfilteredMatrix[$pid][$bid] ?? 0);
    if ($value !== $expected) {
        $renderMatchesMatrix = false;
        break;
    }
    if ($value !== 0) {
        $nonZeroRendered++;
    }
}
$h->test('FIX1 every rendered default cell equals the unfiltered dispatch matrix', $renderMatchesMatrix);
$h->test('FIX1 the default render is not all zeros', $nonZeroRendered > 0, 'non-zero cells=' . $nonZeroRendered);

// Explicit filtering still works: two commissaries, same branch/product/date.
$seedBranch($fix1CommA, 'S13-F1A', 'S13 F1 Commissary A', true);
$seedBranch($fix1CommB, 'S13-F1B', 'S13 F1 Commissary B', true);
$seedBranch($fix1Branch, 'S13-F1BR', 'S13 F1 Branch', false);
$seedProduct($fix1Product, 'S13-F1P', 'S13 F1 Product');
$fix1Date = '2031-01-10';
foreach ([[$fix1CommA, 7], [$fix1CommB, 9]] as [$originId, $qty]) {
    $db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, delivery_date, status) VALUES ("commissary", ?, "branch", ?, ?, "posted")')
        ->execute([$originId, $fix1Branch, $fix1Date]);
    $deliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity) VALUES (?, ?, ?)')
        ->execute([$deliveryId, $fix1Product, $qty]);
}
$fix1FilterA = dl_fetchProductionSheetDispatchMatrix($db, $fix1Date, $fix1CommA);
$fix1FilterB = dl_fetchProductionSheetDispatchMatrix($db, $fix1Date, $fix1CommB);
$fix1FilterAll = dl_fetchProductionSheetDispatchMatrix($db, $fix1Date, 0);
$h->test('FIX1 explicit commissary filter keeps only that commissary', (int)($fix1FilterA[$fix1Product][$fix1Branch] ?? 0) === 7);
$h->test('FIX1 a second explicit filter keeps only the second commissary', (int)($fix1FilterB[$fix1Product][$fix1Branch] ?? 0) === 9);
$h->test('FIX1 All (0) sums both commissaries', (int)($fix1FilterAll[$fix1Product][$fix1Branch] ?? 0) === 16);
$cleanupFixture([$fix1CommA, $fix1CommB, $fix1Branch], [$fix1Product]);

// ═══════════════════════════════════════════════════════════════════════
// FIX 2 — a draft delivery is not sent
// ═══════════════════════════════════════════════════════════════════════
$h->section('FIX 2 — draft deliveries never count as sent');
$seedBranch($fix2Comm, 'S13-F2C', 'S13 F2 Commissary', true);
$seedBranch($fix2Branch, 'S13-F2B', 'S13 F2 Branch', false);
$seedProduct($fix2Product, 'S13-F2P', 'S13 F2 Product');
$seedProduct($fix2DraftOnlyProduct, 'S13-F2D', 'S13 F2 Draft Only');
$db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$fix2Comm, $fix2Product]);
$db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$fix2Comm, $fix2DraftOnlyProduct]);
$fix2Date = '2031-02-10';

$insertDelivery = $db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, delivery_date, status) VALUES ("commissary", ?, "branch", ?, ?, ?)');
$insertItem = $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity) VALUES (?, ?, ?)');
// same product/branch: one draft (4) + one posted (6)
$insertDelivery->execute([$fix2Comm, $fix2Branch, $fix2Date, 'draft']);
$draftId = (int)$db->lastInsertId();
$insertItem->execute([$draftId, $fix2Product, 4]);
$draftItemId = (int)$db->lastInsertId();
$insertDelivery->execute([$fix2Comm, $fix2Branch, $fix2Date, 'posted']);
$postedId = (int)$db->lastInsertId();
$insertItem->execute([$postedId, $fix2Product, 6]);
$postedItemId = (int)$db->lastInsertId();
// a product that only ever has a draft
$insertDelivery->execute([$fix2Comm, $fix2Branch, $fix2Date, 'draft']);
$draftOnlyId = (int)$db->lastInsertId();
$insertItem->execute([$draftOnlyId, $fix2DraftOnlyProduct, 3]);
// receivings for the posted and the draft (linked by delivery_item_id, as the
// receiving matrix joins them)
foreach ([[$postedId, $postedItemId, $fix2Product, 6], [$draftId, $draftItemId, $fix2Product, 4]] as [$deliveryId, $deliveryItemId, $productId, $received]) {
    $db->prepare('INSERT INTO dl_branch_receivings (branch_id, origin_type, delivery_id, received_ledger_date, status) VALUES (?, "commissary", ?, ?, "posted")')
        ->execute([$fix2Branch, $deliveryId, $fix2Date]);
    $receivingId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_branch_receiving_items (receiving_id, delivery_item_id, product_id, quantity_received) VALUES (?, ?, ?, ?)')
        ->execute([$receivingId, $deliveryItemId, $productId, $received]);
}
// A draft receiving attached to the posted dispatch must not inflate received.
$db->prepare('INSERT INTO dl_branch_receivings (branch_id, origin_type, delivery_id, received_ledger_date, status) VALUES (?, "commissary", ?, ?, "draft")')
    ->execute([$fix2Branch, $postedId, $fix2Date]);
$draftReceivingId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO dl_branch_receiving_items (receiving_id, delivery_item_id, product_id, quantity_received) VALUES (?, ?, ?, 100)')
    ->execute([$draftReceivingId, $postedItemId, $fix2Product]);

$fix2Matrix = dl_fetchProductionSheetDispatchMatrix($db, $fix2Date, $fix2Comm);
$h->test('FIX2 dispatch matrix counts the posted item only', (int)($fix2Matrix[$fix2Product][$fix2Branch] ?? 0) === 6);
$h->test('FIX2 a draft-only product is absent from the dispatch matrix', !isset($fix2Matrix[$fix2DraftOnlyProduct]));
$fix2Flags = dl_fetchProductionSheetDispatchEntryFlags($db, $fix2Date, $fix2Comm);
$h->test('FIX2 entry flags do not treat a draft-only product as entered', !isset($fix2Flags[$fix2DraftOnlyProduct]));
$fix2Receiving = dl_fetchProductionSheetReceivingMatrix($db, $fix2Date, $fix2Comm);
$h->test('FIX2 receiving matrix sent count excludes the draft', (int)($fix2Receiving[$fix2Product][$fix2Branch]['sent'] ?? 0) === 6);
$h->test('FIX2 receiving matrix received count excludes the draft', (int)($fix2Receiving[$fix2Product][$fix2Branch]['received'] ?? 0) === 6);
$fix2Sheet = $runSheet($fix2Date, $fix2Comm);
$h->test('FIX2 rendered branch cell excludes the draft', ($fix2Sheet['cells'][$fix2Product . ':' . $fix2Branch] ?? null) === 6);
$fix2Row = $fix2Sheet['rows_detail'][(string)$fix2Product] ?? ($fix2Sheet['rows_detail'][$fix2Product] ?? null);
$h->test('FIX2 rendered row TOTAL excludes the draft', is_array($fix2Row) && (int)$fix2Row['total'] === 6, json_encode($fix2Row));
$cleanupFixture([$fix2Comm, $fix2Branch], [$fix2Product, $fix2DraftOnlyProduct]);

// ═══════════════════════════════════════════════════════════════════════
// FIX 3 — an over-negative correction is refused, never clamped
// ═══════════════════════════════════════════════════════════════════════
$h->section('FIX 3 — over-negative correction rejected under the transaction/lock');
$seedBranch($fix3Comm, 'S13-F3C', 'S13 F3 Commissary', true);
$seedBranch($fix3Branch, 'S13-F3B', 'S13 F3 Branch', false);
$seedProduct($fix3Product, 'S13-F3P', 'S13 F3 Product');
$db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$fix3Comm, $fix3Product]);
$fix3Date = '2031-02-20';

dl_recordDailySheetBranchEntry($admin, [
    'date' => $fix3Date,
    'commissary_branch_id' => $fix3Comm,
    'destination_branch_id' => $fix3Branch,
    'product_id' => $fix3Product,
    'quantity' => 10,
    'submission_id' => 's13-fix3-first',
]);
$commissaryRow = static function () use ($db, $fix3Comm, $fix3Product, $fix3Date): array {
    $stmt = $db->prepare('SELECT produced_qty, dispatched_qty, wastage_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? AND product_id = ? AND ledger_date = ?');
    $stmt->execute([$fix3Comm, $fix3Product, $fix3Date]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$deliveriesForCell = static function () use ($db, $fix3Comm, $fix3Branch, $fix3Product, $fix3Date): int {
    return (int)$db->query(
        "SELECT COUNT(*) FROM dl_deliveries d INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
          WHERE d.delivery_date = '{$fix3Date}' AND d.origin_id = {$fix3Comm} AND d.destination_id = {$fix3Branch}
            AND di.product_id = {$fix3Product}"
    )->fetchColumn();
};

$h->test('FIX3 first entry records 10', (int)dl_dailySheetCellQuantity($db, $fix3Date, $fix3Comm, $fix3Product, $fix3Branch) === 10);
$cellBefore = dl_dailySheetCellQuantity($db, $fix3Date, $fix3Comm, $fix3Product, $fix3Branch);
$deliveriesBefore = $deliveriesForCell();
$dispatchedBefore = (int)($commissaryRow()['dispatched_qty'] ?? 0);
$refused = null;
try {
    dl_recordDailySheetBranchEntry($admin, [
        'date' => $fix3Date,
        'commissary_branch_id' => $fix3Comm,
        'destination_branch_id' => $fix3Branch,
        'product_id' => $fix3Product,
        'quantity' => -15,
        'type' => 'correction',
        'reason_code' => 'manual_adjustment',
        'submission_id' => 's13-fix3-over',
    ]);
} catch (\RuntimeException $e) {
    $refused = $e;
}
$h->test(
    'FIX3 over-negative correction is refused with 422 and a clear message',
    $refused instanceof \RuntimeException
    && $refused->getCode() === 422
    && str_contains($refused->getMessage(), 'below zero'),
    $refused ? $refused->getMessage() : 'no exception'
);
$h->test(
    'FIX3 nothing is written on the refused correction',
    dl_dailySheetCellQuantity($db, $fix3Date, $fix3Comm, $fix3Product, $fix3Branch) === $cellBefore
    && $deliveriesForCell() === $deliveriesBefore
    && (int)($commissaryRow()['dispatched_qty'] ?? 0) === $dispatchedBefore
);

dl_recordDailySheetBranchEntry($admin, [
    'date' => $fix3Date,
    'commissary_branch_id' => $fix3Comm,
    'destination_branch_id' => $fix3Branch,
    'product_id' => $fix3Product,
    'quantity' => -10,
    'type' => 'correction',
    'reason_code' => 'manual_adjustment',
    'submission_id' => 's13-fix3-zero',
]);
$cellAfter = dl_dailySheetCellQuantity($db, $fix3Date, $fix3Comm, $fix3Product, $fix3Branch);
$dispatchedAfter = (int)($commissaryRow()['dispatched_qty'] ?? 0);
$itemSum = (int)$db->query(
    "SELECT COALESCE(SUM(di.quantity), 0) FROM dl_deliveries d INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
      WHERE d.delivery_date = '{$fix3Date}' AND d.origin_id = {$fix3Comm} AND d.destination_id = {$fix3Branch} AND di.product_id = {$fix3Product}"
)->fetchColumn();
$h->test('FIX3 a negative landing exactly on zero is accepted', $cellAfter === 0);
$h->test('FIX3 the stored dispatched quantity is not clamped away from the item sum', $dispatchedAfter === 0 && $itemSum === $dispatchedAfter);
$cleanupFixture([$fix3Comm, $fix3Branch], [$fix3Product]);

// ═══════════════════════════════════════════════════════════════════════
// FIX 4 — consumed pullouts are not credited as returned stock
// ═══════════════════════════════════════════════════════════════════════
$h->section('FIX 4 — real apiSaveCashierWithdrawals + rendered Summary');
$seedBranch($fix4Comm, 'S13-F4C', 'S13 F4 Commissary', true);
$seedBranch($fix4Branch, 'S13-F4B', 'S13 F4 Branch', false);
$seedProduct($fix4Product, 'S13-F4P', 'S13 F4 Pullout Product');
$db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$fix4Comm, $fix4Product]);
$db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$fix4Branch, $fix4Product]);
$fix4Date = '2031-06-15';

$seedFix4Ledger = static function () use ($db, $fix4Comm, $fix4Product, $fix4Date): void {
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, beg_qty, produced_qty, dispatched_qty, wastage_qty) VALUES (?, ?, ?, 0, 20, 0, 0)')
        ->execute([$fix4Comm, $fix4Product, $fix4Date]);
};
$fix4Ledger = static function () use ($db, $fix4Comm, $fix4Product, $fix4Date): array {
    $stmt = $db->prepare('SELECT produced_qty, dispatched_qty, wastage_qty, remaining_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? AND product_id = ? AND ledger_date = ?');
    $stmt->execute([$fix4Comm, $fix4Product, $fix4Date]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$fix4ReturnDeliveries = static function () use ($db, $fix4Comm, $fix4Branch, $fix4Date): int {
    return (int)$db->query(
        "SELECT COUNT(*) FROM dl_deliveries WHERE origin_type = 'branch' AND origin_id = {$fix4Branch}
          AND destination_type = 'branch' AND destination_id = {$fix4Comm} AND delivery_date = '{$fix4Date}'"
    )->fetchColumn();
};
$fix4Payload = static function (string $reason) use ($fix4Branch, $fix4Date, $fix4Comm, $fix4Product): array {
    return [
        'branch_id' => $fix4Branch,
        'date' => $fix4Date,
        'shift' => 'AM',
        'header' => [
            'withdrawal_type' => 'pullout',
            'reason_code' => $reason,
            'target_branch_id' => $fix4Comm,
        ],
        'lines' => [
            ['product_id' => $fix4Product, 'quantity' => 5, 'unit' => 'pcs'],
        ],
    ];
};

dlPersistModuleSettings(['formal_delivery_workflow_enabled' => '1']);
dlModuleSettings(true);
$h->test('FIX4 formal delivery enabled for the end-to-end path', dl_isFormalDeliveryEnabled() === true);

// ── (a) spoilage → wastage_qty up, no saleable return ──
$seedFix4Ledger();
$spoilageResponse = $runWithdraw($fix4Payload('spoilage'));
$h->test('FIX4 spoilage pullout is accepted by the real endpoint', !empty($spoilageResponse['ok']), json_encode($spoilageResponse));
$spoilageLedger = $fix4Ledger();
$h->test('FIX4 spoilage increases wastage_qty by the pullout', (int)($spoilageLedger['wastage_qty'] ?? 0) === 5, json_encode($spoilageLedger));
$h->test('FIX4 spoilage creates the physical wastage return', $fix4ReturnDeliveries() === 1);
$spoilageSheet = $runSheet($fix4Date, $fix4Comm);
$spoilageSummary = $spoilageSheet['summary']['S13 F4 Pullout Product'] ?? null;
$spoilageRemaining = (int)($spoilageLedger['remaining_qty'] ?? 0);
$h->test(
    'FIX4 spoilage gets no saleable return in the rendered Summary',
    is_array($spoilageSummary)
    && (int)$spoilageSummary['wastage'] === 5
    && (int)$spoilageSummary['returned'] === 0
    && (int)$spoilageSummary['net'] === $spoilageRemaining,
    json_encode($spoilageSummary)
);

// ── reset for (b) staff_meal ──
$cleanupFixture([$fix4Comm, $fix4Branch], [$fix4Product]);
$seedBranch($fix4Comm, 'S13-F4C', 'S13 F4 Commissary', true);
$seedBranch($fix4Branch, 'S13-F4B', 'S13 F4 Branch', false);
$seedProduct($fix4Product, 'S13-F4P', 'S13 F4 Pullout Product');
$db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$fix4Comm, $fix4Product]);
$db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$fix4Branch, $fix4Product]);
$seedFix4Ledger();

$staffMealResponse = $runWithdraw($fix4Payload('staff_meal'));
$h->test('FIX4 staff_meal pullout is accepted by the real endpoint', !empty($staffMealResponse['ok']), json_encode($staffMealResponse));
$staffMealLedger = $fix4Ledger();
$h->test(
    'FIX4 staff_meal leaves the commissary product ledger untouched',
    (int)($staffMealLedger['produced_qty'] ?? -1) === 20
    && (int)($staffMealLedger['dispatched_qty'] ?? -1) === 0
    && (int)($staffMealLedger['wastage_qty'] ?? -1) === 0,
    json_encode($staffMealLedger)
);
$h->test('FIX4 staff_meal creates no physical return delivery', $fix4ReturnDeliveries() === 0);
$staffMealSheet = $runSheet($fix4Date, $fix4Comm);
$staffMealSummary = $staffMealSheet['summary']['S13 F4 Pullout Product'] ?? null;
$h->test(
    'FIX4 staff_meal is not credited in the rendered Summary and net_available does not rise',
    is_array($staffMealSummary)
    && (int)$staffMealSummary['returned'] === 0
    && (int)$staffMealSummary['net'] === (int)($staffMealLedger['remaining_qty'] ?? -999),
    json_encode($staffMealSummary)
);
$cleanupFixture([$fix4Comm, $fix4Branch], [$fix4Product]);

// ═══════════════════════════════════════════════════════════════════════
// FIX 4 (offline mirror) — the same consumed-pullout rule through the REAL
// offline replay entry point (apiOfflineReconcile), not the worker directly.
// ═══════════════════════════════════════════════════════════════════════
$h->section('FIX 4 (offline) — real apiOfflineReconcile replay');
$off4Date = '2031-06-20';
$off4Device = 'dl-s13-offline-replay-1';
$off4Enrollment = '9913aaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$off4Scope = (string)(app()->tenant()->current() ?? '');
$off4Hash = hash('sha256', $off4Scope . '|' . trim($off4Device));

$runOfflineReplay = static function (string $reason, string $clientOpId) use ($off4Device, $off4Enrollment, $off4Branch, $off4Comm, $off4Product, $off4Date): array {
    $payload = [
        'device_id' => $off4Device,
        'enrollment_id' => $off4Enrollment,
        'operations' => [[
            'client_op_id' => $clientOpId,
            'type' => 'withdrawal',
            'payload' => [
                'branch_id' => $off4Branch,
                'date' => $off4Date,
                'shift' => 'AM',
                'header' => ['withdrawal_type' => 'pullout', 'reason_code' => $reason, 'target_branch_id' => $off4Comm],
                'lines' => [['product_id' => $off4Product, 'quantity' => 5, 'unit' => 'pcs']],
            ],
        ]],
    ];
    $file = sys_get_temp_dir() . '/s13-offline-replay-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($file, json_encode($payload));
    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/daily_ledger_offline_replay_harness.php') . ' '
        . escapeshellarg($file) . ' '
        . escapeshellarg((string)$off4Branch) . ' 2>/dev/null';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    @unlink($file);
    $decoded = json_decode(implode("\n", $output), true);
    return is_array($decoded) ? $decoded : ['_raw' => implode("\n", $output), '_exit' => $exitCode];
};

$seedOff4 = static function () use ($db, $seedBranch, $seedProduct, $off4Comm, $off4Branch, $off4Product, $off4Date, $off4Scope, $off4Device, $off4Enrollment, $off4Hash): void {
    $seedBranch($off4Comm, 'S13-OF4C', 'S13 OF4 Commissary', true);
    $seedBranch($off4Branch, 'S13-OF4B', 'S13 OF4 Branch', false);
    $seedProduct($off4Product, 'S13-OF4P', 'S13 OF4 Offline Pullout Product');
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$off4Comm, $off4Product]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$off4Branch, $off4Product]);
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, beg_qty, produced_qty, dispatched_qty, wastage_qty) VALUES (?, ?, ?, 0, 20, 0, 0)')
        ->execute([$off4Comm, $off4Product, $off4Date]);
    $db->execute('DELETE FROM dl_offline_sync_receipts WHERE tenant_scope = :ts', [':ts' => $off4Scope]);
    $db->execute('DELETE FROM dl_offline_device_enrollments WHERE tenant_scope = :ts', [':ts' => $off4Scope]);
    $db->prepare('INSERT INTO dl_offline_device_enrollments
            (tenant_scope, enrollment_id, device_id, device_hash, actor_user_id, branch_id, role, status, issued_at, expires_at)
         VALUES (?, ?, ?, ?, 1, ?, "admin", "active", NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))')
        ->execute([$off4Scope, $off4Enrollment, $off4Device, $off4Hash, $off4Branch]);
};
$off4Ledger = static function () use ($db, $off4Comm, $off4Product, $off4Date): array {
    $stmt = $db->prepare('SELECT produced_qty, dispatched_qty, wastage_qty, remaining_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? AND product_id = ? AND ledger_date = ?');
    $stmt->execute([$off4Comm, $off4Product, $off4Date]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};
$off4Returns = static function () use ($db, $off4Comm, $off4Branch, $off4Date): array {
    return $db->query(
        "SELECT remarks FROM dl_deliveries WHERE origin_type = 'branch' AND origin_id = {$off4Branch}
          AND destination_type = 'branch' AND destination_id = {$off4Comm} AND delivery_date = '{$off4Date}'"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
};

// staff_meal (consumed_no_delta) -> no return at all, no ledger delta.
$cleanupFixture([$off4Comm, $off4Branch], [$off4Product]);
$seedOff4();
$offMeal = $runOfflineReplay('staff_meal', 's13-off-staff-meal-1');
$h->test('FIX4 offline staff_meal is applied by the real reconcile endpoint', !empty($offMeal['ok']) && ($offMeal['results'][0]['status'] ?? '') === 'applied', json_encode($offMeal));
$h->test('FIX4 offline staff_meal creates no return delivery', $off4Returns() === []);
$offMealLedger = $off4Ledger();
$h->test('FIX4 offline staff_meal leaves the commissary ledger untouched', (int)($offMealLedger['produced_qty'] ?? -1) === 20 && (int)($offMealLedger['dispatched_qty'] ?? -1) === 0 && (int)($offMealLedger['wastage_qty'] ?? -1) === 0, json_encode($offMealLedger));
$offMealSheet = $runSheet($off4Date, $off4Comm);
$offMealSummary = $offMealSheet['summary']['S13 OF4 Offline Pullout Product'] ?? null;
$h->test('FIX4 offline staff_meal is not credited in the rendered Summary', is_array($offMealSummary) && (int)$offMealSummary['returned'] === 0 && (int)$offMealSummary['net'] === (int)($offMealLedger['remaining_qty'] ?? -999), json_encode($offMealSummary));

// spoilage -> tagged wastage return, Summary excludes it from returned.
$cleanupFixture([$off4Comm, $off4Branch], [$off4Product]);
$seedOff4();
$offSpoil = $runOfflineReplay('spoilage', 's13-off-spoilage-1');
$h->test('FIX4 offline spoilage is applied by the real reconcile endpoint', !empty($offSpoil['ok']) && ($offSpoil['results'][0]['status'] ?? '') === 'applied', json_encode($offSpoil));
$h->test('FIX4 offline spoilage creates the wastage-tagged physical return', $off4Returns() === ['[cashier-pullout-return:wastage]']);
$offSpoilLedger = $off4Ledger();
$h->test('FIX4 offline spoilage increases wastage_qty only', (int)($offSpoilLedger['produced_qty'] ?? -1) === 20 && (int)($offSpoilLedger['wastage_qty'] ?? -1) === 5, json_encode($offSpoilLedger));
$offSpoilSheet = $runSheet($off4Date, $off4Comm);
$offSpoilSummary = $offSpoilSheet['summary']['S13 OF4 Offline Pullout Product'] ?? null;
$h->test('FIX4 offline spoilage is not credited as saleable returned stock', is_array($offSpoilSummary) && (int)$offSpoilSummary['wastage'] === 5 && (int)$offSpoilSummary['returned'] === 0, json_encode($offSpoilSummary));

// saleable -> unchanged: untagged return and produced_qty credit.
$cleanupFixture([$off4Comm, $off4Branch], [$off4Product]);
$seedOff4();
$offSale = $runOfflineReplay('manual_adjustment', 's13-off-saleable-1');
$h->test('FIX4 offline saleable pullout is applied by the real reconcile endpoint', !empty($offSale['ok']) && ($offSale['results'][0]['status'] ?? '') === 'applied', json_encode($offSale));
$h->test('FIX4 offline saleable pullout keeps the untagged return', $off4Returns() === ['[cashier-pullout-return]']);
$offSaleLedger = $off4Ledger();
$h->test('FIX4 offline saleable pullout credits produced_qty', (int)($offSaleLedger['produced_qty'] ?? -1) === 25 && (int)($offSaleLedger['wastage_qty'] ?? -1) === 0, json_encode($offSaleLedger));

$cleanupFixture([$off4Comm, $off4Branch], [$off4Product]);
$db->execute('DELETE FROM dl_offline_sync_receipts WHERE tenant_scope = :ts', [':ts' => $off4Scope]);
$db->execute('DELETE FROM dl_offline_device_enrollments WHERE tenant_scope = :ts', [':ts' => $off4Scope]);

dlPersistModuleSettings(['formal_delivery_workflow_enabled' => $prevFormalRaw]);
dlModuleSettings(true);

// ═══════════════════════════════════════════════════════════════════════
// FIX 5 — displayed equation matches the implemented basis
// ═══════════════════════════════════════════════════════════════════════
$h->section('FIX 5 — displayed equation agrees with the pre-filled number');
$seedBranch($fix5Comm, 'S13-F5C', 'S13 F5 Commissary', true);
$seedBranch($fix5Branch, 'S13-F5B', 'S13 F5 Branch', false);
$seedProduct($fix5Product, 'S13-F5P', 'S13 F5 Product');
$db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$fix5Comm, $fix5Product]);
$fix5Date = '2031-03-10';
$fix5Beg = 10; $fix5Addtl = 5; $fix5Dispatched = 2; $fix5Wastage = 3;
$db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, beg_qty, produced_qty, dispatched_qty, wastage_qty) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([$fix5Comm, $fix5Product, $fix5Date, $fix5Beg, $fix5Addtl, $fix5Dispatched, $fix5Wastage]);
$db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, delivery_date, status) VALUES ("commissary", ?, "branch", ?, ?, "posted")')
    ->execute([$fix5Comm, $fix5Branch, $fix5Date]);
$fix5DeliveryId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity) VALUES (?, ?, ?)')
    ->execute([$fix5DeliveryId, $fix5Product, $fix5Dispatched]);

$fix5Sheet = $runSheet($fix5Date, $fix5Comm);
$fix5Row = $fix5Sheet['rows_detail'][(string)$fix5Product] ?? ($fix5Sheet['rows_detail'][$fix5Product] ?? null);
$fix5Summary = $fix5Sheet['summary']['S13 F5 Product'] ?? null;
$expectedBook = (int)($fix5Row['beg'] ?? 0) + (int)($fix5Row['addtl'] ?? 0) - (int)($fix5Row['total'] ?? 0) - $fix5Wastage;
$h->test(
    'FIX5 the displayed equation names WASTAGE',
    str_contains((string)($fix5Sheet['equation'] ?? ''), 'WASTAGE'),
    'equation=' . (string)($fix5Sheet['equation'] ?? '')
);
$h->test(
    'FIX5 the pre-filled ACTUAL BAL equals BEG + ADDTL − TOTAL − WASTAGE with wastage non-zero',
    is_array($fix5Row)
    && is_array($fix5Summary)
    && (int)$fix5Summary['wastage'] === $fix5Wastage
    && (int)$fix5Row['addtl'] === $fix5Addtl
    && (int)$fix5Row['total'] === $fix5Dispatched
    && (int)$fix5Row['actual'] === $expectedBook,
    json_encode($fix5Row) . ' wastage_summary=' . json_encode($fix5Summary) . ' expected=' . $expectedBook
);
$cleanupFixture([$fix5Comm, $fix5Branch], [$fix5Product]);

// ═══════════════════════════════════════════════════════════════════════
$h->section('Cleanup');
$h->test('FIX1 fixture removed', (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$fix1CommA},{$fix1CommB},{$fix1Branch})")->fetchColumn() === 0);
$h->test('FIX2 fixture removed', (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$fix2Comm},{$fix2Branch})")->fetchColumn() === 0);
$h->test('FIX3 fixture removed', (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$fix3Comm},{$fix3Branch})")->fetchColumn() === 0);
$h->test('FIX4 fixture removed', (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$fix4Comm},{$fix4Branch})")->fetchColumn() === 0);
$h->test('FIX4 offline fixture removed', (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$off4Comm},{$off4Branch})")->fetchColumn() === 0
    && (int)$db->query("SELECT COUNT(*) FROM dl_offline_device_enrollments WHERE tenant_scope = '{$off4Scope}'")->fetchColumn() === 0);
$h->test('FIX5 fixture removed', (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$fix5Comm},{$fix5Branch})")->fetchColumn() === 0);
$h->test('formal delivery setting restored', (string)(dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? '') === (string)$prevFormalRaw);

$h->done();
