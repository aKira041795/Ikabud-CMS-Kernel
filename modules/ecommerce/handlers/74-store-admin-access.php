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
        $pc = ecProductList([
            'store_id' => $id,
            'store_owned_only' => true,
            'limit' => 1,
            'offset' => 0,
        ]);
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

function ecStoreAdminNotifications(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id);
    $store = ecStoreAdminLoadStore($id);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();
        $action = trim((string)($input['action'] ?? ''));
        if ($action === 'mark_all_read') {
            ecStoreNotificationMarkAllRead($id, (int)($user['id'] ?? 0));
        } elseif ($action === 'mark_read') {
            ecStoreNotificationMarkRead((int)($input['notification_id'] ?? 0), $id, (int)($user['id'] ?? 0));
        }

        header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/notifications');
        exit;
    }

    $notifications = ecStoreNotificationList($id, (int)($user['id'] ?? 0), 50, 0);
    $ctx = ecStoreAdminContext($user, $store, 'notifications', [
        'notifications' => $notifications['items'] ?? [],
        'notifications_total' => (int)($notifications['total'] ?? 0),
        'notifications_enabled' => ecStoreNotificationsStorageAvailable(),
    ]);

    ecRender('modules/ecommerce/admin/store-admin-notifications.disyl', $ctx);
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

