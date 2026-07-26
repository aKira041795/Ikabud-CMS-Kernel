<?php
/**
 * DC Cafe — Request-Path Integration Test
 *
 * Exercises DC Cafe order and void endpoints through public/index.php with
 * tenant resolution by host and authenticated request context injection.
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-http', TestHarness::MODE_INTEGRATION, 'baronbakeshop');
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../src/helpers/module-migrations.php';
$h->fingerprint('modules/dc-cafe/routes.php');
$h->fingerprint('modules/dc-cafe/handlers-orders.php');
$h->fingerprint('modules/dc-cafe/handlers-inventory.php');
$h->fingerprint('modules/dc-cafe/handlers-products.php');

$db = app()->db();
$h->section('Bootstrap');
$h->test('Tenant DB available', $db instanceof PDO);
$module = module('dc-cafe');
$h->test('dc-cafe module is registered', $module !== null);

if (!$db instanceof PDO || $module === null) {
    $h->done();
}

tenantSyncKernelMigrations($db);
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('dc-cafe');

/**
 * @return array{status:int,body:string,headers:array,context:array,exit_code:int,raw:string}
 */
function dcRunEntrypointRequest(array $server, ?array $user = null, ?array $postData = null, ?string $rawBody = null): array
{
    $runnerPath = sys_get_temp_dir() . '/dccafe-http-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $bootstrapPath = __DIR__ . '/../../bootstrap.php';
    $entrypointPath = __DIR__ . '/../../public/index.php';
    $patchedEntrypointPath = null;

    if ($rawBody !== null) {
        $patchedEntrypointPath = __DIR__ . '/../../public/dccafe-http-entrypoint-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
        $entrypointSource = (string) file_get_contents($entrypointPath);
        $replacement = "file_get_contents('data://text/plain," . rawurlencode($rawBody) . "')";
        $entrypointSource = str_replace("file_get_contents('php://input')", $replacement, $entrypointSource);
        file_put_contents($patchedEntrypointPath, $entrypointSource);
        $entrypointPath = $patchedEntrypointPath;
    }

    $script = "<?php\n"
        . "foreach (" . var_export($server, true) . " as \$key => \$value) { \$_SERVER[(string) \$key] = \$value; }\n"
        . "if (!isset(\$_SERVER['REQUEST_METHOD'])) { \$_SERVER['REQUEST_METHOD'] = 'GET'; }\n"
        . "if (!isset(\$_SERVER['REQUEST_URI'])) { \$_SERVER['REQUEST_URI'] = '/'; }\n"
        . "if (!isset(\$_SERVER['HTTP_HOST'])) { \$_SERVER['HTTP_HOST'] = 'baronbakeshop'; }\n"
        . "if (!isset(\$_SERVER['SERVER_NAME'])) { \$_SERVER['SERVER_NAME'] = \$_SERVER['HTTP_HOST']; }\n"
        . "if (!isset(\$_SERVER['HTTP_ACCEPT'])) { \$_SERVER['HTTP_ACCEPT'] = 'application/json'; }\n"
        . "\$_GET = [];\n"
        . "\$__ik_query = parse_url((string) \$_SERVER['REQUEST_URI'], PHP_URL_QUERY);\n"
        . "if (is_string(\$__ik_query) && \$__ik_query !== '') { parse_str(\$__ik_query, \$_GET); }\n"
        . "\$_POST = " . var_export($postData, true) . " ?: [];\n"
        . "\$_REQUEST = array_merge(\$_GET, \$_POST);\n"
        . "require " . var_export($bootstrapPath, true) . ";\n"
        . "\$user = " . var_export($user, true) . ";\n"
        . "if (is_array(\$user)) { app()->setUser(\$user); }\n"
        . "register_shutdown_function(static function (): void {\n"
        . "    echo \"\\n__RESULT__\\n\";\n"
        . "    echo json_encode([\n"
        . "        'status' => (int) (http_response_code() ?: 200),\n"
        . "        'headers' => headers_list(),\n"
        . "        'context' => kernelCurrentRequestDispatchContext() ?? [],\n"
        . "    ], JSON_UNESCAPED_SLASHES);\n"
        . "});\n"
        . "require " . var_export($entrypointPath, true) . ";\n";

    file_put_contents($runnerPath, $script);
    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPath);
    if (is_string($patchedEntrypointPath) && $patchedEntrypointPath !== '') {
        @unlink($patchedEntrypointPath);
    }

    $raw = implode("\n", $output);
    $parts = explode("\n__RESULT__\n", $raw, 2);
    $meta = isset($parts[1]) ? json_decode($parts[1], true) : [];
    if (!is_array($meta)) {
        $meta = [];
    }

    return [
        'status' => (int) ($meta['status'] ?? 0),
        'body' => (string) ($parts[0] ?? ''),
        'headers' => is_array($meta['headers'] ?? null) ? $meta['headers'] : [],
        'context' => is_array($meta['context'] ?? null) ? $meta['context'] : [],
        'exit_code' => $exitCode,
        'raw' => $raw,
    ];
}

