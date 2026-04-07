<?php

declare(strict_types=1);

use PDO;
use Throwable;

function wmsBatchWhereClause(?int $batchId): array
{
    if ($batchId === null || $batchId <= 0) {
        return ['(batch_id IS NULL OR batch_id = 0)', []];
    }

    return ['batch_id = ?', [$batchId]];
}

function wmsStockGet(int $productId, int $locationId, ?int $batchId = null): ?array
{
    [$batchWhere, $batchParams] = wmsBatchWhereClause($batchId);
    $params = array_merge([$productId, $locationId], $batchParams);

    return wmsFetchOne(
        'SELECT * FROM wms_stocks WHERE product_id = ? AND location_id = ? AND ' . $batchWhere . ' LIMIT 1',
        $params
    );
}

function wmsEnsureStockRow(int $productId, int $warehouseId, int $locationId, ?int $batchId = null): array
{
    $existing = wmsStockGet($productId, $locationId, $batchId);
    if ($existing !== null) {
        return $existing;
    }

    try {
        wmsDb()->execute(
            'INSERT IGNORE INTO wms_stocks (product_id, warehouse_id, location_id, batch_id, qty_on_hand, qty_reserved)
             VALUES (?, ?, ?, ?, 0, 0)',
            [$productId, $warehouseId, $locationId, $batchId]
        );
    } catch (Throwable $e) {}

    return wmsStockGet($productId, $locationId, $batchId) ?? [
        'id' => 0,
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
        'location_id' => $locationId,
        'batch_id' => $batchId,
        'qty_on_hand' => 0,
        'qty_reserved' => 0,
        'qty_available' => 0,
    ];
}

function wmsUpdateStockLevels(int $stockId, float $qtyOnHand, float $qtyReserved): void
{
    wmsDb()->execute(
        'UPDATE wms_stocks SET qty_on_hand = ?, qty_reserved = ?, updated_at = NOW() WHERE id = ?',
        [wmsNormalizeDecimal($qtyOnHand), wmsNormalizeDecimal($qtyReserved), $stockId]
    );
}

function wmsStockSnapshot(int $warehouseId = 0, array $filters = []): array
{
    $where = ['1=1'];
    $params = [];

    if ($warehouseId > 0) {
        $where[] = 's.warehouse_id = ?';
        $params[] = $warehouseId;
    }

    $productId = (int)($filters['product_id'] ?? 0);
    if ($productId > 0) {
        $where[] = 's.product_id = ?';
        $params[] = $productId;
    }

    $locationId = (int)($filters['location_id'] ?? 0);
    if ($locationId > 0) {
        $where[] = 's.location_id = ?';
        $params[] = $locationId;
    }

    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
        $params[] = wmsSqlLike($q);
        $params[] = wmsSqlLike($q);
        $params[] = wmsSqlLike($q);
    }

    return wmsFetchAll(
        'SELECT s.id, s.product_id, s.warehouse_id, s.location_id, s.batch_id,
                s.qty_on_hand, s.qty_reserved, s.qty_available, s.updated_at,
                p.sku, p.name AS product_name, p.barcode,
                w.code AS warehouse_code, w.name AS warehouse_name,
                l.code AS location_code, l.name AS location_name,
                b.batch_number, b.expires_at
         FROM wms_stocks s
         INNER JOIN wms_products p ON p.id = s.product_id
         INNER JOIN wms_warehouses w ON w.id = s.warehouse_id
         INNER JOIN wms_locations l ON l.id = s.location_id
         LEFT JOIN wms_batches b ON b.id = s.batch_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY p.name ASC, w.name ASC, l.code ASC',
        $params
    );
}

