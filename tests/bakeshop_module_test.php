<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function bt(string $label, bool $ok, string $detail = ''): void
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

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');
$appLogStart = is_file($appLogPath) ? max(0, (int)@filesize($appLogPath)) : 0;
$errorLogStart = is_file($errorLogPath) ? max(0, (int)@filesize($errorLogPath)) : 0;

echo "\n=== BAKESHOP MODULE TEST ===\n\n";

echo "── Manifest ──\n";
$manifestPath = BASE_PATH . '/modules/bakeshop/module.json';
bt('module.json exists', is_file($manifestPath));
$manifest = json_decode((string) file_get_contents($manifestPath), true);
bt('module.json is valid JSON', is_array($manifest));
bt('module id is bakeshop', ($manifest['id'] ?? '') === 'bakeshop');
bt('owns_tables declared', is_array($manifest['owns_tables'] ?? null) && in_array('bakeshop_products', $manifest['owns_tables'], true));
bt('usage view declared in reads_tables', is_array($manifest['reads_tables'] ?? null) && in_array('bakeshop_ingredient_usage', $manifest['reads_tables'], true));
bt('bakeshop auth cookie declared', ($manifest['auth_cookie'] ?? '') === 'bakeshop_token');
bt('depends on kernel.auth.user@1', in_array('kernel.auth.user@1', $manifest['capabilities']['depends'] ?? [], true));
bt('depends on kernel.audit.record@1', in_array('kernel.audit.record@1', $manifest['capabilities']['depends'] ?? [], true));
bt('kernel auth capability declared', in_array('kernel.auth.authenticate@1', array_column($manifest['capabilities']['exposes'] ?? [], 'id'), true));
bt('bakeshop.read capability declared', in_array('bakeshop.read@1', array_column($manifest['capabilities']['exposes'] ?? [], 'id'), true));
bt('bakeshop.manage capability declared', in_array('bakeshop.manage@1', array_column($manifest['capabilities']['exposes'] ?? [], 'id'), true));
bt('bakeshop.product.read capability declared', in_array('bakeshop.product.read@1', array_column($manifest['capabilities']['exposes'] ?? [], 'id'), true));
bt('bakeshop.ingredient.usage.read capability declared', in_array('bakeshop.ingredient.usage.read@1', array_column($manifest['capabilities']['exposes'] ?? [], 'id'), true));
bt('catalog events declared', is_array($manifest['events'] ?? null) && in_array('bakeshop.product.created', $manifest['events'], true));
bt('delivery events declared', is_array($manifest['events'] ?? null) && in_array('bakeshop.delivery.created', $manifest['events'], true));
bt('production event declared', is_array($manifest['events'] ?? null) && in_array('bakeshop.production.created', $manifest['events'], true));
bt('production update event declared', is_array($manifest['events'] ?? null) && in_array('bakeshop.production.updated', $manifest['events'], true));
bt('role_permissions setting declared', array_reduce($manifest['settings_fields'] ?? [], static function (bool $carry, array $field): bool {
    return $carry || (($field['key'] ?? '') === 'role_permissions');
}, false));
bt('module.json lists bootstrap admin migration', in_array('database/migrations/003_bakeshop_bootstrap_admin.sql', $manifest['migrations'] ?? [], true));
bt('module.json lists token version migration', in_array('database/migrations/004_bakeshop_user_token_version.sql', $manifest['migrations'] ?? [], true));
bt('module.json lists bootstrap password reset migration', in_array('database/migrations/005_bakeshop_bootstrap_password_reset.sql', $manifest['migrations'] ?? [], true));
bt('module.json lists password reset token migration', in_array('database/migrations/006_bakeshop_password_resets.sql', $manifest['migrations'] ?? [], true));
bt('module.json lists delivery cost basis migration', in_array('database/migrations/007_bakeshop_delivery_item_cost_basis.sql', $manifest['migrations'] ?? [], true));
bt('module.json lists production voiding migration', in_array('database/migrations/008_bakeshop_production_voiding.sql', $manifest['migrations'] ?? [], true));
bt('module.json lists delivery source migration', in_array('database/migrations/002_bakeshop_delivery_source.sql', $manifest['migrations'] ?? [], true));
bt('module.json lists username case-sensitive migration', in_array('database/migrations/016_bakeshop_username_case_sensitive.sql', $manifest['migrations'] ?? [], true));

// Verify every .sql file in the migrations directory is declared in module.json
$migrationDir = BASE_PATH . '/modules/bakeshop/database/migrations';
$onDiskMigrations = glob($migrationDir . '/*.sql');
$declaredMigrations = $manifest['migrations'] ?? [];
$unexpectedOnDisk = [];
foreach ($onDiskMigrations as $diskPath) {
    $relativePath = 'database/migrations/' . basename($diskPath);
    if (!in_array($relativePath, $declaredMigrations, true)) {
        $unexpectedOnDisk[] = $relativePath;
    }
}
bt('every migration on disk is declared in module.json', $unexpectedOnDisk === [], implode(', ', $unexpectedOnDisk));

// Verify every declared migration file exists on disk
$missingFromDisk = [];
foreach ($declaredMigrations as $declaredPath) {
    if (!is_file(BASE_PATH . '/modules/bakeshop/' . $declaredPath)) {
        $missingFromDisk[] = $declaredPath;
    }
}
bt('every declared migration exists on disk', $missingFromDisk === [], implode(', ', $missingFromDisk));

