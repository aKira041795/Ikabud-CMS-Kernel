<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Per-Store Admin Access (handlers/74-store-admin-access.php)
//
// These handlers allow users assigned in ec_store_users (owner, manager,
// supervisor) to perform administrative functions on their specific store(s)
// without requiring a system-level CMS administrator role.
//
// Access gate: ecRequireStoreAccess() — CMS admins pass through unconditionally;
// non-admins must have a matching ec_store_users row for the target store.
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /ecommerce/my-stores
 *
 * Lists all stores the currently logged-in user is assigned to manage.
 * System administrators are redirected to the full admin panel instead.
 * Any logged-in CMS user (customer role and above) can reach this page.
 */
function ecMyStores(): void
{
    if (!function_exists('cmsRequireRole')) {
        http_response_code(503);
        exit;
    }
    $user   = cmsRequireRole('customer');
    $role   = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    $isAdmin = $source === 'kernel'
        || (function_exists('cmsRoleAtLeast') && cmsRoleAtLeast($role, 'administrator'));

    $userId = (int)($user['id'] ?? 0);
    $stores = (!$isAdmin && $userId > 0) ? ecStoresForUser($userId) : [];

    $ecSettings = ecSettings();
    $ctx = [
        'user'         => $user,
        'stores'       => $stores,
        'is_admin'     => $isAdmin,
        'current_page' => 'my-stores',
        'page_title'   => 'My Store Access',
        'base_url'     => ecGetBaseUrl(),
        'csrf_token'   => app()->csrfToken(),
        'csrf_field'   => app()->csrfField(),
        'ec_settings'  => $ecSettings,
        'currency'     => (string)($ecSettings['currency'] ?? ''),
        'currency_sym' => (string)($ecSettings['currency_symbol'] ?? ''),
    ];
    ecRender('modules/ecommerce/admin/my-stores.disyl', $ctx);
}

/**
 * GET /ecommerce/store-admin/{id}
 *
 * Per-store admin dashboard. Shows stats (order count, product count),
 * recent orders for this store, the store team list, and inventory source.
 * Accessible to owners, managers, supervisors, and system admins.
 */
function ecStoreAdminDashboard(array $params = []): void
{
    $id    = (int)($params['id'] ?? 0);
    $user  = ecRequireStoreAccess($id);

    $store = ecDb()->query('SELECT * FROM ec_stores WHERE id = ? LIMIT 1', [$id])
        ->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($store)) {
        http_response_code(404);
        echo 'Store not found.';
        exit;
    }

    $recentOrders = ecOrderList(['store_id' => $id, 'limit' => 10, 'offset' => 0]);

    $productCount = 0;
    try {
        $pc = ecProductList(['store_id' => $id, 'limit' => 1, 'offset' => 0]);
        $productCount = (int)($pc['total'] ?? 0);
    } catch (\Throwable $e) {}

    $orderCount = (int)($recentOrders['total'] ?? 0);
    $orderTotal = 0.0;
    foreach ($recentOrders['items'] ?? [] as $o) {
        $orderTotal += (float)($o['total'] ?? 0.0);
    }

    $ctx = ecStoreAdminContext($user, $store, 'dashboard', [
        'recent_orders' => $recentOrders['items'] ?? [],
        'order_count'   => $orderCount,
        'order_total'   => number_format($orderTotal, 2),
        'product_count' => $productCount,
        'store_users'   => ecStoreUserList($id),
        'inv_source'    => ecStoreInventorySource($id),
    ]);
    ecRender('modules/ecommerce/admin/store-admin-dashboard.disyl', $ctx);
}

/**
 * GET /ecommerce/store-admin/{id}/orders
 *
 * Paginated order list scoped to this store.
 * Accessible to owners, managers, and supervisors (read-only for supervisors).
 */
function ecStoreAdminOrders(array $params = []): void
{
    $id   = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id);

    $store = ecDb()->query('SELECT * FROM ec_stores WHERE id = ? LIMIT 1', [$id])
        ->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($store)) {
        http_response_code(404);
        echo 'Store not found.';
        exit;
    }

    $input  = ecInput();
    $page   = max(1, (int)($input['page'] ?? 1));
    $limit  = 25;
    $result = ecOrderList([
        'store_id' => $id,
        'status'   => trim((string)($input['status'] ?? '')),
        'search'   => trim((string)($input['search'] ?? '')),
        'limit'    => $limit,
        'offset'   => ($page - 1) * $limit,
    ]);

    $ctx = ecStoreAdminContext($user, $store, 'orders', [
        'orders'        => $result['items'] ?? [],
        'total'         => (int)($result['total'] ?? 0),
        'total_pages'   => max(1, (int)ceil(((int)($result['total'] ?? 0)) / $limit)),
        'page'          => $page,
        'search'        => $input['search'] ?? '',
        'status_filter' => $input['status'] ?? '',
        'statuses'      => ['pending', 'processing', 'complete', 'cancelled', 'refunded'],
    ]);
    ecRender('modules/ecommerce/admin/store-admin-orders.disyl', $ctx);
}

