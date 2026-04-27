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

function btFixture(string $label, bool $ok, string $detail = ''): void
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

function btFixtureFindRow(array $rows, callable $predicate): ?array
{
    foreach ($rows as $row) {
        if ($predicate($row)) {
            return $row;
        }
    }

    return null;
}

function btFixtureUniqueSku(string $seed, string $prefix, mixed $legacyValue): string
{
    $value = trim((string)$legacyValue);
    $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    if ($value === '') {
        $value = $prefix;
    }

    $sku = 'JBFIX-' . $seed . '-' . $prefix . '-' . strtoupper($value);
    return substr($sku, 0, 100);
}

function btFixtureLoad(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Fixture file not found: ' . $path);
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Fixture JSON could not be decoded.');
    }

    return $decoded;
}

function btFixtureDimensionForMeasurement(string $measurementType): string
{
    return match ($measurementType) {
        'weight' => 'mass',
        'volume' => 'volume',
        'piece' => 'count',
        default => throw new InvalidArgumentException('Unsupported measurement type: ' . $measurementType),
    };
}

function btFixtureBaseUnitCodeForMeasurement(string $measurementType): string
{
    return match ($measurementType) {
        'weight' => 'kg',
        'volume' => 'L',
        'piece' => 'pc',
        default => throw new InvalidArgumentException('Unsupported measurement type: ' . $measurementType),
    };
}

