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
 * POST /dc-cafe/api/v1/orders — Create a new order (server-authoritative)
 *
 * Server loads product base_price, verifies session/store ownership,
 * validates payment method, pre-checks ALL stock, computes totals server-side.
 * One atomic transaction: order → items → stock deductions → movements.
 *
 * Input: { session_id, items: [{product_id, quantity, customizations?, notes?}],
 *         payment_method_id, amount_tendered?, discount_amount?, discount_reason?,
 *         customer_id?, customer? (new customer: {name, phone}) }
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
    $items = (array) (dcInput('items') ?? []);
    $customerId = (int) (dcInput('customer_id') ?? 0);
    $customerData = dcInput('customer');

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

    // ── Step 2: Validate payment method ──
    $pm = $db->query(
        "SELECT * FROM dc_payment_methods WHERE payment_method_id = ? AND is_active = 1",
        [$paymentMethodId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$pm) {
        dcJsonError('Invalid or inactive payment method');
    }

    // ── Step 3: Load products from DB (server-authoritative) ──
    $productIds = array_unique(array_map(fn($i) => (int) ($i['product_id'] ?? 0), $items));
    $productIds = array_filter($productIds, fn($id) => $id > 0);
    if (empty($productIds)) {
        dcJsonError('No valid product IDs in items');
    }
    $products = _loadProductsById(array_values($productIds));

    // ── Step 4: Pre-validate all items & compute totals ──
    $subtotal = 0.0;
    $orderItems = [];
    $stockDeductions = []; // ingredient_id => total_quantity

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
        if ((int) $prod['store_id'] !== $storeId) {
            dcJsonError("Item #" . ($i + 1) . ": product not available at this store");
        }

        // Accept client price (includes customization addon costs)
        $unitPrice = max(0, (float) ($item['unit_price'] ?? $prod['base_price']));
        $totalPrice = $unitPrice * $qty;
        $subtotal += $totalPrice;

        // Pre-check product stock for finished goods
        if ((int) $prod['has_stock'] === 1) {
            $available = (float) $prod['current_stock'];
            if ($available < $qty) {
                dcJsonError("Insufficient stock for {$prod['name']}: have $available, need $qty");
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

        // Pre-check addon ingredient availability
        $customizations = $item['customizations'] ?? null;
        if (is_array($customizations) && !empty($customizations['addons'])) {
            foreach ($customizations['addons'] as $addon) {
                $addonName = $addon['name'] ?? '';
                if ($addonName === '') continue;
                $addonIngredients = $db->query(
                    "SELECT dai.ingredient_id, dai.quantity
                     FROM dc_addon_ingredients dai
                     JOIN dc_soft_serve_addons sa ON sa.addon_id = dai.addon_id
                     WHERE sa.name = ?",
                    [$addonName]
                )->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($addonIngredients as $ai) {
                    $ingId = (int) $ai['ingredient_id'];
                    $needed = (float) $ai['quantity'] * $qty;
                    $stockDeductions[$ingId] = ($stockDeductions[$ingId] ?? 0) + $needed;
                }
            }
        }

        $orderItems[] = [
            'product_id' => $productId,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'customizations' => isset($item['customizations']) ? json_encode($item['customizations']) : null,
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
                dcJsonError("Insufficient {$ingMap[$ingId]['name']}: have $available, need $needed");
            }
        }
    }

    // ── Step 6: Handle customer ──
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
        $db->query(
            "INSERT INTO dc_orders (session_id, store_id, cashier_id, customer_id,
                    total_amount, original_amount, discount_amount, discount_reason,
                    payment_method_id, amount_tendered, change_amount, transaction_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'completed')",
            [$sessionId, $storeId, $userId, $customerId ?: null,
             $totalAmount, $originalAmount, $discountAmount, $discountReason ?: null,
             $paymentMethodId, $amountTendered ?: null, $changeAmount]
        );
        $orderId = (int) $db->lastInsertId();

        // Create order items
        foreach ($orderItems as $oi) {
            $db->query(
                "INSERT INTO dc_order_items (order_id, product_id, quantity, unit_price, total_price, customizations, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$orderId, $oi['product_id'], $oi['quantity'], $oi['unit_price'],
                 $oi['total_price'], $oi['customizations'], $oi['notes'] ?: null]
            );
        }

        // Deduct product stock (finished goods)
        foreach ($orderItems as $oi) {
            if ($oi['has_stock'] !== 1) continue;
            $stmt = $db->query(
                "UPDATE dc_products SET current_stock = current_stock - ?
                 WHERE product_id = ? AND current_stock >= ?",
                [$oi['quantity'], $oi['product_id'], $oi['quantity']]
            );
            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException(
                    "Failed to deduct stock for product ID {$oi['product_id']}"
                );
            }
            // Record product stock movement in its own journal
            $db->query(
                "INSERT INTO dc_product_stock_movements (product_id, quantity_change, movement_type,
                        reference_type, reference_id, notes, created_by)
                 VALUES (?, ?, 'sale', 'order', ?, ?, ?)",
                [$oi['product_id'], -$oi['quantity'], $orderId,
                 'Product sale — order #' . $orderId, $userId]
            );
        }

        // Deduct ingredient stock (BOM + addons)
        foreach ($stockDeductions as $ingId => $deductQty) {
            $stmt = $db->query(
                "UPDATE dc_ingredients SET current_stock = current_stock - ?
                 WHERE ingredient_id = ? AND current_stock >= ?",
                [$deductQty, $ingId, $deductQty]
            );
            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException("Failed to deduct ingredient ID $ingId");
            }
            $db->query(
                "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type,
                        reference_type, reference_id, notes, created_by)
                 VALUES (?, ?, 'consumption', 'order', ?, ?, ?)",
                [$ingId, -$deductQty, $orderId,
                 'Auto-deducted by order #' . $orderId, $userId]
            );
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dc_auditLog('order.create_failed', 'dc_orders', null, ['error' => $e->getMessage()], null);
        dcJsonError('Failed to create order: ' . $e->getMessage(), 500);
    }

    dc_auditLog('order.created', 'dc_orders', (string) $orderId, null, [
        'total' => $totalAmount, 'items' => count($orderItems), 'payment' => $paymentMethodId,
    ]);

    dcJsonResponse(['ok' => true, 'order_id' => $orderId, 'total' => $totalAmount]);
}

