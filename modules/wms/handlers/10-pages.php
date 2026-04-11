<?php

declare(strict_types=1);

function wmsPageTasks(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor', 'viewer');
    echo wmsRender('admin/tasks.disyl', wmsAdminContext($user, 'tasks', [
        'page_title' => 'Task Queue',
        'warehouses' => wmsFetchAll('SELECT id, code, name FROM wms_warehouses WHERE deleted_at IS NULL ORDER BY name ASC'),
        'task_users' => wmsFetchAll('SELECT id, full_name, username, role FROM wms_users WHERE is_active = 1 ORDER BY full_name ASC'),
        'products' => wmsFetchAll('SELECT id, sku, name FROM wms_products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC LIMIT 300'),
        'locations' => wmsFetchAll('SELECT id, warehouse_id, code, name FROM wms_locations WHERE deleted_at IS NULL AND is_active = 1 ORDER BY code ASC LIMIT 500'),
    ]));
}

function wmsPageScanner(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor', 'viewer');
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    
    // For mobile layout, we bypass wmsAdminContext which sets up the desktop sidebar.
    // Instead we create a minimal context.
    $context = [
        'auth_user' => $user,
        'current_page' => 'scanner_home',
        'page_title' => 'Scanner',
        'base_url' => $baseUrl,
        'csrf_token' => app()->csrfToken(),
        'content' => wmsRender('admin/scanner-home.disyl', [
            'auth_user' => $user,
            'base_url' => $baseUrl,
            'csrf_token' => app()->csrfToken(),
            'products' => wmsFetchAll('SELECT id, sku, name FROM wms_products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC LIMIT 300'),
            'locations' => wmsFetchAll('SELECT id, warehouse_id, code, name FROM wms_locations WHERE deleted_at IS NULL AND is_active = 1 ORDER BY code ASC LIMIT 500'),
        ])
    ];

    echo wmsRender('layouts/mobile.disyl', $context);
}

function wmsPageDashboard(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor', 'viewer');

    $summary = [
        'products' => 0,
        'warehouses' => 0,
        'locations' => 0,
        'deliveries_pending' => 0,
        'orders_pending' => 0,
        'low_stock_count' => 0,
    ];

    $db = wmsDb();
    try {
        $summary['products'] = (int)($db->query('SELECT COUNT(*) FROM wms_products WHERE deleted_at IS NULL')->fetchColumn() ?: 0);
        $summary['warehouses'] = (int)($db->query('SELECT COUNT(*) FROM wms_warehouses WHERE deleted_at IS NULL')->fetchColumn() ?: 0);
        $summary['locations'] = (int)($db->query('SELECT COUNT(*) FROM wms_locations WHERE deleted_at IS NULL')->fetchColumn() ?: 0);
        $summary['deliveries_pending'] = (int)($db->query("SELECT COUNT(*) FROM wms_deliveries WHERE status IN ('pending', 'partial', 'staged') AND deleted_at IS NULL")->fetchColumn() ?: 0);
        $summary['orders_pending'] = (int)($db->query("SELECT COUNT(*) FROM wms_orders WHERE status IN ('pending', 'picking', 'picked') AND deleted_at IS NULL")->fetchColumn() ?: 0);
        $summary['low_stock_count'] = count(wmsLowStockItems());
    } catch (Throwable $e) {
    }

    $recentDeliveries = [];
    $recentOrders = [];
    $recentMovements = [];

    try {
        $recentDeliveries = wmsFetchAll('SELECT * FROM wms_deliveries WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 8');
        $recentOrders = wmsFetchAll('SELECT * FROM wms_orders WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 8');
        $recentMovements = wmsMovementsList([]);
        $recentMovements = array_slice($recentMovements, 0, 10);
    } catch (Throwable $e) {
    }

    echo wmsRender('admin/dashboard.disyl', wmsAdminContext($user, 'dashboard', [
        'page_title' => 'Warehouse Dashboard',
        'summary' => $summary,
        'recent_deliveries' => $recentDeliveries,
        'recent_orders' => $recentOrders,
        'recent_movements' => $recentMovements,
    ]));
}

