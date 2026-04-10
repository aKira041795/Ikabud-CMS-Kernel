<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Products (handlers/35-admin-products.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /admin/products  — product list
 */
function ecAdminProducts(): void
{
    $user    = ecRequireAdmin();
    $search  = trim((string)(ecInput()['search'] ?? ''));
    $status  = ecInput()['status'] ?? '';
    $catId   = (int)(ecInput()['cat'] ?? 0);
    $page    = max(1, (int)(ecInput()['page'] ?? 1));
    $limit   = 20;
    $offset  = ($page - 1) * $limit;

    $result = ecProductList([
        'search'      => $search,
        'status'      => $status,
        'category_id' => $catId ?: null,
        'limit'       => $limit,
        'offset'      => $offset,
    ]);

    $categories = ecDb()->query(
        ecCmsCategorySelectSql('id, name, slug', 'name ASC')
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $ctx = ecAdminContext($user, 'products', [
        'products'    => $result['items'],
        'total'       => $result['total'],
        'total_pages' => (int)ceil($result['total'] / $limit),
        'page'        => $page,
        'search'      => $search,
        'status'      => $status,
        'cat_id'      => $catId,
        'categories'  => $categories,
    ]);

    ecRender('modules/ecommerce/admin/products.disyl', $ctx);
}

/**
 * GET  /admin/products/new  — new product form
 * POST /admin/products/new  — create product
 */
function ecAdminProductCreate(): void
{
    $user = ecRequireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();
        $attributeLines = trim((string)($input['attribute_lines'] ?? ''));
        $attributes = function_exists('ecProductParseAttributeLines') ? ecProductParseAttributeLines($attributeLines) : [];
        $relationSelections = ecProductRelationSelectionsFromInput($input);
        $bundleChildren = ecProductBundleSelectionsFromInput($input);
        $groupedChildren = ecProductGroupedSelectionsFromInput($input);
        $taxClass = function_exists('ecProductNormalizeTaxClass')
            ? ecProductNormalizeTaxClass($input['tax_class'] ?? 'standard')
            : 'standard';

        try {
            $featuredImageId = $input['featured_image_id'] ?? null;
            $uploadedImage = ecUploadProductFeaturedImage(kernelUploadedFile('featured_image') ?? [], (int)$user['id']);
            if (is_array($uploadedImage) && !empty($uploadedImage['id'])) {
                $featuredImageId = (int)$uploadedImage['id'];
            }

            $productId = ecProductCreate([
                'title'            => $input['title']            ?? 'New Product',
                'slug'             => $input['slug']             ?? '',
                'excerpt'          => $input['excerpt']          ?? '',
                'body'             => $input['body']             ?? '',
                'status'           => $input['status']           ?? 'draft',
                'price'            => $input['price']            ?? null,
                'sale_price'       => $input['sale_price']       ?? null,
                'sku'              => $input['sku']              ?? '',
                'stock_qty'        => $input['stock_qty']        ?? 0,
                'track_stock'      => ($input['track_stock']     ?? 'on') === 'on',
                'category_id'      => $input['category_id']      ?? null,
                'featured_image_id' => $featuredImageId,
                'attributes'       => $attributes,
                'relations'        => $relationSelections,
                'bundle_children'  => $bundleChildren,
                'grouped_children' => $groupedChildren,
                'tax_class'        => $taxClass,
                'is_subscription'  => !empty($input['is_subscription']),
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
                'is_external_product' => !empty($input['is_external_product']),
                'external_product_url' => $input['external_product_url'] ?? '',
                'external_product_button_text' => $input['external_product_button_text'] ?? '',
                'seo_title'        => $input['seo_title'] ?? '',
                'seo_description'  => $input['seo_description'] ?? '',
                'seo_canonical_url'=> $input['seo_canonical_url'] ?? '',
                'seo_og_image'     => $input['seo_og_image'] ?? '',
            ], (int)$user['id']);

            // Save digital license meta (+ optional file upload)
            $digitalFileMeta = [];
            $digitalFileUpload = ecUploadProductDigitalFile(kernelUploadedFile('digital_file') ?? [], (int)$user['id']);
            if (is_array($digitalFileUpload)) {
                $digitalFileMeta['_download_file_path'] = $digitalFileUpload['file_path'];
                $digitalFileMeta['_download_file_name'] = $digitalFileUpload['original_name'];
            }
            ecProductSaveDigitalMeta($productId, array_merge($input, $digitalFileMeta));

            $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Product created.'];
            header('Location: /ecommerce/admin/products/' . $productId . '/edit');
            exit;
        } catch (\Throwable $e) {
            write_log('ecAdminProductCreate error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
            $error = 'Failed to create product: ' . $e->getMessage();
        }
    }

    $categories = ecDb()->query(
        ecCmsCategorySelectSql('id, name', 'name ASC')
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $selectedRelationIds = isset($relationSelections) ? $relationSelections : ecProductDefaultRelationIds();
    $selectedBundleChildren = isset($bundleChildren) ? $bundleChildren : [];
    $selectedGroupedChildren = isset($groupedChildren) ? $groupedChildren : [];

    $ctx = ecAdminContext($user, 'products', [
        'product'    => null,
        'categories' => $categories,
        'selected_category_id' => 0,
        'attribute_lines' => $attributeLines ?? '',
        'selected_tax_class' => $taxClass ?? 'standard',
        'tax_class_options' => ecProductTaxClassOptions($taxClass ?? 'standard'),
        'relation_options' => ecProductAdminRelationOptions(0, array_merge($selectedRelationIds, ['bundle_children' => $selectedBundleChildren, 'grouped_children' => $selectedGroupedChildren])),
        'selected_relation_ids' => $selectedRelationIds,
        'selected_bundle_children' => $selectedBundleChildren,
        'selected_grouped_children' => $selectedGroupedChildren,
        'featured_image_url' => '',
        'addon_lines' => $input['addon_lines'] ?? '',
        'required_membership_tiers_text' => $input['required_membership_tiers_text'] ?? '',
        'booking_time_slots_text' => $input['booking_time_slots'] ?? '',
        'selected_booking_weekdays' => array_map('intval', (array)($input['booking_available_weekdays'] ?? [])),
        'booking_weekday_flags' => (function (array $weekdays): array {
            return [
                'sun' => in_array(0, $weekdays, true),
                'mon' => in_array(1, $weekdays, true),
                'tue' => in_array(2, $weekdays, true),
                'wed' => in_array(3, $weekdays, true),
                'thu' => in_array(4, $weekdays, true),
                'fri' => in_array(5, $weekdays, true),
                'sat' => in_array(6, $weekdays, true),
            ];
        })(array_map('intval', (array)($input['booking_available_weekdays'] ?? []))),
        'seo_defaults' => ecProductSeoDefaults(),
        'error'      => $error ?? null,
        'message'    => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/product-edit.disyl', $ctx);
    
    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

/**
 * GET  /admin/products/{id}/edit  — edit product form
 * POST /admin/products/{id}/edit  — save product
 */
function ecAdminProductEdit(array $params = []): void
{
    $user      = ecRequireAdmin();
    $productId = (int)($params['id'] ?? 0);
    $product   = ecProductGet($productId);

    if (!$product) {
        http_response_code(404);
        ecRender('modules/ecommerce/admin/404.disyl', ['message' => 'Product not found']);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();
        $attributeLines = trim((string)($input['attribute_lines'] ?? ''));
        $attributes = function_exists('ecProductParseAttributeLines') ? ecProductParseAttributeLines($attributeLines) : [];
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

            $uploadedImage = ecUploadProductFeaturedImage(kernelUploadedFile('featured_image') ?? [], (int)$user['id']);
            if (is_array($uploadedImage) && !empty($uploadedImage['id'])) {
                $featuredImageId = (int)$uploadedImage['id'];
            }

            ecProductUpdate($productId, [
                'title'            => $input['title']            ?? $product['title'],
                'slug'             => $input['slug']             ?? $product['slug'],
                'excerpt'          => $input['excerpt']          ?? $product['excerpt'],
                'body'             => $input['body']             ?? $product['body'],
                'status'           => $input['status']           ?? $product['status'],
                'price'            => $input['price']            ?? null,
                'sale_price'       => $input['sale_price']       ?? null,
                'sku'              => $input['sku']              ?? '',
                'stock_qty'        => $input['stock_qty']        ?? 0,
                'track_stock'      => ($input['track_stock']     ?? 'on') === 'on',
                'category_id'      => $input['category_id']      ?? null,
                'featured_image_id' => $featuredImageId,
                'attributes'       => $attributes,
                'relations'        => $relationSelections,
                'bundle_children'  => $bundleChildren,
                'grouped_children' => $groupedChildren,
                'tax_class'        => $taxClass,
                'is_subscription'  => !empty($input['is_subscription']),
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
                'is_external_product' => !empty($input['is_external_product']),
                'external_product_url' => $input['external_product_url'] ?? '',
                'external_product_button_text' => $input['external_product_button_text'] ?? '',
                'seo_title'        => $input['seo_title'] ?? '',
                'seo_description'  => $input['seo_description'] ?? '',
                'seo_canonical_url'=> $input['seo_canonical_url'] ?? '',
                'seo_og_image'     => $input['seo_og_image'] ?? '',
            ]);

            // Save digital license meta (+ optional file upload / removal)
            $digitalFileMeta = [];
            $digitalFileUpload = ecUploadProductDigitalFile(kernelUploadedFile('digital_file') ?? [], (int)$user['id']);
            if (is_array($digitalFileUpload)) {
                $digitalFileMeta['_download_file_path'] = $digitalFileUpload['file_path'];
                $digitalFileMeta['_download_file_name'] = $digitalFileUpload['original_name'];
            }
            ecProductSaveDigitalMeta($productId, array_merge($input, $digitalFileMeta));

            $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Product saved.'];
            header('Location: /ecommerce/admin/products/' . $productId . '/edit');
            exit;
        } catch (\Throwable $e) {
            $error = 'Save failed: ' . $e->getMessage();
        }
    }

    $categories = ecDb()->query(
        ecCmsCategorySelectSql('id, name', 'name ASC')
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    // Refresh product after save
    $product = ecProductGet($productId);
    $selectedRelationIds = isset($relationSelections)
        ? $relationSelections
        : (is_array($product['relation_ids'] ?? null) ? $product['relation_ids'] : ecProductDefaultRelationIds());
    $selectedBundleChildren = isset($bundleChildren)
        ? $bundleChildren
        : ecProductBundleChildSelections($productId);
    $selectedGroupedChildren = isset($groupedChildren)
        ? $groupedChildren
        : ecProductGroupedChildSelections($productId);

    $ctx = ecAdminContext($user, 'products', [
        'product'    => $product,
        'categories' => $categories,
        'selected_category_id' => (int)($product['categories'][0]['id'] ?? 0),
        'attribute_lines' => isset($attributeLines)
            ? $attributeLines
            : (function_exists('ecProductAttributesToLines') ? ecProductAttributesToLines((array)($product['attributes'] ?? [])) : ''),
        'selected_tax_class' => isset($taxClass) ? $taxClass : (string)($product['tax_class'] ?? 'standard'),
        'tax_class_options' => ecProductTaxClassOptions(isset($taxClass) ? $taxClass : (string)($product['tax_class'] ?? 'standard')),
        'relation_options' => ecProductAdminRelationOptions($productId, array_merge($selectedRelationIds, ['bundle_children' => $selectedBundleChildren, 'grouped_children' => $selectedGroupedChildren])),
        'selected_relation_ids' => $selectedRelationIds,
        'selected_bundle_children' => $selectedBundleChildren,
        'selected_grouped_children' => $selectedGroupedChildren,
        'featured_image_url' => (string)($product['featured_image_url'] ?? ''),
        'addon_lines' => isset($input['addon_lines'])
            ? (string)$input['addon_lines']
            : implode("\n", array_map(static function (array $addon): string {
                $parts = [trim((string)($addon['label'] ?? ''))];
                $parts[] = number_format((float)($addon['price'] ?? 0.0), 2, '.', '');
                if (trim((string)($addon['description'] ?? '')) !== '') {
                    $parts[] = trim((string)$addon['description']);
                }
                return implode(' | ', $parts);
            }, is_array($product['addons'] ?? null) ? $product['addons'] : [])),
        'required_membership_tiers_text' => isset($input['required_membership_tiers_text'])
            ? (string)$input['required_membership_tiers_text']
            : implode(', ', is_array($product['required_membership_tiers'] ?? null) ? $product['required_membership_tiers'] : []),
        'booking_time_slots_text' => isset($input['booking_time_slots'])
            ? (string)$input['booking_time_slots']
            : implode("\n", is_array($product['booking']['time_slots'] ?? null) ? $product['booking']['time_slots'] : []),
        'selected_booking_weekdays' => isset($input['booking_available_weekdays'])
            ? array_map('intval', (array)$input['booking_available_weekdays'])
            : array_map('intval', is_array($product['booking']['available_weekdays'] ?? null) ? $product['booking']['available_weekdays'] : []),
        'booking_weekday_flags' => (function (array $weekdays): array {
            return [
                'sun' => in_array(0, $weekdays, true),
                'mon' => in_array(1, $weekdays, true),
                'tue' => in_array(2, $weekdays, true),
                'wed' => in_array(3, $weekdays, true),
                'thu' => in_array(4, $weekdays, true),
                'fri' => in_array(5, $weekdays, true),
                'sat' => in_array(6, $weekdays, true),
            ];
        })(isset($input['booking_available_weekdays'])
            ? array_map('intval', (array)$input['booking_available_weekdays'])
            : array_map('intval', is_array($product['booking']['available_weekdays'] ?? null) ? $product['booking']['available_weekdays'] : [])),
        'seo_defaults' => ecProductSeoDefaults(),
        'error'      => $error ?? null,
        'message'    => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/product-edit.disyl', $ctx);
    
    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}
