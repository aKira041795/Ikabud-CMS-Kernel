<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Public Checkout Handler (handlers/20-public-checkout.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /checkout  — checkout form
 */
function ecPublicCheckout(): void
{
    $cart = ecCartGet();
    if (empty($cart['items'])) {
        header('Location: /ecommerce/cart');
        exit;
    }

    $user            = app()->user();
    $isCustomer      = $user && in_array($user['role'] ?? '', ['subscriber', 'customer', 'editor', 'administrator'], true);

    $cartStore = function_exists('ecCartResolvedStore') ? ecCartResolvedStore((array)($cart['items'] ?? [])) : null;
    $cartStoreSettings = is_array($cartStore) && function_exists('ecStoreSettingsArray') ? ecStoreSettingsArray($cartStore) : [];
    $guestAllowed    = (bool)ecStoreAwareSetting('guest_checkout', $cartStore);
    $loyaltyPoints = function_exists('ecCartSelectedLoyaltyPoints') ? ecCartSelectedLoyaltyPoints() : 0;

    if (!$isCustomer && !$guestAllowed) {
        header('Location: /cms/login?redirect=' . urlencode('/ecommerce/checkout'));
        exit;
    }

    $shippingDefaults = ['country' => function_exists('ecShippingDefaultCountry') ? ecShippingDefaultCountry() : ''];
    $shippingQuote   = function_exists('ecShippingQuote')
        ? ecShippingQuote($cart['items'], $shippingDefaults, (string)($cart['coupon_code'] ?? ''), null, [
            'customer_id' => $isCustomer ? (int)($user['id'] ?? 0) : null,
            'loyalty_points' => $loyaltyPoints,
        ])
        : ['requires_shipping' => false, 'rates' => [], 'selected_rate_id' => null, 'selected_rate' => null, 'totals' => $cart['totals']];
    $paymentLabel    = (string)ecStoreAwareSetting('payment_method_label', $cartStore, 'Manual');
    $cartHasDigital  = ecCartHasDigitalItems($cart['items']);
    $requireAccount  = (bool)ecStoreAwareSetting('require_account_for_digital', $cartStore);

    ecRender('modules/ecommerce/public/checkout.disyl', [
        'page_title'                 => 'Checkout',
        'cart'                       => $cart,
        'user'                       => $user,
        'is_customer'                => $isCustomer,
        'shipping_rates'             => $shippingQuote['rates'] ?? [],
        'shipping_quote'             => $shippingQuote,
        'shipping_defaults'          => $shippingDefaults,
        'requires_shipping'          => !empty($shippingQuote['requires_shipping']),
        'checkout_totals'            => is_array($shippingQuote['totals'] ?? null) ? $shippingQuote['totals'] : $cart['totals'],
        'payment_label'              => $paymentLabel,
        'csrf_token'                 => app()->csrfToken(),
        'store_currency_code'        => (string)($cartStoreSettings['currency'] ?? ($cart['currency'] ?? '')),
        'store_currency_sym'         => (string)($cartStoreSettings['currency_symbol'] ?? ($cart['currency_symbol'] ?? '')),
        'cart_has_digital'           => $cartHasDigital,
        'require_account_for_digital' => $requireAccount,
        'abandoned_cart_enabled'     => ecAbandonedCartEnabled(),
    ]);
}

/**
 * POST /checkout (via API at /api/v1/ecommerce/checkout)
 * This page-level handler redirects after checkout API; the actual processing
 * is done by ecApiCheckout() in 86-api-checkout.php.
 */
function ecPublicCheckoutProcess(): void
{
    // All checkout logic lives in the API handler.
    // This POST route exists only for non-JS fallback; redirect to checkout GET.
    header('Location: /ecommerce/checkout');
    exit;
}

/**
 * GET /order/{token}  — order confirmation page (guest + customer)
 */
function ecPublicOrderConfirm(array $params = []): void
{
    $token = (string)($params['token'] ?? '');
    if (!$token) {
        http_response_code(404);
        ecRender('pages/404.disyl', ['page_title' => 'Order Not Found']);
        return;
    }

    $user       = app()->user();
    $customerId = ($user && in_array($user['role'] ?? '', ['subscriber', 'customer', 'editor', 'administrator'], true))
        ? (int)$user['id'] : null;

    // Lookup by token — no user ID required (guest access)
    $db    = ecDb();
    $order = $db->query(
        "SELECT id FROM ec_orders WHERE confirmation_token = ? LIMIT 1",
        [$token]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        ecRender('pages/404.disyl', ['page_title' => 'Order Not Found']);
        return;
    }

    $fullOrder = ecOrderGet((int)$order['id'], $customerId, $token);

    ecRender('modules/ecommerce/public/order-confirmation.disyl', [
        'page_title'   => 'Order Confirmed',
        'order'        => $fullOrder,
        'is_logged_in' => $customerId !== null,
    ]);
}

function ecPublicRecoverCart(array $params = []): void
{
    $token = trim((string)($params['token'] ?? ''));
    $result = $token !== '' ? ecAbandonedCartRestore($token) : ['ok' => false, 'error' => 'Recovery link is invalid or has expired.'];

    $_SESSION['ec_message'] = !empty($result['ok'])
        ? ['type' => 'success', 'text' => 'Your saved cart has been restored. Review it below and continue to checkout when ready.']
        : ['type' => 'error', 'text' => (string)($result['error'] ?? 'Recovery link is invalid or has expired.')];

    header('Location: /ecommerce/cart');
    exit;
}
