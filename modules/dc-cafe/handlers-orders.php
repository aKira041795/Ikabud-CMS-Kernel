<?php
/**
 * DC Cafe — Order Lifecycle Handlers
 *
 * Create, void, list, export orders. Stock deduction on completion.
 */

declare(strict_types=1);

/**
 * POST /dc-cafe/api/v1/orders — Create a new order
 *
 * Input: { store_id, session_id, items: [{product_id, quantity, unit_price, customizations?, notes?}],
 *         payment_method_id, amount_tendered?, discount_amount?, discount_reason?, voucher_code?,
 *         customer_id?, customer? (new customer: {name, phone}) }
 */
function apiCreateOrder(array $params = []): void
{
    $ctx = dcCtx();
    $ctx->requireAnyRole('admin', 'supervisor', 'cashier');

    $user = $ctx->user();
    $storeId = (int) (dcInput('store_id') ?? $user['store_id'] ?? 1);
    $sessionId = (int) (dcInput('session_id') ?? 0);
    $paymentMethodId = (int) (dcInput('payment_method_id') ?? 1);
    $amountTendered = (float) (dcInput('amount_tendered') ?? 0);
    $discountAmount = (float) (dcInput('discount_amount') ?? 0);
    $discountReason = (string) (dcInput('discount_reason') ?? '');
    $voucherCode = (string) (dcInput('voucher_code') ?? '');
    $items = (array) (dcInput('items') ?? []);
    $customerId = (int) (dcInput('customer_id') ?? 0);
    $customerData = dcInput('customer'); // {name, phone} for new customer

    if (empty($items) || $sessionId <= 0) {
        dcJsonError('Order must contain at least one item and a valid session');
    }

    $db = dcDb();

    // Verify active session
    $session = $db->query(
        "SELECT * FROM dc_sessions WHERE session_id = ? AND status = 'active'",
        [$sessionId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (!$session) {
        dcJsonError('Active session not found');
    }

    // Handle new customer
    if ($customerId <= 0 && $customerData !== null) {
        $name = (string) ($customerData['name'] ?? '');
        $phone = (string) ($customerData['phone'] ?? '');
        if ($name !== '' && $phone !== '') {
            // Check existing
            $existing = $db->query("SELECT customer_id FROM dc_customers WHERE phone = ?", [$phone])->fetch();
            if ($existing) {
                $customerId = (int) $existing['customer_id'];
            } else {
                $db->query(
                    "INSERT INTO dc_customers (name, phone) VALUES (?, ?)",
                    [$name, $phone]
                );
                $customerId = (int) $db->lastInsertId();
            }
        }
    }

    // Calculate totals
    $subtotal = 0;
    foreach ($items as &$item) {
        $qty = (int) ($item['quantity'] ?? 1);
        $price = (float) ($item['unit_price'] ?? 0);
        $item['total_price'] = $qty * $price;
        $subtotal += $item['total_price'];
    }
    unset($item);

    $originalAmount = $subtotal;
    $totalAmount = max(0, $subtotal - $discountAmount);

    // Validate voucher if provided
    $voucherId = null;
    if ($voucherCode !== '') {
        $voucher = $db->query(
            "SELECT * FROM dc_vouchers WHERE code = ? AND is_active = 1
             AND valid_from <= NOW() AND valid_until >= NOW()",
            [$voucherCode]
        )->fetch(\PDO::FETCH_ASSOC);

        if ($voucher) {
            $voucherId = (int) $voucher['voucher_id'];
        }
    }

    $db->beginTransaction();
    try {
        // Create order
        $changeAmount = $amountTendered > 0 ? max(0, $amountTendered - $totalAmount) : 0;
        $db->query(
            "INSERT INTO dc_orders (session_id, store_id, cashier_id, customer_id,
                    total_amount, original_amount, discount_amount, discount_reason,
                    payment_method_id, amount_tendered, change_amount, transaction_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'completed')",
            [
                $sessionId, $storeId, (int) $user['user_id'], $customerId ?: null,
                $totalAmount, $originalAmount, $discountAmount, $discountReason ?: null,
                $paymentMethodId, $amountTendered ?: null, $changeAmount,
            ]
        );
        $orderId = (int) $db->lastInsertId();

        // Create order items
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['unit_price'] ?? 0);
            $totalPrice = (float) ($item['total_price'] ?? ($qty * $price));
            $customizations = isset($item['customizations']) ? json_encode($item['customizations']) : null;
            $notes = (string) ($item['notes'] ?? '');
            $parentItemId = isset($item['parent_item_id']) ? (int) $item['parent_item_id'] : null;

            $db->query(
                "INSERT INTO dc_order_items (order_id, product_id, quantity, unit_price, total_price, customizations, notes, parent_item_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$orderId, $productId, $qty, $price, $totalPrice, $customizations, $notes ?: null, $parentItemId]
            );
        }

        // Deduct inventory via BOM + addon ingredients + product stock
        $deductions = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 1);

            // Deduct product stock for finished goods (has_stock = 1, no BOM)
            $product = $db->query(
                "SELECT has_stock FROM dc_products WHERE product_id = ?", [$productId]
            )->fetch(\PDO::FETCH_ASSOC);

            if ($product && (int)$product['has_stock'] === 1) {
                $db->query(
                    "UPDATE dc_products SET current_stock = current_stock - ? WHERE product_id = ? AND current_stock >= ?",
                    [$qty, $productId, $qty]
                );
            }

            // Deduct product ingredients (BOM)
            $bom = $db->query(
                "SELECT ingredient_id, quantity FROM dc_product_ingredients WHERE product_id = ?",
                [$productId]
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($bom as $bomItem) {
                $ingredientId = (int) $bomItem['ingredient_id'];
                $deductQty = (float) $bomItem['quantity'] * $qty;
                $deductions[$ingredientId] = ($deductions[$ingredientId] ?? 0) + $deductQty;
            }

            // Deduct addon ingredients from customizations JSON
            $customizations = isset($item['customizations']) ? $item['customizations'] : null;
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
                        $ingredientId = (int) $ai['ingredient_id'];
                        $deductQty = (float) $ai['quantity'] * $qty;
                        $deductions[$ingredientId] = ($deductions[$ingredientId] ?? 0) + $deductQty;
                    }
                }
            }
        }

        foreach ($deductions as $ingredientId => $deductQty) {
            $db->query(
                "UPDATE dc_ingredients SET current_stock = current_stock - ? WHERE ingredient_id = ? AND current_stock >= ?",
                [$deductQty, $ingredientId, $deductQty]
            );
            $db->query(
                "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type, reference_type, reference_id, notes)
                 VALUES (?, ?, 'consumption', 'order', ?, ?)",
                [$ingredientId, -$deductQty, $orderId, 'Auto-deducted by order #' . $orderId]
            );
        }

        // Record voucher usage
        if ($voucherId !== null) {
            $db->query(
                "UPDATE dc_vouchers SET used_count = used_count + 1 WHERE voucher_id = ?",
                [$voucherId]
            );
            $db->query(
                "INSERT INTO dc_voucher_usages (voucher_id, order_id, discount_amount) VALUES (?, ?, ?)",
                [$voucherId, $orderId, $discountAmount]
            );
        }

        // Add loyalty points for registered customers
        if ($customerId > 0) {
            $pointsEarned = (int) floor($totalAmount / 20); // 1 point per ₱20
            if ($pointsEarned > 0) {
                $db->query(
                    "UPDATE dc_customers SET points_balance = points_balance + ?, total_points_earned = total_points_earned + ?
                     WHERE customer_id = ?",
                    [$pointsEarned, $pointsEarned, $customerId]
                );
            }
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        dc_auditLog('order.create_failed', 'dc_orders', null, ['error' => $e->getMessage()], null);
        dcJsonError('Failed to create order: ' . $e->getMessage(), 500);
    }

    dc_auditLog('order.created', 'dc_orders', (string) $orderId, null, [
        'total' => $totalAmount, 'items' => count($items), 'payment' => $paymentMethodId,
    ]);

    dcJsonResponse(['ok' => true, 'order_id' => $orderId, 'total' => $totalAmount, 'points_earned' => $pointsEarned ?? 0]);
}

