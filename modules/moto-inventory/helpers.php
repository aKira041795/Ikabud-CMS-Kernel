<?php

declare(strict_types=1);

/**
 * Moto Inventory — Helpers, capability handlers, and module context.
 *
 * The module is NOT auth_owned: the authenticated kernel user is the identity
 * authority. Personas (admin/cashier/owner) are expressed as explicit module
 * permissions resolved from the kernel user's role via a role→permission map
 * (editable through the role_permissions module setting). Branch scope is
 * always resolved server-side from moto_user_branches; the browser can never
 * select a tenant, branch, role, or permission.
 */

// ── Auto-load domain services ─────────────────────────────────────
(function (): void {
    $files = [
        '/services/CatalogService.php',
        '/services/StockService.php',
        '/services/SaleService.php',
        '/services/ImportService.php',
    ];
    foreach ($files as $file) {
        $path = __DIR__ . $file;
        if (file_exists($path)) {
            require_once $path;
        }
    }
})();

// ── Kernel users administration helper (src/helpers, kernel-escalated) ────
// The kernel `users` table is kernel-owned; modules must not declare it in
// owns_tables/co_owns_tables. Access goes through kernelEscalationEnter()
// from src/helpers (the same mechanism used for tenant_module_settings).
$kernelUsersAdminPath = dirname(__DIR__, 2) . '/src/helpers/kernel-users-admin.php';
if (file_exists($kernelUsersAdminPath)) {
    require_once $kernelUsersAdminPath;
}

// ── Permission catalog ────────────────────────────────────────────

function moto_inventory_permission_actions(): array
{
    return [
        'moto_inventory.manage',
        'moto_inventory.sell',
        'moto_inventory.void',
        'moto_inventory.view_cost',
        'moto_inventory.view_profit',
        'moto_inventory.view_audit',
        'moto_inventory.view_all_branches',
    ];
}

function moto_inventory_default_role_permissions(): array
{
    $all = moto_inventory_permission_actions();

    return [
        'superadmin' => $all,
        'admin'      => $all,
        'manager'    => [
            'moto_inventory.manage',
            'moto_inventory.sell',
            'moto_inventory.void',
            'moto_inventory.view_cost',
            'moto_inventory.view_profit',
        ],
        'cashier'    => [
            'moto_inventory.sell',
        ],
        'owner'      => [
            'moto_inventory.view_profit',
            'moto_inventory.view_audit',
            'moto_inventory.view_all_branches',
        ],
    ];
}

function moto_inventory_normalize_role_permissions(mixed $raw): array
{
    $defaults = moto_inventory_default_role_permissions();

    if (is_string($raw) && trim((string)$raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }
    if (!is_array($raw)) {
        return $defaults;
    }

    $allowed = array_flip(moto_inventory_permission_actions());
    $result = [];
    foreach (array_keys($defaults) as $role) {
        $values = $raw[$role] ?? $defaults[$role];
        if (!is_array($values)) {
            $values = [];
        }
        $clean = [];
        foreach ($values as $permission) {
            $permission = trim((string)$permission);
            if ($permission !== '' && isset($allowed[$permission])) {
                $clean[$permission] = true;
            }
        }
        $result[$role] = array_keys($clean);
    }

    return $result;
}

function moto_inventory_settings(): array
{
    $defaults = [];
    if (function_exists('discoverModules')) {
        $manifest = discoverModules()['moto-inventory'] ?? [];
        $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];
        foreach ($fields as $field) {
            if (is_array($field) && array_key_exists('default', $field) && !empty($field['key'])) {
                $defaults[$field['key']] = $field['default'];
            }
        }
    }

    $saved = function_exists('getModuleSettings') ? getModuleSettings('moto-inventory') : [];

    return array_merge($defaults, is_array($saved) ? $saved : []);
}

function moto_inventory_role_permissions(): array
{
    $settings = moto_inventory_settings();

    return moto_inventory_normalize_role_permissions($settings['role_permissions'] ?? null);
}

// ── Capability handlers ───────────────────────────────────────────

function moto_inventory_capability_handlers(): array
{
    return [
        'moto_inventory.catalog.query@1'   => 'moto_cap_catalog_query_1',
        'moto_inventory.catalog.mutate@1'  => 'moto_cap_catalog_mutate_1',
        'moto_inventory.stock.query@1'     => 'moto_cap_stock_query_1',
        'moto_inventory.stock.adjust@1'    => 'moto_cap_stock_adjust_1',
        'moto_inventory.sale.complete@1'   => 'moto_cap_sale_complete_1',
        'moto_inventory.sale.void@1'       => 'moto_cap_sale_void_1',
        'moto_inventory.report.query@1'    => 'moto_cap_report_query_1',
        'moto_inventory.import.mutate@1'   => 'moto_cap_import_mutate_1',
        'moto_inventory.export.mutate@1'   => 'moto_cap_export_mutate_1',
        'moto_inventory.audit.query@1'     => 'moto_cap_audit_query_1',
        'moto_inventory.branch.query@1'    => 'moto_cap_branch_query_1',
    ];
}

