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
            ], (int)$user['id']);

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

    $ctx = ecAdminContext($user, 'products', [
        'product'    => null,
        'categories' => $categories,
        'selected_category_id' => 0,
        'featured_image_url' => '',
        'error'      => $error ?? null,
        'message'    => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/product-edit.disyl', $ctx);
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
            ]);

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

    $ctx = ecAdminContext($user, 'products', [
        'product'    => $product,
        'categories' => $categories,
        'selected_category_id' => (int)($product['categories'][0]['id'] ?? 0),
        'featured_image_url' => (string)($product['featured_image_url'] ?? ''),
        'error'      => $error ?? null,
        'message'    => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/product-edit.disyl', $ctx);
}
