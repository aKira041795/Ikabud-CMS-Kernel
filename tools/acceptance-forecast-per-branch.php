<?php
declare(strict_types=1);

/**
 * Acceptance criterion: THE FORECAST MUST BE PER BRANCH.
 *
 * Handoff P2, measured: `dl_forecastDemand()` groups on `$key = $pid . ':' . $shift` - no branch -
 * and the row carries no branch_id/branch_name. The owner's words:
 *   "An owner cannot tell that branch A needs 100 while branch B needs 10."
 *
 * MEASURED ON THE UNCHANGED TREE (tenant 207, history 2026-09-01..2026-10-11, ALL-branch scope):
 *   forecast rows returned ............ 356      (one per product x shift)
 *   distinct branch x product x shift .. 788      (the demand streams that actually exist)
 *   -> 432 real demand streams are averaged into someone else's suggestion.
 *   pid=110 PANDESAL AM is sold at branches 8 AND 9 and yields ONE averaged row.
 *   `branch_id` is ABSENT from every row.
 *
 * A SECOND LIE IN THE SAME TABLE, which this criterion also guards. "Stock applied" offsets demand
 * with `dl_commissary_product_ledger.remaining_qty` - the COMMISSARY's unsold finished goods. That is
 * the P1 defect in a new place: source stock charged to a destination branch. Two branches supplied
 * by one commissary would each be credited with the full source stock, and the stock has not been
 * delivered to either. A branch's own shelf is `dl_daily_ledger.bal_end` at its latest count, which
 * is measured and dense here (branch 8: 178 products with an ending, latest 2026-10-10).
 *
 * THE FOUR WAYS THE FORECAST STILL MISLEADS IF THESE CHECKS ARE NOT MET:
 *   1. one averaged suggestion standing for many branches, with no branch named on the row;
 *   2. a branch's requirement set by another branch's sales (the average moves for everyone);
 *   3. a source's undelivered stock reducing a destination branch's requirement;
 *   4. a suggestion with no stated place, so it cannot be actioned by anyone.
 *
 * Written and measured against the unchanged tree BEFORE the change. Every check below is RED today.
 * Usage: php tools/acceptance-forecast-per-branch.php
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
$allBranchIds = [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28];
$filters = [
    'accessible_branch_ids' => $allBranchIds,
    'branch_id' => 0,
    'date_from' => '2026-09-01',
    'date_to' => '2026-10-11',
    'product_id' => 0,
    'shift' => '',
    'pending_rows_mode' => 'include',
];

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-62s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

$rows = dl_forecastRows($db, $filters, '2026-10-12', 14);

// --- B1: every row is scoped to a branch -------------------------------------------------------
$rowsWithBranch = 0;
foreach ($rows as $row) {
    if ((int)($row['branch_id'] ?? 0) > 0) {
        $rowsWithBranch++;
    }
}
$check('B1', 'every forecast row carries a branch', $rows !== [] && $rowsWithBranch === count($rows),
    sprintf('%d of %d row(s) carry a positive branch_id%s', $rowsWithBranch, count($rows),
        $rowsWithBranch === 0 ? ' - the row key is product:shift, so the suggestion has no place' : ''));

// --- B2: a product+shift sold at several branches yields SEVERAL rows, not one average ---------
$byProductShift = [];
foreach ($rows as $row) {
    $key = (int)($row['product_id'] ?? 0) . ':' . (string)($row['shift'] ?? '');
    $byProductShift[$key][(int)($row['branch_id'] ?? 0)] = true;
}
$splitCount = 0;
foreach ($byProductShift as $key => $branches) {
    unset($branches[0]);
    if (count($branches) > 1) {
        $splitCount++;
    }
}
$check('B2', 'a product+shift sold at several branches is split per branch',
    $splitCount > 0,
    sprintf('%d product+shift key(s) resolve to more than one branch (measured: 788 branch x product x shift streams exist)',
        $splitCount));

// --- B3: two branches selling the same product+shift get DIFFERENT, INDEPENDENT suggestions -----
// The fixture is measured here rather than assumed: pid 110 PANDESAL AM is sold at branches 8 and 9,
// and their average daily sales differ, so a single averaged row cannot serve both.
$qty = dl_ledgerSalesQuantitySql('dl');
$fixtureStmt = $db->prepare(
    "SELECT dl.branch_id, COUNT(DISTINCT dl.ledger_date) AS days, SUM({$qty}) AS sold,
            ROUND(SUM({$qty}) / GREATEST(1, COUNT(DISTINCT dl.ledger_date)), 2) AS avg_daily
       FROM dl_daily_ledger dl
      WHERE dl.product_id = 110 AND dl.shift = 'AM'
        AND dl.ledger_date BETWEEN ? AND ?
      GROUP BY dl.branch_id HAVING sold > 0 ORDER BY dl.branch_id"
);
$fixtureStmt->execute(['2026-09-01', '2026-10-11']);
$fixture = $fixtureStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$fixtureBranches = [];
foreach ($fixture as $f) {
    $fixtureBranches[(int)$f['branch_id']] = $f;
}
$hasTwoBranchFixture = count($fixtureBranches) >= 2;

$rowAverages = [];
foreach ($rows as $row) {
    if ((int)($row['product_id'] ?? 0) === 110 && (string)($row['shift'] ?? '') === 'AM') {
        $rowAverages[(int)($row['branch_id'] ?? 0)] = (float)($row['average_sales'] ?? -1);
    }
}
$distinctFixtureSuggestions = [];
foreach (array_keys($fixtureBranches) as $bid) {
    if (isset($rowAverages[$bid])) {
        $distinctFixtureSuggestions[$bid] = $rowAverages[$bid];
    }
}
$suggestionsDiffer = count(array_unique($distinctFixtureSuggestions)) === count($distinctFixtureSuggestions)
    && count($distinctFixtureSuggestions) >= 2;
$check('B3', 'two branches selling one product+shift get independent suggestions',
    $hasTwoBranchFixture && $suggestionsDiffer,
    sprintf('fixture branches=%s, per-branch averages returned=%s',
        implode(',', array_keys($fixtureBranches)),
        $distinctFixtureSuggestions === []
            ? 'NONE - a single averaged row serves every branch'
            : json_encode($distinctFixtureSuggestions)));

// --- B4: the stock offset is the BRANCH's own counted stock, not the source's undelivered stock --
// Source scan, bounded to the FUNCTION and with comments STRIPPED: the fix's own comment names the
// table it removed while explaining why, and a scan that reads comments reports the explanation as
// the defect. (Same trap as a criterion matching the sentence that denies the ratio.)
$reportingSrc = (string)file_get_contents($base . '/modules/daily-ledger/helpers/reporting.php');
$forecastStart = (int)strpos($reportingSrc, 'function dl_forecastRows');
$forecastNext = (int)strpos($reportingSrc, "\nfunction ", $forecastStart + 1);
$forecastFunction = $forecastNext > $forecastStart
    ? substr($reportingSrc, $forecastStart, $forecastNext - $forecastStart)
    : substr($reportingSrc, $forecastStart);
$forecastCode = (string)preg_replace('#/\*.*?\*/#s', '', $forecastFunction);
$forecastCode = (string)preg_replace('#//[^\n]*#', '', $forecastCode);
$readsSourceStock = str_contains($forecastCode, 'dl_commissary_product_ledger');
$readsBranchStock = str_contains($forecastCode, 'bal_end');
$check('B4', 'the stock offset is the branch\'s own counted stock, not the source\'s',
    !$readsSourceStock && $readsBranchStock,
    sprintf('forecast code reads dl_commissary_product_ledger=%s (source stock is undelivered and shared by every supplied branch), reads dl_daily_ledger.bal_end=%s',
        $readsSourceStock ? 'YES (the P1 defect in a new place)' : 'no',
        $readsBranchStock ? 'yes' : 'NO'));

