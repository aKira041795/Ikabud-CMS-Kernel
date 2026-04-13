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

    $storeHome = (!$isAdmin && function_exists('kernelResolveStorePortalHomeRedirect'))
        ? kernelResolveStorePortalHomeRedirect($user)
        : null;

    if (!$isAdmin && is_string($storeHome) && str_starts_with($storeHome, '/ecommerce/store-admin/')) {
        app()->redirect($storeHome);
    }

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

function ecStoreAdminLoadStore(int $storeId): array
{
    $store = ecStoreById($storeId);
    if (!is_array($store)) {
        http_response_code(404);
        echo 'Store not found.';
        exit;
    }

    return $store;
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
    $store = ecStoreAdminLoadStore($id);

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
    $store = ecStoreAdminLoadStore($id);

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
    $store = ecStoreAdminLoadStore($id);

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

    $permissions = ecStoreAdminPermissions((string)($user['store_role'] ?? 'supervisor'));
    $canEdit   = !empty($permissions['edit_products']);

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

function ecStoreAdminProductCreate(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id, ['owner', 'manager']);
    $store = ecStoreAdminLoadStore($id);

    $categories = ecDb()->query(
        ecCmsCategorySelectSql('id, name', 'name ASC')
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();
        $featuredImageId = null;
        $uploadedImage = ecUploadProductFeaturedImage(kernelUploadedFile('featured_image') ?? [], (int)($user['id'] ?? 0));
        if (is_array($uploadedImage) && !empty($uploadedImage['id'])) {
            $featuredImageId = (int)$uploadedImage['id'];
        }

        try {
            $productId = ecProductCreate([
                'title' => $input['title'] ?? 'New Product',
                'slug' => $input['slug'] ?? '',
                'excerpt' => $input['excerpt'] ?? '',
                'body' => $input['body'] ?? '',
                'status' => $input['status'] ?? 'draft',
                'price' => $input['price'] ?? null,
                'sale_price' => $input['sale_price'] ?? null,
                'sku' => $input['sku'] ?? '',
                'stock_qty' => $input['stock_qty'] ?? 0,
                'track_stock' => ($input['track_stock'] ?? 'on') === 'on',
                'category_id' => ($input['category_id'] ?? '') !== '' ? (int)$input['category_id'] : null,
                'featured_image_id' => $featuredImageId,
            ], (int)($user['id'] ?? 0));

            ecProductSaveStoreAssignments($productId, [$id]);
            $_SESSION['ec_sa_message'] = ['type' => 'success', 'text' => 'Product created.'];
            header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/products/' . $productId . '/edit');
            exit;
        } catch (\Throwable $e) {
            $error = 'Failed to create product: ' . $e->getMessage();
            $inputState = $input;
        }
    }

    $ctx = ecStoreAdminContext($user, $store, 'products', [
        'product' => null,
        'categories' => $categories,
        'selected_category_id' => 0,
        'message' => $_SESSION['ec_sa_message'] ?? null,
        'error' => $error ?? null,
        'input' => $inputState ?? [],
        'is_new' => true,
        'shared_catalog_product' => false,
    ]);
    unset($_SESSION['ec_sa_message']);

    ecRender('modules/ecommerce/admin/store-admin-product-edit.disyl', $ctx);
}

