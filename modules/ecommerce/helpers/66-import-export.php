<?php

declare(strict_types=1);

function ecCsvNormalizeHeader(string $header): string
{
    $normalized = strtolower(trim($header));
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
    return trim($normalized, '_');
}

function ecCsvRowsFromString(string $csvContent): array
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
            $headers = array_map(static fn(string $header): string => ecCsvNormalizeHeader($header), $values);
            continue;
        }

        $values = array_pad($values, count($headers), null);
        $rows[] = array_combine($headers, array_map(
            static fn(mixed $value): mixed => is_string($value) ? trim($value) : $value,
            $values
        ));
    }

    if ($headers === null) {
        throw new RuntimeException('CSV header row is required.');
    }

    return $rows;
}

function ecCsvBool(mixed $value, ?bool $default = null): ?bool
{
    if ($value === null || $value === '') {
        return $default;
    }

    $normalized = strtolower(trim((string)$value));
    if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
        return false;
    }

    return $default;
}

function ecCsvResponse(string $filename, array $headers, array $rows): never
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

function ecImportReadUploadedCsv(string $field, int $maxBytes = 5242880): array
{
    $file = kernelUploadedFile($field);
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'status' => 422, 'error' => 'Upload a valid CSV file first.'];
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is not available.'];
    }

    if (PHP_SAPI !== 'cli' && function_exists('is_uploaded_file') && !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'status' => 422, 'error' => 'CSV upload did not arrive through the HTTP upload pipeline.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is empty.'];
    }
    if ($size > $maxBytes) {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file exceeds the maximum allowed size.'];
    }

    $raw = @file_get_contents($tmpPath);
    if (!is_string($raw) || trim($raw) === '') {
        return ['ok' => false, 'status' => 422, 'error' => 'Uploaded CSV file is empty.'];
    }

    return ['ok' => true, 'file' => $file, 'raw' => $raw];
}

function ecCsvProductHeaders(): array
{
    return [
        'id',
        'title',
        'slug',
        'status',
        'excerpt',
        'body',
        'price',
        'sale_price',
        'currency',
        'sku',
        'stock_qty',
        'track_stock',
        'category_slug',
        'category_name',
        'attributes',
        'tax_class',
        'created_at',
        'updated_at',
    ];
}

function ecCsvOrderHeaders(): array
{
    return [
        'id',
        'order_number',
        'status',
        'payment_status',
        'source',
        'customer_email',
        'customer_name',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'shipping_amount',
        'total',
        'currency',
        'item_count',
        'items',
        'tracking_number',
        'tracking_carrier',
        'tracking_url',
        'created_at',
        'updated_at',
    ];
}

function ecCsvCustomerHeaders(): array
{
    return [
        'id',
        'username',
        'email',
        'display_name',
        'is_active',
        'order_count',
        'lifetime_value',
        'last_login_at',
        'created_at',
        'updated_at',
    ];
}

function ecCsvProductAttributeCell(array $attributes): string
{
    $lines = function_exists('ecProductAttributesToLines') ? ecProductAttributesToLines($attributes) : '';
    if ($lines === '') {
        return '';
    }

    return implode(' | ', array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $lines) ?: [])));
}

function ecCsvParseAttributeCell(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $normalized = preg_replace('/\s*\|\s*/', "\n", $value) ?? $value;
    return function_exists('ecProductParseAttributeLines') ? ecProductParseAttributeLines($normalized) : [];
}

