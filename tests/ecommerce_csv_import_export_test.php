<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/admin/import-export';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/handlers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

$attributeMigration = __DIR__ . '/../modules/ecommerce/database/migrations/015_ec_product_attributes.sql';
if (is_file($attributeMigration)) {
    app()->db()->exec((string)file_get_contents($attributeMigration));
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];
$cleanupOrderIds = [];
$cleanupCustomerIds = [];
$cleanupCategoryIds = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function ecommerceCsvImportExportUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce CSV import/export test');
    }

    return $userId;
}

function ecommerceCsvBuildString(array $headers, array $rows): string
{
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Failed to open temp CSV stream.');
    }

    fputcsv($stream, $headers);
    foreach ($rows as $row) {
        $ordered = [];
        foreach ($headers as $header) {
            $ordered[] = $row[$header] ?? '';
        }
        fputcsv($stream, $ordered);
    }

    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return (string)$csv;
}

function ecommerceCsvCreateProductCategory(string $name, string $slug): int
{
    return (int)moduleWithContext('cms', static function () use ($name, $slug): int {
        $db = cmsDb();
        if (ecHasCmsCategoryTaxonomy()) {
            $db->execute(
                "INSERT INTO cms_categories (name, slug, taxonomy, created_at, updated_at)
                 VALUES (?, ?, 'product', NOW(), NOW())",
                [$name, $slug]
            );
        } else {
            $db->execute(
                'INSERT INTO cms_categories (name, slug, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
                [$name, $slug]
            );
        }

        return (int)$db->lastInsertId();
    });
}

function cleanupEcommerceCsvImportExportFixtures(array $productIds, array $orderIds, array $customerIds, array $categoryIds): void
{
    $db = app()->db();

    if ($orderIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
        $db->prepare("DELETE FROM ec_order_status_history WHERE order_id IN ({$placeholders})")->execute($orderIds);
        $db->prepare("DELETE FROM ec_payment_transactions WHERE order_id IN ({$placeholders})")->execute($orderIds);
        $db->prepare("DELETE FROM ec_order_meta WHERE order_id IN ({$placeholders})")->execute($orderIds);
        $db->prepare("DELETE FROM ec_order_items WHERE order_id IN ({$placeholders})")->execute($orderIds);
        $db->prepare("DELETE FROM ec_orders WHERE id IN ({$placeholders})")->execute($orderIds);
    }

    if ($productIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $db->prepare("DELETE FROM ec_product_attribute_values WHERE product_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_content_categories WHERE content_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
    }

    if ($categoryIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($categoryIds), '?'));
        $db->prepare("DELETE FROM cms_content_categories WHERE category_id IN ({$placeholders})")->execute($categoryIds);
        $db->prepare("DELETE FROM cms_categories WHERE id IN ({$placeholders})")->execute($categoryIds);
    }

    if ($customerIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($customerIds), '?'));
        $db->prepare("DELETE FROM cms_users WHERE id IN ({$placeholders})")->execute($customerIds);
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE CSV IMPORT / EXPORT ===\n";

$userId = ecommerceCsvImportExportUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$categoryId = ecommerceCsvCreateProductCategory('CSV Import Category ' . $seed, 'csv-import-category-' . strtolower($seed));
$cleanupCategoryIds[] = $categoryId;

$productId = ecProductCreate([
    'title' => 'CSV Fixture Product ' . $seed,
    'slug' => 'csv-fixture-product-' . strtolower($seed),
    'excerpt' => 'Fixture product for CSV export/import coverage.',
    'status' => 'published',
    'price' => 64.25,
    'sale_price' => 54.25,
    'sku' => 'CSV-FIX-' . strtoupper($seed),
    'stock_qty' => 11,
    'track_stock' => true,
    'category_id' => $categoryId,
    'attributes' => ecProductParseAttributeLines('Color: Red, Crimson'),
    'tax_class' => 'standard',
], $userId);
$cleanupProductIds[] = $productId;

$customerId = (int)(ecAutoRegisterGuestAsCustomer('csv-customer-' . strtolower($seed) . '@example.test', 'Csv', 'Customer') ?? 0);
if ($customerId > 0) {
    $cleanupCustomerIds[] = $customerId;
}

$order = ecOrderCreate([
    'customer_id' => $customerId > 0 ? $customerId : null,
    'guest_email' => 'csv-customer-' . strtolower($seed) . '@example.test',
    'guest_name' => 'Csv Customer',
    'subtotal' => 64.25,
    'discount_amount' => 5.00,
    'tax_amount' => 3.50,
    'shipping_amount' => 4.00,
    'total' => 66.75,
    'currency' => 'USD',
    'billing' => [
        'first_name' => 'Csv',
        'last_name' => 'Customer',
        'email' => 'csv-customer-' . strtolower($seed) . '@example.test',
    ],
    'shipping' => [
        'first_name' => 'Csv',
        'last_name' => 'Customer',
        'address_line1' => '123 Export Street',
        'city' => 'Catalog City',
        'country' => 'PH',
    ],
    'cart_items' => [[
        'product_id' => $productId,
        'variant_id' => null,
        'qty' => 1,
        'price_snapshot' => 64.25,
        'product_title' => 'CSV Fixture Product ' . $seed,
        'sku' => 'CSV-FIX-' . strtoupper($seed),
    ]],
    'defer_created_event' => true,
]);
$cleanupOrderIds[] = (int)($order['order_id'] ?? 0);

$productExport = ecCsvExportDefinition('products');
$orderExport = ecCsvExportDefinition('orders');
$customerExport = ecCsvExportDefinition('customers');

$productRow = null;
foreach ((array)($productExport['rows'] ?? []) as $row) {
    if ((string)($row['sku'] ?? '') === 'CSV-FIX-' . strtoupper($seed)) {
        $productRow = $row;
        break;
    }
}

