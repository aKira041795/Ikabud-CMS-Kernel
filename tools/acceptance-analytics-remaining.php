<?php
declare(strict_types=1);

/**
 * Acceptance criterion: Sol's REMAINING analytics findings (the ones left open in e2ba1e76).
 *
 * Source: docs/reviews/sol-analytics-review-2026-10-11.log
 *
 *  R1/R2  THE FORECAST DENOMINATOR. R1 (state the window and the row's own coverage) still holds.
 *         R2 WAS SUPERSEDED on 2026-10-11 by the chair consult (session
 *         forecast-enhancement-2026-10-11) and is now re-anchored to the corrected contract: the
 *         denominator is the days the product could actually be BOUGHT, because the module's own
 *         contract treats an absent row as UNKNOWN rather than a recorded zero, and 71.2% of the
 *         zero-sale cells in the measured window were days the product was not stocked at all.
 *  R3     At network scope the received-stock figure included 3,704 units moved BETWEEN branches
 *         already inside the scope, so stock was counted as network inflow twice.
 *  R4     A PRODUCER branch rendered "stock conversion 0.00%". Exact, and misleading: a producer's
 *         output leaves by dispatch, so retail conversion is not its measure.
 *  R5     Forecast stock took the latest count INSIDE the window, with no as-of date: a valid count
 *         the day before the window read as zero stock, while a 9-day-old count inside it was
 *         accepted with no freshness warning.
 *  R6     A row with movement but no measured sales was given a production number (Sol F8).
 *
 * Usage: php tools/acceptance-analytics-remaining.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/production-efficiency';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
require_once $base . '/modules/daily-ledger/helpers/reporting.php';

$db = module()->db();
$allBranchIds = [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28];
$window = 14;
$target = '2026-10-12';
$filters = [
    'accessible_branch_ids' => $allBranchIds, 'branch_id' => 0,
    'date_from' => '2026-09-01', 'date_to' => '2026-10-11',
    'product_id' => 0, 'shift' => '', 'pending_rows_mode' => 'include',
];

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-64s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

$rows = dl_forecastRows($db, $filters, $target, $window);

// --- R1: the row states the coverage its average rests on ---------------------------------------
$row0 = $rows[0] ?? [];
$hasCoverage = array_key_exists('window_days', $row0) && array_key_exists('coverage_state', $row0);
$check('R1', 'a forecast row states the window and its own coverage', $hasCoverage,
    sprintf('window_days=%s, coverage_state=%s (sample_days alone cannot say "2 of 14" is 2 of 14)',
        array_key_exists('window_days', $row0) ? var_export($row0['window_days'], true) : 'ABSENT',
        array_key_exists('coverage_state', $row0) ? var_export($row0['coverage_state'], true) : 'ABSENT'));

// --- R2: days the BRANCH traded without this product count as measured zero-sales ----------------
// Count the (branch, date, shift) cells the branch actually recorded, from any product. A product
// absent from a recorded cell is a measured zero; absent from an unrecorded cell is unreported.
$recordedStmt = $db->prepare(
    "SELECT DISTINCT dl.branch_id, dl.ledger_date, dl.shift
       FROM dl_daily_ledger dl
      WHERE dl.ledger_date BETWEEN ? AND ?
        AND dl.branch_id IN (" . implode(',', array_fill(0, count($allBranchIds), '?')) . ")"
);
$recordedStmt->execute(array_merge(['2026-09-28', '2026-10-11'], $allBranchIds));
$recordedCells = [];
foreach ($recordedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
    $shift = strtoupper((string)$c['shift']) === 'PM' ? 'PM' : 'AM';
    $recordedCells[(int)$c['branch_id'] . ':' . $shift][(string)$c['ledger_date']] = true;
}

// --- R2: the denominator is the days the product could actually be BOUGHT ----------------------
// SUPERSEDED (2026-10-11, chair consult session forecast-enhancement-2026-10-11). This check used to
// require the denominator to EXCEED the product's own row-days, on the theory that a day the branch
// traded without this product is a measured zero-sale day. Two things falsified that:
//   * the module's own contract (docs/daily-ledger/unfinalized-shift-sales-semantics.md) says a
//     recorded zero requires "a row exists ... NOT AN ABSENT ROW" - an absent row is UNKNOWN;
//   * measured, 1,578 of 2,215 zero-sale cells (71.2%) are days the product could not be bought at
//     all (beg 0 and nothing received), so the old rule fed unbuyable days into the average.
// It reported branch 8 product 34 AM as 0.73/day when that stream sold 8 units on its one buyable
// day. The check now asserts the corrected contract, and `available_days` is the number the
// criterion computes for ITSELF from the ledger so this cannot pass by agreeing with the code.
$availableStmt = $db->prepare(
    "SELECT COUNT(*) AS available_days, SUM(CASE WHEN dl.bal_end IS NULL THEN 0 ELSE GREATEST(0, COALESCE(dl.beg_bal,0) + COALESCE(dl.addtl,0) - COALESCE(dl.withdraw,0) - COALESCE(dl.bal_end,0)) END) AS units
       FROM dl_daily_ledger dl
      WHERE dl.ledger_date BETWEEN ? AND ?
        AND dl.branch_id = ? AND dl.product_id = ? AND dl.shift = ?
        AND dl.bal_end IS NOT NULL
        AND (COALESCE(dl.beg_bal,0) > 0 OR COALESCE(dl.addtl,0) > 0)"
);
$denominatorFixed = false;
$detail = 'no stream found to test';
$thinStream = null;
// Prefer the measured worst case from the chair consult so the check names a stream a reader can
// re-derive by hand: branch 8 product 34 AM.
foreach ($rows as $candidateRow) {
    if ((int)$candidateRow['branch_id'] === 8 && (int)$candidateRow['product_id'] === 34 && (string)$candidateRow['shift'] === 'AM') {
        $thinStream = ['branch_id' => 8, 'product_id' => 34, 'shift' => 'AM'];
        break;
    }
}
if ($thinStream !== null) {
    $availableStmt->execute(['2026-09-28', '2026-10-11', 8, 34, 'AM']);
    $measured = $availableStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $availableDays = (int)($measured['available_days'] ?? 0);
    $measuredUnits = (int)($measured['units'] ?? 0);
    foreach ($rows as $r) {
        if ((int)$r['branch_id'] === 8 && (int)$r['product_id'] === 34 && (string)$r['shift'] === 'AM') {
            // The denominator must be exactly the number of buyable, fully recorded days, and the
            // average must be the units over THOSE days - not diluted by unbuyable ones.
            $denominatorFixed = (int)($r['sample_days'] ?? 0) === $availableDays
                && $availableDays > 0
                && abs((float)($r['average_sales'] ?? -1) - round($measuredUnits / $availableDays, 2)) < 0.01;
            $detail = sprintf('branch 8 product 34 AM: %d buyable day(s) carrying %d units -> row sample_days=%s, average=%s (expected %s). The 10 unbuyable days are reported separately as censored_days=%s',
                $availableDays, $measuredUnits, var_export($r['sample_days'] ?? null, true),
                var_export($r['average_sales'] ?? null, true), number_format($measuredUnits / max(1, $availableDays), 2),
                var_export($r['censored_days'] ?? null, true));
            break;
        }
    }
    if ($detail === 'no stream found to test') {
        $detail = 'stream not found in the forecast output';
    }
} else {
    // Fall back to any stream with at least one buyable day so the check still runs on another tenant.
    foreach ($rows as $r) {
        if ((int)($r['available_days'] ?? 0) > 0) {
            $denominatorFixed = (int)($r['sample_days'] ?? -1) === (int)$r['available_days'];
            $detail = sprintf('branch %d product %d %s: available_days=%d, sample_days=%s',
                (int)$r['branch_id'], (int)$r['product_id'], (string)$r['shift'],
                (int)$r['available_days'], var_export($r['sample_days'] ?? null, true));
            break;
        }
    }
}
$check('R2', 'the average divides by the days the product could be bought', $denominatorFixed, $detail);

// --- R3: network receipts exclude transfers between branches already in scope --------------------
$effAll = dl_productionEfficiencyData($db, ['accessible_branch_ids' => $allBranchIds, 'branch_id' => 0,
    'date_from' => '2026-09-01', 'date_to' => '2026-10-11', 'product_id' => 0, 'shift' => '',
    'pending_rows_mode' => 'exclude']);
$received = (int)($effAll['received'] ?? 0);
$internal = (int)($effAll['received_internal'] ?? 0);
$check('R3', 'the network receipt figure excludes in-scope branch transfers',
    $received > 0 && $received <= 100632 - 3704 && $internal > 0,
    sprintf('received=%s (all-scope receipts are 100,632 including 3,704 internal transfers), internal reported separately=%s',
        number_format($received), number_format($internal)));

// --- R4: a PRODUCER branch does not render a retail conversion rate -----------------------------
$tokens = dl_generateAuthTokens(['sub' => 'admin:1', 'id' => 1, 'username' => 'r', 'name' => 'R', 'role' => 'admin', 'source' => 'daily-ledger']);
$_COOKIE[dlCookieName()] = $tokens['token'];
$render = static function (array $extra = []): string {
    $_GET = array_merge(['scope' => 'ALL', 'date_from' => '2026-09-01', 'date_to' => '2026-10-11'], $extra);
    $_REQUEST = $_GET;
    ob_start();
    try { handleAdminProductionEfficiency(); } catch (Throwable $e) { echo 'THREW: ' . $e->getMessage(); }
    return (string)ob_get_clean();
};
$plain = static fn(string $h): string => (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$b18 = $plain($render(['branch_id' => '18']));
$showsRetailConversion = (bool)preg_match('/Stock conversion\s*0\.00%/i', $b18);
$explainsProducer = (bool)preg_match('/produc\w+[^.]{0,120}(dispatch|not retail|supplied to)/i', $b18);
$check('R4', 'a producer branch states that retail conversion is not its measure',
    !$showsRetailConversion && $explainsProducer,
    sprintf('branch 18 renders "Stock conversion 0.00%%"=%s, states why conversion does not apply=%s',
        $showsRetailConversion ? 'YES (misleading)' : 'no', $explainsProducer ? 'yes' : 'NO'));

// --- R5: forecast stock carries an as-of date and a freshness state -----------------------------
$stockRow = null;
foreach ($rows as $r) {
    if ((int)($r['stock_on_hand'] ?? 0) > 0) { $stockRow = $r; break; }
}
$hasAsOf = $stockRow !== null && trim((string)($stockRow['stock_as_of'] ?? '')) !== ''
    && array_key_exists('stock_age_days', $stockRow) && array_key_exists('stock_state', $stockRow);
$check('R5', 'forecast stock states its as-of date and freshness', $hasAsOf,
    $hasAsOf
        ? sprintf('stock_as_of=%s, age=%s days, state=%s', $stockRow['stock_as_of'], $stockRow['stock_age_days'], $stockRow['stock_state'])
        : 'no stock_as_of / stock_age_days / stock_state on the row - an undated level cannot be judged stale or current');

// --- R6: the "no measured sales => no production number" guard is LIVE, not dead code ------------
// Sol F8's fix reads `sample_days === 0`, but sample_days was assigned `max(1, $denominator)`, so it
// could never be 0 and the guard never fired: movement-only rows kept a production number derived
// from a variance flag. Measured now on tenant 207: the shape exists AND carries zeros. If the clamp
// ever returns, this goes red instead of the defect silently returning.
$noSalesRows = [];
foreach ($rows as $r) {
    if ((int)($r['movement_rows'] ?? 0) > 0 && (int)($r['sample_days'] ?? 0) === 0) {
        $noSalesRows[] = $r;
    }
}
$guarded = $noSalesRows !== [];
$leak = 0;
foreach ($noSalesRows as $r) {
    if ((float)($r['expected_sales'] ?? 0) != 0.0
        || (float)($r['target_production'] ?? 0) != 0.0
        || (int)($r['suggested_production'] ?? 0) != 0
        || (int)($r['inventory_applied'] ?? 0) != 0) {
        $leak++;
    }
}
$statedBasis = $noSalesRows !== [] && trim((string)($noSalesRows[0]['sales_basis'] ?? '')) !== '';

// The half that DISCRIMINATES. The real-data check above cannot tell "the guard fired" from "the
// input happened to be empty". So feed one movement-only row a variance flag of 50 - exactly the
// input Sol F8 measured leaking through as demand - and require the OUTPUT to be zero while the
// rowwise variance_adjustment it consumed is still 50. Same row, non-zero input, zero output: the
// guard is the cause. Delete the guard and this goes red.
$synthetic = dl_forecastDemand(
    [[
        'branch_id' => 1, 'branch_name' => 'SYNTHETIC', 'product_id' => 1, 'sku' => 'SYN-1',
        'product_name' => 'Synthetic', 'shift' => 'AM', 'ledger_date' => '',
        'sales' => null, 'movement_only' => true, 'movement_rows' => 3,
    ]],
    [['branch_id' => 1, 'product_id' => 1, 'shift' => 'AM', 'variance' => 50]],
    [],
    14,
    0.10,
    null,
    []
);
$synRow = $synthetic[0] ?? [];
$consumedNonZeroInput = (float)($synRow['variance_adjustment'] ?? 0) == 50.0;
$emittedZero = (int)($synRow['sample_days'] ?? -1) === 0
    && (float)($synRow['expected_sales'] ?? -1) == 0.0
    && (float)($synRow['target_production'] ?? -1) == 0.0
    && (int)($synRow['suggested_production'] ?? -1) === 0;
$check('R6', 'a row with movement but no measured sales is given no production number',
    $guarded && $leak === 0 && $statedBasis && $consumedNonZeroInput && $emittedZero,
    sprintf('%d movement-only row(s) reachable carrying 0 non-zero expected/target/suggestion; basis stated=%s; '
        . 'synthetic row consumed a variance flag of 50 (variance_adjustment=%s) and still emitted expected=%s target=%s suggestion=%s sample_days=%s',
        count($noSalesRows), $statedBasis ? 'yes' : 'NO',
        var_export($synRow['variance_adjustment'] ?? null, true),
        var_export($synRow['expected_sales'] ?? null, true),
        var_export($synRow['target_production'] ?? null, true),
        var_export($synRow['suggested_production'] ?? null, true),
        var_export($synRow['sample_days'] ?? null, true)));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
