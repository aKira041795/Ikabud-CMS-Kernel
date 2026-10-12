<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the forecast's activity filter and quantity sort.
 *
 * Owner: "for forecast add a filter (a checkbox) to exclude products with no movement or sales"
 *        "then can be sorted (ASC/DESC) ... as to qty"
 *
 * MEASURED on tenant 207, 2026-09-28..2026-10-11: 788 forecast rows, of which **534 (68%)** neither
 * sold nor moved - keys whose ledger rows are all zero (someone opened the cell, or a carry wrote a
 * 0). They are rows a planner cannot act on, and they outnumber the real ones.
 *
 * Two rules this pins that are easy to get wrong:
 *   1. "no movement" must mean NO REAL MOVEMENT, not "no ledger row". Measured: 207 keys hold 1,215
 *      ledger rows between them with every quantity at zero, so a row COUNT reports movement where
 *      none happened.
 *   2. A row that MOVED but did not sell is activity, and must survive the filter. The checkbox
 *      excludes rows with NEITHER, which is what the owner asked for.
 *
 * Usage: php tools/acceptance-forecast-activity-filter.php
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

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-64s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

// A missing helper is the feature being absent, not an error worth a stack trace: report every
// check RED with the reason, so this criterion stays readable when the work is not done.
$required = ['dl_forecastRowHasActivity', 'dl_forecastEmptyRowsMode'];
$missing = array_values(array_filter($required, static fn(string $fn): bool => !function_exists($fn)));
if ($missing !== []) {
    foreach ([
        'F1' => 'the filter keeps only rows that sold or moved',
        'F2' => 'a row that moved but did not sell is NOT excluded',
        'F3' => 'a row with no sale and no real movement has no activity',
        'F4' => 'rows sort by quantity in both directions',
        'F5' => 'the page offers the checkbox and both sort directions',
        'F6' => 'an untouched page load hides the empty rows by default',
    ] as $id => $what) {
        $check($id, $what, false, 'not implemented: missing helper(s) ' . implode(', ', $missing));
    }
    printf("\n  => %d RED. The criterion discriminates: this is the work.\n", $reds);
    exit(1);
}

$filters = static function (string $mode, string $sortDir = 'desc') use ($allBranchIds): array {
    return [
        'accessible_branch_ids' => $allBranchIds, 'branch_id' => 0,
        'date_from' => '2026-09-28', 'date_to' => '2026-10-11',
        'product_id' => 0, 'shift' => '', 'pending_rows_mode' => 'include',
        'empty_rows_mode' => $mode, 'sort_dir' => $sortDir,
    ];
};

// ── F1: the filter removes ONLY the rows that neither sold nor moved ──────────────────────────
$withEmpty = dl_forecastRows($db, $filters('include'), '2026-10-12', 14);
$withoutEmpty = dl_forecastRows($db, $filters('exclude'), '2026-10-12', 14);
$leaks = [];
foreach ($withoutEmpty as $row) {
    if (!dl_forecastRowHasActivity($row)) {
        $leaks[] = sprintf('%d/%d/%s', (int)$row['branch_id'], (int)$row['product_id'], (string)$row['shift']);
    }
}
$dropped = count($withEmpty) - count($withoutEmpty);
$check('F1', 'the filter keeps only rows that sold or moved',
    $leaks === [] && $dropped > 0 && count($withoutEmpty) > 0,
    sprintf('%d row(s) with the filter off, %d with it on (%d dropped); %d row(s) without activity survived%s',
        count($withEmpty), count($withoutEmpty), $dropped, count($leaks),
        $leaks === [] ? '' : ' e.g. ' . implode(', ', array_slice($leaks, 0, 3))));

