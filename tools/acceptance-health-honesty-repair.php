<?php
declare(strict_types=1);

/**
 * Acceptance criterion: repair Sol's four high findings (docs/reviews/sol-health-review-2026-10-11.md).
 *
 * Sol returned CHANGES_REQUIRED with four findings, all severity high, each falsified by a direct
 * render. The chair's own criteria passed on every one of them, so these checks are deliberately
 * written around the falsifications Sol reported rather than around the intentions.
 *
 * S1  Trend arrows are fabricated. $trendComparable tests only whether the CURRENT period is entirely
 *     pending, so: 2026-08-15 vs an empty 2026-08-14 renders "▲ 0.0%" with previous PHP 0.00;
 *     no-row 2026-08-16 vs 2026-08-15 renders "▼ -100.0%" on a page that calls the day "silence, not
 *     underperformance"; and 2026-10-10, with 28 pending rows, renders "▼ -24.5%" as real performance.
 * S2  The three sales states are not distinct: 2026-10-11 says "Awaiting finalization" yet the pending
 *     branch row still renders PHP 0.00, and empty 2026-08-16 renders "PHP 0.00 official sales"
 *     instead of a no-sales state.
 * S3  Two re-anchored assertions are weaker than their names: "viewer page shows the configured net
 *     sales" examines $branchSummaryHtml rather than $viewerHtml, and the 0% hint test never examines
 *     $zeroNetHtml. A green suite therefore claims guidance the page does not render.
 * S4  Ascending cumulative recomputes correctly, but the heading still says "biggest contributor down
 *     to least" and the explanation still calls the 80% crossing the Pareto driver line. In ascending
 *     order that crossing is the accumulated long tail, not the drivers.
 *
 * Chair-written and measured against the unchanged tree BEFORE dispatch.
 * Usage: php tools/acceptance-health-honesty-repair.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/overview';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$tokens = dl_generateAuthTokens([
    'sub' => 'admin:1', 'id' => 1, 'username' => 'repair',
    'name' => 'Repair Acceptance', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$overview = static function (array $extra = []): string {
    $_GET = array_merge(['scope' => 'ALL'], $extra);
    $_REQUEST = $_GET;
    ob_start();
    try {
        handleAdminOverview();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }
    return (string)ob_get_clean();
};
$products = static function (array $extra = []): string {
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
$plain = static fn(string $h): string => (string)preg_replace(
    '/\s+/', ' ',
    html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')
);

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

// --- S1: no performance arrow against an empty or pending baseline ------------------------------
// The exact falsifications Sol reported, each rendered the same way it rendered them.
$cases = [
    'finalized vs EMPTY prior (2026-08-15)' => ['date_from' => '2026-08-15', 'date_to' => '2026-08-15'],
    'no-row day vs finalized (2026-08-16)' => ['date_from' => '2026-08-16', 'date_to' => '2026-08-16'],
    'mostly-pending day (2026-10-10)' => ['date_from' => '2026-10-10', 'date_to' => '2026-10-10'],
];
$offenders = [];
foreach ($cases as $label => $range) {
    $txt = $plain($overview($range));
    // An authoritative performance claim: a signed percentage attached to a trend reading.
    if (preg_match('/(▲|▼|\+|-)\s*[0-9]+(?:\.[0-9]+)?%/', $txt)) {
        $offenders[] = $label;
    }
}
$check('S1', 'no trend arrow/percentage against an empty or pending baseline', $offenders === [],
    $offenders === []
        ? 'none of the three falsified cases renders a signed percentage claim'
        : 'still claims a performance trend for: ' . implode(' | ', $offenders));

// --- S2: the three states are distinct ----------------------------------------------------------
$noneTxt = $plain($overview(['date_from' => '2026-08-16', 'date_to' => '2026-08-16']));
$pendingTxt = $plain($overview(['date_from' => '2026-10-11', 'date_to' => '2026-10-11']));
$finalTxt = $plain($overview(['date_from' => '2026-08-15', 'date_to' => '2026-08-15']));
$noRowState = (bool)preg_match('/no sales recorded|nothing recorded|no ledger rows/i', $noneTxt);
$noneNotZero = !preg_match('/PHP\s*0\.00\s*official/i', $noneTxt);
$pendingNotZero = !preg_match('/PHP\s*0\.00/i', $pendingTxt) || (bool)preg_match('/awaiting finalization|pending/i', $pendingTxt);
$finalIsNumeric = (bool)preg_match('/PHP\s*[1-9][0-9,]*\./', $finalTxt);
$check('S2', 'no-sales, pending and finalized are three distinct states', $noRowState && $noneNotZero && $finalIsNumeric,
    sprintf('no-row day says "no sales recorded"=%s; empty day avoids "PHP 0.00 official"=%s; finalized still numeric=%s; pending avoids a bare PHP 0.00=%s',
        $noRowState ? 'yes' : 'NO', $noneNotZero ? 'yes' : 'NO', $finalIsNumeric ? 'yes' : 'NO', $pendingNotZero ? 'yes' : 'NO'));

// --- S3: the assertions examine the surface they name -------------------------------------------
// The first version of this check PASSED while Sol's finding stood, because it only looked for
// $viewerHtml somewhere within 400 characters of "net sales" - which is a coincidence, not a test of
// the claim. A check that passes without testing the thing is the exact defect being repaired here,
// so this now reads the ARGUMENTS of the named assertion and requires it to reference $viewerHtml and
// NOT the branch-summary HTML Sol found it actually using.
$testFile = $base . '/tests/daily-ledger/daily_ledger_overview_test.php';
$testSrc = (string)file_get_contents($testFile);
$netBody = '';
if (preg_match("/'viewer page shows the configured net sales[^']*'(.*?)(?=\\\$h->test\\(|\\z)/s", $testSrc, $nm)) {
    $netBody = $nm[1];
}
$netUsesViewer = $netBody !== '' && str_contains($netBody, '$viewerHtml');
$netUsesBranch = $netBody !== '' && str_contains($netBody, '$branchSummaryHtml');
$zeroBody = '';
if (preg_match("/'0% net renders[^']*'(.*?)(?=\\\$h->test\\(|\\z)/s", $testSrc, $zm)) {
    $zeroBody = $zm[1];
}
$zeroUsesHtml = $zeroBody !== '' && str_contains($zeroBody, '$zeroNetHtml');
$check('S3', 'the named assertions examine the HTML they claim', $netUsesViewer && !$netUsesBranch && $zeroUsesHtml,
    sprintf('net-sales assertion body uses $viewerHtml=%s, uses $branchSummaryHtml=%s (must be no); zero-hint body uses $zeroNetHtml=%s',
        $netUsesViewer ? 'yes' : 'NO', $netUsesBranch ? 'YES (wrong surface)' : 'no', $zeroUsesHtml ? 'yes' : 'NO'));

// --- S4: ascending mode does not claim to be the biggest-first ranking --------------------------
$ascTxt = $plain($products(['sort_by' => 'cumulative', 'sort_dir' => 'asc']));
$ascHasWrongHeading = (bool)preg_match('/biggest contributor down to least/i', $ascTxt);
$ascSaysLongTail = (bool)preg_match('/long tail|smallest|least first|from the least/i', $ascTxt);
$descTxt = $plain($products(['sort_dir' => 'desc']));
$descKeepsHeading = (bool)preg_match('/biggest contributor down to least/i', $descTxt);
$check('S4', 'ascending mode describes itself as long-tail, not biggest-first', !$ascHasWrongHeading && $ascSaysLongTail && $descKeepsHeading,
    sprintf('ascending still says "biggest contributor down to least"=%s; names the long tail=%s; descending keeps its heading=%s',
        $ascHasWrongHeading ? 'YES (wrong)' : 'no', $ascSaysLongTail ? 'yes' : 'NO', $descKeepsHeading ? 'yes' : 'NO'));

// --- R: the existing surface must survive -------------------------------------------------------
$healthOk = (bool)preg_match('/how is the business doing/i', $plain($overview()));
$productsOk = (bool)preg_match('/conic-gradient/', $products());
$check('R', 'the Health and Products views still render', $healthOk && $productsOk,
    sprintf('health questions=%s, products pie=%s', $healthOk ? 'yes' : 'NO', $productsOk ? 'yes' : 'NO'));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
