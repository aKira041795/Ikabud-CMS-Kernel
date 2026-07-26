<?php
/**
 * DC Cafe — Inventory & Stock Handlers
 *
 * Receive stock, adjust stock, save/load inventory progress, get stock levels,
 * and reconciliation (sales derived from completed orders).
 */

declare(strict_types=1);

/**
 * Load a session and enforce cashier ownership for inventory flows.
 */
function _dcLoadInventorySession(int $sessionId, bool $mustBeActive = false): array
{
    $user = dcCtx()->user();
    $sql = "SELECT * FROM dc_sessions WHERE session_id = ?";
    $params = [$sessionId];
    if ($mustBeActive) {
        $sql .= " AND status = 'active'";
    }
    $session = dcDb()->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);
    if (!$session) {
        dcJsonError($mustBeActive ? 'Active session not found' : 'Session not found', 404);
    }
    if (($user['role'] ?? '') === 'cashier' && (int) $session['user_id'] !== (int) $user['user_id']) {
        dcJsonError('Session belongs to another cashier', 403);
    }
    return $session;
}

/**
 * GET /dc-cafe/api/v1/inventory — Current stock levels
 */
function apiGetStockLevels(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor');

    $inventory = dc_cap_entity_list_inventory_1($params);
    dcJsonResponse(['ok' => true, 'inventory' => $inventory]);
}

/**
 * GET /dc-cafe/api/v1/inventory/reconciliation/{session_id}
 * — Per-session reconciliation view.
 *
 * Returns: per-product actual sales (from completed orders) + saved progress
 * (beginning, production, pullout) so the client can compute expected ending
 * and compare with physical count.
 */