// ── F2: a row that MOVED but did not sell is activity and must survive ────────────────────────
$movedNotSold = 0;
$survived = 0;
$survivorKeys = [];
foreach ($withoutEmpty as $row) {
    $survivorKeys[(int)$row['branch_id'] . ':' . (int)$row['product_id'] . ':' . (string)$row['shift']] = true;
}
foreach ($withEmpty as $row) {
    $noSale = (float)($row['average_sales'] ?? 0) <= 0.0;
    if ($noSale && (int)($row['movement_rows'] ?? 0) > 0) {
        $movedNotSold++;
        $key = (int)$row['branch_id'] . ':' . (int)$row['product_id'] . ':' . (string)$row['shift'];
        if (isset($survivorKeys[$key])) {
            $survived++;
        }
    }
}
$check('F2', 'a row that moved but did not sell is NOT excluded',
    $movedNotSold > 0 && $survived === $movedNotSold,
    sprintf('%d row(s) moved without selling; %d survived the filter', $movedNotSold, $survived));

// ── F3: "no movement" means no REAL movement, not "no ledger row" ──────────────────────────────
// A pure-unit check on the predicate, because the measured case is subtle: 207 keys hold 1,215
// ledger rows with every quantity at zero. A ROW COUNT would call that movement.
// The field names are part of the contract: a forecast row exposes its sales AGGREGATED as
// `average_sales`, and has no `sales` key at all. Reading `sales` silently classified every sold row
// as activity-less and dropped real production targets from the filtered list.
$rowOf = static fn(array $over): array => array_merge([
    'average_sales' => 0.0, 'movement_rows' => 0,
    'branch_name' => 'B', 'product_name' => 'P', 'shift' => 'AM',
], $over);
$check('F3', 'a row with no sale and no real movement has no activity',
    dl_forecastRowHasActivity($rowOf([])) === false
    && dl_forecastRowHasActivity($rowOf(['movement_rows' => 3])) === true
    && dl_forecastRowHasActivity($rowOf(['average_sales' => 8.0])) === true
    && dl_forecastRowHasActivity($rowOf(['average_sales' => 0.0, 'movement_rows' => 2])) === true,
    'no sale + no movement => no activity; movement alone => activity; a sale alone => activity; a zero sale WITH movement => activity');

// ── F4: quantity sort, both directions, as a permutation ──────────────────────────────────────
$qtyOf = static fn(array $row): float => (float)($row['suggested_production'] ?? 0);
$down = dl_forecastRows($db, $filters('exclude', 'desc'), '2026-10-12', 14);
$up = dl_forecastRows($db, $filters('exclude', 'asc'), '2026-10-12', 14);
$monotonicDown = true;
$monotonicUp = true;
for ($i = 1; $i < count($down); $i++) {
    if ($qtyOf($down[$i]) > $qtyOf($down[$i - 1]) + 0.001) {
        $monotonicDown = false;
        break;
    }
}
for ($i = 1; $i < count($up); $i++) {
    if ($qtyOf($up[$i]) < $qtyOf($up[$i - 1]) - 0.001) {
        $monotonicUp = false;
        break;
    }
}
$sameRows = count($down) === count($up)
    && count(array_diff_key(
        array_fill_keys(array_map(static fn(array $r): string => $r['branch_id'] . ':' . $r['product_id'] . ':' . $r['shift'], $down), true),
        array_fill_keys(array_map(static fn(array $r): string => $r['branch_id'] . ':' . $r['product_id'] . ':' . $r['shift'], $up), true)
    )) === 0;
$check('F4', 'rows sort by quantity in both directions',
    $down !== [] && $monotonicDown && $monotonicUp && $sameRows,
    sprintf('desc: %s, asc: %s, same row set both ways: %s; top qty desc=%s, top qty asc=%s',
        $monotonicDown ? 'monotonic' : 'NOT monotonic', $monotonicUp ? 'monotonic' : 'NOT monotonic',
        $sameRows ? 'yes' : 'NO',
        $down === [] ? 'n/a' : number_format($qtyOf($down[0]), 2),
        $up === [] ? 'n/a' : number_format($qtyOf($up[0]), 2)));

