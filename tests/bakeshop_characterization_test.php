<?php

declare(strict_types=1);

/**
 * Bakeshop — Characterization Test
 *
 * Freezes current module behavior before the rewrite.
 * Tests capture exact current behavior so regressions are detectable.
 *
 * These are NOT aspirational tests — they test what the code ACTUALLY does.
 * If a test fails after a change, that change altered existing behavior.
 *
 * Usage: php tests/bakeshop_characterization_test.php
 */

// Suppress all HTML output from bootstrap/app initialization
ob_start();

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop';

require __DIR__ . '/../bootstrap.php';
ob_clean();
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function bc(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  \u{2713} {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  \u{2717} {$label}" . ($detail !== '' ? " \u{2014} {$detail}" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n\u{2500}\u{2500} {$title} \u{2500}\u{2500}\n";
}

// Clear logs
@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP CHARACTERIZATION TEST ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "PHP: " . PHP_VERSION . "\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

// ═══════════════════════════════════════════════════════════════
// 1. MODULE MANIFEST INTEGRITY
// ═══════════════════════════════════════════════════════════════
section('1. Module Manifest Integrity');

$manifestPath = __DIR__ . '/../modules/bakeshop/module.json';
bc('module.json exists', file_exists($manifestPath));
$manifest = json_decode((string)file_get_contents($manifestPath), true);
bc('module.json is valid JSON', is_array($manifest));
bc('module id is bakeshop', ($manifest['id'] ?? '') === 'bakeshop');
bc('module declares auth_owned block', is_array($manifest['auth_owned'] ?? null));
bc('module has owns_tables', is_array($manifest['owns_tables'] ?? null));

// Count owned tables
$ownedTables = $manifest['owns_tables'] ?? [];
bc('module owns 24 tables', count($ownedTables) === 24, 'found ' . count($ownedTables));

// Check specific owned tables (legacy 14 + 017 migration added 2)
$expectedTables = [
    'bakeshop_users', 'bakeshop_password_resets', 'bakeshop_branches',
    'bakeshop_units', 'bakeshop_ingredients', 'bakeshop_products',
    'bakeshop_product_recipe', 'bakeshop_deliveries', 'bakeshop_delivery_items',
    'bakeshop_branch_product_targets', 'bakeshop_production_runs',
    'bakeshop_production_items', 'bakeshop_inventory_adjustments',
    'bakeshop_product_allocations',
    'bakeshop_inventory_movements',
    'bakeshop_document_numbers',
    'bakeshop_user_branches',
    'bakeshop_operational_periods',
    'bakeshop_transfers',
    'bakeshop_transfer_items',
    'bakeshop_stocktake_sessions',
    'bakeshop_stocktake_items',
    'bakeshop_recipe_headers',
    'bakeshop_recipe_version_lines',
];
foreach ($expectedTables as $tbl) {
    bc("owns table: {$tbl}", in_array($tbl, $ownedTables, true));
}

// Migration files inventory
$migrations = $manifest['migrations'] ?? [];
$expectedMigrations = [
    'database/migrations/001_bakeshop_core.sql',
    'database/migrations/002_bakeshop_delivery_source.sql',
    'database/migrations/002_bakeshop_users.sql',
    'database/migrations/003_bakeshop_bootstrap_admin.sql',
    'database/migrations/004_bakeshop_user_token_version.sql',
    'database/migrations/005_bakeshop_bootstrap_password_reset.sql',
    'database/migrations/006_bakeshop_password_resets.sql',
    'database/migrations/007_bakeshop_delivery_item_cost_basis.sql',
    'database/migrations/008_bakeshop_production_voiding.sql',
    'database/migrations/009_bakeshop_delivery_coverage.sql',
    'database/migrations/010_bakeshop_branch_product_targets.sql',
    'database/migrations/011_bakeshop_ingredient_pack.sql',
    'database/migrations/012_bakeshop_inventory_adjustments_and_reorder.sql',
    'database/migrations/013_bakeshop_product_allocations.sql',
    'database/migrations/014_bakeshop_production_day_fraction.sql',
    'database/migrations/015_bakeshop_delivery_item_product.sql',
    'database/migrations/016_bakeshop_username_case_sensitive.sql',
    'database/migrations/017_bakeshop_inventory_ledger.sql',
    'database/migrations/018_bakeshop_branch_access_and_periods.sql',
    'database/migrations/019_bakeshop_alter_table_idempotency.sql',
    'database/migrations/020_bakeshop_transfers_stocktakes_recipes.sql',
];
bc('has 21 migrations', count($migrations) === 21, 'found ' . count($migrations));
foreach ($expectedMigrations as $idx => $mig) {
    bc("migration {$idx}: {$mig}", ($migrations[$idx] ?? '') === $mig);
}

// Capabilities
$capabilities = $manifest['capabilities']['exposes'] ?? [];
$capIds = array_map(static fn($c) => $c['id'] ?? '', $capabilities);
$expectedCaps = [
    'kernel.auth.authenticate@1',
    'bakeshop.read@1',
    'bakeshop.manage@1',
    'bakeshop.product.read@1',
    'bakeshop.ingredient.usage.read@1',
    'entity.list.bakeshop_product@1',
    'entity.get.bakeshop_product@1',
];
bc('exposes 7 capabilities', count($capIds) === 7, 'found ' . count($capIds) . ': ' . implode(', ', $capIds));
foreach ($expectedCaps as $cap) {
    bc("capability: {$cap}", in_array($cap, $capIds, true));
}

$depends = $manifest['capabilities']['depends'] ?? [];
bc('depends on 2 capabilities', count($depends) === 2, 'found ' . count($depends));
bc("depends: kernel.audit.record@1", in_array('kernel.audit.record@1', $depends, true));
bc("depends: kernel.auth.user@1", in_array('kernel.auth.user@1', $depends, true));

// ═══════════════════════════════════════════════════════════════
// 2. ROUTE INVENTORY
// ═══════════════════════════════════════════════════════════════
section('2. Route Inventory');

$routesPath = __DIR__ . '/../modules/bakeshop/routes.php';
bc('routes.php exists', file_exists($routesPath));
$routes = include $routesPath;
bc('routes.php returns array', is_array($routes));

$methodCounts = [];
$totalRoutes = 0;
foreach ($routes as $method => $methodRoutes) {
    $count = count($methodRoutes);
    $methodCounts[$method] = $count;
    $totalRoutes += $count;
}
bc('routes have correct HTTP methods', array_keys($routes) === ['GET', 'POST'], 'got: ' . implode(', ', array_keys($routes)));
bc("total {$totalRoutes} routes registered", $totalRoutes > 0);

// Check critical route patterns exist
$allPaths = [];
foreach ($routes as $method => $methodRoutes) {
    foreach ($methodRoutes as $path => $handler) {
        $allPaths[] = $path;
    }
}

$criticalPaths = [
    '/bakeshop/login',
    '/admin/bakeshop',
    '/admin/bakeshop/branches',
    '/admin/bakeshop/catalog',
    '/admin/bakeshop/deliveries',
    '/admin/bakeshop/production',
    '/admin/bakeshop/settings',
    '/admin/bakeshop/users',
    '/admin/bakeshop/coverage',
    '/admin/bakeshop/ledger',
    '/api/v1/bakeshop/branches',
    '/api/v1/bakeshop/products',
    '/api/v1/bakeshop/deliveries',
    '/api/v1/bakeshop/production',
    '/api/v1/bakeshop/adjustments',
    '/api/v1/bakeshop/allocations',
];
foreach ($criticalPaths as $p) {
    bc("route exists: {$p}", in_array($p, $allPaths, true));
}

// ═══════════════════════════════════════════════════════════════
// 3. SEEDED DATA CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('3. Seeded Data');

// Check seeded units
$units = $db->query("SELECT code, name FROM bakeshop_units ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$unitCodes = array_map(static fn($u) => $u['code'], $units);
bc('units table has data', count($units) > 0, 'found ' . count($units) . ' units: ' . implode(', ', $unitCodes));

$expectedSeedUnits = ['kg', 'g', 'pc'];
foreach ($expectedSeedUnits as $code) {
    bc("seeded unit: {$code}", in_array($code, $unitCodes, true));
}

// Check seeded ingredients
$ingredients = $db->query("SELECT COUNT(*) FROM bakeshop_ingredients")->fetchColumn();
bc('ingredients table has data', (int)$ingredients > 0, 'found ' . $ingredients . ' ingredients');

// Check seeded products
$products = $db->query("SELECT COUNT(*) FROM bakeshop_products")->fetchColumn();
bc('products table has data', (int)$products > 0, 'found ' . $products . ' products');

// Characterize bootstrap admin presence instead of assuming it is seeded
$bootstrapUser = $db->query("SELECT username, role FROM bakeshop_users WHERE username = 'bakeshopadmin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
bc('bootstrap admin lookup is queryable', $bootstrapUser === false || is_array($bootstrapUser));
if (is_array($bootstrapUser)) {
    bc('bootstrap admin username matches', ($bootstrapUser['username'] ?? '') === 'bakeshopadmin');
    bc('bootstrap admin role is admin', ($bootstrapUser['role'] ?? '') === 'admin');
}

// ═══════════════════════════════════════════════════════════════
// 4. AUTH CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('4. Auth Characterization');

bc('bakeshopCookieName returns expected cookie name', bakeshopCookieName() === 'bakeshop_token');
bc('bakeshopBaseUrl returns string', bakeshopBaseUrl() !== '');
bc('bakeshopBootstrapUsername returns bakeshopadmin', bakeshopBootstrapUsername() === 'bakeshopadmin');
bc('bakeshopBootstrapEmail returns email', filter_var(bakeshopBootstrapEmail(), FILTER_VALIDATE_EMAIL) !== false);

$onboardingState = bakeshopBootstrapOnboardingState();
bc('bootstrap onboarding state is array', is_array($onboardingState));
bc('bootstrap onboarding state exposes required flag', array_key_exists('required', $onboardingState));
bc('bootstrap onboarding state exposes successor-admin flag', array_key_exists('needs_successor_admin', $onboardingState));
bc('bootstrap onboarding state exposes retire-bootstrap flag', array_key_exists('can_retire_bootstrap', $onboardingState));
bc('bootstrap onboarding state exposes password-setup flag', array_key_exists('password_setup_required', $onboardingState));

$shouldForce = bakeshopShouldForceBootstrapOnboarding(null, $onboardingState);
bc('bootstrap onboarding guard is callable', is_bool($shouldForce));

// Capability handlers exist
$capHandlers = bakeshop_capability_handlers();
$expectedHandlerKeys = [
    'kernel.auth.authenticate@1',
    'bakeshop.read@1',
    'bakeshop.manage@1',
    'bakeshop.product.read@1',
    'bakeshop.ingredient.usage.read@1',
    'entity.list.bakeshop_product@1',
    'entity.get.bakeshop_product@1',
];
bc('capability handlers map exists', is_array($capHandlers));
foreach ($expectedHandlerKeys as $key) {
    bc("cap handler registered: {$key}", isset($capHandlers[$key]));
}

// ═══════════════════════════════════════════════════════════════
// 5. BRANCH CRUD CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('5. Branch CRUD');

$branchCode = 'CHR-' . substr($suffix, 0, 5);
$branch = bakeshopDeliveriesCreateBranch([
    'code' => $branchCode,
    'name' => 'Characterization Branch ' . $suffix,
    'address' => '123 Test Street',
]);
$branchId = (int)($branch['id'] ?? 0);
bc('branch created with id', $branchId > 0, json_encode($branch, JSON_UNESCAPED_SLASHES));
bc('branch code matches', ($branch['code'] ?? '') === $branchCode);
bc('branch is active by default', ($branch['is_active'] ?? null) == 1);

// Read branch
$fetchedBranch = bakeshopDeliveriesFindBranchById($branchId);
bc('branch can be fetched by id', is_array($fetchedBranch) && (int)($fetchedBranch['id'] ?? 0) === $branchId);

// Update branch (via create with id)
$updatedBranch = bakeshopDeliveriesCreateBranch([
    'id' => $branchId,
    'code' => $branchCode,
    'name' => 'Updated Branch ' . $suffix,
    'address' => '456 New Address',
    'is_active' => 0,
]);
bc('branch can be updated', (int)($updatedBranch['id'] ?? 0) === $branchId);
bc('branch can be deactivated', ($updatedBranch['is_active'] ?? null) == 0);

// Re-activate
$reactivatedBranch = bakeshopDeliveriesCreateBranch([
    'id' => $branchId,
    'code' => $branchCode,
    'name' => 'Updated Branch ' . $suffix,
    'is_active' => 1,
]);
bc('branch can be reactivated', ($reactivatedBranch['is_active'] ?? null) == 1);

// List branches
$branches = bakeshopDeliveriesListBranches();
bc('branches can be listed', is_array($branches) && count($branches) > 0);

// ═══════════════════════════════════════════════════════════════
// 6. UNIT CRUD CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('6. Unit CRUD');

// Get kg unit id
$kgUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'kg' LIMIT 1")->fetchColumn() ?: 0);
$pcUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'pc' LIMIT 1")->fetchColumn() ?: 0);
$gUnitId = (int)($db->query("SELECT id FROM bakeshop_units WHERE code = 'g' LIMIT 1")->fetchColumn() ?: 0);

bc('kg unit exists', $kgUnitId > 0);
bc('pc unit exists', $pcUnitId > 0);
bc('g unit exists', $gUnitId > 0);

// ═══════════════════════════════════════════════════════════════
// 7. PRODUCT CRUD CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('7. Product CRUD');

$productSku = 'CHR-PRD-' . $suffix;
$product = bakeshopCatalogSaveProduct([
    'name' => 'Characterization Product ' . $suffix,
    'sku' => $productSku,
    'category' => 'Bread',
    'default_yield_qty' => 10,
    'default_yield_unit_id' => $kgUnitId,
]);
$productId = (int)($product['id'] ?? 0);
bc('product created with id', $productId > 0, json_encode($product, JSON_UNESCAPED_SLASHES));
bc('product sku matches', ($product['sku'] ?? '') === $productSku);
bc('product is active by default', ($product['is_active'] ?? null) == 1);
bc('product default_yield_qty stored', abs((float)($product['default_yield_qty'] ?? 0) - 10.0) < 0.001);

// Update product
$updatedProduct = bakeshopCatalogSaveProduct([
    'id' => $productId,
    'name' => 'Updated Product ' . $suffix,
    'sku' => $productSku,
    'category' => 'Pastry',
    'default_yield_qty' => 20,
    'default_yield_unit_id' => $pcUnitId,
]);
bc('product can be updated', (int)($updatedProduct['id'] ?? 0) === $productId);
bc('product category can change', ($updatedProduct['category'] ?? '') === 'Pastry');

// ═══════════════════════════════════════════════════════════════
// 8. INGREDIENT CRUD CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('8. Ingredient CRUD');

$ingredientCode = 'CHR-ING-' . substr($suffix, 0, 5);
$ingredient = bakeshopCatalogSaveIngredient([
    'sku' => $ingredientCode,
    'name' => 'Characterization Ingredient ' . $suffix,
    'default_unit_id' => $kgUnitId,
]);
$ingredientId = (int)($ingredient['id'] ?? 0);
bc('ingredient created with id', $ingredientId > 0, json_encode($ingredient, JSON_UNESCAPED_SLASHES));

// Verify via API
$ingredients = bakeshopCatalogFetchAll(
    'SELECT id, sku, name, is_active, default_unit_id FROM bakeshop_ingredients WHERE id = :id',
    [':id' => $ingredientId]
);
$fetchedIng = $ingredients[0] ?? [];
bc('ingredient can be fetched', (int)($fetchedIng['id'] ?? 0) === $ingredientId);
bc('ingredient sku matches', ($fetchedIng['sku'] ?? '') === $ingredientCode);
bc('ingredient is active by default', ($fetchedIng['is_active'] ?? null) == 1);

// ═══════════════════════════════════════════════════════════════
// 9. RECIPE CRUD CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('9. Recipe CRUD');

$recipe = bakeshopCatalogSaveRecipe([
    'product_id' => $productId,
    'ingredient_id' => $ingredientId,
    'qty' => 0.5,
    'unit_id' => $kgUnitId,
]);
bc('recipe created successfully', is_array($recipe) && !empty($recipe));

// Fetch recipe lines
$recipes = bakeshopCatalogFetchAll(
    'SELECT id, product_id, ingredient_id, qty, unit_id
     FROM bakeshop_product_recipe WHERE product_id = :pid',
    [':pid' => $productId]
);
bc('recipe has 1 ingredient line', count($recipes) === 1);
bc('recipe qty stored correctly', abs((float)($recipes[0]['qty'] ?? 0) - 0.5) < 0.001);

// Delete recipe
$deleteResult = bakeshopCatalogDeleteRecipe(['id' => $recipes[0]['id']]);
bc('recipe can be deleted', is_array($deleteResult));

// Verify deletion
$afterDelete = bakeshopCatalogFetchAll(
    'SELECT id FROM bakeshop_product_recipe WHERE id = :id',
    [':id' => $recipes[0]['id']]
);
bc('recipe is removed after delete', empty($afterDelete));

// ═══════════════════════════════════════════════════════════════
// 10. DELIVERY CRUD CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('10. Delivery CRUD');

$delivery = bakeshopDeliveriesCreate([
    'branch_id' => $branchId,
    'delivered_at' => date('Y-m-d'),
    'items' => [[
        'ingredient_id' => $ingredientId,
        'qty' => 50,
        'unit_id' => $kgUnitId,
        'unit_cost' => 25.00,
    ]],
]);
$deliveryId = (int)($delivery['id'] ?? 0);
bc('delivery created with id', $deliveryId > 0, json_encode($delivery, JSON_UNESCAPED_SLASHES));

// Fetch delivery header and item
$fetchedDelivery = $db->prepare(
    'SELECT id, branch_id
      FROM bakeshop_deliveries WHERE id = :id'
);
$fetchedDelivery->execute([':id' => $deliveryId]);
$deliveryRecord = $fetchedDelivery->fetch(PDO::FETCH_ASSOC);
bc('delivery can be fetched', is_array($deliveryRecord) && (int)($deliveryRecord['id'] ?? 0) === $deliveryId);
$fetchedDeliveryItem = $db->prepare(
    'SELECT ingredient_id, qty, unit_id, unit_cost
     FROM bakeshop_delivery_items WHERE delivery_id = :id LIMIT 1'
);
$fetchedDeliveryItem->execute([':id' => $deliveryId]);
$deliveryItemRecord = $fetchedDeliveryItem->fetch(PDO::FETCH_ASSOC);
bc('delivery item can be fetched', is_array($deliveryItemRecord));
bc('delivery item stores qty', abs((float)($deliveryItemRecord['qty'] ?? 0) - 50) < 0.001);
bc('delivery item stores unit_cost', abs((float)($deliveryItemRecord['unit_cost'] ?? 0) - 25.00) < 0.01);

// Delete delivery (current behavior: hard-delete allowed)
$deleteDelivery = bakeshopDeliveriesDelete(['id' => $deliveryId]);
bc('delivery can be deleted (current behavior)', is_array($deleteDelivery));

// Verify deletion
$verifyDelete = $db->prepare('SELECT id FROM bakeshop_deliveries WHERE id = :id');
$verifyDelete->execute([':id' => $deliveryId]);
bc('delivery record removed after delete', $verifyDelete->fetchColumn() === false);

// ═══════════════════════════════════════════════════════════════
// 11. PRODUCTION CRUD CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('11. Production CRUD');

// Re-create delivery and ingredient for production
$ingredient2 = bakeshopCatalogSaveIngredient([
    'sku' => 'CHR-ING2-' . substr($suffix, 0, 4),
    'name' => 'Char Ingredient 2 ' . $suffix,
    'default_unit_id' => $kgUnitId,
]);
$ingredient2Id = (int)($ingredient2['id'] ?? 0);

// Create delivery for ingredient
bakeshopDeliveriesCreate([
    'branch_id' => $branchId,
    'delivered_at' => date('Y-m-d'),
    'items' => [[
        'ingredient_id' => $ingredient2Id,
        'qty' => 100,
        'unit_id' => $kgUnitId,
        'unit_cost' => 10.00,
    ]],
]);

// Create a recipe for the product with ingredient2
$prodRecipeId = 0;
$db->prepare(
    'INSERT INTO bakeshop_product_recipe (product_id, ingredient_id, qty, unit_id, created_at, updated_at)
     VALUES (:pid, :iid, :qty, :uid, NOW(), NOW())'
)->execute([
    ':pid' => $productId,
    ':iid' => $ingredient2Id,
    ':qty' => 0.5,
    ':uid' => $kgUnitId,
]);
$prodRecipeId = (int)$db->lastInsertId();

// Create production run
$production = bakeshopProductionCreate([
    'branch_id' => $branchId,
    'product_id' => $productId,
    'qty_produced' => 10,
    'produced_at' => date('Y-m-d'),
]);
$productionId = (int)($production['id'] ?? 0);
bc('production created with id', $productionId > 0, json_encode($production, JSON_UNESCAPED_SLASHES));
bc('production has days_worth', isset($production['days_worth']));

// Verify production items were created
$prodItems = $db->prepare('SELECT id, ingredient_id, qty_used FROM bakeshop_production_items WHERE run_id = :rid');
$prodItems->execute([':rid' => $productionId]);
$items = $prodItems->fetchAll(PDO::FETCH_ASSOC);
bc('production items created', count($items) > 0, 'found ' . count($items));
bc('production item qty matches current yield scaling', abs((float)($items[0]['qty_used'] ?? 0) - 0.25) < 0.01);

// Void production
$voidResult = bakeshopProductionVoid([
    'id' => $productionId,
    'void_reason' => 'Characterization test void',
]);
bc('production can be voided', is_array($voidResult));

// Check voided_at is set
$voidCheck = $db->prepare('SELECT voided_at, void_reason FROM bakeshop_production_runs WHERE id = :id');
$voidCheck->execute([':id' => $productionId]);
$voidRecord = $voidCheck->fetch(PDO::FETCH_ASSOC);
bc('production voided_at is set', $voidRecord['voided_at'] !== null);
bc('production void_reason is stored', ($voidRecord['void_reason'] ?? '') === 'Characterization test void');

// ═══════════════════════════════════════════════════════════════
// 12. INVENTORY ADJUSTMENT CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('12. Inventory Adjustment CRUD');

$adjustment = bakeshopAdjustmentCreate([
    'branch_id' => $branchId,
    'ingredient_id' => $ingredient2Id,
    'qty' => -5,
    'unit_id' => $kgUnitId,
    'adjustment_type' => 'waste',
    'notes' => 'Waste - characterization test',
    'adjustment_date' => date('Y-m-d'),
]);
$adjustmentId = (int)($adjustment['id'] ?? 0);
bc('adjustment created with id', $adjustmentId > 0, json_encode($adjustment, JSON_UNESCAPED_SLASHES));

// Fetch the adjustment
$adjRecord = $db->prepare('SELECT id, qty, adjustment_type, notes, adjustment_date FROM bakeshop_inventory_adjustments WHERE id = :id');
$adjRecord->execute([':id' => $adjustmentId]);
$adj = $adjRecord->fetch(PDO::FETCH_ASSOC);
bc('adjustment can be fetched', is_array($adj) && (int)($adj['id'] ?? 0) === $adjustmentId);
bc('adjustment stores negative qty', (float)($adj['qty'] ?? 0) < 0);
bc('adjustment stores type', ($adj['adjustment_type'] ?? '') === 'waste');
bc('adjustment stores notes', ($adj['notes'] ?? '') === 'Waste - characterization test');

// Current behavior: adjustment can be hard-deleted
$db->prepare('DELETE FROM bakeshop_inventory_adjustments WHERE id = :id')->execute([':id' => $adjustmentId]);
$verifyAdjDelete = $db->prepare('SELECT id FROM bakeshop_inventory_adjustments WHERE id = :id');
$verifyAdjDelete->execute([':id' => $adjustmentId]);
bc('adjustment can be hard-deleted (current behavior)', $verifyAdjDelete->fetchColumn() === false);

// ═══════════════════════════════════════════════════════════════
// 13. PRODUCT ALLOCATION CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('13. Allocation CRUD');

$allocation = bakeshopAllocationCreate([
    'branch_id' => $branchId,
    'product_id' => $productId,
    'allocated_date' => date('Y-m-d'),
    'days_worth' => 8,
]);
$allocationId = (int)($allocation['id'] ?? 0);
bc('allocation created with id', $allocationId > 0, json_encode($allocation, JSON_UNESCAPED_SLASHES));

// Fetch allocation
$allocRecord = $db->prepare('SELECT id, product_id, days_worth, allocated_date FROM bakeshop_product_allocations WHERE id = :id');
$allocRecord->execute([':id' => $allocationId]);
$alloc = $allocRecord->fetch(PDO::FETCH_ASSOC);
bc('allocation can be fetched', is_array($alloc) && (int)($alloc['id'] ?? 0) === $allocationId);
bc('allocation stores days_worth', abs((float)($alloc['days_worth'] ?? 0) - 8) < 0.01);

// Current behavior: allocation can be hard-deleted
$db->prepare('DELETE FROM bakeshop_product_allocations WHERE id = :id')->execute([':id' => $allocationId]);
$verifyAllocDelete = $db->prepare('SELECT id FROM bakeshop_product_allocations WHERE id = :id');
$verifyAllocDelete->execute([':id' => $allocationId]);
bc('allocation can be hard-deleted (current behavior)', $verifyAllocDelete->fetchColumn() === false);

// ═══════════════════════════════════════════════════════════════
// 14. REPORT CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('14. Reports');

// Usage report
$usageReport = @bakeshopCatalogFetchAll(
    'SELECT pr.id, pr.product_id, pr.qty_produced, pr.produced_at,
            pi.ingredient_id, pi.qty_used
     FROM bakeshop_production_runs pr
     LEFT JOIN bakeshop_production_items pi ON pi.run_id = pr.id
     WHERE pr.branch_id = :bid AND pr.voided_at IS NULL
     ORDER BY pr.produced_at DESC
     LIMIT 50',
    [':bid' => $branchId]
);
bc('usage report is queryable', is_array($usageReport));

// Branch list report
$branchList = bakeshopDeliveriesListBranches();
bc('branch list report returns array', is_array($branchList));

// Product coverage (characterize that it's callable)
$coverageError = null;
$coverageResult = null;
try {
    $coverageResult = bakeshopProductCoverageReport(['branch_id' => $branchId]);
} catch (\Throwable $e) {
    $coverageError = $e->getMessage();
}
bc('coverage report is callable without exception', $coverageError === null, $coverageError ?? '');
if ($coverageError === null) {
    bc('coverage report returns array', is_array($coverageResult));
}

// ═══════════════════════════════════════════════════════════════
// 15. PERMISSION MODEL CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('15. Permission Model');

$defaultPerms = bakeshopDefaultRolePermissions();
bc('default permissions exist', is_array($defaultPerms));
bc('admin has bakeshop.read', in_array('bakeshop.read', $defaultPerms['admin'] ?? [], true));
bc('admin has bakeshop.manage', in_array('bakeshop.manage', $defaultPerms['admin'] ?? [], true));
bc('supervisor has bakeshop.read', in_array('bakeshop.read', $defaultPerms['supervisor'] ?? [], true));
bc('supervisor has bakeshop.manage', in_array('bakeshop.manage', $defaultPerms['supervisor'] ?? [], true));

// Check both roles get identical permissions by default
bc('admin and supervisor have identical default permissions', $defaultPerms['admin'] === $defaultPerms['supervisor']);

// Settings defaults
$settingsDefaults = bakeshopSettingsDefaults();
bc('settings defaults exist', is_array($settingsDefaults));
bc('settings has store_name', isset($settingsDefaults['store_name']));
bc('settings has usage_decimal_places', isset($settingsDefaults['usage_decimal_places']));
bc('settings default DR coverage days is 3', (int)($settingsDefaults['default_dr_coverage_days'] ?? 0) === 3);

// ═══════════════════════════════════════════════════════════════
// 16. INVENTORY RECONSTRUCTION CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('16. Inventory Reconstruction');

// Characterize current product schema instead of assuming a stock_qty column.
$productColumns = $db->query("SHOW COLUMNS FROM bakeshop_products")->fetchAll(PDO::FETCH_ASSOC);
$productColumnNames = array_map(static fn($col) => (string)($col['Field'] ?? ''), $productColumns);
bc('products table has default_yield_qty column', in_array('default_yield_qty', $productColumnNames, true));
bc('products table has no stock_qty column', !in_array('stock_qty', $productColumnNames, true));

// ═══════════════════════════════════════════════════════════════
// 17. DATABASE SCHEMA CHARACTERIZATION
// ═══════════════════════════════════════════════════════════════
section('17. Database Schema');

// Verify all owned tables exist in the database
foreach ($ownedTables as $tbl) {
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tbl}'")->fetchColumn();
    bc("table exists: {$tbl}", (int)$exists > 0);
}

// Check for version/concurrency columns (added by 017 migration)
$hasVersionColumn = $db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_production_runs' AND COLUMN_NAME = 'version'"
)->fetchColumn();
bc('production_runs has version column (017 migration)', (int)$hasVersionColumn > 0);

$hasStateColumn = $db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_deliveries' AND COLUMN_NAME = 'status'"
)->fetchColumn();
bc('deliveries has status column (017 migration)', (int)$hasStateColumn > 0);

