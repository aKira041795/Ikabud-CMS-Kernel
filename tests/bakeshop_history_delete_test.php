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

function btHistory(string $label, bool $ok, string $detail = ''): void
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

function btHistoryFindRow(array $rows, callable $predicate): ?array
{
    foreach ($rows as $row) {
        if ($predicate($row)) {
            return $row;
        }
    }

    return null;
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP HISTORY DELETE TEST ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$branchId = 0;
$productId = 0;
$ingredientId = 0;
$deliveryId = 0;
$runId = 0;

try {
    $kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
    btHistory('seeded kg unit exists', $kgUnitId > 0);

    $branch = bakeshopDeliveriesCreateBranch([
        'code' => 'HD' . substr($suffix, 0, 6),
        'name' => 'History Branch ' . $suffix,
        'address' => 'History Street',
    ]);
    $branchId = (int)($branch['id'] ?? 0);
    btHistory('branch created', $branchId > 0, json_encode($branch, JSON_UNESCAPED_SLASHES));

    $product = bakeshopCatalogSaveProduct([
        'name' => 'History Product ' . $suffix,
        'sku' => 'HIS-PRD-' . $suffix,
        'category' => 'Bread',
        'default_yield_qty' => 10,
        'default_yield_unit_id' => $kgUnitId,
    ]);
    $productId = (int)($product['id'] ?? 0);
    btHistory('product created', $productId > 0, json_encode($product, JSON_UNESCAPED_SLASHES));

    $ingredient = bakeshopCatalogSaveIngredient([
        'name' => 'History Ingredient ' . $suffix,
        'sku' => 'HIS-ING-' . $suffix,
        'default_unit_id' => $kgUnitId,
    ]);
    $ingredientId = (int)($ingredient['id'] ?? 0);
    btHistory('ingredient created', $ingredientId > 0, json_encode($ingredient, JSON_UNESCAPED_SLASHES));

    $recipe = bakeshopCatalogSaveRecipe([
        'product_id' => $productId,
        'ingredient_id' => $ingredientId,
        'unit_id' => $kgUnitId,
        'qty' => 1,
        'notes' => 'history test line',
    ]);
    btHistory('recipe created', (int)($recipe['id'] ?? 0) > 0, json_encode($recipe, JSON_UNESCAPED_SLASHES));

    $delivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => '2026-04-26 08:00:00',
        'reference' => 'HIS-DEL-' . $suffix,
        'received_by' => 'Supervisor',
        'notes' => 'History delete test delivery',
        'items' => [
            [
                'ingredient_id' => $ingredientId,
                'unit_id' => $kgUnitId,
                'qty' => 3,
                'unit_cost' => 10,
            ],
        ],
    ]);
    $deliveryId = (int)($delivery['id'] ?? 0);
    btHistory('delivery created', $deliveryId > 0, json_encode($delivery, JSON_UNESCAPED_SLASHES));

    $run = bakeshopProductionCreate([
        'branch_id' => $branchId,
        'product_id' => $productId,
        'produced_at' => '2026-04-26 10:00:00',
        'qty_produced' => 10,
        'produced_by' => 'Baker',
        'notes' => 'History delete test production',
    ]);
    $runId = (int)($run['id'] ?? 0);
    btHistory('production run created', $runId > 0, json_encode($run, JSON_UNESCAPED_SLASHES));

    $usageBeforeDelete = bakeshopUsageReportRows([
        'branch_id' => $branchId,
        'from_date' => '2026-04-26',
        'to_date' => '2026-04-26',
    ]);
    $beforeRow = btHistoryFindRow($usageBeforeDelete, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $ingredientId);
    btHistory('usage row exists before deletes', $beforeRow !== null, json_encode($usageBeforeDelete, JSON_UNESCAPED_SLASHES));
    btHistory('consumed qty recorded before production delete', abs((float)($beforeRow['consumed_qty_base'] ?? 0) - 1.0) < 0.0001, json_encode($beforeRow, JSON_UNESCAPED_SLASHES));

    $deletedRun = bakeshopProductionDelete(['id' => $runId]);
    btHistory('production delete returns deleted run', (int)($deletedRun['id'] ?? 0) === $runId, json_encode($deletedRun, JSON_UNESCAPED_SLASHES));
    btHistory('production items cascade delete', (int)($db->query('SELECT COUNT(*) FROM bakeshop_production_items WHERE run_id = ' . $runId)->fetchColumn() ?: 0) === 0);
    btHistory('production row deleted', bakeshopProductionFindById($runId) === null);

    $usageAfterProductionDelete = bakeshopUsageReportRows([
        'branch_id' => $branchId,
        'from_date' => '2026-04-26',
        'to_date' => '2026-04-26',
    ]);
    $afterProductionRow = btHistoryFindRow($usageAfterProductionDelete, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $ingredientId);
    btHistory('usage consumed qty clears after production delete', abs((float)($afterProductionRow['consumed_qty_base'] ?? 0) - 0.0) < 0.0001, json_encode($afterProductionRow, JSON_UNESCAPED_SLASHES));
    btHistory('delivery qty remains after production delete', abs((float)($afterProductionRow['delivered_qty_base'] ?? 0) - 3.0) < 0.0001, json_encode($afterProductionRow, JSON_UNESCAPED_SLASHES));

    $deletedDelivery = bakeshopDeliveriesDelete(['id' => $deliveryId]);
    btHistory('delivery delete returns deleted delivery', (int)($deletedDelivery['id'] ?? 0) === $deliveryId, json_encode($deletedDelivery, JSON_UNESCAPED_SLASHES));
    btHistory('delivery items cascade delete', (int)($db->query('SELECT COUNT(*) FROM bakeshop_delivery_items WHERE delivery_id = ' . $deliveryId)->fetchColumn() ?: 0) === 0);
    btHistory('delivery row deleted', bakeshopDeliveriesFindById($deliveryId) === null);

    $usageAfterDeliveryDelete = bakeshopUsageReportRows([
        'branch_id' => $branchId,
        'from_date' => '2026-04-26',
        'to_date' => '2026-04-26',
    ]);
    btHistory('usage rows clear after delivery delete', $usageAfterDeliveryDelete === [], json_encode($usageAfterDeliveryDelete, JSON_UNESCAPED_SLASHES));
} finally {
    if ($runId > 0) {
        $db->prepare('DELETE FROM bakeshop_production_items WHERE run_id = ?')->execute([$runId]);
        $db->prepare('DELETE FROM bakeshop_production_runs WHERE id = ?')->execute([$runId]);
    }

    if ($deliveryId > 0) {
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

    if ($branchId > 0) {
        $db->prepare('DELETE FROM bakeshop_branches WHERE id = ?')->execute([$branchId]);
    }
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btHistory('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btHistory('no error.log errors', $errorLog === '', $errorLog);

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