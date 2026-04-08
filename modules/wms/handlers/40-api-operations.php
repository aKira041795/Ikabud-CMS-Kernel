<?php

declare(strict_types=1);

function wmsApiDeliveriesList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $status = wmsSanitizeString(wmsInput('status', ''), 30);
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        $where = ['d.deleted_at IS NULL'];
        $bind = [];
        if ($status !== '') {
            $where[] = 'd.status = ?';
            $bind[] = $status;
        }
        if ($warehouseId > 0) {
            $where[] = 'd.warehouse_id = ?';
            $bind[] = $warehouseId;
        }
        wmsJsonOk(['data' => wmsFetchAll(
            'SELECT d.*, w.name AS warehouse_name FROM wms_deliveries d INNER JOIN wms_warehouses w ON w.id = d.warehouse_id WHERE ' . implode(' AND ', $where) . ' ORDER BY d.created_at DESC',
            $bind
        )]);
    });
}

function wmsApiDeliveryGet(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $id = (int)($params['id'] ?? 0);
        $delivery = wmsFetchOne('SELECT * FROM wms_deliveries WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
        if ($delivery === null) {
            wmsJsonError('Delivery not found.', 404);
        }
        $delivery['items'] = wmsFetchAll(
            'SELECT di.*, p.sku, p.name AS product_name, l.code AS location_code, sl.code AS staging_location_code, b.batch_number,
                    GREATEST(di.qty_received - di.qty_put_away, 0) AS qty_pending_putaway
             FROM wms_delivery_items di
             INNER JOIN wms_products p ON p.id = di.product_id
             INNER JOIN wms_locations l ON l.id = di.location_id
             LEFT JOIN wms_locations sl ON sl.id = di.staging_location_id
             LEFT JOIN wms_batches b ON b.id = di.batch_id
             WHERE di.delivery_id = ? ORDER BY di.id ASC',
            [$id]
        );
        wmsJsonOk(['data' => $delivery]);
    });
}

function wmsApiDeliveryCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        $referenceNumber = wmsSanitizeString(wmsInput('reference_number', ''), 100);
        $items = wmsRequestBodyItems('items');
        if ($warehouseId <= 0 || $referenceNumber === '' || $items === []) {
            wmsJsonError('Warehouse, reference number, and items are required.', 422);
        }

        $db = wmsDb();
        $db->beginTransaction();
        try {
            $deliveryStatus = in_array(($status = wmsSanitizeString(wmsInput('status', 'pending'), 20)), wmsDeliveryStatuses(), true) ? $status : 'pending';
            $db->execute(
                'INSERT INTO wms_deliveries (reference_number, supplier_name, supplier_reference, warehouse_id, status, expected_at, received_at, notes, meta, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, NOW(), NOW())',
                [
                    $referenceNumber,
                    wmsSanitizeString(wmsInput('supplier_name', ''), 255) ?: null,
                    wmsSanitizeString(wmsInput('supplier_reference', ''), 100) ?: null,
                    $warehouseId,
                    $deliveryStatus,
                    wmsSanitizeString(wmsInput('expected_at', ''), 20) ?: null,
                    wmsSanitizeString(wmsInput('notes', ''), 2000) ?: null,
                    ($meta = wmsJsonDecodeArray(wmsInput('meta', []))) !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    (int)($user['id'] ?? 0),
                ]
            );
            $deliveryId = (int)$db->lastInsertId();
            $hasOperationalProgress = false;

            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $locationId = (int)($item['location_id'] ?? 0);
                $qtyExpected = wmsNormalizeDecimal($item['qty_expected'] ?? 0);
                if ($productId <= 0 || $locationId <= 0 || $qtyExpected <= 0) {
                    throw new RuntimeException('Each delivery item requires product, location, and quantity.');
                }

                $putawayLocation = wmsLocationRecord($locationId);
                if ($putawayLocation === null || (int)($putawayLocation['warehouse_id'] ?? 0) !== $warehouseId) {
                    throw new RuntimeException('Putaway location is invalid for the selected warehouse.');
                }
                if ((int)($putawayLocation['is_staging'] ?? 0) === 1) {
                    throw new RuntimeException('Putaway location cannot be a staging location.');
                }

                $stagingLocationId = isset($item['staging_location_id']) && (int)$item['staging_location_id'] > 0 ? (int)$item['staging_location_id'] : null;
                if ($stagingLocationId !== null) {
                    $stagingLocation = wmsLocationRecord($stagingLocationId);
                    if ($stagingLocation === null || (int)($stagingLocation['warehouse_id'] ?? 0) !== $warehouseId) {
                        throw new RuntimeException('Staging location is invalid for the selected warehouse.');
                    }
                    if ((int)($stagingLocation['is_staging'] ?? 0) !== 1) {
                        throw new RuntimeException('Staging location must be marked as staging.');
                    }
                    if ($stagingLocationId === $locationId) {
                        throw new RuntimeException('Staging and putaway locations must differ.');
                    }
                }

                $qtyReceived = isset($item['qty_received']) && $item['qty_received'] !== ''
                    ? wmsNormalizeDecimal($item['qty_received'])
                    : 0.0;
                if ($qtyReceived < 0 || $qtyReceived > $qtyExpected) {
                    throw new RuntimeException('Received quantity must be between zero and the expected quantity.');
                }

                $qtyPutAway = isset($item['qty_put_away']) && $item['qty_put_away'] !== ''
                    ? wmsNormalizeDecimal($item['qty_put_away'])
                    : ($qtyReceived > 0 && $stagingLocationId === null ? $qtyReceived : 0.0);
                if ($qtyPutAway < 0 || $qtyPutAway > $qtyReceived) {
                    throw new RuntimeException('Putaway quantity must be between zero and the received quantity.');
                }

                $db->execute(
                    'INSERT INTO wms_delivery_items (delivery_id, product_id, location_id, staging_location_id, batch_id, qty_expected, qty_received, qty_put_away, unit_cost, meta, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [
                        $deliveryId,
                        $productId,
                        $locationId,
                        $stagingLocationId,
                        isset($item['batch_id']) && (int)$item['batch_id'] > 0 ? (int)$item['batch_id'] : null,
                        $qtyExpected,
                        $qtyReceived,
                        $qtyPutAway,
                        isset($item['unit_cost']) && $item['unit_cost'] !== '' ? wmsNormalizeDecimal($item['unit_cost']) : null,
                        ($meta = wmsJsonDecodeArray($item['meta'] ?? [])) !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    ]
                );

                $hasOperationalProgress = $hasOperationalProgress || $qtyReceived > 0 || $qtyPutAway > 0;
            }

            if ($hasOperationalProgress) {
                $deliveryStatus = wmsDeliveryRefreshStatus($deliveryId);
                if ($deliveryStatus !== 'pending') {
                    $db->execute('UPDATE wms_deliveries SET received_at = COALESCE(received_at, NOW()), updated_at = NOW() WHERE id = ?', [$deliveryId]);
                }
            }

            $db->commit();
            wmsAudit('wms.delivery.created', 'wms_deliveries', (string)$deliveryId, null, ['reference_number' => $referenceNumber]);
            wmsJsonOk(['id' => $deliveryId, 'status' => $deliveryStatus], 201);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    });
}

