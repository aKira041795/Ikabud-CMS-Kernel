<?php

declare(strict_types=1);

function ecWishlistSafeReturnPath(mixed $value, string $fallback = '/ecommerce/my-wishlist'): string
{
    $path = trim((string)$value);
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return $fallback;
    }

    return $path;
}

function ecPublicWishlist(): void
{
    $user = ecWishlistResolveCustomerUser();
    if (!$user) {
        header('Location: /cms/login?redirect=' . urlencode('/ecommerce/my-wishlist'));
        exit;
    }

    $customerId = (int)($user['id'] ?? 0);
    $wishlistItems = ecWishlistCatalogItemsForCustomer($customerId, 100, ['item_base_url' => '/ecommerce/shop']);
    $wishlistCount = count($wishlistItems);
    $cartCount = (int)(ecCartGet()['totals']['item_count'] ?? 0);
    $message = $_SESSION['ec_message'] ?? null;
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/public/my-wishlist.disyl', [
        'page_title' => 'My Wishlist',
        'wishlist_items' => $wishlistItems,
        'wishlist_count' => $wishlistCount,
        'message' => $message,
        'user' => $user,
        'current_request_uri' => '/ecommerce/my-wishlist',
        'storefront' => [
            'route' => [
                'origin' => 'ecommerce',
                'kind' => 'my_wishlist',
                'mode' => 'traditional',
            ],
            'page' => [
                'kind' => 'wishlist',
                'title' => 'My Wishlist',
                'description' => 'Products you saved for later review or purchase.',
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
                'count' => function_exists('ecCompareCount') ? ecCompareCount() : 0,
                'url' => '/ecommerce/compare',
            ],
            'wishlist' => [
                'count' => $wishlistCount,
                'url' => '/ecommerce/my-wishlist',
            ],
        ],
    ]);

    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

function ecPublicWishlistAction(): void
{
    csrf_verify();

    $input = ecInput();
    $action = trim((string)($input['action'] ?? ''));
    $productId = (int)($input['product_id'] ?? 0);
    $returnTo = ecWishlistSafeReturnPath($input['return_to'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), '/ecommerce/my-wishlist');
    $user = ecWishlistResolveCustomerUser();

    if (!$user) {
        header('Location: /cms/login?redirect=' . urlencode($returnTo));
        exit;
    }

    $customerId = (int)($user['id'] ?? 0);

    if ($action === 'add') {
        $result = ecWishlistAddProduct($customerId, $productId);
        $_SESSION['ec_message'] = !empty($result['ok'])
            ? ['type' => 'success', 'text' => !empty($result['already_wishlisted']) ? 'Product is already in your wishlist.' : 'Product added to your wishlist.']
            : ['type' => 'error', 'text' => (string)($result['error'] ?? 'Could not add product to your wishlist.')];
    } elseif ($action === 'remove') {
        $result = ecWishlistRemoveProduct($customerId, $productId);
        $_SESSION['ec_message'] = !empty($result['ok'])
            ? ['type' => 'success', 'text' => 'Product removed from your wishlist.']
            : ['type' => 'error', 'text' => (string)($result['error'] ?? 'Could not update your wishlist.')];
    } elseif ($action === 'clear') {
        $result = ecWishlistClearForCustomer($customerId);
        $_SESSION['ec_message'] = !empty($result['ok'])
            ? ['type' => 'success', 'text' => 'Wishlist cleared.']
            : ['type' => 'error', 'text' => (string)($result['error'] ?? 'Could not clear your wishlist.')];
        $returnTo = '/ecommerce/my-wishlist';
    } else {
        $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Unsupported wishlist action.'];
    }

    header('Location: ' . $returnTo);
    exit;
}
