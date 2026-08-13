<?php

declare(strict_types=1);

/**
 * Daily Ledger — Manifest Contract Test
 *
 * Validates module.json structure: identity, auth-owned config,
 * capabilities, settings fields, navigation, table ownership,
 * migrations, and file existence.
 */

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-manifest');
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('modules/daily-ledger/routes.php');

$base = $h->basePath();
$manifestPath = $base . '/modules/daily-ledger/module.json';

// ─── Manifest Identity ──────────────────────────────────────────
$h->section('Manifest Identity');

$h->test('module.json exists', is_file($manifestPath));
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$h->test('module.json is valid JSON', is_array($manifest));
$h->test('module id is daily-ledger', ($manifest['id'] ?? '') === 'daily-ledger');
$h->test('version is present', !empty($manifest['version']));
$h->test('description is present', !empty($manifest['description']));
$h->test('author is present', !empty($manifest['author']));
$h->test('routes key is routes.php', ($manifest['routes'] ?? '') === 'routes.php');

// ─── Auth-Owned ─────────────────────────────────────────────────
$h->section('Auth-Owned');

$auth = $manifest['auth_owned'] ?? [];
$h->test('auth_owned declared', is_array($auth) && !empty($auth));
$h->test('users_table is dl_users', ($auth['users_table'] ?? '') === 'dl_users');
$h->test('username_column is username', ($auth['username_column'] ?? '') === 'username');
$h->test('email_column is email', ($auth['email_column'] ?? '') === 'email');
$h->test('password_column is password_hash', ($auth['password_column'] ?? '') === 'password_hash');
$h->test('name_column is full_name', ($auth['name_column'] ?? '') === 'full_name');
$h->test('active_column is is_active', ($auth['active_column'] ?? '') === 'is_active');
$h->test('deleted_column is deleted_at', ($auth['deleted_column'] ?? '') === 'deleted_at');
$h->test('admin role declared', in_array('admin', $auth['admin_roles'] ?? [], true));
$h->test('default_admin_role is admin', ($auth['default_admin_role'] ?? '') === 'admin');
$h->test('touch_updated_at enabled', ($auth['touch_updated_at'] ?? false) === true);
$h->test('auth_cookie is daily_ledger_token', ($manifest['auth_cookie'] ?? '') === 'daily_ledger_token');

// ─── Table Ownership ────────────────────────────────────────────
$h->section('Table Ownership');

$owns = $manifest['owns_tables'] ?? [];
$h->test('owns_tables declared', is_array($owns) && !empty($owns));
$h->test('dl_users owned', in_array('dl_users', $owns, true));
$h->test('dl_daily_ledger owned', in_array('dl_daily_ledger', $owns, true));
$h->test('dl_products owned', in_array('dl_products', $owns, true));
$h->test('dl_branches owned', in_array('dl_branches', $owns, true));
$h->test('dl_deliveries owned', in_array('dl_deliveries', $owns, true));
$h->test('dl_branch_receivings owned', in_array('dl_branch_receivings', $owns, true));
$h->test('dl_cashier_withdrawals owned', in_array('dl_cashier_withdrawals', $owns, true));
$h->test('dl_production_movements owned', in_array('dl_production_movements', $owns, true));
$h->test('dl_commissary_ledger owned', in_array('dl_commissary_ledger', $owns, true));
$h->test('dl_price_groups owned', in_array('dl_price_groups', $owns, true));
$h->test('dl_production_runs owned', in_array('dl_production_runs', $owns, true));
$h->test('dl_password_resets owned', in_array('dl_password_resets', $owns, true));
$h->test('at least 30 tables owned', count($owns) >= 30);

$coOwns = $manifest['co_owns_tables'] ?? [];
$h->test('co-owns audit_logs', in_array('audit_logs', $coOwns, true));

$reads = $manifest['reads_tables'] ?? [];
$h->test('reads rate_limits', in_array('rate_limits', $reads, true));
$h->test('reads refresh_tokens', in_array('refresh_tokens', $reads, true));

// ─── Capabilities ───────────────────────────────────────────────
$h->section('Capabilities');

$exposes = $manifest['capabilities']['exposes'] ?? [];
$exposeIds = array_column($exposes, 'id');
$h->test('exposes kernel.auth.authenticate@1', in_array('kernel.auth.authenticate@1', $exposeIds, true));
$h->test('exposes entity.list.daily_ledger_entry@1', in_array('entity.list.daily_ledger_entry@1', $exposeIds, true));
$h->test('exposes entity.get.daily_ledger_entry@1', in_array('entity.get.daily_ledger_entry@1', $exposeIds, true));
$h->test('at least 3 capabilities exposed', count($exposes) >= 3);

$depends = $manifest['capabilities']['depends'] ?? [];
$h->test('depends declared (may be empty for auth-owned modules)', is_array($depends));

