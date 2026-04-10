<?php

declare(strict_types=1);

function ecCompareSafeReturnPath(mixed $value, string $fallback = '/ecommerce/compare'): string
{
    $path = trim((string)$value);
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return $fallback;
    }

    return $path;
}

function ecPublicCompare(): void
{
    $products = ecCompareProducts();
    $compareItems = ecCompareCatalogItems(EC_COMPARE_MAX_ITEMS, ['item_base_url' => '/ecommerce/shop']);
    $compareRows = ecCompareTableRows($products);
    $cartCount = (int)(ecCartGet()['totals']['item_count'] ?? 0);
    $message = $_SESSION['ec_message'] ?? null;
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/public/compare.disyl', [
        'page_title' => 'Compare Products',
        'compare_products' => $compareItems,
        'compare_rows' => $compareRows,
        'compare_count' => count($compareItems),
        'current_request_uri' => '/ecommerce/compare',
        'message' => $message,
        'storefront' => [
            'route' => [
                'origin' => 'ecommerce',
                'kind' => 'compare',
                'mode' => 'traditional',
            ],
            'page' => [
                'kind' => 'compare',
                'title' => 'Compare Products',
                'description' => 'Review a small shortlist of products side by side before you commit to a purchase.',
            ],
            'navigation' => [
                'shop_url' => '/ecommerce/shop',
                'all_items_url' => '/ecommerce/shop',
                'search_action_url' => '/ecommerce/shop',
            ],
            'cart' => [
                'count' => $cartCount,
            ],
            'compare' => [
                'count' => count($compareItems),
                'url' => '/ecommerce/compare',
            ],
        ],
    ]);

    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

function ecPublicCompareAction(): void
{
    csrf_verify();

    $input = ecInput();
    $action = trim((string)($input['action'] ?? ''));
    $productId = (int)($input['product_id'] ?? 0);
    $returnTo = ecCompareSafeReturnPath($input['return_to'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), '/ecommerce/compare');

    if ($action === 'add') {
        $result = ecCompareAddProduct($productId);
        $_SESSION['ec_message'] = !empty($result['ok'])
            ? ['type' => 'success', 'text' => 'Product added to compare.']
            : ['type' => 'error', 'text' => (string)($result['error'] ?? 'Could not add product to compare.')];
    } elseif ($action === 'remove') {
        ecCompareRemoveProduct($productId);
        $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Product removed from compare.'];
    } elseif ($action === 'clear') {
        ecCompareClear();
        $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Comparison list cleared.'];
        $returnTo = '/ecommerce/compare';
    } else {
        $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Unsupported compare action.'];
    }

    header('Location: ' . $returnTo);
    exit;
}