// ── F5: the page actually offers the controls, and carries their state ─────────────────────────
// RE-ANCHORED 2026-10-12 (viewer surface W1/W14, chair ruling D1/D2): the direction control is now
// a <select name="sort_dir"> INSIDE the filter form; the two hand-written links were REMOVED
// because they dropped product_id, and the form had no sort control of its own. This pins the NEW
// basis - links returning would re-create the two-mechanism defect, and the select is the only
// control that lets the shipped primary oracle keep a selected product AND a direction at once.
$template = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/forecast.disyl');
$hasCheckbox = (bool)preg_match('/type="checkbox"[^>]*empty_rows/i', $template);
$hasSortControl = (bool)preg_match('/<select[^>]*name="sort_dir"/i', $template)
    && (bool)preg_match('/<option value="asc"/i', $template)
    && (bool)preg_match('/<option value="desc"/i', $template);
$check('F5', 'the page offers the checkbox and both sort directions',
    $hasCheckbox && $hasSortControl,
    sprintf('checkbox for empty_rows=%s; asc+desc sort control (select[name=sort_dir])=%s',
        $hasCheckbox ? 'present' : 'ABSENT', $hasSortControl ? 'present' : 'ABSENT'));

// ── F6: the default is the useful one, and it is stated ───────────────────────────────────────
$defaultMode = dl_forecastEmptyRowsMode(null);
$check('F6', 'an untouched page load hides the empty rows by default',
    $defaultMode === 'exclude' && dl_forecastEmptyRowsMode('include') === 'include',
    sprintf('absent => %s (expected exclude, matching the Overview\'s official-only convention); value "include" => %s',
        $defaultMode, dl_forecastEmptyRowsMode('include')));

// ── F7: the page RENDERS the controls and the filtered rows ─────────────────────────────────────
// A grep over the template proves the markup exists, not that the page renders it: an unclosed tag or
// a broken conditional would still pass F5. This renders the real handler and reads the outcome.
$tokens = dl_generateAuthTokens(['sub' => 'admin:1', 'id' => 1, 'username' => 'r', 'name' => 'R', 'role' => 'admin', 'source' => 'daily-ledger']);
$_COOKIE[dlCookieName()] = $tokens['token'];
$render = static function (array $extra = []): string {
    $_GET = array_merge(['date_from' => '2026-09-28', 'date_to' => '2026-10-11', 'window' => '14', 'target_date' => '2026-10-12'], $extra);
    $_REQUEST = $_GET;
    ob_start();
    try {
        handleAdminForecast();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }

    return (string)ob_get_clean();
};
$pageDefault = $render();
$pageInclude = $render(['empty_rows' => 'include']);
$pageAsc = $render(['sort_dir' => 'asc']);
$rowCountOf = static function (string $html): int {
    return substr_count($html, '<tr>');
};
$check('F7', 'the rendered page honours the checkbox and the sort',
    !str_contains($pageDefault, 'THREW:')
    && (bool)preg_match('/name="empty_rows"[^>]*value="exclude"[^>]*checked/i', $pageDefault)
    && (bool)preg_match('/<select[^>]*name="sort_dir"/i', $pageDefault)
    && (bool)preg_match('/<option value="asc"/i', $pageDefault)
    && (bool)preg_match('/<option value="desc"/i', $pageDefault)
    && $rowCountOf($pageInclude) > $rowCountOf($pageDefault)
    && $rowCountOf($pageDefault) > 0,
    sprintf('renders without a fatal: %s; checkbox checked by default: %s; asc+desc sort control rendered: %s; rows default=%d, with the checkbox off=%d (must be MORE)',
        str_contains($pageDefault, 'THREW:') ? 'NO - ' . substr($pageDefault, 0, 160) : 'yes',
        preg_match('/name="empty_rows"[^>]*value="exclude"[^>]*checked/i', $pageDefault) ? 'yes' : 'NO',
        (preg_match('/<option value="asc"/i', $pageDefault) && preg_match('/<option value="desc"/i', $pageDefault)) ? 'yes' : 'NO',
        $rowCountOf($pageDefault), $rowCountOf($pageInclude)));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
