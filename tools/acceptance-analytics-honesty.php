<?php
declare(strict_types=1);

/**
 * Acceptance criterion: the defects Sol found in the analytics review.
 *
 * Source: /tmp/lane-sol-analytics-review-openai-codex_gpt-5.6-sol.log
 * Verdict FALSIFIED of "the analytics are production grade", with these measured findings:
 *
 *  F2  The Overview's per-branch units query used `dl_overviewPendingPredicate()` while the AMOUNT
 *      beside it used `dl_provisionalSqlExpr()`. Units showed 102,615 inclusive and were labelled
 *      "official, finalized rows"; the official population is 86,267. Two populations in one card.
 *  F6  "Physical count: 45,692" and "counted variance 5,465" were SUMS of repeated daily snapshots.
 *      A closing count is a LEVEL: the latest-per-product values are 22,216 and 3,957. Summing levels
 *      is the class of lie P1 removed from closing stock.
 *  F7  "Yield coverage 0 of 178" used every product with production, sales or receipts as its
 *      denominator, but only 36 products had positive production. All 41 runs also have
 *      primary_input_qty=0 and no produced product has a batch basis, so yield is UNAVAILABLE.
 *  F8  Fourteen movement-only rows (no measured sales at all) issued POSITIVE production
 *      suggestions, because a variance flag became expected demand on a zero average.
 *  F2b The efficiency "Sold" card said "official + provisional sales units" while rendering the
 *      official figure.
 *
 * Usage: php tools/acceptance-analytics-honesty.php
 */

$base = dirname(__DIR__);
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/admin/production-efficiency';

$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
$app->templates()->enableCompiledMode(false);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
modulePushContext('daily-ledger');
require_once $base . '/modules/daily-ledger/helpers/reporting.php';

$db = module()->db();
$allBranchIds = [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28];
$filters = [
    'accessible_branch_ids' => $allBranchIds, 'branch_id' => 0,
    'date_from' => '2026-09-01', 'date_to' => '2026-10-11',
    'product_id' => 0, 'shift' => '',
];

$reds = 0;
$check = static function (string $id, string $what, bool $ok, string $detail) use (&$reds): void {
    if (!$ok) {
        $reds++;
    }
    printf("  [%s] %-62s %s\n", $ok ? 'PASS' : 'RED ', $what, $ok ? '' : '<-- not satisfied');
    printf("      %s\n", $detail);
};

$data = $dl = dl_productionEfficiencyData($db, $filters + ['pending_rows_mode' => 'exclude']);

// --- S1: units and the money beside them are the SAME population --------------------------------
$tokens = dl_generateAuthTokens(['sub' => 'admin:1', 'id' => 1, 'username' => 's', 'name' => 'S', 'role' => 'admin', 'source' => 'daily-ledger']);
$_COOKIE[dlCookieName()] = $tokens['token'];
$render = static function (string $route, callable $handler, array $extra = []): string {
    $_SERVER['REQUEST_URI'] = $route;
    $_GET = array_merge(['scope' => 'ALL', 'date_from' => '2026-09-01', 'date_to' => '2026-10-11'], $extra);
    $_REQUEST = $_GET;
    ob_start();
    try { $handler(); } catch (Throwable $e) { echo 'THREW: ' . $e->getMessage(); }
    return (string)ob_get_clean();
};
$plain = static fn(string $h): string => (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

$overviewHtml = $render('/daily-ledger/admin/overview', 'handleAdminOverview');
$overviewText = $plain($overviewHtml);
$unitsCard = null;
if (preg_match('/Units sold\D{0,40}([0-9][0-9,]*)/i', $overviewText, $m)) {
    $unitsCard = (float)str_replace(',', '', $m[1]);
}
// Independently measured official units for this window.
$officialUnits = 0; $inclusiveUnits = 0;
foreach (dl_reportSalesData($db, $filters + ['pending_rows_mode' => 'include'])['rows'] as $row) {
    $u = max(0, (int)($row['sales'] ?? 0));
    $inclusiveUnits += $u;
    if (!dl_rowIsProvisional($row)) { $officialUnits += $u; }
}
$check('S1', 'the Overview units headline is the official population it claims',
    $unitsCard !== null && (int)$unitsCard === $officialUnits,
    sprintf('Overview "Units sold" renders %s; official=%s, inclusive=%s',
        $unitsCard === null ? 'not found' : number_format($unitsCard),
        number_format($officialUnits), number_format($inclusiveUnits)));

// --- S2: a closing count is a LEVEL, not a sum of daily snapshots --------------------------------
$countedRows = $db->prepare(
    "SELECT COUNT(*) FROM dl_commissary_product_ledger
      WHERE ledger_date BETWEEN ? AND ? AND actual_end_qty IS NOT NULL"
);
$countedRows->execute(['2026-09-01', '2026-10-11']);
$snapshotCount = (int)$countedRows->fetchColumn();
$physical = $data['physical_end'] ?? null;
$check('S2', 'physical ending is the latest count per product, not a snapshot sum',
    is_int($physical) && $physical > 0 && $physical < 40000 && $snapshotCount > 100,
    sprintf('physical_end=%s (a snapshot SUM over %d counted rows gives ~45,692; latest-per-product is ~22,216)',
        var_export($physical, true), $snapshotCount));

// --- S3: yield coverage is measured against the PRODUCED population -----------------------------
$produced = (int)($data['produced'] ?? 0);
$yieldTotal = (int)($data['yield_products_total'] ?? 0);
$check('S3', 'yield coverage counts products that actually produced',
    $produced > 0 && $yieldTotal > 0 && $yieldTotal < 100,
    sprintf('yield_products_total=%d (178 counts sales/receipt-only products; only products with positive production belong)',
        $yieldTotal));

// --- S4: a row with NO measured sales never gets a positive suggestion --------------------------
$forecast = dl_forecastRows($db, [
    'accessible_branch_ids' => $allBranchIds, 'branch_id' => 0,
    'date_from' => '2026-09-01', 'date_to' => '2026-10-11',
    'product_id' => 0, 'shift' => '', 'pending_rows_mode' => 'include',
], '2026-10-12', 14);
$positiveWithNoSales = [];
foreach ($forecast as $row) {
    if ((int)($row['sample_days'] ?? 0) === 0 && (int)($row['suggested_production'] ?? 0) > 0) {
        $positiveWithNoSales[] = (int)$row['product_id'] . '@' . (int)$row['branch_id'];
    }
}
$check('S4', 'a row with no measured sales gets no production suggestion',
    $positiveWithNoSales === [],
    sprintf('%d movement-only row(s) still suggest production (e.g. %s) - a variance flag is not demand',
        count($positiveWithNoSales), implode(', ', array_slice($positiveWithNoSales, 0, 3))));

// --- S5: the efficiency Sold card states the basis it is actually rendering ---------------------
$efficiencyText = $plain($render('/daily-ledger/admin/production-efficiency', 'handleAdminProductionEfficiency', ['pending_rows' => 'exclude']));
$contradicts = (bool)preg_match('/official\s*\+\s*provisional/i', $efficiencyText);
$check('S5', 'no card claims "official + provisional" while rendering official figures',
    !$contradicts,
    $contradicts
        ? 'the Sold card says "official + provisional sales units" while the figure is official-only'
        : 'the Sold card no longer claims a basis it is not rendering');

printf("\n  => %d RED. %s\n", $reds, $reds > 0
    ? 'The criterion discriminates: this is the work.'
    : 'WARNING: every criterion passes - it cannot discriminate a change.');
exit($reds > 0 ? 1 : 0);
