<?php

declare(strict_types=1);

function wmsPageInventory(): void
{
    $user = wmsCurrentUser();
    $settings = wmsSettings();

    $products = wmsDb()->query('SELECT id, sku, name, unit, product_type, is_batch_tracked, reorder_point, safety_stock, is_active FROM wms_products ORDER BY name ASC LIMIT 500')->fetchAll(\PDO::FETCH_ASSOC);
    $warehouses = wmsDb()->query('SELECT id, code, name FROM wms_warehouses WHERE is_active = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('inventory', [
        'products' => $products,
        'warehouses' => $warehouses,
        'page_title' => 'Inventory — WMS',
    ]);
}

function wmsPageSuppliers(): void
{
    $user = wmsCurrentUser();
    $suppliers = wmsDb()->query('SELECT * FROM wms_suppliers WHERE is_active = 1 ORDER BY name ASC LIMIT 200')->fetchAll(\PDO::FETCH_ASSOC);
    wmsRenderTemplate('suppliers', [
        'suppliers' => $suppliers,
        'page_title' => 'Suppliers — WMS',
    ]);
}

function wmsPageStock(): void
{
    $user = wmsCurrentUser();
    $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
    $lowStock = ($_GET['low_stock'] ?? '') === '1';

    $sql = 'SELECT s.*, p.sku, p.name AS product_name, p.unit, p.reorder_point, p.safety_stock,
                   w.code AS warehouse_code, w.name AS warehouse_name,
                   l.code AS location_code,
                   b.batch_number
            FROM wms_stock s
            JOIN wms_products p ON p.id = s.product_id
            JOIN wms_warehouses w ON w.id = s.warehouse_id
            LEFT JOIN wms_locations l ON l.id = s.location_id
            LEFT JOIN wms_batches b ON b.id = s.batch_id
            WHERE 1=1';
    $params = [];
    if ($warehouseId) { $sql .= ' AND s.warehouse_id = :wid'; $params[':wid'] = $warehouseId; }
    if ($lowStock) {
        $sql .= ' AND s.qty_on_hand <= COALESCE(p.reorder_point, 0) AND s.qty_on_hand > 0';
    }
    $sql .= ' ORDER BY p.name ASC LIMIT 500';

    $stock = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    $warehouses = wmsDb()->query('SELECT id, code, name FROM wms_warehouses WHERE is_active = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC);
    $products = wmsDb()->query('SELECT id, sku, name FROM wms_products WHERE is_active = 1 ORDER BY name ASC LIMIT 500')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('stock', [
        'stock' => $stock,
        'warehouses' => $warehouses,
        'products' => $products,
        'page_title' => 'Stock Levels — WMS',
    ]);
}

function wmsPageMovements(): void
{
    $user = wmsCurrentUser();
    $type = trim((string)($_GET['type'] ?? ''));
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));

    $sql = 'SELECT m.*, p.sku, p.name AS product_name, p.unit,
                   w.code AS warehouse_code,
                   fl.code AS from_location_code, tl.code AS to_location_code,
                   b.batch_number,
                   u.full_name AS created_by_name
            FROM wms_stock_movements m
            JOIN wms_products p ON p.id = m.product_id
            JOIN wms_warehouses w ON w.id = m.warehouse_id
            LEFT JOIN wms_locations fl ON fl.id = m.from_location_id
            LEFT JOIN wms_locations tl ON tl.id = m.to_location_id
            LEFT JOIN wms_batches b ON b.id = m.batch_id
            LEFT JOIN wms_users u ON u.id = m.created_by
            WHERE 1=1';
    $params = [];
    if ($type !== '') { $sql .= ' AND m.movement_type = :type'; $params[':type'] = $type; }
    $sql .= ' ORDER BY m.created_at DESC LIMIT ' . $limit;

    $movements = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('movements', [
        'movements' => $movements,
        'page_title' => 'Movement History — WMS',
    ]);
}

function wmsPageDeliveries(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));

    $sql = 'SELECT d.*, w.code AS warehouse_code, w.name AS warehouse_name,
                   s.name AS supplier_name, u.full_name AS created_by_name
            FROM wms_deliveries d
            JOIN wms_warehouses w ON w.id = d.warehouse_id
            LEFT JOIN wms_suppliers s ON s.id = d.supplier_id
            LEFT JOIN wms_users u ON u.id = d.created_by
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND d.status = :status'; $params[':status'] = $status; }
    $sql .= ' ORDER BY d.created_at DESC LIMIT 100';

    $deliveries = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    $warehouses = wmsDb()->query('SELECT id, code, name FROM wms_warehouses WHERE is_active = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC);
    $suppliers = wmsDb()->query('SELECT id, code, name FROM wms_suppliers WHERE is_active = 1 ORDER BY name ASC LIMIT 200')->fetchAll(\PDO::FETCH_ASSOC);
    $products = wmsDb()->query('SELECT id, sku, name FROM wms_products WHERE is_active = 1 ORDER BY name ASC LIMIT 500')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('deliveries', [
        'deliveries' => $deliveries,
        'warehouses' => $warehouses,
        'suppliers' => $suppliers,
        'products' => $products,
        'page_title' => 'Deliveries — WMS',
    ]);
}

