<?php

declare(strict_types=1);

// ── Stock Queries ──

function wmsApiStockQuery(): void
{
    $user = wmsCurrentUser();
    $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
    $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
    $locationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : null;
    $batchId = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : null;
    $lowStock = ($_GET['low_stock'] ?? '') === '1';

    $sql = 'SELECT s.*, p.sku, p.name AS product_name, p.unit, p.reorder_point, p.safety_stock,
                   w.code AS warehouse_code, w.name AS warehouse_name,
                   l.code AS location_code, l.name AS location_name,
                   b.batch_number, b.lot_number
            FROM wms_stock s
            JOIN wms_products p ON p.id = s.product_id
            JOIN wms_warehouses w ON w.id = s.warehouse_id
            LEFT JOIN wms_locations l ON l.id = s.location_id
            LEFT JOIN wms_batches b ON b.id = s.batch_id
            WHERE 1=1';
    $params = [];

    if ($productId) { $sql .= ' AND s.product_id = :pid'; $params[':pid'] = $productId; }
    if ($warehouseId) { $sql .= ' AND s.warehouse_id = :wid'; $params[':wid'] = $warehouseId; }
    if ($locationId) { $sql .= ' AND s.location_id = :lid'; $params[':lid'] = $locationId; }
    if ($batchId) { $sql .= ' AND s.batch_id = :bid'; $params[':bid'] = $batchId; }
    if ($lowStock) {
        $sql .= ' AND s.qty_on_hand <= COALESCE(p.reorder_point, 0) AND s.qty_on_hand > 0';
    }
    $sql .= ' ORDER BY p.name ASC LIMIT 200';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['stock' => $rows]);
}

function wmsApiStockGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);

    $stmt = wmsDb()->query(
        'SELECT s.*, p.sku, p.name AS product_name, p.unit, p.reorder_point, p.safety_stock,
                w.code AS warehouse_code, w.name AS warehouse_name,
                l.code AS location_code, l.name AS location_name,
                b.batch_number, b.lot_number
         FROM wms_stock s
         JOIN wms_products p ON p.id = s.product_id
         JOIN wms_warehouses w ON w.id = s.warehouse_id
         LEFT JOIN wms_locations l ON l.id = s.location_id
         LEFT JOIN wms_batches b ON b.id = s.batch_id
         WHERE s.id = :id',
        [':id' => $id]
    );
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) wmsJsonError('Stock record not found.', 404);
    wmsJsonOk(['stock' => $row]);
}

// ── Movement History ──

function wmsApiMovementsList(): void
{
    $user = wmsCurrentUser();
    $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
    $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
    $type = trim((string)($_GET['type'] ?? ''));
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));

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

    if ($productId) { $sql .= ' AND m.product_id = :pid'; $params[':pid'] = $productId; }
    if ($warehouseId) { $sql .= ' AND m.warehouse_id = :wid'; $params[':wid'] = $warehouseId; }
    if ($type !== '') { $sql .= ' AND m.movement_type = :type'; $params[':type'] = $type; }
    $sql .= ' ORDER BY m.created_at DESC LIMIT ' . $limit;

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['movements' => $rows]);
}

// ── Stock Receive (Receipt) ──

