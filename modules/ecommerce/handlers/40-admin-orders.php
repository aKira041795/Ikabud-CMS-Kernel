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

    $db  = ecDb();
    $row = $db->query(
        "SELECT id, order_id, product_id, target_module, target_tier, license_key, status, download_token
           FROM ec_order_licenses WHERE id = ? LIMIT 1",
        [$licenseId]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        ecRender('modules/ecommerce/admin/404.disyl', ['message' => 'License not found']);
        return;
    }

    // Try uploaded digital file first
    $productId = (int)($row['product_id'] ?? 0);
    if ($productId > 0) {
        $filePath = (string)($db->query(
            "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_download_file_path' LIMIT 1",
            [$productId]
        )->fetchColumn() ?: '');
        $fileName = (string)($db->query(
            "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_download_file_name' LIMIT 1",
            [$productId]
        )->fetchColumn() ?: '');

        if ($filePath !== '') {
            $storagePath = STORAGE_PATH . '/digital/' . ltrim($filePath, '/');
            if (is_file($storagePath) && is_readable($storagePath)) {
                $finfo    = new \finfo(FILEINFO_MIME_TYPE);
                $mime     = (string)$finfo->file($storagePath);
                $safeFile = $fileName !== '' ? basename($fileName) : basename($filePath);
                $safeFile = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $safeFile) ?: 'download';

                header('Content-Type: ' . $mime);
                header('Content-Disposition: attachment; filename="' . $safeFile . '"');
                header('Content-Length: ' . filesize($storagePath));
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('X-Content-Type-Options: nosniff');
                readfile($storagePath);
                exit;
            }
            write_log('ecAdminLicenseDownload: file missing on disk: ' . $storagePath, 'warning', ['module' => 'ecommerce']);
        }
    }

    // Fallback: serve JWT license key as text
    $module   = preg_replace('/[^a-z0-9_\-]/i', '', (string)($row['target_module'] ?? 'module'));
    $filename = 'license-' . $module . '.jwt';

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo (string)($row['license_key'] ?? '');
    exit;
}
