<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Coupons (handlers/60-admin-coupons.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET  /admin/ecommerce/coupons  — coupon list
 * POST /admin/ecommerce/coupons  — create coupon
 */
function ecAdminCoupons(): void
{
    $user = ecRequireAdmin();
    $db   = ecDb();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input  = ecInput();
        $action = $input['action'] ?? 'create';

        if ($action === 'create') {
            $code = strtoupper(trim((string)($input['code'] ?? '')));
            if ($code !== '') {
                try {
                    $db->execute(
                        "INSERT INTO ec_coupons (code, type, value, min_order_amount, max_uses, expires_at, description, is_active, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
                        [
                            $code,
                            ecCouponNormalizeType((string)($input['type'] ?? 'percent')),
                            max(0, (float)($input['value'] ?? 0)),
                            max(0, (float)($input['min_order_amount'] ?? 0)),
                            ($input['max_uses'] ?? '') !== '' ? (int)$input['max_uses'] : null,
                            ($input['expires_at'] ?? '') !== '' ? $input['expires_at'] : null,
                            trim((string)($input['description'] ?? '')),
                        ]
                    );
                    $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Coupon created.'];
                } catch (\Throwable $e) {
                    $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Could not create: ' . $e->getMessage()];
                }
            } else {
                $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Coupon code is required.'];
            }
        } elseif ($action === 'toggle') {
            $id = (int)($input['id'] ?? 0);
            if ($id > 0) {
                $db->execute("UPDATE ec_coupons SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?", [$id]);
                $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Coupon updated.'];
            }
        } elseif ($action === 'delete') {
            $id = (int)($input['id'] ?? 0);
            if ($id > 0) {
                $db->execute("DELETE FROM ec_coupons WHERE id = ?", [$id]);
                $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Coupon deleted.'];
            }
        }

        header('Location: /ecommerce/admin/coupons');
        exit;
    }

    $coupons = $db->query(
        "SELECT id, code, type, value, min_order_amount, max_uses, uses_count, expires_at, is_active, description
         FROM ec_coupons ORDER BY created_at DESC"
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $ctx = ecAdminContext($user, 'coupons', [
        'coupons' => $coupons,
        'coupon_type_options' => ecCouponAllowedTypes(),
        'message' => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/coupons.disyl', $ctx);
    
    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}
