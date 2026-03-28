<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/admin/products/new';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

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

function ecommerceSlugSkuTestUserId(): int
{
    static $userId = 0;
    if ($userId > 0) {
        return $userId;
    }

    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce slug/sku test');
    }

    return $userId;
}

function cleanupEcommerceSlugSkuFixture(int $productId): void
{
    if ($productId <= 0) {
        return;
    }

    $db = app()->db();
    $db->prepare('DELETE FROM cms_content_meta WHERE content_id = ?')->execute([$productId]);
    $db->prepare('DELETE FROM cms_entity_capabilities WHERE entity_id = ?')->execute([$productId]);
    $db->prepare('DELETE FROM cms_content WHERE id = ?')->execute([$productId]);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE SLUG / SKU GENERATION ===\n";

$seed = substr(bin2hex(random_bytes(4)), 0, 8);
$productId = ecProductCreate([
    'title' => 'Dynamic Product ' . $seed,
    'slug' => '',
    'sku' => '',
    'status' => 'draft',
], ecommerceSlugSkuTestUserId());

$created = ecProductGet($productId) ?: [];
$createdInventory = ecProductInventory($productId);

t('create generates slug from title when blank', ($created['slug'] ?? '') === 'dynamic-product-' . strtolower($seed), (string)($created['slug'] ?? ''));
t('create generates SKU from slug when blank', ($createdInventory['sku'] ?? '') === 'DYNAMIC-PRODUCT-' . strtoupper($seed), (string)($createdInventory['sku'] ?? ''));

ecProductUpdate($productId, [
    'title' => 'Updated Product ' . $seed,
    'slug' => '',
    'sku' => '',
]);

$updated = ecProductGet($productId) ?: [];
$updatedInventory = ecProductInventory($productId);

t('update regenerates slug from updated title when blank', ($updated['slug'] ?? '') === 'updated-product-' . strtolower($seed), (string)($updated['slug'] ?? ''));
t('update regenerates SKU from updated slug when blank', ($updatedInventory['sku'] ?? '') === 'UPDATED-PRODUCT-' . strtoupper($seed), (string)($updatedInventory['sku'] ?? ''));

cleanupEcommerceSlugSkuFixture($productId);

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP errors in error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

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