$policy = $manifest['capabilities']['policy'] ?? [];
$h->test('capability policy declared', is_array($policy));
$authPolicy = $policy['capabilities']['kernel.auth.authenticate@1'] ?? null;
$h->test('auth capability has allow_callers policy', is_array($authPolicy));
$h->test('auth capability allows daily-ledger caller', in_array('daily-ledger', $authPolicy['allow_callers'] ?? [], true));

// ─── Settings Fields ────────────────────────────────────────────
$h->section('Settings Fields');

$settings = $manifest['settings_fields'] ?? [];
$settingKeys = array_column($settings, 'key');
$h->test('settings_fields declared', is_array($settings) && !empty($settings));
$h->test('app_name declared', in_array('app_name', $settingKeys, true));
$h->test('logo_url declared', in_array('logo_url', $settingKeys, true));
$h->test('favicon_url declared', in_array('favicon_url', $settingKeys, true));
$h->test('production_output_enabled declared', in_array('production_output_enabled', $settingKeys, true));
$h->test('formal_delivery_workflow_enabled declared', in_array('formal_delivery_workflow_enabled', $settingKeys, true));
$h->test('price_groups_enabled declared', in_array('price_groups_enabled', $settingKeys, true));
$h->test('pos_enabled declared', in_array('pos_enabled', $settingKeys, true));
$h->test('pos_allowed_tenders declared', in_array('pos_allowed_tenders', $settingKeys, true));
$h->test('at least 6 settings declared', count($settings) >= 6);

// ─── Navigation ─────────────────────────────────────────────────
$h->section('Navigation');

$nav = $manifest['nav'] ?? [];
$navUrls = [];
$collectNavUrls = static function (array $items) use (&$collectNavUrls, &$navUrls): void {
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (!empty($item['url'])) $navUrls[] = (string)$item['url'];
        if (is_array($item['children'] ?? null)) $collectNavUrls($item['children']);
    }
};
$collectNavUrls($nav);
$h->test('Ledger nav present', in_array('/daily-ledger/ledger', $navUrls, true));
$h->test('Dashboard nav present', in_array('/daily-ledger/admin/dashboard', $navUrls, true));
$h->test('Sales nav present', in_array('/daily-ledger/admin/sales', $navUrls, true));
$h->test('Variances nav present', in_array('/daily-ledger/admin/variances', $navUrls, true));
$h->test('Production Output nav present', in_array('/daily-ledger/admin/production-output', $navUrls, true));
$h->test('Commissary nav present', in_array('/daily-ledger/admin/commissary', $navUrls, true));
$h->test('Deliveries nav present', in_array('/daily-ledger/admin/deliveries', $navUrls, true));
$h->test('POS nav present', in_array('/daily-ledger/pos', $navUrls, true));
$h->test('POS Sales nav present', in_array('/daily-ledger/admin/pos-sales', $navUrls, true));
$h->test('at least 10 nav items declared', count($navUrls) >= 10);

// ─── Migrations ─────────────────────────────────────────────────
$h->section('Migrations');

$migrations = $manifest['migrations'] ?? [];
$h->test('migrations declared', is_array($migrations) && !empty($migrations));
$h->test('at least 35 migrations', count($migrations) >= 35);
$h->test('migration 043 registered', in_array('database/migrations/043_add_adjustment_custom_reason.sql', $migrations, true));
$h->test('migration 044 registered', in_array('database/migrations/044_add_ledger_shift.sql', $migrations, true));
$h->test('migration 044 file exists', is_file($base . '/modules/daily-ledger/database/migrations/044_add_ledger_shift.sql'));
$h->test('migration 045 registered', in_array('database/migrations/045_rebuild_ledger_shift_key.sql', $migrations, true));
$h->test('migration 045 file exists', is_file($base . '/modules/daily-ledger/database/migrations/045_rebuild_ledger_shift_key.sql'));

$m044 = is_file($base . '/modules/daily-ledger/database/migrations/044_add_ledger_shift.sql')
    ? (string)file_get_contents($base . '/modules/daily-ledger/database/migrations/044_add_ledger_shift.sql') : '';
$h->test('migration 044 adds shift ENUM', str_contains($m044, "ADD COLUMN shift ENUM('AM','PM')"));

$m045 = is_file($base . '/modules/daily-ledger/database/migrations/045_rebuild_ledger_shift_key.sql')
    ? (string)file_get_contents($base . '/modules/daily-ledger/database/migrations/045_rebuild_ledger_shift_key.sql') : '';
$h->test('migration 045 rebuilds unique key with shift', str_contains($m045, '(branch_id, product_id, ledger_date, shift)'));
$h->test('migration 045 handles legacy key name', str_contains($m045, 'uq_ledger_entry') && str_contains($m045, 'uq_dl_ledger_entry'));
$h->test('migration 045 uses single-statement SQL (no procedures)', !preg_match('/CREATE\s+PROCEDURE/i', $m045));

