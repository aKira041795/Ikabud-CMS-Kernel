<?php

declare(strict_types=1);

function wmsApiFinancialValuation(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor']);

        $productId = (int)wmsInput('product_id', 0);
        $valuation = wmsCalculateInventoryValuation($productId);

        wmsJsonOk($valuation);
    });
}

function wmsApiPurchaseOrdersList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor', 'viewer']);

        $pos = wmsFetchAll(
            'SELECT 
                p.*, s.name AS supplier_name, w.name AS warehouse_name
             FROM wms_purchase_orders p
             JOIN wms_suppliers s ON s.id = p.supplier_id
             JOIN wms_warehouses w ON w.id = p.warehouse_id
             WHERE p.deleted_at IS NULL
             ORDER BY p.created_at DESC
             LIMIT 100'
        );

        wmsJsonOk(['purchase_orders' => $pos]);
    });
}

function wmsApiPurchaseOrderCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);

        $data = [
            'supplier_id' => wmsInput('supplier_id'),
            'warehouse_id' => wmsInput('warehouse_id'),
            'expected_delivery_date' => wmsInput('expected_delivery_date'),
            'notes' => wmsInput('notes', ''),
            'items' => wmsRequestBodyItems('items'),
        ];

        $poId = wmsPurchaseOrderCreate($data, (int)$user['id']);
        wmsJsonOk(['success' => true, 'purchase_order_id' => $poId]);
    });
}

function wmsApiPurchaseOrderSubmit(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            wmsJsonError('Purchase order ID is required.', 422);
        }

        $deliveryId = wmsPurchaseOrderSubmit($id, (int)$user['id']);
        wmsJsonOk(['success' => true, 'delivery_id' => $deliveryId, 'message' => 'PO submitted and inbound delivery created.']);
    });
}
