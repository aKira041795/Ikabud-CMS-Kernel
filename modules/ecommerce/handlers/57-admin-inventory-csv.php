<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Inventory CSV Import / Export (handlers/57-admin-inventory-csv.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /ecommerce/admin/inventory/export
 *
 * Streams a CSV download of all stock-tracked products.
 * Respects the same search/filter/store_id params as the inventory page.
 */
function ecAdminInventoryExportCsv(): void
{
    ecRequireAdmin();

    $input   = ecInput();
    $search  = trim((string)($input['search'] ?? ''));
    $filter  = trim((string)($input['filter'] ?? ''));
    $storeId = max(0, (int)($input['store_id'] ?? 0));

    // Fetch all matching rows (no pagination for export)
    $inventory = ecInventoryList([
        'search'   => $search,
        'filter'   => $filter,
        'store_id' => $storeId > 0 ? $storeId : null,
        'limit'    => 100000,
        'offset'   => 0,
    ]);

    $headers = ecCsvInventoryHeaders();
    $rows    = ecCsvInventoryExportRows($inventory['items']);

    ecCsvResponse(
        'inventory-' . date('Y-m-d-His') . '.csv',
        $headers,
        $rows
    );
}

/**
 * POST /ecommerce/admin/inventory/import
 *
 * Accepts a CSV upload with SKU + stock_qty columns and bulk-updates
 * inventory quantities. Redirects back to the inventory page with results.
 */
function ecAdminInventoryImportCsv(): void
{
    $user = ecRequireAdmin();
    csrf_verify();

    $upload = ecImportReadUploadedCsv('inventory_csv');
    if (empty($upload['ok'])) {
        $_SESSION['ec_message'] = [
            'type'  => 'error',
            'text'  => (string)($upload['error'] ?? 'CSV upload failed.'),
        ];
        header('Location: ' . ecGetBaseUrl() . '/ecommerce/admin/inventory');
        exit;
    }

    try {
        $result = ecImportInventoryFromCsv((string)$upload['raw'], (int)($user['id'] ?? 0));
        $_SESSION['ec_message'] = [
            'type' => empty($result['errors']) ? 'success' : 'warning',
            'text' => 'Inventory CSV import finished: '
                . (int)($result['updated'] ?? 0) . ' updated, '
                . (int)($result['skipped'] ?? 0) . ' skipped, '
                . count((array)($result['errors'] ?? [])) . ' errors.',
        ];
        if (!empty($result['errors'])) {
            $_SESSION['ec_inventory_import_errors'] = array_slice($result['errors'], 0, 20);
        }
    } catch (\Throwable $e) {
        write_log('ecAdminInventoryImportCsv error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        $_SESSION['ec_message'] = [
            'type' => 'error',
            'text' => 'Inventory CSV import failed: ' . $e->getMessage(),
        ];
    }

    header('Location: ' . ecGetBaseUrl() . '/ecommerce/admin/inventory');
    exit;
}

/**
 * CSV column headers for inventory export.
 */
function ecCsvInventoryHeaders(): array
{
    return ['SKU', 'Product', 'Stock Qty', 'Status', 'Source'];
}

/**
 * Build export row arrays from inventory items.
 */
function ecCsvInventoryExportRows(array $items): array
{
    $rows = [];
    foreach ($items as $item) {
        $status = 'In Stock';
        if (!empty($item['out_of_stock'])) {
            $status = 'Out of Stock';
        } elseif (!empty($item['low_stock'])) {
            $status = 'Low Stock';
        }

        $rows[] = [
            'SKU'       => (string)($item['sku'] ?? ''),
            'Product'   => (string)($item['title'] ?? ''),
            'Stock Qty' => (int)($item['stock_qty'] ?? 0),
            'Status'    => $status,
            'Source'    => (string)($item['source'] ?? 'local'),
        ];
    }

    return $rows;
}

/**
 * Import inventory quantities from CSV content.
 *
 * Required columns: SKU, Stock Qty
 * Optional columns: Product (ignored, informational only)
 *
 * Each row looks up the product by SKU, then sets the stock_qty in
 * cms_entity_capabilities.config for the 'inventory' capability.
 */
function ecImportInventoryFromCsv(string $csvContent, int $actorUserId): array
{
    $rows = ecCsvRowsFromString($csvContent);
    if ($rows === []) {
        return ['updated' => 0, 'skipped' => 0, 'errors' => ['CSV file is empty or has no data rows.']];
    }

    // Validate that required columns exist
    $firstRow = $rows[0] ?? [];
    $normalizedKeys = array_map('ecCsvNormalizeHeader', array_keys($firstRow));
    if (!in_array('sku', $normalizedKeys, true)) {
        return ['updated' => 0, 'skipped' => 0, 'errors' => ['Missing required column: SKU']];
    }
    if (!in_array('stock qty', $normalizedKeys, true) && !in_array('stock_qty', $normalizedKeys, true) && !in_array('quantity', $normalizedKeys, true)) {
        return ['updated' => 0, 'skipped' => 0, 'errors' => ['Missing required column: Stock Qty (or Quantity)']];
    }

    $updated = 0;
    $skipped = 0;
    $errors  = [];
    $db      = ecDb();

    foreach ($rows as $idx => $row) {
        $rowNum = $idx + 2; // +2 for 1-indexed + header row
        $normalizedRow = [];
        foreach ($row as $key => $val) {
            $normalizedRow[ecCsvNormalizeHeader($key)] = $val;
        }

        $sku = trim((string)($normalizedRow['sku'] ?? ''));
        if ($sku === '') {
            $skipped++;
            continue;
        }

        // Resolve stock qty from whichever column name variant they used
        $stockQty = null;
        foreach (['stock qty', 'stock_qty', 'quantity'] as $colName) {
            if (isset($normalizedRow[$colName]) && $normalizedRow[$colName] !== '') {
                $stockQty = (int)$normalizedRow[$colName];
                break;
            }
        }
        if ($stockQty === null) {
            $skipped++;
            continue;
        }
        $stockQty = max(0, $stockQty);

        // Look up product by SKU via the inventory capability config
        try {
            $capRow = $db->query(
                "SELECT ec.id, ec.entity_id, ec.config
                 FROM cms_entity_capabilities ec
                 INNER JOIN cms_content c ON c.id = ec.entity_id AND c.type = 'product' AND c.deleted_at IS NULL
                 WHERE ec.capability_id = 'inventory'
                   AND JSON_UNQUOTE(JSON_EXTRACT(ec.config, '$.sku')) = ?
                 LIMIT 1",
                [$sku]
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$capRow) {
                $errors[] = "Row {$rowNum}: SKU \"{$sku}\" not found.";
                continue;
            }

            $config = (array)json_decode((string)($capRow['config'] ?? '{}'), true);
            $config['stock_qty'] = $stockQty;

            moduleWithContext('cms', static function () use ($config, $capRow): void {
                cmsDb()->execute(
                    "UPDATE cms_entity_capabilities SET config = ?, updated_at = NOW() WHERE id = ?",
                    [json_encode($config), (int)$capRow['id']]
                );
            });

            $updated++;
        } catch (\Throwable $e) {
            $errors[] = "Row {$rowNum}: SKU \"{$sku}\" — " . $e->getMessage();
        }
    }

    write_log("Inventory CSV import complete: {$updated} updated, {$skipped} skipped, " . count($errors) . " errors", 'info', [
        'module' => 'ecommerce',
        'actor'  => $actorUserId,
    ]);

    return ['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
}
