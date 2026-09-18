<?php

declare(strict_types=1);

/**
 * Daily Ledger — Business Overview (viewer) suite.
 *
 * Covers the read-only /daily-ledger/admin/overview analytics: amount-ranked
 * overall and per-branch top products, Pareto 80/20, configurable net sales,
 * and the daily/weekly/monthly production forecast. Includes runtime role
 * proof against the real handler and DB-backed ordering/scope assertions.
 */

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-overview', TestHarness::MODE_INTEGRATION, 'localhost');

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/helpers/reporting.php');
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('templates/modules/daily-ledger/admin/overview.disyl');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

// ─── Fixture identifiers (tenant 207) ───────────────────────────
$branchAlpha = 99401;
$branchBeta = 99402;
$branchGamma = 99403;
$productP1 = 99401; // Alpha
$productP2 = 99402; // Alpha + Beta
$productP3 = 99403; // Beta only
$productP4 = 99404; // unused, proves absent history is not fabricated
$viewerId = 99401;
$adminId = 99402;
$supervisorId = 99403;
$auditorId = 99404;
$cashierId = 99405;
$seedFrom = '2031-06-01';
$seedTo = '2031-06-03';

app()->tenant()->setTenantId(207);
$dlContext = modulePushContext('daily-ledger');
$db = $dlContext ? $dlContext->db() : null;

if (!$db) {
    $h->fail('daily-ledger module context is available for overview integration');
    $h->done();
    exit(1);
}

$originalSettings = getModuleSettings('daily-ledger');

// Restore exact typed values. A key absent before the fixture ran must be
// removed from tenant storage, never persisted as a manifest default.
$deleteTenantSettings = static function (array $keys): void {
    if ($keys === []) {
        return;
    }
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        $deleteStmt = app()->db()->prepare(
            'DELETE FROM ' . moduleTenantSettingsTable() . '\n              WHERE tenant_id = ? AND module_id = ? AND setting_key = ?'
        );
        foreach ($keys as $key) {
            $deleteStmt->execute([207, 'daily-ledger', (string)$key]);
        }
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
        if (function_exists('invalidateTenantModuleSettingsCache')) {
            invalidateTenantModuleSettingsCache();
        }
    }
};

$cleanup = static function () use ($db, $branchAlpha, $branchBeta, $branchGamma, $productP1, $productP2, $productP3, $productP4, $viewerId, $adminId, $supervisorId, $auditorId, $cashierId, $originalSettings, $deleteTenantSettings): void {
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id IN (?, ?, ?)', [$branchAlpha, $branchBeta, $branchGamma]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?, ?)', [$branchAlpha, $branchBeta, $branchGamma]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?, ?)', [$branchAlpha, $branchBeta, $branchGamma]);
    $db->execute('DELETE FROM dl_user_branches WHERE user_id IN (?, ?, ?, ?, ?) OR branch_id IN (?, ?, ?)', [$viewerId, $adminId, $supervisorId, $auditorId, $cashierId, $branchAlpha, $branchBeta, $branchGamma]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?, ?)', [$branchAlpha, $branchBeta, $branchGamma]);
    $db->execute('DELETE FROM dl_users WHERE id IN (?, ?, ?, ?, ?)', [$viewerId, $adminId, $supervisorId, $auditorId, $cashierId]);
    $db->execute('DELETE FROM dl_branches WHERE id IN (?, ?, ?)', [$branchAlpha, $branchBeta, $branchGamma]);
    $db->execute('DELETE FROM dl_products WHERE id IN (?, ?, ?, ?)', [$productP1, $productP2, $productP3, $productP4]);

    $restore = [];
    $toDelete = [];
    foreach (['net_sales_deduction_percent', 'role_permissions'] as $key) {
        if (array_key_exists($key, $originalSettings)) {
            // Preserve the original PHP type (an array-valued role_permissions
            // must not be flattened into the string "Array").
            $restore[$key] = $originalSettings[$key];
        } else {
            $toDelete[] = $key;
        }
    }
    if ($toDelete !== []) {
        $deleteTenantSettings($toDelete);
    }
    if ($restore !== []) {
        saveModuleSettings('daily-ledger', $restore);
    }
    if (function_exists('invalidateTenantModuleSettingsCache')) {
        invalidateTenantModuleSettingsCache();
    }
    dlModuleSettings(true);
};

$cleanup();
saveModuleSettings('daily-ledger', [
    'role_permissions' => json_encode(dl_defaultRolePermissions(), JSON_UNESCAPED_SLASHES),
    'net_sales_deduction_percent' => '10',
]);
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}
dlModuleSettings(true);

