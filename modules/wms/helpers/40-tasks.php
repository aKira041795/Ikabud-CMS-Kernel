<?php

declare(strict_types=1);

function wmsTaskTypes(): array
{
    return ['putaway', 'pick', 'transfer', 'count', 'replenish'];
}

function wmsTaskStatuses(): array
{
    return ['pending', 'in_progress', 'completed', 'cancelled'];
}

function wmsTaskExceptionStatuses(): array
{
    return ['open', 'resolved'];
}

function wmsTaskExceptionDispositionTypes(): array
{
    return ['quarantine', 'mark_damaged', 'release_reservation'];
}

function wmsTaskNormalizeScanToken(mixed $value): string
{
    $token = strtoupper(trim((string)$value));
    return preg_replace('/\s+/', '', $token) ?? $token;
}

function wmsTaskProductIdentifiers(int $productId, ?string $sku, ?string $barcode): array
{
    $identifiers = [
        wmsTaskNormalizeScanToken((string)$productId),
        wmsTaskNormalizeScanToken('PRD-' . $productId),
        wmsTaskNormalizeScanToken((string)$sku),
        wmsTaskNormalizeScanToken((string)$barcode),
    ];

    if ($sku !== null && trim($sku) !== '') {
        $identifiers[] = wmsTaskNormalizeScanToken('SKU-' . $sku);
    }
    if ($barcode !== null && trim($barcode) !== '') {
        $identifiers[] = wmsTaskNormalizeScanToken('BAR-' . $barcode);
    }

    return array_values(array_unique(array_filter($identifiers, static fn ($identifier) => $identifier !== '')));
}

function wmsTaskLocationIdentifiers(?int $locationId, ?string $locationCode): array
{
    $identifiers = [];

    if ($locationId !== null && $locationId > 0) {
        $identifiers[] = wmsTaskNormalizeScanToken((string)$locationId);
        $identifiers[] = wmsTaskNormalizeScanToken('LOC-' . $locationId);
    }
    if ($locationCode !== null && trim($locationCode) !== '') {
        $identifiers[] = wmsTaskNormalizeScanToken($locationCode);
        $identifiers[] = wmsTaskNormalizeScanToken('LOC-' . $locationCode);
    }

    return array_values(array_unique(array_filter($identifiers, static fn ($identifier) => $identifier !== '')));
}

function wmsTaskScanVariants(mixed $value): array
{
    $token = wmsTaskNormalizeScanToken($value);
    if ($token === '') {
        return [];
    }

    $variants = [$token];
    foreach (['PRD-', 'SKU-', 'BAR-', 'LOC-', 'TASK-'] as $prefix) {
        if (str_starts_with($token, $prefix) && strlen($token) > strlen($prefix)) {
            $variants[] = substr($token, strlen($prefix));
        }
    }

    return array_values(array_unique(array_filter($variants, static fn ($variant) => $variant !== '')));
}

function wmsTaskExpectedLocations(array $lines): array
{
    $locations = [];

    foreach ($lines as $line) {
        $locationId = (int)($line['location_id'] ?? 0);
        $locationCode = (string)($line['location_code'] ?? '');
        if ($locationId <= 0 && $locationCode === '') {
            continue;
        }

        $key = $locationId . '|' . $locationCode;
        if (isset($locations[$key])) {
            continue;
        }

        $locations[$key] = [
            'location_id' => $locationId > 0 ? $locationId : null,
            'location_code' => $locationCode,
            'location_name' => (string)($line['location_name'] ?? ''),
            'identifiers' => $line['location_identifiers'] ?? wmsTaskLocationIdentifiers($locationId > 0 ? $locationId : null, $locationCode),
        ];
    }

    return array_values($locations);
}

function wmsTaskFind(int $taskId): ?array
{
    return wmsFetchOne(
        'SELECT
            t.*, 
            w.code AS warehouse_code,
            w.name AS warehouse_name,
            u.full_name AS assigned_name,
            COALESCE(ex.open_exception_count, 0) AS open_exception_count
         FROM wms_tasks t
         INNER JOIN wms_warehouses w ON w.id = t.warehouse_id
         LEFT JOIN wms_users u ON u.id = t.assigned_to
         LEFT JOIN (
             SELECT task_id, COUNT(*) AS open_exception_count
             FROM wms_task_exceptions
             WHERE status = ?
             GROUP BY task_id
         ) ex ON ex.task_id = t.id
         WHERE t.id = ?
         LIMIT 1',
        ['open', $taskId]
    );
}

