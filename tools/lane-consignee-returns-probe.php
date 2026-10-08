<?php

declare(strict_types=1);

/**
 * ACCEPTANCE PROBE — can an admin record a consignee return, and does the report net it?
 *
 * Exercises the real function against the real fanout, then RESTORES the row EXACTLY. This
 * probe mutates live dev data, so both columns are snapshotted before the first mutation and
 * compared afterwards; a restore that writes only one of a pair is what corrupted this exact
 * row once before (see /memories/repo/guards-that-cannot-refuse-2026-10-08.md #8).
 *
 * Fixture: consignee 99750 / product 22 / 2026-10-08 / AM — a dispatch whose shift is OPEN,
 * so the mutation guard permits a return.
 *
 * Exit 0 = the criterion is met. Any other exit = not met (or the probe could not decide,
 * which is also a failure — never a silent pass).
 */

ob_start();
require_once __DIR__ . '/../tests/harness/TestHarness.php';
$h = new TestHarness('lane-consignee-returns-probe', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$CONSIGNEE = 99750;
$PRODUCT = 22;
$DATE = '2026-10-08';
$SHIFT = 'AM';
$ACTOR = 20;
$PRICE = 400.0;

$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failed, $h): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
    if (!$ok) {
        $failed = 1;
        // Mirror the failure into the harness so its exit verdict agrees with ours, without
        // echoing a second copy of the line. The harness's total IS the exit code at done().
        ob_start();
        $h->fail($label, $detail);
        ob_end_clean();
    }
};

$rowSql = 'SELECT id, addtl, withdraw FROM dl_consignee_ledger'
    . ' WHERE consignee_id = :c AND product_id = :p AND ledger_date = :d AND shift = :s LIMIT 1';
