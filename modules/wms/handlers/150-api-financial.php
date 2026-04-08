<?php

declare(strict_types=1);

function wmsApiFinancialValuation(): never
{
    wmsRequireRole(['admin', 'supervisor']);
    
    $productId = (int)wmsInput('product_id', 0);
    $valuation = wmsCalculateInventoryValuation($productId);
    
    wmsJson($valuation);
}

function wmsApiPurchaseOrdersList(): never
{
    wmsRequireRole(['admin', 'supervisor', 'viewer']);
    
    $db = wmsDb();
    
    $pos = $db->fetchAll(
        'SELECT 
            p.*, s.name AS supplier_name, w.name AS warehouse_name
         FROM wms_purchase_orders p
         JOIN wms_suppliers s ON s.id = p.supplier_id
         JOIN wms_warehouses w ON w.id = p.warehouse_id
         ORDER BY p.created_at DESC
         LIMIT 100'
    );
    
    wmsJson(['purchase_orders' => $pos]);
}

function wmsApiPurchaseOrderCreate(): never
{
    $user = wmsRequireStaff(['admin', 'supervisor']);
    
    $data = [
        'supplier_id' => wmsInput('supplier_id'),
        'warehouse_id' => wmsInput('warehouse_id'),
        'expected_delivery_date' => wmsInput('expected_delivery_date'),
        'notes' => wmsInput('notes', ''),
        'items' => wmsInput('items', []),
    ];
    
    try {
        $poId = wmsPurchaseOrderCreate($data, (int)$user['id']);
        wmsJson(['success' => true, 'purchase_order_id' => $poId]);
    } catch (\Throwable $e) {
        wmsJsonError('Failed to create Purchase Order: ' . $e->getMessage(), 400);
    }
}

function wmsApiPurchaseOrderSubmit(): never
{
    $user = wmsRequireStaff(['admin', 'supervisor']);
    
    $parts = explode('/', wmsServer('REQUEST_URI') ?? '');
    $id = (int)$parts[count($parts) - 2];
    
    try {
        $deliveryId = wmsPurchaseOrderSubmit($id, (int)$user['id']);
        wmsJson(['success' => true, 'delivery_id' => $deliveryId, 'message' => 'PO submitted and outbound delivery created.']);
    } catch (\Throwable $e) {
        wmsJsonError('Failed to submit Purchase Order: ' . $e->getMessage(), 400);
    }
}
