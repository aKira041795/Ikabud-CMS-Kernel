<?php
declare(strict_types=1);

/**
 * Acceptance criterion: industry-standard efficiency formulas, and the forecast alongside them.
 *
 * Owner request, verbatim (2026-10-11):
 *   "use an industry standard formula to accurately compute data at products efficiency.
 *    also, forecast must be there too"
 *
 * WHAT THE VIEW COMPUTES TODAY, measured: efficiency_pct = sold / produced * 100
 * (reporting.php:2175 and :2257). That is SELL-THROUGH, which is a standard retail/food measure - but
 * it is not production efficiency, and the page calls it "Efficiency".
 *
 * THE INDUSTRY-STANDARD PRODUCTION EFFICIENCY IS YIELD, and this repo already carries its formula and
 * every column it needs:
 *   migration 016 states it outright: "Theoretical yield = FLOOR(input_qty / batch_input_qty)
 *   * output_pieces_per_batch", with batch_input_qty on dl_products
 *   input_qty is recorded per batch (migration 011, commissary production tables)
 *   produced_qty / wastage_qty (037), remaining_qty GENERATED (064), dispatched_qty
 *   sold units come from dl_daily_ledger
 *
 * THE STANDARD SET THIS CRITERION REQUIRES:
 *   Yield %         output / input * 100                      - true production efficiency
 *   Sell-through %  sold / produced * 100                     - demand conversion (today's figure)
 *   Waste rate %    wasted / produced * 100
 *   Shrinkage %     (produced - sold - wasted - remaining) / produced * 100   - unexplained loss
 *   Mass balance    produced = sold + wasted + remaining + unaccounted
 *
 * AND ONE THING IT FORBIDS: OEE (Availability x Performance x Quality) is the other industry-standard
 * efficiency measure, and it CANNOT be computed here - there is no downtime or rate/speed data in this
 * schema. Claiming a standard the data cannot support is worse than not claiming it, so F5 forbids it.
 *
 * Chair-written and measured against the unchanged tree BEFORE dispatch.
 * Usage: php tools/acceptance-efficiency-standards.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$route = '/daily-ledger/admin/production-efficiency';
$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = $route;

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');

$tokens = dl_generateAuthTokens([
    'sub' => 'admin:1', 'id' => 1, 'username' => 'std',
    'name' => 'Standards', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$routes = (string)file_get_contents($base . '/modules/daily-ledger/routes.php');
$handler = null;
if (preg_match("#" . preg_quote($route, '#') . "'\s*=>\s*'daily-ledger:([A-Za-z0-9_]+)#", $routes, $rm)) {
    $handler = $rm[1];
}

$html = '';
if ($handler !== null && function_exists($handler)) {
    $_GET = ['scope' => 'ALL', 'date_from' => '2026-09-01', 'date_to' => '2026-10-11'];
    $_REQUEST = $_GET;
    ob_start();
    try {
        $handler();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }
    $html = (string)ob_get_clean();
}

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

if ($html === '' || str_contains($html, 'THREW:')) {
    printf("  [RED ] %-58s %s\n", 'the efficiency view renders at all', 'it does not render');
    exit(1);
}
$text = (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

// --- F1: YIELD, the standard production-efficiency measure, is present and defined ---------------
$hasYield = (bool)preg_match('/\byield\b/i', $text);
$definesYield = (bool)preg_match('/yield[^.]{0,140}(output|produced)[^.]{0,60}(input|batch)/i', $text);
$check('F1', 'yield is shown AND its formula is stated', $hasYield && $definesYield,
    sprintf('yield named=%s, formula stated (output over input / batch basis)=%s',
        $hasYield ? 'yes' : 'NO', $definesYield ? 'yes' : 'NO'));

// --- F2: sell-through is named as sell-through, not as "efficiency" -----------------------------
// The distinction matters: sold/produced measures demand conversion, not production efficiency.
$hasSellThrough = (bool)preg_match('/sell[- ]?through|conversion rate/i', $text);
$check('F2', 'sold/produced is named sell-through, not "efficiency"', $hasSellThrough,
    $hasSellThrough ? 'sell-through (or conversion) is named'
        : 'the sold/produced figure is still presented as efficiency, which conflates demand with production');

// --- F3: waste rate and shrinkage are both present ----------------------------------------------
$hasWasteRate = (bool)preg_match('/waste|scrap|spoilage/i', $text) && (bool)preg_match('/\d+(\.\d+)?\s*%/i', $text);
$hasShrinkage = (bool)preg_match('/shrink|unexplained|unaccounted|unreconciled/i', $text);
$check('F3', 'waste rate and shrinkage (unexplained loss) are both shown', $hasWasteRate && $hasShrinkage,
    sprintf('waste rate=%s, shrinkage/unaccounted=%s (unexplained loss is the control an owner needs)',
        $hasWasteRate ? 'yes' : 'NO', $hasShrinkage ? 'yes' : 'NO'));

// --- F4: the mass balance is stated -------------------------------------------------------------
// produced = sold + wasted + remaining + unaccounted. Without it the ratios cannot be reconciled.
$hasBalance = (bool)preg_match('/produced[^.]{0,120}(sold|sales)[^.]{0,80}(wast|spoilage|wasted)/i', $text)
    || (bool)preg_match('/mass balance|reconcil/i', $text);
$check('F4', 'the production mass balance is stated', $hasBalance,
    $hasBalance ? 'the identity produced = sold + wasted + remaining + unaccounted is present'
        : 'no mass balance - the ratios cannot be reconciled against each other');

// --- F5: OEE must NOT be claimed ----------------------------------------------------------------
// This schema has no downtime or speed data, so OEE is not computable. Claiming it would be the
// worst outcome of a request for "industry standard": a named standard the numbers cannot support.
$claimsOee = (bool)preg_match('/\bOEE\b|overall equipment effectiveness/i', $html);
$check('F5', 'OEE is not claimed (no downtime/rate data exists for it)', !$claimsOee,
    $claimsOee ? 'the view claims OEE, which this schema cannot compute - remove it or state its inputs'
        : 'no OEE claim');

// --- F6: the forecast is reachable from here ----------------------------------------------------
// "also, forecast must be there too" - the forecast stays a view of its own AND is reachable from
// the efficiency view, so an owner reading output against plan does not have to know the route.
$forecastRoute = str_contains($routes, '/daily-ledger/admin/forecast');
$linksForecast = (bool)preg_match('#href="[^"]*/admin/forecast[^"]*"#', $html)
    || (bool)preg_match('/forecast/i', $text);
$check('F6', 'the forecast is present/reachable from the efficiency view', $forecastRoute && $linksForecast,
    sprintf('forecast route exists=%s, referenced on this view=%s',
        $forecastRoute ? 'yes' : 'NO', $linksForecast ? 'yes' : 'NO'));

// --- R: the view's existing honesty notes survive ------------------------------------------------
$keepsBasis = (bool)preg_match('/official|provisional/i', $text) && (bool)preg_match('/\b(pieces?|pcs)\b/i', $text);
$check('R', 'the view still states its official/provisional and pieces basis', $keepsBasis,
    'the basis statements from E4 must survive the formula change');

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
