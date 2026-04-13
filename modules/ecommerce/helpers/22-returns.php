<?php

declare(strict_types=1);

function ecReturnRequestStorageAvailable(): bool
{
    static $ready = null;
    if ($ready === true) {
        return $ready;
    }

    $ready = ecTableExists('ec_return_requests') && ecTableExists('ec_return_request_items');
    return $ready;
}

function ecReturnRequestStatuses(): array
{
    return ['pending', 'approved', 'rejected', 'cancelled'];
}

function ecReturnRequestActiveStatuses(): array
{
    return ['pending', 'approved'];
}

function ecReturnRequestNormalizeStatus(mixed $status): string
{
    $status = strtolower(trim((string)$status));
    return in_array($status, ecReturnRequestStatuses(), true) ? $status : 'pending';
}

function ecReturnRequestConditionOptions(): array
{
    return ['unknown', 'good', 'damaged', 'expired'];
}

function ecReturnRequestNormalizeCondition(mixed $condition): string
{
    $condition = strtolower(trim((string)$condition));
    return in_array($condition, ecReturnRequestConditionOptions(), true) ? $condition : 'unknown';
}

function ecReturnRequestGenerateNumber(): string
{
    $year = date('Y');

    try {
        $count = (int)ecDb()->query(
            'SELECT COUNT(*) FROM ec_return_requests WHERE YEAR(created_at) = ?',
            [$year]
        )->fetchColumn();
    } catch (\Throwable $e) {
        $count = 0;
    }

    return 'RET-' . $year . '-' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
}

function ecReturnRequestDecodeMeta(mixed $meta): array
{
    if (is_array($meta)) {
        return $meta;
    }
    if (!is_string($meta) || trim($meta) === '') {
        return [];
    }

    $decoded = json_decode($meta, true);
    return is_array($decoded) ? $decoded : [];
}

