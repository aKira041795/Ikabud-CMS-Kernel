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
    $storeId = max(0, (int)($params['store_id'] ?? 0));

    try {
        $baseParams = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

        if ($storeId > 0) {
            $storeParams = array_merge([$storeId], $baseParams);

            $totalRevenue = (float)$db->query(
                "SELECT COALESCE(SUM(oi.line_total), 0)
                 FROM ec_order_items oi
                 INNER JOIN ec_orders o ON o.id = oi.order_id
                 WHERE oi.store_id = ?
                   AND o.status NOT IN ('cancelled', 'refunded')
                   AND o.created_at >= ? AND o.created_at <= ?",
                $storeParams
            )->fetchColumn();

            $orderCount = (int)$db->query(
                "SELECT COUNT(DISTINCT o.id)
                 FROM ec_order_items oi
                 INNER JOIN ec_orders o ON o.id = oi.order_id
                 WHERE oi.store_id = ?
                   AND o.status NOT IN ('cancelled', 'refunded')
                   AND o.created_at >= ? AND o.created_at <= ?",
                $storeParams
            )->fetchColumn();

            $topProducts = $db->query(
                "SELECT oi.product_id, oi.product_title, oi.sku,
                        SUM(oi.qty) AS units_sold,
                        SUM(oi.line_total) AS revenue
                 FROM ec_order_items oi
                 INNER JOIN ec_orders o ON o.id = oi.order_id
                 WHERE oi.store_id = ?
                   AND o.status NOT IN ('cancelled', 'refunded')
                   AND o.created_at >= ? AND o.created_at <= ?
                 GROUP BY oi.product_id, oi.product_title, oi.sku
                 ORDER BY revenue DESC
                 LIMIT 10",
                $storeParams
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $revenueByDay = $db->query(
                "SELECT DATE(o.created_at) AS date,
                        COUNT(DISTINCT o.id) AS orders,
                        COALESCE(SUM(oi.line_total), 0) AS revenue
                 FROM ec_order_items oi
                 INNER JOIN ec_orders o ON o.id = oi.order_id
                 WHERE oi.store_id = ?
                   AND o.status NOT IN ('cancelled', 'refunded')
                   AND o.created_at >= ? AND o.created_at <= ?
                 GROUP BY DATE(o.created_at)
                 ORDER BY date ASC",
                $storeParams
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } else {
            $base = "FROM ec_orders o
                     WHERE o.status NOT IN ('cancelled', 'refunded')
                       AND o.created_at >= ? AND o.created_at <= ?";

            $totalRevenue = (float)$db->query(
                "SELECT COALESCE(SUM(o.total), 0) $base",
                $baseParams
            )->fetchColumn();

            $orderCount = (int)$db->query(
                "SELECT COUNT(*) $base",
                $baseParams
            )->fetchColumn();

            $topProducts = $db->query(
                "SELECT oi.product_id, oi.product_title, oi.sku,
                        SUM(oi.qty) AS units_sold,
                        SUM(oi.line_total) AS revenue
                 FROM ec_order_items oi
                 INNER JOIN ec_orders o ON o.id = oi.order_id
                 WHERE o.status NOT IN ('cancelled', 'refunded')
                   AND o.created_at >= ? AND o.created_at <= ?
                 GROUP BY oi.product_id, oi.product_title, oi.sku
                 ORDER BY revenue DESC
                 LIMIT 10",
                $baseParams
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $revenueByDay = $db->query(
                "SELECT DATE(o.created_at) AS date,
                        COUNT(*) AS orders,
                        COALESCE(SUM(o.total), 0) AS revenue
                 $base
                 GROUP BY DATE(o.created_at)
                 ORDER BY date ASC",
                $baseParams
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        $avgOrderValue = $orderCount > 0 ? round($totalRevenue / $orderCount, 2) : 0.0;

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
function ecReportInventory(array $params = []): array
{
    $threshold = (int)ecSettings('low_stock_threshold');
    $storeId = max(0, (int)($params['store_id'] ?? 0));
    $limit = max(0, (int)($params['limit'] ?? 0));

    try {
        $join = '';
        $queryParams = [];
        if ($storeId > 0) {
            $join = ' INNER JOIN ec_store_product_overrides store_po ON store_po.product_id = c.id AND store_po.store_id = ? AND store_po.is_visible = 1';
            $queryParams[] = $storeId;
        }

        $candidates = ecDb()->query(
            "SELECT c.id, c.title, c.slug,
                    ec.config AS inventory_config,
                    COALESCE(dm.meta_value, '0') AS is_digital
             FROM cms_content c
             {$join}
             INNER JOIN cms_entity_capabilities ec ON ec.entity_id = c.id AND ec.capability_id = 'inventory'
             LEFT JOIN cms_content_meta dm ON dm.content_id = c.id AND dm.meta_key = '_is_digital'
             WHERE c.type = 'product'
               AND c.deleted_at IS NULL
             ORDER BY c.title ASC",
            $queryParams
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        ecWmsInventorySnapshotMapForSkus(array_map(static function (array $candidate): string {
            $config = (array)json_decode((string)($candidate['inventory_config'] ?? '{}'), true);
            return (string)($config['sku'] ?? '');
        }, $candidates));

        $rows = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $productId = (int)($candidate['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $inventory = ecProductInventoryStateFromConfig(
                (array)json_decode((string)($candidate['inventory_config'] ?? '{}'), true),
                (string)($candidate['is_digital'] ?? '0') === '1',
                $threshold
            );
            if (empty($inventory['track_stock'])) {
                continue;
            }

            $stockQty = array_key_exists('stock_qty', $inventory) && $inventory['stock_qty'] !== null
                ? (int)$inventory['stock_qty']
                : 0;
            if ($stockQty > $threshold) {
                continue;
            }

            $rows[] = [
                'id' => $productId,
                'title' => (string)($candidate['title'] ?? ''),
                'slug' => (string)($candidate['slug'] ?? ''),
                'sku' => (string)($inventory['sku'] ?? ''),
                'stock_qty' => $stockQty,
                'track_stock' => !empty($inventory['track_stock']),
                'in_stock' => !empty($inventory['in_stock']),
                'out_of_stock' => !empty($inventory['out_of_stock']),
                'low_stock' => !empty($inventory['low_stock']),
                'source' => (string)($inventory['source'] ?? 'ecommerce'),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return [(int)($left['stock_qty'] ?? 0), (string)($left['title'] ?? '')]
                <=> [(int)($right['stock_qty'] ?? 0), (string)($right['title'] ?? '')];
        });
        $count = count($rows);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'low_stock_threshold' => $threshold,
            'items'               => $rows,
            'count'               => $count,
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
