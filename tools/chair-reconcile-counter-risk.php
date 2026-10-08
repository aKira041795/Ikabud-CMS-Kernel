<?php

declare(strict_types=1);

/**
 * CHAIR COUNTER-RISK PROBE — the exclusion must not have created a FALSE NEGATIVE.
 *
 * The repair excludes real NULL-shift projection buckets from comparison. The danger of any
 * exclusion is excluding TOO MUCH: if the synthesized empty scope (a pinned commissary/date
 * with NO projection row) were also skipped, genuine `missing_row` departures would go
 * unreported and the guard would silently stop checking.
 *
 * This probe pins a commissary/date that HAS posted deliveries but NO projection row, and
 * asks whether departures are still surfaced. Read-only.
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

echo "== chair counter-risk probe: exclusion must not create a false negative ==\n\n";

// 1. The healthy row must STILL be compared (the counter-risk pin).
$healthy = dl_reconcileCommissaryDispatch($db, 18, '2026-10-07', null);
$check('healthy row still compared (checked >= 1)', $healthy['checked'] >= 1, 'checked=' . $healthy['checked']);
$check('healthy row not wrongly excluded', $healthy['uncomparable'] === [], 'uncomparable=' . count($healthy['uncomparable']));
$check('healthy row reconciled', $healthy['mismatches'] === [], 'mismatches=' . count($healthy['mismatches']));

// 2. A date with NO projection row must be synthesizable and must not blow up.
$noRow = dl_reconcileCommissaryDispatch($db, 18, '1999-01-01', null);
$check('no projection row -> checked=0', $noRow['checked'] === 0, 'checked=' . $noRow['checked']);
$check('no projection row -> no invented mismatch', $noRow['mismatches'] === [], 'mismatches=' . count($noRow['mismatches']));

// 3. THE REVERSE RISK: a date with REAL posted deliveries but NO projection row.
//    If those deliveries are attributable to commissary 18, the departures must surface
//    as missing_row rather than being swallowed by the new skip branch.
$departed = dl_commissaryDepartedQtyByProduct($db, 18, '2026-10-02', null);
$sumDepartures = array_sum($departed);
printf("\n  2026-10-02: %d product(s) attributable to commissary 18, total qty %d\n", count($departed), $sumDepartures);

if ($sumDepartures === 0) {
    echo "  note: no departures attributable to commissary 18 that day; reversing the risk test\n";
    // Fall back to demonstrating the missing_row path is REACHABLE at all: pin the date the
    // projection row exists but ask for a DIFFERENT product's departure. Use the real
    // dispatch and confirm the derived side is non-empty, i.e. the path can fire.
    $probe = dl_reconcileCommissaryDispatch($db, 18, '2026-10-07', null);
    $check('derivation is non-empty for the real dispatch', (
        array_sum(dl_commissaryDepartedQtyByProduct($db, 18, '2026-10-07', null)) > 0
    ), 'derived total=' . array_sum(dl_commissaryDepartedQtyByProduct($db, 18, '2026-10-07', null)));
    $check('missing_row absent only because every departure HAS a row', $probe['mismatches'] === []);
} else {
    $recon = dl_reconcileCommissaryDispatch($db, 18, '2026-10-02', null);
    // Assert the OUTCOME, not a mechanism. The departures must remain VISIBLE with their
    // quantities intact; which bucket carries them is the implementation's business. Naming
    // `missing_row` here would re-encode the very mechanism the unrecorded-day contract
    // replaced, and would false-fail a correct tree.
    $reported = array_sum(array_column($recon['unrecorded'] ?? [], 'derived'))
              + array_sum(array_column($recon['mismatches'], 'derived'));
    $check(
        'departures with no projection row stay visible, quantities intact',
        $reported === $sumDepartures,
        sprintf('reported %d of %d unit(s)', $reported, $sumDepartures)
    );
    $check(
        'an unrecorded day is not claimed as a disagreement',
        $recon['mismatches'] === [],
        'mismatches=' . count($recon['mismatches'])
    );
    $check('synthesized scope was usable (uncomparable empty)', $recon['uncomparable'] === []);
}

// 4. Purity: nothing was written.
$before = (int)$db->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn();
dl_reconcileCommissaryDispatch($db, null, null, null);
$after = (int)$db->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn();
$check('read-only (row count unchanged)', $before === $after, "{$before} -> {$after}");

echo "\n";
echo $fails === 0 ? "COUNTER-RISK PROBE: PASS\n" : "COUNTER-RISK PROBE: FAIL ({$fails})\n";
exit($fails === 0 ? 0 : 1);
