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

function btUsage(string $label, bool $ok, string $detail = ''): void
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

function btUsageFindRow(array $rows, callable $predicate): ?array
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

echo "\n=== BAKESHOP USAGE INTEGRATION ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$branchId = 0;
$productId = 0;
$flourId = 0;
$sugarId = 0;
$deliveryId = 0;
$futureDeliveryId = 0;
$runId = 0;
$voidedRunId = 0;

try {
    $kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
    $gUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'g' LIMIT 1")->fetchColumn() ?: 0);

    btUsage('seeded kg unit exists', $kgUnitId > 0);
    btUsage('seeded g unit exists', $gUnitId > 0);

    $branch = bakeshopDeliveriesCreateBranch([
        'code' => 'T' . $suffix,
        'name' => 'Test Branch ' . $suffix,
        'address' => 'Integration Street',
    ]);
    $branchId = (int)($branch['id'] ?? 0);
    btUsage('branch created', $branchId > 0, json_encode($branch, JSON_UNESCAPED_SLASHES));

    $product = bakeshopCatalogCreateProduct([
        'name' => 'Test Pandesal ' . $suffix,
        'sku' => 'PRD-' . $suffix,
        'category' => 'Bread',
        'default_yield_qty' => 10,
        'default_yield_unit_id' => $kgUnitId,
    ]);
    $productId = (int)($product['id'] ?? 0);
    btUsage('product created', $productId > 0, json_encode($product, JSON_UNESCAPED_SLASHES));

    $flour = bakeshopCatalogCreateIngredient([
        'name' => 'Flour ' . $suffix,
        'sku' => 'ING-FLOUR-' . $suffix,
        'default_unit_id' => $kgUnitId,
    ]);
    $flourId = (int)($flour['id'] ?? 0);
    btUsage('flour ingredient created', $flourId > 0, json_encode($flour, JSON_UNESCAPED_SLASHES));

    $sugar = bakeshopCatalogCreateIngredient([
        'name' => 'Sugar ' . $suffix,
        'sku' => 'ING-SUGAR-' . $suffix,
        'default_unit_id' => $gUnitId,
    ]);
    $sugarId = (int)($sugar['id'] ?? 0);
    btUsage('sugar ingredient created', $sugarId > 0, json_encode($sugar, JSON_UNESCAPED_SLASHES));

    $recipeFlour = bakeshopCatalogSaveRecipe([
        'product_id' => $productId,
        'ingredient_id' => $flourId,
        'unit_id' => $kgUnitId,
        'qty' => 1,
        'notes' => '1 kg flour per 10 yield',
    ]);
    btUsage('flour recipe line saved', (int)($recipeFlour['product_id'] ?? 0) === $productId, json_encode($recipeFlour, JSON_UNESCAPED_SLASHES));

    $recipeSugar = bakeshopCatalogSaveRecipe([
        'product_id' => $productId,
        'ingredient_id' => $sugarId,
        'unit_id' => $gUnitId,
        'qty' => 500,
        'notes' => '500 g sugar per 10 yield',
    ]);
    btUsage('sugar recipe line saved', (int)($recipeSugar['ingredient_id'] ?? 0) === $sugarId, json_encode($recipeSugar, JSON_UNESCAPED_SLASHES));

    $delivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => '2026-04-26 08:00:00',
        'reference' => 'DEL-' . $suffix,
        'received_by' => 'Supervisor',
        'notes' => 'Integration test delivery',
        'items' => [
            [
                'ingredient_id' => $flourId,
                'unit_id' => $kgUnitId,
                'qty' => 5,
                'unit_cost' => 42.5,
                'cost_basis' => 'receipt',
            ],
            [
                'ingredient_id' => $sugarId,
                'unit_id' => $gUnitId,
                'qty' => 2000,
                'unit_cost' => 0.08,
                'cost_basis' => 'manual',
            ],
        ],
    ]);
    $deliveryId = (int)($delivery['id'] ?? 0);
    btUsage('delivery created', $deliveryId > 0, json_encode($delivery, JSON_UNESCAPED_SLASHES));
    btUsage('delivery contains two items', count((array)($delivery['items'] ?? [])) === 2);
    $flourDelivery = btUsageFindRow((array)($delivery['items'] ?? []), static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $flourId);
    $sugarDelivery = btUsageFindRow((array)($delivery['items'] ?? []), static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $sugarId);
    btUsage('delivery item cost basis preserves receipt provenance', (string)($flourDelivery['cost_basis'] ?? '') === 'receipt', json_encode($flourDelivery, JSON_UNESCAPED_SLASHES));
    btUsage('delivery item cost basis preserves manual provenance', (string)($sugarDelivery['cost_basis'] ?? '') === 'manual', json_encode($sugarDelivery, JSON_UNESCAPED_SLASHES));

    $run = bakeshopProductionCreate([
        'branch_id' => $branchId,
        'product_id' => $productId,
        'produced_at' => '2026-04-26 10:00:00',
        'qty_produced' => 20,
        'produced_by' => 'Baker',
        'notes' => 'Integration test run',
    ]);
    $runId = (int)($run['id'] ?? 0);
    btUsage('production run created', $runId > 0, json_encode($run, JSON_UNESCAPED_SLASHES));
    btUsage('production snapshot contains two items', count((array)($run['items'] ?? [])) === 2, json_encode($run['items'] ?? [], JSON_UNESCAPED_SLASHES));

    $snapshotFlour = btUsageFindRow((array)($run['items'] ?? []), static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $flourId);
    $snapshotSugar = btUsageFindRow((array)($run['items'] ?? []), static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $sugarId);
    btUsage('production snapshot scales flour to 2.0000 kg', abs((float)($snapshotFlour['qty_used'] ?? 0) - 2.0) < 0.0001, json_encode($snapshotFlour, JSON_UNESCAPED_SLASHES));
    btUsage('production snapshot scales sugar to 1000.0000 g', abs((float)($snapshotSugar['qty_used'] ?? 0) - 1000.0) < 0.0001, json_encode($snapshotSugar, JSON_UNESCAPED_SLASHES));

    $updatedRun = bakeshopProductionUpdate([
        'id' => $runId,
        'produced_at' => '2026-04-26 10:30:00',
        'produced_by' => 'Lead Baker',
        'notes' => 'Updated production note',
    ]);
    btUsage('production run metadata updated', (string)($updatedRun['produced_by'] ?? '') === 'Lead Baker' && (string)($updatedRun['notes'] ?? '') === 'Updated production note' && (string)($updatedRun['produced_at'] ?? '') === '2026-04-26 10:30:00', json_encode($updatedRun, JSON_UNESCAPED_SLASHES));
    btUsage('production run update preserves snapshot item count', count((array)($updatedRun['items'] ?? [])) === 2, json_encode($updatedRun['items'] ?? [], JSON_UNESCAPED_SLASHES));

    $voidedRun = bakeshopProductionCreate([
        'branch_id' => $branchId,
        'product_id' => $productId,
        'produced_at' => '2026-04-26 11:00:00',
        'qty_produced' => 10,
        'produced_by' => 'Baker',
        'notes' => 'Run to void',
    ]);
    $voidedRunId = (int)($voidedRun['id'] ?? 0);
    btUsage('second production run created for void test', $voidedRunId > 0, json_encode($voidedRun, JSON_UNESCAPED_SLASHES));

    $voidedRunRecord = bakeshopProductionVoid([
        'id' => $voidedRunId,
        'void_reason' => 'Operator entered duplicate run.',
    ], [
        'full_name' => 'Supervisor',
    ]);
    btUsage('production run voided', (int)($voidedRunRecord['id'] ?? 0) === $voidedRunId && trim((string)($voidedRunRecord['voided_at'] ?? '')) !== '', json_encode($voidedRunRecord, JSON_UNESCAPED_SLASHES));

    $visibleProductionRuns = bakeshopProductionList();
    btUsage('voided production run is hidden from production list', btUsageFindRow($visibleProductionRuns, static fn (array $row): bool => (int)($row['id'] ?? 0) === $voidedRunId) === null, json_encode($visibleProductionRuns, JSON_UNESCAPED_SLASHES));

    $futureDelivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => '2026-04-27 08:00:00',
        'reference' => 'DEL-FUTURE-' . $suffix,
        'received_by' => 'Supervisor',
        'notes' => 'Future inventory restock',
        'items' => [
            [
                'ingredient_id' => $flourId,
                'unit_id' => $kgUnitId,
                'qty' => 2,
                'unit_cost' => 43,
                'cost_basis' => 'price_list',
            ],
            [
                'ingredient_id' => $sugarId,
                'unit_id' => $gUnitId,
                'qty' => 500,
                'unit_cost' => 0.08,
                'cost_basis' => 'receipt',
            ],
        ],
    ]);
    $futureDeliveryId = (int)($futureDelivery['id'] ?? 0);
    btUsage('future delivery created', $futureDeliveryId > 0, json_encode($futureDelivery, JSON_UNESCAPED_SLASHES));

    $usageRows = bakeshopUsageReportRows([
        'branch_id' => $branchId,
        'from_date' => '2026-04-26',
        'to_date' => '2026-04-26',
    ]);
    btUsage('usage view returned rows', count($usageRows) >= 2, json_encode($usageRows, JSON_UNESCAPED_SLASHES));

    $flourUsage = btUsageFindRow($usageRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $flourId);
    $sugarUsage = btUsageFindRow($usageRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $sugarId);

    btUsage('flour delivered base qty equals 5.0000', abs((float)($flourUsage['delivered_qty_base'] ?? 0) - 5.0) < 0.0001, json_encode($flourUsage, JSON_UNESCAPED_SLASHES));
    btUsage('flour consumed base qty equals 2.0000 after void exclusion', abs((float)($flourUsage['consumed_qty_base'] ?? 0) - 2.0) < 0.0001, json_encode($flourUsage, JSON_UNESCAPED_SLASHES));
    btUsage('flour variance base qty equals 3.0000', abs((float)($flourUsage['variance_qty_base'] ?? 0) - 3.0) < 0.0001, json_encode($flourUsage, JSON_UNESCAPED_SLASHES));

    btUsage('sugar delivered base qty normalizes 2000 g to 2.0000 kg-base', abs((float)($sugarUsage['delivered_qty_base'] ?? 0) - 2.0) < 0.0001, json_encode($sugarUsage, JSON_UNESCAPED_SLASHES));
    btUsage('sugar consumed base qty normalizes 1000 g to 1.0000 kg-base after void exclusion', abs((float)($sugarUsage['consumed_qty_base'] ?? 0) - 1.0) < 0.0001, json_encode($sugarUsage, JSON_UNESCAPED_SLASHES));
    btUsage('sugar variance base qty equals 1.0000', abs((float)($sugarUsage['variance_qty_base'] ?? 0) - 1.0) < 0.0001, json_encode($sugarUsage, JSON_UNESCAPED_SLASHES));

    $totals = bakeshopUsageTotals($usageRows);
    btUsage('usage totals aggregate delivered base qty', abs((float)($totals['delivered_qty_base'] ?? 0) - 7.0) < 0.0001, json_encode($totals, JSON_UNESCAPED_SLASHES));
    btUsage('usage totals aggregate consumed base qty', abs((float)($totals['consumed_qty_base'] ?? 0) - 3.0) < 0.0001, json_encode($totals, JSON_UNESCAPED_SLASHES));
    btUsage('usage totals aggregate variance base qty', abs((float)($totals['variance_qty_base'] ?? 0) - 4.0) < 0.0001, json_encode($totals, JSON_UNESCAPED_SLASHES));

    $inventoryRows = bakeshopInventorySnapshotRows([
        'branch_id' => $branchId,
        'to_date' => '2026-04-26',
    ]);
    btUsage('inventory snapshot returned rows', count($inventoryRows) >= 2, json_encode($inventoryRows, JSON_UNESCAPED_SLASHES));

    $flourInventory = btUsageFindRow($inventoryRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $flourId);
    $sugarInventory = btUsageFindRow($inventoryRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $sugarId);
    btUsage('flour inventory as of selected end date equals 3.0000', abs((float)($flourInventory['on_hand_qty_base'] ?? 0) - 3.0) < 0.0001, json_encode($flourInventory, JSON_UNESCAPED_SLASHES));
    btUsage('sugar inventory as of selected end date equals 1.0000', abs((float)($sugarInventory['on_hand_qty_base'] ?? 0) - 1.0) < 0.0001, json_encode($sugarInventory, JSON_UNESCAPED_SLASHES));

    $inventoryTotals = bakeshopInventorySnapshotTotals($inventoryRows);
    btUsage('inventory snapshot totals aggregate on-hand base qty', abs((float)($inventoryTotals['on_hand_qty_base'] ?? 0) - 4.0) < 0.0001, json_encode($inventoryTotals, JSON_UNESCAPED_SLASHES));
    btUsage('inventory snapshot totals count tracked ingredient lines', (int)($inventoryTotals['item_count'] ?? 0) === 2, json_encode($inventoryTotals, JSON_UNESCAPED_SLASHES));

    $factualSummary = bakeshopUsageFactualSummary([
        'branch_id' => $branchId,
        'from_date' => '2026-04-26',
        'to_date' => '2026-04-26',
    ]);
    btUsage('factual summary excludes voided production runs', (int)($factualSummary['production_run_count'] ?? 0) === 1, json_encode($factualSummary, JSON_UNESCAPED_SLASHES));

    $currentInventoryRows = bakeshopInventorySnapshotRows([
        'branch_id' => $branchId,
    ]);
    $currentFlourInventory = btUsageFindRow($currentInventoryRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $flourId);
    $currentSugarInventory = btUsageFindRow($currentInventoryRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $sugarId);
    btUsage('current inventory includes later flour restock', abs((float)($currentFlourInventory['on_hand_qty_base'] ?? 0) - 5.0) < 0.0001, json_encode($currentFlourInventory, JSON_UNESCAPED_SLASHES));
    btUsage('current inventory includes later sugar restock', abs((float)($currentSugarInventory['on_hand_qty_base'] ?? 0) - 1.5) < 0.0001, json_encode($currentSugarInventory, JSON_UNESCAPED_SLASHES));
} finally {
    if ($voidedRunId > 0) {
        $db->prepare('DELETE FROM bakeshop_production_items WHERE run_id = ?')->execute([$voidedRunId]);
        $db->prepare('DELETE FROM bakeshop_production_runs WHERE id = ?')->execute([$voidedRunId]);
    }

    if ($runId > 0) {
        $db->prepare('DELETE FROM bakeshop_production_items WHERE run_id = ?')->execute([$runId]);
        $db->prepare('DELETE FROM bakeshop_production_runs WHERE id = ?')->execute([$runId]);
    }

    if ($futureDeliveryId > 0) {
        $db->prepare('DELETE FROM bakeshop_delivery_items WHERE delivery_id = ?')->execute([$futureDeliveryId]);
        $db->prepare('DELETE FROM bakeshop_deliveries WHERE id = ?')->execute([$futureDeliveryId]);
    }

    if ($deliveryId > 0) {
        $db->prepare('DELETE FROM bakeshop_delivery_items WHERE delivery_id = ?')->execute([$deliveryId]);
        $db->prepare('DELETE FROM bakeshop_deliveries WHERE id = ?')->execute([$deliveryId]);
    }

    if ($productId > 0) {
        $db->prepare('DELETE FROM bakeshop_product_recipe WHERE product_id = ?')->execute([$productId]);
        $db->prepare('DELETE FROM bakeshop_products WHERE id = ?')->execute([$productId]);
    }

    if ($sugarId > 0) {
        $db->prepare('DELETE FROM bakeshop_ingredients WHERE id = ?')->execute([$sugarId]);
    }
    if ($flourId > 0) {
        $db->prepare('DELETE FROM bakeshop_ingredients WHERE id = ?')->execute([$flourId]);
    }

    if ($branchId > 0) {
        $db->prepare('DELETE FROM bakeshop_branches WHERE id = ?')->execute([$branchId]);
    }
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btUsage('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btUsage('no error.log errors', $errorLog === '', $errorLog);

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