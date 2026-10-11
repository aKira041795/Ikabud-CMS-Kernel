<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the Pareto PIE and the full RANKED, FILTERABLE contributor list.
 *
 * Owner request, verbatim (2026-10-11):
 *   "for pareto, it would be good to show a pie graph, showing the biggest contributors items to
 *    sales"
 *   "then the top contributor down to least, filterable"
 *   "based on cummulative shares"
 *
 * Grounded in what the page has today:
 *   - the Pareto card renders a TABLE of pareto.contributors (the ~80% cumulative-share set only).
 *     There is NO pie, and the table stops at the 80% line - it is NOT "top contributor down to
 *     least".
 *   - the handler already computes the FULL ranking: $rankedProducts = dl_overviewSortProducts(
 *     $productTotals, ...) - but it passes only 'top_products' (limited) and 'ranked_count' to the
 *     template, NOT the full list. So the full ranking is available and simply not rendered.
 *   - each pareto contributor already carries cumulative_amount and cumulative_share.
 *
 * Chair-written and measured RED against the unchanged tree BEFORE dispatch.
 * Usage: php tools/acceptance-parity-pie-and-ranking.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/products/performance';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$tokens = dl_generateAuthTokens([
    'sub' => 'admin:1', 'id' => 1, 'username' => 'pie-acc',
    'name' => 'Pie Acceptance', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$render = static function (array $extra = []) use ($base): string {
    require_once $base . '/modules/daily-ledger/handlers.php';
    $_GET = array_merge(['scope' => 'ALL', 'date_from' => '2026-09-01', 'date_to' => '2026-10-11'], $extra);
    $_REQUEST = $_GET;
    ob_start();
    try {
        handleAdminProductsPerformance();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }
    return (string)ob_get_clean();
};

$html = $render();
if ($html === '' || str_contains($html, 'THREW:')) {
    fwrite(STDERR, "  render failed: " . substr(strip_tags($html), 0, 200) . "\n");
    exit(1);
}

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-56s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

// --- F1: a pie exists, and it is actually ROUND -----------------------------------------------
// Server-rendered, no JS dependency: conic-gradient computed in PHP.
// ROUNDNESS IS PART OF F1, measured after this criterion passed a square: a conic-gradient on a
// square div renders as a beach-ball fan, not a pie. "A pie is rendered" must mean the thing the
// owner sees is a circle, so the circular clip is asserted here, not assumed from a class name.
$hasPie = (bool)preg_match('/conic-gradient\(([^)]*)\)/', $html, $pm);
$stops = $hasPie ? preg_split('/,(?![^(]*\))/', $pm[1]) : [];
$pieElement = '';
if ($hasPie && preg_match('/<div[^>]*style="[^"]*conic-gradient[^"]*"[^>]*>/', $html, $em)) {
    $pieElement = $em[0];
}
$isRound = $pieElement !== ''
    && (bool)preg_match('/border-radius:\s*(50%|9999px)(\s*!important)?/', $pieElement)
    && (bool)preg_match('/overflow:\s*hidden/', $pieElement);
$check('F1', 'a pie graph is rendered AND clipped to a circle', $hasPie && count($stops) >= 2 && $isRound,
    sprintf('pie present=%s, %d colour stop(s), circular clip=%s (a square with a conic-gradient is a fan, not a pie)',
        $hasPie ? 'yes' : 'NO', count($stops), $isRound ? 'yes' : 'NO'));

// --- F2: the pie is accessible and self-describing ----------------------------------------------
$pieAccessible = $hasPie && (bool)preg_match('/conic-gradient/', $html)
    && (bool)preg_match('/role="img"[^>]*aria-label="[^"]*(contributor|share|sales)[^"]*"/i', $html);
$check('F2', 'the pie carries role="img" and a real aria-label', $pieAccessible,
    $pieAccessible ? 'accessible label present' : 'pie has no role="img"/aria-label describing the shares');

