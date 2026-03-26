<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Orders (handlers/40-admin-orders.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /admin/orders  — order list with filters
 */
function ecAdminOrders(): void
{
    $user   = ecRequireAdmin();
    $input  = ecInput();
    $page   = max(1, (int)($input['page'] ?? 1));
    $limit  = 25;

    $filters = [
        'status'         => $input['status']         ?? '',
        'payment_status' => $input['payment_status'] ?? '',
        'source'         => $input['source']         ?? '',
        'search'         => trim((string)($input['search'] ?? '')),
        'date_from'      => $input['date_from']      ?? '',
        'date_to'        => $input['date_to']        ?? '',
        'limit'          => $limit,
        'offset'         => ($page - 1) * $limit,
    ];

    $result = ecOrderList($filters);

    $ctx = ecAdminContext($user, 'orders', [
        'orders'      => $result['items'],
        'total'       => $result['total'],
        'total_pages' => (int)ceil($result['total'] / $limit),
        'page'        => $page,
        'filters'     => $filters,
    ]);

    ecRender('modules/ecommerce/admin/orders.disyl', $ctx);
}

/**
 * GET  /admin/orders/{id}  — order detail
 * POST /admin/orders/{id}  — update status / add note
 */
function ecAdminOrderDetail(array $params = []): void
{
    $user    = ecRequireAdmin();
    $orderId = (int)($params['id'] ?? 0);
    $order   = ecOrderGet($orderId);

    if (!$order) {
        http_response_code(404);
        ecRender('modules/ecommerce/admin/404.disyl', ['message' => 'Order not found']);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input  = ecInput();
        $action = $input['action'] ?? '';

        if ($action === 'update_status') {
            $updated = ecOrderUpdateStatus($orderId, (string)($input['status'] ?? ''), $input['note'] ?? null);
            $msg = $updated
                ? ['type' => 'success', 'text' => 'Status updated.']
                : ['type' => 'error', 'text'   => 'Invalid status transition.'];
            $_SESSION['ec_message'] = $msg;
        } elseif ($action === 'mark_paid') {
            ecOrderMarkPaid($orderId);
            $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Order marked as paid.'];
        }

        header('Location: /ecommerce/admin/orders/' . $orderId);
        exit;
    }

    $allowedStatuses = EC_ORDER_STATUS_TRANSITIONS[$order['status']] ?? [];
    $symbol          = (string)($order['currency_symbol'] ?? ecSettings('currency_symbol'));

    $ctx = ecAdminContext($user, 'orders', [
        'order'           => $order,
        'allowed_statuses' => $allowedStatuses,
        'currency_symbol' => $symbol,
        'message'         => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/order-detail.disyl', $ctx);
    
    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}
