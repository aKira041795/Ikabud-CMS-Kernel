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

    dcCsvRow($out, ['DC Cafe analytics', $range['from'] . ' to ' . $range['to'], $scope]);
    if (!empty($range['clamped'])) {
        dcCsvRow($out, ['Note', 'Range limited to the most recent ' . $range['max_days'] . ' days']);
    }
    dcCsvRow($out, []);

    dcCsvRow($out, ['Sales']);
    dcCsvRow($out, ['Scope', 'Orders', 'Revenue', 'Average ticket', 'Share %']);
    $overall = $bundle['sales']['overall'];
    dcCsvRow($out, ['All branches', $overall['orders'], $overall['revenue'], $overall['avg_ticket'], 100]);
    foreach ($bundle['sales']['branches'] as $branch) {
        dcCsvRow($out, [$branch['name'], $branch['orders'], $branch['revenue'], $branch['avg_ticket'], $branch['share_pct']]);
    }
    dcCsvRow($out, []);

    dcCsvRow($out, ['Best sellers (all branches)']);
    dcCsvRow($out, ['Rank', 'Product', 'Units', 'Revenue', 'Share %']);
    foreach ($bundle['products']['top'] as $row) {
        dcCsvRow($out, [$row['rank'], $row['name'], $row['qty'], $row['revenue'], $row['revenue_share_pct']]);
    }
    dcCsvRow($out, []);

    dcCsvRow($out, ['Best sellers per branch']);
    foreach ($bundle['products']['by_branch'] as $branch) {
        dcCsvRow($out, [$branch['name']]);
        if ($branch['products'] === []) {
            dcCsvRow($out, ['(no sales in this period)']);
            continue;
        }
        dcCsvRow($out, ['Rank', 'Product', 'Units', 'Revenue']);
        foreach ($branch['products'] as $row) {
            dcCsvRow($out, [$row['rank'], $row['name'], $row['qty'], $row['revenue']]);
        }
    }
    dcCsvRow($out, []);

    $pareto = $bundle['pareto'];
    dcCsvRow($out, ['Pareto', $pareto['vital_few'] . ' of ' . $pareto['total_products'] . ' products make up '
        . $pareto['cut_pct'] . '% of revenue']);
    dcCsvRow($out, ['Rank', 'Product', 'Revenue', 'Cumulative', 'Cumulative %']);
    foreach ($pareto['rows'] as $row) {
        dcCsvRow($out, [$row['rank'], $row['name'], $row['revenue'], $row['cumulative_revenue'], $row['cumulative_pct']]);
    }
    dcCsvRow($out, []);

    foreach (['weekly' => 'Forecast (weekly)', 'monthly' => 'Forecast (monthly)'] as $key => $heading) {
        $forecast = $bundle['forecast'][$key];
        dcCsvRow($out, [$heading]);
        dcCsvRow($out, ['Method', $forecast['method'], 'Confidence', $forecast['confidence']]);
        dcCsvRow($out, ['Note', $forecast['note']]);
        dcCsvRow($out, ['Period', 'Revenue', 'Kind']);
        foreach ($forecast['observed'] as $bucket) {
            if (empty($bucket['complete'])) {
                continue; // Not fitted on, so not reported as though it were.
            }
            dcCsvRow($out, [$bucket['label'], $bucket['revenue'], 'actual']);
        }
        foreach ($forecast['projection'] as $step) {
            dcCsvRow($out, ['+' . $step['period'], $step['revenue'], 'projected']);
        }
        dcCsvRow($out, []);
    }

    fclose($out);
    exit;
}

/**
 * GET /dc-cafe/api/v1/analytics/export.pdf
 *
 * The same figures as the CSV, laid out to be read and kept rather than parsed. dompdf is
 * already a dependency of this project, so this costs no new one. Remote assets are disabled
 * because the deployment host cannot fetch them — which is why the styling below is inline.
 */
