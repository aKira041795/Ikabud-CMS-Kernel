<?php
declare(strict_types=1);

/**
 * Acceptance criterion: NO STAT THAT LIES on the production-efficiency view.
 *
 * Owner, verbatim (2026-10-11):
 *   "some branches have production, some supplied by a commissary"
 *   "calculating production can be based from source (own production or commissary)"
 *   "i don't want to have stats that lie"
 *   "units sold cannot be more than the production"
 *   "the very first ledger entry of beginning and additional is a starting point. then moving
 *    forward, daily additional data is the production data"
 *
 * WHY THE OLD CRITERION COULD NOT CATCH THIS. tools/acceptance-production-efficiency.php asserts
 * that words appear (produced, wastage, sold) and that SOME percentage equals wasted/produced. Every
 * one of those passed while the page reported 1162.38% sell-through and -1020.25% shrinkage, because
 * a page can be complete and still be arithmetically false. The missing check was never "does the
 * panel exist" - always "can the number be believed".
 *
 * MEASURED ON THE UNCHANGED TREE (tenant 207, 2026-09-01..2026-10-11), which is the base these
 * checks are written against:
 *
 *   dl_productionEfficiencyData() all-branch scope:
 *     produced 8828 · sold 102615 · sell_through_pct 1162.38 · shrinkage_pct -1020.25
 *     yield_pct null · production_days 4 · products 178 · product_exceeds 31 · sold_zero_production 94
 *
 *   why that is a lie and not just an oddity:
 *     - the 8828 produced units belong to ONE branch (18 RIZAL-COMMIS) over FOUR days;
 *     - the 102615 sold units belong almost entirely to branch 8 (95704) and branch 9 (6911),
 *       which are SUPPLIED BY that commissary and produce nothing themselves;
 *     - the two sides are merged by product id alone, so the ratio divides retail sales by
 *       commissary output over windows that do not match.
 *
 *   the attribution evidence the page ignores (all measured):
 *     - dl_branches: 2 commissaries (18, 21), 11 hybrid assigned to 18, 6 commissary_supplied
 *       assigned to 21, 2 self_managed unassigned;
 *     - posted receivings: 100,632 units, of which 96,928 (96.3%) are origin_type='commissary'
 *       and 3,704 are branch transfers - ZERO local production;
 *     - dl_commissary_product_ledger: 447 rows, branch 18 only, 4 days, beg_qty 77,896,
 *       produced 8,828, dispatched 12,547, wasted 119. Its generated remaining_qty is -3,838
 *       because the GENERATED column omits opening stock; beg+produced-dispatched-wasted = 74,058.
 *
 * THE FOUR WAYS THIS VIEW STILL LIES IF THE CHECKS BELOW ARE NOT MET:
 *   1. a ratio above 100% rendered as an efficiency, which is a defect presenting as a triumph;
 *   2. a supplying branch rendering "0 produced" as a MEASURED zero (it was supplied, not idle);
 *   3. a ratio whose denominator is absent rendering as a number rather than as a named state;
 *   4. a stock figure that omits opening stock and therefore reads as a deficit.
 *
 * Written and measured against the unchanged tree BEFORE the change. Every check below is RED today.
 * Usage: php tools/acceptance-truthful-production-stats.php
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

require_once $base . '/modules/daily-ledger/helpers/reporting.php';

$tokens = dl_generateAuthTokens([
    'sub' => 'admin:1', 'id' => 1, 'username' => 'truth',
    'name' => 'Truth', 'role' => 'admin', 'source' => 'daily-ledger',
]);
$_COOKIE[dlCookieName()] = $tokens['token'];

$routes = (string)file_get_contents($base . '/modules/daily-ledger/routes.php');
$handler = null;
if (preg_match("#" . preg_quote($route, '#') . "'\s*=>\s*'daily-ledger:([A-Za-z0-9_]+)#", $routes, $rm)) {
    $handler = $rm[1];
}

/** Render the view for a given query scope. */
$render = static function (array $extra = []) use ($handler): string {
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

$plain = static fn(string $h): string => (string)preg_replace(
    '/\s+/', ' ',
    html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')
);

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-60s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

$allHtml = $render();
$allText = $plain($allHtml);
$b8Text = $plain($render(['branch_id' => '8']));
$b18Text = $plain($render(['branch_id' => '18']));

if ($allHtml === '' || str_contains($allHtml, 'THREW:')) {
    printf("\n  => the view does not render at all; every check below is RED.\n  %s\n", substr($allHtml, 0, 400));
    exit(1);
}

// --- T1: a rate above 100% must never be rendered as an achieved efficiency --------------------
// The page may legitimately SHOW such a figure as a named finding; it may not present it under a
// performance label as the result. Today it renders "Sell-through % 1162.38%" as a headline.
// Figures render through number_format:2, so thousands separators are present ("1,162.38%").
$num = '([0-9][0-9,]*(?:\.[0-9]+)?)';
$suspect = [];
foreach ([
    'sell-through' => '/sell-through\s*%?[^0-9%]{0,60}' . $num . '\s*%/i',
    'efficiency' => '/efficien\w*\s*%?[^0-9%]{0,60}' . $num . '\s*%/i',
    'conversion' => '/conversion\s*%?[^0-9%]{0,60}' . $num . '\s*%/i',
] as $label => $pattern) {
    if (preg_match($pattern, $allText, $m)) {
        $value = (float)str_replace(',', '', $m[1]);
        if ($value > 100.0) {
            $suspect[] = $label . '=' . $m[1] . '%';
        }
    }
}
$check('T1', 'no rate above 100% is rendered as an efficiency', $suspect === [],
    $suspect === []
        ? 'no sell-through/efficiency/conversion figure above 100% is present'
        : 'PRESENTED AS PERFORMANCE: ' . implode(', ', $suspect)
          . ' - sold (destination flow) divided by produced (source flow) over non-matching windows');

// --- T2: the stock-in denominator is named, with its day coverage beside it --------------------
// Owner: "daily additional data is the production data". Whichever series the view divides by, it
// must name it and say how many days carry it, or the ratio has no visible basis.
$namesInflow = (bool)preg_match('/stock\s*in|addt.?l|additional|received|stock received/i', $allText);
$statesDays = (bool)preg_match('/\b([0-9]+)\s*(day|days)\b/i', $allText)
    && (bool)preg_match('/(day|days)[^.]{0,80}(record|cover|produc|received|stock)/i', $allText);
$check('T2', 'the stock-in denominator is named and its day coverage stated', $namesInflow && $statesDays,
    sprintf('denominator named=%s, day coverage stated beside it=%s',
        $namesInflow ? 'yes' : 'NO', $statesDays ? 'yes' : 'NO'));

// --- T3: a supplying branch names its source and never shows a measured zero output ------------
// Branch 8 Miputak: mode=hybrid, assigned_commissary_id=18. It received 83,783 units and sold
// 95,704. It produces nothing. "Produced 0 pcs" reads as "this branch produced nothing and this is
// a fact about its production", when the fact is "this branch is supplied by RIZAL-COMMIS".
$b8NamesSource = (bool)preg_match('/RIZAL-COMMIS|commissary|supplied by/i', $b8Text);
$b8ZeroAsMeasured = (bool)preg_match('/produced\D{0,30}\b0\b\s*(pcs|pieces)?/i', $b8Text);
$check('T3', 'a supplying branch names its source and shows no measured zero output',
    $b8NamesSource && !$b8ZeroAsMeasured,
    sprintf('branch 8: source named=%s, "0 produced" presented as measured=%s',
        $b8NamesSource ? 'yes' : 'NO', $b8ZeroAsMeasured ? 'YES (the lie)' : 'no'));

// --- T4: the source panel includes opening stock, so stock is never a phantom deficit ----------
// The generated remaining_qty (produced - dispatched - wastage) omits beg_qty and sums to -3,838
// for branch 18, where the book balance beg+produced-dispatched-wasted is 74,058. Asserted on the
// DATA, not on prose: a regex over prose can be satisfied by a sentence that explains the bug
// while the number stays wrong.
$dataB18 = dl_productionEfficiencyData(module()->db(), [
    'accessible_branch_ids' => [18],
    'branch_id' => 18,
    'date_from' => '2026-09-01',
    'date_to' => '2026-10-11',
    'product_id' => 0,
    'shift' => '',
]);
$closingB18 = $dataB18['closing_calculated'] ?? null;
$openingB18 = $dataB18['opening'] ?? null;
$closingStateB18 = (string)($dataB18['closing_state'] ?? '');
// Either the balance closes with a non-negative figure, or it does NOT close and says so. What is
// never acceptable is a NEGATIVE stock number, or a silent zero.
$closingHonest = ($closingB18 === null && $closingStateB18 !== '')
    || (is_int($closingB18) && $closingB18 >= 0);
$check('T4', 'closing stock counts opening stock and never goes negative',
    $closingHonest && is_int($openingB18) && !array_key_exists('net_remaining', $dataB18),
    sprintf('branch 18: opening=%s, calculated closing=%s, closing state=%s',
        var_export($openingB18, true), var_export($closingB18, true),
        $closingStateB18 === '' ? '(closes)' : $closingStateB18));

// --- T5: a COVERED day with zero output is distinguished from a day with no record ------------
// Measured for branch 18: the production ledger has 4 row-covered days, of which only 3 carry
// positive output. `COUNT(DISTINCT ledger_date)` alone reports 4 and silently equates "a day was
// recorded" with "output happened". Until the view states both counts, "no production recorded in
// this period" and "output was zero on every recorded day" are indistinguishable - and the second
// reads as "we made nothing", which is the lie.
// Sol's design requires the same distinction: "Distinguish row-covered days from positive-output
// days" (docs/reviews/sol-production-attribution-2026-10-11.md).
$coveredDays = null;
$positiveDays = null;
if (preg_match('/\b([0-9]+)\s*(?:day|days)\b[^.]{0,60}(?:record|cover)/i', $allText, $cm)) {
    $coveredDays = (int)$cm[1];
}
if (preg_match('/([0-9]+)\s*(?:day|days)\b[^.]{0,60}(?:positive|output|produced)/i', $allText, $pm)) {
    $positiveDays = (int)$pm[1];
}
$check('T5', 'row-covered days are distinguished from positive-output days',
    $coveredDays !== null && $positiveDays !== null && $coveredDays !== $positiveDays,
    sprintf('covered-days stated=%s, positive-output days stated=%s (measured: 4 covered, 3 positive)',
        $coveredDays === null ? 'NO' : (string)$coveredDays,
        $positiveDays === null ? 'NO' : (string)$positiveDays));

// --- T6: the answer is attributed BY SOURCE, at data level ------------------------------------
// Sol's finding (docs/reviews/sol-production-attribution-2026-10-11.md, C1 CONFIRMED): the
// aggregation never resolves supply_source_type / default_supply_mode / assigned_commissary_id.
// This check asserts the DATA the page is built from is attributed, not merely that the page talks
// about sources - a label without the underlying split would be worse than the current silence.
$data = dl_productionEfficiencyData(module()->db(), [
    'accessible_branch_ids' => [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28],
    'branch_id' => 0,
    'date_from' => '2026-09-01',
    'date_to' => '2026-10-11',
    'product_id' => 0,
    'shift' => '',
]);
$hasAttribution = array_key_exists('sources', $data)
    && is_array($data['sources'])
    && $data['sources'] !== []
    && array_key_exists('has_producer_in_scope', $data)
    && array_key_exists('supplied_labels', $data)
    && array_key_exists('received', $data);
$ratioIsSourceScoped = !isset($data['sell_through_pct'])
    || $data['sell_through_pct'] === null
    || (float)$data['sell_through_pct'] <= 100.0;
$check('T6', 'the aggregation attributes by source and no ratio exceeds 100%',
    $hasAttribution && $ratioIsSourceScoped,
    sprintf('attribution keys present=%s; sell_through_pct=%s',
        $hasAttribution ? 'yes' : 'NO (the aggregation is still source-blind)',
        isset($data['sell_through_pct']) ? var_export($data['sell_through_pct'], true) : 'unset (removed)'));

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
