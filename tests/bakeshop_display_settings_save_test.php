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
$originalDefaultDrCoverageDays = $originalSettings['default_dr_coverage_days'] ?? null;
$originalProductRecipeStatus = $originalSettings['product_recipe_status'] ?? null;
$originalProductionRecipeMode = $originalSettings['production_recipe_mode'] ?? null;
$originalPrintTemplate = $originalSettings['print_template'] ?? null;

try {
    $saved = bakeshopSaveDisplaySettings([
        'store_name' => 'Juliana Bread Co.',
        'store_description' => 'Wholesale baking, delivery planning, and branch stock reporting.',
        'store_logo_url' => '/uploads/bakeshop/juliana-logo.png',
        'usage_decimal_places' => '3',
        'default_dr_coverage_days' => '5',
        'product_recipe_status' => 'active',
        'production_recipe_mode' => 'required',
        'print_template' => 'standard',
    ]);

    btDisplay('store name persists normalized value', ($saved['store_name'] ?? '') === 'Juliana Bread Co.', json_encode($saved, JSON_UNESCAPED_SLASHES));
    btDisplay('store description persists normalized value', ($saved['store_description'] ?? '') === 'Wholesale baking, delivery planning, and branch stock reporting.', json_encode($saved, JSON_UNESCAPED_SLASHES));
    btDisplay('store logo url persists value', ($saved['store_logo_url'] ?? '') === '/uploads/bakeshop/juliana-logo.png', json_encode($saved, JSON_UNESCAPED_SLASHES));
    btDisplay('usage decimal places are normalized to int', ($saved['usage_decimal_places'] ?? null) === 3, json_encode($saved, JSON_UNESCAPED_SLASHES));
    btDisplay('default dr coverage days are normalized to int', ($saved['default_dr_coverage_days'] ?? null) === 5, json_encode($saved, JSON_UNESCAPED_SLASHES));
    btDisplay('product recipe status persists normalized value', ($saved['product_recipe_status'] ?? '') === 'active', json_encode($saved, JSON_UNESCAPED_SLASHES));
    btDisplay('production recipe mode persists normalized value', ($saved['production_recipe_mode'] ?? '') === 'required', json_encode($saved, JSON_UNESCAPED_SLASHES));
    btDisplay('print template persists standard option', ($saved['print_template'] ?? '') === 'standard', json_encode($saved, JSON_UNESCAPED_SLASHES));

    $stored = getModuleSettings('bakeshop');
    btDisplay('stored store name persists', ($stored['store_name'] ?? '') === 'Juliana Bread Co.', json_encode($stored, JSON_UNESCAPED_SLASHES));
    btDisplay('stored store description persists', ($stored['store_description'] ?? '') === 'Wholesale baking, delivery planning, and branch stock reporting.', json_encode($stored, JSON_UNESCAPED_SLASHES));
    btDisplay('stored store logo url persists', ($stored['store_logo_url'] ?? '') === '/uploads/bakeshop/juliana-logo.png', json_encode($stored, JSON_UNESCAPED_SLASHES));
    btDisplay('stored usage decimal places persist as string', ($stored['usage_decimal_places'] ?? '') === '3', json_encode($stored, JSON_UNESCAPED_SLASHES));
    btDisplay('stored default dr coverage days persist as string', ($stored['default_dr_coverage_days'] ?? '') === '5', json_encode($stored, JSON_UNESCAPED_SLASHES));
    btDisplay('stored product recipe status persists', ($stored['product_recipe_status'] ?? '') === 'active', json_encode($stored, JSON_UNESCAPED_SLASHES));
    btDisplay('stored production recipe mode persists', ($stored['production_recipe_mode'] ?? '') === 'required', json_encode($stored, JSON_UNESCAPED_SLASHES));
    btDisplay('stored print template persists', ($stored['print_template'] ?? '') === 'standard', json_encode($stored, JSON_UNESCAPED_SLASHES));

    $brand = bakeshopBrandSettings();
    $places = bakeshopUsageDecimalPlaces();
    $defaultDrCoverageDays = bakeshopDefaultDrCoverageDays();
    $productRecipeStatus = bakeshopProductRecipeStatus();
    $productRecipesEnabled = bakeshopProductRecipesEnabled();
    $productionRecipeMode = bakeshopProductionRecipeMode();
    $productionRequiresRecipe = bakeshopProductionRequiresRecipe();
    $template = bakeshopPrintTemplate();
    btDisplay('brand helper returns saved store name', ($brand['store_name'] ?? '') === 'Juliana Bread Co.', json_encode($brand, JSON_UNESCAPED_SLASHES));
    btDisplay('brand helper returns saved description', ($brand['store_description'] ?? '') === 'Wholesale baking, delivery planning, and branch stock reporting.', json_encode($brand, JSON_UNESCAPED_SLASHES));
    btDisplay('brand helper returns saved logo url', ($brand['store_logo_url'] ?? '') === '/uploads/bakeshop/juliana-logo.png', json_encode($brand, JSON_UNESCAPED_SLASHES));
    btDisplay('usage decimal helper returns saved value', $places === 3, (string)$places);
    btDisplay('default dr coverage helper returns saved value', $defaultDrCoverageDays === 5, (string)$defaultDrCoverageDays);
    btDisplay('product recipe status helper returns saved value', $productRecipeStatus === 'active', $productRecipeStatus);
    btDisplay('product recipes enabled helper returns true for active status', $productRecipesEnabled === true, $productRecipeStatus);
    btDisplay('production recipe mode helper returns saved value', $productionRecipeMode === 'required', $productionRecipeMode);
    btDisplay('production recipe required helper returns true for required mode', $productionRequiresRecipe === true, $productionRecipeMode);
    btDisplay('print template helper returns saved value', $template === 'standard', $template);

    $disabled = bakeshopSaveDisplaySettings([
        'store_name' => 'Juliana Bread Co.',
        'store_description' => 'Wholesale baking, delivery planning, and branch stock reporting.',
        'store_logo_url' => '/uploads/bakeshop/juliana-logo.png',
        'usage_decimal_places' => '3',
        'default_dr_coverage_days' => '5',
        'product_recipe_status' => 'inactive',
        'production_recipe_mode' => 'required',
        'print_template' => 'standard',
    ]);
    btDisplay('product recipe status can be deactivated', ($disabled['product_recipe_status'] ?? '') === 'inactive', json_encode($disabled, JSON_UNESCAPED_SLASHES));
    btDisplay('production recipe mode is coerced to optional when recipes are deactivated', ($disabled['production_recipe_mode'] ?? '') === 'optional', json_encode($disabled, JSON_UNESCAPED_SLASHES));
    $storedAfterDisabled = getModuleSettings('bakeshop');
    btDisplay('stored production recipe mode is optional when recipes are deactivated', ($storedAfterDisabled['production_recipe_mode'] ?? '') === 'optional', json_encode($storedAfterDisabled, JSON_UNESCAPED_SLASHES));
    btDisplay('production required helper falls back to false when recipes are deactivated', bakeshopProductionRequiresRecipe() === false, json_encode([
        'product_recipe_status' => bakeshopProductRecipeStatus(),
        'production_recipe_mode' => bakeshopProductionRecipeMode(),
    ], JSON_UNESCAPED_SLASHES));

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
        'default_dr_coverage_days' => $originalDefaultDrCoverageDays,
        'product_recipe_status' => $originalProductRecipeStatus,
        'production_recipe_mode' => $originalProductionRecipeMode,
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