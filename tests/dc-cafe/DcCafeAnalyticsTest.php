<?php
/**
 * DC Cafe — viewer role and sales analytics.
 *
 * The viewer is a read-only reporting role: sales overall and per branch, best
 * sellers, the Pareto split, and a weekly/monthly projection. It is a branch
 * setting and it is off by default, so a branch that never asked for it has a
 * login it cannot reach rather than a page it cannot open.
 *
 * Two things are worth stating plainly about the numbers, because both are
 * deliberate and both could look like bugs:
 *
 *   1. Products that did not sell are ABSENT, not zero. The catalogue is large
 *      and a list of everything a branch does not sell is noise.
 *   2. Branches that sold nothing are PRESENT, as zero. There are only a few
 *      branches and a silent one is a fact worth seeing.
 *
 * These tests guard:
 *   1. the setting exists, is a checkbox, and is off by default
 *   2. the role policy reads that setting rather than hardcoding a list
 *   3. the maths, against hand-computed answers
 *   4. a projection states its own confidence and does not pass off a
 *      part-finished period as a complete one
 *   5. a viewer is refused at sign-in while the switch is off
 *   6. a viewer is refused the operational endpoints even when it is on
 *   7. the pages actually render
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-analytics', TestHarness::MODE_INTEGRATION, 'dccafe.test');

// A suite that dies half way must not exit 0. The kernel's exception handler
// renders an HTML page and exits cleanly, which would otherwise look green.
$completed = false;
register_shutdown_function(static function () use (&$completed): void {
    if (!$completed) {
        fwrite(STDERR, "\nSUITE ABORTED before the end — treat as FAIL\n");
        exit(1);
    }
});
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../src/helpers/module-registry.php';
require_once __DIR__ . '/../../modules/dc-cafe/helpers.php';

$h->fingerprint('modules/dc-cafe/module.json');
$h->fingerprint('modules/dc-cafe/helpers.php');
$h->fingerprint('modules/dc-cafe/helpers/analytics.php');
$h->fingerprint('modules/dc-cafe/handlers.php');
$h->fingerprint('modules/dc-cafe/handlers-analytics.php');
$h->fingerprint('modules/dc-cafe/routes.php');
$h->fingerprint('templates/modules/dc-cafe/dashboard.disyl');
$h->fingerprint('templates/modules/dc-cafe/partials/analytics.disyl');
$h->fingerprint('templates/modules/dc-cafe/reports/index.disyl');
$h->fingerprint('templates/modules/dc-cafe/layouts/app.disyl');

$read = static fn(string $p): string => (string) @file_get_contents(__DIR__ . '/../../' . $p);

$manifest = json_decode($read('modules/dc-cafe/module.json'), true) ?: [];
$helpers = $read('modules/dc-cafe/helpers.php');
$analyticsSrc = $read('modules/dc-cafe/helpers/analytics.php');
$analyticsHandlers = $read('modules/dc-cafe/handlers-analytics.php');
$routes = $read('modules/dc-cafe/routes.php');
$layout = $read('templates/modules/dc-cafe/layouts/app.disyl');
$partial = $read('templates/modules/dc-cafe/partials/analytics.disyl');
$dashboardTpl = $read('templates/modules/dc-cafe/dashboard.disyl');

// No database handle is taken here on purpose. dc-cafe is a tenant-owned module,
// and app()->db() in CLI hands back the BASE database — whose dc_users enum has
// no 'viewer' in it at all. The Live Requests section below takes a handle on the
// tenant's own database instead.

/**
 * Drive a request through the real front controller in a subprocess, so routing,
 * auth and DiSyL are all exercised rather than called directly.
 *
 * @return array{status:int,body:string,json:mixed}
 */
function dcAnalyticsRequest(string $method, string $uri, ?array $user, ?array $body = null, ?string $cookie = null): array
{
    $base = '/var/www/html/applicationostest';
    $encoded = $body !== null ? http_build_query($body) : '';
    $runnerPath = sys_get_temp_dir() . '/dccafe-analytics-' . bin2hex(random_bytes(6)) . '.php';

    $script = "<?php\n"
        . "\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . ";\n"
        . "\$_SERVER['REQUEST_URI'] = " . var_export($uri, true) . ";\n"
        . "\$_SERVER['HTTP_HOST'] = 'dccafe.test';\n"
        . "\$_SERVER['SERVER_NAME'] = 'dccafe.test';\n"
        . "\$_SERVER['HTTP_ACCEPT'] = 'application/json';\n"
        . "\$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';\n"
        . "\$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';\n"
        . ($cookie !== null ? "\$_SERVER['HTTP_COOKIE'] = " . var_export($cookie, true) . ";\n" : '')
        . "\$_GET = [];\n"
        . "parse_str((string) parse_url(" . var_export($uri, true) . ", PHP_URL_QUERY), \$_GET);\n"
        . "\$_POST = [];\n"
        . "if (" . var_export($encoded, true) . " !== '') { parse_str(" . var_export($encoded, true) . ", \$_POST); }\n"
        . "\$_REQUEST = array_merge(\$_GET, \$_POST);\n"
        . "require " . var_export($base . '/bootstrap.php', true) . ";\n"
        . "\$u = " . var_export($user, true) . ";\n"
        . "if (is_array(\$u)) { app()->setUser(\$u); }\n"
        . "register_shutdown_function(static function (): void {\n"
        . "    echo \"\\n__R__\\n\";\n"
        . "    echo json_encode(['status' => (int) (http_response_code() ?: 200), 'headers' => headers_list()], JSON_UNESCAPED_SLASHES);\n"
        . "});\n"
        . "require " . var_export($base . '/public/index.php', true) . ";\n";

    file_put_contents($runnerPath, $script);
    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPath);

    $text = implode("\n", $output);
    $parts = explode("\n__R__\n", $text, 2);
    $meta = json_decode((string) ($parts[1] ?? ''), true);
    $bodyText = trim((string) ($parts[0] ?? ''));

    return [
        'status' => (int) (is_array($meta) ? ($meta['status'] ?? 0) : 0),
        'headers' => (array) (is_array($meta) ? ($meta['headers'] ?? []) : []),
        'body' => $bodyText,
        'json' => json_decode($bodyText, true),
    ];
}