function ecReturnRequestItemRows(int $requestId): array
{
    if ($requestId <= 0 || !ecReturnRequestStorageAvailable()) {
        return [];
    }

    try {
        $rows = ecDb()->query(
            'SELECT rri.id, rri.request_id, rri.order_item_id, rri.product_id, rri.product_title, rri.sku, rri.qty_requested, rri.condition_code, rri.notes, rri.created_at, rri.updated_at,
                    oi.store_id
             FROM ec_return_request_items rri
             LEFT JOIN ec_order_items oi ON oi.id = rri.order_item_id
             WHERE rri.request_id = ?
             ORDER BY rri.id ASC',
            [$requestId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    return array_map(static function (array $row): array {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['request_id'] = (int)($row['request_id'] ?? 0);
        $row['order_item_id'] = (int)($row['order_item_id'] ?? 0);
        $row['product_id'] = (int)($row['product_id'] ?? 0);
        $row['store_id'] = max(0, (int)($row['store_id'] ?? 0));
        $row['qty_requested'] = max(0, (int)($row['qty_requested'] ?? 0));
        $row['condition_code'] = ecReturnRequestNormalizeCondition($row['condition_code'] ?? 'unknown');
        $row['condition_label'] = ucfirst((string)$row['condition_code']);
        $row['product_title'] = trim((string)($row['product_title'] ?? 'Product'));
        $row['sku'] = trim((string)($row['sku'] ?? ''));
        $row['notes'] = trim((string)($row['notes'] ?? ''));
        return $row;
    }, $rows);
}

function ecReturnRequestBelongsToStore(int $requestId, int $storeId): bool
{
    if ($requestId <= 0 || $storeId <= 0 || !ecReturnRequestStorageAvailable()) {
        return false;
    }

    try {
        return (int)ecDb()->query(
            'SELECT COUNT(*)
             FROM ec_return_request_items rri
             INNER JOIN ec_order_items oi ON oi.id = rri.order_item_id
             WHERE rri.request_id = ? AND oi.store_id = ?',
            [$requestId, $storeId]
        )->fetchColumn() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

function ecReturnRequestFilterRowToStore(array $row, int $storeId): array
{
    if ($storeId <= 0) {
        return $row;
    }

    $items = array_values(array_filter((array)($row['items'] ?? []), static function (array $item) use ($storeId): bool {
        return (int)($item['store_id'] ?? 0) === $storeId;
    }));

    $row['items'] = $items;
    $row['item_count'] = count($items);
    $row['total_qty_requested'] = array_reduce($items, static function (int $carry, array $item): int {
        return $carry + max(0, (int)($item['qty_requested'] ?? 0));
    }, 0);

    return $row;
}

function ecReturnRequestRow(array $row): array
{
    $row['id'] = (int)($row['id'] ?? 0);
    $row['order_id'] = (int)($row['order_id'] ?? 0);
    $row['customer_id'] = isset($row['customer_id']) && $row['customer_id'] !== null ? (int)$row['customer_id'] : null;
    $row['reviewed_by_user_id'] = isset($row['reviewed_by_user_id']) && $row['reviewed_by_user_id'] !== null ? (int)$row['reviewed_by_user_id'] : null;
    $row['wms_return_id'] = isset($row['wms_return_id']) && $row['wms_return_id'] !== null ? (int)$row['wms_return_id'] : null;
    $row['request_number'] = trim((string)($row['request_number'] ?? ''));
    $row['status'] = ecReturnRequestNormalizeStatus($row['status'] ?? 'pending');
    $row['reason'] = trim((string)($row['reason'] ?? ''));
    $row['condition_code'] = ecReturnRequestNormalizeCondition($row['condition_code'] ?? 'unknown');
    $row['condition_label'] = ucfirst((string)$row['condition_code']);
    $row['customer_note'] = trim((string)($row['customer_note'] ?? ''));
    $row['admin_note'] = trim((string)($row['admin_note'] ?? ''));
    $row['wms_reference_number'] = trim((string)($row['wms_reference_number'] ?? ''));
    $row['meta'] = ecReturnRequestDecodeMeta($row['meta'] ?? null);
    $row['items'] = array_values(is_array($row['items'] ?? null) ? $row['items'] : ecReturnRequestItemRows((int)$row['id']));
    $row['item_count'] = count($row['items']);
    $row['total_qty_requested'] = array_reduce($row['items'], static function (int $carry, array $item): int {
        return $carry + max(0, (int)($item['qty_requested'] ?? 0));
    }, 0);
    $row['created_by_name'] = trim((string)($row['created_by_name'] ?? ''));
    $row['reviewed_by_name'] = trim((string)($row['reviewed_by_name'] ?? ''));
    $row['has_wms_return'] = (int)($row['wms_return_id'] ?? 0) > 0 || $row['wms_reference_number'] !== '';
    $row['wms_sync_error'] = trim((string)($row['meta']['wms_sync_error'] ?? ''));

    return $row;
}

function ecReturnRequestGet(int $requestId): ?array
{
    if ($requestId <= 0 || !ecReturnRequestStorageAvailable()) {
        return null;
    }

    try {
        $row = ecDb()->query(
            'SELECT rr.*, o.order_number,
                    creator.display_name AS created_by_name,
                    reviewer.display_name AS reviewed_by_name
             FROM ec_return_requests rr
             INNER JOIN ec_orders o ON o.id = rr.order_id
             LEFT JOIN cms_users creator ON creator.id = rr.customer_id
             LEFT JOIN cms_users reviewer ON reviewer.id = rr.reviewed_by_user_id
             WHERE rr.id = ?
             LIMIT 1',
            [$requestId]
        )->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }

    return is_array($row) ? ecReturnRequestRow($row) : null;
}

function ecReturnRequestRowsForOrder(int $orderId): array
{
    if ($orderId <= 0 || !ecReturnRequestStorageAvailable()) {
        return [];
    }

    try {
        $rows = ecDb()->query(
            'SELECT rr.*, o.order_number,
                    creator.display_name AS created_by_name,
                    reviewer.display_name AS reviewed_by_name
             FROM ec_return_requests rr
             INNER JOIN ec_orders o ON o.id = rr.order_id
             LEFT JOIN cms_users creator ON creator.id = rr.customer_id
             LEFT JOIN cms_users reviewer ON reviewer.id = rr.reviewed_by_user_id
             WHERE rr.order_id = ?
             ORDER BY rr.created_at DESC, rr.id DESC',
            [$orderId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    return array_map('ecReturnRequestRow', $rows);
}

function ecReturnRequestedItemQuantities(array $requests, ?array $statuses = null): array
{
    $statuses = $statuses !== null ? array_values(array_map('ecReturnRequestNormalizeStatus', $statuses)) : ecReturnRequestActiveStatuses();
    $quantities = [];

    foreach ($requests as $request) {
        if (!is_array($request)) {
            continue;
        }
        if (!in_array((string)($request['status'] ?? 'pending'), $statuses, true)) {
            continue;
        }

        foreach ((array)($request['items'] ?? []) as $item) {
            $orderItemId = (int)($item['order_item_id'] ?? 0);
            if ($orderItemId < 1) {
                continue;
            }

            $quantities[$orderItemId] = (int)($quantities[$orderItemId] ?? 0) + max(0, (int)($item['qty_requested'] ?? 0));
        }
    }

    return $quantities;
}

function ecOrderHydrateReturnRequests(array $order): array
{
    $orderId = (int)($order['id'] ?? 0);
    $requests = ecReturnRequestRowsForOrder($orderId);
    $requestedQuantities = ecReturnRequestedItemQuantities($requests);
    $pendingCount = 0;
    $approvedCount = 0;
    $totalReturnableQty = 0;

    $order['items'] = is_array($order['items'] ?? null) ? $order['items'] : [];

    foreach ($requests as $request) {
        if ((string)($request['status'] ?? '') === 'pending') {
            $pendingCount++;
        } elseif ((string)($request['status'] ?? '') === 'approved') {
            $approvedCount++;
        }
    }

    foreach ($order['items'] as &$item) {
        $orderItemId = (int)($item['id'] ?? 0);
        $requestedQty = (int)($requestedQuantities[$orderItemId] ?? 0);
        $orderedQty = max(0, (int)($item['qty'] ?? 0));
        $refundedQty = max(0, (int)($item['refunded_qty'] ?? 0));
        $eligibleQty = max(0, $orderedQty - $refundedQty);
        $returnableQty = max(0, $eligibleQty - $requestedQty);

        $item['requested_return_qty'] = $requestedQty;
        $item['returnable_qty'] = $returnableQty;
        $item['returnable'] = $returnableQty > 0;
        $totalReturnableQty += $returnableQty;
    }
    unset($item);

    $status = (string)($order['status'] ?? '');
    $order['return_requests'] = $requests;
    $order['has_return_requests'] = $requests !== [];
    $order['return_summary'] = [
        'request_count' => count($requests),
        'pending_count' => $pendingCount,
        'approved_count' => $approvedCount,
        'total_returnable_qty' => $totalReturnableQty,
        'can_request' => in_array($status, ['delivered', 'refunded'], true) && $totalReturnableQty > 0,
    ];

    return $order;
}

function ecReturnRequestPersistMeta(int $requestId, array $meta): void
{
    ecDb()->execute(
        'UPDATE ec_return_requests SET meta = ?, updated_at = NOW() WHERE id = ? LIMIT 1',
        [json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $requestId]
    );
}

function ecReturnRequestCreate(int $orderId, int $customerId, array $returnItems, array $options = []): array
{
    if (!ecReturnRequestStorageAvailable()) {
        throw new \RuntimeException('Return request storage is unavailable.');
    }

    $order = ecOrderGet($orderId, $customerId);
    if (!is_array($order)) {
        throw new \InvalidArgumentException('Order not found.');
    }
    if (!in_array((string)($order['status'] ?? ''), ['delivered', 'refunded'], true)) {
        throw new \InvalidArgumentException('Returns can only be requested after delivery.');
    }

    $reason = trim((string)($options['reason'] ?? ''));
    if ($reason === '') {
        throw new \InvalidArgumentException('Return reason is required.');
    }

    $conditionCode = ecReturnRequestNormalizeCondition($options['condition'] ?? 'unknown');
    $customerNote = trim((string)($options['customer_note'] ?? ''));
    $existingRequests = ecReturnRequestRowsForOrder($orderId);
    $activeRequestedQuantities = ecReturnRequestedItemQuantities($existingRequests);
    $orderItemsById = [];

    foreach ((array)($order['items'] ?? []) as $item) {
        $orderItemsById[(int)($item['id'] ?? 0)] = $item;
    }

    $normalizedItems = [];
    foreach ($returnItems as $orderItemId => $qtyValue) {
        $normalizedOrderItemId = (int)$orderItemId;
        $qtyRequested = max(0, (int)$qtyValue);
        if ($normalizedOrderItemId < 1 || $qtyRequested < 1) {
            continue;
        }

        $orderItem = $orderItemsById[$normalizedOrderItemId] ?? null;
        if (!is_array($orderItem)) {
            throw new \InvalidArgumentException('A selected return item could not be matched to this order.');
        }

        $orderedQty = max(0, (int)($orderItem['qty'] ?? 0));
        $refundedQty = max(0, (int)($orderItem['refunded_qty'] ?? 0));
        $alreadyRequestedQty = max(0, (int)($activeRequestedQuantities[$normalizedOrderItemId] ?? 0));
        $maxReturnableQty = max(0, $orderedQty - $refundedQty - $alreadyRequestedQty);
        if ($qtyRequested > $maxReturnableQty) {
            throw new \InvalidArgumentException('Requested return quantity exceeds the remaining eligible quantity for an item.');
        }

        $normalizedItems[] = [
            'order_item_id' => $normalizedOrderItemId,
            'product_id' => (int)($orderItem['product_id'] ?? 0),
            'product_title' => trim((string)($orderItem['product_title'] ?? 'Product')),
            'sku' => trim((string)($orderItem['sku'] ?? '')),
            'qty_requested' => $qtyRequested,
            'condition_code' => $conditionCode,
            'notes' => '',
        ];
    }

    if ($normalizedItems === []) {
        throw new \InvalidArgumentException('Select at least one item quantity to request a return.');
    }

    $db = ecDb();
    $db->beginTransaction();
    try {
        $requestNumber = ecReturnRequestGenerateNumber();
        $meta = ['source' => 'customer_portal'];
        $db->execute(
            'INSERT INTO ec_return_requests (
                order_id, customer_id, request_number, status, reason, condition_code,
                customer_note, admin_note, reviewed_by_user_id, reviewed_at,
                wms_return_id, wms_reference_number, meta, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, ?, NOW(), NOW())',
            [
                $orderId,
                $customerId > 0 ? $customerId : null,
                $requestNumber,
                'pending',
                $reason,
                $conditionCode,
                $customerNote !== '' ? $customerNote : null,
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        $requestId = (int)$db->lastInsertId();

        foreach ($normalizedItems as $item) {
            $db->execute(
                'INSERT INTO ec_return_request_items (
                    request_id, order_item_id, product_id, product_title, sku,
                    qty_requested, condition_code, notes, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $requestId,
                    $item['order_item_id'],
                    $item['product_id'],
                    $item['product_title'],
                    $item['sku'] !== '' ? $item['sku'] : null,
                    $item['qty_requested'],
                    $item['condition_code'],
                    $item['notes'] !== '' ? $item['notes'] : null,
                ]
            );
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    ecOrderRecordStatusHistory($orderId, (string)($order['status'] ?? 'delivered'), [
        'source' => 'customer_return',
        'note' => 'Return request ' . $requestNumber . ' submitted.',
        'actor_user_id' => $customerId > 0 ? $customerId : null,
        'history_key' => 'return_request:' . $requestId,
        'meta' => ['return_request_id' => $requestId, 'request_number' => $requestNumber],
    ]);

    $request = ecReturnRequestGet($requestId);
    if ($request === null) {
        throw new \RuntimeException('Return request could not be reloaded.');
    }

    try {
        app()->events()->fire('ecommerce.return.requested', [
            'order_id' => $orderId,
            'order_number' => (string)($order['order_number'] ?? ''),
            'customer_id' => $customerId,
            'return_request' => $request,
            'order' => ecOrderBridgeSnapshot($order),
        ]);
    } catch (\Throwable $e) {
    }

    return [
        'request' => $request,
        'order' => ecOrderGet($orderId, $customerId) ?: $order,
    ];
}

function ecReturnRequestBuildWmsPayload(array $request, array $order, ?int $actorUserId = null): ?array
{
    $bridgeOrder = ecOrderBridgeSnapshot($order);
    $warehouseId = (int)($bridgeOrder['warehouse_id'] ?? 0);
    if ($warehouseId <= 0) {
        return null;
    }

    $customerName = trim((string)($bridgeOrder['customer_name'] ?? ''));
    if ($customerName === '') {
        $customerName = trim((string)($order['billing']['first_name'] ?? '') . ' ' . (string)($order['billing']['last_name'] ?? ''));
    }

    $items = [];
    foreach ((array)($request['items'] ?? []) as $item) {
        $qtyRequested = max(0, (int)($item['qty_requested'] ?? 0));
        if ($qtyRequested < 1) {
            continue;
        }

        $items[] = [
            'product_id' => (int)($item['product_id'] ?? 0),
            'sku' => trim((string)($item['sku'] ?? '')),
            'qty_returned' => $qtyRequested,
            'condition' => ecReturnRequestNormalizeCondition($item['condition_code'] ?? $request['condition_code'] ?? 'unknown'),
            'notes' => trim((string)($item['notes'] ?? '')),
        ];
    }

    if ($items === []) {
        return null;
    }

    return [
        'reference_number' => (string)($request['request_number'] ?? ''),
        'order_id' => (int)($order['id'] ?? 0),
        'customer_name' => $customerName,
        'warehouse_id' => $warehouseId,
        'reason' => (string)($request['reason'] ?? ''),
        'notes' => trim((string)($request['customer_note'] ?? '')),
        'items' => $items,
        'meta' => [
            'source_module' => 'ecommerce',
            'ecommerce_order_id' => (int)($order['id'] ?? 0),
            'ecommerce_order_number' => (string)($order['order_number'] ?? ''),
            'ecommerce_return_request_id' => (int)($request['id'] ?? 0),
            'customer_email' => (string)($order['customer_email'] ?? $order['guest_email'] ?? ''),
        ],
        'actor_user_id' => $actorUserId,
    ];
}

function ecReturnRequestSyncToWms(array $request, array $order, ?int $actorUserId = null): array
{
    if ((int)($request['wms_return_id'] ?? 0) > 0) {
        return ['ok' => true, 'existing' => true, 'return_id' => (int)$request['wms_return_id']];
    }

    $payload = ecReturnRequestBuildWmsPayload($request, $order, $actorUserId);
    if ($payload === null) {
        return ['ok' => false, 'skipped' => true, 'error' => 'No warehouse return payload could be resolved for this request.'];
    }

    $result = null;
    if (app()->capabilities()->has('wms.return.create@1')) {
        $result = app()->cap()->call('wms.return.create@1', $payload);
    } elseif (function_exists('wms_cap_wms_return_create_1')) {
        $result = wms_cap_wms_return_create_1($payload, 'wms.return.create@1', 'wms');
    } else {
        return ['ok' => false, 'skipped' => true, 'error' => 'WMS return capability is unavailable.'];
    }

    if (!is_array($result) || empty($result['ok'])) {
        return [
            'ok' => false,
            'error' => is_array($result) ? (string)($result['error'] ?? 'WMS return creation failed.') : 'WMS return creation failed.',
        ];
    }

    return [
        'ok' => true,
        'return_id' => isset($result['return_id']) ? (int)$result['return_id'] : null,
        'reference_number' => (string)($result['reference_number'] ?? ($payload['reference_number'] ?? '')),
    ];
}

function ecReturnRequestReview(int $requestId, string $status, array $options = []): array
{
    if (!ecReturnRequestStorageAvailable()) {
        throw new \RuntimeException('Return request storage is unavailable.');
    }

    $status = ecReturnRequestNormalizeStatus($status);
    if (!in_array($status, ['approved', 'rejected', 'cancelled'], true)) {
        throw new \InvalidArgumentException('Invalid return request review status.');
    }

    $request = ecReturnRequestGet($requestId);
    if ($request === null) {
        throw new \InvalidArgumentException('Return request not found.');
    }
    if ((string)($request['status'] ?? '') !== 'pending') {
        throw new \InvalidArgumentException('Only pending return requests can be reviewed.');
    }

    $order = ecOrderGet((int)$request['order_id']);
    if (!is_array($order)) {
        throw new \RuntimeException('Return request order could not be loaded.');
    }

    $reviewedByUserId = isset($options['reviewed_by_user_id']) && (int)($options['reviewed_by_user_id'] ?? 0) > 0
        ? (int)$options['reviewed_by_user_id']
        : null;
    $adminNote = trim((string)($options['admin_note'] ?? ''));
    $meta = is_array($request['meta'] ?? null) ? $request['meta'] : [];

    ecDb()->execute(
        'UPDATE ec_return_requests
         SET status = ?, admin_note = ?, reviewed_by_user_id = ?, reviewed_at = NOW(), updated_at = NOW()
         WHERE id = ? LIMIT 1',
        [
            $status,
            $adminNote !== '' ? $adminNote : null,
            $reviewedByUserId,
            $requestId,
        ]
    );

    $wmsSync = ['ok' => false, 'skipped' => true, 'error' => 'Not requested.'];
    if ($status === 'approved') {
        $wmsSync = ecReturnRequestSyncToWms($request, $order, $reviewedByUserId);
        if (!empty($wmsSync['ok'])) {
            ecDb()->execute(
                'UPDATE ec_return_requests SET wms_return_id = ?, wms_reference_number = ?, updated_at = NOW() WHERE id = ? LIMIT 1',
                [
                    isset($wmsSync['return_id']) && (int)($wmsSync['return_id'] ?? 0) > 0 ? (int)$wmsSync['return_id'] : null,
                    trim((string)($wmsSync['reference_number'] ?? '')) !== '' ? (string)$wmsSync['reference_number'] : null,
                    $requestId,
                ]
            );
            $meta['wms_sync_status'] = 'synced';
        } else {
            $meta['wms_sync_status'] = !empty($wmsSync['skipped']) ? 'skipped' : 'failed';
            $meta['wms_sync_error'] = (string)($wmsSync['error'] ?? 'WMS return sync failed.');
        }
    }

    ecReturnRequestPersistMeta($requestId, $meta);

    $updatedRequest = ecReturnRequestGet($requestId);
    if ($updatedRequest === null) {
        throw new \RuntimeException('Return request could not be reloaded after review.');
    }

    $historyNote = 'Return request ' . (string)($updatedRequest['request_number'] ?? '') . ' ' . $status . '.';
    if ($adminNote !== '') {
        $historyNote .= ' ' . $adminNote;
    }

    ecOrderRecordStatusHistory((int)$request['order_id'], (string)($order['status'] ?? 'delivered'), [
        'source' => 'ecommerce_returns',
        'note' => $historyNote,
        'actor_user_id' => $reviewedByUserId,
        'history_key' => 'return_request_review:' . $requestId . ':' . $status,
        'meta' => [
            'return_request_id' => $requestId,
            'request_number' => (string)($updatedRequest['request_number'] ?? ''),
            'review_status' => $status,
            'wms_return_id' => $updatedRequest['wms_return_id'] ?? null,
        ],
    ]);

    try {
        app()->events()->fire('ecommerce.return.' . $status, [
            'order_id' => (int)($order['id'] ?? 0),
            'order_number' => (string)($order['order_number'] ?? ''),
            'actor_user_id' => $reviewedByUserId,
            'return_request' => $updatedRequest,
            'order' => ecOrderBridgeSnapshot($order),
        ]);
    } catch (\Throwable $e) {
    }

    return [
        'request' => $updatedRequest,
        'order' => ecOrderGet((int)$request['order_id']) ?: $order,
        'wms_sync' => $wmsSync,
    ];
}

function ecReturnRequestList(array $filters = []): array
{
    if (!ecReturnRequestStorageAvailable()) {
        return ['items' => [], 'total' => 0];
    }

    $where = [];
    $params = [];

    $status = ecReturnRequestNormalizeStatus($filters['status'] ?? '');
    $storeId = max(0, (int)($filters['store_id'] ?? 0));
    if (($filters['status'] ?? '') !== '') {
        $where[] = 'rr.status = ?';
        $params[] = $status;
    }

    if ($storeId > 0) {
        $where[] = 'EXISTS (
            SELECT 1
            FROM ec_return_request_items rri_filter
            INNER JOIN ec_order_items oi_filter ON oi_filter.id = rri_filter.order_item_id
            WHERE rri_filter.request_id = rr.id
              AND oi_filter.store_id = ?
        )';
        $params[] = $storeId;
    }

    $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';
    $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
    $offset = max(0, (int)($filters['offset'] ?? 0));

    try {
        $total = (int)ecDb()->query(
            'SELECT COUNT(*)
             FROM ec_return_requests rr
             ' . $whereSql,
            $params
        )->fetchColumn();

        $rows = ecDb()->query(
            'SELECT rr.*, o.order_number, o.status AS order_status,
                    creator.display_name AS created_by_name,
                    reviewer.display_name AS reviewed_by_name,
                    COALESCE(NULLIF(CONCAT(TRIM(om1.meta_value), " ", TRIM(om2.meta_value)), ""), creator.display_name, o.guest_name, "Customer") AS customer_name
             FROM ec_return_requests rr
             INNER JOIN ec_orders o ON o.id = rr.order_id
             LEFT JOIN cms_users creator ON creator.id = rr.customer_id
             LEFT JOIN cms_users reviewer ON reviewer.id = rr.reviewed_by_user_id
             LEFT JOIN ec_order_meta om1 ON om1.order_id = o.id AND om1.meta_key = "billing_first_name"
             LEFT JOIN ec_order_meta om2 ON om2.order_id = o.id AND om2.meta_key = "billing_last_name"
             ' . $whereSql . '
             ORDER BY rr.created_at DESC, rr.id DESC
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset])
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return ['items' => [], 'total' => 0];
    }

    $items = array_map('ecReturnRequestRow', $rows);
    if ($storeId > 0) {
        $items = array_map(static function (array $row) use ($storeId): array {
            return ecReturnRequestFilterRowToStore($row, $storeId);
        }, $items);
    }

    return [
        'items' => $items,
        'total' => $total,
    ];
}