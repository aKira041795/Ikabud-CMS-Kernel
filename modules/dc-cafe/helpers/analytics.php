<?php
declare(strict_types=1);

/**
 * DC Cafe sales analytics.
 *
 * Read-only reporting over completed orders: totals overall and per branch, best
 * sellers, a Pareto split, and a weekly/monthly projection.
 *
 * Three rules shape all of it:
 *   - Only completed orders count. A voided sale is not revenue, and a parked
 *     order was never a sale at all.
 *   - A product with no sales in the period is left out. A catalogue holds far
 *     more products than any one period sells, so carrying the unsold ones would
 *     bury the answer to "what actually moved".
 *   - Branches are shown even when they sold nothing. There are only a handful,
 *     and a branch with no sales is a fact worth seeing — unlike an unsold
 *     product, which is just catalogue.
 *
 * The fetching is SQL; the arithmetic is plain PHP over arrays. MySQL 5.7 has no
 * window functions, so running totals are accumulated in PHP — which also means
 * the maths can be tested without touching a database.
 *
 * @mysql57-compat: no window functions, no CTEs; daily rows aggregated in PHP.
 */

/** Longest span a single request will aggregate, so a stray range cannot scan forever. */
const DC_ANALYTICS_MAX_DAYS = 400;

/** Default window when the caller does not choose one. */
const DC_ANALYTICS_DEFAULT_DAYS = 30;

/**
 * Normalise a requested date range.
 *
 * Defaults to the last 30 days, swaps a reversed range rather than returning
 * nothing, and clamps the span. Bad input becomes a usable window instead of an
 * error: this is a reporting screen, and refusing to draw anything helps nobody.
 *
 * @return array{from:string,to:string,days:int}
 */
function dcAnalyticsRange(?string $from, ?string $to): array
{
    $today = new \DateTimeImmutable('today');
    $toDate = dcAnalyticsParseDate($to) ?? $today;
    $fromDate = dcAnalyticsParseDate($from) ?? $toDate->sub(new \DateInterval('P' . (DC_ANALYTICS_DEFAULT_DAYS - 1) . 'D'));

    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    $days = (int) $fromDate->diff($toDate)->days + 1;
    $clamped = false;
    if ($days > DC_ANALYTICS_MAX_DAYS) {
        // Reported rather than applied quietly: a report that silently answers a
        // narrower question than the one asked is worse than one that says so.
        $fromDate = $toDate->sub(new \DateInterval('P' . (DC_ANALYTICS_MAX_DAYS - 1) . 'D'));
        $days = DC_ANALYTICS_MAX_DAYS;
        $clamped = true;
    }

    return ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d'), 'days' => $days, 'clamped' => $clamped, 'max_days' => DC_ANALYTICS_MAX_DAYS];
}

/** Parse a Y-m-d date, or null when the caller gave something unusable. */
function dcAnalyticsParseDate(?string $value): ?\DateTimeImmutable
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $parsed instanceof \DateTimeImmutable ? $parsed : null;
}

/**
 * Branch list, active first. Used to label the per-branch rows and to show a
 * branch that sold nothing as a zero rather than omitting it.
 *
 * @return array<int, array{store_id:int,name:string}>
 */