// New event declarations
bt('product deleted event declared', in_array('bakeshop.product.deleted', $manifest['events'] ?? [], true));
bt('ingredient deleted event declared', in_array('bakeshop.ingredient.deleted', $manifest['events'] ?? [], true));
bt('unit created event declared', in_array('bakeshop.unit.created', $manifest['events'] ?? [], true));
bt('adjustment created event declared', in_array('bakeshop.adjustment.created', $manifest['events'] ?? [], true));

echo "\n── Discovery ──\n";
$all = discoverModules();
bt('Module discovered by kernel', isset($all['bakeshop']));

echo "\n── Routes ──\n";
$routesFile = BASE_PATH . '/modules/bakeshop/routes.php';
bt('routes.php exists', is_file($routesFile));
$routes = require $routesFile;
bt('routes.php returns array', is_array($routes));
bt('login page route declared', ($routes['GET']['/bakeshop/login'] ?? '') === 'bakeshop:bakeshopPageLogin');
bt('forgot password page route declared', ($routes['GET']['/bakeshop/forgot-password'] ?? '') === 'bakeshop:bakeshopForgotPasswordPage');
bt('reset password page route declared', ($routes['GET']['/bakeshop/reset-password'] ?? '') === 'bakeshop:bakeshopResetPasswordPage');
bt('logout route declared as POST', ($routes['POST']['/bakeshop/logout'] ?? '') === 'bakeshop:bakeshopLogout');
bt('admin page route declared', ($routes['GET']['/admin/bakeshop'] ?? '') === 'bakeshop:bakeshopPageSupervisor');
bt('branches page route declared', ($routes['GET']['/admin/bakeshop/branches'] ?? '') === 'bakeshop:bakeshopPageBranches');
bt('catalog page route declared', ($routes['GET']['/admin/bakeshop/catalog'] ?? '') === 'bakeshop:bakeshopPageCatalog');
bt('deliveries page route declared', ($routes['GET']['/admin/bakeshop/deliveries'] ?? '') === 'bakeshop:bakeshopPageDeliveries');
bt('production page route declared', ($routes['GET']['/admin/bakeshop/production'] ?? '') === 'bakeshop:bakeshopPageProduction');
bt('usage page route declared', ($routes['GET']['/admin/bakeshop/usage'] ?? '') === 'bakeshop:bakeshopPageUsage');
bt('history page route declared', ($routes['GET']['/admin/bakeshop/history'] ?? '') === 'bakeshop:bakeshopPageHistory');
bt('users page route declared', ($routes['GET']['/admin/bakeshop/users'] ?? '') === 'bakeshop:bakeshopPageUsers');
bt('account page route declared', ($routes['GET']['/admin/bakeshop/account'] ?? '') === 'bakeshop:bakeshopPageAccount');
bt('settings page route declared', ($routes['GET']['/admin/bakeshop/settings'] ?? '') === 'bakeshop:bakeshopPageSettings');
bt('branches API route declared', ($routes['GET']['/api/v1/bakeshop/branches'] ?? '') === 'bakeshop:bakeshopApiBranchesIndex');
bt('users API route declared', ($routes['GET']['/api/v1/bakeshop/users'] ?? '') === 'bakeshop:bakeshopApiUsersList');
bt('units API route declared', ($routes['GET']['/api/v1/bakeshop/units'] ?? '') === 'bakeshop:bakeshopApiUnitsIndex');
bt('usage API route declared', ($routes['GET']['/api/v1/bakeshop/usage'] ?? '') === 'bakeshop:bakeshopApiUsageIndex');
bt('module auth login route declared', ($routes['POST']['/bakeshop/auth/login'] ?? '') === 'bakeshop:bakeshopAuthLogin');
bt('forgot password API route declared', ($routes['POST']['/api/v1/bakeshop/auth/forgot-password'] ?? '') === 'bakeshop:bakeshopApiForgotPassword');
bt('reset password API route declared', ($routes['POST']['/api/v1/bakeshop/auth/reset-password'] ?? '') === 'bakeshop:bakeshopApiResetPassword');
bt('account password API route declared', ($routes['POST']['/api/v1/bakeshop/account/password'] ?? '') === 'bakeshop:bakeshopApiAccountPasswordUpdate');
bt('create user API route declared', ($routes['POST']['/api/v1/bakeshop/users'] ?? '') === 'bakeshop:bakeshopApiUserCreate');
bt('settings permissions API route declared', ($routes['POST']['/api/v1/bakeshop/settings/permissions'] ?? '') === 'bakeshop:bakeshopApiSettingsSavePermissions');
bt('settings display API route declared', ($routes['POST']['/api/v1/bakeshop/settings/display'] ?? '') === 'bakeshop:bakeshopApiSettingsSaveDisplay');
bt('branch status API route declared', ($routes['POST']['/api/v1/bakeshop/branches/{id}/status'] ?? '') === 'bakeshop:bakeshopApiBranchesStatusUpdate');
bt('product status API route declared', ($routes['POST']['/api/v1/bakeshop/products/{id}/status'] ?? '') === 'bakeshop:bakeshopApiProductsStatusUpdate');
bt('ingredient status API route declared', ($routes['POST']['/api/v1/bakeshop/ingredients/{id}/status'] ?? '') === 'bakeshop:bakeshopApiIngredientsStatusUpdate');
bt('recipe delete API route declared', ($routes['POST']['/api/v1/bakeshop/recipes/delete'] ?? '') === 'bakeshop:bakeshopApiRecipesDelete');
bt('delivery delete API route declared', ($routes['POST']['/api/v1/bakeshop/deliveries/delete'] ?? '') === 'bakeshop:bakeshopApiDeliveriesDelete');
bt('production void API route declared', ($routes['POST']['/api/v1/bakeshop/production/void'] ?? '') === 'bakeshop:bakeshopApiProductionVoid');
bt('production delete API route aliases void handler', ($routes['POST']['/api/v1/bakeshop/production/delete'] ?? '') === 'bakeshop:bakeshopApiProductionDelete');

