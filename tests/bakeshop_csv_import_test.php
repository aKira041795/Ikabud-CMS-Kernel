<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function bt(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  \u{2713} {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  \u{2717} {$label}" . ($detail !== '' ? " \u{2014} {$detail}" : '') . "\n";
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP CSV IMPORT TEST ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$kgUnitId = 0;
$pcUnitId = 0;

try {
    $kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
    $pcUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'pc' LIMIT 1")->fetchColumn() ?: 0);
    bt('seeded kg unit exists', $kgUnitId > 0);
    bt('seeded pc unit exists', $pcUnitId > 0);

    // -- 1. PRODUCT IMPORT -------------------------------------------------

    $productCsv = "sku,name,category,default_yield_qty,default_yield_unit_code\n";
    $productCsv .= "IMP-PRD-{$suffix}-A,Import Product Alpha,Bread,10,kg\n";
    $productCsv .= "IMP-PRD-{$suffix}-B,Import Product Beta,Pastry,24,pc\n";
    $productCsv .= "IMP-PRD-{$suffix}-C,Import Product Gamma,,5,kg\n";

    $prodResult = bakeshopImportProductsFromCsv($productCsv);
    bt('product import creates 3 products', $prodResult['created'] === 3, "got {$prodResult['created']}");
    bt('product import has 0 errors', $prodResult['error_count'] === 0, "got {$prodResult['error_count']}");

    // Verify products exist
    $productA = bakeshopCatalogFetchOne(
        "SELECT id, name, sku, default_yield_qty FROM bakeshop_products WHERE sku = :sku LIMIT 1",
        [':sku' => "IMP-PRD-{$suffix}-A"]
    );
    bt('product A persisted with correct yield', $productA !== null && (float)($productA['default_yield_qty'] ?? 0) === 10.0);

    $productC = bakeshopCatalogFetchOne(
        "SELECT id, name, category FROM bakeshop_products WHERE sku = :sku LIMIT 1",
        [':sku' => "IMP-PRD-{$suffix}-C"]
    );
    bt('product C persisted with blank category', $productC !== null && ($productC['category'] ?? '') === '');

    // -- 2. PRODUCT RE-IMPORT (update) -------------------------------------

    $updateCsv = "sku,name,category,default_yield_qty,default_yield_unit_code\n";
    $updateCsv .= "IMP-PRD-{$suffix}-A,Import Product Alpha Updated,Bread,15,kg\n";

    $updateResult = bakeshopImportProductsFromCsv($updateCsv);
    bt('product re-import updates 1', $updateResult['updated'] === 1, "got {$updateResult['updated']}");
    bt('product re-import has 0 errors', $updateResult['error_count'] === 0);

    $updatedA = bakeshopCatalogFetchOne(
        "SELECT name, default_yield_qty FROM bakeshop_products WHERE sku = :sku LIMIT 1",
        [':sku' => "IMP-PRD-{$suffix}-A"]
    );
    bt('product A name updated', ($updatedA['name'] ?? '') === 'Import Product Alpha Updated');
    bt('product A yield updated to 15', (float)($updatedA['default_yield_qty'] ?? 0) === 15.0);

    // -- 3. RECIPE IMPORT --------------------------------------------------

    $ingredient = bakeshopCatalogSaveIngredient([
        'name' => 'Import Flour ' . $suffix,
        'sku' => "IMP-FLR-{$suffix}",
        'default_unit_id' => $kgUnitId,
    ]);
    $flourId = (int)($ingredient['id'] ?? 0);
    bt('ingredient created for recipe import', $flourId > 0);

    $ingredient2 = bakeshopCatalogSaveIngredient([
        'name' => 'Import Sugar ' . $suffix,
        'sku' => "IMP-SGR-{$suffix}",
        'default_unit_id' => $kgUnitId,
    ]);
    $sugarId = (int)($ingredient2['id'] ?? 0);
    bt('second ingredient created', $sugarId > 0);

    $recipeCsv = "product_sku,ingredient_sku,qty,unit_code,notes\n";
    $recipeCsv .= "IMP-PRD-{$suffix}-A,IMP-FLR-{$suffix},2.5,kg,Base flour\n";
    $recipeCsv .= "IMP-PRD-{$suffix}-A,IMP-SGR-{$suffix},0.3,kg,Sweetener\n";

    $recipeResult = bakeshopImportRecipesFromCsv($recipeCsv);
    bt('recipe import creates 2 lines', $recipeResult['created'] === 2, "got {$recipeResult['created']}");
    bt('recipe import has 0 errors', $recipeResult['error_count'] === 0, "got {$recipeResult['error_count']}");

    // Verify recipe lines
    $recipeLines = bakeshopCatalogFetchAll(
        "SELECT r.qty, i.name FROM bakeshop_product_recipe r
         INNER JOIN bakeshop_ingredients i ON i.id = r.ingredient_id
         WHERE r.product_id = :pid ORDER BY r.id ASC",
        [':pid' => $productA['id']]
    );
    bt('product has 2 recipe lines', count($recipeLines) === 2, 'got ' . count($recipeLines));
    bt('flour recipe line qty is 2.5', count($recipeLines) >= 1 && (float)($recipeLines[0]['qty'] ?? 0) === 2.5);

    // -- 4. RECIPE IMPORT — resolve by name ---------------------------------

    $recipeCsv2 = "product_name,ingredient_name,qty,unit_code\n";
    $recipeCsv2 .= "Import Product Alpha Updated,Import Flour {$suffix},1.0,kg\n";
    $recipeResult2 = bakeshopImportRecipesFromCsv($recipeCsv2);
    bt('recipe import by name handles duplicate (upsert)', $recipeResult2['error_count'] === 0, 'errors: ' . $recipeResult2['error_count']);

    // -- 5. RECIPE IMPORT — error handling ---------------------------------

    $badCsv = "product_sku,ingredient_sku,qty,unit_code\n";
    $badCsv .= "NONEXISTENT-SKU,IMP-FLR-{$suffix},1,kg\n";
    $badResult = bakeshopImportRecipesFromCsv($badCsv);
    bt('recipe import catches missing product error', $badResult['error_count'] === 1);

    // -- 6. PRODUCTION IMPORT -----------------------------------------------

    $branch = bakeshopDeliveriesCreateBranch([
        'code' => 'IMP' . $suffix,
        'name' => 'Import Test Branch ' . $suffix,
    ]);
    $branchId = (int)($branch['id'] ?? 0);
    bt('branch created for production import', $branchId > 0);

    $prodCsv = "branch_code,product_sku,qty_produced,produced_at,notes\n";
    $prodCsv .= "IMP{$suffix},IMP-PRD-{$suffix}-A,30,2026-06-12 08:00:00,Imported batch\n";
    $prodCsv .= "IMP{$suffix},IMP-PRD-{$suffix}-B,48,2026-06-12 09:00:00,\n";

    $prodImportResult = bakeshopImportProductionFromCsv($prodCsv);
    bt('production import creates 2 runs', $prodImportResult['created'] === 2, "got {$prodImportResult['created']}");
    bt('production import has 0 errors', $prodImportResult['error_count'] === 0, "got {$prodImportResult['error_count']}");

    // Verify production runs exist
    $runs = bakeshopCatalogFetchAll(
        "SELECT pr.qty_produced, p.name AS product_name
         FROM bakeshop_production_runs pr
         INNER JOIN bakeshop_products p ON p.id = pr.product_id
         WHERE pr.branch_id = :bid ORDER BY pr.id ASC",
        [':bid' => $branchId]
    );
    bt('2 production runs exist', count($runs) === 2, 'got ' . count($runs));
    bt('first run qty is 30', count($runs) >= 1 && (float)($runs[0]['qty_produced'] ?? 0) === 30.0);

    // Verify production items were snapshot
    $prodItems = bakeshopCatalogFetchAll(
        "SELECT pi.qty_used, i.name AS ingredient_name
         FROM bakeshop_production_items pi
         INNER JOIN bakeshop_ingredients i ON i.id = pi.ingredient_id
         WHERE pi.run_id IN (SELECT id FROM bakeshop_production_runs WHERE branch_id = :bid)
         ORDER BY pi.id ASC",
        [':bid' => $branchId]
    );
    bt('production snapshot created ingredient items', count($prodItems) >= 2, 'got ' . count($prodItems));

    // -- 7. PRODUCTION IMPORT — resolve by name -----------------------------

    $prodCsv2 = "branch_code,product_name,qty_produced,produced_at\n";
    $prodCsv2 .= "IMP{$suffix},Import Product Alpha Updated,5,2026-06-12 10:00:00\n";
    $prodImportResult2 = bakeshopImportProductionFromCsv($prodCsv2);
    bt('production import by product name creates 1 run', $prodImportResult2['created'] === 1);

    // -- 8. EMPTY CSV rejection --------------------------------------------

    try {
        bakeshopImportProductsFromCsv("header\n");
        bt('empty CSV (no data rows) throws', false, 'should have thrown');
    } catch (InvalidArgumentException $e) {
        bt('empty CSV (no data rows) throws', str_contains($e->getMessage(), 'at least one data row'));
    }

    // -- 9. HEADER-ONLY CSV rejection --------------------------------------

    try {
        bakeshopImportProductsFromCsv("sku,name\n");
        bt('header-only CSV throws', false, 'should have thrown');
    } catch (InvalidArgumentException $e) {
        bt('header-only CSV throws', str_contains($e->getMessage(), 'at least one data row'));
    }

    // -- 10. BLANK CSV rejection -------------------------------------------

    try {
        bakeshopImportProductsFromCsv('');
        bt('blank CSV throws', false, 'should have thrown');
    } catch (InvalidArgumentException $e) {
        bt('blank CSV throws', str_contains($e->getMessage(), 'empty'));
    }

    // -- LOGS ---------------------------------------------------------------

    bt('no app.log errors', trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log')) === '');
    bt('no error.log errors', trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log')) === '');

} catch (Throwable $e) {
    bt("unexpected exception: {$e->getMessage()}", false, $e->getTraceAsString());
}

echo "\n" . str_repeat("\u{2500}", 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";

if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    \u{2022} {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);
