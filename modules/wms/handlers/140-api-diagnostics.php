<?php

declare(strict_types=1);

function wmsApiDiagnosticsTrace(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor']);

        $productId = (int)wmsInput('product_id', 0);
        if ($productId <= 0) {
            wmsJsonError('product_id is required');
        }

        $trace = wmsFetchAll(
            'SELECT 
                m.id, m.movement_type, m.qty, m.qty_before, m.qty_after, 
                m.reference_type, m.reference_id, m.created_at,
                p.sku AS product_sku, l.code AS location_code, b.batch_number
             FROM wms_movements m
             JOIN wms_products p ON p.id = m.product_id
             JOIN wms_locations l ON l.id = m.location_id
             LEFT JOIN wms_batches b ON b.id = m.batch_id
             WHERE m.product_id = ?
             ORDER BY m.created_at DESC
             LIMIT 100',
            [$productId]
        );

        wmsJsonOk(['trace' => $trace]);
    });
}

function wmsApiDiagnosticsReservations(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor']);

        $reservations = wmsFetchAll(
            'SELECT 
                s.id as stock_id, s.product_id, s.location_id, s.qty_reserved,
                p.sku, p.name AS product_name,
                l.code AS location_code,
                w.name AS warehouse_name
             FROM wms_stocks s
             JOIN wms_products p ON p.id = s.product_id
             JOIN wms_locations l ON l.id = s.location_id
             JOIN wms_warehouses w ON w.id = s.warehouse_id
             WHERE s.qty_reserved > 0
             ORDER BY s.qty_reserved DESC
             LIMIT 100'
        );

        wmsJsonOk(['reservations' => $reservations]);
    });
}
