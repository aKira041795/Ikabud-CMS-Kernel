<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the overview's ALL-PERIODS gross sales summary, above the filters.
 *
 * Owner request, verbatim (2026-10-11):
 *   "at the overview view, what i'd like to be there aside from the filters and data, above it is
 *    the summary of sales (gross) for all periods (data is available). as a business owned, that
 *    what i'd like to, then a monthly and daily breakdown average"
 *
 * Read as: a gross-sales summary covering EVERY period (not just the selected one), placed ABOVE
 * the filter bar, with a monthly and a daily average.
 *
 * WHAT ALREADY EXISTS, measured rather than assumed:
 *   dl_overviewOfficialSalesTotal(DatabaseContract $db, array $filters): float - reporting.php:1403
 *   is the official-only sales total for a filter set. The all-periods figure is that helper with the
 *   date bounds removed, so this is reuse, not a new query. The overview already computes gross
 *   ($grandAmount) and derives net from it via dl_overviewNetSales(), so gross vs net is modelled.
 *
 * THE THREE WAYS THIS SUMMARY COULD LIE, which this criterion exists to prevent:
 *   1. AN AVERAGE WITH NO STATED DENOMINATOR. "PHP 4,120 a month" is meaningless without saying
 *      whether it divided by months WITH sales or by every calendar month since the first entry. This
 *      probe has 5 areas where 4 have no rows at all, so the two answers differ enormously and the
 *      owner would read the diluted one as decline.
 *   2. GROSS LABELLED AS NET, or the official-only basis left unstated. Money here is official-only -
 *      a provisional row contributes units but never an amount - so a summary omitting that would
 *      disagree with every other figure on the page.
 *   3. A LIFETIME TOTAL THAT SILENTLY IGNORES THE AREA FILTER, making the header contradict the view.
 *
 * Chair-written and measured RED against the unchanged tree BEFORE dispatch.
 * Usage: php tools/acceptance-overview-all-periods-summary.php
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
    'sub' => 'admin:1', 'id' => 1, 'username' => 'allperiods',
    'name' => 'All Periods', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$render = static function (array $extra = []): string {
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

$html = $render();
if ($html === '' || str_contains($html, 'THREW:')) {
    fwrite(STDERR, "  overview did not render: " . substr(strip_tags($html), 0, 200) . "\n");
    exit(1);
}

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

$text = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

// --- SCOPE EVERY CHECK TO THE NEW BLOCK -----------------------------------------------------------
// The first version of this criterion reported A1/A2/A3/A6 GREEN on the UNCHANGED tree: the word
// "All" matched the "All areas" option, the date span matched the selected period, and "10 branches"
// satisfied the day/month denominator regex. Checks that pass on the base discriminate nothing, so
// the summary must render inside a container this criterion can isolate, and every content check runs
// INSIDE it. That is the same structural contract as data-product-row on the ranked list.
$blockId = 'dl-all-periods-summary';
$blockStart = strpos($html, $blockId);
$block = '';
if ($blockStart !== false) {
    // Take the element and a generous window: enough to include its own contents, not the page.
    $block = substr($html, max(0, $blockStart - 400), 6000);
}
$blockText = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$hasBlock = $blockStart !== false;
$check('A0', 'the summary renders in its own identifiable block', $hasBlock,
    $hasBlock ? 'found #' . $blockId . ' at byte ' . $blockStart
        : 'no #' . $blockId . ' - the checks below cannot be scoped, and unscoped text matched this page before');

// --- A1: an all-periods gross summary exists ----------------------------------------------------
$hasAllPeriods = (bool)preg_match('/\ball[ -]?(time|periods?)\b/i', $blockText);
$hasGross = (bool)preg_match('/\bgross\b/i', $blockText);
$check('A1', 'an all-periods GROSS summary is present', $hasBlock && $hasAllPeriods && $hasGross,
    sprintf('inside the block: all-time/all-periods=%s, gross=%s', $hasAllPeriods ? 'yes' : 'NO', $hasGross ? 'yes' : 'NO'));

// --- A2: it sits ABOVE the filter bar ----------------------------------------------------------
// Measured by document position, not by reading the template: "above" is a layout claim.
$formPos = strpos($html, '<form');
$aboveFilters = $hasBlock && $formPos !== false && $blockStart < $formPos;
$check('A2', 'the summary renders above the filter bar', $aboveFilters,
    sprintf('block at byte %s, first <form> at byte %s - the summary must come first',
        $hasBlock ? (string)$blockStart : 'absent', $formPos === false ? '?' : (string)$formPos));

// --- A3: it covers a real span, not the selected period ----------------------------------------
// An all-periods figure must show the span it covers, or "all periods" is an unverifiable word.
$hasSpan = (bool)preg_match('/\b20\d{2}-\d{2}-\d{2}\b[^.]{0,80}\b20\d{2}-\d{2}-\d{2}\b/', $blockText);
$check('A3', 'the summary states the span it covers', $hasBlock && $hasSpan,
    $hasSpan ? 'two dates inside the block, so the coverage is checkable'
        : 'no date span inside the block - "all periods" cannot be verified by the reader');

// --- A4/A5/A6: monthly and daily averages, each with its denominator ---------------------------
$hasMonthly = (bool)preg_match('/averag\w*\s+(per\s+|a\s+)?month|monthly\s+average/i', $blockText);
$hasDaily = (bool)preg_match('/averag\w*\s+(per\s+|a\s+)?day|daily\s+average/i', $blockText);
// The denominator must be tied to the average, not merely a count on the page.
$hasDenominator = (bool)preg_match('/\d[\d,]*\s+(day|days|month|months)\b[^.]{0,60}(with|having|of sales|of trading|recorded|actual|traded)/i', $blockText)
    || (bool)preg_match('/(across|over|in|divided by|÷)\s*[\d,]+\s+(day|days|month|months)/i', $blockText);
$check('A4', 'a monthly average is given', $hasBlock && $hasMonthly, $hasMonthly ? 'monthly average inside the block' : 'no monthly average in the block');
$check('A5', 'a daily average is given', $hasBlock && $hasDaily, $hasDaily ? 'daily average inside the block' : 'no daily average in the block');
$check('A6', 'the averages state what they divided by', $hasBlock && $hasDenominator,
    $hasDenominator ? 'a day/month count is stated with the average'
        : 'no denominator tied to the average - an average without one is not verifiable');

// --- A7: the official-only basis is stated ------------------------------------------------------
$statesBasis = (bool)preg_match('/official|finalized|provisional/i', $blockText);
$check('A7', 'the money basis (official only) is stated', $hasBlock && $statesBasis,
    $statesBasis ? 'basis stated in the block' : 'unstated in the block - the page elsewhere is official-only, so a bare total would disagree with it');

// --- A8: the all-periods total respects the area/branch scope ----------------------------------
// Falsifiable: narrowing the scope must move the lifetime figure, or the header contradicts the view.
// It must compare against a scope that CAN differ. ALL versus AREA:2 cannot: every one of the 5355
// ledger rows in this probe sits in area_id=2, so the two are legitimately identical and the check
// would report a false red on correct work. AREA:1 (Dapitan) has no ledger rows at all, so a scoped
// figure there must be zero - a difference the data cannot hide.
$worldLifetime = null;
if (preg_match('#id="dl-all-periods-summary".*?PHP\s*([0-9][0-9,]*\.[0-9]{2})#s', $html, $wlm)) {
    $worldLifetime = (float)str_replace(',', '', $wlm[1]);
}
$narrowHtml = $render(['scope' => 'AREA:1']);
$narrowLifetime = null;
if (preg_match('#id="dl-all-periods-summary".*?PHP\s*([0-9][0-9,]*\.[0-9]{2})#s', $narrowHtml, $nlm)) {
    $narrowLifetime = (float)str_replace(',', '', $nlm[1]);
}
$scopeAware = $worldLifetime !== null && $narrowLifetime !== null
    && $worldLifetime > 0.0
    && $narrowLifetime < $worldLifetime;
$check('A8', 'the all-periods total respects the current area scope', $scopeAware,
    sprintf('ALL lifetime=%s, AREA:1 (no ledger rows) lifetime=%s - the scoped figure must be smaller, and ALL must not be zero',
        $worldLifetime === null ? '?' : number_format($worldLifetime, 2),
        $narrowLifetime === null ? '?' : number_format($narrowLifetime, 2)));

// --- A9: regression - the existing health content survives -------------------------------------
$hasVerdict = (bool)preg_match('/\b(healthy|attention|at risk|on track|declining|improving|stable)\b/i', $text);
$hasQuestions = (bool)preg_match('/how is the business doing|is it healthy|where can operations/i', $text);
$check('A9', 'the existing Health view content still renders', $hasVerdict && $hasQuestions,
    sprintf('verdict=%s, the five questions=%s', $hasVerdict ? 'yes' : 'NO', $hasQuestions ? 'yes' : 'NO'));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