function wmsTaskExpectedScanContext(array $task): array
{
    $referenceType = trim((string)($task['reference_type'] ?? ''));
    $referenceId = (int)($task['reference_id'] ?? 0);

    $context = [
        'reference' => [
            'type' => $referenceType,
            'id' => $referenceId > 0 ? $referenceId : null,
            'label' => $referenceType !== '' && $referenceId > 0 ? ucfirst($referenceType) . ' #' . $referenceId : 'Manual task',
            'status' => null,
        ],
        'hint' => 'No reference-driven scan guard is configured for this task.',
        'requires_scan' => false,
        'enforce_qty_max' => false,
        'products' => [],
        'locations' => [],
        'qty_expected' => null,
    ];

    if ($referenceId <= 0 || $referenceType === '') {
        return $context;
    }

    if ($referenceType === 'order') {
        $order = wmsFetchOne(
            'SELECT id, order_number, status, warehouse_id FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$referenceId]
        );
        if ($order === null) {
            return $context;
        }

        $items = wmsFetchAll(
            'SELECT
                oi.id AS line_id,
                oi.product_id,
                oi.location_id,
                oi.batch_id,
                oi.qty_ordered,
                oi.qty_picked,
                p.sku,
                p.barcode,
                p.name AS product_name,
                l.code AS location_code,
                l.name AS location_name,
                b.batch_number
             FROM wms_order_items oi
             INNER JOIN wms_products p ON p.id = oi.product_id
             LEFT JOIN wms_locations l ON l.id = oi.location_id
             LEFT JOIN wms_batches b ON b.id = oi.batch_id
             WHERE oi.order_id = ?
             ORDER BY oi.id ASC',
            [$referenceId]
        );

        $lines = [];
        $qtyExpected = 0.0;
        foreach ($items as $item) {
            $remainingQty = max(0.0, wmsNormalizeDecimal(($item['qty_ordered'] ?? 0) - ($item['qty_picked'] ?? 0)));
            if ((string)($task['task_type'] ?? '') === 'pick' && $remainingQty <= 0) {
                continue;
            }

            $lineQty = $remainingQty > 0 ? $remainingQty : wmsNormalizeDecimal($item['qty_ordered'] ?? 0);
            $lines[] = [
                'line_id' => (int)$item['line_id'],
                'product_id' => (int)$item['product_id'],
                'sku' => (string)($item['sku'] ?? ''),
                'barcode' => (string)($item['barcode'] ?? ''),
                'product_name' => (string)($item['product_name'] ?? ''),
                'location_id' => isset($item['location_id']) ? (int)$item['location_id'] : null,
                'location_code' => (string)($item['location_code'] ?? ''),
                'location_name' => (string)($item['location_name'] ?? ''),
                'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                'batch_number' => (string)($item['batch_number'] ?? ''),
                'qty_expected' => $lineQty,
                'product_identifiers' => wmsTaskProductIdentifiers((int)$item['product_id'], (string)($item['sku'] ?? ''), (string)($item['barcode'] ?? '')),
                'location_identifiers' => wmsTaskLocationIdentifiers(isset($item['location_id']) ? (int)$item['location_id'] : null, (string)($item['location_code'] ?? '')),
            ];
            $qtyExpected += $lineQty;
        }

        return [
            'reference' => [
                'type' => 'order',
                'id' => (int)$order['id'],
                'label' => 'Order ' . (string)($order['order_number'] ?? ('#' . $referenceId)),
                'status' => (string)($order['status'] ?? ''),
            ],
            'hint' => 'Scan the reserved product and pick location before confirming the task.',
            'requires_scan' => $lines !== [],
            'enforce_qty_max' => true,
            'products' => $lines,
            'locations' => wmsTaskExpectedLocations($lines),
            'qty_expected' => $qtyExpected > 0 ? wmsNormalizeDecimal($qtyExpected) : null,
        ];
    }

    if ($referenceType === 'delivery') {
        $delivery = wmsFetchOne(
            'SELECT id, reference_number, status FROM wms_deliveries WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$referenceId]
        );
        if ($delivery === null) {
            return $context;
        }

        $items = wmsFetchAll(
            'SELECT
                di.id AS line_id,
                di.delivery_id,
                di.product_id,
                di.location_id,
                di.staging_location_id,
                di.batch_id,
                di.qty_expected,
                di.qty_received,
                di.qty_put_away,
                p.sku,
                p.barcode,
                p.name AS product_name,
                l.code AS location_code,
                l.name AS location_name,
                sl.code AS staging_location_code,
                sl.name AS staging_location_name,
                b.batch_number
             FROM wms_delivery_items di
             INNER JOIN wms_products p ON p.id = di.product_id
             INNER JOIN wms_locations l ON l.id = di.location_id
             LEFT JOIN wms_locations sl ON sl.id = di.staging_location_id
             LEFT JOIN wms_batches b ON b.id = di.batch_id
             WHERE di.delivery_id = ?
             ORDER BY di.id ASC',
            [$referenceId]
        );

        $lines = [];
        $qtyExpected = 0.0;
        foreach ($items as $item) {
            $remainingQty = wmsDeliveryItemRemainingPutAwayQty($item);
            if ($remainingQty <= 0 && (string)($task['task_type'] ?? '') === 'putaway') {
                continue;
            }

            $lineQty = $remainingQty > 0 ? $remainingQty : wmsNormalizeDecimal($item['qty_received'] ?? ($item['qty_expected'] ?? 0));
            $lines[] = [
                'line_id' => (int)$item['line_id'],
                'product_id' => (int)$item['product_id'],
                'sku' => (string)($item['sku'] ?? ''),
                'barcode' => (string)($item['barcode'] ?? ''),
                'product_name' => (string)($item['product_name'] ?? ''),
                'location_id' => (int)$item['location_id'],
                'location_code' => (string)($item['location_code'] ?? ''),
                'location_name' => (string)($item['location_name'] ?? ''),
                'staging_location_id' => isset($item['staging_location_id']) ? (int)$item['staging_location_id'] : null,
                'staging_location_code' => (string)($item['staging_location_code'] ?? ''),
                'staging_location_name' => (string)($item['staging_location_name'] ?? ''),
                'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                'batch_number' => (string)($item['batch_number'] ?? ''),
                'qty_expected' => $lineQty,
                'product_identifiers' => wmsTaskProductIdentifiers((int)$item['product_id'], (string)($item['sku'] ?? ''), (string)($item['barcode'] ?? '')),
                'location_identifiers' => wmsTaskLocationIdentifiers((int)$item['location_id'], (string)($item['location_code'] ?? '')),
            ];
            $qtyExpected += $lineQty;
        }

        return [
            'reference' => [
                'type' => 'delivery',
                'id' => (int)$delivery['id'],
                'label' => 'Delivery ' . (string)($delivery['reference_number'] ?? ('#' . $referenceId)),
                'status' => (string)($delivery['status'] ?? ''),
            ],
            'hint' => 'Scan the staged product and final location before confirming putaway.',
            'requires_scan' => $lines !== [],
            'enforce_qty_max' => true,
            'products' => $lines,
            'locations' => wmsTaskExpectedLocations($lines),
            'qty_expected' => $qtyExpected > 0 ? wmsNormalizeDecimal($qtyExpected) : null,
        ];
    }

    if ($referenceType === 'delivery_item') {
        $item = wmsFetchOne(
            'SELECT
                di.id AS line_id,
                di.delivery_id,
                di.product_id,
                di.location_id,
                di.staging_location_id,
                di.batch_id,
                di.qty_expected,
                di.qty_received,
                di.qty_put_away,
                d.reference_number,
                d.status AS delivery_status,
                p.sku,
                p.barcode,
                p.name AS product_name,
                l.code AS location_code,
                l.name AS location_name,
                sl.code AS staging_location_code,
                sl.name AS staging_location_name,
                b.batch_number
             FROM wms_delivery_items di
             INNER JOIN wms_deliveries d ON d.id = di.delivery_id
             INNER JOIN wms_products p ON p.id = di.product_id
             INNER JOIN wms_locations l ON l.id = di.location_id
             LEFT JOIN wms_locations sl ON sl.id = di.staging_location_id
             LEFT JOIN wms_batches b ON b.id = di.batch_id
             WHERE di.id = ?
             LIMIT 1',
            [$referenceId]
        );
        if ($item === null) {
            return $context;
        }

        $qtyExpected = wmsDeliveryItemRemainingPutAwayQty($item);
        $lines = [];
        if ($qtyExpected > 0 || (string)($task['task_type'] ?? '') !== 'putaway') {
            $lines[] = [
                'line_id' => (int)$item['line_id'],
                'product_id' => (int)$item['product_id'],
                'sku' => (string)($item['sku'] ?? ''),
                'barcode' => (string)($item['barcode'] ?? ''),
                'product_name' => (string)($item['product_name'] ?? ''),
                'location_id' => (int)$item['location_id'],
                'location_code' => (string)($item['location_code'] ?? ''),
                'location_name' => (string)($item['location_name'] ?? ''),
                'staging_location_id' => isset($item['staging_location_id']) ? (int)$item['staging_location_id'] : null,
                'staging_location_code' => (string)($item['staging_location_code'] ?? ''),
                'staging_location_name' => (string)($item['staging_location_name'] ?? ''),
                'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                'batch_number' => (string)($item['batch_number'] ?? ''),
                'qty_expected' => $qtyExpected > 0 ? $qtyExpected : null,
                'product_identifiers' => wmsTaskProductIdentifiers((int)$item['product_id'], (string)($item['sku'] ?? ''), (string)($item['barcode'] ?? '')),
                'location_identifiers' => wmsTaskLocationIdentifiers((int)$item['location_id'], (string)($item['location_code'] ?? '')),
            ];
        }

        return [
            'reference' => [
                'type' => 'delivery_item',
                'id' => (int)$item['line_id'],
                'label' => 'Delivery ' . (string)($item['reference_number'] ?? ('#' . (int)$item['delivery_id'])) . ' line #' . (int)$item['line_id'],
                'status' => (string)($item['delivery_status'] ?? ''),
            ],
            'hint' => 'Scan the staged product and final location before confirming putaway.',
            'requires_scan' => $lines !== [],
            'enforce_qty_max' => true,
            'products' => $lines,
            'locations' => wmsTaskExpectedLocations($lines),
            'qty_expected' => $qtyExpected > 0 ? wmsNormalizeDecimal($qtyExpected) : null,
        ];
    }

    if ($referenceType === 'cycle_count') {
        $count = wmsFetchOne(
            'SELECT id, reference_number, status, location_id FROM wms_cycle_counts WHERE id = ? LIMIT 1',
            [$referenceId]
        );
        if ($count === null) {
            return $context;
        }

        $items = wmsFetchAll(
            'SELECT
                cci.id AS line_id,
                cci.product_id,
                cci.location_id,
                cci.batch_id,
                cci.qty_system,
                cci.qty_counted,
                p.sku,
                p.barcode,
                p.name AS product_name,
                l.code AS location_code,
                l.name AS location_name,
                b.batch_number
             FROM wms_cycle_count_items cci
             INNER JOIN wms_products p ON p.id = cci.product_id
             INNER JOIN wms_locations l ON l.id = cci.location_id
             LEFT JOIN wms_batches b ON b.id = cci.batch_id
             WHERE cci.cycle_count_id = ?
             ORDER BY cci.id ASC',
            [$referenceId]
        );

        $lines = [];
        foreach ($items as $item) {
            $lines[] = [
                'line_id' => (int)$item['line_id'],
                'product_id' => (int)$item['product_id'],
                'sku' => (string)($item['sku'] ?? ''),
                'barcode' => (string)($item['barcode'] ?? ''),
                'product_name' => (string)($item['product_name'] ?? ''),
                'location_id' => (int)$item['location_id'],
                'location_code' => (string)($item['location_code'] ?? ''),
                'location_name' => (string)($item['location_name'] ?? ''),
                'batch_id' => isset($item['batch_id']) ? (int)$item['batch_id'] : null,
                'batch_number' => (string)($item['batch_number'] ?? ''),
                'qty_expected' => isset($item['qty_system']) ? wmsNormalizeDecimal($item['qty_system']) : null,
                'qty_counted' => ($item['qty_counted'] ?? null) !== null ? wmsNormalizeDecimal($item['qty_counted']) : null,
                'product_identifiers' => wmsTaskProductIdentifiers((int)$item['product_id'], (string)($item['sku'] ?? ''), (string)($item['barcode'] ?? '')),
                'location_identifiers' => wmsTaskLocationIdentifiers((int)$item['location_id'], (string)($item['location_code'] ?? '')),
            ];
        }

        $locations = wmsTaskExpectedLocations($lines);
        if ($locations === [] && (int)($count['location_id'] ?? 0) > 0) {
            $location = wmsFetchOne('SELECT id, code, name FROM wms_locations WHERE id = ? LIMIT 1', [(int)$count['location_id']]);
            if ($location !== null) {
                $locations[] = [
                    'location_id' => (int)$location['id'],
                    'location_code' => (string)($location['code'] ?? ''),
                    'location_name' => (string)($location['name'] ?? ''),
                    'identifiers' => wmsTaskLocationIdentifiers((int)$location['id'], (string)($location['code'] ?? '')),
                ];
            }
        }

        return [
            'reference' => [
                'type' => 'cycle_count',
                'id' => (int)$count['id'],
                'label' => 'Cycle Count ' . (string)($count['reference_number'] ?? ('#' . $referenceId)),
                'status' => (string)($count['status'] ?? ''),
            ],
            'hint' => 'Scan the count location and product before closing the count task.',
            'requires_scan' => $lines !== [] || $locations !== [],
            'enforce_qty_max' => false,
            'products' => $lines,
            'locations' => $locations,
            'qty_expected' => null,
        ];
    }

    if ($referenceType === 'product') {
        $product = wmsFetchOne(
            'SELECT id, sku, barcode, name FROM wms_products WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$referenceId]
        );
        if ($product === null) {
            return $context;
        }

        return [
            'reference' => [
                'type' => 'product',
                'id' => (int)$product['id'],
                'label' => 'Product ' . (string)($product['sku'] ?? ('#' . $referenceId)),
                'status' => null,
            ],
            'hint' => 'Scan the target product before confirming the replenishment or manual task.',
            'requires_scan' => true,
            'enforce_qty_max' => false,
            'products' => [[
                'line_id' => null,
                'product_id' => (int)$product['id'],
                'sku' => (string)($product['sku'] ?? ''),
                'barcode' => (string)($product['barcode'] ?? ''),
                'product_name' => (string)($product['name'] ?? ''),
                'location_id' => null,
                'location_code' => '',
                'location_name' => '',
                'batch_id' => null,
                'batch_number' => '',
                'qty_expected' => null,
                'product_identifiers' => wmsTaskProductIdentifiers((int)$product['id'], (string)($product['sku'] ?? ''), (string)($product['barcode'] ?? '')),
                'location_identifiers' => [],
            ]],
            'locations' => [],
            'qty_expected' => null,
        ];
    }

    return $context;
}