// ── 1. The switch ──
$h->section('Branch Setting, Off By Default');

$fields = [];
foreach ((array) ($manifest['settings_fields'] ?? []) as $field) {
    if (is_array($field) && isset($field['key'])) {
        $fields[(string) $field['key']] = $field;
    }
}
$viewerField = $fields['pos_viewer_dashboard_enabled'] ?? null;
$h->test('the viewer surface is declared as a branch setting', is_array($viewerField), json_encode(array_keys($fields)));
$h->test(
    'it is a checkbox',
    is_array($viewerField) && ($viewerField['type'] ?? '') === 'checkbox',
    json_encode($viewerField)
);
$h->test(
    'it is off by default',
    is_array($viewerField) && (string) ($viewerField['default'] ?? '') === '0',
    (string) ($viewerField['default'] ?? '')
);
$h->test(
    'the policy helper reads the setting rather than hardcoding a list',
    (bool) preg_match('/function dcViewerDashboardEnabled\(\)[\s\S]{0,160}dcSettingBool\(\'pos_viewer_dashboard_enabled\'\)/', $analyticsSrc)
);
$h->test(
    'the viewer role is in the role policy only through that helper',
    (bool) preg_match('/function dcAnalyticsRoles\(\)[\s\S]{0,400}dcViewerDashboardEnabled\(\)/', $analyticsSrc)
);

// The role has to be assignable, or an administrator can define it and never use it.
// Two handlers validated against a hand-written list that did not include it, so the
// app refused the role the database accepts.
$h->test(
    'a user can actually be given the viewer role',
    in_array('viewer', dcAssignableRoles(), true),
    implode(', ', dcAssignableRoles())
);
$h->test(
    'user management no longer validates against a hand-written role list',
    !str_contains($read('modules/dc-cafe/handlers.php'), "['admin', 'supervisor', 'auditor', 'cashier']")
);
$h->test(
    'and the user form offers it',
    str_contains($read('templates/modules/dc-cafe/settings/index.disyl'), '<option value="viewer">')
);

// PHP 8.4 deprecates fputcsv() without the $escape argument, and every row the export
// wrote was doing that — one deprecation per line, hundreds per export, straight into
// the error log.
$h->test(
    'the CSV export writes rows through a helper that passes the escape parameter',
    str_contains($analyticsSrc, "fputcsv(\$handle, \$fields, ',', '\"', '\\\\')")
);
$h->test(
    'and no call site omits it',
    !str_contains($analyticsHandlers, 'fputcsv($out')
);

// ── 2. Range ──
$h->section('Period Range');

$default = dcAnalyticsRange(null, null);
$h->test('no arguments means a recent window, not everything', $default['days'] >= 28 && $default['days'] <= 31, (string) $default['days']);
$h->test('it does not report itself as clamped when it was not', $default['clamped'] === false, json_encode($default['clamped']));
$h->test('the window ends today', $default['to'] === date('Y-m-d'), (string) $default['to']);

$swapped = dcAnalyticsRange('2026-03-31', '2026-03-01');
$h->test('reversed dates are swapped rather than rejected', $swapped['from'] === '2026-03-01' && $swapped['to'] === '2026-03-31', json_encode($swapped));

$clamped = dcAnalyticsRange('2015-01-01', '2026-01-01');
$h->test('an unreasonable span is clamped', $clamped['clamped'] === true, json_encode($clamped));
$h->test('and the clamp is disclosed rather than hidden', $clamped['days'] === DC_ANALYTICS_MAX_DAYS && (int) $clamped['max_days'] === DC_ANALYTICS_MAX_DAYS, (string) $clamped['days']);

// ── 3. Top products, against hand-computed answers ──
$h->section('Top Products');

$rows = [
    ['product_id' => 1, 'name' => 'Alpha', 'qty' => 10, 'revenue' => 100.0, 'orders' => 4],
    ['product_id' => 2, 'name' => 'Bravo', 'qty' => 5, 'revenue' => 300.0, 'orders' => 3],
    ['product_id' => 3, 'name' => 'Charlie', 'qty' => 30, 'revenue' => 100.0, 'orders' => 9],
];

$byRevenue = dcAnalyticsTopProducts($rows, 10, 'revenue');
$h->test('revenue ranking puts the biggest first', ($byRevenue[0]['name'] ?? '') === 'Bravo', json_encode(array_column($byRevenue, 'name')));
$h->test('a 300 of 500 share is reported as 60 percent', (float) ($byRevenue[0]['revenue_share_pct'] ?? 0) === 60.0, (string) ($byRevenue[0]['revenue_share_pct'] ?? ''));
$h->test('rank is 1-based and contiguous', array_column($byRevenue, 'rank') === [1, 2, 3], json_encode(array_column($byRevenue, 'rank')));

$byQty = dcAnalyticsTopProducts($rows, 10, 'qty');
$h->test('unit ranking orders differently, as it should', ($byQty[0]['name'] ?? '') === 'Charlie', json_encode(array_column($byQty, 'name')));
$h->test('a 30 of 45 unit share is reported as 66.7 percent', (float) ($byQty[0]['qty_share_pct'] ?? 0) === 66.7, (string) ($byQty[0]['qty_share_pct'] ?? ''));
$h->test('shares are rounded to one decimal, consistently', (float) ($byRevenue[1]['revenue_share_pct'] ?? 0) === 20.0, (string) ($byRevenue[1]['revenue_share_pct'] ?? ''));

$limited = dcAnalyticsTopProducts($rows, 2, 'revenue');
$h->test('the limit is honoured', count($limited) === 2, (string) count($limited));

$h->test('no rows means an empty ranking, not a crash', dcAnalyticsTopProducts([], 10, 'revenue') === [], 'empty');
$h->test(
    'each ranked product reports how many orders it appeared in',
    array_key_exists('orders', $byRevenue[0]) && (int) $byRevenue[0]['orders'] === 3,
    json_encode($byRevenue[0])
);

// ── 4. Pareto, against hand-computed answers ──
$h->section('Pareto');

