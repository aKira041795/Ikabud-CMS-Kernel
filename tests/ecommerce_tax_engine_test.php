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

function ecommerceTaxEngineTestUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce tax engine test');
    }

    return $userId;
}

function cleanupEcommerceTaxFixtures(array $productIds, array $originalSettings): void
{
    $db = app()->db();

    if ($productIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
    }

    $restoreSettings = is_array($originalSettings) ? $originalSettings : [];
    $defaults = function_exists('ecSettingsDefaults') ? ecSettingsDefaults() : [];
    foreach (['currency_symbol', 'tax_default_country', 'tax_rate', 'tax_inclusive', 'tax_standard_rules', 'tax_reduced_rules', 'tax_zero_rules'] as $key) {
        if (is_array($originalSettings) && array_key_exists($key, $originalSettings)) {
            $restoreSettings[$key] = $originalSettings[$key];
            continue;
        }
        $restoreSettings[$key] = $defaults[$key] ?? '';
    }

    saveModuleSettings('ecommerce', $restoreSettings);
    invalidateTenantModuleSettingsCache();
    if (function_exists('ecSettingsResetCache')) {
        ecSettingsResetCache();
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE TAX ENGINE ===\n";

$userId = ecommerceTaxEngineTestUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$standardProductId = ecProductCreate([
    'title' => 'Tax Standard ' . $seed,
    'slug' => 'tax-standard-' . strtolower($seed),
    'excerpt' => 'Standard tax fixture.',
    'status' => 'published',
    'price' => 100.00,
    'tax_class' => 'standard',
], $userId);
$reducedProductId = ecProductCreate([
    'title' => 'Tax Reduced ' . $seed,
    'slug' => 'tax-reduced-' . strtolower($seed),
    'excerpt' => 'Reduced tax fixture.',
    'status' => 'published',
    'price' => 200.00,
    'tax_class' => 'reduced',
], $userId);
$zeroProductId = ecProductCreate([
    'title' => 'Tax Zero ' . $seed,
    'slug' => 'tax-zero-' . strtolower($seed),
    'excerpt' => 'Zero tax fixture.',
    'status' => 'published',
    'price' => 50.00,
    'tax_class' => 'zero',
], $userId);
$cleanupProductIds = [$standardProductId, $reducedProductId, $zeroProductId];

saveModuleSettings('ecommerce', array_merge(is_array($originalSettings) ? $originalSettings : [], [
    'currency_symbol' => '$',
    'tax_default_country' => 'PH',
    'tax_rate' => '12',
    'tax_inclusive' => false,
    'tax_standard_rules' => "PH||||12\nUS|CA|||8.25",
    'tax_reduced_rules' => "PH||||6",
    'tax_zero_rules' => '',
]));
invalidateTenantModuleSettingsCache();
if (function_exists('ecSettingsResetCache')) {
    ecSettingsResetCache();
}

$items = [
    [
        'product_id' => $standardProductId,
        'qty' => 1,
        'price_snapshot' => 100.00,
        'product_title' => 'Tax Standard ' . $seed,
        'sku' => 'TAX-STD-' . strtoupper($seed),
    ],
    [
        'product_id' => $reducedProductId,
        'qty' => 1,
        'price_snapshot' => 200.00,
        'product_title' => 'Tax Reduced ' . $seed,
        'sku' => 'TAX-RED-' . strtoupper($seed),
    ],
    [
        'product_id' => $zeroProductId,
        'qty' => 1,
        'price_snapshot' => 50.00,
        'product_title' => 'Tax Zero ' . $seed,
        'sku' => 'TAX-ZRO-' . strtoupper($seed),
    ],
];

$philippinesTotals = ecCalculateTotals($items, null, null, [
    'country' => 'PH',
    'state' => 'Metro Manila',
    'city' => 'Manila',
    'postal_code' => '1000',
]);
$fallbackTotals = ecCalculateTotals($items, null, null, [
    'country' => 'FR',
]);

saveModuleSettings('ecommerce', array_merge(is_array($originalSettings) ? $originalSettings : [], [
    'currency_symbol' => '$',
    'tax_default_country' => 'PH',
    'tax_rate' => '12',
    'tax_inclusive' => true,
    'tax_standard_rules' => 'PH||||12',
    'tax_reduced_rules' => '',
    'tax_zero_rules' => '',
]));
invalidateTenantModuleSettingsCache();
if (function_exists('ecSettingsResetCache')) {
    ecSettingsResetCache();
}

$inclusiveTotals = ecCalculateTotals([
    [
        'product_id' => $standardProductId,
        'qty' => 1,
        'price_snapshot' => 112.00,
        'product_title' => 'Tax Standard Inclusive ' . $seed,
        'sku' => 'TAX-INC-' . strtoupper($seed),
    ],
], null, null, ['country' => 'PH']);

$product = ecProductGet($standardProductId) ?: [];
$productEditTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/product-edit.disyl') ?: '';
$settingsTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/settings.disyl') ?: '';

t('product detail hydration includes tax class', (string)($product['tax_class'] ?? '') === 'standard', json_encode($product['tax_class'] ?? null));
t('destination rules apply mixed tax classes', abs((float)($philippinesTotals['tax'] ?? 0.0) - 24.00) < 0.001, json_encode($philippinesTotals));
t('mixed tax classes return mixed tax label', (string)($philippinesTotals['tax_label'] ?? '') === 'Tax (mixed)', (string)($philippinesTotals['tax_label'] ?? ''));
t('fallback tax rate is used when no destination rule matches', abs((float)($fallbackTotals['tax'] ?? 0.0) - 36.00) < 0.001, json_encode($fallbackTotals));
t('tax inclusive mode back-calculates included tax', abs((float)($inclusiveTotals['tax'] ?? 0.0) - 12.00) < 0.001 && abs((float)($inclusiveTotals['total'] ?? 0.0) - 112.00) < 0.001, json_encode($inclusiveTotals));
t('product edit template exposes tax class selector', str_contains($productEditTemplate, 'name="tax_class"'));
t('settings template exposes destination tax rules', str_contains($settingsTemplate, 'name="tax_standard_rules"') && str_contains($settingsTemplate, 'name="tax_reduced_rules"'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceTaxFixtures($cleanupProductIds, is_array($originalSettings) ? $originalSettings : []);

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