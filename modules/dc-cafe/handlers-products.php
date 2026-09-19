<?php
/**
 * DC Cafe — Product Stock Handlers
 *
 * Receive finished product deliveries, view product stock levels.
 */

declare(strict_types=1);

function _dcResolveInventoryStoreId(\Ikabud\Kernel\Contracts\ModuleContext $ctx, int $requestedStoreId): int
{
    $user = $ctx->user();
    $role = (string) ($user['role'] ?? '');
    $defaultStoreId = (int) ($user['store_id'] ?? 0);
    $effectiveStoreId = $requestedStoreId > 0 ? $requestedStoreId : $defaultStoreId;

    if ($effectiveStoreId <= 0) {
        dcJsonError('Store ID is required', 400);
    }

    if ($role === 'cashier' && $defaultStoreId > 0 && $effectiveStoreId !== $defaultStoreId) {
        dcJsonError('Cashier cannot modify another branch inventory', 403);
    }

    $store = dcDb()->query(
        "SELECT store_id, is_active FROM dc_stores WHERE store_id = ?",
        [$effectiveStoreId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$store) {
        dcJsonError('Store not found', 404);
    }
    if ((int) ($store['is_active'] ?? 0) !== 1) {
        dcJsonError('Store is inactive', 422);
    }

    return $effectiveStoreId;
}

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
    // What actually landed, with the branch each item resolved to. Collected
    // here rather than from the raw payload so the trail cannot report store 0
    // for a request that omitted store_id.
    $received = [];
    // Use session store_id as default branch for receiving
    $defaultStoreId = _dcResolveInventoryStoreId($ctx, (int) (dcInput('store_id') ?? 0));

    $db->beginTransaction();
    try {
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 0);
            $notes = (string) ($item['notes'] ?? '');
            $itemStoreId = _dcResolveInventoryStoreId($ctx, (int) ($item['store_id'] ?? $defaultStoreId));

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

            // Update branch stock; insert row if not exists
            $db->query(
                "INSERT INTO dc_product_store_stock (product_id, store_id, on_hand_qty, reorder_level, version)
                 VALUES (?, ?, ?, COALESCE((SELECT reorder_level FROM dc_products WHERE product_id = ?), 0), 1)
                 ON DUPLICATE KEY UPDATE on_hand_qty = on_hand_qty + ?, version = version + 1",
                [$productId, $itemStoreId, $quantity, $productId, $quantity]
            );

            // Record branch-aware product stock movement
            $db->query(
                "INSERT INTO dc_product_stock_movements (product_id, store_id, quantity_change, movement_type,
                        reference_type, notes, created_by)
                 VALUES (?, ?, ?, 'purchase', 'supplier', ?, ?)",
                [$productId, $itemStoreId, $quantity, $notes ?: 'Product delivery received', $userId]
            );

            $processed++;
            if (count($received) < 20) {
                $received[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'store_id' => $itemStoreId,
                ];
            }
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dcJsonError('Failed to process product delivery: ' . $e->getMessage(), 500);
    }

    // Stock arriving is a ledger event: it becomes the Additional column in the
    // reconciliation, so it belongs in the trail alongside the movement itself.
    if ($received !== []) {
        dc_auditLog('stock.received', 'dc_products', (string) $defaultStoreId, null, [
            'products' => $processed,
            'items' => $received,
        ]);
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
    $storeId = _dcResolveInventoryStoreId($ctx, (int) (dcInput('store_id') ?? 0));
    $catalogStoreId = dcCatalogStoreId($storeId);

    $products = dcDb()->query(
        "SELECT p.product_id, p.name, p.base_price,
                COALESCE(pss.on_hand_qty, 0) AS current_stock,
                COALESCE(pss.reorder_level, p.reorder_level) AS reorder_level,
                c.name AS category_name,
                CASE WHEN COALESCE(pss.on_hand_qty, 0) <= COALESCE(pss.reorder_level, p.reorder_level)
                          AND COALESCE(pss.reorder_level, p.reorder_level) > 0
                     THEN 1 ELSE 0 END AS is_low
         FROM dc_products p
         LEFT JOIN dc_categories c ON c.category_id = p.category_id
         LEFT JOIN dc_product_store_stock pss
               ON pss.product_id = p.product_id AND pss.store_id = ?
         WHERE p.store_id = ? AND p.is_active = 1 AND p.has_stock = 1
         ORDER BY is_low DESC, p.name ASC"
    , [$storeId, $catalogStoreId])->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse(['ok' => true, 'products' => $products]);
}

/**
 * PATCH /dc-cafe/api/v1/inventory/product-stock/{id} — Inline update product stock/reorder
 */