$seed = static function () use ($db, $branchAlpha, $branchBeta, $branchGamma, $productP1, $productP2, $productP3, $productP4, $viewerId, $adminId, $supervisorId, $auditorId, $cashierId, $seedFrom, $seedTo): void {
    foreach ([[$branchAlpha, 'OA', 'Overview Alpha'], [$branchBeta, 'OB', 'Overview Beta'], [$branchGamma, 'OG', 'Overview Gamma']] as [$id, $code, $name]) {
        $db->execute('INSERT INTO dl_branches (id, code, name, is_active) VALUES (:id, :code, :name, 1)', [':id' => $id, ':code' => $code, ':name' => $name]);
    }
    foreach ([[$productP1, 'OV-P1', 'Overview P1', 10], [$productP2, 'OV-P2', 'Overview P2', 5], [$productP3, 'OV-P3', 'Overview P3', 20], [$productP4, 'OV-P4', 'Overview P4', 99]] as [$id, $sku, $name, $price]) {
        $db->execute('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, :price, 0, 1)', [':id' => $id, ':sku' => $sku, ':name' => $name, ':price' => $price]);
    }
    foreach ([[$productP1, $branchAlpha], [$productP2, $branchAlpha], [$productP2, $branchBeta], [$productP3, $branchBeta], [$productP4, $branchGamma]] as [$pid, $bid]) {
        $db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $bid, ':p' => $pid]);
    }
    foreach ([[$viewerId, 'overview-viewer', 'viewer'], [$adminId, 'overview-admin', 'admin'], [$supervisorId, 'overview-supervisor', 'supervisor'], [$auditorId, 'overview-auditor', 'auditor'], [$cashierId, 'overview-cashier', 'cashier']] as [$id, $username, $role]) {
        $db->execute('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (:id, :u, :p, :n, :r, 1)', [
            ':id' => $id,
            ':u' => $username,
            ':p' => password_hash('Overview!2031', PASSWORD_BCRYPT),
            ':n' => 'Overview ' . $role,
            ':r' => $role,
        ]);
    }
    $db->execute('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)', [':u' => $supervisorId, ':b' => $branchAlpha]);
    $db->execute('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)', [':u' => $cashierId, ':b' => $branchAlpha]);

    $ledger = $db->prepare('INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end) VALUES (:b, :p, :d, :s, :price, :beg, 0, 0, :end)');
    // Alpha: P1 4+6=10 units @10 = 100; P2 10 units @5 = 50.
    $ledger->execute([':b' => $branchAlpha, ':p' => $productP1, ':d' => '2031-06-01', ':s' => 'AM', ':price' => 10, ':beg' => 10, ':end' => 6]);
    $ledger->execute([':b' => $branchAlpha, ':p' => $productP1, ':d' => '2031-06-02', ':s' => 'AM', ':price' => 10, ':beg' => 10, ':end' => 4]);
    $ledger->execute([':b' => $branchAlpha, ':p' => $productP2, ':d' => '2031-06-01', ':s' => 'AM', ':price' => 5, ':beg' => 10, ':end' => 0]);
    // Beta: P3 15 units @20 = 300; P2 5 units @5 = 25.
    $ledger->execute([':b' => $branchBeta, ':p' => $productP3, ':d' => '2031-06-01', ':s' => 'AM', ':price' => 20, ':beg' => 20, ':end' => 5]);
    $ledger->execute([':b' => $branchBeta, ':p' => $productP2, ':d' => '2031-06-02', ':s' => 'AM', ':price' => 5, ':beg' => 10, ':end' => 5]);
    // Gamma: P4 has no sales rows at all (absent history).
};
$seed();

$overviewFilters = static function (array $accessible, int $branchId = 0, string $from = '2031-06-01', string $to = '2031-06-03'): array {
    return [
        'date_from' => $from,
        'date_to' => $to,
        'branch_id' => $branchId,
        'product_id' => 0,
        'shift' => '',
        'accessible_branch_ids' => $accessible,
    ];
};
$accessibleAll = [$branchAlpha, $branchBeta, $branchGamma];

// ─── Manifest / Settings ────────────────────────────────────────
$h->section('Manifest and Tenant Settings');

$manifest = json_decode((string)file_get_contents($base . '/modules/daily-ledger/module.json'), true);
$settingsFields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];
$netField = null;
foreach ($settingsFields as $field) {
    if (($field['key'] ?? '') === 'net_sales_deduction_percent') {
        $netField = $field;
        break;
    }
}
$h->test('manifest declares net_sales_deduction_percent', is_array($netField));
$h->test('net field is text with default "0"', ($netField['type'] ?? '') === 'text' && (string)($netField['default'] ?? '') === '0');
$h->test('manifest adds no migration for the setting', !in_array('net_sales_deduction_percent', array_map('strval', array_column($manifest['migrations'] ?? [], 0)), true));

$h->test('dlModuleSettings reads the persisted tenant net value', (string)(dlModuleSettings(true)['net_sales_deduction_percent'] ?? '') === '10');
saveModuleSettings('daily-ledger', ['net_sales_deduction_percent' => '7.5']);
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}
$h->test('dlModuleSettings reflects an updated tenant net value', (string)(dlModuleSettings(true)['net_sales_deduction_percent'] ?? '') === '7.5');
saveModuleSettings('daily-ledger', ['net_sales_deduction_percent' => '10']);
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}
dlModuleSettings(true);

// ─── Net sales (pure) ───────────────────────────────────────────
$h->section('Configurable Net Sales');

$h->test('non-numeric net percent resolves to 0', dl_overviewNetSalesPercent('not-a-number') === 0.0);
$h->test('negative net percent clamps to 0', dl_overviewNetSalesPercent('-25') === 0.0);
$h->test('over-100 net percent clamps to 100', dl_overviewNetSalesPercent('150') === 100.0);
$h->test('fractional net percent is preserved', dl_overviewNetSalesPercent('12.5') === 12.5);
$netConfigured = dl_overviewNetSales(200.0, '12.5');
$h->test('positive net percent applies exact deduction', $netConfigured['configured'] === true && $netConfigured['net'] === 175.0 && $netConfigured['percent'] === 12.5);
$netZero = dl_overviewNetSales(200.0, '0');
$h->test('0% net renders as not configured without a duplicate gross net value', $netZero['configured'] === false && $netZero['net'] === 200.0);

// ─── Pareto (pure) ──────────────────────────────────────────────
$h->section('Pareto 80/20');

