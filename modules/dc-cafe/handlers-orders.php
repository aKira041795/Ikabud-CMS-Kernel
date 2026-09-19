<?php
/**
 * DC Cafe — Order Lifecycle Handlers
 *
 * Create, void, list, export orders. Stock deduction on completion.
 * Server-authoritative pricing, session/store verification, atomic posting.
 */

declare(strict_types=1);

/**
 * Load active products by IDs with their base_price and stock info.
 * Returns map keyed by product_id.
 */
function _loadProductsById(array $ids): array
{
    if (empty($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = dcDb()->query(
        "SELECT product_id, name, base_price, has_stock, current_stock,
                reorder_level, is_active, store_id
         FROM dc_products WHERE product_id IN ($placeholders)",
        $ids
    )->fetchAll(\PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['product_id']] = $r;
    }
    return $map;
}

/**
 * Load active products by IDs for a branch sale, including branch stock.
 */
function _loadBranchProductsById(array $ids, int $storeId, int $catalogStoreId): array
{
    if (empty($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = [$storeId, $catalogStoreId];
    foreach ($ids as $id) {
        $params[] = (int) $id;
    }
    $rows = dcDb()->query(
        "SELECT p.product_id, p.name, p.base_price, p.has_stock,
                p.reorder_level, p.is_active, p.store_id,
                p.slot_count, p.component_category_id,
                p.component_min_price, p.component_max_price,
                COALESCE(pss.on_hand_qty, 0) AS branch_stock
         FROM dc_products p
         LEFT JOIN dc_product_store_stock pss
               ON pss.product_id = p.product_id AND pss.store_id = ?
         WHERE p.store_id = ?
           AND p.product_id IN ($placeholders)",
        $params
    )->fetchAll(\PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['product_id']] = $row;
    }
    return $map;
}

/**
 * Load active addon definitions by addon_id.
 */
function _loadActiveAddonsById(array $ids): array
{
    if (empty($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = dcDb()->query(
        "SELECT addon_id, name, price, type
         FROM dc_soft_serve_addons
         WHERE addon_id IN ($placeholders) AND is_active = 1",
        $ids
    )->fetchAll(\PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['addon_id']] = $row;
    }
    return $map;
}

/**
 * Normalize and validate customization payload structure.
 */
function _normalizeOrderCustomizations(mixed $customizations, int $itemIndex): ?array
{
    if ($customizations === null || $customizations === '') {
        return null;
    }
    if (!is_array($customizations)) {
        dcJsonError("Item #{$itemIndex}: customizations must be an object");
    }
    if (isset($customizations['addons']) && !is_array($customizations['addons'])) {
        dcJsonError("Item #{$itemIndex}: addons must be a list");
    }
    return $customizations;
}

/**
 * Deduct branch stock for a product.
 *
 * Strict mode keeps the guarded UPDATE so a concurrent sale cannot oversell.
 * Permissive modes let the balance go negative — the honest representation when
 * a delivery is on the rack but has not been entered yet — and create the row if
 * the branch has never stocked the item.
 */
function _deductBranchProductStock(
    \Ikabud\Kernel\Contracts\ModuleDB $db,
    int $productId,
    int $storeId,
    float $qty,
    bool $strict
): void {
    if ($strict) {
        $stmt = $db->query(
            "UPDATE dc_product_store_stock SET on_hand_qty = on_hand_qty - ?,
                    version = version + 1
             WHERE product_id = ? AND store_id = ? AND on_hand_qty >= ?",
            [$qty, $productId, $storeId, $qty]
        );
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException(
                "Failed to deduct branch stock for product ID {$productId} at store {$storeId}"
            );
        }
        return;
    }

    $db->query(
        "INSERT INTO dc_product_store_stock (product_id, store_id, on_hand_qty, reorder_level, version)
         VALUES (?, ?, ?, 0, 1)
         ON DUPLICATE KEY UPDATE on_hand_qty = on_hand_qty + ?, version = version + 1",
        [$productId, $storeId, -$qty, -$qty]
    );
}

/**
 * Deduct ingredient stock. Ingredients are tracked globally, not per branch.
 */
function _deductIngredientStock(
    \Ikabud\Kernel\Contracts\ModuleDB $db,
    int $ingredientId,
    float $qty,
    bool $strict
): void {
    if ($strict) {
        $stmt = $db->query(
            "UPDATE dc_ingredients SET current_stock = current_stock - ?
             WHERE ingredient_id = ? AND current_stock >= ?",
            [$qty, $ingredientId, $qty]
        );
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException("Failed to deduct ingredient ID $ingredientId");
        }
        return;
    }

    $db->query(
        "UPDATE dc_ingredients SET current_stock = current_stock - ? WHERE ingredient_id = ?",
        [$qty, $ingredientId]
    );
}

/**
 * POST /dc-cafe/api/v1/orders — Create a new order (server-authoritative)
 *
 * Server loads product base_price, verifies session/store ownership,
 * validates payment method, pre-checks ALL stock, computes totals server-side.
 * One atomic transaction: order → items → stock deductions → movements.
 *
 * Input: { session_id, items: [{product_id, quantity, customizations?, notes?}],
 *         payment_method_id, amount_tendered?, discount_amount?, discount_reason?,
 *         reference_id?, customer_id?, customer? (new customer: {name, phone}) }
 *
 * reference_id is the optional payment reference (e.g. a GCash reference number).
 * It is never required — cashiers may not always have it to hand.
 */
function apiCreateOrder(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $user = $ctx->user();
    $sessionId = (int) (dcInput('session_id') ?? 0);
    $paymentMethodId = (int) (dcInput('payment_method_id') ?? 0);
    $amountTendered = (float) (dcInput('amount_tendered') ?? 0);
    $discountAmount = (float) (dcInput('discount_amount') ?? 0);
    $discountReason = (string) (dcInput('discount_reason') ?? '');
    $referenceId = trim((string) (dcInput('reference_id') ?? ''));
    $items = (array) (dcInput('items') ?? []);
    $customerId = (int) (dcInput('customer_id') ?? 0);
    $customerData = dcInput('customer');

    // Order queue. `park` holds the sale for later; `order_id` finalizes a parked
    // one. Both are absent on an ordinary sale, so the cashier's common path runs
    // exactly as it did before.
    $parkOrder = (bool) dcInput('park');
    $resumeOrderId = (int) (dcInput('order_id') ?? 0);

    if (empty($items) || $sessionId <= 0) {
        dcJsonError('Order must contain at least one item and a valid session');
    }

    $db = dcDb();

    // ── Step 1: Verify session belongs to this cashier's store ──
    $session = $db->query(
        "SELECT s.*, st.name AS store_name
         FROM dc_sessions s
         JOIN dc_stores st ON st.store_id = s.store_id
         WHERE s.session_id = ? AND s.status = 'active'",
        [$sessionId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$session) {
        dcJsonError('Active session not found');
    }
    if ((int) $session['user_id'] !== (int) $user['user_id']) {
        dcJsonError('Session belongs to another cashier');
    }
    $storeId = (int) $session['store_id'];
    $catalogStoreId = dcCatalogStoreId($storeId);

    // ── Step 1b: Order queue — the branch policy and the order being finalized ──
    // Parking and finalizing sit behind the same switch, so one gate covers both.
    if (($parkOrder || $resumeOrderId > 0) && !dcOrderQueueEnabled()) {
        dcJsonError('The order queue is not enabled for this branch', 403);
    }

    $parkedOrder = null;
    if ($resumeOrderId > 0) {
        // Scoped to the branch rather than to the cashier: the queue belongs to
        // the till, so a parked sale is not stranded when a shift changes.
        $parkedOrder = $db->query(
            "SELECT * FROM dc_orders WHERE order_id = ? AND store_id = ? AND status = 'pending'",
            [$resumeOrderId, $storeId]
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$parkedOrder) {
            dcJsonError('That parked order is no longer in the queue');
        }
    }

    // ── Step 2: Validate payment method ──
    // A parked order has not been paid for, so there is nothing to validate and
    // no method to record yet. It is filled in when the order is finalized.
    $pm = null;
    if (!$parkOrder) {
        $pm = $db->query(
            "SELECT * FROM dc_payment_methods WHERE payment_method_id = ? AND is_active = 1",
            [$paymentMethodId]
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$pm) {
            dcJsonError('Invalid or inactive payment method');
        }

        // Branch policy: a reference number may be mandatory for digital tender.
        // Enforced here, not just in the UI, so it cannot be skipped by a direct call.
        if (strtolower((string) ($pm['code'] ?? '')) === 'gcash'
            && dcSettingBool('pos_require_gcash_reference')
            && $referenceId === ''
        ) {
            dcJsonError('A GCash reference number is required for this branch');
        }
    }

    // ── Step 3: Load products from DB (server-authoritative) ──
    $productIds = array_unique(array_map(fn($i) => (int) ($i['product_id'] ?? 0), $items));
    $productIds = array_filter($productIds, fn($id) => $id > 0);
    if (empty($productIds)) {
        dcJsonError('No valid product IDs in items');
    }
    $products = _loadBranchProductsById(array_values($productIds), $storeId, $catalogStoreId);

    // ── Step 4: Pre-validate all items & compute totals ──
    $subtotal = 0.0;
    $orderItems = [];
    $stockDeductions = []; // ingredient_id => total_quantity
    $componentDeductions = []; // product_id => box component consumption
    // Behaviour when stock is short is a tenant policy, not a hard rule.
    $stockPolicyBlocks = dcStockPolicyBlocksSale();
    $shortfalls = [];

    foreach ($items as $i => $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['quantity'] ?? 1);
        if ($qty <= 0) {
            dcJsonError("Item #" . ($i + 1) . ": quantity must be positive");
        }
        if (!isset($products[$productId])) {
            dcJsonError("Item #" . ($i + 1) . ": product ID $productId not found or inactive");
        }
        $prod = $products[$productId];
        $customizations = _normalizeOrderCustomizations($item['customizations'] ?? null, $i + 1);
        $addonTotal = 0.0;
        $canonicalAddons = [];

        // Pre-check product stock for finished goods. Under `strict` a shortfall
        // blocks the sale; under `warn` / `allow_negative` the sale proceeds and
        // the shortfall is recorded for follow-up.
        if ((int) $prod['has_stock'] === 1) {
            $available = (float) $prod['branch_stock'];
            if ($available < $qty) {
                if ($stockPolicyBlocks) {
                    dcJsonError("Insufficient stock for {$prod['name']}: have $available, need $qty");
                }
                $shortfalls[] = [
                    'type' => 'product',
                    'name' => (string) $prod['name'],
                    'available' => $available,
                    'needed' => (float) $qty,
                ];
            }
        }

        // Pre-check BOM ingredient availability
        $bom = $db->query(
            "SELECT ingredient_id, quantity FROM dc_product_ingredients WHERE product_id = ?",
            [$productId]
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($bom as $bomItem) {
            $ingId = (int) $bomItem['ingredient_id'];
            $needed = (float) $bomItem['quantity'] * $qty;
            $stockDeductions[$ingId] = ($stockDeductions[$ingId] ?? 0) + $needed;
        }

        // Pre-check addon ingredient availability and compute canonical addon price.
        if (is_array($customizations) && !empty($customizations['addons'])) {
            $addonIds = [];
            foreach ($customizations['addons'] as $addon) {
                $addonId = (int) ($addon['id'] ?? $addon['addon_id'] ?? 0);
                if ($addonId <= 0) {
                    dcJsonError("Item #" . ($i + 1) . ": addon selection is invalid");
                }
                if (isset($addonIds[$addonId])) {
                    dcJsonError("Item #" . ($i + 1) . ": duplicate addon selected");
                }
                $addonIds[$addonId] = true;
            }

            $addonMap = _loadActiveAddonsById(array_keys($addonIds));
            if (count($addonMap) !== count($addonIds)) {
                dcJsonError("Item #" . ($i + 1) . ": one or more addons are inactive or missing");
            }

            foreach ($customizations['addons'] as $addon) {
                $addonId = (int) ($addon['id'] ?? $addon['addon_id'] ?? 0);
                $addonRow = $addonMap[$addonId];
                $addonName = (string) ($addon['name'] ?? '');
                if ($addonName !== '' && $addonName !== (string) $addonRow['name']) {
                    dcJsonError("Item #" . ($i + 1) . ": addon payload does not match catalog");
                }
                $addonTotal += (float) $addonRow['price'];
                $canonicalAddons[] = [
                    'id' => $addonId,
                    'name' => $addonRow['name'],
                    'price' => (float) $addonRow['price'],
                    'type' => $addonRow['type'],
                ];
                $addonIngredients = $db->query(
                    "SELECT ingredient_id, quantity
                     FROM dc_addon_ingredients
                     WHERE addon_id = ?",
                    [$addonId]
                )->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($addonIngredients as $ai) {
                    $ingId = (int) $ai['ingredient_id'];
                    $needed = (float) $ai['quantity'] * $qty;
                    $stockDeductions[$ingId] = ($stockDeductions[$ingId] ?? 0) + $needed;
                }
            }

            $customizations['addons'] = $canonicalAddons;
        }

        // Box/bundle: the cashier's slot selection drives component stock.
        // The box price is fixed, so components contribute no revenue — they
        // leave the branch as bundle consumption (pullout), not as direct sales.
        if (dcBoxIsContainer($prod)) {
            $boxSelection = $customizations['box']['components'] ?? null;
            if (!is_array($boxSelection)) {
                dcJsonError(
                    "Item #" . ($i + 1) . ": {$prod['name']} requires " .
                    (int) $prod['slot_count'] . ' item(s) to be selected'
                );
            }
            $boxDefinition = dcBoxDefinition($prod, $storeId);
            $boxComponents = dcBoxResolveSelection(
                $prod,
                $boxDefinition,
                $boxSelection,
                $boxDefinition['standard_set']
            );

            foreach ($boxComponents as $component) {
                $componentId = (int) $component['product_id'];
                if (!isset($componentDeductions[$componentId])) {
                    $componentDeductions[$componentId] = [
                        'product_id'     => $componentId,
                        'name'           => $component['name'],
                        'qty'            => 0.0,
                        'box_product_id' => (int) $prod['product_id'],
                        'box_name'       => (string) $prod['name'],
                    ];
                }
                $componentDeductions[$componentId]['qty'] += $component['quantity'] * $qty;
            }

            $customizations['box'] = [
                'box_product_id' => (int) $prod['product_id'],
                'box_name'       => (string) $prod['name'],
                'slot_count'     => (int) $prod['slot_count'],
                'components'     => $boxComponents,
            ];
        }

        $unitPrice = (float) $prod['base_price'] + $addonTotal;
        $clientUnitPrice = array_key_exists('unit_price', $item) ? (float) $item['unit_price'] : null;
        if ($clientUnitPrice !== null && abs($clientUnitPrice - $unitPrice) > 0.009) {
            dcJsonError("Item #" . ($i + 1) . ": price changed, refresh the cart");
        }
        $totalPrice = $unitPrice * $qty;
        $subtotal += $totalPrice;

        $orderItems[] = [
            'product_id' => $productId,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'customizations' => $customizations ? json_encode($customizations) : null,
            'notes' => (string) ($item['notes'] ?? ''),
            'has_stock' => (int) $prod['has_stock'],
        ];
    }

    // ── Step 5: Verify ingredient stock ──
    if (!empty($stockDeductions)) {
        $ingIds = array_keys($stockDeductions);
        $placeholders = implode(',', array_fill(0, count($ingIds), '?'));
        $ingredients = $db->query(
            "SELECT ingredient_id, name, current_stock FROM dc_ingredients WHERE ingredient_id IN ($placeholders)",
            $ingIds
        )->fetchAll(\PDO::FETCH_ASSOC);
        $ingMap = [];
        foreach ($ingredients as $ing) {
            $ingMap[(int) $ing['ingredient_id']] = $ing;
        }
        foreach ($stockDeductions as $ingId => $needed) {
            if (!isset($ingMap[$ingId])) {
                dcJsonError("Ingredient ID $ingId not found");
            }
            $available = (float) $ingMap[$ingId]['current_stock'];
            if ($available < $needed) {
                if ($stockPolicyBlocks) {
                    dcJsonError("Insufficient {$ingMap[$ingId]['name']}: have $available, need $needed");
                }
                $shortfalls[] = [
                    'type' => 'ingredient',
                    'name' => (string) $ingMap[$ingId]['name'],
                    'available' => $available,
                    'needed' => $needed,
                ];
            }
        }
    }

    // ── Step 5b: Verify box component stock in this branch ──
    if (!empty($componentDeductions)) {
        $componentProducts = _loadBranchProductsById(
            array_keys($componentDeductions),
            $storeId,
            $catalogStoreId
        );
        foreach ($componentDeductions as $componentId => $pending) {
            $row = $componentProducts[$componentId] ?? null;
            if ($row === null) {
                dcJsonError(
                    "Box item '{$pending['name']}' is not available in this branch catalog"
                );
            }
            $available = (float) $row['branch_stock'];
            if ($available < $pending['qty']) {
                if ($stockPolicyBlocks) {
                    dcJsonError(
                        "Insufficient stock for {$pending['name']}: have $available, need {$pending['qty']}"
                    );
                }
                $shortfalls[] = [
                    'type' => 'box_component',
                    'name' => (string) $pending['name'],
                    'available' => $available,
                    'needed' => (float) $pending['qty'],
                ];
            }
        }
    }

    // ── Step 6: Handle customer ──
    if ($discountAmount < 0) {
        dcJsonError('Discount amount cannot be negative');
    }
    if ($discountAmount > $subtotal) {
        dcJsonError('Discount amount cannot exceed subtotal');
    }
    // Branch policy: discounts can be switched off entirely.
    if ($discountAmount > 0 && !dcSettingBool('pos_allow_discount')) {
        dcJsonError('Discounts are not enabled for this branch');
    }
    // Branch policy: an administrator caps what may be given away on one sale.
    // Enforced here so a crafted request cannot exceed the till's own limit.
    $ceilingError = dcDiscountCeilingError((float) $subtotal, (float) $discountAmount);
    if ($ceilingError !== null) {
        dcJsonError($ceilingError);
    }
    // dc_orders.reference_id is VARCHAR(50).
    if (mb_strlen($referenceId) > 50) {
        dcJsonError('Reference number cannot exceed 50 characters');
    }

    if ($customerId <= 0 && $customerData !== null) {
        $name = (string) ($customerData['name'] ?? '');
        $phone = (string) ($customerData['phone'] ?? '');
        if ($name !== '' && $phone !== '') {
            $existing = $db->query("SELECT customer_id FROM dc_customers WHERE phone = ?", [$phone])->fetch();
            if ($existing) {
                $customerId = (int) $existing['customer_id'];
            } else {
                $db->query("INSERT INTO dc_customers (name, phone) VALUES (?, ?)", [$name, $phone]);
                $customerId = (int) $db->lastInsertId();
            }
        }
    }

    // ── Step 7: Atomic transaction — create order, deduct stock ──
    $originalAmount = $subtotal;
    $totalAmount = max(0, $subtotal - $discountAmount);
    $userId = (int) $user['user_id'];

    $db->beginTransaction();
    try {
        // Create order
        $changeAmount = $amountTendered > 0 ? max(0, $amountTendered - $totalAmount) : 0;

        if ($parkOrder) {
            // A parked order is a saved cart, not a sale: no payment method, no
            // tender, and a status every report already excludes.
            $db->query(
                "INSERT INTO dc_orders (session_id, store_id, cashier_id, customer_id,
                        total_amount, original_amount, discount_amount, discount_reason,
                        transaction_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')",
                [$sessionId, $storeId, $userId, $customerId ?: null,
                 $totalAmount, $originalAmount, $discountAmount, $discountReason ?: null]
            );
            $orderId = (int) $db->lastInsertId();
        } elseif ($parkedOrder !== null) {
            // Finalizing a parked order completes that same row, so one order id
            // carries the sale from park to payment. The alternative — discarding
            // the parked record and creating an unrelated one — would leave the
            // trail with no way to tell the two are the same customer.
            //
            // transaction_date is stamped now rather than at park time, so a sale
            // held across midnight lands on the day it was paid for. The session
            // and cashier move to whoever is taking the money, because that is
            // whose drawer it goes into.
            $orderId = $resumeOrderId;
            $db->query(
                "UPDATE dc_orders
                    SET session_id = ?, store_id = ?, cashier_id = ?, customer_id = ?,
                        total_amount = ?, original_amount = ?, discount_amount = ?,
                        discount_reason = ?, payment_method_id = ?, amount_tendered = ?,
                        change_amount = ?, reference_id = ?, transaction_date = NOW(),
                        status = 'completed'
                  WHERE order_id = ? AND status = 'pending'",
                [$sessionId, $storeId, $userId, $customerId ?: null,
                 $totalAmount, $originalAmount, $discountAmount, $discountReason ?: null,
                 $paymentMethodId, $amountTendered ?: null, $changeAmount, $referenceId ?: null,
                 $orderId]
            );
            // The parked lines are replaced by what is being paid for now, so a
            // cart edited after reinstating cannot leave stale rows behind.
            $db->query("DELETE FROM dc_order_items WHERE order_id = ?", [$orderId]);
        } else {
            $db->query(
                "INSERT INTO dc_orders (session_id, store_id, cashier_id, customer_id,
                        total_amount, original_amount, discount_amount, discount_reason,
                        payment_method_id, amount_tendered, change_amount, reference_id, transaction_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'completed')",
                [$sessionId, $storeId, $userId, $customerId ?: null,
                 $totalAmount, $originalAmount, $discountAmount, $discountReason ?: null,
                 $paymentMethodId, $amountTendered ?: null, $changeAmount, $referenceId ?: null]
            );
            $orderId = (int) $db->lastInsertId();
        }

        // Create order items
        foreach ($orderItems as $oi) {
            $db->query(
                "INSERT INTO dc_order_items (order_id, product_id, quantity, unit_price, total_price, customizations, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$orderId, $oi['product_id'], $oi['quantity'], $oi['unit_price'],
                 $oi['total_price'], $oi['customizations'], $oi['notes'] ?: null]
            );
        }

        // Stock only moves when the sale actually happens. A parked order takes no
        // stock, so none of these run for it and a queue cannot consume inventory
        // before the customer pays. Availability was already checked above, so the
        // cashier still finds out now rather than in front of the customer later.
        if (!$parkOrder) {
            // Deduct product stock (finished goods) — branch-aware via dc_product_store_stock
            foreach ($orderItems as $oi) {
                if ($oi['has_stock'] !== 1) continue;
                _deductBranchProductStock($db, (int) $oi['product_id'], $storeId, (float) $oi['quantity'], $stockPolicyBlocks);
                // Record branch-aware product stock movement
                $db->query(
                    "INSERT INTO dc_product_stock_movements (product_id, store_id, quantity_change, movement_type,
                            reference_type, reference_id, notes, created_by)
                     VALUES (?, ?, ?, 'sale', 'order', ?, ?, ?)",
                    [$oi['product_id'], $storeId, -$oi['quantity'], $orderId,
                         'Product sale — order #' . $orderId, $userId]
                );
            }

            // Deduct box component stock. Recorded as movement_type 'sale' so the
            // void handler restores it, but tagged consumption_channel 'bundle' so
            // the reconciliation reports it as box pullout rather than a counter sale.
            foreach ($componentDeductions as $componentId => $pending) {
                _deductBranchProductStock($db, (int) $componentId, $storeId, (float) $pending['qty'], $stockPolicyBlocks);
                $db->query(
                    "INSERT INTO dc_product_stock_movements (product_id, store_id, quantity_change, movement_type,
                            consumption_channel, bundle_product_id, reference_type, reference_id, notes, created_by)
                     VALUES (?, ?, ?, 'sale', 'bundle', ?, 'order', ?, ?, ?)",
                    [$componentId, $storeId, -$pending['qty'], $pending['box_product_id'], $orderId,
                     'Box component — ' . $pending['name'] . ' in ' . $pending['box_name'] . ' (order #' . $orderId . ')',
                     $userId]
                );
            }

            // Deduct ingredient stock (BOM + addons)
            foreach ($stockDeductions as $ingId => $deductQty) {
                _deductIngredientStock($db, (int) $ingId, (float) $deductQty, $stockPolicyBlocks);
                $db->query(
                    "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type,
                            reference_type, reference_id, notes, created_by)
                     VALUES (?, ?, 'consumption', 'order', ?, ?, ?)",
                    [$ingId, -$deductQty, $orderId,
                     'Auto-deducted by order #' . $orderId, $userId]
                );
            }
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dc_auditLog('order.create_failed', 'dc_orders', null, ['error' => $e->getMessage()], null);
        dcJsonError('Failed to create order: ' . $e->getMessage(), 500);
    }

    // A parked order is not a sale yet — it is a saved cart waiting for payment.
    // Its own action keeps it out of the "sales recorded" story in the trail.
    if ($parkOrder) {
        dc_auditLog('order.parked', 'dc_orders', (string) $orderId, null, [
            'total' => $totalAmount, 'items' => count($orderItems),
        ]);
        dcJsonResponse([
            'ok' => true,
            'order_id' => $orderId,
            'total' => $totalAmount,
            'parked' => true,
        ]);
    }

    $createdContext = [
        'total' => $totalAmount, 'items' => count($orderItems), 'payment' => $paymentMethodId,
    ];
    if ($parkedOrder !== null) {
        // Say so, so a sale that was held and then paid reads as one continuous
        // transaction rather than appearing out of nowhere.
        $createdContext['finalized_from_parked'] = true;
    }

    dc_auditLog('order.created', 'dc_orders', (string) $orderId, null, $createdContext);

    // Warn mode: the sale completed, but the books did not have the stock. Record
    // what was short so receiving can be caught up instead of silently drifting.
    if ($shortfalls !== [] && dcStockPolicyRecordsShortfall()) {
        dc_auditLog('stock.shortfall', 'dc_orders', (string) $orderId, null, [
            'policy' => dcStockPolicy(),
            'items' => $shortfalls,
        ]);
        write_log('dc-cafe order sold below recorded stock', 'warning', [
            'order_id' => $orderId,
            'store_id' => $storeId,
            'shortfalls' => $shortfalls,
        ]);
    }

    dcJsonResponse(['ok' => true, 'order_id' => $orderId, 'total' => $totalAmount]);
}

