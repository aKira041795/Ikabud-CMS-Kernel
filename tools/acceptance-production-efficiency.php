<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the PRODUCTION EFFICIENCY view - its own page, per the owner's pattern.
 *
 * Owner requests, in order (2026-10-11):
 *   "for the forecast, it would be good to also have a filter with production vs pullouts
 *    (spoilage/unsellable) so we get the efficiency data for the product produced vs sales vs
 *    wastage, aggreagted (if this is logical and useful)"
 *   "for forecast, separate this too in its own view"
 *
 * CHAIR DECISION (owner unavailable, asked to work autonomously). The owner's own pattern decides it:
 *   "we can separate the product level in its own view. don't integrate with reports. this will give
 *    the business the focus needed" - and now "separate this TOO in its own view".
 * Health, Products and Reports are each their own surface. "Too" means like those. So the efficiency
 * analysis becomes its own view at /daily-ledger/admin/production-efficiency, a peer of the others,
 * rather than a block inside the forecast page. The forecast page itself already exists and stays.
 *
 * The schema was measured before contracting, and it is what makes this worth building:
 *   dl_commissary_product_ledger.produced_qty / .wastage_qty (migration 037)
 *   .remaining_qty is GENERATED, and the invariant is already in the code
 *   (handlers.php:2896: beg_qty + produced_qty - dispatched_qty - wastage_qty)
 *   pullout reasons already exist as PulloutReturnReason, with 'expired' and 'spoilage'
 *   sales units come from dl_daily_ledger, the same integer quantity the product ranking sums
 * All integers in ONE unit, so sold/produced and wastage/produced are real ratios.
 *
 * THE THREE WAYS THIS VIEW COULD MISLEAD, which this criterion exists to prevent:
 *   1. wastage rate against remaining_qty instead of produced. remaining already subtracts wastage,
 *      so that would overstate waste by everything still on the shelf.
 *   2. money basis unstated - money on this module is official-only, so a panel mixing bases would
 *      disagree with every other figure in the module.
 *   3. unit unstated - "efficiency 82%" means nothing unless both sides are pieces.
 *
 * Chair-written and measured against the unchanged tree BEFORE dispatch.
 * Usage: php tools/acceptance-production-efficiency.php
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
    'sub' => 'admin:1', 'id' => 1, 'username' => 'eff',
    'name' => 'Efficiency', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$routes = (string)file_get_contents($base . '/modules/daily-ledger/routes.php');
$hasRoute = str_contains($routes, $route);
$handler = null;
if (preg_match("#" . preg_quote($route, '#') . "'\s*=>\s*'daily-ledger:([A-Za-z0-9_]+)#", $routes, $rm)) {
    $handler = $rm[1];
}

$render = static function (array $extra = []) use (&$handler): string {
    if ($handler === null || !function_exists($handler)) {
        return '';
    }
    $_GET = array_merge(['scope' => 'ALL', 'date_from' => '2026-09-01', 'date_to' => '2026-10-11'], $extra);
    $_REQUEST = $_GET;
    ob_start();
    try {
        $handler();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }
    return (string)ob_get_clean();
};

$html = $render();

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

$plain = static fn(string $h): string => (string)preg_replace(
    '/\s+/', ' ',
    html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')
);
$text = $plain($html);

// --- N1: the view exists as its own surface -----------------------------------------------------
$check('N1', 'a production-efficiency route is registered', $hasRoute,
    $hasRoute ? $route . ' -> ' . (string)$handler : 'no ' . $route . ' in routes.php');
$nav = (string)file_get_contents($base . '/templates/modules/daily-ledger/layouts/app.disyl');
$navHas = (bool)preg_match('#href="\{base_url\}/admin/production-efficiency"#', $nav);
$check('N2', 'the nav links to it, as a peer of Health/Products/Reports', $navHas,
    $navHas ? 'nav entry present' : 'no nav entry - the owner could not reach it by clicking');

// --- N3: a VIEWER can open it (the owner reviews as a viewer) -----------------------------------
$renders = $html !== '' && !str_contains($html, 'THREW:');
$viewerOk = false;
if ($renders && $handler !== null) {
    require_once $base . '/modules/daily-ledger/handlers.php';
    $vt = dl_generateAuthTokens([
        'sub' => 'viewer:21', 'id' => 21, 'username' => 'eff-viewer',
        'name' => 'Eff Viewer', 'role' => 'viewer', 'source' => 'daily-ledger',
    ]);
    $_COOKIE[dlCookieName()] = $vt['token'];
    $_GET = ['scope' => 'ALL', 'date_from' => '2026-09-01', 'date_to' => '2026-10-11'];
    $_REQUEST = $_GET;
    ob_start();
    try {
        $handler();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }
    $vHtml = (string)ob_get_clean();
    $viewerOk = $vHtml !== '' && !preg_match('/not authorised|forbidden|access denied|THREW/i', $vHtml);
}
$check('N3', 'a viewer can open it (the owner reviews as a viewer)', $viewerOk,
    $viewerOk ? 'rendered for a viewer without an authorisation refusal'
        : 'not reachable by a viewer, or the view does not render at all');

if (!$renders) {
    printf("\n  => %d RED. %s\n", $reds + 6, 'The view does not render yet: this is the work.');
    exit(1);
}

