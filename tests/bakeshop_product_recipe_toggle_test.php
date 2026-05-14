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

function btRecipeToggle(string $label, bool $ok, string $detail = ''): void
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

function renderBakeshopRecipeToggleSupervisor(array $user): string
{
    $previousGet = $_GET;
    $previousRequestUri = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop';
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/admin/bakeshop';
    app()->setUser($user);
    ob_start();
    try {
        bakeshopPageSupervisor();
        return (string)ob_get_clean();
    } finally {
        $_GET = $previousGet;
        $_SERVER['REQUEST_URI'] = $previousRequestUri;
    }
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP PRODUCT RECIPE TOGGLE TEST ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$originalSettings = getModuleSettings('bakeshop');
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$productId = 0;
$ingredientId = 0;

try {
    saveModuleSettings('bakeshop', array_merge(is_array($originalSettings) ? $originalSettings : [], [
        'product_recipe_status' => 'active',
        'production_recipe_mode' => 'required',
    ]));

    $kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
    btRecipeToggle('seeded kg unit exists', $kgUnitId > 0);

    $product = bakeshopCatalogSaveProduct([
        'name' => 'Toggle Product ' . $suffix,
        'sku' => 'TGL-PRD-' . $suffix,
        'category' => 'Bread',
        'default_yield_qty' => 12,
        'default_yield_unit_id' => $kgUnitId,
    ]);
    $productId = (int)($product['id'] ?? 0);
    btRecipeToggle('product created for toggle test', $productId > 0, json_encode($product, JSON_UNESCAPED_SLASHES));

    $ingredient = bakeshopCatalogSaveIngredient([
        'name' => 'Toggle Ingredient ' . $suffix,
        'sku' => 'TGL-ING-' . $suffix,
        'default_unit_id' => $kgUnitId,
    ]);
    $ingredientId = (int)($ingredient['id'] ?? 0);
    btRecipeToggle('ingredient created for toggle test', $ingredientId > 0, json_encode($ingredient, JSON_UNESCAPED_SLASHES));

    $recipe = bakeshopCatalogSaveRecipe([
        'product_id' => $productId,
        'ingredient_id' => $ingredientId,
        'unit_id' => $kgUnitId,
        'qty' => 2,
        'notes' => 'Toggle baseline',
    ]);
    btRecipeToggle('recipe line can be created while recipes are active', (int)($recipe['id'] ?? 0) > 0, json_encode($recipe, JSON_UNESCAPED_SLASHES));

    saveModuleSettings('bakeshop', array_merge(is_array($originalSettings) ? $originalSettings : [], [
        'product_recipe_status' => 'inactive',
        'production_recipe_mode' => 'required',
    ]));

    btRecipeToggle('product recipe status helper reports inactive', bakeshopProductRecipeStatus() === 'inactive', bakeshopProductRecipeStatus());
    btRecipeToggle('product recipes enabled helper reports false', bakeshopProductRecipesEnabled() === false, bakeshopProductRecipeStatus());
    btRecipeToggle('production recipe mode helper falls back to optional when recipes are deactivated', bakeshopProductionRecipeMode() === 'optional', bakeshopProductionRecipeMode());
    btRecipeToggle('production guard no longer requires recipe when recipes are deactivated', bakeshopProductionRequiresRecipe() === false, json_encode([
        'product_recipe_status' => bakeshopProductRecipeStatus(),
        'production_recipe_mode' => bakeshopProductionRecipeMode(),
    ], JSON_UNESCAPED_SLASHES));
    btRecipeToggle('recipe list is hidden when recipes are deactivated', bakeshopCatalogListRecipes() === [], 'expected empty list while recipe rows remain stored');

    $countStmt = $db->prepare('SELECT COUNT(*) FROM bakeshop_product_recipe WHERE product_id = :product_id');
    $countStmt->execute([':product_id' => $productId]);
    btRecipeToggle('existing recipe rows remain stored while feature is off', (int)$countStmt->fetchColumn() === 1);

    $saveBlocked = false;
    try {
        bakeshopCatalogSaveRecipe([
            'product_id' => $productId,
            'ingredient_id' => $ingredientId,
            'unit_id' => $kgUnitId,
            'qty' => 3,
        ]);
    } catch (InvalidArgumentException $e) {
        $saveBlocked = str_contains($e->getMessage(), 'deactivated');
    }
    btRecipeToggle('recipe save is blocked while recipes are deactivated', $saveBlocked);

    $html = renderBakeshopRecipeToggleSupervisor([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ]);
    btRecipeToggle('supervisor renders deactivated recipe notice', str_contains($html, 'Product Recipes Are Deactivated'), $html);
    btRecipeToggle('supervisor hides recipe panel when recipes are deactivated', str_contains($html, 'id="recipe-panel" style="display:none;"'), $html);

    if ($productId > 0) {
        bakeshopCatalogDeleteProduct(['id' => $productId]);
        $productId = 0;
    }
    if ($ingredientId > 0) {
        bakeshopCatalogDeleteIngredient(['id' => $ingredientId]);
        $ingredientId = 0;
    }
} finally {
    if ($productId > 0) {
        try {
            bakeshopCatalogDeleteProduct(['id' => $productId]);
        } catch (Throwable $e) {
        }
    }
    if ($ingredientId > 0) {
        try {
            bakeshopCatalogDeleteIngredient(['id' => $ingredientId]);
        } catch (Throwable $e) {
        }
    }

    saveModuleSettings('bakeshop', is_array($originalSettings) ? $originalSettings : []);
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btRecipeToggle('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btRecipeToggle('no error.log errors', $errorLog === '', $errorLog);

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