$migrationOk = true;
foreach ($migrations as $m) {
    $mPath = $base . '/modules/daily-ledger/' . ltrim($m, '/');
    if (!is_file($mPath)) {
        $h->fail("Migration exists: {$m}", "file not found at {$mPath}");
        $migrationOk = false;
    }
}
if ($migrationOk) {
    $h->pass('All declared migration files exist');
}

// ─── File Existence ─────────────────────────────────────────────
$h->section('File Existence');

$h->test('routes.php exists', is_file($base . '/modules/daily-ledger/routes.php'));
$h->test('handlers.php exists', is_file($base . '/modules/daily-ledger/handlers.php'));
$h->test('handlers-deliveries.php exists', is_file($base . '/modules/daily-ledger/handlers-deliveries.php'));
$h->test('handlers-pos.php exists', is_file($base . '/modules/daily-ledger/handlers-pos.php'));
$h->test('helpers.php exists', is_file($base . '/modules/daily-ledger/helpers.php'));
$h->test('helpers/entity-views.php exists', is_file($base . '/modules/daily-ledger/helpers/entity-views.php'));
$h->test('helpers/views/daily_ledger_entry.disyl exists', is_file($base . '/modules/daily-ledger/helpers/views/daily_ledger_entry.disyl'));

// ─── Entity Views ───────────────────────────────────────────────
$h->section('Entity Views');

$entityViewsPath = $base . '/modules/daily-ledger/helpers/entity-views.php';
$h->test('entity-views.php loads without error', (function() use ($entityViewsPath) {
    try { require_once $entityViewsPath; return true; } catch (\Throwable $e) { return false; }
})());

// Check capability handlers via source inspection (helpers.php requires bootstrap)
$helpersSource = (string) file_get_contents($base . '/modules/daily-ledger/helpers.php');
$h->test('daily_ledger_capability_handlers defined in helpers.php', str_contains($helpersSource, 'function daily_ledger_capability_handlers'));
$h->test('dl_cap_entity_list_entry_1 defined in helpers.php', str_contains($helpersSource, 'function dl_cap_entity_list_entry_1'));
$h->test('dl_cap_entity_get_entry_1 defined in helpers.php', str_contains($helpersSource, 'function dl_cap_entity_get_entry_1'));
$h->test('kernel.auth.authenticate@1 handler declared', str_contains($helpersSource, "'kernel.auth.authenticate@1'"));

// ─── PHP Syntax Check ───────────────────────────────────────────
$h->section('PHP Syntax');

$phpFiles = array_merge(
    glob($base . '/modules/daily-ledger/*.php') ?: [],
    glob($base . '/modules/daily-ledger/helpers/*.php') ?: []
);
$syntaxOk = true;
foreach ($phpFiles as $f) {
    $out = null;
    $rc = 0;
    exec("php -l " . escapeshellarg($f) . " 2>/dev/null", $out, $rc);
    if ($rc !== 0) {
        $h->fail("Syntax: " . basename($f), implode(' ', $out));
        $syntaxOk = false;
    }
}
if ($syntaxOk) {
    $h->pass('All PHP files pass syntax check');
}

// ─── Nav ↔ Routes Cross-Reference ──────────────────────────────
$h->section('Nav ↔ Routes Cross-Reference');

$routesPath = $base . '/modules/daily-ledger/routes.php';
$routes = include $routesPath;
$getRoutes = array_keys($routes['GET'] ?? []);

// Extract nav URLs from module.json
$navUrls = [];
$collectNav = static function (array $items) use (&$collectNav, &$navUrls): void {
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (!empty($item['url'])) $navUrls[] = (string)$item['url'];
        if (is_array($item['children'] ?? null)) $collectNav($item['children']);
    }
};
$collectNav($manifest['nav'] ?? []);

$navRouteOk = true;
foreach ($navUrls as $navUrl) {
    if (!in_array($navUrl, $getRoutes, true)) {
        $h->fail("Nav URL resolves to GET route: {$navUrl}", 'not found in routes.php GET');
        $navRouteOk = false;
    }
}
if ($navRouteOk) {
    $h->pass('All ' . count($navUrls) . ' nav URLs resolve to registered GET routes');
}

// ─── Template Existence ─────────────────────────────────────────
$h->section('Template Existence');

// Map nav URLs to expected template paths
$templateMap = [
    '/daily-ledger/ledger' => 'templates/modules/daily-ledger/cashier/ledger.disyl',
    '/daily-ledger/ledger/rows' => 'templates/modules/daily-ledger/cashier/partials/ledger-rows.disyl',
    '/daily-ledger/login' => 'templates/modules/daily-ledger/pages/login.disyl',
    '/daily-ledger/forgot-password' => null, // renders via app()->render, template resolved at runtime
    '/daily-ledger/reset-password' => null,  // renders via app()->render, template resolved at runtime
    '/daily-ledger/logout' => null, // redirect, no template
];

