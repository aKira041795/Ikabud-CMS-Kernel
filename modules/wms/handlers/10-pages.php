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