function wmsMovementsList(array $filters = []): array
{
    $where = ['1=1'];
    $params = [];

    $productId = (int)($filters['product_id'] ?? 0);
    if ($productId > 0) {
        $where[] = 'm.product_id = ?';
        $params[] = $productId;
    }

    $warehouseId = (int)($filters['warehouse_id'] ?? 0);
    if ($warehouseId > 0) {
        $where[] = 'm.warehouse_id = ?';
        $params[] = $warehouseId;
    }

    $type = trim((string)($filters['movement_type'] ?? ''));
    if ($type !== '') {
        $where[] = 'm.movement_type = ?';
        $params[] = $type;
    }

    $dateFrom = trim((string)($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $where[] = 'DATE(m.created_at) >= ?';
        $params[] = $dateFrom;
    }

    $dateTo = trim((string)($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $where[] = 'DATE(m.created_at) <= ?';
        $params[] = $dateTo;
    }

    return wmsFetchAll(
        'SELECT m.*, p.sku, p.name AS product_name, l.code AS location_code, w.name AS warehouse_name, b.batch_number
         FROM wms_movements m
         INNER JOIN wms_products p ON p.id = m.product_id
         INNER JOIN wms_locations l ON l.id = m.location_id
         INNER JOIN wms_warehouses w ON w.id = m.warehouse_id
         LEFT JOIN wms_batches b ON b.id = m.batch_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT 500',
        $params
    );
}

function wmsLowStockItems(int $warehouseId = 0, ?int $threshold = null): array
{
    $settings = wmsSettings();
    $threshold = $threshold ?? (int)($settings['low_stock_threshold'] ?? 10);
    if ($threshold < 0) {
        $threshold = 0;
    }

    $where = ['s.qty_available <= ?'];
    $params = [$threshold];

    if ($warehouseId > 0) {
        $where[] = 's.warehouse_id = ?';
        $params[] = $warehouseId;
    }

    return wmsFetchAll(
        'SELECT s.product_id, s.warehouse_id, SUM(s.qty_available) AS qty_available,
                p.sku, p.name AS product_name, w.name AS warehouse_name
         FROM wms_stocks s
         INNER JOIN wms_products p ON p.id = s.product_id
         INNER JOIN wms_warehouses w ON w.id = s.warehouse_id
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY s.product_id, s.warehouse_id, p.sku, p.name, w.name
         ORDER BY qty_available ASC, p.name ASC',
        $params
    );
}

function wmsMaybeFireLowStockEvent(int $warehouseId, int $productId): void
{
    try {
        $items = wmsLowStockItems($warehouseId);
        foreach ($items as $item) {
            if ((int)($item['product_id'] ?? 0) !== $productId) {
                continue;
            }
            wmsCtx()->fireEvent('wms.stock.low', [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'qty_available' => (float)($item['qty_available'] ?? 0),
            ]);
            break;
        }
    } catch (Throwable $e) {
        wmsCtx()->log('wms low stock event failed: ' . $e->getMessage(), 'warning');
    }
}

function wmsMovementCreate(array $data): int
{
    $movementType = wmsSanitizeString($data['movement_type'] ?? '', 40);
    if (!in_array($movementType, wmsMovementTypes(), true)) {
        throw new RuntimeException('Invalid movement type.');
    }

    $productId = wmsRequirePositiveId((int)($data['product_id'] ?? 0), 'Product ID');
    $warehouseId = wmsRequirePositiveId((int)($data['warehouse_id'] ?? 0), 'Warehouse ID');
    $locationId = wmsRequirePositiveId((int)($data['location_id'] ?? 0), 'Location ID');
    $batchId = isset($data['batch_id']) && (int)$data['batch_id'] > 0 ? (int)$data['batch_id'] : null;
    $qty = wmsNormalizeDecimal($data['qty'] ?? 0);
    if ($qty == 0.0) {
        throw new RuntimeException('Movement quantity must not be zero.');
    }

    $idempotencyKey = isset($data['idempotency_key']) ? wmsSanitizeString($data['idempotency_key'], 100) : null;

    $db = wmsDb();
    $started = false;
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $started = true;
    }

    try {
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existingMovement = wmsFetchOne('SELECT movement_id FROM wms_idempotency_keys WHERE idempotency_key = ? LIMIT 1 FOR UPDATE', [$idempotencyKey]);
            if ($existingMovement !== null) {
                if ($started) {
                    $db->rollBack();
                }
                return (int)$existingMovement['movement_id'];
            }
        }

        $product = wmsFetchOne('SELECT is_batch_tracked FROM wms_products WHERE id = ? LIMIT 1', [$productId]);
        if ($product === null) {
            throw new RuntimeException('Product not found.');
        }
        if ((int)$product['is_batch_tracked'] === 1 && $batchId === null && $movementType !== 'adjustment') {
            throw new RuntimeException('Batch ID is required for batch-tracked product movements.');
        }

        $settings = wmsSettings();
        $allowNegative = (bool)($settings['allow_negative_stock'] ?? false);

        // Ensure stock row exists before locking
        wmsEnsureStockRow($productId, $warehouseId, $locationId, $batchId);

        // Fetch FOR UPDATE to lock the row exclusively and prevent race conditions
        [$batchWhere, $batchParams] = wmsBatchWhereClause($batchId);
        $stock = wmsFetchOne(
            'SELECT * FROM wms_stocks WHERE product_id = ? AND location_id = ? AND ' . $batchWhere . ' LIMIT 1 FOR UPDATE',
            array_merge([$productId, $locationId], $batchParams)
        );

        $stockId = (int)($stock['id'] ?? 0);
        $qtyBefore = wmsNormalizeDecimal($stock['qty_on_hand'] ?? 0);
        $reservedBefore = wmsNormalizeDecimal($stock['qty_reserved'] ?? 0);
        $qtyAfter = $qtyBefore;
        $reservedAfter = $reservedBefore;

        if (in_array($movementType, ['reserved', 'unreserved'], true)) {
            if ($movementType === 'reserved') {
                if (!$allowNegative && ($qtyBefore - $reservedBefore) < $qty) {
                    throw new RuntimeException('Insufficient available stock to reserve.');
                }
                $reservedAfter = wmsNormalizeDecimal($reservedBefore + $qty);
            } else {
                if ($reservedBefore < abs($qty)) {
                    throw new RuntimeException('Insufficient reserved stock to release.');
                }
                $reservedAfter = wmsNormalizeDecimal($reservedBefore - abs($qty));
            }
        } else {
            $qtyAfter = wmsNormalizeDecimal($qtyBefore + $qty);
            if (!$allowNegative && $qtyAfter < 0) {
                throw new RuntimeException('Insufficient stock for movement. Negative stock is disabled.');
            }
            if (in_array($movementType, ['out', 'transfer_out', 'cycle_count_adjustment'], true) && $qty < 0 && $reservedBefore > 0) {
                $reservedAfter = max(0.0, wmsNormalizeDecimal($reservedBefore + $qty));
            }
        }

        $db->execute(
            'INSERT INTO wms_movements
             (movement_type, reference_type, reference_id, product_id, warehouse_id, location_id, batch_id, qty, qty_before, qty_after, unit_cost, notes, actor_user_id, meta, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $movementType,
                ($data['reference_type'] ?? null) !== null ? wmsSanitizeString($data['reference_type'], 50) : null,
                isset($data['reference_id']) ? (int)$data['reference_id'] : null,
                $productId,
                $warehouseId,
                $locationId,
                $batchId,
                $qty,
                $qtyBefore,
                $qtyAfter,
                isset($data['unit_cost']) ? wmsNormalizeDecimal($data['unit_cost']) : null,
                ($data['notes'] ?? null) !== null ? wmsSanitizeString($data['notes'], 2000) : null,
                isset($data['actor_user_id']) ? (int)$data['actor_user_id'] : null,
                isset($data['meta']) ? json_encode($data['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
        $movementId = (int)$db->lastInsertId();

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $db->execute(
                'INSERT INTO wms_idempotency_keys (idempotency_key, movement_id, created_at) VALUES (?, ?, NOW())',
                [$idempotencyKey, $movementId]
            );
        }

        wmsUpdateStockLevels($stockId, $qtyAfter, $reservedAfter);

        if ($started) {
            $db->commit();
        }

        wmsAudit('wms.movement.created', 'wms_movements', (string)$movementId, null, [
            'movement_type' => $movementType,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'batch_id' => $batchId,
            'qty' => $qty,
            'qty_before' => $qtyBefore,
            'qty_after' => $qtyAfter,
            'qty_reserved_after' => $reservedAfter,
        ]);

        try {
            wmsCtx()->fireEvent('wms.movement.created', [
                'movement_id' => $movementId,
                'movement_type' => $movementType,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'batch_id' => $batchId,
                'qty' => $qty,
                'qty_after' => $qtyAfter,
            ]);
        } catch (Throwable $e) {
            wmsCtx()->log('wms movement event failed: ' . $e->getMessage(), 'warning');
        }

        if (!in_array($movementType, ['reserved', 'unreserved'], true)) {
            wmsMaybeFireLowStockEvent($warehouseId, $productId);
        }

        return $movementId;
    } catch (Throwable $e) {
        if ($started && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function wmsReserveStock(array $item): int
{
    $qty = wmsNormalizeDecimal($item['qty'] ?? 0);
    if ($qty <= 0) {
        throw new RuntimeException('Reserve quantity must be greater than zero.');
    }
    if (!isset($item['reference_type']) || !isset($item['reference_id'])) {
        throw new RuntimeException('Reservations must include reference_type and reference_id for traceability.');
    }

    $item['movement_type'] = 'reserved';
    $item['qty'] = $qty;

    return wmsMovementCreate($item);
}

function wmsReleaseStock(array $item): int
{
    $qty = wmsNormalizeDecimal($item['qty'] ?? 0);
    if ($qty <= 0) {
        throw new RuntimeException('Release quantity must be greater than zero.');
    }
    if (!isset($item['reference_type']) || !isset($item['reference_id'])) {
        throw new RuntimeException('Releases must include reference_type and reference_id for traceability.');
    }

    $item['movement_type'] = 'unreserved';
    $item['qty'] = -$qty;

    return wmsMovementCreate($item);
}
