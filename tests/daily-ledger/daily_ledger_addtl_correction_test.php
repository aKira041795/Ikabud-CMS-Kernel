<?php

declare(strict_types=1);

/**
 * Daily Ledger — Reducing Additional stock (the correction path for Addt'l)
 *
 * The ledger's Addt'l cell has no input: unlike beg_bal / bal_end it is not a
 * counted value, it ACCUMULATES from formal receive, informal receive and Add
 * Stock (adjustment_add). That is deliberate — `addtl` is meant to be evidence
 * of stock that actually arrived, so an operator cannot retype it. The gap was
 * that nothing could take back an amount keyed too high either: the only
 * writers moved `addtl` by an increment, and `apiSaveLedgerField` (which can
 * write the column absolutely) has no input rendered anywhere.
 *
 * The fix reuses the Correction precedent instead of adding a new mechanism: a
 * NEGATIVE quantity on Add Stock. It moves `addtl` back by that amount and,
 * because it is still a withdrawal row, leaves a reason, a liable person and an
 * audit entry behind — so the trail reads "original entry -> correction" rather
 * than showing a number that quietly changed.
 *
 * These tests pin three things that would otherwise erode:
 *   1. Which types accept a minus (correction and Add Stock; nothing else).
 *   2. `addtl` can never be driven below zero, and a rejected reduction leaves
 *      the ledger AND the withdrawal table completely untouched.
 *   3. `addtl` still equals the sum of its adjustment rows — the invariant that
 *      proves no clamping or half-application happened.
 *
 * Integration mode — real tenant DB (207), isolated fixtures, full cleanup.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-addtl-correction', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('templates/modules/daily-ledger/cashier/modal_patch.disyl');

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

$branchId = 99071;
$productId = 99071;
$date = '2030-03-15';
$shift = 'AM';

$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);

$db->execute(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, :mode, 0, 1)',
    [':id' => $branchId, ':code' => 'T-ADDTCOR', ':name' => 'Addtl Correction Branch', ':addr' => 'Test', ':mode' => 'self_managed']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
     VALUES (:id, :sku, :name, 25.0, 0, 1)',
    [':id' => $productId, ':sku' => 'ADDTCOR-TEST', ':name' => 'Addtl Correction Product']
);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $branchId, ':p' => $productId]);

$admin = ['id' => 999999, 'role' => 'admin', 'source' => 'daily-ledger'];

/**
 * Apply one adjustment through the real worker (offline replay path, which
 * shares dl_resolveWithdrawalLineForType and the addtl delta with the online
 * create path).
 *
 * @return array{ok:bool,error:string,code:int,totals:array}
 */
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
        return ['ok' => !empty($res['ok']), 'error' => '', 'code' => 0, 'totals' => (array)($res['totals'] ?? [])];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'code' => (int)$e->getCode(), 'totals' => []];
    }
};

$ledgerAddtl = static fn (): int => (int)$db->query(
    "SELECT COALESCE(SUM(addtl), 0) FROM dl_daily_ledger WHERE branch_id = {$branchId} AND product_id = {$productId}"
)->fetchColumn();

/** Sum of the adjustment rows that are supposed to compose addtl. */
$adjustmentSum = static fn (): int => (int)$db->query(
    "SELECT COALESCE(SUM(quantity), 0) FROM dl_cashier_withdrawals
      WHERE branch_id = {$branchId} AND product_id = {$productId} AND withdrawal_type = 'adjustment_add'"
)->fetchColumn();

$rowCount = static fn (): int => (int)$db->query(
    "SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = {$branchId} AND product_id = {$productId}"
)->fetchColumn();

// ─── Which types accept a minus ────────────────────────────────────────
$h->section('Types that accept a negative quantity');