$pareto = dcAnalyticsPareto($rows, 0.8);
// Bravo 300 -> 60%, Alpha 100 -> 80%, Charlie 100 -> 100%. Two products reach 80%.
$h->test('the vital few is the count that reaches the cut', (int) $pareto['vital_few'] === 2, (string) $pareto['vital_few']);
$h->test('the whole catalogue is counted', (int) $pareto['total_products'] === 3, (string) $pareto['total_products']);
$h->test('the cut is reported as a percentage', (float) $pareto['cut_pct'] === 80.0, (string) $pareto['cut_pct']);
$h->test('total revenue is the sum of the rows', (float) $pareto['revenue_total'] === 500.0, (string) $pareto['revenue_total']);
$h->test(
    'cumulative percentages are 60, 80, 100',
    array_map(static fn(array $r): float => (float) $r['cumulative_pct'], $pareto['rows']) === [60.0, 80.0, 100.0],
    json_encode(array_column($pareto['rows'], 'cumulative_pct'))
);
$h->test(
    'cumulative revenue is a running total',
    array_map(static fn(array $r): float => (float) $r['cumulative_revenue'], $pareto['rows']) === [300.0, 400.0, 500.0],
    json_encode(array_column($pareto['rows'], 'cumulative_revenue'))
);
$h->test('the rows are ordered by revenue descending', array_column($pareto['rows'], 'name') === ['Bravo', 'Alpha', 'Charlie'], json_encode(array_column($pareto['rows'], 'name')));
$h->test('a cut of 50 percent needs only the top product', (int) dcAnalyticsPareto($rows, 0.5)['vital_few'] === 1, (string) dcAnalyticsPareto($rows, 0.5)['vital_few']);

$zeroRevenue = dcAnalyticsPareto([
    ['product_id' => 9, 'name' => 'Free', 'qty' => 4, 'revenue' => 0.0, 'orders' => 1],
], 0.8);
$h->test('free items cannot carry revenue, so they are not ranked', (int) $zeroRevenue['total_products'] === 0, (string) $zeroRevenue['total_products']);

// ── 5. Forecast, against hand-computed answers ──
$h->section('Forecast');

/** @param array<int, float> $revenues */
$mkBuckets = static function (array $revenues, int $incomplete = 0): array {
    $out = [];
    foreach ($revenues as $i => $rev) {
        $out[] = [
            'key' => 'b' . $i,
            'label' => 'Period ' . ($i + 1),
            'revenue' => $rev,
            'complete' => $i < (count($revenues) - $incomplete),
        ];
    }
    return $out;
};

$rising = dcAnalyticsProjection($mkBuckets([10.0, 20.0, 30.0]), 4);
// A line through (0,10),(1,20),(2,30) is 10 + 10n, so the next periods are 40,50,60,70.
$h->test(
    'a rising line is continued, not flattened',
    array_map(static fn(array $p): float => (float) $p['revenue'], $rising['projection']) === [40.0, 50.0, 60.0, 70.0],
    json_encode(array_column($rising['projection'], 'revenue'))
);
$h->test('the trend is named as the method used', ($rising['method'] ?? '') === 'trend', (string) ($rising['method'] ?? ''));
$h->test('the mean of 10,20,30 is 20', (float) $rising['average'] === 20.0, (string) $rising['average']);
$h->test('the periods projected are numbered from 1', array_column($rising['projection'], 'period') === [1, 2, 3, 4], json_encode(array_column($rising['projection'], 'period')));

// All three periods must have sales for a trend to be fitted at all, so the
// decline is 100,60,20 rather than 100,50,0 — a period with no sales at all is
// correctly not an observation, and would fall back to the mean instead.
$falling = dcAnalyticsProjection($mkBuckets([100.0, 60.0, 20.0]), 4);
$h->test('a steep decline is still fitted as a trend', ($falling['method'] ?? '') === 'trend', (string) ($falling['method'] ?? ''));
$h->test(
    'a projection is never negative',
    array_filter(array_column($falling['projection'], 'revenue'), static fn($r): bool => (float) $r < 0.0) === [],
    json_encode(array_column($falling['projection'], 'revenue'))
);
$h->test('and it does floor at zero', (float) $falling['projection'][0]['revenue'] === 0.0, (string) $falling['projection'][0]['revenue']);

$twoPeriods = dcAnalyticsProjection($mkBuckets([10.0, 10.0]), 4);
$h->test('two periods are too few to call a trend', ($twoPeriods['method'] ?? '') === 'average', (string) ($twoPeriods['method'] ?? ''));
$h->test(
    'so it repeats the mean instead of extrapolating',
    array_map(static fn(array $p): float => (float) $p['revenue'], $twoPeriods['projection']) === [10.0, 10.0, 10.0, 10.0],
    json_encode(array_column($twoPeriods['projection'], 'revenue'))
);

$withPartial = dcAnalyticsProjection($mkBuckets([10.0, 20.0, 30.0, 5.0], 1), 4);
$h->test('a part-finished period is not counted as an observation', (int) $withPartial['periods_with_sales'] === 3, (string) $withPartial['periods_with_sales']);
$h->test('and that exclusion is reported', (int) $withPartial['periods_excluded_partial'] === 1, (string) $withPartial['periods_excluded_partial']);
$h->test('and it is said out loud in the note', str_contains(strtolower((string) $withPartial['note']), 'not counted'), (string) $withPartial['note']);
$h->test(
    'the half-finished period does not drag the projection down',
    (float) $withPartial['projection'][0]['revenue'] === 40.0,
    (string) $withPartial['projection'][0]['revenue']
);

$threeSales = dcAnalyticsProjection($mkBuckets([10.0, 10.0, 10.0]), 2);
$h->test('three trading periods is not enough confidence to claim much', ($threeSales['confidence'] ?? '') === 'insufficient', (string) ($threeSales['confidence'] ?? ''));

$fourSales = dcAnalyticsProjection($mkBuckets([10.0, 10.0, 10.0, 10.0]), 2);
$h->test('four trading periods is low confidence', ($fourSales['confidence'] ?? '') === 'low', (string) ($fourSales['confidence'] ?? ''));

