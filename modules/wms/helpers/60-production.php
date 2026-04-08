<?php

declare(strict_types=1);

use PDO;
use Throwable;

function wmsRecipeCreate(array $data): int
{
    $productId = wmsRequirePositiveId((int)($data['product_id'] ?? 0), 'Finished Good Product ID');
    $name = wmsSanitizeString($data['name'] ?? '', 255);
    $expectedYield = wmsNormalizeDecimal($data['expected_yield'] ?? 1.0);
    $instructions = isset($data['instructions']) ? wmsSanitizeString($data['instructions'], 5000) : null;

    if ($name === '') {
        throw new RuntimeException('Recipe name is required.');
    }

    $db = wmsDb();
    $db->execute(
        'INSERT INTO wms_recipes (product_id, name, expected_yield, instructions, created_at) VALUES (?, ?, ?, ?, NOW())',
        [$productId, $name, $expectedYield, $instructions]
    );
    $recipeId = (int)$db->lastInsertId();

    if (!empty($data['items']) && is_array($data['items'])) {
        foreach ($data['items'] as $item) {
            wmsRecipeItemAdd($recipeId, $item);
        }
    }

    return $recipeId;
}

function wmsRecipeItemAdd(int $recipeId, array $item): void
{
    $materialId = wmsRequirePositiveId((int)($item['material_product_id'] ?? 0), 'Material Product ID');
    $qty = wmsNormalizeDecimal($item['qty_required'] ?? 0);
    if ($qty <= 0) {
        throw new RuntimeException('Material quantity must be greater than zero.');
    }
    $substitutable = (int)($item['is_substitutable'] ?? 0);

    wmsDb()->execute(
        'INSERT INTO wms_recipe_items (recipe_id, material_product_id, qty_required, is_substitutable, created_at) VALUES (?, ?, ?, ?, NOW())',
        [$recipeId, $materialId, $qty, $substitutable]
    );
}