$emptyPareto = dl_overviewPareto([]);
$h->test('zero-total Pareto yields an empty state without division', $emptyPareto['has_data'] === false && $emptyPareto['contributor_count'] === 0 && $emptyPareto['contributor_share'] === 0.0);
$zeroRowPareto = dl_overviewPareto([['id' => 1, 'amount' => 0.0]]);
$h->test(
    'products with no sales are excluded from the 80/20 scope entirely',
    $zeroRowPareto['has_data'] === false
        && $zeroRowPareto['scope_count'] === 0
        && $zeroRowPareto['contributor_count'] === 0
        && $zeroRowPareto['bottom_count'] === 0
);

$mixedPareto = dl_overviewPareto([
    ['id' => 1, 'name' => 'Sells', 'amount' => 100.0],
    ['id' => 2, 'name' => 'Never sold', 'amount' => 0.0],
]);
$h->test(
    'a no-sales product is neither a contributor nor counted in the bottom set',
    $mixedPareto['scope_count'] === 1
        && $mixedPareto['contributor_count'] === 1
        && $mixedPareto['bottom_count'] === 0
        && count($mixedPareto['contributors']) === 1
        && $mixedPareto['contributors'][0]['id'] === 1
);

$singlePareto = dl_overviewPareto([['id' => 7, 'name' => 'Solo', 'amount' => 100.0]]);
$h->test('one product over 80% is the sole contributor', $singlePareto['contributor_count'] === 1 && $singlePareto['contributor_share'] === 100.0 && $singlePareto['bottom_count'] === 0);

$tiePareto = dl_overviewPareto([
    ['id' => 2, 'name' => 'First', 'amount' => 50.0],
    ['id' => 1, 'name' => 'Second', 'amount' => 50.0],
], 0.5);
$h->test('ties follow the supplied deterministic order', ($tiePareto['contributors'][0]['id'] ?? null) === 2 && $tiePareto['contributor_count'] === 1);

$cumulativePareto = dl_overviewPareto([
    ['id' => 1, 'amount' => 100.0],
    ['id' => 2, 'amount' => 60.0],
    ['id' => 3, 'amount' => 40.0],
]);
$h->test('cumulative contributor share reaches at least 80%', $cumulativePareto['contributor_share'] >= 80.0 && $cumulativePareto['contributor_count'] === 2);
$h->test('bottom count equals total products minus contributors', $cumulativePareto['bottom_count'] === 1);

// ─── Forecast period factors (pure) ─────────────────────────────
$h->section('Forecast Period Factors');

$h->test('default forecast window is 14', dl_overviewForecastWindow(null) === 14 && dl_overviewForecastWindow('abc') === 14);
$h->test('forecast window clamps to the bounded range', dl_overviewForecastWindow(1) === 3 && dl_overviewForecastWindow(500) === 90 && dl_overviewForecastWindow(21) === 21);
$h->test('daily period factor is 1', dl_overviewForecastPeriodDays('daily') === 1);
$h->test('weekly period factor is 7', dl_overviewForecastPeriodDays('weekly') === 7);
$h->test('monthly period factor is 30', dl_overviewForecastPeriodDays('monthly') === 30);
$h->test('unknown period falls back to daily', dl_overviewNormalizeForecastPeriod('yearly') === 'daily');

$pureForecastRows = [
    ['product_id' => 1, 'sku' => 'A', 'product_name' => 'Alpha', 'shift' => 'AM', 'average_sales' => 5.0, 'sample_days' => 3],
    ['product_id' => 1, 'sku' => 'A', 'product_name' => 'Alpha', 'shift' => 'PM', 'average_sales' => 2.0, 'sample_days' => 3],
    ['product_id' => 2, 'sku' => 'B', 'product_name' => 'Beta', 'shift' => 'AM', 'average_sales' => 10.0, 'sample_days' => 1],
];
$dailySummary = dl_overviewForecastSummary($pureForecastRows, 'daily');
$dailyByProduct = array_column($dailySummary['products'], 'projected_units', 'product_id');
$h->test('daily projection sums per-shift averages per product', ($dailyByProduct[1] ?? null) === 7 && ($dailyByProduct[2] ?? null) === 10);
$h->test('daily total applies factor 1', $dailySummary['total_units'] === 17);
$weeklySummary = dl_overviewForecastSummary($pureForecastRows, 'weekly');
$weeklyByProduct = array_column($weeklySummary['products'], 'projected_units', 'product_id');
$h->test('weekly projection applies factor 7', ($weeklyByProduct[1] ?? null) === 49 && ($weeklyByProduct[2] ?? null) === 70);
$monthlySummary = dl_overviewForecastSummary($pureForecastRows, 'monthly');
$monthlyByProduct = array_column($monthlySummary['products'], 'projected_units', 'product_id');
$h->test('monthly projection applies factor 30', ($monthlyByProduct[1] ?? null) === 210 && ($monthlyByProduct[2] ?? null) === 300);
$h->test('empty history yields no fabricated forecast', dl_overviewForecastSummary([], 'weekly')['products'] === []);

// ─── DB-backed ranking, Pareto and branch slicing ───────────────
$h->section('DB Ranking, Pareto and Branch Scope');

$productTotals = dl_overviewProductTotals($db, $overviewFilters($accessibleAll));
$h->test('overall totals order by amount DESC then units DESC', array_column($productTotals, 'id') === [$productP3, $productP1, $productP2]);

// ─── Ranking controls (pure) ────────────────────────────────────
$h->section('Ranking controls');