// --- E1: produced, sold and wasted together, aggregated -----------------------------------------
$check('E1', 'produced, sold and wasted appear together, aggregated',
    (bool)preg_match('/\bproduced\b/i', $text) && (bool)preg_match('/\b(wastage|wasted|spoilage|unsellable)\b/i', $text)
        && (bool)preg_match('/\b(sold|sales)\b/i', $text),
    sprintf('produced=%s wasted=%s sold=%s',
        preg_match('/\bproduced\b/i', $text) ? 'yes' : 'NO',
        preg_match('/\b(wastage|wasted|spoilage|unsellable)\b/i', $text) ? 'yes' : 'NO',
        preg_match('/\b(sold|sales)\b/i', $text) ? 'yes' : 'NO'));

// --- E2: the production-vs-pullout filter the owner asked for -----------------------------------
$hasFilter = (bool)preg_match('/name="(production_pullout|pullout_view|movement_view|view)"[^>]*/i', $html);
$offersPullout = (bool)preg_match('/pullout|spoilage|unsellable|wastage/i', $html);
$check('E2', 'a production-vs-pullout filter is offered', $hasFilter && $offersPullout,
    sprintf('filter control=%s, pullout vocabulary=%s', $hasFilter ? 'yes' : 'NO', $offersPullout ? 'yes' : 'NO'));

// --- E3: the wastage rate divides by PRODUCED ---------------------------------------------------
$rate = $produced = $wasted = null;
if (preg_match('/wastage[^0-9]{0,40}([0-9]+(?:\.[0-9]+)?)\s*%/i', $text, $rm2)) {
    $rate = (float)$rm2[1];
}
if (preg_match('/produced[^0-9]{0,40}([0-9][0-9,]*)/i', $text, $pm3)) {
    $produced = (float)str_replace(',', '', $pm3[1]);
}
if (preg_match('/(?:wastage|wasted|spoilage)[^0-9]{0,40}([0-9][0-9,]*)/i', $text, $wm2)) {
    $wasted = (float)str_replace(',', '', $wm2[1]);
}
$rateMatches = $rate !== null && $produced !== null && $wasted !== null && $produced > 0
    && abs($rate - (($wasted / $produced) * 100.0)) < 1.5;
$check('E3', 'the wastage rate is a real percentage measured against produced', $rateMatches,
    sprintf('rate=%s produced=%s wasted=%s%s', $rate === null ? 'none' : $rate . '%',
        $produced === null ? '?' : $produced, $wasted === null ? '?' : $wasted,
        $rate !== null ? ($rateMatches ? ' - equals wasted/produced' : ' - WARNING: not wasted/produced') : ''));

// --- E4: the basis is stated --------------------------------------------------------------------
$check('E4', 'the view states its official/provisional and unit basis',
    (bool)preg_match('/official|provisional|finalized/i', $text) && (bool)preg_match('/\b(pieces?|pcs|units?)\b/i', $text),
    sprintf('money basis=%s, unit named=%s',
        preg_match('/official|provisional|finalized/i', $text) ? 'yes' : 'NO',
        preg_match('/\b(pieces?|pcs|units?)\b/i', $text) ? 'yes' : 'NO'));

// --- E5: each ratio is named with its OWN basis, and sold/produced is gone ----------------------
// RE-ANCHORED 2026-10-11. The old E5 positively REQUIRED "sold over produced" in the text, so it
// blessed the defect (Sol's Q6). After the split it still passed - by matching the sentence that
// denies the ratio. It now requires both real measures to be named with their own denominators, and
// requires the blended sold/produced RATE to be absent.
$rateBasisOk = (bool)preg_match('/yield\s*%[^.]{0,90}theoretical output/i', $text)
    && (bool)preg_match('/stock conversion\s*%[^.]{0,90}received/i', $text)
    && !(bool)preg_match('/sell-through\s*%[^0-9%]{0,60}[0-9]/i', $text);
$check('E5', 'yield names the batch basis and conversion names received stock', $rateBasisOk,
    'the production measure must name the batch basis, the destination measure must name received stock, and no sell-through rate may render');

// --- E7: a supplying branch is named as supplied, not shown as producing nothing ----------------
// The opposite case of E1: having asked that produced/sold/wasted appear together, the page must also
// NOT present an absent production figure as a measured zero for a branch that is supplied by a
// commissary. Branch 8 (Miputak) is hybrid, assigned to commissary 18, and produced nothing.
$b8Text = $plain($render(['branch_id' => '8']));
$namesSupply = (bool)preg_match('/supplied by\s+[A-Za-z0-9\- ]+/i', $b8Text);
$noMeasuredZero = !(bool)preg_match('/produced\D{0,30}\b0\b/i', $b8Text);
$check('E7', 'a supplying branch is named as supplied, with no fabricated zero output',
    $namesSupply && $noMeasuredZero,
    sprintf('branch 8: supply source named=%s, no "0 produced" as a measurement=%s',
        $namesSupply ? 'yes' : 'NO', $noMeasuredZero ? 'yes' : 'NO'));

// --- E6: the forecast page itself survives ------------------------------------------------------
$fk = $handler;                       // prove the forecast was not replaced by this view
$fRoutes = (string)file_get_contents($base . '/modules/daily-ledger/routes.php');
$forecastStillThere = (bool)preg_match("#/daily-ledger/admin/forecast'\s*=>\s*'daily-ledger:handleAdminForecast#", $fRoutes);
$forecastHasEfficiency = (bool)preg_match('/production-efficiency/', (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/forecast.disyl'));
$check('E6', 'the forecast route still exists (this is a sibling, not a replacement)',
    $forecastStillThere && !$forecastHasEfficiency,
    sprintf('forecast route present=%s; forecast template does not host the efficiency block=%s',
        $forecastStillThere ? 'yes' : 'NO', $forecastHasEfficiency ? 'NO (it still does)' : 'yes'));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