function ecCsvProductIdBySku(string $sku): int
{
    $sku = trim($sku);
    if ($sku === '') {
        return 0;
    }

    try {
        $id = (int)(ecDb()->query(
            "SELECT c.id
             FROM cms_entity_capabilities cap
             INNER JOIN cms_content c ON c.id = cap.entity_id
             WHERE cap.capability_id = 'inventory'
               AND c.type = 'product'
               AND c.deleted_at IS NULL
               AND JSON_UNQUOTE(JSON_EXTRACT(cap.config, '$.sku')) = ?
             LIMIT 1",
            [$sku]
        )->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    } catch (Throwable $e) {
        $rows = ecDb()->query(
            "SELECT c.id, cap.config
             FROM cms_entity_capabilities cap
             INNER JOIN cms_content c ON c.id = cap.entity_id
             WHERE cap.capability_id = 'inventory'
               AND c.type = 'product'
               AND c.deleted_at IS NULL"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $config = json_decode((string)($row['config'] ?? '{}'), true);
            if (trim((string)($config['sku'] ?? '')) === $sku) {
                return (int)($row['id'] ?? 0);
            }
        }
    }

    return 0;
}

function ecCsvCategoryLookup(): array
{
    static $cache = [];

    $tenantId = (int)app()->tenant()->current();
    if (isset($cache[$tenantId])) {
        return $cache[$tenantId];
    }

    $rows = ecDb()->query(ecCmsCategorySelectSql('id, name, slug', 'name ASC'))->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $lookup = [
        'by_id' => [],
        'by_slug' => [],
        'by_name' => [],
    ];

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $slug = trim((string)($row['slug'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $lookup['by_id'][$id] = $row;
        if ($slug !== '') {
            $lookup['by_slug'][strtolower($slug)] = $row;
        }
        if ($name !== '') {
            $lookup['by_name'][strtolower($name)] = $row;
        }
    }

    $cache[$tenantId] = $lookup;
    return $cache[$tenantId];
}

function ecCsvResolveProductCategory(array $row): array
{
    $categoryIdRaw = trim((string)($row['category_id'] ?? ''));
    $categorySlug = strtolower(trim((string)($row['category_slug'] ?? '')));
    $categoryName = strtolower(trim((string)($row['category_name'] ?? '')));
    $provided = $categoryIdRaw !== '' || $categorySlug !== '' || $categoryName !== '';

    if (!$provided) {
        return ['provided' => false, 'category_id' => null];
    }

    $lookup = ecCsvCategoryLookup();
    if ($categoryIdRaw !== '') {
        $categoryId = (int)$categoryIdRaw;
        if ($categoryId > 0 && isset($lookup['by_id'][$categoryId])) {
            return ['provided' => true, 'category_id' => $categoryId];
        }
        throw new RuntimeException('Category id not found: ' . $categoryIdRaw);
    }

    if ($categorySlug !== '' && isset($lookup['by_slug'][$categorySlug])) {
        return ['provided' => true, 'category_id' => (int)($lookup['by_slug'][$categorySlug]['id'] ?? 0)];
    }

    if ($categoryName !== '' && isset($lookup['by_name'][$categoryName])) {
        return ['provided' => true, 'category_id' => (int)($lookup['by_name'][$categoryName]['id'] ?? 0)];
    }

    throw new RuntimeException('Category not found. Use an existing category id, slug, or name.');
}

function ecCsvNormalizeStatus(string $value, ?string $default = null): ?string
{
    $value = trim(strtolower($value));
    if ($value === '') {
        return $default;
    }

    return in_array($value, ['draft', 'published', 'private'], true) ? $value : $default;
}

function ecCsvNullableFloat(mixed $value): ?float
{
    if ($value === null) {
        return null;
    }

    $normalized = trim((string)$value);
    if ($normalized === '') {
        return null;
    }

    if (!is_numeric($normalized)) {
        throw new RuntimeException('Expected a numeric decimal value.');
    }

    return round((float)$normalized, 2);
}

function ecCsvNullableInt(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    $normalized = trim((string)$value);
    if ($normalized === '') {
        return null;
    }

    if (!preg_match('/^-?\d+$/', $normalized)) {
        throw new RuntimeException('Expected an integer value.');
    }

    return (int)$normalized;
}

function ecCsvBuildProductPayload(array $row, ?array $existingProduct = null): array
{
    $isCreate = $existingProduct === null;
    $payload = [];

    $title = trim((string)($row['title'] ?? ''));
    if ($isCreate) {
        if ($title === '') {
            throw new RuntimeException('title is required for new products.');
        }
        $payload['title'] = $title;
        $payload['excerpt'] = trim((string)($row['excerpt'] ?? ''));
        $payload['body'] = (string)($row['body'] ?? '');
    } else {
        if ($title !== '') {
            $payload['title'] = $title;
        }
        if (trim((string)($row['excerpt'] ?? '')) !== '') {
            $payload['excerpt'] = trim((string)$row['excerpt']);
        }
        if (trim((string)($row['body'] ?? '')) !== '') {
            $payload['body'] = (string)$row['body'];
        }
    }

    $slug = trim((string)($row['slug'] ?? ''));
    if ($isCreate || $slug !== '' || $title !== '') {
        $payload['slug'] = $slug;
    }

    $status = ecCsvNormalizeStatus((string)($row['status'] ?? ''), $isCreate ? 'draft' : null);
    if ($status !== null) {
        $payload['status'] = $status;
    }

    $price = ecCsvNullableFloat($row['price'] ?? null);
    $salePrice = ecCsvNullableFloat($row['sale_price'] ?? null);
    if ($isCreate || $price !== null) {
        $payload['price'] = $price;
    }
    if ($isCreate || array_key_exists('sale_price', $row)) {
        $payload['sale_price'] = $salePrice;
    }

    $currency = trim((string)($row['currency'] ?? ''));
    if ($currency !== '') {
        $payload['currency'] = strtoupper($currency);
    }

    $sku = trim((string)($row['sku'] ?? ''));
    if ($isCreate || $sku !== '') {
        $payload['sku'] = $sku;
    }

    $stockQty = ecCsvNullableInt($row['stock_qty'] ?? null);
    if ($isCreate || $stockQty !== null) {
        $payload['stock_qty'] = $stockQty ?? 0;
    }

    $trackStock = ecCsvBool($row['track_stock'] ?? null, null);
    if ($isCreate || $trackStock !== null) {
        $payload['track_stock'] = (bool)($trackStock ?? true);
    }

    $category = ecCsvResolveProductCategory($row);
    if ($category['provided']) {
        $payload['category_id'] = $category['category_id'];
    }

    $attributes = ecCsvParseAttributeCell((string)($row['attributes'] ?? ''));
    if ($isCreate || $attributes !== []) {
        $payload['attributes'] = $attributes;
    }

    $taxClass = trim((string)($row['tax_class'] ?? ''));
    if ($isCreate || $taxClass !== '') {
        $payload['tax_class'] = $taxClass !== '' ? $taxClass : 'standard';
    }

    return $payload;
}

function ecImportProductsFromCsv(string $csvContent, int $actorUserId): array
{
    $rows = ecCsvRowsFromString($csvContent);
    $created = 0;
    $updated = 0;
    $errors = [];

    foreach ($rows as $index => $row) {
        $lineNumber = $index + 2;
        try {
            $existingProduct = null;
            $productId = ecCsvNullableInt($row['id'] ?? null) ?? 0;
            if ($productId > 0) {
                $existingProduct = ecProductGet($productId, false);
                if ($existingProduct === null) {
                    throw new RuntimeException('Product id not found: ' . $productId);
                }
            } else {
                $sku = trim((string)($row['sku'] ?? ''));
                if ($sku !== '') {
                    $matchedProductId = ecCsvProductIdBySku($sku);
                    if ($matchedProductId > 0) {
                        $existingProduct = ecProductGet($matchedProductId, false);
                    }
                }
            }

            $payload = ecCsvBuildProductPayload($row, $existingProduct);
            if ($existingProduct === null) {
                ecProductCreate($payload, $actorUserId);
                $created++;
            } else {
                ecProductUpdate((int)$existingProduct['id'], $payload);
                $updated++;
            }
        } catch (Throwable $e) {
            $errors[] = ['line' => $lineNumber, 'message' => $e->getMessage()];
        }
    }

    write_log('Ecommerce CSV product import completed.', 'info', [
        'module' => 'ecommerce',
        'created' => $created,
        'updated' => $updated,
        'errors' => count($errors),
        'actor_user_id' => $actorUserId,
    ]);

    return [
        'created' => $created,
        'updated' => $updated,
        'errors' => $errors,
        'total_rows' => count($rows),
    ];
}

function ecCsvProductExportRows(): array
{
    $ids = ecDb()->query(
        "SELECT id FROM cms_content WHERE type = 'product' AND deleted_at IS NULL ORDER BY created_at DESC"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $rows = [];

    foreach ($ids as $idValue) {
        $product = ecProductGet((int)$idValue, false);
        if ($product === null) {
            continue;
        }

        $category = (array)($product['categories'][0] ?? []);
        $pricing = (array)($product['pricing'] ?? []);
        $inventory = (array)($product['inventory'] ?? []);

        $rows[] = [
            'id' => (int)($product['id'] ?? 0),
            'title' => (string)($product['title'] ?? ''),
            'slug' => (string)($product['slug'] ?? ''),
            'status' => (string)($product['status'] ?? ''),
            'excerpt' => (string)($product['excerpt'] ?? ''),
            'body' => (string)($product['body'] ?? ''),
            'price' => isset($pricing['price']) ? number_format((float)$pricing['price'], 2, '.', '') : '',
            'sale_price' => isset($pricing['sale_price']) && $pricing['sale_price'] !== null ? number_format((float)$pricing['sale_price'], 2, '.', '') : '',
            'currency' => (string)($pricing['currency'] ?? ecSettings('currency')),
            'sku' => (string)($inventory['sku'] ?? ''),
            'stock_qty' => isset($inventory['stock_qty']) ? (string)(int)$inventory['stock_qty'] : '',
            'track_stock' => !empty($inventory['track_stock']) ? '1' : '0',
            'category_slug' => (string)($category['slug'] ?? ''),
            'category_name' => (string)($category['name'] ?? ''),
            'attributes' => ecCsvProductAttributeCell((array)($product['attributes'] ?? [])),
            'tax_class' => (string)($product['tax_class'] ?? 'standard'),
            'created_at' => (string)($product['created_at'] ?? ''),
            'updated_at' => (string)($product['updated_at'] ?? ''),
        ];
    }

    return $rows;
}

function ecCsvOrderExportRows(): array
{
    $ids = ecDb()->query('SELECT id FROM ec_orders ORDER BY created_at DESC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $rows = [];

    foreach ($ids as $idValue) {
        $order = ecOrderGet((int)$idValue);
        if ($order === null) {
            continue;
        }

        $itemsSummary = [];
        foreach ((array)($order['items'] ?? []) as $item) {
            $title = trim((string)($item['product_title'] ?? 'Item'));
            $qty = (int)($item['qty'] ?? 0);
            $itemsSummary[] = $title . ' x' . $qty;
        }

        $tracking = (array)($order['shipment_tracking'] ?? []);
        $rows[] = [
            'id' => (int)($order['id'] ?? 0),
            'order_number' => (string)($order['order_number'] ?? ''),
            'status' => (string)($order['status'] ?? ''),
            'payment_status' => (string)($order['payment_status'] ?? ''),
            'source' => (string)($order['source'] ?? ''),
            'customer_email' => (string)($order['customer_email'] ?? ''),
            'customer_name' => (string)($order['customer_name'] ?? ''),
            'subtotal' => number_format((float)($order['subtotal_amount'] ?? 0), 2, '.', ''),
            'discount_amount' => number_format((float)($order['discount_amount'] ?? 0), 2, '.', ''),
            'tax_amount' => number_format((float)($order['tax_amount'] ?? 0), 2, '.', ''),
            'shipping_amount' => number_format((float)($order['shipping_amount'] ?? 0), 2, '.', ''),
            'total' => number_format((float)($order['total_amount'] ?? 0), 2, '.', ''),
            'currency' => (string)($order['currency'] ?? ''),
            'item_count' => (string)count((array)($order['items'] ?? [])),
            'items' => implode(' | ', $itemsSummary),
            'tracking_number' => (string)($tracking['tracking_number'] ?? ''),
            'tracking_carrier' => (string)($tracking['carrier'] ?? ''),
            'tracking_url' => (string)($tracking['tracking_url'] ?? ''),
            'created_at' => (string)($order['created_at'] ?? ''),
            'updated_at' => (string)($order['updated_at'] ?? ''),
        ];
    }

    return $rows;
}

function ecCsvCustomerExportRows(): array
{
    return ecDb()->query(
        "SELECT
             u.id,
             u.username,
             u.email,
             u.display_name,
             u.is_active,
             (SELECT COUNT(*) FROM ec_orders o WHERE o.customer_id = u.id) AS order_count,
             (SELECT SUM(o.total) FROM ec_orders o WHERE o.customer_id = u.id) AS lifetime_value,
             u.last_login_at,
             u.created_at,
             u.updated_at
         FROM cms_users u
         WHERE u.role = 'customer'
         ORDER BY u.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ecCsvExportDefinition(string $resource): ?array
{
    $dateSuffix = date('Ymd-His');

    return match (trim(strtolower($resource))) {
        'products' => [
            'label' => 'Products',
            'filename' => 'ecommerce-products-' . $dateSuffix . '.csv',
            'headers' => ecCsvProductHeaders(),
            'rows' => ecCsvProductExportRows(),
        ],
        'orders' => [
            'label' => 'Orders',
            'filename' => 'ecommerce-orders-' . $dateSuffix . '.csv',
            'headers' => ecCsvOrderHeaders(),
            'rows' => ecCsvOrderExportRows(),
        ],
        'customers' => [
            'label' => 'Customers',
            'filename' => 'ecommerce-customers-' . $dateSuffix . '.csv',
            'headers' => ecCsvCustomerHeaders(),
            'rows' => ecCsvCustomerExportRows(),
        ],
        default => null,
    };
}

function ecCsvExportResources(): array
{
    return [
        [
            'key' => 'products',
            'label' => 'Products',
            'description' => 'Current catalog records with pricing, stock, category, and attribute columns.',
            'download_url' => ecGetBaseUrl() . '/ecommerce/admin/import-export/products',
        ],
        [
            'key' => 'orders',
            'label' => 'Orders',
            'description' => 'Order headers with totals, customer details, tracking, and a compact item summary.',
            'download_url' => ecGetBaseUrl() . '/ecommerce/admin/import-export/orders',
        ],
        [
            'key' => 'customers',
            'label' => 'Customers',
            'description' => 'Customer accounts with order counts, lifetime value, and account status.',
            'download_url' => ecGetBaseUrl() . '/ecommerce/admin/import-export/customers',
        ],
    ];
}

function ecStoreImportProductsFromCsv(string $csvContent, int $storeId, int $actorUserId): array
{
    $rows = ecCsvRowsFromString($csvContent);
    $created = 0;
    $updated = 0;
    $errors = [];

    foreach ($rows as $index => $row) {
        $lineNumber = $index + 2;
        try {
            $existingProduct = null;
            $productId = ecCsvNullableInt($row['id'] ?? null) ?? 0;
            if ($productId > 0) {
                $existingProduct = ecProductGet($productId, false);
                if ($existingProduct === null) {
                    throw new RuntimeException('Product id not found: ' . $productId);
                }
            } else {
                $sku = trim((string)($row['sku'] ?? ''));
                if ($sku !== '') {
                    $matchedProductId = ecCsvProductIdBySku($sku);
                    if ($matchedProductId > 0) {
                        $existingProduct = ecProductGet($matchedProductId, false);
                    }
                }
            }

            $payload = ecCsvBuildProductPayload($row, $existingProduct);
            $assignedStores = [];

            if ($existingProduct === null) {
                $productId = ecProductCreate($payload, $actorUserId);
                $created++;
            } else {
                $productId = (int)($existingProduct['id'] ?? 0);
                ecProductUpdate($productId, $payload);
                $updated++;
                if (function_exists('ecProductStoreAssignmentMap')) {
                    $assignmentMap = ecProductStoreAssignmentMap([$productId]);
                    $assignedStores = array_values(array_filter(array_map('intval', $assignmentMap[$productId] ?? [])));
                }
            }

            ecProductSaveStoreAssignments($productId, array_values(array_unique(array_merge($assignedStores, [$storeId]))));
        } catch (Throwable $e) {
            $errors[] = ['line' => $lineNumber, 'message' => $e->getMessage()];
        }
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'errors' => $errors,
        'total_rows' => count($rows),
    ];
}

function ecStoreCsvProductExportRows(int $storeId): array
{
    $result = ecProductList([
        'store_id' => $storeId,
        'store_owned_only' => true,
        'limit' => 5000,
        'offset' => 0,
    ]);
    $rows = [];

    foreach ((array)($result['items'] ?? []) as $product) {
        $resolved = ecProductGet((int)($product['id'] ?? 0), false);
        if (!is_array($resolved)) {
            continue;
        }

        $category = (array)($resolved['categories'][0] ?? []);
        $pricing = (array)($resolved['pricing'] ?? []);
        $inventory = (array)($resolved['inventory'] ?? []);

        $rows[] = [
            'id' => (int)($resolved['id'] ?? 0),
            'title' => (string)($resolved['title'] ?? ''),
            'slug' => (string)($resolved['slug'] ?? ''),
            'status' => (string)($resolved['status'] ?? ''),
            'excerpt' => (string)($resolved['excerpt'] ?? ''),
            'body' => (string)($resolved['body'] ?? ''),
            'price' => isset($pricing['price']) ? number_format((float)$pricing['price'], 2, '.', '') : '',
            'sale_price' => isset($pricing['sale_price']) && $pricing['sale_price'] !== null ? number_format((float)$pricing['sale_price'], 2, '.', '') : '',
            'currency' => (string)($pricing['currency'] ?? ecSettings('currency')),
            'sku' => (string)($inventory['sku'] ?? ''),
            'stock_qty' => isset($inventory['stock_qty']) ? (string)(int)$inventory['stock_qty'] : '',
            'track_stock' => !empty($inventory['track_stock']) ? '1' : '0',
            'category_slug' => (string)($category['slug'] ?? ''),
            'category_name' => (string)($category['name'] ?? ''),
            'attributes' => ecCsvProductAttributeCell((array)($resolved['attributes'] ?? [])),
            'tax_class' => (string)($resolved['tax_class'] ?? 'standard'),
            'created_at' => (string)($resolved['created_at'] ?? ''),
            'updated_at' => (string)($resolved['updated_at'] ?? ''),
        ];
    }

    return $rows;
}

function ecStoreCsvOrderExportRows(int $storeId): array
{
    $result = ecOrderList([
        'store_id' => $storeId,
        'limit' => 5000,
        'offset' => 0,
    ]);
    $rows = [];

    foreach ((array)($result['items'] ?? []) as $order) {
        $resolved = ecOrderGet((int)($order['id'] ?? 0));
        if (!is_array($resolved)) {
            continue;
        }

        $itemsSummary = [];
        foreach ((array)($resolved['items'] ?? []) as $item) {
            if ((int)($item['store_id'] ?? 0) !== $storeId) {
                continue;
            }
            $itemsSummary[] = trim((string)($item['product_title'] ?? 'Item')) . ' x' . (int)($item['qty'] ?? 0);
        }

        $tracking = (array)($resolved['shipment_tracking'] ?? []);
        $rows[] = [
            'id' => (int)($resolved['id'] ?? 0),
            'order_number' => (string)($resolved['order_number'] ?? ''),
            'status' => (string)($resolved['status'] ?? ''),
            'payment_status' => (string)($resolved['payment_status'] ?? ''),
            'source' => (string)($resolved['source'] ?? ''),
            'customer_email' => (string)($resolved['customer_email'] ?? ''),
            'customer_name' => (string)($resolved['customer_name'] ?? ''),
            'subtotal' => number_format((float)($resolved['subtotal_amount'] ?? 0), 2, '.', ''),
            'discount_amount' => number_format((float)($resolved['discount_amount'] ?? 0), 2, '.', ''),
            'tax_amount' => number_format((float)($resolved['tax_amount'] ?? 0), 2, '.', ''),
            'shipping_amount' => number_format((float)($resolved['shipping_amount'] ?? 0), 2, '.', ''),
            'total' => number_format((float)($resolved['total_amount'] ?? 0), 2, '.', ''),
            'currency' => (string)($resolved['currency'] ?? ''),
            'item_count' => (string)count($itemsSummary),
            'items' => implode(' | ', $itemsSummary),
            'tracking_number' => (string)($tracking['tracking_number'] ?? ''),
            'tracking_carrier' => (string)($tracking['carrier'] ?? ''),
            'tracking_url' => (string)($tracking['tracking_url'] ?? ''),
            'created_at' => (string)($resolved['created_at'] ?? ''),
            'updated_at' => (string)($resolved['updated_at'] ?? ''),
        ];
    }

    return $rows;
}

function ecStoreCsvCustomerExportRows(int $storeId): array
{
    $result = ecStoreCustomerList($storeId, ['limit' => 5000, 'offset' => 0]);

    return array_map(static function (array $customer): array {
        return [
            'id' => (int)($customer['customer_id'] ?? 0),
            'username' => (string)($customer['username'] ?? ''),
            'email' => (string)($customer['email'] ?? ''),
            'display_name' => (string)($customer['display_name'] ?? ''),
            'is_active' => (int)($customer['is_active'] ?? 1),
            'order_count' => (int)($customer['order_count'] ?? 0),
            'lifetime_value' => number_format((float)($customer['lifetime_value'] ?? 0), 2, '.', ''),
            'last_login_at' => '',
            'created_at' => (string)($customer['created_at'] ?? ''),
            'updated_at' => (string)($customer['last_order_at'] ?? ''),
        ];
    }, (array)($result['items'] ?? []));
}

function ecStoreCsvExportDefinition(int $storeId, string $resource): ?array
{
    $dateSuffix = date('Ymd-His');
    $store = ecStoreById($storeId);
    $slug = trim((string)($store['slug'] ?? ('store-' . $storeId)));

    return match (trim(strtolower($resource))) {
        'products' => [
            'label' => 'Products',
            'filename' => $slug . '-products-' . $dateSuffix . '.csv',
            'headers' => ecCsvProductHeaders(),
            'rows' => ecStoreCsvProductExportRows($storeId),
        ],
        'orders' => [
            'label' => 'Orders',
            'filename' => $slug . '-orders-' . $dateSuffix . '.csv',
            'headers' => ecCsvOrderHeaders(),
            'rows' => ecStoreCsvOrderExportRows($storeId),
        ],
        'customers' => [
            'label' => 'Customers',
            'filename' => $slug . '-customers-' . $dateSuffix . '.csv',
            'headers' => ecCsvCustomerHeaders(),
            'rows' => ecStoreCsvCustomerExportRows($storeId),
        ],
        default => null,
    };
}

function ecStoreCsvExportResources(int $storeId): array
{
    $base = ecGetBaseUrl() . '/ecommerce/store-admin/' . $storeId . '/import-export';

    return [
        [
            'key' => 'products',
            'label' => 'Products',
            'description' => 'Products assigned to this store, ready for export or round-trip updates.',
            'download_url' => $base . '/products',
        ],
        [
            'key' => 'orders',
            'label' => 'Orders',
            'description' => 'Orders containing this store\'s products, including customer and tracking details.',
            'download_url' => $base . '/orders',
        ],
        [
            'key' => 'customers',
            'label' => 'Customers',
            'description' => 'Customers who purchased from this store, with order counts and store revenue totals.',
            'download_url' => $base . '/customers',
        ],
    ];
}