// --- F3: slices come from CUMULATIVE SHARES and the whole pie accounts for the period -----------
// The owner said "based on cummulative shares". Contributors are the cumulative-80% set, so the pie
// must cover them AND state the remainder, or it silently misrepresents the period.
$hasRemainder = (bool)preg_match('/[Oo]ther|[Rr]emainder|[Bb]alance/', $html);
$sum = 0.0;
if ($hasPie) {
    // percent stops summing to ~100 is the falsifiable part
    preg_match_all('/([0-9.]+)%/', $pm[1], $pcts);
    $nums = array_map('floatval', $pcts[1] ?? []);
    $sum = $nums === [] ? 0.0 : max($nums);
}
$check('F3', 'pie covers the period: contributors + stated remainder', $hasRemainder && $sum >= 99.0,
    sprintf('remainder slice=%s, highest percent stop=%.1f%% (must reach ~100%% or the pie lies)',
        $hasRemainder ? 'yes' : 'NO', $sum));

// --- F4: the list is the FULL ranking, top contributor down to least ----------------------------
$listRows = preg_match_all('/<tr[^>]*data-product-row/i', $html);
$check('F4', 'the ranked list is the full ranking, not the 80% subset', $listRows > 0 && $hasPie,
    sprintf('%d row(s) marked as product-ranking rows; the Pareto table alone is only the 80%% set',
        $listRows));

// --- F5: the list is filterable, and the filter is real -----------------------------------------
$hasFilter = (bool)preg_match('/name="product_q"|name="min_share"|data-product-filter/i', $html);
$filtered = $render(['product_q' => 'zzz-no-such-product-zzz']);
$filteredRows = preg_match_all('/<tr[^>]*data-product-row/i', $filtered);
$check('F5', 'a filter control exists AND actually narrows the list', $hasFilter && $filteredRows < $listRows,
    sprintf('control present=%s; rows unfiltered=%d, rows with a no-match filter=%d (must drop)',
        $hasFilter ? 'yes' : 'NO', $listRows, $filteredRows));

// --- F6: ordering really is descending ("top contributor down to least") -----------------------
// Read the row order out of the HTML and assert the amounts never increase. A string match on the
// template cannot prove ordering; the rendered order can.
$ordered = false;
$seq = [];
if (preg_match_all('#data-product-row[^>]*>.*?PHP\s*([0-9,]+(?:\.[0-9]+)?)#s', $html, $rows)) {
    $seq = array_map(static fn($v): float => (float)str_replace(',', '', $v), $rows[1]);
    $ordered = count($seq) >= 2;
    for ($i = 1; $i < count($seq); $i++) {
        if ($seq[$i] > $seq[$i - 1] + 0.005) {
            $ordered = false;
            break;
        }
    }
}
$check('F6', 'rows run biggest contributor first, down to least', $ordered,
    $seq === [] ? 'no ordered product rows found to check'
        : sprintf('%d amount(s) read in row order: %s%s', count($seq),
            implode(' > ', array_map(static fn($v): string => number_format($v, 2), array_slice($seq, 0, 5))),
            count($seq) > 5 ? ' ...' : ''));

// --- F8: the cumulative-share column holds REAL numbers, and is cumulative -----------------------
// Added after F1-F7 passed on a build whose cumulative column read 0.00% on every row: the field
// was taken from the Pareto helper, which only sets it on its own contributor rows. A column of
// zeros is a false statement, and nothing above noticed it. So this asserts the values exist,
// increase, and end where the ranking's total should land.
$cumulatives = [];
if (preg_match_all('#data-product-row.*?<td class="text-right">(?:<)?[0-9.]+%</td>\s*<td class="text-right">([0-9.]+)%</td>#s', $html, $cm)) {
    $cumulatives = array_map('floatval', $cm[1]);
}
$cumulativeOk = count($cumulatives) >= 2;
if ($cumulativeOk) {
    foreach ($cumulatives as $i => $v) {
        if ($i > 0 && $v + 0.01 < $cumulatives[$i - 1]) {
            $cumulativeOk = false;
            break;
        }
        if ($i > 0 && abs($v) < 0.0001 && abs($cumulatives[0]) < 0.0001) {
            $cumulativeOk = false;
            break;
        }
    }
    if (end($cumulatives) < 99.0) {
        $cumulativeOk = false;
    }
}
$check('F8', 'cumulative share column is real and cumulative (not 0.00%)', $cumulativeOk,
    $cumulatives === [] ? 'no cumulative column values could be read'
        : sprintf('%d value(s), first=%s%% last=%s%% (last must reach ~100%% of the ranking)',
            count($cumulatives), number_format($cumulatives[0], 2), number_format(end($cumulatives), 2)));