function dcAnalyticsBranches(): array
{
    try {
        return array_map(
            static fn(array $r): array => ['store_id' => (int) $r['store_id'], 'name' => (string) $r['name']],
            dcDb()->query("SELECT store_id, name FROM dc_stores WHERE is_active = 1 ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC)
        );
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Revenue and order counts overall and per branch, for completed orders only.
 *
 * @return array{overall:array,branches:array<int,array>}
 */
function dcAnalyticsSales(string $from, string $to, ?int $storeId = null): array
{
    $db = dcDb();
    $where = "o.status = 'completed' AND DATE(o.transaction_date) BETWEEN ? AND ?";
    $args = [$from, $to];
    if ($storeId !== null && $storeId > 0) {
        $where .= ' AND o.store_id = ?';
        $args[] = $storeId;
    }

    $overall = $db->query(
        "SELECT COUNT(*) AS orders,
                COALESCE(SUM(o.total_amount), 0) AS revenue,
                COALESCE(SUM(o.discount_amount), 0) AS discount,
                COALESCE(AVG(o.total_amount), 0) AS avg_ticket
         FROM dc_orders o
         WHERE {$where}",
        $args
    )->fetch(\PDO::FETCH_ASSOC) ?: [];

    // Items sold, kept separate so an order-level aggregate is not inflated by
    // counting item rows into it.
    $items = (int) $db->query(
        "SELECT COALESCE(SUM(oi.quantity), 0)
         FROM dc_order_items oi
         JOIN dc_orders o ON o.order_id = oi.order_id
         WHERE {$where} AND oi.quantity > 0",
        $args
    )->fetchColumn();

    $revenue = (float) ($overall['revenue'] ?? 0);
    $orderCount = (int) ($overall['orders'] ?? 0);

    $overallOut = [
        'orders' => $orderCount,
        'revenue' => round($revenue, 2),
        'discount' => round((float) ($overall['discount'] ?? 0), 2),
        'avg_ticket' => round((float) ($overall['avg_ticket'] ?? 0), 2),
        'items' => $items,
        'branches' => 0,
    ];

    $byBranch = [];
    foreach ($db->query(
        "SELECT o.store_id,
                COUNT(*) AS orders,
                COALESCE(SUM(o.total_amount), 0) AS revenue,
                COALESCE(AVG(o.total_amount), 0) AS avg_ticket
         FROM dc_orders o
         WHERE {$where}
         GROUP BY o.store_id",
        $args
    )->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $byBranch[(int) $row['store_id']] = [
            'orders' => (int) $row['orders'],
            'revenue' => (float) $row['revenue'],
            'avg_ticket' => (float) $row['avg_ticket'],
        ];
    }

    // Every active branch appears, so a branch with no takings is visible as a
    // zero instead of silently missing from the comparison.
    $branches = [];
    foreach (dcAnalyticsBranches() as $branch) {
        if ($storeId !== null && $storeId > 0 && $branch['store_id'] !== $storeId) {
            continue;
        }
        $found = $byBranch[$branch['store_id']] ?? ['orders' => 0, 'revenue' => 0.0, 'avg_ticket' => 0.0];
        $branches[] = [
            'store_id' => $branch['store_id'],
            'name' => $branch['name'],
            'orders' => $found['orders'],
            'revenue' => round($found['revenue'], 2),
            'avg_ticket' => round($found['avg_ticket'], 2),
            'share_pct' => $revenue > 0 ? round(($found['revenue'] / $revenue) * 100, 1) : 0.0,
        ];
    }
    // A branch that has since been deactivated can still hold takings in range,
    // so anything found in the data but missing from the active list is added.
    foreach ($byBranch as $id => $found) {
        $known = false;
        foreach ($branches as $b) {
            if ($b['store_id'] === $id) {
                $known = true;
                break;
            }
        }
        if (!$known && ($storeId === null || $storeId <= 0 || $id === $storeId)) {
            $branches[] = [
                'store_id' => $id,
                'name' => 'Branch #' . $id,
                'orders' => $found['orders'],
                'revenue' => round($found['revenue'], 2),
                'avg_ticket' => round($found['avg_ticket'], 2),
                'share_pct' => $revenue > 0 ? round(($found['revenue'] / $revenue) * 100, 1) : 0.0,
            ];
        }
    }
    usort($branches, static fn(array $a, array $b): int => $b['revenue'] <=> $a['revenue']);
    $overallOut['branches'] = count($branches);

    return ['overall' => $overallOut, 'branches' => $branches];
}

/**
 * One row per product that actually sold in the period.
 *
 * A product with no sales is absent by construction — the join only keeps items
 * that exist on a completed order — and `quantity > 0` is asserted too, so a
 * zero-quantity line cannot slip a product in.
 *
 * @return array<int, array{product_id:int,name:string,qty:float,revenue:float,orders:int}>
 */
function dcAnalyticsProductRows(string $from, string $to, ?int $storeId = null): array
{
    $where = "o.status = 'completed' AND DATE(o.transaction_date) BETWEEN ? AND ? AND oi.quantity > 0";
    $args = [$from, $to];
    if ($storeId !== null && $storeId > 0) {
        $where .= ' AND o.store_id = ?';
        $args[] = $storeId;
    }

    return array_map(
        static fn(array $r): array => [
            'product_id' => (int) $r['product_id'],
            'name' => (string) $r['name'],
            'qty' => (float) $r['qty'],
            'revenue' => round((float) $r['revenue'], 2),
            'orders' => (int) $r['orders'],
        ],
        dcDb()->query(
            "SELECT oi.product_id, p.name,
                    SUM(oi.quantity) AS qty,
                    COALESCE(SUM(oi.total_price), 0) AS revenue,
                    COUNT(DISTINCT oi.order_id) AS orders
             FROM dc_order_items oi
             JOIN dc_orders o ON o.order_id = oi.order_id
             JOIN dc_products p ON p.product_id = oi.product_id
             WHERE {$where}
             GROUP BY oi.product_id, p.name
             ORDER BY revenue DESC",
            $args
        )->fetchAll(\PDO::FETCH_ASSOC)
    );
}

/**
 * Rank products for a "best sellers" list.
 *
 * Pure: takes the rows above and returns them ranked, with each one's share of
 * the period's revenue so a reader can see how much of the total the leader is.
 *
 * @param array<int, array> $rows
 * @return array<int, array>
 */
function dcAnalyticsTopProducts(array $rows, int $limit = 10, string $by = 'revenue'): array
{
    $totalRevenue = 0.0;
    $totalQty = 0.0;
    foreach ($rows as $row) {
        $totalRevenue += (float) $row['revenue'];
        $totalQty += (float) $row['qty'];
    }

    $ranked = $rows;
    usort($ranked, static function (array $a, array $b) use ($by): int {
        $left = $by === 'qty' ? (float) $a['qty'] : (float) $a['revenue'];
        $right = $by === 'qty' ? (float) $b['qty'] : (float) $b['revenue'];
        return $right <=> $left ?: strcmp((string) $a['name'], (string) $b['name']);
    });

    $out = [];
    foreach (array_slice($ranked, 0, max(1, $limit)) as $index => $row) {
        $out[] = [
            'rank' => $index + 1,
            'product_id' => (int) $row['product_id'],
            'name' => (string) $row['name'],
            'qty' => (float) $row['qty'],
            'revenue' => (float) $row['revenue'],
            'orders' => (int) $row['orders'],
            'revenue_share_pct' => $totalRevenue > 0 ? round(((float) $row['revenue'] / $totalRevenue) * 100, 1) : 0.0,
            'qty_share_pct' => $totalQty > 0 ? round(((float) $row['qty'] / $totalQty) * 100, 1) : 0.0,
        ];
    }
    return $out;
}

/**
 * Pareto split: how few products make up most of the revenue.
 *
 * Products are ranked by revenue and a running total is accumulated here, in
 * PHP, because MySQL 5.7 cannot express `SUM(...) OVER (ORDER BY ...)`.
 *
 * A product that sold but earned nothing (fully discounted) contributes no
 * revenue, so it is excluded: it cannot form part of a cumulative revenue share,
 * and leaving it in would only pad the count of products needed.
 *
 * @param array<int, array> $rows  product rows from dcAnalyticsProductRows()
 * @param float $cut               the share of revenue considered "the bulk", 0.8 = the classic 80/20
 * @return array{vital_few:int,total_products:int,cut:float,revenue_total:float,rows:array<int,array>,reachable:bool}
 */
function dcAnalyticsPareto(array $rows, float $cut = 0.8): array
{
    $earning = array_values(array_filter($rows, static fn(array $r): bool => (float) $r['revenue'] > 0));
    usort($earning, static fn(array $a, array $b): int => (float) $b['revenue'] <=> (float) $a['revenue']);

    $total = 0.0;
    foreach ($earning as $row) {
        $total += (float) $row['revenue'];
    }

    $running = 0.0;
    $out = [];
    $vitalFew = 0;
    foreach ($earning as $index => $row) {
        $running += (float) $row['revenue'];
        $cumulativePct = $total > 0 ? ($running / $total) * 100 : 0.0;
        // The first product to reach the cut is the last one that is "vital";
        // counting it is what makes the number match the usual reading of the rule.
        if ($vitalFew === 0 && $cumulativePct >= $cut * 100) {
            $vitalFew = $index + 1;
        }
        $out[] = [
            'rank' => $index + 1,
            'product_id' => (int) $row['product_id'],
            'name' => (string) $row['name'],
            'revenue' => (float) $row['revenue'],
            'qty' => (float) $row['qty'],
            'cumulative_revenue' => round($running, 2),
            'cumulative_pct' => round($cumulativePct, 1),
            'revenue_share_pct' => $total > 0 ? round(((float) $row['revenue'] / $total) * 100, 1) : 0.0,
        ];
    }

    return [
        'vital_few' => $vitalFew > 0 ? $vitalFew : count($out),
        'total_products' => count($out),
        'cut' => $cut,
        'cut_pct' => round($cut * 100, 1),
        'revenue_total' => round($total, 2),
        'rows' => $out,
        // False when even selling everything could not reach the cut, which only
        // happens on a single product or rounding — worth saying rather than
        // reporting a percentage that quietly means nothing.
        'reachable' => $total > 0,
    ];
}

/**
 * Daily revenue for the period, used as the basis for the forecast.
 *
 * Days with no trade return no row; the series is filled in PHP so gaps are
 * zeroes rather than missing keys.
 *
 * @return array<string,float>  date => revenue, every day in range present
 */
function dcAnalyticsDailySeries(string $from, string $to, ?int $storeId = null): array
{
    $where = "o.status = 'completed' AND DATE(o.transaction_date) BETWEEN ? AND ?";
    $args = [$from, $to];
    if ($storeId !== null && $storeId > 0) {
        $where .= ' AND o.store_id = ?';
        $args[] = $storeId;
    }

    $found = [];
    foreach (dcDb()->query(
        "SELECT DATE(o.transaction_date) AS day, COALESCE(SUM(o.total_amount), 0) AS revenue
         FROM dc_orders o
         WHERE {$where}
         GROUP BY DATE(o.transaction_date)",
        $args
    )->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $found[(string) $row['day']] = (float) $row['revenue'];
    }

    $series = [];
    $cursor = new \DateTimeImmutable($from);
    $end = new \DateTimeImmutable($to);
    while ($cursor <= $end) {
        $key = $cursor->format('Y-m-d');
        $series[$key] = $found[$key] ?? 0.0;
        $cursor = $cursor->add(new \DateInterval('P1D'));
    }
    return $series;
}

/**
 * Roll a daily series up into whole weeks or months.
 *
 * Only periods that lie **entirely** inside the range are kept. A half-finished
 * week or month is not comparable with a complete one, and feeding it to a
 * forecast would understate the trend — so partial periods are dropped and
 * reported as such.
 *
 * Pure: takes the daily series and returns the buckets, each flagged with whether
 * the range covers it entirely.
 *
 * @param array<string,float> $daily
 * @return array<int, array{key:string,label:string,start:string,end:string,revenue:float,days:int,complete:bool}>
 */
function dcAnalyticsBuckets(array $daily, string $granularity): array
{
    $buckets = [];
    foreach ($daily as $date => $revenue) {
        $day = new \DateTimeImmutable((string) $date);
        if ($granularity === 'month') {
            $start = $day->modify('first day of this month');
            $end = $day->modify('last day of this month');
            $key = $start->format('Y-m');
            $label = $start->format('M Y');
        } else {
            $start = $day->modify('monday this week');
            $end = $start->add(new \DateInterval('P6D'));
            $key = $start->format('o-\WW');
            $label = 'Week of ' . $start->format('j M');
        }

        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'key' => $key,
                'label' => $label,
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
                'revenue' => 0.0,
                'days' => 0,
                'complete' => true,
            ];
        }
        $buckets[$key]['revenue'] += (float) $revenue;
        $buckets[$key]['days']++;
    }

    ksort($buckets);

    // A period the range only partly covers is marked, not discarded: the caller
    // decides what to fit on, and can then say what it left out.
    $rangeStart = array_key_first($daily);
    $rangeEnd = array_key_last($daily);
    foreach ($buckets as $key => $bucket) {
        if (($rangeStart !== null && $bucket['start'] < $rangeStart)
            || ($rangeEnd !== null && $bucket['end'] > $rangeEnd)) {
            $buckets[$key]['complete'] = false;
        }
    }

    return array_values($buckets);
}

/**
 * Project the next periods from a run of whole periods.
 *
 * Least-squares line when there are enough periods to mean anything, otherwise
 * the plain average. The confidence figure is reported rather than hidden: with
 * a handful of weeks the honest answer is "this is thin", and a caller that
 * wants a number can still have one.
 *
 * Pure: takes buckets and returns observed plus projection.
 *
 * @param array<int, array> $buckets from dcAnalyticsBuckets()
 * @return array{periods:int,observed:array<int,array>,average:float,trend_per_period:float|null,projection:array<int,array>,confidence:string,method:string,note:string}
 */
function dcAnalyticsProjection(array $buckets, int $periods = 4, float $clampAtZero = 0.0): array
{
    // Only whole periods are fitted. A week still in progress is not comparable
    // with a finished one, and including it would drag the line towards whatever
    // has happened so far today.
    $all = array_values($buckets);
    $partial = count(array_filter($all, static fn(array $b): bool => empty($b['complete'])));
    $buckets = array_values(array_filter($all, static fn(array $b): bool => !empty($b['complete'])));

    $values = array_map(static fn(array $b): float => (float) $b['revenue'], $buckets);
    $count = count($values);
    $sum = array_sum($values);
    $average = $count > 0 ? $sum / $count : 0.0;

    // Confidence follows the periods that actually traded, not the periods that
    // merely elapsed. Fifty empty weeks and four with sales is four periods of
    // evidence, however long the calendar says the window was.
    $withSales = count(array_filter($values, static fn(float $v): bool => $v > 0));

    $trend = null;
    $intercept = null;
    $method = 'average';
    // A line needs three points to have a slope at all, and three *trading*
    // periods to be worth believing. Fitting a trend to a single busy week and
    // then extrapolating it turns one good Saturday into a forecast of
    // escalation, so below that bar the typical period is the honest answer.
    if ($count >= 3 && $withSales >= 3) {
        // Least squares on the period index. Slope is revenue per period.
        $meanX = ($count - 1) / 2;
        $meanY = $average;
        $num = 0.0;
        $den = 0.0;
        foreach ($values as $i => $y) {
            $num += ($i - $meanX) * ($y - $meanY);
            $den += ($i - $meanX) ** 2;
        }
        if ($den > 0) {
            $trend = $num / $den;
            $intercept = $meanY - ($trend * $meanX);
            $method = 'trend';
        }
    }

    $projection = [];
    for ($step = 1; $step <= $periods; $step++) {
        // Predicted from the fitted line, not from the last observed period. A
        // single quiet week at the end of the window would otherwise drag the
        // whole projection down with it.
        $value = ($trend !== null && $intercept !== null)
            ? $intercept + ($trend * ($count - 1 + $step))
            : $average;
        // Revenue cannot be negative, so a steep downward trend is floored rather
        // than reported as a negative forecast.
        $value = max($clampAtZero, $value);
        $projection[] = ['period' => $step, 'revenue' => round($value, 2)];
    }

    if ($withSales >= 8) {
        $confidence = 'medium';
    } elseif ($withSales >= 4) {
        $confidence = 'low';
    } else {
        $confidence = 'insufficient';
    }

    $note = match ($confidence) {
        'insufficient' => 'Only ' . $withSales . ' of ' . $count
            . ' complete periods had any sales, so this is the typical period rather than a trend.',
        'low' => $withSales . ' of ' . $count
            . ' complete periods had sales; treat the direction as indicative only.',
        default => 'Based on ' . $withSales . ' trading periods out of ' . $count . ' complete.',
    };
    if ($partial > 0) {
        // Said out loud because the excluded period usually holds the most recent
        // takings, so the totals elsewhere on the screen will not match these.
        $note .= ' ' . $partial . ' part-finished period'
            . ($partial === 1 ? ' is' : 's are') . ' not counted.';
    }

    return [
        'periods' => $count,
        'periods_with_sales' => $withSales,
        'periods_excluded_partial' => $partial,
        'observed' => $buckets,
        'average' => round($average, 2),
        'trend_per_period' => $trend !== null ? round($trend, 2) : null,
        'projection' => $projection,
        'confidence' => $confidence,
        'method' => $method,
        'note' => $note,
    ];
}

/**
 * Everything the dashboard and the reports screen need, in one call.
 *
 * Assembled here so both surfaces show the same numbers by construction rather
 * than by two implementations that happen to agree today.
 *
 * @return array<string,mixed>
 */
function dcAnalyticsBundle(?string $from = null, ?string $to = null, ?int $storeId = null): array
{
    $range = dcAnalyticsRange($from, $to);
    $fromDate = $range['from'];
    $toDate = $range['to'];

    $sales = dcAnalyticsSales($fromDate, $toDate, $storeId);
    $productRows = dcAnalyticsProductRows($fromDate, $toDate, $storeId);

    $perBranchProducts = [];
    foreach (dcAnalyticsBranches() as $branch) {
        if ($storeId !== null && $storeId > 0 && $branch['store_id'] !== $storeId) {
            continue;
        }
        $perBranchProducts[] = [
            'store_id' => $branch['store_id'],
            'name' => $branch['name'],
            'products' => dcAnalyticsTopProducts(
                dcAnalyticsProductRows($fromDate, $toDate, $branch['store_id']),
                5
            ),
        ];
    }

    $daily = dcAnalyticsDailySeries($fromDate, $toDate, $storeId);

    return [
        'range' => $range,
        'store_id' => $storeId,
        'sales' => $sales,
        'products' => [
            'top' => dcAnalyticsTopProducts($productRows, 10),
            'by_branch' => $perBranchProducts,
            'sold_count' => count($productRows),
        ],
        'pareto' => dcAnalyticsPareto($productRows),
        'forecast' => [
            'weekly' => dcAnalyticsProjection(dcAnalyticsBuckets($daily, 'week'), 4),
            'monthly' => dcAnalyticsProjection(dcAnalyticsBuckets($daily, 'month'), 3),
        ],
    ];
}

/**
 * Whether this branch offers the viewer role its dashboard and reports.
 *
 * Off by default. It is a branch decision: a site with nobody to read the
 * numbers has no reason to expose a read-only role, and switching it on is what
 * lets a viewer sign in to anything at all.
 */
function dcViewerDashboardEnabled(): bool
{
    return dcSettingBool('pos_viewer_dashboard_enabled');
}

/** Read-only roles that may see the analytics when the branch allows a viewer. */
function dcAnalyticsRoles(): array
{
    return dcViewerDashboardEnabled()
        ? ['admin', 'supervisor', 'auditor', 'viewer']
        : ['admin', 'supervisor', 'auditor'];
}

/**
 * Where a signed-in user of this role belongs.
 *
 * One decision in one place. This ternary used to be written out three times, and
 * that is exactly how a role ends up being sent somewhere it is refused.
 */
function dcLandingForRole(string $role): string
{
    return in_array($role, ['admin', 'supervisor', 'cashier'], true)
        ? '/dc-cafe/pos'
        : '/dc-cafe/dashboard';
}

/**
 * Whether this user's session has anywhere left that it may go.
 *
 * A viewer is the only role that can lose every one of its pages while it is signed
 * in, because the branch setting that grants them can be switched off underneath it.
 * When that happens the landing above must not be followed: it is refused, which
 * bounces back to the entry route, which offers the same landing again. Callers use
 * this to end the session instead — see pageDcCafeLogin().
 *
 * @param array<string, mixed> $user
 */
function dcSessionHasLanding(array $user): bool
{
    return (string) ($user['role'] ?? '') !== 'viewer' || dcViewerDashboardEnabled();
}