/**
 * POST /dc-cafe/api/v1/orders/{id}/void — Void an order (idempotent)
 *
 * Restores BOM/addon ingredient stock AND finished-product stock.
 * Only completed orders can be voided. Rejects already-voided orders.
 */
function apiVoidOrder(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor');

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

    $db->beginTransaction();
    try {
        $items = $db->query(
            "SELECT oi.product_id, oi.quantity, oi.item_id, oi.customizations,
                    p.has_stock
             FROM dc_order_items oi
             JOIN dc_products p ON p.product_id = oi.product_id
             WHERE oi.order_id = ?",
            [$orderId]
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $qty = (int) $item['quantity'];

            // Restore finished-product stock
            if ((int) $item['has_stock'] === 1) {
                $db->query(
                    "UPDATE dc_products SET current_stock = current_stock + ? WHERE product_id = ?",
                    [$qty, $productId]
                );
                $db->query(
                    "INSERT INTO dc_product_stock_movements (product_id, quantity_change, movement_type,
                            reference_type, reference_id, notes)
                     VALUES (?, ?, 'void_restore', 'order', ?, ?)",
                    [$productId, $qty, $orderId, 'Restored by void of order #' . $orderId]
                );
            }

            // Restore BOM ingredients
            $bom = $db->query(
                "SELECT ingredient_id, quantity FROM dc_product_ingredients WHERE product_id = ?",
                [$productId]
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($bom as $bomItem) {
                $ingId = (int) $bomItem['ingredient_id'];
                $restoreQty = (float) $bomItem['quantity'] * $qty;
                $db->query(
                    "UPDATE dc_ingredients SET current_stock = current_stock + ? WHERE ingredient_id = ?",
                    [$restoreQty, $ingId]
                );
                $db->query(
                    "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type,
                            reference_type, reference_id, notes)
                     VALUES (?, ?, 'adjustment', 'order', ?, ?)",
                    [$ingId, $restoreQty, $orderId, 'Restored by void of order #' . $orderId]
                );
            }

            // Restore addon ingredients
            $customizations = $item['customizations'] ? json_decode($item['customizations'], true) : null;
            if (is_array($customizations) && !empty($customizations['addons'])) {
                foreach ($customizations['addons'] as $addon) {
                    $addonName = $addon['name'] ?? '';
                    if ($addonName === '') continue;
                    $addonIngredients = $db->query(
                        "SELECT dai.ingredient_id, dai.quantity
                         FROM dc_addon_ingredients dai
                         JOIN dc_soft_serve_addons sa ON sa.addon_id = dai.addon_id
                         WHERE sa.name = ?",
                        [$addonName]
                    )->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($addonIngredients as $ai) {
                        $ingId = (int) $ai['ingredient_id'];
                        $restoreQty = (float) $ai['quantity'] * $qty;
                        $db->query(
                            "UPDATE dc_ingredients SET current_stock = current_stock + ? WHERE ingredient_id = ?",
                            [$restoreQty, $ingId]
                        );
                        $db->query(
                            "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type,
                                    reference_type, reference_id, notes)
                             VALUES (?, ?, 'adjustment', 'order', ?, ?)",
                            [$ingId, $restoreQty, $orderId, 'Restored by void of order #' . $orderId]
                        );
                    }
                }
            }
        }

        $db->query("UPDATE dc_orders SET status = 'voided' WHERE order_id = ?", [$orderId]);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dcJsonError('Failed to void order: ' . $e->getMessage(), 500);
    }

    dc_auditLog('order.voided', 'dc_orders', (string) $orderId, ['total' => $order['total_amount']], null);
    dcJsonResponse(['ok' => true]);
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
         JOIN dc_payment_methods pm ON pm.payment_method_id = o.payment_method_id
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

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dc-cafe-sales-report-' . $startDate . '-to-' . $endDate . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Product', 'Category', 'Qty Sold', 'Total Revenue', 'Order Count']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['product'], $row['category'], $row['qty_sold'], $row['total_revenue'], $row['order_count']]);
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
         JOIN dc_payment_methods pm ON pm.payment_method_id = o.payment_method_id
         WHERE o.customer_id = ?
         ORDER BY o.transaction_date DESC LIMIT 50",
        [$customerId]
    )->fetchAll(\PDO::FETCH_ASSOC);

    dcJsonResponse(['ok' => true, 'orders' => $orders]);
}
