<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop/print/dr-projection';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function btDrPrint(string $label, bool $ok, string $detail = ''): void
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

function renderBakeshopDrProjectionPrint(array $query = []): string
{
    $previousGet = $_GET;
    $previousRequestUri = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop/print/dr-projection';
    $_GET = $query;
    $_SERVER['REQUEST_URI'] = '/admin/bakeshop/print/dr-projection' . ($query === [] ? '' : ('?' . http_build_query($query)));
    app()->setUser([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ]);

    ob_start();
    try {
        bakeshopPagePrintDrProjection();
        return (string)ob_get_clean();
    } finally {
        $_GET = $previousGet;
        $_SERVER['REQUEST_URI'] = $previousRequestUri;
    }
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP DR PROJECTION PRINT TEST ===\n\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$originalSettings = getModuleSettings('bakeshop');
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$branchId = 0;
$productId = 0;
$targetId = 0;
$ingredientId = 0;
$deliveryId = 0;
$runIds = [];

try {
    saveModuleSettings('bakeshop', array_merge(is_array($originalSettings) ? $originalSettings : [], [
        'store_name' => 'North Oven Bakery',
        'store_description' => 'Projection printouts for branch planning.',
        'store_logo_url' => '/uploads/bakeshop/north-oven.png',
        'usage_decimal_places' => '2',
        'print_template' => 'standard',
    ]));
    bakeshopClearBrandSettingsCache();

    $kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
    btDrPrint('seeded kg unit exists', $kgUnitId > 0);

    $branch = bakeshopDeliveriesCreateBranch([
        'code' => 'DP' . $suffix,
        'name' => 'Projection Branch ' . $suffix,
        'address' => 'Projection Street',
    ]);
    $branchId = (int)($branch['id'] ?? 0);
    btDrPrint('branch created', $branchId > 0, json_encode($branch, JSON_UNESCAPED_SLASHES));

    $product = bakeshopCatalogCreateProduct([
        'name' => 'Projection Loaf ' . $suffix,
        'sku' => 'PRJ-' . $suffix,
        'category' => 'Bread',
        'default_yield_qty' => 1,
        'default_yield_unit_id' => $kgUnitId,
    ]);
    $productId = (int)($product['id'] ?? 0);
    btDrPrint('product created', $productId > 0, json_encode($product, JSON_UNESCAPED_SLASHES));

    $ingredient = bakeshopCatalogCreateIngredient([
        'name' => 'Projection Flour ' . $suffix,
        'sku' => 'ING-FLOUR-' . $suffix,
        'default_unit_id' => $kgUnitId,
        'pack_label' => 'sack',
        'pack_qty' => 25,
        'pack_unit_id' => $kgUnitId,
    ]);
    $ingredientId = (int)($ingredient['id'] ?? 0);
    btDrPrint('ingredient created', $ingredientId > 0, json_encode($ingredient, JSON_UNESCAPED_SLASHES));

    $target = bakeshopProductTargetsSave([
        'branch_id' => $branchId,
        'product_id' => $productId,
        'daily_qty' => 1,
        'unit_id' => $kgUnitId,
    ]);
    $targetId = (int)($target['id'] ?? 0);
    btDrPrint('branch product target created', $targetId > 0, json_encode($target, JSON_UNESCAPED_SLASHES));

    $delivery = bakeshopDeliveriesCreate([
        'branch_id' => $branchId,
        'delivered_at' => '2026-05-01 08:00:00',
        'coverage_days' => 3,
        'reference' => 'DR-' . $suffix,
        'received_by' => 'Supervisor',
        'items' => [[
            'ingredient_id' => $ingredientId,
            'unit_id' => $kgUnitId,
            'qty' => 75,
        ]],
    ]);
    $deliveryId = (int)($delivery['id'] ?? 0);
    btDrPrint('projection delivery created', $deliveryId > 0, json_encode($delivery, JSON_UNESCAPED_SLASHES));

    foreach (['2026-05-01 05:00:00', '2026-05-02 05:00:00', '2026-05-03 05:00:00', '2026-05-05 05:00:00', '2026-05-07 05:00:00'] as $producedAt) {
        $run = bakeshopProductionCreate([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'produced_at' => $producedAt,
            'qty_produced' => 1,
            'produced_by' => 'Projection Baker',
            'notes' => 'Projection print test run',
            'relax_guards' => true,
        ]);
        $runIds[] = (int)($run['id'] ?? 0);
    }
    btDrPrint('production days recorded', count(array_filter($runIds)) === 5, json_encode($runIds, JSON_UNESCAPED_SLASHES));

    $html = renderBakeshopDrProjectionPrint([
        'branch_id' => $branchId,
        'from_date' => '2026-05-01',
        'to_date' => '2026-05-07',
        'horizon_days' => 7,
    ]);

    btDrPrint('print page renders title and branding', str_contains($html, 'Printable DR Projection') && str_contains($html, 'North Oven Bakery') && str_contains($html, 'Projection printouts for branch planning.'), $html);
    btDrPrint('print page renders configured logo', str_contains($html, '/uploads/bakeshop/north-oven.png'), $html);
    btDrPrint('print page renders branch and projection horizon', str_contains($html, (string)($branch['code'] ?? '') . ' - ' . (string)($branch['name'] ?? '')) && str_contains($html, 'Projection Horizon') && str_contains($html, '7 day(s)'), $html);
    btDrPrint('print page renders ingredient projection row', str_contains($html, (string)($ingredient['name'] ?? '')) && str_contains($html, '75 KG') && str_contains($html, '25 KG') && str_contains($html, '175.00 KG'), $html);
    btDrPrint('print page renders per-pack display', str_contains($html, '7 SACK'), $html);
    btDrPrint('print page renders product projection row', str_contains($html, (string)($product['name'] ?? '')) && str_contains($html, '5 / 7') && str_contains($html, '2 day(s)') && str_contains($html, '5.00 KG'), $html);
    btDrPrint('print page renders usage workspace back link', str_contains($html, '/admin/bakeshop/usage?branch_id=' . $branchId . '&amp;from_date=2026-05-01&amp;to_date=2026-05-07'), $html);

    $emptyHtml = renderBakeshopDrProjectionPrint();
    btDrPrint('print page explains missing scope', str_contains($emptyHtml, 'branch_id must be a positive integer.') && str_contains($emptyHtml, 'Open the Usage workspace'), $emptyHtml);
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

    if ($ingredientId > 0) {
        $db->prepare('DELETE FROM bakeshop_ingredients WHERE id = ?')->execute([$ingredientId]);
    }

    if ($branchId > 0) {
        $db->prepare('DELETE FROM bakeshop_branches WHERE id = ?')->execute([$branchId]);
    }

    saveModuleSettings('bakeshop', is_array($originalSettings) ? $originalSettings : []);
    bakeshopClearBrandSettingsCache();
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btDrPrint('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btDrPrint('no error.log errors', $errorLog === '', $errorLog);

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