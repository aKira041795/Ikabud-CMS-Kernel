<?php

declare(strict_types=1);

/**
 * Daily Ledger — box deduction (packaging follows the sale).
 *
 * OWNER DIRECTION 2026-11-19: "at products, a new view for box mapping ... assigned to a product so
 * that when a qty shows how many were sold in a shift, the box mapped is also auto deducted at the
 * branch." Boxes belong to BRANCHES, so the deduction lands on the branch ledger.
 *
 * THE TEST THAT MATTERS MOST is #2: the cashier sheet saves per cell, on blur, and again on every
 * correction. If the deduction incremented, 12 cakes would become 12 boxes, then 24, then 36, for one
 * day's baking. Everything else here is secondary to "a re-save changes nothing".
 *
 * Each assertion names what breaks if it regresses:
 * - re-save not idempotent = boxes multiply with every cell edit;
 * - a correction not reversing = the box can only ever go down, and a mistyped sale becomes permanent;
 * - clamp on a short box = a shortage is hidden behind a balance that cannot be true (and the sale is
 *   blocked, or the box quietly stops at zero);
 * - a closed day or frozen variance accepting a deduction = settled history is rewritten by a later
 *   save, which is the failure class of 2026-11-16;
 * - ceil instead of round = 13 cakes with 12 per box would claim one box instead of two.
 *
 * Fixtures: products 99801-99803, branch 998 branch id taken from dl_branches, ledger rows dated
 * 2020-01-01 (no real data), all removed in finally.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-box-deduction', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$db = app()->db();
$passed = 0;
$failed = 0;

// dl_auditLog() returns early when module() is null, so in CLI the audit assertions would pass
// vacuously if the context were never established. This makes them real.
$ctx = module('daily-ledger');
if ($ctx) {
    kernel_request_context_set('_activeModuleContext', $ctx);
    app()->setActiveModule('daily-ledger');
    \Ikabud\Kernel\Database\KernelPDO::setActiveModule('daily-ledger');
}

function chk(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$date = '2020-01-01';
$branchId = (int)$db->query('SELECT id FROM dl_branches ORDER BY id LIMIT 1')->fetchColumn();
$catId = (int)$db->query('SELECT id FROM dl_product_categories ORDER BY id LIMIT 1')->fetchColumn();

$cakeId = 99801;
$boxId = 99802;
$plainId = 99803;

$cleanup = function () use ($db, $branchId, $date, $cakeId, $boxId, $plainId): void {
    $db->exec("DELETE FROM dl_daily_ledger WHERE ledger_date = '{$date}' AND product_id IN ({$cakeId},{$boxId},{$plainId})");
    $db->exec("DELETE FROM dl_ledger_day_status WHERE ledger_date = '{$date}' AND branch_id = {$branchId}");
    $db->exec("DELETE FROM dl_products WHERE id IN ({$cakeId},{$boxId},{$plainId})");
    $db->exec("DELETE FROM audit_logs WHERE action IN ('packaging_auto_deducted','packaging_shortfall')"
        . " AND entity_id IS NOT NULL AND action IN ('packaging_auto_deducted','packaging_shortfall')"
        . " AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
};
$cleanup();

$insP = $db->prepare(
    'INSERT INTO dl_products (id, name, sku, category_id, product_category, is_active, is_box, box_product_id, units_per_box, sort_order)
     VALUES (:id, :name, :sku, :cat, :pcat, 1, :isbox, :boxid, :units, 0)'
);
$insP->execute([':id' => $cakeId, ':name' => 'TEST CAKE', ':sku' => 'TC99801', ':cat' => $catId, ':pcat' => 'cake', ':isbox' => 0, ':boxid' => $boxId, ':units' => 1]);
$insP->execute([':id' => $boxId, ':name' => 'TEST BOX', ':sku' => 'TB99802', ':cat' => $catId, ':pcat' => 'other', ':isbox' => 1, ':boxid' => null, ':units' => 1]);
$insP->execute([':id' => $plainId, ':name' => 'TEST NO BOX', ':sku' => 'TN99803', ':cat' => $catId, ':pcat' => 'bread', ':isbox' => 0, ':boxid' => null, ':units' => 1]);

$insL = $db->prepare(
    'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, addtl, withdraw, bal_end, sales, packaging_applied)
     VALUES (:bid, :pid, :d, :shift, :beg, :addtl, :w, :end, :sales, 0)'
);
$cakeRow = function (int $beg, int $end) use ($insL, $branchId, $date, $cakeId): void {
    $insL->execute([
        ':bid' => $branchId, ':pid' => $cakeId, ':d' => $date, ':shift' => 'AM',
        ':beg' => $beg, ':addtl' => 0, ':w' => 0, ':end' => $end, ':sales' => null,
    ]);
};
$readBox = function () use ($db, $branchId, $date, $boxId): array {
    $s = $db->prepare('SELECT beg_bal, addtl, withdraw, bal_end, sales, packaging_applied FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = :s LIMIT 1');
    $s->execute([':b' => $branchId, ':p' => $boxId, ':d' => $date, ':s' => 'AM']);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
};
$auditCount = function (string $action) use ($db): int {
    $s = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = :a AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $s->execute([':a' => $action]);
    return (int)$s->fetchColumn();
};
// Mirrors the hook in dl_recomputeSales without needing module() context.
$applySales = function (int $sales) use ($db, $branchId, $date, $cakeId): array {
    return dl_applyPackagingForSale($db, $branchId, $cakeId, $date, 'AM', 0, $sales);
};

try {
    echo "box deduction — derived, never incremented\n";

    // ── 1. first save: 12 cakes, 1 box each ───────────────────────────────────────────────
    $cakeRow(100, 88);   // beg 100, end 88 -> sales 12
    $r = $applySales(12);
    $box = $readBox();
    chk('12 cakes deduct 12 boxes', (int)($box['withdraw'] ?? -1) === 12, json_encode($box));
    chk('packaging_applied records what was deducted', (int)($box['packaging_applied'] ?? -1) === 12);
    chk('the deduction is reported as applied', ($r['applied'] ?? false) === true && (int)$r['usage'] === 12);
    chk('one packaging_auto_deducted audit row', $auditCount('packaging_auto_deducted') === 1);

    // ── 2. THE ONE THAT MATTERS: re-saving must change nothing ────────────────────────────
    $before = $readBox();
    $r = $applySales(12);
    $after = $readBox();
    chk(
        'a re-save is idempotent (boxes are not multiplied by repeated saves)',
        $before === $after && ($r['reason'] ?? '') === 'up_to_date',
        'before=' . json_encode($before) . ' after=' . json_encode($after)
    );
    chk('still exactly one audit row after the re-save', $auditCount('packaging_auto_deducted') === 1);

    // ── 3. a corrected sale corrects the box by exactly the correction ────────────────────
    $r = $applySales(5);
    $box = $readBox();
    chk('correcting 12 -> 5 gives 7 boxes back', (int)$box['withdraw'] === 5 && (int)$r['delta'] === -7, json_encode($box));

    // ── 4. removing the sale gives every box back ─────────────────────────────────────────
    $r = $applySales(0);
    $box = $readBox();
    chk('zero sales leave zero boxes deducted', (int)$box['withdraw'] === 0 && (int)$box['packaging_applied'] === 0, json_encode($box));

    // ── 5. ceil, not round: a pack of twelve ──────────────────────────────────────────────
    $db->exec("UPDATE dl_products SET units_per_box = 12 WHERE id = {$cakeId}");
    $applySales(13);
    $box = $readBox();
    chk('13 cakes at 12 per box deduct 2 boxes (ceil, the second is opened)', (int)$box['withdraw'] === 2, json_encode($box));
    $db->exec("UPDATE dl_products SET units_per_box = 1 WHERE id = {$cakeId}");

    // ── 6. a short box goes negative and is NAMED, never clamped ──────────────────────────
    $applySales(0);                       // reset the box row to 0
    $db->exec("UPDATE dl_daily_ledger SET beg_bal = 0, addtl = 0, bal_end = NULL WHERE branch_id = {$branchId} AND product_id = {$boxId} AND ledger_date = '{$date}' AND shift = 'AM'");
    $shortfallsBefore = $auditCount('packaging_shortfall');
    $r = $applySales(5);
    $box = $readBox();
    chk(
        'a box the branch never received goes negative, not clamped',
        (int)$box['withdraw'] === 5 && ($tot = ((int)$box['beg_bal'] + (int)$box['addtl'] - (int)$box['withdraw'])) < 0,
        json_encode($box)
    );
    chk('the shortfall is named in an audit event', $auditCount('packaging_shortfall') === $shortfallsBefore + 1);
    chk('the shortfall reports the short units', (int)($r['missing'] ?? 0) === 5, 'missing=' . ($r['missing'] ?? 'none'));

    // ── 7. a closed day is settled history ────────────────────────────────────────────────
    $applySales(0);
    $before = $readBox();
    $db->exec("INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES ({$branchId}, '{$date}', 'closed')");
    $r = $applySales(9);
    $after = $readBox();
    chk('a closed day refuses the deduction', ($r['reason'] ?? '') === 'day_closed', (string)($r['reason'] ?? ''));
    chk('a closed day leaves the box row untouched', $before === $after);
    $db->exec("DELETE FROM dl_ledger_day_status WHERE branch_id = {$branchId} AND ledger_date = '{$date}'");

    // ── 8. no mapping, no deduction, no history edit ──────────────────────────────────────
    $r = dl_applyPackagingForSale($db, $branchId, $plainId, $date, 'AM', 0, 7);
    chk('a product with no box mapping deducts nothing', ($r['reason'] ?? '') === 'no_mapping', (string)($r['reason'] ?? ''));

    // ── 9. the box row's own sales stay consistent with its own columns ───────────────────
    $applySales(0);
    $db->exec("UPDATE dl_daily_ledger SET bal_end = 3 WHERE branch_id = {$branchId} AND product_id = {$boxId} AND ledger_date = '{$date}' AND shift = 'AM'");
    $applySales(4);
    $box = $readBox();
    // dl_computeSalesValue() clamps at 0 by design, so the expected figure is the clamped one.
    $expectedSales = max(0, (int)$box['beg_bal'] + (int)$box['addtl'] - (int)$box['withdraw'] - (int)$box['bal_end']);
    chk(
        'the box row sales figure is recomputed after its withdraw moves',
        $box['sales'] !== null && (int)$box['sales'] === $expectedSales,
        json_encode($box) . ' expected=' . $expectedSales
    );

    // ── 10. OWNER SCENARIO: box qty was never updated, a mapped cake still sells ──────────
    // "what happens if qty of boxes was not updated and there's a sale of a cake mapped to the box?"
    // -> the sale is ALLOWED, the box goes negative, and the shortage is named. Never blocked.
    $db->exec("DELETE FROM dl_daily_ledger WHERE branch_id = {$branchId} AND product_id = {$boxId} AND ledger_date = '{$date}'");
    $db->exec("DELETE FROM dl_daily_ledger WHERE branch_id = {$branchId} AND product_id = {$plainId} AND ledger_date = '{$date}'");
    $db->exec("UPDATE dl_daily_ledger SET beg_bal = 50, bal_end = NULL, withdraw = 0 WHERE branch_id = {$branchId} AND product_id = {$cakeId} AND ledger_date = '{$date}' AND shift = 'AM'");
    $shortBefore = $auditCount('packaging_shortfall');
    $r = $applySales(9);                       // 9 cakes sold, box never received
    $box = $readBox();
    chk(
        'a sale still goes through when the box quantity was never updated (not blocked)',
        ($r['applied'] ?? false) === true,
        json_encode($r)
    );
    chk(
        'the box row is created and carries the deduction',
        (int)($box['withdraw'] ?? 0) === 9 && (int)($box['packaging_applied'] ?? 0) === 9,
        json_encode($box)
    );
    chk('the shortage is named rather than stopping the sale', $auditCount('packaging_shortfall') === $shortBefore + 1);

    // ── 11. a box row that is CREATED must carry the previous shift's stock, not 0 ────────
    // Otherwise a box holding 40 from yesterday would appear short the moment a cake sells today.
    $db->exec("DELETE FROM dl_daily_ledger WHERE branch_id = {$branchId} AND product_id = {$boxId} AND ledger_date = '{$date}'");
    $prevDate = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    $db->exec("DELETE FROM dl_daily_ledger WHERE branch_id = {$branchId} AND product_id = {$boxId} AND ledger_date = '{$prevDate}'");
    $db->prepare(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, addtl, withdraw, bal_end, sales, packaging_applied)
         VALUES (:b, :p, :d, \'PM\', 40, 0, 0, 40, NULL, 0)'
    )->execute([':b' => $branchId, ':p' => $boxId, ':d' => $prevDate]);
    $db->exec("UPDATE dl_daily_ledger SET withdraw = 0, packaging_applied = 0 WHERE branch_id = {$branchId} AND product_id = {$cakeId} AND ledger_date = '{$date}' AND shift = 'AM'");
    $shortBefore2 = $auditCount('packaging_shortfall');
    $r = $applySales(4);                       // 4 cakes, box carried 40 from yesterday
    $box = $readBox();
    chk(
        'a created box row opens at the carried stock, not at zero',
        (int)($box['beg_bal'] ?? -1) === 40,
        json_encode($box)
    );
    chk(
        'no false shortfall when the box actually had stock',
        $auditCount('packaging_shortfall') === $shortBefore2,
        json_encode($box)
    );
    $db->exec("DELETE FROM dl_daily_ledger WHERE branch_id = {$branchId} AND product_id = {$boxId} AND ledger_date = '{$prevDate}'");
} finally {
    $cleanup();
}

$total = $passed + $failed;
echo "\n  {$passed}/{$total} passed\n";
$h->done($failed === 0);
exit($failed === 0 ? 0 : 1);
