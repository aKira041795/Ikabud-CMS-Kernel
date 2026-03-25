<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Public My Orders Handler (handlers/25-public-orders.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /my-orders  — customer order history
 */
function ecPublicMyOrders(): void
{
    $user = app()->user();
    if (!$user || !in_array($user['role'] ?? '', ['subscriber', 'customer', 'editor', 'administrator'], true)) {
        header('Location: /login?redirect=' . urlencode('/my-orders'));
        exit;
    }

    $page  = max(1, (int)(ecInput()['page'] ?? 1));
    $limit = 10;

    $result = ecCustomerOrders((int)$user['id'], $limit, ($page - 1) * $limit);

    ecRender('modules/ecommerce/public/my-orders.disyl', [
        'page_title'  => 'My Orders',
        'orders'      => $result['items'],
        'total'       => $result['total'],
        'page'        => $page,
        'total_pages' => (int)ceil($result['total'] / $limit),
        'user'        => $user,
    ]);
}

/**
 * GET /my-orders/{id}  — single order detail for customer
 */
function ecPublicOrderDetail(): void
{
    $user = app()->user();
    if (!$user) {
        header('Location: /login');
        exit;
    }

    $orderId = (int)(ecCtx()['params']['id'] ?? 0);
    $order   = ecOrderGet($orderId, (int)$user['id']);

    if (!$order) {
        http_response_code(404);
        ecRender('modules/ecommerce/public/404.disyl', ['message' => 'Order not found']);
        return;
    }

    ecRender('modules/ecommerce/public/order-detail.disyl', [
        'page_title' => 'Order ' . $order['order_number'],
        'order'      => $order,
        'user'       => $user,
    ]);
}
