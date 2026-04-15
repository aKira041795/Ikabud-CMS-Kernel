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
            $itemScope = ecStoreOwnedLineItemPredicate('o', 'oi', 'store_sales_scope_items');
            $storeParams = array_merge(
                ecStoreScopeQueryParams($storeId, (int)$itemScope['params_per_store']),
                $baseParams
            );

            $totalRevenue = (float)$db->query(
                "SELECT COALESCE(SUM(oi.line_total), 0)
                 FROM ec_order_items oi
                 INNER JOIN ec_orders o ON o.id = oi.order_id
                 WHERE {$itemScope['sql']}
                   AND o.status NOT IN ('cancelled', 'refunded')
                   AND o.created_at >= ? AND o.created_at <= ?",
                $storeParams
            )->fetchColumn();

            $orderCount = (int)$db->query(
                "SELECT COUNT(DISTINCT o.id)
                 FROM ec_order_items oi
                 INNER JOIN ec_orders o ON o.id = oi.order_id
                                 WHERE {$itemScope['sql']}
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
                                 WHERE {$itemScope['sql']}
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
                                 WHERE {$itemScope['sql']}
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

// ─── Report Caching (Tier 3.7) ─────────────────────────────────────────

function ecReportCacheAvailable(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        ecDb()->query('SELECT 1 FROM ec_report_cache LIMIT 1');
        $available = true;
    } catch (\Throwable $e) {
        $available = false;
    }
    return $available;
}

function ecReportCacheKey(string $reportType, array $params): string
{
    $storeId = (int)($params['store_id'] ?? 0);
    $tz = trim((string)($params['timezone'] ?? ''));
    $hash = hash('sha256', json_encode($params));
    return "{$reportType}:{$storeId}:{$hash}" . ($tz ? ":{$tz}" : '');
}

function ecReportCacheGet(string $cacheKey): ?array
{
    if (!ecReportCacheAvailable()) return null;
    try {
        $row = ecDb()->query(
            'SELECT data_json FROM ec_report_cache WHERE cache_key = ? AND expires_at > NOW() LIMIT 1',
            [$cacheKey]
        );
        if ($row instanceof \PDOStatement) $row = $row->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row['data_json'])) {
            $decoded = json_decode($row['data_json'], true);
            return is_array($decoded) ? $decoded : null;
        }
    } catch (\Throwable $e) {}
    return null;
}

function ecReportCacheSet(string $cacheKey, string $reportType, array $params, array $data, int $ttlSeconds = 300): void
{
    if (!ecReportCacheAvailable()) return;
    try {
        $paramsHash = hash('sha256', json_encode($params));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ecDb()->execute(
            'INSERT INTO ec_report_cache (cache_key, report_type, params_hash, data_json, expires_at, created_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
             ON DUPLICATE KEY UPDATE
                data_json = VALUES(data_json),
                expires_at = VALUES(expires_at),
                created_at = NOW()',
            [$cacheKey, $reportType, $paramsHash, $json, $ttlSeconds]
        );
    } catch (\Throwable $e) {
        write_log('ecReportCacheSet error: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce']);
    }
}

function ecReportCacheInvalidate(string $reportType): void
{
    if (!ecReportCacheAvailable()) return;
    try {
        ecDb()->execute('DELETE FROM ec_report_cache WHERE report_type = ?', [$reportType]);
    } catch (\Throwable $e) {}
}

function ecReportCachePurgeExpired(): int
{
    if (!ecReportCacheAvailable()) return 0;
    try {
        return (int)ecDb()->execute('DELETE FROM ec_report_cache WHERE expires_at <= NOW()');
    } catch (\Throwable $e) {
        return 0;
    }
}

function ecReportSalesCached(array $params = []): array
{
    $cacheKey = ecReportCacheKey('sales', $params);
    $cached = ecReportCacheGet($cacheKey);
    if ($cached !== null) {
        $cached['_cached'] = true;
        return $cached;
    }
    $data = ecReportSales($params);
    $ttl = (int)($params['cache_ttl'] ?? 300);
    if ($ttl > 0 && !empty($data['order_count'])) {
        ecReportCacheSet($cacheKey, 'sales', $params, $data, $ttl);
    }
    $data['_cached'] = false;
    return $data;
}

function ecReportInventoryCached(array $params = []): array
{
    $cacheKey = ecReportCacheKey('inventory', $params);
    $cached = ecReportCacheGet($cacheKey);
    if ($cached !== null) {
        $cached['_cached'] = true;
        return $cached;
    }
    $data = ecReportInventory($params);
    $ttl = (int)($params['cache_ttl'] ?? 600);
    if ($ttl > 0) {
        ecReportCacheSet($cacheKey, 'inventory', $params, $data, $ttl);
    }
    $data['_cached'] = false;
    return $data;
}

// ─── Timezone Support (Tier 3.7) ───────────────────────────────────────

function ecReportTimezone(array $params): string
{
    $tz = trim((string)($params['timezone'] ?? ''));
    if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
        return $tz;
    }
    $settingsTz = trim((string)ecSettings('report_timezone'));
    if ($settingsTz !== '' && in_array($settingsTz, timezone_identifiers_list(), true)) {
        return $settingsTz;
    }
    return date_default_timezone_get();
}

function ecReportDateRangeWithTimezone(array $params): array
{
    [$dateFrom, $dateTo] = ecReportDateRange($params);
    $tz = ecReportTimezone($params);
    return [$dateFrom, $dateTo, $tz];
}

function ecReportConvertDateToTimezone(string $date, string $fromTz, string $toTz): string
{
    try {
        $dt = new \DateTime($date, new \DateTimeZone($fromTz));
        $dt->setTimezone(new \DateTimeZone($toTz));
        return $dt->format('Y-m-d H:i:s');
    } catch (\Throwable $e) {
        return $date;
    }
}
