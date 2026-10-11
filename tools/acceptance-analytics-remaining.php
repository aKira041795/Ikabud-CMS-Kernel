<?php
declare(strict_types=1);

/**
 * Acceptance criterion: Sol's REMAINING analytics findings (the ones left open in e2ba1e76).
 *
 * Source: docs/reviews/sol-analytics-review-2026-10-11.log
 *
 *  R1/R2  THE FORECAST DENOMINATOR. The "14-day" average divided only by days the PRODUCT had an
 *         official row. Sol measured sample-day counts of 0, 1, 2, 4, 5, 7, 10, 11, 12 - most streams
 *         are nowhere near 14. Two different absences were treated identically:
 *           - the branch TRADED that day and this product has no row  -> a MEASURED ZERO
 *           - the branch did not trade / did not report at all       -> UNREPORTED, not a zero
 *         Counting only product-row days turns "sold 20 on the 2 days it appeared" into a daily
 *         demand of 10, which overstates what a production planner must make.
 *  R3     At network scope the received-stock figure included 3,704 units moved BETWEEN branches
 *         already inside the scope, so stock was counted as network inflow twice.
 *  R4     A PRODUCER branch rendered "stock conversion 0.00%". Exact, and misleading: a producer's
 *         output leaves by dispatch, so retail conversion is not its measure.
 *  R5     Forecast stock took the latest count INSIDE the window, with no as-of date: a valid count
 *         the day before the window read as zero stock, while a 9-day-old count inside it was
 *         accepted with no freshness warning.
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

// Find a stream the branch DID trade on more days than the product has rows.
$demandStmt = $db->prepare(
    "SELECT dl.branch_id, dl.product_id, dl.shift, COUNT(DISTINCT dl.ledger_date) AS product_days
       FROM dl_daily_ledger dl
      WHERE dl.ledger_date BETWEEN ? AND ? AND dl.branch_id = 8
      GROUP BY dl.branch_id, dl.product_id, dl.shift ORDER BY product_days ASC LIMIT 400"
);
$demandStmt->execute(['2026-09-28', '2026-10-11']);
$thinStream = null;
$branchRecordedDays = count($recordedCells['8:PM'] ?? []) + count($recordedCells['8:AM'] ?? []);
foreach ($demandStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
    $shift = strtoupper((string)$s['shift']) === 'PM' ? 'PM' : 'AM';
    $branchDays = count($recordedCells['8:' . $shift] ?? []);
    if ($branchDays > (int)$s['product_days'] && (int)$s['product_days'] > 0) {
        $thinStream = ['branch_id' => 8, 'product_id' => (int)$s['product_id'], 'shift' => $shift,
                       'product_days' => (int)$s['product_days'], 'branch_days' => $branchDays];
        break;
    }
}
$denominatorFixed = false;
$detail = 'no thin stream found to test';
if ($thinStream !== null) {
    foreach ($rows as $r) {
        if ((int)$r['branch_id'] === $thinStream['branch_id']
            && (int)$r['product_id'] === $thinStream['product_id']
            && (string)$r['shift'] === $thinStream['shift']) {
            // The denominator must exceed the product's own row-days, because the branch's recorded
            // days without this product are measured zero-sales days.
            $denominatorFixed = (int)($r['sample_days'] ?? 0) > $thinStream['product_days'];
            $detail = sprintf('branch 8 product %d %s: product row-days=%d, branch recorded days=%d, row sample_days=%s',
                $thinStream['product_id'], $thinStream['shift'], $thinStream['product_days'],
                $thinStream['branch_days'], var_export($r['sample_days'] ?? null, true));
            break;
        }
    }
    if ($detail === 'no thin stream found to test') {
        $detail = 'stream not found in the forecast output';
    }
}
$check('R2', 'days the branch traded without this product count as zero-sales days', $denominatorFixed,
    $detail);

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