function apiGetReconciliation(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $sessionId = (int) ($params['session_id'] ?? 0);
    if ($sessionId <= 0) {
        dcJsonError('Invalid session ID');
    }

    $db = dcDb();
    _dcLoadInventorySession($sessionId);

    // Sales by product — quantity from completed order items in this session
    $sales = $db->query(
        "SELECT oi.product_id, SUM(oi.quantity) AS qty_sold
         FROM dc_order_items oi
         JOIN dc_orders o ON o.order_id = oi.order_id
         WHERE o.session_id = ? AND o.status = 'completed'
         GROUP BY oi.product_id",
        [$sessionId]
    )->fetchAll(\PDO::FETCH_ASSOC);
    $salesMap = [];
    foreach ($sales as $s) {
        $salesMap[(int) $s['product_id']] = (float) $s['qty_sold'];
    }

    // Saved progress (manual beginning/production/pullout counts)
    $progress = $db->query(
        "SELECT * FROM dc_inventory_progress WHERE session_id = ?",
        [$sessionId]
    )->fetchAll(\PDO::FETCH_ASSOC);
    $progressMap = [];
    foreach ($progress as $p) {
        $progressMap[(int) $p['product_id']] = $p;
    }

    // All active products with their ledger group
    $products = $db->query(
        "SELECT p.product_id, p.name, c.name AS category_name,
                COALESCE(c.ledger_group, c.name) AS ledger_group,
                p.current_stock
         FROM dc_products p
         JOIN dc_categories c ON c.category_id = p.category_id
         WHERE p.is_active = 1
         ORDER BY COALESCE(c.ledger_group, c.name), c.sort_order, p.name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $items = [];
    foreach ($products as $p) {
        $pid = (int) $p['product_id'];
        $saved = $progressMap[$pid] ?? null;
        $item = [
            'product_id' => $pid,
            'name' => $p['name'],
            'category_name' => $p['category_name'],
            'ledger_group' => $p['ledger_group'],
            'beginning_qty' => $saved ? (float) $saved['beginning_qty'] : 0,
            'production_qty' => $saved ? (float) $saved['production_qty'] : 0,
            'pullout_qty' => $saved ? (float) $saved['pullout_qty'] : 0,
            'sold_qty' => $salesMap[$pid] ?? 0,
            'notes' => $saved ? ($saved['notes'] ?? '') : '',
            'current_stock' => (float) $p['current_stock'],
        ];
        $item['expected_ending'] = $item['beginning_qty'] + $item['production_qty'] - $item['pullout_qty'] - $item['sold_qty'];
        $items[] = $item;
    }

    dcJsonResponse(['ok' => true, 'items' => $items]);
}

/**
function apiReceiveStock(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $ingredientId = (int) (dcInput('ingredient_id') ?? 0);
    $quantity = (float) (dcInput('quantity') ?? 0);
    $costPerUnit = (float) (dcInput('cost_per_unit') ?? 0);
    $supplierId = dcInput('supplier_id') ? (int) dcInput('supplier_id') : null;
    $notes = (string) (dcInput('notes') ?? '');

    if ($ingredientId <= 0 || $quantity <= 0) {
        dcJsonError('Valid ingredient_id and quantity are required');
    }

    $db = dcDb();

    // Verify ingredient exists
    $ingredient = $db->query("SELECT * FROM dc_ingredients WHERE ingredient_id = ?", [$ingredientId])->fetch();
    if (!$ingredient) {
        dcJsonError('Ingredient not found', 404);
    }

    $db->beginTransaction();
    try {
        // Update stock
        $db->query(
            "UPDATE dc_ingredients SET current_stock = current_stock + ?, cost_per_unit = ?
             WHERE ingredient_id = ?",
            [$quantity, $costPerUnit > 0 ? $costPerUnit : $ingredient['cost_per_unit'], $ingredientId]
        );

        // Record movement
        $db->query(
            "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type,
                    reference_type, notes, created_by)
             VALUES (?, ?, 'purchase', 'supplier', ?, ?)",
            [$ingredientId, $quantity, $notes ?: 'Stock received', (int) $ctx->user()['user_id']]
        );

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dcJsonError('Failed to receive stock: ' . $e->getMessage(), 500);
    }

    dc_auditLog('stock.received', 'dc_ingredients', (string) $ingredientId, null, [
        'quantity' => $quantity, 'supplier_id' => $supplierId,
    ]);

    dcJsonResponse(['ok' => true]);
}

/**
 * POST /dc-cafe/api/v1/inventory/receive/batch — Receive multiple items at once
 *
 * Input: { items: [{ ingredient_id, quantity, cost_per_unit?, notes? }] }
 */
function apiReceiveStockBatch(array $params = []): void
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
            $ingredientId = (int) ($item['ingredient_id'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 0);
            $costPerUnit = (float) ($item['cost_per_unit'] ?? 0);
            $notes = (string) ($item['notes'] ?? '');

            if ($ingredientId <= 0 || $quantity <= 0) {
                $errors[] = "Invalid ingredient_id or quantity for item #" . ($processed + 1);
                continue;
            }

            $ingredient = $db->query(
                "SELECT * FROM dc_ingredients WHERE ingredient_id = ?", [$ingredientId]
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$ingredient) {
                $errors[] = "Ingredient ID $ingredientId not found";
                continue;
            }

            $db->query(
                "UPDATE dc_ingredients SET current_stock = current_stock + ?, cost_per_unit = ?
                 WHERE ingredient_id = ?",
                [$quantity, $costPerUnit > 0 ? $costPerUnit : $ingredient['cost_per_unit'], $ingredientId]
            );

            $db->query(
                "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type,
                        reference_type, notes, created_by)
                 VALUES (?, ?, 'purchase', 'supplier', ?, ?)",
                [$ingredientId, $quantity, $notes ?: 'Batch stock received', $userId]
            );

            $processed++;
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dcJsonError('Failed to process batch: ' . $e->getMessage(), 500);
    }

    dcJsonResponse([
        'ok' => true,
        'processed' => $processed,
        'errors' => $errors,
        'message' => $processed . ' item(s) received.' . ($errors ? ' ' . count($errors) . ' error(s).' : ''),
    ]);
}

/**
 * POST /dc-cafe/api/v1/inventory/adjust — Manual stock adjustment
 *
 * Input: { ingredient_id, quantity_change (positive=add, negative=remove), reason }
 */
function apiAdjustStock(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $ingredientId = (int) (dcInput('ingredient_id') ?? 0);
    $quantityChange = (float) (dcInput('quantity_change') ?? 0);
    $reason = (string) (dcInput('reason') ?? '');

    if ($ingredientId <= 0 || $quantityChange == 0) {
        dcJsonError('Valid ingredient_id and non-zero quantity_change are required');
    }

    $db = dcDb();
    $ingredient = $db->query("SELECT * FROM dc_ingredients WHERE ingredient_id = ?", [$ingredientId])->fetch();
    if (!$ingredient) {
        dcJsonError('Ingredient not found', 404);
    }

    // Prevent negative stock
    $newStock = (float) $ingredient['current_stock'] + $quantityChange;
    if ($newStock < 0) {
        dcJsonError('Adjustment would result in negative stock. Current stock: ' . (float) $ingredient['current_stock']);
    }

    $movementType = $quantityChange > 0 ? 'adjustment' : 'waste';

    $db->beginTransaction();
    try {
        $db->query(
            "UPDATE dc_ingredients SET current_stock = ? WHERE ingredient_id = ?",
            [$newStock, $ingredientId]
        );
        $db->query(
            "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type, notes, created_by)
             VALUES (?, ?, ?, ?, ?)",
            [$ingredientId, $quantityChange, $movementType, $reason ?: 'Manual adjustment', (int) $ctx->user()['user_id']]
        );
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dcJsonError('Failed to adjust stock: ' . $e->getMessage(), 500);
    }

    dc_auditLog('stock.adjusted', 'dc_ingredients', (string) $ingredientId, [
        'old_stock' => $ingredient['current_stock'],
    ], [
        'new_stock' => $newStock, 'change' => $quantityChange, 'reason' => $reason,
    ]);

    dcJsonResponse(['ok' => true, 'new_stock' => $newStock]);
}

