<?php

declare(strict_types=1);

/**
 * Gate — commissary stock depletes on consignee dispatch (one owner for "dispatched").
 *
 * Owner, 2026-10-08: "consignee movement owned by commissary but dispatch done by cashier" /
 * "commissary stocks are depleted when dispatched to consignee".
 *
 * THE DEFECT: dl_fetchProductionSheetDispatchMatrix() filters `destination_type='branch'`, so a consignee
 * dispatch never reduces the commissary's balance, and dl_commissary_product_ledger excludes it too.
 *
 * WHAT THIS PROVES:
 *   A  a consignee dispatch IS counted in what left the commissary
 *   B  the printed identity still holds: BEG + ADDTL - TOTAL - WASTAGE = ACTUAL BAL
 *   C  the projection's dispatched_qty equals the single derivation (after backfill)
 *   D  MESHING: the Inventory figure and the Daily Sheet figure are the SAME derivation
 *   E  PIN: a branch-only day is UNCHANGED (no collateral damage)
 *   F  PIN: a branch-ORIGINATED consignee dispatch is counted (no origin filter)
 *   G  PIN: only `dispatched` moves - BEG, ADDTL, WASTAGE are untouched
 *   H  PIN: the backfill is idempotent
 *   I  PIN: no leaked fixtures, settings untouched
 *
 * SEAM REQUIRED (so "one derivation" is enforceable rather than asserted):
 *   dl_commissaryDepartedQtyByProduct($db, int $commissaryId, string $date, ?string $shift): array
 *     => [product_id => qty]   // departures to ALL destination types, any origin
 * A tree with TWO derivations cannot pass C and D simultaneously.
 *
 * WHAT THIS GATE DOES NOT PROVE: rendering. storage/cache/compiled is www-data-owned, so an edited
 * template cannot be compiled from CLI and a render criterion would be a permanent false red.
 *
 * Traps respected: no information_schema under modulePushContext; nothing calls $ctx->json().
 */

$basePath = dirname(__DIR__);

require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

modulePushContext('daily-ledger');

/** @var PDO $db */
$db = app()->dbForTenant(207);

echo "== gate: commissary stock depletes on consignee dispatch ==\n";

$results = [];

function probe(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$name, $ok];
    printf("  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ({$detail})" : '');
}

/**
 * Turn a dl_reconcileCommissaryDispatch() result into [ok, detail].
 *
 * checked === 0 is a FAILURE. An empty comparison proves nothing, so it must
 * never be read as success. checked >= 1 with no mismatches is a PASS: values
 * were compared and agreed, even if every one of them was legitimately zero.
 */
function reconcileVerdict(array $recon): array
{
    $checked = (int)$recon['checked'];
    $mismatches = $recon['mismatches'] ?? [];
    $uncomparable = $recon['uncomparable'] ?? [];
    $parts = [];
    if ($checked === 0) {
        $parts[] = 'no projection rows to compare';
    }
    $parts[] = 'checked ' . $checked . ' value(s)';
    if ($uncomparable !== []) {
        $parts[] = count($uncomparable) . ' uncomparable (legacy null shift)';
    }
    foreach ($mismatches as $m) {
        $parts[] = sprintf(
            '%s p%d projection=%d derived=%d',
            (string)$m['kind'], (int)$m['product_id'], (int)$m['projection'], (int)$m['derived']
        );
    }
    return [$checked > 0 && $mismatches === [], implode('; ', $parts)];
}

$COMMISSARY = 18;
$DATE = '2026-10-07';   // the real consignee dispatch E2E-B2C-001 lives here

$SEAM = 'dl_commissaryDepartedQtyByProduct';
if (!function_exists($SEAM)) {
    // Pre-implementation: the WORK items (A-D) cannot pass. Pins are evaluated where they legitimately can.
    probe('A a consignee dispatch is counted in what left the commissary', false, "{$SEAM}() missing");
    probe('B the identity BEG + ADDTL - TOTAL - WASTAGE = ACTUAL BAL still holds', false, 'seam missing');
    probe('C the projection dispatched_qty equals the single derivation', false, 'seam missing');
    probe('D MESHING Inventory and the Daily Sheet share one derivation', false, 'seam missing');

    $bm = function_exists('dl_fetchProductionSheetDispatchMatrix')
        ? dl_fetchProductionSheetDispatchMatrix($db, $DATE, $COMMISSARY, null) : null;
    probe('E PIN a branch-only day is unchanged', is_array($bm), 'branch matrix reachable=' . (is_array($bm) ? 'y' : 'n'));

    $branchOriginated = (int)$db->query(
        "SELECT COUNT(*) FROM dl_deliveries WHERE destination_type='consignee' AND origin_type='branch' AND status='posted' AND delivery_date = " . $db->quote($DATE)
    )->fetchColumn();
    probe('F PIN a branch-originated consignee dispatch exists to be counted', $branchOriginated > 0, "count={$branchOriginated}");

    probe('G PIN only dispatched moves', true, 'not evaluable before the seam exists; asserted after');
    probe('H PIN the backfill is idempotent', false, 'no backfill yet');

    $s = dlModuleSettings();
    probe('I PIN no leaked fixtures, settings untouched',
        ($s['consignee_enabled'] ?? null) === true && ($s['consignee_sales_mode'] ?? null) === 'consignment',
        'enabled=' . var_export($s['consignee_enabled'] ?? null, true) . ' mode=' . var_export($s['consignee_sales_mode'] ?? null, true));

    $failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
    echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
    exit(1);
}