/**
 * GET /ecommerce/store-admin/{id}/products
 *
 * Paginated product list scoped to this store.
 * Owners and managers can edit products; supervisors see read-only view.
 */
function ecStoreAdminProducts(array $params = []): void
{
    $id   = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id);

    $store = ecDb()->query('SELECT * FROM ec_stores WHERE id = ? LIMIT 1', [$id])
        ->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($store)) {
        http_response_code(404);
        echo 'Store not found.';
        exit;
    }

    $input  = ecInput();
    $page   = max(1, (int)($input['page'] ?? 1));
    $limit  = 25;
    $result = ecProductList([
        'store_id'        => $id,
        'store_owned_only'=> true,
        'status'          => trim((string)($input['status'] ?? '')),
        'search'          => trim((string)($input['search'] ?? '')),
        'limit'           => $limit,
        'offset'          => ($page - 1) * $limit,
    ]);

    $storeRole = (string)($user['store_role'] ?? 'supervisor');
    $canEdit   = in_array($storeRole, ['owner', 'manager', 'administrator'], true);

    $ctx = ecStoreAdminContext($user, $store, 'products', [
        'products'    => $result['items'] ?? [],
        'total'       => (int)($result['total'] ?? 0),
        'total_pages' => max(1, (int)ceil(((int)($result['total'] ?? 0)) / $limit)),
        'page'        => $page,
        'search'      => $input['search'] ?? '',
        'can_edit'    => $canEdit,
    ]);
    ecRender('modules/ecommerce/admin/store-admin-products.disyl', $ctx);
}

// ─────────────────────────────────────────────────────────────────────────
// Store-scoped Coupons
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET|POST /ecommerce/store-admin/{id}/coupons
 *
 * Owners and managers can create/toggle/delete coupons scoped to their store.
 * Supervisors see a read-only list.
 */
function ecStoreAdminCoupons(array $params = []): void
{
    $id   = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id);

    $store = ecDb()->query('SELECT * FROM ec_stores WHERE id = ? LIMIT 1', [$id])
        ->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($store)) {
        http_response_code(404);
        echo 'Store not found.';
        exit;
    }

    $storeRole = (string)($user['store_role'] ?? 'supervisor');
    $canEdit   = in_array($storeRole, ['owner', 'manager', 'administrator'], true);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canEdit) {
            http_response_code(403);
            exit;
        }
        csrf_verify();
        $input  = ecInput();
        $action = trim((string)($input['action'] ?? 'create'));

        if ($action === 'create') {
            $code = strtoupper(trim((string)($input['code'] ?? '')));
            if ($code !== '') {
                try {
                    ecDb()->execute(
                        "INSERT INTO ec_coupons (store_id, code, type, value, min_order_amount, max_uses, expires_at, description, is_active, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
                        [
                            $id,
                            $code,
                            ecCouponNormalizeType((string)($input['type'] ?? 'percent')),
                            max(0, (float)($input['value'] ?? 0)),
                            max(0, (float)($input['min_order_amount'] ?? 0)),
                            ($input['max_uses'] ?? '') !== '' ? (int)$input['max_uses'] : null,
                            ($input['expires_at'] ?? '') !== '' ? $input['expires_at'] : null,
                            trim((string)($input['description'] ?? '')),
                        ]
                    );
                    $_SESSION['ec_sa_message'] = ['type' => 'success', 'text' => 'Coupon created.'];
                } catch (\Throwable $e) {
                    $_SESSION['ec_sa_message'] = ['type' => 'error', 'text' => 'Could not create coupon: ' . $e->getMessage()];
                }
            } else {
                $_SESSION['ec_sa_message'] = ['type' => 'error', 'text' => 'Coupon code is required.'];
            }
        } elseif ($action === 'toggle') {
            $couponId = (int)($input['id'] ?? 0);
            if ($couponId > 0) {
                ecDb()->execute(
                    "UPDATE ec_coupons SET is_active = NOT is_active, updated_at = NOW() WHERE id = ? AND store_id = ?",
                    [$couponId, $id]
                );
                $_SESSION['ec_sa_message'] = ['type' => 'success', 'text' => 'Coupon updated.'];
            }
        } elseif ($action === 'delete') {
            $couponId = (int)($input['id'] ?? 0);
            if ($couponId > 0) {
                ecDb()->execute(
                    "DELETE FROM ec_coupons WHERE id = ? AND store_id = ?",
                    [$couponId, $id]
                );
                $_SESSION['ec_sa_message'] = ['type' => 'success', 'text' => 'Coupon deleted.'];
            }
        }

        header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/coupons');
        exit;
    }

    $coupons = ecDb()->query(
        "SELECT * FROM ec_coupons WHERE store_id = ? ORDER BY created_at DESC",
        [$id]
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $ctx = ecStoreAdminContext($user, $store, 'coupons', [
        'coupons'  => $coupons,
        'message'  => $_SESSION['ec_sa_message'] ?? null,
        'can_edit' => $canEdit,
    ]);
    unset($_SESSION['ec_sa_message']);
    ecRender('modules/ecommerce/admin/store-admin-coupons.disyl', $ctx);
}

