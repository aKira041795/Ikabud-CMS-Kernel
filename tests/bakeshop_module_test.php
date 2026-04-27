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
bt('role_permissions setting declared', array_reduce($manifest['settings_fields'] ?? [], static function (bool $carry, array $field): bool {
    return $carry || (($field['key'] ?? '') === 'role_permissions');
}, false));
bt('module.json lists bootstrap admin migration', in_array('database/migrations/003_bakeshop_bootstrap_admin.sql', $manifest['migrations'] ?? [], true));
bt('module.json lists token version migration', in_array('database/migrations/004_bakeshop_user_token_version.sql', $manifest['migrations'] ?? [], true));
bt('module.json lists bootstrap password reset migration', in_array('database/migrations/005_bakeshop_bootstrap_password_reset.sql', $manifest['migrations'] ?? [], true));

echo "\n── Discovery ──\n";
$all = discoverModules();
bt('Module discovered by kernel', isset($all['bakeshop']));

echo "\n── Routes ──\n";
$routesFile = BASE_PATH . '/modules/bakeshop/routes.php';
bt('routes.php exists', is_file($routesFile));
$routes = require $routesFile;
bt('routes.php returns array', is_array($routes));
bt('login page route declared', ($routes['GET']['/bakeshop/login'] ?? '') === 'bakeshop:bakeshopPageLogin');
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
bt('account password API route declared', ($routes['POST']['/api/v1/bakeshop/account/password'] ?? '') === 'bakeshop:bakeshopApiAccountPasswordUpdate');
bt('create user API route declared', ($routes['POST']['/api/v1/bakeshop/users'] ?? '') === 'bakeshop:bakeshopApiUserCreate');
bt('settings permissions API route declared', ($routes['POST']['/api/v1/bakeshop/settings/permissions'] ?? '') === 'bakeshop:bakeshopApiSettingsSavePermissions');
bt('settings display API route declared', ($routes['POST']['/api/v1/bakeshop/settings/display'] ?? '') === 'bakeshop:bakeshopApiSettingsSaveDisplay');
bt('branch status API route declared', ($routes['POST']['/api/v1/bakeshop/branches/{id}/status'] ?? '') === 'bakeshop:bakeshopApiBranchesStatusUpdate');
bt('product status API route declared', ($routes['POST']['/api/v1/bakeshop/products/{id}/status'] ?? '') === 'bakeshop:bakeshopApiProductsStatusUpdate');
bt('ingredient status API route declared', ($routes['POST']['/api/v1/bakeshop/ingredients/{id}/status'] ?? '') === 'bakeshop:bakeshopApiIngredientsStatusUpdate');
bt('recipe delete API route declared', ($routes['POST']['/api/v1/bakeshop/recipes/delete'] ?? '') === 'bakeshop:bakeshopApiRecipesDelete');
bt('delivery delete API route declared', ($routes['POST']['/api/v1/bakeshop/deliveries/delete'] ?? '') === 'bakeshop:bakeshopApiDeliveriesDelete');
bt('production delete API route declared', ($routes['POST']['/api/v1/bakeshop/production/delete'] ?? '') === 'bakeshop:bakeshopApiProductionDelete');

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
$layoutBase = BASE_PATH . '/templates/modules/bakeshop/layouts';
bt('bakeshop app layout exists', is_file($layoutBase . '/app.disyl'));
bt('bakeshop print layout exists', is_file($layoutBase . '/print.disyl'));

try {
    $loginHtml = app()->render('pages/login.disyl', bakeshopLoginPageContext());
    bt('login template renders bakeshop brand', str_contains($loginHtml, "Julie&#039;s Bakeshop") || str_contains($loginHtml, "Julie's Bakeshop"));
    bt('login template renders bakeshop CTA', str_contains($loginHtml, 'Enter Bakeshop'));
    bt('login template renders bakeshop subtitle', str_contains($loginHtml, 'Sign in with your bakeshop staff account to manage recipes, deliveries, production, and daily usage.'));
    bt('login template renders bakeshop helper title', str_contains($loginHtml, 'After You Sign In'));
    bt('login template posts to bakeshop auth route', str_contains($loginHtml, '/bakeshop/auth/login'));
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
    bt('supervisor template sets history review note when opening focused editor', str_contains($shellHtml, "Reviewing this product from activity history."));
    bt('supervisor template frames deliveries as daily branch receiving', str_contains($shellHtml, 'Saving posts one daily branch receipt and includes only ingredients with a quantity greater than zero.'));
    bt('supervisor template exposes delivery CSV import controls', str_contains($shellHtml, 'delivery-csv-file') && str_contains($shellHtml, 'Download CSV Template'));
    bt('supervisor template lists a daily ingredient delivery sheet', str_contains($shellHtml, 'Every ingredient is listed below in its default unit.'));
    bt('supervisor template frames usage as inventory movement', str_contains($shellHtml, 'Read the inventory movement view: received deliveries minus recipe-based production consumption'));
    bt('supervisor template exposes current ingredient inventory panel', str_contains($shellHtml, 'Current Ingredient Inventory'));
    bt('supervisor template explains branch-only inventory snapshot scope', str_contains($shellHtml, 'The branch filter applies here; the date range does not.'));
} catch (Throwable $e) {
    bt('supervisor template renders in bakeshop shell', false, $e->getMessage());
}

echo "\n── Migration ──\n";
$migrationPath = BASE_PATH . '/modules/bakeshop/database/migrations/001_bakeshop_core.sql';
bt('migration exists', is_file($migrationPath));
$migration = (string) file_get_contents($migrationPath);
bt('migration creates bakeshop_branches', str_contains($migration, 'CREATE TABLE IF NOT EXISTS `bakeshop_branches`'));
bt('migration defines usage view', str_contains($migration, 'CREATE OR REPLACE VIEW `bakeshop_ingredient_usage`'));
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