// Under-tested route surfaces — pages
bt('ingredients page route declared', ($routes['GET']['/admin/bakeshop/ingredients'] ?? '') === 'bakeshop:bakeshopPageIngredients');
bt('product ledger page route declared', ($routes['GET']['/admin/bakeshop/ledger'] ?? '') === 'bakeshop:bakeshopPageProductLedger');
bt('product coverage page route declared', ($routes['GET']['/admin/bakeshop/coverage'] ?? '') === 'bakeshop:bakeshopPageProductCoverage');
bt('coverage print page route declared', ($routes['GET']['/admin/bakeshop/coverage/print'] ?? '') === 'bakeshop:bakeshopPagePrintCoverage');
bt('coverage CSV route declared', ($routes['GET']['/admin/bakeshop/coverage/csv'] ?? '') === 'bakeshop:bakeshopApiCoverageCsv');
bt('print summary page route declared', ($routes['GET']['/admin/bakeshop/print'] ?? '') === 'bakeshop:bakeshopPagePrintSummary');
bt('DR projection print route declared', ($routes['GET']['/admin/bakeshop/print/dr-projection'] ?? '') === 'bakeshop:bakeshopPagePrintDrProjection');

// Under-tested route surfaces — APIs
bt('health API route declared', ($routes['GET']['/api/v1/bakeshop/health'] ?? '') === 'bakeshop:bakeshopApiHealth');
bt('product targets API route declared', ($routes['GET']['/api/v1/bakeshop/product-targets'] ?? '') === 'bakeshop:bakeshopApiProductTargetsIndex');
bt('DR projection API route declared', ($routes['GET']['/api/v1/bakeshop/reports/dr-projection'] ?? '') === 'bakeshop:bakeshopApiDrProjectionIndex');
bt('suggested reorder API route declared', ($routes['GET']['/api/v1/bakeshop/reports/suggested-reorder'] ?? '') === 'bakeshop:bakeshopApiSuggestedReorderIndex');
bt('product coverage API route declared', ($routes['GET']['/api/v1/bakeshop/reports/product-coverage'] ?? '') === 'bakeshop:bakeshopApiProductCoverage');
bt('allocations API route declared', ($routes['GET']['/api/v1/bakeshop/allocations'] ?? '') === 'bakeshop:bakeshopApiAllocationsIndex');
bt('adjustments API route declared', ($routes['GET']['/api/v1/bakeshop/adjustments'] ?? '') === 'bakeshop:bakeshopApiAdjustmentsIndex');
bt('product import template route declared', ($routes['GET']['/api/v1/bakeshop/products/import/template'] ?? '') === 'bakeshop:bakeshopApiProductsImportTemplate');
bt('recipe import template route declared', ($routes['GET']['/api/v1/bakeshop/recipes/import/template'] ?? '') === 'bakeshop:bakeshopApiRecipesImportTemplate');
bt('production import template route declared', ($routes['GET']['/api/v1/bakeshop/production/import/template'] ?? '') === 'bakeshop:bakeshopApiProductionImportTemplate');

