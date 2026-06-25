<?php

declare(strict_types=1);

// ── Cycle Counts ──

function wmsApiCycleCountsList(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));

    $sql = 'SELECT cc.*, w.code AS warehouse_code,
                   a.full_name AS assigned_name, c.full_name AS created_name
            FROM wms_cycle_counts cc
            JOIN wms_warehouses w ON w.id = cc.warehouse_id
            LEFT JOIN wms_users a ON a.id = cc.assigned_to
            LEFT JOIN wms_users c ON c.id = cc.created_by
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND cc.status = :status'; $params[':status'] = $status; }
    $sql .= ' ORDER BY cc.created_at DESC LIMIT 100';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['cycle_counts' => $rows]);
}

function wmsApiCycleCountGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);

    $cc = wmsDb()->query(
        'SELECT cc.*, w.code AS warehouse_code, a.full_name AS assigned_name
         FROM wms_cycle_counts cc
         JOIN wms_warehouses w ON w.id = cc.warehouse_id
         LEFT JOIN wms_users a ON a.id = cc.assigned_to
         WHERE cc.id = :id',
        [':id' => $id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$cc) wmsJsonError('Cycle count not found.', 404);

    $items = wmsDb()->query(
        'SELECT cci.*, p.sku, p.name AS product_name, p.unit, l.code AS location_code, b.batch_number
         FROM wms_cycle_count_items cci
         JOIN wms_products p ON p.id = cci.product_id
         LEFT JOIN wms_locations l ON l.id = cci.location_id
         LEFT JOIN wms_batches b ON b.id = cci.batch_id
         WHERE cci.cycle_count_id = :cid ORDER BY cci.id ASC',
        [':cid' => $id]
    )->fetchAll(\PDO::FETCH_ASSOC);

    wmsJsonOk(['cycle_count' => $cc, 'items' => $items]);
}

function wmsApiCycleCountCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $countType = trim((string)($input['count_type'] ?? 'full'));
    $assignedTo = isset($input['assigned_to']) ? (int)$input['assigned_to'] : null;
    $scheduledAt = trim((string)($input['scheduled_at'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $items = $input['items'] ?? [];

    if ($warehouseId <= 0) wmsJsonError('warehouse_id is required.');

    $countNumber = 'CC-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    wmsDb()->beginTransaction();
    try {
        wmsDb()->execute(
            'INSERT INTO wms_cycle_counts (warehouse_id, count_number, count_type, status, assigned_to, scheduled_at, notes, created_by)
             VALUES (:wid, :cn, :ct, :status, :as, :sa, :notes, :uid)',
            [
                ':wid' => $warehouseId, ':cn' => $countNumber, ':ct' => $countType,
                ':status' => 'open', ':as' => $assignedTo,
                ':sa' => $scheduledAt ?: null, ':notes' => $notes ?: null,
                ':uid' => (int)$user['id'],
            ]
        );
        $countId = (int)wmsDb()->lastInsertId();

        if (is_array($items) && count($items) > 0) {
            foreach ($items as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $lid = isset($item['location_id']) ? (int)$item['location_id'] : null;
                $bid = isset($item['batch_id']) ? (int)$item['batch_id'] : null;
                $eq = (float)($item['expected_qty'] ?? 0);
                if ($pid <= 0) continue;

                wmsDb()->execute(
                    'INSERT INTO wms_cycle_count_items (cycle_count_id, product_id, location_id, batch_id, expected_qty, status)
                     VALUES (:cid, :pid, :lid, :bid, :eq, :status)',
                    [':cid' => $countId, ':pid' => $pid, ':lid' => $lid, ':bid' => $bid, ':eq' => $eq, ':status' => 'pending']
                );
            }
        } else {
            // Auto-populate with all stock in warehouse
            $stockItems = wmsDb()->query(
                'SELECT product_id, location_id, batch_id, qty_on_hand FROM wms_stock WHERE warehouse_id = :wid AND qty_on_hand > 0 ORDER BY product_id',
                [':wid' => $warehouseId]
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($stockItems as $si) {
                wmsDb()->execute(
                    'INSERT INTO wms_cycle_count_items (cycle_count_id, product_id, location_id, batch_id, expected_qty, status)
                     VALUES (:cid, :pid, :lid, :bid, :eq, :status)',
                    [
                        ':cid' => $countId, ':pid' => $si['product_id'],
                        ':lid' => $si['location_id'], ':bid' => $si['batch_id'],
                        ':eq' => $si['qty_on_hand'], ':status' => 'pending',
                    ]
                );
            }
        }

        wmsDb()->commit();
        wmsJsonOk(['cycle_count_id' => $countId, 'count_number' => $countNumber], 201);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

function wmsApiCycleCountRecord(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor', 'picker']);
    $id = (int)($params['id'] ?? 0);
    $itemId = (int)($params['item_id'] ?? 0);
    $input = wmsInput();

    $countedQty = (float)($input['counted_qty'] ?? -1);
    if ($countedQty < 0) wmsJsonError('counted_qty is required.');

    $item = wmsDb()->query(
        'SELECT cci.*, cc.status AS cc_status FROM wms_cycle_count_items cci
         JOIN wms_cycle_counts cc ON cc.id = cci.cycle_count_id
         WHERE cci.id = :id AND cci.cycle_count_id = :cid',
        [':id' => $itemId, ':cid' => $id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$item) wmsJsonError('Cycle count item not found.', 404);
    if ($item['cc_status'] === 'completed') wmsJsonError('Cycle count already completed.');

    wmsDb()->execute(
        'UPDATE wms_cycle_count_items SET counted_qty = :cq, status = :status, counted_at = NOW() WHERE id = :id',
        [':cq' => $countedQty, ':status' => 'counted', ':id' => $itemId]
    );

    wmsJsonOk(['cycle_count_item_id' => $itemId, 'counted_qty' => $countedQty]);
}

function wmsApiCycleCountComplete(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);
    $input = wmsInput();
    $applyAdjustments = ($input['apply_adjustments'] ?? '1') === '1';

    $cc = wmsDb()->query('SELECT id, warehouse_id, status FROM wms_cycle_counts WHERE id = :id', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    if (!$cc) wmsJsonError('Cycle count not found.', 404);
    if ($cc['status'] === 'completed') wmsJsonError('Already completed.');

    $warehouseId = (int)$cc['warehouse_id'];

    wmsDb()->beginTransaction();
    try {
        $items = wmsDb()->query(
            'SELECT * FROM wms_cycle_count_items WHERE cycle_count_id = :cid AND counted_qty IS NOT NULL',
            [':cid' => $id]
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $expected = (float)$item['expected_qty'];
            $counted = (float)$item['counted_qty'];
            $diff = $counted - $expected;
            if ($diff == 0) continue;

            if ($applyAdjustments) {
                // Update stock
                $stock = wmsDb()->query(
                    'SELECT id, qty_on_hand FROM wms_stock WHERE product_id = :pid AND warehouse_id = :wid
                     AND (location_id = :lid OR (:lid_null IS NULL AND location_id IS NULL))
                     AND (batch_id = :bid OR (:bid_null IS NULL AND batch_id IS NULL))',
                    [':pid' => $item['product_id'], ':wid' => $warehouseId,
                     ':lid' => $item['location_id'], ':lid_null' => $item['location_id'], ':bid' => $item['batch_id'], ':bid_null' => $item['batch_id']]
                )->fetch(\PDO::FETCH_ASSOC);

                if ($stock) {
                    wmsDb()->execute(
                        'UPDATE wms_stock SET qty_on_hand = qty_on_hand + :diff, last_movement_at = NOW() WHERE id = :id',
                        [':diff' => $diff, ':id' => $stock['id']]
                    );
                    $prevQty = (float)$stock['qty_on_hand'];
                    $newQty = $prevQty + $diff;
                    $stockId = (int)$stock['id'];
                } else {
                    $prevQty = 0;
                    $newQty = $counted;
                    wmsDb()->execute(
                        'INSERT INTO wms_stock (product_id, warehouse_id, location_id, batch_id, qty_on_hand, last_movement_at)
                         VALUES (:pid, :wid, :lid, :bid, :qty, NOW())',
                        [':pid' => $item['product_id'], ':wid' => $warehouseId,
                         ':lid' => $item['location_id'], ':bid' => $item['batch_id'], ':qty' => $counted]
                    );
                    $stockId = (int)wmsDb()->lastInsertId();
                }

                if ($diff != 0) {
                    wmsDb()->execute(
                        'INSERT INTO wms_stock_movements (product_id, warehouse_id, to_location_id, batch_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reference_type, reference_id, reason, created_by)
                         VALUES (:pid, :wid, :lid, :bid, :type, :qty, :prev, :new, :rtype, :rid, :reason, :uid)',
                        [
                            ':pid' => $item['product_id'], ':wid' => $warehouseId,
                            ':lid' => $item['location_id'], ':bid' => $item['batch_id'],
                            ':type' => $diff > 0 ? 'adjustment_up' : 'adjustment_down',
                            ':qty' => $diff, ':prev' => $prevQty, ':new' => $newQty,
                            ':rtype' => 'cycle_count', ':rid' => $id,
                            ':reason' => 'Cycle count adjustment',
                            ':uid' => (int)$user['id'],
                        ]
                    );
                }

                // Record inventory adjustment
                wmsDb()->execute(
                    'INSERT INTO wms_inventory_adjustments (product_id, warehouse_id, location_id, batch_id, adjustment_type, expected_qty, counted_qty, reason, status, created_by, counted_at)
                     VALUES (:pid, :wid, :lid, :bid, :atype, :expected, :counted, :reason, :status, :uid, NOW())',
                    [
                        ':pid' => $item['product_id'], ':wid' => $warehouseId,
                        ':lid' => $item['location_id'], ':bid' => $item['batch_id'],
                        ':atype' => 'cycle_count', ':expected' => $expected,
                        ':counted' => $counted, ':reason' => 'Cycle count #' . $id,
                        ':status' => 'approved', ':uid' => (int)$user['id'],
                    ]
                );
            }

            wmsDb()->execute(
                'UPDATE wms_cycle_count_items SET status = :status WHERE id = :id',
                [':status' => 'resolved', ':id' => $item['id']]
            );
        }

        wmsDb()->execute(
            'UPDATE wms_cycle_counts SET status = :status, completed_at = NOW() WHERE id = :id',
            [':status' => 'completed', ':id' => $id]
        );

        wmsDb()->commit();
        wmsJsonOk(['cycle_count_id' => $id, 'status' => 'completed', 'adjustments_applied' => $applyAdjustments]);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

// ── Returns ──

function wmsApiReturnsList(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));

    $sql = 'SELECT r.*, w.code AS warehouse_code, u.full_name AS created_name
            FROM wms_returns r
            JOIN wms_warehouses w ON w.id = r.warehouse_id
            LEFT JOIN wms_users u ON u.id = r.created_by
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND r.status = :status'; $params[':status'] = $status; }
    $sql .= ' ORDER BY r.created_at DESC LIMIT 100';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['returns' => $rows]);
}

function wmsApiReturnGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);

    $r = wmsDb()->query(
        'SELECT r.*, w.code AS warehouse_code, u.full_name AS created_name
         FROM wms_returns r
         JOIN wms_warehouses w ON w.id = r.warehouse_id
         LEFT JOIN wms_users u ON u.id = r.created_by
         WHERE r.id = :id',
        [':id' => $id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$r) wmsJsonError('Return not found.', 404);

    $items = wmsDb()->query(
        'SELECT ri.*, p.sku, p.name AS product_name, p.unit, b.batch_number
         FROM wms_return_items ri
         JOIN wms_products p ON p.id = ri.product_id
         LEFT JOIN wms_batches b ON b.id = ri.batch_id
         WHERE ri.return_id = :rid ORDER BY ri.id ASC',
        [':rid' => $id]
    )->fetchAll(\PDO::FETCH_ASSOC);

    wmsJsonOk(['return' => $r, 'items' => $items]);
}

function wmsApiReturnCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $orderId = isset($input['order_id']) ? (int)$input['order_id'] : null;
    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $returnType = trim((string)($input['return_type'] ?? 'customer'));
    $customerName = trim((string)($input['customer_name'] ?? ''));
    $customerEmail = trim((string)($input['customer_email'] ?? ''));
    $reason = trim((string)($input['reason'] ?? ''));
    $disposition = trim((string)($input['disposition'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $items = $input['items'] ?? [];

    if ($warehouseId <= 0) wmsJsonError('warehouse_id is required.');
    if (!is_array($items) || count($items) === 0) wmsJsonError('At least one item is required.');

    $returnNumber = 'RMA-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    wmsDb()->beginTransaction();
    try {
        wmsDb()->execute(
            'INSERT INTO wms_returns (return_number, order_id, warehouse_id, return_type, status, customer_name, customer_email, reason, disposition, notes, created_by)
             VALUES (:rn, :oid, :wid, :rt, :status, :cn, :ce, :reason, :disp, :notes, :uid)',
            [
                ':rn' => $returnNumber, ':oid' => $orderId, ':wid' => $warehouseId,
                ':rt' => $returnType, ':status' => 'pending',
                ':cn' => $customerName ?: null, ':ce' => $customerEmail ?: null,
                ':reason' => $reason ?: null, ':disp' => $disposition ?: null,
                ':notes' => $notes ?: null, ':uid' => (int)$user['id'],
            ]
        );
        $returnId = (int)wmsDb()->lastInsertId();

        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 0);
            $cond = trim((string)($item['condition'] ?? ''));
            $disp = trim((string)($item['disposition'] ?? ''));
            if ($pid <= 0 || $qty <= 0) continue;

            wmsDb()->execute(
                'INSERT INTO wms_return_items (return_id, product_id, batch_id, quantity, `condition`, disposition, notes)
                 VALUES (:rid, :pid, :bid, :qty, :cond, :disp, :notes)',
                [
                    ':rid' => $returnId, ':pid' => $pid,
                    ':bid' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                    ':qty' => $qty, ':cond' => $cond ?: null,
                    ':disp' => $disp ?: null, ':notes' => $item['notes'] ?? null,
                ]
            );
        }

        wmsDb()->commit();
        wmsJsonOk(['return_id' => $returnId, 'return_number' => $returnNumber], 201);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

function wmsApiReturnProcess(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);
    $input = wmsInput();

    $action = trim((string)($input['action'] ?? ''));
    $locationId = isset($input['location_id']) ? (int)$input['location_id'] : null;

    if (!in_array($action, ['approve', 'reject', 'restock', 'scrap'], true)) {
        wmsJsonError('action must be approve, reject, restock, or scrap.');
    }

    $ret = wmsDb()->query('SELECT * FROM wms_returns WHERE id = :id', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    if (!$ret) wmsJsonError('Return not found.', 404);

    wmsDb()->beginTransaction();
    try {
        if ($action === 'approve') {
            wmsDb()->execute(
                'UPDATE wms_returns SET status = :status, inspected_by = :uid, inspected_at = NOW() WHERE id = :id',
                [':status' => 'approved', ':uid' => (int)$user['id'], ':id' => $id]
            );
        } elseif ($action === 'reject') {
            wmsDb()->execute(
                'UPDATE wms_returns SET status = :status, inspected_by = :uid, inspected_at = NOW() WHERE id = :id',
                [':status' => 'rejected', ':uid' => (int)$user['id'], ':id' => $id]
            );
        } elseif ($action === 'restock' || $action === 'scrap') {
            $items = wmsDb()->query('SELECT * FROM wms_return_items WHERE return_id = :rid', [':rid' => $id])->fetchAll(\PDO::FETCH_ASSOC);
            $warehouseId = (int)$ret['warehouse_id'];

            foreach ($items as $item) {
                $qty = (float)$item['quantity'];

                if ($action === 'restock') {
                    $stock = wmsDb()->query(
                        'SELECT id, qty_on_hand FROM wms_stock WHERE product_id = :pid AND warehouse_id = :wid
                         AND (location_id = :lid OR (:lid_null IS NULL AND location_id IS NULL))
                         AND (batch_id = :bid OR (:bid_null IS NULL AND batch_id IS NULL))',
                        [':pid' => $item['product_id'], ':wid' => $warehouseId, ':lid' => $locationId, ':lid_null' => $locationId, ':bid' => $item['batch_id'], ':bid_null' => $item['batch_id']]
                    )->fetch(\PDO::FETCH_ASSOC);

                    if ($stock) {
                        $prevQty = (float)$stock['qty_on_hand'];
                        $newQty = $prevQty + $qty;
                        wmsDb()->execute('UPDATE wms_stock SET qty_on_hand = :qty, last_movement_at = NOW() WHERE id = :id', [':qty' => $newQty, ':id' => $stock['id']]);
                    } else {
                        $prevQty = 0;
                        $newQty = $qty;
                        wmsDb()->execute(
                            'INSERT INTO wms_stock (product_id, warehouse_id, location_id, batch_id, qty_on_hand, last_movement_at)
                             VALUES (:pid, :wid, :lid, :bid, :qty, NOW())',
                            [':pid' => $item['product_id'], ':wid' => $warehouseId, ':lid' => $locationId, ':bid' => $item['batch_id'], ':qty' => $qty]
                        );
                    }

                    wmsDb()->execute(
                        'INSERT INTO wms_stock_movements (product_id, warehouse_id, to_location_id, batch_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reference_type, reference_id, notes, created_by)
                         VALUES (:pid, :wid, :lid, :bid, :type, :qty, :prev, :new, :rtype, :rid, :notes, :uid)',
                        [
                            ':pid' => $item['product_id'], ':wid' => $warehouseId, ':lid' => $locationId,
                            ':bid' => $item['batch_id'], ':type' => 'return_in', ':qty' => $qty,
                            ':prev' => $prevQty, ':new' => $newQty,
                            ':rtype' => 'return', ':rid' => $id,
                            ':notes' => 'Return restock', ':uid' => (int)$user['id'],
                        ]
                    );
                }

                $disp = $action === 'restock' ? 'restock' : 'scrap';
                wmsDb()->execute('UPDATE wms_return_items SET disposition = :disp WHERE id = :id', [':disp' => $disp, ':id' => $item['id']]);
            }

            wmsDb()->execute(
                'UPDATE wms_returns SET status = :status, disposition = :disp, inspected_by = :uid, inspected_at = NOW() WHERE id = :id',
                [':status' => $action === 'restock' ? 'restocked' : 'disposed', ':disp' => $action,
                 ':uid' => (int)$user['id'], ':id' => $id]
            );
        }

        wmsDb()->commit();
        wmsJsonOk(['return_id' => $id, 'status' => $ret['status']]);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

// ── Tasks ──

function wmsApiTasksList(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));
    $assignedTo = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;

    $sql = 'SELECT t.*, w.code AS warehouse_code,
                   a.full_name AS assigned_name, c.full_name AS created_name
            FROM wms_tasks t
            JOIN wms_warehouses w ON w.id = t.warehouse_id
            LEFT JOIN wms_users a ON a.id = t.assigned_to
            LEFT JOIN wms_users c ON c.id = t.created_by
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND t.status = :status'; $params[':status'] = $status; }
    if ($assignedTo) { $sql .= ' AND t.assigned_to = :as'; $params[':as'] = $assignedTo; }
    $sql .= ' ORDER BY t.priority ASC, t.created_at DESC LIMIT 100';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['tasks' => $rows]);
}

function wmsApiTaskGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);

    $task = wmsDb()->query(
        'SELECT t.*, w.code AS warehouse_code, a.full_name AS assigned_name
         FROM wms_tasks t
         JOIN wms_warehouses w ON w.id = t.warehouse_id
         LEFT JOIN wms_users a ON a.id = t.assigned_to
         WHERE t.id = :id',
        [':id' => $id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$task) wmsJsonError('Task not found.', 404);
    wmsJsonOk(['task' => $task]);
}

function wmsApiTaskCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $taskType = trim((string)($input['task_type'] ?? 'other'));
    $priority = trim((string)($input['priority'] ?? 'medium'));
    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $referenceType = trim((string)($input['reference_type'] ?? ''));
    $referenceId = isset($input['reference_id']) ? (int)$input['reference_id'] : null;
    $assignedTo = isset($input['assigned_to']) ? (int)$input['assigned_to'] : null;
    $dueAt = trim((string)($input['due_at'] ?? ''));

    if ($warehouseId <= 0 || $title === '') wmsJsonError('warehouse_id and title are required.');
    if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) wmsJsonError('Invalid priority.');

    wmsDb()->execute(
        'INSERT INTO wms_tasks (warehouse_id, task_type, status, priority, title, description, reference_type, reference_id, assigned_to, due_at, created_by)
         VALUES (:wid, :tt, :status, :pri, :title, :desc, :rt, :rid, :as, :due, :uid)',
        [
            ':wid' => $warehouseId, ':tt' => $taskType, ':status' => 'open',
            ':pri' => $priority, ':title' => $title, ':desc' => $description ?: null,
            ':rt' => $referenceType ?: null, ':rid' => $referenceId,
            ':as' => $assignedTo, ':due' => $dueAt ?: null,
            ':uid' => (int)$user['id'],
        ]
    );

    wmsJsonOk(['task_id' => (int)wmsDb()->lastInsertId()], 201);
}

function wmsApiTaskUpdate(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);
    $input = wmsInput();

    $task = wmsDb()->query('SELECT id, status FROM wms_tasks WHERE id = :id', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    if (!$task) wmsJsonError('Task not found.', 404);

    $updates = [];
    $vals = [':id' => $id];
    foreach (['title', 'description', 'priority', 'task_type', 'assigned_to', 'due_at'] as $f) {
        if (isset($input[$f])) { $updates[] = "$f = :$f"; $vals[":$f"] = $input[$f]; }
    }
    if (!empty($updates)) {
        wmsDb()->execute('UPDATE wms_tasks SET ' . implode(', ', $updates) . ' WHERE id = :id', $vals);
    }
    wmsJsonOk(['task_id' => $id]);
}

function wmsApiTaskComplete(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor', 'picker']);
    $id = (int)($params['id'] ?? 0);

    $task = wmsDb()->query('SELECT id, status FROM wms_tasks WHERE id = :id', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    if (!$task) wmsJsonError('Task not found.', 404);
    if ($task['status'] === 'completed') wmsJsonError('Task already completed.');

    wmsDb()->execute(
        'UPDATE wms_tasks SET status = :status, completed_by = :uid, completed_at = NOW() WHERE id = :id',
        [':status' => 'completed', ':uid' => (int)$user['id'], ':id' => $id]
    );
    wmsJsonOk(['task_id' => $id, 'status' => 'completed']);
}

// ── Recipes (BOM) ──

function wmsApiRecipesList(): void
{
    $user = wmsCurrentUser();

    $rows = wmsDb()->query(
        'SELECT r.*, p.sku, p.name AS product_name, p.unit, u.full_name AS created_name
         FROM wms_recipes r
         JOIN wms_products p ON p.id = r.product_id
         LEFT JOIN wms_users u ON u.id = r.created_by
         ORDER BY r.name ASC LIMIT 200'
    )->fetchAll(\PDO::FETCH_ASSOC);

    wmsJsonOk(['recipes' => $rows]);
}

function wmsApiRecipeGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);

    $recipe = wmsDb()->query(
        'SELECT r.*, p.sku, p.name AS product_name, p.unit
         FROM wms_recipes r
         JOIN wms_products p ON p.id = r.product_id
         WHERE r.id = :id',
        [':id' => $id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$recipe) wmsJsonError('Recipe not found.', 404);

    $ingredients = wmsDb()->query(
        'SELECT ri.*, p.sku, p.name AS ingredient_name, p.unit
         FROM wms_recipe_ingredients ri
         JOIN wms_products p ON p.id = ri.ingredient_product_id
         WHERE ri.recipe_id = :rid ORDER BY ri.sort_order ASC, ri.id ASC',
        [':rid' => $id]
    )->fetchAll(\PDO::FETCH_ASSOC);

    wmsJsonOk(['recipe' => $recipe, 'ingredients' => $ingredients]);
}

function wmsApiRecipeCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $productId = (int)($input['product_id'] ?? 0);
    $recipeCode = trim((string)($input['recipe_code'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $outputQty = (float)($input['output_qty'] ?? 1);
    $outputUnit = trim((string)($input['output_unit'] ?? 'pcs'));
    $notes = trim((string)($input['notes'] ?? ''));
    $ingredients = $input['ingredients'] ?? [];

    if ($productId <= 0 || $recipeCode === '' || $name === '') {
        wmsJsonError('product_id, recipe_code, and name are required.');
    }

    wmsDb()->beginTransaction();
    try {
        wmsDb()->execute(
            'INSERT INTO wms_recipes (product_id, recipe_code, name, description, output_qty, output_unit, notes, created_by)
             VALUES (:pid, :rc, :name, :desc, :oq, :ou, :notes, :uid)',
            [
                ':pid' => $productId, ':rc' => $recipeCode, ':name' => $name,
                ':desc' => $description ?: null, ':oq' => $outputQty, ':ou' => $outputUnit,
                ':notes' => $notes ?: null, ':uid' => (int)$user['id'],
            ]
        );
        $recipeId = (int)wmsDb()->lastInsertId();

        if (is_array($ingredients)) {
            foreach ($ingredients as $i => $ing) {
                $ipid = (int)($ing['ingredient_product_id'] ?? 0);
                $iqty = (float)($ing['quantity'] ?? 0);
                $iunit = trim((string)($ing['unit'] ?? $outputUnit));
                $waste = (float)($ing['wastage_pct'] ?? 0);
                if ($ipid <= 0 || $iqty <= 0) continue;

                wmsDb()->execute(
                    'INSERT INTO wms_recipe_ingredients (recipe_id, ingredient_product_id, quantity, unit, wastage_pct, sort_order, notes)
                     VALUES (:rid, :ipid, :qty, :unit, :waste, :sort, :notes)',
                    [
                        ':rid' => $recipeId, ':ipid' => $ipid, ':qty' => $iqty,
                        ':unit' => $iunit, ':waste' => $waste, ':sort' => $i,
                        ':notes' => $ing['notes'] ?? null,
                    ]
                );
            }
        }

        wmsDb()->commit();
        wmsJsonOk(['recipe_id' => $recipeId], 201);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

// ── Production Orders ──

function wmsApiProductionOrdersList(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));

    $sql = 'SELECT po.*, r.recipe_code, r.name AS recipe_name, p.sku, p.name AS product_name,
                   w.code AS warehouse_code, c.full_name AS created_name
            FROM wms_production_orders po
            JOIN wms_recipes r ON r.id = po.recipe_id
            JOIN wms_products p ON p.id = r.product_id
            JOIN wms_warehouses w ON w.id = po.warehouse_id
            LEFT JOIN wms_users c ON c.id = po.created_by
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND po.status = :status'; $params[':status'] = $status; }
    $sql .= ' ORDER BY po.created_at DESC LIMIT 100';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['production_orders' => $rows]);
}

function wmsApiProductionOrderGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);

    $po = wmsDb()->query(
        'SELECT po.*, r.recipe_code, r.name AS recipe_name, r.output_qty, r.output_unit,
                p.sku, p.name AS product_name, p.unit,
                w.code AS warehouse_code
         FROM wms_production_orders po
         JOIN wms_recipes r ON r.id = po.recipe_id
         JOIN wms_products p ON p.id = r.product_id
         JOIN wms_warehouses w ON w.id = po.warehouse_id
         WHERE po.id = :id',
        [':id' => $id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$po) wmsJsonError('Production order not found.', 404);

    $consumedItems = wmsDb()->query(
        'SELECT poi.*, p.sku, p.name AS ingredient_name, p.unit, b.batch_number
         FROM wms_production_order_items poi
         JOIN wms_products p ON p.id = poi.ingredient_product_id
         LEFT JOIN wms_batches b ON b.id = poi.batch_id
         WHERE poi.production_order_id = :oid ORDER BY poi.id ASC',
        [':oid' => $id]
    )->fetchAll(\PDO::FETCH_ASSOC);

    wmsJsonOk(['production_order' => $po, 'consumed' => $consumedItems]);
}

function wmsApiProductionOrderCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $recipeId = (int)($input['recipe_id'] ?? 0);
    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $quantity = (float)($input['quantity'] ?? 0);
    $locationId = isset($input['location_id']) ? (int)$input['location_id'] : null;
    $notes = trim((string)($input['notes'] ?? ''));

    if ($recipeId <= 0 || $warehouseId <= 0 || $quantity <= 0) {
        wmsJsonError('recipe_id, warehouse_id, and quantity (>0) are required.');
    }

    $recipe = wmsDb()->query('SELECT * FROM wms_recipes WHERE id = :id AND is_active = 1', [':id' => $recipeId])->fetch(\PDO::FETCH_ASSOC);
    if (!$recipe) wmsJsonError('Recipe not found.', 404);

    $orderNumber = 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    wmsDb()->beginTransaction();
    try {
        wmsDb()->execute(
            'INSERT INTO wms_production_orders (recipe_id, order_number, warehouse_id, status, quantity, location_id, notes, created_by)
             VALUES (:rid, :on, :wid, :status, :qty, :lid, :notes, :uid)',
            [
                ':rid' => $recipeId, ':on' => $orderNumber, ':wid' => $warehouseId,
                ':status' => 'planned', ':qty' => $quantity, ':lid' => $locationId,
                ':notes' => $notes ?: null, ':uid' => (int)$user['id'],
            ]
        );
        $prodOrderId = (int)wmsDb()->lastInsertId();

        // Create consumption items from recipe ingredients
        $ingredients = wmsDb()->query(
            'SELECT ri.*, p.unit FROM wms_recipe_ingredients ri
             JOIN wms_products p ON p.id = ri.ingredient_product_id
             WHERE ri.recipe_id = :rid ORDER BY ri.sort_order ASC',
            [':rid' => $recipeId]
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($ingredients as $ing) {
            $required = (float)$ing['quantity'] * ($quantity / (float)$recipe['output_qty']);
            // Adjust for wastage
            $wastagePct = (float)($ing['wastage_pct'] ?? 0);
            $requiredWithWastage = $required * (1 + $wastagePct / 100);

            wmsDb()->execute(
                'INSERT INTO wms_production_order_items (production_order_id, ingredient_product_id, quantity_required, notes)
                 VALUES (:poid, :ipid, :qty, :notes)',
                [
                    ':poid' => $prodOrderId, ':ipid' => $ing['ingredient_product_id'],
                    ':qty' => $requiredWithWastage,
                    ':notes' => 'From recipe ' . $recipe['recipe_code'],
                ]
            );
        }

        wmsDb()->commit();
        wmsJsonOk(['production_order_id' => $prodOrderId, 'order_number' => $orderNumber], 201);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

function wmsApiProductionOrderComplete(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);
    $input = wmsInput();

    $actualOutput = (float)($input['actual_output'] ?? 0);
    $outputBatchId = isset($input['output_batch_id']) ? (int)$input['output_batch_id'] : null;

    $po = wmsDb()->query(
        'SELECT po.*, r.product_id, r.output_qty, r.output_unit
         FROM wms_production_orders po
         JOIN wms_recipes r ON r.id = po.recipe_id
         WHERE po.id = :id',
        [':id' => $id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$po) wmsJsonError('Production order not found.', 404);
    if ($po['status'] === 'completed') wmsJsonError('Already completed.');

    $warehouseId = (int)$po['warehouse_id'];
    $outputLocationId = $po['location_id'] ? (int)$po['location_id'] : null;
    $outputQty = $actualOutput > 0 ? $actualOutput : (float)$po['quantity'];

    wmsDb()->beginTransaction();
    try {
        // Consume ingredients from stock
        $consumedItems = wmsDb()->query(
            'SELECT * FROM wms_production_order_items WHERE production_order_id = :oid',
            [':oid' => $id]
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($consumedItems as $ci) {
            $required = (float)$ci['quantity_required'];
            if ($required <= 0) continue;

            $stockRows = wmsDb()->query(
                'SELECT id, qty_on_hand, qty_reserved FROM wms_stock WHERE product_id = :pid AND warehouse_id = :wid AND qty_on_hand > 0 ORDER BY qty_on_hand ASC',
                [':pid' => $ci['ingredient_product_id'], ':wid' => $warehouseId]
            )->fetchAll(\PDO::FETCH_ASSOC);

            $toConsume = $required;
            foreach ($stockRows as $sr) {
                if ($toConsume <= 0) break;
                $consume = min($toConsume, (float)$sr['qty_on_hand']);
                if ($consume <= 0) continue;

                wmsDb()->execute('UPDATE wms_stock SET qty_on_hand = qty_on_hand - :c, last_movement_at = NOW() WHERE id = :id', [':c' => $consume, ':id' => $sr['id']]);

                wmsDb()->execute(
                    'INSERT INTO wms_stock_movements (product_id, warehouse_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reference_type, reference_id, notes, created_by)
                     VALUES (:pid, :wid, :type, :qty, :prev, :new, :rtype, :rid, :notes, :uid)',
                    [
                        ':pid' => $ci['ingredient_product_id'], ':wid' => $warehouseId,
                        ':type' => 'production_consumption', ':qty' => -$consume,
                        ':prev' => (float)$sr['qty_on_hand'], ':new' => (float)$sr['qty_on_hand'] - $consume,
                        ':rtype' => 'production', ':rid' => $id,
                        ':notes' => 'Production #' . $po['order_number'],
                        ':uid' => (int)$user['id'],
                    ]
                );

                $toConsume -= $consume;
            }

            $consumed = $required - max(0, $toConsume);
            wmsDb()->execute(
                'UPDATE wms_production_order_items SET quantity_consumed = :consumed WHERE id = :id',
                [':consumed' => $consumed, ':id' => $ci['id']]
            );
        }

        // Add output product to stock
        $stock = wmsDb()->query(
            'SELECT id, qty_on_hand FROM wms_stock WHERE product_id = :pid AND warehouse_id = :wid
             AND (location_id = :lid OR (:lid_null IS NULL AND location_id IS NULL))
             AND (batch_id = :bid OR (:bid_null IS NULL AND batch_id IS NULL))',
            [':pid' => $po['product_id'], ':wid' => $warehouseId, ':lid' => $outputLocationId, ':lid_null' => $outputLocationId, ':bid' => $outputBatchId, ':bid_null' => $outputBatchId]
        )->fetch(\PDO::FETCH_ASSOC);

        if ($stock) {
            $prevQty = (float)$stock['qty_on_hand'];
            $newQty = $prevQty + $outputQty;
            wmsDb()->execute('UPDATE wms_stock SET qty_on_hand = :qty, last_movement_at = NOW() WHERE id = :id', [':qty' => $newQty, ':id' => $stock['id']]);
        } else {
            $prevQty = 0;
            $newQty = $outputQty;
            wmsDb()->execute(
                'INSERT INTO wms_stock (product_id, warehouse_id, location_id, batch_id, qty_on_hand, last_movement_at)
                 VALUES (:pid, :wid, :lid, :bid, :qty, NOW())',
                [':pid' => $po['product_id'], ':wid' => $warehouseId, ':lid' => $outputLocationId, ':bid' => $outputBatchId, ':qty' => $outputQty]
            );
        }

        wmsDb()->execute(
            'INSERT INTO wms_stock_movements (product_id, warehouse_id, to_location_id, batch_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reference_type, reference_id, notes, created_by)
             VALUES (:pid, :wid, :lid, :bid, :type, :qty, :prev, :new, :rtype, :rid, :notes, :uid)',
            [
                ':pid' => $po['product_id'], ':wid' => $warehouseId, ':lid' => $outputLocationId,
                ':bid' => $outputBatchId, ':type' => 'production_output', ':qty' => $outputQty,
                ':prev' => $prevQty, ':new' => $newQty,
                ':rtype' => 'production', ':rid' => $id,
                ':notes' => 'Production #' . $po['order_number'],
                ':uid' => (int)$user['id'],
            ]
        );

        wmsDb()->execute(
            'UPDATE wms_production_orders SET status = :status, quantity_produced = :qp, completed_by = :uid, completed_at = NOW() WHERE id = :id',
            [':status' => 'completed', ':qp' => $outputQty, ':uid' => (int)$user['id'], ':id' => $id]
        );

        wmsDb()->commit();
        wmsJsonOk(['production_order_id' => $id, 'status' => 'completed', 'quantity_produced' => $outputQty]);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

// ── Event Webhooks ──

function wmsApiWebhooksList(): void
{
    $user = wmsCurrentUser(['admin']);
    $rows = wmsDb()->query(
        'SELECT w.*, u.full_name AS created_name FROM wms_event_webhooks w
         LEFT JOIN wms_users u ON u.id = w.created_by
         ORDER BY w.event_name ASC, w.id ASC'
    )->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['webhooks' => $rows]);
}

function wmsApiWebhookCreate(): void
{
    $user = wmsCurrentUser(['admin']);
    $input = wmsInput();

    $eventName = trim((string)($input['event_name'] ?? ''));
    $url = trim((string)($input['url'] ?? ''));
    $secret = trim((string)($input['secret'] ?? ''));
    $retryCount = (int)($input['retry_count'] ?? 3);
    $timeoutMs = (int)($input['timeout_ms'] ?? 5000);
    $headers = $input['headers'] ?? null;

    if ($eventName === '' || $url === '') wmsJsonError('event_name and url are required.');

    wmsDb()->execute(
        'INSERT INTO wms_event_webhooks (event_name, url, secret, headers, retry_count, timeout_ms, is_active, created_by)
         VALUES (:en, :url, :secret, :headers, :rc, :to, 1, :uid)',
        [
            ':en' => $eventName, ':url' => $url, ':secret' => $secret ?: null,
            ':headers' => $headers ? json_encode($headers) : null,
            ':rc' => max(0, min(10, $retryCount)),
            ':to' => max(500, min(30000, $timeoutMs)),
            ':uid' => (int)$user['id'],
        ]
    );

    wmsJsonOk(['webhook_id' => (int)wmsDb()->lastInsertId()], 201);
}

function wmsApiWebhookToggle(array $params): void
{
    $user = wmsCurrentUser(['admin']);
    $id = (int)($params['id'] ?? 0);

    $webhook = wmsDb()->query('SELECT id, is_active FROM wms_event_webhooks WHERE id = :id', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    if (!$webhook) wmsJsonError('Webhook not found.', 404);

    $newActive = (int)$webhook['is_active'] ? 0 : 1;
    wmsDb()->execute('UPDATE wms_event_webhooks SET is_active = :active WHERE id = :id', [':active' => $newActive, ':id' => $id]);
    wmsJsonOk(['webhook_id' => $id, 'is_active' => (bool)$newActive]);
}

function wmsApiWebhookDelete(array $params): void
{
    $user = wmsCurrentUser(['admin']);
    $id = (int)($params['id'] ?? 0);
    wmsDb()->execute('DELETE FROM wms_event_webhooks WHERE id = :id', [':id' => $id]);
    wmsJsonOk(['webhook_id' => $id]);
}

// ── Config / Settings ──

function wmsApiConfigSave(): void
{
    $user = wmsCurrentUser(['admin']);
    $body = json_decode(file_get_contents('php://input'), true);
    $configs = $body['configs'] ?? [];

    // Prevent overwriting onboarding status from UI
    unset($configs['onboarding.completed']);

    foreach ($configs as $key => $value) {
        if ($value === null || $value === '') {
            $value = '';
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        wmsDb()->execute(
            'INSERT INTO wms_configs (config_key, config_value, updated_at) VALUES (:key, :val, NOW())
             ON DUPLICATE KEY UPDATE config_value = :val2, updated_at = NOW()',
            [':key' => $key, ':val' => (string)$value, ':val2' => (string)$value]
        );
    }

    wmsJsonOk(['saved' => count($configs)]);
}