// --- B5: a row states its place and whether it is production or a supply requirement -----------
$row0 = $rows[0] ?? [];
$namesPlace = trim((string)($row0['branch_name'] ?? '')) !== '';
$statesBasis = in_array((string)($row0['source_class'] ?? ($row0['supply_class'] ?? '')), ['producer', 'supplied', 'mixed', 'unknown'], true);
$check('B5', 'a row names its branch and whether the suggestion is production or supply',
    $namesPlace && $statesBasis,
    sprintf('branch_name=%s, source class=%s',
        $namesPlace ? 'yes' : 'NO',
        $statesBasis ? (string)($row0['source_class'] ?? $row0['supply_class']) : 'NO (the row cannot say if it is production or a delivery)'));

// --- B6: the page shows a Branch column --------------------------------------------------------
$template = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/forecast.disyl');
$hasBranchColumn = (bool)preg_match('/<th[^>]*>\s*Branch\s*<\/th>/i', $template)
    && (bool)preg_match('/row\.branch_name|row\.branch_id/', $template);
$check('B6', 'the forecast page shows the branch on each row', $hasBranchColumn,
    $hasBranchColumn
        ? 'a Branch column is rendered from the row'
        : 'no Branch column - the owner still cannot tell branch A from branch B');

// --- B7: EVERY product with movement in the window gets a row ---------------------------------
// Owner: "all products with movement". Measured on tenant 207: 420 branch-product pairs carry ledger
// movement in the window and 209 of them have ZERO sales. The forecast is driven by official sales
// rows, so those 209 pairs - stock that was received, withdrawn or held but never sold - produce no
// row at all. An owner cannot see that a product is dead stock, nor that a branch holds a product of
// which it sells nothing; it simply disappears from the page.
$movedStmt = $db->prepare(
    "SELECT dl.branch_id, dl.product_id, dl.shift
       FROM dl_daily_ledger dl
      WHERE dl.ledger_date BETWEEN ? AND ?
        AND dl.branch_id IN (" . implode(',', array_fill(0, count($allBranchIds), '?')) . ")
      GROUP BY dl.branch_id, dl.product_id, dl.shift"
);
$movedStmt->execute(array_merge(['2026-09-01', '2026-10-11'], $allBranchIds));
$movedKeys = [];
foreach ($movedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
    $movedKeys[(int)$m['branch_id'] . ':' . (int)$m['product_id'] . ':' . strtoupper((string)$m['shift'])] = true;
}
$forecastKeys = [];
foreach ($rows as $row) {
    $forecastKeys[(int)($row['branch_id'] ?? 0) . ':' . (int)($row['product_id'] ?? 0) . ':' . strtoupper((string)($row['shift'] ?? ''))] = true;
}
$missingMoved = array_diff_key($movedKeys, $forecastKeys);
$check('B7', 'every branch+product+shift with movement gets a forecast row',
    $missingMoved === [],
    sprintf('%d of %d branch+product+shift key(s) with movement are MISSING from the forecast (e.g. %s)',
        count($missingMoved), count($movedKeys),
        implode(', ', array_slice(array_keys($missingMoved), 0, 3))));

