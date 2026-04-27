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
$productId = 0;
$ingredientId = 0;

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
    btRecipe('product created', $productId > 0, json_encode($product, JSON_UNESCAPED_SLASHES));

    $ingredient = bakeshopCatalogSaveIngredient([
        'name' => 'Recipe Ingredient ' . $suffix,
        'sku' => 'RCP-ING-' . $suffix,
        'default_unit_id' => $kgUnitId,
    ]);
    $ingredientId = (int)($ingredient['id'] ?? 0);
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
    $productId = 0;

    $deletedIngredient = bakeshopCatalogDeleteIngredient(['id' => $ingredientId]);
    btRecipe('ingredient delete returns deleted row', (int)($deletedIngredient['id'] ?? 0) === $ingredientId, json_encode($deletedIngredient, JSON_UNESCAPED_SLASHES));
    btRecipe('ingredient delete removes ingredient row', bakeshopCatalogFindIngredientById($ingredientId) === null);
    $ingredientId = 0;
} finally {
    if ($productId > 0) {
        $db->prepare('DELETE FROM bakeshop_product_recipe WHERE product_id = ?')->execute([$productId]);
        $db->prepare('DELETE FROM bakeshop_products WHERE id = ?')->execute([$productId]);
    }

    if ($ingredientId > 0) {
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