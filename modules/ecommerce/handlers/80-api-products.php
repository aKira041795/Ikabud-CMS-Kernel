<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — API: Products (handlers/80-api-products.php)
// ─────────────────────────────────────────────────────────────────────────

function ecApiProductsList(): void
{
    $input  = ecInput();
    $result = ecProductList([
        'search'      => $input['search']      ?? '',
        'category_id' => isset($input['cat'])  ? (int)$input['cat'] : null,
        'status'      => $input['status']      ?? 'published',
        'limit'       => min(50, (int)($input['limit']  ?? 12)),
        'offset'      => max(0,  (int)($input['offset'] ?? 0)),
    ]);

    ecJsonOk($result);
}

function ecApiProductGet(): void
{
    $id      = (int)(ecCtx()['params']['id'] ?? 0);
    $product = ecProductGet($id);

    if (!$product) {
        ecJsonError('Product not found', 404);
    }
    ecJsonOk(['product' => $product]);
}

function ecApiProductCreate(): void
{
    $user = ecRequireAdmin();
    $data = ecInput();

    try {
        $id = ecProductCreate($data, (int)$user['id']);
        ecJsonOk(['product_id' => $id], 201);
    } catch (\Throwable $e) {
        write_log('ecApiProductCreate: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        ecJsonError('Create failed: ' . $e->getMessage(), 422);
    }
}

function ecApiProductUpdate(): void
{
    ecRequireAdmin();
    $id   = (int)(ecCtx()['params']['id'] ?? 0);
    $data = ecInput();

    if (!ecProductGet($id)) {
        ecJsonError('Product not found', 404);
    }

    try {
        ecProductUpdate($id, $data);
        ecJsonOk(['product' => ecProductGet($id)]);
    } catch (\Throwable $e) {
        ecJsonError('Update failed: ' . $e->getMessage(), 422);
    }
}

function ecApiProductDelete(): void
{
    ecRequireAdmin();
    $id = (int)(ecCtx()['params']['id'] ?? 0);

    if (!ecProductGet($id)) {
        ecJsonError('Product not found', 404);
    }

    ecProductDelete($id);
    ecJsonOk(['deleted' => true]);
}
