#!/usr/bin/env bash
#
# ACCEPTANCE GATE — an UNRECORDED day must not be reported as a mismatch.
#
# CONTRACT (AUTHORITATIVE): .ai/commissary-reconcile-unrecorded.contract.md
#
# WHY. dl_reconcileCommissaryDispatch() reports kind='missing_row' as a MISMATCH when a
# departure exists for a (commissary, date) that has no projection row. But the invariant
#
#     projection.dispatched_qty == derived(commissary, date, shift)
#
# is UNDEFINED for such a day: there is no projection row to compare against. A day with no
# recorded sheet is not a day the projection disagrees - it is a day the projection does not
# cover. Same reasoning that put legacy NULL-shift rows in 'uncomparable'.
#
# MEASURED on tenant 207: only 1 of 132 posted deliveries ever produced a
# dl_delivery_ledger_effects row, so the projection-writing effect path is newly wired. 42
# historical dates carry departures with NO projection row at all - 95,437 units. Reporting
# those as mismatches makes the guard untrustworthy for exactly the reason it exists.
#
# WHAT THIS MEASURES. Commissary 18 on 2026-10-02 has real posted departures and no
# projection row. The reconciliation must surface those departures WITHOUT claiming a
# disagreement:
#
#   * 'mismatches' must NOT contain them
#   * them must still be REPORTED (a separate bucket), so an unrecorded day stays visible
#     and the guard cannot silently stop noticing departures
#
# On the unchanged tree the first assertion FAILS (37 missing_row are reported as mismatches).
#
# Usage:  bash tools/lane-reconcile-unrecorded-acceptance.sh
# Exit 0 = an unrecorded day is reported without being called a mismatch.

set -u

cd /var/www/html/applicationostest || exit 1

PROBE="/tmp/acc-unrecorded-probe.php"

cat > "$PROBE" <<'PHP_EOF'
<?php

declare(strict_types=1);

$basePath = '/var/www/html/applicationostest';
require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/daily-ledger/helpers.php';
require_once $basePath . '/modules/daily-ledger/handlers-deliveries.php';
require_once $basePath . '/modules/daily-ledger/handlers.php';

modulePushContext('daily-ledger');
$db = app()->dbForTenant(207);

if (!function_exists('dl_reconcileCommissaryDispatch')) {
    echo "VERDICT=FAIL reason=function_missing\n";
    exit(1);
}

$COMMISSARY = 18;
$UNRECORDED = '2026-10-02';   // posted departures, no projection row
$RECORDED   = '2026-10-07';   // the one date with a projection row

$reasons = [];

// --- Real departures on the unrecorded day, established independently of the function. ---
$derived = dl_commissaryDepartedQtyByProduct($db, $COMMISSARY, $UNRECORDED, null);
$derivedTotal = array_sum($derived);
$prodCount = count($derived);

$projRows = (int)$db->query(
    'SELECT COUNT(*) FROM dl_commissary_product_ledger
      WHERE commissary_branch_id = ' . $COMMISSARY . ' AND ledger_date = ' . $db->quote($UNRECORDED)
)->fetchColumn();

if ($derivedTotal === 0 || $projRows !== 0) {
    echo "VERDICT=INCONCLUSIVE reason=fixture_not_as_expected derived={$derivedTotal} proj_rows={$projRows}\n";
    exit(2);
}

$recon = dl_reconcileCommissaryDispatch($db, $COMMISSARY, $UNRECORDED, null);

// 1. No departure may be claimed as a mismatch on a day with no projection row.
$claimed = array_filter(
    $recon['mismatches'],
    static fn(array $m): bool => (int)$m['product_id'] > 0
);
if ($claimed !== []) {
    $reasons[] = sprintf(
        'unrecorded day reported %d mismatch(es) (e.g. %s p%d projection=%d derived=%d)',
        count($claimed),
        (string)$claimed[0]['kind'],
        (int)$claimed[0]['product_id'],
        (int)$claimed[0]['projection'],
        (int)$claimed[0]['derived']
    );
}

// 2. The departures must STILL be reported in a DEDICATED bucket - not by being called a
//    mismatch, and not by being dropped. Silence would be a false negative, which is the
//    worse failure: the guard would stop noticing real departures entirely.
$reported = false;
$bucketName = '';
foreach (['unrecorded', 'uncomparable', 'uncovered', 'informational'] as $key) {
    if (!empty($recon[$key])) { $reported = true; $bucketName = $key; break; }
}
if (!$reported) {
    $reasons[] = sprintf(
        'the %d departure(s) totalling %d units are not in a dedicated bucket; they must be '
        . 'visible OUTSIDE `mismatches` (today they sit inside it as kind=missing_row)',
        $prodCount,
        $derivedTotal
    );
}

// 3. Nothing may be counted as compared: there was no projection row to compare.
if ((int)$recon['checked'] !== 0) {
    $reasons[] = 'checked = ' . (int)$recon['checked'] . ' but there was no projection row to compare';
}

// 4. THE COUNTER-RISK: the recorded day must be unaffected - still compared, still clean.
$healthy = dl_reconcileCommissaryDispatch($db, $COMMISSARY, $RECORDED, null);
if ((int)$healthy['checked'] < 1) {
    $reasons[] = 'recorded day stopped being compared (checked=' . (int)$healthy['checked'] . ')';
}
if ($healthy['mismatches'] !== []) {
    $reasons[] = 'recorded day now reports ' . count($healthy['mismatches']) . ' mismatch(es)';
}

if ($reasons !== []) {
    echo 'VERDICT=FAIL reasons=' . implode(' | ', $reasons) . "\n";
    exit(1);
}

printf(
    "VERDICT=PASS unrecorded_departures=%d units=%d reported=yes mismatch=no recorded_day_checked=%d\n",
    $prodCount,
    $derivedTotal,
    (int)$healthy['checked']
);
exit(0);
PHP_EOF

echo "== acceptance: an unrecorded day must not be called a mismatch =="

out=$(php "$PROBE" 2>&1)
code=$?

printf '%s\n' "$out" | sed 's/^/  /'

echo
if [ "$code" -eq 2 ]; then
  echo "ACCEPTANCE: INCONCLUSIVE (fixture not as expected)"
  exit 2
fi

if [ "$code" -eq 0 ]; then
  echo "ACCEPTANCE: PASS (an unrecorded day is reported without being claimed as a disagreement)"
  exit 0
fi

echo "ACCEPTANCE: FAIL (an unrecorded day is still reported as a mismatch)"
exit 1
