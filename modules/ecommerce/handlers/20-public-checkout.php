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
        header('Location: /cart');
        exit;
    }

    $user            = app()->user();
    $isCustomer      = $user && in_array($user['role'] ?? '', ['subscriber', 'customer', 'editor', 'administrator'], true);
    $guestAllowed    = (bool)ecSettings('guest_checkout', true);

    if (!$isCustomer && !$guestAllowed) {
        header('Location: /login?redirect=' . urlencode('/checkout'));
        exit;
    }

    $shippingRates   = ecShippingRates();
    $paymentLabel    = ecSettings('payment_method_label', 'Cash on Delivery / Bank Transfer');

    ecRender('modules/ecommerce/public/checkout.disyl', [
        'page_title'      => 'Checkout',
        'cart'            => $cart,
        'user'            => $user,
        'is_customer'     => $isCustomer,
        'shipping_rates'  => $shippingRates,
        'payment_label'   => $paymentLabel,
        'csrf_token'      => csrf_token(),
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
    header('Location: /checkout');
    exit;
}

/**
 * GET /order/{token}  — order confirmation page (guest + customer)
 */
function ecPublicOrderConfirm(): void
{
    $token = ecCtx()['params']['token'] ?? '';
    if (!$token) {
        http_response_code(404);
        ecRender('modules/ecommerce/public/404.disyl', ['message' => 'Order not found']);
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
        ecRender('modules/ecommerce/public/404.disyl', ['message' => 'Order not found']);
        return;
    }

    $fullOrder = ecOrderGet((int)$order['id'], $customerId, $token);

    ecRender('modules/ecommerce/public/order-confirmation.disyl', [
        'page_title' => 'Order Confirmed',
        'order'      => $fullOrder,
    ]);
}