$eightSales = dcAnalyticsProjection($mkBuckets([10.0, 10.0, 10.0, 10.0, 10.0, 10.0, 10.0, 10.0]), 2);
$h->test('eight trading periods is medium confidence', ($eightSales['confidence'] ?? '') === 'medium', (string) ($eightSales['confidence'] ?? ''));

$noSales = dcAnalyticsProjection($mkBuckets([0.0, 0.0, 0.0]), 0);
$h->test('periods with no sales at all are not observations', (int) $noSales['periods_with_sales'] === 0, (string) $noSales['periods_with_sales']);
$h->test('and nothing is projected from nothing', (float) $noSales['average'] === 0.0, (string) $noSales['average']);

// ── 6. Buckets ──
$h->section('Buckets And Completeness');

$daily = [];
foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'] as $day) {
    // A date-keyed map, which is what dcAnalyticsDailySeries returns and what
    // dcAnalyticsBuckets is written to consume.
    $daily[$day] = 10.0;
}
$weekly = array_values(dcAnalyticsBuckets($daily, 'week'));
$h->test('every observed week is present', count($weekly) >= 1, (string) count($weekly));
$h->test(
    'each bucket says whether the period has finished',
    $weekly !== [] && array_key_exists('complete', $weekly[0]),
    json_encode(array_keys($weekly[0] ?? []))
);
$h->test('each bucket carries a key and a label', $weekly !== [] && ($weekly[0]['key'] ?? '') !== '' && ($weekly[0]['label'] ?? '') !== '', json_encode($weekly[0] ?? null));
$h->test(
    'a week the range only partly covers is marked incomplete, not dropped',
    $weekly !== [] && ($weekly[0]['complete'] ?? true) === false,
    json_encode($weekly[0]['complete'] ?? null)
);
$h->test(
    'the week bucket runs Monday to Sunday',
    $weekly !== [] && ($weekly[0]['start'] ?? '') === '2026-03-02' && ($weekly[0]['end'] ?? '') === '2026-03-08',
    json_encode([$weekly[0]['start'] ?? null, $weekly[0]['end'] ?? null])
);
$h->test('its revenue is the sum of the days in it', $weekly !== [] && (float) $weekly[0]['revenue'] === 40.0, (string) ($weekly[0]['revenue'] ?? ''));

$monthly = array_values(dcAnalyticsBuckets($daily, 'month'));
$h->test('monthly buckets group by calendar month', $monthly !== [] && ($monthly[0]['key'] ?? '') === '2026-03', json_encode(array_column($monthly, 'key')));
$h->test('and the partial month is flagged rather than dropped', $monthly !== [] && ($monthly[0]['complete'] ?? true) === false, json_encode($monthly[0]['complete'] ?? null));

// A fully covered week must NOT be flagged — otherwise the flag is meaningless.
$fullWeek = [];
foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-07', '2026-03-08'] as $day) {
    $fullWeek[$day] = 5.0;
}
$full = array_values(dcAnalyticsBuckets($fullWeek, 'week'));
$h->test('a week the range covers end to end is complete', ($full[0]['complete'] ?? false) === true, json_encode($full[0]['complete'] ?? null));

// ── 7. Products that did not sell are absent ──
$h->section('Unsold Products Are Left Out');

$h->test(
    'the product query only counts completed sales',
    (bool) preg_match('/dcAnalyticsProductRows[\s\S]{0,1400}status\s*=\s*\'completed\'/', $analyticsSrc)
);
$h->test(
    'and only counts lines that actually sold',
    (bool) preg_match('/dcAnalyticsProductRows[\s\S]{0,1400}quantity\s*>\s*0/', $analyticsSrc)
);
$h->test(
    'a parked order is not revenue — that is filtered by status, not by guessing',
    !str_contains($analyticsSrc, "'pending'") || (bool) preg_match("/status\s*=\s*'completed'/", $analyticsSrc)
);

// ── 8. Routing and templates ──
$h->section('Routing And Templates');

$h->test('the reports page has a page route', (bool) preg_match("#'/dc-cafe/reports'\s*=>\s*'dc-cafe:pageReports'#", $routes));
$h->test('the analytics endpoint is routed', str_contains($routes, "'/dc-cafe/api/v1/analytics'"));
$h->test('the CSV export is routed', str_contains($routes, "'/dc-cafe/api/v1/analytics/export'"));
$h->test('the reports page handler exists', (bool) preg_match('/function pageReports\(/', $analyticsHandlers));
$h->test('the analytics handler exists', (bool) preg_match('/function apiGetAnalytics\(/', $analyticsHandlers));
$h->test('the export handler exists', (bool) preg_match('/function apiExportAnalyticsCsv\(/', $analyticsHandlers));
$h->test('the export leaves a record, because taking data out is an event', str_contains($analyticsHandlers, "'report.exported'"));

$h->test('the reports page template exists', is_file(__DIR__ . '/../../templates/modules/dc-cafe/reports/index.disyl'));
$h->test('the shared block exists', is_file(__DIR__ . '/../../templates/modules/dc-cafe/partials/analytics.disyl'));
// The reports page must not render the analytics block any more. That duplication is exactly
// what let the same figures live in two places; it generates now, the dashboard displays.
$h->test(
    'the reports page does not repeat the dashboard',
    !str_contains($read('templates/modules/dc-cafe/reports/index.disyl'), 'partials/analytics.disyl')
);
$h->test('so does the dashboard, so the two cannot disagree', str_contains($dashboardTpl, 'partials/analytics.disyl'));
$h->test('the shared block reads the one analytics endpoint', substr_count($partial, '/dc-cafe/api/v1/analytics') >= 1);
$h->test('the partial is self-contained — it declares its own component', str_contains($partial, 'x-data="dcAnalytics()"') && str_contains($partial, 'function dcAnalytics()'));
// The Share column appended its sign in markup while the Pareto table did not, so the same
// kind of figure appeared as "69.7%" in one table and "69.7" in the next.
$h->test(
    'the Pareto cumulative share is rendered with a percent sign',
    str_contains($partial, '<span x-text="r.cumulative_pct"></span>%'),
    'percent sign present'
);
$h->test('the dashboard hides the operational tiles from a viewer', (bool) preg_match("/\{if user\.role != 'viewer'\}/", $dashboardTpl));