// Under-tested route surfaces — POST
bt('product targets store route declared', ($routes['POST']['/api/v1/bakeshop/product-targets'] ?? '') === 'bakeshop:bakeshopApiProductTargetsStore');
bt('product targets delete route declared', ($routes['POST']['/api/v1/bakeshop/product-targets/delete'] ?? '') === 'bakeshop:bakeshopApiProductTargetsDelete');
bt('products batch-delete route declared', ($routes['POST']['/api/v1/bakeshop/products/batch-delete'] ?? '') === 'bakeshop:bakeshopApiProductsBatchDelete');
bt('products delete route declared', ($routes['POST']['/api/v1/bakeshop/products/delete'] ?? '') === 'bakeshop:bakeshopApiProductsDelete');
bt('ingredients batch-delete route declared', ($routes['POST']['/api/v1/bakeshop/ingredients/batch-delete'] ?? '') === 'bakeshop:bakeshopApiIngredientsBatchDelete');
bt('ingredients delete route declared', ($routes['POST']['/api/v1/bakeshop/ingredients/delete'] ?? '') === 'bakeshop:bakeshopApiIngredientsDelete');
bt('deliveries batch-delete route declared', ($routes['POST']['/api/v1/bakeshop/deliveries/batch-delete'] ?? '') === 'bakeshop:bakeshopApiDeliveriesBatchDelete');
bt('logo upload route declared', ($routes['POST']['/api/v1/bakeshop/settings/logo'] ?? '') === 'bakeshop:bakeshopApiSettingsUploadLogo');
bt('allocations store route declared', ($routes['POST']['/api/v1/bakeshop/allocations'] ?? '') === 'bakeshop:bakeshopApiAllocationsStore');
bt('allocations delete route declared', ($routes['POST']['/api/v1/bakeshop/allocations/delete'] ?? '') === 'bakeshop:bakeshopApiAllocationsDelete');
bt('adjustments store route declared', ($routes['POST']['/api/v1/bakeshop/adjustments'] ?? '') === 'bakeshop:bakeshopApiAdjustmentsStore');
bt('adjustments delete route declared', ($routes['POST']['/api/v1/bakeshop/adjustments/delete'] ?? '') === 'bakeshop:bakeshopApiAdjustmentsDelete');
bt('production import route declared', ($routes['POST']['/api/v1/bakeshop/production/import'] ?? '') === 'bakeshop:bakeshopApiProductionImport');
bt('products import route declared', ($routes['POST']['/api/v1/bakeshop/products/import'] ?? '') === 'bakeshop:bakeshopApiProductsImport');
bt('recipes import route declared', ($routes['POST']['/api/v1/bakeshop/recipes/import'] ?? '') === 'bakeshop:bakeshopApiRecipesImport');
bt('delivery store route declared', ($routes['POST']['/api/v1/bakeshop/deliveries'] ?? '') === 'bakeshop:bakeshopApiDeliveriesStore');

echo "\n── Helpers ──\n";
$helpersFile = BASE_PATH . '/modules/bakeshop/helpers.php';
bt('helpers.php exists', is_file($helpersFile));
require_once $helpersFile;
require_once BASE_PATH . '/modules/bakeshop/handlers.php';
bt('bakeshopCtx function exists', function_exists('bakeshopCtx'));
bt('bakeshopDb function exists', function_exists('bakeshopDb'));
bt('bakeshopInput function exists', function_exists('bakeshopInput'));
bt('bakeshopRender function exists', function_exists('bakeshopRender'));
bt('bakeshopRolePermissions function exists', function_exists('bakeshopRolePermissions'));
bt('bakeshopRoleHasPermission function exists', function_exists('bakeshopRoleHasPermission'));
bt('bakeshopLoginPageContext function exists', function_exists('bakeshopLoginPageContext'));
bt('bakeshop auth provider function exists', function_exists('bakeshop_cap_kernel_auth_authenticate_1'));
bt('bakeshop unsafe bootstrap password helper exists', function_exists('bakeshopUserHasUnsafeBootstrapPassword'));
bt('bakeshop capability handler export exists', function_exists('bakeshop_capability_handlers'));
bt('bakeshop bootstrap onboarding helper exists', function_exists('bakeshopBootstrapOnboardingState'));
bt('bakeshop bootstrap redirect helper exists', function_exists('bakeshopShouldForceBootstrapOnboarding'));
bt('bakeshop forgot password page function exists', function_exists('bakeshopForgotPasswordPage'));
bt('bakeshop reset password page function exists', function_exists('bakeshopResetPasswordPage'));
bt('bakeshop forgot password API function exists', function_exists('bakeshopApiForgotPassword'));
bt('bakeshop reset password API function exists', function_exists('bakeshopApiResetPassword'));
bt('bakeshop supervisor workspace renderer exists', function_exists('bakeshopRenderSupervisorWorkspace'));
bt('bakeshop history page function exists', function_exists('bakeshopPageHistory'));
bt('bakeshop users page function exists', function_exists('bakeshopPageUsers'));
bt('bakeshop account page function exists', function_exists('bakeshopPageAccount'));
bt('bakeshop settings page function exists', function_exists('bakeshopPageSettings'));

try {
    $userFocusHtml = bakeshopRender('pages/users.disyl', bakeshopPageContext([
        'id' => 1,
        'username' => 'bakeshopadmin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ], 'users', [
        'page_title' => 'Bakeshop Staff',
        'current_user_id' => 1,
        'bootstrap_onboarding' => bakeshopBootstrapOnboardingState(),
        'users' => [[
            'id' => 5,
            'username' => 'focususer',
            'email' => 'focus@example.test',
            'phone' => '',
            'full_name' => 'Focus User',
            'role' => 'supervisor',
            'is_active' => 1,
            'created_at' => '2026-04-27 00:00:00',
        ]],
        'active_users' => [[
            'id' => 5,
            'username' => 'focususer',
            'email' => 'focus@example.test',
            'phone' => '',
            'full_name' => 'Focus User',
            'role' => 'supervisor',
            'is_active' => 1,
            'created_at' => '2026-04-27 00:00:00',
        ]],
        'inactive_users' => [],
    ]));
    bt('users template exposes focus user card target', str_contains($userFocusHtml, 'data-user-card-id="5"'));
    bt('users template reads focus_user_id query param', str_contains($userFocusHtml, "searchParams.get('focus_user_id')"));
    bt('users template exposes a local focus review note', str_contains($userFocusHtml, 'data-user-focus-note="5"'));
    bt('users template focuses the first field for a focused staff card', str_contains($userFocusHtml, 'focusField.focus({ preventScroll: true })'));
} catch (Throwable $e) {
    bt('users template renders focus targeting hooks', false, $e->getMessage());
}

