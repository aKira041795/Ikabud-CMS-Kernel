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
        header('Location: /cms/login?redirect=' . urlencode('/ecommerce/my-orders'));
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
function ecPublicOrderDetail(array $params = []): void
{
    $user = app()->user();
    if (!$user) {
        header('Location: /cms/login?redirect=' . urlencode('/ecommerce/my-orders'));
        exit;
    }

    $orderId = (int)($params['id'] ?? 0);
    $order   = ecOrderGet($orderId, (int)$user['id']);

    if (!$order) {
        http_response_code(404);
        ecRender('pages/404.disyl', ['page_title' => 'Order Not Found']);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();
        $action = (string)($input['action'] ?? '');

        if ($action === 'request_return') {
            try {
                ecReturnRequestCreate($orderId, (int)$user['id'], (array)($input['return_qty'] ?? []), [
                    'reason' => $input['return_reason'] ?? '',
                    'condition' => $input['return_condition'] ?? 'unknown',
                    'customer_note' => $input['return_note'] ?? '',
                ]);
                $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Return request submitted.'];
            } catch (\Throwable $e) {
                $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Return request failed: ' . $e->getMessage()];
            }
        }

        header('Location: /ecommerce/my-orders/' . $orderId);
        exit;
    }

    ecRender('modules/ecommerce/public/order-detail.disyl', [
        'page_title' => 'Order ' . $order['order_number'],
        'order'      => $order,
        'user'       => $user,
        'message'    => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);
}

/**
 * Token-based digital product download.
 *
 * GET /ecommerce/download/{token}
 *
 * Authentication: the customer MUST be logged in. After auth, the
 * download_token (64-char hex, 256-bit) is verified against ec_order_licenses
 * and must belong to the authenticated user by customer_id or customer_email.
 *
 * Serves the uploaded digital file when a file path is attached to the
 * product; falls back to serving the JWT license key as a text file when
 * no digital file has been uploaded for the product.
 */
function ecPublicDownloadLicense(array $params): void
{
    // ── Require login ────────────────────────────────────────────────
    $user = app()->user();
    if (!$user || ($user['source'] ?? '') !== 'cms') {
        $redirectTo = '/ecommerce/download/' . urlencode((string)($params['token'] ?? ''));
        header('Location: /cms/login?redirect=' . urlencode($redirectTo));
        exit;
    }

    $token = trim((string)($params['token'] ?? ''));
    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        http_response_code(404);
        ecRender('pages/404.disyl', ['page_title' => 'Download Not Found']);
        return;
    }

    $db  = ecDb();
    $row = $db->query(
        "SELECT id, order_id, product_id, customer_id, customer_email, target_module, target_tier,
                license_key, status, downloaded_at
           FROM ec_order_licenses WHERE download_token = ? LIMIT 1",
        [$token]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$row || $row['status'] !== 'active') {
        http_response_code(404);
        ecRender('pages/404.disyl', ['page_title' => 'Download Not Found']);
        return;
    }

    // ── Verify ownership: customer_id match OR email match ───────────
    $userId    = (int)$user['id'];
    $userEmail = strtolower(trim((string)($user['email'] ?? '')));
    $ownerById = ($row['customer_id'] !== null && (int)$row['customer_id'] === $userId);
    $ownerByEmail = ($userEmail !== '' && strtolower(trim((string)$row['customer_email'])) === $userEmail);
    if (!$ownerById && !$ownerByEmail) {
        http_response_code(403);
        ecRender('pages/403.disyl', ['page_title' => 'Access Denied']) || ecRender('pages/404.disyl', ['page_title' => 'Access Denied']);
        return;
    }

    // ── Record first download timestamp ──────────────────────────────
    if (!$row['downloaded_at']) {
        try {
            moduleWithContext('cms', static function () use ($row): void {
                cmsDb()->execute(
                    "UPDATE ec_order_licenses SET downloaded_at = NOW() WHERE id = ?",
                    [(int)$row['id']]
                );
            });
        } catch (\Throwable $e) {
            write_log('ecPublicDownloadLicense: could not record download: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce']);
        }
    }

    // ── Try to serve the uploaded digital file ───────────────────────
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
                // Sanitise filename for Content-Disposition header.
                $safeFile = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $safeFile) ?: 'download';

                header('Content-Type: ' . $mime);
                header('Content-Disposition: attachment; filename="' . $safeFile . '"');
                header('Content-Length: ' . filesize($storagePath));
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('X-Content-Type-Options: nosniff');
                readfile($storagePath);
                exit;
            }

            write_log('ecPublicDownloadLicense: file missing on disk: ' . $storagePath, 'warning', ['module' => 'ecommerce']);
        }
    }

    // ── Fallback: serve the JWT license key as a text file ───────────
    $module   = preg_replace('/[^a-z0-9_\-]/i', '', (string)($row['target_module'] ?? 'module'));
    $filename = 'license-' . $module . '.jwt';

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $row['license_key'];
    exit;
}
