<?php

declare(strict_types=1);

function wmsPageTasks(array $params = []): void
{
    $user = wmsRequireStaff(['admin', 'supervisor', 'viewer']);
    echo wmsRender('admin/tasks.disyl', wmsAdminContext($user, 'tasks', [
        'page_title' => 'Task Queue',
    ]));
}

function wmsPageDashboard(array $params = []): void
{
    $user = wmsRequireStaff(['admin', 'supervisor', 'viewer']);

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
        $summary['deliveries_pending'] = (int)($db->query("SELECT COUNT(*) FROM wms_deliveries WHERE status IN ('pending', 'partial') AND deleted_at IS NULL")->fetchColumn() ?: 0);
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
    $user = wmsRequireStaff(['admin', 'supervisor']);
    echo wmsRender('admin/receiving.disyl', wmsAdminContext($user, 'receiving', [
        'page_title' => 'Receiving',
        'deliveries' => wmsFetchAll('SELECT * FROM wms_deliveries WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 50'),
        'warehouses' => wmsFetchAll('SELECT id, code, name FROM wms_warehouses WHERE deleted_at IS NULL ORDER BY name ASC'),
        'products' => wmsFetchAll('SELECT id, sku, name FROM wms_products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name ASC LIMIT 200'),
        'locations' => wmsFetchAll('SELECT id, warehouse_id, code, name FROM wms_locations WHERE deleted_at IS NULL AND is_active = 1 ORDER BY code ASC LIMIT 300'),
    ]));
}

function wmsPagePicking(array $params = []): void
{
    $user = wmsRequireStaff(['admin', 'supervisor']);
    echo wmsRender('admin/picking.disyl', wmsAdminContext($user, 'picking', [
        'page_title' => 'Picking',
        'orders' => wmsFetchAll('SELECT * FROM wms_orders WHERE deleted_at IS NULL ORDER BY priority ASC, created_at DESC LIMIT 50'),
    ]));
}

function wmsPageInventory(array $params = []): void
{
    $user = wmsRequireStaff(['admin', 'supervisor', 'viewer']);
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
    $user = wmsRequireStaff(['admin', 'supervisor']);
    echo wmsRender('admin/settings.disyl', wmsAdminContext($user, 'settings', [
        'page_title' => 'WMS Settings',
        'putaway_rules' => wmsFetchAll('SELECT r.*, w.name AS warehouse_name FROM wms_putaway_rules r INNER JOIN wms_warehouses w ON w.id = r.warehouse_id ORDER BY r.priority DESC, r.id DESC'),
        'warehouses' => wmsFetchAll('SELECT id, code, name FROM wms_warehouses WHERE deleted_at IS NULL ORDER BY name ASC'),
    ]));
}

function wmsPageSuppliers(array $params = []): void
{
    $user = wmsRequireStaff(['admin', 'supervisor', 'viewer']);
    echo wmsRender('admin/suppliers.disyl', wmsAdminContext($user, 'suppliers', [
        'page_title' => 'Suppliers',
        'suppliers' => wmsFetchAll('SELECT * FROM wms_suppliers WHERE deleted_at IS NULL ORDER BY name ASC'),
    ]));
}

function wmsPageReturns(array $params = []): void
{
    $user = wmsRequireStaff(['admin', 'supervisor', 'viewer']);
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
    $user = wmsRequireStaff(['admin']);
    echo wmsRender('admin/users.disyl', wmsAdminContext($user, 'users', [
        'page_title' => 'Users',
        'users' => wmsFetchAll('SELECT id, username, email, full_name, role, is_active, created_at FROM wms_users ORDER BY full_name ASC'),
    ]));
}