// --- B8: a row with movement but no measured sales SAYS so --------------------------------------
// Re-anchored: once B7 passes there are no missing keys to sample, so the row is found by its own
// shape - a row that carries movement but zero sample days. A 0 suggestion there must be explained,
// or it reads as "demand was measured as zero" when the truth is "no sales were measured".
$movementOnlyRow = null;
foreach ($rows as $row) {
    if ((int)($row['movement_rows'] ?? 0) > 0 && (int)($row['sample_days'] ?? 0) === 0) {
        $movementOnlyRow = $row;
        break;
    }
}
$movementOnlyCount = 0;
foreach ($rows as $row) {
    if ((int)($row['movement_rows'] ?? 0) > 0 && (int)($row['sample_days'] ?? 0) === 0) {
        $movementOnlyCount++;
    }
}
$statesBasis = $movementOnlyRow !== null
    && trim((string)($movementOnlyRow['sales_basis'] ?? '')) !== '';
$check('B8', 'a movement-only row states that it has no sales history',
    $movementOnlyCount > 0 && $statesBasis,
    sprintf('%d movement-only row(s) present; the sampled one states its basis=%s%s',
        $movementOnlyCount,
        $statesBasis ? 'yes' : 'NO',
        $statesBasis ? '' : ' - a 0 suggestion would read as measured no-demand rather than no sales recorded'));

// --- B9: the branch filter narrows the forecast to that branch ----------------------------------
$branchEightRows = [];
foreach ($rows as $row) {
    if ((int)($row['branch_id'] ?? 0) === 8) {
        $branchEightRows[] = $row;
    }
}
$scopedFilters = $filters;
$scopedFilters['branch_id'] = 8;
$scopedRows = dl_forecastRows($db, $scopedFilters, '2026-10-12', 14);
$onlyBranchEight = $scopedRows !== [];
foreach ($scopedRows as $row) {
    if ((int)($row['branch_id'] ?? 0) !== 8) {
        $onlyBranchEight = false;
        break;
    }
}
$check('B9', 'filtering to one branch returns only that branch',
    $onlyBranchEight && count($scopedRows) < count($rows),
    sprintf('branch 8 scope returned %d row(s), all branch 8=%s (all-branch scope returns %d)',
        count($scopedRows), $onlyBranchEight ? 'yes' : 'NO', count($rows)));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