/**
 * @return array{status:int,body:string,headers:array,exit_code:int,raw:string}
 */
function dcRunDirectHandler(string $handlerFile, string $handlerName, array $params = [], ?array $user = null, ?array $postData = null): array
{
    $runnerPath = sys_get_temp_dir() . '/dccafe-direct-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $bootstrapPath = __DIR__ . '/../../bootstrap.php';
    $moduleManagerPath = __DIR__ . '/../../src/helpers/module-manager.php';
    $handlerPath = __DIR__ . '/../../' . ltrim($handlerFile, '/');
    $helpersPath = __DIR__ . '/../../modules/dc-cafe/helpers.php';

    $script = "<?php\n"
        . "\$_SERVER['REQUEST_METHOD'] = 'POST';\n"
        . "\$_SERVER['REQUEST_URI'] = '/__direct-handler';\n"
        . "\$_SERVER['HTTP_HOST'] = 'baronbakeshop';\n"
        . "\$_SERVER['SERVER_NAME'] = 'baronbakeshop';\n"
        . "\$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';\n"
        . "\$_GET = [];\n"
        . "\$_POST = " . var_export($postData, true) . " ?: [];\n"
        . "\$_REQUEST = \$_POST;\n"
        . "require " . var_export($bootstrapPath, true) . ";\n"
        . "require_once " . var_export($moduleManagerPath, true) . ";\n"
        . "require_once " . var_export($helpersPath, true) . ";\n"
        . "require_once " . var_export($handlerPath, true) . ";\n"
        . "\$user = " . var_export($user, true) . ";\n"
        . "if (is_array(\$user)) { app()->setUser(\$user); }\n"
        . "register_shutdown_function(static function (): void {\n"
        . "    echo \"\\n__RESULT__\\n\";\n"
        . "    echo json_encode([\n"
        . "        'status' => (int) (http_response_code() ?: 200),\n"
        . "        'headers' => headers_list(),\n"
        . "    ], JSON_UNESCAPED_SLASHES);\n"
        . "});\n"
        . $handlerName . '(' . var_export($params, true) . ');' . "\n";

    file_put_contents($runnerPath, $script);
    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPath);

    $raw = implode("\n", $output);
    $parts = explode("\n__RESULT__\n", $raw, 2);
    $meta = isset($parts[1]) ? json_decode($parts[1], true) : [];
    if (!is_array($meta)) {
        $meta = [];
    }

    return [
        'status' => (int) ($meta['status'] ?? 0),
        'body' => (string) ($parts[0] ?? ''),
        'headers' => is_array($meta['headers'] ?? null) ? $meta['headers'] : [],
        'exit_code' => $exitCode,
        'raw' => $raw,
    ];
}

function dcJsonResponse(array $response): ?array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : null;
}

function dcFetchOne(PDO $db, string $sql, array $params = []): ?array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function dcFetchValue(PDO $db, string $sql, array $params = []): mixed
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function dcTestUser(PDO $db, string $username, string $role, int $storeId): array
{
    $db->prepare(
        "INSERT INTO dc_users (username, password_hash, email, full_name, role, store_id, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)"
    )->execute([
        $username,
        password_hash('secret123', PASSWORD_BCRYPT),
        $username . '@example.test',
        strtoupper($role) . ' User',
        $role,
        $storeId,
    ]);

    $userId = (int) $db->lastInsertId();
    return [
        'id' => $userId,
        'user_id' => $userId,
        'username' => $username,
        'name' => strtoupper($role) . ' User',
        'full_name' => strtoupper($role) . ' User',
        'role' => $role,
        'store_id' => $storeId,
        'source' => 'dc-cafe',
    ];
}

