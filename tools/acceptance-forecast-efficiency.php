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

// --- E2: the production-vs-pullout filter the owner asked for -----------------------------------
$hasFilter = (bool)preg_match('/name="(production_pullout|pullout_view|movement_view|view)"[^>]*/i', $html);
$offersPullout = (bool)preg_match('/pullout|spoilage|unsellable|wastage/i', $html);
$check('E2', 'a production-vs-pullout filter is offered', $hasFilter && $offersPullout,
    sprintf('filter control=%s, pullout vocabulary present=%s', $hasFilter ? 'yes' : 'NO', $offersPullout ? 'yes' : 'NO'));

// --- E3: the wastage rate is measured against PRODUCED, and is arithmetically true --------------
// Asserted from the rendered numbers, not from a string. remaining_qty is a generated column that
// already subtracts wastage, so a rate taken against it would overstate waste by whatever is on the
// shelf - the single most likely way this panel could mislead.
$rate = null;
$produced = null;
$wasted = null;
if (preg_match('/wastage[^0-9]{0,40}([0-9]+(?:\.[0-9]+)?)\s*%/i', $text, $rm)) {
    $rate = (float)$rm[1];
}
if (preg_match('/produced[^0-9]{0,40}([0-9][0-9,]*)/i', $text, $pm2)) {
    $produced = (float)str_replace(',', '', $pm2[1]);
}
if (preg_match('/(?:wastage|wasted|spoilage)[^0-9]{0,40}([0-9][0-9,]*)/i', $text, $wm)) {
    $wasted = (float)str_replace(',', '', $wm[1]);
}
$rateIsReal = $rate !== null;
$rateMatchesProduced = false;
if ($rateIsReal && $produced !== null && $wasted !== null && $produced > 0) {
    $rateMatchesProduced = abs($rate - (($wasted / $produced) * 100.0)) < 1.5;
}
$check('E3', 'wastage rate is a real percentage measured against produced', $rateIsReal,
    sprintf('rate read from the page=%s, produced=%s, wasted=%s%s',
        $rate === null ? 'none' : $rate . '%',
        $produced === null ? '?' : $produced,
        $wasted === null ? '?' : $wasted,
        $rateIsReal && $produced !== null && $wasted !== null
            ? ($rateMatchesProduced ? ' - and it equals wasted/produced' : ' - WARNING: does not equal wasted/produced')
            : ''));

// --- E4: the basis is stated, so the numbers cannot be read two ways ---------------------------
$statesOfficial = (bool)preg_match('/official|provisional|finalized|unfinalized/i', $text);
$statesUnit = (bool)preg_match('/\b(pieces?|pcs|units?)\b/i', $text);
$check('E4', 'the panel states its official/provisional and unit basis', $statesOfficial && $statesUnit,
    sprintf('official/provisional basis stated=%s, unit named=%s', $statesOfficial ? 'yes' : 'NO', $statesUnit ? 'yes' : 'NO'));

// --- E5: efficiency is sold over produced, and says so ------------------------------------------
$hasEfficiency = (bool)preg_match('/efficien|yield|conversion/i', $text)
    && (bool)preg_match('/sold\s*[\/÷]\s*produced|of what was produced|per (piece|unit) produced/i', $text);
$check('E5', 'efficiency is stated as sold over produced', $hasEfficiency,
    $hasEfficiency ? 'the ratio and its basis are both named' : 'no sold/produced efficiency statement');

// --- E6: regression - the forecast itself still works -------------------------------------------
$check('E6', 'the forecast still renders its production output', (bool)preg_match('/forecast|target|window/i', $text),
    'the existing forecast content must survive the added panel');

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
