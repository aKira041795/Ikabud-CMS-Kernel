<?php

function wmsProductExists(int $productId): bool
{
    return (int)(wmsDb()->query('SELECT id FROM wms_products WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$productId])->fetchColumn() ?: 0) > 0;
}

function wmsWarehouseExists(int $warehouseId): bool
{
    return (int)(wmsDb()->query('SELECT id FROM wms_warehouses WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$warehouseId])->fetchColumn() ?: 0) > 0;
}

function wmsLocationExists(int $locationId): bool
{
    return (int)(wmsDb()->query('SELECT id FROM wms_locations WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$locationId])->fetchColumn() ?: 0) > 0;
}

function wmsResolveReceivingStagingLocation(int $warehouseId): array
{
    $warehouseId = wmsRequirePositiveId($warehouseId, 'Warehouse ID');
    $warehouse = wmsFetchOne('SELECT id, code, name FROM wms_warehouses WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$warehouseId]);
    if ($warehouse === null) {
        throw new RuntimeException('Warehouse not found.');
    }

    $existing = wmsFetchOne(
        'SELECT * FROM wms_locations WHERE warehouse_id = ? AND deleted_at IS NULL AND COALESCE(is_staging, 0) = 1 ORDER BY is_active DESC, sort_order ASC, id ASC LIMIT 1',
        [$warehouseId]
    );
    if ($existing !== null) {
        return $existing;
    }

    $baseCode = 'RCV-STAGE';
    $code = $baseCode;
    $suffix = 1;
    while (wmsFetchOne('SELECT id FROM wms_locations WHERE warehouse_id = ? AND code = ? LIMIT 1', [$warehouseId, $code]) !== null) {
        $suffix++;
        $code = $baseCode . '-' . $suffix;
    }

    wmsDb()->execute(
        'INSERT INTO wms_locations (warehouse_id, parent_id, code, name, type, capacity, capacity_unit, sort_order, is_active, is_staging, created_at, updated_at)
         VALUES (?, NULL, ?, ?, ?, NULL, NULL, ?, 1, 1, NOW(), NOW())',
        [$warehouseId, $code, 'Inbound Staging', 'bin', 9999]
    );

    $location = wmsLocationRecord((int)wmsDb()->lastInsertId());
    if ($location === null) {
        throw new RuntimeException('Failed to create inbound staging location.');
    }

    return $location;
}

function wmsResolveQuarantineLocation(int $warehouseId): array
{
    $warehouseId = wmsRequirePositiveId($warehouseId, 'Warehouse ID');
    $warehouse = wmsFetchOne('SELECT * FROM wms_warehouses WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$warehouseId]);
    if ($warehouse === null) {
        throw new RuntimeException('Warehouse not found.');
    }

    $candidateIds = [];
    $warehouseQuarantineId = (int)($warehouse['quarantine_location_id'] ?? 0);
    if ($warehouseQuarantineId > 0) {
        $candidateIds[] = $warehouseQuarantineId;
    }

    $defaultQuarantineId = (int)wmsConfigGet('returns.default_quarantine_location_id', 0);
    if ($defaultQuarantineId > 0 && !in_array($defaultQuarantineId, $candidateIds, true)) {
        $candidateIds[] = $defaultQuarantineId;
    }

    foreach ($candidateIds as $locationId) {
        $candidate = wmsLocationRecord($locationId);
        if ($candidate === null) {
            continue;
        }
        if ((int)($candidate['warehouse_id'] ?? 0) !== $warehouseId || (int)($candidate['is_active'] ?? 0) !== 1) {
            continue;
        }

        if ((int)($warehouse['quarantine_location_id'] ?? 0) !== (int)$candidate['id']) {
            wmsDb()->execute(
                'UPDATE wms_warehouses SET quarantine_location_id = ?, updated_at = NOW() WHERE id = ?',
                [(int)$candidate['id'], $warehouseId]
            );
        }

        return $candidate;
    }

    $existing = wmsFetchOne(
        'SELECT * FROM wms_locations
         WHERE warehouse_id = ?
           AND deleted_at IS NULL
           AND is_active = 1
                     AND (UPPER(code) = ? OR UPPER(name) = ? OR UPPER(type) = ?)
         ORDER BY sort_order ASC, id ASC
         LIMIT 1',
                [$warehouseId, 'QUARANTINE', 'QUARANTINE HOLD', 'QUARANTINE']
    );
    if ($existing !== null) {
        wmsDb()->execute(
            'UPDATE wms_warehouses SET quarantine_location_id = ?, updated_at = NOW() WHERE id = ?',
            [(int)$existing['id'], $warehouseId]
        );

        return $existing;
    }

    $baseCode = 'QUARANTINE';
    $code = $baseCode;
    $suffix = 2;
    while (wmsFetchOne('SELECT id FROM wms_locations WHERE warehouse_id = ? AND code = ? LIMIT 1', [$warehouseId, $code]) !== null) {
        $code = $baseCode . '-' . $suffix;
        $suffix++;
    }

    wmsDb()->execute(
        'INSERT INTO wms_locations (warehouse_id, parent_id, code, name, type, capacity, capacity_unit, sort_order, is_active, created_at, updated_at)
         VALUES (?, NULL, ?, ?, ?, NULL, NULL, ?, 1, NOW(), NOW())',
        [$warehouseId, $code, 'Quarantine Hold', 'quarantine', 9998]
    );

    $location = wmsLocationRecord((int)wmsDb()->lastInsertId());
    if ($location === null) {
        throw new RuntimeException('Failed to create quarantine location.');
    }

    wmsDb()->execute(
        'UPDATE wms_warehouses SET quarantine_location_id = ?, updated_at = NOW() WHERE id = ?',
        [(int)$location['id'], $warehouseId]
    );

    return $location;
}

function wmsDeliveryItemRemainingPutAwayQty(array $item): float
{
    return max(0.0, wmsNormalizeDecimal(($item['qty_received'] ?? 0) - ($item['qty_put_away'] ?? 0)));
}

function wmsDeliveryRefreshStatus(int $deliveryId): string
{
    $delivery = wmsFetchOne('SELECT id, status FROM wms_deliveries WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$deliveryId]);
    if ($delivery === null) {
        throw new RuntimeException('Delivery not found.');
    }

    if ((string)($delivery['status'] ?? '') === 'cancelled') {
        return 'cancelled';
    }

    $totals = wmsFetchOne(
        'SELECT
            COALESCE(SUM(qty_expected), 0) AS qty_expected_total,
            COALESCE(SUM(qty_received), 0) AS qty_received_total,
            COALESCE(SUM(qty_put_away), 0) AS qty_put_away_total
         FROM wms_delivery_items
         WHERE delivery_id = ?',
        [$deliveryId]
    ) ?? [];

    $qtyExpected = wmsNormalizeDecimal($totals['qty_expected_total'] ?? 0);
    $qtyReceived = wmsNormalizeDecimal($totals['qty_received_total'] ?? 0);
    $qtyPutAway = wmsNormalizeDecimal($totals['qty_put_away_total'] ?? 0);

    if ($qtyReceived <= 0) {
        $status = 'pending';
    } elseif ($qtyReceived < $qtyExpected) {
        $status = 'partial';
    } elseif ($qtyPutAway < $qtyReceived) {
        $status = 'staged';
    } else {
        $status = 'received';
    }

    wmsDb()->execute('UPDATE wms_deliveries SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $deliveryId]);

    return $status;
}

function wmsDeliveryEnsurePutawayTasks(int $deliveryId): array
{
    $delivery = wmsFetchOne(
        'SELECT id, reference_number, warehouse_id, status FROM wms_deliveries WHERE id = ? AND deleted_at IS NULL LIMIT 1',
        [$deliveryId]
    );
    if ($delivery === null) {
        throw new RuntimeException('Delivery not found.');
    }

    if ((string)($delivery['status'] ?? '') === 'cancelled') {
        return [];
    }

    $items = wmsFetchAll(
        'SELECT
            di.id,
            di.product_id,
            di.location_id,
            di.staging_location_id,
            di.batch_id,
            di.qty_received,
            di.qty_put_away,
            p.sku,
            p.name AS product_name,
            l.code AS putaway_location_code,
            sl.code AS staging_location_code
         FROM wms_delivery_items di
         INNER JOIN wms_products p ON p.id = di.product_id
         INNER JOIN wms_locations l ON l.id = di.location_id
         LEFT JOIN wms_locations sl ON sl.id = di.staging_location_id
         WHERE di.delivery_id = ?
         ORDER BY di.id ASC',
        [$deliveryId]
    );

    $taskIds = [];
    foreach ($items as $item) {
        $qtyRemaining = wmsDeliveryItemRemainingPutAwayQty($item);
        if ($qtyRemaining <= 0 || (int)($item['staging_location_id'] ?? 0) <= 0) {
            continue;
        }

        $existingTask = wmsFetchOne(
            'SELECT id FROM wms_tasks WHERE task_type = ? AND reference_type = ? AND reference_id = ? AND status IN (?, ?) LIMIT 1',
            ['putaway', 'delivery_item', (int)$item['id'], 'pending', 'in_progress']
        );
        if ($existingTask !== null) {
            $taskIds[] = (int)$existingTask['id'];
            continue;
        }

        $taskIds[] = wmsTaskCreate([
            'warehouse_id' => (int)$delivery['warehouse_id'],
            'task_type' => 'putaway',
            'priority' => 20,
            'reference_type' => 'delivery_item',
            'reference_id' => (int)$item['id'],
            'notes' => 'Put away ' . $qtyRemaining . ' of ' . ((string)($item['sku'] ?? '') !== '' ? (string)$item['sku'] : ('product #' . (int)$item['product_id']))
                . ' from ' . ((string)($item['staging_location_code'] ?? '') !== '' ? (string)$item['staging_location_code'] : 'staging')
                . ' to ' . ((string)($item['putaway_location_code'] ?? '') !== '' ? (string)$item['putaway_location_code'] : ('location #' . (int)$item['location_id']))
                . ' for delivery ' . (string)($delivery['reference_number'] ?? ('#' . $deliveryId)),
        ]);
    }

    return array_values(array_unique(array_filter($taskIds)));
}

function wmsOrderPickCandidate(int $productId, int $warehouseId, bool $isBatchTracked, string $strategy): ?array
{
    $query = 'SELECT s.location_id, s.batch_id, s.qty_available, l.code AS location_code, b.batch_number, b.expires_at
              FROM wms_stocks s
              INNER JOIN wms_locations l ON l.id = s.location_id
              LEFT JOIN wms_batches b ON b.id = s.batch_id
              WHERE s.product_id = ? AND s.warehouse_id = ? AND s.qty_available > 0 AND l.deleted_at IS NULL AND l.is_active = 1 AND COALESCE(l.is_staging, 0) = 0';

    if ($isBatchTracked) {
        $query .= ' AND s.batch_id IS NOT NULL';
    }

    $query .= ' ORDER BY ';
    if ($strategy === 'fifo') {
        $query .= 's.updated_at ASC, s.id ASC';
    } elseif ($strategy === 'lifo') {
        $query .= 's.updated_at DESC, s.id DESC';
    } else {
        $query .= 'CASE WHEN b.expires_at IS NULL THEN 1 ELSE 0 END ASC, b.expires_at ASC, s.updated_at ASC';
    }

    $query .= ' LIMIT 1';

    return wmsFetchOne($query, [$productId, $warehouseId]);
}

function wmsBatchTrackedStockHasUnbatchedAvailability(int $productId, int $warehouseId): bool
{
    $count = (int)(wmsDb()->query(
        'SELECT COUNT(*)
         FROM wms_stocks s
         INNER JOIN wms_locations l ON l.id = s.location_id
         WHERE s.product_id = ?
           AND s.warehouse_id = ?
           AND s.qty_available > 0
           AND (s.batch_id IS NULL OR s.batch_id = 0)
           AND l.deleted_at IS NULL
           AND l.is_active = 1
           AND COALESCE(l.is_staging, 0) = 0',
        [$productId, $warehouseId]
    )->fetchColumn() ?: 0);

    return $count > 0;
}

function wmsOrderCreate(array $data): int
{
    $orderNumber = wmsSanitizeString($data['order_number'] ?? '', 100);
    $warehouseId = (int)($data['warehouse_id'] ?? 0);
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    
    if ($orderNumber === '' || $warehouseId <= 0 || $items === []) {
        throw new RuntimeException('Order number, warehouse, and items are required.');
    }

    $db = wmsDb();
    $db->beginTransaction();
    try {
        $db->execute(
            'INSERT INTO wms_orders (order_number, external_reference, customer_name, warehouse_id, status, priority, ordered_at, dispatched_at, notes, meta, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, NOW(), NOW())',
            [
                $orderNumber,
                wmsSanitizeString($data['external_reference'] ?? '', 100) ?: null,
                wmsSanitizeString($data['customer_name'] ?? '', 255) ?: null,
                $warehouseId,
                in_array(($status = wmsSanitizeString($data['status'] ?? 'pending', 20)), wmsOrderStatuses(), true) ? $status : 'pending',
                (int)($data['priority'] ?? 100),
                wmsSanitizeString($data['ordered_at'] ?? '', 20) ?: date('Y-m-d H:i:s'),
                wmsSanitizeString($data['notes'] ?? '', 2000) ?: null,
                ($meta = (is_array($data['meta'] ?? null) ? $data['meta'] : [])) !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                (int)($data['created_by'] ?? 0),
            ]
        );
        $orderId = (int)$db->lastInsertId();

        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $qtyOrdered = wmsNormalizeDecimal($item['qty_ordered'] ?? 0);
            if ($productId <= 0 || $qtyOrdered <= 0) {
                throw new RuntimeException('Each order item requires product and quantity.');
            }
            $db->execute(
                'INSERT INTO wms_order_items (order_id, product_id, location_id, batch_id, qty_ordered, qty_reserved, qty_picked, notes, meta, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $orderId,
                    $productId,
                    ($locationId = (int)($item['location_id'] ?? 0)) > 0 ? $locationId : null,
                    isset($item['batch_id']) && (int)$item['batch_id'] > 0 ? (int)$item['batch_id'] : null,
                    $qtyOrdered,
                    wmsNormalizeDecimal($item['qty_reserved'] ?? 0),
                    wmsNormalizeDecimal($item['qty_picked'] ?? 0),
                    wmsSanitizeString($item['notes'] ?? '', 500) ?: null,
                    ($meta = (is_array($item['meta'] ?? null) ? $item['meta'] : [])) !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ]
            );
        }

        $db->commit();
        wmsAudit('wms.order.created', 'wms_orders', (string)$orderId, null, ['order_number' => $orderNumber]);
        return $orderId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function wmsOrderBridgeEventPayload(int $orderId, ?array $order = null, ?string $status = null): array
{
    $order = $order ?? wmsFetchOne('SELECT * FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$orderId]) ?? [];
    $meta = [];
    $rawMeta = (string)($order['meta'] ?? '');
    if ($rawMeta !== '') {
        $decoded = json_decode($rawMeta, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    return [
        'order_id' => $orderId,
        'wms_order_id' => $orderId,
        'order_number' => (string)($order['order_number'] ?? ''),
        'external_reference' => (string)($order['external_reference'] ?? ''),
        'warehouse_id' => (int)($order['warehouse_id'] ?? 0),
        'customer_name' => (string)($order['customer_name'] ?? ''),
        'status' => $status ?? (string)($order['status'] ?? ''),
        'ecommerce_order_id' => (int)($meta['ecommerce_order_id'] ?? 0),
        'ecommerce_order_number' => (string)($meta['ecommerce_order_number'] ?? ($order['external_reference'] ?? '')),
        'tracking_number' => (string)($meta['tracking_number'] ?? ''),
        'tracking_carrier' => (string)($meta['tracking_carrier'] ?? $meta['carrier'] ?? ''),
        'tracking_url' => (string)($meta['tracking_url'] ?? ''),
        'meta' => $meta,
    ];
}

function wmsOrderBridgeReservationCandidate(array $order, array $item, float $requiredQty): ?array
{
    $rawMeta = (string)($order['meta'] ?? '');
    if ($rawMeta === '') {
        return null;
    }

    $meta = json_decode($rawMeta, true);
    if (!is_array($meta)) {
        return null;
    }

    $ecommerceOrderId = (int)($meta['ecommerce_order_id'] ?? 0);
    if ($ecommerceOrderId <= 0) {
        return null;
    }

    return wmsFetchOne(
        'SELECT m.location_id, m.batch_id, m.qty, l.code AS location_code, b.batch_number
         FROM wms_movements m
         INNER JOIN wms_locations l ON l.id = m.location_id
         LEFT JOIN wms_batches b ON b.id = m.batch_id
         WHERE m.reference_type = ? AND m.reference_id = ? AND m.movement_type = ?
           AND m.product_id = ? AND m.warehouse_id = ? AND m.qty >= ?
         ORDER BY m.created_at ASC, m.id ASC
         LIMIT 1',
        ['order', $ecommerceOrderId, 'reserved', (int)$item['product_id'], (int)$order['warehouse_id'], $requiredQty]
    );
}

function wmsOrderGeneratePickList(int $orderId): array
{
    $order = wmsFetchOne('SELECT * FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE', [$orderId]);
    if ($order === null) {
        throw new RuntimeException('Order not found.');
    }
    if ((string)$order['status'] !== 'pending') {
        throw new RuntimeException('Pick lists can only be generated for pending orders.');
    }

    $strategy = strtolower((string)wmsConfigGet('picking.default_strategy', 'fefo'));
    $items = wmsFetchAll('SELECT * FROM wms_order_items WHERE order_id = ? ORDER BY id ASC', [$orderId]);
    $result = [];

    foreach ($items as $item) {
        $requiredQty = max(0.0, wmsNormalizeDecimal(($item['qty_ordered'] ?? 0) - ($item['qty_picked'] ?? 0)));
        if ($requiredQty <= 0) {
            $result[] = $item;
            continue;
        }

        $product = wmsFetchOne('SELECT is_batch_tracked FROM wms_products WHERE id = ? AND deleted_at IS NULL LIMIT 1', [(int)$item['product_id']]);
        if ($product === null) {
            throw new RuntimeException('Product not found for order item #' . (int)$item['id']);
        }

        $isBatchTracked = (int)($product['is_batch_tracked'] ?? 0) === 1;
        $bridgeReservation = wmsOrderBridgeReservationCandidate($order, $item, $requiredQty);
        if ($bridgeReservation !== null) {
            wmsDb()->execute(
                'UPDATE wms_order_items SET location_id = ?, batch_id = ?, qty_reserved = ? WHERE id = ?',
                [
                    (int)$bridgeReservation['location_id'],
                    isset($bridgeReservation['batch_id']) ? (int)$bridgeReservation['batch_id'] : null,
                    $requiredQty,
                    (int)$item['id'],
                ]
            );

            $result[] = array_merge($item, [
                'location_id' => (int)$bridgeReservation['location_id'],
                'batch_id' => isset($bridgeReservation['batch_id']) ? (int)$bridgeReservation['batch_id'] : null,
                'location_code' => (string)($bridgeReservation['location_code'] ?? ''),
                'batch_number' => (string)($bridgeReservation['batch_number'] ?? ''),
                'qty_to_pick' => $requiredQty,
            ]);
            continue;
        }

        $pick = wmsOrderPickCandidate((int)$item['product_id'], (int)$order['warehouse_id'], $isBatchTracked, $strategy);
        if ($pick === null) {
            if ($isBatchTracked && wmsBatchTrackedStockHasUnbatchedAvailability((int)$item['product_id'], (int)$order['warehouse_id'])) {
                throw new RuntimeException('Batch-tracked stock for order item #' . (int)$item['id'] . ' is missing batch assignments. Resolve inventory before generating a pick list.');
            }

            throw new RuntimeException('No stock available for order item #' . (int)$item['id']);
        }

        wmsDb()->execute(
            'UPDATE wms_order_items SET location_id = ?, batch_id = ? WHERE id = ?',
            [(int)$pick['location_id'], isset($pick['batch_id']) ? (int)$pick['batch_id'] : null, (int)$item['id']]
        );

        wmsReserveStock([
            'reference_type' => 'order',
            'reference_id' => (int)$orderId,
            'product_id' => (int)$item['product_id'],
            'warehouse_id' => (int)$order['warehouse_id'],
            'location_id' => (int)$pick['location_id'],
            'batch_id' => isset($pick['batch_id']) ? (int)$pick['batch_id'] : null,
            'qty' => $requiredQty,
            'meta' => ['order_item_id' => (int)$item['id']]
        ]);

        $result[] = array_merge($item, [
            'location_id' => (int)$pick['location_id'],
            'batch_id' => isset($pick['batch_id']) ? (int)$pick['batch_id'] : null,
            'location_code' => (string)($pick['location_code'] ?? ''),
            'batch_number' => (string)($pick['batch_number'] ?? ''),
            'qty_to_pick' => $requiredQty,
        ]);
    }

    // Phase 4: Pick Path Routing Optimization
    // Sort the final pick list by location_code to create the shortest physical path
    usort($result, function ($a, $b) {
        return strnatcasecmp((string)($a['location_code'] ?? ''), (string)($b['location_code'] ?? ''));
    });

    wmsDb()->execute('UPDATE wms_orders SET status = ? WHERE id = ?', ['picking', $orderId]);

    return $result;
}

function wmsDeliveryReceive(int $deliveryId, ?int $actorUserId = null): array
{
    $delivery = wmsFetchOne('SELECT * FROM wms_deliveries WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$deliveryId]);
    if ($delivery === null) {
        throw new RuntimeException('Delivery not found.');
    }

    if ((string)$delivery['status'] === 'cancelled') {
        throw new RuntimeException('Cancelled deliveries cannot be received.');
    }

    $items = wmsFetchAll('SELECT * FROM wms_delivery_items WHERE delivery_id = ? ORDER BY id ASC', [$deliveryId]);
    if ($items === []) {
        throw new RuntimeException('Delivery has no items.');
    }

    $movementIds = [];
    $putawayTaskIds = [];
    $db = wmsDb();
    $started = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $started = true;
    }

    try {
        $defaultStagingLocation = wmsResolveReceivingStagingLocation((int)$delivery['warehouse_id']);

        foreach ($items as $item) {
            $qty = max(0.0, wmsNormalizeDecimal(($item['qty_expected'] ?? 0) - ($item['qty_received'] ?? 0)));
            if ($qty <= 0) {
                continue;
            }

            $stagingLocationId = (int)($item['staging_location_id'] ?? 0);
            if ($stagingLocationId <= 0) {
                $stagingLocationId = (int)$defaultStagingLocation['id'];
            }

            $stagingLocation = wmsLocationRecord($stagingLocationId);
            if ($stagingLocation === null || (int)($stagingLocation['warehouse_id'] ?? 0) !== (int)$delivery['warehouse_id']) {
                throw new RuntimeException('Staging location is invalid for this delivery.');
            }
            if ((int)($stagingLocation['is_staging'] ?? 0) !== 1) {
                throw new RuntimeException('Inbound receipts must land in a staging location.');
            }

            $movementIds[] = wmsMovementCreate([
                'movement_type' => 'in',
                'reference_type' => 'delivery',
                'reference_id' => $deliveryId,
                'product_id' => (int)$item['product_id'],
                'warehouse_id' => (int)$delivery['warehouse_id'],
                'location_id' => $stagingLocationId,
                'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                'qty' => $qty,
                'unit_cost' => isset($item['unit_cost']) ? wmsNormalizeDecimal($item['unit_cost']) : null,
                'actor_user_id' => $actorUserId,
                'meta' => [
                    'delivery_item_id' => (int)$item['id'],
                    'flow' => 'receive_to_staging',
                    'putaway_location_id' => (int)$item['location_id'],
                ],
            ]);

            $db->execute(
                'UPDATE wms_delivery_items SET qty_received = qty_received + ?, staging_location_id = ?, updated_at = NOW() WHERE id = ?',
                [$qty, $stagingLocationId, (int)$item['id']]
            );
        }

        $status = wmsDeliveryRefreshStatus($deliveryId);
        if ($status !== 'pending' && ($delivery['received_at'] ?? null) === null) {
            $db->execute('UPDATE wms_deliveries SET received_at = NOW(), updated_at = NOW() WHERE id = ?', [$deliveryId]);
        }

        $putawayTaskIds = wmsDeliveryEnsurePutawayTasks($deliveryId);

        if ($started) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($started && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $status = wmsFetchOne('SELECT status FROM wms_deliveries WHERE id = ? LIMIT 1', [$deliveryId])['status'] ?? 'pending';

    wmsAudit('wms.delivery.received', 'wms_deliveries', (string)$deliveryId, $delivery, ['status' => $status]);
    wmsCtx()->fireEvent('wms.delivery.received', [
        'delivery_id' => $deliveryId,
        'movement_ids' => $movementIds,
        'putaway_task_ids' => $putawayTaskIds,
        'status' => $status,
    ]);

    return [
        'delivery_id' => $deliveryId,
        'movement_ids' => $movementIds,
        'putaway_task_ids' => $putawayTaskIds,
        'status' => $status,
    ];
}

function wmsDeliveryPutAwayItem(int $deliveryItemId, ?int $actorUserId = null): array
{
    $item = wmsFetchOne(
        'SELECT
            di.*, d.reference_number, d.warehouse_id, d.status AS delivery_status,
            p.sku, p.name AS product_name,
            l.code AS putaway_location_code,
            sl.code AS staging_location_code
         FROM wms_delivery_items di
         INNER JOIN wms_deliveries d ON d.id = di.delivery_id
         INNER JOIN wms_products p ON p.id = di.product_id
         INNER JOIN wms_locations l ON l.id = di.location_id
         LEFT JOIN wms_locations sl ON sl.id = di.staging_location_id
         WHERE di.id = ?
         LIMIT 1',
        [$deliveryItemId]
    );
    if ($item === null) {
        throw new RuntimeException('Delivery item not found.');
    }

    if ((string)($item['delivery_status'] ?? '') === 'cancelled') {
        throw new RuntimeException('Cancelled deliveries cannot be put away.');
    }

    $qtyRemaining = wmsDeliveryItemRemainingPutAwayQty($item);
    if ($qtyRemaining <= 0) {
        $status = wmsDeliveryRefreshStatus((int)$item['delivery_id']);
        return [
            'delivery_id' => (int)$item['delivery_id'],
            'delivery_item_id' => $deliveryItemId,
            'movement_ids' => [],
            'status' => $status,
        ];
    }

    $stagingLocationId = (int)($item['staging_location_id'] ?? 0);
    if ($stagingLocationId <= 0 || !wmsLocationIsStaging($stagingLocationId)) {
        throw new RuntimeException('No staged stock location is linked to this delivery line.');
    }

    $putawayLocationId = (int)($item['location_id'] ?? 0);
    if ($putawayLocationId <= 0) {
        throw new RuntimeException('Putaway location is required.');
    }
    if ($stagingLocationId === $putawayLocationId) {
        throw new RuntimeException('Putaway location must differ from the staging location.');
    }

    $db = wmsDb();
    $started = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $started = true;
    }

    try {
        $transfer = wmsTransferCreate(
            $stagingLocationId,
            $putawayLocationId,
            [[
                'product_id' => (int)$item['product_id'],
                'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                'qty' => $qtyRemaining,
            ]],
            $actorUserId,
            'Delivery putaway for ' . ((string)($item['reference_number'] ?? '') !== '' ? (string)$item['reference_number'] : ('delivery #' . (int)$item['delivery_id'])),
            'delivery_item',
            $deliveryItemId,
            [
                'delivery_id' => (int)$item['delivery_id'],
                'delivery_item_id' => $deliveryItemId,
            ]
        );

        $db->execute(
            'UPDATE wms_delivery_items SET qty_put_away = qty_put_away + ?, updated_at = NOW() WHERE id = ?',
            [$qtyRemaining, $deliveryItemId]
        );

        $status = wmsDeliveryRefreshStatus((int)$item['delivery_id']);

        if ($started) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($started && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    wmsAudit('wms.delivery_item.put_away', 'wms_delivery_items', (string)$deliveryItemId, null, [
        'delivery_id' => (int)$item['delivery_id'],
        'qty_put_away' => $qtyRemaining,
    ]);

    return [
        'delivery_id' => (int)$item['delivery_id'],
        'delivery_item_id' => $deliveryItemId,
        'movement_ids' => $transfer['movement_ids'] ?? [],
        'status' => $status,
    ];
}

function wmsOrderPick(int $orderId, ?int $actorUserId = null): array
{
    $order = wmsFetchOne('SELECT * FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$orderId]);
    if ($order === null) {
        throw new RuntimeException('Order not found.');
    }

    $items = wmsFetchAll('SELECT * FROM wms_order_items WHERE order_id = ? ORDER BY id ASC', [$orderId]);
    if ($items === []) {
        throw new RuntimeException('Order has no items.');
    }

    $movementIds = [];
    $db = wmsDb();
    $db->beginTransaction();

    try {
        foreach ($items as $item) {
            $qty = wmsNormalizeDecimal(($item['qty_ordered'] ?? 0) - ($item['qty_picked'] ?? 0));
            if ($qty <= 0) {
                continue;
            }
            if ((int)($item['location_id'] ?? 0) <= 0) {
                throw new RuntimeException('Generate pick list before picking order #' . $orderId);
            }

            $movementIds[] = wmsMovementCreate([
                'movement_type' => 'out',
                'reference_type' => 'order',
                'reference_id' => $orderId,
                'product_id' => (int)$item['product_id'],
                'warehouse_id' => (int)$order['warehouse_id'],
                'location_id' => (int)$item['location_id'],
                'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                'qty' => -$qty,
                'actor_user_id' => $actorUserId,
                'meta' => ['order_item_id' => (int)$item['id']],
            ]);

            $db->execute('UPDATE wms_order_items SET qty_picked = qty_picked + ? WHERE id = ?', [$qty, (int)$item['id']]);
        }

        $db->execute('UPDATE wms_orders SET status = ?, updated_at = NOW() WHERE id = ?', ['picked', $orderId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    wmsAudit('wms.order.picked', 'wms_orders', (string)$orderId, $order, ['status' => 'picked']);
    wmsCtx()->fireEvent('wms.order.picked', wmsOrderBridgeEventPayload($orderId, $order, 'picked'));

    return [
        'order_id' => $orderId,
        'movement_ids' => $movementIds,
    ];
}

function wmsOrderCancel(int $orderId, ?int $actorUserId = null): void
{
    $order = wmsFetchOne('SELECT * FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE', [$orderId]);
    if ($order === null) {
        throw new RuntimeException('Order not found.');
    }

    $status = (string)$order['status'];
    if (in_array($status, ['picked', 'dispatched', 'cancelled'], true)) {
        throw new RuntimeException("Order cannot be cancelled in status: {$status}");
    }

    $db = wmsDb();
    $db->beginTransaction();

    try {
        if ($status === 'picking') {
            // Find all reservations for this order to release them
            $reservations = wmsFetchAll(
                "SELECT * FROM wms_movements 
                 WHERE reference_type = 'order' AND reference_id = ? AND movement_type = 'reserved'",
                [$orderId]
            );

            // Calculate netted reserved qty per stock location (in case of partial pick/unpick complexity)
            // But wmsReleaseStock natively takes an item array
            foreach ($reservations as $res) {
                wmsReleaseStock([
                    'reference_type' => 'order_cancel',
                    'reference_id' => $orderId,
                    'product_id' => (int)$res['product_id'],
                    'warehouse_id' => (int)$res['warehouse_id'],
                    'location_id' => (int)$res['location_id'],
                    'batch_id' => isset($res['batch_id']) ? (int)$res['batch_id'] : null,
                    'qty' => (float)$res['qty'],
                    'actor_user_id' => $actorUserId,
                    'meta' => ['cancelled_reservation_id' => (int)$res['id']]
                ]);
            }

            // Clear pick list assignments
            $db->execute('UPDATE wms_order_items SET location_id = NULL, batch_id = NULL WHERE order_id = ?', [$orderId]);
        }

        $db->execute('UPDATE wms_orders SET status = ?, updated_at = NOW() WHERE id = ?', ['cancelled', $orderId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    wmsAudit('wms.order.cancelled', 'wms_orders', (string)$orderId, $order, ['status' => 'cancelled']);
}

function wmsOrderDispatch(int $orderId, ?int $actorUserId = null): void
{
    $order = wmsFetchOne('SELECT * FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$orderId]);
    if ($order === null) {
        throw new RuntimeException('Order not found.');
    }

    wmsDb()->execute('UPDATE wms_orders SET status = ?, dispatched_at = NOW(), updated_at = NOW() WHERE id = ?', ['dispatched', $orderId]);
    wmsAudit('wms.order.dispatched', 'wms_orders', (string)$orderId, $order, ['status' => 'dispatched', 'actor_user_id' => $actorUserId]);
    wmsCtx()->fireEvent('wms.order.dispatched', wmsOrderBridgeEventPayload($orderId, $order, 'dispatched'));
}

function wmsOrderDeliver(int $orderId, ?int $actorUserId = null): void
{
    $order = wmsFetchOne('SELECT * FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$orderId]);
    if ($order === null) {
        throw new RuntimeException('Order not found.');
    }
    if ((string)($order['status'] ?? '') !== 'dispatched') {
        throw new RuntimeException('Only dispatched orders can be delivered.');
    }

    wmsDb()->execute('UPDATE wms_orders SET status = ?, updated_at = NOW() WHERE id = ?', ['delivered', $orderId]);
    wmsAudit('wms.order.delivered', 'wms_orders', (string)$orderId, $order, ['status' => 'delivered', 'actor_user_id' => $actorUserId]);
    wmsCtx()->fireEvent('wms.order.delivered', wmsOrderBridgeEventPayload($orderId, $order, 'delivered'));
}

function wmsOrderCollectPayment(int $orderId, ?int $actorUserId = null, array $options = []): array
{
    $db = wmsDb();
    $db->beginTransaction();

    try {
        $order = wmsFetchOne('SELECT * FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE', [$orderId]);
        if ($order === null) {
            throw new RuntimeException('Order not found.');
        }
        if ((string)($order['status'] ?? '') !== 'delivered') {
            throw new RuntimeException('Only delivered orders can record payment collection.');
        }

        $meta = wmsJsonDecodeArray($order['meta'] ?? null);
        if (!empty($meta['payment_collected_at'])) {
            $db->commit();
            return [
                'order_id' => $orderId,
                'already_collected' => true,
                'collected_at' => (string)$meta['payment_collected_at'],
                'payment_method' => (string)($meta['payment_collection_method'] ?? ''),
            ];
        }

        $collectedAt = trim((string)($options['collected_at'] ?? '')) ?: date('Y-m-d H:i:s');
        $paymentMethod = trim((string)($options['payment_method'] ?? 'pay_on_delivery')) ?: 'pay_on_delivery';
        $note = trim((string)($options['note'] ?? ''));

        $meta['payment_collected_at'] = $collectedAt;
        $meta['payment_collection_method'] = $paymentMethod;
        if ($actorUserId !== null && $actorUserId > 0) {
            $meta['payment_collected_by'] = $actorUserId;
        }
        if ($note !== '') {
            $meta['payment_collection_note'] = $note;
        }

        $db->execute(
            'UPDATE wms_orders SET meta = ?, updated_at = NOW() WHERE id = ?',
            [json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $orderId]
        );
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    wmsAudit('wms.order.payment_collected', 'wms_orders', (string)$orderId, $order, [
        'status' => 'delivered',
        'payment_method' => $paymentMethod,
        'collected_at' => $collectedAt,
        'actor_user_id' => $actorUserId,
    ]);
    wmsCtx()->fireEvent('wms.order.payment_collected', array_merge(
        wmsOrderBridgeEventPayload($orderId, $order, 'delivered'),
        [
            'payment_status' => 'paid',
            'payment_method' => $paymentMethod,
            'collected_at' => $collectedAt,
            'actor_user_id' => $actorUserId,
        ]
    ));

    return [
        'order_id' => $orderId,
        'collected_at' => $collectedAt,
        'payment_method' => $paymentMethod,
    ];
}

function wmsTransferCreate(
    int $fromLocationId,
    int $toLocationId,
    array $items,
    ?int $actorUserId = null,
    ?string $notes = null,
    ?string $referenceType = null,
    ?int $referenceId = null,
    array $extraMeta = []
): array
{
    if ($fromLocationId <= 0 || $toLocationId <= 0 || $fromLocationId === $toLocationId) {
        throw new RuntimeException('Valid source and destination locations are required.');
    }

    $fromLocation = wmsFetchOne('SELECT * FROM wms_locations WHERE id = ? LIMIT 1', [$fromLocationId]);
    $toLocation = wmsFetchOne('SELECT * FROM wms_locations WHERE id = ? LIMIT 1', [$toLocationId]);
    if ($fromLocation === null || $toLocation === null) {
        throw new RuntimeException('Transfer locations not found.');
    }

    $movementIds = [];
    $db = wmsDb();
    $started = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $started = true;
    }

    $referenceType = $referenceType !== null && trim($referenceType) !== ''
        ? wmsSanitizeString($referenceType, 50)
        : 'transfer';
    $referenceId = $referenceId !== null && $referenceId > 0 ? $referenceId : null;

    try {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $qty = wmsNormalizeDecimal($item['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $productId = (int)($item['product_id'] ?? 0);
            $batchId = isset($item['batch_id']) && (int)$item['batch_id'] > 0 ? (int)$item['batch_id'] : null;

            $movementIds[] = wmsMovementCreate([
                'movement_type' => 'transfer_out',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'product_id' => $productId,
                'warehouse_id' => (int)$fromLocation['warehouse_id'],
                'location_id' => $fromLocationId,
                'batch_id' => $batchId,
                'qty' => -$qty,
                'notes' => $notes,
                'actor_user_id' => $actorUserId,
                'meta' => array_merge(['to_location_id' => $toLocationId], $extraMeta),
            ]);

            $movementIds[] = wmsMovementCreate([
                'movement_type' => 'transfer_in',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'product_id' => $productId,
                'warehouse_id' => (int)$toLocation['warehouse_id'],
                'location_id' => $toLocationId,
                'batch_id' => $batchId,
                'qty' => $qty,
                'notes' => $notes,
                'actor_user_id' => $actorUserId,
                'meta' => array_merge(['from_location_id' => $fromLocationId], $extraMeta),
            ]);
        }

        if ($started) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($started && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return ['movement_ids' => $movementIds];
}

function wmsPutAwaySuggest(int $productId, int $warehouseId): array
{
    $product = wmsFetchOne('SELECT * FROM wms_products WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$productId]);
    if ($product === null) {
        throw new RuntimeException('Product not found.');
    }

    $rules = wmsFetchAll(
        'SELECT * FROM wms_putaway_rules WHERE warehouse_id = ? AND is_active = 1 AND (product_id IS NULL OR product_id = ?) AND (product_type IS NULL OR product_type = ?)
         ORDER BY priority DESC, id ASC',
        [$warehouseId, $productId, (string)($product['product_type'] ?? 'physical')]
    );

    $locations = wmsFetchAll(
        'SELECT l.*, COALESCE(SUM(s.qty_on_hand), 0) AS current_qty
         FROM wms_locations l
         LEFT JOIN wms_stocks s ON s.location_id = l.id
         WHERE l.warehouse_id = ? AND l.is_active = 1 AND l.deleted_at IS NULL AND COALESCE(l.is_staging, 0) = 0
         GROUP BY l.id
         ORDER BY l.sort_order ASC, l.code ASC',
        [$warehouseId]
    );

    $ranked = [];
    foreach ($locations as $location) {
        $score = 0;
        foreach ($rules as $rule) {
            $preferredZone = trim((string)($rule['preferred_zone'] ?? ''));
            if ($preferredZone !== '' && !str_starts_with((string)($location['code'] ?? ''), $preferredZone)) {
                continue;
            }
            $score += (int)($rule['priority'] ?? 0);
        }
        $capacity = isset($location['capacity']) ? (float)$location['capacity'] : null;
        $currentQty = (float)($location['current_qty'] ?? 0);
        if ($capacity !== null && $capacity > 0) {
            $score += (int)max(0, round(($capacity - $currentQty) * 10));
        }
        $location['score'] = $score;
        $ranked[] = $location;
    }

    usort($ranked, static function (array $a, array $b): int {
        return ((int)($b['score'] ?? 0) <=> (int)($a['score'] ?? 0)) ?: strcmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
    });

    return array_slice($ranked, 0, 10);
}

function wmsCycleCountSnapshot(int $cycleCountId): array
{
    $count = wmsFetchOne('SELECT * FROM wms_cycle_counts WHERE id = ? LIMIT 1', [$cycleCountId]);
    if ($count === null) {
        throw new RuntimeException('Cycle count not found.');
    }

    $where = ['warehouse_id = ?'];
    $params = [(int)$count['warehouse_id']];
    if ((int)($count['location_id'] ?? 0) > 0) {
        $where[] = 'location_id = ?';
        $params[] = (int)$count['location_id'];
    }

    $stocks = wmsFetchAll('SELECT * FROM wms_stocks WHERE ' . implode(' AND ', $where) . ' ORDER BY product_id ASC, location_id ASC', $params);
    $db = wmsDb();
    $db->beginTransaction();

    try {
        $db->execute('DELETE FROM wms_cycle_count_items WHERE cycle_count_id = ?', [$cycleCountId]);
        foreach ($stocks as $stock) {
            $db->execute(
                'INSERT INTO wms_cycle_count_items (cycle_count_id, product_id, location_id, batch_id, qty_system, qty_counted, adjustment_movement_id, notes)
                 VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
                [
                    $cycleCountId,
                    (int)$stock['product_id'],
                    (int)$stock['location_id'],
                    isset($stock['batch_id']) ? (int)$stock['batch_id'] : null,
                    wmsNormalizeDecimal($stock['qty_on_hand'] ?? 0),
                ]
            );
        }
        $db->execute('UPDATE wms_cycle_counts SET status = ?, updated_at = NOW() WHERE id = ?', ['in_progress', $cycleCountId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return wmsFetchAll('SELECT * FROM wms_cycle_count_items WHERE cycle_count_id = ? ORDER BY id ASC', [$cycleCountId]);
}

function wmsCycleCountClose(int $cycleCountId, ?int $actorUserId = null): array
{
    $count = wmsFetchOne('SELECT * FROM wms_cycle_counts WHERE id = ? LIMIT 1', [$cycleCountId]);
    if ($count === null) {
        throw new RuntimeException('Cycle count not found.');
    }

    $items = wmsFetchAll('SELECT * FROM wms_cycle_count_items WHERE cycle_count_id = ? ORDER BY id ASC', [$cycleCountId]);
    $movementIds = [];
    $db = wmsDb();
    $db->beginTransaction();

    try {
        foreach ($items as $item) {
            $variance = wmsNormalizeDecimal(($item['qty_counted'] ?? $item['qty_system']) - ($item['qty_system'] ?? 0));
            if ($variance == 0.0) {
                continue;
            }

            $movementId = wmsMovementCreate([
                'movement_type' => 'cycle_count_adjustment',
                'reference_type' => 'cycle_count',
                'reference_id' => $cycleCountId,
                'product_id' => (int)$item['product_id'],
                'warehouse_id' => (int)$count['warehouse_id'],
                'location_id' => (int)$item['location_id'],
                'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                'qty' => $variance,
                'actor_user_id' => $actorUserId,
                'meta' => ['cycle_count_item_id' => (int)$item['id']],
            ]);

            $movementIds[] = $movementId;
            $db->execute('UPDATE wms_cycle_count_items SET adjustment_movement_id = ? WHERE id = ?', [$movementId, (int)$item['id']]);
        }

        $db->execute('UPDATE wms_cycle_counts SET status = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?', ['completed', $cycleCountId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return ['movement_ids' => $movementIds];
}

function wmsVelocityReport(int $days = 30): array
{
    $days = max(1, min(365, $days));
    return wmsFetchAll(
        'SELECT p.id AS product_id, p.sku, p.name AS product_name,
                SUM(CASE WHEN m.movement_type IN (\'out\', \'transfer_out\') THEN ABS(m.qty) ELSE 0 END) AS qty_out,
                SUM(CASE WHEN m.movement_type = \'in\' THEN m.qty ELSE 0 END) AS qty_in,
                COUNT(*) AS movement_count
         FROM wms_products p
         LEFT JOIN wms_movements m ON m.product_id = p.id AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
         WHERE p.deleted_at IS NULL
         GROUP BY p.id, p.sku, p.name
         ORDER BY qty_out DESC, movement_count DESC, p.name ASC',
        [$days]
    );
}

function wmsExpiryReport(int $days = 30): array
{
    $days = max(1, min(365, $days));
    return wmsFetchAll(
        'SELECT b.id, b.product_id, b.batch_number, b.expires_at, p.sku, p.name AS product_name,
                COALESCE(SUM(s.qty_on_hand), 0) AS qty_on_hand
         FROM wms_batches b
         INNER JOIN wms_products p ON p.id = b.product_id
         LEFT JOIN wms_stocks s ON s.batch_id = b.id
         WHERE b.expires_at IS NOT NULL AND b.expires_at <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
         GROUP BY b.id, b.product_id, b.batch_number, b.expires_at, p.sku, p.name
         ORDER BY b.expires_at ASC, p.name ASC',
        [$days]
    );
}