$suffix = 'http_' . bin2hex(random_bytes(4));
$created = [
    'order_id' => 0,
    'session_id' => 0,
    'product_id' => 0,
    'cashier_id' => 0,
    'supervisor_id' => 0,
    'addon_id' => 0,
    'ingredient_ids' => [],
];

try {
    $h->section('Fixture Setup');

    $storeId = (int) ($db->query("SELECT store_id FROM dc_stores ORDER BY store_id ASC LIMIT 1")->fetchColumn() ?: 0);
    $secondaryStoreId = (int) ($db->query("SELECT store_id FROM dc_stores ORDER BY store_id ASC LIMIT 1 OFFSET 1")->fetchColumn() ?: 0);
    $categoryId = (int) ($db->query("SELECT category_id FROM dc_categories ORDER BY category_id ASC LIMIT 1")->fetchColumn() ?: 0);
    $paymentMethodId = (int) ($db->query("SELECT payment_method_id FROM dc_payment_methods WHERE is_active = 1 ORDER BY payment_method_id ASC LIMIT 1")->fetchColumn() ?: 0);

    $h->test('Seeded store available', $storeId > 0);
    $h->test('Secondary store available for branch fallback', $secondaryStoreId > 0);
    $h->test('Seeded category available', $categoryId > 0);
    $h->test('Active payment method available', $paymentMethodId > 0);

    $cashier = dcTestUser($db, 'dccafe_cashier_' . $suffix, 'cashier', $storeId);
    $supervisor = dcTestUser($db, 'dccafe_supervisor_' . $suffix, 'supervisor', $storeId);
    $created['cashier_id'] = (int) $cashier['user_id'];
    $created['supervisor_id'] = (int) $supervisor['user_id'];

    $db->prepare(
        "INSERT INTO dc_sessions (user_id, store_id, starting_cash, shift_type, shift_start, status)
         VALUES (?, ?, 500.00, 'morning', NOW(), 'active')"
    )->execute([(int) $cashier['user_id'], $storeId]);
    $sessionId = (int) $db->lastInsertId();
    $created['session_id'] = $sessionId;
    $h->test('Active cashier session created', $sessionId > 0);

    $db->prepare(
        "INSERT INTO dc_ingredients (name, unit, cost_per_unit, current_stock, reorder_level, is_active)
         VALUES (?, 'L', 10.00, 10.00, 1.00, 1)"
    )->execute(['DC HTTP Mix ' . $suffix]);
    $mixIngredientId = (int) $db->lastInsertId();

    $db->prepare(
        "INSERT INTO dc_ingredients (name, unit, cost_per_unit, current_stock, reorder_level, is_active)
         VALUES (?, 'L', 12.00, 10.00, 1.00, 1)"
    )->execute(['DC HTTP Addon Syrup ' . $suffix]);
    $addonIngredientId = (int) $db->lastInsertId();
    $created['ingredient_ids'] = [$mixIngredientId, $addonIngredientId];

    $db->prepare(
        "INSERT INTO dc_products (store_id, category_id, name, base_price, is_variable, has_stock, current_stock, reorder_level, is_active)
         VALUES (?, ?, ?, 100.00, 1, 1, 5.00, 1.00, 1)"
    )->execute([$storeId, $categoryId, 'DC HTTP Product ' . $suffix]);
    $productId = (int) $db->lastInsertId();
    $created['product_id'] = $productId;

    // Create branch stock row for the test product
    $db->prepare(
        "INSERT INTO dc_product_store_stock (product_id, store_id, on_hand_qty, reorder_level, version)
         VALUES (?, ?, 5.00, 1.00, 1)"
    )->execute([$productId, $storeId]);
    // Also create stock row for secondary branch
    $db->prepare(
        "INSERT INTO dc_product_store_stock (product_id, store_id, on_hand_qty, reorder_level, version)
         VALUES (?, ?, 3.00, 1.00, 1)"
    )->execute([$productId, $secondaryStoreId]);

    $db->prepare(
        "INSERT INTO dc_product_ingredients (product_id, ingredient_id, quantity)
         VALUES (?, ?, 0.50)"
    )->execute([$productId, $mixIngredientId]);

    $db->prepare(
        "INSERT INTO dc_soft_serve_addons (name, price, type, is_active)
         VALUES (?, 12.50, 'topping', 1)"
    )->execute(['DC HTTP Addon ' . $suffix]);
    $addonId = (int) $db->lastInsertId();
    $created['addon_id'] = $addonId;

    $db->prepare(
        "INSERT INTO dc_addon_ingredients (addon_id, ingredient_id, quantity)
         VALUES (?, ?, 0.25)"
    )->execute([$addonId, $addonIngredientId]);

    $initialBranchStock = (float) ($db->query("SELECT on_hand_qty FROM dc_product_store_stock WHERE product_id = {$productId} AND store_id = {$storeId}")->fetchColumn() ?: 0);
    $initialMixStock = (float) ($db->query("SELECT current_stock FROM dc_ingredients WHERE ingredient_id = {$mixIngredientId}")->fetchColumn() ?: 0);
    $initialAddonStock = (float) ($db->query("SELECT current_stock FROM dc_ingredients WHERE ingredient_id = {$addonIngredientId}")->fetchColumn() ?: 0);

    $h->section('Branch Catalog Fallback');

    $branchCashier = dcTestUser($db, 'dccafe_branch_cashier_' . $suffix, 'cashier', $secondaryStoreId);
    $db->prepare(
        "INSERT INTO dc_sessions (user_id, store_id, starting_cash, shift_type, shift_start, status)
         VALUES (?, ?, 500.00, 'morning', NOW(), 'active')"
    )->execute([(int) $branchCashier['user_id'], $secondaryStoreId]);
    $branchSessionId = (int) $db->lastInsertId();

    $productsResponse = dcRunEntrypointRequest([
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/dc-cafe/api/v1/products?store_id=' . $secondaryStoreId,
        'HTTP_HOST' => 'baronbakeshop',
        'SERVER_NAME' => 'baronbakeshop',
        'HTTP_ACCEPT' => 'application/json',
    ], $branchCashier);
    $productsJson = dcJsonResponse($productsResponse);
    $branchProducts = is_array($productsJson['products'] ?? null) ? $productsJson['products'] : [];

    $h->test('Branch cashier product API returns HTTP 200', (int) $productsResponse['status'] === 200, $productsResponse['raw']);
    $h->test('Branch cashier sees fallback shared catalog', count($branchProducts) > 0, $productsResponse['body']);
    $h->test(
        'Branch fallback includes seeded test product',
        count(array_filter($branchProducts, static fn(array $p): bool => ((int) ($p['id'] ?? 0)) === $productId)) === 1,
        $productsResponse['body']
    );

    $insufficientBranchPayload = [
        'session_id' => $branchSessionId,
        'payment_method_id' => $paymentMethodId,
        'items' => [[
            'product_id' => $productId,
            'quantity' => 4,
            'unit_price' => 100.00,
        ]],
    ];
    $insufficientBranchResponse = dcRunEntrypointRequest([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/dc-cafe/api/v1/orders',
        'HTTP_HOST' => 'baronbakeshop',
        'SERVER_NAME' => 'baronbakeshop',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
    ], $branchCashier, $insufficientBranchPayload);
    $insufficientBranchJson = dcJsonResponse($insufficientBranchResponse);

    $h->test('Branch oversell is rejected before order write', (int) $insufficientBranchResponse['status'] === 400, $insufficientBranchResponse['raw']);
    $h->test(
        'Branch oversell reports insufficient stock',
        is_array($insufficientBranchJson) && str_contains((string) ($insufficientBranchJson['error'] ?? ''), 'Insufficient stock'),
        $insufficientBranchResponse['body']
    );

    $h->section('Tampered Price Rejection');

    $tamperedPayload = [
        'session_id' => $sessionId,
        'payment_method_id' => $paymentMethodId,
        'items' => [[
            'product_id' => $productId,
            'quantity' => 2,
            'unit_price' => 100.00,
            'customizations' => [
                'addons' => [[
                    'id' => $addonId,
                    'name' => 'DC HTTP Addon ' . $suffix,
                ]],
            ],
        ]],
    ];

    $tamperedResponse = dcRunEntrypointRequest([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/dc-cafe/api/v1/orders',
        'HTTP_HOST' => 'baronbakeshop',
        'SERVER_NAME' => 'baronbakeshop',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
    ], $cashier, $tamperedPayload);
    $tamperedJson = dcJsonResponse($tamperedResponse);

    $h->test('Tampered order returns HTTP 400', (int) $tamperedResponse['status'] === 400, $tamperedResponse['raw']);
    $h->test(
        'Tampered order reports price refresh error',
        is_array($tamperedJson) && str_contains((string) ($tamperedJson['error'] ?? ''), 'price changed'),
        $tamperedResponse['body']
    );
    $tamperedOrders = (int) ($db->query("SELECT COUNT(*) FROM dc_orders WHERE session_id = {$sessionId}")->fetchColumn() ?: 0);
    $h->test('Tampered order creates no order rows', $tamperedOrders === 0, (string) $tamperedOrders);

    $h->section('Create Order');

    $goodPayload = [
        'session_id' => $sessionId,
        'payment_method_id' => $paymentMethodId,
        'items' => [[
            'product_id' => $productId,
            'quantity' => 2,
            'unit_price' => 112.50,
            'customizations' => [
                'addons' => [[
                    'id' => $addonId,
                    'name' => 'DC HTTP Addon ' . $suffix,
                ]],
            ],
        ]],
    ];

    $createResponse = dcRunEntrypointRequest([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/dc-cafe/api/v1/orders',
        'HTTP_HOST' => 'baronbakeshop',
        'SERVER_NAME' => 'baronbakeshop',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
    ], $cashier, $goodPayload);
    $createJson = dcJsonResponse($createResponse);
    $created['order_id'] = (int) ($createJson['order_id'] ?? 0);

    $h->test('Order create returns HTTP 200', (int) $createResponse['status'] === 200, $createResponse['raw']);
    $h->test('Order create returns ok=true', is_array($createJson) && !empty($createJson['ok']), $createResponse['body']);
    $h->test('Order id returned', $created['order_id'] > 0, $createResponse['body']);

    $orderId = $created['order_id'];
    $order = dcFetchOne($db, "SELECT * FROM dc_orders WHERE order_id = ?", [$orderId]);
    $item = dcFetchOne($db, "SELECT * FROM dc_order_items WHERE order_id = ?", [$orderId]);

    $h->test('Created order persisted as completed', is_array($order) && ($order['status'] ?? '') === 'completed');
    $h->test('Created order total is server-priced', is_array($order) && abs((float) $order['total_amount'] - 225.00) < 0.001, json_encode($order, JSON_UNESCAPED_SLASHES));
    $h->test('Order item unit_price persisted from server pricing', is_array($item) && abs((float) $item['unit_price'] - 112.50) < 0.001, json_encode($item, JSON_UNESCAPED_SLASHES));

    $productStockAfterSale = (float) ($db->query("SELECT on_hand_qty FROM dc_product_store_stock WHERE product_id = {$productId} AND store_id = {$storeId}")->fetchColumn() ?: 0);
    $mixStockAfterSale = (float) ($db->query("SELECT current_stock FROM dc_ingredients WHERE ingredient_id = {$mixIngredientId}")->fetchColumn() ?: 0);
    $addonStockAfterSale = (float) ($db->query("SELECT current_stock FROM dc_ingredients WHERE ingredient_id = {$addonIngredientId}")->fetchColumn() ?: 0);

    $h->test('Finished-product stock deducted by quantity sold', abs($productStockAfterSale - ($initialBranchStock - 2.0)) < 0.001, "{$initialBranchStock} => {$productStockAfterSale}");
    $h->test('BOM ingredient deducted by recorded sale quantity', abs($mixStockAfterSale - ($initialMixStock - 1.0)) < 0.001, "{$initialMixStock} => {$mixStockAfterSale}");
    $h->test('Addon ingredient deducted by recorded sale quantity', abs($addonStockAfterSale - ($initialAddonStock - 0.5)) < 0.001, "{$initialAddonStock} => {$addonStockAfterSale}");

    $saleMovements = (int) ($db->query("SELECT COUNT(*) FROM dc_product_stock_movements WHERE reference_type = 'order' AND reference_id = {$orderId} AND movement_type = 'sale'")->fetchColumn() ?: 0);
    $consumptionMovements = (int) ($db->query("SELECT COUNT(*) FROM dc_inventory_movements WHERE reference_type = 'order' AND reference_id = {$orderId} AND movement_type = 'consumption'")->fetchColumn() ?: 0);
    $h->test('Product sale journal row recorded', $saleMovements === 1, (string) $saleMovements);
    $h->test('Ingredient consumption journal rows recorded', $consumptionMovements === 2, (string) $consumptionMovements);

    $primaryStockBeforeBlockedReceive = (float) ($db->query("SELECT on_hand_qty FROM dc_product_store_stock WHERE product_id = {$productId} AND store_id = {$storeId}")->fetchColumn() ?: 0);
    $blockedReceiveResponse = dcRunEntrypointRequest([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/dc-cafe/api/v1/products/receive/batch',
        'HTTP_HOST' => 'baronbakeshop',
        'SERVER_NAME' => 'baronbakeshop',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
    ], $branchCashier, [
        'items' => [[
            'product_id' => $productId,
            'quantity' => 1,
            'store_id' => $storeId,
            'notes' => 'should fail',
        ]],
    ]);
    $blockedReceiveJson = dcJsonResponse($blockedReceiveResponse);
    $primaryStockAfterBlockedReceive = (float) ($db->query("SELECT on_hand_qty FROM dc_product_store_stock WHERE product_id = {$productId} AND store_id = {$storeId}")->fetchColumn() ?: 0);

    $h->test('Cashier cannot receive stock into another branch', (int) $blockedReceiveResponse['status'] === 403, $blockedReceiveResponse['raw']);
    $h->test(
        'Cross-branch receive reports branch-ownership error',
        is_array($blockedReceiveJson) && str_contains((string) ($blockedReceiveJson['error'] ?? ''), 'another branch inventory'),
        $blockedReceiveResponse['body']
    );
    $h->test(
        'Cross-branch receive leaves primary branch stock unchanged',
        abs($primaryStockAfterBlockedReceive - $primaryStockBeforeBlockedReceive) < 0.001,
        "{$primaryStockBeforeBlockedReceive} => {$primaryStockAfterBlockedReceive}"
    );

    $branchPayload = [
        'session_id' => $branchSessionId,
        'payment_method_id' => $paymentMethodId,
        'items' => [[
            'product_id' => $productId,
            'quantity' => 1,
            'unit_price' => 112.50,
            'customizations' => [
                'addons' => [[
                    'id' => $addonId,
                    'name' => 'DC HTTP Addon ' . $suffix,
                ]],
            ],
        ]],
    ];
    $branchOrderResponse = dcRunEntrypointRequest([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/dc-cafe/api/v1/orders',
        'HTTP_HOST' => 'baronbakeshop',
        'SERVER_NAME' => 'baronbakeshop',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
    ], $branchCashier, $branchPayload);
    $branchOrderJson = dcJsonResponse($branchOrderResponse);
    $branchOrderId = (int) ($branchOrderJson['order_id'] ?? 0);

    $h->test('Branch cashier can create order against shared catalog', (int) $branchOrderResponse['status'] === 200, $branchOrderResponse['raw']);
    $h->test('Branch cashier order returns ok=true', is_array($branchOrderJson) && !empty($branchOrderJson['ok']), $branchOrderResponse['body']);

    $stockPatchResponse = dcRunDirectHandler(
        'modules/dc-cafe/handlers-products.php',
        'apiUpdateProductStock',
        ['id' => $productId],
        $supervisor,
        [
        'store_id' => $secondaryStoreId,
        'current_stock' => 5,
        'reorder_level' => 2,
        ]
    );
    $stockPatchJson = dcJsonResponse($stockPatchResponse);
    $patchedBranchStock = (float) ($db->query("SELECT on_hand_qty FROM dc_product_store_stock WHERE product_id = {$productId} AND store_id = {$secondaryStoreId}")->fetchColumn() ?: 0);
    $patchedReorderLevel = (float) ($db->query("SELECT reorder_level FROM dc_products WHERE product_id = {$productId}")->fetchColumn() ?: 0);
    $patchMovementDelta = (float) ($db->query(
        "SELECT quantity_change
         FROM dc_product_stock_movements
         WHERE product_id = {$productId}
           AND store_id = {$secondaryStoreId}
           AND movement_type = 'adjustment'
           AND reference_type = 'inventory_edit'
         ORDER BY movement_id DESC
         LIMIT 1"
    )->fetchColumn() ?: 0);

    $h->test('Branch stock patch returns HTTP 200', (int) $stockPatchResponse['status'] === 200, $stockPatchResponse['raw']);
    $h->test('Branch stock patch returns ok=true', is_array($stockPatchJson) && !empty($stockPatchJson['ok']), $stockPatchResponse['body']);
    $h->test('Branch stock patch updates only targeted branch quantity', abs($patchedBranchStock - 5.0) < 0.001, (string) $patchedBranchStock);
    $h->test('Branch stock patch records correct movement delta', abs($patchMovementDelta - 3.0) < 0.001, (string) $patchMovementDelta);
    $h->test('Branch stock patch updates reorder level without SQL bind failure', abs($patchedReorderLevel - 2.0) < 0.001, (string) $patchedReorderLevel);

    if ($branchOrderId > 0) {
        $db->prepare("UPDATE dc_products SET current_stock = ? WHERE product_id = ?")->execute([$productStockAfterSale, $productId]);
        $db->prepare("UPDATE dc_ingredients SET current_stock = ? WHERE ingredient_id = ?")->execute([$mixStockAfterSale, $mixIngredientId]);
        $db->prepare("UPDATE dc_ingredients SET current_stock = ? WHERE ingredient_id = ?")->execute([$addonStockAfterSale, $addonIngredientId]);
        $db->prepare("DELETE FROM dc_product_stock_movements WHERE reference_type = 'order' AND reference_id = ?")->execute([$branchOrderId]);
        $db->prepare("DELETE FROM dc_inventory_movements WHERE reference_type = 'order' AND reference_id = ?")->execute([$branchOrderId]);
        $db->prepare("DELETE FROM dc_order_items WHERE order_id = ?")->execute([$branchOrderId]);
        $db->prepare("DELETE FROM dc_orders WHERE order_id = ?")->execute([$branchOrderId]);
    }

    $h->section('Void Order');

    $db->prepare("UPDATE dc_product_ingredients SET quantity = 3.50 WHERE product_id = ? AND ingredient_id = ?")
        ->execute([$productId, $mixIngredientId]);
    $db->prepare("UPDATE dc_addon_ingredients SET quantity = 1.75 WHERE addon_id = ? AND ingredient_id = ?")
        ->execute([$addonId, $addonIngredientId]);

    $voidResponse = dcRunEntrypointRequest([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/dc-cafe/api/v1/orders/' . $orderId . '/void',
        'HTTP_HOST' => 'baronbakeshop',
        'SERVER_NAME' => 'baronbakeshop',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
    ], $supervisor, []);
    $voidJson = dcJsonResponse($voidResponse);

    $h->test('Void returns HTTP 200', (int) $voidResponse['status'] === 200, $voidResponse['raw']);
    $h->test('Void returns ok=true', is_array($voidJson) && !empty($voidJson['ok']), $voidResponse['body']);

    $voidedOrder = dcFetchValue($db, "SELECT status FROM dc_orders WHERE order_id = ?", [$orderId]);
    $productStockAfterVoid = (float) ($db->query("SELECT on_hand_qty FROM dc_product_store_stock WHERE product_id = {$productId} AND store_id = {$storeId}")->fetchColumn() ?: 0);
    $mixStockAfterVoid = (float) ($db->query("SELECT current_stock FROM dc_ingredients WHERE ingredient_id = {$mixIngredientId}")->fetchColumn() ?: 0);
    $addonStockAfterVoid = (float) ($db->query("SELECT current_stock FROM dc_ingredients WHERE ingredient_id = {$addonIngredientId}")->fetchColumn() ?: 0);
    $voidRestoreMovements = (int) ($db->query("SELECT COUNT(*) FROM dc_product_stock_movements WHERE reference_type = 'order' AND reference_id = {$orderId} AND movement_type = 'void_restore'")->fetchColumn() ?: 0);
    $adjustmentMovements = (int) ($db->query("SELECT COUNT(*) FROM dc_inventory_movements WHERE reference_type = 'order' AND reference_id = {$orderId} AND movement_type = 'adjustment'")->fetchColumn() ?: 0);

    $h->test('Order status updated to voided', $voidedOrder === 'voided', (string) $voidedOrder);
    $h->test('Finished-product stock restored to pre-sale balance', abs($productStockAfterVoid - $initialBranchStock) < 0.001, "{$initialBranchStock} => {$productStockAfterVoid}");
    $h->test('BOM ingredient restored from recorded journal, not current recipe', abs($mixStockAfterVoid - $initialMixStock) < 0.001, "{$initialMixStock} => {$mixStockAfterVoid}");
    $h->test('Addon ingredient restored from recorded journal, not current recipe', abs($addonStockAfterVoid - $initialAddonStock) < 0.001, "{$initialAddonStock} => {$addonStockAfterVoid}");
    $h->test('Void restore journal row recorded', $voidRestoreMovements === 1, (string) $voidRestoreMovements);
    $h->test('Ingredient adjustment rows recorded on void', $adjustmentMovements === 2, (string) $adjustmentMovements);

    $repeatVoidResponse = dcRunEntrypointRequest([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/dc-cafe/api/v1/orders/' . $orderId . '/void',
        'HTTP_HOST' => 'baronbakeshop',
        'SERVER_NAME' => 'baronbakeshop',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
    ], $supervisor, []);
    $repeatVoidJson = dcJsonResponse($repeatVoidResponse);

    $h->test('Repeat void rejected', (int) $repeatVoidResponse['status'] === 400, $repeatVoidResponse['raw']);
    $h->test(
        'Repeat void reports completed-order guard',
        is_array($repeatVoidJson) && str_contains((string) ($repeatVoidJson['error'] ?? ''), 'Only completed orders can be voided'),
        $repeatVoidResponse['body']
    );
} finally {
    if ($created['order_id'] > 0) {
        $db->prepare("DELETE FROM dc_product_stock_movements WHERE reference_type = 'order' AND reference_id = ?")->execute([$created['order_id']]);
        $db->prepare("DELETE FROM dc_inventory_movements WHERE reference_type = 'order' AND reference_id = ?")->execute([$created['order_id']]);
        $db->prepare("DELETE FROM dc_order_items WHERE order_id = ?")->execute([$created['order_id']]);
        $db->prepare("DELETE FROM dc_orders WHERE order_id = ?")->execute([$created['order_id']]);
    }

    if (!empty($created['product_id'])) {
        $db->prepare("DELETE FROM dc_product_ingredients WHERE product_id = ?")->execute([$created['product_id']]);
        $db->prepare("DELETE FROM dc_products WHERE product_id = ?")->execute([$created['product_id']]);
    }

    if (!empty($created['addon_id'])) {
        $db->prepare("DELETE FROM dc_addon_ingredients WHERE addon_id = ?")->execute([$created['addon_id']]);
        $db->prepare("DELETE FROM dc_soft_serve_addons WHERE addon_id = ?")->execute([$created['addon_id']]);
    }

    foreach ($created['ingredient_ids'] as $ingredientId) {
        $db->prepare("DELETE FROM dc_ingredients WHERE ingredient_id = ?")->execute([(int) $ingredientId]);
    }

    if (!empty($created['session_id'])) {
        $db->prepare("DELETE FROM dc_inventory_progress WHERE session_id = ?")->execute([$created['session_id']]);
        $db->prepare("DELETE FROM dc_sessions WHERE session_id = ?")->execute([$created['session_id']]);
    }

    if (!empty($branchSessionId)) {
        $db->prepare("DELETE FROM dc_inventory_progress WHERE session_id = ?")->execute([$branchSessionId]);
        $db->prepare("DELETE FROM dc_sessions WHERE session_id = ?")->execute([$branchSessionId]);
    }

    foreach ([$created['cashier_id'], $created['supervisor_id'], (int) ($branchCashier['user_id'] ?? 0)] as $userId) {
        if ($userId > 0) {
            $db->prepare("DELETE FROM dc_users WHERE user_id = ?")->execute([$userId]);
        }
    }
}

$h->done();
