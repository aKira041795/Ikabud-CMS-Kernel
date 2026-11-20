<?php

declare(strict_types=1);

/**
 * The day-ending finalize gate must ask BOTH ledgers.
 *
 * WHY THIS EXISTS — measured 2026-11-20 on tenant 207: of 6,091 commissary lines with no ending,
 * 2,794 sat on a shift already stamped `finalized`, 2,238 of them carrying real movement. Those rows
 * were not neglected; they were LOCKED. dl_finalizeDayEndingShiftCore() decided completeness from
 * dl_shiftMissingEndings(), which reads dl_daily_ledger only, so a day could be closed over PIC cells
 * that were still empty — and dl_assertShiftMutable then made them unenterable, leaving an admin
 * reopen as the only way back. The repair CLI could not see them either, because its candidate scan
 * joined the cashier table only. It reported "nothing to reopen" for exactly the rows it exists to
 * free.
 *
 * This test pins the gate in both directions, plus the two ways it must NOT over-refuse:
 *   - a commissary product with movement and no ending BLOCKS finalize, and the refusal names the
 *     production sheet so the operator knows who must act;
 *   - once the ending is recorded, finalize proceeds;
 *   - a product with no movement is not a gap (the same rule the auto-finalizer uses), so an idle
 *     product cannot strand a shift;
 *   - a branch that is not a commissary is unaffected.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-commissary-ending-gate', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/reporting.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$hub = 99791;          // a commissary
$plain = 99792;        // an ordinary branch with no commissary sheet
$busyProduct = 99793;  // has movement, will owe an ending
$idleProduct = 99794;  // assigned but never moves
$day = '2032-06-10';

$cleanup = static function () use ($db, $hub, $plain, $busyProduct, $idleProduct): void {
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN (?, ?)')->execute([$hub, $plain]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?)')->execute([$hub, $plain]);
    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id IN (?, ?)')->execute([$hub, $plain]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?)')->execute([$hub, $plain]);
    $db->prepare('DELETE FROM dl_variance_flags WHERE branch_id IN (?, ?)')->execute([$hub, $plain]);
    $db->prepare('DELETE FROM audit_logs WHERE branch_id IN (?, ?) AND action IN (?, ?, ?)')->execute([$hub, $plain, 'finalize_shift', 'reopen_shift', 'reopen_day']);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?)')->execute([$hub, $plain]);
    $db->prepare('DELETE FROM dl_products WHERE id IN (?, ?)')->execute([$busyProduct, $idleProduct]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$hub, $plain]);
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, 1, 1)')
        ->execute([$hub, 'GATE-HUB', 'Gate Commissary', 'self_managed']);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, 0, 1)')
        ->execute([$plain, 'GATE-PLN', 'Gate Plain Branch', 'commissary_supplied']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$busyProduct, 'GATE-P1', 'Gate Busy Product']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$idleProduct, 'GATE-P2', 'Gate Idle Product']);
    foreach ([$busyProduct, $idleProduct] as $pid) {
        $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$hub, $pid]);
    }

    // Movement with no ending -> a gap. A row with every movement at zero -> not a gap.
    $cpl = $db->prepare(
        'INSERT INTO dl_commissary_product_ledger
            (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $cpl->execute([$hub, $busyProduct, $day, 'PM', 10, 0, 4, 0, null]);
    $cpl->execute([$hub, $idleProduct, $day, 'PM', 0, 0, 0, 0, null]);

    $h->section('A commissary ending that is owed blocks the day-ending finalize');

    $missing = dl_shiftMissingCommissaryEndings($db, $hub, $day, 'PM');
    $h->test(
        'the predicate names the movement-bearing product and ignores the idle one',
        count($missing) === 1 && (int)($missing[0]['product_id'] ?? 0) === $busyProduct,
        'missing=' . json_encode(array_map(static fn($m) => (int)$m['product_id'], $missing))
        . ' (expected exactly [' . $busyProduct . '])'
    );

    $result = dl_finalizeDayEndingShiftCore($db, $hub, $day, 'PM', 1, 'test');
    $h->test(
        'finalize is REFUSED while the production sheet owes an ending',
        ($result['finalized'] ?? true) === false && ($result['missing'] ?? []) !== [],
        'finalized=' . var_export($result['finalized'] ?? null, true) . ' missing=' . count($result['missing'] ?? [])
    );
    $sheets = array_unique(array_map(static fn(array $m): string => (string)($m['sheet'] ?? 'branch'), $result['missing'] ?? []));
    $h->test(
        'the refusal says the ending is on the COMMISSARY sheet, not the branch ledger',
        in_array('commissary', $sheets, true),
        'sheets=' . implode(',', $sheets) . ' (an operator cannot act on a sheet they cannot see)'
    );

    $status = $db->prepare('SELECT status FROM dl_ledger_shift_status WHERE branch_id = ? AND ledger_date = ? AND shift = ?');
    $status->execute([$hub, $day, 'PM']);
    $h->test(
        'the shift stays OPEN, so the cells are still editable',
        (string)($status->fetchColumn() ?: '') !== 'finalized',
        'status=' . var_export($status->fetchColumn(), true)
    );

    $h->section('Once the ending is recorded, finalize proceeds');

    $db->prepare('UPDATE dl_commissary_product_ledger SET actual_end_qty = 6 WHERE commissary_branch_id = ? AND product_id = ? AND ledger_date = ? AND shift = ?')
        ->execute([$hub, $busyProduct, $day, 'PM']);
    $result2 = dl_finalizeDayEndingShiftCore($db, $hub, $day, 'PM', 1, 'test');
    $h->test(
        'a complete production sheet finalizes',
        ($result2['finalized'] ?? false) === true,
        'finalized=' . var_export($result2['finalized'] ?? null, true) . ' missing=' . count($result2['missing'] ?? [])
    );

    $h->section('A branch with no commissary sheet is unaffected');

    $result3 = dl_finalizeDayEndingShiftCore($db, $plain, $day, 'PM', 1, 'test');
    $h->test(
        'a non-commissary branch finalizes exactly as before',
        ($result3['finalized'] ?? false) === true,
        'finalized=' . var_export($result3['finalized'] ?? null, true) . ' missing=' . count($result3['missing'] ?? [])
    );
    $h->test(
        'the commissary predicate returns nothing for a branch with no production rows',
        dl_shiftMissingCommissaryEndings($db, $plain, $day, 'PM') === [],
        'the gate must not invent work on the cashier-only branches'
    );
} finally {
    $cleanup();
}

$h->done();