function apiUpdateProductStock(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid product ID', 400);
    }

    $currentStock = dcInput('current_stock');
    $reorderLevel = dcInput('reorder_level');
    $hasStock = $currentStock !== null;
    $hasReorder = $reorderLevel !== null;

    if (!$hasStock && !$hasReorder) {
        dcJsonError('Nothing to update', 400);
    }

    $db = dcDb();
    $userId = (int) $ctx->user()['user_id'];
    $adjustStoreId = _dcResolveInventoryStoreId($ctx, (int) (dcInput('store_id') ?? 0));
    $product = $db->query(
        "SELECT p.product_id, p.current_stock, p.reorder_level,
                pss.on_hand_qty AS branch_on_hand
         FROM dc_products p
         LEFT JOIN dc_product_store_stock pss
               ON pss.product_id = p.product_id AND pss.store_id = ?
         WHERE p.product_id = ?",
        [$adjustStoreId, $id]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$product) {
        dcJsonError('Product not found', 404);
    }

    $sets = [];
    $vals = [];
    $stockChanged = false;
    $oldStock = isset($product['branch_on_hand']) ? (float) $product['branch_on_hand'] : 0.0;
    $newStock = $oldStock;

    if ($hasStock) {
        $newStock = (float) $currentStock;
        if ($newStock < 0) {
            dcJsonError('Stock cannot be negative', 422);
        }
        $stockChanged = abs($newStock - $oldStock) > 0.0001;
    }

    if ($hasReorder) {
        $sets[] = 'reorder_level = ?';
        $vals[] = (float) $reorderLevel;
    }

    $db->beginTransaction();
    try {
        if ($stockChanged) {
            // Update branch stock; insert row if not exists
            $db->query(
                "INSERT INTO dc_product_store_stock (product_id, store_id, on_hand_qty, reorder_level, version)
                 VALUES (?, ?, ?, COALESCE((SELECT reorder_level FROM dc_products WHERE product_id = ?), 0), 1)
                 ON DUPLICATE KEY UPDATE on_hand_qty = ?, version = version + 1",
                [$id, $adjustStoreId, $newStock, $id, $newStock]
            );
            $db->query(
                "INSERT INTO dc_product_stock_movements (product_id, store_id, quantity_change, movement_type,
                        reference_type, reference_id, notes, created_by)
                 VALUES (?, ?, ?, 'adjustment', 'inventory_edit', ?, ?, ?)",
                [$id, $adjustStoreId, $newStock - $oldStock, $id, 'Inline product stock edit', $userId]
            );
        }
        if ($hasReorder) {
            $vals[] = $id;
            $db->query("UPDATE dc_products SET " . implode(', ', $sets) . " WHERE product_id = ?", $vals);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dcJsonError('Failed to update product stock: ' . $e->getMessage(), 500);
    }

    // A manual correction also lands in the reconciliation's Additional column,
    // so the trail records who moved stock by hand and by how much.
    if ($stockChanged) {
        dc_auditLog('stock.adjusted', 'dc_products', (string) $id, ['on_hand_qty' => $oldStock], [
            'on_hand_qty' => $newStock,
            'delta' => $newStock - $oldStock,
            'store_id' => $adjustStoreId,
        ]);
    } elseif ($hasReorder) {
        dc_auditLog('product.reorder_level_changed', 'dc_products', (string) $id, null, [
            'reorder_level' => (float) $reorderLevel,
        ]);
    }

    dcJsonResponse(['ok' => true]);
}

/**
 * GET /dc-cafe/api/v1/products/{id}/box-options — slot picker data for a box.
 *
 * Returns the box's standard flavour set (pre-filled defaults) and every flavour
 * eligible to fill a slot: same category, inside the box's price band, in stock.
 */
function apiGetBoxOptions(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid product ID', 400);
    }

    $storeId = _dcResolveInventoryStoreId($ctx, (int) (dcInput('store_id') ?? 0));
    $catalogStoreId = dcCatalogStoreId($storeId);

    $box = dcDb()->query(
        "SELECT p.product_id, p.name, p.base_price, p.has_stock, p.slot_count,
                p.component_category_id, p.component_min_price, p.component_max_price
         FROM dc_products p
         WHERE p.product_id = ? AND p.store_id = ? AND p.is_active = 1",
        [$id, $catalogStoreId]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$box) {
        dcJsonError('Product not found', 404);
    }
    if (!dcBoxIsContainer($box)) {
        dcJsonError('Product is not a box', 422);
    }

    $definition = dcBoxDefinition($box, $storeId);

    // A standard slot that is out of stock forces the cashier to swap it.
    $shortSlots = [];
    foreach ($definition['standard_set'] as $standard) {
        if (!$standard['available']) {
            $shortSlots[] = (int) $standard['slot_no'];
        }
    }

    dcJsonResponse([
        'ok'                  => true,
        'product_id'          => (int) $box['product_id'],
        'name'                => (string) $box['name'],
        'price'               => (float) $box['base_price'],
        'slot_count'          => $definition['slot_count'],
        'min_price'           => $definition['min_price'],
        'max_price'           => $definition['max_price'],
        'has_standard_set'    => $definition['standard_set'] !== [],
        'standard_set'        => $definition['standard_set'],
        'choices'             => $definition['choices'],
        'short_slots'         => $shortSlots,
        // False when every standard flavour is stocked — the POS then adds the
        // box in a single tap without opening the picker.
        'requires_selection'  => $shortSlots !== [],
    ]);
}
