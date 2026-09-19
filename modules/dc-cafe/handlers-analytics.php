<?php
declare(strict_types=1);

/**
 * DC Cafe reporting surface: the analytics dashboard, its JSON feed and the CSV
 * export.
 *
 * Read-only by design. A viewer may read these and nothing else, which is why
 * every entry point gates on dcAnalyticsRoles() rather than repeating a role list
 * that could drift from the setting that governs it.
 *
 * This is a split handler file, so it may only rely on helpers.php — everything
 * it calls comes from there or from helpers/analytics.php, which helpers.php
 * loads.
 */

/**
 * GET /dc-cafe/reports — the reporting screen.
 *
 * Renders the same analytics block the dashboard uses, so the two can never
 * disagree about a number.
 */
function pageReports(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole(...dcAnalyticsRoles());

    echo dcRender('reports/index.disyl', [
        'page_title' => 'DC Cafe Reports',
    ]);
}

/**
 * Resolve the requested period and branch from the request.
 *
 * @return array{0:?string,1:?string,2:?int}
 */
function dcAnalyticsRequestScope(): array
{
    $from = trim((string) (dcInput('from') ?? ''));
    $to = trim((string) (dcInput('to') ?? ''));
    $storeId = (int) (dcInput('store_id') ?? 0);

    return [
        $from !== '' ? $from : null,
        $to !== '' ? $to : null,
        // 0 means every branch, which is what the dashboard shows by default.
        $storeId > 0 ? $storeId : null,
    ];
}

/**
 * GET /dc-cafe/api/v1/analytics — everything the dashboard and reports display.
 *
 * One request rather than five: the four analyses are read together and any
 * staleness between them would be a disagreement about the same period.
 */
function apiGetAnalytics(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole(...dcAnalyticsRoles());

    [$from, $to, $storeId] = dcAnalyticsRequestScope();

    $bundle = dcAnalyticsBundle($from, $to, $storeId);

    dcJsonResponse(['ok' => true] + $bundle);
}

/**
 * GET /dc-cafe/api/v1/analytics/export — the same figures as CSV.
 *
 * Exported rather than audited silently: the trail records who took the numbers
 * out and for which period, because a reporting export is the point at which
 * trading data leaves the application.
 */
function apiExportAnalyticsCsv(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole(...dcAnalyticsRoles());

    [$from, $to, $storeId] = dcAnalyticsRequestScope();
    $bundle = dcAnalyticsBundle($from, $to, $storeId);
    $range = $bundle['range'];

    dc_auditLog('report.exported', 'dc_reports', null, null, [
        'kind' => 'analytics',
        'from' => $range['from'],
        'to' => $range['to'],
        'store_id' => $storeId,
    ]);

    $scope = $storeId === null ? 'all branches' : ('store ' . $storeId);
    $filename = 'dc-cafe-analytics-' . $range['from'] . '-to-' . $range['to'] . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // BOM so Excel reads the peso figures as UTF-8

    fputcsv($out, ['DC Cafe analytics', $range['from'] . ' to ' . $range['to'], $scope]);
    if (!empty($range['clamped'])) {
        fputcsv($out, ['Note', 'Range limited to the most recent ' . $range['max_days'] . ' days']);
    }
    fputcsv($out, []);

    fputcsv($out, ['Sales']);
    fputcsv($out, ['Scope', 'Orders', 'Revenue', 'Average ticket', 'Share %']);
    $overall = $bundle['sales']['overall'];
    fputcsv($out, ['All branches', $overall['orders'], $overall['revenue'], $overall['avg_ticket'], 100]);
    foreach ($bundle['sales']['branches'] as $branch) {
        fputcsv($out, [$branch['name'], $branch['orders'], $branch['revenue'], $branch['avg_ticket'], $branch['share_pct']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Best sellers (all branches)']);
    fputcsv($out, ['Rank', 'Product', 'Units', 'Revenue', 'Share %']);
    foreach ($bundle['products']['top'] as $row) {
        fputcsv($out, [$row['rank'], $row['name'], $row['qty'], $row['revenue'], $row['revenue_share_pct']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Best sellers per branch']);
    foreach ($bundle['products']['by_branch'] as $branch) {
        fputcsv($out, [$branch['name']]);
        if ($branch['products'] === []) {
            fputcsv($out, ['(no sales in this period)']);
            continue;
        }
        fputcsv($out, ['Rank', 'Product', 'Units', 'Revenue']);
        foreach ($branch['products'] as $row) {
            fputcsv($out, [$row['rank'], $row['name'], $row['qty'], $row['revenue']]);
        }
    }
    fputcsv($out, []);

    $pareto = $bundle['pareto'];
    fputcsv($out, ['Pareto', $pareto['vital_few'] . ' of ' . $pareto['total_products'] . ' products make up '
        . $pareto['cut_pct'] . '% of revenue']);
    fputcsv($out, ['Rank', 'Product', 'Revenue', 'Cumulative', 'Cumulative %']);
    foreach ($pareto['rows'] as $row) {
        fputcsv($out, [$row['rank'], $row['name'], $row['revenue'], $row['cumulative_revenue'], $row['cumulative_pct']]);
    }
    fputcsv($out, []);

    foreach (['weekly' => 'Forecast (weekly)', 'monthly' => 'Forecast (monthly)'] as $key => $heading) {
        $forecast = $bundle['forecast'][$key];
        fputcsv($out, [$heading]);
        fputcsv($out, ['Method', $forecast['method'], 'Confidence', $forecast['confidence']]);
        fputcsv($out, ['Note', $forecast['note']]);
        fputcsv($out, ['Period', 'Revenue', 'Kind']);
        foreach ($forecast['observed'] as $bucket) {
            if (empty($bucket['complete'])) {
                continue; // Not fitted on, so not reported as though it were.
            }
            fputcsv($out, [$bucket['label'], $bucket['revenue'], 'actual']);
        }
        foreach ($forecast['projection'] as $step) {
            fputcsv($out, ['+' . $step['period'], $step['revenue'], 'projected']);
        }
        fputcsv($out, []);
    }

    fclose($out);
    exit;
}