function ecStoreAdminProductEdit(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $productId = (int)($params['productId'] ?? 0);
    $user = ecRequireStoreAccess($id, ['owner', 'manager']);
    $store = ecStoreAdminLoadStore($id);

    if (!ecStoreOwnsProduct($id, $productId)) {
        http_response_code(404);
        echo 'Product not found for this store.';
        exit;
    }

    $product = ecProductGet($productId, false);
    if (!$product) {
        http_response_code(404);
        echo 'Product not found.';
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();

        try {
            $featuredImageId = array_key_exists('featured_image_id', $input) ? $input['featured_image_id'] : ($product['featured_image_id'] ?? null);
            if (($input['remove_featured_image'] ?? '') === '1') {
                $featuredImageId = null;
            }

            $uploadedImage = ecUploadProductFeaturedImage(kernelUploadedFile('featured_image') ?? [], (int)($user['id'] ?? 0));
            if (is_array($uploadedImage) && !empty($uploadedImage['id'])) {
                $featuredImageId = (int)$uploadedImage['id'];
            }

            ecProductUpdate($productId, [
                'title' => $input['title'] ?? $product['title'],
                'slug' => $input['slug'] ?? $product['slug'],
                'excerpt' => $input['excerpt'] ?? $product['excerpt'],
                'body' => $input['body'] ?? $product['body'],
                'status' => $input['status'] ?? $product['status'],
                'price' => $input['price'] ?? ($product['pricing']['price'] ?? null),
                'sale_price' => $input['sale_price'] ?? ($product['pricing']['sale_price'] ?? null),
                'sku' => $input['sku'] ?? ($product['inventory']['sku'] ?? ''),
                'stock_qty' => $input['stock_qty'] ?? ($product['inventory']['stock_qty'] ?? 0),
                'track_stock' => ($input['track_stock'] ?? (!empty($product['inventory']['track_stock']) ? 'on' : 'off')) === 'on',
                'category_id' => ($input['category_id'] ?? '') !== '' ? (int)$input['category_id'] : null,
                'featured_image_id' => $featuredImageId,
            ]);

            $_SESSION['ec_sa_message'] = ['type' => 'success', 'text' => 'Product saved.'];
            header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/products/' . $productId . '/edit');
            exit;
        } catch (\Throwable $e) {
            $error = 'Save failed: ' . $e->getMessage();
            $inputState = $input;
        }

        $product = ecProductGet($productId, false) ?: $product;
    }

    $categories = ecDb()->query(
        ecCmsCategorySelectSql('id, name', 'name ASC')
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $assignmentMap = ecProductStoreAssignmentMap([$productId]);
    $assignedStores = $assignmentMap[$productId] ?? [];

    $ctx = ecStoreAdminContext($user, $store, 'products', [
        'product' => $product,
        'categories' => $categories,
        'selected_category_id' => (int)($product['categories'][0]['id'] ?? 0),
        'message' => $_SESSION['ec_sa_message'] ?? null,
        'error' => $error ?? null,
        'input' => $inputState ?? [],
        'is_new' => false,
        'shared_catalog_product' => count($assignedStores) > 1,
    ]);
    unset($_SESSION['ec_sa_message']);

    ecRender('modules/ecommerce/admin/store-admin-product-edit.disyl', $ctx);
}

function ecStoreAdminReports(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id, ['owner', 'manager']);
    $store = ecStoreAdminLoadStore($id);
    $input = ecInput();
    $reportParams = [
        'period' => $input['period'] ?? 'month',
        'start_date' => $input['start_date'] ?? '',
        'end_date' => $input['end_date'] ?? '',
        'store_id' => $id,
    ];

    $ctx = ecStoreAdminContext($user, $store, 'reports', [
        'params' => $reportParams,
        'sales' => ecReportSales($reportParams),
        'inventory' => ecReportInventory(['store_id' => $id]),
    ]);

    ecRender('modules/ecommerce/admin/store-admin-reports.disyl', $ctx);
}

