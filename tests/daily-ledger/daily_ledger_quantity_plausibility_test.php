<?php

declare(strict_types=1);

/**
 * Daily Ledger — a commissary sheet entry that is wildly out of scale is refused, not absorbed
 *
 * Owner, 2026-11-21: "this is an issue that we can use to make commis daily sheets better moving
 * forward." The issue: RIZAL-COMMIS carried TOASTED MAMON @15 at ~324 → 891 for days, then a single
 * cell took it to 19,723 and then 20,691 — twenty times the item's own scale — and nothing in the
 * sheet objected. The figure propagated into deliveries, receivings, three weeks of book balance and
 * a second product variant before a human noticed.
 *
 * The rule under test (dl_commissaryQuantityPlausibilityGuard):
 *   baseline = the highest value this item held at this hub in that column in the trailing 30 days,
 *              excluding the date being written;
 *   refuse when new > max(baseline * 10, baseline + 1000);
 *   say nothing when there is no history, when the baseline is under 10 units, or when a written
 *   reason was supplied.
 *
 *   AC1  an in-scale value passes untouched (the guard must not become a nuisance).
 *   AC2  a 20x value is refused, and the message names the baseline and the multiple.
 *   AC3  the same value WITH a reason passes — a deliberate out-of-scale entry stays possible.
 *   AC4  a brand-new item (no history) is never blocked, however large its first entry.
 *   AC5  an item whose own scale is small is not blocked by the floor.
 *   AC6  the guard is wired into the writer, not merely callable: the delta choke point refuses it.
 *
 * Fixtures: branch 99895 (commissary) with product 99895, dates 2035-09-01..03; removed in finally.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-quantity-plausibility', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$tenantId = 207;
$branchId = 99895;
$productId = 99895;
$day1 = '2035-09-01';
$day2 = '2035-09-02';
$day3 = '2035-09-03';

app()->tenant()->setTenantId($tenantId);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$cleanup = static function () use ($db, $branchId, $productId): void {
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_branches WHERE id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_products WHERE id = :p')->execute([':p' => $productId]);
};

$addRow = static function (string $ledgerDate, string $shift, int $beg, int $produced, int $dispatched) use ($db, $branchId, $productId): void {
    $db->prepare(
        'INSERT INTO dl_commissary_product_ledger
            (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty)
         VALUES (:cb, :p, :d, :s, :beg, :prod, :disp, 0)
         ON DUPLICATE KEY UPDATE beg_qty = VALUES(beg_qty), produced_qty = VALUES(produced_qty),
             dispatched_qty = VALUES(dispatched_qty)'
    )->execute([
        ':cb' => $branchId, ':p' => $productId, ':d' => $ledgerDate,
        ':s' => $shift, ':beg' => $beg, ':prod' => $produced, ':disp' => $dispatched,
    ]);
};

$throwsWith = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};

try {
    $cleanup();

    // The hub and the item have to exist: dl_commissary_product_ledger carries a foreign key to
    // dl_branches, so a fixture row without them aborts the suite before any assertion runs.
    $db->prepare('INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
                  VALUES (?,?,?,?, "self_managed", 1, 1)')
        ->execute([$branchId, 'PLQ-TEST', 'Plausibility Commissary', 'Test']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?,?,?,15,0,1)')
        ->execute([$productId, 'PLQ-A', 'TOASTED MAMON @15']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')
        ->execute([$branchId, $productId]);

    // A hub that already knows this item: the baseline is 800.
    $addRow($day1, 'AM', 0, 800, 0);
    $addRow($day2, 'AM', 800, 700, 600);

    // AC1 — in scale, no complaint.
    $inScale = $throwsWith(static function () use ($db, $branchId, $productId, $day3): void {
        dl_commissaryQuantityPlausibilityGuard($db, $branchId, $productId, $day3, ['produced_qty' => 900]);
    });
    $h->test('an in-scale entry passes untouched', $inScale === null, (string)$inScale);

    // AC2 — 20,691 against a baseline of 800 is the measured defect; it must be refused and named.
    $outOfScale = $throwsWith(static function () use ($db, $branchId, $productId, $day3): void {
        dl_commissaryQuantityPlausibilityGuard($db, $branchId, $productId, $day3, ['produced_qty' => 20691]);
    });
    $h->test(
        'a 20x entry is refused, and the message names the baseline',
        is_string($outOfScale) && str_contains($outOfScale, '800') && str_contains($outOfScale, '20691'),
        (string)$outOfScale
    );

    // AC3 — the same number with a written reason is a decision, not an accident.
    $withReason = $throwsWith(static function () use ($db, $branchId, $productId, $day3): void {
        dl_commissaryQuantityPlausibilityGuard($db, $branchId, $productId, $day3, ['produced_qty' => 20691], 'bulk batch for the fiesta order');
    });
    $h->test('the same value passes when a reason is supplied', $withReason === null, (string)$withReason);

    // AC4 — a new item has no scale, so nothing can be out of scale.
    $noHistory = $throwsWith(static function () use ($db, $branchId, $productId): void {
        dl_commissaryQuantityPlausibilityGuard($db, $branchId, $productId, '2035-12-24', ['produced_qty' => 50000]);
    });
    $h->test('a brand-new item is never blocked by its own absence of history', $noHistory === null, (string)$noHistory);

    // AC5 — the floor: baseline 8 is below the guard's minimum scale, so 400 must pass.
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND product_id = :p')
        ->execute([':b' => $branchId, ':p' => $productId]);
    $addRow($day1, 'AM', 0, 8, 0);
    $smallScale = $throwsWith(static function () use ($db, $branchId, $productId, $day2): void {
        dl_commissaryQuantityPlausibilityGuard($db, $branchId, $productId, $day2, ['produced_qty' => 400]);
    });
    $h->test('an item whose own scale is tiny is not blocked', $smallScale === null, (string)$smallScale);

    // AC6 — wired, not just available: the writer every path funnels through must refuse it.
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND product_id = :p')
        ->execute([':b' => $branchId, ':p' => $productId]);
    $addRow($day1, 'AM', 0, 800, 0);
    $addRow($day2, 'AM', 800, 700, 600);
    $wired = $throwsWith(static function () use ($db, $branchId, $productId, $day3): void {
        dl_applyCommissaryProductLedgerDelta($db, $branchId, $productId, $day3, 20691, 0, 1, 0, false, 'AM');
    });
    $after = $db->prepare('SELECT produced_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d');
    $after->execute([':b' => $branchId, ':p' => $productId, ':d' => $day3]);
    $stored = (int)($after->fetchColumn() ?: 0);
    $h->test(
        'the writer itself refuses the out-of-scale delta, and stores nothing',
        is_string($wired) && str_contains($wired, 'last 30 days') && $stored === 0,
        json_encode(['error' => $wired, 'stored' => $stored])
    );

    // AC7 — the beginning rule. The mamon chain started with beg_qty = 15,075 stored with reason
    // NULL on an item that had carried nothing; an opening every later balance inherits.
    $h->test(
        'a first-time large beginning needs a reason (measured: the 15,075 mamon opening)',
        dl_commissaryBeginningNeedsReason($db, $branchId, $productId, $day3, 15075) === true,
        (string)dl_commissaryBeginningNeedsReason($db, $branchId, $productId, $day3, 15075)
    );
    $h->test(
        'a small first beginning, and a beginning in line with the item, need nothing',
        dl_commissaryBeginningNeedsReason($db, $branchId, $productId, $day3, 400) === false
            && dl_commissaryBeginningNeedsReason($db, $branchId, $productId, $day3, 900) === false,
        json_encode([
            'small_first' => dl_commissaryBeginningNeedsReason($db, $branchId, $productId, $day3, 400),
            'in_scale' => dl_commissaryBeginningNeedsReason($db, $branchId, $productId, $day3, 900),
        ])
    );

    // AC8 — "allowable a negative/variance when total of deliveries is more than the declared
    // stocks on hand": the dispatch is NOT refused, and it leaves a durable finding behind.
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND product_id = :p')
        ->execute([':b' => $branchId, ':p' => $productId]);
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE aggregate_key LIKE :k')
        ->execute([':k' => 'commissary-over-dispatch-' . $branchId . '-%']);
    $addRow($day1, 'AM', 5, 0, 0);   // holds 5

    $refused = null;
    try {
        dl_applyCommissaryProductLedgerDelta($db, $branchId, $productId, $day1, 0, 50, 1, 0, false, 'AM');
    } catch (\Throwable $e) {
        $refused = $e->getMessage();
    }
    $afterDispatch = $db->prepare('SELECT dispatched_qty, (beg_qty + produced_qty - dispatched_qty - wastage_qty) AS bal
                                     FROM dl_commissary_product_ledger
                                    WHERE commissary_branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "AM"');
    $afterDispatch->execute([':b' => $branchId, ':p' => $productId, ':d' => $day1]);
    $state = $afterDispatch->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'a dispatch beyond stock is ALLOWED and the balance goes negative (a dispatch is a fact)',
        $refused === null && (int)($state['dispatched_qty'] ?? 0) === 50 && (int)($state['bal'] ?? 0) === -45,
        json_encode(['refused' => $refused, 'state' => $state])
    );

    $findingStmt = $db->prepare('SELECT finding_type, title, detail, finding_count FROM dl_integrity_notifications
                                  WHERE aggregate_key = :k');
    $findingStmt->execute([':k' => 'commissary-over-dispatch-' . $branchId . '-' . $productId . '-' . $day1]);
    $finding = $findingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'it leaves a durable finding naming both numbers, so the variance cannot go unseen',
        ($finding['finding_type'] ?? '') === 'variance'
            && str_contains((string)($finding['title'] ?? ''), 'dispatched 50')
            && str_contains((string)($finding['title'] ?? ''), 'only 5 on hand')
            && str_contains((string)($finding['detail'] ?? ''), 'a dispatch is a fact'),
        json_encode($finding)
    );

    // A second over-dispatch on the same item/day collapses into the same finding and counts.
    try {
        dl_applyCommissaryProductLedgerDelta($db, $branchId, $productId, $day1, 0, 5, 1, 0, false, 'AM');
    } catch (\Throwable $e) {
        // already-negative balances are allowed too; nothing to assert on the throw
    }
    $findingStmt->execute([':k' => 'commissary-over-dispatch-' . $branchId . '-' . $productId . '-' . $day1]);
    $finding2 = $findingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $countStmt = $db->prepare('SELECT COUNT(*) FROM dl_integrity_notifications WHERE aggregate_key LIKE :k');
    $countStmt->execute([':k' => 'commissary-over-dispatch-' . $branchId . '-' . $productId . '-%']);
    $rowCount = (int)$countStmt->fetchColumn();
    $h->test(
        'a repeat over-dispatch increments the same finding instead of adding a row',
        (int)($finding2['finding_count'] ?? 0) === 2 && $rowCount === 1,
        json_encode(['finding_count' => $finding2['finding_count'] ?? null, 'rows' => $rowCount])
    );

    // AC9 — the per-row flag the sheet renders ("so daily sheets per row can capture that").
    $cases = [
        'shipped within stock' => [100, 50, 0, 120, false, 0],
        'shipped exactly what it held' => [100, 50, 0, 150, false, 0],
        'shipped past its stock' => [100, 50, 0, 170, true, 20],
        'carried a deficit but shipped nothing' => [-3145, 0, 85, 0, false, 0],
        'shipped anything while already short' => [-100, 0, 0, 5, true, 5],
    ];
    $wrong = [];
    foreach ($cases as $label => [$beg, $addtl, $waste, $dispatched, $expectOver, $expectExcess]) {
        $got = dl_sheetOverDispatch($beg, $addtl, $waste, $dispatched);
        if ($got['over'] !== $expectOver || (int)$got['excess'] !== $expectExcess) {
            $wrong[$label] = $got;
        }
    }
    $h->test(
        'the per-row over-dispatch flag is right in all five shapes, including "shipped nothing"',
        $wrong === [],
        json_encode($wrong)
    );
} finally {
    $cleanup();
}

$h->done();
