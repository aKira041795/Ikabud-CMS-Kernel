<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — API: Cart (handlers/82-api-cart.php)
// ─────────────────────────────────────────────────────────────────────────

function ecApiCartGet(): void
{
    ecJsonOk(['cart' => ecCartGet()]);
}

function ecApiCartAdd(): void
{
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

    if (!$productId) {
        ecJsonError('product_id required', 422);
    }

    $result = ecCartAdd($productId, $qty, $variantId);
    if (!$result['ok']) {
        ecJsonError($result['error'], 422);
    }

    ecJsonOk($result);
}

function ecApiCartUpdate(): void
{
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
    ecCartClear();
    ecJsonOk(['cart' => ecCartGet()]);
}