/**
 * POST /dc-cafe/api/v1/inventory/progress — Save/update inventory progress for a session
 *
 * Input: { session_id, items: [{product_id, beginning_qty?, production_qty?, pullout_qty?, ending_qty?, sold_qty?, notes?}] }
 */
function apiSaveInventoryProgress(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $sessionId = (int) (dcInput('session_id') ?? 0);
    $items = (array) (dcInput('items') ?? []);

    if ($sessionId <= 0 || empty($items)) {
        dcJsonError('session_id and items are required');
    }

    $db = dcDb();
    _dcLoadInventorySession($sessionId, true);

    $db->beginTransaction();
    try {
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) continue;

            $beginning = (float) ($item['beginning_qty'] ?? 0);
            $production = (float) ($item['production_qty'] ?? 0);
            $pullout = (float) ($item['pullout_qty'] ?? 0);
            $notes = (string) ($item['notes'] ?? '');

            // Upsert: insert or update
            $existing = $db->query(
                "SELECT progress_id FROM dc_inventory_progress WHERE session_id = ? AND product_id = ?",
                [$sessionId, $productId]
            )->fetch();

            if ($existing) {
                $db->query(
                    "UPDATE dc_inventory_progress SET beginning_qty = ?, production_qty = ?,
                     pullout_qty = ?, ending_qty = ?, sold_qty = ?, notes = ?, updated_at = NOW()
                     WHERE progress_id = ?",
                    [$beginning, $production, $pullout, 0, 0, $notes ?: null, (int) $existing['progress_id']]
                );
            } else {
                $db->query(
                    "INSERT INTO dc_inventory_progress (session_id, product_id, beginning_qty, production_qty,
                     pullout_qty, ending_qty, sold_qty, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$sessionId, $productId, $beginning, $production, $pullout, 0, 0, $notes ?: null]
                );
            }
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dcJsonError('Failed to save inventory progress: ' . $e->getMessage(), 500);
    }

    dcJsonResponse(['ok' => true]);
}

/**
 * GET /dc-cafe/api/v1/inventory/progress/{session_id} — Load saved inventory progress
 */
function apiGetInventoryProgress(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $sessionId = (int) ($params['session_id'] ?? 0);
    if ($sessionId <= 0) {
        dcJsonError('Invalid session ID');
    }
    _dcLoadInventorySession($sessionId);

    $items = dcDb()->query(
        "SELECT ip.*, p.name AS product_name
         FROM dc_inventory_progress ip
         JOIN dc_products p ON p.product_id = ip.product_id
         WHERE ip.session_id = ?",
        [$sessionId]
    )->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse(['ok' => true, 'items' => $items]);
}

// ─── Supplier Handlers ──────────────────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/suppliers — List all suppliers
 */