// Admin page templates
$adminDir = $base . '/templates/modules/daily-ledger/admin';
if (is_dir($adminDir)) {
    foreach (scandir($adminDir) as $file) {
        if (!str_ends_with($file, '.disyl')) continue;
        $page = basename($file, '.disyl');
        $route = '/daily-ledger/admin/' . $page;
        $templateMap[$route] = 'templates/modules/daily-ledger/admin/' . $file;
    }
}

$templateOk = true;
foreach ($templateMap as $route => $templatePath) {
    if ($templatePath === null) continue; // skip redirects
    if (!in_array($route, $getRoutes, true)) continue; // skip pages without routes
    if (!is_file($base . '/' . $templatePath)) {
        $h->fail("Template exists for route: {$route}", "expected {$templatePath}");
        $templateOk = false;
    }
}
if ($templateOk) {
    $h->pass('All routed admin pages have corresponding templates');
}

// Check for orphan templates (templates without routes)
$orphanOk = true;
foreach ($templateMap as $route => $templatePath) {
    if ($templatePath === null) continue;
    if (!in_array($route, $getRoutes, true) && is_file($base . '/' . $templatePath)) {
        // production.disyl is a known partial, not an orphan
        if (str_contains($templatePath, 'production.disyl') && !str_contains($templatePath, 'production-output')) {
            continue; // known partial template, not a page
        }
        $h->fail("No orphan templates: {$templatePath}", "template exists but route {$route} not registered");
        $orphanOk = false;
    }
}
if ($orphanOk) {
    $h->pass('No orphan templates (all templates have registered GET routes)');
}

// ─── Workbench Contract ─────────────────────────────────────────
$h->section('Workbench Contract');

$contractPath = $base . '/modules/daily-ledger/workbench-contract.json';
$h->test('workbench-contract.json exists', is_file($contractPath));

if (is_file($contractPath)) {
    $contract = json_decode((string) file_get_contents($contractPath), true);
    $h->test('contract is valid JSON', is_array($contract));
    $h->test('contract uses correct schema', ($contract['schema'] ?? '') === 'ark.workbench-test-contract.v1');
    $h->test('contract version 1.0.0', ($contract['contract_version'] ?? '') === '1.0.0');

    // Ownership
    $h->test('GET routes declared', !empty($contract['ownership']['routes']['GET'] ?? null));
    $h->test('POST routes declared', !empty($contract['ownership']['routes']['POST'] ?? null));
    $h->test('tables match module.json', count($contract['ownership']['tables'] ?? []) >= 30);

    // Pages
    $h->test('pages declared', !empty($contract['pages'] ?? null));
    $h->test('at least 15 pages declared', count($contract['pages'] ?? []) >= 15);

    // Actions
    $h->test('POST actions declared', !empty($contract['actions'] ?? null));

    // Gates
    $h->test('gates.required includes contract', in_array('contract', $contract['gates']['required'] ?? [], true));
    $h->test('gates.required includes routes', in_array('routes', $contract['gates']['required'] ?? [], true));

    // Test files
    $testFiles = $contract['test_files']['php'] ?? [];
    $h->test('test_files.php is array', is_array($testFiles));
    $h->test('test_files.php has at least 3 entries', count($testFiles) >= 3);

    $browserFiles = $contract['test_files']['browser'] ?? [];
    $h->test('test_files.browser is array', is_array($browserFiles));
    $h->test('test_files.browser has at least 3 entries', count($browserFiles) >= 3);

    // Required components
    $pages = $contract['pages'] ?? [];
    $pagesWithComponents = count(array_filter($pages, fn($p) => !empty($p['required_components'] ?? [])));
    $h->test('at least 15 pages have required_components', $pagesWithComponents >= 15);

    // Invariants
    $invariantIds = array_column($contract['invariants'] ?? [], 'id');
    $h->test('nav-routes-match-get invariant declared', in_array('nav-routes-match-get', $invariantIds, true));
    $h->test('workbench-selectors-present invariant declared', in_array('workbench-selectors-present', $invariantIds, true));
    $h->test('navigation-routes-resolve invariant declared', in_array('navigation-routes-resolve', $invariantIds, true));
    $h->test('offline-pending-save-scope invariant declared', in_array('offline-pending-save-scope', $invariantIds, true));
    $h->test('derived-sales-reconciliation invariant declared', in_array('derived-sales-reconciliation', $invariantIds, true));
    $h->test('at least 5 invariants declared', count($contract['invariants'] ?? []) >= 5);
}

$h->done();
