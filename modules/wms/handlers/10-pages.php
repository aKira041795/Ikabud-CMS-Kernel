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