function ecStoreAdminProductFormState(array $input = [], ?array $product = null): array
{
    $state = is_array($product) ? $product : [];
    $pricing = is_array($state['pricing'] ?? null) ? $state['pricing'] : [];
    $inventory = is_array($state['inventory'] ?? null) ? $state['inventory'] : [];
    $booking = is_array($state['booking'] ?? null) ? $state['booking'] : [];

    $normalizeBool = static function (string $key, bool $fallback = false) use ($input): bool {
        if (!array_key_exists($key, $input)) {
            return $fallback;
        }

        $value = $input[$key];
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string)$value, ['1', 'on', 'true', 'yes'], true);
    };

    $parseLines = static function (mixed $value): array {
        $lines = preg_split('/\r\n|\r|\n/', trim((string)$value)) ?: [];
        return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    };

    $state['title'] = (string)($input['title'] ?? $state['title'] ?? '');
    $state['slug'] = (string)($input['slug'] ?? $state['slug'] ?? '');
    $state['excerpt'] = (string)($input['excerpt'] ?? $state['excerpt'] ?? '');
    $state['body'] = (string)($input['body'] ?? $state['body'] ?? '');
    $state['status'] = (string)($input['status'] ?? $state['status'] ?? 'draft');
    $state['featured_image_id'] = $input['featured_image_id'] ?? ($state['featured_image_id'] ?? '');

    $state['pricing'] = array_merge($pricing, [
        'price' => $input['price'] ?? ($pricing['price'] ?? ''),
        'sale_price' => $input['sale_price'] ?? ($pricing['sale_price'] ?? ''),
    ]);

    $state['inventory'] = array_merge($inventory, [
        'sku' => (string)($input['sku'] ?? $inventory['sku'] ?? ''),
        'track_stock' => $normalizeBool('track_stock', (bool)($inventory['track_stock'] ?? true)),
        'stock_qty' => $input['stock_qty'] ?? ($inventory['stock_qty'] ?? 0),
    ]);

    $state['tax_class'] = function_exists('ecProductNormalizeTaxClass')
        ? ecProductNormalizeTaxClass($input['tax_class'] ?? ($state['tax_class'] ?? 'standard'))
        : (string)($input['tax_class'] ?? $state['tax_class'] ?? 'standard');

    $state['is_subscription'] = $normalizeBool('is_subscription', !empty($state['is_subscription']));
    $state['subscription_interval_unit'] = (string)($input['subscription_interval_unit'] ?? $state['subscription_interval_unit'] ?? 'month');
    $state['subscription_interval_count'] = (int)($input['subscription_interval_count'] ?? $state['subscription_interval_count'] ?? 1);
    $state['subscription_trial_days'] = (int)($input['subscription_trial_days'] ?? $state['subscription_trial_days'] ?? 0);
    $state['subscription_max_cycles'] = (int)($input['subscription_max_cycles'] ?? $state['subscription_max_cycles'] ?? 0);
    $state['subscription_grace_period_days'] = (int)($input['subscription_grace_period_days'] ?? $state['subscription_grace_period_days'] ?? 7);

    $state['is_membership_product'] = $normalizeBool('is_membership_product', !empty($state['is_membership_product']));
    $state['membership_tier'] = (string)($input['membership_tier'] ?? $state['membership_tier'] ?? 'member');
    $state['membership_duration_days'] = (int)($input['membership_duration_days'] ?? $state['membership_duration_days'] ?? 365);

    $state['booking'] = array_merge($booking, [
        'enabled' => $normalizeBool('booking_enabled', !empty($booking['enabled'])),
        'duration_minutes' => (int)($input['booking_duration_minutes'] ?? $booking['duration_minutes'] ?? 60),
        'notice_hours' => (int)($input['booking_notice_hours'] ?? $booking['notice_hours'] ?? 24),
        'available_weekdays' => array_map('intval', (array)($input['booking_available_weekdays'] ?? $booking['available_weekdays'] ?? [])),
        'time_slots' => array_key_exists('booking_time_slots', $input)
            ? $parseLines($input['booking_time_slots'])
            : (array)($booking['time_slots'] ?? []),
        'allow_reschedule' => $normalizeBool('booking_allow_reschedule', !empty($booking['allow_reschedule'])),
        'reschedule_cutoff_hours' => (int)($input['booking_reschedule_cutoff_hours'] ?? $booking['reschedule_cutoff_hours'] ?? 24),
        'allow_cancel' => $normalizeBool('booking_allow_cancel', !empty($booking['allow_cancel'])),
        'cancel_cutoff_hours' => (int)($input['booking_cancel_cutoff_hours'] ?? $booking['cancel_cutoff_hours'] ?? 24),
        'reminder_hours_before' => (int)($input['booking_reminder_hours_before'] ?? $booking['reminder_hours_before'] ?? 24),
    ]);

    $state['is_digital'] = $normalizeBool('is_digital', !empty($state['is_digital']));
    $state['license_module'] = (string)($input['license_module'] ?? $state['license_module'] ?? '');
    $state['license_tier'] = (string)($input['license_tier'] ?? $state['license_tier'] ?? 'pro');
    $state['license_duration_days'] = (int)($input['license_duration_days'] ?? $state['license_duration_days'] ?? 365);

    $state['is_external_product'] = $normalizeBool('is_external_product', !empty($state['is_external_product']));
    $state['external_product_url'] = (string)($input['external_product_url'] ?? $state['external_product_url'] ?? '');
    $state['external_product_button_text'] = (string)($input['external_product_button_text'] ?? $state['external_product_button_text'] ?? 'Buy Externally');

    $state['seo_title'] = (string)($input['seo_title'] ?? $state['seo_title'] ?? '');
    $state['seo_description'] = (string)($input['seo_description'] ?? $state['seo_description'] ?? '');
    $state['seo_canonical_url'] = (string)($input['seo_canonical_url'] ?? $state['seo_canonical_url'] ?? '');
    $state['seo_og_image'] = (string)($input['seo_og_image'] ?? $state['seo_og_image'] ?? '');

    return $state;
}

