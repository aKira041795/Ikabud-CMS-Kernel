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
    $orders = ecOrdersAttachOperationalAuthority((array)($result['items'] ?? []));

    $ctx = ecAdminContext($user, 'orders', [
        'orders'      => $orders,
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

    $authority = ecOrderOperationalAuthority($orderId);
    $authorityStore = is_array($authority['store'] ?? null) ? $authority['store'] : null;
    $canManageOrder = !empty($authority['can_process_globally']);
    $authorityStoreAdminUrl = $authorityStore
        ? ecGetBaseUrl() . '/ecommerce/store-admin/' . (int)($authorityStore['id'] ?? 0) . '/orders/' . $orderId
        : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManageOrder) {
            $_SESSION['ec_message'] = [
                'type' => 'error',
                'text' => 'This order is store-owned. Review it here if needed, but process it from the assigned store admin workspace.',
            ];
            header('Location: /ecommerce/admin/orders/' . $orderId);
            exit;
        }

        csrf_verify();
        $input  = ecInput();
        $action = $input['action'] ?? '';

        if ($action === 'update_status') {
            $updated = ecOrderUpdateStatusWithOptions($orderId, (string)($input['status'] ?? ''), $input['note'] ?? null, [
                'source' => 'ecommerce_admin',
                'actor_user_id' => (int)($user['id'] ?? 0),
                'tracking' => [
                    'tracking_number' => $input['tracking_number'] ?? '',
                    'carrier' => $input['tracking_carrier'] ?? '',
                    'tracking_url' => $input['tracking_url'] ?? '',
                ],
            ]);
            $msg = $updated
                ? ['type' => 'success', 'text' => 'Status updated.']
                : ['type' => 'error', 'text'   => 'Invalid status transition.'];
            $_SESSION['ec_message'] = $msg;
        } elseif ($action === 'update_tracking') {
            $updated = ecOrderUpdateStatusWithOptions($orderId, (string)($order['status'] ?? 'pending'), $input['tracking_note'] ?? null, [
                'source' => 'ecommerce_admin',
                'actor_user_id' => (int)($user['id'] ?? 0),
                'tracking' => [
                    'tracking_number' => $input['tracking_number'] ?? '',
                    'carrier' => $input['tracking_carrier'] ?? '',
                    'tracking_url' => $input['tracking_url'] ?? '',
                ],
            ]);
            $_SESSION['ec_message'] = $updated
                ? ['type' => 'success', 'text' => 'Shipment tracking updated.']
                : ['type' => 'error', 'text' => 'Shipment tracking could not be updated.'];
        } elseif ($action === 'create_refund') {
            try {
                $result = ecOrderCreateRefund($orderId, (array)($input['refund_qty'] ?? []), [
                    'amount' => $input['refund_amount'] ?? 0,
                    'reason' => $input['refund_reason'] ?? '',
                    'admin_note' => $input['refund_note'] ?? '',
                    'restock_inventory' => !empty($input['restock_inventory']),
                    'created_by_user_id' => (int)($user['id'] ?? 0),
                ]);
                $_SESSION['ec_message'] = [
                    'type' => 'success',
                    'text' => 'Refund created: ' . (string)($result['refund']['refund_number'] ?? ''),
                ];
            } catch (\Throwable $e) {
                $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Refund failed: ' . $e->getMessage()];
            }
        } elseif ($action === 'review_return_request') {
            try {
                $result = ecReturnRequestReview(
                    (int)($input['return_request_id'] ?? 0),
                    (string)($input['review_status'] ?? ''),
                    [
                        'admin_note' => $input['return_admin_note'] ?? '',
                        'reviewed_by_user_id' => (int)($user['id'] ?? 0),
                    ]
                );
                $status = (string)($result['request']['status'] ?? 'updated');
                $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Return request ' . $status . '.'];
            } catch (\Throwable $e) {
                $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Return request review failed: ' . $e->getMessage()];
            }
        } elseif ($action === 'mark_paid') {
            ecOrderMarkPaid($orderId);
            $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Order marked as paid.'];
        } elseif ($action === 'regenerate_license') {
            if (function_exists('ecOrderLicenseRegenerate') && !empty($input['license_id'])) {
                $regenerated = ecOrderLicenseRegenerate((int)$input['license_id']);
                $_SESSION['ec_message'] = $regenerated
                    ? ['type' => 'success', 'text' => 'License key regenerated successfully.']
                    : ['type' => 'error', 'text' => 'Failed to regenerate license key. Please ensure your private key is saved in module settings.'];
            }
        }

        header('Location: /ecommerce/admin/orders/' . $orderId);
        exit;
    }

    $allowedStatuses = EC_ORDER_STATUS_TRANSITIONS[$order['status']] ?? [];
    $symbol          = (string)($order['currency_symbol'] ?? ecSettings('currency_symbol'));

    $orderBookings = function_exists('ecBookingsForOrder') ? ecBookingsForOrder($orderId) : [];
    if (function_exists('ecBookingHydrateForDisplay')) {
        $orderBookings = array_map('ecBookingHydrateForDisplay', $orderBookings);
    }

    $ctx = ecAdminContext($user, 'orders', [
        'order'           => $order,
        'allowed_statuses' => $allowedStatuses,
        'currency_symbol' => $symbol,
        'refunds'         => $order['refunds'] ?? [],
        'refund_summary'  => $order['refund_summary'] ?? [],
        'order_bookings'  => $orderBookings,
        'can_manage_order' => $canManageOrder,
        'authority_scope' => (string)($authority['scope'] ?? 'global'),
        'authority_store' => $authorityStore,
        'authority_store_admin_url' => $authorityStoreAdminUrl,
        'message'         => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/order-detail.disyl', $ctx);
    
    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

function ecAdminReturns(): void
{
    $user = ecRequireAdmin();
    $input = ecInput();
    $filters = [
        'status' => trim((string)($input['status'] ?? '')),
        'limit' => 75,
        'offset' => 0,
    ];
    $result = ecReturnRequestList($filters);

    $ctx = ecAdminContext($user, 'returns', [
        'page_title' => 'Ecommerce — Return Requests',
        'return_requests' => $result['items'],
        'filters' => $filters,
        'message' => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/returns.disyl', $ctx);
}

/**
 * GET /ecommerce/admin/licenses/{id}/download
 *
 * Admin-only direct download for a digital license.
 * No ownership check — the admin role is the only gate.
 * Serves the uploaded digital file or falls back to JWT text.
 */
function ecAdminLicenseDownload(array $params = []): void
{
    ecRequireAdmin();

    $licenseId = (int)($params['id'] ?? 0);
    if ($licenseId <= 0) {
        http_response_code(404);
        ecRender('modules/ecommerce/admin/404.disyl', ['message' => 'License not found']);
        return;
    }

    $row = ecOrderLicenseFindById($licenseId);

    if (!$row) {
        http_response_code(404);
        ecRender('modules/ecommerce/admin/404.disyl', ['message' => 'License not found']);
        return;
    }

    ecOutputOrderLicenseDownload($row);
}