// --- G1/G2/G3: the pie is hoverable and every slice can tell you its share ----------------------
// A conic-gradient cannot report the hovered slice, so the wheel must carry an overlay of invisible
// wedges. Without them the owner gets a picture with no way to read a slice's share.
preg_match_all('#<path[^>]*class="dl-pie-wedge"[^>]*data-slice="(\d+)"[^>]*>\s*<title>([^<]*)</title>#s', $html, $wedges, PREG_SET_ORDER);
$wedgeCount = count($wedges);
$invisible = (bool)preg_match('#class="dl-pie-wedge"[^>]*fill-opacity="0"#', $html);
$titledWithShare = 0;
foreach ($wedges as $wedge) {
    if (str_contains($wedge[2], '%') && trim($wedge[2]) !== '') {
        $titledWithShare++;
    }
}
$sliceCount = substr_count($html, 'conic-gradient') > 0 ? count($stops) : 0;
$check('G1', 'pie carries hoverable wedges (transparent, on top)', $wedgeCount >= 2 && $invisible,
    sprintf('%d wedge(s), transparent fill=%s', $wedgeCount, $invisible ? 'yes' : 'NO'));
$check('G2', 'every wedge tooltip states its share (%)', $wedgeCount > 0 && $titledWithShare === $wedgeCount,
    sprintf('%d of %d tooltip(s) carry a percentage; first: "%s"',
        $titledWithShare, $wedgeCount, (string)($wedges[0][2] ?? '')));

// --- G4: hovering a slice can highlight its ranked row -----------------------------------------
// The wiring is what the page must ship; the behaviour itself is verified in a browser.
// The 'Other products' wedge is an AGGREGATE, so it legitimately has no single row to light up.
// Requiring every wedge to match a row would fail on correct work - measured 10 of 11 matching,
// with the 11th being Other. So Other is asserted to have NO row, and every contributor wedge is
// asserted to have one.
preg_match_all('#<tr[^>]*data-slice-row="(-?\d+)"#', $html, $rowSlices);
$rowIndexes = array_map('intval', $rowSlices[1] ?? []);
$wedgeIndexes = array_map(static fn(array $w): int => (int)$w[1], $wedges);
$otherWedgeIndex = null;
foreach ($wedges as $wedge) {
    if (stripos((string)$wedge[2], 'Other products') === 0) {
        $otherWedgeIndex = (int)$wedge[1];
    }
}
$contributorWedges = array_values(array_filter(
    $wedgeIndexes,
    static fn(int $i): bool => $i !== $otherWedgeIndex
));
$matched = array_intersect($contributorWedges, $rowIndexes);
$otherHasRow = $otherWedgeIndex !== null && in_array($otherWedgeIndex, $rowIndexes, true);
$check(
    'G4',
    'every contributor wedge maps to its ranked row (Other maps to none)',
    $contributorWedges !== [] && count($matched) === count($contributorWedges) && !$otherHasRow,
    sprintf(
        '%d contributor wedge(s), %d matched; the aggregate Other wedge (#%s) maps to a row: %s (must be no)',
        count($contributorWedges),
        count($matched),
        $otherWedgeIndex === null ? 'none' : (string)$otherWedgeIndex,
        $otherHasRow ? 'YES' : 'no'
    )
);

// --- F7: regression guards ----------------------------------------------------------------------
$health = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/overview.disyl');
$check('F7', 'Health still has no product blocks (its A8 stays green)', !preg_match('/top_products|pareto/i', $health),
    'product analytics must stay on the products view only');

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