function ecStoreAdminReturns(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id, ['owner', 'manager']);
    $store = ecStoreAdminLoadStore($id);
    $permissions = ecStoreAdminPermissions((string)($user['store_role'] ?? 'supervisor'));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($permissions['review_returns'])) {
            http_response_code(403);
            exit;
        }

        csrf_verify();
        $input = ecInput();
        $requestId = (int)($input['request_id'] ?? 0);
        $action = trim((string)($input['action'] ?? ''));

        if ($requestId > 0 && ecReturnRequestBelongsToStore($requestId, $id)) {
            $status = match ($action) {
                'approve' => 'approved',
                'reject' => 'rejected',
                'cancel' => 'cancelled',
                default => '',
            };

            if ($status !== '') {
                try {
                    ecReturnRequestReview($requestId, $status, [
                        'reviewed_by_user_id' => (int)($user['id'] ?? 0),
                        'admin_note' => trim((string)($input['admin_note'] ?? '')),
                    ]);
                    $_SESSION['ec_sa_message'] = ['type' => 'success', 'text' => 'Return request updated.'];
                } catch (\Throwable $e) {
                    $_SESSION['ec_sa_message'] = ['type' => 'error', 'text' => 'Could not update return request: ' . $e->getMessage()];
                }
            }
        }

        header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/returns');
        exit;
    }

    $input = ecInput();
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = 20;
    $status = trim((string)($input['status'] ?? ''));
    $result = ecReturnRequestList([
        'store_id' => $id,
        'status' => $status,
        'limit' => $limit,
        'offset' => ($page - 1) * $limit,
    ]);

    $ctx = ecStoreAdminContext($user, $store, 'returns', [
        'return_requests' => $result['items'] ?? [],
        'filters' => ['status' => $status],
        'total' => (int)($result['total'] ?? 0),
        'total_pages' => max(1, (int)ceil(((int)($result['total'] ?? 0)) / $limit)),
        'page' => $page,
        'message' => $_SESSION['ec_sa_message'] ?? null,
        'can_edit' => !empty($permissions['review_returns']),
    ]);
    unset($_SESSION['ec_sa_message']);

    ecRender('modules/ecommerce/admin/store-admin-returns.disyl', $ctx);
}

function ecStoreAdminCustomers(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id, ['owner', 'manager']);
    $store = ecStoreAdminLoadStore($id);
    $input = ecInput();
    $search = trim((string)($input['search'] ?? ''));
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = 25;

    $result = ecStoreCustomerList($id, [
        'search' => $search,
        'limit' => $limit,
        'offset' => ($page - 1) * $limit,
    ]);

    $ctx = ecStoreAdminContext($user, $store, 'customers', [
        'customers' => $result['items'] ?? [],
        'total' => (int)($result['total'] ?? 0),
        'total_pages' => max(1, (int)ceil(((int)($result['total'] ?? 0)) / $limit)),
        'page' => $page,
        'search' => $search,
    ]);

    ecRender('modules/ecommerce/admin/store-admin-customers.disyl', $ctx);
}

function ecStoreAdminCategories(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id, ['owner', 'manager']);
    $store = ecStoreAdminLoadStore($id);

    $ctx = ecStoreAdminContext($user, $store, 'categories', [
        'categories' => ecStoreCategoryList($id),
    ]);

    ecRender('modules/ecommerce/admin/store-admin-categories.disyl', $ctx);
}

function ecStoreAdminAbandonedCarts(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id, ['owner', 'manager']);
    $store = ecStoreAdminLoadStore($id);

    $ctx = ecStoreAdminContext($user, $store, 'abandoned_carts', [
        'abandoned_cart_metrics' => ecStoreAbandonedCartMetrics($id),
        'abandoned_carts' => ecStoreAbandonedCartList($id, 75),
    ]);

    ecRender('modules/ecommerce/admin/store-admin-abandoned-carts.disyl', $ctx);
}