/**
 * POST /dc-cafe/api/v1/orders/{id}/void — Void an order
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
        dcJsonError('Only completed orders can be voided');
    }

    $db->beginTransaction();
    try {
        // Reverse stock deductions (BOM + addon ingredients)
        $items = $db->query(
            "SELECT oi.product_id, oi.quantity, oi.item_id, oi.customizations
             FROM dc_order_items oi WHERE oi.order_id = ?",
            [$orderId]
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $bom = $db->query(
                "SELECT ingredient_id, quantity FROM dc_product_ingredients WHERE product_id = ?",
                [(int) $item['product_id']]
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($bom as $bomItem) {
                $ingredientId = (int) $bomItem['ingredient_id'];
                $qty = (float) $bomItem['quantity'] * (int) $item['quantity'];
                $db->query(
                    "UPDATE dc_ingredients SET current_stock = current_stock + ? WHERE ingredient_id = ?",
                    [$qty, $ingredientId]
                );
                $db->query(
                    "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type, reference_type, reference_id, notes)
                     VALUES (?, ?, 'adjustment', 'order', ?, ?)",
                    [$ingredientId, $qty, $orderId, 'Restored by void of order #' . $orderId]
                );
            }

            // Restore addon ingredients from customizations JSON
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
                        $ingredientId = (int) $ai['ingredient_id'];
                        $qty = (float) $ai['quantity'] * (int) $item['quantity'];
                        $db->query(
                            "UPDATE dc_ingredients SET current_stock = current_stock + ? WHERE ingredient_id = ?",
                            [$qty, $ingredientId]
                        );
                        $db->query(
                            "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type, reference_type, reference_id, notes)
                             VALUES (?, ?, 'adjustment', 'order', ?, ?)",
                            [$ingredientId, $qty, $orderId, 'Restored by void of order #' . $orderId]
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
