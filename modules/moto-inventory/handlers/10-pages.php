<?php

declare(strict_types=1);

/**
 * Moto Inventory — Page handlers.
 *
 * Thin server-rendered page shells. All data interactions happen through the
 * /api/v1/moto-inventory/* endpoints from focused same-origin JS.
 */

/**
 * Kernel login surface for entry-module routing.
 *
 * The module is NOT auth_owned — kernel authentication is the identity
 * authority. This route exists so the module satisfies the auth/entry route
 * contract (and stays selectable as a tenant entry module); it simply sends
 * the visitor to the kernel canonical login page.
 */
function motoPageLogin(array $params = []): void
{
    if (app()->user()) {
        app()->redirect('/moto-inventory');
        return;
    }
    app()->redirect('/login');
}

function motoPageDashboard(array $params = []): void
{
    $ctx = moto_ctx();
    $branches = moto_accessible_branches($ctx);
    $defaultBranch = $branches[0]['id'] ?? null;

    $metrics = [
        'part_count' => 0, 'units_on_hand' => 0, 'stock_value_retail' => 0, 'today_sales' => 0,
        'today_revenue' => 0.0, 'today_profit' => 0.0, 'pending_imports' => 0,
    ];
    try {
        $db = moto_db((int)$ctx['tenant_id']);
        $tid = (int)$ctx['tenant_id'];
        $branchWhere = '';
        $branchParams = [];
        if (!$ctx['view_all_branches'] && $ctx['branch_ids'] !== []) {
            $ids = implode(',', array_map('intval', $ctx['branch_ids']));
            $branchWhere = ' AND p.branch_id IN (' . $ids . ')';
        } elseif (!$ctx['view_all_branches']) {
            $branchWhere = ' AND 1 = 0';
        }
        $metrics['part_count'] = (int)$db->query(
            "SELECT COUNT(*) FROM moto_products p WHERE p.tenant_id = :tid AND p.archived = 0{$branchWhere}",
            [':tid' => $tid] + $branchParams
        )->fetchColumn();
        $row = $db->query(
            "SELECT COALESCE(SUM(p.qty_on_hand),0) AS units, COALESCE(SUM(p.qty_on_hand * p.price),0) AS retail
             FROM moto_products p WHERE p.tenant_id = :tid AND p.archived = 0{$branchWhere}",
            [':tid' => $tid] + $branchParams
        )->fetch(\PDO::FETCH_ASSOC);
        $metrics['units_on_hand'] = (float)($row['units'] ?? 0);
        $metrics['stock_value_retail'] = (float)($row['retail'] ?? 0);

        $today = date('Y-m-d') . ' 00:00:00';
        $todayRow = $db->query(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(s.total),0) AS rev, COALESCE(SUM(s.profit),0) AS profit
             FROM moto_sales s
             WHERE s.tenant_id = :tid AND s.status = 'completed' AND s.created_at >= :today",
            [':tid' => $tid, ':today' => $today]
        )->fetch(\PDO::FETCH_ASSOC);
        $metrics['today_sales'] = (int)($todayRow['cnt'] ?? 0);
        $metrics['today_revenue'] = (float)($todayRow['rev'] ?? 0);
        $metrics['today_profit'] = (float)($todayRow['profit'] ?? 0);

        $metrics['pending_imports'] = (int)$db->query(
            "SELECT COUNT(*) FROM moto_imports WHERE tenant_id = :tid AND status = 'staged'",
            [':tid' => $tid]
        )->fetchColumn();
    } catch (\Throwable $e) {
        // Dashboard is resilient to missing tables (pre-migration).
    }

    echo app()->render('modules/moto-inventory/pages/dashboard', moto_page_context($ctx, 'dashboard', 'Dashboard', [
        'metrics' => $metrics,
        'default_branch' => $defaultBranch,
    ]));
}

function motoPageInventory(array $params = []): void
{
    $ctx = moto_ctx();
    echo app()->render('modules/moto-inventory/pages/inventory', moto_page_context($ctx, 'inventory', 'Inventory'));
}

function motoPageSales(array $params = []): void
{
    $ctx = moto_ctx();
    moto_require_page_permission($ctx, 'moto_inventory.sell');
    echo app()->render('modules/moto-inventory/pages/sales', moto_page_context($ctx, 'sales', 'Sales'));
}

function motoPageHistory(array $params = []): void
{
    $ctx = moto_ctx();
    echo app()->render('modules/moto-inventory/pages/history', moto_page_context($ctx, 'history', 'History'));
}

function motoPageReports(array $params = []): void
{
    $ctx = moto_ctx();
    moto_require_page_permission($ctx, 'moto_inventory.view_profit');
    echo app()->render('modules/moto-inventory/pages/reports', moto_page_context($ctx, 'reports', 'Reports'));
}

function motoPageAudit(array $params = []): void
{
    $ctx = moto_ctx();
    moto_require_page_permission($ctx, 'moto_inventory.view_audit');
    echo app()->render('modules/moto-inventory/pages/audit', moto_page_context($ctx, 'audit', 'Audit'));
}

