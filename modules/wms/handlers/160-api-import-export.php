<?php

declare(strict_types=1);

function wmsApiExportProductsCsv(array $params = []): void
{
    wmsRequireStaff(['admin', 'supervisor', 'viewer']);
    wmsCsvResponse('wms_products.csv', ['sku', 'barcode', 'name', 'description', 'unit', 'product_type', 'weight', 'reorder_point', 'safety_stock', 'is_batch_tracked', 'is_active'], wmsExportProductsRows());
}

function wmsApiExportStockCsv(array $params = []): void
{
    wmsRequireStaff(['admin', 'supervisor', 'viewer']);
    wmsCsvResponse('wms_stock_snapshot.csv', ['sku', 'product_name', 'warehouse_code', 'warehouse_name', 'location_code', 'batch_number', 'qty_on_hand', 'qty_reserved', 'qty_available'], wmsExportStockRows());
}

function wmsApiExportSuppliersCsv(array $params = []): void
{
    wmsRequireStaff(['admin', 'supervisor', 'viewer']);
    wmsCsvResponse('wms_suppliers.csv', ['code', 'name', 'contact_person', 'email', 'phone', 'address', 'lead_time_days', 'is_active'], wmsExportSuppliersRows());
}

function wmsApiImportProductsCsv(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);
        $result = wmsImportProductsFromCsv((string)wmsInput('csv_content', ''), (int)$user['id']);
        wmsJsonOk($result);
    });
}

function wmsApiImportStockCsv(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);
        $result = wmsImportStockFromCsv((string)wmsInput('csv_content', ''), (int)$user['id']);
        wmsJsonOk($result);
    });
}

function wmsApiImportSuppliersCsv(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);
        $result = wmsImportSuppliersFromCsv((string)wmsInput('csv_content', ''), (int)$user['id']);
        wmsJsonOk($result);
    });
}