function wmsTaskGetDetailed(int $taskId): array
{
    $task = wmsTaskFind($taskId);
    if ($task === null) {
        throw new RuntimeException('Task not found.');
    }

    $task['open_exception_count'] = (int)($task['open_exception_count'] ?? 0);
    $task['expected_scan'] = wmsTaskExpectedScanContext($task);
    $task['exceptions'] = wmsTaskExceptionsList(['task_id' => $taskId, 'status' => '']);

    return $task;
}

function wmsTaskExceptionFind(int $exceptionId): ?array
{
    $exception = wmsFetchOne(
        'SELECT
            e.*,
            t.task_type,
            t.status AS task_status,
            t.priority,
            t.reference_type,
            t.reference_id,
            t.warehouse_id,
            w.code AS warehouse_code,
            w.name AS warehouse_name,
            u.full_name AS assigned_name
         FROM wms_task_exceptions e
         INNER JOIN wms_tasks t ON t.id = e.task_id
         INNER JOIN wms_warehouses w ON w.id = t.warehouse_id
         LEFT JOIN wms_users u ON u.id = t.assigned_to
         WHERE e.id = ?
         LIMIT 1',
        [$exceptionId]
    );
    if ($exception === null) {
        return null;
    }

    $exception['scan_payload'] = wmsJsonDecodeArray($exception['scan_payload'] ?? null);
    $exception['disposition_payload'] = wmsJsonDecodeArray($exception['disposition_payload'] ?? null);

    return $exception;
}