// ── 9. Navigation ──
$h->section('Navigation For A Viewer');

// Structural rather than a regex: the guard block is located, then the links that
// must sit inside it are looked for there. A regex over this markup was fragile
// and wrong once already.
$viewerGuard = strpos($layout, "{if user.role != 'viewer'}");
$h->test('the navigation has a viewer guard', $viewerGuard !== false, 'guard');
$guardedNav = $viewerGuard === false ? '' : substr($layout, (int) $viewerGuard, 1300);
$h->test('the till link sits inside that guard, so a viewer is not offered it', str_contains($guardedNav, 'href="/dc-cafe/pos"'), 'guarded');
$h->test('the deliveries link too', str_contains($guardedNav, 'href="/dc-cafe/products/receive"'), 'guarded');
$h->test('and the customers link', str_contains($guardedNav, 'href="/dc-cafe/customers"'), 'guarded');
$h->test('the reports link is offered', str_contains($layout, 'href="/dc-cafe/reports"'));

$settingsGuard = strpos($layout, "{if user.role != 'cashier' && user.role != 'auditor' && user.role != 'viewer'}");
$h->test(
    'settings stay closed to a viewer',
    $settingsGuard !== false && str_contains(substr($layout, (int) $settingsGuard, 400), 'href="/dc-cafe/settings"'),
    'settings'
);

// The guard is derived from dcAnalyticsRoles() now rather than written out as a role
// literal. Asserting the old string would have pinned the drift in place and kept this
// test green while the nav offered a link the handler refused, so it is asserted against
// the rule instead — and against the absence of the literal that caused that.
$dashGuard = strpos($layout, '{if can_view_analytics}');
$h->test(
    'the analytics links sit inside a derived guard, not a role literal',
    $dashGuard !== false
        && !str_contains($layout, "{if user.role != 'cashier'}")
        && str_contains(substr($layout, (int) $dashGuard, 300), 'href="/dc-cafe/dashboard"'),
    'derived guard'
);
$h->test('a cashier is not among the roles that may read the analytics', !in_array('cashier', dcAnalyticsRoles(), true), json_encode(dcAnalyticsRoles()));
$h->test('an admin is', in_array('admin', dcAnalyticsRoles(), true), json_encode(dcAnalyticsRoles()));
$h->test('an auditor is', in_array('auditor', dcAnalyticsRoles(), true), json_encode(dcAnalyticsRoles()));
$h->test(
    'and a viewer is included exactly when the branch switch says so',
    in_array('viewer', dcAnalyticsRoles(), true) === dcViewerDashboardEnabled(),
    'switch=' . (dcViewerDashboardEnabled() ? 'on' : 'off') . ' roles=' . json_encode(dcAnalyticsRoles())
);

// ── 10. Live HTTP ──
$h->section('Live Requests');

// Under this harness app()->db() is the TENANT's own database, not the base one.
// (A plain CLI bootstrap resolves to the base database instead, which is why the
// base copy of dc_users has no 'viewer' in its enum and this would look wrong.)
$db = app()->db();
$resolvedDb = (string) $db->query('SELECT DATABASE()')->fetchColumn();
$h->test('the harness hands us the tenant database', $resolvedDb === 'dccafe', $resolvedDb);