$h->test('correction accepts a minus', dl_withdrawalTypeAllowsNegative('correction'));
$h->test('Add Stock accepts a minus (reduces addtl)', dl_withdrawalTypeAllowsNegative('adjustment_add'));
foreach (['charge', 'pullout', 'used'] as $type) {
    $h->test("{$type} does not accept a minus", !dl_withdrawalTypeAllowsNegative($type));
}
$h->test('an unknown type does not accept a minus', !dl_withdrawalTypeAllowsNegative('not_a_type'));

// ─── Baseline: Add Stock still adds ────────────────────────────────────
$h->section('Add Stock still adds');

$seed = $apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => 'encoder_omission'], 10);
$h->test('Add Stock +10 is accepted', $seed['ok'], $seed['error']);
$h->test('addtl becomes 10', $ledgerAddtl() === 10, 'addtl=' . $ledgerAddtl());

// ─── The new path: Add Stock can take it back ──────────────────────────
$h->section('A minus takes additional stock back');

$reduce = $apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => 'encoder_omission'], -4);
$h->test('Add Stock -4 is accepted', $reduce['ok'], $reduce['error']);
$h->test('addtl drops to 6', $ledgerAddtl() === 6, 'addtl=' . $ledgerAddtl());
$h->test('the reduction is recorded as its own row', $rowCount() === 2, 'rows=' . $rowCount());

$negRow = $db->prepare(
    "SELECT quantity, unit, pack_qty, reason_code FROM dl_cashier_withdrawals
      WHERE branch_id = :b AND withdrawal_type = 'adjustment_add' AND quantity < 0
      ORDER BY id DESC LIMIT 1"
);
$negRow->execute([':b' => $branchId]);
$neg = $negRow->fetch(PDO::FETCH_ASSOC) ?: [];
$h->test('the row stores the negative quantity', (int)($neg['quantity'] ?? 0) === -4, 'qty=' . ($neg['quantity'] ?? 'null'));
$h->test('a negative is stored as pieces, never boxes', ($neg['unit'] ?? '') === 'pcs' && ($neg['pack_qty'] ?? null) === null);
$h->test('the row carries the reason it was made for', ($neg['reason_code'] ?? '') === 'encoder_omission');

// The audit trail has to answer "who took this back and why".
$auditStmt = $db->prepare("SELECT new_data FROM audit_logs WHERE action = 'withdrawal' AND branch_id = :b ORDER BY id DESC LIMIT 1");
$auditStmt->execute([':b' => $branchId]);
$auditPayload = json_decode((string)$auditStmt->fetchColumn(), true);
$h->test(
    'the audit entry records the reduction',
    is_array($auditPayload) && (int)($auditPayload['lines'][0]['addtl'] ?? 0) === -4,
    json_encode($auditPayload['lines'] ?? null)
);

// ─── It cannot go below zero ───────────────────────────────────────────
$h->section('addtl can never go below zero');

$overReduce = $apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => 'encoder_omission'], -100);
$h->test('a reduction larger than the balance is rejected', !$overReduce['ok']);
$h->test('it is rejected as a validation error, not a server fault', $overReduce['code'] === 422, 'code=' . $overReduce['code']);
$h->test(
    'the message says what is wrong and what is available',
    str_contains($overReduce['error'], 'below zero') && str_contains($overReduce['error'], '6'),
    $overReduce['error']
);
$h->test('the ledger is untouched by the rejection', $ledgerAddtl() === 6, 'addtl=' . $ledgerAddtl());
$h->test('no row was left behind by the rejection', $rowCount() === 2, 'rows=' . $rowCount());

// Nothing recorded at all: there is nothing to take back.
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);

$noBalance = $apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => 'encoder_omission'], -5);
$h->test('a reduction with no recorded balance is rejected', !$noBalance['ok'] && $noBalance['code'] === 422, 'code=' . $noBalance['code'] . ' ' . $noBalance['error']);
$h->test('the message explains there is nothing to reduce', str_contains($noBalance['error'], 'no additional stock'), $noBalance['error']);
$h->test('a rejected reduction does not create a ledger row', $ledgerAddtl() === 0, 'addtl=' . $ledgerAddtl());
$h->test('a rejected reduction does not create a withdrawal row', $rowCount() === 0, 'rows=' . $rowCount());