function wmsPageOrders(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));

    $sql = 'SELECT o.*, w.code AS warehouse_code, u.full_name AS created_by_name
            FROM wms_orders o
            JOIN wms_warehouses w ON w.id = o.warehouse_id
            LEFT JOIN wms_users u ON u.id = o.created_by
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND o.status = :status'; $params[':status'] = $status; }
    if ($type !== '') { $sql .= ' AND o.order_type = :type'; $params[':type'] = $type; }
    $sql .= ' ORDER BY o.created_at DESC LIMIT 100';

    $orders = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    $warehouses = wmsDb()->query('SELECT id, code, name FROM wms_warehouses WHERE is_active = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC);
    $products = wmsDb()->query('SELECT id, sku, name FROM wms_products WHERE is_active = 1 ORDER BY name ASC LIMIT 500')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('orders', [
        'orders' => $orders,
        'warehouses' => $warehouses,
        'products' => $products,
        'page_title' => 'Orders — WMS',
    ]);
}

function wmsPageCycleCounts(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));

    $sql = 'SELECT cc.*, w.code AS warehouse_code, a.full_name AS assigned_name
            FROM wms_cycle_counts cc
            JOIN wms_warehouses w ON w.id = cc.warehouse_id
            LEFT JOIN wms_users a ON a.id = cc.assigned_to
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND cc.status = :status'; $params[':status'] = $status; }
    $sql .= ' ORDER BY cc.created_at DESC LIMIT 100';

    $cycleCounts = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    $warehouses = wmsDb()->query('SELECT id, code, name FROM wms_warehouses WHERE is_active = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC);
    $products = wmsDb()->query('SELECT id, sku, name FROM wms_products WHERE is_active = 1 ORDER BY name ASC LIMIT 500')->fetchAll(\PDO::FETCH_ASSOC);
    $users = wmsDb()->query('SELECT id, full_name, role FROM wms_users WHERE is_active = 1 ORDER BY full_name ASC')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('cycle-counts', [
        'cycle_counts' => $cycleCounts,
        'warehouses' => $warehouses,
        'products' => $products,
        'users' => $users,
        'page_title' => 'Cycle Counts — WMS',
    ]);
}

function wmsPageReturns(): void
{
    $user = wmsCurrentUser();

    $sql = 'SELECT r.*, w.code AS warehouse_code, u.full_name AS created_name
            FROM wms_returns r
            JOIN wms_warehouses w ON w.id = r.warehouse_id
            LEFT JOIN wms_users u ON u.id = r.created_by
            ORDER BY r.created_at DESC LIMIT 100';

    $returns = wmsDb()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    $warehouses = wmsDb()->query('SELECT id, code, name FROM wms_warehouses WHERE is_active = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC);
    $products = wmsDb()->query('SELECT id, sku, name FROM wms_products WHERE is_active = 1 ORDER BY name ASC LIMIT 500')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('returns', [
        'returns' => $returns,
        'warehouses' => $warehouses,
        'products' => $products,
        'page_title' => 'Returns — WMS',
    ]);
}

function wmsPageTasks(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));

    $sql = 'SELECT t.*, w.code AS warehouse_code, a.full_name AS assigned_name
            FROM wms_tasks t
            JOIN wms_warehouses w ON w.id = t.warehouse_id
            LEFT JOIN wms_users a ON a.id = t.assigned_to
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND t.status = :status'; $params[':status'] = $status; }
    $sql .= ' ORDER BY t.priority ASC, t.created_at DESC LIMIT 100';

    $tasks = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    $warehouses = wmsDb()->query('SELECT id, code, name FROM wms_warehouses WHERE is_active = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC);
    $users = wmsDb()->query('SELECT id, full_name, role FROM wms_users WHERE is_active = 1 ORDER BY full_name ASC')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('tasks', [
        'tasks' => $tasks,
        'warehouses' => $warehouses,
        'users' => $users,
        'page_title' => 'Tasks — WMS',
    ]);
}

function wmsPageRecipes(): void
{
    $user = wmsCurrentUser();
    $recipes = wmsDb()->query(
        'SELECT r.*, p.sku, p.name AS product_name, p.unit, u.full_name AS created_name
         FROM wms_recipes r
         JOIN wms_products p ON p.id = r.product_id
         LEFT JOIN wms_users u ON u.id = r.created_by
         ORDER BY r.name ASC LIMIT 200'
    )->fetchAll(\PDO::FETCH_ASSOC);

    $products = wmsDb()->query('SELECT id, sku, name, unit FROM wms_products WHERE is_active = 1 ORDER BY name ASC LIMIT 500')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('recipes', [
        'recipes' => $recipes,
        'products' => $products,
        'page_title' => 'Recipes — WMS',
    ]);
}