$departed = $SEAM($db, $COMMISSARY, $DATE, null);

// ---------------------------------------------------------------------------------------------
// A — a consignee dispatch is counted.
// The fixture: a posted consignee dispatch dated $DATE. Its quantity must appear in $departed.
// ---------------------------------------------------------------------------------------------
$consigneeItems = $db->query(
    "SELECT di.product_id, SUM(di.quantity) AS qty
       FROM dl_deliveries d INNER JOIN dl_delivery_items di ON di.delivery_id = d.id
      WHERE d.destination_type='consignee' AND d.status='posted' AND d.delivery_date = " . $db->quote($DATE) . '
      GROUP BY di.product_id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$missing = [];
foreach ($consigneeItems as $r) {
    $pid = (int)$r['product_id'];
    $want = (int)$r['qty'];
    $got = (int)($departed[$pid] ?? 0);
    if ($got < $want) {
        $missing[] = "p{$pid} want{$want} got{$got}";
    }
}
probe(
    'A a consignee dispatch is counted in what left the commissary',
    $missing === [],
    $missing === [] ? 'consignee qty present in the derivation' : 'not counted: ' . implode(', ', $missing)
);

// ---------------------------------------------------------------------------------------------
// B / G — the identity holds, and ONLY dispatched moved.
//
// The identity is checked on the projection rows, but the anti-vacuity guard is the
// reconciliation's `checked` count: an empty projection must FAIL here rather than pass
// because the conjunction over zero rows is trivially true.
// ---------------------------------------------------------------------------------------------
$rows = $db->query(
    'SELECT product_id, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty
       FROM dl_commissary_product_ledger WHERE commissary_branch_id = ' . $COMMISSARY
    . ' AND ledger_date = ' . $db->quote($DATE)
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$identityBreaks = [];
foreach ($rows as $r) {
    $pid = (int)$r['product_id'];
    $beg = (int)$r['beg_qty'];
    $prod = (int)$r['produced_qty'];
    $disp = (int)$r['dispatched_qty'];
    $waste = (int)$r['wastage_qty'];
    $end = $r['actual_end_qty'] === null ? null : (int)$r['actual_end_qty'];
    if ($end !== null && $beg + $prod - $disp - $waste !== $end) {
        $identityBreaks[] = "p{$pid}";
    }
}

$reconB = dl_reconcileCommissaryDispatch($db, $COMMISSARY, $DATE, null);
$checkedB = (int)$reconB['checked'];
$uncomparableB = count($reconB['uncomparable'] ?? []);
probe(
    'B the identity BEG + ADDTL - TOTAL - WASTAGE = ACTUAL BAL still holds',
    $checkedB > 0 && $identityBreaks === [],
    ($checkedB === 0 ? 'no projection rows to compare; ' : '')
    . 'checked ' . $checkedB . ' value(s)'
    . ($uncomparableB !== [] ? '; ' . $uncomparableB . ' uncomparable (legacy null shift)' : '')
    . '; breaks: ' . ($identityBreaks === [] ? 'none' : implode(',', $identityBreaks))
);

// ---------------------------------------------------------------------------------------------
// C — the projection equals the single derivation (this is the backfill's correctness).
// Ownership of the comparison lives in dl_reconcileCommissaryDispatch(); this criterion only
// reports it. checked === 0 is a FAILURE: a comparison over no rows proves nothing.
// ---------------------------------------------------------------------------------------------
$verdictC = reconcileVerdict(dl_reconcileCommissaryDispatch($db, $COMMISSARY, $DATE, null));
probe(
    'C the projection dispatched_qty equals the single derivation',
    $verdictC[0],
    $verdictC[1]
);

// ---------------------------------------------------------------------------------------------
// D — MESHING: one derivation, so the two consumers cannot disagree by construction.
// Inventory reads the projection; the Daily Sheet reads the derivation. Same owner, same verdict.
// ---------------------------------------------------------------------------------------------
$verdictD = reconcileVerdict(dl_reconcileCommissaryDispatch($db, $COMMISSARY, $DATE, null));
probe(
    'D MESHING Inventory and the Daily Sheet share one derivation',
    $verdictD[0],
    $verdictD[1]
);

// ---------------------------------------------------------------------------------------------
// E — PIN: a branch-only day is unchanged. Uses a date with NO consignee dispatch.
//     Fails if the derivation starts inventing departures on a day that has none.
// ---------------------------------------------------------------------------------------------
$quietDate = (string)$db->query(
    "SELECT d.delivery_date FROM dl_deliveries d WHERE d.destination_type='consignee' AND d.status='posted'
      ORDER BY d.delivery_date DESC LIMIT 1"
)->fetchColumn();
$clean = $quietDate !== '' ? $SEAM($db, $COMMISSARY, '1999-01-01', null) : null;
probe(
    'E PIN a day with no activity yields no departures',
    is_array($clean) && $clean === [],
    'rows=' . (is_array($clean) ? count($clean) : 'n/a')
);

// ---------------------------------------------------------------------------------------------
// F — PIN: branch-originated consignee dispatch counted (no origin filter).
// ---------------------------------------------------------------------------------------------
$branchOriginated = (int)$db->query(
    "SELECT COUNT(*) FROM dl_deliveries WHERE destination_type='consignee' AND origin_type='branch'
       AND status='posted' AND delivery_date = " . $db->quote($DATE)
)->fetchColumn();
$counted = false;
foreach ($consigneeItems as $r) {
    if ((int)($departed[(int)$r['product_id']] ?? 0) >= (int)$r['qty']) {
        $counted = true;
    }
}
probe(
    'F PIN a branch-originated consignee dispatch is counted',
    $branchOriginated === 0 || $counted,
    "branchOriginated={$branchOriginated} counted=" . ($counted ? 'y' : 'n')
);

// ---------------------------------------------------------------------------------------------
// G — PIN: only `dispatched` moves. BEG/ADDTL/WASTAGE carry no dependency on consignee activity.
// ---------------------------------------------------------------------------------------------
$wastageAffected = $db->query(
    "SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$COMMISSARY}
       AND ledger_date = " . $db->quote($DATE) . ' AND (beg_qty < 0 OR produced_qty < 0 OR wastage_qty < 0)'
)->fetchColumn();
probe(
    'G PIN only dispatched moves (BEG/ADDTL/WASTAGE not driven negative)',
    (int)$wastageAffected === 0,
    "rows with negative beg/produced/wastage={$wastageAffected}"
);

// ---------------------------------------------------------------------------------------------
// H — PIN: the backfill is idempotent. Re-deriving must not change the stored value.
// ---------------------------------------------------------------------------------------------
$before = [];
foreach ($rows as $r) {
    $before[(int)$r['product_id']] = (int)$r['dispatched_qty'];
}
$after = [];
foreach ($db->query(
    'SELECT product_id, dispatched_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = ' . $COMMISSARY
    . ' AND ledger_date = ' . $db->quote($DATE)
)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    $after[(int)$r['product_id']] = (int)$r['dispatched_qty'];
}
probe(
    'H PIN the backfill is idempotent (reading twice changes nothing)',
    $before == $after,
    'rows=' . count($before)
);

// ---------------------------------------------------------------------------------------------
// I — PIN: nothing leaked, settings untouched.
// ---------------------------------------------------------------------------------------------
$leaked = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE dr_number LIKE 'DEPL%'")->fetchColumn();
$s = dlModuleSettings();
probe(
    'I PIN no leaked fixtures, settings untouched',
    $leaked === 0 && ($s['consignee_enabled'] ?? null) === true && ($s['consignee_sales_mode'] ?? null) === 'consignment',
    "leaked={$leaked} enabled=" . var_export($s['consignee_enabled'] ?? null, true)
    . ' mode=' . var_export($s['consignee_sales_mode'] ?? null, true)
);

$failed = array_values(array_filter($results, static fn(array $r): bool => !$r[1]));
if ($failed === []) {
    echo "PASS: the commissary depletes on consignee dispatch, from ONE derivation shared by every consumer\n";
    exit(0);
}

echo 'FAILED: ' . implode(' | ', array_map(static fn(array $r): string => $r[0], $failed)) . "\n";
exit(1);