function wmsApiDeliveryReceive(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        $result = wmsDeliveryReceive((int)($params['id'] ?? 0), (int)($user['id'] ?? 0));
        wmsJsonOk($result);
    });
}

function wmsApiDeliveryCancel(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor');
        $id = (int)($params['id'] ?? 0);
        $existing = wmsFetchOne('SELECT * FROM wms_deliveries WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
        if ($existing === null) {
            wmsJsonError('Delivery not found.', 404);
        }
        wmsDb()->execute('UPDATE wms_deliveries SET status = ?, updated_at = NOW() WHERE id = ?', ['cancelled', $id]);
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiOrdersList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $status = wmsSanitizeString(wmsInput('status', ''), 30);
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        $where = ['o.deleted_at IS NULL'];
        $bind = [];
        if ($status !== '') {
            $where[] = 'o.status = ?';
            $bind[] = $status;
        }
        if ($warehouseId > 0) {
            $where[] = 'o.warehouse_id = ?';
            $bind[] = $warehouseId;
        }
        wmsJsonOk(['data' => wmsFetchAll(
            'SELECT o.*, w.name AS warehouse_name FROM wms_orders o INNER JOIN wms_warehouses w ON w.id = o.warehouse_id WHERE ' . implode(' AND ', $where) . ' ORDER BY o.priority ASC, o.created_at DESC',
            $bind
        )]);
    });
}

function wmsApiOrderGet(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $id = (int)($params['id'] ?? 0);
        $order = wmsFetchOne('SELECT * FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
        if ($order === null) {
            wmsJsonError('Order not found.', 404);
        }
        $order['items'] = wmsFetchAll(
            'SELECT oi.*, p.sku, p.name AS product_name, l.code AS location_code, b.batch_number
             FROM wms_order_items oi
             INNER JOIN wms_products p ON p.id = oi.product_id
             LEFT JOIN wms_locations l ON l.id = oi.location_id
             LEFT JOIN wms_batches b ON b.id = oi.batch_id
             WHERE oi.order_id = ? ORDER BY oi.id ASC',
            [$id]
        );
        wmsJsonOk(['data' => $order]);
    });
}

function wmsApiOrderCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        $data = wmsInput();
        $data['created_by'] = (int)($user['id'] ?? 0);
        $orderId = wmsOrderCreate($data);
        wmsJsonOk(['id' => $orderId]);
    });
}

function wmsApiOrderPickList(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor');
        wmsJsonOk(['data' => wmsOrderGeneratePickList((int)($params['id'] ?? 0))]);
    });
}

function wmsApiOrderPick(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        wmsJsonOk(wmsOrderPick((int)($params['id'] ?? 0), (int)($user['id'] ?? 0)));
    });
}

function wmsApiOrderDispatch(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        wmsOrderDispatch((int)($params['id'] ?? 0), (int)($user['id'] ?? 0));
        wmsJsonOk(['id' => (int)($params['id'] ?? 0)]);
    });
}

function wmsApiOrderCancel(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        $id = (int)($params['id'] ?? 0);
        wmsOrderCancel($id, (int)($user['id'] ?? 0));
        wmsJsonOk(['id' => $id]);
    });
}

function wmsApiTransfersList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        wmsJsonOk(['data' => wmsFetchAll(
            "SELECT * FROM wms_movements WHERE movement_type IN ('transfer_out', 'transfer_in') ORDER BY created_at DESC LIMIT 500"
        )]);
    });
}

function wmsApiTransferCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        $fromLocationId = (int)wmsInput('from_location_id', 0);
        $toLocationId = (int)wmsInput('to_location_id', 0);
        $items = wmsRequestBodyItems('items');
        if ($items === []) {
            wmsJsonError('Transfer items are required.', 422);
        }
        wmsJsonOk(wmsTransferCreate(
            $fromLocationId,
            $toLocationId,
            $items,
            (int)($user['id'] ?? 0),
            wmsSanitizeString(wmsInput('notes', ''), 2000) ?: null
        ));
    });
}

function wmsApiCycleCountsList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        wmsJsonOk(['data' => wmsFetchAll('SELECT * FROM wms_cycle_counts ORDER BY created_at DESC LIMIT 200')]);
    });
}

function wmsApiCycleCountGet(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor', 'viewer');
        $id = (int)($params['id'] ?? 0);
        $count = wmsFetchOne('SELECT * FROM wms_cycle_counts WHERE id = ? LIMIT 1', [$id]);
        if ($count === null) {
            wmsJsonError('Cycle count not found.', 404);
        }
        $count['items'] = wmsFetchAll('SELECT * FROM wms_cycle_count_items WHERE cycle_count_id = ? ORDER BY id ASC', [$id]);
        wmsJsonOk(['data' => $count]);
    });
}

function wmsApiCycleCountCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        if ($warehouseId <= 0) {
            wmsJsonError('Warehouse is required.', 422);
        }
        wmsDb()->execute(
            'INSERT INTO wms_cycle_counts (reference_number, warehouse_id, location_id, status, scheduled_at, completed_at, notes, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, NOW(), NOW())',
            [
                wmsSanitizeString(wmsInput('reference_number', 'CC-' . date('Ymd-His')), 100),
                $warehouseId,
                ($locationId = (int)wmsInput('location_id', 0)) > 0 ? $locationId : null,
                in_array(($status = wmsSanitizeString(wmsInput('status', 'open'), 20)), wmsCycleCountStatuses(), true) ? $status : 'open',
                wmsSanitizeString(wmsInput('scheduled_at', ''), 20) ?: null,
                wmsSanitizeString(wmsInput('notes', ''), 2000) ?: null,
                (int)($user['id'] ?? 0),
            ]
        );
        wmsJsonOk(['id' => (int)wmsDb()->lastInsertId()], 201);
    });
}

function wmsApiCycleCountSnapshot(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor');
        wmsJsonOk(['data' => wmsCycleCountSnapshot((int)($params['id'] ?? 0))]);
    });
}

function wmsApiCycleCountRecordItem(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireAnyRole('admin', 'supervisor');
        $countId = (int)($params['id'] ?? 0);
        $itemId = (int)($params['itemId'] ?? 0);
        $qtyCounted = wmsNormalizeDecimal(wmsInput('qty_counted', 0));
        wmsDb()->execute(
            'UPDATE wms_cycle_count_items SET qty_counted = ?, notes = ?, updated_at = NOW() WHERE id = ? AND cycle_count_id = ?',
            [$qtyCounted, wmsSanitizeString(wmsInput('notes', ''), 1000) ?: null, $itemId, $countId]
        );
        wmsJsonOk(['item_id' => $itemId, 'qty_counted' => $qtyCounted]);
    });
}

function wmsApiCycleCountComplete(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireAnyRole('admin', 'supervisor');
        wmsJsonOk(wmsCycleCountClose((int)($params['id'] ?? 0), (int)($user['id'] ?? 0)));
    });
}