function wmsApiStockReceive(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $productId = (int)($input['product_id'] ?? 0);
    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $locationId = isset($input['location_id']) ? (int)$input['location_id'] : null;
    $batchId = isset($input['batch_id']) ? (int)$input['batch_id'] : null;
    $quantity = (float)($input['quantity'] ?? 0);
    $referenceType = trim((string)($input['reference_type'] ?? ''));
    $referenceId = isset($input['reference_id']) ? (int)$input['reference_id'] : null;
    $notes = trim((string)($input['notes'] ?? ''));
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));

    if ($productId <= 0 || $warehouseId <= 0 || $quantity <= 0) {
        wmsJsonError('product_id, warehouse_id, and quantity (>0) are required.');
    }

    $p = wmsDb()->query('SELECT id, is_batch_tracked FROM wms_products WHERE id = :id AND is_active = 1', [':id' => $productId])->fetch(\PDO::FETCH_ASSOC);
    if (!$p) wmsJsonError('Product not found.', 404);
    $w = wmsDb()->query('SELECT id FROM wms_warehouses WHERE id = :id AND is_active = 1', [':id' => $warehouseId])->fetch(\PDO::FETCH_ASSOC);
    if (!$w) wmsJsonError('Warehouse not found.', 404);

    if ($p['is_batch_tracked'] && !$batchId) {
        wmsJsonError('Batch-tracked products require a batch_id.');
    }

    if ($idempotencyKey !== '') {
        $existing = wmsDb()->query('SELECT id FROM wms_idempotency_keys WHERE idempotency_key = :key', [':key' => $idempotencyKey])->fetch(\PDO::FETCH_ASSOC);
        if ($existing) wmsJsonError('Idempotent request — already processed.', 409);
    }

    wmsDb()->beginTransaction();
    try {
        $stock = wmsDb()->query(
            'SELECT id, qty_on_hand FROM wms_stock
             WHERE product_id = :pid AND warehouse_id = :wid
               AND (location_id = :lid OR (:lid_null IS NULL AND location_id IS NULL))
               AND (batch_id = :bid OR (:bid_null IS NULL AND batch_id IS NULL))',
            [':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId, ':lid_null' => $locationId, ':bid' => $batchId, ':bid_null' => $batchId]
        )->fetch(\PDO::FETCH_ASSOC);

        if ($stock) {
            $prevQty = (float)$stock['qty_on_hand'];
            $newQty = $prevQty + $quantity;
            wmsDb()->execute(
                'UPDATE wms_stock SET qty_on_hand = :qty, last_movement_at = NOW() WHERE id = :id',
                [':qty' => $newQty, ':id' => $stock['id']]
            );
            $stockId = (int)$stock['id'];
        } else {
            $prevQty = 0;
            $newQty = $quantity;
            wmsDb()->execute(
                'INSERT INTO wms_stock (product_id, warehouse_id, location_id, batch_id, qty_on_hand, last_movement_at)
                 VALUES (:pid, :wid, :lid, :bid, :qty, NOW())',
                [':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId, ':bid' => $batchId, ':qty' => $quantity]
            );
            $stockId = (int)wmsDb()->lastInsertId();
        }

        wmsDb()->execute(
            'INSERT INTO wms_stock_movements (product_id, warehouse_id, to_location_id, batch_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reference_type, reference_id, notes, created_by)
             VALUES (:pid, :wid, :lid, :bid, :type, :qty, :prev, :new, :ref_type, :ref_id, :notes, :uid)',
            [
                ':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId,
                ':bid' => $batchId, ':type' => 'receipt', ':qty' => $quantity,
                ':prev' => $prevQty, ':new' => $newQty,
                ':ref_type' => $referenceType ?: null, ':ref_id' => $referenceId,
                ':notes' => $notes ?: null, ':uid' => (int)$user['id'],
            ]
        );

        if ($idempotencyKey !== '') {
            wmsDb()->execute('INSERT INTO wms_idempotency_keys (idempotency_key, created_at) VALUES (:key, NOW())', [':key' => $idempotencyKey]);
        }

        wmsDb()->commit();
        wmsJsonOk(['stock_id' => $stockId, 'movement_id' => (int)wmsDb()->lastInsertId(), 'new_qty_on_hand' => $newQty], 201);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

// ── Stock Transfer ──

function wmsApiStockTransfer(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $productId = (int)($input['product_id'] ?? 0);
    $fromLocationId = (int)($input['from_location_id'] ?? 0);
    $toLocationId = (int)($input['to_location_id'] ?? 0);
    $quantity = (float)($input['quantity'] ?? 0);
    $batchId = isset($input['batch_id']) ? (int)$input['batch_id'] : null;
    $notes = trim((string)($input['notes'] ?? ''));

    if ($productId <= 0 || $fromLocationId <= 0 || $toLocationId <= 0 || $quantity <= 0) {
        wmsJsonError('product_id, from_location_id, to_location_id, and quantity (>0) are required.');
    }
    if ($fromLocationId === $toLocationId) {
        wmsJsonError('Source and destination locations must differ.');
    }

    $fromLoc = wmsDb()->query('SELECT id, warehouse_id FROM wms_locations WHERE id = :id AND is_active = 1', [':id' => $fromLocationId])->fetch(\PDO::FETCH_ASSOC);
    if (!$fromLoc) wmsJsonError('Source location not found.', 404);
    $toLoc = wmsDb()->query('SELECT id, warehouse_id FROM wms_locations WHERE id = :id AND is_active = 1', [':id' => $toLocationId])->fetch(\PDO::FETCH_ASSOC);
    if (!$toLoc) wmsJsonError('Destination location not found.', 404);
    if ($fromLoc['warehouse_id'] !== $toLoc['warehouse_id']) {
        wmsJsonError('Cross-warehouse transfers not supported yet — use receipt + transfer_out.');
    }
    $warehouseId = (int)$fromLoc['warehouse_id'];

    $stock = wmsDb()->query(
        'SELECT id, qty_on_hand, qty_reserved FROM wms_stock
         WHERE product_id = :pid AND warehouse_id = :wid AND location_id = :lid
           AND (batch_id = :bid OR (:bid_null IS NULL AND batch_id IS NULL))',
        [':pid' => $productId, ':wid' => $warehouseId, ':lid' => $fromLocationId, ':bid' => $batchId, ':bid_null' => $batchId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$stock) wmsJsonError('No stock at source location.', 404);

    $available = (float)$stock['qty_on_hand'] - (float)$stock['qty_reserved'];
    if ($quantity > $available) {
        wmsJsonError("Insufficient available stock. Available: $available");
    }

    wmsDb()->beginTransaction();
    try {
        $prevFrom = (float)$stock['qty_on_hand'];
        $newFrom = $prevFrom - $quantity;
        wmsDb()->execute('UPDATE wms_stock SET qty_on_hand = :qty, last_movement_at = NOW() WHERE id = :id', [':qty' => $newFrom, ':id' => $stock['id']]);

        $destStock = wmsDb()->query(
            'SELECT id, qty_on_hand FROM wms_stock
             WHERE product_id = :pid AND warehouse_id = :wid AND location_id = :lid
               AND (batch_id = :bid OR (:bid_null IS NULL AND batch_id IS NULL))',
            [':pid' => $productId, ':wid' => $warehouseId, ':lid' => $toLocationId, ':bid' => $batchId, ':bid_null' => $batchId]
        )->fetch(\PDO::FETCH_ASSOC);

        if ($destStock) {
            $prevTo = (float)$destStock['qty_on_hand'];
            $newTo = $prevTo + $quantity;
            wmsDb()->execute('UPDATE wms_stock SET qty_on_hand = :qty, last_movement_at = NOW() WHERE id = :id', [':qty' => $newTo, ':id' => $destStock['id']]);
        } else {
            $prevTo = 0;
            $newTo = $quantity;
            wmsDb()->execute(
                'INSERT INTO wms_stock (product_id, warehouse_id, location_id, batch_id, qty_on_hand, last_movement_at)
                 VALUES (:pid, :wid, :lid, :bid, :qty, NOW())',
                [':pid' => $productId, ':wid' => $warehouseId, ':lid' => $toLocationId, ':bid' => $batchId, ':qty' => $quantity]
            );
        }

        wmsDb()->execute(
            'INSERT INTO wms_stock_movements (product_id, warehouse_id, from_location_id, to_location_id, batch_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, notes, created_by)
             VALUES (:pid, :wid, :flid, :tlid, :bid, :type, :qty, :prev, :new, :notes, :uid)',
            [
                ':pid' => $productId, ':wid' => $warehouseId,
                ':flid' => $fromLocationId, ':tlid' => $toLocationId,
                ':bid' => $batchId, ':type' => 'transfer', ':qty' => $quantity,
                ':prev' => $prevFrom, ':new' => $newFrom,
                ':notes' => $notes ?: null, ':uid' => (int)$user['id'],
            ]
        );

        wmsDb()->commit();
        wmsJsonOk(['from_stock_id' => (int)$stock['id'], 'new_from_qty' => $newFrom, 'new_to_qty' => $newTo]);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

// ── Stock Adjustment ──

function wmsApiStockAdjust(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $productId = (int)($input['product_id'] ?? 0);
    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $locationId = isset($input['location_id']) ? (int)$input['location_id'] : null;
    $batchId = isset($input['batch_id']) ? (int)$input['batch_id'] : null;
    $newQty = (float)($input['new_quantity'] ?? -1);
    $adjustmentType = trim((string)($input['adjustment_type'] ?? 'correction'));
    $reason = trim((string)($input['reason'] ?? ''));

    if ($productId <= 0 || $warehouseId <= 0 || $newQty < 0) {
        wmsJsonError('product_id, warehouse_id, and new_quantity (>=0) are required.');
    }
    if ($reason === '') wmsJsonError('Reason is required for adjustments.');

    $stock = wmsDb()->query(
        'SELECT id, qty_on_hand, qty_reserved FROM wms_stock
         WHERE product_id = :pid AND warehouse_id = :wid
           AND (location_id = :lid OR (:lid_null IS NULL AND location_id IS NULL))
           AND (batch_id = :bid OR (:bid_null IS NULL AND batch_id IS NULL))',
        [':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId, ':lid_null' => $locationId, ':bid' => $batchId, ':bid_null' => $batchId]
    )->fetch(\PDO::FETCH_ASSOC);

    wmsDb()->beginTransaction();
    try {
        if ($stock) {
            $prevQty = (float)$stock['qty_on_hand'];
            $diff = $newQty - $prevQty;
            wmsDb()->execute('UPDATE wms_stock SET qty_on_hand = :qty, last_movement_at = NOW() WHERE id = :id', [':qty' => $newQty, ':id' => $stock['id']]);
            $stockId = (int)$stock['id'];

            if ($diff != 0) {
                $movementType = $diff > 0 ? 'adjustment_up' : 'adjustment_down';
                wmsDb()->execute(
                    'INSERT INTO wms_stock_movements (product_id, warehouse_id, to_location_id, batch_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reason, notes, created_by)
                     VALUES (:pid, :wid, :lid, :bid, :type, :qty, :prev, :new, :reason, :reason_notes, :uid)',
                    [
                        ':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId,
                        ':bid' => $batchId, ':type' => $movementType, ':qty' => $diff,
                        ':prev' => $prevQty, ':new' => $newQty,
                        ':reason' => $reason, ':reason_notes' => $reason, ':uid' => (int)$user['id'],
                    ]
                );
            }
        } else {
            $prevQty = 0;
            wmsDb()->execute(
                'INSERT INTO wms_stock (product_id, warehouse_id, location_id, batch_id, qty_on_hand, last_movement_at)
                 VALUES (:pid, :wid, :lid, :bid, :qty, NOW())',
                [':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId, ':bid' => $batchId, ':qty' => $newQty]
            );
            $stockId = (int)wmsDb()->lastInsertId();

            if ($newQty > 0) {
                wmsDb()->execute(
                    'INSERT INTO wms_stock_movements (product_id, warehouse_id, to_location_id, batch_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reason, notes, created_by)
                     VALUES (:pid, :wid, :lid, :bid, :type, :qty, :prev, :new, :reason, :reason_notes, :uid)',
                    [
                        ':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId,
                        ':bid' => $batchId, ':type' => 'adjustment_up', ':qty' => $newQty,
                        ':prev' => 0, ':new' => $newQty,
                        ':reason' => $reason, ':reason_notes' => $reason, ':uid' => (int)$user['id'],
                    ]
                );
            }
        }

        wmsDb()->execute(
            'INSERT INTO wms_inventory_adjustments (product_id, warehouse_id, location_id, batch_id, adjustment_type, expected_qty, counted_qty, reason, status, created_by, counted_at)
             VALUES (:pid, :wid, :lid, :bid, :atype, :expected, :counted, :reason, :status, :uid, NOW())',
            [
                ':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId,
                ':bid' => $batchId, ':atype' => $adjustmentType,
                ':expected' => $prevQty, ':counted' => $newQty,
                ':reason' => $reason, ':status' => 'approved', ':uid' => (int)$user['id'],
            ]
        );

        wmsDb()->commit();
        wmsJsonOk(['stock_id' => $stockId, 'new_qty_on_hand' => $newQty]);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

// ── Stock Scrap ──

function wmsApiStockScrap(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $productId = (int)($input['product_id'] ?? 0);
    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $locationId = isset($input['location_id']) ? (int)$input['location_id'] : null;
    $batchId = isset($input['batch_id']) ? (int)$input['batch_id'] : null;
    $quantity = (float)($input['quantity'] ?? 0);
    $reason = trim((string)($input['reason'] ?? ''));

    if ($productId <= 0 || $warehouseId <= 0 || $quantity <= 0) {
        wmsJsonError('product_id, warehouse_id, and quantity (>0) are required.');
    }
    if ($reason === '') wmsJsonError('Reason is required for scrap.');

    $stock = wmsDb()->query(
        'SELECT id, qty_on_hand FROM wms_stock
         WHERE product_id = :pid AND warehouse_id = :wid
           AND (location_id = :lid OR (:lid_null IS NULL AND location_id IS NULL))
           AND (batch_id = :bid OR (:bid_null IS NULL AND batch_id IS NULL))',
        [':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId, ':lid_null' => $locationId, ':bid' => $batchId, ':bid_null' => $batchId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$stock) wmsJsonError('No stock record found.', 404);
    if ((float)$stock['qty_on_hand'] < $quantity) wmsJsonError('Insufficient stock to scrap.');

    wmsDb()->beginTransaction();
    try {
        $prevQty = (float)$stock['qty_on_hand'];
        $newQty = $prevQty - $quantity;
        wmsDb()->execute('UPDATE wms_stock SET qty_on_hand = :qty, last_movement_at = NOW() WHERE id = :id', [':qty' => $newQty, ':id' => $stock['id']]);

        wmsDb()->execute(
            'INSERT INTO wms_stock_movements (product_id, warehouse_id, to_location_id, batch_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reason, notes, created_by)
             VALUES (:pid, :wid, :lid, :bid, :type, :qty, :prev, :new, :reason, :reason_notes, :uid)',
            [
                ':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId,
                ':bid' => $batchId, ':type' => 'scrap', ':qty' => -$quantity,
                ':prev' => $prevQty, ':new' => $newQty,
                ':reason' => $reason, ':reason_notes' => $reason, ':uid' => (int)$user['id'],
            ]
        );

        wmsDb()->commit();
        wmsJsonOk(['stock_id' => (int)$stock['id'], 'new_qty_on_hand' => $newQty]);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

// ── Adjustments List ──

function wmsApiAdjustmentsList(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));

    $sql = 'SELECT a.*, p.sku, p.name AS product_name, p.unit,
                   w.code AS warehouse_code, w.name AS warehouse_name,
                   l.code AS location_code,
                   b.batch_number,
                   cu.full_name AS created_by_name,
                   au.full_name AS approved_by_name
            FROM wms_inventory_adjustments a
            JOIN wms_products p ON p.id = a.product_id
            JOIN wms_warehouses w ON w.id = a.warehouse_id
            LEFT JOIN wms_locations l ON l.id = a.location_id
            LEFT JOIN wms_batches b ON b.id = a.batch_id
            LEFT JOIN wms_users cu ON cu.id = a.created_by
            LEFT JOIN wms_users au ON au.id = a.approved_by
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND a.status = :status'; $params[':status'] = $status; }
    $sql .= ' ORDER BY a.created_at DESC LIMIT ' . $limit;

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['adjustments' => $rows]);
}

// ── Adjustment Approve/Reject ──

function wmsApiAdjustmentReview(array $params): void
{
    $user = wmsCurrentUser(['admin']);
    $id = (int)($params['id'] ?? 0);
    $input = wmsInput();
    $action = trim((string)($input['action'] ?? ''));

    if (!in_array($action, ['approve', 'reject'], true)) {
        wmsJsonError('action must be "approve" or "reject".');
    }

    $adj = wmsDb()->query('SELECT id, status FROM wms_inventory_adjustments WHERE id = :id', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    if (!$adj) wmsJsonError('Adjustment not found.', 404);
    if ($adj['status'] !== 'pending') wmsJsonError('Adjustment already reviewed.');

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    wmsDb()->execute(
        'UPDATE wms_inventory_adjustments SET status = :status, approved_by = :uid, approved_at = NOW() WHERE id = :id',
        [':status' => $newStatus, ':uid' => (int)$user['id'], ':id' => $id]
    );

    wmsJsonOk(['adjustment_id' => $id, 'status' => $newStatus]);
}
