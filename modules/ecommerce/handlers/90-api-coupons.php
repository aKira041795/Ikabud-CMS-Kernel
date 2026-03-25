<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — API: Coupons (handlers/90-api-coupons.php)
// ─────────────────────────────────────────────────────────────────────────

function ecApiCouponCreate(): void
{
    ecRequireAdmin();
    $input = ecInput();
    $code  = strtoupper(trim((string)($input['code'] ?? '')));

    if (!$code) {
        ecJsonError('code required', 422);
    }

    try {
        ecDb()->execute(
            "INSERT INTO ec_coupons (code, type, value, min_order_amount, max_uses, expires_at, description, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
            [
                $code,
                in_array($input['type'] ?? '', ['percent', 'fixed'], true) ? $input['type'] : 'percent',
                max(0, (float)($input['value'] ?? 0)),
                max(0, (float)($input['min_order_amount'] ?? 0)),
                ($input['max_uses'] ?? '') !== '' ? (int)$input['max_uses'] : null,
                ($input['expires_at'] ?? '') !== '' ? $input['expires_at'] : null,
                trim((string)($input['description'] ?? '')),
            ]
        );
        $id = (int)ecDb()->lastInsertId();
        ecJsonOk(['coupon_id' => $id], 201);
    } catch (\Throwable $e) {
        ecJsonError('Create failed: ' . $e->getMessage(), 422);
    }
}

function ecApiCouponUpdate(): void
{
    ecRequireAdmin();
    $id    = (int)(ecCtx()['params']['id'] ?? 0);
    $input = ecInput();

    $fields = [];
    $params = [];

    if (isset($input['is_active'])) {
        $fields[]  = 'is_active = ?';
        $params[]  = (int)(bool)$input['is_active'];
    }
    if (isset($input['expires_at'])) {
        $fields[]  = 'expires_at = ?';
        $params[]  = $input['expires_at'] ?: null;
    }
    if (isset($input['max_uses'])) {
        $fields[]  = 'max_uses = ?';
        $params[]  = $input['max_uses'] !== '' ? (int)$input['max_uses'] : null;
    }
    if (isset($input['description'])) {
        $fields[]  = 'description = ?';
        $params[]  = trim((string)$input['description']);
    }

    if (empty($fields)) {
        ecJsonError('Nothing to update', 422);
    }

    $fields[]  = 'updated_at = NOW()';
    $params[]  = $id;

    ecDb()->execute('UPDATE ec_coupons SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    ecJsonOk(['ok' => true]);
}

function ecApiCouponDelete(): void
{
    ecRequireAdmin();
    $id = (int)(ecCtx()['params']['id'] ?? 0);
    ecDb()->execute("DELETE FROM ec_coupons WHERE id = ?", [$id]);
    ecJsonOk(['deleted' => true]);
}