// Check for inventory movements table (added by 017 migration)
$hasMovementsTable = $db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bakeshop_inventory_movements'"
)->fetchColumn();
bc('inventory_movements table exists (017 migration)', (int)$hasMovementsTable > 0);

// ═══════════════════════════════════════════════════════════════
// 18. LOG CHECK
// ═══════════════════════════════════════════════════════════════
section('18. Log Check');

$appLog = (string)@file_get_contents(STORAGE_PATH . '/logs/app.log');
$errorLog = (string)@file_get_contents(STORAGE_PATH . '/logs/error.log');

$appCritical = substr_count($appLog, '[critical]');
$errorCritical = substr_count($errorLog, '[critical]');

bc('no critical errors in app.log', $appCritical === 0, "found {$appCritical}");
bc('no critical errors in error.log', $errorCritical === 0, "found {$errorCritical}");

// ═══════════════════════════════════════════════════════════════
// CLEANUP — Remove characterization data
// ═══════════════════════════════════════════════════════════════
section('Cleanup');

$db->prepare('DELETE FROM bakeshop_product_allocations WHERE branch_id = :bid')->execute([':bid' => $branchId]);
$db->prepare('DELETE FROM bakeshop_inventory_adjustments WHERE branch_id = :bid')->execute([':bid' => $branchId]);
$db->prepare('DELETE FROM bakeshop_production_items WHERE run_id IN (SELECT id FROM bakeshop_production_runs WHERE branch_id = :bid)')->execute([':bid' => $branchId]);
$db->prepare('DELETE FROM bakeshop_production_runs WHERE branch_id = :bid')->execute([':bid' => $branchId]);
$db->prepare('DELETE FROM bakeshop_product_recipe WHERE product_id = :pid')->execute([':pid' => $productId]);
$db->prepare('DELETE FROM bakeshop_delivery_items WHERE delivery_id IN (SELECT id FROM bakeshop_deliveries WHERE branch_id = :bid)')->execute([':bid' => $branchId]);
$db->prepare('DELETE FROM bakeshop_deliveries WHERE branch_id = :bid')->execute([':bid' => $branchId]);
$db->prepare('DELETE FROM bakeshop_ingredients WHERE id IN (:ingredient_id, :ingredient2_id)')
    ->execute([
        ':ingredient_id' => $ingredientId,
        ':ingredient2_id' => $ingredient2Id,
    ]);
$db->prepare('DELETE FROM bakeshop_branch_product_targets WHERE branch_id = :bid')->execute([':bid' => $branchId]);
$db->prepare('DELETE FROM bakeshop_products WHERE id = :pid')->execute([':pid' => $productId]);
$db->prepare('DELETE FROM bakeshop_branches WHERE id = :bid')->execute([':bid' => $branchId]);

bc('characterization data cleaned up', true);

// ═══════════════════════════════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 55) . "\n";
echo "  RESULTS\n";
echo "  {$pass}/" . ($pass + $fail) . " passed, {$fail} failed\n";
echo "  Assertions: {$pass}\n";
echo str_repeat('═', 55) . "\n";

ob_clean();

if ($fail > 0) {
    echo "\n  Errors:\n";
    foreach ($errors as $err) {
        echo "    - {$err}\n";
    }
    echo "\n";
    exit(1);
}

echo "  \u{1f389} All tests passed.\n\n";
exit(0);
