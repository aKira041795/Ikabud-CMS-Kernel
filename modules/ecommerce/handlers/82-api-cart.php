<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — API: Cart (handlers/82-api-cart.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Enforce CSRF for cart mutation endpoints when called from a browser session.
 * API clients using Bearer tokens are exempt (the token itself acts as CSRF proof).
 */
function ecApiCartCsrfEnforce(): void
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader === '' || !str_starts_with(strtolower($authHeader), 'bearer ')) {
        app()->csrfEnforce();
    }
}

function ecApiCartGet(): void
{
    ecJsonOk(['cart' => ecCartGet()]);
}

function ecApiCartAdd(): void
{
    ecApiCartCsrfEnforce();

    // F19: Rate limit add-to-cart to 30 requests/minute per IP.
    try {
        if (PHP_SAPI !== 'cli') {
        $rlId = kernelLoginRateLimitIdentifier('ecommerce.cart');
        $rlDb = app()->db();
        $rlCutoff = date('Y-m-d H:i:s', time() - 60);
        $rlDb->prepare(
            'INSERT INTO rate_limits (identifier, action, attempts, window_start)
             VALUES (:id, :act, 1, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                 attempts     = IF(window_start >= :c1, attempts + 1, 1),
                 window_start = IF(window_start >= :c2, window_start, CURRENT_TIMESTAMP)'
        )->execute([':id' => $rlId, ':act' => 'cart_add', ':c1' => $rlCutoff, ':c2' => $rlCutoff]);
        $rlRow = $rlDb->prepare('SELECT attempts, window_start FROM rate_limits WHERE identifier = :id AND action = :act LIMIT 1');
        $rlRow->execute([':id' => $rlId, ':act' => 'cart_add']);
        $rlData = $rlRow->fetch(\PDO::FETCH_ASSOC);
        if (is_array($rlData) && ($rlData['window_start'] ?? '') >= $rlCutoff && (int)($rlData['attempts'] ?? 0) > 30) {
            ecJsonError('Too many requests. Please slow down.', 429);
        }
        }
    } catch (\Throwable $ignored) {
        // Non-fatal: proceed if rate_limits table unavailable.
    }

    $input     = ecInput();
    $groupedItems = is_array($input['grouped_items'] ?? null) ? $input['grouped_items'] : [];

    if ($groupedItems !== []) {
        $result = ecCartAddGroupedItems($groupedItems);
        if (!$result['ok']) {
            ecJsonError($result['error'] ?? 'Could not add grouped products', 422);
        }

        ecJsonOk($result);
    }

    $productId = (int)($input['product_id'] ?? $input['entity_id'] ?? 0);
    $qty       = max(1, (int)($input['qty'] ?? 1));
    $variantId = isset($input['variant_id']) ? (int)$input['variant_id'] : null;
    $options   = [
        'add_ons' => is_array($input['add_ons'] ?? null) ? $input['add_ons'] : (is_array($input['addons'] ?? null) ? $input['addons'] : []),
        'booking' => is_array($input['booking'] ?? null) ? $input['booking'] : [],
    ];

    if (!$productId) {
        ecJsonError('product_id required', 422);
    }

    $result = ecCartAdd($productId, $qty, $variantId, $options);
    if (!$result['ok']) {
        ecJsonError($result['error'], 422);
    }

    ecJsonOk($result);
}

function ecApiCartUpdate(): void
{
    ecApiCartCsrfEnforce();
    $input     = ecInput();
    $itemIndex = (int)($input['item_index'] ?? -1);
    $qty       = (int)($input['qty'] ?? 0);

    if ($itemIndex < 0) {
        ecJsonError('item_index required', 422);
    }

    $result = ecCartUpdate($itemIndex, $qty);
    if (!$result['ok']) {
        ecJsonError((string)($result['error'] ?? 'Could not update cart item'), 422);
    }
    ecJsonOk($result);
}

function ecApiCartRemove(): void
{
    ecApiCartCsrfEnforce();
    $input     = ecInput();
    $itemIndex = (int)($input['item_index'] ?? -1);

    if ($itemIndex < 0) {
        ecJsonError('item_index required', 422);
    }

    $result = ecCartRemove($itemIndex);
    ecJsonOk($result);
}

function ecApiCartApplyCoupon(): void
{
    ecApiCartCsrfEnforce();

    // Rate limit coupon validation to prevent brute-force guessing of coupon codes
    if (function_exists('kernelRateLimit')) {
        $rl = kernelRateLimit('coupon_apply', 15, 300); // 15 attempts per 5 minutes
        if ($rl['limited']) {
            if (function_exists('kernelEmitRateLimitJson')) {
                kernelEmitRateLimitJson($rl, 'Too many coupon attempts. Please wait.');
            } else {
                ecJsonError('Too many coupon attempts. Please wait.', 429);
            }
            exit;
        }
    }

    $input = ecInput();
    $code  = trim((string)($input['code'] ?? ''));

    if ($code === '') {
        ecJsonError('code required', 422);
    }

    $result = ecCartApplyCoupon($code);
    if (!$result['ok']) {
        ecJsonError($result['error'] ?? 'Invalid coupon', 422);
    }

    ecJsonOk($result);
}

function ecApiCartClear(): void
{
    ecApiCartCsrfEnforce();
    ecCartClear();
    ecJsonOk(['cart' => ecCartGet()]);
}

function ecApiCartApplyLoyalty(): void
{
    ecApiCartCsrfEnforce();
    $input = ecInput();
    $points = max(0, (int)($input['points'] ?? 0));

    $result = $points > 0
        ? (function_exists('ecCartApplyLoyalty') ? ecCartApplyLoyalty($points) : ['ok' => false, 'error' => 'Loyalty rewards are unavailable.'])
        : (function_exists('ecCartClearLoyalty') ? ecCartClearLoyalty() : ['ok' => true, 'cart' => ecCartGet()]);

    if (empty($result['ok'])) {
        ecJsonError((string)($result['error'] ?? 'Could not apply loyalty points.'), 422);
    }

    ecJsonOk($result);
}
