<?php

declare(strict_types=1);

app()->registerAuthTable('wms', 'wms_users');

/**
 * Capability handler map for the WMS module.
 */
function wms_capability_handlers(): array
{
    return [
        'kernel.auth.authenticate@1' => 'wms_cap_kernel_auth_authenticate_1',
        'wms.stock.query@1'     => 'wms_cap_stock_query_1',
        'wms.stock.reserve@1'   => 'wms_cap_stock_reserve_1',
        'wms.stock.release@1'   => 'wms_cap_stock_release_1',
        'wms.order.create@1'    => 'wms_cap_order_create_1',
        'wms.order.cancel@1'    => 'wms_cap_order_cancel_1',
        'wms.return.create@1'   => 'wms_cap_wms_return_create_1',
    ];
}

// ── Auth capability handler ──

function wms_cap_kernel_auth_authenticate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): ?array
{
    if (!is_array($payload)) return null;

    $username = trim((string)($payload['username'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    if ($username === '' || $password === '') return null;

    $prefix = '@wms:';
    if (!str_starts_with($username, $prefix)) return null;
    $username = trim(substr($username, strlen($prefix)));
    if ($username === '') return null;

    try {
        $stmt = wmsDb()->prepare(
            "SELECT id, username, email, password_hash, full_name, role, is_active\n"
            . "FROM wms_users\n"
            . "WHERE (username = :username OR email = :email) AND is_active = 1\n"
            . "LIMIT 1"
        );
        $stmt->execute([':username' => $username, ':email' => $username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row) || !password_verify($password, (string)($row['password_hash'] ?? ''))) {
            return null;
        }

        return [
            'user' => [
                'id' => (int)($row['id'] ?? 0),
                'username' => (string)($row['username'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'full_name' => (string)($row['full_name'] ?? ''),
                'role' => (string)($row['role'] ?? 'viewer'),
                'sub' => 'wms:' . (int)($row['id'] ?? 0),
            ],
            'source' => 'wms',
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

// ── Stock capability handlers ──

function wms_cap_stock_query_1(mixed $payload): ?array
{
    if (!is_array($payload)) return null;
    $productId = (int)($payload['product_id'] ?? 0);
    $warehouseId = (int)($payload['warehouse_id'] ?? 0);
    $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];

    $sql = 'SELECT s.*, p.sku, p.name AS product_name, p.unit,
                   (s.qty_on_hand - s.qty_reserved - s.qty_staged) AS qty_available
            FROM wms_stock s
            JOIN wms_products p ON p.id = s.product_id
            WHERE 1=1';
    $params = [];
    if ($productId) { $sql .= ' AND s.product_id = :pid'; $params[':pid'] = $productId; }
    if ($warehouseId) { $sql .= ' AND s.warehouse_id = :wid'; $params[':wid'] = $warehouseId; }

    // SKU filter support (consumed by the ecommerce WMS-authoritative overlay,
    // which batches stock snapshots for a set of SKUs).
    $skus = [];
    if (isset($filters['skus']) && is_array($filters['skus'])) {
        foreach ($filters['skus'] as $sku) {
            $sku = strtoupper(trim((string)$sku));
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }
    }
    if ($skus !== []) {
        $placeholders = [];
        foreach (array_values(array_unique($skus)) as $i => $sku) {
            $ph = ':sku' . $i;
            $placeholders[] = $ph;
            $params[$ph] = $sku;
        }
        $sql .= ' AND UPPER(p.sku) IN (' . implode(',', $placeholders) . ')';
    }
    $sql .= ' ORDER BY p.name ASC';

    try {
        $rows = wmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
        return ['ok' => true, 'data' => $rows, 'stock' => $rows];
    } catch (\Throwable $e) {
        return null;
    }
}

function wms_cap_stock_reserve_1(mixed $payload): ?array
{
    if (!is_array($payload)) return null;

    // ── Batch (ecommerce bridge) payload ──────────────────────────────
    // {reference_type, reference_id, items: [{product_id, sku, qty, warehouse_id, location_id, batch_id}], idempotency_key, actor_user_id}
    if (isset($payload['items']) && is_array($payload['items']) && $payload['items'] !== []) {
        return wmsCapStockReserveBatch($payload);
    }

    // ── Single-item payload (backward compat / direct calls) ──────────
    $productId = (int)($payload['product_id'] ?? 0);
    $warehouseId = (int)($payload['warehouse_id'] ?? 0);
    $quantity = (float)($payload['quantity'] ?? 0);

    if ($productId <= 0 || $warehouseId <= 0 || $quantity <= 0) return null;

    try {
        $stock = wmsDb()->query(
            'SELECT id, qty_on_hand, qty_reserved FROM wms_stock
             WHERE product_id = :pid AND warehouse_id = :wid
             ORDER BY qty_on_hand - qty_reserved DESC LIMIT 1',
            [':pid' => $productId, ':wid' => $warehouseId]
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$stock) return ['ok' => false, 'reserved' => 0, 'message' => 'No stock available.'];
        $available = (float)$stock['qty_on_hand'] - (float)$stock['qty_reserved'];
        $toReserve = min($quantity, $available);
        if ($toReserve <= 0) return ['ok' => false, 'reserved' => 0, 'message' => 'No available stock to reserve.'];

        wmsDb()->execute('UPDATE wms_stock SET qty_reserved = qty_reserved + :qty WHERE id = :id', [':qty' => $toReserve, ':id' => $stock['id']]);
        wmsStockMovementCreate([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'movement_type' => 'reserved',
            'quantity' => $toReserve,
            'prev_qty_on_hand' => (float)$stock['qty_on_hand'],
            'new_qty_on_hand' => (float)$stock['qty_on_hand'],
            'reference_type' => 'reservation',
            'reference_id' => isset($payload['reference_id']) ? (int)$payload['reference_id'] : null,
        ]);
        return ['ok' => true, 'reserved' => $toReserve, 'stock_id' => (int)$stock['id']];
    } catch (\Throwable $e) {
        return null;
    }
}

function wmsCapStockReserveBatch(array $payload): array
{
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $referenceType = trim((string)($payload['reference_type'] ?? 'order'));
    $referenceId = isset($payload['reference_id']) ? (int)$payload['reference_id'] : null;
    $idempotencyKey = trim((string)($payload['idempotency_key'] ?? ''));
    $actorUserId = isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null;

    if ($idempotencyKey !== '') {
        $existing = wmsDb()->query(
            'SELECT id FROM wms_idempotency_keys WHERE idempotency_key = :key LIMIT 1',
            [':key' => $idempotencyKey]
        )->fetch(\PDO::FETCH_ASSOC);
        if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
            return ['ok' => true, 'idempotent' => true, 'reserved' => 0];
        }
    }

    $reserved = 0.0;
    $movementIds = [];
    $db = wmsDb();
    $db->beginTransaction();
    try {
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $productId = (int)($item['product_id'] ?? 0);
            $sku = strtoupper(trim((string)($item['sku'] ?? '')));
            // The ecommerce bridge sends its own product_id which is NOT a
            // wms_products row. Resolve the WMS product by SKU whenever the
            // provided id is absent OR does not reference an existing WMS
            // product (mirrors the historical wmsBridgeResolveProductId).
            $wmsProductRow = $productId > 0
                ? $db->query('SELECT id FROM wms_products WHERE id = :id LIMIT 1', [':id' => $productId])->fetch(\PDO::FETCH_ASSOC)
                : null;
            if (!is_array($wmsProductRow) && $sku !== '') {
                $resolved = $db->query('SELECT id FROM wms_products WHERE UPPER(sku) = :sku LIMIT 1', [':sku' => $sku])->fetch(\PDO::FETCH_ASSOC);
                if (is_array($resolved) && (int)($resolved['id'] ?? 0) > 0) {
                    $productId = (int)$resolved['id'];
                }
            }
            if ($productId <= 0) continue;

            $warehouseId = (int)($item['warehouse_id'] ?? $payload['warehouse_id'] ?? 0);
            $quantity = (float)($item['qty'] ?? $item['quantity'] ?? $item['qty_ordered'] ?? 0);
            if ($warehouseId <= 0 || $quantity <= 0) continue;

            $stock = $db->query(
                'SELECT id, qty_on_hand, qty_reserved FROM wms_stock
                 WHERE product_id = :pid AND warehouse_id = :wid
                 ORDER BY qty_on_hand - qty_reserved DESC LIMIT 1',
                [':pid' => $productId, ':wid' => $warehouseId]
            )->fetch(\PDO::FETCH_ASSOC);
            if (!$stock) continue;

            $available = (float)$stock['qty_on_hand'] - (float)$stock['qty_reserved'];
            $toReserve = min($quantity, $available);
            if ($toReserve <= 0) continue;

            $db->execute('UPDATE wms_stock SET qty_reserved = qty_reserved + :qty WHERE id = :id', [':qty' => $toReserve, ':id' => $stock['id']]);
            $movementId = wmsStockMovementCreate([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'location_id' => isset($item['location_id']) ? (int)$item['location_id'] : null,
                'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                'movement_type' => 'reserved',
                'quantity' => $toReserve,
                'prev_qty_on_hand' => (float)$stock['qty_on_hand'],
                'new_qty_on_hand' => (float)$stock['qty_on_hand'],
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by' => $actorUserId,
            ], $db);
            $movementIds[] = $movementId;
            $reserved += $toReserve;
        }

        if ($idempotencyKey !== '') {
            $db->execute('INSERT INTO wms_idempotency_keys (idempotency_key, movement_id, created_at) VALUES (:key, :mid, NOW())', [':key' => $idempotencyKey, ':mid' => $movementIds !== [] ? (int)end($movementIds) : 0]);
        }

        $db->commit();
        return ['ok' => true, 'reserved' => $reserved, 'movement_ids' => $movementIds];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function wms_cap_stock_release_1(mixed $payload): ?array
{
    if (!is_array($payload)) return null;

    // ── Batch (ecommerce bridge) payload ──────────────────────────────
    if (isset($payload['items']) && is_array($payload['items']) && $payload['items'] !== []) {
        return wmsCapStockReleaseBatch($payload);
    }

    // ── Single-item payload (backward compat / direct calls) ──────────
    $productId = (int)($payload['product_id'] ?? 0);
    $warehouseId = (int)($payload['warehouse_id'] ?? 0);
    $quantity = (float)($payload['quantity'] ?? 0);

    if ($productId <= 0 || $warehouseId <= 0 || $quantity <= 0) return null;

    try {
        $stock = wmsDb()->query(
            'SELECT id, qty_reserved FROM wms_stock WHERE product_id = :pid AND warehouse_id = :wid AND qty_reserved > 0 ORDER BY qty_reserved DESC LIMIT 1',
            [':pid' => $productId, ':wid' => $warehouseId]
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$stock) return ['ok' => false, 'released' => 0, 'message' => 'No reserved stock found.'];

        $toRelease = min($quantity, (float)$stock['qty_reserved']);
        wmsDb()->execute('UPDATE wms_stock SET qty_reserved = qty_reserved - :qty WHERE id = :id', [':qty' => $toRelease, ':id' => $stock['id']]);
        wmsStockMovementCreate([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'movement_type' => 'unreserved',
            'quantity' => $toRelease,
            'prev_qty_on_hand' => 0,
            'new_qty_on_hand' => 0,
            'reference_type' => 'reservation',
            'reference_id' => isset($payload['reference_id']) ? (int)$payload['reference_id'] : null,
        ]);
        return ['ok' => true, 'released' => $toRelease, 'stock_id' => (int)$stock['id']];
    } catch (\Throwable $e) {
        return null;
    }
}

function wmsCapStockReleaseBatch(array $payload): array
{
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $referenceType = trim((string)($payload['reference_type'] ?? 'order'));
    $referenceId = isset($payload['reference_id']) ? (int)$payload['reference_id'] : null;
    $idempotencyKey = trim((string)($payload['idempotency_key'] ?? ''));
    $actorUserId = isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null;

    if ($idempotencyKey !== '') {
        $existing = wmsDb()->query(
            'SELECT id FROM wms_idempotency_keys WHERE idempotency_key = :key LIMIT 1',
            [':key' => $idempotencyKey]
        )->fetch(\PDO::FETCH_ASSOC);
        if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
            return ['ok' => true, 'idempotent' => true, 'released' => 0];
        }
    }

    $released = 0.0;
    $movementIds = [];
    $db = wmsDb();
    $db->beginTransaction();
    try {
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $productId = (int)($item['product_id'] ?? 0);
            $sku = strtoupper(trim((string)($item['sku'] ?? '')));
            // Resolve by SKU when the provided id is not a real WMS product
            // (ecommerce SKU-bridge fallback).
            $wmsProductRow = $productId > 0
                ? $db->query('SELECT id FROM wms_products WHERE id = :id LIMIT 1', [':id' => $productId])->fetch(\PDO::FETCH_ASSOC)
                : null;
            if (!is_array($wmsProductRow) && $sku !== '') {
                $resolved = $db->query('SELECT id FROM wms_products WHERE UPPER(sku) = :sku LIMIT 1', [':sku' => $sku])->fetch(\PDO::FETCH_ASSOC);
                if (is_array($resolved) && (int)($resolved['id'] ?? 0) > 0) {
                    $productId = (int)$resolved['id'];
                }
            }
            if ($productId <= 0) continue;

            $warehouseId = (int)($item['warehouse_id'] ?? $payload['warehouse_id'] ?? 0);
            $quantity = (float)($item['qty'] ?? $item['quantity'] ?? $item['qty_ordered'] ?? 0);
            if ($warehouseId <= 0 || $quantity <= 0) continue;

            $stock = $db->query(
                'SELECT id, qty_on_hand, qty_reserved FROM wms_stock
                 WHERE product_id = :pid AND warehouse_id = :wid AND qty_reserved > 0
                 ORDER BY qty_reserved DESC LIMIT 1',
                [':pid' => $productId, ':wid' => $warehouseId]
            )->fetch(\PDO::FETCH_ASSOC);
            if (!$stock) continue;

            $toRelease = min($quantity, (float)$stock['qty_reserved']);
            if ($toRelease <= 0) continue;

            $db->execute('UPDATE wms_stock SET qty_reserved = qty_reserved - :qty WHERE id = :id', [':qty' => $toRelease, ':id' => $stock['id']]);
            $movementId = wmsStockMovementCreate([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'location_id' => isset($item['location_id']) ? (int)$item['location_id'] : null,
                'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                'movement_type' => 'unreserved',
                'quantity' => $toRelease,
                'prev_qty_on_hand' => 0,
                'new_qty_on_hand' => 0,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by' => $actorUserId,
            ], $db);
            $movementIds[] = $movementId;
            $released += $toRelease;
        }

        if ($idempotencyKey !== '') {
            $db->execute('INSERT INTO wms_idempotency_keys (idempotency_key, movement_id, created_at) VALUES (:key, :mid, NOW())', [':key' => $idempotencyKey, ':mid' => $movementIds !== [] ? (int)end($movementIds) : 0]);
        }

        $db->commit();
        return ['ok' => true, 'released' => $released, 'movement_ids' => $movementIds];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Insert a stock movement row into the current movement ledger
 * (wms_stock_movements). Accepts an optional PDO-ish DB handle so callers
 * inside a transaction can reuse it.
 */
function wmsStockMovementCreate(array $data, ?\Ikabud\Kernel\Contracts\ModuleDB $db = null): int
{
    $db = $db ?? wmsDb();
    $db->execute(
        'INSERT INTO wms_stock_movements (product_id, warehouse_id, from_location_id, to_location_id, batch_id, movement_type, quantity, prev_qty_on_hand, new_qty_on_hand, reference_type, reference_id, notes, created_by)
         VALUES (:pid, :wid, :fl, :tl, :bid, :type, :qty, :prev, :new, :ref_type, :ref_id, :notes, :uid)',
        [
            ':pid' => (int)($data['product_id'] ?? 0),
            ':wid' => (int)($data['warehouse_id'] ?? 0),
            ':fl' => isset($data['from_location_id']) ? (int)$data['from_location_id'] : null,
            ':tl' => isset($data['location_id']) ? (int)$data['location_id'] : null,
            ':bid' => isset($data['batch_id']) ? (int)$data['batch_id'] : null,
            ':type' => (string)($data['movement_type'] ?? 'adjustment'),
            ':qty' => (float)($data['quantity'] ?? 0),
            ':prev' => (float)($data['prev_qty_on_hand'] ?? 0),
            ':new' => (float)($data['new_qty_on_hand'] ?? 0),
            ':ref_type' => (string)($data['reference_type'] ?? ''),
            ':ref_id' => isset($data['reference_id']) ? (int)$data['reference_id'] : null,
            ':notes' => isset($data['notes']) ? (string)$data['notes'] : null,
            ':uid' => isset($data['created_by']) ? (int)$data['created_by'] : null,
        ]
    );
    return (int)$db->lastInsertId();
}

// ── Order capability handlers ──

function wms_cap_order_create_1(mixed $payload): ?array
{
    if (!is_array($payload)) return null;
    $items = $payload['items'] ?? [];
    $warehouseId = (int)($payload['warehouse_id'] ?? 0);
    if ($warehouseId <= 0 || !is_array($items) || count($items) === 0) return null;

    $externalReference = trim((string)($payload['external_reference'] ?? $payload['order_number'] ?? ''));

    // Idempotency by external reference (the ecommerce order_number).
    if ($externalReference !== '') {
        try {
            $existing = wmsDb()->query(
                'SELECT id FROM wms_orders WHERE external_reference = :er AND (deleted_at IS NULL OR deleted_at = 0) ORDER BY id DESC LIMIT 1',
                [':er' => $externalReference]
            )->fetch(\PDO::FETCH_ASSOC);
            if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                return ['ok' => true, 'existing' => true, 'order_id' => (int)$existing['id'], 'external_reference' => $externalReference];
            }
        } catch (\Throwable $e) {
            try {
                $existing = wmsDb()->query(
                    'SELECT id FROM wms_orders WHERE external_reference = :er ORDER BY id DESC LIMIT 1',
                    [':er' => $externalReference]
                )->fetch(\PDO::FETCH_ASSOC);
                if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                    return ['ok' => true, 'existing' => true, 'order_id' => (int)$existing['id'], 'external_reference' => $externalReference];
                }
            } catch (\Throwable $e2) {
                return ['ok' => false, 'error' => 'WMS order idempotency check failed: ' . $e2->getMessage()];
            }
        }
    }

    $orderNumber = trim((string)($payload['order_number'] ?? ''));
    if ($orderNumber === '') {
        $orderNumber = 'API-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    $customerName = trim((string)($payload['customer_name'] ?? ''));
    $orderedAt = trim((string)($payload['ordered_at'] ?? ''));
    $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
    $actorUserId = isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : 0;

    try {
        $db = wmsDb();
        $db->beginTransaction();

        $db->execute(
            'INSERT INTO wms_orders (order_number, external_reference, customer_name, warehouse_id, status, ordered_at, notes, meta, created_by)
             VALUES (:on, :er, :cn, :wid, :status, :oa, :notes, :meta, :uid)',
            [
                ':on' => $orderNumber,
                ':er' => $externalReference !== '' ? $externalReference : null,
                ':cn' => $customerName !== '' ? $customerName : null,
                ':wid' => $warehouseId,
                ':status' => 'pending',
                ':oa' => $orderedAt !== '' ? $orderedAt : date('Y-m-d H:i:s'),
                ':notes' => 'Created via ecommerce bridge',
                ':meta' => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':uid' => $actorUserId > 0 ? $actorUserId : 0,
            ]
        );
        $orderId = (int)$db->lastInsertId();

        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $pid = (int)($item['product_id'] ?? 0);
            $sku = strtoupper(trim((string)($item['sku'] ?? '')));
            // SKU-bridge fallback: resolve the WMS product by SKU when the
            // provided id is absent OR is not a real WMS product.
            $wmsProductRow = $pid > 0
                ? $db->query('SELECT id FROM wms_products WHERE id = :id LIMIT 1', [':id' => $pid])->fetch(\PDO::FETCH_ASSOC)
                : null;
            if (!is_array($wmsProductRow) && $sku !== '') {
                $resolved = $db->query('SELECT id FROM wms_products WHERE UPPER(sku) = :sku LIMIT 1', [':sku' => $sku])->fetch(\PDO::FETCH_ASSOC);
                if (is_array($resolved) && (int)($resolved['id'] ?? 0) > 0) {
                    $pid = (int)$resolved['id'];
                }
            }
            if ($pid <= 0) continue;

            $qty = (float)($item['qty_ordered'] ?? $item['qty'] ?? $item['quantity'] ?? 0);
            if ($qty <= 0) continue;

            $db->execute(
                'INSERT INTO wms_order_items (order_id, product_id, location_id, batch_id, qty_ordered, notes, meta)
                 VALUES (:oid, :pid, :lid, :bid, :qty, :notes, :meta)',
                [
                    ':oid' => $orderId,
                    ':pid' => $pid,
                    ':lid' => isset($item['location_id']) ? (int)$item['location_id'] : null,
                    ':bid' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                    ':qty' => $qty,
                    ':notes' => isset($item['notes']) ? (string)$item['notes'] : null,
                    ':meta' => null,
                ]
            );
        }

        $db->commit();
        return [
            'ok' => true,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'external_reference' => $externalReference !== '' ? $externalReference : null,
        ];
    } catch (\Throwable $e) {
        if (isset($db) && method_exists($db, 'inTransaction') && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function wms_cap_order_cancel_1(mixed $payload): ?array
{
    if (!is_array($payload)) return null;

    $orderId = (int)($payload['order_id'] ?? 0);
    $externalReference = trim((string)($payload['external_reference'] ?? ''));
    if ($orderId <= 0 && $externalReference === '') return null;

    try {
        $db = wmsDb();
        $order = null;
        if ($orderId > 0) {
            $order = $db->query('SELECT id, status FROM wms_orders WHERE id = :id', [':id' => $orderId])->fetch(\PDO::FETCH_ASSOC);
        }
        if ($order === null && $externalReference !== '') {
            $order = $db->query(
                'SELECT id, status FROM wms_orders WHERE external_reference = :er ORDER BY id DESC LIMIT 1',
                [':er' => $externalReference]
            )->fetch(\PDO::FETCH_ASSOC);
        }
        if (!$order) return ['ok' => true, 'missing' => true];
        if (in_array($order['status'], ['shipped', 'delivered', 'cancelled'], true)) {
            return ['ok' => true, 'order_id' => (int)$order['id'], 'already_cancelled' => true];
        }

        $db->beginTransaction();

        // Release reserved stock for order items (qty_reserved + movement).
        $items = $db->query('SELECT product_id, qty_reserved FROM wms_order_items WHERE order_id = :oid', [':oid' => $order['id']])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            $reserved = (float)($item['qty_reserved'] ?? 0);
            if ($reserved <= 0) continue;

            $stockRows = $db->query(
                'SELECT id, qty_reserved FROM wms_stock WHERE product_id = :pid AND qty_reserved > 0 ORDER BY qty_reserved DESC',
                [':pid' => $item['product_id']]
            )->fetchAll(\PDO::FETCH_ASSOC);
            $toRelease = $reserved;
            foreach ($stockRows as $sr) {
                $rel = min($toRelease, (float)$sr['qty_reserved']);
                $db->execute('UPDATE wms_stock SET qty_reserved = qty_reserved - :rel WHERE id = :id', [':rel' => $rel, ':id' => $sr['id']]);
                $toRelease -= $rel;
                if ($toRelease <= 0) break;
            }
            wmsStockMovementCreate([
                'product_id' => (int)$item['product_id'],
                'warehouse_id' => (int)($order['warehouse_id'] ?? 0),
                'movement_type' => 'unreserved',
                'quantity' => $reserved,
                'prev_qty_on_hand' => 0,
                'new_qty_on_hand' => 0,
                'reference_type' => 'order',
                'reference_id' => (int)$order['id'],
            ], $db);
        }

        $db->execute('UPDATE wms_orders SET status = :status WHERE id = :id', [':status' => 'cancelled', ':id' => $order['id']]);
        $db->commit();
        return ['ok' => true, 'cancelled' => true, 'order_id' => (int)$order['id']];
    } catch (\Throwable $e) {
        if (isset($db) && method_exists($db, 'inTransaction') && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Capability: wms.return.create@1
 *
 * Creates a pending WMS return record from an ecommerce return-request payload.
 * The ecommerce module declares wms.return.create@1 as a dependency and calls
 * this via ecReturnRequestSyncToWms() when a return request is approved.
 *
 * Payload shape (produced by ecReturnRequestBuildWmsPayload):
 * {
 *   "reference_number": string,   // ecommerce request_number
 *   "order_id": int,
 *   "customer_name": string,
 *   "warehouse_id": int,
 *   "reason": string,
 *   "notes": string,
 *   "meta": array,
 *   "actor_user_id": int|null,
 *   "items": [{ "product_id": int, "sku": string, "qty_returned": int, "condition": string, "notes": string }]
 * }
 *
 * Writes to the live WMS returns schema (wms_returns.reference_number,
 * wms_return_items.qty_returned + `condition` enum).
 */
function wms_cap_wms_return_create_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload. Array expected.'];
    }

    $referenceNumber = trim((string)($payload['reference_number'] ?? ''));
    if ($referenceNumber === '') {
        return ['ok' => false, 'error' => 'Reference number is required.'];
    }

    try {
        $db = wmsDb();
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'WMS database unavailable: ' . $e->getMessage()];
    }

    // Idempotency: an existing return for this reference is returned as-is.
    try {
        $existing = $db->query(
            'SELECT id, reference_number FROM wms_returns WHERE reference_number = :rn AND (deleted_at IS NULL OR deleted_at = 0) LIMIT 1',
            [':rn' => $referenceNumber]
        )->fetch(\PDO::FETCH_ASSOC);
        if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
            return [
                'ok' => true,
                'existing' => true,
                'return_id' => (int)$existing['id'],
                'reference_number' => (string)($existing['reference_number'] ?? $referenceNumber),
            ];
        }
    } catch (\Throwable $e) {
        // The live schema may not expose deleted_at; retry without the filter.
        try {
            $existing = $db->query(
                'SELECT id, reference_number FROM wms_returns WHERE reference_number = :rn LIMIT 1',
                [':rn' => $referenceNumber]
            )->fetch(\PDO::FETCH_ASSOC);
            if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                return [
                    'ok' => true,
                    'existing' => true,
                    'return_id' => (int)$existing['id'],
                    'reference_number' => (string)($existing['reference_number'] ?? $referenceNumber),
                ];
            }
        } catch (\Throwable $e2) {
            return ['ok' => false, 'error' => 'WMS return idempotency check failed: ' . $e2->getMessage()];
        }
    }

    $warehouseId = (int)($payload['warehouse_id'] ?? 0);
    if ($warehouseId <= 0) {
        return ['ok' => false, 'error' => 'Warehouse ID is required.'];
    }
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    if ($items === []) {
        return ['ok' => false, 'error' => 'At least one return item is required.'];
    }

    try {
        $db->beginTransaction();

        $orderId = (int)($payload['order_id'] ?? 0);
        $customerName = trim((string)($payload['customer_name'] ?? ''));
        $reason = trim((string)($payload['reason'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $actorUserId = (int)($payload['actor_user_id'] ?? 0);

        $db->execute(
            'INSERT INTO wms_returns (reference_number, order_id, customer_name, warehouse_id, status, reason, received_at, notes, meta, created_by, created_at, updated_at)
             VALUES (:rn, :oid, :cn, :wid, :status, :reason, NOW(), :notes, :meta, :uid, NOW(), NOW())',
            [
                ':rn' => $referenceNumber,
                ':oid' => $orderId > 0 ? $orderId : null,
                ':cn' => $customerName !== '' ? $customerName : null,
                ':wid' => $warehouseId,
                ':status' => 'pending',
                ':reason' => $reason !== '' ? $reason : null,
                ':notes' => $notes !== '' ? $notes : null,
                ':meta' => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':uid' => $actorUserId > 0 ? $actorUserId : 0,
            ]
        );
        $returnId = (int)$db->lastInsertId();

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int)($item['product_id'] ?? 0);
            $sku = strtoupper(trim((string)($item['sku'] ?? '')));
            // The caller (ecommerce) sends its own product_id which is NOT a
            // wms_products row. Resolve the WMS product by SKU whenever the
            // provided id is absent OR does not reference an existing WMS
            // product (mirrors wmsBridgeResolveProductId).
            $wmsProductRow = $productId > 0
                ? $db->query('SELECT id FROM wms_products WHERE id = :id LIMIT 1', [':id' => $productId])->fetch(\PDO::FETCH_ASSOC)
                : null;
            if (!is_array($wmsProductRow) && $sku !== '') {
                $resolved = $db->query('SELECT id FROM wms_products WHERE UPPER(sku) = :sku LIMIT 1', [':sku' => $sku])->fetch(\PDO::FETCH_ASSOC);
                if (is_array($resolved) && (int)($resolved['id'] ?? 0) > 0) {
                    $productId = (int)$resolved['id'];
                }
            }
            if ($productId <= 0) {
                throw new \RuntimeException('Return item has no resolvable WMS product.');
            }

            $qtyReturned = (float)($item['qty_returned'] ?? $item['quantity'] ?? $item['qty'] ?? 0);
            if ($qtyReturned <= 0) {
                throw new \RuntimeException('Return item quantity must be greater than zero.');
            }

            $condition = in_array((string)($item['condition'] ?? ''), ['good', 'damaged', 'expired', 'unknown'], true)
                ? (string)$item['condition']
                : 'unknown';

            // Live schema requires location_id (no default). Resolve a
            // warehouse location when the caller did not supply one.
            $locationId = isset($item['location_id']) ? (int)$item['location_id'] : 0;
            if ($locationId <= 0) {
                $loc = $db->query(
                    'SELECT id FROM wms_locations WHERE warehouse_id = :wid AND is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1',
                    [':wid' => $warehouseId]
                )->fetch(\PDO::FETCH_ASSOC);
                if (is_array($loc) && (int)($loc['id'] ?? 0) > 0) {
                    $locationId = (int)$loc['id'];
                }
            }

            $db->execute(
                'INSERT INTO wms_return_items (return_id, product_id, location_id, batch_id, qty_returned, `condition`, notes)
                 VALUES (:rid, :pid, :lid, :bid, :qty, :cond, :notes)',
                [
                    ':rid' => $returnId,
                    ':pid' => $productId,
                    ':lid' => $locationId > 0 ? $locationId : null,
                    ':bid' => isset($item['batch_id']) && (int)$item['batch_id'] > 0 ? (int)$item['batch_id'] : null,
                    ':qty' => $qtyReturned,
                    ':cond' => $condition,
                    ':notes' => trim((string)($item['notes'] ?? '')) !== '' ? trim((string)$item['notes']) : null,
                ]
            );
        }

        $db->commit();
        return [
            'ok' => true,
            'return_id' => $returnId,
            'reference_number' => $referenceNumber,
        ];
    } catch (\Throwable $e) {
        if (method_exists($db, 'inTransaction') && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ── Core helpers ──

function wmsBaseUrl(): string
{
    return rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
}

function wmsExternalBaseUrl(): string
{
    return external_base_url((string)config('app.url', ''));
}

function wmsCookieName(): string
{
    return 'wms_token';
}

function wmsSetAuthCookie(string $token, int $expiresInSeconds = 86400): void
{
    $expiry = time() + max(60, $expiresInSeconds);
    setcookie(wmsCookieName(), $token, [
        'expires'  => $expiry,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

function wmsClearAuthCookie(): void
{
    setcookie(wmsCookieName(), '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => config('cookie.samesite', 'Strict'),
    ]);
}

function wmsCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('wms');
    if ($ctx === null) {
        throw new \RuntimeException('WMS module context not available.');
    }
    return $ctx;
}

function wmsDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return wmsCtx()->db();
}

function wmsInput(): mixed
{
    return wmsCtx()->input();
}

function wmsRender(string $template, array $context = []): string
{
    return app()->render($template, $context);
}

function wmsJsonOk(array $extra = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

function wmsJsonError(string $message, int $status = 422): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function wmsUser(): ?array
{
    return app()->user();
}

function wmsSettings(): array
{
    static $settings = null;
    if ($settings !== null) return $settings;

    try {
        $stmt = wmsDb()->prepare('SELECT config_key, config_value FROM wms_configs');
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['config_key']] = $r['config_value'];
        }
    } catch (\Throwable $e) {
        $settings = [];
    }
    return $settings;
}

function wmsConfigGet(string $key, mixed $default = null): mixed
{
    $s = wmsSettings();
    return array_key_exists($key, $s) ? $s[$key] : $default;
}

function wmsConfigSet(string $key, string $value): void
{
    $stmt = wmsDb()->prepare(
        'INSERT INTO wms_configs (config_key, config_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE config_value = :v2'
    );
    $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
    // Reset cache
    $GLOBALS['__wms_settings_cache'] = null;
}

function wmsLoginPageContext(array $overrides = []): array
{
    $baseUrl = wmsBaseUrl();
    return array_merge([
        'page_title'                => 'WMS Sign In',
        'brand_mark_html'           => '<span>W</span>',
        'login_logo_html'           => '<span>W</span>',
        'login_brand_text'          => 'WMS Console',
        'login_subtitle'            => 'Sign in to manage warehouse operations',
        'login_username_label'      => 'Username or Email',
        'login_endpoint'            => $baseUrl . '/wms/auth/login',
        'login_button_text'         => 'Access WMS',
        'login_loading_text'        => 'Signing in...',
        'login_brand_html'          => '<span>WMS</span> Console',
        'login_forgot_url'          => $baseUrl . '/wms/forgot-password',
        'login_forgot_text'         => 'Forgot password?',
        'gui' => [
            'app_name'        => 'WMS Console',
            'app_name_accent' => 'WMS',
            'app_name_rest'   => 'Console',
            'font_url'        => '',
            'font_family'     => 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'color_primary'       => '#2563eb',
            'color_primary_hover' => '#1d4ed8',
            'color_primary_light' => 'rgba(37, 99, 235, 0.18)',
            'color_bg'            => 'linear-gradient(135deg, #0f172a 0%, #0b1120 45%, #1e3a8a 100%)',
            'color_surface'       => '#ffffff',
            'color_border'        => '#e5e7eb',
            'color_text'          => '#111827',
            'color_text_muted'    => '#6b7280',
        ],
    ], $overrides);
}

function wmsPasswordResetTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function wmsRenderTemplate(string $pageContent, array $extra = []): void
{
    $user = wmsUser();
    $settings = wmsSettings();
    $baseUrl = wmsBaseUrl();

    $context = array_merge([
        'current_user' => $user,
        'settings' => $settings,
        'base_url' => $baseUrl,
        'page_content' => $pageContent,
        'menu_items' => wmsNavItems($user['role'] ?? ''),
        'page_title' => $extra['page_title'] ?? 'WMS',
    ], $extra);

    // Render the page template as a string, pass as page_body
    $pageTemplate = __DIR__ . '/templates/pages/' . $pageContent . '.disyl';
    if (file_exists($pageTemplate)) {
        $context['page_body'] = app()->render($pageTemplate, $context);
    } else {
        $context['page_body'] = '<div class="p-8 text-center text-gray-400 text-sm">Page not found: ' . htmlspecialchars($pageContent, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    echo app()->render('modules/wms/layouts/admin.disyl', $context);
}

// ── Home URL hook for WMS ──
try {
    app()->hooks()->on('kernel.home_url', function ($url, $role, $user) {
        if (is_array($user) && ($user['source'] ?? '') === 'wms') {
            return '/wms';
        }
        return $url;
    });
} catch (\Throwable $e) {
    // Hook registration not available yet — will be picked up on next request
}