// ─── Every other type still refuses a minus ────────────────────────────
$h->section('Other types still refuse a minus');

foreach ([['charge', 'manual_adjustment'], ['pullout', 'manual_adjustment'], ['used', 'staff_meal']] as [$type, $reason]) {
    $res = $apply(['withdrawal_type' => $type, 'reason_code' => $reason], -3);
    $h->test(
        "{$type} still rejects a negative quantity",
        !$res['ok'] && $res['code'] === 422 && str_contains($res['error'], 'Negative quantities are only allowed'),
        'code=' . $res['code'] . ' ' . $res['error']
    );
}
$h->test('the refusal created nothing', $ledgerAddtl() === 0 && $rowCount() === 0);

// ─── The invariant: addtl == sum of its adjustment rows ────────────────
$h->section('addtl still equals the sum of its adjustments');

$apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => 'encoder_omission'], 20);
$apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => 'encoder_omission'], -8);
$apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => 'encoder_omission'], -5);
$h->test('addtl tracks repeated additions and reductions', $ledgerAddtl() === 7, 'addtl=' . $ledgerAddtl());
$h->test(
    'addtl equals the sum of the adjustment rows (nothing clamped or half-applied)',
    $ledgerAddtl() === $adjustmentSum(),
    'addtl=' . $ledgerAddtl() . ' sum=' . $adjustmentSum()
);
$h->test('addtl is never negative', $ledgerAddtl() >= 0);

// ─── The online path carries the same guard ────────────────────────────
$h->section('Online path mirrors the offline worker');

$onlineSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$offlineSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php');

// Both accumulator columns are read under the row lock before a delta is
// applied, so the floor checks see the current value and a concurrent writer
// cannot interleave. Assert the CONTRACT (a locked read of the accumulators),
// never the exact column list: adding a column to that SELECT is a legitimate
// change, and pinning the literal string made this test fail for a correct edit.
$lockReads = static function (string $src): int {
    return (int)preg_match_all(
        "/SELECT id, addtl(?:, withdraw)? FROM dl_daily_ledger[^']*FOR UPDATE/",
        $src
    );
};
$h->test(
    'every online accumulation site reads the columns under FOR UPDATE',
    $lockReads($onlineSrc) >= 2,
    'occurrences=' . $lockReads($onlineSrc) . ' (withdrawal create + delivery receive)'
);
$h->test(
    'the offline replay reads the columns under FOR UPDATE',
    $lockReads($offlineSrc) >= 1,
    'occurrences=' . $lockReads($offlineSrc)
);

// ─── withdraw must ACCUMULATE, not be rebuilt from the cashier rows ────
// A dispatch also moves `withdraw`. Rebuilding it from SUM(cashier rows) after
// a dispatch discards the dispatched quantity, so a cashier entry following a
// dispatch silently erased it (reproduced: dispatch 60 then cashier 43 gave 43,
// not 103). Both paths must apply this row's delta instead.
$h->test(
    'the online withdraw write applies a delta, not a rebuilt total',
    str_contains($onlineSrc, 'SET withdraw = withdraw + :qty')
);
$h->test(
    'the offline withdraw write applies a delta, not a rebuilt total',
    str_contains($offlineSrc, 'SET withdraw = withdraw + :qty')
);
$h->test(
    'neither path rebuilds withdraw from the cashier-row SUM',
    !str_contains($onlineSrc, 'newTotal = max(0, (int)$stmtSum')
        && !str_contains($offlineSrc, 'newTotal = max(0, (int)$stmtSum')
);
$h->test(
    'the online path rejects a withdraw reduction below zero',
    str_contains($onlineSrc, 'Cannot reduce withdrawals below zero')
);
$h->test(
    'the offline path rejects a withdraw reduction below zero',
    str_contains($offlineSrc, 'Cannot reduce withdrawals below zero')
);
$h->test(
    'a reduction with nothing recorded is rejected on the withdraw side too',
    str_contains($onlineSrc, 'There are no withdrawals recorded')
);