function wmsPageProduction(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));

    $sql = 'SELECT po.*, r.recipe_code, r.name AS recipe_name, p.sku, p.name AS product_name,
                   w.code AS warehouse_code
            FROM wms_production_orders po
            JOIN wms_recipes r ON r.id = po.recipe_id
            JOIN wms_products p ON p.id = r.product_id
            JOIN wms_warehouses w ON w.id = po.warehouse_id
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND po.status = :status'; $params[':status'] = $status; }
    $sql .= ' ORDER BY po.created_at DESC LIMIT 100';

    $productionOrders = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    $recipes = wmsDb()->query('SELECT id, recipe_code, name FROM wms_recipes WHERE is_active = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC);
    $warehouses = wmsDb()->query('SELECT id, code, name FROM wms_warehouses WHERE is_active = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC);
    $products = wmsDb()->query('SELECT id, sku, name, unit FROM wms_products WHERE is_active = 1 ORDER BY name ASC LIMIT 500')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('production', [
        'production_orders' => $productionOrders,
        'recipes' => $recipes,
        'warehouses' => $warehouses,
        'products' => $products,
        'page_title' => 'Production — WMS',
    ]);
}

function wmsPageReceiving(): void
{
    $user = wmsCurrentUser();
    $deliveries = wmsDb()->query(
        'SELECT d.*, w.code AS warehouse_code, s.name AS supplier_name
         FROM wms_deliveries d
         JOIN wms_warehouses w ON w.id = d.warehouse_id
         LEFT JOIN wms_suppliers s ON s.id = d.supplier_id
         WHERE d.status IN (\'expected\', \'in_transit\', \'partially_received\')
         ORDER BY d.expected_at ASC, d.created_at DESC LIMIT 100'
    )->fetchAll(\PDO::FETCH_ASSOC);

    $warehouses = wmsDb()->query('SELECT id, code, name FROM wms_warehouses WHERE is_active = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC);
    $suppliers = wmsDb()->query('SELECT id, code, name FROM wms_suppliers WHERE is_active = 1 ORDER BY name ASC LIMIT 200')->fetchAll(\PDO::FETCH_ASSOC);
    $products = wmsDb()->query('SELECT id, sku, name FROM wms_products WHERE is_active = 1 ORDER BY name ASC LIMIT 500')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('receiving', [
        'deliveries' => $deliveries,
        'warehouses' => $warehouses,
        'suppliers' => $suppliers,
        'products' => $products,
        'page_title' => 'Receiving — WMS',
    ]);
}

function wmsPagePicking(): void
{
    $user = wmsCurrentUser();
    $picklists = wmsDb()->query(
        'SELECT p.*, w.code AS warehouse_code, a.full_name AS assigned_name
         FROM wms_picklists p
         JOIN wms_warehouses w ON w.id = p.warehouse_id
         LEFT JOIN wms_users a ON a.id = p.assigned_to
         WHERE p.status IN (\'open\', \'in_progress\')
         ORDER BY p.created_at DESC LIMIT 100'
    )->fetchAll(\PDO::FETCH_ASSOC);

    $orders = wmsDb()->query(
        "SELECT id, order_number, customer_name FROM wms_orders WHERE status = 'pending' ORDER BY created_at ASC LIMIT 200"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $warehouses = wmsDb()->query('SELECT id, code, name FROM wms_warehouses WHERE is_active = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC);
    $users = wmsDb()->query('SELECT id, full_name, role FROM wms_users WHERE is_active = 1 ORDER BY full_name ASC')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('picking', [
        'picklists' => $picklists,
        'orders' => $orders,
        'warehouses' => $warehouses,
        'users' => $users,
        'page_title' => 'Picking — WMS',
    ]);
}

function wmsPageUsers(): void
{
    $user = wmsCurrentUser(['admin']);
    $users = wmsDb()->query('SELECT id, username, email, full_name, role, is_active, created_at FROM wms_users ORDER BY full_name ASC LIMIT 200')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('users', [
        'users' => $users,
        'page_title' => 'Users — WMS',
    ]);
}

function wmsPageSettings(): void
{
    $user = wmsCurrentUser(['admin']);
    $settings = wmsSettings();

    wmsRenderTemplate('settings', [
        'settings' => $settings,
        'page_title' => 'Settings — WMS',
    ]);
}

function wmsPageScanner(): void
{
    $user = wmsCurrentUser();
    $products = wmsDb()->query('SELECT id, sku, name, barcode, unit FROM wms_products WHERE is_active = 1 ORDER BY name ASC LIMIT 500')->fetchAll(\PDO::FETCH_ASSOC);

    wmsRenderTemplate('scanner', [
        'products' => $products,
        'page_title' => 'Scanner — WMS',
    ]);
}
