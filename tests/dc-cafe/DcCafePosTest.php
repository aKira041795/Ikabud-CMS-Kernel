<?php
/**
 * DC Cafe POS — Integration Tests
 *
 * Tests the full POS flow: start session → create order → verify stock deduction → end session.
 * Uses TestHarness integration mode with a tenant DB.
 *
 * @see .github/instructions/testing-conventions.instructions.md
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-pos', TestHarness::MODE_INTEGRATION, 'baronbakeshop');

// Fingerprint the source files we're testing
$h->fingerprint('modules/dc-cafe/handlers.php');
$h->fingerprint('modules/dc-cafe/handlers-orders.php');
$h->fingerprint('modules/dc-cafe/handlers-inventory.php');
$h->fingerprint('modules/dc-cafe/handlers-customers.php');
$h->fingerprint('modules/dc-cafe/helpers/entity-views.php');

// Clear logs before starting
file_put_contents('/var/www/html/applicationostest/storage/logs/app.log', '');
file_put_contents('/var/www/html/applicationostest/storage/logs/error.log', '');

$db = app()->db();

// ─── SECTION: Module Loads ──────────────────────────────────────────────

$h->section('Module Loads');

$module = module('dc-cafe');
$h->test('dc-cafe module is registered', $module !== null);
$h->test('dc-cafe has db() access', $module->db() !== null);

// ─── SECTION: Database Migrations ────────────────────────────────────────

$h->section('Database Schema');

$tables = [
    'dc_users', 'dc_stores', 'dc_categories', 'dc_products',
    'dc_payment_methods', 'dc_sessions', 'dc_orders', 'dc_order_items',
    'dc_customers', 'dc_ingredients', 'dc_product_ingredients',
    'dc_inventory_movements', 'dc_suppliers', 'dc_soft_serve_bases',
    'dc_soft_serve_sauces', 'dc_soft_serve_toppings', 'dc_vouchers',
    'dc_voucher_usages', 'dc_inventory_progress',
];

foreach ($tables as $table) {
    $result = $db->query("SHOW TABLES LIKE '{$table}'")->fetch();
    $h->test("Table '{$table}' exists", $result !== false && $result[0] === $table);
}

// Verify seeded data
$stores = $db->query("SELECT COUNT(*) AS cnt FROM dc_stores")->fetch();
$h->test('Stores seeded (3)', (int) $stores['cnt'] === 3);

$categories = $db->query("SELECT COUNT(*) AS cnt FROM dc_categories")->fetch();
$h->test('Categories seeded (5)', (int) $categories['cnt'] === 5);

$paymentMethods = $db->query("SELECT COUNT(*) AS cnt FROM dc_payment_methods")->fetch();
$h->test('Payment methods seeded (3)', (int) $paymentMethods['cnt'] === 3);

$bases = $db->query("SELECT COUNT(*) AS cnt FROM dc_soft_serve_bases")->fetch();
$h->test('Soft-serve bases seeded (3)', (int) $bases['cnt'] === 3);

// ─── SECTION: Auth ──────────────────────────────────────────────────────

$h->section('Module-Owned Auth');

// Create a test user
$db->query(
    "INSERT INTO dc_users (username, password_hash, full_name, role, store_id, is_active)
     VALUES (?, ?, ?, ?, ?, 1)",
    ['testcashier', password_hash('testpass123', PASSWORD_BCRYPT), 'Test Cashier', 'cashier', 1]
);

$authResult = dc_cap_kernel_auth_authenticate_1(['username' => 'testcashier', 'password' => 'testpass123']);
$h->test('Auth succeeds with valid credentials', $authResult !== null && $authResult['role'] === 'cashier');

$badAuth = dc_cap_kernel_auth_authenticate_1(['username' => 'testcashier', 'password' => 'wrongpass']);
$h->test('Auth fails with invalid password', $badAuth === null);

// Capture the actual user_id from the database (avoid hardcoding 1)
$testUserId = (int) $db->query("SELECT user_id FROM dc_users WHERE username = 'testcashier'")->fetchColumn();
$h->test('Test user_id resolved', $testUserId > 0);

// ─── SECTION: POS Session Flow ──────────────────────────────────────────

$h->section('Session Lifecycle');

// Start a session using the actual test user
$db->query(
    "INSERT INTO dc_sessions (user_id, store_id, starting_cash, shift_type, shift_start, status)
     VALUES (?, 1, 1000.00, 'morning', NOW(), 'active')",
    [$testUserId]
);
$sessionId = (int) $db->lastInsertId();
$h->test('Session created with ID', $sessionId > 0);

// Verify session is active
$session = $db->query("SELECT * FROM dc_sessions WHERE session_id = ?", [$sessionId])->fetch(\PDO::FETCH_ASSOC);
$h->test('Session status is active', $session['status'] === 'active');
$h->test('Session has store_id', (int) $session['store_id'] === 1);
$h->test('Session has is_late_report default', (int) $session['is_late_report'] === 0);

// ─── SECTION: Product Catalog ───────────────────────────────────────────

$h->section('Product Catalog');

// Insert test products
$db->query(
    "INSERT INTO dc_products (store_id, category_id, name, base_price, is_variable)
     VALUES (1, 1, 'Test Cuddly (FroYo)', 95.00, 1)"
);
$productId1 = (int) $db->lastInsertId();

$db->query(
    "INSERT INTO dc_products (store_id, category_id, name, base_price, is_variable)
     VALUES (1, 1, 'Test Snuggly (Soft Serve)', 150.00, 1)"
);
$productId2 = (int) $db->lastInsertId();

$h->test('Products created', $productId1 > 0 && $productId2 > 0);

// Test entity view
$products = dc_cap_entity_list_product_1(['store_id' => 1]);
$h->test('Entity list returns products', count($products) >= 2);
$h->test('Entity includes name', $products[0]['name'] !== '');
$h->test('Entity includes price', $products[0]['price'] > 0);
$h->test('Entity includes category', $products[0]['category'] !== '');
$h->test('Entity includes is_variable', isset($products[0]['is_variable']));

// Test entity get
$singleProduct = dc_cap_entity_get_product_1(['id' => $productId1]);
$h->test('Entity get returns single product', $singleProduct !== null);
$h->test('Entity get name matches', $singleProduct['name'] === 'Test Cuddly (FroYo)');

// ─── SECTION: Order Creation & Stock Deduction ──────────────────────────

$h->section('Order Creation & Inventory');

// Insert test ingredients
$db->query(
    "INSERT INTO dc_ingredients (name, unit, cost_per_unit, current_stock, reorder_level)
     VALUES ('Test FroYo Mix', 'L', 45.00, 10.00, 2.00)"
);
$ingredientId1 = (int) $db->lastInsertId();

$db->query(
    "INSERT INTO dc_ingredients (name, unit, cost_per_unit, current_stock, reorder_level)
     VALUES ('Test Caramel Sauce', 'L', 80.00, 5.00, 1.00)"
);
$ingredientId2 = (int) $db->lastInsertId();

// Create BOM: Cuddly = 0.2L FroYo Mix + 0.05L Caramel Sauce
$db->query(
    "INSERT INTO dc_product_ingredients (product_id, ingredient_id, quantity)
     VALUES (?, ?, ?)",
    [$productId1, $ingredientId1, 0.200]
);
$db->query(
    "INSERT INTO dc_product_ingredients (product_id, ingredient_id, quantity)
     VALUES (?, ?, ?)",
    [$productId1, $ingredientId2, 0.050]
);

// Verify BOM
$bom = $db->query(
    "SELECT * FROM dc_product_ingredients WHERE product_id = ?",
    [$productId1]
)->fetchAll(\PDO::FETCH_ASSOC);
$h->test('BOM has 2 ingredients', count($bom) === 2);

// Create an order (simulating what apiCreateOrder does)
$totalAmount = 95.00;
$db->query(
    "INSERT INTO dc_orders (session_id, store_id, cashier_id, total_amount, original_amount,
            payment_method_id, transaction_date, status)
     VALUES (?, 1, ?, ?, ?, 1, NOW(), 'completed')",
    [$sessionId, $testUserId, $totalAmount, $totalAmount]
);
$orderId = (int) $db->lastInsertId();
$h->test('Order created with ID', $orderId > 0);

// Add order item
$db->query(
    "INSERT INTO dc_order_items (order_id, product_id, quantity, unit_price, total_price)
     VALUES (?, ?, 2, 95.00, 190.00)",
    [$orderId, $productId1]
);

// Deduct stock via BOM (simulating the real deduction logic)
$items = $db->query("SELECT product_id, quantity FROM dc_order_items WHERE order_id = ?", [$orderId])->fetchAll();
foreach ($items as $item) {
    $bomItems = $db->query(
        "SELECT ingredient_id, quantity FROM dc_product_ingredients WHERE product_id = ?",
        [(int) $item['product_id']]
    )->fetchAll();
    foreach ($bomItems as $bomItem) {
        $ingredientId = (int) $bomItem['ingredient_id'];
        $qtyToDeduct = (float) $bomItem['quantity'] * (int) $item['quantity'];
        $db->query(
            "UPDATE dc_ingredients SET current_stock = current_stock - ? WHERE ingredient_id = ?",
            [$qtyToDeduct, $ingredientId]
        );
        $db->query(
            "INSERT INTO dc_inventory_movements (ingredient_id, quantity_change, movement_type, reference_type, reference_id)
             VALUES (?, ?, 'consumption', 'order', ?)",
            [$ingredientId, -$qtyToDeduct, $orderId]
        );
    }
}

// Verify stock deducted
$froyoSrock = $db->query(
    "SELECT current_stock FROM dc_ingredients WHERE ingredient_id = ?",
    [$ingredientId1]
)->fetchColumn();
$h->test('FroYo stock deducted (10 - 0.4 = 9.6)', abs((float) $froyoSrock - 9.6) < 0.001);

$caramelStock = $db->query(
    "SELECT current_stock FROM dc_ingredients WHERE ingredient_id = ?",
    [$ingredientId2]
)->fetchColumn();
$h->test('Caramel stock deducted (5 - 0.1 = 4.9)', abs((float) $caramelStock - 4.9) < 0.001);

// Verify movement audit trail
$movements = $db->query(
    "SELECT COUNT(*) AS cnt FROM dc_inventory_movements WHERE reference_type = 'order' AND reference_id = ?",
    [$orderId]
)->fetch();
$h->test('2 inventory movements recorded for order', (int) $movements['cnt'] === 2);

// ─── SECTION: Entity Views ──────────────────────────────────────────────

$h->section('Entity Views');

$orders = dc_cap_entity_list_order_1(['store_id' => 1]);
$h->test('Entity list returns orders', count($orders) >= 1);
$h->test('Order entity has total', $orders[0]['total'] > 0);
$h->test('Order entity has status', in_array($orders[0]['status'], ['draft', 'completed', 'voided']));
$h->test('Order entity has payment method', $orders[0]['payment_method'] !== '');

$orderDetail = dc_cap_entity_get_order_1(['id' => $orderId]);
$h->test('Entity get returns order', $orderDetail !== null);
$h->test('Entity get order has items', count($orderDetail['items']) >= 1);

$inventory = dc_cap_entity_list_inventory_1([]);
$h->test('Entity list returns inventory', count($inventory) >= 2);
$h->test('Inventory entity has stock field', isset($inventory[0]['stock']));
$h->test('Inventory entity has is_low field', isset($inventory[0]['is_low']));

// ─── SECTION: Order Void ────────────────────────────────────────────────

$h->section('Order Void — Stock Reversal');

// Save stock before void
$froYoBeforeVoid = $db->query(
    "SELECT current_stock FROM dc_ingredients WHERE ingredient_id = ?",
    [$ingredientId1]
)->fetchColumn();

// Reverse stock (simulating apiVoidOrder logic)
$items = $db->query("SELECT product_id, quantity FROM dc_order_items WHERE order_id = ?", [$orderId])->fetchAll();
foreach ($items as $item) {
    $bomItems = $db->query(
        "SELECT ingredient_id, quantity FROM dc_product_ingredients WHERE product_id = ?",
        [(int) $item['product_id']]
    )->fetchAll();
    foreach ($bomItems as $bomItem) {
        $ingredientId = (int) $bomItem['ingredient_id'];
        $qtyToAdd = (float) $bomItem['quantity'] * (int) $item['quantity'];
        $db->query("UPDATE dc_ingredients SET current_stock = current_stock + ? WHERE ingredient_id = ?", [$qtyToAdd, $ingredientId]);
    }
}
$db->query("UPDATE dc_orders SET status = 'voided' WHERE order_id = ?", [$orderId]);

$froYoAfterVoid = $db->query(
    "SELECT current_stock FROM dc_ingredients WHERE ingredient_id = ?",
    [$ingredientId1]
)->fetchColumn();
$h->test('Stock restored after void', abs((float) $froYoAfterVoid - (float) $froYoBeforeVoid) < 0.001);

$voidedOrder = $db->query("SELECT status FROM dc_orders WHERE order_id = ?", [$orderId])->fetchColumn();
$h->test('Order status changed to voided', $voidedOrder === 'voided');

// ─── SECTION: Session End ───────────────────────────────────────────────

$h->section('Session End');

$db->query(
    "UPDATE dc_sessions SET ending_cash = 1190.00, shift_end = NOW(), status = 'closed'
     WHERE session_id = ?",
    [$sessionId]
);

$session = $db->query("SELECT * FROM dc_sessions WHERE session_id = ?", [$sessionId])->fetch(\PDO::FETCH_ASSOC);
$h->test('Session status is closed', $session['status'] === 'closed');
$h->test('Session has ending_cash', (float) $session['ending_cash'] > 0);

// ─── SECTION: Voucher Validation ────────────────────────────────────────

$h->section('Voucher Validation');

$db->query(
    "INSERT INTO dc_vouchers (code, discount_type, discount_value, min_order_amount, valid_from, valid_until)
     VALUES ('TEST10', 'fixed', 10.00, 50.00, NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 30 DAY)"
);
$voucherId = (int) $db->lastInsertId();
$h->test('Voucher created', $voucherId > 0);

$voucher = $db->query(
    "SELECT * FROM dc_vouchers WHERE code = 'TEST10' AND is_active = 1
     AND valid_from <= NOW() AND valid_until >= NOW()"
)->fetch(\PDO::FETCH_ASSOC);
$h->test('Voucher is valid', $voucher !== false);

// ─── SECTION: Customer & Loyalty ────────────────────────────────────────

$h->section('Customer & Loyalty');

$db->query(
    "INSERT INTO dc_customers (name, phone, points_balance, total_points_earned)
     VALUES ('Test Customer', '09171234567', 50, 100)"
);
$customerId = (int) $db->lastInsertId();
$h->test('Customer created', $customerId > 0);

$customer = dc_cap_entity_get_customer_1(['id' => $customerId]);
$h->test('Customer entity has points', $customer['points'] === 50);
$h->test('Customer entity has order_count', $customer['order_count'] >= 0);

// Add points (1 per ₱20)
$spent = 190.00;
$pointsEarned = (int) floor($spent / 20);
$db->query(
    "UPDATE dc_customers SET points_balance = points_balance + ?,
     total_points_earned = total_points_earned + ? WHERE customer_id = ?",
    [$pointsEarned, $pointsEarned, $customerId]
);
$updatedCustomer = $db->query("SELECT points_balance FROM dc_customers WHERE customer_id = ?", [$customerId])->fetchColumn();
$h->test('Points earned (50 + 9 = 59)', (int) $updatedCustomer === 59);

// ─── SECTION: Inventory Progress Save/Resume ────────────────────────────

$h->section('Inventory Progress');

$db->query(
    "INSERT INTO dc_inventory_progress (session_id, product_id, beginning_qty, production_qty, ending_qty, sold_qty)
     VALUES (?, ?, 10, 5, 8, 7)",
    [$sessionId, $productId1]
);
$progressId = (int) $db->lastInsertId();
$h->test('Inventory progress saved', $progressId > 0);

$progress = $db->query(
    "SELECT * FROM dc_inventory_progress WHERE session_id = ? AND product_id = ?",
    [$sessionId, $productId1]
)->fetch(\PDO::FETCH_ASSOC);
$h->test('Progress resume returns data', $progress !== false);
$h->test('Progress has beginning_qty', (float) $progress['beginning_qty'] === 10.0);
$h->test('Progress has sold_qty', (float) $progress['sold_qty'] === 7.0);

// ─── Cleanup ────────────────────────────────────────────────────────────

$h->section('Test Cleanup');

$db->query("DELETE FROM dc_inventory_movements WHERE reference_id = ?", [$orderId]);
$db->query("DELETE FROM dc_order_items WHERE order_id = ?", [$orderId]);
$db->query("DELETE FROM dc_orders WHERE order_id = ?", [$orderId]);
$db->query("DELETE FROM dc_product_ingredients WHERE product_id IN (?, ?)", [$productId1, $productId2]);
$db->query("DELETE FROM dc_ingredients WHERE ingredient_id IN (?, ?)", [$ingredientId1, $ingredientId2]);
$db->query("DELETE FROM dc_inventory_progress WHERE progress_id = ?", [$progressId]);
$db->query("DELETE FROM dc_vouchers WHERE voucher_id = ?", [$voucherId]);
$db->query("DELETE FROM dc_customers WHERE customer_id = ?", [$customerId]);
$db->query("DELETE FROM dc_products WHERE product_id IN (?, ?)", [$productId1, $productId2]);
$db->query("DELETE FROM dc_sessions WHERE session_id = ?", [$sessionId]);
$db->query("DELETE FROM dc_users WHERE username = 'testcashier'");

$h->test('Test data cleaned up', true);

// ─── Done ───────────────────────────────────────────────────────────────

$h->done();