function ecStoreAdminProductEditorContext(
    array $user,
    array $store,
    array $product,
    array $input,
    array $options = []
): array {
    $categories = ecDb()->query(
        ecCmsCategorySelectSql('id, name', 'name ASC')
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $productId = (int)($product['id'] ?? 0);
    $attributeLines = array_key_exists('attribute_lines', $options)
        ? (string)$options['attribute_lines']
        : (function_exists('ecProductAttributesToLines') ? ecProductAttributesToLines((array)($product['attributes'] ?? [])) : '');
    $taxClass = (string)($options['tax_class'] ?? $product['tax_class'] ?? 'standard');
    $selectedRelationIds = $options['relation_ids']
        ?? (is_array($product['relation_ids'] ?? null) ? $product['relation_ids'] : ecProductDefaultRelationIds());
    $selectedBundleChildren = $options['bundle_children']
        ?? ($productId > 0 ? ecProductBundleChildSelections($productId) : []);
    $selectedGroupedChildren = $options['grouped_children']
        ?? ($productId > 0 ? ecProductGroupedChildSelections($productId) : []);
    $addonLines = array_key_exists('addon_lines', $options)
        ? (string)$options['addon_lines']
        : implode("\n", array_map(static function (array $addon): string {
            $parts = [trim((string)($addon['label'] ?? ''))];
            $parts[] = number_format((float)($addon['price'] ?? 0.0), 2, '.', '');
            if (trim((string)($addon['description'] ?? '')) !== '') {
                $parts[] = trim((string)$addon['description']);
            }
            return implode(' | ', $parts);
        }, is_array($product['addons'] ?? null) ? $product['addons'] : []));
    $requiredMembershipTiersText = array_key_exists('required_membership_tiers_text', $options)
        ? (string)$options['required_membership_tiers_text']
        : implode(', ', is_array($product['required_membership_tiers'] ?? null) ? $product['required_membership_tiers'] : []);
    $bookingTimeSlotsText = array_key_exists('booking_time_slots', $options)
        ? (string)$options['booking_time_slots']
        : implode("\n", is_array($product['booking']['time_slots'] ?? null) ? $product['booking']['time_slots'] : []);
    $selectedBookingWeekdays = array_key_exists('booking_available_weekdays', $input)
        ? array_map('intval', (array)$input['booking_available_weekdays'])
        : array_map('intval', is_array($product['booking']['available_weekdays'] ?? null) ? $product['booking']['available_weekdays'] : []);

    return ecStoreAdminContext($user, $store, 'products', [
        'product' => $product,
        'categories' => $categories,
        'selected_category_id' => (int)($options['selected_category_id'] ?? $product['categories'][0]['id'] ?? 0),
        'attribute_lines' => $attributeLines,
        'selected_tax_class' => $taxClass,
        'tax_class_options' => ecProductTaxClassOptions($taxClass),
        'relation_options' => ecProductAdminRelationOptions($productId, array_merge($selectedRelationIds, [
            'bundle_children' => $selectedBundleChildren,
            'grouped_children' => $selectedGroupedChildren,
        ])),
        'selected_relation_ids' => $selectedRelationIds,
        'selected_bundle_children' => $selectedBundleChildren,
        'selected_grouped_children' => $selectedGroupedChildren,
        'featured_image_url' => (string)($product['featured_image_url'] ?? ''),
        'addon_lines' => $addonLines,
        'required_membership_tiers_text' => $requiredMembershipTiersText,
        'booking_time_slots_text' => $bookingTimeSlotsText,
        'selected_booking_weekdays' => $selectedBookingWeekdays,
        'booking_weekday_flags' => [
            'sun' => in_array(0, $selectedBookingWeekdays, true),
            'mon' => in_array(1, $selectedBookingWeekdays, true),
            'tue' => in_array(2, $selectedBookingWeekdays, true),
            'wed' => in_array(3, $selectedBookingWeekdays, true),
            'thu' => in_array(4, $selectedBookingWeekdays, true),
            'fri' => in_array(5, $selectedBookingWeekdays, true),
            'sat' => in_array(6, $selectedBookingWeekdays, true),
        ],
        'seo_defaults' => ecProductSeoDefaults(),
        'error' => $options['error'] ?? null,
        'message' => $options['message'] ?? null,
        'is_new' => !empty($options['is_new']),
        'shared_catalog_product' => !empty($options['shared_catalog_product']),
    ]);
}

function ecStoreAdminProductCreate(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id, ['owner', 'manager']);
    $store = ecStoreAdminLoadStore($id);

    $input = [];
    $product = ecStoreAdminProductFormState();
    $attributeLines = '';
    $relationSelections = ecProductDefaultRelationIds();
    $bundleChildren = [];
    $groupedChildren = [];
    $taxClass = 'standard';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();
        $attributeLines = trim((string)($input['attribute_lines'] ?? ''));
        $relationSelections = ecProductRelationSelectionsFromInput($input);
        $bundleChildren = ecProductBundleSelectionsFromInput($input);
        $groupedChildren = ecProductGroupedSelectionsFromInput($input);
        $taxClass = function_exists('ecProductNormalizeTaxClass')
            ? ecProductNormalizeTaxClass($input['tax_class'] ?? 'standard')
            : 'standard';
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
                'attributes' => function_exists('ecProductParseAttributeLines') ? ecProductParseAttributeLines($attributeLines) : [],
                'relations' => $relationSelections,
                'bundle_children' => $bundleChildren,
                'grouped_children' => $groupedChildren,
                'tax_class' => $taxClass,
                'is_subscription' => !empty($input['is_subscription']),
                'subscription_interval_unit' => $input['subscription_interval_unit'] ?? 'month',
                'subscription_interval_count' => $input['subscription_interval_count'] ?? 1,
                'subscription_trial_days' => $input['subscription_trial_days'] ?? 0,
                'subscription_max_cycles' => $input['subscription_max_cycles'] ?? 0,
                'subscription_grace_period_days' => $input['subscription_grace_period_days'] ?? 7,
                'is_membership_product' => !empty($input['is_membership_product']),
                'membership_tier' => $input['membership_tier'] ?? 'member',
                'membership_duration_days' => $input['membership_duration_days'] ?? 365,
                'required_membership_tiers_text' => $input['required_membership_tiers_text'] ?? '',
                'addon_lines' => $input['addon_lines'] ?? '',
                'booking_enabled' => !empty($input['booking_enabled']),
                'booking_duration_minutes' => $input['booking_duration_minutes'] ?? 60,
                'booking_notice_hours' => $input['booking_notice_hours'] ?? 24,
                'booking_available_weekdays' => $input['booking_available_weekdays'] ?? [],
                'booking_time_slots' => $input['booking_time_slots'] ?? '',
                'booking_allow_reschedule' => !empty($input['booking_allow_reschedule']),
                'booking_reschedule_cutoff_hours' => $input['booking_reschedule_cutoff_hours'] ?? 24,
                'booking_allow_cancel' => !empty($input['booking_allow_cancel']),
                'booking_cancel_cutoff_hours' => $input['booking_cancel_cutoff_hours'] ?? 24,
                'booking_reminder_hours_before' => $input['booking_reminder_hours_before'] ?? 24,
                'is_external_product' => !empty($input['is_external_product']),
                'external_product_url' => $input['external_product_url'] ?? '',
                'external_product_button_text' => $input['external_product_button_text'] ?? '',
                'seo_title' => $input['seo_title'] ?? '',
                'seo_description' => $input['seo_description'] ?? '',
                'seo_canonical_url' => $input['seo_canonical_url'] ?? '',
                'seo_og_image' => $input['seo_og_image'] ?? '',
            ], (int)($user['id'] ?? 0));

            $digitalFileMeta = [];
            $digitalFileUpload = ecUploadProductDigitalFile(kernelUploadedFile('digital_file') ?? [], (int)($user['id'] ?? 0));
            if (is_array($digitalFileUpload)) {
                $digitalFileMeta['_download_file_path'] = $digitalFileUpload['file_path'];
                $digitalFileMeta['_download_file_name'] = $digitalFileUpload['original_name'];
            }
            ecProductSaveDigitalMeta($productId, array_merge($input, $digitalFileMeta));

            ecProductSaveStoreAssignments($productId, [$id]);
            $_SESSION['ec_sa_message'] = ['type' => 'success', 'text' => 'Product created.'];
            header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/products/' . $productId . '/edit');
            exit;
        } catch (\Throwable $e) {
            $error = 'Failed to create product: ' . $e->getMessage();
            $product = ecStoreAdminProductFormState($input);
        }
    }

    $ctx = ecStoreAdminProductEditorContext($user, $store, $product, $input, [
        'attribute_lines' => $attributeLines,
        'relation_ids' => $relationSelections,
        'bundle_children' => $bundleChildren,
        'grouped_children' => $groupedChildren,
        'tax_class' => $taxClass,
        'selected_category_id' => ($input['category_id'] ?? '') !== '' ? (int)$input['category_id'] : 0,
        'addon_lines' => (string)($input['addon_lines'] ?? ''),
        'required_membership_tiers_text' => (string)($input['required_membership_tiers_text'] ?? ''),
        'booking_time_slots' => (string)($input['booking_time_slots'] ?? ''),
        'message' => $_SESSION['ec_sa_message'] ?? null,
        'error' => $error ?? null,
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

    $product = ecProductGet($productId);
    if (!$product) {
        http_response_code(404);
        echo 'Product not found.';
        exit;
    }

    $input = [];
    $attributeLines = function_exists('ecProductAttributesToLines') ? ecProductAttributesToLines((array)($product['attributes'] ?? [])) : '';
    $relationSelections = is_array($product['relation_ids'] ?? null) ? $product['relation_ids'] : ecProductDefaultRelationIds();
    $bundleChildren = ecProductBundleChildSelections($productId);
    $groupedChildren = ecProductGroupedChildSelections($productId);
    $taxClass = (string)($product['tax_class'] ?? 'standard');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();
        $attributeLines = trim((string)($input['attribute_lines'] ?? ''));
        $relationSelections = ecProductRelationSelectionsFromInput($input, $productId);
        $bundleChildren = ecProductBundleSelectionsFromInput($input, $productId);
        $groupedChildren = ecProductGroupedSelectionsFromInput($input, $productId);
        $taxClass = function_exists('ecProductNormalizeTaxClass')
            ? ecProductNormalizeTaxClass($input['tax_class'] ?? ($product['tax_class'] ?? 'standard'))
            : 'standard';

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
                'attributes' => function_exists('ecProductParseAttributeLines') ? ecProductParseAttributeLines($attributeLines) : [],
                'relations' => $relationSelections,
                'bundle_children' => $bundleChildren,
                'grouped_children' => $groupedChildren,
                'tax_class' => $taxClass,
                'is_subscription' => !empty($input['is_subscription']),
                'subscription_interval_unit' => $input['subscription_interval_unit'] ?? 'month',
                'subscription_interval_count' => $input['subscription_interval_count'] ?? 1,
                'subscription_trial_days' => $input['subscription_trial_days'] ?? 0,
                'subscription_max_cycles' => $input['subscription_max_cycles'] ?? 0,
                'subscription_grace_period_days' => $input['subscription_grace_period_days'] ?? 7,
                'is_membership_product' => !empty($input['is_membership_product']),
                'membership_tier' => $input['membership_tier'] ?? 'member',
                'membership_duration_days' => $input['membership_duration_days'] ?? 365,
                'required_membership_tiers_text' => $input['required_membership_tiers_text'] ?? '',
                'addon_lines' => $input['addon_lines'] ?? '',
                'booking_enabled' => !empty($input['booking_enabled']),
                'booking_duration_minutes' => $input['booking_duration_minutes'] ?? 60,
                'booking_notice_hours' => $input['booking_notice_hours'] ?? 24,
                'booking_available_weekdays' => $input['booking_available_weekdays'] ?? [],
                'booking_time_slots' => $input['booking_time_slots'] ?? '',
                'booking_allow_reschedule' => !empty($input['booking_allow_reschedule']),
                'booking_reschedule_cutoff_hours' => $input['booking_reschedule_cutoff_hours'] ?? 24,
                'booking_allow_cancel' => !empty($input['booking_allow_cancel']),
                'booking_cancel_cutoff_hours' => $input['booking_cancel_cutoff_hours'] ?? 24,
                'booking_reminder_hours_before' => $input['booking_reminder_hours_before'] ?? 24,
                'is_external_product' => !empty($input['is_external_product']),
                'external_product_url' => $input['external_product_url'] ?? '',
                'external_product_button_text' => $input['external_product_button_text'] ?? '',
                'seo_title' => $input['seo_title'] ?? '',
                'seo_description' => $input['seo_description'] ?? '',
                'seo_canonical_url' => $input['seo_canonical_url'] ?? '',
                'seo_og_image' => $input['seo_og_image'] ?? '',
            ]);

            $digitalFileMeta = [];
            $digitalFileUpload = ecUploadProductDigitalFile(kernelUploadedFile('digital_file') ?? [], (int)($user['id'] ?? 0));
            if (is_array($digitalFileUpload)) {
                $digitalFileMeta['_download_file_path'] = $digitalFileUpload['file_path'];
                $digitalFileMeta['_download_file_name'] = $digitalFileUpload['original_name'];
            }
            ecProductSaveDigitalMeta($productId, array_merge($input, $digitalFileMeta));

            $_SESSION['ec_sa_message'] = ['type' => 'success', 'text' => 'Product saved.'];
            header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/products/' . $productId . '/edit');
            exit;
        } catch (\Throwable $e) {
            $error = 'Save failed: ' . $e->getMessage();
            $product = ecStoreAdminProductFormState($input, $product);
        }

        $product = ecProductGet($productId, false) ?: $product;
    }
    $assignmentMap = ecProductStoreAssignmentMap([$productId]);
    $assignedStores = $assignmentMap[$productId] ?? [];

    $ctx = ecStoreAdminProductEditorContext($user, $store, $product, $input, [
        'attribute_lines' => $attributeLines,
        'relation_ids' => $relationSelections,
        'bundle_children' => $bundleChildren,
        'grouped_children' => $groupedChildren,
        'tax_class' => $taxClass,
        'selected_category_id' => ($input['category_id'] ?? '') !== ''
            ? (int)$input['category_id']
            : (int)($product['categories'][0]['id'] ?? 0),
        'addon_lines' => array_key_exists('addon_lines', $input)
            ? (string)$input['addon_lines']
            : implode("\n", array_map(static function (array $addon): string {
                $parts = [trim((string)($addon['label'] ?? ''))];
                $parts[] = number_format((float)($addon['price'] ?? 0.0), 2, '.', '');
                if (trim((string)($addon['description'] ?? '')) !== '') {
                    $parts[] = trim((string)$addon['description']);
                }
                return implode(' | ', $parts);
            }, is_array($product['addons'] ?? null) ? $product['addons'] : [])),
        'required_membership_tiers_text' => array_key_exists('required_membership_tiers_text', $input)
            ? (string)$input['required_membership_tiers_text']
            : implode(', ', is_array($product['required_membership_tiers'] ?? null) ? $product['required_membership_tiers'] : []),
        'booking_time_slots' => array_key_exists('booking_time_slots', $input)
            ? (string)$input['booking_time_slots']
            : implode("\n", is_array($product['booking']['time_slots'] ?? null) ? $product['booking']['time_slots'] : []),
        'message' => $_SESSION['ec_sa_message'] ?? null,
        'error' => $error ?? null,
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

function ecStoreAdminMessages(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id);
    $store = ecStoreAdminLoadStore($id);
    $permissions = ecStoreAdminPermissions((string)($user['store_role'] ?? 'supervisor'));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($permissions['manage_messages'])) {
            http_response_code(403);
            exit;
        }

        csrf_verify();
        $input = ecInput();
        $orderId = (int)($input['order_id'] ?? 0);
        $result = ecStoreMessageCreateFromStore($orderId, $id, $user, (string)($input['message_body'] ?? ''));
        $_SESSION['ec_sa_message'] = $result['ok']
            ? ['type' => 'success', 'text' => 'Message sent.']
            : ['type' => 'error', 'text' => $result['error']];
        header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/messages?order=' . $orderId);
        exit;
    }

    $threads = ecStoreMessageThreadList($id, 50);
    $selectedOrderId = max(0, (int)(ecInput()['order'] ?? 0));
    if ($selectedOrderId <= 0 && isset($threads[0]['order_id'])) {
        $selectedOrderId = (int)$threads[0]['order_id'];
    }
    $selectedOrder = $selectedOrderId > 0 ? ecOrderGet($selectedOrderId) : null;

    $ctx = ecStoreAdminContext($user, $store, 'messages', [
        'threads' => $threads,
        'selected_order_id' => $selectedOrderId,
        'selected_order' => $selectedOrder,
        'selected_messages' => $selectedOrderId > 0 ? ecStoreMessagesForOrder($id, $selectedOrderId) : [],
        'message_storage_available' => ecStoreMessagesStorageAvailable(),
        'can_reply' => !empty($permissions['manage_messages']),
        'message' => $_SESSION['ec_sa_message'] ?? null,
    ]);
    unset($_SESSION['ec_sa_message']);

    ecRender('modules/ecommerce/admin/store-admin-messages.disyl', $ctx);
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

