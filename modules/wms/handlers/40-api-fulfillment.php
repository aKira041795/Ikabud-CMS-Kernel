<?php

declare(strict_types=1);

// ── Deliveries (Inbound) ──

function wmsApiDeliveriesList(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));
    $supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;

    $sql = 'SELECT d.*, w.code AS warehouse_code, w.name AS warehouse_name,
                   s.name AS supplier_name, u.full_name AS created_by_name
            FROM wms_deliveries d
            JOIN wms_warehouses w ON w.id = d.warehouse_id
            LEFT JOIN wms_suppliers s ON s.id = d.supplier_id
            LEFT JOIN wms_users u ON u.id = d.created_by
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND d.status = :status'; $params[':status'] = $status; }
    if ($supplierId) { $sql .= ' AND d.supplier_id = :sid'; $params[':sid'] = $supplierId; }
    $sql .= ' ORDER BY d.created_at DESC LIMIT 100';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['deliveries' => $rows]);
}

function wmsApiDeliveryGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);

    $stmt = wmsDb()->query(
        'SELECT d.*, w.code AS warehouse_code, w.name AS warehouse_name,
                s.name AS supplier_name, u.full_name AS created_by_name
         FROM wms_deliveries d
         JOIN wms_warehouses w ON w.id = d.warehouse_id
         LEFT JOIN wms_suppliers s ON s.id = d.supplier_id
         LEFT JOIN wms_users u ON u.id = d.created_by
         WHERE d.id = :id',
        [':id' => $id]
    );
    $delivery = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$delivery) wmsJsonError('Delivery not found.', 404);

    $items = wmsDb()->query(
        'SELECT di.*, p.sku, p.name AS product_name, p.unit, b.batch_number
         FROM wms_delivery_items di
         JOIN wms_products p ON p.id = di.product_id
         LEFT JOIN wms_batches b ON b.id = di.batch_id
         WHERE di.delivery_id = :did ORDER BY di.id ASC',
        [':did' => $id]
    )->fetchAll(\PDO::FETCH_ASSOC);

    wmsJsonOk(['delivery' => $delivery, 'items' => $items]);
}

function wmsApiDeliveryCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $supplierId = isset($input['supplier_id']) ? (int)$input['supplier_id'] : null;
    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $deliveryNumber = trim((string)($input['delivery_number'] ?? ''));
    $reference = trim((string)($input['reference'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $expectedAt = trim((string)($input['expected_at'] ?? ''));
    $items = $input['items'] ?? [];

    if ($warehouseId <= 0 || $deliveryNumber === '') {
        wmsJsonError('warehouse_id and delivery_number are required.');
    }

    $existing = wmsDb()->query('SELECT id FROM wms_deliveries WHERE delivery_number = :dn', [':dn' => $deliveryNumber])->fetch(\PDO::FETCH_ASSOC);
    if ($existing) wmsJsonError('Delivery number already exists.');

    wmsDb()->beginTransaction();
    try {
        wmsDb()->execute(
            'INSERT INTO wms_deliveries (supplier_id, warehouse_id, delivery_number, status, reference, notes, expected_at, created_by)
             VALUES (:sid, :wid, :dn, :status, :ref, :notes, :ea, :uid)',
            [
                ':sid' => $supplierId, ':wid' => $warehouseId,
                ':dn' => $deliveryNumber, ':status' => 'expected',
                ':ref' => $reference ?: null, ':notes' => $notes ?: null,
                ':ea' => $expectedAt ?: null, ':uid' => (int)$user['id'],
            ]
        );
        $deliveryId = (int)wmsDb()->lastInsertId();

        if (is_array($items)) {
            foreach ($items as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $qty = (float)($item['expected_qty'] ?? 0);
                if ($pid <= 0 || $qty <= 0) continue;

                wmsDb()->execute(
                    'INSERT INTO wms_delivery_items (delivery_id, product_id, batch_id, expected_qty, received_qty, status)
                     VALUES (:did, :pid, :bid, :eq, 0, :status)',
                    [
                        ':did' => $deliveryId, ':pid' => $pid,
                        ':bid' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                        ':eq' => $qty, ':status' => 'pending',
                    ]
                );
            }
        }

        wmsDb()->commit();
        wmsJsonOk(['delivery_id' => $deliveryId], 201);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

function wmsApiDeliveryReceive(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);
    $input = wmsInput();

    $delivery = wmsDb()->query('SELECT * FROM wms_deliveries WHERE id = :id', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    if (!$delivery) wmsJsonError('Delivery not found.', 404);
    if (in_array($delivery['status'], ['received', 'cancelled'], true)) {
        wmsJsonError('Delivery already ' . $delivery['status'] . '.');
    }

    $items = $input['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        wmsJsonError('items array is required with product_id and received_qty.');
    }

    $warehouseId = (int)$delivery['warehouse_id'];
    $allReceived = true;

    wmsDb()->beginTransaction();
    try {
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $receivedQty = (float)($item['received_qty'] ?? 0);
            $locationId = isset($item['location_id']) ? (int)$item['location_id'] : null;
            $batchId = isset($item['batch_id']) ? (int)$item['batch_id'] : null;
            $itemNotes = trim((string)($item['notes'] ?? ''));

            if ($productId <= 0 || $receivedQty <= 0) continue;

            // Update delivery item
            $di = wmsDb()->query(
                'SELECT id, expected_qty, received_qty FROM wms_delivery_items WHERE delivery_id = :did AND product_id = :pid',
                [':did' => $id, ':pid' => $productId]
            )->fetch(\PDO::FETCH_ASSOC);

            $newReceivedQty = $receivedQty;
            if ($di) {
                $newReceivedQty = (float)$di['received_qty'] + $receivedQty;
                $expectedQty = (float)$di['expected_qty'];
                $itemStatus = $newReceivedQty >= $expectedQty ? 'received' : ($newReceivedQty > 0 ? 'short' : 'pending');
                if ($newReceivedQty > $expectedQty) $itemStatus = 'over_received';
                wmsDb()->execute(
                    'UPDATE wms_delivery_items SET received_qty = :rq, status = :status WHERE id = :id',
                    [':rq' => $newReceivedQty, ':status' => $itemStatus, ':id' => $di['id']]
                );
                if ($itemStatus !== 'received') $allReceived = false;
            }

            // Add to stock
            $stock = wmsDb()->query(
                'SELECT id, qty_on_hand FROM wms_stock WHERE product_id = :pid AND warehouse_id = :wid
                 AND (location_id = :lid OR (:lid_null IS NULL AND location_id IS NULL))
                 AND (batch_id = :bid OR (:bid_null IS NULL AND batch_id IS NULL))',
                [':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId, ':lid_null' => $locationId, ':bid' => $batchId, ':bid_null' => $batchId]
            )->fetch(\PDO::FETCH_ASSOC);

            if ($stock) {
                $prevQty = (float)$stock['qty_on_hand'];
                $newQty = $prevQty + $receivedQty;
                wmsDb()->execute('UPDATE wms_stock SET qty_on_hand = :qty, last_movement_at = NOW() WHERE id = :id', [':qty' => $newQty, ':id' => $stock['id']]);
            } else {
                $prevQty = 0;
                $newQty = $receivedQty;
                wmsDb()->execute(
                    'INSERT INTO wms_stock (product_id, warehouse_id, location_id, batch_id, qty_on_hand, last_movement_at)
                     VALUES (:pid, :wid, :lid, :bid, :qty, NOW())',
                    [':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId, ':bid' => $batchId, ':qty' => $receivedQty]
                );
            }

            wmsDb()->execute(
                'INSERT INTO wms_stock_movements (product_id, warehouse_id, to_location_id, batch_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reference_type, reference_id, notes, created_by)
                 VALUES (:pid, :wid, :lid, :bid, :type, :qty, :prev, :new, :rtype, :rid, :notes, :uid)',
                [
                    ':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId,
                    ':bid' => $batchId, ':type' => 'receipt', ':qty' => $receivedQty,
                    ':prev' => $prevQty, ':new' => $newQty,
                    ':rtype' => 'delivery', ':rid' => $id,
                    ':notes' => $itemNotes ?: null, ':uid' => (int)$user['id'],
                ]
            );
        }

        $deliveryStatus = $allReceived ? 'received' : 'partially_received';
        wmsDb()->execute(
            'UPDATE wms_deliveries SET status = :status, received_at = NOW(), received_by = :uid WHERE id = :id',
            [':status' => $deliveryStatus, ':uid' => (int)$user['id'], ':id' => $id]
        );

        wmsDb()->commit();
        wmsJsonOk(['delivery_id' => $id, 'status' => $deliveryStatus]);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

function wmsApiDeliveryCancel(array $params): void
{
    $user = wmsCurrentUser(['admin']);
    $id = (int)($params['id'] ?? 0);

    $delivery = wmsDb()->query('SELECT id, status FROM wms_deliveries WHERE id = :id', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    if (!$delivery) wmsJsonError('Delivery not found.', 404);
    if (in_array($delivery['status'], ['received', 'cancelled'], true)) {
        wmsJsonError('Cannot cancel a ' . $delivery['status'] . ' delivery.');
    }

    wmsDb()->execute('UPDATE wms_deliveries SET status = :status WHERE id = :id', [':status' => 'cancelled', ':id' => $id]);
    wmsJsonOk(['delivery_id' => $id, 'status' => 'cancelled']);
}

// ── Orders (Outbound) ──

function wmsApiOrdersList(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));

    $sql = 'SELECT o.*, w.code AS warehouse_code,
                   u.full_name AS created_by_name
            FROM wms_orders o
            JOIN wms_warehouses w ON w.id = o.warehouse_id
            LEFT JOIN wms_users u ON u.id = o.created_by
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND o.status = :status'; $params[':status'] = $status; }
    if ($type !== '') { $sql .= ' AND o.order_type = :type'; $params[':type'] = $type; }
    $sql .= ' ORDER BY o.created_at DESC LIMIT 100';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['orders' => $rows]);
}

function wmsApiOrderGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);

    $stmt = wmsDb()->query(
        'SELECT o.*, w.code AS warehouse_code, w.name AS warehouse_name,
                u.full_name AS created_by_name
         FROM wms_orders o
         JOIN wms_warehouses w ON w.id = o.warehouse_id
         LEFT JOIN wms_users u ON u.id = o.created_by
         WHERE o.id = :id',
        [':id' => $id]
    );
    $order = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$order) wmsJsonError('Order not found.', 404);

    $items = wmsDb()->query(
        'SELECT oi.*, p.sku, p.name AS product_name, p.unit, b.batch_number
         FROM wms_order_items oi
         JOIN wms_products p ON p.id = oi.product_id
         LEFT JOIN wms_batches b ON b.id = oi.batch_id
         WHERE oi.order_id = :oid ORDER BY oi.id ASC',
        [':oid' => $id]
    )->fetchAll(\PDO::FETCH_ASSOC);

    $shipments = wmsDb()->query(
        'SELECT * FROM wms_shipments WHERE order_id = :oid ORDER BY created_at ASC',
        [':oid' => $id]
    )->fetchAll(\PDO::FETCH_ASSOC);

    wmsJsonOk(['order' => $order, 'items' => $items, 'shipments' => $shipments]);
}

function wmsApiOrderCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $orderType = trim((string)($input['order_type'] ?? 'sales_order'));
    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $customerName = trim((string)($input['customer_name'] ?? ''));
    $customerEmail = trim((string)($input['customer_email'] ?? ''));
    $customerPhone = trim((string)($input['customer_phone'] ?? ''));
    $shippingAddress = trim((string)($input['shipping_address'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $items = $input['items'] ?? [];

    if ($warehouseId <= 0 || !is_array($items) || count($items) === 0) {
        wmsJsonError('warehouse_id and items are required.');
    }

    $orderNumber = 'SO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    wmsDb()->beginTransaction();
    try {
        wmsDb()->execute(
            'INSERT INTO wms_orders (order_number, order_type, warehouse_id, status, customer_name, customer_email, customer_phone, shipping_address, notes, created_by)
             VALUES (:on, :ot, :wid, :status, :cn, :ce, :cp, :sa, :notes, :uid)',
            [
                ':on' => $orderNumber, ':ot' => $orderType, ':wid' => $warehouseId,
                ':status' => 'pending', ':cn' => $customerName ?: null,
                ':ce' => $customerEmail ?: null, ':cp' => $customerPhone ?: null,
                ':sa' => $shippingAddress ?: null, ':notes' => $notes ?: null,
                ':uid' => (int)$user['id'],
            ]
        );
        $orderId = (int)wmsDb()->lastInsertId();

        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $qty = (float)($item['quantity_ordered'] ?? 0);
            $price = isset($item['unit_price']) ? (float)$item['unit_price'] : null;
            if ($pid <= 0 || $qty <= 0) continue;

            wmsDb()->execute(
                'INSERT INTO wms_order_items (order_id, product_id, batch_id, quantity_ordered, unit_price, status)
                 VALUES (:oid, :pid, :bid, :qty, :price, :status)',
                [
                    ':oid' => $orderId, ':pid' => $pid,
                    ':bid' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                    ':qty' => $qty, ':price' => $price, ':status' => 'pending',
                ]
            );
        }

        wmsDb()->commit();
        wmsJsonOk(['order_id' => $orderId, 'order_number' => $orderNumber], 201);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

function wmsApiOrderCancel(array $params): void
{
    $user = wmsCurrentUser(['admin']);
    $id = (int)($params['id'] ?? 0);

    $order = wmsDb()->query('SELECT id, status FROM wms_orders WHERE id = :id', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    if (!$order) wmsJsonError('Order not found.', 404);
    if (in_array($order['status'], ['shipped', 'delivered', 'cancelled'], true)) {
        wmsJsonError('Cannot cancel a ' . $order['status'] . ' order.');
    }

    wmsDb()->beginTransaction();
    try {
        // Release any reserved stock
        $items = wmsDb()->query('SELECT product_id, quantity_picked FROM wms_order_items WHERE order_id = :oid', [':oid' => $id])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            $picked = (float)($item['quantity_picked'] ?? 0);
            if ($picked > 0) {
                $stockRows = wmsDb()->query(
                    'SELECT id, qty_reserved FROM wms_stock WHERE product_id = :pid AND qty_reserved > 0 ORDER BY qty_reserved DESC',
                    [':pid' => $item['product_id']]
                )->fetchAll(\PDO::FETCH_ASSOC);
                $toRelease = $picked;
                foreach ($stockRows as $sr) {
                    $rel = min($toRelease, (float)$sr['qty_reserved']);
                    wmsDb()->execute('UPDATE wms_stock SET qty_reserved = qty_reserved - :rel WHERE id = :id', [':rel' => $rel, ':id' => $sr['id']]);
                    $toRelease -= $rel;
                    if ($toRelease <= 0) break;
                }
            }
        }

        wmsDb()->execute('UPDATE wms_orders SET status = :status WHERE id = :id', [':status' => 'cancelled', ':id' => $id]);
        wmsDb()->commit();
        wmsJsonOk(['order_id' => $id, 'status' => 'cancelled']);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

// ── Picklists ──

function wmsApiPicklistsList(): void
{
    $user = wmsCurrentUser();
    $status = trim((string)($_GET['status'] ?? ''));

    $sql = 'SELECT p.*, w.code AS warehouse_code,
                   a.full_name AS assigned_to_name, c.full_name AS created_by_name
            FROM wms_picklists p
            JOIN wms_warehouses w ON w.id = p.warehouse_id
            LEFT JOIN wms_users a ON a.id = p.assigned_to
            LEFT JOIN wms_users c ON c.id = p.created_by
            WHERE 1=1';
    $params = [];
    if ($status !== '') { $sql .= ' AND p.status = :status'; $params[':status'] = $status; }
    $sql .= ' ORDER BY p.created_at DESC LIMIT 100';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['picklists' => $rows]);
}

function wmsApiPicklistGet(array $params): void
{
    $user = wmsCurrentUser();
    $id = (int)($params['id'] ?? 0);

    $pl = wmsDb()->query(
        'SELECT p.*, w.code AS warehouse_code,
                a.full_name AS assigned_to_name, c.full_name AS created_by_name
         FROM wms_picklists p
         JOIN wms_warehouses w ON w.id = p.warehouse_id
         LEFT JOIN wms_users a ON a.id = p.assigned_to
         LEFT JOIN wms_users c ON c.id = p.created_by
         WHERE p.id = :id',
        [':id' => $id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$pl) wmsJsonError('Picklist not found.', 404);

    $items = wmsDb()->query(
        'SELECT pi.*, p.sku, p.name AS product_name, p.unit,
                l.code AS location_code, b.batch_number
         FROM wms_picklist_items pi
         JOIN wms_products p ON p.id = pi.product_id
         LEFT JOIN wms_locations l ON l.id = pi.location_id
         LEFT JOIN wms_batches b ON b.id = pi.batch_id
         WHERE pi.picklist_id = :pid ORDER BY pi.id ASC',
        [':pid' => $id]
    )->fetchAll(\PDO::FETCH_ASSOC);

    wmsJsonOk(['picklist' => $pl, 'items' => $items]);
}

function wmsApiPicklistCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $orderIds = $input['order_ids'] ?? [];
    $assignedTo = isset($input['assigned_to']) ? (int)$input['assigned_to'] : null;

    if ($warehouseId <= 0 || !is_array($orderIds) || count($orderIds) === 0) {
        wmsJsonError('warehouse_id and order_ids are required.');
    }

    wmsDb()->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $orderItems = wmsDb()->query(
            "SELECT oi.id, oi.order_id, oi.product_id, oi.quantity_ordered, oi.quantity_picked
             FROM wms_order_items oi
             JOIN wms_orders o ON o.id = oi.order_id
             WHERE oi.order_id IN ($placeholders) AND o.warehouse_id = ? AND o.status = 'pending'
             AND oi.quantity_ordered > oi.quantity_picked",
            array_merge($orderIds, [$warehouseId])
        )->fetchAll(\PDO::FETCH_ASSOC);

        if (count($orderItems) === 0) {
            wmsJsonError('No pickable items found for these orders.');
        }

        wmsDb()->execute(
            'INSERT INTO wms_picklists (warehouse_id, status, assigned_to, created_by)
             VALUES (:wid, :status, :assignee, :uid)',
            [':wid' => $warehouseId, ':status' => 'open', ':assignee' => $assignedTo, ':uid' => (int)$user['id']]
        );
        $picklistId = (int)wmsDb()->lastInsertId();

        foreach ($orderItems as $oi) {
            $toPick = (float)$oi['quantity_ordered'] - (float)$oi['quantity_picked'];
            if ($toPick <= 0) continue;

            // Find a location with stock
            $loc = wmsDb()->query(
                'SELECT location_id, batch_id FROM wms_stock WHERE product_id = :pid AND warehouse_id = :wid AND qty_on_hand - qty_reserved > 0 ORDER BY location_id ASC LIMIT 1',
                [':pid' => $oi['product_id'], ':wid' => $warehouseId]
            )->fetch(\PDO::FETCH_ASSOC);

            wmsDb()->execute(
                'INSERT INTO wms_picklist_items (picklist_id, order_item_id, product_id, location_id, batch_id, quantity_to_pick, status)
                 VALUES (:plid, :oiid, :pid, :lid, :bid, :qty, :status)',
                [
                    ':plid' => $picklistId, ':oiid' => $oi['id'],
                    ':pid' => $oi['product_id'],
                    ':lid' => $loc ? $loc['location_id'] : null,
                    ':bid' => $loc ? $loc['batch_id'] : null,
                    ':qty' => $toPick, ':status' => 'pending',
                ]
            );
        }

        wmsDb()->commit();
        wmsJsonOk(['picklist_id' => $picklistId], 201);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

function wmsApiPicklistPickItem(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor', 'picker']);
    $id = (int)($params['id'] ?? 0);
    $itemId = (int)($params['item_id'] ?? 0);
    $input = wmsInput();

    $qtyPicked = (float)($input['quantity_picked'] ?? 0);

    $item = wmsDb()->query(
        'SELECT pi.*, pl.warehouse_id, pl.status AS pl_status
         FROM wms_picklist_items pi
         JOIN wms_picklists pl ON pl.id = pi.picklist_id
         WHERE pi.id = :id AND pi.picklist_id = :plid',
        [':id' => $itemId, ':plid' => $id]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$item) wmsJsonError('Picklist item not found.', 404);
    if ($item['pl_status'] === 'completed') wmsJsonError('Picklist already completed.');

    if ($qtyPicked <= 0) $qtyPicked = (float)$item['quantity_to_pick'];
    $qtyPicked = min($qtyPicked, (float)$item['quantity_to_pick']);
    if ($qtyPicked <= 0) wmsJsonError('Nothing to pick.');

    $warehouseId = (int)$item['warehouse_id'];
    $productId = (int)$item['product_id'];
    $locationId = $item['location_id'] ? (int)$item['location_id'] : null;
    $batchId = $item['batch_id'] ? (int)$item['batch_id'] : null;

    wmsDb()->beginTransaction();
    try {
        // Reserve the stock
        $stock = wmsDb()->query(
            'SELECT id, qty_on_hand, qty_reserved FROM wms_stock
             WHERE product_id = :pid AND warehouse_id = :wid
               AND (location_id = :lid OR (:lid_null IS NULL AND location_id IS NULL))
               AND (batch_id = :bid OR (:bid_null IS NULL AND batch_id IS NULL))',
            [':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId, ':lid_null' => $locationId, ':bid' => $batchId, ':bid_null' => $batchId]
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$stock) wmsJsonError('No stock record found.', 404);
        if ((float)$stock['qty_on_hand'] - (float)$stock['qty_reserved'] < $qtyPicked) {
            wmsJsonError('Insufficient available stock to pick.');
        }

        wmsDb()->execute('UPDATE wms_stock SET qty_reserved = qty_reserved + :qty, last_movement_at = NOW() WHERE id = :id', [':qty' => $qtyPicked, ':id' => $stock['id']]);

        wmsDb()->execute(
            'UPDATE wms_picklist_items SET quantity_picked = :qp, status = :status, picked_at = NOW() WHERE id = :id',
            [':qp' => $qtyPicked, ':status' => 'picked', ':id' => $itemId]
        );

        // Update order item
        if ($item['order_item_id']) {
            wmsDb()->execute(
                'UPDATE wms_order_items SET quantity_picked = quantity_picked + :qp, status = CASE WHEN quantity_picked + :qp2 >= quantity_ordered THEN \'picked\' ELSE \'allocated\' END WHERE id = :id',
                [':qp' => $qtyPicked, ':qp2' => $qtyPicked, ':id' => $item['order_item_id']]
            );
        }

        // Check if all items picked
        $remaining = wmsDb()->query(
            'SELECT COUNT(*) AS cnt FROM wms_picklist_items WHERE picklist_id = :pid AND status != \'picked\'',
            [':pid' => $id]
        )->fetch(\PDO::FETCH_ASSOC);

        if ($remaining && (int)$remaining['cnt'] === 0) {
            wmsDb()->execute(
                'UPDATE wms_picklists SET status = :status, completed_at = NOW() WHERE id = :id',
                [':status' => 'completed', ':id' => $id]
            );

            // Update linked orders to picking->picked
            $pickedOrderItems = wmsDb()->query(
                'SELECT DISTINCT oi.order_id FROM wms_picklist_items pi
                 JOIN wms_order_items oi ON oi.id = pi.order_item_id
                 WHERE pi.picklist_id = :pid',
                [':pid' => $id]
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($pickedOrderItems as $poi) {
                wmsDb()->execute(
                    'UPDATE wms_orders SET status = :status, picked_by = :uid, picked_at = NOW() WHERE id = :id AND status = :from_status',
                    [':status' => 'picked', ':uid' => (int)$user['id'], ':id' => (int)$poi['order_id'], ':from_status' => 'picking']
                );
            }
        }

        wmsDb()->commit();
        wmsJsonOk(['picklist_item_id' => $itemId, 'quantity_picked' => $qtyPicked]);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

function wmsApiPicklistAssign(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);
    $input = wmsInput();
    $assignedTo = (int)($input['assigned_to'] ?? 0);

    if ($assignedTo <= 0) wmsJsonError('assigned_to is required.');

    $pl = wmsDb()->query('SELECT id, status FROM wms_picklists WHERE id = :id', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    if (!$pl) wmsJsonError('Picklist not found.', 404);
    if ($pl['status'] === 'completed') wmsJsonError('Picklist already completed.');

    wmsDb()->execute(
        'UPDATE wms_picklists SET assigned_to = :assignee, status = CASE WHEN status = \'open\' THEN \'in_progress\' ELSE status END WHERE id = :id',
        [':assignee' => $assignedTo, ':id' => $id]
    );

    wmsJsonOk(['picklist_id' => $id]);
}

// ── Shipments ──

function wmsApiShipmentCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $orderId = (int)($input['order_id'] ?? 0);
    $carrier = trim((string)($input['carrier'] ?? ''));
    $trackingNumber = trim((string)($input['tracking_number'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));

    if ($orderId <= 0) wmsJsonError('order_id is required.');

    $order = wmsDb()->query('SELECT id, status FROM wms_orders WHERE id = :id', [':id' => $orderId])->fetch(\PDO::FETCH_ASSOC);
    if (!$order) wmsJsonError('Order not found.', 404);
    if (!in_array($order['status'], ['picked', 'packed'], true)) {
        wmsJsonError('Order must be picked before shipping.');
    }

    wmsDb()->beginTransaction();
    try {
        wmsDb()->execute(
            'INSERT INTO wms_shipments (order_id, carrier, tracking_number, status, notes)
             VALUES (:oid, :carrier, :tn, :status, :notes)',
            [
                ':oid' => $orderId, ':carrier' => $carrier ?: null,
                ':tn' => $trackingNumber ?: null, ':status' => 'shipped',
                ':notes' => $notes ?: null,
            ]
        );

        wmsDb()->execute(
            'UPDATE wms_orders SET status = :status, shipped_by = :uid, shipped_at = NOW() WHERE id = :id',
            [':status' => 'shipped', ':uid' => (int)$user['id'], ':id' => $orderId]
        );

        // Reduce stock on hand
        $items = wmsDb()->query('SELECT product_id, quantity_picked FROM wms_order_items WHERE order_id = :oid', [':oid' => $orderId])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            $qty = (float)($item['quantity_picked'] ?? 0);
            if ($qty <= 0) continue;

            $stockRows = wmsDb()->query(
                'SELECT id, qty_on_hand, qty_reserved FROM wms_stock WHERE product_id = :pid AND qty_reserved > 0 ORDER BY qty_reserved DESC',
                [':pid' => $item['product_id']]
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($stockRows as $sr) {
                $rel = min($qty, (float)$sr['qty_reserved'], (float)$sr['qty_on_hand']);
                if ($rel <= 0) continue;
                wmsDb()->execute('UPDATE wms_stock SET qty_on_hand = qty_on_hand - :rel, qty_reserved = qty_reserved - :rel, last_movement_at = NOW() WHERE id = :id', [':rel' => $rel, ':id' => $sr['id']]);

                wmsDb()->execute(
                    'INSERT INTO wms_stock_movements (product_id, warehouse_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reference_type, reference_id, notes, created_by)
                     SELECT :pid, o.warehouse_id, :type, :qty, :prev, :new, :rtype, :rid, :notes, :uid
                     FROM wms_orders o WHERE o.id = :oid2',
                    [
                        ':pid' => $item['product_id'], ':type' => 'sale', ':qty' => -$rel,
                        ':prev' => (float)$sr['qty_on_hand'], ':new' => (float)$sr['qty_on_hand'] - $rel,
                        ':rtype' => 'order', ':rid' => $orderId,
                        ':notes' => 'Shipped via ' . ($carrier ?: 'unknown'),
                        ':uid' => (int)$user['id'], ':oid2' => $orderId,
                    ]
                );

                $qty -= $rel;
                if ($qty <= 0) break;
            }
        }

        wmsDb()->commit();
        wmsJsonOk(['shipment_id' => (int)wmsDb()->lastInsertId(), 'order_id' => $orderId, 'status' => 'shipped']);
    } catch (\Throwable $e) {
        wmsDb()->rollBack();
        throw $e;
    }
}

// ── Putaway Rules ──

function wmsApiPutawayRulesList(): void
{
    $user = wmsCurrentUser();
    $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;

    $sql = 'SELECT pr.*, p.sku, p.name AS product_name,
                   l.code AS location_code, l.name AS location_name,
                   w.code AS warehouse_code
            FROM wms_putaway_rules pr
            JOIN wms_warehouses w ON w.id = pr.warehouse_id
            JOIN wms_locations l ON l.id = pr.location_id
            LEFT JOIN wms_products p ON p.id = pr.product_id
            WHERE 1=1';
    $params = [];
    if ($warehouseId) { $sql .= ' AND pr.warehouse_id = :wid'; $params[':wid'] = $warehouseId; }
    $sql .= ' ORDER BY pr.priority ASC, pr.id ASC LIMIT 200';

    $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    wmsJsonOk(['putaway_rules' => $rows]);
}

function wmsApiPutawayRuleCreate(): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $input = wmsInput();

    $productId = isset($input['product_id']) ? (int)$input['product_id'] : null;
    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $locationId = (int)($input['location_id'] ?? 0);
    $priority = (int)($input['priority'] ?? 100);
    $conditionType = trim((string)($input['condition_type'] ?? ''));
    $conditionValue = trim((string)($input['condition_value'] ?? ''));

    if ($warehouseId <= 0 || $locationId <= 0) {
        wmsJsonError('warehouse_id and location_id are required.');
    }

    wmsDb()->execute(
        'INSERT INTO wms_putaway_rules (product_id, warehouse_id, location_id, priority, condition_type, condition_value, is_active)
         VALUES (:pid, :wid, :lid, :pri, :ct, :cv, 1)',
        [
            ':pid' => $productId, ':wid' => $warehouseId, ':lid' => $locationId,
            ':pri' => $priority, ':ct' => $conditionType ?: null, ':cv' => $conditionValue ?: null,
        ]
    );

    wmsJsonOk(['putaway_rule_id' => (int)wmsDb()->lastInsertId()], 201);
}

function wmsApiPutawayRuleDelete(array $params): void
{
    $user = wmsCurrentUser(['admin', 'supervisor']);
    $id = (int)($params['id'] ?? 0);
    wmsDb()->execute('DELETE FROM wms_putaway_rules WHERE id = :id', [':id' => $id]);
    wmsJsonOk(['putaway_rule_id' => $id]);
}