function apiListSuppliers(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor');

    $suppliers = dcDb()->query(
        "SELECT * FROM dc_suppliers WHERE is_active = 1 ORDER BY name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse(['ok' => true, 'suppliers' => $suppliers]);
}

/**
 * GET /dc-cafe/api/v1/suppliers/{id} — Get single supplier
 */
function apiGetSupplier(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor');

    $id = (int) ($params['id'] ?? 0);
    $supplier = dcDb()->query("SELECT * FROM dc_suppliers WHERE supplier_id = ?", [$id])->fetch(\PDO::FETCH_ASSOC);
    if (!$supplier) { dcJsonError('Supplier not found', 404); }
    dcJsonResponse(['ok' => true, 'supplier' => $supplier]);
}

/**
 * POST /dc-cafe/api/v1/suppliers — Create supplier
 * PUT  /dc-cafe/api/v1/suppliers/{id} — Update supplier
 */
function apiCreateSupplier(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');
    $name = (string) (dcInput('name') ?? '');
    if ($name === '') { dcJsonError('Supplier name is required'); }
    dcDb()->query("INSERT INTO dc_suppliers (name, contact_person, phone, email) VALUES (?, ?, ?, ?)",
        [$name, dcInput('contact_person') ?: null, dcInput('phone') ?: null, dcInput('email') ?: null]);
    dcJsonResponse(['ok' => true, 'supplier_id' => (int) dcDb()->lastInsertId()]);
}

function apiUpdateSupplier(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');
    $id = (int) ($params['id'] ?? 0);
    dcDb()->query(
        "UPDATE dc_suppliers SET name = ?, contact_person = ?, phone = ?, email = ? WHERE supplier_id = ?",
        [dcInput('name') ?? '', dcInput('contact_person') ?: null, dcInput('phone') ?: null, dcInput('email') ?: null, $id]
    );
    dcJsonResponse(['ok' => true]);
}

/**
 * GET /dc-cafe/api/v1/ingredients — List all ingredients (with supplier name)
 */
function apiListIngredients(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor');

    $rows = dcDb()->query(
        "SELECT i.*, s.name AS supplier_name
         FROM dc_ingredients i
         LEFT JOIN dc_suppliers s ON s.supplier_id = i.supplier_id
         WHERE i.is_active = 1
         ORDER BY i.name"
    )->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse(['ok' => true, 'ingredients' => $rows]);
}

function apiGetIngredient(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor');
    $id = (int) ($params['id'] ?? 0);
    $row = dcDb()->query("SELECT * FROM dc_ingredients WHERE ingredient_id = ?", [$id])->fetch(\PDO::FETCH_ASSOC);
    if (!$row) { dcJsonError('Ingredient not found', 404); }
    dcJsonResponse(['ok' => true, 'ingredient' => $row]);
}

function apiCreateIngredient(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');
    $name = (string) (dcInput('name') ?? '');
    $unit = (string) (dcInput('unit') ?? '');
    if ($name === '' || $unit === '') { dcJsonError('Name and unit are required'); }
    dcDb()->query(
        "INSERT INTO dc_ingredients (name, unit, cost_per_unit, reorder_level, supplier_id)
         VALUES (?, ?, ?, ?, ?)",
        [$name, $unit, (float) (dcInput('cost_per_unit') ?? 0), (float) (dcInput('reorder_level') ?? 0),
         dcInput('supplier_id') ? (int) dcInput('supplier_id') : null]
    );
    dcJsonResponse(['ok' => true, 'ingredient_id' => (int) dcDb()->lastInsertId()]);
}

function apiUpdateIngredient(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');
    $id = (int) ($params['id'] ?? 0);
    dcDb()->query(
        "UPDATE dc_ingredients SET name = ?, unit = ?, cost_per_unit = ?, reorder_level = ?, supplier_id = ?
         WHERE ingredient_id = ?",
        [dcInput('name') ?? '', dcInput('unit') ?? '', (float) (dcInput('cost_per_unit') ?? 0),
         (float) (dcInput('reorder_level') ?? 0), dcInput('supplier_id') ? (int) dcInput('supplier_id') : null, $id]
    );
    dcJsonResponse(['ok' => true]);
}

/**
 * PATCH /dc-cafe/api/v1/inventory/stock/{id} — Inline update stock/reorder
 *
 * Input: { current_stock?, reorder_level? }
 * Only updates fields that are provided.
 */
function apiUpdateInventoryStock(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

    $id = (int) ($params['id'] ?? 0);
    if ($id <= 0) {
        dcJsonError('Invalid ingredient ID', 400);
    }

    $currentStock = dcInput('current_stock');
    $reorderLevel = dcInput('reorder_level');
    $hasStock = $currentStock !== null;
    $hasReorder = $reorderLevel !== null;

    if (!$hasStock && !$hasReorder) {
        dcJsonError('Nothing to update', 400);
    }

    $db = dcDb();
    $ingredient = $db->query(
        "SELECT ingredient_id, current_stock FROM dc_ingredients WHERE ingredient_id = ?",
        [$id]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$ingredient) {
        dcJsonError('Ingredient not found', 404);
    }

    $oldStock = (float) $ingredient['current_stock'];
    $sets = [];
    $vals = [];

    if ($hasStock) {
        $newStock = (float) $currentStock;
        if ($newStock < 0) {
            dcJsonError('Stock cannot be negative', 422);
        }
        $sets[] = 'current_stock = ?';
        $vals[] = $newStock;

        $diff = $newStock - $oldStock;
        if ((int) $diff !== 0) {
            $movementType = $diff > 0 ? 'adjustment' : 'waste';
        }
    }

    if ($hasReorder) {
        $sets[] = 'reorder_level = ?';
        $vals[] = (float) $reorderLevel;
    }

    $vals[] = $id;
    $db->query("UPDATE dc_ingredients SET " . implode(', ', $sets) . " WHERE ingredient_id = ?", $vals);

    if (isset($movementType)) {
        $db->query(
            "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type, notes, created_by)
             VALUES (?, ?, ?, 'Inline edit', ?)",
            [$id, $diff, $movementType, (int) $ctx->user()['user_id']]
        );
    }

    dcJsonResponse(['ok' => true]);
}