echo "\n── Templates ──\n";
$templateBase = BASE_PATH . '/templates/modules/bakeshop/pages';
bt('supervisor template exists', is_file($templateBase . '/supervisor.disyl'));
bt('print summary template exists', is_file($templateBase . '/print-summary.disyl'));
bt('users template exists', is_file($templateBase . '/users.disyl'));
bt('account template exists', is_file($templateBase . '/account.disyl'));
bt('settings template exists', is_file($templateBase . '/settings.disyl'));
bt('history template exists', is_file($templateBase . '/history.disyl'));
bt('forgot password template exists', is_file($templateBase . '/forgot-password.disyl'));
bt('reset password template exists', is_file($templateBase . '/reset-password.disyl'));
$layoutBase = BASE_PATH . '/templates/modules/bakeshop/layouts';
bt('bakeshop app layout exists', is_file($layoutBase . '/app.disyl'));
bt('bakeshop print layout exists', is_file($layoutBase . '/print.disyl'));

try {
    $printSummaryHtml = bakeshopRender('pages/print-summary.disyl', [
        'page_title' => 'Printable Bakeshop Summary',
        'brand_settings' => bakeshopBrandSettings(),
        'filters' => ['branch_id' => null, 'from_date' => null, 'to_date' => null, 'supplier' => null, 'ingredient_ids' => []],
        'branch_filter_options' => [],
        'supplier_options' => [],
        'ingredient_options' => [],
        'branch_scope_label' => 'All branches with activity',
        'supplier_scope_label' => 'All suppliers',
        'ingredient_scope_label' => 'All ingredients',
        'branches' => [],
        'summary_groups' => [],
        'factual_summary' => ['ingredient_count' => 0, 'delivery_item_count' => 0, 'production_run_count' => 0, 'inventory_on_hand_qty_base' => '0.00'],
        'display_from_date' => null,
        'display_to_date' => null,
        'usage_decimal_places' => 2,
        'print_template_label' => 'Standard Template',
        'output_summary_label' => 'Rounded to 2 decimal places',
    ]);
    bt('print summary template exposes filter controls', str_contains($printSummaryHtml, 'name="branch_id"') && str_contains($printSummaryHtml, 'name="supplier"') && str_contains($printSummaryHtml, 'name="ingredient_ids[]"') && str_contains($printSummaryHtml, 'Apply Filters'));
    bt('print summary template supports live scoped filter refresh', str_contains($printSummaryHtml, '/api/v1/bakeshop/usage') && str_contains($printSummaryHtml, 'print-filter-status') && str_contains($printSummaryHtml, 'print-ingredient-modal'));
    bt('print summary template exposes all-branches reset flow', str_contains($printSummaryHtml, 'All branches') && str_contains($printSummaryHtml, '/admin/bakeshop/print'));
} catch (Throwable $e) {
    bt('print summary template exposes filter controls', false, $e->getMessage());
    bt('print summary template supports live scoped filter refresh', false, $e->getMessage());
    bt('print summary template exposes all-branches reset flow', false, $e->getMessage());
}

try {
    $loginHtml = app()->render('pages/login.disyl', bakeshopLoginPageContext());
    bt('login template renders bakeshop brand', str_contains($loginHtml, "Julie&#039;s Bakeshop") || str_contains($loginHtml, "Julie's Bakeshop"));
    bt('login template renders bakeshop CTA', str_contains($loginHtml, 'Enter Bakeshop'));
    bt('login template renders store description subtitle', str_contains($loginHtml, 'Production, deliveries, recipes, staff access, and daily reporting inside the bakeshop module.'));
    bt('login template renders bakeshop helper title', str_contains($loginHtml, 'After You Sign In'));
    bt('login template posts to bakeshop auth route', str_contains($loginHtml, '/bakeshop/auth/login'));
    bt('login template renders forgot password link', str_contains($loginHtml, '/bakeshop/forgot-password'));
} catch (Throwable $e) {
    bt('login template renders with bakeshop context', false, $e->getMessage());
}

