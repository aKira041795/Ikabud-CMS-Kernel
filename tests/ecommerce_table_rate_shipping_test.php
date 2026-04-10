<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/checkout';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];
$originalSettings = getModuleSettings('ecommerce');

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

function ecommerceTableRateShippingUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce table-rate shipping test');
    }

    return $userId;
}

function cleanupEcommerceTableRateShippingFixtures(array $productIds, array $originalSettings): void
{
    if ($productIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        app()->db()->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
        app()->db()->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
        app()->db()->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
    }

    saveModuleSettings('ecommerce', is_array($originalSettings) ? $originalSettings : []);
    invalidateTenantModuleSettingsCache();
    if (function_exists('ecSettingsResetCache')) {
        ecSettingsResetCache();
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE TABLE-RATE SHIPPING ===\n";

$userId = ecommerceTableRateShippingUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$physicalProductId = ecProductCreate([
    'title' => 'Shipping Fixture ' . $seed,
    'slug' => 'shipping-fixture-' . strtolower($seed),
    'excerpt' => 'Physical shipping fixture.',
    'status' => 'published',
    'price' => 50.00,
    'stock_qty' => 10,
    'track_stock' => true,
], $userId);
$digitalProductId = ecProductCreate([
    'title' => 'Shipping Digital Fixture ' . $seed,
    'slug' => 'shipping-digital-' . strtolower($seed),
    'excerpt' => 'Digital shipping fixture.',
    'status' => 'published',
    'price' => 75.00,
], $userId);
$cleanupProductIds = [$physicalProductId, $digitalProductId];
ecProductSaveDigitalMeta($digitalProductId, ['is_digital' => '1']);

saveModuleSettings('ecommerce', array_merge(is_array($originalSettings) ? $originalSettings : [], [
    'currency_symbol' => '$',
    'tax_rate' => '0',
    'tax_default_country' => 'PH',
    'shipping_default_country' => 'PH',
    'shipping_table_rate_rules' => implode("\n", [
        'PH||||0|149.99|||120|Metro Saver|Local Courier|3-5 business days',
        'PH||||150||||0|Free Delivery|Local Courier|2-4 business days',
        'PH||||0||3||180|Bulk Freight|Local Courier|2-4 business days',
    ]),
]));
invalidateTenantModuleSettingsCache();
if (function_exists('ecSettingsResetCache')) {
    ecSettingsResetCache();
}

$physicalItemsTwo = [[
    'product_id' => $physicalProductId,
    'qty' => 2,
    'price_snapshot' => 50.00,
    'product_title' => 'Shipping Fixture ' . $seed,
    'sku' => 'SHIP-' . strtoupper($seed),
]];
$physicalItemsThree = [[
    'product_id' => $physicalProductId,
    'qty' => 3,
    'price_snapshot' => 50.00,
    'product_title' => 'Shipping Fixture ' . $seed,
    'sku' => 'SHIP-' . strtoupper($seed),
]];
$digitalItems = [[
    'product_id' => $digitalProductId,
    'qty' => 1,
    'price_snapshot' => 75.00,
    'product_title' => 'Shipping Digital Fixture ' . $seed,
    'sku' => 'SHIP-DIG-' . strtoupper($seed),
]];

$parsedRules = ecShippingParseTableRateRules((string)ecSettings('shipping_table_rate_rules', ''));
$phQuote = ecShippingQuote($physicalItemsTwo, ['country' => 'PH']);
$phBulkQuote = ecShippingQuote($physicalItemsThree, ['country' => 'PH']);
$fallbackQuote = ecShippingQuote($physicalItemsTwo, ['country' => 'FR']);
$digitalQuote = ecShippingQuote($digitalItems, ['country' => 'PH']);
$checkoutTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/checkout.disyl') ?: '';
$nativeCheckoutTemplate = file_get_contents(__DIR__ . '/../storage/cms-themes/native-default/public/ecommerce/checkout.disyl') ?: '';

t('table-rate parser reads all configured rules', count($parsedRules) === 3, 'count=' . count($parsedRules));
t('physical cart requires shipping', ecCartRequiresShipping($physicalItemsTwo));
t('PH quote selects the table-rate shipping rule', (string)($phQuote['selected_rate']['source'] ?? '') === 'table_rate', json_encode($phQuote['selected_rate'] ?? []));
t('PH quote resolves the configured shipping amount', abs((float)($phQuote['totals']['shipping'] ?? 0.0) - 120.0) < 0.001, json_encode($phQuote['totals'] ?? []));
t('higher subtotal exposes free and bulk shipping options', count((array)($phBulkQuote['rates'] ?? [])) === 2 && abs((float)($phBulkQuote['totals']['shipping'] ?? 0.0) - 0.0) < 0.001, json_encode($phBulkQuote['rates'] ?? []));
t('non-matching destination falls back to database shipping rates', (string)($fallbackQuote['selected_rate']['source'] ?? '') === 'database' && (string)($fallbackQuote['selected_rate']['name'] ?? '') === 'Standard Shipping', json_encode($fallbackQuote['selected_rate'] ?? []));
t('digital-only cart bypasses shipping entirely', empty($digitalQuote['requires_shipping']) && count((array)($digitalQuote['rates'] ?? [])) === 0, json_encode($digitalQuote));
t('base checkout template fetches live shipping quotes', str_contains($checkoutTemplate, '/api/v1/ecommerce/shipping/rates'));
t('native checkout template fetches live shipping quotes', str_contains($nativeCheckoutTemplate, '/api/v1/ecommerce/shipping/rates'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceTableRateShippingFixtures($cleanupProductIds, is_array($originalSettings) ? $originalSettings : []);

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