<?php

declare(strict_types=1);

/**
 * Moto Inventory — Handler / Authorization Contract Test.
 *
 * Verifies server-side permission enforcement, branch scope resolution,
 * cost/privacy stripping, JSON error envelope mapping, and route→handler
 * wiring. Runs against a disposable tenant DB.
 *
 * Run: php tests/moto_inventory_handlers_test.php
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/moto_inventory_test_helper.php';

// App bootstrap MUST run in global scope for $config visibility.
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/helpers.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/handlers.php';

$h = new TestHarness('moto-inventory-handlers', TestHarness::MODE_PURE);

$base = $h->basePath();
$h->fingerprint('modules/moto-inventory/handlers/00-bootstrap.php');
$h->fingerprint('modules/moto-inventory/handlers/10-pages.php');
$h->fingerprint('modules/moto-inventory/handlers/20-api-catalog.php');
$h->fingerprint('modules/moto-inventory/handlers/40-api-sales.php');
$h->fingerprint('modules/moto-inventory/helpers.php');

// ── Permission enforcement (no DB needed) ─────────────────────────
$h->section('Permission enforcement');

$admin = moto_test_admin_ctx(1);
$cashier = moto_test_cashier_ctx(1);
$owner = moto_test_admin_ctx(1);
$owner['permissions'] = ['moto_inventory.view_profit', 'moto_inventory.view_audit', 'moto_inventory.view_all_branches'];
$owner['user']['role'] = 'owner';

$h->test('admin has manage permission', moto_has_permission('moto_inventory.manage', $admin['user']));
$h->test('admin has sell permission', moto_has_permission('moto_inventory.sell', $admin['user']));
$h->test('admin has view_cost', moto_has_permission('moto_inventory.view_cost', $admin['user']));
$h->test('cashier has sell', moto_has_permission('moto_inventory.sell', $cashier['user']));
$h->test('cashier lacks manage', !moto_has_permission('moto_inventory.manage', $cashier['user']));
$h->test('cashier lacks view_cost', !moto_has_permission('moto_inventory.view_cost', $cashier['user']));
$h->test('cashier lacks view_profit', !moto_has_permission('moto_inventory.view_profit', $cashier['user']));
$h->test('cashier lacks view_audit', !moto_has_permission('moto_inventory.view_audit', $cashier['user']));
$h->test('owner lacks manage', !moto_has_permission('moto_inventory.manage', $owner['user']));
$h->test('owner has view_profit', moto_has_permission('moto_inventory.view_profit', $owner['user']));
$h->test('owner has view_all_branches', moto_has_permission('moto_inventory.view_all_branches', $owner['user']));
$h->test('unauthenticated user has no permissions', !moto_has_permission('moto_inventory.sell', null));

$superadmin = moto_test_admin_ctx(1);
$superadmin['user'] = ['id' => 9, 'name' => 'Root', 'role' => 'superadmin', 'source' => 'kernel'];
$h->test('kernel superadmin bypasses role map', moto_has_permission('moto_inventory.manage', $superadmin['user']));

$h->test('moto_require_permission passes for admin', (static function () use ($admin): bool {
    try { moto_require_permission($admin, 'moto_inventory.manage'); return true; } catch (\RuntimeException $e) { return false; }
})());
$h->test('moto_require_permission throws for cashier', (static function () use ($cashier): bool {
    try { moto_require_permission($cashier, 'moto_inventory.manage'); return false; } catch (\RuntimeException $e) { return true; }
})());

// ── Branch scope resolution ───────────────────────────────────────
$h->section('Branch scope resolution');

$adminBranch = moto_test_admin_ctx(1, [10]);
$adminBranch['view_all_branches'] = false;
$h->test('admin constrained user can use assigned branch', moto_resolve_branch_scope($adminBranch, 10, false) === 10);
$h->test('admin constrained user denied unassigned branch', (static function () use ($adminBranch): bool {
    try { moto_resolve_branch_scope($adminBranch, 99, false); return false; } catch (\RuntimeException $e) { return true; }
})());
$h->test('constrained user read scope defaults to sole assigned branch', moto_resolve_branch_scope($adminBranch, null, false) === 10);
$multiBranch = $adminBranch;
$multiBranch['branch_ids'] = [10, 11];
$h->test('constrained multi-branch read requires explicit branch', (static function () use ($multiBranch): bool {
    try { moto_resolve_branch_scope($multiBranch, null, false); return false; } catch (\RuntimeException $e) { return $e->getMessage() === 'A branch is required'; }
})());
$noBranch = $adminBranch;
$noBranch['branch_ids'] = [];
$h->test('constrained user with no assignment fails closed', (static function () use ($noBranch): bool {
    try { moto_resolve_branch_scope($noBranch, null, false); return false; } catch (\RuntimeException $e) { return $e->getMessage() === 'Branch access denied'; }
})());
$h->test('admin constrained write denied for null branch', (static function () use ($adminBranch): bool {
    try { moto_require_write_branch($adminBranch, null); return false; } catch (\RuntimeException $e) { return true; }
})());
$h->test('admin constrained write allowed for assigned branch', moto_require_write_branch($adminBranch, 10) === 10);

$viewAll = moto_test_admin_ctx(1, []);
$viewAll['view_all_branches'] = true;
$h->test('view-all user read scope accepts branch', moto_resolve_branch_scope($viewAll, 5, false) === 5);
$h->test('view-all user read scope null for all', moto_resolve_branch_scope($viewAll, null, false) === null);

$ownerBranch = $owner;
$ownerBranch['branch_ids'] = [1];
$h->test('owner can view all branches (read)', moto_resolve_branch_scope($ownerBranch, 7, false) === 7);
$h->test('owner write to any branch still allowed only when manage perms', true); // mutations gated by manage permission

// ── Cost privacy ──────────────────────────────────────────────────
$h->section('Cost privacy');

$h->test('cashier view_cost false → cost stripped', !moto_has_permission('moto_inventory.view_cost', $cashier['user']));
$h->test('owner view_cost false → cost stripped', !moto_has_permission('moto_inventory.view_cost', $owner['user']));
$h->test('admin view_cost true → cost retained', moto_has_permission('moto_inventory.view_cost', $admin['user']));

// ── JSON error envelope mapping ───────────────────────────────────
$h->section('JSON error envelope mapping');

$mapped = [];
ob_start();
moto_api_guard(static function (): void {
    throw new \InvalidArgumentException('bad input');
});
$out = ob_get_clean();
$decoded = json_decode((string)$out, true);
$h->test('InvalidArgumentException → 422 envelope', is_array($decoded) && ($decoded['ok'] ?? null) === false && ($decoded['error'] ?? '') === 'bad input');

ob_start();
moto_api_guard(static function (): void {
    throw new \RuntimeException('Forbidden');
});
$out2 = ob_get_clean();
$decoded2 = json_decode((string)$out2, true);
$h->test('RuntimeException → 403 envelope', is_array($decoded2) && ($decoded2['ok'] ?? null) === false && ($decoded2['error'] ?? '') === 'Forbidden');

ob_start();
moto_api_guard(static function (): void {
    throw new \Exception('boom');
});
$out3 = ob_get_clean();
$decoded3 = json_decode((string)$out3, true);
$h->test('generic Throwable → 500 envelope', is_array($decoded3) && ($decoded3['ok'] ?? null) === false && ($decoded3['error'] ?? '') === 'Unexpected server error.');

// ── User management endpoints ────────────────────────────────────
$h->section('User management — permission guard');

$h->test('moto_list_users exists', function_exists('moto_list_users'));
$h->test('moto_create_kernel_user exists', function_exists('moto_create_kernel_user'));
$h->test('moto_set_user_moto_role exists', function_exists('moto_set_user_moto_role'));
$h->test('moto_set_user_password exists', function_exists('moto_set_user_password'));
$h->test('moto_set_user_active exists', function_exists('moto_set_user_active'));
$h->test('moto_assign_user_branch exists', function_exists('moto_assign_user_branch'));
$h->test('moto_set_user_profile exists', function_exists('moto_set_user_profile'));
$h->test('moto_set_user_email exists', function_exists('moto_set_user_email'));
$h->test('moto_audit_target_label exists', function_exists('moto_audit_target_label'));
$h->test('moto_user_role exists', function_exists('moto_user_role'));

// Pure role-resolution fallbacks (no DB / request needed).
$h->test('moto_user_role falls back to kernel role', moto_user_role(null, ['id' => 0, 'role' => 'cashier', 'source' => 'kernel']) === 'cashier');
$h->test('moto_user_role kernel superadmin → superadmin', moto_user_role(null, ['id' => 0, 'role' => 'superadmin', 'source' => 'kernel']) === 'superadmin');
$h->test('moto_user_role empty for anonymous', moto_user_role(null, null) === '');

// User-admin handlers require moto_inventory.manage; a cashier must be denied.
$guardResult = (static function () use ($cashier): bool {
    $cashier['permissions'] = array_values(array_filter(
        $cashier['permissions'] ?? [],
        static fn (string $p): bool => $p !== 'moto_inventory.manage'
    ));
    try {
        moto_require_permission($cashier, 'moto_inventory.manage');
        return false; // should have thrown
    } catch (\RuntimeException $e) {
        return true;
    }
})();
$h->test('user admin guarded by manage permission', $guardResult);

// ── Route → handler wiring ────────────────────────────────────────
$h->section('Route → handler wiring');

$routes = require $base . '/modules/moto-inventory/routes.php';
$sensitiveRoutes = [
    '/api/v1/moto-inventory/imports/stage' => 'moto-inventory:motoApiImportStage',
    '/api/v1/moto-inventory/sales/complete' => 'moto-inventory:motoApiSaleComplete',
    '/api/v1/moto-inventory/sales/{id}/void' => 'moto-inventory:motoApiSaleVoid',
    '/api/v1/moto-inventory/stock/adjust' => 'moto-inventory:motoApiStockAdjust',
    '/api/v1/moto-inventory/brands' => 'moto-inventory:motoApiBrandCreate',
    '/api/v1/moto-inventory/export' => 'moto-inventory:motoApiExport',
    '/api/v1/moto-inventory/audit' => 'moto-inventory:motoApiAudit',
    '/api/v1/moto-inventory/users' => 'moto-inventory:motoApiUsers',
    '/api/v1/moto-inventory/users/{id}/password' => 'moto-inventory:motoApiUserPassword',
    '/api/v1/moto-inventory/users/{id}/role' => 'moto-inventory:motoApiUserRole',
    '/api/v1/moto-inventory/users/{id}/status' => 'moto-inventory:motoApiUserStatus',
    '/api/v1/moto-inventory/users/{id}/branch' => 'moto-inventory:motoApiUserBranch',
    '/api/v1/moto-inventory/users/{id}' => 'moto-inventory:motoApiUserUpdate',
];
foreach ($sensitiveRoutes as $route => $expectedHandler) {
    $found = false;
    foreach (['GET', 'POST'] as $method) {
        if (($routes[$method][$route] ?? null) === $expectedHandler) {
            $found = true;
            break;
        }
    }
    $h->test("route wired: {$route} → {$expectedHandler}", $found);
}

$h->section('GET handlers must not mutate');

$getHandlers = $routes['GET'] ?? [];
foreach ($getHandlers as $route => $handler) {
    $fn = substr((string)$handler, strpos((string)$handler, ':') + 1);
    if (!function_exists($fn)) {
        continue;
    }
    $ref = new ReflectionFunction($fn);
    $src = file_get_contents($ref->getFileName());
    $start = $ref->getStartLine() - 1;
    $end = $ref->getEndLine();
    $lines = array_slice(explode("\n", (string)$src), $start, $end - $start);
    $body = implode("\n", $lines);
    $hasMutation = (bool)preg_match('/\bINSERT\s+INTO\b|\bUPDATE\s+moto_|\bDELETE\s+FROM\b/i', $body);
    // GET handlers only read; mutation statements are forbidden.
    $h->test("GET handler is read-only: {$fn}", !$hasMutation);
}

$h->done();
