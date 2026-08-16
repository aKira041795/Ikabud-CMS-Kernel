<?php

declare(strict_types=1);

/**
 * Moto Inventory — Manifest / Schema Contract Test (pure, no DB).
 *
 * Verifies: exact module id, valid JSON, owned tables, declared migrations,
 * capability handler names, no invalid kernel dependencies, navigation
 * routes registered, and settings defaults.
 *
 * Run: php tests/moto_inventory_manifest_test.php
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/moto_inventory_test_helper.php';

// App bootstrap MUST run in global scope for $config visibility.
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/src/http/tenant-entry-modules.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/helpers.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/handlers.php';

$h = new TestHarness('moto-inventory-manifest', TestHarness::MODE_PURE);

$base = $h->basePath();
$h->fingerprint('modules/moto-inventory/module.json');
$h->fingerprint('modules/moto-inventory/routes.php');
$h->fingerprint('modules/moto-inventory/helpers.php');

$manifestPath = $base . '/modules/moto-inventory/module.json';
$manifestRaw = (string)file_get_contents($manifestPath);
$manifest = json_decode($manifestRaw, true);

$h->section('Manifest — identity & JSON');

$h->test('module.json is valid JSON', is_array($manifest), json_last_error_msg());
if (!is_array($manifest)) {
    $h->gap('Cannot validate manifest fields — JSON invalid');
    $h->done();
    // unreachable in practice
}
$h->test('exact module id is moto-inventory', ($manifest['id'] ?? '') === 'moto-inventory');
$h->test('name is Moto Inventory', ($manifest['name'] ?? '') === 'Moto Inventory');
$h->test('version is set', !empty($manifest['version']));
$h->test('routes file declared', ($manifest['routes'] ?? '') === 'routes.php');
$h->test('handlers file declared', ($manifest['handlers'] ?? '') === 'handlers.php');

$h->section('Manifest — tenant entry-module contract');

$h->test('entry_module is true (selectable as tenant entry module)', ($manifest['entry_module'] ?? false) === true);
$h->test('auth_owned is NOT set (kernel auth is the identity authority)', empty($manifest['auth_owned']));
$h->test('auth_cookie is NOT set', empty($manifest['auth_cookie']));
$h->test('type is not service-module', ($manifest['type'] ?? 'module') !== 'service-module');

$motoRoutes = require $base . '/modules/moto-inventory/routes.php';
$motoGet = $motoRoutes['GET'] ?? [];
$h->test('GET /moto-inventory/login route registered (entry auth contract)', isset($motoGet['/moto-inventory/login']) && is_string($motoGet['/moto-inventory/login']));
$h->test('motoPageLogin handler is callable', function_exists('motoPageLogin'));

$layoutPath = $base . '/templates/modules/moto-inventory/layouts/app.disyl';
$layout = (string)file_get_contents($layoutPath);
$h->test('layout sign-out links to kernel canonical /auth/logout (module convention)', str_contains($layout, 'class="mi-link" href="/auth/logout"'));
$h->test('layout sign-out does NOT link to /login', !str_contains($layout, 'class="mi-link" href="/login"'));

$h->section('Manifest — branded login context');

$h->test('moto_inventoryLoginPageContext is callable (branded login)', function_exists('moto_inventoryLoginPageContext'));
$motoLoginCtx = function_exists('moto_inventoryLoginPageContext') ? moto_inventoryLoginPageContext() : [];
$h->test('login context posts to stateless /api/v1/auth/login (no CSRF pre-auth)', ($motoLoginCtx['login_endpoint'] ?? '') === '/api/v1/auth/login');
$h->test('login context prefers kernel auth source', ($motoLoginCtx['login_preferred_source'] ?? '') === 'kernel');
$h->test('login context is Moto-branded (gui + logo)', (($motoLoginCtx['gui']['app_name'] ?? '') === 'Moto Inventory') && !empty($motoLoginCtx['login_logo_html']));
$h->test('login context has forgot-password url', (($motoLoginCtx['login_forgot_url'] ?? '') !== ''));
$h->test('entry module eligibility helper lists moto-inventory', (function (): bool {
    if (!function_exists('listTenantEntryModuleOptions')) {
        return false;
    }
    foreach (listTenantEntryModuleOptions() as $option) {
        if (($option['id'] ?? '') === 'moto-inventory') {
            return true;
        }
    }
    return false;
})());

$h->section('Manifest — owned tables & migrations');

$owned = $manifest['owns_tables'] ?? [];
$expectedTables = [
    'moto_branches', 'moto_user_branches', 'moto_user_roles', 'moto_user_profiles', 'moto_brands', 'moto_products',
    'moto_stock_movements', 'moto_sales', 'moto_sale_items', 'moto_imports',
    'moto_import_rows', 'moto_audit_log', 'moto_idempotency_keys',
    'moto_preferences', 'moto_backups',
];
$h->test('owns_tables is an array', is_array($owned));
foreach ($expectedTables as $t) {
    $h->test("owns_tables contains {$t}", is_array($owned) && in_array($t, $owned, true));
}
$h->test('all owned tables prefixed moto_', count(array_filter(
    is_array($owned) ? $owned : [],
    static fn (string $t): bool => str_starts_with($t, 'moto_')
)) === count(is_array($owned) ? $owned : []));

// Kernel `users` is kernel-owned and must NOT be claimed by this module
// (manifest guard rejects owning a kernel table). User administration reaches
// it through src/helpers/kernel-users-admin.php (kernel escalation).
$coOwned = $manifest['co_owns_tables'] ?? [];
$h->test('co_owns_tables is an array', is_array($coOwned));
$declared = array_merge(is_array($owned) ? $owned : [], is_array($coOwned) ? $coOwned : [], is_array($manifest['reads_tables'] ?? null) ? $manifest['reads_tables'] : []);
$h->test('kernel users table not claimed in manifest', !in_array('users', $declared, true));
$h->test('kernel-users-admin helper exists', is_file($base . '/src/helpers/kernel-users-admin.php'));
$h->test('kernelUsersList helper available', function_exists('kernelUsersList') || is_file($base . '/src/helpers/kernel-users-admin.php'));

$migrations = $manifest['migrations'] ?? [];
$h->test('five migrations declared', is_array($migrations) && count($migrations) === 5);
$expectedMigrations = [
    'database/migrations/001_moto_inventory_core.sql',
    'database/migrations/002_moto_inventory_sales_and_movements.sql',
    'database/migrations/003_moto_inventory_import_audit_and_idempotency.sql',
    'database/migrations/004_moto_inventory_user_roles.sql',
    'database/migrations/005_moto_inventory_user_profiles.sql',
];
foreach ($expectedMigrations as $m) {
    $h->test("migration file exists: {$m}", is_file($base . '/modules/moto-inventory/' . $m));
}
$h->test('no MySQL 8 window functions in migrations', !preg_match('/OVER\s*\(/i', (string)file_get_contents($base . '/modules/moto-inventory/database/migrations/001_moto_inventory_core.sql') . file_get_contents($base . '/modules/moto-inventory/database/migrations/002_moto_inventory_sales_and_movements.sql') . file_get_contents($base . '/modules/moto-inventory/database/migrations/003_moto_inventory_import_audit_and_idempotency.sql') . file_get_contents($base . '/modules/moto-inventory/database/migrations/004_moto_inventory_user_roles.sql') . file_get_contents($base . '/modules/moto-inventory/database/migrations/005_moto_inventory_user_profiles.sql')));
$h->test('no CTEs in migrations', !preg_match('/\bWITH\b\s+[A-Za-z_]+/i', (string)file_get_contents($base . '/modules/moto-inventory/database/migrations/002_moto_inventory_sales_and_movements.sql')));

$h->section('Manifest — capabilities');

$capabilities = $manifest['capabilities'] ?? [];
$exposes = is_array($capabilities['exposes'] ?? null) ? $capabilities['exposes'] : [];
$expectedCaps = [
    'moto_inventory.catalog.query@1',
    'moto_inventory.catalog.mutate@1',
    'moto_inventory.stock.query@1',
    'moto_inventory.stock.adjust@1',
    'moto_inventory.sale.complete@1',
    'moto_inventory.sale.void@1',
    'moto_inventory.report.query@1',
    'moto_inventory.import.mutate@1',
    'moto_inventory.export.mutate@1',
    'moto_inventory.audit.query@1',
    'moto_inventory.branch.query@1',
];
foreach ($expectedCaps as $capId) {
    $found = false;
    foreach ($exposes as $cap) {
        if (is_array($cap) && ($cap['id'] ?? '') === $capId) {
            $found = true;
            break;
        }
    }
    $h->test("capability exposed: {$capId}", $found);
}
$h->test('depends is empty (no non-kernel providers)', ($capabilities['depends'] ?? null) === []);

// Capability handler names must be callable functions.
$handlerMap = moto_inventory_capability_handlers();
$h->test('capability handler map defined', is_array($handlerMap) && count($handlerMap) === count($expectedCaps));
foreach ($handlerMap as $capId => $fn) {
    $h->test("handler callable: {$capId} → {$fn}", is_callable($fn));
}

$h->section('Manifest — nav & settings');

$nav = $manifest['nav'] ?? [];
$h->test('nav is an array', is_array($nav));
$navUrls = array_map(static fn (array $n): string => (string)($n['url'] ?? ''), is_array($nav) ? $nav : []);
$routes = require $base . '/modules/moto-inventory/routes.php';
$getRoutes = $routes['GET'] ?? [];
foreach ($navUrls as $url) {
    $h->test("nav route registered in routes.php: {$url}", isset($getRoutes[$url]));
}

$settingsFields = $manifest['settings_fields'] ?? [];
$h->test('settings_fields declared', is_array($settingsFields) && count($settingsFields) >= 3);
$h->test('low_stock_threshold default 5', $settingsFields[0]['key'] === 'low_stock_threshold' && (string)$settingsFields[0]['default'] === '5');
$h->test('undo_window_minutes default 5', (string)$settingsFields[2]['default'] === '5');

$h->section('Manifest — import handler defaults (regression)');

$importHandler = (string)file_get_contents($base . '/modules/moto-inventory/handlers/50-api-import-export.php');
$h->test('stage handler allows server-side auto-mapping (no hard 422 on missing mapping)', !preg_match('/A column mapping is required/', $importHandler));
$h->test('stage handler defaults data_start_row to 1 (skips header row)', str_contains($importHandler, "array_key_exists('data_start_row', \$input)") && str_contains($importHandler, ': 1'));
$h->test('stage handler still forwards an explicit mapping', str_contains($importHandler, 'ImportService::stage('));

$productsApi = (string)file_get_contents($base . '/modules/moto-inventory/services/CatalogService.php');
$h->test('products list API returns brand_id (part edit preselect)', preg_match('/SELECT p\.id, p\.brand_id, p\.part_number/', $productsApi) === 1);

$layoutFile = (string)file_get_contents($base . '/templates/modules/moto-inventory/layouts/app.disyl');
$h->test('shared JS loads before page scripts (no defer)', str_contains($layoutFile, '<script src="/moto-inventory/assets/moto-inventory.js"></script>'));
$h->test('config uses application/json script block (DiSyL-safe)', str_contains($layoutFile, '<script type="application/json" id="mi-config">'));

$h->section('Manifest — permissions model');

$actions = moto_inventory_permission_actions();
foreach (['moto_inventory.manage', 'moto_inventory.sell', 'moto_inventory.void', 'moto_inventory.view_cost', 'moto_inventory.view_profit', 'moto_inventory.view_audit', 'moto_inventory.view_all_branches'] as $p) {
    $h->test("permission declared: {$p}", in_array($p, $actions, true));
}
$defaults = moto_inventory_default_role_permissions();
$h->test('default role permissions map present', is_array($defaults));
$h->test('admin has all permissions', count($defaults['admin'] ?? []) === count($actions));
$h->test('cashier only sells', ($defaults['cashier'] ?? []) === ['moto_inventory.sell']);
$ownerPerms = $defaults['owner'] ?? [];
sort($ownerPerms);
$h->test('owner is read-only with profit/audit/all-branches', $ownerPerms === ['moto_inventory.view_all_branches', 'moto_inventory.view_audit', 'moto_inventory.view_profit']);

$h->section('Manifest — events');

$events = array_map(static fn (array $e): string => (string)($e['key'] ?? ''), is_array($manifest['events'] ?? null) ? $manifest['events'] : []);
foreach (['moto_inventory.sale.completed', 'moto_inventory.sale.voided', 'moto_inventory.import.committed'] as $ev) {
    $h->test("event declared: {$ev}", in_array($ev, $events, true));
}

$h->done();