$h->test(
    'descending amount keeps the canonical ranking',
    array_column(dl_overviewSortProducts($productTotals, 'amount', 'desc'), 'id') === [$productP3, $productP1, $productP2]
);
$h->test(
    'ascending amount reverses the ranking',
    array_column(dl_overviewSortProducts($productTotals, 'amount', 'asc'), 'id') === [$productP2, $productP1, $productP3]
);
$h->test(
    'ranking by units falls back to amount for ties',
    array_column(dl_overviewSortProducts($productTotals, 'units', 'desc'), 'id') === [$productP3, $productP2, $productP1]
);
$h->test(
    'unsupported sort input falls back to amount descending',
    dl_overviewNormalizeSortBy('bogus') === 'amount'
        && dl_overviewNormalizeSortDir('sideways') === 'desc'
        && array_column(dl_overviewSortProducts($productTotals, 'bogus', 'sideways'), 'id') === [$productP3, $productP1, $productP2]
);
$h->test(
    'sort label describes the active ranking',
    dl_overviewSortLabel('amount', 'desc') === 'sales amount (highest first)'
        && dl_overviewSortLabel('units', 'asc') === 'units sold (lowest first)'
);

$bars = dl_overviewBarPercentages([['id' => 1, 'amount' => 50.0], ['id' => 2, 'amount' => 200.0]], 'amount');
$h->test('bar percentages scale to the largest value', $bars[0]['bar_pct'] === 25.0 && $bars[1]['bar_pct'] === 100.0);
$zeroBars = dl_overviewBarPercentages([['id' => 1, 'amount' => 0.0]], 'amount');
$h->test('bar percentages never divide by zero', $zeroBars[0]['bar_pct'] === 0.0);

$h->test(
    'chart series limit normalizes to a bounded length',
    dl_overviewNormalizeChartLimit(null) === DL_OVERVIEW_CHART_LIMIT_DEFAULT
        && dl_overviewNormalizeChartLimit('5') === 5
        && dl_overviewNormalizeChartLimit('0') === 0
        && dl_overviewNormalizeChartLimit('999') === DL_OVERVIEW_CHART_LIMIT_MAX
        && dl_overviewNormalizeChartLimit('not-a-number') === DL_OVERVIEW_CHART_LIMIT_DEFAULT
);
$seriesRows = [
    ['id' => 1, 'amount' => 100.0],
    ['id' => 2, 'amount' => 0.0],
    ['id' => 3, 'amount' => 50.0],
];
$h->test(
    'chart series drops zero-value series by default',
    array_column(dl_overviewChartSeries($seriesRows, 10, false, 'amount'), 'id') === [1, 3]
);
$h->test(
    'chart series can include zero-value series on request',
    array_column(dl_overviewChartSeries($seriesRows, 10, true, 'amount'), 'id') === [1, 2, 3]
);
$h->test(
    'chart series honours the limit and treats 0 as unlimited',
    array_column(dl_overviewChartSeries($seriesRows, 1, true, 'amount'), 'id') === [1]
        && array_column(dl_overviewChartSeries($seriesRows, 0, true, 'amount'), 'id') === [1, 2, 3]
);
$shares = dl_overviewValueShares([['id' => 1, 'amount' => 25.0], ['id' => 2, 'amount' => 75.0]], 'amount');
$h->test('value shares are computed against the series total', $shares[0]['share_pct'] === 25.0 && $shares[1]['share_pct'] === 75.0);
$emptyShares = dl_overviewValueShares([['id' => 1, 'amount' => 0.0]], 'amount');
$h->test('value shares never divide by zero', $emptyShares[0]['share_pct'] === 0.0);

$zeroForecast = dl_overviewForecastSummary([
    ['product_id' => 1, 'product_name' => 'Sells', 'sku' => 'S1', 'average_sales' => 2.0, 'sample_days' => 14],
    ['product_id' => 2, 'product_name' => 'Never sold', 'sku' => 'S2', 'average_sales' => 0.0, 'sample_days' => 14],
], 'monthly');
$h->test(
    'monthly projection excludes products with zero units sold',
    count($zeroForecast['products']) === 1
        && $zeroForecast['products'][0]['product_id'] === 1
        && $zeroForecast['products'][0]['projected_units'] === 60
        && $zeroForecast['total_units'] === 60
        && $zeroForecast['total_daily'] === 2.0
);
$allZeroForecast = dl_overviewForecastSummary([
    ['product_id' => 1, 'product_name' => 'Never sold', 'sku' => 'S1', 'average_sales' => 0.0, 'sample_days' => 14],
], 'weekly');
$h->test(
    'a forecast with only zero-unit products reports an empty projection',
    $allZeroForecast['products'] === [] && $allZeroForecast['total_units'] === 0 && $allZeroForecast['total_daily'] === 0.0
);
$soldForecast = dl_overviewForecastSummary([
    ['product_id' => 1, 'product_name' => 'Sold', 'sku' => 'S1', 'average_sales' => 3.0, 'sample_days' => 14],
], 'daily');
$h->test(
    'a daily projection keeps a sold product at its per-day average',
    $soldForecast['products'][0]['projected_units'] === 3 && $soldForecast['period_days'] === 1
);

