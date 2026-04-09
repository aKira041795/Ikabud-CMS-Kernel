<?php

declare(strict_types=1);

function wmsDiagnosticsFilters(): array
{
    return [
        'product_id' => (int)wmsInput('product_id', 0),
        'ecommerce_order_id' => (int)wmsInput('ecommerce_order_id', 0),
        'external_reference' => trim((string)wmsInput('external_reference', '')),
    ];
}

function wmsDiagnosticsResolveEcommerceOrderId(string $externalReference): int
{
    $externalReference = trim($externalReference);
    if ($externalReference === '') {
        return 0;
    }

    $order = wmsFetchOne(
        'SELECT meta FROM wms_orders WHERE external_reference = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
        [$externalReference]
    );
    if ($order === null) {
        return 0;
    }

    $meta = wmsJsonDecodeArray($order['meta'] ?? null);
    return (int)($meta['ecommerce_order_id'] ?? 0);
}

function wmsDiagnosticsBridgeOrders(array $filters = []): array
{
    $rows = wmsFetchAll(
        "SELECT id, order_number, external_reference, customer_name, warehouse_id, status, created_at, updated_at, meta
         FROM wms_orders
         WHERE deleted_at IS NULL AND (external_reference IS NOT NULL AND external_reference <> '')
         ORDER BY created_at DESC
         LIMIT 100"
    );

    $externalReferenceFilter = trim((string)($filters['external_reference'] ?? ''));
    $ecommerceOrderIdFilter = (int)($filters['ecommerce_order_id'] ?? 0);

    $orders = [];
    foreach ($rows as $row) {
        $meta = wmsJsonDecodeArray($row['meta'] ?? null);
        $ecommerceOrderId = (int)($meta['ecommerce_order_id'] ?? 0);
        $externalReference = (string)($row['external_reference'] ?? '');

        if ($externalReferenceFilter !== '' && stripos($externalReference, $externalReferenceFilter) === false) {
            continue;
        }
        if ($ecommerceOrderIdFilter > 0 && $ecommerceOrderId !== $ecommerceOrderIdFilter) {
            continue;
        }

        $reservedQty = 0.0;
        if ($ecommerceOrderId > 0) {
            $reservedQty = (float)(wmsDb()->query(
                'SELECT COALESCE(SUM(qty), 0) FROM wms_movements WHERE reference_type = ? AND reference_id = ? AND movement_type = ?',
                ['order', $ecommerceOrderId, 'reserved']
            )->fetchColumn() ?: 0);
        }

        $orders[] = [
            'id' => (int)($row['id'] ?? 0),
            'order_number' => (string)($row['order_number'] ?? ''),
            'external_reference' => $externalReference,
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'ecommerce_order_id' => $ecommerceOrderId,
            'ecommerce_order_number' => (string)($meta['ecommerce_order_number'] ?? $externalReference),
            'customer_email' => (string)($meta['customer_email'] ?? ''),
            'reserved_qty' => $reservedQty,
        ];
    }

    return $orders;
}

