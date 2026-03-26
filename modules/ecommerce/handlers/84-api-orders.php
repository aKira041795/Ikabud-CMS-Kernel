<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — API: Orders (handlers/84-api-orders.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /api/v1/ecommerce/orders  — admin: all orders
 */
function ecApiOrdersList(): void
{
    ecRequireAdmin();
    $input  = ecInput();
    $result = ecOrderList([
        'status'         => $input['status']         ?? '',
        'payment_status' => $input['payment_status'] ?? '',
        'search'         => $input['search']         ?? '',
        'date_from'      => $input['date_from']      ?? '',
        'date_to'        => $input['date_to']        ?? '',
        'limit'          => min(100, (int)($input['limit']  ?? 25)),
        'offset'         => max(0,   (int)($input['offset'] ?? 0)),
    ]);
    ecJsonOk($result);
}

/**
 * GET /api/v1/ecommerce/my-orders  — customer: own orders
 */
function ecApiMyOrders(): void
{
    $user = app()->user();
    if (!$user) {
        ecJsonError('Authentication required', 401);
    }

    $input  = ecInput();
    $result = ecCustomerOrders(
        (int)$user['id'],
        min(50, (int)($input['limit']  ?? 10)),
        max(0,  (int)($input['offset'] ?? 0))
    );
    ecJsonOk($result);
}

/**
 * GET /api/v1/ecommerce/orders/{id}  — admin view of one order
 */
function ecApiOrderGet(array $params = []): void
{
    ecRequireAdmin();
    $id    = (int)($params['id'] ?? 0);
    $order = ecOrderGet($id);

    if (!$order) {
        ecJsonError('Order not found', 404);
    }
    ecJsonOk(['order' => $order]);
}

/**
 * POST /api/v1/ecommerce/orders/{id}/status  — update status
 */
function ecApiOrderStatus(array $params = []): void
{
    ecRequireAdmin();
    $id     = (int)($params['id'] ?? 0);
    $input  = ecInput();
    $status = trim((string)($input['status'] ?? ''));
    $note   = trim((string)($input['note']   ?? ''));

    $updated = ecOrderUpdateStatus($id, $status, $note ?: null);
    if (!$updated) {
        ecJsonError('Invalid status or transition not allowed', 422);
    }
    ecJsonOk(['order' => ecOrderGet($id)]);
}

/**
 * POST /api/v1/ecommerce/orders/{id}/note  — add meta note
 */
function ecApiOrderNote(array $params = []): void
{
    ecRequireAdmin();
    $id    = (int)($params['id'] ?? 0);
    $input = ecInput();
    $note  = trim((string)($input['note'] ?? ''));

    if (!$note) {
        ecJsonError('note required', 422);
    }

    $db = ecDb();
    $db->execute(
        "INSERT INTO ec_order_meta (order_id, meta_key, meta_value) VALUES (?, 'admin_note', ?)
         ON DUPLICATE KEY UPDATE meta_value = CONCAT(meta_value, '\n', VALUES(meta_value))",
        [$id, $note]
    );
    ecJsonOk(['ok' => true]);
}