function wmsPageReceiving(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor');
    echo wmsRender('admin/receiving.disyl', wmsAdminContext($user, 'receiving', [
        'page_title' => 'Receiving',
        'deliveries' => wmsFetchAll(
            'SELECT
                d.*, w.name AS warehouse_name,
                COALESCE(item_totals.qty_expected_total, 0) AS qty_expected_total,
                COALESCE(item_totals.qty_received_total, 0) AS qty_received_total,
                COALESCE(item_totals.qty_put_away_total, 0) AS qty_put_away_total,
                COALESCE(item_totals.lines_pending_putaway, 0) AS lines_pending_putaway,
                COALESCE(task_totals.open_putaway_tasks, 0) AS open_putaway_tasks
             FROM wms_deliveries d
             INNER JOIN wms_warehouses w ON w.id = d.warehouse_id
             LEFT JOIN (
                 SELECT
                    di.delivery_id,
                    COALESCE(SUM(di.qty_expected), 0) AS qty_expected_total,
                    COALESCE(SUM(di.qty_received), 0) AS qty_received_total,
                    COALESCE(SUM(di.qty_put_away), 0) AS qty_put_away_total,
                    COALESCE(SUM(CASE WHEN di.qty_received > di.qty_put_away THEN 1 ELSE 0 END), 0) AS lines_pending_putaway
                 FROM wms_delivery_items di
                 GROUP BY di.delivery_id
             ) item_totals ON item_totals.delivery_id = d.id
             LEFT JOIN (
                 SELECT di.delivery_id, COUNT(DISTINCT t.id) AS open_putaway_tasks
                 FROM wms_delivery_items di
                 INNER JOIN wms_tasks t ON t.reference_type = ? AND t.reference_id = di.id AND t.task_type = ? AND t.status IN (\'pending\', \'in_progress\')
                 GROUP BY di.delivery_id
             ) task_totals ON task_totals.delivery_id = d.id
             WHERE d.deleted_at IS NULL
             ORDER BY d.created_at DESC
             LIMIT 50',
            ['delivery_item', 'putaway']
        ),
        'warehouses' => wmsFetchAll('SELECT id, code, name FROM wms_warehouses WHERE deleted_at IS NULL ORDER BY name ASC'),
        'products' => wmsFetchAll('SELECT id, sku, name FROM wms_products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC LIMIT 200'),
        'locations' => wmsFetchAll('SELECT id, warehouse_id, code, name FROM wms_locations WHERE deleted_at IS NULL AND is_active = 1 AND COALESCE(is_staging, 0) = 0 ORDER BY code ASC LIMIT 300'),
    ]));
}

function wmsPagePicking(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor');
    echo wmsRender('admin/picking.disyl', wmsAdminContext($user, 'picking', [
        'page_title' => 'Picking',
        'orders' => wmsFetchAll('SELECT * FROM wms_orders WHERE deleted_at IS NULL ORDER BY priority ASC, created_at DESC LIMIT 50'),
    ]));
}

function wmsPageInventory(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor', 'viewer');
    echo wmsRender('admin/inventory.disyl', wmsAdminContext($user, 'inventory', [
        'page_title' => 'Inventory',
        'stock_rows' => array_slice(wmsStockSnapshot(0, []), 0, 200),
        'low_stock_items' => array_slice(wmsLowStockItems(), 0, 50),
        'warehouses' => wmsFetchAll('SELECT id, code, name FROM wms_warehouses WHERE deleted_at IS NULL ORDER BY name ASC'),
        'products' => wmsFetchAll('SELECT id, sku, name FROM wms_products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC LIMIT 200'),
        'locations' => wmsFetchAll('SELECT id, warehouse_id, code, name FROM wms_locations WHERE deleted_at IS NULL AND is_active = 1 ORDER BY code ASC LIMIT 300'),
    ]));
}

function wmsPageSettings(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor');

    // Fetch all runtime configs from wms_configs table
    $rawConfigs = wmsFetchAll('SELECT config_key, config_value FROM wms_configs ORDER BY config_key ASC');
    $configs = [];
    foreach ($rawConfigs as $row) {
        $val = json_decode($row['config_value'], true);
        $configs[$row['config_key']] = (json_last_error() === JSON_ERROR_NONE) ? $val : $row['config_value'];
    }

    // Locations for quarantine dropdown
    $locations = wmsFetchAll('SELECT id, warehouse_id, code, name FROM wms_locations WHERE deleted_at IS NULL AND is_active = 1 ORDER BY code ASC LIMIT 500');

    echo wmsRender('admin/settings.disyl', wmsAdminContext($user, 'settings', [
        'page_title' => 'WMS Settings',
        'configs' => $configs,
        'configs_json' => json_encode($configs, JSON_UNESCAPED_UNICODE),
        'putaway_rules' => wmsFetchAll('SELECT r.*, w.name AS warehouse_name FROM wms_putaway_rules r INNER JOIN wms_warehouses w ON w.id = r.warehouse_id ORDER BY r.priority DESC, r.id DESC'),
        'warehouses' => wmsFetchAll('SELECT id, code, name FROM wms_warehouses WHERE deleted_at IS NULL ORDER BY name ASC'),
        'locations' => $locations,
        'is_admin' => ($user['role'] ?? '') === 'admin',
    ]));
}

function wmsPageSuppliers(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor', 'viewer');
    echo wmsRender('admin/suppliers.disyl', wmsAdminContext($user, 'suppliers', [
        'page_title' => 'Suppliers',
        'suppliers' => wmsFetchAll('SELECT * FROM wms_suppliers WHERE deleted_at IS NULL ORDER BY name ASC'),
    ]));
}

function wmsPageReturns(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor', 'viewer');
    echo wmsRender('admin/returns.disyl', wmsAdminContext($user, 'returns', [
        'page_title' => 'Returns',
        'returns' => wmsFetchAll(
            'SELECT r.*, w.name AS warehouse_name FROM wms_returns r
             INNER JOIN wms_warehouses w ON w.id = r.warehouse_id
             WHERE r.deleted_at IS NULL ORDER BY r.created_at DESC LIMIT 100'
        ),
        'warehouses' => wmsFetchAll('SELECT id, code, name FROM wms_warehouses WHERE deleted_at IS NULL ORDER BY name ASC'),
        'products' => wmsFetchAll('SELECT id, sku, name FROM wms_products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC LIMIT 200'),
        'locations' => wmsFetchAll('SELECT id, warehouse_id, code, name FROM wms_locations WHERE deleted_at IS NULL AND is_active = 1 ORDER BY code ASC LIMIT 300'),
    ]));
}

function wmsPageUsers(array $params = []): void
{
    $user = wmsRequireAnyRole('admin');
    echo wmsRender('admin/users.disyl', wmsAdminContext($user, 'users', [
        'page_title' => 'Users',
        'current_user_id' => (int)($user['id'] ?? 0),
        'users' => wmsUsersListData(),
    ]));
}

function wmsPageAccount(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor', 'viewer');
    $account = wmsUserAccountRecord((int)($user['id'] ?? 0));

    if ($account === null) {
        throw new RuntimeException('Account not found.');
    }

    echo wmsRender('admin/account.disyl', wmsAdminContext($user, 'account', [
        'page_title' => 'My Account',
        'account' => $account,
    ]));
}


function wmsPageOnboarding(array $params = []): void
{
    $user = wmsRequireAnyRole('admin');
    echo wmsRender('admin/onboarding.disyl', wmsAdminContext($user, 'onboarding', [
        'page_title' => 'WMS Onboarding',
        'warehouse_count' => (int)(wmsDb()->query('SELECT COUNT(*) FROM wms_warehouses WHERE deleted_at IS NULL')->fetchColumn() ?: 0),
        'product_count' => (int)(wmsDb()->query('SELECT COUNT(*) FROM wms_products WHERE deleted_at IS NULL')->fetchColumn() ?: 0),
        'location_count' => (int)(wmsDb()->query('SELECT COUNT(*) FROM wms_locations WHERE deleted_at IS NULL')->fetchColumn() ?: 0),
    ]));
}

function wmsPageDiagnostics(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor');
    $filters = wmsDiagnosticsFilters();
    echo wmsRender('admin/diagnostics.disyl', wmsAdminContext($user, 'diagnostics', [
        'page_title' => 'Diagnostics & Observability',
        'products' => wmsFetchAll('SELECT id, sku, name FROM wms_products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC LIMIT 300'),
        'filters' => $filters,
        'reservations' => wmsDiagnosticsReservationRows($filters),
        'bridge_orders' => wmsDiagnosticsBridgeOrders($filters),
        'trace' => wmsDiagnosticsTraceRows($filters),
    ]));
}

function wmsPageFinancial(array $params = []): void
{
    $user = wmsRequireAnyRole('admin', 'supervisor');
    echo wmsRender('admin/financial.disyl', wmsAdminContext($user, 'financial', [
        'page_title' => 'Financial & POs',
        'warehouses' => wmsFetchAll('SELECT id, code, name FROM wms_warehouses WHERE deleted_at IS NULL ORDER BY name ASC'),
        'suppliers' => wmsFetchAll('SELECT id, code, name FROM wms_suppliers WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC'),
        'products' => wmsFetchAll('SELECT id, sku, name FROM wms_products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC LIMIT 300'),
    ]));
}