/**
 * GET /dc-cafe/api/v1/orders/pending — the branch order queue.
 *
 * A parked sale is waiting for its customer, so this is what the till shows in
 * order to bring one back. Nothing listed here is a sale: no money has been
 * taken and no stock has moved.
 */
function apiListPendingOrders(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    // Reported as disabled rather than as an error: the till asks on load, and a
    // branch without the queue simply has an empty one.
    if (!dcOrderQueueEnabled()) {
        dcJsonResponse(['ok' => true, 'enabled' => false, 'orders' => []]);
    }

    $storeId = (int) (dcInput('store_id') ?? 1);

    $rows = dcDb()->query(
        "SELECT o.order_id, o.total_amount, o.discount_amount, o.created_at,
                u.full_name AS cashier_name,
                (SELECT COUNT(*) FROM dc_order_items oi WHERE oi.order_id = o.order_id) AS item_count
         FROM dc_orders o
         JOIN dc_users u ON u.user_id = o.cashier_id
         WHERE o.store_id = ? AND o.status = 'pending'
         ORDER BY o.order_id DESC
         LIMIT 50",
        [$storeId]
    )->fetchAll(\PDO::FETCH_ASSOC);

    $orders = array_map(static function (array $row): array {
        return [
            'order_id'   => (int) $row['order_id'],
            'total'      => (float) $row['total_amount'],
            'discount'   => (float) $row['discount_amount'],
            'item_count' => (int) $row['item_count'],
            'cashier'    => (string) ($row['cashier_name'] ?? ''),
            'parked_at'  => (string) $row['created_at'],
        ];
    }, $rows);

    dcJsonResponse(['ok' => true, 'enabled' => true, 'orders' => $orders]);
}

