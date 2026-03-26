<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Reports (helpers/50-reports.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Sales aggregate report.
 *
 * @param array $params  period (today|week|month|year|custom), start_date, end_date, product_id
 * @return array  total_revenue, order_count, avg_order_value, top_products[], revenue_by_day[]
 */
function ecReportSales(array $params = []): array
{
    $db = ecDb();

    [$dateFrom, $dateTo] = ecReportDateRange($params);

    // Let other modules augment report params
    $params = app()->hooks()->filter('ecommerce.reports.sales.params', $params);

    try {
        $base = "FROM ec_orders o
                 WHERE o.status NOT IN ('cancelled', 'refunded')
                   AND DATE(o.created_at) BETWEEN ? AND ?";
        $baseParams = [$dateFrom, $dateTo];

        $totalRevenue = (float)$db->query(
            "SELECT COALESCE(SUM(o.total), 0) $base", $baseParams
        )->fetchColumn();

        $orderCount = (int)$db->query(
            "SELECT COUNT(*) $base", $baseParams
        )->fetchColumn();

        $avgOrderValue = $orderCount > 0 ? round($totalRevenue / $orderCount, 2) : 0.0;

        $topProducts = $db->query(
            "SELECT oi.product_id, oi.product_title, oi.sku,
                    SUM(oi.qty) as units_sold,
                    SUM(oi.line_total) as revenue
             FROM ec_order_items oi
             INNER JOIN ec_orders o ON o.id = oi.order_id
             WHERE o.status NOT IN ('cancelled', 'refunded')
               AND DATE(o.created_at) BETWEEN ? AND ?
             GROUP BY oi.product_id, oi.product_title, oi.sku
             ORDER BY revenue DESC
             LIMIT 10",
            $baseParams
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $revenueByDay = $db->query(
            "SELECT DATE(o.created_at) as date,
                    COUNT(*) as orders,
                    COALESCE(SUM(o.total), 0) as revenue
             $base
             GROUP BY DATE(o.created_at)
             ORDER BY date ASC",
            $baseParams
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $sym = (string)ecSettings('currency_symbol');

        $data = [
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'total_revenue'   => round($totalRevenue, 2),
            'revenue_fmt'     => $sym . number_format($totalRevenue, 2),
            'order_count'     => $orderCount,
            'avg_order_value' => $avgOrderValue,
            'avg_fmt'         => $sym . number_format($avgOrderValue, 2),
            'top_products'    => $topProducts,
            'revenue_by_day'  => $revenueByDay,
        ];

        // Allow other modules to extend
        return app()->hooks()->filter('ecommerce.reports.sales.data', $data, $params);

    } catch (\Throwable $e) {
        write_log('ecReportSales error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        return [
            'date_from' => $dateFrom, 'date_to' => $dateTo,
            'total_revenue' => 0, 'order_count' => 0, 'avg_order_value' => 0,
            'top_products' => [], 'revenue_by_day' => [],
        ];
    }
}

/**
 * Inventory status report — products low on stock or out of stock.
 */
function ecReportInventory(): array
{
    $threshold = (int)ecSettings('low_stock_threshold');

    try {
        $rows = ecDb()->query(
            "SELECT c.id, c.title, c.slug,
                    ec.config,
                    JSON_UNQUOTE(JSON_EXTRACT(ec.config, '$.sku')) as sku,
                    CAST(JSON_EXTRACT(ec.config, '$.stock_qty') AS SIGNED) as stock_qty,
                    JSON_EXTRACT(ec.config, '$.track_stock') as track_stock
             FROM cms_content c
             INNER JOIN cms_entity_capabilities ec ON ec.entity_id = c.id AND ec.capability_id = 'inventory'
             WHERE c.type = 'product'
               AND c.deleted_at IS NULL
               AND JSON_EXTRACT(ec.config, '$.track_stock') = true
               AND CAST(JSON_EXTRACT(ec.config, '$.stock_qty') AS SIGNED) <= ?
             ORDER BY stock_qty ASC
             LIMIT 50",
            [$threshold]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return [
            'low_stock_threshold' => $threshold,
            'items'               => $rows,
            'count'               => count($rows),
        ];
    } catch (\Throwable $e) {
        write_log('ecReportInventory error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        return ['low_stock_threshold' => $threshold, 'items' => [], 'count' => 0];
    }
}

/**
 * Resolve date range from params.
 * @return array  [dateFrom, dateTo] in Y-m-d format
 */
function ecReportDateRange(array $params): array
{
    $period = $params['period'] ?? 'month';

    if ($period === 'custom' && !empty($params['start_date']) && !empty($params['end_date'])) {
        return [
            date('Y-m-d', strtotime($params['start_date'])),
            date('Y-m-d', strtotime($params['end_date'])),
        ];
    }

    $today = date('Y-m-d');
    return match ($period) {
        'today'  => [$today, $today],
        'week'   => [date('Y-m-d', strtotime('monday this week')), $today],
        'year'   => [date('Y-01-01'), $today],
        default  => [date('Y-m-01'), $today],  // month
    };
}