function moto_cap_catalog_query_1(mixed $payload): array
{
    return ['granted' => moto_has_permission('moto_inventory.manage') || moto_has_permission('moto_inventory.sell') || moto_has_permission('moto_inventory.view_cost') || moto_has_permission('moto_inventory.view_profit')];
}
function moto_cap_catalog_mutate_1(mixed $payload): array
{
    return ['granted' => moto_has_permission('moto_inventory.manage')];
}
function moto_cap_stock_query_1(mixed $payload): array
{
    return ['granted' => moto_cap_catalog_query_1($payload)['granted']];
}
function moto_cap_stock_adjust_1(mixed $payload): array
{
    return ['granted' => moto_has_permission('moto_inventory.manage')];
}
function moto_cap_sale_complete_1(mixed $payload): array
{
    return ['granted' => moto_has_permission('moto_inventory.sell') || moto_has_permission('moto_inventory.manage')];
}
function moto_cap_sale_void_1(mixed $payload): array
{
    return ['granted' => moto_has_permission('moto_inventory.void') || moto_has_permission('moto_inventory.manage')];
}
function moto_cap_report_query_1(mixed $payload): array
{
    return ['granted' => moto_has_permission('moto_inventory.view_profit') || moto_has_permission('moto_inventory.manage')];
}
function moto_cap_import_mutate_1(mixed $payload): array
{
    return ['granted' => moto_has_permission('moto_inventory.manage')];
}
function moto_cap_export_mutate_1(mixed $payload): array
{
    return ['granted' => moto_has_permission('moto_inventory.manage')];
}
function moto_cap_audit_query_1(mixed $payload): array
{
    return ['granted' => moto_has_permission('moto_inventory.view_audit') || moto_has_permission('moto_inventory.manage')];
}
function moto_cap_branch_query_1(mixed $payload): array
{
    return ['granted' => true];
}

// ── Context resolution ────────────────────────────────────────────

/**
 * Resolve the tenant-scoped ModuleDB. Prefers the active module context
 * (HTTP requests); falls back to an explicit tenant DB for CLI/tests.
 */
function moto_db(?int $tenantId = null): \Ikabud\Kernel\Contracts\ModuleDB
{
    if (function_exists('module') && module() !== null) {
        try {
            return module()->db();
        } catch (\Throwable $e) {
            // Fall through to the explicit tenant-safe path.
        }
    }

    $resolved = $tenantId ?? moto_resolve_tenant_id();
    if ($resolved <= 0) {
        throw new \RuntimeException('Moto Inventory tenant context is unavailable');
    }

    $tenantDb = app()->dbForTenant($resolved);
    $manifestPath = __DIR__ . '/module.json';
    $manifest = is_file($manifestPath) ? (json_decode((string)file_get_contents($manifestPath), true) ?: []) : [];
    if ($manifest === [] && function_exists('discoverModules')) {
        $manifest = discoverModules()['moto-inventory'] ?? [];
    }

    return new \Ikabud\Kernel\Contracts\ModuleDB(
        $tenantDb,
        'moto-inventory',
        is_array($manifest['owns_tables'] ?? null) ? $manifest['owns_tables'] : [],
        is_array($manifest['reads_tables'] ?? null) ? $manifest['reads_tables'] : []
    );
}

function moto_resolve_tenant_id(): int
{
    $tenantId = app()->tenant()->current();
    if ($tenantId === null || (int)$tenantId <= 0) {
        $tenantId = app()->tenant()->resolve(app()->user());
    }

    return (int)($tenantId ?? 0);
}

/**
 * Fail closed: require an authenticated kernel user.
 */