function motoPageImport(array $params = []): void
{
    $ctx = moto_ctx();
    moto_require_page_permission($ctx, 'moto_inventory.manage');
    $imports = [];
    try {
        $imports = ImportService::imports($ctx, ['branch_id' => $ctx['view_all_branches'] ? null : ($ctx['branch_ids'][0] ?? null)]);
    } catch (\Throwable $e) {
        $imports = [];
    }
    $brands = [];
    try {
        $brands = CatalogService::brands($ctx, ['include_archived' => true, 'include_trashed' => true])['rows'];
    } catch (\Throwable $e) {
        $brands = [];
    }
    $templates = [];
    try {
        $templates = ImportTemplateService::all($ctx);
    } catch (\Throwable $e) {
        $templates = ['presets' => [], 'custom' => []];
    }
    // Escaped JSON for a <script type="application/json"> embed: user-supplied
    // template names/labels must never break out of the tag.
    $templatesJson = json_encode($templates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $templatesJson = str_replace(['<', '>', '&'], ['\\u003c', '\\u003e', '\\u0026'], $templatesJson);
    echo app()->render('modules/moto-inventory/pages/import', moto_page_context($ctx, 'import', 'Import', [
        'imports'          => $imports,
        'brands'           => $brands,
        'import_templates' => $templates,
        'templates_json'   => $templatesJson,
    ]));
}

function motoPageBranches(array $params = []): void
{
    $ctx = moto_ctx();
    moto_require_page_permission($ctx, 'moto_inventory.manage');
    $branches = moto_accessible_branches($ctx);
    $users = [];
    try {
        $users = moto_list_users($ctx);
    } catch (\Throwable $e) {
        $users = [];
    }

    // Precompute branch → assigned users chips (avoids relying on DiSyL `in`
    // / dynamic-key array access, which are not supported).
    $branchUsers = [];
    foreach ($users as $u) {
        foreach ((array)($u['branches'] ?? []) as $bid) {
            $branchUsers[(int)$bid][] = $u;
        }
    }
    $branches = array_map(static function (array $b) use ($branchUsers): array {
        $bid = (int)$b['id'];
        $chips = [];
        foreach ($branchUsers[$bid] ?? [] as $u) {
            $chips[] = '<span class="mi-badge" style="background:var(--accent-soft);color:var(--accent-strong)">'
                . htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8')
                . ' <a href="#" data-unassign-branch="' . $bid . '" data-user-id="' . (int)$u['id']
                . '" title="Remove" style="color:inherit;text-decoration:none;font-weight:700">×</a></span>';
        }
        $b['user_chips'] = $chips !== []
            ? implode('', $chips)
            : '<span class="mi-muted" style="font-size:.76rem">No users assigned</span>';
        return $b;
    }, $branches);

    echo app()->render('modules/moto-inventory/pages/branches', moto_page_context($ctx, 'branches', 'Branches', [
        'branches'                   => $branches,
        'users'                      => $users,
        'role_permissions_effective' => json_encode(moto_inventory_role_permissions(), JSON_UNESCAPED_SLASHES),
    ]));
}

/**
 * Dedicated Users page: manage kernel users (name, email, password, role).
 */
function motoPageUsers(array $params = []): void
{
    $ctx = moto_ctx();
    moto_require_page_permission($ctx, 'moto_inventory.manage');
    $users = [];
    $branches = [];
    try {
        $users = moto_list_users($ctx);
        $branches = moto_accessible_branches($ctx);
    } catch (\Throwable $e) {
        $users = [];
    }

    echo app()->render('modules/moto-inventory/pages/users', moto_page_context($ctx, 'users', 'Users', [
        'users'    => $users,
        'branches' => $branches,
    ]));
}

/**
 * Settings form save (non-API, CSRF-protected).
 */
function motoPageSettingsSave(array $params = []): void
{
    $ctx = moto_ctx();
    moto_require_page_permission($ctx, 'moto_inventory.manage');
    moto_enforce_csrf();

    $input = moto_input();
    $rolePermissions = (string)($input['role_permissions'] ?? '');
    if (trim($rolePermissions) !== '' && json_decode($rolePermissions, true) === null) {
        app()->redirect('/moto-inventory/branches?error=invalid-role-permissions');
        return;
    }

    $settings = [
        'low_stock_threshold'  => max(0, (int)($input['low_stock_threshold'] ?? 5)),
        'allow_negative_stock' => !empty($input['allow_negative_stock']) ? true : false,
        'undo_window_minutes'  => max(0, (int)($input['undo_window_minutes'] ?? 5)),
    ];
    if (trim($rolePermissions) !== '') {
        $settings['role_permissions'] = $rolePermissions;
    }

    if (function_exists('saveModuleSettings')) {
        saveModuleSettings('moto-inventory', $settings);
    }

    app()->redirect('/moto-inventory/branches?success=settings-saved');
}