function apiExportAnalyticsPdf(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole(...dcAnalyticsRoles());

    [$from, $to, $storeId] = dcAnalyticsRequestScope();
    $bundle = dcAnalyticsBundle($from, $to, $storeId);
    $range = $bundle['range'];

    dc_auditLog('report.exported', 'dc_reports', null, null, [
        'kind' => 'analytics',
        'format' => 'pdf',
        'from' => $range['from'],
        'to' => $range['to'],
        'store_id' => $storeId,
    ]);

    if (!class_exists(\Dompdf\Dompdf::class)) {
        dcJsonError('PDF generation is unavailable on this installation', 500);
        return;
    }

    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
    $dompdf->loadHtml(dcAnalyticsPdfHtml($bundle));
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream(
        'dc-cafe-analytics-' . $range['from'] . '-to-' . $range['to'] . '.pdf',
        ['Attachment' => true]
    );
    exit;
}

/**
 * The report as a self-contained HTML document, for dompdf to render.
 *
 * Every figure comes from the same bundle the screen and the CSV read, so what is printed
 * cannot disagree with what was on screen when it was taken.
 *
 * @param array<string, mixed> $bundle
 */
function dcAnalyticsPdfHtml(array $bundle): string
{
    $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $money = static fn($v): string => number_format((float) $v, 2);
    $range = $bundle['range'];
    $overall = $bundle['sales']['overall'];
    $pareto = $bundle['pareto'];

    $h = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 17px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 16px 0 5px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; }
        .muted { color: #6b7280; }
        .note { color: #92400e; margin: 4px 0 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th { text-align: left; background: #f3f4f6; border-bottom: 1px solid #d1d5db; padding: 3px 5px; }
        td { border-bottom: 1px solid #f3f4f6; padding: 3px 5px; }
        .r { text-align: right; }
        .tiles td { border: 0; padding: 2px 6px 2px 0; }
        .tiles .k { color: #6b7280; font-size: 9px; }
        .tiles .v { font-size: 13px; font-weight: bold; }
    </style></head><body>';

    $h .= '<h1>DC Cafe — Sales Analytics</h1>';
    $h .= '<div class="muted">' . $e($range['from']) . ' to ' . $e($range['to'])
        . ' &middot; ' . ($bundle['store_id'] === null ? 'all branches' : 'branch #' . (int) $bundle['store_id'])
        . '</div>';
    if (!empty($range['clamped'])) {
        $h .= '<p class="note">Range limited to the most recent ' . (int) $range['max_days'] . ' days.</p>';
    }

    $h .= '<h2>Sales</h2><table class="tiles"><tr>'
        . '<td><div class="k">Revenue</div><div class="v">' . $money($overall['revenue']) . '</div></td>'
        . '<td><div class="k">Orders</div><div class="v">' . (int) $overall['orders'] . '</div></td>'
        . '<td><div class="k">Average ticket</div><div class="v">' . $money($overall['avg_ticket']) . '</div></td>'
        . '<td><div class="k">Units sold</div><div class="v">' . (int) $overall['items'] . '</div></td>'
        . '</tr></table>';

    $h .= '<table><tr><th>Branch</th><th class="r">Orders</th><th class="r">Revenue</th>'
        . '<th class="r">Average ticket</th><th class="r">Share</th></tr>';
    foreach ($bundle['sales']['branches'] as $b) {
        // Branches that took nothing are listed: a silent branch is a fact, not an absence.
        $h .= '<tr><td>' . $e($b['name']) . '</td><td class="r">' . (int) $b['orders'] . '</td>'
            . '<td class="r">' . $money($b['revenue']) . '</td><td class="r">' . $money($b['avg_ticket']) . '</td>'
            . '<td class="r">' . $e($b['share_pct']) . '%</td></tr>';
    }
    $h .= '</table>';

    $h .= '<h2>Best sellers</h2>';
    if ($bundle['products']['top'] === []) {
        $h .= '<p class="muted">Nothing sold in this period. Products with no sales are not listed.</p>';
    } else {
        $h .= '<table><tr><th>#</th><th>Product</th><th class="r">Units</th><th class="r">Revenue</th>'
            . '<th class="r">Share</th></tr>';
        foreach ($bundle['products']['top'] as $p) {
            $h .= '<tr><td>' . (int) $p['rank'] . '</td><td>' . $e($p['name']) . '</td>'
                . '<td class="r">' . $e($p['qty']) . '</td><td class="r">' . $money($p['revenue']) . '</td>'
                . '<td class="r">' . $e($p['revenue_share_pct']) . '%</td></tr>';
        }
        $h .= '</table><p class="muted">' . (int) $bundle['products']['sold_count']
            . ' product(s) sold; unsold products are not listed.</p>';
    }

    $h .= '<h2>Best sellers per branch</h2>';
    foreach ($bundle['products']['by_branch'] as $b) {
        $h .= '<p><strong>' . $e($b['name']) . '</strong></p>';
        if ($b['products'] === []) {
            $h .= '<p class="muted">No sales in this period.</p>';
            continue;
        }
        $h .= '<table><tr><th>#</th><th>Product</th><th class="r">Units</th><th class="r">Revenue</th></tr>';
        foreach ($b['products'] as $p) {
            $h .= '<tr><td>' . (int) $p['rank'] . '</td><td>' . $e($p['name']) . '</td>'
                . '<td class="r">' . $e($p['qty']) . '</td><td class="r">' . $money($p['revenue']) . '</td></tr>';
        }
        $h .= '</table>';
    }

    $h .= '<h2>Pareto</h2><p>' . (int) $pareto['vital_few'] . ' of ' . (int) $pareto['total_products']
        . ' products account for ' . $e($pareto['cut_pct']) . '% of revenue.</p>';
    if ($pareto['rows'] !== []) {
        $h .= '<table><tr><th>#</th><th>Product</th><th class="r">Revenue</th>'
            . '<th class="r">Cumulative</th><th class="r">Cumulative %</th></tr>';
        foreach ($pareto['rows'] as $r) {
            $h .= '<tr><td>' . (int) $r['rank'] . '</td><td>' . $e($r['name']) . '</td>'
                . '<td class="r">' . $money($r['revenue']) . '</td>'
                . '<td class="r">' . $money($r['cumulative_revenue']) . '</td>'
                . '<td class="r">' . $e($r['cumulative_pct']) . '</td></tr>';
        }
        $h .= '</table>';
    }

    foreach (['weekly' => 'Forecast (weekly)', 'monthly' => 'Forecast (monthly)'] as $key => $heading) {
        $f = $bundle['forecast'][$key];
        $h .= '<h2>' . $e($heading) . '</h2>';
        // Confidence and the note travel with the numbers: a projection without them reads as a
        // fact, and the note is where an excluded part-finished period is disclosed.
        $h .= '<p class="muted">Method: ' . $e($f['method']) . ' &middot; Confidence: ' . $e($f['confidence'])
            . ' &middot; Mean per period: ' . $money($f['average']) . '</p>';
        $h .= '<p class="note">' . $e($f['note']) . '</p>';
        $h .= '<table><tr><th>Period</th><th class="r">Revenue</th><th>Kind</th></tr>';
        foreach ($f['observed'] as $b) {
            if (empty($b['complete'])) {
                continue; // Not fitted on, so not reported as though it were.
            }
            $h .= '<tr><td>' . $e($b['label']) . '</td><td class="r">' . $money($b['revenue'])
                . '</td><td class="muted">actual</td></tr>';
        }
        foreach ($f['projection'] as $p) {
            $h .= '<tr><td>+' . (int) $p['period'] . '</td><td class="r">' . $money($p['revenue'])
                . '</td><td class="muted">projected</td></tr>';
        }
        $h .= '</table>';
    }

    return $h . '</body></html>';
}
