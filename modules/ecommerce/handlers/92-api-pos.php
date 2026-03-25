<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — API: POS (handlers/92-api-pos.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /api/v1/ecommerce/pos/products?search=...
 */
function ecApiPosProducts(): void
{
    ecRequireAdmin();
    $query    = trim((string)(ecInput()['search'] ?? ''));
    $products = ecPosProductSearch($query);
    ecJsonOk(['products' => $products]);
}

/**
 * POST /api/v1/ecommerce/pos/transaction
 */
function ecApiPosTransaction(): void
{
    $user = ecRequireAdmin();
    csrf_verify();

    $input = ecInput();
    $items = (array)($input['items'] ?? []);

    if (empty($items)) {
        ecJsonError('No items in sale', 422);
    }

    // Validate + normalize items
    $saleItems = [];
    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        if (!$productId) {
            ecJsonError('product_id required for each item', 422);
        }
        $product = ecProductGet($productId);
        if (!$product) {
            ecJsonError('Product not found: ' . $productId, 422);
        }
        $pricing = $product['pricing'];
        $saleItems[] = [
            'product_id'     => $productId,
            'variant_id'     => isset($item['variant_id']) ? (int)$item['variant_id'] : null,
            'qty'            => max(1, (int)($item['qty'] ?? 1)),
            'price_snapshot' => isset($item['price_override'])
                ? (float)$item['price_override']
                : (float)($pricing['price'] ?? 0),
            'product_title'  => $product['title'],
            'sku'            => $product['inventory']['sku'] ?? '',
        ];
    }

    try {
        $result = ecPosSale($saleItems, [
            'cashier_user_id' => (int)$user['id'],
            'tender_amount'   => isset($input['tender_amount']) ? (float)$input['tender_amount'] : null,
            'coupon_code'     => $input['coupon_code'] ?? null,
            'customer_note'   => $input['customer_note'] ?? null,
        ]);
    } catch (\Throwable $e) {
        write_log('ecApiPosTransaction error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        ecJsonError('Sale failed: ' . $e->getMessage(), 422);
    }

    ecJsonOk($result, 201);
}