function wmsTaskExceptionPersistResolution(
    int $exceptionId,
    ?string $resolutionNote,
    int $actorUserId,
    ?string $dispositionType = null,
    array $dispositionPayload = []
): array {
    $note = wmsSanitizeString((string)$resolutionNote, 500);
    if ($note === '') {
        $note = 'Resolved by operator.';
    }

    $type = $dispositionType !== null ? wmsSanitizeString($dispositionType, 50) : null;
    wmsDb()->execute(
        'UPDATE wms_task_exceptions
         SET status = ?,
             resolution_note = ?,
             disposition_type = ?,
             disposition_payload = ?,
             resolved_by = ?,
             resolved_at = NOW()
         WHERE id = ?',
        [
            'resolved',
            $note,
            $type !== '' ? $type : null,
            $dispositionPayload !== [] ? json_encode($dispositionPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $actorUserId,
            $exceptionId,
        ]
    );

    return wmsTaskExceptionFind($exceptionId) ?? ['id' => $exceptionId, 'status' => 'resolved'];
}

function wmsTaskExceptionSourceLocationId(array $task, array $line): int
{
    $sourceLocationId = (int)($line['source_location_id'] ?? 0);
    if ($sourceLocationId > 0) {
        return $sourceLocationId;
    }

    if (in_array((string)($task['reference_type'] ?? ''), ['delivery', 'delivery_item'], true) && (int)($line['staging_location_id'] ?? 0) > 0) {
        return (int)$line['staging_location_id'];
    }

    return (int)($line['location_id'] ?? 0);
}

function wmsTaskExceptionResolveInventoryContext(array $exception, array $overrides = []): array
{
    $task = wmsTaskFind((int)($exception['task_id'] ?? 0));
    if ($task === null) {
        throw new RuntimeException('Task not found for exception remediation.');
    }

    $expected = wmsTaskExpectedScanContext($task);
    $lines = is_array($expected['products'] ?? null) ? array_values($expected['products']) : [];
    $scanPayload = is_array($exception['scan_payload'] ?? null) ? $exception['scan_payload'] : wmsJsonDecodeArray($exception['scan_payload'] ?? null);

    $productId = isset($overrides['product_id']) && (int)$overrides['product_id'] > 0 ? (int)$overrides['product_id'] : 0;
    $locationId = isset($overrides['location_id']) && (int)$overrides['location_id'] > 0 ? (int)$overrides['location_id'] : 0;
    $batchId = isset($overrides['batch_id']) && (int)$overrides['batch_id'] > 0 ? (int)$overrides['batch_id'] : null;
    $qty = isset($overrides['qty']) && $overrides['qty'] !== '' ? wmsNormalizeDecimal($overrides['qty']) : null;

    $candidateLines = $lines;

    if ($productId > 0) {
        $candidateLines = array_values(array_filter(
            $candidateLines,
            static fn (array $line): bool => (int)($line['product_id'] ?? 0) === $productId
        ));
    } else {
        $scanProductCode = wmsSanitizeString($scanPayload['product_code'] ?? $scanPayload['barcode'] ?? '', 100);
        if ($scanProductCode !== '' && $candidateLines !== []) {
            $productVariants = wmsTaskScanVariants($scanProductCode);
            $filteredLines = array_values(array_filter(
                $candidateLines,
                static fn (array $line): bool => array_intersect($productVariants, $line['product_identifiers'] ?? []) !== []
            ));
            if ($filteredLines !== []) {
                $candidateLines = $filteredLines;
            }
        }
    }

    if ($locationId > 0) {
        $candidateLines = array_values(array_filter(
            $candidateLines,
            static fn (array $line): bool => (int)($line['location_id'] ?? 0) === $locationId || (int)($line['staging_location_id'] ?? 0) === $locationId || (int)($line['source_location_id'] ?? 0) === $locationId
        ));
    } else {
        $scanLocationCode = wmsSanitizeString($scanPayload['location_code'] ?? '', 100);
        if ($scanLocationCode !== '' && $candidateLines !== []) {
            $locationVariants = wmsTaskScanVariants($scanLocationCode);
            $filteredLines = array_values(array_filter(
                $candidateLines,
                static function (array $line) use ($locationVariants): bool {
                    $stagingIdentifiers = wmsTaskLocationIdentifiers(
                        isset($line['staging_location_id']) && (int)$line['staging_location_id'] > 0 ? (int)$line['staging_location_id'] : null,
                        (string)($line['staging_location_code'] ?? '')
                    );

                    return array_intersect($locationVariants, $line['location_identifiers'] ?? []) !== []
                        || array_intersect($locationVariants, $stagingIdentifiers) !== [];
                }
            ));
            if ($filteredLines !== []) {
                $candidateLines = $filteredLines;
            }
        }
    }

    if ($candidateLines === [] && count($lines) === 1 && $productId <= 0 && $locationId <= 0) {
        $candidateLines = $lines;
    }

    $line = null;
    if ($candidateLines !== []) {
        if (count($candidateLines) > 1) {
            throw new RuntimeException('Exception maps to multiple stock lines. Provide explicit product and source location overrides.');
        }
        $line = $candidateLines[0];
    }

    if ($productId <= 0) {
        $productId = (int)($line['product_id'] ?? 0);
    }
    if ($productId <= 0) {
        throw new RuntimeException('Product override is required for this exception.');
    }

    if ($locationId <= 0) {
        $locationId = is_array($line) ? wmsTaskExceptionSourceLocationId($task, $line) : 0;
    }
    if ($locationId <= 0) {
        throw new RuntimeException('Source location override is required for this exception.');
    }

    if ($batchId === null && is_array($line) && isset($line['batch_id']) && (int)$line['batch_id'] > 0) {
        $batchId = (int)$line['batch_id'];
    }

    if ($qty === null) {
        if (($scanPayload['qty'] ?? '') !== '') {
            $qty = wmsNormalizeDecimal($scanPayload['qty']);
        } elseif (is_array($line) && ($line['qty_expected'] ?? null) !== null) {
            $qty = wmsNormalizeDecimal($line['qty_expected']);
        }
    }
    if ($qty === null || $qty <= 0) {
        throw new RuntimeException('Quantity override is required for this exception.');
    }

    $product = wmsFetchOne('SELECT id, sku, name FROM wms_products WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$productId]);
    if ($product === null) {
        throw new RuntimeException('Product not found for exception remediation.');
    }

    $location = wmsLocationRecord($locationId);
    if ($location === null) {
        throw new RuntimeException('Source location not found for exception remediation.');
    }
    if ((int)($location['warehouse_id'] ?? 0) !== (int)$task['warehouse_id']) {
        throw new RuntimeException('Source location does not belong to the task warehouse.');
    }

    return [
        'task' => $task,
        'expected' => $expected,
        'scan_payload' => $scanPayload,
        'line' => $line,
        'warehouse_id' => (int)$task['warehouse_id'],
        'product_id' => $productId,
        'product_sku' => (string)($product['sku'] ?? ''),
        'product_name' => (string)($product['name'] ?? ''),
        'location_id' => $locationId,
        'location_code' => (string)($location['code'] ?? ''),
        'location_name' => (string)($location['name'] ?? ''),
        'batch_id' => $batchId,
        'qty' => $qty,
    ];
}

function wmsTaskExceptionDisposition(int $exceptionId, string $dispositionType, array $payload, int $actorUserId): array
{
    $dispositionType = wmsSanitizeString($dispositionType, 50);
    if (!in_array($dispositionType, wmsTaskExceptionDispositionTypes(), true)) {
        throw new RuntimeException('Invalid exception disposition type.');
    }

    $exception = wmsTaskExceptionFind($exceptionId);
    if ($exception === null) {
        throw new RuntimeException('Task exception not found.');
    }
    if ((string)($exception['status'] ?? '') === 'resolved') {
        throw new RuntimeException('Task exception is already resolved.');
    }

    $resolutionNote = wmsSanitizeString((string)($payload['resolution_note'] ?? ''), 500);
    $dispositionPayload = [
        'task_id' => (int)($exception['task_id'] ?? 0),
        'exception_id' => $exceptionId,
        'disposition_type' => $dispositionType,
    ];

    if ($dispositionType === 'quarantine') {
        $context = wmsTaskExceptionResolveInventoryContext($exception, $payload);
        $quarantineLocation = wmsResolveQuarantineLocation((int)$context['warehouse_id']);
        if ((int)$quarantineLocation['id'] === (int)$context['location_id']) {
            throw new RuntimeException('Stock is already in the quarantine location.');
        }

        $db = wmsDb();
        $started = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $started = true;
        }

        try {
            $transfer = wmsTransferCreate(
                (int)$context['location_id'],
                (int)$quarantineLocation['id'],
                [[
                    'product_id' => (int)$context['product_id'],
                    'batch_id' => $context['batch_id'],
                    'qty' => $context['qty'],
                ]],
                $actorUserId,
                $resolutionNote !== '' ? $resolutionNote : 'Moved stock to quarantine from task exception #' . $exceptionId,
                'task_exception',
                $exceptionId,
                [
                    'task_id' => (int)$exception['task_id'],
                    'disposition' => 'quarantine',
                ]
            );

            $holdMovementId = wmsReserveStock([
                'reference_type' => 'quarantine_exception',
                'reference_id' => $exceptionId,
                'product_id' => (int)$context['product_id'],
                'warehouse_id' => (int)$context['warehouse_id'],
                'location_id' => (int)$quarantineLocation['id'],
                'batch_id' => $context['batch_id'],
                'qty' => $context['qty'],
                'notes' => $resolutionNote !== '' ? $resolutionNote : 'Quarantine hold from task exception #' . $exceptionId,
                'actor_user_id' => $actorUserId,
                'meta' => [
                    'task_id' => (int)$exception['task_id'],
                    'exception_id' => $exceptionId,
                    'disposition' => 'quarantine_hold',
                ],
            ]);

            if ($started) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($started && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $dispositionPayload = array_merge($dispositionPayload, [
            'movement_ids' => array_merge($transfer['movement_ids'] ?? [], [$holdMovementId]),
            'product_id' => (int)$context['product_id'],
            'product_sku' => (string)$context['product_sku'],
            'location_id' => (int)$context['location_id'],
            'location_code' => (string)$context['location_code'],
            'quarantine_location_id' => (int)$quarantineLocation['id'],
            'quarantine_location_code' => (string)($quarantineLocation['code'] ?? ''),
            'quarantine_hold_movement_id' => $holdMovementId,
            'batch_id' => $context['batch_id'],
            'qty' => $context['qty'],
        ]);

        $resolutionNote = $resolutionNote !== ''
            ? $resolutionNote
            : 'Resolved by moving ' . $context['qty'] . ' of ' . ((string)$context['product_sku'] !== '' ? (string)$context['product_sku'] : ('product #' . (int)$context['product_id'])) . ' to quarantine hold.';
    } elseif ($dispositionType === 'mark_damaged') {
        $context = wmsTaskExceptionResolveInventoryContext($exception, $payload);
        $movementId = wmsMovementCreate([
            'movement_type' => 'out',
            'reference_type' => 'task_exception',
            'reference_id' => $exceptionId,
            'product_id' => (int)$context['product_id'],
            'warehouse_id' => (int)$context['warehouse_id'],
            'location_id' => (int)$context['location_id'],
            'batch_id' => $context['batch_id'],
            'qty' => -$context['qty'],
            'notes' => $resolutionNote !== '' ? $resolutionNote : 'Damaged stock write-off from task exception #' . $exceptionId,
            'actor_user_id' => $actorUserId,
            'meta' => [
                'task_id' => (int)$exception['task_id'],
                'disposition' => 'mark_damaged',
            ],
        ]);

        $dispositionPayload = array_merge($dispositionPayload, [
            'movement_ids' => [$movementId],
            'product_id' => (int)$context['product_id'],
            'product_sku' => (string)$context['product_sku'],
            'location_id' => (int)$context['location_id'],
            'location_code' => (string)$context['location_code'],
            'batch_id' => $context['batch_id'],
            'qty' => $context['qty'],
        ]);

        $resolutionNote = $resolutionNote !== ''
            ? $resolutionNote
            : 'Resolved by writing off ' . $context['qty'] . ' of ' . ((string)$context['product_sku'] !== '' ? (string)$context['product_sku'] : ('product #' . (int)$context['product_id'])) . ' as damaged stock.';
    } else {
        if ((string)($exception['task_type'] ?? '') !== 'pick' || (string)($exception['reference_type'] ?? '') !== 'order') {
            throw new RuntimeException('Reservation release is only supported for order pick task exceptions.');
        }

        $context = wmsTaskExceptionResolveInventoryContext($exception, $payload);
        $movementId = wmsReleaseStock([
            'reference_type' => (string)$exception['reference_type'],
            'reference_id' => (int)$exception['reference_id'],
            'product_id' => (int)$context['product_id'],
            'warehouse_id' => (int)$context['warehouse_id'],
            'location_id' => (int)$context['location_id'],
            'batch_id' => $context['batch_id'],
            'qty' => $context['qty'],
            'notes' => $resolutionNote !== '' ? $resolutionNote : 'Released reservation from task exception #' . $exceptionId,
            'actor_user_id' => $actorUserId,
            'meta' => [
                'task_id' => (int)$exception['task_id'],
                'exception_id' => $exceptionId,
                'disposition' => 'release_reservation',
            ],
        ]);

        $dispositionPayload = array_merge($dispositionPayload, [
            'movement_ids' => [$movementId],
            'product_id' => (int)$context['product_id'],
            'product_sku' => (string)$context['product_sku'],
            'location_id' => (int)$context['location_id'],
            'location_code' => (string)$context['location_code'],
            'batch_id' => $context['batch_id'],
            'qty' => $context['qty'],
            'reference_type' => (string)$exception['reference_type'],
            'reference_id' => (int)$exception['reference_id'],
        ]);

        $resolutionNote = $resolutionNote !== ''
            ? $resolutionNote
            : 'Resolved by releasing ' . $context['qty'] . ' of ' . ((string)$context['product_sku'] !== '' ? (string)$context['product_sku'] : ('product #' . (int)$context['product_id'])) . ' from reservation.';
    }

    $resolved = wmsTaskExceptionPersistResolution($exceptionId, $resolutionNote, $actorUserId, $dispositionType, $dispositionPayload);
    wmsAudit('wms.task_exception.disposition', 'wms_task_exceptions', (string)$exceptionId, null, [
        'task_id' => (int)$exception['task_id'],
        'disposition_type' => $dispositionType,
        'disposition_payload' => $dispositionPayload,
    ]);

    return $resolved;
}

function wmsTaskExceptionCreate(int $taskId, string $type, string $message, array $scanPayload = [], ?int $actorUserId = null): int
{
    $type = wmsSanitizeString($type, 80);
    $message = wmsSanitizeString($message, 500);

    if ($type === '' || $message === '') {
        throw new RuntimeException('Exception type and message are required.');
    }

    $existing = wmsFetchOne(
        'SELECT id FROM wms_task_exceptions WHERE task_id = ? AND exception_type = ? AND message = ? AND status = ? LIMIT 1',
        [$taskId, $type, $message, 'open']
    );
    if ($existing !== null) {
        return (int)$existing['id'];
    }

    wmsDb()->execute(
        'INSERT INTO wms_task_exceptions (task_id, exception_type, status, message, scan_payload, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())',
        [
            $taskId,
            $type,
            'open',
            $message,
            $scanPayload !== [] ? json_encode($scanPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $actorUserId,
        ]
    );

    return (int)wmsDb()->lastInsertId();
}

function wmsTaskResolveOpenExceptions(int $taskId, int $actorUserId, string $note = 'Resolved via successful scan confirmation.'): void
{
    wmsDb()->execute(
        'UPDATE wms_task_exceptions
         SET status = ?, resolution_note = ?, resolved_by = ?, resolved_at = NOW()
         WHERE task_id = ? AND status = ?',
        ['resolved', wmsSanitizeString($note, 500), $actorUserId, $taskId, 'open']
    );
}

function wmsTaskPrepareReference(array $task): void
{
    $referenceType = (string)($task['reference_type'] ?? '');
    $referenceId = (int)($task['reference_id'] ?? 0);
    if ($referenceType === '' || $referenceId <= 0) {
        return;
    }

    if ($referenceType === 'order' && (string)($task['task_type'] ?? '') === 'pick') {
        $order = wmsFetchOne('SELECT id, status FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$referenceId]);
        if ($order === null) {
            throw new RuntimeException('Order not found for task reference.');
        }

        if ((string)($order['status'] ?? '') === 'pending') {
            $unassignedItem = wmsFetchOne(
                'SELECT id FROM wms_order_items WHERE order_id = ? AND qty_ordered > qty_picked AND (location_id IS NULL OR location_id = 0) LIMIT 1',
                [$referenceId]
            );
            if ($unassignedItem !== null) {
                wmsOrderGeneratePickList($referenceId);
            }
        }

        return;
    }

    if ($referenceType === 'cycle_count' && (string)($task['task_type'] ?? '') === 'count') {
        $count = wmsFetchOne('SELECT id, status FROM wms_cycle_counts WHERE id = ? LIMIT 1', [$referenceId]);
        if ($count === null) {
            throw new RuntimeException('Cycle count not found for task reference.');
        }

        if ((string)($count['status'] ?? '') === 'open') {
            wmsCycleCountSnapshot($referenceId);
        }
    }
}

function wmsTaskCompareScan(array $expected, array $scanPayload): array
{
    $productCode = wmsSanitizeString($scanPayload['product_code'] ?? $scanPayload['barcode'] ?? '', 100);
    $locationCode = wmsSanitizeString($scanPayload['location_code'] ?? '', 100);
    $qtyInput = $scanPayload['qty'] ?? null;
    $qty = ($qtyInput !== null && $qtyInput !== '') ? wmsNormalizeDecimal($qtyInput) : null;

    $errors = [];
    $matchedLines = $expected['products'] ?? [];

    if (($expected['products'] ?? []) !== []) {
        if ($productCode === '') {
            $errors[] = 'Product scan is required.';
        } else {
            $productVariants = wmsTaskScanVariants($productCode);
            $matchedLines = array_values(array_filter(
                $expected['products'],
                static fn (array $line): bool => array_intersect($productVariants, $line['product_identifiers'] ?? []) !== []
            ));

            if ($matchedLines === []) {
                $errors[] = 'Scanned product does not match the expected work.';
            }
        }
    }

    $matchedLocations = $expected['locations'] ?? [];
    if (($expected['locations'] ?? []) !== []) {
        if ($locationCode === '') {
            $errors[] = 'Location scan is required.';
        } else {
            $locationVariants = wmsTaskScanVariants($locationCode);
            $matchedLocations = array_values(array_filter(
                $expected['locations'],
                static fn (array $location): bool => array_intersect($locationVariants, $location['identifiers'] ?? []) !== []
            ));

            if ($matchedLocations === []) {
                $errors[] = 'Scanned location does not match the expected work location.';
            }

            if ($matchedLines !== []) {
                $matchedLines = array_values(array_filter(
                    $matchedLines,
                    static fn (array $line): bool => ($line['location_identifiers'] ?? []) === []
                        || array_intersect($locationVariants, $line['location_identifiers'] ?? []) !== []
                ));

                if ($matchedLines === [] && ($expected['products'] ?? []) !== []) {
                    $errors[] = 'Scanned product is not expected at the scanned location.';
                }
            }
        }
    }

    if ($qty !== null) {
        if ($qty <= 0) {
            $errors[] = 'Quantity must be greater than zero.';
        } elseif (($expected['enforce_qty_max'] ?? false) && $matchedLines !== []) {
            $allowedQty = 0.0;
            foreach ($matchedLines as $line) {
                $allowedQty += wmsNormalizeDecimal($line['qty_expected'] ?? 0);
            }
            if ($allowedQty > 0 && $qty > $allowedQty) {
                $errors[] = 'Scanned quantity exceeds the expected quantity for this task.';
            }
        }
    }

    return [
        'matched' => $errors === [],
        'errors' => $errors,
        'product_code' => $productCode,
        'location_code' => $locationCode,
        'qty' => $qty,
        'matched_lines' => $matchedLines,
        'matched_locations' => $matchedLocations,
    ];
}

function wmsTaskExecute(array $task, array $scanPayload, array $validation, int $actorUserId): array
{
    $referenceType = (string)($task['reference_type'] ?? '');
    $referenceId = (int)($task['reference_id'] ?? 0);
    $taskType = (string)($task['task_type'] ?? '');

    if ($referenceType === 'order' && $taskType === 'pick' && $referenceId > 0) {
        $order = wmsFetchOne('SELECT status FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$referenceId]);
        if ($order === null) {
            throw new RuntimeException('Order not found for pick task.');
        }

        if ((string)($order['status'] ?? '') === 'pending') {
            wmsTaskPrepareReference($task);
            $order = wmsFetchOne('SELECT status FROM wms_orders WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$referenceId]) ?? $order;
        }

        if (in_array((string)($order['status'] ?? ''), ['picked', 'dispatched'], true)) {
            return ['order_id' => $referenceId, 'status' => (string)$order['status']];
        }
        if ((string)($order['status'] ?? '') === 'cancelled') {
            throw new RuntimeException('Cancelled orders cannot be picked.');
        }

        return wmsOrderPick($referenceId, $actorUserId);
    }

    if ($referenceType === 'delivery_item' && $taskType === 'putaway' && $referenceId > 0) {
        return wmsDeliveryPutAwayItem($referenceId, $actorUserId);
    }

    if ($referenceType === 'delivery' && $taskType === 'putaway' && $referenceId > 0) {
        $delivery = wmsFetchOne('SELECT status FROM wms_deliveries WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$referenceId]);
        if ($delivery === null) {
            throw new RuntimeException('Delivery not found for putaway task.');
        }

        if ((string)($delivery['status'] ?? '') === 'cancelled') {
            throw new RuntimeException('Cancelled deliveries cannot be received.');
        }

        $deliveryItems = wmsFetchAll(
            'SELECT id FROM wms_delivery_items WHERE delivery_id = ? AND qty_received > qty_put_away ORDER BY id ASC',
            [$referenceId]
        );
        if ($deliveryItems === []) {
            if ((string)($delivery['status'] ?? '') === 'received') {
                return ['delivery_id' => $referenceId, 'status' => 'received'];
            }

            throw new RuntimeException('Receive the delivery into staging before completing putaway.');
        }

        $movementIds = [];
        $itemResults = [];
        foreach ($deliveryItems as $deliveryItem) {
            $itemResult = wmsDeliveryPutAwayItem((int)$deliveryItem['id'], $actorUserId);
            $itemResults[] = $itemResult;
            foreach ($itemResult['movement_ids'] ?? [] as $movementId) {
                $movementIds[] = $movementId;
            }
        }

        $status = (string)(wmsFetchOne('SELECT status FROM wms_deliveries WHERE id = ? LIMIT 1', [$referenceId])['status'] ?? ($delivery['status'] ?? 'pending'));

        return [
            'delivery_id' => $referenceId,
            'status' => $status,
            'movement_ids' => $movementIds,
            'items' => $itemResults,
        ];
    }

    if ($referenceType === 'cycle_count' && $taskType === 'count' && $referenceId > 0) {
        $count = wmsFetchOne('SELECT status FROM wms_cycle_counts WHERE id = ? LIMIT 1', [$referenceId]);
        if ($count === null) {
            throw new RuntimeException('Cycle count not found for task.');
        }

        if ((string)($count['status'] ?? '') === 'open') {
            wmsTaskPrepareReference($task);
            $count = wmsFetchOne('SELECT status FROM wms_cycle_counts WHERE id = ? LIMIT 1', [$referenceId]) ?? $count;
        }

        if (($scanPayload['qty'] ?? '') !== '' && count($validation['matched_lines'] ?? []) === 1) {
            $matchedLine = $validation['matched_lines'][0];
            if (isset($matchedLine['line_id']) && (int)$matchedLine['line_id'] > 0) {
                wmsDb()->execute(
                    'UPDATE wms_cycle_count_items SET qty_counted = ?, updated_at = NOW() WHERE id = ? AND cycle_count_id = ?',
                    [wmsNormalizeDecimal($scanPayload['qty']), (int)$matchedLine['line_id'], $referenceId]
                );
            }
        }

        if ((string)($count['status'] ?? '') === 'completed') {
            return ['cycle_count_id' => $referenceId, 'status' => 'completed'];
        }
        if ((string)($count['status'] ?? '') === 'cancelled') {
            throw new RuntimeException('Cancelled cycle counts cannot be closed.');
        }

        return wmsCycleCountClose($referenceId, $actorUserId);
    }

    return ['manual' => true];
}

function wmsTaskCreate(array $data): int
{
    $type = wmsSanitizeString($data['task_type'] ?? '', 50);
    if (!in_array($type, wmsTaskTypes(), true)) {
        throw new RuntimeException('Invalid task type.');
    }

    $warehouseId = wmsRequirePositiveId((int)($data['warehouse_id'] ?? 0), 'Warehouse ID');
    $priority = (int)($data['priority'] ?? 50);
    $status = wmsSanitizeString($data['status'] ?? 'pending', 50);
    if (!in_array($status, wmsTaskStatuses(), true)) {
        $status = 'pending';
    }

    $db = wmsDb();
    $db->execute(
        'INSERT INTO wms_tasks (warehouse_id, task_type, status, priority, reference_type, reference_id, assigned_to, due_at, notes, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
        [
            $warehouseId,
            $type,
            $status,
            $priority,
            isset($data['reference_type']) ? wmsSanitizeString($data['reference_type'], 50) : null,
            isset($data['reference_id']) ? (int)$data['reference_id'] : null,
            isset($data['assigned_to']) ? (int)$data['assigned_to'] : null,
            isset($data['due_at']) ? wmsSanitizeString($data['due_at'], 50) : null,
            isset($data['notes']) ? wmsSanitizeString($data['notes'], 2000) : null,
        ]
    );

    return (int)$db->lastInsertId();
}

function wmsTasksList(array $filters = []): array
{
    $where = ['1=1'];
    $params = [];

    $warehouseId = (int)($filters['warehouse_id'] ?? 0);
    if ($warehouseId > 0) {
        $where[] = 't.warehouse_id = ?';
        $params[] = $warehouseId;
    }

    $status = trim((string)($filters['status'] ?? ''));
    if ($status !== '') {
        $where[] = 't.status = ?';
        $params[] = $status;
    }

    $type = trim((string)($filters['task_type'] ?? ''));
    if ($type !== '') {
        $where[] = 't.task_type = ?';
        $params[] = $type;
    }

    $assignedTo = (int)($filters['assigned_to'] ?? 0);
    if ($assignedTo > 0) {
        $where[] = 't.assigned_to = ?';
        $params[] = $assignedTo;
    }

    return wmsFetchAll(
        'SELECT
            t.*, 
            w.code AS warehouse_code,
            w.name AS warehouse_name,
            u.full_name AS assigned_name,
            COALESCE(ex.open_exception_count, 0) AS open_exception_count
         FROM wms_tasks t
         INNER JOIN wms_warehouses w ON w.id = t.warehouse_id
         LEFT JOIN wms_users u ON u.id = t.assigned_to
         LEFT JOIN (
             SELECT task_id, COUNT(*) AS open_exception_count
             FROM wms_task_exceptions
             WHERE status = ?
             GROUP BY task_id
         ) ex ON ex.task_id = t.id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY CASE WHEN t.status = ? THEN 0 WHEN t.status = ? THEN 1 ELSE 2 END, t.priority ASC, t.created_at ASC
         LIMIT 500',
        array_merge(['open'], $params, ['in_progress', 'pending'])
    );
}

function wmsTaskExceptionsList(array $filters = []): array
{
    $where = ['1=1'];
    $params = [];

    $taskId = (int)($filters['task_id'] ?? 0);
    if ($taskId > 0) {
        $where[] = 'e.task_id = ?';
        $params[] = $taskId;
    }

    $status = array_key_exists('status', $filters) ? trim((string)$filters['status']) : 'open';
    if ($status !== '') {
        $where[] = 'e.status = ?';
        $params[] = $status;
    }

    return wmsFetchAll(
        'SELECT
            e.*,
            t.task_type,
            t.status AS task_status,
            t.priority,
            t.reference_type,
            t.reference_id,
            w.code AS warehouse_code,
            w.name AS warehouse_name,
            u.full_name AS assigned_name
         FROM wms_task_exceptions e
         INNER JOIN wms_tasks t ON t.id = e.task_id
         INNER JOIN wms_warehouses w ON w.id = t.warehouse_id
         LEFT JOIN wms_users u ON u.id = t.assigned_to
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY CASE WHEN e.status = ? THEN 0 ELSE 1 END, e.created_at DESC, e.id DESC',
        array_merge($params, ['open'])
    );
}

function wmsTaskExceptionResolve(int $exceptionId, ?string $resolutionNote, int $actorUserId): array
{
    $exception = wmsTaskExceptionFind($exceptionId);
    if ($exception === null) {
        throw new RuntimeException('Task exception not found.');
    }

    if ((string)($exception['status'] ?? '') !== 'resolved') {
        return wmsTaskExceptionPersistResolution($exceptionId, $resolutionNote, $actorUserId);
    }

    return $exception;
}

function wmsTaskScanConfirm(int $taskId, array $scanPayload, int $actorUserId): array
{
    $action = wmsSanitizeString($scanPayload['action'] ?? 'complete', 20);
    if (!in_array($action, ['start', 'complete'], true)) {
        throw new RuntimeException('Invalid task scan action.');
    }

    $task = wmsTaskFind($taskId);
    if ($task === null) {
        throw new RuntimeException('Task not found.');
    }

    if ((string)($task['status'] ?? '') === 'cancelled') {
        throw new RuntimeException('Cancelled tasks cannot be confirmed.');
    }
    if ($action === 'start' && (string)($task['status'] ?? '') === 'in_progress') {
        return [
            'task_id' => $taskId,
            'action' => $action,
            'matched' => true,
            'executed' => false,
            'status' => 'in_progress',
            'task' => wmsTaskGetDetailed($taskId),
        ];
    }
    if ($action === 'complete' && (string)($task['status'] ?? '') === 'completed') {
        return [
            'task_id' => $taskId,
            'action' => $action,
            'matched' => true,
            'executed' => true,
            'status' => 'completed',
            'task' => wmsTaskGetDetailed($taskId),
        ];
    }

    try {
        wmsTaskPrepareReference($task);
    } catch (Throwable $e) {
        $exceptionId = wmsTaskExceptionCreate($taskId, 'preparation_failed', $e->getMessage(), $scanPayload, $actorUserId);
        return [
            'task_id' => $taskId,
            'action' => $action,
            'matched' => false,
            'executed' => false,
            'message' => $e->getMessage(),
            'errors' => [$e->getMessage()],
            'exception_id' => $exceptionId,
            'task' => wmsTaskGetDetailed($taskId),
        ];
    }

    $task = wmsTaskFind($taskId);
    if ($task === null) {
        throw new RuntimeException('Task not found.');
    }

    $expected = wmsTaskExpectedScanContext($task);
    $validation = wmsTaskCompareScan($expected, $scanPayload);

    if (!$validation['matched']) {
        $message = implode(' ', $validation['errors']);
        $exceptionId = wmsTaskExceptionCreate($taskId, 'scan_mismatch', $message, array_merge($scanPayload, ['expected' => $expected['reference'] ?? []]), $actorUserId);
        return [
            'task_id' => $taskId,
            'action' => $action,
            'matched' => false,
            'executed' => false,
            'message' => $message,
            'errors' => $validation['errors'],
            'exception_id' => $exceptionId,
            'expected_scan' => $expected,
            'task' => wmsTaskGetDetailed($taskId),
        ];
    }

    if ((string)($task['status'] ?? '') === 'pending') {
        wmsTaskUpdateStatus($taskId, 'in_progress', $actorUserId);
    }

    if ($action === 'start') {
        wmsTaskResolveOpenExceptions($taskId, $actorUserId);
        return [
            'task_id' => $taskId,
            'action' => $action,
            'matched' => true,
            'executed' => false,
            'status' => 'in_progress',
            'task' => wmsTaskGetDetailed($taskId),
        ];
    }

    try {
        $execution = wmsTaskExecute($task, $scanPayload, $validation, $actorUserId);
    } catch (Throwable $e) {
        $exceptionId = wmsTaskExceptionCreate($taskId, 'execution_failed', $e->getMessage(), $scanPayload, $actorUserId);
        return [
            'task_id' => $taskId,
            'action' => $action,
            'matched' => true,
            'executed' => false,
            'message' => $e->getMessage(),
            'exception_id' => $exceptionId,
            'task' => wmsTaskGetDetailed($taskId),
        ];
    }

    wmsTaskUpdateStatus($taskId, 'completed', $actorUserId);
    wmsTaskResolveOpenExceptions($taskId, $actorUserId);

    return [
        'task_id' => $taskId,
        'action' => $action,
        'matched' => true,
        'executed' => true,
        'status' => 'completed',
        'execution' => $execution,
        'task' => wmsTaskGetDetailed($taskId),
    ];
}

function wmsTaskUpdateStatus(int $taskId, string $status, ?int $actorUserId = null): void
{
    if (!in_array($status, wmsTaskStatuses(), true)) {
        throw new RuntimeException('Invalid task status.');
    }

    $task = wmsFetchOne('SELECT * FROM wms_tasks WHERE id = ? LIMIT 1 FOR UPDATE', [$taskId]);
    if ($task === null) {
        throw new RuntimeException('Task not found.');
    }

    $updates = ['status = ?', 'updated_at = NOW()'];
    $params = [$status];

    if ($status === 'in_progress' && $task['started_at'] === null) {
        $updates[] = 'started_at = NOW()';
        $updates[] = 'completed_at = NULL';
    } elseif ($status === 'completed' || $status === 'cancelled') {
        $updates[] = 'completed_at = NOW()';
    }

    if ($actorUserId !== null) {
        $updates[] = 'assigned_to = ?';
        $params[] = $actorUserId;
    }

    $params[] = $taskId;

    wmsDb()->execute('UPDATE wms_tasks SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);
}

function wmsGenerateReplenishmentTasks(int $warehouseId): int
{
    $products = wmsFetchAll(
        'SELECT p.id, p.reorder_point, p.safety_stock, s.qty_available 
         FROM wms_products p 
         JOIN (
            SELECT product_id, SUM(qty_available) as qty_available 
            FROM wms_stocks 
            WHERE warehouse_id = ? 
            GROUP BY product_id
         ) s ON s.product_id = p.id
         WHERE p.reorder_point > 0 AND s.qty_available <= p.reorder_point AND p.deleted_at IS NULL',
        [$warehouseId]
    );

    $count = 0;
    foreach ($products as $p) {
        $existing = wmsFetchOne(
            'SELECT id FROM wms_tasks
             WHERE warehouse_id = ?
               AND task_type = ?
               AND reference_type = ?
               AND reference_id = ?
               AND status IN (?, ?)
             LIMIT 1',
            [$warehouseId, 'replenish', 'product', (int)$p['id'], 'pending', 'in_progress']
        );
        if ($existing !== null) {
            continue;
        }

        $targetQty = max(0, $p['safety_stock'] - $p['qty_available']);
        if ($targetQty > 0) {
            wmsTaskCreate([
                'warehouse_id' => $warehouseId,
                'task_type' => 'replenish',
                'priority' => 10,
                'reference_type' => 'product',
                'reference_id' => (int)$p['id'],
                'notes' => 'Auto-replenishment generated. Current available qty: ' . $p['qty_available'] . ', safety stock target: ' . $p['safety_stock'] . ', replenish target: ' . $targetQty . ' for product ID: ' . $p['id'],
            ]);
            $count++;
        }
    }

    return $count;
}

function wms_cap_wms_replenishment_suggest_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $warehouseId = (int)($payload['warehouse_id'] ?? 0);
    if ($warehouseId <= 0) {
        return ['ok' => false, 'error' => 'Warehouse ID required'];
    }

    $created = wmsGenerateReplenishmentTasks($warehouseId);
    return ['ok' => true, 'tasks_created' => $created];
}