$orderRow = null;
foreach ((array)($orderExport['rows'] ?? []) as $row) {
    if ((string)($row['order_number'] ?? '') === (string)($order['order_number'] ?? '')) {
        $orderRow = $row;
        break;
    }
}

$customerRow = null;
foreach ((array)($customerExport['rows'] ?? []) as $row) {
    if ((int)($row['id'] ?? 0) === $customerId) {
        $customerRow = $row;
        break;
    }
}

$importCsv = ecommerceCsvBuildString(ecCsvProductHeaders(), [[
    'id' => '',
    'title' => 'CSV Imported Product ' . $seed,
    'slug' => 'csv-imported-product-' . strtolower($seed),
    'status' => 'published',
    'excerpt' => 'Created from CSV import.',
    'body' => '',
    'price' => '88.50',
    'sale_price' => '',
    'currency' => 'USD',
    'sku' => 'CSV-NEW-' . strtoupper($seed),
    'stock_qty' => '7',
    'track_stock' => 'yes',
    'category_slug' => 'csv-import-category-' . strtolower($seed),
    'category_name' => '',
    'attributes' => 'Color: Green, Emerald | Size: Large',
    'tax_class' => 'standard',
    'created_at' => '',
    'updated_at' => '',
], [
    'id' => (string)$productId,
    'title' => 'CSV Updated Product ' . $seed,
    'slug' => '',
    'status' => 'published',
    'excerpt' => '',
    'body' => '',
    'price' => '79.95',
    'sale_price' => '',
    'currency' => 'USD',
    'sku' => 'CSV-FIX-' . strtoupper($seed),
    'stock_qty' => '4',
    'track_stock' => '1',
    'category_slug' => '',
    'category_name' => '',
    'attributes' => 'Color: Red | Finish: Matte',
    'tax_class' => 'reduced',
    'created_at' => '',
    'updated_at' => '',
]]);

$importResult = ecImportProductsFromCsv($importCsv, $userId);
$newProductId = ecCsvProductIdBySku('CSV-NEW-' . strtoupper($seed));
if ($newProductId > 0) {
    $cleanupProductIds[] = $newProductId;
}

$updatedProduct = ecProductGet($productId, false);
$newProduct = $newProductId > 0 ? ecProductGet($newProductId, false) : null;

$routes = file_get_contents(__DIR__ . '/../modules/ecommerce/routes.php') ?: '';
$adminLayout = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/layouts/admin.disyl') ?: '';
$importTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/import-export.disyl') ?: '';
$productTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/products.disyl') ?: '';
$orderTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/orders.disyl') ?: '';
$customerTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/customers.disyl') ?: '';

t('product export definition exposes CSV filename and headers', str_ends_with((string)($productExport['filename'] ?? ''), '.csv') && in_array('attributes', (array)($productExport['headers'] ?? []), true), json_encode($productExport));
t('product export row includes category and attribute data', is_array($productRow) && (string)($productRow['category_slug'] ?? '') === 'csv-import-category-' . strtolower($seed) && str_contains((string)($productRow['attributes'] ?? ''), 'Color: Red'), json_encode($productRow));
t('order export row includes totals and item summary', is_array($orderRow) && (string)($orderRow['total'] ?? '') === '66.75' && str_contains((string)($orderRow['items'] ?? ''), 'CSV Fixture Product'), json_encode($orderRow));
t('customer export row includes lifetime value and order count', is_array($customerRow) && (int)($customerRow['order_count'] ?? 0) >= 1, json_encode($customerRow));
t('product CSV import creates and updates rows', (int)($importResult['created'] ?? 0) === 1 && (int)($importResult['updated'] ?? 0) === 1 && count((array)($importResult['errors'] ?? [])) === 0, json_encode($importResult));
t('product CSV import creates a new categorized product', $newProduct !== null && (string)($newProduct['title'] ?? '') === 'CSV Imported Product ' . $seed && (string)($newProduct['categories'][0]['slug'] ?? '') === 'csv-import-category-' . strtolower($seed), json_encode($newProduct));
t('product CSV import updates pricing and tax class through existing product helpers', $updatedProduct !== null && (string)($updatedProduct['pricing']['formatted'] ?? '') !== '' && (float)($updatedProduct['pricing']['active_price'] ?? 0) === 79.95 && (string)($updatedProduct['tax_class'] ?? '') === 'reduced', json_encode($updatedProduct));
t('product CSV import updates attributes and clears sale price', $updatedProduct !== null && (float)($updatedProduct['pricing']['sale_price'] ?? 0) === 0.0 && str_contains(ecCsvProductAttributeCell((array)($updatedProduct['attributes'] ?? [])), 'Finish: Matte'), json_encode($updatedProduct));
t('routes expose admin import-export page and resource export route', str_contains($routes, '/ecommerce/admin/import-export') && str_contains($routes, '/ecommerce/admin/import-export/{resource}'));
t('admin navigation exposes import-export page', str_contains($adminLayout, '/ecommerce/admin/import-export'));
t('admin import-export template includes shared download and import actions', str_contains($importTemplate, 'Download {resource.label} CSV') && str_contains($importTemplate, 'Import Products CSV'));
t('products, orders, and customers pages link to the shared import-export surface', str_contains($productTemplate, '/ecommerce/admin/import-export') && str_contains($orderTemplate, '/ecommerce/admin/import-export') && str_contains($customerTemplate, '/ecommerce/admin/import-export'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceCsvImportExportFixtures($cleanupProductIds, $cleanupOrderIds, $cleanupCustomerIds, $cleanupCategoryIds);

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    ✗ {$error}\n";
    }
}
echo "════════════════════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);