/**
 * POST /dc-cafe/api/v1/orders/{id}/discard — drop a parked order.
 *
 * A parked order holds no stock and no money, so discarding one cannot lose a
 * sale. The audit row is what keeps the fact that it existed, so a discarded
 * park stays visible in the trail instead of quietly disappearing.
 */
function apiDiscardPendingOrder(array $params = []): void
{
    $ctx = dcCtx();
    // Open to any cashier by default: a parked order is their own held sale and
    // nothing was sold, so clearing a mis-park needs no approval. A branch that
    // wants a second pair of eyes switches that on in settings.
    if (dcDiscardRequiresSupervisor()) {
        $ctx->requireAnyRole('admin', 'supervisor');
    } else {
        $ctx->requireAnyRole('admin', 'supervisor', 'cashier');
    }

    if (!dcOrderQueueEnabled()) {
        dcJsonError('The order queue is not enabled for this branch', 403);
    }

    $orderId = (int) ($params['id'] ?? 0);
    if ($orderId <= 0) {
        dcJsonError('Invalid order ID');
    }
    $storeId = (int) (dcInput('store_id') ?? 1);

    $db = dcDb();
    // Only ever a parked order: a completed sale is voided, never discarded.
    $order = $db->query(
        "SELECT * FROM dc_orders WHERE order_id = ? AND status = 'pending'",
        [$orderId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$order) {
        dcJsonError('That parked order is no longer in the queue');
    }
    if ((int) $order['store_id'] !== $storeId) {
        dcJsonError('That parked order belongs to another branch', 403);
    }

    $itemCount = (int) $db->query(
        "SELECT COUNT(*) FROM dc_order_items WHERE order_id = ?",
        [$orderId]
    )->fetchColumn();

    $db->beginTransaction();
    try {
        // Items first: fk_dc_order_items_order carries no ON DELETE CASCADE.
        $db->query("DELETE FROM dc_order_items WHERE order_id = ?", [$orderId]);
        $db->query("DELETE FROM dc_orders WHERE order_id = ? AND status = 'pending'", [$orderId]);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dcJsonError('Could not discard the parked order', 500);
    }

    dc_auditLog('order.parked_discarded', 'dc_orders', (string) $orderId, null, [
        'total' => (float) $order['total_amount'],
        'items' => $itemCount,
    ]);

    dcJsonResponse(['ok' => true]);
}

/**
 * POST /dc-cafe/api/v1/orders/{id}/void — Void an order (idempotent)
 *
 * Restores BOM/addon ingredient stock AND finished-product stock.
 * Only completed orders can be voided. Rejects already-voided orders.
 *
 * A cashier may start a void, but it only completes when an administrator or
 * supervisor supplies their own void PIN. The PIN is required regardless of who
 * is signed in, because the till session is shared — requiring it always is what
 * makes the recorded approver meaningful.
 */
function apiVoidOrder(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');
    $userId = (int) $ctx->user()['user_id'];

    $orderId = (int) ($params['id'] ?? 0);
    if ($orderId <= 0) {
        dcJsonError('Invalid order ID');
    }

    $db = dcDb();
    $order = $db->query("SELECT * FROM dc_orders WHERE order_id = ?", [$orderId])->fetch(\PDO::FETCH_ASSOC);
    if (!$order) {
        dcJsonError('Order not found', 404);
    }
    if ($order['status'] !== 'completed') {
        dcJsonError('Only completed orders can be voided, current status: ' . $order['status']);
    }

    $storeId = (int) ($order['store_id'] ?? 0);

    // Branch policy: voiding can be switched off entirely, making a completed
    // sale final. Checked first so no approval work happens for a disabled till.
    if (!dcVoidEnabled()) {
        dcJsonError('Voiding is not enabled for this branch', 403);
    }

    // ── Supervisor approval ──
    // Checked before the order is touched so a failed approval has no effect.
    $throttleError = dcVoidThrottleError($storeId);
    if ($throttleError !== null) {
        dcJsonError($throttleError, 429);
    }

    if (!dcHasVoidApprovers()) {
        dcJsonError(
            'No supervisor void PIN is configured. An administrator must set one in Settings → User Accounts.',
            403
        );
    }

    $approver = dcVerifyVoidPin((string) (dcInput('void_pin') ?? ''));
    if ($approver === null) {
        dcRecordVoidAttempt($storeId, $userId, false);
        write_log('dc_cafe.void.approval_denied', 'warning', [
            'order_id' => $orderId,
            'requested_by' => $userId,
            'failures' => dcVoidFailureCount($storeId),
        ]);
        dcJsonError('Incorrect void PIN', 403);
    }

    $db->beginTransaction();
    try {
        $productMovements = $db->query(
            "SELECT product_id, store_id, quantity_change
             FROM dc_product_stock_movements
             WHERE reference_type = 'order' AND reference_id = ? AND movement_type = 'sale'",
            [$orderId]
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($productMovements as $movement) {
            $restoreQty = -1 * (float) $movement['quantity_change'];
            if ($restoreQty <= 0) {
                continue;
            }
            $productId = (int) $movement['product_id'];
            $movementStoreId = (int) ($movement['store_id'] ?? $order['store_id']);
            $db->query(
                "UPDATE dc_product_store_stock SET on_hand_qty = on_hand_qty + ?,
                        version = version + 1
                 WHERE product_id = ? AND store_id = ?",
                [$restoreQty, $productId, $movementStoreId]
            );
            $db->query(
                "INSERT INTO dc_product_stock_movements (product_id, store_id, quantity_change, movement_type,
                        reference_type, reference_id, notes, created_by)
                 VALUES (?, ?, ?, 'void_restore', 'order', ?, ?, ?)",
                [$productId, $movementStoreId, $restoreQty, $orderId,
                 'Restored recorded sale for order #' . $orderId, $userId]
            );
        }

        $ingredientMovements = $db->query(
            "SELECT ingredient_id, quantity_change
             FROM dc_inventory_movements
             WHERE reference_type = 'order' AND reference_id = ? AND movement_type = 'consumption'",
            [$orderId]
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($ingredientMovements as $movement) {
            $restoreQty = -1 * (float) $movement['quantity_change'];
            if ($restoreQty <= 0) {
                continue;
            }
            $ingredientId = (int) $movement['ingredient_id'];
            $db->query(
                "UPDATE dc_ingredients SET current_stock = current_stock + ? WHERE ingredient_id = ?",
                [$restoreQty, $ingredientId]
            );
            $db->query(
                "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type,
                        reference_type, reference_id, notes, created_by)
                 VALUES (?, ?, 'adjustment', 'order', ?, ?, ?)",
                [$ingredientId, $restoreQty, $orderId, 'Restored recorded consumption for order #' . $orderId, $userId]
            );
        }

        $db->query("UPDATE dc_orders SET status = 'voided' WHERE order_id = ?", [$orderId]);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dcJsonError('Failed to void order: ' . $e->getMessage(), 500);
    }

    dc_auditLog('order.voided', 'dc_orders', (string) $orderId, ['total' => $order['total_amount']], [
        // The point of a personal PIN: the record names the approver.
        'approved_by' => (int) $approver['user_id'],
        'approved_by_username' => (string) $approver['username'],
        'approved_by_role' => (string) $approver['role'],
        'requested_by' => $userId,
    ]);
    dcRecordVoidAttempt($storeId, $userId, true);
    dcClearVoidFailures($storeId);

    dcJsonResponse([
        'ok' => true,
        'approved_by' => (string) ($approver['full_name'] ?: $approver['username']),
        'requested_by' => (string) ($ctx->user()['full_name'] ?? ''),
    ]);
}

// ─── Export Handlers ───────────────────────────────────────────────────

/**
 * GET /dc-cafe/api/v1/orders/export — CSV export of orders
 */
function apiExportOrdersCsv(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor');

    $storeId = (int) (dcInput('store_id') ?? 1);
    $startDate = (string) (dcInput('start_date') ?? date('Y-m-d', strtotime('-30 days')));
    $endDate = (string) (dcInput('end_date') ?? date('Y-m-d'));

    $rows = dcDb()->query(
        "SELECT o.order_id, o.transaction_date, o.total_amount, o.discount_amount,
                o.original_amount, o.status, pm.name AS payment_method,
                u.full_name AS cashier, c.name AS customer
         FROM dc_orders o
         LEFT JOIN dc_payment_methods pm ON pm.payment_method_id = o.payment_method_id
         JOIN dc_users u ON u.user_id = o.cashier_id
         LEFT JOIN dc_customers c ON c.customer_id = o.customer_id
         WHERE o.store_id = ? AND DATE(o.transaction_date) BETWEEN ? AND ?
         ORDER BY o.transaction_date DESC",
        [$storeId, $startDate, $endDate]
    )->fetchAll(\PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dc-cafe-orders-' . $startDate . '-to-' . $endDate . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // BOM for Excel
    fputcsv($out, ['Order ID', 'Date', 'Total', 'Discount', 'Original', 'Status', 'Payment', 'Cashier', 'Customer']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['order_id'], $row['transaction_date'], $row['total_amount'],
            $row['discount_amount'], $row['original_amount'], $row['status'],
            $row['payment_method'], $row['cashier'], $row['customer'],
        ]);
    }
    fclose($out);
    exit;
}