try {
    $user = [
        'id' => 1,
        'username' => 'bakeshopadmin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ];
    $shellHtml = bakeshopRender('pages/supervisor.disyl', bakeshopPageContext($user, 'workspace', [
        'stats' => ['products' => 0, 'ingredients' => 0, 'recipes' => 0, 'units' => 0],
        'units' => [],
        'sections' => [],
        'current_user_id' => 1,
        'can_manage_settings' => true,
        'bootstrap_onboarding' => bakeshopBootstrapOnboardingState(),
        'permission_matrix' => [
            'admin' => ['read' => true, 'manage' => true],
            'supervisor' => ['read' => true, 'manage' => false],
        ],
    ]));
    bt('supervisor template uses module-owned shell', str_contains($shellHtml, 'Module-owned workspace UI. This shell no longer inherits the kernel admin navigation.'));
    bt('supervisor template omits kernel admin chrome', !str_contains($shellHtml, 'APPLICATION KERNEL OS'));
    bt('supervisor template reads focus_kind query param', str_contains($shellHtml, "searchParams.get('focus_kind')"));
    bt('supervisor template reuses edit helpers for focused rows', str_contains($shellHtml, 'openFocusedEditor(kind, match)'));
    bt('supervisor template exposes focus notes for edit forms', str_contains($shellHtml, 'branch-focus-note') && str_contains($shellHtml, 'product-focus-note') && str_contains($shellHtml, 'recipe-focus-note'));
    bt('supervisor template defines shared two-decimal display helper', str_contains($shellHtml, 'function displayTwoDecimalQuantity(value, fallback = \'0.00\')'));
    bt('supervisor template defines shared detail list helper', str_contains($shellHtml, 'function renderDetailList(items, renderItem, emptyMessage = \'No detail loaded.\')'));
    bt('supervisor template sets history review note when opening focused editor', str_contains($shellHtml, "Reviewing this product from activity history."));
    bt('supervisor template frames deliveries as daily branch receiving', str_contains($shellHtml, 'Saving posts one daily branch receipt and includes only ingredients with a quantity greater than zero.'));
    bt('supervisor template exposes product multi-delete controls', str_contains($shellHtml, 'products-delete-selected') && str_contains($shellHtml, 'products-select-all'));
    bt('supervisor template separates active and archived products', str_contains($shellHtml, 'products-view-active') && str_contains($shellHtml, 'products-view-archived') && str_contains($shellHtml, 'No archived products yet.'));
    bt('supervisor template exposes ingredient multi-delete controls', str_contains($shellHtml, 'ingredients-delete-selected') && str_contains($shellHtml, 'ingredients-select-all') && str_contains($shellHtml, 'Delete Selected'));
    bt('supervisor template separates active and archived ingredients', str_contains($shellHtml, 'ingredients-view-active') && str_contains($shellHtml, 'ingredients-view-archived') && str_contains($shellHtml, 'No archived ingredients yet.'));
    bt('supervisor template exposes delivery multi-delete controls', str_contains($shellHtml, 'deliveries-delete-selected') && str_contains($shellHtml, 'deliveries-select-all'));
    bt('supervisor template separates daily receiving and saved receipts', str_contains($shellHtml, 'data-delivery-section-tab="receive"') && str_contains($shellHtml, 'data-delivery-section-tab="saved"') && str_contains($shellHtml, 'Daily Receiving') && str_contains($shellHtml, 'Saved Receipts'));
    bt('supervisor template exposes saved receipt detail toggle', str_contains($shellHtml, 'data-action="toggle-delivery-detail"') && str_contains($shellHtml, 'View Details') && str_contains($shellHtml, 'renderDeliveryDetail(row)'));
    bt('supervisor template previews bulk delete targets before confirmation', str_contains($shellHtml, 'bakeshop-delete-confirm') && str_contains($shellHtml, 'confirmBulkDelete({'));
    bt('supervisor template guides in-use products toward archive instead of delete', str_contains($shellHtml, 'Products already used in saved batches can be archived but not deleted.') && str_contains($shellHtml, 'Used by ') && str_contains($shellHtml, 'saved batch'));
    bt('supervisor template guides in-use ingredients toward archive instead of delete', str_contains($shellHtml, 'In use, archive instead') && str_contains($shellHtml, 'Ingredients already in use can be archived but not deleted.'));
    bt('supervisor template forces cache-safe reloads after list mutations', str_contains($shellHtml, 'await loadIngredients(true);') && str_contains($shellHtml, 'await loadProducts(true);') && str_contains($shellHtml, 'await loadDeliveries(true);') && str_contains($shellHtml, 'deleteIngredientsByIds') && str_contains($shellHtml, 'deleteProductsByIds') && str_contains($shellHtml, 'deleteDeliveriesByIds'));
    bt('supervisor template merges summary and usage report scope', str_contains($shellHtml, 'branch_id: fromUsage.branch_id || fromSummary.branch_id') && str_contains($shellHtml, 'const scope = currentReportScope();') && str_contains($shellHtml, 'const fromSummary = {'));
    bt('supervisor template exposes delivery CSV import controls', str_contains($shellHtml, 'delivery-csv-file') && str_contains($shellHtml, 'Download CSV Template'));
    bt('supervisor template lists a daily ingredient delivery sheet', str_contains($shellHtml, 'Every ingredient is listed below in its default unit.') && str_contains($shellHtml, 'cost_basis'));
    bt('supervisor template frames usage as inventory movement', str_contains($shellHtml, 'Read the inventory movement view: received deliveries minus recipe-based production consumption'));
    bt('supervisor template exposes factual summary usage counters', str_contains($shellHtml, 'usage-factual-ingredients') && str_contains($shellHtml, 'usage-factual-deliveries') && str_contains($shellHtml, 'usage-factual-runs'));
    bt('supervisor template exposes as-of-date inventory panel', str_contains($shellHtml, 'Ingredient Inventory As Of Scope End'));
    bt('supervisor template explains end-date inventory snapshot scope', str_contains($shellHtml, 'The branch filter always applies, and if only a from date is set it is used as the as-of date.'));
    bt('supervisor template uses inline autosave qty entry for production products', str_contains($shellHtml, 'data-production-qty-input') && str_contains($shellHtml, 'Choose the branch, finished time, and who made it.') && str_contains($shellHtml, '<th>Amount Made</th>') && str_contains($shellHtml, 'function normalizeOptionalTwoDecimalQuantity(value)') && str_contains($shellHtml, 'Saving entry...') && str_contains($shellHtml, 'Blank = no production') && str_contains($shellHtml, 'Add ingredients for this item first.') && str_contains($shellHtml, 'Testing Override: Off') && str_contains($shellHtml, 'relax_guards') && str_contains($shellHtml, 'Saved Entries') && str_contains($shellHtml, 'Last Saved Entry'));
    bt('supervisor template exposes production void flow', str_contains($shellHtml, 'production/void') && str_contains($shellHtml, 'Void this saved entry and remove its ingredient use from usage and inventory?') && str_contains($shellHtml, 'void-production'));
    bt('supervisor template exposes production entry review flow', str_contains($shellHtml, 'Update Saved Entry') && str_contains($shellHtml, 'Reviewing a saved entry.') && str_contains($shellHtml, 'edit-production'));
} catch (Throwable $e) {
    bt('supervisor template renders in bakeshop shell', false, $e->getMessage());
}

echo "\n── Sidebar Link Coverage ──\n";
// Verify every internal bakeshop sidebar link resolves to a registered GET route
// Check the rendered supervisor HTML for sidebar navigation links
$getRoutes = array_keys($routes['GET'] ?? []);

// Known sidebar links that should be rendered in the bakeshop shell
$sidebarLinks = [
    '/admin/bakeshop' => 'dashboard',
    '/admin/bakeshop/branches' => 'branches',
    '/admin/bakeshop/catalog' => 'catalog',
    '/admin/bakeshop/ingredients' => 'ingredients',
    '/admin/bakeshop/deliveries' => 'deliveries',
    '/admin/bakeshop/production' => 'production',
    '/admin/bakeshop/usage' => 'usage',
    '/admin/bakeshop/history' => 'history',
    '/admin/bakeshop/users' => 'users',
    '/admin/bakeshop/account' => 'account',
    '/admin/bakeshop/settings' => 'settings',
    '/admin/bakeshop/print' => 'print summary',
    '/admin/bakeshop/ledger' => 'product ledger',
    '/admin/bakeshop/coverage' => 'product coverage',
];
$sidebarLinkFailures = [];
foreach ($sidebarLinks as $link => $label) {
    $found = false;
    foreach ($getRoutes as $getRoute) {
        if ($getRoute === $link || (str_ends_with($getRoute, '}') && str_starts_with($link, rtrim($getRoute, '}')))) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $sidebarLinkFailures[] = "{$link} ({$label})";
    }
}
bt('every sidebar link resolves to a registered GET route', $sidebarLinkFailures === [], implode(', ', $sidebarLinkFailures));

// Check rendered supervisor HTML for sidebar navigation presence
bt('supervisor template renders sidebar navigation links', str_contains($shellHtml, 'bakeshop-nav') || str_contains($shellHtml, 'sidebar-nav') || str_contains($shellHtml, 'bakeshop-sidebar'));
bt('supervisor template references branches in navigation', str_contains($shellHtml, 'branches') || str_contains($shellHtml, 'Branches'));
bt('supervisor template references catalog in navigation', str_contains($shellHtml, 'catalog') || str_contains($shellHtml, 'Products'));
bt('supervisor template references production in navigation', str_contains($shellHtml, 'production') || str_contains($shellHtml, 'Production'));
bt('supervisor template references usage in navigation', str_contains($shellHtml, 'usage') || str_contains($shellHtml, 'Usage'));

echo "\n── Migration ──\n";
$migrationPath = BASE_PATH . '/modules/bakeshop/database/migrations/001_bakeshop_core.sql';
bt('migration exists', is_file($migrationPath));
$migration = (string) file_get_contents($migrationPath);
bt('migration creates bakeshop_branches', str_contains($migration, 'CREATE TABLE IF NOT EXISTS `bakeshop_branches`'));
bt('migration defines usage view', str_contains($migration, 'CREATE OR REPLACE VIEW `bakeshop_ingredient_usage`'));
bt('migration defines production void columns', str_contains($migration, '`voided_at` DATETIME NULL') && str_contains($migration, '`void_reason` TEXT NULL'));
$productionVoidMigrationPath = BASE_PATH . '/modules/bakeshop/database/migrations/008_bakeshop_production_voiding.sql';
bt('production void migration exists', is_file($productionVoidMigrationPath));
$productionVoidMigration = (string) file_get_contents($productionVoidMigrationPath);
bt('production void migration rebuilds usage view with void exclusion', str_contains($productionVoidMigration, 'ALTER TABLE bakeshop_production_runs ADD COLUMN voided_at DATETIME NULL AFTER notes') && str_contains($productionVoidMigration, 'WHERE pr.`voided_at` IS NULL'));
$userMigrationPath = BASE_PATH . '/modules/bakeshop/database/migrations/002_bakeshop_users.sql';
bt('user migration exists', is_file($userMigrationPath));
$userMigration = (string) file_get_contents($userMigrationPath);
bt('user migration creates bakeshop_users', str_contains($userMigration, 'CREATE TABLE IF NOT EXISTS `bakeshop_users`'));
bt(
    'user migration normalizes cms bootstrap admin',
    str_contains($userMigration, '@bakeshop002_has_cms_users')
        && str_contains($userMigration, 'cmsadmin')
        && str_contains($userMigration, 'bakeshopadmin')
);
bt('user migration no longer ships the legacy shared bootstrap hash', !str_contains($userMigration, '92IXUNpkjO0rOQ5byMi'));
bt('user migration seeds bootstrap password reset marker', str_contains($userMigration, '!bakeshop-bootstrap-password-reset-required!'));
$bootstrapMigrationPath = BASE_PATH . '/modules/bakeshop/database/migrations/003_bakeshop_bootstrap_admin.sql';
bt('bootstrap admin migration exists', is_file($bootstrapMigrationPath));
$tokenVersionMigrationPath = BASE_PATH . '/modules/bakeshop/database/migrations/004_bakeshop_user_token_version.sql';
bt('token version migration exists', is_file($tokenVersionMigrationPath));
$tokenVersionMigration = (string) file_get_contents($tokenVersionMigrationPath);
bt('token version migration adds bakeshop_users token_version', str_contains($tokenVersionMigration, 'ALTER TABLE bakeshop_users ADD COLUMN token_version'));
$bootstrapPasswordResetMigrationPath = BASE_PATH . '/modules/bakeshop/database/migrations/005_bakeshop_bootstrap_password_reset.sql';
bt('bootstrap password reset migration exists', is_file($bootstrapPasswordResetMigrationPath));
$bootstrapPasswordResetMigration = (string) file_get_contents($bootstrapPasswordResetMigrationPath);
bt('bootstrap password reset migration scrubs legacy shared hash', str_contains($bootstrapPasswordResetMigration, '92IXUNpkjO0rOQ5byMi'));
bt('bootstrap password reset migration applies reset marker', str_contains($bootstrapPasswordResetMigration, '!bakeshop-bootstrap-password-reset-required!'));
$passwordResetMigrationPath = BASE_PATH . '/modules/bakeshop/database/migrations/006_bakeshop_password_resets.sql';
bt('password reset token migration exists', is_file($passwordResetMigrationPath));
$passwordResetMigration = (string) file_get_contents($passwordResetMigrationPath);
bt('password reset token migration creates bakeshop_password_resets', str_contains($passwordResetMigration, 'CREATE TABLE IF NOT EXISTS `bakeshop_password_resets`'));
bt('password reset token migration references bakeshop_users', str_contains($passwordResetMigration, 'FOREIGN KEY (`user_id`) REFERENCES `bakeshop_users`(`id`)'));

echo "\n── Handlers ──\n";
$handlersPath = BASE_PATH . '/modules/bakeshop/handlers/10-pages.php';
bt('page handlers exist', is_file($handlersPath));
$handlerCode = (string) file_get_contents($handlersPath);
bt('page handlers use bakeshopRender', str_contains($handlerCode, 'bakeshopRender('));

$catalogHandlersPath = BASE_PATH . '/modules/bakeshop/handlers/20-api-products-recipe.php';
bt('catalog handlers exist', is_file($catalogHandlersPath));
$catalogHandlerCode = (string) file_get_contents($catalogHandlersPath);
bt('catalog handlers no longer use not implemented placeholders', !str_contains($catalogHandlerCode, 'bakeshopNotImplemented'));
bt('catalog handlers expose units index', str_contains($catalogHandlerCode, 'function bakeshopApiUnitsIndex'));
bt('catalog handlers expose product delete eligibility metadata', str_contains($catalogHandlerCode, 'production_reference_count') && str_contains($catalogHandlerCode, 'AS can_delete'));
bt('catalog handlers expose ingredient delete eligibility metadata', str_contains($catalogHandlerCode, 'AS can_delete') && str_contains($catalogHandlerCode, 'recipe_reference_count'));

$deliveryHandlersPath = BASE_PATH . '/modules/bakeshop/handlers/30-api-deliveries.php';
bt('delivery handlers exist', is_file($deliveryHandlersPath));
$deliveryHandlerCode = (string) file_get_contents($deliveryHandlersPath);
bt('delivery handlers no longer use not implemented placeholders', !str_contains($deliveryHandlerCode, 'bakeshopNotImplemented'));
bt('delivery handlers expose branches index', str_contains($deliveryHandlerCode, 'function bakeshopApiBranchesIndex'));

$productionHandlersPath = BASE_PATH . '/modules/bakeshop/handlers/40-api-production.php';
bt('production handlers exist', is_file($productionHandlersPath));
$productionHandlerCode = (string) file_get_contents($productionHandlersPath);
bt('production handlers no longer use not implemented placeholders', !str_contains($productionHandlerCode, 'bakeshopNotImplemented'));
bt('production handlers snapshot recipe items', str_contains($productionHandlerCode, 'INSERT INTO bakeshop_production_items'));

echo "\n── Logs ──\n";
$appLogRaw = (string) @file_get_contents($appLogPath);
$errorLogRaw = (string) @file_get_contents($errorLogPath);
$appLog = trim($appLogStart > 0 ? (string)substr($appLogRaw, $appLogStart) : $appLogRaw);
$errorLog = trim($errorLogStart > 0 ? (string)substr($errorLogRaw, $errorLogStart) : $errorLogRaw);
bt('No errors in app.log', $appLog === '' || !str_contains(strtolower($appLog), 'error'));
bt('No errors in error.log', $errorLog === '');

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