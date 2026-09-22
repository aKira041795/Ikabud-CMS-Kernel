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

// Both addtl accumulation sites read it under the row lock, which is what makes
// the floor check safe against a concurrent receive. There are two on purpose:
// the withdrawal create path (below) and the delivery receive path, which had
// the same read-add-store shape already.
$addtlLockSites = substr_count($onlineSrc, "'SELECT id, addtl FROM dl_daily_ledger");
$h->test(
    'every online addtl accumulation site reads it under FOR UPDATE',
    $addtlLockSites === 2,
    'occurrences=' . $addtlLockSites
);
$h->test(
    'the offline replay reads addtl under FOR UPDATE',
    substr_count($offlineSrc, "'SELECT id, addtl FROM dl_daily_ledger") === 1
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
$h->test(
    'the modal rule names both types explicitly',
    str_contains($modalSrc, "=== 'correction' || this.header.withdrawal_type === 'adjustment_add'")
);

// ─── Cleanup ───────────────────────────────────────────────────────────
$h->section('Cleanup');

$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);

$left = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = {$branchId}")->fetchColumn();
$h->test('cleanup removed the fixture rows', $left === 0);

$h->done();