function wmsDiagnosticsReservationRows(array $filters = []): array
{
    $productId = (int)($filters['product_id'] ?? 0);
    $ecommerceOrderId = (int)($filters['ecommerce_order_id'] ?? 0);
    $externalReference = trim((string)($filters['external_reference'] ?? ''));
    if ($ecommerceOrderId <= 0 && $externalReference !== '') {
        $ecommerceOrderId = wmsDiagnosticsResolveEcommerceOrderId($externalReference);
        if ($ecommerceOrderId <= 0) {
            return [];
        }
    }

    $sql = 'SELECT
                m.id,
                m.product_id,
                m.warehouse_id,
                m.location_id,
                m.batch_id,
                m.qty,
                m.reference_id AS ecommerce_order_id,
                m.created_at,
                p.sku,
                p.name AS product_name,
                l.code AS location_code,
                w.name AS warehouse_name,
                b.batch_number
            FROM wms_movements m
            INNER JOIN wms_products p ON p.id = m.product_id
            INNER JOIN wms_locations l ON l.id = m.location_id
            INNER JOIN wms_warehouses w ON w.id = m.warehouse_id
            LEFT JOIN wms_batches b ON b.id = m.batch_id
            WHERE m.reference_type = ? AND m.movement_type = ?';
    $params = ['order', 'reserved'];

    if ($productId > 0) {
        $sql .= ' AND m.product_id = ?';
        $params[] = $productId;
    }
    if ($ecommerceOrderId > 0) {
        $sql .= ' AND m.reference_id = ?';
        $params[] = $ecommerceOrderId;
    }

    $sql .= ' ORDER BY m.created_at DESC, m.id DESC LIMIT 100';
    $rows = wmsFetchAll($sql, $params);

    $reservations = [];
    foreach ($rows as $row) {
        $linkedOrder = wmsBridgeOrderRecordByPayload(['ecommerce_order_id' => (int)($row['ecommerce_order_id'] ?? 0)]);
        $meta = is_array($linkedOrder) ? wmsJsonDecodeArray($linkedOrder['meta'] ?? null) : [];
        $reservations[] = [
            'id' => (int)($row['id'] ?? 0),
            'product_id' => (int)($row['product_id'] ?? 0),
            'sku' => (string)($row['sku'] ?? ''),
            'product_name' => (string)($row['product_name'] ?? ''),
            'warehouse_name' => (string)($row['warehouse_name'] ?? ''),
            'location_code' => (string)($row['location_code'] ?? ''),
            'batch_number' => (string)($row['batch_number'] ?? ''),
            'qty' => (float)($row['qty'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'ecommerce_order_id' => (int)($row['ecommerce_order_id'] ?? 0),
            'wms_order_id' => (int)($linkedOrder['id'] ?? 0),
            'wms_order_number' => (string)($linkedOrder['order_number'] ?? ''),
            'wms_order_status' => (string)($linkedOrder['status'] ?? ''),
            'external_reference' => (string)($linkedOrder['external_reference'] ?? ''),
            'customer_email' => (string)($meta['customer_email'] ?? ''),
        ];
    }

    return $reservations;
}

function wmsDiagnosticsTraceRows(array $filters = []): array
{
    $productId = (int)($filters['product_id'] ?? 0);
    $ecommerceOrderId = (int)($filters['ecommerce_order_id'] ?? 0);
    $externalReference = trim((string)($filters['external_reference'] ?? ''));
    if ($ecommerceOrderId <= 0 && $externalReference !== '') {
        $ecommerceOrderId = wmsDiagnosticsResolveEcommerceOrderId($externalReference);
        if ($ecommerceOrderId <= 0) {
            return [];
        }
    }

    $sql = 'SELECT
                m.id, m.movement_type, m.qty, m.qty_before, m.qty_after,
                m.reference_type, m.reference_id, m.created_at,
                p.sku AS product_sku, p.name AS product_name,
                l.code AS location_code, b.batch_number
            FROM wms_movements m
            INNER JOIN wms_products p ON p.id = m.product_id
            INNER JOIN wms_locations l ON l.id = m.location_id
            LEFT JOIN wms_batches b ON b.id = m.batch_id
            WHERE 1=1';
    $params = [];

    if ($productId > 0) {
        $sql .= ' AND m.product_id = ?';
        $params[] = $productId;
    }
    if ($ecommerceOrderId > 0) {
        $sql .= ' AND m.reference_type = ? AND m.reference_id = ?';
        $params[] = 'order';
        $params[] = $ecommerceOrderId;
    }

    $sql .= ' ORDER BY m.created_at DESC, m.id DESC LIMIT 100';
    return wmsFetchAll($sql, $params);
}

function wmsApiDiagnosticsTrace(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor');

        $filters = wmsDiagnosticsFilters();
        if ((int)$filters['product_id'] <= 0 && (int)$filters['ecommerce_order_id'] <= 0 && (string)$filters['external_reference'] === '') {
            wmsJsonError('product_id, ecommerce_order_id, or external_reference is required');
        }

        $trace = wmsDiagnosticsTraceRows($filters);

        wmsJsonOk(['trace' => $trace]);
    });
}

function wmsApiDiagnosticsReservations(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor');

        $reservations = wmsDiagnosticsReservationRows(wmsDiagnosticsFilters());

        wmsJsonOk(['reservations' => $reservations]);
    });
}
