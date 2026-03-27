<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — API: Products (handlers/80-api-products.php)
// ─────────────────────────────────────────────────────────────────────────

function ecApiProductsList(): void
{
    $input  = ecInput();

    // Accept ?cats[]=1&cats[]=2 (multi-category) or legacy ?cat=1 (single).
    $categoryIds = [];
    if (!empty($input['cats'])) {
        $categoryIds = array_values(array_unique(array_map('intval', (array)$input['cats'])));
    } elseif (isset($input['cat'])) {
        $categoryIds = [(int)$input['cat']];
    }

    $result = ecProductList([
        'search'       => $input['search']  ?? '',
        'category_ids' => $categoryIds,
        'status'       => $input['status']  ?? 'published',
        'limit'        => min(50, (int)($input['limit']  ?? 12)),
        'offset'       => max(0,  (int)($input['offset'] ?? 0)),
    ]);

    ecJsonOk($result);
}

function ecApiCategoryList(): void
{
    try {
        $db = ecDb();
        $rows = $db->query(ecCmsCategorySelectSql('id, name, slug'), [])->fetchAll(\PDO::FETCH_ASSOC);
        ecJsonOk(['categories' => is_array($rows) ? $rows : []]);
    } catch (\Throwable $e) {
        write_log('ecApiCategoryList error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        ecJsonOk(['categories' => []]);
    }
}

function ecApiProductGet(array $params = []): void
{
    $id      = (int)($params['id'] ?? 0);
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

function ecApiProductUpdate(array $params = []): void
{
    ecRequireAdmin();
    $id   = (int)($params['id'] ?? 0);
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

function ecApiProductDelete(array $params = []): void
{
    ecRequireAdmin();
    $id = (int)($params['id'] ?? 0);

    if (!ecProductGet($id)) {
        ecJsonError('Product not found', 404);
    }

    ecProductDelete($id);
    ecJsonOk(['deleted' => true]);
}