$h->test(
    'pending rows are excluded unless the viewer opts to include them',
    dl_overviewPendingRowsMode('exclude') === 'exclude'
        && dl_overviewPendingRowsMode('include') === 'include'
        && dl_overviewPendingRowsMode(null) === 'exclude'
        && dl_overviewPendingRowsMode('') === 'exclude'
        && dl_overviewPendingRowsMode('bogus') === 'exclude'
);
$h->test(
    'pending predicate drops rows that have no ending yet',
    str_contains(dl_overviewPendingPredicate('dl'), 'dl.bal_end IS NOT NULL')
);
$h->test(
    'pending predicate falls back to the default alias when given an invalid one',
    str_contains(dl_overviewPendingPredicate('bad alias!'), 'dl.bal_end IS NOT NULL')
);
$h->test('overall amount totals match seeded sales', array_column($productTotals, 'amount') === [300.0, 100.0, 75.0]);
$h->test('overall unit totals match seeded sales', array_column($productTotals, 'units') === [15, 10, 15]);

$topProducts = dl_overviewTopProducts($productTotals, 2);
$h->test('overall top-N slice is amount-ranked and bounded', array_column($topProducts, 'id') === [$productP3, $productP1]);

$pareto = dl_overviewPareto($productTotals);
$h->test('DB Pareto reports two contributors over 80%', $pareto['contributor_count'] === 2 && $pareto['contributor_share'] >= 80.0);
$h->test('DB Pareto bottom count is total minus contributors', $pareto['bottom_count'] === 1 && $pareto['total_amount'] === 475.0);

$branchProducts = dl_overviewBranchProductTotals($db, $overviewFilters($accessibleAll), 5);
$byBranch = [];
foreach ($branchProducts as $branchRow) {
    $byBranch[(int)$branchRow['branch_id']] = array_column($branchRow['products'], 'id');
}
$h->test('per-branch top products are sliced per branch', ($byBranch[$branchAlpha] ?? []) === [$productP1, $productP2] && ($byBranch[$branchBeta] ?? []) === [$productP3, $productP2]);
$h->test('per-branch slice respects the requested limit', count(dl_overviewBranchProductTotals($db, $overviewFilters($accessibleAll), 1)[0]['products'] ?? []) === 1);

$scopedTotals = dl_overviewProductTotals($db, $overviewFilters($accessibleAll, $branchAlpha));
$h->test('branch filter removes out-of-scope products', array_column($scopedTotals, 'id') === [$productP1, $productP2]);
$scopedPareto = dl_overviewPareto($scopedTotals);
$h->test('branch Pareto uses only the scoped amount', $scopedPareto['total_amount'] === 150.0);

$dateScoped = dl_overviewProductTotals($db, $overviewFilters($accessibleAll, 0, '2031-06-02', '2031-06-02'));
$h->test('date filter removes out-of-scope product rows', array_column($dateScoped, 'id') === [$productP1, $productP2] && array_column($dateScoped, 'amount') === [60.0, 25.0]);

$inaccessibleTotals = dl_overviewProductTotals($db, $overviewFilters($accessibleAll, 99499));
$h->test('inaccessible requested branch returns no analytics', $inaccessibleTotals === []);
$h->test('inaccessible requested branch returns no per-branch rows', dl_overviewBranchProductTotals($db, $overviewFilters($accessibleAll, 99499)) === []);

// ─── DB-backed forecast anchoring ───────────────────────────────
$h->section('DB Forecast Anchoring');

$forecastRows = dl_overviewForecastRows($db, $overviewFilters([$branchAlpha], $branchAlpha), '2031-06-03', 14);
$alphaForecast = dl_overviewForecastSummary($forecastRows, 'daily');
$alphaDaily = array_column($alphaForecast['products'], 'projected_units', 'product_id');
$h->test('forecast anchors history at the selected date_to', ($alphaDaily[$productP1] ?? null) === 5 && ($alphaDaily[$productP2] ?? null) === 10);
$alphaWeekly = dl_overviewForecastSummary($forecastRows, 'weekly');
$alphaWeeklyByProduct = array_column($alphaWeekly['products'], 'projected_units', 'product_id');
$h->test('DB weekly forecast applies the period factor to per-day averages', ($alphaWeeklyByProduct[$productP1] ?? null) === 35 && ($alphaWeeklyByProduct[$productP2] ?? null) === 70);
$alphaMonthly = dl_overviewForecastSummary($forecastRows, 'monthly');
$alphaMonthlyByProduct = array_column($alphaMonthly['products'], 'projected_units', 'product_id');
$h->test('DB monthly forecast applies the period factor to per-day averages', ($alphaMonthlyByProduct[$productP1] ?? null) === 150 && ($alphaMonthlyByProduct[$productP2] ?? null) === 300);
$emptyForecast = dl_overviewForecastSummary(dl_overviewForecastRows($db, $overviewFilters([$branchAlpha], $branchAlpha), '2032-01-15', 14), 'daily');
$h->test('empty history renders no fabricated forecast', $emptyForecast['products'] === [] && $emptyForecast['total_units'] === 0);

// ─── Query contract (no N+1) ────────────────────────────────────
$h->section('Query Contract');

