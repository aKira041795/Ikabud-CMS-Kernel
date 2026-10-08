<?php

declare(strict_types=1);

/**
 * CHAIR TEST — can an admin CORRECT a consignee dispatch entry?
 *
 * A first attempt wrapped this in an outer transaction and broke it: the correction path
 * manages its OWN transaction, and PDO cannot nest them ("There is already an active
 * transaction"). This version does not wrap. Instead it SNAPSHOTS every row it may touch,
 * exercises the real function, then RESTORES - asserting the restore, so a leak is visible
 * rather than silent.
 *
 * It tests BOTH branches of the answer:
 *   A. while the shift is FINALIZED -> the correction must be REFUSED
 *   B. after the shift is REOPENED  -> the correction must SUCCEED and move the ledger
 *
 * Tenant 207 baseline (delivery 2000005950, origin branch 8, consignee 99750, product 53,
 * 2026-10-07 AM): items.quantity 3, dl_consignee_ledger.addtl 3, one credit effect qty 3,
 * zero variance flags, zero adjustment effects, shift status 'finalized'.
 */

$basePath = '/var/www/html/applicationostest';

require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

modulePushContext('daily-ledger');

$db = app()->dbForTenant(207);

$fails = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$fails): void {
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ({$detail})" : '');
    if (!$ok) { $fails++; }
};

$DELIVERY  = 2000005950;
$PRODUCT   = 53;
$CONSIGNEE = 99750;
$BRANCH    = 8;
$DATE      = '2026-10-07';
$SHIFT     = 'AM';
$ACTOR     = 20;

// CUSTODY = addtl - withdraw, which is what the effect's before_qty/after_qty snapshots mean.
// A shortfall increments `withdraw` rather than decrementing `addtl`, so reading `addtl`
// alone would report "did not move" on a perfectly correct correction.
$ledgerQ = static function (PDO $db) use ($CONSIGNEE, $PRODUCT, $DATE, $SHIFT): int {
    $r = $db->query('SELECT addtl, withdraw FROM dl_consignee_ledger WHERE consignee_id = ' . $CONSIGNEE
        . ' AND product_id = ' . $PRODUCT . ' AND ledger_date = ' . $db->quote($DATE)
        . ' AND shift = ' . $db->quote($SHIFT))->fetch(PDO::FETCH_ASSOC) ?: [];
    return (int)($r['addtl'] ?? 0) - (int)($r['withdraw'] ?? 0);
};
$ledgerParts = static function (PDO $db) use ($CONSIGNEE, $PRODUCT, $DATE, $SHIFT): string {
    $r = $db->query('SELECT addtl, withdraw FROM dl_consignee_ledger WHERE consignee_id = ' . $CONSIGNEE
        . ' AND product_id = ' . $PRODUCT . ' AND ledger_date = ' . $db->quote($DATE)
        . ' AND shift = ' . $db->quote($SHIFT))->fetch(PDO::FETCH_ASSOC) ?: [];
    return 'addtl=' . (int)($r['addtl'] ?? 0) . ' withdraw=' . (int)($r['withdraw'] ?? 0);
};

echo "== can an admin correct a consignee dispatch entry? ==\n\n";

$delivery = $db->query('SELECT * FROM dl_deliveries WHERE id = ' . $DELIVERY)->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$delivery) { echo "INCONCLUSIVE: delivery not found\n"; exit(2); }

$ledgerSnapshot = $db->query('SELECT addtl, withdraw FROM dl_consignee_ledger WHERE consignee_id = ' . $CONSIGNEE
        . ' AND product_id = ' . $PRODUCT . ' AND ledger_date = ' . $db->quote($DATE) . ' AND shift = ' . $db->quote($SHIFT))
        ->fetch(PDO::FETCH_ASSOC) ?: ['addtl' => 0, 'withdraw' => 0];
$baselineAddtl = (int)$ledgerSnapshot['addtl'];
$baselineWithdraw = (int)$ledgerSnapshot['withdraw'];
$ledgerBaseline = $baselineAddtl - $baselineWithdraw;
printf("delivery %d: consignee %d, origin branch %d, %s %s, status %s, provenance %s\n",
    $DELIVERY, $CONSIGNEE, $BRANCH, $DATE, $SHIFT, $delivery['status'], $delivery['provenance_status']);
