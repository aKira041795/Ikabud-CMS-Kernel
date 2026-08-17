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

function btManukan(string $label, bool $ok, string $detail = ''): void
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

function btManukanFindRow(array $rows, callable $predicate): ?array
{
    foreach ($rows as $row) {
        if ($predicate($row)) {
            return $row;
        }
    }

    return null;
}

function btManukanLoadFixture(string $path): array
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

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP MANUKAN PROCESS TEST ===\n\n";

$fixture = btManukanLoadFixture(__DIR__ . '/fixtures/bakeshop-manukan-photo-process.json');
$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$branchId = 0;
$deliveryId = 0;
$createdIngredientIds = [];
$createdProductIds = [];
$runIds = [];
$createdBranchCode = '';

try {
    $unitCodes = [];
    foreach ((array)($fixture['ingredients'] ?? []) as $ingredient) {
        $unitCodes[] = (string)($ingredient['default_unit_code'] ?? '');
    }
    foreach ((array)($fixture['delivery']['items'] ?? []) as $deliveryItem) {
        $unitCodes[] = (string)($deliveryItem['unit_code'] ?? '');
    }
    foreach ((array)($fixture['products'] ?? []) as $product) {
        $unitCodes[] = (string)($product['yield_unit_code'] ?? '');
        foreach ((array)($product['recipe'] ?? []) as $recipeItem) {
            $unitCodes[] = (string)($recipeItem['unit_code'] ?? '');
        }
    }
    $unitCodes = array_values(array_unique(array_filter(array_map('trim', $unitCodes))));

    $unitMap = [];
    foreach ($unitCodes as $unitCode) {
        $stmt = $db->prepare('SELECT id, code, factor_to_base FROM bakeshop_units WHERE code = ? LIMIT 1');
        $stmt->execute([$unitCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Missing seeded bakeshop unit: ' . $unitCode);
        }
        $unitMap[$unitCode] = [
            'id' => (int)$row['id'],
            'factor_to_base' => (float)$row['factor_to_base'],
        ];
    }

    btManukan('fixture ingredient count matches metadata', count((array)($fixture['ingredients'] ?? [])) === (int)($fixture['meta']['counts']['ingredients'] ?? -1), json_encode($fixture['meta']['counts'] ?? [], JSON_UNESCAPED_SLASHES));
    btManukan('fixture product count matches metadata', count((array)($fixture['products'] ?? [])) === (int)($fixture['meta']['counts']['products'] ?? -1), json_encode($fixture['meta']['counts'] ?? [], JSON_UNESCAPED_SLASHES));
    btManukan('fixture delivery item count matches metadata', count((array)($fixture['delivery']['items'] ?? [])) === (int)($fixture['meta']['counts']['delivery_items'] ?? -1), json_encode($fixture['meta']['counts'] ?? [], JSON_UNESCAPED_SLASHES));
    btManukan('fixture production run count matches metadata', count((array)($fixture['products'] ?? [])) === (int)($fixture['meta']['counts']['production_runs'] ?? -1), json_encode($fixture['meta']['counts'] ?? [], JSON_UNESCAPED_SLASHES));

    $recipeSources = (array)($fixture['meta']['recipe_sources'] ?? []);
    $recipeSourceMap = (array)($fixture['recipe_source_map'] ?? []);
    btManukan('fixture includes web recipe source families', count($recipeSources) >= 6, json_encode(array_keys($recipeSources), JSON_UNESCAPED_SLASHES));
    btManukan('fixture maps every product to a recipe source family', count($recipeSourceMap) === count((array)($fixture['products'] ?? [])), json_encode(array_keys($recipeSourceMap), JSON_UNESCAPED_SLASHES));
    foreach ($recipeSources as $recipeSourceKey => $recipeSource) {
        btManukan('recipe source has at least one url for ' . $recipeSourceKey, count((array)($recipeSource['urls'] ?? [])) > 0, json_encode($recipeSource, JSON_UNESCAPED_SLASHES));
        btManukan('recipe source has a basis summary for ' . $recipeSourceKey, trim((string)($recipeSource['summary'] ?? '')) !== '', json_encode($recipeSource, JSON_UNESCAPED_SLASHES));
    }
    foreach ((array)($fixture['products'] ?? []) as $product) {
        $productKey = trim((string)($product['key'] ?? ''));
        $recipeSourceKey = trim((string)($recipeSourceMap[$productKey] ?? ''));
        btManukan('recipe source is mapped for ' . $productKey, $recipeSourceKey !== '' && isset($recipeSources[$recipeSourceKey]), json_encode($product, JSON_UNESCAPED_SLASHES));
    }

    $suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $branchInput = (array)($fixture['branch'] ?? []);
    $createdBranchCode = trim((string)($branchInput['code'] ?? 'MNKPHOTO')) . '-' . $suffix;
    $branch = bakeshopDeliveriesCreateBranch([
        'code' => $createdBranchCode,
        'name' => (string)($branchInput['name'] ?? 'Julies Manukan Branch') . ' [' . $suffix . ']',
        'address' => (string)($branchInput['address'] ?? 'Manukan'),
    ]);
    $branchId = (int)($branch['id'] ?? 0);
    btManukan('fixture branch created', $branchId > 0, json_encode($branch, JSON_UNESCAPED_SLASHES));

    $ingredientMap = [];
    foreach ((array)($fixture['ingredients'] ?? []) as $ingredient) {
        $key = trim((string)($ingredient['key'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('Fixture ingredient is missing key.');
        }

        $record = bakeshopCatalogCreateIngredient([
            'name' => (string)($ingredient['name'] ?? 'Ingredient') . ' [' . $suffix . ']',
            'sku' => (string)($ingredient['sku'] ?? $key) . '-' . $suffix,
            'default_unit_id' => (int)$unitMap[(string)$ingredient['default_unit_code']]['id'],
            'is_active' => 1,
        ]);

        $localId = (int)($record['id'] ?? 0);
        $createdIngredientIds[] = $localId;
        $ingredientMap[$key] = [
            'id' => $localId,
            'fixture' => $ingredient,
        ];
    }
    btManukan('created all fixture ingredients', count($ingredientMap) === count((array)($fixture['ingredients'] ?? [])), (string)count($ingredientMap));

    $productMap = [];
    foreach ((array)($fixture['products'] ?? []) as $product) {
        $key = trim((string)($product['key'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('Fixture product is missing key.');
        }

        $record = bakeshopCatalogCreateProduct([
            'name' => (string)($product['name'] ?? 'Product') . ' [' . $suffix . ']',
            'sku' => (string)($product['sku'] ?? $key) . '-' . $suffix,
            'category' => (string)($product['category'] ?? 'Bread'),
            'default_yield_qty' => (float)($product['yield_qty'] ?? 1),
            'default_yield_unit_id' => (int)$unitMap[(string)$product['yield_unit_code']]['id'],
            'is_active' => 1,
        ]);

        $localId = (int)($record['id'] ?? 0);
        $createdProductIds[] = $localId;
        $productMap[$key] = [
            'id' => $localId,
            'fixture' => $product,
        ];

        foreach ((array)($product['recipe'] ?? []) as $recipeLine) {
            $ingredientKey = (string)($recipeLine['ingredient_key'] ?? '');
            if (!isset($ingredientMap[$ingredientKey])) {
                throw new RuntimeException('Fixture recipe references missing ingredient key: ' . $ingredientKey);
            }

            bakeshopCatalogSaveRecipe([
                'product_id' => $localId,
                'ingredient_id' => (int)$ingredientMap[$ingredientKey]['id'],
                'unit_id' => (int)$unitMap[(string)$recipeLine['unit_code']]['id'],
                'qty' => (float)($recipeLine['qty'] ?? 0),
                'notes' => 'Photo-derived process fixture placeholder recipe',
            ]);
        }
    }
    btManukan('created all fixture products', count($productMap) === count((array)($fixture['products'] ?? [])), (string)count($productMap));

    $deliveryItems = [];
    $expectedDelivered = [];
    $deliverySourceMap = (array)($fixture['delivery']['source_document_map'] ?? []);
    $deliverySourceNotes = (array)($fixture['delivery']['source_document_notes'] ?? []);
    $expectedDeliveryAmountBySource = [];
    $receiptTotalAmount = (float)($fixture['delivery']['receipt_total_amount'] ?? 0);
    $extendedTotalAmount = (float)($fixture['delivery']['extended_total_amount'] ?? 0);
    btManukan('fixture declares delivery source provenance for each delivery line', count($deliverySourceMap) === count((array)($fixture['delivery']['items'] ?? [])), json_encode($deliverySourceMap, JSON_UNESCAPED_SLASHES));
    btManukan('fixture declares receipt total amount', $receiptTotalAmount > 0, (string)$receiptTotalAmount);
    btManukan('fixture declares extended delivery total amount', $extendedTotalAmount > 0, (string)$extendedTotalAmount);
    foreach ((array)($fixture['delivery']['items'] ?? []) as $deliveryItem) {
        $ingredientKey = (string)($deliveryItem['ingredient_key'] ?? '');
        $unitCode = (string)($deliveryItem['unit_code'] ?? '');
        if (!isset($ingredientMap[$ingredientKey], $unitMap[$unitCode])) {
            throw new RuntimeException('Fixture delivery references missing ingredient or unit.');
        }

        $sourceDocument = trim((string)($deliverySourceMap[$ingredientKey] ?? ''));
        if ($sourceDocument === '' || !isset($deliverySourceNotes[$sourceDocument])) {
            throw new RuntimeException('Fixture delivery line is missing source provenance for ingredient key: ' . $ingredientKey);
        }

        $deliveryItems[] = [
            'ingredient_id' => (int)$ingredientMap[$ingredientKey]['id'],
            'unit_id' => (int)$unitMap[$unitCode]['id'],
            'qty' => (float)($deliveryItem['qty'] ?? 0),
            'unit_cost' => $deliveryItem['unit_cost'],
            'cost_basis' => $sourceDocument === 'dpl_price_list' ? 'price_list' : 'receipt',
        ];

        $expectedDelivered[$ingredientKey] = ($expectedDelivered[$ingredientKey] ?? 0.0)
            + ((float)($deliveryItem['qty'] ?? 0) * (float)$unitMap[$unitCode]['factor_to_base']);
        $expectedDeliveryAmountBySource[$sourceDocument] = ($expectedDeliveryAmountBySource[$sourceDocument] ?? 0.0)
            + round((float)($deliveryItem['qty'] ?? 0) * (float)($deliveryItem['unit_cost'] ?? 0), 2);
    }

    $delivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => (string)($fixture['delivery']['delivered_at'] ?? '2026-04-15 05:30:00'),
        'reference' => (string)($fixture['delivery']['reference'] ?? 'DEL-PHOTO'),
        'received_by' => (string)($fixture['delivery']['received_by'] ?? 'Receiver'),
        'notes' => (string)($fixture['delivery']['notes'] ?? ''),
        'items' => $deliveryItems,
    ]);
    $deliveryId = (int)($delivery['id'] ?? 0);
    btManukan('fixture delivery created', $deliveryId > 0, json_encode($delivery, JSON_UNESCAPED_SLASHES));
    btManukan('fixture delivery item count matches fixture', count((array)($delivery['items'] ?? [])) === count((array)($fixture['delivery']['items'] ?? [])), (string)count((array)($delivery['items'] ?? [])));

    $actualDeliveryAmountBySource = [];
    $actualDeliveryTotalAmount = 0.0;
    foreach ((array)($fixture['delivery']['items'] ?? []) as $deliveryItem) {
        $ingredientKey = (string)($deliveryItem['ingredient_key'] ?? '');
        $sourceDocument = (string)$deliverySourceMap[$ingredientKey];
        $localIngredientId = (int)$ingredientMap[$ingredientKey]['id'];
        $deliveryRow = btManukanFindRow((array)($delivery['items'] ?? []), static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $localIngredientId);

        btManukan('delivery row exists for ' . $ingredientKey, is_array($deliveryRow), json_encode($delivery['items'] ?? [], JSON_UNESCAPED_SLASHES));

        $expectedUnitCost = round((float)($deliveryItem['unit_cost'] ?? 0), 4);
        $storedUnitCost = (float)($deliveryRow['unit_cost'] ?? 0);
        btManukan('delivery unit cost matches fixture for ' . $ingredientKey, abs($storedUnitCost - $expectedUnitCost) < 0.0001, json_encode($deliveryRow, JSON_UNESCAPED_SLASHES));
        $expectedCostBasis = $sourceDocument === 'dpl_price_list' ? 'price_list' : 'receipt';
        btManukan('delivery cost basis matches fixture provenance for ' . $ingredientKey, (string)($deliveryRow['cost_basis'] ?? '') === $expectedCostBasis, json_encode($deliveryRow, JSON_UNESCAPED_SLASHES));

        $storedLineAmount = round((float)($deliveryRow['qty'] ?? 0) * $storedUnitCost, 2);
        $actualDeliveryAmountBySource[$sourceDocument] = ($actualDeliveryAmountBySource[$sourceDocument] ?? 0.0) + $storedLineAmount;
        $actualDeliveryTotalAmount += $storedLineAmount;
    }

    $expectedReceiptStoredAmount = (float)($expectedDeliveryAmountBySource['receipt'] ?? 0.0);
    $expectedSupplementalStoredAmount = (float)($expectedDeliveryAmountBySource['dpl_price_list'] ?? 0.0);
    $actualReceiptStoredAmount = (float)($actualDeliveryAmountBySource['receipt'] ?? 0.0);
    $actualSupplementalStoredAmount = (float)($actualDeliveryAmountBySource['dpl_price_list'] ?? 0.0);
    btManukan('fixture-derived receipt subtotal stays within document rounding', abs($expectedReceiptStoredAmount - $receiptTotalAmount) <= 0.02, json_encode(['expected_receipt' => $expectedReceiptStoredAmount, 'printed_receipt' => $receiptTotalAmount], JSON_UNESCAPED_SLASHES));
    btManukan('stored receipt subtotal stays within document rounding', abs($actualReceiptStoredAmount - $receiptTotalAmount) <= 0.02, json_encode(['stored_receipt' => $actualReceiptStoredAmount, 'printed_receipt' => $receiptTotalAmount], JSON_UNESCAPED_SLASHES));
    btManukan('fixture-derived extended total stays within document rounding', abs(array_sum($expectedDeliveryAmountBySource) - $extendedTotalAmount) <= 0.02, json_encode(['expected_extended' => array_sum($expectedDeliveryAmountBySource), 'declared_extended' => $extendedTotalAmount], JSON_UNESCAPED_SLASHES));
    btManukan('stored extended total stays within document rounding', abs($actualDeliveryTotalAmount - $extendedTotalAmount) <= 0.02, json_encode(['stored_extended' => $actualDeliveryTotalAmount, 'declared_extended' => $extendedTotalAmount], JSON_UNESCAPED_SLASHES));
    btManukan('supplemental DPL amount is preserved after unit normalization', abs($expectedSupplementalStoredAmount - 670.00) <= 0.02 && abs($actualSupplementalStoredAmount - 670.00) <= 0.02, json_encode(['expected_supplemental' => $expectedSupplementalStoredAmount, 'actual_supplemental' => $actualSupplementalStoredAmount], JSON_UNESCAPED_SLASHES));

    $expectedConsumed = [];
    $expectedProductionItems = 0;
    foreach ((array)($fixture['products'] ?? []) as $product) {
        $productKey = (string)($product['key'] ?? '');
        $run = bakeshopProductionCreate([
            'branch_id' => $branchId,
            'product_id' => (int)$productMap[$productKey]['id'],
            'produced_at' => (string)($product['produced_at'] ?? '2026-04-15 08:00:00'),
            'qty_produced' => (float)($product['yield_qty'] ?? 1),
            'produced_by' => (string)($product['produced_by'] ?? 'Fixture Baker'),
            'notes' => 'Photo-derived process fixture run',
        ]);
        $runId = (int)($run['id'] ?? 0);
        $runIds[] = $runId;
        btManukan('production run created for ' . (string)($product['name'] ?? $productKey), $runId > 0, json_encode($run, JSON_UNESCAPED_SLASHES));
        btManukan('production snapshot item count matches recipe for ' . (string)($product['name'] ?? $productKey), count((array)($run['items'] ?? [])) === count((array)($product['recipe'] ?? [])), json_encode($run['items'] ?? [], JSON_UNESCAPED_SLASHES));
        $expectedProductionItems += count((array)($product['recipe'] ?? []));

        foreach ((array)($product['recipe'] ?? []) as $recipeLine) {
            $ingredientKey = (string)($recipeLine['ingredient_key'] ?? '');
            $unitCode = (string)($recipeLine['unit_code'] ?? '');
            $expectedConsumed[$ingredientKey] = ($expectedConsumed[$ingredientKey] ?? 0.0)
                + ((float)($recipeLine['qty'] ?? 0) * (float)$unitMap[$unitCode]['factor_to_base']);
        }
    }
    btManukan('created all fixture production runs', count($runIds) === count((array)($fixture['products'] ?? [])), (string)count($runIds));

    $usageRows = bakeshopUsageReportRows([
        'branch_id' => $branchId,
        'from_date' => '2026-04-15',
        'to_date' => '2026-04-15',
    ]);
    btManukan('usage rows generated for photo-derived flow', count($usageRows) === count((array)($fixture['ingredients'] ?? [])), json_encode($usageRows, JSON_UNESCAPED_SLASHES));

    $inventoryRows = bakeshopInventorySnapshotRows([
        'branch_id' => $branchId,
    ]);
    btManukan('inventory snapshot rows generated for photo-derived flow', count($inventoryRows) === count((array)($fixture['ingredients'] ?? [])), json_encode($inventoryRows, JSON_UNESCAPED_SLASHES));

    foreach ((array)($fixture['ingredients'] ?? []) as $ingredient) {
        $ingredientKey = (string)($ingredient['key'] ?? '');
        $localIngredientId = (int)$ingredientMap[$ingredientKey]['id'];
        $usageRow = btManukanFindRow($usageRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $localIngredientId);
        $inventoryRow = btManukanFindRow($inventoryRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $localIngredientId);

        $expectedDeliveredBase = (float)($expectedDelivered[$ingredientKey] ?? 0.0);
        $expectedConsumedBase = (float)($expectedConsumed[$ingredientKey] ?? 0.0);
        $expectedVarianceBase = $expectedDeliveredBase - $expectedConsumedBase;

        btManukan('usage row exists for ' . $ingredientKey, is_array($usageRow), json_encode($usageRows, JSON_UNESCAPED_SLASHES));
        btManukan('inventory row exists for ' . $ingredientKey, is_array($inventoryRow), json_encode($inventoryRows, JSON_UNESCAPED_SLASHES));
        btManukan('delivered qty matches fixture for ' . $ingredientKey, abs((float)($usageRow['delivered_qty_base'] ?? 0) - $expectedDeliveredBase) < 0.0001, json_encode($usageRow, JSON_UNESCAPED_SLASHES));
        btManukan('consumed qty matches fixture recipe for ' . $ingredientKey, abs((float)($usageRow['consumed_qty_base'] ?? 0) - $expectedConsumedBase) < 0.0001, json_encode($usageRow, JSON_UNESCAPED_SLASHES));
        btManukan('variance qty matches fixture for ' . $ingredientKey, abs((float)($usageRow['variance_qty_base'] ?? 0) - $expectedVarianceBase) < 0.0001, json_encode($usageRow, JSON_UNESCAPED_SLASHES));
        btManukan('inventory snapshot matches fixture for ' . $ingredientKey, abs((float)($inventoryRow['on_hand_qty_base'] ?? 0) - $expectedVarianceBase) < 0.0001, json_encode($inventoryRow, JSON_UNESCAPED_SLASHES));
    }

    $usageTotals = bakeshopUsageTotals($usageRows);
    $inventoryTotals = bakeshopInventorySnapshotTotals($inventoryRows);
    $expectedDeliveredTotal = array_sum($expectedDelivered);
    $expectedConsumedTotal = array_sum($expectedConsumed);

    // The app stores each ingredient's variance rounded to usage_decimal_places
    // (2) and then sums those per-row values. Summing raw delivered/consumed and
    // rounding once can differ by 0.01 when per-row roundings accumulate across
    // ingredients (surfaces on PHP 8.5). Mirror the app's model so the totals
    // assertion is version-independent.
    $expectedPerRowVariance = [];
    foreach (array_keys($expectedDelivered) as $ingredientKey) {
        $expectedPerRowVariance[] = round(
            (float)($expectedDelivered[$ingredientKey] ?? 0.0) - (float)($expectedConsumed[$ingredientKey] ?? 0.0),
            2
        );
    }
    $roundedExpectedVarianceTotal = round(array_sum($expectedPerRowVariance), 2);
    $roundedExpectedDeliveredTotal = round($expectedDeliveredTotal, 2);
    $roundedExpectedConsumedTotal = round($expectedConsumedTotal, 2);

    btManukan('usage totals delivered base match fixture', abs((float)($usageTotals['delivered_qty_base'] ?? 0) - $roundedExpectedDeliveredTotal) < 0.0001, json_encode($usageTotals, JSON_UNESCAPED_SLASHES));
    btManukan('usage totals consumed base match fixture', abs((float)($usageTotals['consumed_qty_base'] ?? 0) - $roundedExpectedConsumedTotal) < 0.0001, json_encode($usageTotals, JSON_UNESCAPED_SLASHES));
    btManukan('usage totals variance base match fixture', abs((float)($usageTotals['variance_qty_base'] ?? 0) - $roundedExpectedVarianceTotal) < 0.0001, json_encode(['actual' => $usageTotals['variance_qty_base'] ?? null, 'expected' => $roundedExpectedVarianceTotal, 'delivered_total' => $roundedExpectedDeliveredTotal, 'consumed_total' => $roundedExpectedConsumedTotal], JSON_UNESCAPED_SLASHES));
    btManukan('inventory totals on-hand base match fixture', abs((float)($inventoryTotals['on_hand_qty_base'] ?? 0) - $roundedExpectedVarianceTotal) < 0.0001, json_encode(['actual' => $inventoryTotals['on_hand_qty_base'] ?? null, 'expected' => $roundedExpectedVarianceTotal, 'item_count' => $inventoryTotals['item_count'] ?? null], JSON_UNESCAPED_SLASHES));
    btManukan('inventory totals count tracked ingredient lines', (int)($inventoryTotals['item_count'] ?? 0) === count((array)($fixture['ingredients'] ?? [])), json_encode($inventoryTotals, JSON_UNESCAPED_SLASHES));

    $actualFactualSummary = [
        'ingredient_count' => count((array)($fixture['ingredients'] ?? [])),
        'delivery_item_count' => count((array)($fixture['delivery']['items'] ?? [])),
        'production_run_count' => count((array)($fixture['products'] ?? [])),
        'recipe_source_family_count' => count($recipeSources),
        'receipt_source_line_count' => count(array_filter($deliverySourceMap, static fn (string $source): bool => $source === 'receipt')),
        'supplemental_source_line_count' => count(array_filter($deliverySourceMap, static fn (string $source): bool => $source === 'dpl_price_list')),
        'receipt_total_amount' => round($receiptTotalAmount, 2),
        'extended_total_amount' => round($extendedTotalAmount, 2),
        'delivered_qty_base' => round((float)($usageTotals['delivered_qty_base'] ?? 0), 2),
        'consumed_qty_base' => round((float)($usageTotals['consumed_qty_base'] ?? 0), 2),
        'variance_qty_base' => round((float)($usageTotals['variance_qty_base'] ?? 0), 2),
        'inventory_on_hand_qty_base' => round((float)($inventoryTotals['on_hand_qty_base'] ?? 0), 2),
    ];
    $expectedFactualSummary = (array)($fixture['factual_summary'] ?? []);
    btManukan('fixture exposes factual summary contract', $expectedFactualSummary !== [], json_encode($expectedFactualSummary, JSON_UNESCAPED_SLASHES));
    btManukan('computed factual summary matches fixture contract', $actualFactualSummary == $expectedFactualSummary, json_encode(['expected' => $expectedFactualSummary, 'actual' => $actualFactualSummary], JSON_UNESCAPED_SLASHES));

    $runtimeFactualSummary = bakeshopUsageFactualSummary([
        'branch_id' => $branchId,
        'from_date' => '2026-04-15',
        'to_date' => '2026-04-15',
    ]);
    $expectedRuntimeFactualSummary = [
        'ingredient_count' => (int)($expectedFactualSummary['ingredient_count'] ?? 0),
        'delivery_item_count' => (int)($expectedFactualSummary['delivery_item_count'] ?? 0),
        'production_run_count' => (int)($expectedFactualSummary['production_run_count'] ?? 0),
        'delivered_qty_base' => (float)($expectedFactualSummary['delivered_qty_base'] ?? 0),
        'consumed_qty_base' => (float)($expectedFactualSummary['consumed_qty_base'] ?? 0),
        'variance_qty_base' => round(
            (float)($expectedFactualSummary['delivered_qty_base'] ?? 0)
            - (float)($expectedFactualSummary['consumed_qty_base'] ?? 0),
            2
        ),
        'inventory_on_hand_qty_base' => (float)($expectedFactualSummary['inventory_on_hand_qty_base'] ?? 0),
    ];
    $runtimeContractSummary = array_intersect_key($runtimeFactualSummary, $expectedRuntimeFactualSummary);
    btManukan('runtime factual summary helper matches live operations contract', $runtimeContractSummary == $expectedRuntimeFactualSummary, json_encode(['expected' => $expectedRuntimeFactualSummary, 'actual' => $runtimeFactualSummary], JSON_UNESCAPED_SLASHES));

    $totalProductionItems = (int)($db->query('SELECT COUNT(*) FROM bakeshop_production_items WHERE run_id IN (' . implode(', ', array_map('intval', $runIds)) . ')')->fetchColumn() ?: 0);
    btManukan('production item rows match total recipe lines', $totalProductionItems === $expectedProductionItems, (string)$totalProductionItems);
} finally {
    if ($runIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($runIds), '?'));
        $db->prepare('DELETE FROM bakeshop_production_items WHERE run_id IN (' . $placeholders . ')')->execute($runIds);
        $db->prepare('DELETE FROM bakeshop_production_runs WHERE id IN (' . $placeholders . ')')->execute($runIds);
    }

    if ($deliveryId > 0) {
        $db->prepare('DELETE FROM bakeshop_delivery_items WHERE delivery_id = ?')->execute([$deliveryId]);
        $db->prepare('DELETE FROM bakeshop_deliveries WHERE id = ?')->execute([$deliveryId]);
    }

    if ($createdProductIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($createdProductIds), '?'));
        $db->prepare('DELETE FROM bakeshop_products WHERE id IN (' . $placeholders . ')')->execute($createdProductIds);
    }

    if ($createdIngredientIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($createdIngredientIds), '?'));
        $db->prepare('DELETE FROM bakeshop_ingredients WHERE id IN (' . $placeholders . ')')->execute($createdIngredientIds);
    }

    if ($branchId > 0) {
        $db->prepare('DELETE FROM bakeshop_branches WHERE id = ?')->execute([$branchId]);
    } elseif ($createdBranchCode !== '') {
        $db->prepare('DELETE FROM bakeshop_branches WHERE code = ?')->execute([$createdBranchCode]);
    }
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btManukan(
    'no app.log errors',
    $appLog === '' || (!str_contains(strtolower($appLog), '[error]') && !str_contains(strtolower($appLog), '[critical]')),
    $appLog
);
btManukan('no error.log errors', $errorLog === '', $errorLog);

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
