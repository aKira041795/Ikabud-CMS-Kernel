<?php

declare(strict_types=1);

/**
 * Daily Ledger — a withdrawal must recompute the sales cell
 *
 * `sales` is DERIVED, never authoritative: sales = beg_bal + addtl - withdraw -
 * bal_end, and NULL while no ending is recorded (pending, not zero). Reports and
 * dashboards derive it themselves, so they were always right — which is exactly what
 * disguised the bug below.
 *
 * The cashier withdrawal handlers wrote `dl_daily_ledger.addtl`/`.withdraw` and then
 * recomputed only the VARIANCE FLAGS. Nothing recomputed the stored `sales` column,
 * so it kept whatever it held when the ending was set — computed while withdraw was
 * still 0 — and afterwards disagreed with the three columns printed beside it.
 *
 * Found on live data (2026-09-18 AM, branch 8): 7 of 173 rows were stale by exactly
 * the withdrawal amount, e.g. BBS-0110 addtl=340 withdraw=71 bal_end=30 stored
 * sales=310 (= 340-30, withdraw omitted) where the invariant gives 239. The
 * withdrawal row's created_at and the ledger row's updated_at were the SAME second,
 * proving the withdrawal path wrote the ledger without recomputing sales.
 *
 * Two write sites had the gap, plus the offline parity path:
 *   handlers.php            apiSaveCashierWithdrawals  (create — withdraw AND addtl)
 *   handlers.php            apiUpdateCashierWithdrawal (edit)
 *   handlers-offline.php    dl_offlineApplyWithdrawal  (device replay)
 *
 * These tests drive the real worker for behaviour and assert on the online handlers'
 * source, because those end in $ctx->json() and cannot be invoked in-process (the
 * same split the addtl-correction suite uses).
 *
 * Integration mode — isolated fixtures on branch 99072, full cleanup.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-withdrawal-recomputes-sales', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('modules/daily-ledger/helpers.php');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-offline.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$dlCtx = modulePushContext('daily-ledger');
if (!$dlCtx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}

$db = $dlCtx->db();

$branchId = 99072;
$productId = 99072;
$date = '2030-03-16';
$shift = 'AM';

$teardown = static function () use ($db, $branchId, $productId): void {
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
};
$teardown();

$db->execute(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, :mode, 0, 1)',
    [':id' => $branchId, ':code' => 'T-SALESREC', ':name' => 'Sales Recompute Branch', ':addr' => 'Test', ':mode' => 'self_managed']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
     VALUES (:id, :sku, :name, 25.0, 0, 1)',
    [':id' => $productId, ':sku' => 'SALESREC-TEST', ':name' => 'Sales Recompute Product']
);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $branchId, ':p' => $productId]);

$admin = ['id' => 999999, 'role' => 'admin', 'source' => 'daily-ledger'];

/** Absolute values so the expected sales is arithmetic, not another query. */
$seedLedger = static function (int $addtl, int $withdraw, ?int $balEnd, ?int $sales) use ($db, $branchId, $productId, $date, $shift): void {
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p', [':b' => $branchId, ':p' => $productId]);
    $db->execute(
        'INSERT INTO dl_daily_ledger
            (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales, encoded_by, updated_by)
         VALUES (:b, :p, :d, :s, 25.0, 0, :addtl, :withdraw, :bal_end, :sales, 999999, 999999)',
        [
            ':b' => $branchId, ':p' => $productId, ':d' => $date, ':s' => $shift,
            ':addtl' => $addtl, ':withdraw' => $withdraw, ':bal_end' => $balEnd, ':sales' => $sales,
        ]
    );
};

/** Drive one line through the real device-replay worker. */
$apply = static function (array $header, int $qty) use ($admin, $branchId, $date, $productId, $shift): array {
    try {
        $res = dl_offlineApplyWithdrawal($admin, [
            'type' => 'withdrawal',
            'payload' => [
                'branch_id' => $branchId,
                'date' => $date,
                'shift' => $shift,
                'header' => $header,
                'lines' => [['product_id' => $productId, 'quantity' => $qty, 'unit' => 'pcs']],
            ],
        ]);
        return ['ok' => !empty($res['ok']), 'error' => '', 'totals' => (array)($res['totals'] ?? [])];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'totals' => []];
    }
};

