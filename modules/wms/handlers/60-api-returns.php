<?php

declare(strict_types=1);

function wmsApiReturnsList(array $params = []): void
{
    wmsResponseGuard(function (): void {
        wmsRequireStaff(['admin', 'supervisor', 'viewer']);
        $where = ['r.deleted_at IS NULL'];
        $bind = [];
        $status = wmsSanitizeString(wmsInput('status', ''), 30);
        if ($status !== '') {
            $where[] = 'r.status = ?';
            $bind[] = $status;
        }
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        if ($warehouseId > 0) {
            $where[] = 'r.warehouse_id = ?';
            $bind[] = $warehouseId;
        }
        wmsJsonOk(['data' => wmsFetchAll(
            'SELECT r.*, w.name AS warehouse_name FROM wms_returns r
             INNER JOIN wms_warehouses w ON w.id = r.warehouse_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY r.created_at DESC LIMIT 200',
            $bind
        )]);
    });
}

function wmsApiReturnGet(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        wmsRequireStaff(['admin', 'supervisor', 'viewer']);
        $id = (int)($params['id'] ?? 0);
        $return = wmsFetchOne('SELECT r.*, w.name AS warehouse_name FROM wms_returns r INNER JOIN wms_warehouses w ON w.id = r.warehouse_id WHERE r.id = ? AND r.deleted_at IS NULL LIMIT 1', [$id]);
        if ($return === null) {
            wmsJsonError('Return not found.', 404);
        }
        $return['items'] = wmsFetchAll(
            'SELECT ri.*, p.sku, p.name AS product_name, l.code AS location_code, b.batch_number
             FROM wms_return_items ri
             INNER JOIN wms_products p ON p.id = ri.product_id
             INNER JOIN wms_locations l ON l.id = ri.location_id
             LEFT JOIN wms_batches b ON b.id = ri.batch_id
             WHERE ri.return_id = ? ORDER BY ri.id ASC',
            [$id]
        );
        wmsJsonOk(['data' => $return]);
    });
}

function wmsApiReturnCreate(array $params = []): void
{
    wmsResponseGuard(function (): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);
        $referenceNumber = wmsSanitizeString(wmsInput('reference_number', ''), 100);
        $warehouseId = (int)wmsInput('warehouse_id', 0);
        $items = wmsRequestBodyItems('items');
        if ($referenceNumber === '' || $warehouseId <= 0 || $items === []) {
            wmsJsonError('Reference number, warehouse, and items are required.', 422);
        }

        $db = wmsDb();
        $db->beginTransaction();
        try {
            $db->execute(
                'INSERT INTO wms_returns (reference_number, order_id, customer_name, warehouse_id, status, reason, received_at, notes, meta, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW(), NOW())',
                [
                    $referenceNumber,
                    ($orderId = (int)wmsInput('order_id', 0)) > 0 ? $orderId : null,
                    wmsSanitizeString(wmsInput('customer_name', ''), 255) ?: null,
                    $warehouseId,
                    'pending',
                    wmsSanitizeString(wmsInput('reason', ''), 500) ?: null,
                    wmsSanitizeString(wmsInput('notes', ''), 5000) ?: null,
                    ($meta = wmsJsonDecodeArray(wmsInput('meta', []))) !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    (int)($user['id'] ?? 0),
                ]
            );
            $returnId = (int)$db->lastInsertId();

            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $locationId = (int)($item['location_id'] ?? 0);
                $qtyReturned = wmsNormalizeDecimal($item['qty_returned'] ?? 0);
                if ($productId <= 0 || $locationId <= 0 || $qtyReturned <= 0) {
                    throw new RuntimeException('Each return item requires product, location, and quantity.');
                }
                $condition = in_array($item['condition'] ?? '', ['good', 'damaged', 'expired', 'unknown'], true) ? $item['condition'] : 'unknown';
                $db->execute(
                    'INSERT INTO wms_return_items (return_id, product_id, location_id, batch_id, qty_returned, qty_restocked, `condition`, notes, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 0, ?, ?, NOW(), NOW())',
                    [
                        $returnId,
                        $productId,
                        $locationId,
                        isset($item['batch_id']) && (int)$item['batch_id'] > 0 ? (int)$item['batch_id'] : null,
                        $qtyReturned,
                        $condition,
                        wmsSanitizeString($item['notes'] ?? '', 500) ?: null,
                    ]
                );
            }

            $db->commit();
            wmsAudit('wms.return.created', 'wms_returns', (string)$returnId, null, ['reference_number' => $referenceNumber]);
            wmsJsonOk(['id' => $returnId], 201);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    });
}

function wmsApiReturnRestock(array $params = []): void
{
    wmsResponseGuard(function () use ($params): void {
        $user = wmsRequireStaff(['admin', 'supervisor']);
        $returnId = (int)($params['id'] ?? 0);
        $return = wmsFetchOne('SELECT * FROM wms_returns WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$returnId]);
        if ($return === null) {
            wmsJsonError('Return not found.', 404);
        }
        if (in_array((string)$return['status'], ['restocked', 'cancelled'], true)) {
            wmsJsonError('Return is already ' . $return['status'] . '.', 409);
        }

        $items = wmsFetchAll('SELECT * FROM wms_return_items WHERE return_id = ? ORDER BY id ASC', [$returnId]);
        if ($items === []) {
            wmsJsonError('Return has no items.', 422);
        }

        $movementIds = [];
        $db = wmsDb();
        $db->beginTransaction();
        try {
            foreach ($items as $item) {
                if ((string)($item['condition'] ?? '') === 'damaged' || (string)($item['condition'] ?? '') === 'expired') {
                    continue;
                }
                $qty = wmsNormalizeDecimal($item['qty_returned'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $movementId = wmsMovementCreate([
                    'movement_type' => 'in',
                    'reference_type' => 'return',
                    'reference_id' => $returnId,
                    'product_id' => (int)$item['product_id'],
                    'warehouse_id' => (int)$return['warehouse_id'],
                    'location_id' => (int)$item['location_id'],
                    'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                    'qty' => $qty,
                    'notes' => 'Return restock: ' . (string)$return['reference_number'],
                    'actor_user_id' => (int)($user['id'] ?? 0),
                    'meta' => ['return_item_id' => (int)$item['id'], 'condition' => $item['condition']],
                ]);
                $movementIds[] = $movementId;

                $db->execute(
                    'UPDATE wms_return_items SET qty_restocked = ?, restock_movement_id = ?, updated_at = NOW() WHERE id = ?',
                    [wmsNormalizeDecimal($qty), $movementId, (int)$item['id']]
                );
            }

            $db->execute(
                'UPDATE wms_returns SET status = ?, updated_at = NOW() WHERE id = ?',
                ['restocked', $returnId]
            );
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        wmsAudit('wms.return.restocked', 'wms_returns', (string)$returnId, $return, ['movement_ids' => $movementIds]);
        wmsJsonOk(['return_id' => $returnId, 'movement_ids' => $movementIds]);
    });
}
