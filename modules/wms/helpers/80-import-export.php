<?php

declare(strict_types=1);

function wmsCsvNormalizeHeader(string $header): string
{
    $normalized = strtolower(trim($header));
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
    return trim($normalized, '_');
}

function wmsCsvRowsFromString(string $csvContent): array
{
    $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
    $csvContent = trim($csvContent);
    if ($csvContent === '') {
        throw new RuntimeException('CSV content is required.');
    }

    $lines = preg_split('/\r\n|\n|\r/', $csvContent) ?: [];
    $headers = null;
    $rows = [];

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        $values = str_getcsv($line);
        if ($headers === null) {
            $headers = array_map(static fn ($header) => wmsCsvNormalizeHeader((string)$header), $values);
            continue;
        }

        $values = array_pad($values, count($headers), null);
        $rows[] = array_combine($headers, array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $values));
    }

    if ($headers === null) {
        throw new RuntimeException('CSV header row is required.');
    }

    return $rows;
}

function wmsCsvBool(mixed $value, bool $default = false): int
{
    if ($value === null || $value === '') {
        return $default ? 1 : 0;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true) ? 1 : 0;
}

function wmsCsvResponse(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $stream = fopen('php://output', 'wb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }

    fputcsv($stream, $headers);
    foreach ($rows as $row) {
        $ordered = [];
        foreach ($headers as $header) {
            $ordered[] = $row[$header] ?? '';
        }
        fputcsv($stream, $ordered);
    }

    fclose($stream);
    exit;
}

function wmsImportProductsFromCsv(string $csvContent, int $actorUserId): array
{
    $rows = wmsCsvRowsFromString($csvContent);
    $created = 0;
    $updated = 0;
    $errors = [];

    foreach ($rows as $index => $row) {
        $lineNumber = $index + 2;
        try {
            $sku = wmsSanitizeString($row['sku'] ?? '', 100);
            $name = wmsSanitizeString($row['name'] ?? '', 255);
            if ($sku === '' || $name === '') {
                throw new RuntimeException('SKU and name are required.');
            }

            $existing = wmsFetchOne('SELECT id FROM wms_products WHERE sku = ? LIMIT 1', [$sku]);
            $params = [
                $sku,
                wmsSanitizeString($row['barcode'] ?? '', 100) ?: null,
                $name,
                wmsSanitizeString($row['description'] ?? '', 5000) ?: null,
                wmsSanitizeString($row['unit'] ?? 'pcs', 50) ?: 'pcs',
                wmsSanitizeString($row['product_type'] ?? 'physical', 50) ?: 'physical',
                ($row['weight'] ?? '') !== '' ? wmsNormalizeDecimal($row['weight']) : null,
                ($row['reorder_point'] ?? '') !== '' ? wmsNormalizeDecimal($row['reorder_point']) : 0.0,
                ($row['safety_stock'] ?? '') !== '' ? wmsNormalizeDecimal($row['safety_stock']) : 0.0,
                wmsCsvBool($row['is_batch_tracked'] ?? 0),
                wmsCsvBool($row['is_active'] ?? 1, true),
            ];

            if ($existing === null) {
                wmsDb()->execute(
                    'INSERT INTO wms_products (sku, barcode, name, description, unit, product_type, weight, reorder_point, safety_stock, is_batch_tracked, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    $params
                );
                $created++;
            } else {
                $params[] = (int)$existing['id'];
                wmsDb()->execute(
                    'UPDATE wms_products SET sku = ?, barcode = ?, name = ?, description = ?, unit = ?, product_type = ?, weight = ?, reorder_point = ?, safety_stock = ?, is_batch_tracked = ?, is_active = ?, deleted_at = NULL, updated_at = NOW() WHERE id = ?',
                    $params
                );
                $updated++;
            }
        } catch (Throwable $e) {
            $errors[] = ['line' => $lineNumber, 'message' => $e->getMessage()];
        }
    }

    wmsLog('Imported products CSV by user #' . $actorUserId . ' (' . $created . ' created, ' . $updated . ' updated, ' . count($errors) . ' errors)');

    return [
        'created' => $created,
        'updated' => $updated,
        'errors' => $errors,
    ];
}

function wmsImportSuppliersFromCsv(string $csvContent, int $actorUserId): array
{
    $rows = wmsCsvRowsFromString($csvContent);
    $created = 0;
    $updated = 0;
    $errors = [];

    foreach ($rows as $index => $row) {
        $lineNumber = $index + 2;
        try {
            $code = wmsSanitizeString($row['code'] ?? '', 50);
            $name = wmsSanitizeString($row['name'] ?? '', 255);
            if ($code === '' || $name === '') {
                throw new RuntimeException('Code and name are required.');
            }

            $existing = wmsFetchOne('SELECT id FROM wms_suppliers WHERE code = ? LIMIT 1', [$code]);
            $params = [
                $code,
                $name,
                wmsSanitizeString($row['contact_person'] ?? '', 255) ?: null,
                wmsSanitizeString($row['email'] ?? '', 255) ?: null,
                wmsSanitizeString($row['phone'] ?? '', 50) ?: null,
                wmsSanitizeString($row['address'] ?? '', 5000) ?: null,
                ($row['lead_time_days'] ?? '') !== '' ? (int)$row['lead_time_days'] : null,
                wmsCsvBool($row['is_active'] ?? 1, true),
            ];

            if ($existing === null) {
                wmsDb()->execute(
                    'INSERT INTO wms_suppliers (code, name, contact_person, email, phone, address, lead_time_days, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    $params
                );
                $created++;
            } else {
                $params[] = (int)$existing['id'];
                wmsDb()->execute(
                    'UPDATE wms_suppliers SET code = ?, name = ?, contact_person = ?, email = ?, phone = ?, address = ?, lead_time_days = ?, is_active = ?, deleted_at = NULL, updated_at = NOW() WHERE id = ?',
                    $params
                );
                $updated++;
            }
        } catch (Throwable $e) {
            $errors[] = ['line' => $lineNumber, 'message' => $e->getMessage()];
        }
    }

    wmsLog('Imported suppliers CSV by user #' . $actorUserId . ' (' . $created . ' created, ' . $updated . ' updated, ' . count($errors) . ' errors)');

    return [
        'created' => $created,
        'updated' => $updated,
        'errors' => $errors,
    ];
}

function wmsImportStockFromCsv(string $csvContent, int $actorUserId): array
{
    $rows = wmsCsvRowsFromString($csvContent);
    $adjusted = 0;
    $skipped = 0;
    $errors = [];

    foreach ($rows as $index => $row) {
        $lineNumber = $index + 2;
        try {
            $sku = wmsSanitizeString($row['sku'] ?? '', 100);
            $warehouseCode = wmsSanitizeString($row['warehouse_code'] ?? '', 100);
            $locationCode = wmsSanitizeString($row['location_code'] ?? '', 100);
            if ($sku === '' || $warehouseCode === '' || $locationCode === '' || ($row['qty_on_hand'] ?? '') === '') {
                throw new RuntimeException('sku, warehouse_code, location_code, and qty_on_hand are required.');
            }

            $product = wmsFetchOne('SELECT id, is_batch_tracked FROM wms_products WHERE sku = ? AND deleted_at IS NULL LIMIT 1', [$sku]);
            if ($product === null) {
                throw new RuntimeException('Product not found for SKU ' . $sku . '.');
            }

            $warehouse = wmsFetchOne('SELECT id FROM wms_warehouses WHERE code = ? AND deleted_at IS NULL LIMIT 1', [$warehouseCode]);
            if ($warehouse === null) {
                throw new RuntimeException('Warehouse not found for code ' . $warehouseCode . '.');
            }

            $location = wmsFetchOne('SELECT id FROM wms_locations WHERE warehouse_id = ? AND code = ? AND deleted_at IS NULL LIMIT 1', [(int)$warehouse['id'], $locationCode]);
            if ($location === null) {
                throw new RuntimeException('Location not found for code ' . $locationCode . '.');
            }

            $batchId = null;
            $batchNumber = wmsSanitizeString($row['batch_number'] ?? '', 100);
            if ($batchNumber !== '') {
                $batch = wmsFetchOne('SELECT id FROM wms_batches WHERE product_id = ? AND batch_number = ? LIMIT 1', [(int)$product['id'], $batchNumber]);
                if ($batch === null) {
                    wmsDb()->execute(
                        'INSERT INTO wms_batches (product_id, batch_number, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
                        [(int)$product['id'], $batchNumber]
                    );
                    $batchId = (int)wmsDb()->lastInsertId();
                } else {
                    $batchId = (int)$batch['id'];
                }
            }

            $targetQty = wmsNormalizeDecimal($row['qty_on_hand']);
            $current = wmsStockGet((int)$product['id'], (int)$location['id'], $batchId);
            $currentQty = wmsNormalizeDecimal($current['qty_on_hand'] ?? 0);
            $delta = wmsNormalizeDecimal($targetQty - $currentQty);

            if ($delta == 0.0) {
                $skipped++;
                continue;
            }

            wmsMovementCreate([
                'movement_type' => 'adjustment',
                'reference_type' => 'import',
                'product_id' => (int)$product['id'],
                'warehouse_id' => (int)$warehouse['id'],
                'location_id' => (int)$location['id'],
                'batch_id' => $batchId,
                'qty' => $delta,
                'unit_cost' => ($row['unit_cost'] ?? '') !== '' ? wmsNormalizeDecimal($row['unit_cost']) : null,
                'notes' => wmsSanitizeString($row['notes'] ?? 'Stock CSV import', 2000),
                'actor_user_id' => $actorUserId,
                'meta' => [
                    'import_type' => 'stock_csv',
                    'target_qty_on_hand' => $targetQty,
                ],
            ]);
            $adjusted++;
        } catch (Throwable $e) {
            $errors[] = ['line' => $lineNumber, 'message' => $e->getMessage()];
        }
    }

    wmsLog('Imported stock CSV by user #' . $actorUserId . ' (' . $adjusted . ' adjusted, ' . $skipped . ' skipped, ' . count($errors) . ' errors)');

    return [
        'adjusted' => $adjusted,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

function wmsExportProductsRows(): array
{
    return wmsFetchAll(
        'SELECT sku, barcode, name, description, unit, product_type, weight, reorder_point, safety_stock, is_batch_tracked, is_active
         FROM wms_products
         WHERE deleted_at IS NULL
         ORDER BY name ASC'
    );
}

function wmsExportSuppliersRows(): array
{
    return wmsFetchAll(
        'SELECT code, name, contact_person, email, phone, address, lead_time_days, is_active
         FROM wms_suppliers
         WHERE deleted_at IS NULL
         ORDER BY name ASC'
    );
}

function wmsExportStockRows(): array
{
    return array_map(static function (array $row): array {
        return [
            'sku' => $row['sku'] ?? '',
            'product_name' => $row['product_name'] ?? '',
            'warehouse_code' => $row['warehouse_code'] ?? '',
            'warehouse_name' => $row['warehouse_name'] ?? '',
            'location_code' => $row['location_code'] ?? '',
            'batch_number' => $row['batch_number'] ?? '',
            'qty_on_hand' => $row['qty_on_hand'] ?? 0,
            'qty_reserved' => $row['qty_reserved'] ?? 0,
            'qty_available' => $row['qty_available'] ?? 0,
        ];
    }, wmsStockSnapshot(0, []));
}