$row = static fn (): array => (array)$db->query(
    "SELECT beg_bal, addtl, withdraw, bal_end, sales FROM dl_daily_ledger
      WHERE branch_id = {$branchId} AND product_id = {$productId} AND ledger_date = '{$date}' AND shift = '{$shift}'"
)->fetch(PDO::FETCH_ASSOC);

/** What the invariant says the cell should read. */
$expected = static function (array $r): ?int {
    if ($r['bal_end'] === null) {
        return null;
    }
    return max(0, (int)$r['beg_bal'] + (int)$r['addtl'] - (int)$r['withdraw'] - (int)$r['bal_end']);
};

// ─── The live failure, replayed ────────────────────────────────────────
$h->section('The Sep 18 failure: ending set first, withdrawal afterwards');

// Ending recorded while withdraw was 0 => sales = 100 - 40 = 60, exactly the state
// the live rows were frozen in.
$seedLedger(100, 0, 40, 60);
$before = $row();
$h->test('fixture starts consistent (sales 60 = 100 - 0 - 40)', (int)$before['sales'] === 60, json_encode($before));

$charge = $apply(['withdrawal_type' => 'charge', 'reason_code' => 'manual_adjustment'], 25);
$h->test('the withdrawal is accepted', $charge['ok'], $charge['error']);
$after = $row();
$h->test('withdraw moved by the line quantity', (int)$after['withdraw'] === 25, json_encode($after));
$h->test(
    'the stored sales followed the withdrawal instead of going stale',
    (int)$after['sales'] === 35,
    'sales=' . $after['sales'] . ', expected 35 (this is the Sep 18 value that stayed at 60)'
);
$h->test('stored sales equals the invariant', (int)$after['sales'] === $expected($after), 'stored=' . $after['sales'] . ' invariant=' . $expected($after));

// ─── Add Stock moves the same cell ─────────────────────────────────────
$h->section('Add Stock feeds the same invariant');

$add = $apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => 'encoder_omission'], 10);
$h->test('Add Stock is accepted', $add['ok'], $add['error']);
$added = $row();
$h->test('addtl moved', (int)$added['addtl'] === 110, json_encode($added));
$h->test(
    'sales followed the addtl change (110 - 25 - 40 = 45)',
    (int)$added['sales'] === 45,
    'sales=' . $added['sales']
);

$reduce = $apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => 'encoder_omission'], -4);
$h->test('a negative Add Stock correction is accepted', $reduce['ok'], $reduce['error']);
$reduced = $row();
$h->test(
    'sales followed the correction (106 - 25 - 40 = 41)',
    (int)$reduced['sales'] === 41,
    'sales=' . $reduced['sales']
);
$h->test('stored sales still equals the invariant', (int)$reduced['sales'] === $expected($reduced), 'stored=' . $reduced['sales'] . ' invariant=' . $expected($reduced));

// ─── Pending stays pending ─────────────────────────────────────────────
$h->section('A pending ending must stay NULL, never become 0');

// The ending was never recorded. A withdrawal must not invent a sales figure - a
// zero here would be reported as "no sales" rather than "not counted yet".
$seedLedger(100, 0, null, null);
$pendingCharge = $apply(['withdrawal_type' => 'charge', 'reason_code' => 'manual_adjustment'], 7);
$h->test('the withdrawal on a pending day is accepted', $pendingCharge['ok'], $pendingCharge['error']);
$pending = $row();
$h->test('withdraw still moved', (int)$pending['withdraw'] === 7, json_encode($pending));
$h->test('sales is still NULL, not 0', $pending['sales'] === null, 'sales=' . var_export($pending['sales'], true));

// Setting the ending afterwards must resolve it to the invariant.
$db->execute(
    "UPDATE dl_daily_ledger SET bal_end = 50 WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = :s",
    [':b' => $branchId, ':p' => $productId, ':d' => $date, ':s' => $shift]
);
$settled = $apply(['withdrawal_type' => 'charge', 'reason_code' => 'manual_adjustment'], 3);
$h->test('a further withdrawal after the ending is accepted', $settled['ok'], $settled['error']);
$final = $row();
$h->test(
    'sales resolves to the invariant once the ending exists (100 - 10 - 50 = 40)',
    (int)$final['sales'] === 40,
    'sales=' . $final['sales']
);

