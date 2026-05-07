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

function btRecipe(string $label, bool $ok, string $detail = ''): void
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

echo "\n=== BAKESHOP RECIPE MUTATION TEST ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$productIds = [];
$ingredientIds = [];

try {
    $kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
    btRecipe('seeded kg unit exists', $kgUnitId > 0);

    $product = bakeshopCatalogSaveProduct([
        'name' => 'Recipe Product ' . $suffix,
        'sku' => 'RCP-PRD-' . $suffix,
        'category' => 'Bread',
        'default_yield_qty' => 10,
        'default_yield_unit_id' => $kgUnitId,
    ]);
    $productId = (int)($product['id'] ?? 0);
    $productIds[] = $productId;
    btRecipe('product created', $productId > 0, json_encode($product, JSON_UNESCAPED_SLASHES));

    $ingredient = bakeshopCatalogSaveIngredient([
        'name' => 'Recipe Ingredient ' . $suffix,
        'sku' => 'RCP-ING-' . $suffix,
        'default_unit_id' => $kgUnitId,
    ]);
    $ingredientId = (int)($ingredient['id'] ?? 0);
    $ingredientIds[] = $ingredientId;
    btRecipe('ingredient created', $ingredientId > 0, json_encode($ingredient, JSON_UNESCAPED_SLASHES));

    $recipe = bakeshopCatalogSaveRecipe([
        'product_id' => $productId,
        'ingredient_id' => $ingredientId,
        'unit_id' => $kgUnitId,
        'qty' => 1.5,
        'notes' => 'Initial line',
    ]);
    $recipeId = (int)($recipe['id'] ?? 0);
    btRecipe('recipe line created', $recipeId > 0, json_encode($recipe, JSON_UNESCAPED_SLASHES));

    $updatedRecipe = bakeshopCatalogSaveRecipe([
        'product_id' => $productId,
        'ingredient_id' => $ingredientId,
        'unit_id' => $kgUnitId,
        'qty' => 2.25,
        'notes' => 'Updated line',
    ]);
    btRecipe('recipe line updates same key in place', (int)($updatedRecipe['id'] ?? 0) === $recipeId, json_encode($updatedRecipe, JSON_UNESCAPED_SLASHES));
    btRecipe('recipe qty updated', abs((float)($updatedRecipe['qty'] ?? 0) - 2.25) < 0.0001, json_encode($updatedRecipe, JSON_UNESCAPED_SLASHES));

    $deletedRecipe = bakeshopCatalogDeleteRecipe(['id' => $recipeId]);
    btRecipe('recipe delete returns deleted row', (int)($deletedRecipe['id'] ?? 0) === $recipeId, json_encode($deletedRecipe, JSON_UNESCAPED_SLASHES));
    btRecipe('recipe list no longer contains deleted row', bakeshopCatalogFindRecipeById($recipeId) === null);

    $recreatedRecipe = bakeshopCatalogSaveRecipe([
        'product_id' => $productId,
        'ingredient_id' => $ingredientId,
        'unit_id' => $kgUnitId,
        'qty' => 3,
        'notes' => 'Cascade delete check',
    ]);
    btRecipe('recipe line recreated for delete check', (int)($recreatedRecipe['id'] ?? 0) > 0, json_encode($recreatedRecipe, JSON_UNESCAPED_SLASHES));

    $deletedProduct = bakeshopCatalogDeleteProduct(['id' => $productId]);
    btRecipe('product delete returns deleted row', (int)($deletedProduct['id'] ?? 0) === $productId, json_encode($deletedProduct, JSON_UNESCAPED_SLASHES));
    btRecipe('product delete removes recipe lines', bakeshopCatalogFindRecipeLine($productId, $ingredientId, $kgUnitId) === null);
    btRecipe('product delete removes product row', bakeshopCatalogFindProductById($productId) === null);
    $productIds = array_values(array_filter($productIds, static fn (int $id): bool => $id !== $productId));

    $deletedIngredient = bakeshopCatalogDeleteIngredient(['id' => $ingredientId]);
    btRecipe('ingredient delete returns deleted row', (int)($deletedIngredient['id'] ?? 0) === $ingredientId, json_encode($deletedIngredient, JSON_UNESCAPED_SLASHES));
    btRecipe('ingredient delete removes ingredient row', bakeshopCatalogFindIngredientById($ingredientId) === null);

    $ingredientIds = array_values(array_filter($ingredientIds, static fn (int $id): bool => $id !== $ingredientId));

    $batchProductA = bakeshopCatalogSaveProduct([
        'name' => 'Batch Product A ' . $suffix,
        'sku' => 'RCP-BPA-' . $suffix,
        'category' => 'Bread',
        'default_yield_qty' => 8,
        'default_yield_unit_id' => $kgUnitId,
    ]);
    $batchProductB = bakeshopCatalogSaveProduct([
        'name' => 'Batch Product B ' . $suffix,
        'sku' => 'RCP-BPB-' . $suffix,
        'category' => 'Bread',
        'default_yield_qty' => 9,
        'default_yield_unit_id' => $kgUnitId,
    ]);
    $batchProductIds = [
        (int)($batchProductA['id'] ?? 0),
        (int)($batchProductB['id'] ?? 0),
    ];
    $productIds = array_merge($productIds, $batchProductIds);
    btRecipe('batch delete products seeded', min($batchProductIds) > 0, json_encode($batchProductIds, JSON_UNESCAPED_SLASHES));

    $batchIngredientA = bakeshopCatalogSaveIngredient([
        'name' => 'Batch Ingredient A ' . $suffix,
        'sku' => 'RCP-BIA-' . $suffix,
        'default_unit_id' => $kgUnitId,
    ]);
    $batchIngredientB = bakeshopCatalogSaveIngredient([
        'name' => 'Batch Ingredient B ' . $suffix,
        'sku' => 'RCP-BIB-' . $suffix,
        'default_unit_id' => $kgUnitId,
    ]);
    $batchIngredientIds = [
        (int)($batchIngredientA['id'] ?? 0),
        (int)($batchIngredientB['id'] ?? 0),
    ];
    $ingredientIds = array_merge($ingredientIds, $batchIngredientIds);
    btRecipe('batch delete ingredients seeded', min($batchIngredientIds) > 0, json_encode($batchIngredientIds, JSON_UNESCAPED_SLASHES));

    $deletedProducts = bakeshopCatalogDeleteProductsBatch(['ids' => $batchProductIds]);
    btRecipe('batch product delete count matches', (int)($deletedProducts['deleted_count'] ?? 0) === 2, json_encode($deletedProducts, JSON_UNESCAPED_SLASHES));
    btRecipe('batch product delete removes all rows', bakeshopCatalogFindProductById($batchProductIds[0]) === null && bakeshopCatalogFindProductById($batchProductIds[1]) === null);
    $productIds = array_values(array_filter($productIds, static fn (int $id): bool => !in_array($id, $batchProductIds, true)));

    $deletedIngredients = bakeshopCatalogDeleteIngredientsBatch(['ids' => $batchIngredientIds]);
    btRecipe('batch ingredient delete count matches', (int)($deletedIngredients['deleted_count'] ?? 0) === 2, json_encode($deletedIngredients, JSON_UNESCAPED_SLASHES));
    btRecipe('batch ingredient delete removes all rows', bakeshopCatalogFindIngredientById($batchIngredientIds[0]) === null && bakeshopCatalogFindIngredientById($batchIngredientIds[1]) === null);
    $ingredientIds = array_values(array_filter($ingredientIds, static fn (int $id): bool => !in_array($id, $batchIngredientIds, true)));
} finally {
    foreach ($productIds as $productId) {
        if ($productId <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM bakeshop_product_recipe WHERE product_id = ?')->execute([$productId]);
        $db->prepare('DELETE FROM bakeshop_products WHERE id = ?')->execute([$productId]);
    }

    foreach ($ingredientIds as $ingredientId) {
        if ($ingredientId <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM bakeshop_ingredients WHERE id = ?')->execute([$ingredientId]);
    }
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btRecipe('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btRecipe('no error.log errors', $errorLog === '', $errorLog);

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