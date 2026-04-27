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

function btAdmin(string $label, bool $ok, string $detail = ''): void
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

echo "\n=== BAKESHOP ADMIN MUTATION TEST ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$branchId = 0;
$productId = 0;
$ingredientId = 0;

try {
    $kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
    $gUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'g' LIMIT 1")->fetchColumn() ?: 0);

    btAdmin('seeded units exist', $kgUnitId > 0 && $gUnitId > 0);

    $branch = bakeshopDeliveriesCreateBranch([
        'code' => 'ADM' . substr($suffix, 0, 5),
        'name' => 'Admin Branch ' . $suffix,
        'address' => 'Initial Address',
    ]);
    $branchId = (int)($branch['id'] ?? 0);
    btAdmin('branch created', $branchId > 0, json_encode($branch, JSON_UNESCAPED_SLASHES));

    $updatedBranch = bakeshopDeliveriesCreateBranch([
        'id' => $branchId,
        'code' => 'ADM' . substr($suffix, 0, 5),
        'name' => 'Updated Branch ' . $suffix,
        'address' => 'Updated Address',
        'is_active' => 0,
    ]);
    btAdmin('branch updated in place', (int)($updatedBranch['id'] ?? 0) === $branchId && ($updatedBranch['name'] ?? '') !== ($branch['name'] ?? ''), json_encode($updatedBranch, JSON_UNESCAPED_SLASHES));
    btAdmin('branch can be archived', (int)($updatedBranch['is_active'] ?? 1) === 0, json_encode($updatedBranch, JSON_UNESCAPED_SLASHES));

    $product = bakeshopCatalogSaveProduct([
        'name' => 'Admin Product ' . $suffix,
        'sku' => 'ADM-PRD-' . $suffix,
        'category' => 'Bread',
        'default_yield_qty' => 12,
        'default_yield_unit_id' => $kgUnitId,
    ]);
    $productId = (int)($product['id'] ?? 0);
    btAdmin('product created', $productId > 0, json_encode($product, JSON_UNESCAPED_SLASHES));

    $updatedProduct = bakeshopCatalogSaveProduct([
        'id' => $productId,
        'name' => 'Updated Product ' . $suffix,
        'sku' => 'ADM-PRD-' . $suffix,
        'category' => 'Signature Bread',
        'default_yield_qty' => 24,
        'default_yield_unit_id' => $kgUnitId,
        'is_active' => false,
    ]);
    btAdmin('product updated in place', (int)($updatedProduct['id'] ?? 0) === $productId && ($updatedProduct['category'] ?? '') === 'Signature Bread', json_encode($updatedProduct, JSON_UNESCAPED_SLASHES));
    btAdmin('product can be archived', (int)($updatedProduct['is_active'] ?? 1) === 0, json_encode($updatedProduct, JSON_UNESCAPED_SLASHES));

    $ingredient = bakeshopCatalogSaveIngredient([
        'name' => 'Admin Ingredient ' . $suffix,
        'sku' => 'ADM-ING-' . $suffix,
        'default_unit_id' => $kgUnitId,
    ]);
    $ingredientId = (int)($ingredient['id'] ?? 0);
    btAdmin('ingredient created', $ingredientId > 0, json_encode($ingredient, JSON_UNESCAPED_SLASHES));

    $updatedIngredient = bakeshopCatalogSaveIngredient([
        'id' => $ingredientId,
        'name' => 'Updated Ingredient ' . $suffix,
        'sku' => 'ADM-ING-' . $suffix,
        'default_unit_id' => $gUnitId,
        'is_active' => '0',
    ]);
    btAdmin('ingredient updated in place', (int)($updatedIngredient['id'] ?? 0) === $ingredientId && ($updatedIngredient['default_unit_code'] ?? '') === 'g', json_encode($updatedIngredient, JSON_UNESCAPED_SLASHES));
    btAdmin('ingredient can be archived', (int)($updatedIngredient['is_active'] ?? 1) === 0, json_encode($updatedIngredient, JSON_UNESCAPED_SLASHES));

    $branches = bakeshopDeliveriesListBranches();
    $products = bakeshopCatalogListProducts();
    $ingredients = bakeshopCatalogListIngredients();

    $branchRow = array_values(array_filter($branches, static fn (array $row): bool => (int)($row['id'] ?? 0) === $branchId))[0] ?? null;
    $productRow = array_values(array_filter($products, static fn (array $row): bool => (int)($row['id'] ?? 0) === $productId))[0] ?? null;
    $ingredientRow = array_values(array_filter($ingredients, static fn (array $row): bool => (int)($row['id'] ?? 0) === $ingredientId))[0] ?? null;

    btAdmin('branch list reflects archived state', (int)($branchRow['is_active'] ?? 1) === 0, json_encode($branchRow, JSON_UNESCAPED_SLASHES));
    btAdmin('product list reflects archived state', (int)($productRow['is_active'] ?? 1) === 0, json_encode($productRow, JSON_UNESCAPED_SLASHES));
    btAdmin('ingredient list reflects archived state', (int)($ingredientRow['is_active'] ?? 1) === 0, json_encode($ingredientRow, JSON_UNESCAPED_SLASHES));
} finally {
    if ($productId > 0) {
        $db->prepare('DELETE FROM bakeshop_product_recipe WHERE product_id = ?')->execute([$productId]);
        $db->prepare('DELETE FROM bakeshop_products WHERE id = ?')->execute([$productId]);
    }

    if ($ingredientId > 0) {
        $db->prepare('DELETE FROM bakeshop_ingredients WHERE id = ?')->execute([$ingredientId]);
    }

    if ($branchId > 0) {
        $db->prepare('DELETE FROM bakeshop_branches WHERE id = ?')->execute([$branchId]);
    }
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btAdmin('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btAdmin('no error.log errors', $errorLog === '', $errorLog);

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