printf("baseline: dispatched 3, ledger addtl %d, variance flags %d, adjustment effects %d\n\n",
    $ledgerBaseline,
    (int)$db->query('SELECT COUNT(*) FROM dl_delivery_variance_flags')->fetchColumn(),
    (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger_effects WHERE effect_kind = 'adjustment'")->fetchColumn());

$shiftRow = $db->query('SELECT id, status FROM dl_ledger_shift_status WHERE branch_id = ' . $BRANCH
    . ' AND ledger_date = ' . $db->quote($DATE) . ' AND shift = ' . $db->quote($SHIFT))->fetch(PDO::FETCH_ASSOC) ?: null;
printf("shift row: %s\n\n", json_encode($shiftRow));

try {
    // -----------------------------------------------------------------------------------------
    // A. While the shift is FINALIZED the correction must be refused.
    // -----------------------------------------------------------------------------------------
    $db->prepare('UPDATE dl_ledger_shift_status SET status = "finalized" WHERE branch_id = ? AND ledger_date = ? AND shift = ?')
       ->execute([$BRANCH, $DATE, $SHIFT]);

    $refused = dl_recordConsigneeDeliveryDiscrepancies(
        $db, $delivery, [['product_id' => $PRODUCT, 'received_qty' => 2]], $ACTOR, 'locked-shift attempt', true
    );
    $check('A. while the shift is FINALIZED the correction is REFUSED',
        $refused['correction_error'] !== null,
        $refused['correction_error'] ?? 'NO ERROR - it applied on a locked shift!');
    $check('A. the refusal did not move the consignee ledger',
        $ledgerQ($db) === $ledgerBaseline, 'custody=' . $ledgerQ($db) . ' (' . $ledgerParts($db) . ')');
    $check('A. the refusal tells the admin what to do',
        stripos((string)$refused['correction_error'], 'reopen') !== false,
        (string)$refused['correction_error']);

    // -----------------------------------------------------------------------------------------
    // B. Reopen the shift; the correction must now SUCCEED and move the ledger.
    // -----------------------------------------------------------------------------------------
    $db->prepare('UPDATE dl_ledger_shift_status SET status = "open" WHERE branch_id = ? AND ledger_date = ? AND shift = ?')
       ->execute([$BRANCH, $DATE, $SHIFT]);

    $applied = dl_recordConsigneeDeliveryDiscrepancies(
        $db, $delivery, [['product_id' => $PRODUCT, 'received_qty' => 2]], $ACTOR, 'counted on arrival: one short', true
    );

    $ledgerAfter = $ledgerQ($db);
    $flag = $db->query('SELECT id, sent_qty, received_qty, variance FROM dl_delivery_variance_flags WHERE delivery_id = ' . $DELIVERY)->fetch(PDO::FETCH_ASSOC) ?: [];
    $effect = $db->query("SELECT id, quantity, effect_kind, effect_status, before_qty, after_qty FROM dl_consignee_ledger_effects
                          WHERE delivery_id = " . $DELIVERY . " AND effect_kind = 'adjustment'")->fetch(PDO::FETCH_ASSOC) ?: [];

    $check('B. after REOPENING, the correction applies', $applied['correction_error'] === null, $applied['correction_error'] ?? 'ok');
    $check('B. one adjustment effect was written', (int)$applied['adjusted'] === 1, 'adjusted=' . $applied['adjusted']);
    $check('B. the consignee ledger MOVED 3 -> 2', $ledgerAfter === 2, "addtl 3 -> {$ledgerAfter}");
    $check('B. variance flag records sent=3 received=2 variance=-1',
        (int)($flag['sent_qty'] ?? 0) === 3 && (int)($flag['received_qty'] ?? 0) === 2 && (int)($flag['variance'] ?? 0) === -1,
        json_encode($flag));
    $check('B. the adjustment is APPEND-ONLY with before/after snapshots',
        is_array($effect) && $effect !== [] && array_key_exists('before_qty', $effect) && array_key_exists('after_qty', $effect),
        json_encode($effect));

    // -----------------------------------------------------------------------------------------
    // C. Repeating the SAME correction must not double-apply.
    // -----------------------------------------------------------------------------------------
    $again = dl_recordConsigneeDeliveryDiscrepancies(
        $db, $delivery, [['product_id' => $PRODUCT, 'received_qty' => 2]], $ACTOR, 'same figure again', true
    );
    $check('C. repeating the SAME correction does not double-apply',
        $again['correction_error'] === null && $ledgerQ($db) === 2,
        'adjusted=' . $again['adjusted'] . ' ledger=' . $ledgerQ($db));

    // -----------------------------------------------------------------------------------------
    // D. A CONFLICTING correction must be refused, not silently overwrite.
    // -----------------------------------------------------------------------------------------
    $conflict = dl_recordConsigneeDeliveryDiscrepancies(
        $db, $delivery, [['product_id' => $PRODUCT, 'received_qty' => 1]], $ACTOR, 'a different figure', true
    );
    $check('D. a CONFLICTING correction is REFUSED rather than overwriting',
        $conflict['correction_error'] !== null,
        $conflict['correction_error'] ?? 'NO ERROR - it silently applied a second correction!');
    $check('D. the refused conflict left the ledger at 2', $ledgerQ($db) === 2, 'custody=' . $ledgerQ($db) . ' (' . $ledgerParts($db) . ')');

    // -----------------------------------------------------------------------------------------
    // E. received == sent is a no-op.
    // -----------------------------------------------------------------------------------------
    $noop = dl_recordConsigneeDeliveryDiscrepancies(
        $db, $delivery, [['product_id' => $PRODUCT, 'received_qty' => 3]], $ACTOR, 'all arrived', true
    );
    $check('E. received == sent produces no adjustment', (int)$noop['adjusted'] === 0, 'adjusted=' . $noop['adjusted']);

} finally {
    // ---------------------------------------------------------------------------------------------
    // RESTORE - asserted below, so a leak cannot pass silently.
    // ---------------------------------------------------------------------------------------------
    $db->exec("DELETE FROM dl_consignee_ledger_effects WHERE delivery_id = {$DELIVERY} AND effect_kind = 'adjustment'");
    $db->exec('DELETE FROM dl_delivery_variance_flags WHERE delivery_id = ' . $DELIVERY);
    $db->prepare('UPDATE dl_consignee_ledger SET addtl = ?, withdraw = ? WHERE consignee_id = ? AND product_id = ? AND ledger_date = ? AND shift = ?')
       ->execute([$baselineAddtl, $baselineWithdraw, $CONSIGNEE, $PRODUCT, $DATE, $SHIFT]);
    if ($shiftRow !== null) {
        $db->prepare('UPDATE dl_ledger_shift_status SET status = ? WHERE id = ?')
           ->execute([(string)$shiftRow['status'], (int)$shiftRow['id']]);
    }
}

echo "\n== restore ==\n";
$check('consignee ledger restored to baseline', $ledgerQ($db) === $ledgerBaseline, 'custody=' . $ledgerQ($db) . ' (' . $ledgerParts($db) . ')');
$check('no variance flags left', (int)$db->query('SELECT COUNT(*) FROM dl_delivery_variance_flags')->fetchColumn() === 0);
$check('no adjustment effects left', (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger_effects WHERE effect_kind = 'adjustment'")->fetchColumn() === 0);
$restoredStatus = $shiftRow === null ? null : $db->query('SELECT status FROM dl_ledger_shift_status WHERE id = ' . (int)$shiftRow['id'])->fetchColumn();
$check('shift status restored', $shiftRow === null || $restoredStatus === $shiftRow['status'],
    "now=" . var_export($restoredStatus, true) . " was=" . ($shiftRow['status'] ?? 'n/a'));

echo "\n";
echo $fails === 0 ? "RESULT: admin CAN correct a consignee entry (after reopening the shift), auditably\n"
                  : "RESULT: FAIL ({$fails} check(s))\n";
exit($fails === 0 ? 0 : 1);