// ─── an audit record must never outlive its rollback ───────────────────
// dl_auditLog() writes through the kernel connection, which does not share the
// module transaction. Publishing it BEFORE the commit is what produced audit
// rows claiming six successful withdrawals on a day when only one row existed:
// the audit survived the rollback and reported a write that never landed.
$commitPos = strpos($onlineSrc, "\$ctx->db()->commit();");
$auditPos = strpos($onlineSrc, "dl_auditLog('withdrawal'");
$h->test(
    'the withdrawal transaction commits before the audit is published',
    $commitPos !== false && $auditPos !== false && $commitPos < $auditPos,
    'commitPos=' . var_export($commitPos, true) . ' auditPos=' . var_export($auditPos, true)
);
$h->test(
    'the online path rejects a below-zero reduction',
    str_contains($onlineSrc, 'Cannot reduce additional stock below zero')
);
$h->test(
    'the offline path rejects a below-zero reduction',
    str_contains($offlineSrc, 'Cannot reduce additional stock below zero')
);

// A 422 raised inside the transaction must reach the operator. Before this it
// fell through to the generic handler and read "Database error" with a 500.
$h->test(
    'the online catch surfaces a 422 instead of reporting a database error',
    str_contains($onlineSrc, "\$e->getCode() === 422")
);
$pos422 = strpos($onlineSrc, "\$e->getCode() === 422");
$posDbErr = strpos($onlineSrc, "'error' => 'Database error'");
$h->test(
    'the 422 branch runs before the generic 500 fallback',
    $pos422 !== false && $posDbErr !== false && $pos422 < $posDbErr,
    'pos422=' . var_export($pos422, true) . ' posDbErr=' . var_export($posDbErr, true)
);

// The edit path used to clamp with max(0, ...): the edited row would keep its
// quantity while addtl silently stopped short, so a correction could report
// success without fully applying.
$h->test(
    'the edit path no longer clamps addtl to zero',
    !str_contains($onlineSrc, '$nextAddtl = max(0, $nextAddtl);')
);
$h->test(
    'the edit path rejects a below-zero change instead',
    str_contains($onlineSrc, 'would take additional stock below zero')
);

// ─── The modal offers the minus, from one rule ─────────────────────────
$h->section('The modal offers the minus');

$modalSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/modal_patch.disyl');
$h->test('the template has a single allowsNegative() rule', str_contains($modalSrc, 'allowsNegative() {'));
$h->test(
    'the qty input allows a minus for Add Stock',
    str_contains($modalSrc, ':min="allowsNegative() ? -999999 : 0"')
);
$h->test(
    'the reduces-total hint follows the same rule',
    str_contains($modalSrc, 'x-show="allowsNegative() &amp;&amp; line.quantity &lt; 0"')
        || str_contains($modalSrc, 'x-show="allowsNegative() && line.quantity < 0"')
);
$h->test(
    'the explanation is shown for Add Stock as well as Correction',
    str_contains($modalSrc, 'x-show="allowsNegative()"')
);
$h->test(
    'the minus rule is no longer keyed on isCorrection',
    substr_count($modalSrc, 'isCorrection') === 0,
    'occurrences=' . substr_count($modalSrc, 'isCorrection')
);
$h->test(
    'the modal tells the operator where to fix Addt\'l',
    str_contains($modalSrc, "Addt'l cell cannot be edited directly")
);
$h->test(
    'the negative hint names both types',
    str_contains($modalSrc, 'Correction or Add Stock')
);
// Reporting "Stock added" after a reduction would state the opposite of what
// was recorded, which is exactly the false confidence this path exists to stop.
$h->test(
    'the toast reports a reduction as a reduction',
    str_contains($modalSrc, 'Additional stock reduced')
);
// The client decides whether a minus MAY be typed; the server enforces it.
// Correction-Additional reuses the Add Stock path, so the modal must decide
// direction and type through one mapping rather than by matching the raw option
// value. Naming all three reducing types here keeps that list honest.
$h->test(
    'the modal rule names all three reducing types',
    str_contains($modalSrc, "=== 'correction'")
        && str_contains($modalSrc, "=== 'adjustment_add'")
        && str_contains($modalSrc, "=== 'correction_addtl'")
);

