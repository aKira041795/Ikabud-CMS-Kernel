<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];

function te(string $label, bool $ok, string $detail = ''): void
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

function ecommerceExternalProductsUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for external products test');
    }

    return $userId;
}

function cleanupExternalProductFixtures(array $productIds): void
{
    if ($productIds === []) {
        return;
    }

    $db = app()->db();
    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content_categories WHERE content_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');
ecCartClear();

echo "\n=== ECOMMERCE EXTERNAL PRODUCTS ===\n";

$userId = ecommerceExternalProductsUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);
$externalUrl = 'https://partner.example.test/products/' . strtolower($seed);

$productId = ecProductCreate([
    'title' => 'External Product ' . $seed,
    'slug' => 'external-product-' . strtolower($seed),
    'excerpt' => 'External partner checkout product.',
    'status' => 'published',
    'price' => 499.00,
    'stock_qty' => 0,
    'track_stock' => false,
    'is_external_product' => true,
    'external_product_url' => $externalUrl,
    'external_product_button_text' => 'Buy on Partner Site',
], $userId);
$cleanupProductIds[] = $productId;

$product = ecProductGet($productId);
$storefront = ecBuildStorefrontDetailContext($product, ['route_kind' => 'product_detail']);
$cartAdd = ecCartAdd($productId, 1);
$bridgePayload = ecProductBridgeEventPayload($productId);

$productTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/product.disyl') ?: '';
$nativeTemplate = file_get_contents(__DIR__ . '/../storage/cms-themes/native-default/public/ecommerce/single-product.disyl') ?: '';
$adminTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/product-edit.disyl') ?: '';
$actionTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/blocks/action.block.disyl') ?: '';
$ordersHelper = file_get_contents(__DIR__ . '/../modules/ecommerce/helpers/20-orders.php') ?: '';

te('external product hydration reads outbound checkout metadata', !empty($product['is_external_product']) && (string)($product['external_product_url'] ?? '') === $externalUrl && (string)($product['product_type'] ?? '') === 'external', json_encode($product));
te('storefront detail context exposes external checkout CTA fields', !empty($storefront['product']['is_external_product']) && (string)($storefront['product']['external_product_button_text'] ?? '') === 'Buy on Partner Site', json_encode($storefront['product'] ?? []));
te('external products are rejected by the server-side cart add path', empty($cartAdd['ok']) && str_contains((string)($cartAdd['error'] ?? ''), 'external checkout'), json_encode($cartAdd));
te('product bridge payload marks external products with external product_type', (string)($bridgePayload['product_type'] ?? '') === 'external', json_encode($bridgePayload));
te('shared and native product templates render external checkout CTA copy', str_contains($productTemplate, 'Buy Externally') && str_contains($nativeTemplate, 'external checkout partner'));
te('admin product form exposes external product fields', str_contains($adminTemplate, 'External or affiliate product') && str_contains($adminTemplate, 'external_product_url'));
te('cms action block and wms bridge definitions recognize external product behavior', str_contains($actionTemplate, 'entity.external_product_url') && str_contains($ordersHelper, "'product_type' => '{{product_type}}'"));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
te('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
te('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

ecCartClear();
cleanupExternalProductFixtures($cleanupProductIds);

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