$reportingSource = (string)file_get_contents($base . '/modules/daily-ledger/helpers/reporting.php');
$branchHelperStart = strpos($reportingSource, 'function dl_overviewBranchProductTotals');
$branchHelperEnd = strpos($reportingSource, 'function dl_overviewTopProducts');
$branchHelperSlice = ($branchHelperStart !== false && $branchHelperEnd !== false) ? substr($reportingSource, $branchHelperStart, $branchHelperEnd - $branchHelperStart) : '';
$h->test('per-branch helper issues exactly one aggregate query', substr_count($branchHelperSlice, '->prepare(') === 1);
$h->test('per-branch helper aggregates by (branch_id, product_id)', str_contains($branchHelperSlice, 'GROUP BY dl.branch_id, b.name, p.id'));
$h->test('overall totals use a deterministic amount/units/id tie-break', str_contains($reportingSource, 'ORDER BY amount DESC, units DESC, p.id ASC'));
$handlersSource = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$overviewStart = strpos($handlersSource, 'function handleAdminOverview');
$overviewEnd = strpos($handlersSource, 'function handleAdminReports');
$overviewSlice = ($overviewStart !== false && $overviewEnd !== false) ? substr($handlersSource, $overviewStart, $overviewEnd - $overviewStart) : '';
$h->test('handler calls the per-branch helper once (no per-branch loop)', substr_count($overviewSlice, 'dl_overviewBranchProductTotals(') === 1);
$h->test('overview reuses canonical sales SQL helpers', str_contains($reportingSource, "dl_ledgerSalesQuantitySql('dl')") && str_contains($reportingSource, "dl_ledgerSalesAmountSql('dl')"));
$h->test('overview SQL avoids MySQL 8-only constructs', stripos($overviewSlice, ' OVER(') === false && stripos($overviewSlice, ' OVER (') === false && preg_match('/\bWITH\s+[a-z_]+\s+AS\s*\(/i', $overviewSlice) !== 1 && stripos($overviewSlice, 'JSON_TABLE') === false);

// ─── Runtime handler render (role access) ───────────────────────
$h->section('Runtime Handler Render');

$renderAs = static function (string $role, int $userId, string $query = '') use ($h): string {
    $tokens = dl_generateAuthTokens([
        'sub' => $role . ':' . $userId,
        'id' => $userId,
        'username' => 'overview-' . $role,
        'name' => 'Overview ' . $role,
        'role' => $role,
        'source' => 'daily-ledger',
    ]);
    $cookieName = dlCookieName();
    $previousCookie = $_COOKIE[$cookieName] ?? null;
    $previousGet = $_GET;
    $_COOKIE[$cookieName] = $tokens['token'];
    parse_str($query, $_GET);
    $level = ob_get_level();
    ob_start();
    try {
        handleAdminOverview();
        return (string)ob_get_clean();
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        $_GET = $previousGet;
        if ($previousCookie === null) {
            unset($_COOKIE[$cookieName]);
        } else {
            $_COOKIE[$cookieName] = $previousCookie;
        }
    }
};

foreach ([['viewer', $viewerId], ['admin', $adminId], ['supervisor', $supervisorId], ['auditor', $auditorId]] as [$role, $roleId]) {
    $html = $renderAs($role, $roleId, 'branch_id=' . $branchAlpha . '&date_from=2031-06-01&date_to=2031-06-03&forecast_period=daily');
    $ok = str_contains($html, 'Business Overview')
        && str_contains($html, 'Gross Sales')
        && str_contains($html, 'Top Saleable Products')
        && str_contains($html, 'Pareto 80/20')
        && str_contains($html, 'Production Forecast')
        && str_contains($html, 'Overview P1');
    $h->test("{$role} renders the read-only overview analytics", $ok);
}

$viewerHtml = $renderAs('viewer', $viewerId, 'branch_id=' . $branchAlpha . '&date_from=2031-06-01&date_to=2031-06-03&forecast_period=daily');
$h->test('viewer page exposes no POST/mutation form', !preg_match('/<form[^>]+method=["\']post/i', $viewerHtml));
$h->test('viewer page shows the configured net sales label', str_contains($viewerHtml, 'Net Sales') && str_contains($viewerHtml, 'PHP 135.00') && str_contains($viewerHtml, '10.00%'));
$h->test('viewer page shows the per-branch net value', str_contains($viewerHtml, 'Net PHP 135.00') && str_contains($viewerHtml, 'Overview Alpha'));
$h->test('viewer page shows the amount-ranked per-branch product', str_contains($viewerHtml, 'Top Products by Branch') && str_contains($viewerHtml, 'Overview Alpha') && str_contains($viewerHtml, 'Overview P2'));

// Ranking controls must actually reorder the rendered table, and the caption
// must describe the ranking that is in force.
$topSection = static function (string $html): string {
    $start = strpos($html, 'Top Saleable Products');
    if ($start === false) {
        return '';
    }
    $end = strpos($html, 'Top Products by Branch', $start);

    return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
};
$allRangeDescHtml = $renderAs('viewer', $viewerId, 'date_from=2031-06-01&date_to=2031-06-02&sort_by=amount&sort_dir=desc');
$allRangeAscHtml = $renderAs('viewer', $viewerId, 'date_from=2031-06-01&date_to=2031-06-02&sort_by=amount&sort_dir=asc');
$descTop = $topSection($allRangeDescHtml);
$ascTop = $topSection($allRangeAscHtml);
$h->test(
    'ranked table caption reflects the selected direction',
    str_contains($allRangeDescHtml, 'ranked by sales amount (highest first)')
        && str_contains($allRangeAscHtml, 'ranked by sales amount (lowest first)')
);
$h->test(
    'descending renders the strongest product before the weakest',
    strpos($descTop, 'Overview P3') !== false
        && strpos($descTop, 'Overview P2') !== false
        && strpos($descTop, 'Overview P3') < strpos($descTop, 'Overview P2')
);
$h->test(
    'ascending renders the weakest product before the strongest',
    strpos($ascTop, 'Overview P2') !== false
        && strpos($ascTop, 'Overview P3') !== false
        && strpos($ascTop, 'Overview P2') < strpos($ascTop, 'Overview P3')
);
$h->test(
    'filter form exposes both sort fields and both directions',
    str_contains($viewerHtml, 'name="sort_by"')
        && str_contains($viewerHtml, 'name="sort_dir"')
        && str_contains($viewerHtml, 'value="units"')
        && str_contains($viewerHtml, 'value="asc"')
);
$h->test(
    'overview renders dependency-free bar charts',
    substr_count($allRangeDescHtml, 'style="width:') >= 3
        && str_contains($allRangeDescHtml, 'Sales amount by branch')
        && str_contains($allRangeDescHtml, 'Coverage reached')
        && str_contains($allRangeDescHtml, 'The red tick marks the 80% threshold.')
        && str_contains($allRangeDescHtml, 'Projected units by product')
);
$h->test(
    'ranked tables render inline row bars',
    str_contains($allRangeDescHtml, 'bg-emerald-100') && str_contains($allRangeDescHtml, 'bg-indigo-100')
);
$h->test(
    'chart filters are exposed and branch chart reports its plotted share',
    str_contains($allRangeDescHtml, 'name="chart_limit"')
        && str_contains($allRangeDescHtml, 'name="chart_show_empty"')
        && str_contains($allRangeDescHtml, 'plotted')
);