function wmsProductionOrderCreate(array $data, ?int $actorUserId = null): int
{
    $referenceNo = wmsSanitizeString($data['reference_no'] ?? '', 100);
    $recipeId = wmsRequirePositiveId((int)($data['recipe_id'] ?? 0), 'Recipe ID');
    $warehouseId = wmsRequirePositiveId((int)($data['warehouse_id'] ?? 0), 'Warehouse ID');
    $targetQty = wmsNormalizeDecimal($data['target_qty'] ?? 0);

    if ($referenceNo === '') {
        $referenceNo = 'PROD-' . date('YmdHis') . '-' . mt_rand(100, 999);
    }
    if ($targetQty <= 0) {
        throw new RuntimeException('Target production quantity must be > 0.');
    }

    $recipe = wmsFetchOne('SELECT * FROM wms_recipes WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$recipeId]);
    if (!$recipe) {
        throw new RuntimeException('Recipe not found or deleted.');
    }
    $multiplier = $targetQty / max(1.0, (float)$recipe['expected_yield']);

    $db = wmsDb();
    $db->beginTransaction();

    try {
        $db->execute(
            'INSERT INTO wms_production_orders (reference_no, recipe_id, warehouse_id, target_qty, notes, actor_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$referenceNo, $recipeId, $warehouseId, $targetQty, isset($data['notes']) ? wmsSanitizeString($data['notes'], 2000) : null, $actorUserId]
        );
        $orderId = (int)$db->lastInsertId();

        $items = wmsFetchAll('SELECT * FROM wms_recipe_items WHERE recipe_id = ?', [$recipeId]);
        foreach ($items as $item) {
            $reqQty = wmsNormalizeDecimal((float)$item['qty_required'] * $multiplier);
            $db->execute(
                'INSERT INTO wms_production_materials (production_order_id, material_product_id, qty_required) VALUES (?, ?, ?)',
                [$orderId, (int)$item['material_product_id'], $reqQty]
            );
        }

        $db->commit();
        return $orderId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function wmsProductionOrderStart(int $orderId, ?int $actorUserId = null): void
{
    $order = wmsFetchOne('SELECT * FROM wms_production_orders WHERE id = ? FOR UPDATE', [$orderId]);
    if (!$order || $order['status'] !== 'pending') {
        throw new RuntimeException('Order not found or not pending.');
    }

    wmsDb()->execute('UPDATE wms_production_orders SET status = ?, started_at = NOW(), actor_user_id = ? WHERE id = ?', ['in_production', $actorUserId, $orderId]);
    
    // Explicitly create an associated task for assembly
    wmsTaskCreate([
        'warehouse_id' => (int)$order['warehouse_id'],
        'task_type' => 'putaway', // In lieu of an explicit assembly task type, or we map to general pending
        'status' => 'in_progress',
        'reference_type' => 'production_order',
        'reference_id' => $orderId,
        'assigned_to' => $actorUserId,
        'notes' => 'Production active for Ref ' . $order['reference_no']
    ]);
}

function wmsProductionOrderComplete(int $orderId, array $payload, ?int $actorUserId = null): array
{
    $actualYield = wmsNormalizeDecimal($payload['actual_yield'] ?? 0);
    $locationId = wmsRequirePositiveId((int)($payload['location_id'] ?? 0), 'Putaway Location ID');

    if ($actualYield <= 0) {
        throw new RuntimeException('Actual yield must be greater than zero to complete production.');
    }

    $order = wmsFetchOne('SELECT * FROM wms_production_orders WHERE id = ? FOR UPDATE', [$orderId]);
    if (!$order || $order['status'] !== 'in_production') {
        throw new RuntimeException('Order not found or not in production.');
    }
    
    $recipe = wmsFetchOne('SELECT product_id FROM wms_recipes WHERE id = ?', [(int)$order['recipe_id']]);

    $db = wmsDb();
    $db->beginTransaction();
    $movementIds = [];

    try {
        // 1. Consume all required raw materials
        $materials = wmsFetchAll('SELECT * FROM wms_production_materials WHERE production_order_id = ?', [$orderId]);
        foreach ($materials as $mat) {
            $reqQty = (float)$mat['qty_required'];
            
            // Auto-consume from default picking location for simplicity (FEFO abstraction could be injected here)
            // Look up stock for this material in the warehouse
            $stockQuery = wmsFetchOne('SELECT location_id, batch_id FROM wms_stocks WHERE product_id = ? AND warehouse_id = ? AND qty_available >= ? LIMIT 1', 
                [(int)$mat['material_product_id'], (int)$order['warehouse_id'], $reqQty]);
            
            if (!$stockQuery) {
                throw new RuntimeException('Insufficient stock for material ID ' . $mat['material_product_id'] . ' to complete production.');
            }

            $movementIds[] = wmsMovementCreate([
                'movement_type' => 'out',
                'reference_type' => 'production_order',
                'reference_id' => $orderId,
                'product_id' => (int)$mat['material_product_id'],
                'warehouse_id' => (int)$order['warehouse_id'],
                'location_id' => (int)$stockQuery['location_id'],
                'batch_id' => isset($stockQuery['batch_id']) ? (int)$stockQuery['batch_id'] : null,
                'qty' => -$reqQty,
                'actor_user_id' => $actorUserId,
                'notes' => 'Consumed for production ' . $order['reference_no']
            ]);
            
            $db->execute('UPDATE wms_production_materials SET qty_consumed = ? WHERE id = ?', [$reqQty, (int)$mat['id']]);
        }

        // 2. Putaway the finished goods
        $movementIds[] = wmsMovementCreate([
            'movement_type' => 'in',
            'reference_type' => 'production_order',
            'reference_id' => $orderId,
            'product_id' => (int)$recipe['product_id'],
            'warehouse_id' => (int)$order['warehouse_id'],
            'location_id' => $locationId,
            'batch_id' => isset($payload['batch_id']) && (int)$payload['batch_id'] > 0 ? (int)$payload['batch_id'] : null,
            'qty' => $actualYield,
            'actor_user_id' => $actorUserId,
            'notes' => 'Finished good generated from production ' . $order['reference_no']
        ]);

        $db->execute('UPDATE wms_production_orders SET status = ?, actual_yield = ?, completed_at = NOW(), actor_user_id = ? WHERE id = ?', 
            ['completed', $actualYield, $actorUserId, $orderId]);
            
        // Complete linked task
        $db->execute("UPDATE wms_tasks SET status = 'completed', completed_at = NOW() WHERE reference_type = 'production_order' AND reference_id = ? AND status = 'in_progress'", [$orderId]);

        $db->commit();
        
        wmsAudit('wms.production.completed', 'wms_production_orders', (string)$orderId, null, [
            'actual_yield' => $actualYield,
            'product_id' => (int)$recipe['product_id']
        ]);

        return $movementIds;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
