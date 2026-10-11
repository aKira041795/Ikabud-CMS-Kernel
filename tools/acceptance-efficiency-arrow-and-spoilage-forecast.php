<?php
declare(strict_types=1);

/**
 * Acceptance criterion: per-product trend colour on the Products view, and a spoilage-adjusted
 * per-product production forecast.
 *
 * Owner requests, verbatim (2026-10-11):
 *   "arrow color, if up, green, down red at products view"
 *   "then set the forecast per product based on efficiency (spoilage rate). forecast is for the
 *    business to have an idea how much to produce with spoilage rate considered against sales"
 *
 * GROUNDED BEFORE CONTRACTING:
 *
 * Products view: it has NO performance arrow. Its only directional marks are the cumulative-share
 * SORT arrows in the header. So T1 requires the arrow to exist (a per-product trend against the
 * previous period) and T2 forbids colouring the sort control - a green sort arrow would assert
 * "good" where it only means "smallest first". The Health view already colours its own trend
 * (delta-up emerald / delta-down red), and this asks for the same reading on Products.
 *
 * Forecast: reporting.php:dl_forecastDemand($salesRows, $varianceRows, $inventoryRows,
 * $window = 14, $safetyRate = 0.10) applies ONE flat 10% safety rate to every product. That is not
 * spoilage - it is a margin. The standard gross-up for expected loss is
 *     target production = expected sales / (1 - spoilage rate)
 * so a product spoiling 20% must be produced at sales/0.8, and a flat 10% under-produces every
 * product that spoils worse than 10% - which is precisely the failure the owner is asking to fix.
 * The spoilage rate itself is now computed by the production-efficiency view (wastage/produced), so
 * this must REUSE that figure rather than introduce a second definition of spoilage.
 *
 * Chair-written and measured against the unchanged tree BEFORE dispatch.
 * Usage: php tools/acceptance-products-arrow-and-spoilage-forecast.php
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
    'sub' => 'admin:1', 'id' => 1, 'username' => 'arrow',
    'name' => 'Arrow', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

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
$forecast = static function (array $extra = []): string {
    $_GET = array_merge(['scope' => 'ALL'], $extra);
    $_REQUEST = $_GET;
    ob_start();
    try {
        handleAdminForecast();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }
    return (string)ob_get_clean();
};

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

$eff = static function (array $extra = []): string {
    $_GET = array_merge(['scope' => 'ALL', 'date_from' => '2026-09-01', 'date_to' => '2026-10-11'], $extra);
    $_REQUEST = $_GET;
    ob_start();
    try {
        handleAdminProductionEfficiency();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }
    return (string)ob_get_clean();
};

$prodHtml = $products();
$prodText = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($prodHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

// The owner corrected this: "thet's at efficiency". The arrow colour belongs on the EFFICIENCY view,
// so every arrow check below reads that view, not Products.
$effHtml = $eff();
$effText = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($effHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

// --- T1: a per-product DIRECTION indicator exists on the efficiency view -------------------------
$hasDirection = (bool)preg_match('/trend-(up|down)|delta-(up|down)|arrow-(up|down)|improve|worsen|direction/i', $effHtml)
    || (bool)preg_match('/[▲▼]\s*[0-9.,]+\s*%/', $effText);
$check('T1', 'the efficiency view shows a per-product direction indicator', $hasDirection,
    $hasDirection ? 'a direction indicator is rendered'
        : 'the only arrows present are the red "exceeds" WARNINGS (yield_exceeds, sell_through_exceeds); there is no direction indicator to colour');

// --- T2: green for improvement, red for deterioration -------------------------------------------
$hasGreen = (bool)preg_match('/(emerald|text-green|bg-green|green-)/i', $effHtml);
$hasRed = (bool)preg_match('/(rose|text-red|bg-red|red-)/i', $effHtml);
$check('T2', 'improvement renders green and deterioration renders red', $hasGreen && $hasRed,
    sprintf('green class present=%s, red class present=%s', $hasGreen ? 'yes' : 'NO', $hasRed ? 'yes' : 'NO'));

// --- T3: THE COLOUR MUST FOLLOW IMPROVEMENT, NOT RAW DIRECTION ----------------------------------
// This is the trap. "up = green" is correct for yield and sell-through, and EXACTLY BACKWARDS for
// wastage rate and residual: a rising wastage rate is a deterioration. A naive up/down colouring
// would paint the worst news on the page green. So the view must say which way each metric improves.
$invertedForLoss = (bool)preg_match('/lower is better|down is better|rising .{0,30}(worse|deteriorat)|higher .{0,30}(worse|deteriorat)/i', $effText)
    || (bool)preg_match('/wastage[^.]{0,80}(improves|better)[^.]{0,40}(down|lower|fall)/i', $effText)
    || (bool)preg_match('/direction[^.]{0,80}(metric|measure)[^.]{0,80}(improve|better)/i', $effText);
$check('T3', 'the colour follows improvement, and says so for loss metrics', $invertedForLoss,
    $invertedForLoss ? 'the page states which direction is an improvement'
        : 'nothing states the direction of improvement, so a rising WASTAGE RATE would be painted green - the worst news on the page reading as good');

// --- T4: the WARNING arrows must not be confused with the direction colour ----------------------
$keepsWarnings = (bool)preg_match('/yield_exceeds|sell_through_exceeds|exceeds/i', $effHtml);
$check('T4', 'the existing "exceeds" warnings stay distinguishable', $keepsWarnings,
    $keepsWarnings ? 'the exceedance warnings survive alongside the direction colour'
        : 'the warnings were removed instead of distinguished - they say "check this", not "improving"');

// --- T4: the forecast uses a PER-PRODUCT spoilage rate ------------------------------------------
$fcHtml = $forecast();
$fcText = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($fcHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$namesSpoilage = (bool)preg_match('/spoil|wastage rate|waste rate|shrink/i', $fcText);
$perProductRate = (bool)preg_match('/spoil|wastage|waste|shrink/i', $fcText)
    && (bool)preg_match('/[0-9]+(?:\.[0-9]+)?\s*%/', $fcText);
$check('T4', 'the forecast states a per-product spoilage rate', $namesSpoilage && $perProductRate,
    sprintf('spoilage vocabulary=%s, a percentage is shown with it=%s',
        $namesSpoilage ? 'yes' : 'NO', $perProductRate ? 'yes' : 'NO'));

// --- T5: the suggestion is the standard gross-up, not a flat margin -----------------------------
// target = expected / (1 - rate). A flat 10% for every product is the defect being replaced.
$flatRate = (bool)preg_match('/\b10\s*%\b|safety rate|safety margin/i', $fcText);
$statesFormula = (bool)preg_match('#(divided by|/|÷)\s*\(?\s*1\s*[-−]\s*#i', $fcText)
    || (bool)preg_match('/gross(ed)? up|adjusted for (expected )?(spoilage|loss|waste)/i', $fcText);
$check('T5', 'the forecast grosses up expected sales by the spoilage rate', $statesFormula && !$flatRate,
    sprintf('formula stated=%s; a flat safety rate still present=%s (it must be replaced by the product\'s own rate)',
        $statesFormula ? 'yes' : 'NO', $flatRate ? 'YES' : 'no'));

// --- T6: products with no measurable spoilage rate are stated, not silently defaulted -----------
$statesCoverage = (bool)preg_match('/no (spoilage|wastage|waste) (rate|history|data)|not (yet )?measurable|assumed|default/i', $fcText);
$check('T6', 'products without a measurable spoilage rate are stated explicitly', $statesCoverage,
    $statesCoverage ? 'the page says what it does for products with no rate'
        : 'silently defaults - a rate invented per product is worse than a stated gap');

// --- R: neither view regresses ------------------------------------------------------------------
$prodOk = str_contains($prodHtml, 'conic-gradient') && str_contains($prodText, 'cumulative share');
$effOk = (bool)preg_match('/yield|sell-through|wastage rate/i', $effText);
$fcOk = (bool)preg_match('/suggested production|forecast/i', $fcText);
$check('R', 'the products, efficiency and forecast views all still render', $prodOk && $effOk && $fcOk,
    sprintf('products pie+cumulative=%s, efficiency standards=%s, forecast table=%s',
        $prodOk ? 'yes' : 'NO', $effOk ? 'yes' : 'NO', $fcOk ? 'yes' : 'NO'));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