// Selecting one branch must not remove the other authorized choices from the
// filter. The dropdown keeps every authorized branch while the cards/queries
// stay scoped to the selected branch.
$viewerFilterHtml = $renderAs('viewer', $viewerId, 'branch_id=' . $branchAlpha . '&date_from=2031-06-01&date_to=2031-06-03&forecast_period=daily');
$dropdownStart = strpos($viewerFilterHtml, 'name="branch_id"');
$dropdownEnd = $dropdownStart === false ? false : strpos($viewerFilterHtml, '</select>', $dropdownStart);
$dropdownHtml = ($dropdownStart !== false && $dropdownEnd !== false) ? substr($viewerFilterHtml, $dropdownStart, $dropdownEnd - $dropdownStart) : '';
$h->test(
    'branch filter retains All Branches plus every authorized branch',
    str_contains($dropdownHtml, 'All Branches')
        && str_contains($dropdownHtml, 'value="' . $branchAlpha . '"')
        && str_contains($dropdownHtml, 'value="' . $branchBeta . '"')
        && str_contains($dropdownHtml, 'value="' . $branchGamma . '"')
);
$formClose = strpos($viewerFilterHtml, '</form>');
$analyticsHtml = $formClose === false ? '' : substr($viewerFilterHtml, $formClose + 7);
$h->test(
    'selected branch analytics contain only the chosen branch',
    str_contains($analyticsHtml, 'Overview Alpha')
        && !str_contains($analyticsHtml, 'Overview Beta')
        && !str_contains($analyticsHtml, 'Overview Gamma')
);
$h->test(
    'selected branch analytics total only the chosen branch amounts',
    str_contains($analyticsHtml, 'PHP 150.00') && !str_contains($analyticsHtml, 'PHP 475.00')
);

$supervisorScoped = $renderAs('supervisor', $supervisorId, 'branch_id=' . $branchBeta . '&date_from=2031-06-01&date_to=2031-06-03');
$h->test('inaccessible branch render shows no out-of-scope branch data', !str_contains($supervisorScoped, 'Overview Beta') && !str_contains($supervisorScoped, 'Overview P3'));
$h->test('inaccessible branch render shows the empty analytics state', str_contains($supervisorScoped, 'No sales recorded for this period.') || str_contains($supervisorScoped, 'No sales amount recorded'));

// 0% is a valid but ambiguous setting: render the configuration hint, not a
// duplicate gross value dressed up as a net amount.
saveModuleSettings('daily-ledger', ['net_sales_deduction_percent' => '0']);
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}
dlModuleSettings(true);
$zeroNetHtml = $renderAs('viewer', $viewerId, 'branch_id=' . $branchAlpha . '&date_from=2031-06-01&date_to=2031-06-03');
$h->test('0% net renders the configuration hint', str_contains($zeroNetHtml, 'Not configured') && str_contains($zeroNetHtml, 'Net Sales Deduction %'));
$h->test('0% net does not render a misleading net amount', !str_contains($zeroNetHtml, 'Net PHP') && !str_contains($zeroNetHtml, 'deduction from'));
saveModuleSettings('daily-ledger', ['net_sales_deduction_percent' => '10']);
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}
dlModuleSettings(true);

// A pending ending produces a 0-amount product row (nothing was actually sold).
// Such products must be absent from the ranked tables and from the 80/20 rule.
$db->execute("INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end) VALUES (?, ?, '2031-06-03', 'AM', 99, 1, 0, 0, NULL)", [$branchGamma, $productP4]);
$zeroAmountHtml = $renderAs('viewer', $viewerId, 'date_from=2031-06-01&date_to=2031-06-03');
$h->test(
    'no-sales product is absent from the overview and the 80/20 rule',
    !str_contains($zeroAmountHtml, 'Overview P4') && !str_contains($zeroAmountHtml, '0.0%')
);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = ? AND product_id = ?', [$branchGamma, $productP4]);

