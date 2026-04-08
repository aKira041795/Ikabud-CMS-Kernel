<?php

declare(strict_types=1);

use PDO;
use Throwable;

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

function wmsOrderGeneratePickList(int $orderId): array
{
    $order = wmsFetchOne('SELECT * FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE', [$orderId]);
    if ($order === null) {
        throw new RuntimeException('Order not found.');
    }
    if ((string)$order['status'] !== 'pending') {
        throw new RuntimeException('Pick lists can only be generated for pending orders.');
    }

    $strategy = strtolower((string)(wmsSettings()['picking_strategy'] ?? 'fefo'));
    $items = wmsFetchAll('SELECT * FROM wms_order_items WHERE order_id = ? ORDER BY id ASC', [$orderId]);
    $result = [];

    foreach ($items as $item) {
        $requiredQty = max(0.0, wmsNormalizeDecimal(($item['qty_ordered'] ?? 0) - ($item['qty_picked'] ?? 0)));
        if ($requiredQty <= 0) {
            $result[] = $item;
            continue;
        }

        $query = 'SELECT s.location_id, s.batch_id, s.qty_available, l.code AS location_code, b.batch_number, b.expires_at
                  FROM wms_stocks s
                  INNER JOIN wms_locations l ON l.id = s.location_id
                  LEFT JOIN wms_batches b ON b.id = s.batch_id
                  WHERE s.product_id = ? AND s.warehouse_id = ? AND s.qty_available > 0
                  ORDER BY ';

        if ($strategy === 'fifo') {
            $query .= 's.updated_at ASC, s.id ASC';
        } elseif ($strategy === 'lifo') {
            $query .= 's.updated_at DESC, s.id DESC';
        } else {
            $query .= 'CASE WHEN b.expires_at IS NULL THEN 1 ELSE 0 END ASC, b.expires_at ASC, s.updated_at ASC';
        }

        $query .= ' LIMIT 1';

        $pick = wmsFetchOne($query, [(int)$item['product_id'], (int)$order['warehouse_id']]);
        if ($pick === null) {
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
    $db = wmsDb();
    $db->beginTransaction();

    try {
        foreach ($items as $item) {
            $qty = wmsNormalizeDecimal($item['qty_received'] ?? $item['qty_expected'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $movementIds[] = wmsMovementCreate([
                'movement_type' => 'in',
                'reference_type' => 'delivery',
                'reference_id' => $deliveryId,
                'product_id' => (int)$item['product_id'],
                'warehouse_id' => (int)$delivery['warehouse_id'],
                'location_id' => (int)$item['location_id'],
                'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                'qty' => $qty,
                'unit_cost' => isset($item['unit_cost']) ? wmsNormalizeDecimal($item['unit_cost']) : null,
                'actor_user_id' => $actorUserId,
                'meta' => ['delivery_item_id' => (int)$item['id']],
            ]);

            $db->execute('UPDATE wms_delivery_items SET qty_received = ? WHERE id = ?', [$qty, (int)$item['id']]);
        }

        $db->execute('UPDATE wms_deliveries SET status = ?, received_at = NOW(), updated_at = NOW() WHERE id = ?', ['received', $deliveryId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    wmsAudit('wms.delivery.received', 'wms_deliveries', (string)$deliveryId, $delivery, ['status' => 'received']);
    wmsCtx()->fireEvent('wms.delivery.received', ['delivery_id' => $deliveryId, 'movement_ids' => $movementIds]);

    return [
        'delivery_id' => $deliveryId,
        'movement_ids' => $movementIds,
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
    wmsCtx()->fireEvent('wms.order.dispatched', ['order_id' => $orderId]);
}

function wmsTransferCreate(int $fromLocationId, int $toLocationId, array $items, ?int $actorUserId = null, ?string $notes = null): array
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
    $db->beginTransaction();

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
                'reference_type' => 'transfer',
                'product_id' => $productId,
                'warehouse_id' => (int)$fromLocation['warehouse_id'],
                'location_id' => $fromLocationId,
                'batch_id' => $batchId,
                'qty' => -$qty,
                'notes' => $notes,
                'actor_user_id' => $actorUserId,
                'meta' => ['to_location_id' => $toLocationId],
            ]);

            $movementIds[] = wmsMovementCreate([
                'movement_type' => 'transfer_in',
                'reference_type' => 'transfer',
                'product_id' => $productId,
                'warehouse_id' => (int)$toLocation['warehouse_id'],
                'location_id' => $toLocationId,
                'batch_id' => $batchId,
                'qty' => $qty,
                'notes' => $notes,
                'actor_user_id' => $actorUserId,
                'meta' => ['from_location_id' => $fromLocationId],
            ]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
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
         WHERE l.warehouse_id = ? AND l.is_active = 1 AND l.deleted_at IS NULL
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