function ecStoreAdminLoyalty(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id, ['owner', 'manager']);
    $store = ecStoreAdminLoadStore($id);
    $summary = ecStoreLoyaltySummary($id, 75);

    $ctx = ecStoreAdminContext($user, $store, 'loyalty', [
        'loyalty_summary' => $summary,
    ]);

    ecRender('modules/ecommerce/admin/store-admin-loyalty.disyl', $ctx);
}

function ecStoreAdminImportExport(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    $user = ecRequireStoreAccess($id, ['owner', 'manager']);
    $store = ecStoreAdminLoadStore($id);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();
        $action = trim((string)($input['action'] ?? ''));

        if ($action === 'import_products') {
            $upload = ecImportReadUploadedCsv('csv_file');
            if (!($upload['ok'] ?? false)) {
                $_SESSION['ec_sa_message'] = ['type' => 'error', 'text' => (string)($upload['error'] ?? 'Upload failed.')];
            } else {
                try {
                    $result = ecStoreImportProductsFromCsv((string)($upload['raw'] ?? ''), $id, (int)($user['id'] ?? 0));
                    $errorCount = count((array)($result['errors'] ?? []));
                    $summary = 'Imported ' . (int)($result['created'] ?? 0) . ' new product(s) and updated ' . (int)($result['updated'] ?? 0) . '.';
                    if ($errorCount > 0) {
                        $summary .= ' ' . $errorCount . ' row(s) failed.';
                    }
                    $_SESSION['ec_sa_message'] = [
                        'type' => $errorCount > 0 ? 'error' : 'success',
                        'text' => $summary,
                    ];
                } catch (\Throwable $e) {
                    $_SESSION['ec_sa_message'] = ['type' => 'error', 'text' => 'Import failed: ' . $e->getMessage()];
                }
            }
        }

        header('Location: ' . ecGetBaseUrl() . '/ecommerce/store-admin/' . $id . '/import-export');
        exit;
    }

    $ctx = ecStoreAdminContext($user, $store, 'import_export', [
        'export_resources' => ecStoreCsvExportResources($id),
        'product_import_headers' => ecCsvProductHeaders(),
        'message' => $_SESSION['ec_sa_message'] ?? null,
    ]);
    unset($_SESSION['ec_sa_message']);

    ecRender('modules/ecommerce/admin/store-admin-import-export.disyl', $ctx);
}

function ecStoreAdminExportCsv(array $params = []): void
{
    $id = (int)($params['id'] ?? 0);
    ecRequireStoreAccess($id, ['owner', 'manager']);
    ecStoreAdminLoadStore($id);

    $definition = ecStoreCsvExportDefinition($id, (string)($params['resource'] ?? ''));
    if ($definition === null) {
        http_response_code(404);
        echo 'Export resource not found.';
        exit;
    }

    ecCsvResponse((string)$definition['filename'], (array)$definition['headers'], (array)$definition['rows']);
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
        'storefront_hours_form_schedule' => function_exists('ecStorefrontHoursFormSchedule') ? ecStorefrontHoursFormSchedule($inputData, $store) : [],
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

