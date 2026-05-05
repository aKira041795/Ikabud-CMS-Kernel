<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop/print';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function btPrint(string $label, bool $ok, string $detail = ''): void
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

echo "\n=== BAKESHOP PRINT SUMMARY TEST ===\n\n";

$originalSettings = getModuleSettings('bakeshop');
$originalStoreName = $originalSettings['store_name'] ?? null;
$originalStoreDescription = $originalSettings['store_description'] ?? null;
$originalStoreLogoUrl = $originalSettings['store_logo_url'] ?? null;
$originalUsageDecimalPlaces = $originalSettings['usage_decimal_places'] ?? null;
$originalPrintTemplate = $originalSettings['print_template'] ?? null;

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$branchId = 0;
$productId = 0;
$ingredientId = 0;
$secondIngredientId = 0;
$thirdIngredientId = 0;
$deliveryIds = [];
$runId = 0;

try {
    saveModuleSettings('bakeshop', [
        'store_name' => 'North Oven Bakery',
        'store_description' => 'Printable branch balances for the wholesale bakery team.',
        'store_logo_url' => '/uploads/bakeshop/north-oven.png',
        'usage_decimal_places' => '2',
        'print_template' => 'standard',
    ]);

    $kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
    btPrint('seeded kg unit exists', $kgUnitId > 0);

    $branch = bakeshopDeliveriesCreateBranch([
        'code' => 'PR' . substr($suffix, 0, 6),
        'name' => 'Print Branch ' . $suffix,
        'address' => 'Print Street',
    ]);
    $branchId = (int)($branch['id'] ?? 0);
    btPrint('branch created', $branchId > 0, json_encode($branch, JSON_UNESCAPED_SLASHES));

    $product = bakeshopCatalogSaveProduct([
        'name' => 'Print Product ' . $suffix,
        'sku' => 'PRT-PRD-' . $suffix,
        'category' => 'Bread',
        'default_yield_qty' => 10,
        'default_yield_unit_id' => $kgUnitId,
    ]);
    $productId = (int)($product['id'] ?? 0);
    btPrint('product created', $productId > 0, json_encode($product, JSON_UNESCAPED_SLASHES));

    $ingredient = bakeshopCatalogSaveIngredient([
        'name' => 'Print Flour ' . $suffix,
        'sku' => 'PRT-ING-' . $suffix,
        'default_unit_id' => $kgUnitId,
    ]);
    $ingredientId = (int)($ingredient['id'] ?? 0);
    btPrint('ingredient created', $ingredientId > 0, json_encode($ingredient, JSON_UNESCAPED_SLASHES));

    $secondIngredient = bakeshopCatalogSaveIngredient([
        'name' => 'Print Sugar ' . $suffix,
        'sku' => 'PRT-ING2-' . $suffix,
        'default_unit_id' => $kgUnitId,
    ]);
    $secondIngredientId = (int)($secondIngredient['id'] ?? 0);
    btPrint('second ingredient created', $secondIngredientId > 0, json_encode($secondIngredient, JSON_UNESCAPED_SLASHES));

    $thirdIngredient = bakeshopCatalogSaveIngredient([
        'name' => 'Print Yeast ' . $suffix,
        'sku' => 'PRT-ING3-' . $suffix,
        'default_unit_id' => $kgUnitId,
    ]);
    $thirdIngredientId = (int)($thirdIngredient['id'] ?? 0);
    btPrint('third ingredient created', $thirdIngredientId > 0, json_encode($thirdIngredient, JSON_UNESCAPED_SLASHES));

    $recipe = bakeshopCatalogSaveRecipe([
        'product_id' => $productId,
        'ingredient_id' => $ingredientId,
        'unit_id' => $kgUnitId,
        'qty' => 1,
        'notes' => 'print test line',
    ]);
    btPrint('recipe created', (int)($recipe['id'] ?? 0) > 0, json_encode($recipe, JSON_UNESCAPED_SLASHES));

    $openingDelivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => '2026-04-25 08:00:00',
        'reference' => 'PRINT-OPEN-' . $suffix,
        'received_by' => 'Supervisor',
        'source_type' => 'commissary',
        'items' => [
            [
                'ingredient_id' => $ingredientId,
                'unit_id' => $kgUnitId,
                'qty' => 5,
                'unit_cost' => 10,
            ],
        ],
    ]);
    $deliveryIds[] = (int)($openingDelivery['id'] ?? 0);
    btPrint('opening delivery created', end($deliveryIds) > 0, json_encode($openingDelivery, JSON_UNESCAPED_SLASHES));

    $delivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => '2026-04-26 08:00:00',
        'reference' => 'PRINT-DEL-' . $suffix,
        'source_type' => 'other',
        'source_name' => 'Farmer Coop',
        'received_by' => 'Supervisor',
        'items' => [
            [
                'ingredient_id' => $ingredientId,
                'unit_id' => $kgUnitId,
                'qty' => 4,
                'unit_cost' => 10,
            ],
        ],
    ]);
    $deliveryIds[] = (int)($delivery['id'] ?? 0);
    btPrint('delivery created', end($deliveryIds) > 0, json_encode($delivery, JSON_UNESCAPED_SLASHES));

    $mixedCommissaryDelivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => '2026-04-26 09:00:00',
        'reference' => 'PRINT-CMSY-' . $suffix,
        'source_type' => 'commissary',
        'received_by' => 'Supervisor',
        'items' => [
            [
                'ingredient_id' => $ingredientId,
                'unit_id' => $kgUnitId,
                'qty' => 3,
                'unit_cost' => 10,
            ],
        ],
    ]);
    $deliveryIds[] = (int)($mixedCommissaryDelivery['id'] ?? 0);
    btPrint('mixed-source commissary delivery created', end($deliveryIds) > 0, json_encode($mixedCommissaryDelivery, JSON_UNESCAPED_SLASHES));

    $commissaryOnlyDelivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => '2026-04-26 09:30:00',
        'reference' => 'PRINT-CMS2-' . $suffix,
        'source_type' => 'commissary',
        'received_by' => 'Supervisor',
        'items' => [
            [
                'ingredient_id' => $secondIngredientId,
                'unit_id' => $kgUnitId,
                'qty' => 6,
                'unit_cost' => 9,
            ],
        ],
    ]);
    $deliveryIds[] = (int)($commissaryOnlyDelivery['id'] ?? 0);
    btPrint('commissary-only delivery created', end($deliveryIds) > 0, json_encode($commissaryOnlyDelivery, JSON_UNESCAPED_SLASHES));

    $otherSupplierDelivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => '2026-04-26 09:45:00',
        'reference' => 'PRINT-OTH2-' . $suffix,
        'source_type' => 'other',
        'source_name' => 'Farmer Coop',
        'received_by' => 'Supervisor',
        'items' => [
            [
                'ingredient_id' => $thirdIngredientId,
                'unit_id' => $kgUnitId,
                'qty' => 2,
                'unit_cost' => 11,
            ],
        ],
    ]);
    $deliveryIds[] = (int)($otherSupplierDelivery['id'] ?? 0);
    btPrint('other-supplier delivery created', end($deliveryIds) > 0, json_encode($otherSupplierDelivery, JSON_UNESCAPED_SLASHES));

    $run = bakeshopProductionCreate([
        'branch_id' => $branchId,
        'product_id' => $productId,
        'produced_at' => '2026-04-26 10:00:00',
        'qty_produced' => 20,
        'produced_by' => 'Baker',
    ]);
    $runId = (int)($run['id'] ?? 0);
    btPrint('production run created', $runId > 0, json_encode($run, JSON_UNESCAPED_SLASHES));

    $filters = bakeshopUsageNormalizeFilters([
        'branch_id' => $branchId,
        'from_date' => '2026-04-26',
        'to_date' => '2026-04-26',
        'supplier' => 'other:Farmer Coop',
        'ingredient_ids' => [$ingredientId],
    ]);
    $branches = bakeshopUsageBranchOptions();
    $branchLabel = (string)($branch['code'] ?? '') . ' - ' . (string)($branch['name'] ?? '');
    $allRows = bakeshopPrintSummaryRows([
        'branch_id' => $branchId,
        'from_date' => '2026-04-26',
        'to_date' => '2026-04-26',
    ]);
    btPrint('unfiltered print summary includes all scoped ingredients', count($allRows) === 3, json_encode(array_column($allRows, 'ingredient_name'), JSON_UNESCAPED_SLASHES));

    $supplierRows = bakeshopPrintSummaryRows([
        'branch_id' => $branchId,
        'from_date' => '2026-04-26',
        'to_date' => '2026-04-26',
        'supplier' => 'other:Farmer Coop',
    ]);
    btPrint('supplier filter excludes commissary-only ingredients', count($supplierRows) === 2 && !in_array((string)($secondIngredient['name'] ?? ''), array_column($supplierRows, 'ingredient_name'), true), json_encode(array_column($supplierRows, 'ingredient_name'), JSON_UNESCAPED_SLASHES));

    $genericOtherRows = bakeshopPrintSummaryRows([
        'branch_id' => $branchId,
        'from_date' => '2026-04-26',
        'to_date' => '2026-04-26',
        'supplier' => 'other',
    ]);
    btPrint('generic other supplier filter includes all non-commissary ingredients', count($genericOtherRows) === 2 && !in_array((string)($secondIngredient['name'] ?? ''), array_column($genericOtherRows, 'ingredient_name'), true), json_encode(array_column($genericOtherRows, 'ingredient_name'), JSON_UNESCAPED_SLASHES));

    $filteredRows = bakeshopPrintSummaryRows($filters);
    btPrint('ingredient multi-select narrows supplier-filtered rows', count($filteredRows) === 1 && (int)($filteredRows[0]['ingredient_id'] ?? 0) === $ingredientId, json_encode($filteredRows, JSON_UNESCAPED_SLASHES));

    $summaryGroups = bakeshopPrintSummaryBranchGroups($filters);
    $factualSummary = bakeshopUsageFactualSummary($filters);
    $supplierOptions = bakeshopUsageSupplierOptions($filters);
    $ingredientOptions = bakeshopPrintSummaryIngredientOptions($filters);
    $html = bakeshopRender('pages/print-summary.disyl', [
        'page_title' => 'Printable Bakeshop Summary',
        'brand_settings' => bakeshopBrandSettings(),
        'filters' => $filters,
        'branch_filter_options' => [[
            'value' => (string)$branchId,
            'label' => $branchLabel,
            'selected' => true,
        ]],
        'supplier_options' => $supplierOptions,
        'ingredient_options' => $ingredientOptions,
        'branch_scope_label' => bakeshopPrintSummaryScopeLabel($filters, $branches, $summaryGroups),
        'supplier_scope_label' => 'Other: Farmer Coop',
        'ingredient_scope_label' => (string)($ingredient['name'] ?? ''),
        'branches' => $branches,
        'summary_groups' => $summaryGroups,
        'factual_summary' => $factualSummary,
        'display_from_date' => bakeshopPrintSummaryFormatDate($filters['from_date'] ?? null),
        'display_to_date' => bakeshopPrintSummaryFormatDate($filters['to_date'] ?? null),
        'usage_decimal_places' => bakeshopUsageDecimalPlaces(),
        'print_template_label' => bakeshopPrintSummaryTemplateLabel(bakeshopPrintTemplate()),
        'output_summary_label' => 'Rounded to ' . bakeshopUsageDecimalPlaces() . ' decimal place' . (bakeshopUsageDecimalPlaces() === 1 ? '' : 's'),
    ]);

    btPrint('print summary renders page title', str_contains($html, 'Printable Bakeshop Summary'));
    btPrint('print summary renders configured store branding', str_contains($html, 'North Oven Bakery') && str_contains($html, 'Printable branch balances for the wholesale bakery team.'), $html);
    btPrint('print summary renders configured store logo', str_contains($html, '/uploads/bakeshop/north-oven.png'), $html);
    btPrint('print summary renders branch label', str_contains($html, $branchLabel), $html);
    btPrint('print summary renders ingredient row', str_contains($html, (string)($ingredient['name'] ?? '')), $html);
    btPrint('print summary renders filter controls', str_contains($html, 'All branches') && str_contains($html, 'name="supplier"') && str_contains($html, 'name="ingredient_ids[]"') && str_contains($html, 'Apply Filters'), $html);
    btPrint('print summary renders new balance headings', str_contains($html, 'Beginning Balance') && str_contains($html, 'Delivery Source') && str_contains($html, 'Remaining Balance'), $html);
    btPrint('print summary renders beginning balance using configured decimals', str_contains($html, '5.00'), $html);
    btPrint('print summary renders period delivery using configured decimals', str_contains($html, '7.00'), $html);
    btPrint('print summary renders usage using configured decimals', str_contains($html, '2.00'), $html);
    btPrint('print summary renders remaining balance using configured decimals', str_contains($html, '10.00'), $html);
    btPrint('print summary renders supplier label', str_contains($html, 'Other: Farmer Coop') && !str_contains($html, 'Commissary, Other'), $html);
    btPrint('print summary renders selected filter scopes', str_contains($html, '<strong>Supplier</strong>') && str_contains($html, '<p>Other: Farmer Coop</p>') && str_contains($html, '<strong>Ingredients</strong>'), $html);
    btPrint('print summary renders configured output meta', str_contains($html, 'Rounded to 2 decimal places') && str_contains($html, 'Standard template'), $html);
    btPrint('print summary renders factual summary cards', str_contains($html, 'Tracked Ingredients') && str_contains($html, 'Delivery Lines') && str_contains($html, 'Production Runs') && str_contains($html, 'On Hand At Scope End'), $html);
    btPrint('print summary explains inventory as of the selected end date', str_contains($html, 'inventory values reflect stock as of the selected end date'), $html);
} finally {
    if ($runId > 0) {
        $db->prepare('DELETE FROM bakeshop_production_items WHERE run_id = ?')->execute([$runId]);
        $db->prepare('DELETE FROM bakeshop_production_runs WHERE id = ?')->execute([$runId]);
    }

    foreach (array_reverse($deliveryIds) as $deliveryId) {
        if ($deliveryId <= 0) {
            continue;
        }

        $db->prepare('DELETE FROM bakeshop_delivery_items WHERE delivery_id = ?')->execute([$deliveryId]);
        $db->prepare('DELETE FROM bakeshop_deliveries WHERE id = ?')->execute([$deliveryId]);
    }

    if ($productId > 0) {
        $db->prepare('DELETE FROM bakeshop_product_recipe WHERE product_id = ?')->execute([$productId]);
        $db->prepare('DELETE FROM bakeshop_products WHERE id = ?')->execute([$productId]);
    }

    if ($ingredientId > 0) {
        $db->prepare('DELETE FROM bakeshop_ingredients WHERE id = ?')->execute([$ingredientId]);
    }

    if ($secondIngredientId > 0) {
        $db->prepare('DELETE FROM bakeshop_ingredients WHERE id = ?')->execute([$secondIngredientId]);
    }

    if ($thirdIngredientId > 0) {
        $db->prepare('DELETE FROM bakeshop_ingredients WHERE id = ?')->execute([$thirdIngredientId]);
    }

    if ($branchId > 0) {
        $db->prepare('DELETE FROM bakeshop_branches WHERE id = ?')->execute([$branchId]);
    }

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
btPrint('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btPrint('no error.log errors', $errorLog === '', $errorLog);

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