// ─── Both online handlers, and offline, call it ────────────────────────
$h->section('Every withdrawal write path recomputes sales');

$onlineSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$offlineSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php');

/** Slice out one function body so a mention in a comment cannot satisfy the check. */
$bodyOf = static function (string $src, string $fn): string {
    $start = strpos($src, 'function ' . $fn . '(');
    if ($start === false) {
        return '';
    }
    $end = strpos($src, "\nfunction ", $start + 10);
    return $end === false ? substr($src, $start) : substr($src, $start, $end - $start);
};

$createBody = $bodyOf($onlineSrc, 'apiSaveCashierWithdrawals');
$editBody = $bodyOf($onlineSrc, 'apiUpdateCashierWithdrawal');
$offlineBody = $bodyOf($offlineSrc, 'dl_offlineApplyWithdrawal');

$h->test('located all three handler bodies', $createBody !== '' && $editBody !== '' && $offlineBody !== '');

$h->test(
    'create handler recomputes sales',
    str_contains($createBody, 'dl_recomputeSales('),
    'occurrences=' . substr_count($createBody, 'dl_recomputeSales(')
);
$h->test(
    'edit handler recomputes sales',
    str_contains($editBody, 'dl_recomputeSales('),
    'occurrences=' . substr_count($editBody, 'dl_recomputeSales(')
);
$h->test(
    'offline replay recomputes sales',
    str_contains($offlineBody, 'dl_recomputeSales('),
    'occurrences=' . substr_count($offlineBody, 'dl_recomputeSales(')
);

// The recompute has to land AFTER the ledger write, or it recomputes the old numbers.
$createWrite = strpos($createBody, 'SET withdraw = withdraw + :qty');
$createRecalc = strpos($createBody, 'dl_recomputeSales(');
$h->test(
    'the create-path recompute runs after the withdraw write',
    $createWrite !== false && $createRecalc !== false && $createRecalc > $createWrite,
    'write@' . var_export($createWrite, true) . ' recompute@' . var_export($createRecalc, true)
);

$editWrite = strpos($editBody, 'SET addtl = :a, withdraw = :w');
$editRecalc = strpos($editBody, 'dl_recomputeSales(');
$h->test(
    'the edit-path recompute runs after the ledger write',
    $editWrite !== false && $editRecalc !== false && $editRecalc > $editWrite,
    'write@' . var_export($editWrite, true) . ' recompute@' . var_export($editRecalc, true)
);

// Atomicity: a post-commit recompute would leave a window where the row is durable
// but the cell still disagrees with it.
$editCommit = strpos($editBody, '$db->commit();');
$h->test(
    'the edit-path recompute happens before the commit, so it is atomic with the write',
    $editRecalc !== false && $editCommit !== false && $editRecalc < $editCommit,
    'recompute@' . var_export($editRecalc, true) . ' commit@' . var_export($editCommit, true)
);

$h->test(
    'both the withdraw and the addtl branch are covered by one call after the branch',
    substr_count($createBody, 'dl_recomputeSales($branchId, $pid, $date, $userId, $shift)') === 1,
    'occurrences=' . substr_count($createBody, 'dl_recomputeSales($branchId, $pid, $date, $userId, $shift)')
);

// ─── The invariant helper and the SQL must agree ───────────────────────
$h->section('The in-PHP and in-SQL invariants agree');

$h->test('pending ending yields NULL from the helper', dl_computeSalesValue(100, 0, 0, null) === null);
$h->test('a recorded zero ending still computes', dl_computeSalesValue(10, 0, 0, 0) === 10);
$h->test('the helper never returns a negative', dl_computeSalesValue(1, 0, 50, 0) === 0);
$h->test('the helper matches the live Sep 18 row', dl_computeSalesValue(0, 340, 71, 30) === 239, 'BBS-0110 expected 239');

$teardown();
$h->done();
