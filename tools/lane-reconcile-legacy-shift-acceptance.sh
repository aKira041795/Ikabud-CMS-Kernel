#!/usr/bin/env bash
#
# ACCEPTANCE GATE — a legacy NULL-shift projection row must not produce a FALSE mismatch.
#
# CONTRACT (AUTHORITATIVE): .ai/commissary-reconcile-legacy-shift.contract.md
#
# WHY. dl_reconcileCommissaryDispatch() compares each projection bucket against
# dl_commissaryDepartedQtyByProduct($db, $cid, $date, $shift). That helper's contract is
# "null shift means DO NOT FILTER ON SHIFT" - it cannot express `shift IS NULL`. So a
# projection row whose shift IS NULL is compared against the ALL-SHIFT SUM and reported as
# a mismatch.
#
# That is not hypothetical. Migration 070 added `shift ENUM('AM','PM') NULL DEFAULT NULL` to
# dl_commissary_product_ledger WITH NO BACKFILL, and the unique key is
# (commissary_branch_id, product_id, ledger_date, shift_key) where shift_key = COALESCE(shift,'').
# A legacy NULL row and a new 'AM' row for the same product/date therefore COEXIST legitimately
# on any tenant with pre-070 history. On such a tenant this guard would report a mismatch that
# is not a mismatch, and a guard that cries wolf is worse than no guard.
#
# WHAT THIS MEASURES. Inside ONE transaction:
#   * read the real projection row (commissary 18, product 53, 2026-10-07, shift AM)
#   * INSERT a legacy NULL-shift row for the same product/date carrying a DIFFERENT value
#   * ask the reconciliation to judge it
#   * assert it does NOT report that row as a `value` mismatch
#   * ROLLBACK, then assert the row count is back to what it was
#
# On the unchanged tree the assertion FAILS (the guard reports the false mismatch).
# It can only PASS once a NULL-shift bucket is excluded from the comparison.
#
# Usage:  bash tools/lane-reconcile-legacy-shift-acceptance.sh
# Exit 0 = the false mismatch is gone.  Exit 1 = it is still produced.

set -u

cd /var/www/html/applicationostest || exit 1

PROBE="/tmp/acc-legacy-shift-probe.php"

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

$COMMISSARY = 18;
$DATE = '2026-10-07';

if (!function_exists('dl_reconcileCommissaryDispatch')) {
    echo "VERDICT=FAIL reason=function_missing\n";
    exit(1);
}

$before = (int)$db->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn();

$row = $db->query(
    'SELECT product_id, shift, dispatched_qty FROM dl_commissary_product_ledger
      WHERE commissary_branch_id = ' . $COMMISSARY . ' AND ledger_date = ' . $db->quote($DATE) . '
        AND shift IS NOT NULL LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "VERDICT=INCONCLUSIVE reason=no_shifted_row_to_build_a_legacy_sibling_from\n";
    exit(2);
}

$pid = (int)$row['product_id'];
$shiftedQty = (int)$row['dispatched_qty'];
// A legacy aggregate that cannot equal the per-shift derivation. Any value other than the
// all-shift total would do; using a clearly distinct one keeps the intent obvious.
$legacyQty = $shiftedQty + 42;

$falseMismatch = null;
$uncomparableNoted = false;

$db->beginTransaction();
try {
    $db->prepare(
        'INSERT INTO dl_commissary_product_ledger
             (commissary_branch_id, product_id, ledger_date, shift, dispatched_qty)
         VALUES (?, ?, ?, NULL, ?)'
    )->execute([$COMMISSARY, $pid, $DATE, $legacyQty]);

    $recon = dl_reconcileCommissaryDispatch($db, $COMMISSARY, $DATE, null);

    foreach ($recon['mismatches'] as $m) {
        // The false positive: the NULL-shift row reported as a VALUE mismatch. `shift` is
        // null in the returned mismatch, which is how the legacy bucket surfaces.
        if ($m['shift'] === null && (int)$m['product_id'] === $pid) {
            $falseMismatch = sprintf(
                'product %d null-shift row projection=%d derived=%d kind=%s',
                (int)$m['product_id'], (int)$m['projection'], (int)$m['derived'], (string)$m['kind']
            );
        }
    }

    if (isset($recon['uncomparable']) && $recon['uncomparable'] !== []) {
        $uncomparableNoted = true;
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$after = (int)$db->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn();
if ($after !== $before) {
    echo "VERDICT=FAIL reason=rollback_leaked before={$before} after={$after}\n";
    exit(1);
}

if ($falseMismatch !== null) {
    echo "VERDICT=FAIL reason=false_mismatch detail={$falseMismatch}\n";
    exit(1);
}

printf("VERDICT=PASS rollback_clean=yes uncomparable_reported=%s\n", $uncomparableNoted ? 'yes' : 'no');
exit(0);
PHP_EOF

echo "== acceptance: a legacy NULL-shift row must not be a FALSE mismatch =="

out=$(php "$PROBE" 2>&1)
code=$?

printf '%s\n' "$out" | sed 's/^/  /'

echo
if [ "$code" -eq 2 ]; then
  echo "ACCEPTANCE: INCONCLUSIVE (no shifted row to build the legacy sibling from)"
  exit 2
fi

if [ "$code" -eq 0 ]; then
  echo "ACCEPTANCE: PASS (a legacy NULL-shift row is no longer reported as a mismatch)"
  exit 0
fi

echo "ACCEPTANCE: FAIL (the guard still produces a false mismatch for a legacy NULL-shift row)"
exit 1