function btFixtureEnsureLegacyUnit(PDO $db, array $ingredientRow, array &$createdUnitIds, array &$unitMap): array
{
    $code = trim((string)($ingredientRow['unit_code'] ?? ''));
    if ($code === '') {
        throw new InvalidArgumentException('Fixture ingredient is missing unit_code.');
    }

    if (isset($unitMap[$code])) {
        return $unitMap[$code];
    }

    $existingStmt = $db->prepare('SELECT id, factor_to_base FROM bakeshop_units WHERE code = ? LIMIT 1');
    $existingStmt->execute([$code]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($existing)) {
        $unitMap[$code] = [
            'id' => (int)$existing['id'],
            'factor_to_base' => (float)$existing['factor_to_base'],
        ];
        return $unitMap[$code];
    }

    $measurementType = (string)($ingredientRow['measurement_type'] ?? '');
    $dimension = btFixtureDimensionForMeasurement($measurementType);
    $baseUnitCode = btFixtureBaseUnitCodeForMeasurement($measurementType);
    $baseStmt = $db->prepare('SELECT id FROM bakeshop_units WHERE code = ? LIMIT 1');
    $baseStmt->execute([$baseUnitCode]);
    $baseUnitId = (int)($baseStmt->fetchColumn() ?: 0);
    if ($baseUnitId <= 0) {
        throw new RuntimeException('Missing base bakeshop unit for code ' . $baseUnitCode);
    }

    $sortOrder = (int)($db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM bakeshop_units')->fetchColumn() ?: 10);
    $factorToBase = number_format((float)($ingredientRow['measurement_value'] ?? 1), 6, '.', '');
    $insert = $db->prepare(
        'INSERT INTO bakeshop_units (code, name, dimension, base_unit_id, factor_to_base, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $code,
        (string)($ingredientRow['unit_name'] ?? $code),
        $dimension,
        $baseUnitId,
        $factorToBase,
        $sortOrder,
    ]);

    $unitId = (int)$db->lastInsertId();
    $createdUnitIds[] = $unitId;
    $unitMap[$code] = [
        'id' => $unitId,
        'factor_to_base' => (float)$factorToBase,
    ];

    return $unitMap[$code];
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP JULIES FIXTURE IMPORT ===\n\n";

$fixturePath = __DIR__ . '/fixtures/bakeshop-julies-bread-pastry.json';
$fixture = btFixtureLoad($fixturePath);
$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$seed = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$createdUnitIds = [];
$importedIngredientIds = [];
$importedProductIds = [];
$branchId = 0;
$deliveryId = 0;
$runId = 0;

$legacyIngredientMap = [];
$legacyProductMap = [];
$legacyUnitMap = [];
$pieceUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'pc' LIMIT 1")->fetchColumn() ?: 0);

try {
    $products = array_values(array_filter((array)($fixture['products'] ?? []), 'is_array'));
    $ingredients = array_values(array_filter((array)($fixture['ingredients'] ?? []), 'is_array'));
    $recipes = array_values(array_filter((array)($fixture['recipes'] ?? []), 'is_array'));
    $fixtureCounts = (array)($fixture['meta']['counts'] ?? []);

    btFixture('fixture product count matches metadata', count($products) === (int)($fixtureCounts['products'] ?? -1), json_encode($fixtureCounts));
    btFixture('fixture ingredient count matches metadata', count($ingredients) === (int)($fixtureCounts['ingredients'] ?? -1), json_encode($fixtureCounts));
    btFixture('fixture recipe count matches metadata', count($recipes) === (int)($fixtureCounts['recipes'] ?? -1), json_encode($fixtureCounts));
    btFixture('piece yield unit exists for fixture import', $pieceUnitId > 0, (string)$pieceUnitId);

    foreach ($ingredients as $ingredient) {
        $unit = btFixtureEnsureLegacyUnit($db, $ingredient, $createdUnitIds, $legacyUnitMap);
        $record = bakeshopCatalogCreateIngredient([
            'name' => (string)$ingredient['name'] . ' [' . $seed . ']',
            'sku' => btFixtureUniqueSku($seed, 'ING', $ingredient['ingredient_id'] ?? $ingredient['name'] ?? ''),
            'default_unit_id' => $unit['id'],
            'is_active' => 1,
        ]);
        $localId = (int)($record['id'] ?? 0);
        $legacyId = (int)($ingredient['ingredient_id'] ?? 0);
        $legacyIngredientMap[$legacyId] = [
            'local_id' => $localId,
            'local_unit_id' => (int)$unit['id'],
            'factor_to_base' => (float)$unit['factor_to_base'],
            'fixture' => $ingredient,
        ];
        $importedIngredientIds[] = $localId;
    }

    foreach ($products as $product) {
        $record = bakeshopCatalogCreateProduct([
            'name' => (string)$product['name'] . ' [' . $seed . ']',
            'sku' => btFixtureUniqueSku($seed, 'PRD', $product['sku'] ?? $product['product_id'] ?? ''),
            'category' => (string)($product['category'] ?? ''),
            'default_yield_qty' => max(1, (float)($product['yield'] ?? 1)),
            'default_yield_unit_id' => $pieceUnitId,
            'is_active' => (int)($product['is_active'] ?? 1),
        ]);
        $localId = (int)($record['id'] ?? 0);
        $legacyId = (int)($product['product_id'] ?? 0);
        $legacyProductMap[$legacyId] = [
            'local_id' => $localId,
            'fixture' => $product,
        ];
        $importedProductIds[] = $localId;
    }

    $importedRecipeCount = 0;
    $recipesByProduct = [];
    foreach ($recipes as $recipe) {
        $legacyProductId = (int)($recipe['product_id'] ?? 0);
        $legacyIngredientId = (int)($recipe['ingredient_id'] ?? 0);
        if (!isset($legacyProductMap[$legacyProductId], $legacyIngredientMap[$legacyIngredientId])) {
            throw new RuntimeException('Fixture recipe references missing product or ingredient.');
        }

        $saved = bakeshopCatalogSaveRecipe([
            'product_id' => $legacyProductMap[$legacyProductId]['local_id'],
            'ingredient_id' => $legacyIngredientMap[$legacyIngredientId]['local_id'],
            'unit_id' => $legacyIngredientMap[$legacyIngredientId]['local_unit_id'],
            'qty' => (float)($recipe['quantity'] ?? 0),
            'notes' => 'Imported from Julie\'s fixture ' . $seed,
        ]);
        $importedRecipeCount++;
        $recipesByProduct[$legacyProductId][] = [
            'fixture' => $recipe,
            'saved' => $saved,
            'ingredient' => $legacyIngredientMap[$legacyIngredientId],
        ];
    }

    btFixture('imported all fixture ingredients', count($importedIngredientIds) === count($ingredients), (string)count($importedIngredientIds));
    btFixture('imported all fixture products', count($importedProductIds) === count($products), (string)count($importedProductIds));
    btFixture('imported all fixture recipes', $importedRecipeCount === count($recipes), (string)$importedRecipeCount);
    btFixture('legacy fixture introduced missing unit mappings', count($legacyUnitMap) === 3, json_encode(array_keys($legacyUnitMap)));

    $sampleProductId = 0;
    foreach ($products as $product) {
        $legacyProductId = (int)($product['product_id'] ?? 0);
        $recipeCount = count($recipesByProduct[$legacyProductId] ?? []);
        if ($recipeCount >= 2 && $recipeCount <= 6) {
            $sampleProductId = $legacyProductId;
            break;
        }
    }
    if ($sampleProductId <= 0) {
        foreach ($products as $product) {
            $legacyProductId = (int)($product['product_id'] ?? 0);
            if (count($recipesByProduct[$legacyProductId] ?? []) > 0) {
                $sampleProductId = $legacyProductId;
                break;
            }
        }
    }
    btFixture('sample imported product with recipe exists', $sampleProductId > 0);

    $sampleProduct = $legacyProductMap[$sampleProductId] ?? null;
    $sampleRecipeItems = $recipesByProduct[$sampleProductId] ?? [];
    if ($sampleProduct === null || $sampleRecipeItems === []) {
        throw new RuntimeException('Unable to select a sample imported product with recipes.');
    }

    $branch = bakeshopDeliveriesCreateBranch([
        'code' => 'JB' . substr($seed, 0, 6),
        'name' => 'Julies Fixture Branch ' . $seed,
        'address' => 'Fixture Import Street',
    ]);
    $branchId = (int)($branch['id'] ?? 0);
    btFixture('fixture branch created', $branchId > 0, json_encode($branch, JSON_UNESCAPED_SLASHES));

    $deliveryItems = [];
    foreach ($sampleRecipeItems as $sampleRecipeItem) {
        $recipeQty = (float)($sampleRecipeItem['fixture']['quantity'] ?? 0);
        $deliveryItems[] = [
            'ingredient_id' => (int)$sampleRecipeItem['ingredient']['local_id'],
            'unit_id' => (int)$sampleRecipeItem['ingredient']['local_unit_id'],
            'qty' => number_format($recipeQty * 3, 4, '.', ''),
            'unit_cost' => null,
        ];
    }

    $delivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => '2026-04-27 08:00:00',
        'reference' => 'JBFIX-' . $seed,
        'received_by' => 'Fixture Import',
        'notes' => 'Julie\'s fixture import delivery',
        'items' => $deliveryItems,
    ]);
    $deliveryId = (int)($delivery['id'] ?? 0);
    btFixture('delivery created from imported ingredients', $deliveryId > 0, json_encode($delivery, JSON_UNESCAPED_SLASHES));
    btFixture('delivery contains each recipe ingredient', count((array)($delivery['items'] ?? [])) === count($sampleRecipeItems));

    $qtyProduced = max(1, (float)($sampleProduct['fixture']['yield'] ?? 1));
    $run = bakeshopProductionCreate([
        'branch_id' => $branchId,
        'product_id' => (int)$sampleProduct['local_id'],
        'produced_at' => '2026-04-27 10:00:00',
        'qty_produced' => $qtyProduced,
        'produced_by' => 'Fixture Baker',
        'notes' => 'Julie\'s fixture import production',
    ]);
    $runId = (int)($run['id'] ?? 0);
    btFixture('production run created from imported product', $runId > 0, json_encode($run, JSON_UNESCAPED_SLASHES));
    btFixture('production snapshot includes recipe items', count((array)($run['items'] ?? [])) === count($sampleRecipeItems), json_encode($run['items'] ?? [], JSON_UNESCAPED_SLASHES));

    $usageRows = bakeshopUsageReportRows([
        'branch_id' => $branchId,
        'from_date' => '2026-04-27',
        'to_date' => '2026-04-27',
    ]);
    btFixture('usage rows generated for imported flow', count($usageRows) >= count($sampleRecipeItems), json_encode($usageRows, JSON_UNESCAPED_SLASHES));

    $inventoryRows = bakeshopInventorySnapshotRows([
        'branch_id' => $branchId,
    ]);
    btFixture('inventory snapshot rows generated for imported flow', count($inventoryRows) >= count($sampleRecipeItems), json_encode($inventoryRows, JSON_UNESCAPED_SLASHES));

    $firstSampleItem = $sampleRecipeItems[0];
    $localIngredientId = (int)$firstSampleItem['ingredient']['local_id'];
    $recipeQty = (float)($firstSampleItem['fixture']['quantity'] ?? 0);
    $factor = (float)($firstSampleItem['ingredient']['factor_to_base'] ?? 1);

    $usageRow = btFixtureFindRow($usageRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $localIngredientId);
    $inventoryRow = btFixtureFindRow($inventoryRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $localIngredientId);

    $expectedDeliveredBase = $recipeQty * 3 * $factor;
    $expectedConsumedBase = $recipeQty * $factor;
    $expectedVarianceBase = $expectedDeliveredBase - $expectedConsumedBase;

    btFixture('usage delivered qty matches imported delivery after unit normalization', abs((float)($usageRow['delivered_qty_base'] ?? 0) - $expectedDeliveredBase) < 0.0001, json_encode($usageRow, JSON_UNESCAPED_SLASHES));
    btFixture('usage consumed qty matches imported recipe after unit normalization', abs((float)($usageRow['consumed_qty_base'] ?? 0) - $expectedConsumedBase) < 0.0001, json_encode($usageRow, JSON_UNESCAPED_SLASHES));
    btFixture('inventory snapshot reflects remaining imported stock', abs((float)($inventoryRow['on_hand_qty_base'] ?? 0) - $expectedVarianceBase) < 0.0001, json_encode($inventoryRow, JSON_UNESCAPED_SLASHES));
} finally {
    if ($runId > 0) {
        $db->prepare('DELETE FROM bakeshop_production_items WHERE run_id = ?')->execute([$runId]);
        $db->prepare('DELETE FROM bakeshop_production_runs WHERE id = ?')->execute([$runId]);
    }

    if ($deliveryId > 0) {
        $db->prepare('DELETE FROM bakeshop_delivery_items WHERE delivery_id = ?')->execute([$deliveryId]);
        $db->prepare('DELETE FROM bakeshop_deliveries WHERE id = ?')->execute([$deliveryId]);
    }

    if ($branchId > 0) {
        $db->prepare('DELETE FROM bakeshop_branches WHERE id = ?')->execute([$branchId]);
    }

    if ($importedProductIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($importedProductIds), '?'));
        $db->prepare('DELETE FROM bakeshop_products WHERE id IN (' . $placeholders . ')')->execute($importedProductIds);
    }

    if ($importedIngredientIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($importedIngredientIds), '?'));
        $db->prepare('DELETE FROM bakeshop_ingredients WHERE id IN (' . $placeholders . ')')->execute($importedIngredientIds);
    }

    if ($createdUnitIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($createdUnitIds), '?'));
        $db->prepare('DELETE FROM bakeshop_units WHERE id IN (' . $placeholders . ')')->execute($createdUnitIds);
    }
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btFixture('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btFixture('no error.log errors', $errorLog === '', $errorLog);

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