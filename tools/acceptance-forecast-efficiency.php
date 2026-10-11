<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the FORECAST view's production-vs-pullout efficiency block.
 *
 * Owner request, verbatim (2026-10-11):
 *   "for the forecast, it would be good to also have a filter with production vs pullouts
 *    (spoilage/unsellable) so we get the efficiency data for the product produced vs sales vs
 *    wastage, aggreagted (if this is logical and useful)"
 *
 * CHAIR'S ANSWER TO "is this logical and useful": YES, and the schema already carries it. Measured
 * before contracting:
 *   - dl_commissary_product_ledger holds produced_qty, wastage_qty (migration 037) and a GENERATED
 *     remaining_qty, all INT, next to beg_qty/actual_end_qty (migration 064).
 *   - the production invariant is already written down in handlers.php:2896 as
 *     beg_qty + produced_qty - dispatched_qty - wastage_qty
 *   - pullout reasons are already vocabulary: PulloutReturnReason, with 'expired' and 'spoilage'
 *   - sales units come from dl_daily_ledger, the same integer quantity the product ranking sums.
 * Because produced, dispatched, wasted and sold are all integers in ONE unit, sold/produced and
 * wastage/produced are real ratios and not a pcs-vs-boxes artefact. That is what makes this worth
 * building rather than a misleading graph.
 *
 * THE HONESTY REQUIREMENTS this criterion exists to enforce:
 *   1. wastage rate must be wastage / PRODUCED. Treating remaining_qty as waste would overstate it
 *      by everything still sitting on the shelf, and remaining is a generated column that already
 *      subtracts wastage.
 *   2. the official-vs-provisional basis must be stated: money on this module is official-only, so
 *      an efficiency panel that mixes bases would disagree with the rest of the module.
 *   3. the unit must be named. "Efficiency 82%" means nothing unless the reader knows both sides are
 *      pieces.
 *
 * Chair-written and measured RED against the unchanged tree BEFORE dispatch.
 * Usage: php tools/acceptance-forecast-efficiency.php
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

$tokens = dl_generateAuthTokens([
    'sub' => 'admin:1', 'id' => 1, 'username' => 'eff-acc',
    'name' => 'Efficiency Acceptance', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$render = static function (array $extra = []): string {
    $_GET = array_merge(['scope' => 'ALL', 'date_from' => '2026-09-01', 'date_to' => '2026-10-11'], $extra);
    $_REQUEST = $_GET;
    ob_start();
    try {
        handleAdminForecast();
    } catch (Throwable $e) {
        echo 'THREW: ' . $e->getMessage();
    }
    return (string)ob_get_clean();
};

$html = $render();
if ($html === '' || str_contains($html, 'THREW:')) {
    fwrite(STDERR, "  forecast view did not render: " . substr(strip_tags($html), 0, 200) . "\n");
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

// --- E1: produced, sold and wasted are shown together -------------------------------------------
// The whole point of the request: the three figures side by side, aggregated.
$hasProduced = (bool)preg_match('/\bproduced\b/i', $text);
$hasWasted = (bool)preg_match('/\b(wastage|wasted|spoilage|unsellable)\b/i', $text);
$hasSold = (bool)preg_match('/\b(sold|sales)\b/i', $text);
$check('E1', 'produced, sold and wasted appear together, aggregated', $hasProduced && $hasWasted && $hasSold,
    sprintf('produced=%s wasted=%s sold=%s', $hasProduced ? 'yes' : 'NO', $hasWasted ? 'yes' : 'NO', $hasSold ? 'yes' : 'NO'));

// --- E2: the forecast page must NOT host the production-vs-pullout filter ----------------------
// RE-ANCHORED 2026-10-11. This file was written when the plan was a block ON the forecast page; the
// recorded decision (see the header of acceptance-production-efficiency.php) made it its own peer
// view instead. The old E2 required the block to be HERE, so it demanded the reversed design and was
// measured 4 RED before the source/destination change (verified by stashing the change and re-running).
// The live requirement is the opposite, and it is worth guarding: the analysis must not be re-inlined
// into the forecast page, because it would then be read as forecast output.
$hasFilter = (bool)preg_match('/name="(production_pullout|pullout_view|movement_view)"[^>]*/i', $html);
$hasEfficiencyBlock = (bool)preg_match('/production-efficiency/i', (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/forecast.disyl'));
$check('E2', 'the forecast page does NOT host the production-vs-pullout block', !$hasFilter && !$hasEfficiencyBlock,
    sprintf('forecast filter control present=%s, efficiency block linked from the forecast template=%s ' . '(the peer view owns it)',
        $hasFilter ? 'YES (it must not)' : 'no', $hasEfficiencyBlock ? 'YES (it must not)' : 'no'));

// --- E3: no wastage rate is measured against produced ON the forecast page ---------------------
// The forecast page has no production ledger, so any "wastage %" there could only be assembled from
// the same non-matching populations the efficiency view used to blend. It must show none.
$rate = null;
if (preg_match('/wastage[^0-9]{0,40}([0-9]+(?:\.[0-9]+)?)\s*%/i', $text, $rm)) {
    $rate = (float)$rm[1];
}
$check('E3', 'the forecast page states no wastage rate of its own', $rate === null,
    $rate === null
        ? 'no wastage rate is rendered on the forecast page - it belongs to the efficiency view'
        : 'a wastage rate of ' . $rate . '% is rendered here; the forecast page has no production ledger to support one');

// --- E4: the forecast states the horizon its numbers come from ---------------------------------
$statesHorizon = (bool)preg_match('/window|history|target/i', $text);
$check('E4', 'the forecast states its window/target horizon', $statesHorizon,
    $statesHorizon ? 'a window/target horizon is stated' : 'the forecast does not state the horizon its averages come from');

// --- E5: the forecast page must not present sold/produced as an efficiency ----------------------
// RE-ANCHORED with E2: the old E5 positively required "sold over produced" here. The page must not
// carry the blended ratio at all, in either direction.
$statesBlendedRatio = (bool)preg_match('/sold\s*[\/÷]\s*produced/i', $text);
$check('E5', 'the forecast page states no sold/produced ratio', !$statesBlendedRatio,
    $statesBlendedRatio
        ? 'a sold/produced ratio is rendered on the forecast page; that ratio blends a destination flow with a source flow'
        : 'no sold/produced ratio is rendered here - the peer view reports conversion over received stock instead');

// --- E6: regression - the forecast itself still works -------------------------------------------
$check('E6', 'the forecast still renders its production output', (bool)preg_match('/forecast|target|window/i', $text),
    'the existing forecast content must survive the added panel');

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
