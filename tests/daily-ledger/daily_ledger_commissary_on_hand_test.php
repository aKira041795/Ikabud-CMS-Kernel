<?php

declare(strict_types=1);

/**
 * Daily Ledger — commissary STOCK ON HAND is the carried balance, never a sum of flows
 *
 * Client concern 2026-11-17: "commissary rizal ang actual beginning nila sa B.FOREST@430 is 2 pero
 * inig mg dr na sila 6 ilang onhand" — beginning 2, the DR screen showed 6 on hand.
 *
 * Root cause: `dl_commissary_product_ledger.remaining_qty` is a GENERATED column equal to
 * `produced_qty - dispatched_qty - wastage_qty` (migrations 036/037). It excludes `beg_qty`, and
 * every later row's `beg_qty` already carries the previous row's balance — so summing it across
 * history reports net production surplus (a FLOW) under the label "on hand" (a STOCK). Measured on
 * RIZAL-COMMIS · B.FOREST @430: 87 rows summed to 6 while the stock held was 4.
 *
 *   AC1  stock that exists ONLY as a carried beginning is on hand (the flow says 0).
 *   AC2  the balance is never counted once per shift (two shifts carry the same balance).
 *   AC3  a physically counted ending wins over the book balance.
 *   AC4  on hand as of a date ignores later rows.
 *   AC5  a product with stock but negative flow is still offered; zero stock is not.
 *   AC6  the figure that is ENFORCED is the figure that is OFFERED (one definition).
 *
 * Fixtures: branch 99896 (commissary) with products 99896/99897, date 2035-09-01; removed in finally.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-commissary-on-hand', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$tenantId = 207;
$branchId = 99896;
$productA = 99896;
$productB = 99897;
$date = '2035-09-01';
$laterDate = '2035-09-02';

app()->tenant()->setTenantId($tenantId);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$cleanup = static function () use ($db, $branchId, $productA, $productB): void {
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_branches WHERE id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_products WHERE id IN (:a, :b)')->execute([':a' => $productA, ':b' => $productB]);
};

$addRow = static function (int $productId, string $ledgerDate, string $shift, int $beg, int $produced, int $dispatched, int $wastage, ?int $actualEnd = null) use ($db, $branchId): void {
    $db->prepare(
        'INSERT INTO dl_commissary_product_ledger
            (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty, end_source)
         VALUES (:cb, :p, :d, :s, :beg, :prod, :disp, :waste, :actual, :src)
         ON DUPLICATE KEY UPDATE beg_qty = VALUES(beg_qty), produced_qty = VALUES(produced_qty),
             dispatched_qty = VALUES(dispatched_qty), wastage_qty = VALUES(wastage_qty),
             actual_end_qty = VALUES(actual_end_qty), end_source = VALUES(end_source)'
    )->execute([
        ':cb' => $branchId, ':p' => $productId, ':d' => $ledgerDate, ':s' => $shift,
        ':beg' => $beg, ':prod' => $produced, ':disp' => $dispatched, ':waste' => $wastage,
        ':actual' => $actualEnd, ':src' => $actualEnd === null ? null : 'counted',
    ]);
};

$flowSum = static function (int $productId, ?string $asOf = null) use ($db, $branchId, $date): int {
    $sql = 'SELECT COALESCE(SUM(remaining_qty),0) FROM dl_commissary_product_ledger
             WHERE commissary_branch_id = :b AND product_id = :p';
    $bind = [':b' => $branchId, ':p' => $productId];
    if ($asOf !== null) { $sql .= ' AND ledger_date <= :d'; $bind[':d'] = $asOf; }
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    return (int)$stmt->fetchColumn();
};

$cleanup();

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
                  VALUES (?,?,?,?, "self_managed", 1, 1)')
        ->execute([$branchId, 'OH-TEST', 'On Hand Commissary', 'Test']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?,?,?,430,0,1)')
        ->execute([$productA, 'OH-A', 'B.FOREST @430']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?,?,?,100,0,1)')
        ->execute([$productB, 'OH-B', 'HIDDEN CAKE @100']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')->execute([$branchId, $productA]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')->execute([$branchId, $productB]);

    // ─── AC1 — stock held PURELY as a carried beginning ───────────────────
    $h->section('AC1 — a carried beginning is stock, even with no movement');

    $addRow($productA, $date, 'AM', 2, 0, 0, 0);
    $addRow($productA, $date, 'PM', 2, 0, 0, 0);

    $h->test('the FLOW column sums to 0 for a product holding 2', $flowSum($productA) === 0, (string)$flowSum($productA));
    $onHand = dl_commissaryOnHandForProduct($db, $branchId, $productA, $date);
    $h->test('stock on hand is the carried beginning (2)', $onHand['on_hand'] === 2, (string)$onHand['on_hand']);
    $h->test('on hand names the row it came from', $onHand['as_of_date'] === $date && $onHand['as_of_shift'] === 'PM', json_encode($onHand));

    // ─── AC2 — the balance is not counted once per shift ─────────────────
    $h->section('AC2 — two shifts carrying the same balance is still one stock');

    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b')->execute([':b' => $branchId]);
    $addRow($productA, $date, 'AM', 2, 4, 0, 0);   // balance 6
    $addRow($productA, $date, 'PM', 6, 0, 0, 0);   // carries 6, no movement
    $onHand = dl_commissaryOnHandForProduct($db, $branchId, $productA, $date);
    $h->test('stock is 6, not 2 + 6 + 4 summed across shifts', $onHand['on_hand'] === 6, (string)$onHand['on_hand']);
    // The OLD prefill added beg+produced-dispatched across shifts: 2+4 + 6 = 12.
    $h->test('the cross-shift sum the prefill used would have said 12', (2 + 4 - 0) + (6 + 0 - 0) === 12);

    // ─── AC3 — a physically counted ending wins ──────────────────────────
    $h->section('AC3 — the count wins over the book balance');

    $addRow($productA, $date, 'PM', 6, 0, 0, 0, 3);
    $counted = dl_commissaryOnHandForProduct($db, $branchId, $productA, $date);
    $h->test('a settled shift reports the COUNTED stock (3), not the book (6)', $counted['on_hand'] === 3, (string)$counted['on_hand']);
    $h->test('the row reports that it was settled', $counted['settled'] === true);

    // ─── AC4 — as-of date ────────────────────────────────────────────────
    $h->section('AC4 — on hand as of a date ignores later rows');

    $addRow($productA, $laterDate, 'AM', 3, 0, 0, 0);
    $h->test('as of the earlier date the stock is still 3', dl_commissaryOnHandForProduct($db, $branchId, $productA, $date)['on_hand'] === 3);
    $h->test('as of the later date it follows the ledger', dl_commissaryOnHandForProduct($db, $branchId, $productA, $laterDate)['on_hand'] === 3);
    $addRow($productA, $laterDate, 'AM', 3, 2, 0, 0);
    $h->test('later production is visible on the later date only', dl_commissaryOnHandForProduct($db, $branchId, $productA, $laterDate)['on_hand'] === 5);

    // ─── AC5 — what the dispatch table offers ────────────────────────────
    $h->section('AC5 — offer stock, not net flow');

    // Product B: produced 5 once, dispatched 12 over time -> flow -7, but the carried balance
    // still holds 3 (the BDAY CAKE case). Product C-like case: balance 0 must NOT be offered.
    $addRow($productB, $date, 'AM', 10, 5, 12, 0);   // balance 3, flow -7
    $matrix = dl_commissaryOnHandByProduct($db, [$branchId], $date)[$branchId] ?? [];
    $h->test('the negative-flow product still reports its stock', ($matrix[$productB]['on_hand'] ?? null) === 3, json_encode($matrix[$productB] ?? null));
    $h->test('its flow really is negative', $flowSum($productB, $date) === -7, (string)$flowSum($productB, $date));

    $handlers = php_strip_whitespace($base . '/modules/daily-ledger/handlers.php');
    $h->test('the dispatch table no longer gates on the flow column', !str_contains($handlers, 'HAVING cumulative_remaining > 0'));
    $h->test('the dispatch table no longer sums the flow column as on-hand', !str_contains($handlers, 'SUM(cpl.remaining_qty) AS cumulative_remaining'));
    $h->test('no screen sums remaining_qty as a cumulative stock any more', !str_contains($handlers, 'SUM(remaining_qty) AS cumulative_remaining'));

    // ─── AC6 — offered == enforced ───────────────────────────────────────
    $h->section('AC6 — the figure shown is the figure enforced');

    $h->test('the dispatch stock check uses the one definition', str_contains($handlers, 'dl_commissaryOnHandForProduct($db, $cid, $pid)'));
    $h->test('the definition is written once', substr_count($handlers, 'function dl_commissaryOnHandByProduct(') === 1);
    $h->test('the single-product reader delegates to it', str_contains($handlers, 'dl_commissaryOnHandByProduct($db, [$commissaryId], $asOfDate)'));
} finally {
    $cleanup();
}

$h->done();