function ecStoreAdminSettings(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id, ['owner']);
    $store = ecStoreAdminLoadStore($id);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();
        $action = trim((string)($input['action'] ?? 'save'));

        if ($action === 'save_inventory_source') {
            $sourceType = trim((string)($input['inventory_source_type'] ?? 'local'));
            $warehouseId = max(0, (int)($input['inventory_warehouse_id'] ?? 0)) ?: null;
            $result = ecStoreSaveInventorySource($id, $sourceType, $warehouseId);
            $_SESSION['ec_sa_message'] = $result['ok']
                ? ['type' => 'success', 'text' => 'Inventory source saved.']
                : ['type' => 'error', 'text' => $result['error']];
            header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/settings');
            exit;
        }

        if ($action === 'assign_user') {
            $assignUserId = (int)($input['assign_user_id'] ?? 0);
            $assignRole = trim((string)($input['assign_role'] ?? 'manager'));
            $result = ecStoreUserAssign($id, $assignUserId, $assignRole);
            $_SESSION['ec_sa_message'] = $result['ok']
                ? ['type' => 'success', 'text' => 'Store user assigned.']
                : ['type' => 'error', 'text' => $result['error']];
            header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/settings');
            exit;
        }

        if ($action === 'remove_user') {
            $removeUserId = (int)($input['remove_user_id'] ?? 0);
            $result = ecStoreUserRemove($id, $removeUserId);
            $_SESSION['ec_sa_message'] = $result['ok']
                ? ['type' => 'success', 'text' => 'Store user removed.']
                : ['type' => 'error', 'text' => $result['error']];
            header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/settings');
            exit;
        }

        $result = ecStoreUpdate($id, [
            'name' => $input['name'] ?? $store['name'],
            'code' => $input['code'] ?? $store['code'],
            'slug' => $input['slug'] ?? $store['slug'],
            'description' => $input['description'] ?? $store['description'],
            'announcement' => $input['announcement'] ?? ($store['announcement'] ?? ''),
            'banner_image_id' => max(0, (int)($input['banner_image_id'] ?? ($store['banner_image_id'] ?? 0))) ?: null,
            'logo_image_id' => max(0, (int)($input['logo_image_id'] ?? ($store['logo_image_id'] ?? 0))) ?: null,
            'is_active' => !empty($input['is_active']),
            'is_default' => !empty($store['is_default']),
            'settings_json' => ecStoreSettingsJsonFromInput($input),
        ]);

        $_SESSION['ec_sa_message'] = $result['ok']
            ? ['type' => 'success', 'text' => 'Store settings saved.']
            : ['type' => 'error', 'text' => $result['error']];
        header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/settings');
        exit;
    }

    $inputData = $store;
    $rawSettings = trim((string)($store['settings_json'] ?? ''));
    if ($rawSettings !== '') {
        $decoded = json_decode($rawSettings, true);
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                $inputData['setting_' . $key] = $value;
            }
        }
    }

    $cmsUsersList = [];
    try {
        ecDb()->query('SELECT 1 FROM cms_users LIMIT 1');
        $cmsUsersList = ecDb()->query(
            'SELECT id, username, display_name, email FROM cms_users WHERE is_active = 1 ORDER BY display_name ASC, username ASC'
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $ignored) {
    }

    $ctx = ecStoreAdminContext($user, $store, 'settings', [
        'input' => $inputData,
        'store_users' => ecStoreUserList($id),
        'store_branding_supported' => ecStoreBrandingColumnsAvailable(),
        'cms_users_list' => $cmsUsersList,
        'inventory_source' => ecStoreInventorySource($id),
        'warehouses' => ecStoreInventoryWarehouseOptions(),
        'message' => $_SESSION['ec_sa_message'] ?? null,
    ]);
    unset($_SESSION['ec_sa_message']);

    ecRender('modules/ecommerce/admin/store-admin-settings.disyl', $ctx);
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
    $user = ecRequireStoreAccess($id, ['owner', 'manager']);
    $store = ecStoreAdminLoadStore($id);

    $permissions = ecStoreAdminPermissions((string)($user['store_role'] ?? 'supervisor'));
    $canEdit   = !empty($permissions['manage_coupons']);

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
    $store = ecStoreAdminLoadStore($id);

    $permissions = ecStoreAdminPermissions((string)($user['store_role'] ?? 'supervisor'));
    $canEdit   = !empty($permissions['moderate_reviews']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
        csrf_verify();
        $input    = ecInput();
        $reviewId = (int)($input['review_id'] ?? $input['id'] ?? 0);
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