// ─── withdraw must COMPOSE with its other source ───────────────────────
// `withdraw` is not only the sum of cashier rows: a dispatch (Send to Branch)
// increments it too, via dl_applyLedgerDelta(..., 'withdraw', ...). The cashier
// path used to REBUILD the column from SUM(cashier rows), which discarded the
// dispatched quantity — an order-dependent, silent understatement of withdraw,
// and therefore an overstatement of sales. Both writers must apply a delta.
$h->section('withdraw composes with its other source');

$ledgerWithdraw = static fn (): int => (int)$db->query(
    "SELECT COALESCE(SUM(withdraw), 0) FROM dl_daily_ledger
      WHERE branch_id = {$branchId} AND product_id = {$productId} AND shift = '{$shift}'"
)->fetchColumn();

$h->test('withdraw starts at zero for this key', $ledgerWithdraw() === 0, 'withdraw=' . $ledgerWithdraw());

// Exactly what a dispatch does.
dl_applyLedgerDelta($branchId, $productId, $date, 60, 999999, 'withdraw', $shift);
$h->test('a dispatch increments withdraw to 60', $ledgerWithdraw() === 60, 'withdraw=' . $ledgerWithdraw());

$composed = $apply(['withdrawal_type' => 'charge', 'reason_code' => 'manual_adjustment'], 43);
$h->test('the cashier charge is accepted alongside it', $composed['ok'], $composed['error']);
$h->test(
    'the two sources compose (60 + 43 = 103) instead of the dispatch being erased',
    $ledgerWithdraw() === 103,
    'withdraw=' . $ledgerWithdraw() . ' (43 means the dispatch was dropped)'
);

$withdrawFloor = $apply(['withdrawal_type' => 'correction', 'reason_code' => 'manual_adjustment'], -200);
$h->test(
    'a withdraw reduction below zero is rejected as 422, not clamped to zero',
    !$withdrawFloor['ok'] && $withdrawFloor['code'] === 422,
    'code=' . $withdrawFloor['code'] . ' ' . $withdrawFloor['error']
);
$h->test(
    'the rejection names what is recorded',
    str_contains($withdrawFloor['error'], 'Cannot reduce withdrawals below zero'),
    $withdrawFloor['error']
);
$h->test('the rejected reduction left withdraw untouched', $ledgerWithdraw() === 103, 'withdraw=' . $ledgerWithdraw());

// ─── an unknown outcome must not be reported as a failure ───────────────
// Aborting a fetch does not cancel the server's work, so after a timeout the
// client cannot know whether the write landed. Claiming it did not is what sends
// an operator back to re-key the entry by hand - the duplicate mechanism.
$h->section('A timed-out write reports uncertainty, not failure');

$layoutSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/layouts/app.disyl');
// Scope the negative check to the message FUNCTION. Asserting on the whole file
// also matches the prose comment that explains the old wording, so the check would
// fail on its own documentation - the same trap as pinning an exact SQL string.
$msgFnStart = strpos($layoutSrc, 'window.dlWriteTimeoutMessage = function');
$msgFnEnd = $msgFnStart !== false ? strpos($layoutSrc, '};', $msgFnStart) : false;
$msgFn = ($msgFnStart !== false && $msgFnEnd !== false)
    ? substr($layoutSrc, $msgFnStart, $msgFnEnd - $msgFnStart)
    : '';
