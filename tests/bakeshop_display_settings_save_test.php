<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop/settings';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function btDisplay(string $label, bool $ok, string $detail = ''): void
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

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP DISPLAY SETTINGS SAVE TEST ===\n\n";

$originalSettings = getModuleSettings('bakeshop');
$originalStoreName = $originalSettings['store_name'] ?? null;
$originalStoreDescription = $originalSettings['store_description'] ?? null;
$originalStoreLogoUrl = $originalSettings['store_logo_url'] ?? null;
$originalUsageDecimalPlaces = $originalSettings['usage_decimal_places'] ?? null;
$originalPrintTemplate = $originalSettings['print_template'] ?? null;

try {
    $saved = bakeshopSaveDisplaySettings([
        'store_name' => 'Juliana Bread Co.',
        'store_description' => 'Wholesale baking, delivery planning, and branch stock reporting.',
        'store_logo_url' => '/uploads/bakeshop/juliana-logo.png',
        'usage_decimal_places' => '3',
        'print_template' => 'standard',
    ]);

    btDisplay('store name persists normalized value', ($saved['store_name'] ?? '') === 'Juliana Bread Co.', json_encode($saved, JSON_UNESCAPED_SLASHES));
    btDisplay('store description persists normalized value', ($saved['store_description'] ?? '') === 'Wholesale baking, delivery planning, and branch stock reporting.', json_encode($saved, JSON_UNESCAPED_SLASHES));
    btDisplay('store logo url persists value', ($saved['store_logo_url'] ?? '') === '/uploads/bakeshop/juliana-logo.png', json_encode($saved, JSON_UNESCAPED_SLASHES));
    btDisplay('usage decimal places are normalized to int', ($saved['usage_decimal_places'] ?? null) === 3, json_encode($saved, JSON_UNESCAPED_SLASHES));
    btDisplay('print template persists standard option', ($saved['print_template'] ?? '') === 'standard', json_encode($saved, JSON_UNESCAPED_SLASHES));

    $stored = getModuleSettings('bakeshop');
    btDisplay('stored store name persists', ($stored['store_name'] ?? '') === 'Juliana Bread Co.', json_encode($stored, JSON_UNESCAPED_SLASHES));
    btDisplay('stored store description persists', ($stored['store_description'] ?? '') === 'Wholesale baking, delivery planning, and branch stock reporting.', json_encode($stored, JSON_UNESCAPED_SLASHES));
    btDisplay('stored store logo url persists', ($stored['store_logo_url'] ?? '') === '/uploads/bakeshop/juliana-logo.png', json_encode($stored, JSON_UNESCAPED_SLASHES));
    btDisplay('stored usage decimal places persist as string', ($stored['usage_decimal_places'] ?? '') === '3', json_encode($stored, JSON_UNESCAPED_SLASHES));
    btDisplay('stored print template persists', ($stored['print_template'] ?? '') === 'standard', json_encode($stored, JSON_UNESCAPED_SLASHES));

    $brand = bakeshopBrandSettings();
    $places = bakeshopUsageDecimalPlaces();
    $template = bakeshopPrintTemplate();
    btDisplay('brand helper returns saved store name', ($brand['store_name'] ?? '') === 'Juliana Bread Co.', json_encode($brand, JSON_UNESCAPED_SLASHES));
    btDisplay('brand helper returns saved description', ($brand['store_description'] ?? '') === 'Wholesale baking, delivery planning, and branch stock reporting.', json_encode($brand, JSON_UNESCAPED_SLASHES));
    btDisplay('brand helper returns saved logo url', ($brand['store_logo_url'] ?? '') === '/uploads/bakeshop/juliana-logo.png', json_encode($brand, JSON_UNESCAPED_SLASHES));
    btDisplay('usage decimal helper returns saved value', $places === 3, (string)$places);
    btDisplay('print template helper returns saved value', $template === 'standard', $template);

    $totals = bakeshopUsageTotals([
        [
            'delivered_qty_base' => '1.2349',
            'consumed_qty_base' => '0.5',
            'variance_qty_base' => '0.7349',
        ],
    ]);
    btDisplay('usage totals apply configured decimal places', ($totals['delivered_qty_base'] ?? '') === '1.235', json_encode($totals, JSON_UNESCAPED_SLASHES));
} finally {
    saveModuleSettings('bakeshop', [
        'store_name' => $originalStoreName,
        'store_description' => $originalStoreDescription,
        'store_logo_url' => $originalStoreLogoUrl,
        'usage_decimal_places' => $originalUsageDecimalPlaces,
        'print_template' => $originalPrintTemplate,
    ]);
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btDisplay('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btDisplay('no error.log errors', $errorLog === '', $errorLog);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);