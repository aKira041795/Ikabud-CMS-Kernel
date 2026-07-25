<?php
/**
 * DC Cafe — Product Stock Handlers
 *
 * Receive finished product deliveries, view product stock levels.
 */

declare(strict_types=1);

/**
 * GET /dc-cafe/products/receive — Receive product stock page
 */
function pageReceiveProducts(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $products = dcDb()->query(
        "SELECT product_id, name, base_price, current_stock, reorder_level, category_id, has_stock
         FROM dc_products WHERE is_active = 1 AND has_stock = 1 ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    echo dcRender('products/receive.disyl', [
        'page_title' => 'Receive Products',
        'products' => $products,
    ]);
}

/**
 * POST /dc-cafe/api/v1/products/receive/batch — Receive multiple products
 */
function apiReceiveProductsBatch(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $items = (array) (dcInput('items') ?? []);
    if (empty($items)) {
        dcJsonError('Items array is required');
    }

    $db = dcDb();
    $userId = (int) $ctx->user()['user_id'];
    $processed = 0;
    $errors = [];

    $db->beginTransaction();
    try {
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 0);
            $notes = (string) ($item['notes'] ?? '');

            if ($productId <= 0 || $quantity <= 0) {
                $errors[] = "Invalid product_id or quantity";
                continue;
            }

            $product = $db->query(
                "SELECT * FROM dc_products WHERE product_id = ? AND has_stock = 1",
                [$productId]
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$product) {
                $errors[] = "Product ID $productId not found or not stock-tracked";
                continue;
            }

            $db->query(
                "UPDATE dc_products SET current_stock = current_stock + ? WHERE product_id = ?",
                [$quantity, $productId]
            );

            $db->query(
                "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type,
                        reference_type, notes, created_by)
                 VALUES (?, ?, 'purchase', 'supplier', ?, ?)",
                [$productId, $quantity, $notes ?: 'Product delivery received', $userId]
            );

            $processed++;
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dcJsonError('Failed to process product delivery: ' . $e->getMessage(), 500);
    }

    dcJsonResponse([
        'ok' => true,
        'processed' => $processed,
        'errors' => $errors,
        'message' => $processed . ' product(s) received.' . ($errors ? ' ' . count($errors) . ' error(s).' : ''),
    ]);
}

/**
 * GET /dc-cafe/api/v1/products/stock — Current product stock levels
 */
function apiGetProductStockLevels(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    $products = dcDb()->query(
        "SELECT p.product_id, p.name, p.base_price, p.current_stock, p.reorder_level,
                c.name AS category_name,
                CASE WHEN p.current_stock <= p.reorder_level AND p.reorder_level > 0 THEN 1 ELSE 0 END AS is_low
         FROM dc_products p
         LEFT JOIN dc_categories c ON c.category_id = p.category_id
         WHERE p.is_active = 1 AND p.has_stock = 1
         ORDER BY is_low DESC, p.name ASC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse(['ok' => true, 'products' => $products]);
}