function moto_user(): array
{
    $user = app()->user();
    if (!is_array($user) || empty($user)) {
        if (function_exists('kernel_is_api_request') && kernel_is_api_request()) {
            app()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        app()->redirect('/login');
        // @codeCoverageIgnoreStart
        exit;
        // @codeCoverageIgnoreEnd
    }

    return $user;
}

function moto_has_permission(string $permission, ?array $user = null): bool
{
    $user = $user ?? (is_array(app()->user()) ? app()->user() : null);
    if (!is_array($user)) {
        return false;
    }
    // Kernel superadmin always has module permissions.
    if (($user['source'] ?? '') === 'kernel' && ($user['role'] ?? '') === 'superadmin') {
        return true;
    }
    $role = moto_user_role(null, $user);
    $permissions = moto_inventory_role_permissions();

    return in_array($permission, $permissions[$role] ?? [], true);
}

/**
 * Resolve the module role for a kernel user.
 *
 * Kernel auth is the identity authority; the module role is stored per tenant
 * in moto_user_roles so an admin can differentiate cashiers/owners/etc. that
 * all authenticate as kernel admins. Falls back to the kernel role when no
 * moto_user_roles row exists.
 */
function moto_user_role(?array $ctx = null, ?array $user = null): string
{
    $user = $user ?? (is_array(app()->user()) ? app()->user() : null);
    if (!is_array($user)) {
        return '';
    }
    if (($user['source'] ?? '') === 'kernel' && ($user['role'] ?? '') === 'superadmin') {
        return 'superadmin';
    }

    $tenantId = $ctx !== null && isset($ctx['tenant_id']) ? (int)$ctx['tenant_id'] : 0;
    if ($tenantId <= 0) {
        try {
            $tenantId = moto_resolve_tenant_id();
        } catch (\Throwable $e) {
            $tenantId = 0;
        }
    }
    $userId = (int)($user['id'] ?? (int)($user['sub'] ?? 0));
    if ($tenantId > 0 && $userId > 0) {
        try {
            $db = moto_db($tenantId);
            $stmt = $db->prepare('SELECT role FROM moto_user_roles WHERE tenant_id = :tid AND user_id = :uid LIMIT 1');
            $stmt->execute([':tid' => $tenantId, ':uid' => $userId]);
            $role = $stmt->fetchColumn();
            if (is_string($role) && $role !== '') {
                return $role;
            }
        } catch (\Throwable $e) {
            // Fall through to the kernel role.
        }
    }

    return (string)($user['role'] ?? '');
}

function moto_can_view_all_branches(?array $user = null): bool
{
    return moto_has_permission('moto_inventory.view_all_branches', $user);
}

/**
 * Server-resolved branch IDs the user may act in. Owners/read-only users
 * with view_all_branches still only READ; mutations are gated separately.
 */
function moto_user_branch_ids(int $tenantId, int $userId): array
{
    try {
        $db = moto_db($tenantId);
        $stmt = $db->prepare(
            'SELECT branch_id FROM moto_user_branches WHERE tenant_id = :tid AND user_id = :uid'
        );
        $stmt->execute([':tid' => $tenantId, ':uid' => $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map('intval', $rows));
    } catch (\Throwable $e) {
        return [];
    }
}

// ── User management (kernel users table) ─────────────────────────
// Kernel auth is the identity authority: login + password hashes live in the
// kernel `users` table (via app()->db(), the tenant DB). This module provides
// an admin UI to administer those users and to assign each a per-tenant
// module role (moto_user_roles) and branch memberships.

function moto_list_users(array $ctx): array
{
    $tenantId = (int)$ctx['tenant_id'];
    $rows = kernelUsersList($tenantId);

    $mdb = moto_db($tenantId);
    foreach ($rows as &$row) {
        $userId = (int)$row['id'];
        $row['moto_role'] = null;
        $stmt = $mdb->prepare('SELECT role FROM moto_user_roles WHERE tenant_id = :tid AND user_id = :uid LIMIT 1');
        $stmt->execute([':tid' => $tenantId, ':uid' => $userId]);
        $role = $stmt->fetchColumn();
        if (is_string($role) && $role !== '') {
            $row['moto_role'] = $role;
        }
        $row['branches'] = moto_user_branch_ids($tenantId, $userId);
    }
    unset($row);

    return $rows;
}

function moto_create_kernel_user(array $ctx, array $input): array
{
    $username = strtolower(trim((string)($input['username'] ?? '')));
    $fullName = trim((string)($input['full_name'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $kernelRole = trim((string)($input['role'] ?? 'admin'));
    $motoRole = trim((string)($input['moto_role'] ?? ''));
    // New users are active by default so they can log in immediately.
    $isActive = array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : 1;

    if (!preg_match('/^[a-z0-9_.-]{3,50}$/', $username)) {
        throw new \InvalidArgumentException('Username must be 3–50 characters (letters, numbers, . _ -)');
    }
    if ($password === '' || strlen($password) < 6) {
        throw new \InvalidArgumentException('Password must be at least 6 characters');
    }
    if (!in_array($kernelRole, ['admin', 'superadmin', 'manager', 'viewer'], true)) {
        throw new \InvalidArgumentException('Invalid kernel role');
    }
    if ($motoRole !== '' && !in_array($motoRole, ['admin', 'manager', 'cashier', 'owner'], true)) {
        throw new \InvalidArgumentException('Invalid module role');
    }

    $tenantId = (int)$ctx['tenant_id'];
    if (kernelUserExistsByUsername($tenantId, $username)) {
        throw new \InvalidArgumentException('Username already exists');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $email = trim((string)($input['email'] ?? ''));
    $userId = kernelUserCreate(
        $tenantId,
        $username,
        $email !== '' ? $email : null,
        $hash,
        $fullName !== '' ? $fullName : $username,
        $kernelRole,
        $isActive
    );

    if ($motoRole !== '') {
        moto_set_user_moto_role($ctx, $userId, $motoRole);
    }
    $branchIds = $input['branch_ids'] ?? [];
    if (is_array($branchIds)) {
        foreach ($branchIds as $bid) {
            $bid = (int)$bid;
            if ($bid > 0) {
                moto_assign_user_branch($ctx, $bid, $userId, true);
            }
        }
    }

    moto_audit($ctx, 'moto_inventory.user.created', 'kernel_user', (string)$userId, null, [
        'username' => $username, 'role' => $kernelRole, 'moto_role' => $motoRole,
    ]);

    return ['id' => $userId, 'username' => $username, 'moto_role' => $motoRole !== '' ? $motoRole : $kernelRole];
}

function moto_set_user_password(array $ctx, int $userId, string $password): void
{
    if ($userId <= 0) {
        throw new \InvalidArgumentException('Invalid user');
    }
    if (strlen($password) < 6) {
        throw new \InvalidArgumentException('Password must be at least 6 characters');
    }
    $tenantId = (int)$ctx['tenant_id'];
    if (!kernelUserExists($tenantId, $userId)) {
        throw new \InvalidArgumentException('User not found');
    }
    kernelUserSetPassword($tenantId, $userId, password_hash($password, PASSWORD_BCRYPT));
    moto_audit($ctx, 'moto_inventory.user.password_reset', 'kernel_user', (string)$userId, null, ['password_reset' => true]);
}

function moto_set_user_moto_role(array $ctx, int $userId, string $role): void
{
    if ($userId <= 0) {
        throw new \InvalidArgumentException('Invalid user');
    }
    if (!in_array($role, ['admin', 'manager', 'cashier', 'owner'], true)) {
        throw new \InvalidArgumentException('Invalid module role');
    }
    $db = moto_db((int)$ctx['tenant_id']);
    $db->prepare(
        'INSERT INTO moto_user_roles (tenant_id, user_id, role) VALUES (:tid, :uid, :r)
         ON DUPLICATE KEY UPDATE role = VALUES(role)'
    )->execute([':tid' => (int)$ctx['tenant_id'], ':uid' => $userId, ':r' => $role]);
    moto_audit($ctx, 'moto_inventory.user.role_set', 'kernel_user', (string)$userId, null, ['role' => $role]);
}

function moto_set_user_active(array $ctx, int $userId, bool $active): void
{
    if ($userId <= 0) {
        throw new \InvalidArgumentException('Invalid user');
    }
    kernelUserSetActive((int)$ctx['tenant_id'], $userId, $active);
    moto_audit($ctx, 'moto_inventory.user.status', 'kernel_user', (string)$userId, null, ['is_active' => $active]);
}

function moto_assign_user_branch(array $ctx, int $branchId, int $userId, bool $assigned): void
{
    $tenantId = (int)$ctx['tenant_id'];
    $db = moto_db($tenantId);
    if ($assigned) {
        $db->prepare(
            'INSERT IGNORE INTO moto_user_branches (tenant_id, user_id, branch_id) VALUES (:tid, :uid, :bid)'
        )->execute([':tid' => $tenantId, ':uid' => $userId, ':bid' => $branchId]);
    } else {
        $db->prepare('DELETE FROM moto_user_branches WHERE tenant_id = :tid AND user_id = :uid AND branch_id = :bid')
            ->execute([':tid' => $tenantId, ':uid' => $userId, ':bid' => $branchId]);
    }
    moto_audit($ctx, 'moto_inventory.branch.assignment.updated', 'moto_branch', (string)$branchId, null, [
        'user_id' => $userId, 'assigned' => $assigned,
    ]);
}

/**
 * Resolve a human-readable label for an audit target (JOIN instead of raw id).
 */
function moto_audit_target_label(\PDO|\Ikabud\Kernel\Contracts\DatabaseContract $db, int $tenantId, string $type, int $id): ?string
{
    static $cache = [];
    $key = $type . ':' . $id;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $label = null;
    try {
        if ($type === 'moto_product') {
            $stmt = $db->prepare('SELECT part_number FROM moto_products WHERE tenant_id = :t AND id = :i LIMIT 1');
            $stmt->execute([':t' => $tenantId, ':i' => $id]);
            $v = $stmt->fetchColumn();
            $label = is_string($v) ? $v : null;
        } elseif ($type === 'moto_sale') {
            $stmt = $db->prepare('SELECT sale_ref FROM moto_sales WHERE tenant_id = :t AND id = :i LIMIT 1');
            $stmt->execute([':t' => $tenantId, ':i' => $id]);
            $v = $stmt->fetchColumn();
            $label = is_string($v) ? $v : null;
        } elseif ($type === 'moto_branch') {
            $stmt = $db->prepare('SELECT name FROM moto_branches WHERE tenant_id = :t AND id = :i LIMIT 1');
            $stmt->execute([':t' => $tenantId, ':i' => $id]);
            $v = $stmt->fetchColumn();
            $label = is_string($v) ? $v : null;
        } elseif ($type === 'moto_brand') {
            $stmt = $db->prepare('SELECT name FROM moto_brands WHERE tenant_id = :t AND id = :i LIMIT 1');
            $stmt->execute([':t' => $tenantId, ':i' => $id]);
            $v = $stmt->fetchColumn();
            $label = is_string($v) ? $v : null;
        } elseif ($type === 'kernel_user') {
            $label = kernelUserUsername($tenantId, $id);
        }
    } catch (\Throwable $e) {
        $label = null;
    }

    $cache[$key] = $label;
    return $label;
}

/**
 * Build the full server-side request context: tenant, user, permissions,
 * branch ids, and flags. Fails closed when context is missing.
 */
function moto_ctx(): array
{
    $user = moto_user();
    $tenantId = moto_resolve_tenant_id();
    if ($tenantId <= 0) {
        throw new \RuntimeException('Moto Inventory tenant context is unavailable');
    }

    $userId = (int)($user['id'] ?? (int)($user['sub'] ?? 0));
    $viewAllBranches = moto_can_view_all_branches($user);

    return [
        'tenant_id'          => $tenantId,
        'user'               => $user,
        'user_id'            => $userId,
        'actor_name'         => (string)($user['name'] ?? $user['full_name'] ?? $user['username'] ?? ''),
        'role'               => (string)($user['role'] ?? ''),
        'permissions'        => array_keys(array_filter([
            'moto_inventory.manage'            => moto_has_permission('moto_inventory.manage', $user),
            'moto_inventory.sell'              => moto_has_permission('moto_inventory.sell', $user),
            'moto_inventory.void'              => moto_has_permission('moto_inventory.void', $user),
            'moto_inventory.view_cost'         => moto_has_permission('moto_inventory.view_cost', $user),
            'moto_inventory.view_profit'       => moto_has_permission('moto_inventory.view_profit', $user),
            'moto_inventory.view_audit'        => moto_has_permission('moto_inventory.view_audit', $user),
            'moto_inventory.view_all_branches' => $viewAllBranches,
        ])),
        'view_all_branches'  => $viewAllBranches,
        'branch_ids'         => moto_user_branch_ids($tenantId, $userId),
    ];
}

/**
 * Resolve the branch scope for an action. $requestedBranchId is the value
 * supplied by the browser (never trusted by itself). When the user can view
 * all branches and requests a valid branch, that branch is used; otherwise
 * the user's assigned branches constrain the scope. Reads may be null (all
 * accessible); mutations require an exact branch in scope.
 */
function moto_resolve_branch_scope(array $ctx, ?int $requestedBranchId, bool $forWrite = false): ?int
{
    $branchIds = $ctx['branch_ids'] ?? [];
    if ($ctx['view_all_branches'] ?? false) {
        if ($forWrite) {
            // Mutations must still target a specific branch the user can see.
            return $requestedBranchId !== null ? $requestedBranchId : null;
        }
        return $requestedBranchId !== null ? $requestedBranchId : null;
    }

    // Constrained users must never turn an omitted branch into a tenant-wide
    // query. Resolve the sole assignment when unambiguous; otherwise require
    // the caller to choose one of its assigned branches explicitly.
    if ($requestedBranchId === null) {
        if (count($branchIds) === 1) {
            return (int)$branchIds[0];
        }
        throw new \RuntimeException($branchIds === [] ? 'Branch access denied' : 'A branch is required');
    }
    if (!in_array($requestedBranchId, $branchIds, true)) {
        throw new \RuntimeException('Branch access denied');
    }

    return $requestedBranchId;
}

// ── Input / JSON / CSRF ───────────────────────────────────────────

function moto_input(): array
{
    $input = app()->input();
    return is_array($input) ? $input : [];
}

function moto_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function moto_json_ok(array $data = [], int $status = 200): void
{
    moto_json(['ok' => true, 'data' => $data], $status);
}

function moto_json_error(string $message, int $status = 422, array $extra = []): void
{
    moto_json(['ok' => false, 'error' => $message, 'details' => $extra], $status);
}

function moto_enforce_csrf(): void
{
    // Non-API POST routes are protected by the kernel's session-based CSRF
    // enforcement (public/index.php safety net) plus this explicit check.
    // The forms submit app()->csrfToken() (session token), so we enforce the
    // same session-based contract rather than a JWT-derived one.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    app()->csrfEnforce();
}

// ── Money / quantity normalization ────────────────────────────────

function moto_money(mixed $value): string
{
    if ($value === null || $value === '') {
        return '0.00';
    }
    if (is_numeric($value)) {
        return number_format((float)$value, 2, '.', '');
    }
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$value);
    return number_format((float)($clean === '' ? 0 : $clean), 2, '.', '');
}

function moto_qty(mixed $value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$value);
    $qty = (float)($clean === '' ? 0 : $clean);
    return round($qty, 4);
}

function moto_money_float(mixed $value): float
{
    return (float)moto_money($value);
}

// ── Audit (module-owned, append-only) ─────────────────────────────

/**
 * Append an immutable audit row. Pass $db (the caller's transaction
 * connection) to make the audit write atomic with the business mutation:
 * if the audit fails the surrounding transaction rolls back and no
 * misleading partial commit is reported.
 */
function moto_audit(
    array $ctx,
    string $action,
    ?string $targetType = null,
    ?string $targetId = null,
    mixed $before = null,
    mixed $after = null,
    ?int $branchId = null,
    ?string $idempotencyKey = null,
    ?\Ikabud\Kernel\Contracts\ModuleDB $db = null
): void {
    // Audit persistence is part of the mutation contract. Never convert a
    // failed write into an apparent success; callers may include this insert
    // in their transaction, and otherwise the API must surface the failure.
    $db = $db ?? moto_db((int)($ctx['tenant_id'] ?? 0));
    $stmt = $db->prepare(
        'INSERT INTO moto_audit_log
            (tenant_id, branch_id, actor_user_id, actor_name, action, target_type, target_id, request_id, idempotency_key, before_data, after_data)
         VALUES (:tid, :bid, :uid, :actor, :action, :ttype, :tid2, :req, :idem, :before, :after)'
    );
    $stmt->execute([
        ':tid'    => (int)($ctx['tenant_id'] ?? 0),
        ':bid'    => $branchId,
        ':uid'    => (int)($ctx['user_id'] ?? 0) ?: null,
        ':actor'  => (string)($ctx['actor_name'] ?? ''),
        ':action' => $action,
        ':ttype'  => $targetType,
        ':tid2'   => $targetId,
        ':req'    => function_exists('request_id') ? request_id() : null,
        ':idem'   => $idempotencyKey,
        ':before' => $before !== null ? json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ':after'  => $after !== null ? json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
    ]);
}

// ── Events ────────────────────────────────────────────────────────

function moto_emit_event(string $key, array $payload = []): void
{
    try {
        app()->events()->fire($key, $payload, 'moto-inventory');
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('moto event fire failed: ' . $e->getMessage(), 'warning', ['event' => $key]);
        }
    }
}

// ── Idempotency ───────────────────────────────────────────────────

/**
 * Return the stored response for a completed idempotency key, or null when
 * the key has not been used yet. A key reused with a different request
 * payload is a client error (422).
 */
function moto_idem_fetch(array $ctx, string $key, string $operation, array $requestPayload, ?int $branchId = null): ?array
{
    $branchId = $branchId ?? (int)($ctx['branch_id'] ?? 0);
    if ($key === '' || $key === null) {
        return null;
    }
    try {
        $db = moto_db((int)($ctx['tenant_id'] ?? 0));
        $stmt = $db->prepare(
            'SELECT response_payload, request_hash FROM moto_idempotency_keys
             WHERE tenant_id = :tid AND branch_id = :bid AND idempotency_key = :key AND operation = :op LIMIT 1'
        );
        $stmt->execute([
            ':tid' => (int)($ctx['tenant_id'] ?? 0),
            ':bid' => $branchId,
            ':key' => $key,
            ':op'  => $operation,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['response_payload'] === null) {
            return null;
        }
        $hash = (string)($row['request_hash'] ?? '');
        $expected = hash('sha256', (string)json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($hash !== '' && !hash_equals($expected, $hash)) {
            throw new \InvalidArgumentException('Idempotency key reused with a different request payload');
        }

        return json_decode((string)$row['response_payload'], true) ?: [];
    } catch (\Throwable $e) {
        throw $e;
    }
}

/**
 * Claim an idempotency key inside the CALLER's open transaction. The unique
 * (tenant, branch, key, operation) constraint makes the claim the concurrency
 * guard: exactly one request can insert the row, so only it may write the
 * business data. Returns true when this request owns the claim, false when a
 * concurrent request already claimed it (its response is either pending or
 * already recorded).
 *
 * Because the claim is inserted inside the transaction, a failed/rolled-back
 * owner releases the claim automatically — no stale "pending" rows survive.
 */
function moto_idem_claim(\Ikabud\Kernel\Contracts\ModuleDB $db, array $ctx, string $key, string $operation, array $requestPayload, ?int $branchId = null): bool
{
    $branchId = $branchId ?? (int)($ctx['branch_id'] ?? 0);
    if ($key === '' || $key === null) {
        return true; // no dedup key → this caller owns the operation
    }
    $stmt = $db->prepare(
        'INSERT IGNORE INTO moto_idempotency_keys
            (tenant_id, branch_id, idempotency_key, operation, request_hash, request_payload, response_payload)
         VALUES (:tid, :bid, :key, :op, :hash, :req, NULL)'
    );
    $stmt->execute([
        ':tid'  => (int)($ctx['tenant_id'] ?? 0),
        ':bid'  => $branchId,
        ':key'  => $key,
        ':op'   => $operation,
        ':hash' => hash('sha256', (string)json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ':req'  => json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    return $stmt->rowCount() === 1;
}

/**
 * Record the completed response for a key this request claimed. Must be called
 * inside the same transaction as moto_idem_claim() so the response becomes
 * durable atomically with the business write (no window where a retry could
 * hit a unique-key failure without a stored response to return).
 */
function moto_idem_complete(\Ikabud\Kernel\Contracts\ModuleDB $db, array $ctx, string $key, string $operation, array $responsePayload, ?int $branchId = null): void
{
    $branchId = $branchId ?? (int)($ctx['branch_id'] ?? 0);
    if ($key === '' || $key === null) {
        return;
    }
    $stmt = $db->prepare(
        'UPDATE moto_idempotency_keys SET response_payload = :res
         WHERE tenant_id = :tid AND branch_id = :bid AND idempotency_key = :key AND operation = :op'
    );
    $stmt->execute([
        ':res' => json_encode($responsePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':tid' => (int)($ctx['tenant_id'] ?? 0),
        ':bid' => $branchId,
        ':key' => $key,
        ':op'  => $operation,
    ]);
}

/**
 * Wait (bounded) for a concurrent request that owns the key to record its
 * committed response, then return it deterministically. If the owner never
 * completes (e.g. it rolled back and released the claim), this throws so the
 * client can retry and reclaim the key.
 */
function moto_idem_wait_fetch(array $ctx, string $key, string $operation, array $requestPayload, ?int $branchId = null): array
{
    for ($i = 0; $i < 20; $i++) {
        $cached = moto_idem_fetch($ctx, $key, $operation, $requestPayload, $branchId);
        if ($cached !== null) {
            return $cached;
        }
        usleep(100000); // 100 ms
    }
    throw new \RuntimeException('Request is being processed concurrently; retry shortly');
}

// ── MICHAELSON code cipher (legacy price code) ────────────────────

function moto_code_cipher_map(): array
{
    return [
        'M' => '1', 'I' => '2', 'C' => '3', 'H' => '4', 'A' => '5',
        'E' => '6', 'L' => '7', 'S' => '8', 'O' => '9', 'N' => '0',
    ];
}

/**
 * Decode a MICHAELSON price code to a numeric price, or null when it
 * contains a letter outside the cipher.
 */
function moto_code_to_price(mixed $code): ?float
{
    $code = strtoupper(trim((string)$code));
    if ($code === '') {
        return null;
    }
    $cipher = moto_code_cipher_map();
    $digits = '';
    foreach (str_split($code) as $char) {
        if (ctype_digit($char)) {
            $digits .= $char;
            continue;
        }
        if (!isset($cipher[$char])) {
            return null;
        }
        $digits .= $cipher[$char];
    }
    if ($digits === '') {
        return null;
    }

    return (float)$digits;
}

// ── Validation helpers ────────────────────────────────────────────

function moto_require_permission(array $ctx, string $permission): void
{
    if (!in_array($permission, $ctx['permissions'] ?? [], true)) {
        throw new \RuntimeException('Forbidden');
    }
}

function moto_require_write_branch(array $ctx, ?int $branchId): int
{
    if ($branchId === null || $branchId <= 0) {
        throw new \RuntimeException('A branch is required');
    }
    $branchIds = $ctx['branch_ids'] ?? [];
    $canSee = ($ctx['view_all_branches'] ?? false) || in_array($branchId, $branchIds, true);
    if (!$canSee) {
        throw new \RuntimeException('Branch access denied');
    }

    return $branchId;
}

function moto_branch_name(int $tenantId, int $branchId): string
{
    try {
        $db = moto_db($tenantId);
        $stmt = $db->prepare('SELECT name FROM moto_branches WHERE tenant_id = :tid AND id = :bid LIMIT 1');
        $stmt->execute([':tid' => $tenantId, ':bid' => $branchId]);
        $name = $stmt->fetchColumn();
        return is_string($name) ? $name : ('Branch #' . $branchId);
    } catch (\Throwable $e) {
        return 'Branch #' . $branchId;
    }
}

// ── Tenant entry-module home redirect ─────────────────────────────
//
// When this tenant's entry module is moto-inventory, an authenticated user's
// home redirect lands in the module dashboard. Superadmin is handled by the
// kernel before this hook fires; the persona roles listed below are the ones
// the module's permission map understands.
app()->hooks()->on('kernel.home_url', static function (?string $url, string $role, ?array $user = null) {
    if (!in_array((string)$role, ['admin', 'manager', 'cashier', 'owner'], true)) {
        return $url;
    }
    if (!function_exists('tenantEntryModuleIdForTenant')) {
        return $url;
    }
    try {
        $tenantId = app()->tenant()->current();
    } catch (\Throwable $e) {
        return $url;
    }
    if ($tenantId === null || tenantEntryModuleIdForTenant((int)$tenantId) !== 'moto-inventory') {
        return $url;
    }
    return '/moto-inventory';
}, 80);

// ── Entry-module login page context ───────────────────────────────
// Renders a Moto-branded login page on the kernel /login route for this
// tenant (resolved by kernelResolveEntryModuleLoginContext()). Kernel auth is
// the identity authority, so the form posts to the kernel canonical /auth/login
// with preferred_source=kernel; the same-origin icon URL is used rather than an
// embedded copy of the logo.
function moto_inventoryLoginPageContext(array $overrides = []): array
{
    $appName = 'Moto Inventory';
    $escapedAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $logoUrl = '/moto-inventory/icon-192.png';
    $brandMarkHtml = '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $escapedAppName . ' logo">';

    return array_merge([
        'page_title' => $appName . ' Sign In',
        'app_name' => $appName,
        'logo_url' => $logoUrl,
        'favicon_url' => '/moto-inventory/favicon.png',
        'login_favicon_url' => '/moto-inventory/favicon.png',
        'brand_mark_html' => $brandMarkHtml,
        'login_logo_html' => $brandMarkHtml,
        'login_brand_text' => $appName,
        'login_brand_html' => $escapedAppName,
        'login_subtitle' => 'Inventory · Sales · Profit',
        'login_username_label' => 'Username',
        'login_endpoint' => '/api/v1/auth/login',
        'login_preferred_source' => 'kernel',
        'login_button_text' => 'Sign In',
        'login_loading_text' => 'Signing in...',
        'login_forgot_url' => '/forgot-password',
        'login_forgot_text' => 'Forgot password?',
        'gui' => [
            'app_name' => $appName,
            'app_name_accent' => $appName,
            'app_name_rest' => '',
            'font_url' => 'https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap',
            'font_family' => 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'color_primary' => '#d97706',
            'color_primary_hover' => '#b45309',
            'color_primary_light' => 'rgba(217, 119, 6, 0.18)',
            'color_bg' => 'linear-gradient(150deg, #1a1a1a 0%, #2b2b2b 55%, #3a2c14 100%)',
            'color_surface' => 'rgba(255, 255, 255, 0.97)',
            'color_border' => '#e3e0d6',
            'color_text' => '#1a1a1a',
            'color_text_muted' => '#6b675e',
            'css_overrides' => '.login-card{max-width:400px;border:1px solid rgba(217,119,6,.25);box-shadow:0 28px 80px rgba(0,0,0,.35)}.login-mark{background:#1a1a1a;border:1px solid rgba(217,119,6,.4)}.login-logo h1{letter-spacing:-.02em}.login-logo p{color:#6b675e}.form-label{text-transform:uppercase;letter-spacing:.08em;font-size:11px;color:#6b675e}.form-input{background:rgba(255,255,255,.9)}.btn-login{box-shadow:0 14px 30px rgba(217,119,6,.28)}',
        ],
    ], $overrides);
}