$readRow = static function () use ($db, $rowSql, $CONSIGNEE, $PRODUCT, $DATE, $SHIFT): ?array {
    $st = $db->prepare($rowSql);
    $st->execute([':c' => $CONSIGNEE, ':p' => $PRODUCT, ':d' => $DATE, ':s' => $SHIFT]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
};
/** The report row for the fixture line, or null when the report does not carry it. */
$reportRow = static function () use ($db, $DATE, $PRODUCT, $CONSIGNEE): ?array {
    $report = dl_fetchConsigneeDispatchReport($db, ['date_from' => $DATE, 'date_to' => $DATE]);
    foreach ($report['rows'] as $r) {
        if ((int)$r['product_id'] === $PRODUCT && (int)$r['consignee_id'] === $CONSIGNEE) {
            return $r;
        }
    }
    return null;
};

// ---- snapshot BEFORE the first mutation ------------------------------------------------
$snapshot = $readRow();
$doctrineEffects = $db->query("SELECT id FROM dl_consignee_ledger_effects WHERE effect_kind = 'return'")
    ->fetchAll(PDO::FETCH_COLUMN) ?: [];
$existed = $snapshot !== null;

echo 'fixture: consignee ' . $CONSIGNEE . ' / product ' . $PRODUCT . ' / ' . $DATE . ' ' . $SHIFT
    . ' -> ' . ($existed
        ? 'addtl=' . (int)$snapshot['addtl'] . ' withdraw=' . (int)$snapshot['withdraw']
        : 'ROW ABSENT (created and removed by this probe)') . PHP_EOL;

$restore = static function () use ($db, $readRow, $snapshot, $doctrineEffects, $existed, $CONSIGNEE, $PRODUCT, $DATE, $SHIFT): void {
    $row = $readRow();
    if (!$existed) {
        if ($row !== null) {
            $db->prepare('DELETE FROM dl_consignee_ledger WHERE id = ?')->execute([(int)$row['id']]);
        }
    } elseif ($row !== null) {
        $db->prepare('UPDATE dl_consignee_ledger SET addtl = ?, withdraw = ? WHERE id = ?')
           ->execute([(int)$snapshot['addtl'], (int)$snapshot['withdraw'], (int)$row['id']]);
    }
    // ModuleDB has NO exec() — that missing method is exactly what fataled this restore once and
    // left the dev fixture carrying stray `return` effects. Use prepare()->execute().
    $keep = implode(',', array_map('intval', $doctrineEffects)) ?: '0';
    $db->prepare("DELETE FROM dl_consignee_ledger_effects WHERE effect_kind = 'return' AND id NOT IN ({$keep})")
       ->execute();
};

try {
    if (!function_exists('dl_recordConsigneeReturn')) {
        $check('dl_recordConsigneeReturn() exists', false, 'no function to exercise yet');
        echo PHP_EOL . 'PROBE: FAIL (nothing to test)' . PHP_EOL;
        exit(1);
    }

    // Ensure a row with custody exists so a return has something to take from.
    if (!$existed) {
        $db->prepare('INSERT INTO dl_consignee_ledger (consignee_id, product_id, ledger_date, shift, price_snapshot, addtl, withdraw)'
            . ' VALUES (:c, :p, :d, :s, :price, 2, 0)')
           ->execute([':c' => $CONSIGNEE, ':p' => $PRODUCT, ':d' => $DATE, ':s' => $SHIFT, ':price' => $PRICE]);
    }
    $start = $readRow();
    $startCustody = (int)$start['addtl'] - (int)$start['withdraw'];

    // Baseline REPORT row. Every assertion below is a MOVEMENT against this, never an absolute:
    // `dl_consignee_ledger` is a MOVEMENT ledger, so a return total that was ever LOWERED is
    // appended to `addtl` and reversed again by the report. Comparing the raw columns to the
    // report is therefore only valid while no total has ever been lowered — which is exactly the
    // trap that made an earlier version of this probe accuse a correct implementation.
    $reportBefore = $reportRow();
    if ($reportBefore === null) {
        $check('the fixture line is visible on the report before any change', false, 'nothing to measure');
        echo PHP_EOL . 'PROBE: FAIL (no baseline report row)' . PHP_EOL;
        exit(1);
    }
    $unitPrice = (float)$reportBefore['unit_price'];
    $returnedBefore = (int)$reportBefore['returned_qty'];
    $netBefore = (float)$reportBefore['net_value'];
    $spoilageBefore = (float)$reportBefore['spoilage_value'];
    $dispatchBefore = (float)$reportBefore['dispatch_value'];
    $quantityBefore = (int)$reportBefore['quantity'];

    // Totals are ABSOLUTE (the function applies the delta), so derive them from the baseline:
    // the fixture may legitimately already carry returns and custody.
    $partialTotal = $returnedBefore + 1;              // one piece back
    $fullTotal = $returnedBefore + $startCustody;     // the whole dispatch back -> custody 0

    echo 'report before: quantity=' . $quantityBefore . ' returned=' . $returnedBefore
        . ' unit_price=' . $unitPrice . ' dispatch=' . $dispatchBefore
        . ' spoilage=' . $spoilageBefore . ' net=' . $netBefore . PHP_EOL;

    // (a) a return moves custody DOWN without rewriting the dispatch record
    dl_recordConsigneeReturn($db, $CONSIGNEE, $PRODUCT, $DATE, $SHIFT, $partialTotal, $ACTOR);
    $after1 = $readRow();
    $check(
        'recording a return of 1 reduces CUSTODY by 1',
        ((int)$after1['addtl'] - (int)$after1['withdraw']) === $startCustody - 1,
        'custody ' . $startCustody . ' -> ' . ((int)$after1['addtl'] - (int)$after1['withdraw'])
    );

    // (b) idempotent: the same TOTAL twice must not double-apply
    dl_recordConsigneeReturn($db, $CONSIGNEE, $PRODUCT, $DATE, $SHIFT, $partialTotal, $ACTOR);
    $after2 = $readRow();
    $check(
        'submitting the same total twice does not double-apply',
        (int)$after2['addtl'] === (int)$after1['addtl'] && (int)$after2['withdraw'] === (int)$after1['withdraw'],
        'addtl/withdraw stayed ' . (int)$after2['addtl'] . '/' . (int)$after2['withdraw']
    );

    // (b2) REPORT — PARTIAL return. Custody is still out, so the line must show, and the
    //      valuation must show the delivery and the write-off SIDE BY SIDE rather than
    //      netting the delivery figure away.
    $partial = $reportRow();
    if ($partial === null) {
        $check('the report still shows the line after a PARTIAL return', false, 'line vanished from the report');
    } else {
        $check(
            'the report echoes the new total return, up one piece',
            (int)($partial['returned_qty'] ?? -1) === $partialTotal,
            'returned_qty=' . ($partial['returned_qty'] ?? 'null') . ' (expected ' . $partialTotal . ')'
        );
        $check(
            'the GROSS dispatched quantity and value are unchanged (the dispatch record is not rewritten)',
            (int)($partial['quantity'] ?? -1) === $quantityBefore
                && abs((float)($partial['dispatch_value'] ?? -1) - $dispatchBefore) < 0.011,
            'quantity=' . ($partial['quantity'] ?? 'null')
                . ' dispatch_value=' . ($partial['dispatch_value'] ?? 'null')
                . ' (expected ' . $dispatchBefore . ')'
        );
        $check(
            'spoilage_value rises by exactly one unit price (the piece written off)',
            abs((float)($partial['spoilage_value'] ?? -1) - ($spoilageBefore + $unitPrice)) < 0.011,
            'spoilage_value=' . ($partial['spoilage_value'] ?? 'null')
                . ' (expected ' . ($spoilageBefore + $unitPrice) . ')'
        );
        $check(
            'the still-collectible figure drops by exactly one unit price',
            abs((float)($partial['net_value'] ?? -1) - ($netBefore - $unitPrice)) < 0.011,
            'net_value=' . ($partial['net_value'] ?? 'null') . ' (expected ' . ($netBefore - $unitPrice) . ')'
        );
        $check(
            'the ledger adds up: dispatch_value - spoilage_value = net_value',
            abs(((float)($partial['dispatch_value'] ?? 0) - (float)($partial['spoilage_value'] ?? 0))
                - (float)($partial['net_value'] ?? -1)) < 0.011,
            'dispatch=' . ($partial['dispatch_value'] ?? 'null')
                . ' spoilage=' . ($partial['spoilage_value'] ?? 'null')
                . ' net=' . ($partial['net_value'] ?? 'null')
        );
        $check(
            'net_value equals (quantity - returned_qty) at the snapshot unit price',
            abs((float)($partial['net_value'] ?? -1)
                - ((int)($partial['quantity'] ?? 0) - (int)($partial['returned_qty'] ?? 0)) * $unitPrice) < 0.011,
            'net_value=' . ($partial['net_value'] ?? 'null') . ' unit_price=' . $unitPrice
        );
    }

    // (c) raising the total applies only the DELTA, and the FULL total empties custody
    dl_recordConsigneeReturn($db, $CONSIGNEE, $PRODUCT, $DATE, $SHIFT, $fullTotal, $ACTOR);
    $after3 = $readRow();
    $check(
        'raising the total to the whole dispatch empties custody',
        ((int)$after3['addtl'] - (int)$after3['withdraw']) === 0,
        'custody=' . ((int)$after3['addtl'] - (int)$after3['withdraw'])
    );

    // (d) the effect is recorded, append-only
    $kinds = $db->query("SELECT COUNT(*) FROM dl_consignee_ledger_effects WHERE effect_kind = 'return' AND effect_status = 'applied'")
        ->fetchColumn();
    $check('a durable `return` effect row was written', (int)$kinds >= 1, 'applied return effects: ' . (int)$kinds);

    // (e) REPORT — FULL return: custody is 0. The line MUST STILL SHOW. A fully pulled-out
    //     delivery is the STRONGEST evidence the ledger holds, so dropping it would destroy
    //     exactly what this report exists to prove. (An earlier draft of the contract said to
    //     drop zero-custody lines; that made this probe unsatisfiable by a correct
    //     implementation, so the CONTRACT was fixed, not the probe.)
    $full = $reportRow();
    if ($full === null) {
        $check(
            'a fully returned line is STILL reported (custody 0 does not erase the delivery)',
            false,
            'zero-custody line was dropped — the delivery evidence was destroyed'
        );
    } else {
        $check(
            'a fully returned line is STILL reported (custody 0 does not erase the delivery)',
            true,
            'custody 0 and the line is still listed'
        );
        $check(
            'the fully returned line reports the whole dispatch as returned',
            (int)($full['returned_qty'] ?? -1) === $fullTotal,
            'returned_qty=' . ($full['returned_qty'] ?? 'null') . ' (expected ' . $fullTotal . ')'
        );
        $check(
            'a fully returned line nets to 0 while its GROSS dispatch value is unchanged',
            abs((float)($full['net_value'] ?? -1)) < 0.011
                && abs((float)($full['dispatch_value'] ?? -1) - $dispatchBefore) < 0.011,
            'net_value=' . ($full['net_value'] ?? 'null')
                . ' dispatch_value=' . ($full['dispatch_value'] ?? 'null')
                . ' (expected ' . $dispatchBefore . ')'
        );
        $check(
            'the spoilage figure now covers the whole dispatch',
            abs((float)($full['spoilage_value'] ?? -1) - $dispatchBefore) < 0.011,
            'spoilage_value=' . ($full['spoilage_value'] ?? 'null') . ' (expected ' . $dispatchBefore . ')'
        );
    }
} catch (\Throwable $e) {
    $check('the probe ran without throwing', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $restore();
    $back = $readRow();
    $ok = $existed
        ? ($back !== null && (int)$back['addtl'] === (int)$snapshot['addtl'] && (int)$back['withdraw'] === (int)$snapshot['withdraw'])
        : ($back === null);
    $left = (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger_effects WHERE effect_kind = 'return'")
        ->fetchColumn();
    $leftExpected = count($doctrineEffects);
    echo PHP_EOL . '== restore ==' . PHP_EOL;
    $check(
        'the fixture row is restored EXACTLY (both columns)',
        $ok,
        $existed ? 'now addtl=' . (int)$back['addtl'] . ' withdraw=' . (int)$back['withdraw']
            . ' | was addtl=' . (int)$snapshot['addtl'] . ' withdraw=' . (int)$snapshot['withdraw'] : 'row removed again'
    );
    $check('no stray `return` effects are left behind', $left === $leftExpected, 'left=' . $left . ' expected=' . $leftExpected);
    if (!$ok || $left !== $leftExpected) {
        $failed = 1;
    }
}

echo PHP_EOL . ($failed === 0 ? 'PROBE: PASS' : 'PROBE: FAIL') . PHP_EOL;

// Close the harness explicitly. Its shutdown guard reports "SUITE ABORTED before the end" for
// any suite that never calls done(), and it also enforces the no-log-growth rule — the probe's
// own no-log check. done() exits with the harness verdict, which the $check mirror keeps in step
// with ours.
$h->done();