// An official row where nothing moved (beg_bal == bal_end) is zero units sold
// rather than a pending row, so it reaches the forecast engine with a zero
// average. The projection must omit it instead of listing 0 units.
$db->execute("INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end) VALUES (?, ?, '2031-06-03', 'AM', 99, 5, 0, 0, 5)", [$branchGamma, $productP4]);
$forecastSection = static function (string $html): string {
    $start = strpos($html, 'Production Forecast');

    return $start === false ? '' : substr($html, $start);
};
$zeroOfficialHtml = $renderAs('viewer', $viewerId, 'date_from=2031-06-01&date_to=2031-06-03&forecast_period=monthly');
$zeroOfficialForecast = $forecastSection($zeroOfficialHtml);
$h->test(
    'monthly projection omits a product with zero units sold',
    !str_contains($zeroOfficialForecast, 'Overview P4') && str_contains($zeroOfficialForecast, 'Overview P')
);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = ? AND product_id = ?', [$branchGamma, $productP4]);

// Pending rows are excluded by default, so only completed entries are counted.
$grossOf = static function (string $html): string {
    return preg_match('/text-emerald-600">PHP ([0-9,\.]+)/', $html, $m) ? $m[1] : '';
};
$pendingDefault = $renderAs('viewer', $viewerId, 'date_from=2031-06-01&date_to=2031-06-03');
$h->test(
    'the pending filter defaults on without claiming a filter was applied',
    str_contains($pendingDefault, 'name="pending_rows"')
        && str_contains($pendingDefault, 'value="include"')
        && str_contains($pendingDefault, 'value="exclude"')
        && str_contains($pendingDefault, 'checked')
        && str_contains($pendingDefault, 'name="filters_applied"')
        && !str_contains($pendingDefault, 'Filters applied')
);
$db->execute("INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end) VALUES (?, ?, '2031-06-01', 'PM', 99, 4, 0, 0, NULL)", [$branchAlpha, $productP4]);
$pendingExcluded = $renderAs('viewer', $viewerId, 'date_from=2031-06-01&date_to=2031-06-03&filters_applied=1&pending_rows=exclude');
$pendingIncluded = $renderAs('viewer', $viewerId, 'date_from=2031-06-01&date_to=2031-06-03&filters_applied=1&pending_rows=include');
$bothFieldsHtml = $renderAs('viewer', $viewerId, 'date_from=2031-06-01&date_to=2031-06-03&filters_applied=1&pending_rows=include&pending_rows=exclude');
$h->test(
    'applying the filter shows the cue for whichever state is active',
    str_contains($pendingExcluded, 'Filters applied — pending ledger rows are excluded')
        && str_contains($pendingIncluded, 'Filters applied — pending ledger rows are included')
);
$h->test(
    'switching the filter off is flagged and leaves the totals unchanged',
    !str_contains($pendingIncluded, 'pending ledger rows are excluded')
        && $grossOf($pendingIncluded) === $grossOf($pendingExcluded)
);
$h->test(
    'the checkbox value wins when the hidden companion field is also submitted',
    str_contains($bothFieldsHtml, 'Filters applied — pending ledger rows are excluded')
        && !str_contains($bothFieldsHtml, 'pending ledger rows are included')
);
$h->test(
    'a pending row never reaches the rankings, included or not',
    !str_contains($pendingExcluded, 'Overview P4') && !str_contains($pendingIncluded, 'Overview P4')
);
$db->execute("DELETE FROM dl_daily_ledger WHERE branch_id = ? AND product_id = ? AND shift = 'PM'", [$branchAlpha, $productP4]);

// A completed row with a real sale is counted regardless of its ending value.
$db->execute("INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end) VALUES (?, ?, '2031-06-01', 'AM', 99, 5, 0, 0, 0)", [$branchAlpha, $productP4]);
$completedHtml = $renderAs('viewer', $viewerId, 'date_from=2031-06-01&date_to=2031-06-03');
$h->test(
    'a completed row is counted whatever its ending value',
    str_contains($completedHtml, 'Overview P4') && !str_contains($completedHtml, 'Filters applied')
);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = ? AND product_id = ?', [$branchAlpha, $productP4]);

$harness = __DIR__ . '/daily_ledger_overview_runtime_harness.php';
$cashierOutput = [];
$cashierCode = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' cashier ' . escapeshellarg((string)$cashierId) . ' ' . escapeshellarg('branch_id=' . $branchAlpha), $cashierOutput, $cashierCode);
$cashierText = implode("\n", $cashierOutput);
$h->test('cashier is denied the overview at runtime', $cashierCode === 0 && trim($cashierText) === '' && !str_contains($cashierText, 'RENDERED'));

// ─── Zero writes ────────────────────────────────────────────────
$h->section('Read-only Guarantee');

$countRows = static fn(string $table): int => (int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
$beforeCounts = [
    'dl_daily_ledger' => $countRows('dl_daily_ledger'),
    'dl_production_movements' => $countRows('dl_production_movements'),
    'dl_commissary_product_ledger' => $countRows('dl_commissary_product_ledger'),
    'dl_ledger_day_status' => $countRows('dl_ledger_day_status'),
];
$beforeSettings = getModuleSettings('daily-ledger');
$renderAs('viewer', $viewerId, 'branch_id=' . $branchAlpha . '&date_from=2031-06-01&date_to=2031-06-03&forecast_period=monthly');
$afterCounts = [
    'dl_daily_ledger' => $countRows('dl_daily_ledger'),
    'dl_production_movements' => $countRows('dl_production_movements'),
    'dl_commissary_product_ledger' => $countRows('dl_commissary_product_ledger'),
    'dl_ledger_day_status' => $countRows('dl_ledger_day_status'),
];
$h->test('overview GET performs zero ledger/production writes', $beforeCounts === $afterCounts);
$h->test('overview GET performs zero settings writes', $beforeSettings === getModuleSettings('daily-ledger'));

$cleanup();

$h->done();