$h->test(
    'the timeout message function no longer asserts the change was not recorded',
    $msgFn !== ''
        && !str_contains($msgFn, 'was NOT recorded')
        && !str_contains($msgFn, 'NOT saved'),
    'fnLen=' . strlen($msgFn)
);
$h->test(
    'the timeout message admits the outcome is unknown',
    str_contains($layoutSrc, 'NOT yet known whether')
);
$h->test(
    'the timeout message tells the operator Retry is safe and not to re-key',
    str_contains($layoutSrc, 'will not create a second entry')
        && str_contains($layoutSrc, 'Do not key it in again')
);
$h->test(
    'the Retry control states that repeating is safe',
    str_contains($layoutSrc, 'Retry — safe, will not duplicate')
);

// ─── a successful save must re-read the rows ────────────────────────────
// refreshLedgerTotals() fires the dl:rows-refresh that re-reads the ledger rows,
// so the cell shows what the server actually holds. It existed all along, but no
// write path called it: the operator's number never moved, they concluded the entry
// was refused, and keyed it again - the repetition that duplicates an amount.
// Measured on the live page 2026-09-22: a modal save now issues the rows request.
$h->section('A successful save re-reads the ledger rows');

$h->test(
    'the modal calls refreshLedgerTotals on a successful save',
    str_contains($modalSrc, "typeof window.refreshLedgerTotals === 'function'")
        && str_contains($modalSrc, 'window.refreshLedgerTotals();')
);
$ledgerSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');
$h->test(
    'refreshLedgerTotals still fires the rows refresh',
    str_contains($ledgerSrc, "htmx.trigger(document.getElementById('ledger-body'), 'dl:rows-refresh')")
);
$h->test(
    'the rows container listens for that event and re-reads the partial',
    str_contains($ledgerSrc, 'hx-trigger="dl:rows-refresh"')
        && str_contains($ledgerSrc, 'id="ledger-body"')
);

// ─── the confirmed value is painted before the row re-read ──────────────
// The row re-read is a full round trip. Without an immediate paint the number sits
// stale for that whole time, and an operator who cannot see the entry land keys it
// again. The painted value must come from the write RESPONSE, never from client
// arithmetic, or it can disagree with what was actually stored.
$h->section('The confirmed value is painted before the row re-read');

$h->test(
    'the online path returns the resulting balance and the column it belongs to',
    str_contains($onlineSrc, "'result' => \$nextAddtl ?? \$qty, 'field' => 'addtl'")
        && str_contains($onlineSrc, "'result' => \$newTotal, 'field' => 'withdraw'")
);
$h->test(
    'the offline replay returns the same response shape',
    str_contains($offlineSrc, "'result' => \$nextAddtl ?? \$qty, 'field' => 'addtl'")
        && str_contains($offlineSrc, "'result' => \$newTotal, 'field' => 'withdraw'")
);
$h->test(
    'the delta keys survive, so the audit line still reads a delta',
    str_contains($onlineSrc, "'addtl' => \$qty, 'result'") && str_contains($onlineSrc, "'total' => \$newTotal, 'result'")
);
// Scope these to the painter's own body. Matching the whole file also matches the
// same attribute names in the row markup and in unrelated helpers, and pinning an
// exact concatenation breaks on any requote - both have already bitten this suite.
$paintStart = strpos($ledgerSrc, 'window.dlPaintLedgerCells = function');
$paintEnd = $paintStart !== false ? strpos($ledgerSrc, '};', $paintStart) : false;
$paintFn = ($paintStart !== false && $paintEnd !== false)
    ? substr($ledgerSrc, $paintStart, $paintEnd - $paintStart)
    : '';