// ─────────────────────────────────────────────────────────────────────────
// Store-scoped Reviews
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET|POST /ecommerce/store-admin/{id}/reviews
 *
 * Owners and managers can approve/reject reviews for their store's products.
 * Supervisors see a read-only list.
 */
function ecStoreAdminReviews(array $params = []): void
{
    $id   = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id);

    $store = ecDb()->query('SELECT * FROM ec_stores WHERE id = ? LIMIT 1', [$id])
        ->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($store)) {
        http_response_code(404);
        echo 'Store not found.';
        exit;
    }

    $storeRole = (string)($user['store_role'] ?? 'supervisor');
    $canEdit   = in_array($storeRole, ['owner', 'manager', 'administrator'], true);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
        csrf_verify();
        $input    = ecInput();
        $reviewId = (int)($input['id'] ?? 0);
        $newStatus = match (trim((string)($input['action'] ?? ''))) {
            'approve' => 'approved',
            'reject'  => 'rejected',
            'spam'    => 'spam',
            'pending' => 'pending',
            default   => '',
        };
        if ($reviewId > 0 && $newStatus !== '' && function_exists('ecReviewSetStatus')) {
            ecReviewSetStatus($reviewId, $newStatus, (int)($user['id'] ?? 0));
        }
        header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/reviews');
        exit;
    }

    $input  = ecInput();
    $page   = max(1, (int)($input['page'] ?? 1));
    $limit  = 20;
    $status = trim((string)($input['status'] ?? 'pending'));
    $search = trim((string)($input['search'] ?? ''));

    $result = ecReviewList([
        'store_id' => $id,
        'status'   => $status === 'all' ? '' : $status,
        'search'   => $search,
        'limit'    => $limit,
        'offset'   => ($page - 1) * $limit,
    ]);

    $ctx = ecStoreAdminContext($user, $store, 'reviews', [
        'reviews'     => $result['items'] ?? [],
        'total'       => (int)($result['total'] ?? 0),
        'total_pages' => max(1, (int)ceil(((int)($result['total'] ?? 0)) / $limit)),
        'page'        => $page,
        'status'      => $status,
        'search'      => $search,
        'can_edit'    => $canEdit,
    ]);
    ecRender('modules/ecommerce/admin/store-admin-reviews.disyl', $ctx);
}

// ─────────────────────────────────────────────────────────────────────────
// REST API — Store User Assignments
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /api/v1/ecommerce/stores/list
 *
 * Returns all active stores as JSON. Used by the CMS admin users page to
 * populate the "add store assignment" dropdown.
 * Requires CMS administrator.
 */
function ecApiStoresList(): void
{
    header('Content-Type: application/json');
    ecRequireAdmin();

    if (!ecStoreStorageAvailable()) {
        echo json_encode(['ok' => true, 'stores' => []]);
        exit;
    }

    $result = ecStoreList(['active_only' => true, 'limit' => 500]);
    $stores = array_map(fn($s) => [
        'id'   => (int)$s['id'],
        'name' => $s['name'],
        'slug' => $s['slug'],
        'code' => $s['code'],
    ], $result['items']);

    echo json_encode(['ok' => true, 'stores' => $stores]);
    exit;
}

/**
 * POST /api/v1/ecommerce/store-users
 *
 * Assign or remove a user from a store. Called by the CMS admin users page JS.
 * Requires CMS administrator.
 *
 * Body: { action: 'assign'|'remove', user_id: int, store_id: int, role?: string }
 */
function ecApiStoreUsersManage(): void
{
    header('Content-Type: application/json');
    ecRequireAdmin();

    $input   = ecInput();
    $action  = trim((string)($input['action'] ?? 'assign'));
    $userId  = (int)($input['user_id'] ?? 0);
    $storeId = (int)($input['store_id'] ?? 0);

    if ($userId <= 0 || $storeId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'user_id and store_id are required']);
        exit;
    }

    if ($action === 'remove') {
        $result = ecStoreUserRemove($storeId, $userId);
    } else {
        $role   = trim((string)($input['role'] ?? 'manager'));
        $result = ecStoreUserAssign($storeId, $userId, $role);
    }

    if ($result['ok']) {
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $result['error']]);
    }
    exit;
}

