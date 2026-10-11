<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the forecast's OBSERVATION MODEL.
 *
 * Source: chair consultation 2026-10-11 (session forecast-enhancement-2026-10-11), P0.
 * Verified against the module's own contract, docs/daily-ledger/unfinalized-shift-sales-semantics.md
 * ("The safe condition is therefore: a row exists; beg_bal, addtl and withdraw are recorded/known
 * zero; bal_end IS NOT NULL and equals zero; and any claimed previous-day zero is itself a recorded
 * ending, NOT AN ABSENT ROW").
 *
 * MEASURED on tenant 207, 2026-09-28..2026-10-11, official rows: 956 cells sold; 637 zero-sale cells
 * where the product WAS available; 1,578 zero-sale cells where the product was NOT available
 * (beg 0 and nothing received - unbuyable at any level of demand). So 71.2% of all zero-sale cells,
 * and 49.8% of ALL cells, are CENSORED observations. Dividing by them is not a conservative
 * forecast, it is a wrong one: branch 8 product 34 AM sold 8 units over the 1 day it was stocked and
 * was reported as 0.73/day.
 *
 * The three states must stay distinguishable:
 *   observed sale      -> eligible, enters the numerator
 *   measured zero      -> eligible (the product WAS available), enters the denominator
 *   unavailable        -> CENSORED, must NOT enter the denominator
 *   absent row         -> UNKNOWN, must NOT be counted as a measured zero (the documented contract)
 *
 * Usage: php tools/acceptance-forecast-availability.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/forecast';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
require_once $base . '/modules/daily-ledger/helpers/reporting.php';

$db = module()->db();

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-64s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

/** One synthetic sales row in the shape dl_reportSalesData() emits. */
$cell = static function (string $date, ?int $sales, int $beg, int $addtl = 0, int $withdraw = 0) {
    return [
        'branch_id' => 900, 'branch_name' => 'SYNTH', 'product_id' => 1, 'sku' => 'S', 'product_name' => 'S',
        'shift' => 'AM', 'ledger_date' => $date, 'sales' => $sales,
        'beg_bal' => $beg, 'addtl' => $addtl, 'withdraw' => $withdraw, 'bal_end' => 0,
        'source_class' => 'unknown', 'source_label' => 'source not declared', 'movement_rows' => 1,
    ];
};

// ── A1/A2: an UNBUYABLE day is not a zero-demand day ──────────────────────────────────────────
// 3 eligible days (available, sales 6 / 4 / 0 → 10 units over 3 days) and 2 days the product was
// not stocked at all (beg 0, nothing received, sales 0 by construction). The honest average is
// 10/3 = 3.33. Counting the unbuyable days as measured zeros gives 10/5 = 2.00 - the dilution that
// makes a forecast understate demand and a bakery under-produce.
$synthetic = [
    $cell('2026-10-01', 6, 10),
    $cell('2026-10-02', 4, 10),
    $cell('2026-10-03', 0, 10),
    $cell('2026-10-04', 0, 0),
    $cell('2026-10-05', 0, 0),
];
$rows = dl_forecastDemand($synthetic, [], [], 14, 0.10, null, []);
$row = $rows[0] ?? [];
$sampleDays = (int)($row['sample_days'] ?? -1);
$average = (float)($row['average_sales'] ?? -1);
$censored = (int)($row['censored_days'] ?? -1);
$expectedAverage = round(10 / 3, 2);
$check('A1', 'a day the product could not be bought is not a zero-demand day',
    $sampleDays === 3 && abs($average - $expectedAverage) < 0.01,
    sprintf('3 available days (10 units) + 2 unavailable days: sample_days=%d (expected 3), average=%s (expected %s; counting the unbuyable days as zeros gives 2.00)',
        $sampleDays, number_format($average, 2), number_format($expectedAverage, 2)));
$check('A2', 'the excluded days are COUNTED and reported, not silently dropped',
    $censored === 2,
    sprintf('censored_days=%d (expected 2) - a forecast that quietly shrinks its own denominator cannot be audited',
        $censored));

// ── A3: an absent ROW is unknown, not a measured zero ─────────────────────────────────────────
// The documented contract: a recorded zero requires a row that exists. A product with no row on a
// day the branch traded is an ABSENT observation. The 5th day here has no row at all, and the
// branch recorded that shift: unknown_days must be 1 and must NOT enter the denominator.
$withGap = [
    $cell('2026-10-01', 6, 10),
    $cell('2026-10-02', 4, 10),
    $cell('2026-10-03', 0, 10),
    $cell('2026-10-04', 0, 0),
    $cell('2026-10-05', 0, 0),
];
$recordedCells = ['900:AM' => ['2026-10-01' => true, '2026-10-02' => true, '2026-10-03' => true, '2026-10-04' => true, '2026-10-05' => true, '2026-10-06' => true]];
$rowsGap = dl_forecastDemand($withGap, [], [], 14, 0.10, null, $recordedCells);
$gapRow = $rowsGap[0] ?? [];
$check('A3', 'an absent row is UNKNOWN, not counted as a measured zero',
    (int)($gapRow['sample_days'] ?? -1) === 3 && (int)($gapRow['unknown_days'] ?? -1) === 1,
    sprintf('the branch recorded 6 days, the product has 5 rows (3 available, 2 unbuyable): sample_days=%s (expected 3), unknown_days=%s (expected 1)',
        var_export($gapRow['sample_days'] ?? null, true), var_export($gapRow['unknown_days'] ?? null, true)));

// ── A4: the evidence the average rests on is gated, not implied ───────────────────────────────
$statusOf = static function (int $availableDays): string {
    $rows = [];
    for ($i = 0; $i < $availableDays; $i++) {
        $rows[] = [
            'branch_id' => 901, 'branch_name' => 'SYNTH2', 'product_id' => 2, 'sku' => 'S2', 'product_name' => 'S2',
            'shift' => 'AM', 'ledger_date' => sprintf('2026-09-%02d', $i + 1), 'sales' => 5,
            'beg_bal' => 10, 'addtl' => 0, 'withdraw' => 0, 'bal_end' => 5,
            'source_class' => 'unknown', 'source_label' => 'source not declared', 'movement_rows' => 1,
        ];
    }
    $built = dl_forecastDemand($rows, [], [], 60, 0.10, null, []);
    return (string)($built[0]['evidence_status'] ?? 'ABSENT');
};
$statusTwo = $statusOf(2);
$statusTwelve = $statusOf(12);
$check('A4', 'the row states whether its evidence is sufficient',
    $statusTwo === 'insufficient' && $statusTwelve === 'sufficient',
    sprintf('2 available days -> %s (expected insufficient); 12 available days -> %s (expected sufficient)',
        $statusTwo, $statusTwelve));

// ── A5: measured on real data, the dilution is real and the fix removes it ────────────────────
$filters = [
    'accessible_branch_ids' => [8], 'branch_id' => 8, 'date_from' => '2026-09-28', 'date_to' => '2026-10-11',
    'product_id' => 0, 'shift' => '', 'pending_rows_mode' => 'include',
];
$realRows = dl_forecastRows($db, $filters, '2026-10-12', 14);
$target = null;
foreach ($realRows as $realRow) {
    if ((int)$realRow['product_id'] === 34 && (string)$realRow['shift'] === 'AM') {
        $target = $realRow;
        break;
    }
}
$realSample = (int)($target['sample_days'] ?? -1);
$realCensored = (int)($target['censored_days'] ?? -1);
$realAverage = (float)($target['average_sales'] ?? -1);
$check('A5', 'a real stream\'s average divides by the days it could actually be bought',
    $target !== null && $realSample === 1 && $realCensored === 10,
    $target === null
        ? 'branch 8 product 34 AM not found in the forecast output'
        : sprintf('branch 8 product 34 AM: sample_days=%d (expected 1 available day), censored_days=%d (expected 10), average=%s (was 0.73/day diluted by unbuyable days)',
            $realSample, $realCensored, number_format($realAverage, 2)));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