$h->test(
    'the ledger exposes a painter keyed by field and product',
    $paintFn !== ''
        && str_contains($paintFn, 'querySelectorAll')
        && str_contains($paintFn, 'data-field=')
        && str_contains($paintFn, 'data-product='),
    'fnLen=' . strlen($paintFn)
);
$h->test(
    'the modal paints from the response before refreshing',
    str_contains($modalSrc, 'window.dlPaintLedgerCells(data.totals);')
        && strpos($modalSrc, 'dlPaintLedgerCells(data.totals)') < strpos($modalSrc, 'window.refreshLedgerTotals();')
);
$h->test(
    'the painter refuses to write a missing/undefined result',
    $paintFn !== ''
        && str_contains($paintFn, "typeof t.result === 'undefined'")
        && str_contains($paintFn, 't.result === null'),
    'fnLen=' . strlen($paintFn)
);

// ─── addtl reconciliation: an unexplained figure must be visible ────────
// addtl accumulates and nothing has ever checked it against its sources, so a
// figure inflated by non-identical retries stayed invisible. This reports where the
// stored total disagrees with the entries that should account for it.
$h->section('addtl reconciliation');

$reconcile = static fn (int $bid = 0): array => dl_reconcileAddtl(
    $db,
    $bid > 0 ? $bid : $branchId,
    $date,
    $date
);

$h->test(
    'a figure its evidence fully explains is not reported',
    $reconcile() === [],
    'rows=' . count($reconcile())
);

// An addtl increase with no receiving behind it - the exact shape of a balloon.
dl_applyLedgerDelta($branchId, $productId, $date, 50, 999999, 'addtl', $shift);
$flagged = $reconcile();
$row = $flagged[0] ?? [];
$h->test('an unexplained addtl increase is reported', count($flagged) === 1, 'rows=' . count($flagged));
$h->test(
    'the unexplained amount is reported as the difference',
    (int)($row['difference'] ?? 0) === 50,
    'diff=' . var_export($row['difference'] ?? null, true)
);
$h->test(
    'each source is broken out so the gap is attributable',
    (int)($row['receiving_qty'] ?? -1) === 0 && (int)($row['adjustment_qty'] ?? -1) === 7,
    'receiving=' . var_export($row['receiving_qty'] ?? null, true)
        . ' adjustments=' . var_export($row['adjustment_qty'] ?? null, true)
);

// Posting the evidence closes the gap. The report is a triage list, not a verdict:
// it says "not explained by these sources", and a human decides.
$db->execute(
    "INSERT INTO dl_branch_receivings (branch_id, origin_type, received_ledger_date, status)
     VALUES (:b, 'manual_adjustment', :d, 'posted')",
    [':b' => $branchId, ':d' => $date]
);
$receivingId = (int)$db->lastInsertId();
$db->execute(
    "INSERT INTO dl_branch_receiving_items (receiving_id, product_id, quantity_received, unit)
     VALUES (:r, :p, 50, 'pcs')",
    [':r' => $receivingId, ':p' => $productId]
);
$h->test(
    'posting the receiving evidence clears the figure',
    $reconcile() === [],
    'rows=' . count($reconcile())
);

// Only POSTED receivings count. A draft is not evidence, and treating it as such
// would clear a real discrepancy without anything having arrived.
$db->execute("UPDATE dl_branch_receivings SET status = 'draft' WHERE id = :r", [':r' => $receivingId]);
$h->test(
    'a draft receiving is not counted as evidence',
    count($reconcile()) === 1,
    'rows=' . count($reconcile())
);
$db->execute("UPDATE dl_branch_receivings SET status = 'posted' WHERE id = :r", [':r' => $receivingId]);

$h->test(
    'an unusable branch is refused rather than scanning the estate',
    dl_reconcileAddtl($db, 0, $date, $date) === []
        && dl_reconcileAddtl($db, $branchId, '', '') === []
);

// ─── Cleanup ───────────────────────────────────────────────────────────
$h->section('Cleanup');

$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
// Receiving items follow their header (FK ON DELETE CASCADE).
$db->execute('DELETE FROM dl_branch_receivings WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);

$left = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = {$branchId}")->fetchColumn();
$h->test('cleanup removed the fixture rows', $left === 0);

$h->done();
