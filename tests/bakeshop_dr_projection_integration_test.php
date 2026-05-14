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

function btProjection(string $label, bool $ok, string $detail = ''): void
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

function btProjectionFindRow(array $rows, callable $predicate): ?array
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

echo "\n=== BAKESHOP DR PROJECTION INTEGRATION ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$branchId = 0;
$productId = 0;
$targetId = 0;
$flourId = 0;
$cheeseId = 0;
$deliveryId = 0;
$runIds = [];

try {
    $kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
    $gUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'g' LIMIT 1")->fetchColumn() ?: 0);
    $pcUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'pc' LIMIT 1")->fetchColumn() ?: 0);

    btProjection('seeded kg unit exists', $kgUnitId > 0);
    btProjection('seeded g unit exists', $gUnitId > 0);
    btProjection('seeded pc unit exists', $pcUnitId > 0);

    $branch = bakeshopDeliveriesCreateBranch([
        'code' => 'DP' . $suffix,
        'name' => 'Projection Branch ' . $suffix,
        'address' => 'Projection Street',
    ]);
    $branchId = (int)($branch['id'] ?? 0);
    btProjection('branch created', $branchId > 0, json_encode($branch, JSON_UNESCAPED_SLASHES));

    $product = bakeshopCatalogCreateProduct([
        'name' => 'Projection Loaf ' . $suffix,
        'sku' => 'PRJ-' . $suffix,
        'category' => 'Bread',
        'default_yield_qty' => 1,
        'default_yield_unit_id' => $kgUnitId,
    ]);
    $productId = (int)($product['id'] ?? 0);
    btProjection('product created', $productId > 0, json_encode($product, JSON_UNESCAPED_SLASHES));

    $flour = bakeshopCatalogCreateIngredient([
        'name' => 'Projection Flour ' . $suffix,
        'sku' => 'ING-FLOUR-' . $suffix,
        'default_unit_id' => $kgUnitId,
        'pack_label' => 'sack',
        'pack_qty' => 25,
        'pack_unit_id' => $kgUnitId,
    ]);
    $flourId = (int)($flour['id'] ?? 0);
    btProjection('flour ingredient created with pack metadata', $flourId > 0 && (string)($flour['pack_label'] ?? '') === 'sack', json_encode($flour, JSON_UNESCAPED_SLASHES));

    $cheese = bakeshopCatalogCreateIngredient([
        'name' => 'Projection Cheese ' . $suffix,
        'sku' => 'ING-CHEESE-' . $suffix,
        'default_unit_id' => $gUnitId,
        'pack_label' => 'can',
        'pack_qty' => 410,
        'pack_unit_id' => $gUnitId,
    ]);
    $cheeseId = (int)($cheese['id'] ?? 0);
    btProjection('cheese ingredient created with pack metadata', $cheeseId > 0 && (string)($cheese['pack_label'] ?? '') === 'can', json_encode($cheese, JSON_UNESCAPED_SLASHES));

    try {
        bakeshopCatalogCreateIngredient([
            'name' => 'Invalid Pack ' . $suffix,
            'sku' => 'ING-INVALID-' . $suffix,
            'default_unit_id' => $kgUnitId,
            'pack_label' => 'piece',
            'pack_qty' => 1,
            'pack_unit_id' => $pcUnitId,
        ]);
        btProjection('ingredient pack unit must match default unit dimension', false, 'Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        btProjection('ingredient pack unit must match default unit dimension', str_contains($e->getMessage(), 'pack_unit_id must match the ingredient default unit dimension'), $e->getMessage());
    }

    $target = bakeshopProductTargetsSave([
        'branch_id' => $branchId,
        'product_id' => $productId,
        'daily_qty' => 1,
        'unit_id' => $kgUnitId,
    ]);
    $targetId = (int)($target['id'] ?? 0);
    btProjection('branch product target saved', $targetId > 0 && (int)($target['branch_id'] ?? 0) === $branchId, json_encode($target, JSON_UNESCAPED_SLASHES));

    try {
        bakeshopProductTargetsSave([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'daily_qty' => 1,
            'unit_id' => $pcUnitId,
        ]);
        btProjection('product target unit must match product unit dimension', false, 'Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        btProjection('product target unit must match product unit dimension', str_contains($e->getMessage(), 'unit_id must match the product default yield unit dimension'), $e->getMessage());
    }

    $delivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => '2026-05-01 08:00:00',
        'coverage_days' => 3,
        'reference' => 'DR-' . $suffix,
        'received_by' => 'Supervisor',
        'notes' => 'Projection seed delivery',
        'items' => [
            [
                'ingredient_id' => $flourId,
                'unit_id' => $kgUnitId,
                'qty' => 75,
            ],
            [
                'ingredient_id' => $cheeseId,
                'unit_id' => $gUnitId,
                'qty' => 820,
            ],
        ],
    ]);
    $deliveryId = (int)($delivery['id'] ?? 0);
    btProjection('projection delivery created', $deliveryId > 0 && (int)($delivery['coverage_days'] ?? 0) === 3, json_encode($delivery, JSON_UNESCAPED_SLASHES));

    foreach (['2026-05-01 05:00:00', '2026-05-02 05:00:00', '2026-05-03 05:00:00', '2026-05-05 05:00:00', '2026-05-07 05:00:00'] as $producedAt) {
        $run = bakeshopProductionCreate([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'produced_at' => $producedAt,
            'qty_produced' => 1,
            'produced_by' => 'Projection Baker',
            'notes' => 'Projection test run',
            'relax_guards' => true,
        ]);
        $runIds[] = (int)($run['id'] ?? 0);
    }
    btProjection('five production days recorded', count(array_filter($runIds)) === 5, json_encode($runIds, JSON_UNESCAPED_SLASHES));

    $report = bakeshopDrProjectionReport([
        'branch_id' => $branchId,
        'from_date' => '2026-05-01',
        'to_date' => '2026-05-07',
        'horizon_days' => 7,
    ]);
    btProjection('projection report returns ingredient rows', count((array)($report['ingredients'] ?? [])) === 2, json_encode($report, JSON_UNESCAPED_SLASHES));
    btProjection('projection report returns product rows', count((array)($report['products'] ?? [])) === 1, json_encode($report, JSON_UNESCAPED_SLASHES));

    $flourRow = btProjectionFindRow((array)($report['ingredients'] ?? []), static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $flourId);
    $cheeseRow = btProjectionFindRow((array)($report['ingredients'] ?? []), static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === $cheeseId);
    $productRow = btProjectionFindRow((array)($report['products'] ?? []), static fn (array $row): bool => (int)($row['product_id'] ?? 0) === $productId);

    btProjection('flour projection scales 75 kg over 3 days to 175 kg weekly', abs((float)($flourRow['projected_qty'] ?? 0) - 175.0) < 0.0001, json_encode($flourRow, JSON_UNESCAPED_SLASHES));
    btProjection('flour per-pack display resolves to 7 SACK', (string)($flourRow['per_pack_display'] ?? '') === '7 SACK', json_encode($flourRow, JSON_UNESCAPED_SLASHES));
    btProjection('cheese projection keeps per-pack remainder', (string)($cheeseRow['per_pack_display'] ?? '') === '4 CAN + 273.33 G', json_encode($cheeseRow, JSON_UNESCAPED_SLASHES));
    btProjection('product projection deducts skipped days in strict mode', abs((float)($productRow['projected_qty'] ?? 0) - 5.0) < 0.0001, json_encode($productRow, JSON_UNESCAPED_SLASHES));
    btProjection('product projection reports missing days', (int)($productRow['days_produced'] ?? 0) === 5 && (int)($productRow['missing_days'] ?? 0) === 2, json_encode($productRow, JSON_UNESCAPED_SLASHES));

    $targets = bakeshopProductTargetsList(['branch_id' => $branchId]);
    btProjection('product targets list returns saved row', count($targets) === 1 && (int)($targets[0]['id'] ?? 0) === $targetId, json_encode($targets, JSON_UNESCAPED_SLASHES));
} finally {
    foreach ($runIds as $runId) {
        if ($runId > 0) {
            $db->prepare('DELETE FROM bakeshop_production_items WHERE run_id = ?')->execute([$runId]);
            $db->prepare('DELETE FROM bakeshop_production_runs WHERE id = ?')->execute([$runId]);
        }
    }

    if ($deliveryId > 0) {
        $db->prepare('DELETE FROM bakeshop_delivery_items WHERE delivery_id = ?')->execute([$deliveryId]);
        $db->prepare('DELETE FROM bakeshop_deliveries WHERE id = ?')->execute([$deliveryId]);
    }

    if ($targetId > 0) {
        $db->prepare('DELETE FROM bakeshop_branch_product_targets WHERE id = ?')->execute([$targetId]);
    }

    if ($productId > 0) {
        $db->prepare('DELETE FROM bakeshop_product_recipe WHERE product_id = ?')->execute([$productId]);
        $db->prepare('DELETE FROM bakeshop_products WHERE id = ?')->execute([$productId]);
    }

    if ($cheeseId > 0) {
        $db->prepare('DELETE FROM bakeshop_ingredients WHERE id = ?')->execute([$cheeseId]);
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
btProjection('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btProjection('no error.log errors', $errorLog === '', $errorLog);

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
