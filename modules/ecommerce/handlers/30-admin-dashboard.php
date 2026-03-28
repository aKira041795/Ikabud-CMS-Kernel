<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Dashboard (handlers/30-admin-dashboard.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /ecommerce/admin  — admin dashboard
 */
function ecAdminDashboard(): void
{
    $user = ecRequireAdmin();

    $db    = ecDb();
    $today = date('Y-m-d');

    // Today stats
    try {
        $todayRevenue = (float)$db->query(
            "SELECT COALESCE(SUM(total), 0) FROM ec_orders WHERE DATE(created_at) = ? AND status NOT IN ('cancelled', 'refunded')",
            [$today]
        )->fetchColumn();

        $todayOrders = (int)$db->query(
            "SELECT COUNT(*) FROM ec_orders WHERE DATE(created_at) = ?",
            [$today]
        )->fetchColumn();

        $pendingOrders = (int)$db->query(
            "SELECT COUNT(*) FROM ec_orders WHERE status = 'pending'"
        )->fetchColumn();

        $monthRevenue = (float)$db->query(
            "SELECT COALESCE(SUM(total), 0) FROM ec_orders WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW()) AND status NOT IN ('cancelled', 'refunded')"
        )->fetchColumn();

        $recentOrders = $db->query(
            "SELECT id, order_number, guest_name, guest_email, status, payment_status, total, currency, created_at
             FROM ec_orders ORDER BY created_at DESC LIMIT 10"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $lowStock = ecReportInventory();
    } catch (\Throwable $e) {
        $todayRevenue = $todayOrders = $pendingOrders = $monthRevenue = 0;
        $recentOrders = [];
        $lowStock     = ['items' => [], 'count' => 0];
    }

    $ctx = ecAdminContext($user, 'dashboard', [
        'today_revenue'  => $todayRevenue,
        'today_orders'   => $todayOrders,
        'pending_orders' => $pendingOrders,
        'month_revenue'  => $monthRevenue,
        'recent_orders'  => $recentOrders,
        'low_stock'      => $lowStock,
    ]);

    ecRender('modules/ecommerce/admin/dashboard.disyl', $ctx);
}