/**
 * GET /dc-cafe/api/v1/sales-report/export — CSV sales report
 */
function apiExportSalesReportCsv(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor');

    $storeId = (int) (dcInput('store_id') ?? 1);
    $startDate = (string) (dcInput('start_date') ?? date('Y-m-d', strtotime('-30 days')));
    $endDate = (string) (dcInput('end_date') ?? date('Y-m-d'));

    $rows = dcDb()->query(
        "SELECT p.name AS product, c.name AS category,
                SUM(oi.quantity) AS qty_sold, SUM(oi.total_price) AS total_revenue,
                COUNT(DISTINCT o.order_id) AS order_count
         FROM dc_order_items oi
         JOIN dc_orders o ON o.order_id = oi.order_id
         JOIN dc_products p ON p.product_id = oi.product_id
         JOIN dc_categories c ON c.category_id = p.category_id
         WHERE o.store_id = ? AND DATE(o.transaction_date) BETWEEN ? AND ? AND o.status = 'completed'
         GROUP BY p.product_id, p.name, c.name
         ORDER BY total_revenue DESC",
        [$storeId, $startDate, $endDate]
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Reversed sales in the same window. Completed orders are the only ones that
    // count toward revenue, so a voided order must not appear among the products
    // above — but leaving it out entirely would hide the fact that it happened.
    $voided = dcDb()->query(
        "SELECT o.order_id, o.transaction_date, o.total_amount, o.discount_amount, o.status,
                o.discount_reason, pm.name AS payment_method,
                u.full_name AS cashier,
                GROUP_CONCAT(CONCAT(oi.quantity, 'x ', p.name) ORDER BY p.name SEPARATOR ', ') AS items
         FROM dc_orders o
         LEFT JOIN dc_payment_methods pm ON pm.payment_method_id = o.payment_method_id
         LEFT JOIN dc_users u ON u.user_id = o.cashier_id
         LEFT JOIN dc_order_items oi ON oi.order_id = o.order_id
         LEFT JOIN dc_products p ON p.product_id = oi.product_id
         WHERE o.store_id = ? AND DATE(o.transaction_date) BETWEEN ? AND ? AND o.status = 'voided'
         GROUP BY o.order_id, o.transaction_date, o.total_amount, o.discount_amount, o.status,
                  o.discount_reason, pm.name, u.full_name
         ORDER BY o.transaction_date DESC",
        [$storeId, $startDate, $endDate]
    )->fetchAll(\PDO::FETCH_ASSOC);

    $completedTotals = dcDb()->query(
        "SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS revenue
         FROM dc_orders
         WHERE store_id = ? AND DATE(transaction_date) BETWEEN ? AND ? AND status = 'completed'",
        [$storeId, $startDate, $endDate]
    )->fetch(\PDO::FETCH_ASSOC) ?: ['orders' => 0, 'revenue' => 0];

    $voidedTotals = dcDb()->query(
        "SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS revenue
         FROM dc_orders
         WHERE store_id = ? AND DATE(transaction_date) BETWEEN ? AND ? AND status = 'voided'",
        [$storeId, $startDate, $endDate]
    )->fetch(\PDO::FETCH_ASSOC) ?: ['orders' => 0, 'revenue' => 0];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dc-cafe-sales-report-' . $startDate . '-to-' . $endDate . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");

    // Summary first, so the headline numbers and the void adjustment are read
    // together rather than inferred from the rows below. Net revenue carries no
    // order count on purpose: a voided order was never revenue, so subtracting
    // counts would produce a number with no meaning.
    fputcsv($out, ['DC Cafe sales report']);
    fputcsv($out, ['Period', $startDate . ' to ' . $endDate]);
    fputcsv($out, []);
    fputcsv($out, ['Summary']);
    fputcsv($out, ['Metric', 'Orders', 'Amount']);
    fputcsv($out, ['Completed orders', $completedTotals['orders'], $completedTotals['revenue']]);
    fputcsv($out, ['Voided orders', $voidedTotals['orders'], $voidedTotals['revenue']]);
    fputcsv($out, ['Net revenue', '', $completedTotals['revenue'] - $voidedTotals['revenue']]);
    fputcsv($out, []);

    fputcsv($out, ['Products sold (completed orders only)']);
    fputcsv($out, ['Product', 'Category', 'Qty Sold', 'Total Revenue', 'Order Count']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['product'], $row['category'], $row['qty_sold'], $row['total_revenue'], $row['order_count']]);
    }

    // Listed in full, and excluded from the totals above on purpose.
    fputcsv($out, []);
    fputcsv($out, ['Voided orders (excluded from the totals above)']);
    fputcsv($out, ['Order', 'Date', 'Items', 'Payment', 'Cashier', 'Discount', 'Amount Reversed', 'Status']);
    foreach ($voided as $v) {
        fputcsv($out, [
            $v['order_id'],
            $v['transaction_date'],
            $v['items'] ?: '',
            $v['payment_method'] ?: '',
            $v['cashier'] ?: '',
            $v['discount_amount'] > 0 ? $v['discount_amount'] . ' (' . ($v['discount_reason'] ?: 'discount') . ')' : '',
            $v['total_amount'],
            $v['status'],
        ]);
    }

    fclose($out);
    exit;
}

/**
 * GET /dc-cafe/api/v1/customers/{id}/orders
 */
function apiGetCustomerOrders(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'auditor', 'cashier');

    $customerId = (int) ($params['id'] ?? 0);
    if ($customerId <= 0) {
        dcJsonError('Invalid customer ID');
    }

    $orders = dcDb()->query(
        "SELECT o.order_id, o.total_amount, o.status, o.transaction_date,
                pm.name AS payment_method
         FROM dc_orders o
         LEFT JOIN dc_payment_methods pm ON pm.payment_method_id = o.payment_method_id
         WHERE o.customer_id = ?
         ORDER BY o.transaction_date DESC LIMIT 50",
        [$customerId]
    )->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse(['ok' => true, 'orders' => $orders]);
}