// The tenant id is read from the tenant's own settings rows. kernel_tenants holds
// it too, but that table lives in the base database and is not reachable here.
$tenantId = (int) ($db->query('SELECT tenant_id FROM tenant_module_settings ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$h->test('the tenant id is discoverable from its own settings rows', $tenantId > 0, (string) $tenantId);

// The kernel's login limiter is keyed on the client, and every request this suite
// makes comes from the same one — so repeated runs would trip it and the sign-in
// assertions below would fail with 429 for a reason that has nothing to do with the
// viewer role. Cleared here, and cleared again at the end so no other suite inherits
// a poisoned counter. The whole table, not a LIKE match on the identifier: the key
// is built from a prefix and a route, and assuming it reads "login" was wrong.
// Rate-limit bookkeeping is not business data and rebuilds on the next request.
$db->exec('DELETE FROM rate_limits');
$h->test('the login limiter is clear to begin with', true, 'reset');

$roleEnum = (string) ($db->query("SHOW COLUMNS FROM dc_users LIKE 'role'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '');
$h->test('the tenant users table knows the viewer role', str_contains($roleEnum, "'viewer'"), $roleEnum);

// A deliberately unmistakable prefix. Auth rows record no actor, so cleanup has to
// find them by the username in the payload, and a sweep that could match a real
// account's name has no business running against an audit trail.
$suffix = 'zzfxa' . bin2hex(random_bytes(4));
$db->prepare(
    "INSERT INTO dc_users (username, password_hash, email, full_name, role, store_id, is_active)
     VALUES (?, ?, ?, 'Analytics Admin', 'admin', 1, 1)"
)->execute([$suffix, password_hash('secret123', PASSWORD_BCRYPT), $suffix . '@example.test']);
$adminId = (int) $db->lastInsertId();
$admin = [
    'id' => $adminId, 'user_id' => $adminId, 'username' => $suffix,
    'name' => 'Analytics Admin', 'full_name' => 'Analytics Admin',
    'role' => 'admin', 'store_id' => 1, 'source' => 'dc-cafe',
];

$viewerSuffix = 'zzfxv' . bin2hex(random_bytes(4));
$db->prepare(
    "INSERT INTO dc_users (username, password_hash, email, full_name, role, store_id, is_active)
     VALUES (?, ?, ?, 'Analytics Viewer', 'viewer', 1, 1)"
)->execute([$viewerSuffix, password_hash('secret123', PASSWORD_BCRYPT), $viewerSuffix . '@example.test']);
$viewerId = (int) $db->lastInsertId();
$viewer = [
    'id' => $viewerId, 'user_id' => $viewerId, 'username' => $viewerSuffix,
    'name' => 'Analytics Viewer', 'full_name' => 'Analytics Viewer',
    'role' => 'viewer', 'store_id' => 1, 'source' => 'dc-cafe',
];

$h->test('the viewer role is accepted by the tenant users table', $viewerId > 0, (string) $viewerId);

// Whatever the branch has right now is recorded and put back afterwards, so this
// suite neither depends on the switch being absent nor leaves it changed. It is a
// live setting: an earlier version of this file assumed no row existed and failed
// the moment an operator actually used the switch.
$switchStmt = $db->prepare(
    "SELECT setting_value FROM tenant_module_settings
     WHERE tenant_id = ? AND module_id = 'dc-cafe' AND setting_key = 'pos_viewer_dashboard_enabled'"
);
$switchStmt->execute([$tenantId]);
$switchBefore = $switchStmt->fetchColumn();

$flip = $db->prepare(
    "INSERT INTO tenant_module_settings (tenant_id, module_id, setting_key, setting_value)
     VALUES (?, 'dc-cafe', 'pos_viewer_dashboard_enabled', ?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
);

// Start from off: refusing at the door is what gets asserted next.
$flip->execute([$tenantId, '"0"']);
$h->test('the branch starts with the viewer surface off', dcAnalyticsRequest('GET', '/dc-cafe/api/v1/analytics', $viewer)['status'] === 403, 'off');

// The login route is read from the route table rather than guessed.
$loginUri = '';
if (preg_match("#'(/[^']+)'\s*=>\s*'dc-cafe:handleAuthLogin'#", $routes, $m)) {
    $loginUri = $m[1];
}
$h->test('the login route is discoverable', $loginUri !== '', $loginUri);

// While the switch is off, a viewer is stopped at the door.
$refusedLogin = dcAnalyticsRequest('POST', $loginUri, null, [
    'username' => $viewerSuffix,
    'password' => 'secret123',
]);
$h->test('a viewer is refused at sign-in while the switch is off', $refusedLogin['status'] === 403, (string) $refusedLogin['status']);
$h->test('and told why, rather than getting a generic failure', str_contains(strtolower((string) $refusedLogin['body']), 'viewer'), mb_substr($refusedLogin['body'], 0, 200));
$h->test('and is not signed in by it', !is_array($refusedLogin['json']) || ($refusedLogin['json']['ok'] ?? true) !== true, mb_substr($refusedLogin['body'], 0, 200));

// A disabled viewer can never obtain a session, so there is no page for it to be
// refused at: an unauthenticated page request redirects to sign-in, exactly as it
// does for anybody. The refusal that matters is the one at the door above, and the
// API refusal below is the same gate applied to the data itself.
$anonReports = dcAnalyticsRequest('GET', '/dc-cafe/reports', null);
$h->test(
    'with no session a page redirects to sign-in, as it does for anyone',
    $anonReports['status'] === 302,
    (string) $anonReports['status']
);
$h->test(
    'and no figures are served with that redirect',
    !str_contains($anonReports['body'], 'dcAnalytics()'),
    mb_substr($anonReports['body'], 0, 120)
);

// An admin is unaffected by the switch.
$adminReports = dcAnalyticsRequest('GET', '/dc-cafe/reports', $admin);
$h->test('an admin reaches the reports page', $adminReports['status'] === 200, (string) $adminReports['status']);
// Generating is the page's whole purpose, so the downloads must be there — and the figures must
// not be, which is the point of the split.
$h->test('the reports page offers the CSV download', str_contains($adminReports['body'], 'Download CSV'), mb_substr($adminReports['body'], 0, 200));
$h->test('and the PDF download', str_contains($adminReports['body'], 'Download PDF'), mb_substr($adminReports['body'], 0, 200));
$h->test('and the period controls', str_contains($adminReports['body'], '/dc-cafe/api/v1/analytics/export'), mb_substr($adminReports['body'], 0, 200));
$h->test('and it does not display the analytics', !str_contains($adminReports['body'], 'dcAnalytics()'), mb_substr($adminReports['body'], 0, 200));

$adminDash = dcAnalyticsRequest('GET', '/dc-cafe/dashboard', $admin);
$h->test('an admin still gets the operational dashboard', $adminDash['status'] === 200, (string) $adminDash['status']);
$h->test('and it also carries the analytics', str_contains($adminDash['body'], 'dcAnalytics()'), 'dashboard');

$withSales = dcAnalyticsRequest('GET', '/dc-cafe/api/v1/analytics', $admin);
$h->test('the analytics endpoint answers an admin', $withSales['status'] === 200, (string) $withSales['status']);
$bundle = is_array($withSales['json']) ? $withSales['json'] : [];
$h->test('and returns the bundle the page expects', ($bundle['ok'] ?? false) === true, mb_substr($withSales['body'], 0, 200));
$h->test('with a range', isset($bundle['range']['from'], $bundle['range']['to']), json_encode($bundle['range'] ?? null));
$h->test('with overall sales', isset($bundle['sales']['overall']['revenue']), json_encode($bundle['sales']['overall'] ?? null));
$h->test('with a branch breakdown', isset($bundle['sales']['branches']) && is_array($bundle['sales']['branches']), 'branches');
$h->test('with top products', isset($bundle['products']['top']) && is_array($bundle['products']['top']), 'top');
// Both rankings are cut on the server over every product that sold, so the units view can
// surface a product the revenue view leaves out. Re-sorting the revenue top ten in the
// browser could not: it can only reorder products that are already in that ten.
$h->test('and a units ranking alongside it', isset($bundle['products']['top_by_qty']) && is_array($bundle['products']['top_by_qty']), 'top_by_qty');
$mixed = [
    ['product_id' => 1, 'name' => 'Revenue leader', 'revenue' => 900.0, 'qty' => 1.0, 'orders' => 1],
    ['product_id' => 2, 'name' => 'Volume leader', 'revenue' => 100.0, 'qty' => 50.0, 'orders' => 5],
    ['product_id' => 3, 'name' => 'Mid', 'revenue' => 300.0, 'qty' => 10.0, 'orders' => 3],
];
$byRev = dcAnalyticsTopProducts($mixed, 1, 'revenue');
$byQty = dcAnalyticsTopProducts($mixed, 1, 'qty');
$h->test('the revenue ranking leads on revenue', ($byRev[0]['product_id'] ?? 0) === 1, json_encode($byRev[0] ?? null));
$h->test(
    'and the units ranking reaches a product the revenue cut excludes',
    ($byQty[0]['product_id'] ?? 0) === 2,
    json_encode($byQty[0] ?? null)
);
$h->test('with a products-per-branch breakdown', isset($bundle['products']['by_branch']) && is_array($bundle['products']['by_branch']), 'by_branch');
$h->test('with a Pareto split', isset($bundle['pareto']['rows']) && is_array($bundle['pareto']['rows']), 'pareto');
$h->test('with a weekly forecast', isset($bundle['forecast']['weekly']['projection']), 'weekly');
$h->test('with a monthly forecast', isset($bundle['forecast']['monthly']['projection']), 'monthly');
$h->test(
    'the weekly forecast projects four periods',
    count((array) ($bundle['forecast']['weekly']['projection'] ?? [])) === 4,
    (string) count((array) ($bundle['forecast']['weekly']['projection'] ?? []))
);
// This window's complete periods hold no trading, so a monthly projection here could only
// be a column of ₱0.00 rows presented as a forecast. It is withheld and the reason is
// stated. The projection itself is exercised with periods that did trade, further down,
// so withholding cannot be satisfied by simply never forecasting.
$h->test(
    'no monthly forecast rows are invented when no complete month traded',
    count((array) ($bundle['forecast']['monthly']['projection'] ?? [])) === 0,
    (string) count((array) ($bundle['forecast']['monthly']['projection'] ?? []))
);
$h->test(
    'and the empty window is stated rather than a zero average called typical',
    !str_contains((string) ($bundle['forecast']['monthly']['note'] ?? ''), 'typical period'),
    (string) ($bundle['forecast']['monthly']['note'] ?? '')
);
$h->test(
    'while a week that did trade still forecasts, so the two differ for a reason',
    count((array) ($bundle['forecast']['weekly']['projection'] ?? [])) === 4,
    'weekly=' . count((array) ($bundle['forecast']['weekly']['projection'] ?? []))
);

// The projection on its own, with buckets built here rather than read from the database,
// so the arithmetic is asserted independently of whatever the demo data happens to hold.
$traded = [];
foreach ([120.0, 180.0, 240.0] as $i => $rev) {
    $traded[] = ['key' => 'w' . $i, 'label' => 'W' . $i, 'revenue' => $rev, 'complete' => true];
}
$fit = dcAnalyticsProjection($traded, 3);
$h->test('three trading periods do produce a projection', count($fit['projection']) === 3, json_encode($fit['projection']));
$h->test('fitted as a trend, since three periods traded', ($fit['method'] ?? '') === 'trend', (string) ($fit['method'] ?? ''));
$h->test('and a rising run projects above its last observation', (float) $fit['projection'][0]['revenue'] > 240.0, json_encode($fit['projection'][0] ?? null));

$quiet = [];
foreach ([0, 0, 0] as $i => $rev) {
    $quiet[] = ['key' => 'q' . $i, 'label' => 'Q' . $i, 'revenue' => 0.0, 'complete' => true];
}
$none = dcAnalyticsProjection($quiet, 3);
$h->test('three empty periods produce no projection', count($none['projection']) === 0, json_encode($none['projection']));
$h->test('and are named as insufficient evidence', ($none['confidence'] ?? '') === 'insufficient', (string) ($none['confidence'] ?? ''));

$void = dcAnalyticsProjection([], 3);
$h->test('no complete period at all produces no projection', count($void['projection']) === 0, json_encode($void['projection']));
$h->test('and is not described as a typical period', !str_contains((string) $void['note'], 'typical period'), (string) $void['note']);
$h->test(
    'the recent window has real sales, so the assertions above are not vacuous',
    (int) ($bundle['sales']['overall']['orders'] ?? 0) > 0,
    json_encode($bundle['sales']['overall'] ?? null)
);
$h->test(
    'every branch is listed, including any that took nothing',
    count((array) ($bundle['sales']['branches'] ?? [])) >= 1,
    (string) count((array) ($bundle['sales']['branches'] ?? []))
);
$h->test(
    'a branch with no sales is shown as zero rather than omitted',
    count(array_filter((array) ($bundle['sales']['branches'] ?? []), static fn($b) => array_key_exists('revenue', (array) $b))) === count((array) ($bundle['sales']['branches'] ?? [])),
    'each branch has a revenue key'
);
$h->test(
    'the top products carry a share of revenue',
    ($bundle['products']['top'] ?? []) === [] || array_key_exists('revenue_share_pct', (array) $bundle['products']['top'][0]),
    json_encode(array_keys((array) ($bundle['products']['top'][0] ?? [])))
);

$viewerApi = dcAnalyticsRequest('GET', '/dc-cafe/api/v1/analytics', $viewer);
$h->test('a viewer cannot read the analytics endpoint while the switch is off', $viewerApi['status'] === 403, (string) $viewerApi['status']);

// A period with nothing in it: the products list must be empty, not a list of zeroes.
$emptyRange = dcAnalyticsRequest('GET', '/dc-cafe/api/v1/analytics?from=2001-01-01&to=2001-01-31', $admin);
$emptyBundle = is_array($emptyRange['json']) ? $emptyRange['json'] : [];
$h->test('a period with no sales answers normally', $emptyRange['status'] === 200, (string) $emptyRange['status']);
$h->test(
    'and reports no products sold rather than a catalogue of zeroes',
    (int) ($emptyBundle['products']['sold_count'] ?? -1) === 0,
    json_encode($emptyBundle['products']['sold_count'] ?? null)
);
$h->test(
    'and no top products',
    (array) ($emptyBundle['products']['top'] ?? ['x']) === [],
    json_encode($emptyBundle['products']['top'] ?? null)
);
$h->test(
    'and nothing to rank in the Pareto',
    (int) ($emptyBundle['pareto']['total_products'] ?? -1) === 0,
    json_encode($emptyBundle['pareto']['total_products'] ?? null)
);
$h->test(
    'and no revenue',
    (float) ($emptyBundle['sales']['overall']['revenue'] ?? -1.0) === 0.0,
    json_encode($emptyBundle['sales']['overall'] ?? null)
);

$csv = dcAnalyticsRequest('GET', '/dc-cafe/api/v1/analytics/export?from=2001-01-01&to=2001-01-31', $admin);
$h->test('the export answers', $csv['status'] === 200, (string) $csv['status']);
$h->test('and is a CSV', stripos($csv['body'], 'branch') !== false || stripos($csv['body'], 'section') !== false, mb_substr($csv['body'], 0, 120));

// ── 11. With the switch on ──
$h->section('With The Viewer Surface Switched On');

try {
    $flip->execute([$tenantId, '"1"']);

    $viewerNow = dcAnalyticsRequest('GET', '/dc-cafe/api/v1/analytics', $viewer);
    $h->test('a viewer can read the analytics', $viewerNow['status'] === 200, (string) $viewerNow['status']);
    $h->test('and gets the same bundle an admin gets', ($viewerNow['json']['ok'] ?? false) === true, mb_substr($viewerNow['body'], 0, 160));

    $viewerLogin = dcAnalyticsRequest('POST', $loginUri, null, [
        'username' => $viewerSuffix,
        'password' => 'secret123',
    ]);
    $h->test('and a viewer may now sign in', ($viewerLogin['json']['ok'] ?? false) === true, mb_substr($viewerLogin['body'], 0, 200));
    $h->test(
        'and is sent to the dashboard, which it may read',
        ($viewerLogin['json']['redirect'] ?? '') === '/dc-cafe/dashboard',
        (string) ($viewerLogin['json']['redirect'] ?? '')
    );
    $h->test(
        'and the sign-in records the role it granted',
        ($viewerLogin['json']['user']['role'] ?? '') === 'viewer',
        json_encode($viewerLogin['json']['user'] ?? null)
    );

    // The operational view is still not the viewer's to read: it names customers.
    $viewerOps = dcAnalyticsRequest('GET', '/dc-cafe/api/v1/dashboard/today', $viewer);
    $h->test('but not the operational view of today', $viewerOps['status'] === 403, (string) $viewerOps['status']);

    $h->test('an admin is unaffected by the switch either way', dcAnalyticsRequest('GET', '/dc-cafe/reports', $admin)['status'] === 200, 'admin still 200');
} finally {
    if ($switchBefore === false) {
        $db->prepare(
            "DELETE FROM tenant_module_settings
             WHERE tenant_id = ? AND module_id = 'dc-cafe' AND setting_key = 'pos_viewer_dashboard_enabled'"
        )->execute([$tenantId]);
    } else {
        $flip->execute([$tenantId, (string) $switchBefore]);
    }
}

$switchStmt->execute([$tenantId]);
$h->test('the switch was put back exactly as it was', $switchStmt->fetchColumn() === $switchBefore, 'restored');

// What this harness cannot settle, recorded rather than asserted: an assertion
// that could not have failed is not evidence.
$h->gap('Viewer PAGE access is verified, but only by hand: a curl sequence signs in as a viewer and confirms /dc-cafe/reports and /dc-cafe/dashboard answer 200 with the figures, /dc-cafe/pos and /dc-cafe/settings bounce to the dashboard rather than to sign-in, and the operational API answers 403. This harness cannot repeat it — headers_list() is empty under the CLI SAPI, so the cookie sign-in issues cannot be captured, and a page request from here is always anonymous. A Playwright journey that signs in as a viewer and keeps the cookie would make it repeatable in CI.');

// ── Cleanup ──
$h->section('Cleanup');

// Auth rows carry no actor: at the instant of a sign-in nobody is authenticated
// yet, so the identity is recorded in entity_id and in the payload instead. An
// earlier version deleted only on actor_user_id, matched none of them, and left
// every sign-in behind — while the assertion below passed against rows it had
// never looked at. It now deletes by what the rows actually carry.
$purge = $db->prepare(
    "DELETE FROM audit_logs
     WHERE actor_user_id = ?
        OR (entity_type = 'dc_users' AND entity_id = ?)
        OR new_data LIKE ?"
);
foreach ([[$adminId, $suffix], [$viewerId, $viewerSuffix]] as [$uid, $username]) {
    $purge->execute([$uid, (string) $uid, '%"' . $username . '"%']);
    $db->prepare('DELETE FROM dc_users WHERE user_id = ?')->execute([$uid]);
}

// Belt and braces: sweep anything a previous run left, now that the prefix makes
// that safe to do against a real audit trail.
$db->prepare("DELETE FROM audit_logs WHERE new_data LIKE '%\"zzfx%'")->execute();

// Exporting a report is a real event and the suite's export records one too. It is
// found by the range the test asks for, which no real export will ever request. Its
// actor is empty here only because this suite stubs the user instead of holding a
// session; a real export records whoever ran it.
$db->prepare("DELETE FROM audit_logs WHERE entity_type = 'dc_reports' AND new_data LIKE ?")
    ->execute(['%2001-01-01%']);

$left = $db->prepare('SELECT COUNT(*) FROM dc_users WHERE user_id IN (?, ?)');
$left->execute([$adminId, $viewerId]);
$h->test('the fixture users are gone', (int) $left->fetchColumn() === 0, 'users');

$leftAudit = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE new_data LIKE ? OR new_data LIKE ?');
$leftAudit->execute(['%"' . $suffix . '"%', '%"' . $viewerSuffix . '"%']);
$h->test(
    'and their trail is gone with them',
    (int) $leftAudit->fetchColumn() === 0,
    'looked for ' . $suffix . ' and ' . $viewerSuffix
);

$switchStmt->execute([$tenantId]);
$h->test(
    'and the switch was left exactly as it was found',
    $switchStmt->fetchColumn() === $switchBefore,
    'found ' . var_export($switchBefore, true)
);

$db->exec('DELETE FROM rate_limits');
$h->test('and the login limiter is clear again', true, 'reset');

$completed = true;
$h->done();
