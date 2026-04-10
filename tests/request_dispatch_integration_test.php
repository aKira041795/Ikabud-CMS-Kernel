<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$dispatchDb = app()->db();
$dispatchRunner = new \Ikabud\Kernel\Database\MigrationRunner($dispatchDb);
tenantSyncKernelMigrations($dispatchDb);
$dispatchRunner->migrate('ecommerce');
$dispatchRunner->migrate('wms');

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function headerValues(array $headers, string $prefix): array
{
    return array_values(array_filter($headers, static function (string $header) use ($prefix): bool {
        return stripos($header, $prefix . ':') === 0;
    }));
}

function runRequestThroughEntrypoint(array $server, ?array $user = null, ?string $hookCode = null, ?string $rawBody = null): array
{
    $runnerPath = sys_get_temp_dir() . '/ikabud-request-dispatch-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $entrypointPath = __DIR__ . '/../public/index.php';
    $serverExport = var_export($server, true);
    $userExport = var_export($user, true);
    $hook = $hookCode ?? '';
    $patchedEntrypointPath = null;

    if ($rawBody !== null) {
        $patchedEntrypointPath = __DIR__ . '/../public/ikabud-request-dispatch-entrypoint-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
        $entrypointSource = (string)file_get_contents($entrypointPath);
        $replacement = "file_get_contents('data://text/plain," . rawurlencode($rawBody) . "')";
        $entrypointSource = str_replace("file_get_contents('php://input')", $replacement, $entrypointSource);
        file_put_contents($patchedEntrypointPath, $entrypointSource);
        $entrypointPath = $patchedEntrypointPath;
    }

    $entrypoint = var_export($entrypointPath, true);

    $script = "<?php\n"
        . "foreach ({$serverExport} as \$key => \$value) { \$_SERVER[(string) \$key] = \$value; }\n"
        . "if (!isset(\$_SERVER['REQUEST_METHOD'])) { \$_SERVER['REQUEST_METHOD'] = 'GET'; }\n"
        . "if (!isset(\$_SERVER['REQUEST_URI'])) { \$_SERVER['REQUEST_URI'] = '/'; }\n"
        . "if (!isset(\$_SERVER['HTTP_HOST'])) { \$_SERVER['HTTP_HOST'] = 'applicationos.test'; }\n"
        . "\$_GET = [];\n"
        . "\$__ik_query = parse_url((string) \$_SERVER['REQUEST_URI'], PHP_URL_QUERY);\n"
        . "if (is_string(\$__ik_query) && \$__ik_query !== '') { parse_str(\$__ik_query, \$_GET); }\n"
        . "\$_REQUEST = array_merge(\$_REQUEST ?? [], \$_GET);\n"
        . "\$_SERVER['SCRIPT_NAME'] = '/public/index.php';\n"
        . "\$_SERVER['PHP_SELF'] = '/public/index.php';\n"
        . "require {$bootstrap};\n"
        . "\$user = {$userExport};\n"
        . "if (is_array(\$user)) { app()->setUser(\$user); }\n"
        . $hook . "\n"
        . "register_shutdown_function(static function (): void { echo \"\\n__CONTEXT__\\n\"; echo json_encode(kernelCurrentRequestDispatchContext() ?? [], JSON_UNESCAPED_SLASHES); echo \"\\n__HEADERS__\\n\"; echo json_encode(headers_list(), JSON_UNESCAPED_SLASHES); });\n"
        . "require {$entrypoint};\n";

    file_put_contents($runnerPath, $script);
    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPath);
    if (is_string($patchedEntrypointPath) && $patchedEntrypointPath !== '') {
        @unlink($patchedEntrypointPath);
    }

    $stdout = implode("\n", $output);
    $parts = explode("\n__CONTEXT__\n", $stdout, 2);
    $contextParts = isset($parts[1]) ? explode("\n__HEADERS__\n", $parts[1], 2) : [];
    $context = isset($contextParts[0]) ? json_decode($contextParts[0], true) : [];
    if (!is_array($context)) {
        $context = [];
    }

    $headers = isset($contextParts[1]) ? json_decode($contextParts[1], true) : [];
    if (!is_array($headers)) {
        $headers = [];
    }

    return [
        'exit_code' => $exitCode,
        'body' => $parts[0] ?? '',
        'context' => $context,
        'headers' => $headers,
        'raw' => $stdout,
    ];
}

function seedCustomerOrderTimelineFixture(string $suffix): array
{
    $db = app()->db();
    $customerId = 910000 + random_int(100, 999);
    $orderNumber = 'EC-DISP-' . strtoupper($suffix);
    $token = bin2hex(random_bytes(16));

    $statement = $db->prepare(
        "INSERT INTO ec_orders (order_number, customer_id, guest_email, guest_name, source, status, payment_status, subtotal, discount_amount, tax_amount, shipping_amount, total, currency, coupon_code, customer_note, confirmation_token, placed_by_user_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'web', 'delivered', 'paid', 100.00, 0.00, 0.00, 0.00, 100.00, 'PHP', NULL, '', ?, NULL, NOW(), NOW())"
    );
    $statement->execute([$orderNumber, $customerId, 'dispatch-' . $suffix . '@example.com', 'Dispatch Fixture', $token]);
    $orderId = (int)$db->lastInsertId();

    $statement = $db->prepare(
        'INSERT INTO ec_order_items (order_id, product_id, variant_id, product_title, sku, unit_price, qty, line_total, variant_label) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, NULL)'
    );
    $statement->execute([$orderId, 1, 'Dispatch Fixture Product', 'DISP-' . strtoupper($suffix), 100.00, 1, 100.00]);

    $statement = $db->prepare('INSERT INTO ec_order_meta (order_id, meta_key, meta_value) VALUES (?, ?, ?)');
    foreach ([
        ['billing_first_name', 'Dispatch'],
        ['billing_last_name', 'Fixture'],
        ['billing_email', 'dispatch-' . $suffix . '@example.com'],
        ['billing_address_line1', '123 Dispatch St'],
        ['billing_city', 'Manila'],
        ['billing_state', 'NCR'],
        ['billing_postal_code', '1000'],
        ['billing_country', 'PH'],
        ['shipping_first_name', 'Dispatch'],
        ['shipping_last_name', 'Fixture'],
        ['shipping_address_line1', '123 Dispatch St'],
        ['shipping_city', 'Manila'],
        ['shipping_state', 'NCR'],
        ['shipping_postal_code', '1000'],
        ['shipping_country', 'PH'],
    ] as [$metaKey, $metaValue]) {
        $statement->execute([$orderId, $metaKey, $metaValue]);
    }

    $statement = $db->prepare(
        'INSERT INTO ec_order_status_history (order_id, status, source, note, actor_user_id, history_key, meta, created_at) VALUES (?, ?, ?, ?, NULL, ?, NULL, DATE_ADD(NOW(), INTERVAL ? SECOND))'
    );
    foreach ([
        ['pending', 'checkout', 'Order placed.'],
        ['processing', 'wms_bridge', 'WMS marked the order as picked.'],
        ['shipped', 'wms_bridge', 'WMS marked the order as dispatched.'],
        ['delivered', 'wms_bridge', 'WMS marked the order as delivered.'],
    ] as $index => [$status, $source, $note]) {
        $statement->execute([$orderId, $status, $source, $note, 'dispatch:' . $suffix . ':' . $status, $index]);
    }

    return [
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'customer_id' => $customerId,
    ];
}

function cleanupCustomerOrderTimelineFixture(array $fixture): void
{
    $orderId = (int)($fixture['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $db = app()->db();
    foreach ([
        'DELETE FROM ec_order_status_history WHERE order_id = ?',
        'DELETE FROM ec_order_licenses WHERE order_id = ?',
        'DELETE FROM ec_order_items WHERE order_id = ?',
        'DELETE FROM ec_order_meta WHERE order_id = ?',
        'DELETE FROM ec_payment_transactions WHERE order_id = ?',
        'DELETE FROM ec_orders WHERE id = ?',
    ] as $sql) {
        $statement = $db->prepare($sql);
        $statement->execute([$orderId]);
    }
}

function seedWmsDiagnosticsFixture(string $suffix): array
{
    $db = app()->db();
    $warehouseCode = 'WDG-' . strtoupper($suffix);
    $locationCode = 'WDGL-' . strtoupper($suffix);
    $sku = 'WDG-SKU-' . strtoupper($suffix);
    $externalReference = 'EC-DIAG-' . strtoupper($suffix);
    $ecommerceOrderId = 920000 + random_int(100, 999);

    $statement = $db->prepare('INSERT INTO wms_warehouses (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())');
    $statement->execute([$warehouseCode, 'Diagnostics Warehouse ' . strtoupper($suffix)]);
    $warehouseId = (int)$db->lastInsertId();

    $statement = $db->prepare('INSERT INTO wms_locations (warehouse_id, parent_id, code, name, type, sort_order, is_active, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, 0, 1, NOW(), NOW())');
    $statement->execute([$warehouseId, $locationCode, 'Diagnostics Bin ' . strtoupper($suffix), 'bin']);
    $locationId = (int)$db->lastInsertId();

    $statement = $db->prepare("INSERT INTO wms_products (sku, barcode, name, description, unit, product_type, is_batch_tracked, is_active, created_at, updated_at) VALUES (?, NULL, ?, '', 'pcs', 'physical', 0, 1, NOW(), NOW())");
    $statement->execute([$sku, 'Diagnostics Product ' . strtoupper($suffix)]);
    $productId = (int)$db->lastInsertId();

    $orderNumber = 'WMS-DIAG-' . strtoupper($suffix);
    $meta = json_encode([
        'source_module' => 'ecommerce',
        'ecommerce_order_id' => $ecommerceOrderId,
        'ecommerce_order_number' => $externalReference,
        'customer_email' => 'diag-' . $suffix . '@example.com',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $statement = $db->prepare('INSERT INTO wms_orders (order_number, external_reference, customer_name, warehouse_id, status, priority, ordered_at, notes, meta, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 100, NOW(), ?, ?, NULL, NOW(), NOW())');
    $statement->execute([$orderNumber, $externalReference, 'Diagnostics Customer', $warehouseId, 'dispatched', 'Seeded diagnostics fixture', $meta]);
    $orderId = (int)$db->lastInsertId();

    $statement = $db->prepare('INSERT INTO wms_order_items (order_id, product_id, location_id, batch_id, qty_ordered, qty_reserved, qty_picked, notes, meta, created_at, updated_at) VALUES (?, ?, ?, NULL, 2.0000, 2.0000, 0.0000, ?, NULL, NOW(), NOW())');
    $statement->execute([$orderId, $productId, $locationId, 'Seeded diagnostics item']);

    $statement = $db->prepare('INSERT INTO wms_movements (movement_type, reference_type, reference_id, product_id, warehouse_id, location_id, batch_id, qty, qty_before, qty_after, unit_cost, notes, actor_user_id, meta, created_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, NULL, ?, NULL, NULL, NOW())');
    $statement->execute(['reserved', 'order', $ecommerceOrderId, $productId, $warehouseId, $locationId, 2.0000, 10.0000, 8.0000, 'Seeded ecommerce reservation trace']);

    return [
        'warehouse_id' => $warehouseId,
        'location_id' => $locationId,
        'product_id' => $productId,
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'external_reference' => $externalReference,
        'ecommerce_order_id' => $ecommerceOrderId,
        'sku' => $sku,
    ];
}

function cleanupWmsDiagnosticsFixture(array $fixture): void
{
    $db = app()->db();

    if (!empty($fixture['order_id'])) {
        $statement = $db->prepare('DELETE FROM wms_order_items WHERE order_id = ?');
        $statement->execute([(int)$fixture['order_id']]);

        $statement = $db->prepare('DELETE FROM wms_orders WHERE id = ?');
        $statement->execute([(int)$fixture['order_id']]);
    }

    if (!empty($fixture['product_id'])) {
        $statement = $db->prepare('DELETE FROM wms_movements WHERE product_id = ?');
        $statement->execute([(int)$fixture['product_id']]);

        $statement = $db->prepare('DELETE FROM wms_products WHERE id = ?');
        $statement->execute([(int)$fixture['product_id']]);
    }

    if (!empty($fixture['location_id'])) {
        $statement = $db->prepare('DELETE FROM wms_locations WHERE id = ?');
        $statement->execute([(int)$fixture['location_id']]);
    }

    if (!empty($fixture['warehouse_id'])) {
        $statement = $db->prepare('DELETE FROM wms_warehouses WHERE id = ?');
        $statement->execute([(int)$fixture['warehouse_id']]);
    }
}

echo "\n=== REQUEST DISPATCH ENTRYPOINT ===\n";

$intercepted = runRequestThroughEntrypoint(
    [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/request-dispatch-test',
        'HTTP_HOST' => 'applicationos.test',
    ],
    null,
    <<<'PHP'
app()->hooks()->on('kernel.request.before_dispatch', static function (array $context): array {
    if (kernelRequestDispatchPath($context) !== '/request-dispatch-test') {
        return $context;
    }

    echo 'intercepted';
    $context['handled'] = true;
    return $context;
}, -5000);
PHP
);
t('public entrypoint allows pre-dispatch hook short-circuit', ($intercepted['body'] ?? '') === 'intercepted', $intercepted['raw']);

$rootRedirect = runRequestThroughEntrypoint([
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/',
    'HTTP_HOST' => 'applicationos.test',
]);
t(
    'public entrypoint redirects unauthenticated root requests to login',
    ($rootRedirect['context']['redirect'] ?? '') === '/login',
    json_encode($rootRedirect['context'])
);

$loginRedirect = runRequestThroughEntrypoint(
    [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/login',
        'HTTP_HOST' => 'applicationos.test',
    ],
    [
        'id' => 1,
        'username' => 'root',
        'name' => 'Root User',
        'role' => 'superadmin',
        'source' => 'kernel',
    ]
);
t(
    'public entrypoint redirects authenticated kernel superadmin away from login',
    ($loginRedirect['context']['redirect'] ?? '') === '/superadmin/settings',
    json_encode($loginRedirect['context'])
);

$authenticatedRootFallback = runRequestThroughEntrypoint(
    [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/',
        'HTTP_HOST' => 'applicationos.test',
    ],
    [
        'id' => 7,
        'username' => 'auditor',
        'name' => 'Auditor User',
        'role' => 'auditor',
        'source' => 'kernel',
    ],
    <<<'PHP'
app()->hooks()->on('kernel.home_url', static function ($value) {
    return false;
}, 999999);
PHP
);
t(
    'public entrypoint renders authenticated root fallback without undefined-variable warnings',
    !str_contains($authenticatedRootFallback['raw'] ?? '', 'Undefined variable $homeRole')
        && ($authenticatedRootFallback['context']['redirect'] ?? '') === ''
        && str_contains($authenticatedRootFallback['body'] ?? '', 'Kernel OS status overview'),
    $authenticatedRootFallback['raw']
);

$cmsLoginRedirect = runRequestThroughEntrypoint(
    [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/cms/login',
        'HTTP_HOST' => 'applicationos.test',
    ],
    [
        'id' => 11,
        'username' => 'cmsadmin',
        'name' => 'CMS Admin',
        'role' => 'administrator',
        'source' => 'cms',
    ]
);
t(
    'public entrypoint redirects authenticated CMS users away from CMS login',
    ($cmsLoginRedirect['context']['redirect'] ?? '') === '/cms/admin',
    json_encode($cmsLoginRedirect['context'])
);

$kernelIntegrationsPage = runRequestThroughEntrypoint(
    [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/kernel/integrations',
        'HTTP_HOST' => 'applicationos.test',
    ],
    [
        'id' => 1,
        'username' => 'root',
        'name' => 'Root User',
        'role' => 'superadmin',
        'source' => 'kernel',
    ]
);
t(
    'kernel integrations page renders for kernel superadmin without fatal errors',
    ($kernelIntegrationsPage['exit_code'] ?? 1) === 0
        && str_contains($kernelIntegrationsPage['body'] ?? '', 'Integration Bridge')
        && !str_contains($kernelIntegrationsPage['body'] ?? '', 'Page not found'),
    $kernelIntegrationsPage['raw']
);
t(
    'kernel integrations page includes a rendered CSRF token for JS requests',
    preg_match("/X-CSRF-Token': '[a-f0-9]{64}'/", $kernelIntegrationsPage['body'] ?? '') === 1,
    $kernelIntegrationsPage['body'] ?? ''
);

$kernelIntegrationsDeleteNoCsrf = runRequestThroughEntrypoint(
    [
        'REQUEST_METHOD' => 'DELETE',
        'REQUEST_URI' => '/api/v1/kernel/integrations?id=0',
        'HTTP_HOST' => 'applicationos.test',
    ],
    [
        'id' => 1,
        'username' => 'root',
        'name' => 'Root User',
        'role' => 'superadmin',
        'source' => 'kernel',
    ]
);
t(
    'kernel integrations API rejects mutating requests without CSRF token',
    str_contains($kernelIntegrationsDeleteNoCsrf['body'] ?? '', 'Invalid CSRF token'),
    $kernelIntegrationsDeleteNoCsrf['raw']
);

$kernelIntegrationsDeleteWithCsrf = runRequestThroughEntrypoint(
    [
        'REQUEST_METHOD' => 'DELETE',
        'REQUEST_URI' => '/api/v1/kernel/integrations?id=0',
        'HTTP_HOST' => 'applicationos.test',
    ],
    [
        'id' => 1,
        'username' => 'root',
        'name' => 'Root User',
        'role' => 'superadmin',
        'source' => 'kernel',
    ],
    <<<'PHP'
$_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken();
PHP
);
t(
    'kernel integrations API accepts mutating requests with valid CSRF token',
    str_contains($kernelIntegrationsDeleteWithCsrf['body'] ?? '', '"ok":true'),
    $kernelIntegrationsDeleteWithCsrf['raw']
);

$kernelBridgeSuffix = bin2hex(random_bytes(4));
$kernelBridgeName = 'request_dispatch_bridge_' . $kernelBridgeSuffix;

try {
    $kernelIntegrationsValidate = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/kernel/integrations',
            'HTTP_HOST' => 'applicationos.test',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ],
        [
            'id' => 1,
            'username' => 'root',
            'name' => 'Root User',
            'role' => 'superadmin',
            'source' => 'kernel',
        ],
        <<<'PHP'
$_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken();
PHP,
        json_encode([
            '_action' => 'validate',
            'name' => $kernelBridgeName,
            'trigger_event' => 'ecommerce.order.created',
            'target_capability' => 'wms.stock.reserve@1',
            'mapping_json' => [
                'reference_type' => 'order',
                'reference_id' => '{{order.id}}',
                'items' => '{{order.items}}',
                'idempotency_key' => '{{idempotency_key}}',
            ],
        ], JSON_UNESCAPED_SLASHES)
    );
    $kernelIntegrationsValidatePayload = json_decode((string)($kernelIntegrationsValidate['body'] ?? ''), true);
    t(
        'kernel integrations API validates a bridge draft via live POST body',
        is_array($kernelIntegrationsValidatePayload)
            && ($kernelIntegrationsValidatePayload['ok'] ?? false) === true
            && ($kernelIntegrationsValidatePayload['resolved_capability'] ?? '') === 'wms.stock.reserve@1'
            && ($kernelIntegrationsValidatePayload['version_lock'] ?? '') === 'wms.stock.reserve@1',
        $kernelIntegrationsValidate['raw']
    );

    $kernelIntegrationsValidateInvalid = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/kernel/integrations',
            'HTTP_HOST' => 'applicationos.test',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ],
        [
            'id' => 1,
            'username' => 'root',
            'name' => 'Root User',
            'role' => 'superadmin',
            'source' => 'kernel',
        ],
        <<<'PHP'
$_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken();
PHP,
        json_encode([
            '_action' => 'validate',
            'name' => $kernelBridgeName . '_invalid',
            'trigger_event' => 'ecommerce.order.created',
            'target_capability' => 'wms.stock.reserve@1',
            'mapping_json' => [
                'reference_id' => '{{order.unknown_id}}',
            ],
        ], JSON_UNESCAPED_SLASHES)
    );
    $kernelIntegrationsValidateInvalidPayload = json_decode((string)($kernelIntegrationsValidateInvalid['body'] ?? ''), true);
    t(
        'kernel integrations API rejects invalid draft mappings via live POST body',
        is_array($kernelIntegrationsValidateInvalidPayload)
            && ($kernelIntegrationsValidateInvalidPayload['ok'] ?? true) === false
            && str_contains(implode(' ', $kernelIntegrationsValidateInvalidPayload['errors'] ?? []), 'Unknown mapping variables'),
        $kernelIntegrationsValidateInvalid['raw']
    );

    $kernelIntegrationsCreate = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/kernel/integrations',
            'HTTP_HOST' => 'applicationos.test',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ],
        [
            'id' => 1,
            'username' => 'root',
            'name' => 'Root User',
            'role' => 'superadmin',
            'source' => 'kernel',
        ],
        <<<'PHP'
$_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken();
PHP,
        json_encode([
            'name' => $kernelBridgeName,
            'trigger_event' => 'ecommerce.order.paid',
            'target_capability' => 'wms.stock.reserve',
            'mapping_json' => [
                'reference_type' => 'payment',
                'reference_id' => '{{order_id}}',
            ],
            'is_active' => 1,
        ], JSON_UNESCAPED_SLASHES)
    );
    $kernelIntegrationsCreatePayload = json_decode((string)($kernelIntegrationsCreate['body'] ?? ''), true);
    $createdKernelBridgeId = (int)($kernelIntegrationsCreatePayload['id'] ?? 0);
    t(
        'kernel integrations API creates a bridge via live POST body',
        is_array($kernelIntegrationsCreatePayload)
            && ($kernelIntegrationsCreatePayload['ok'] ?? false) === true
            && $createdKernelBridgeId > 0,
        $kernelIntegrationsCreate['raw']
    );

    $kernelIntegrationsCreateDuplicate = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/kernel/integrations',
            'HTTP_HOST' => 'applicationos.test',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ],
        [
            'id' => 1,
            'username' => 'root',
            'name' => 'Root User',
            'role' => 'superadmin',
            'source' => 'kernel',
        ],
        <<<'PHP'
$_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken();
PHP,
        json_encode([
            'name' => $kernelBridgeName . '_duplicate',
            'trigger_event' => 'ecommerce.order.paid',
            'target_capability' => 'wms.stock.reserve@1',
            'mapping_json' => [
                'reference_type' => 'payment',
                'reference_id' => '{{order_id}}',
            ],
            'is_active' => 1,
        ], JSON_UNESCAPED_SLASHES)
    );
    $kernelIntegrationsCreateDuplicatePayload = json_decode((string)($kernelIntegrationsCreateDuplicate['body'] ?? ''), true);
    t(
        'kernel integrations API treats alias and resolved version duplicates as the same bridge',
        is_array($kernelIntegrationsCreateDuplicatePayload)
            && ($kernelIntegrationsCreateDuplicatePayload['ok'] ?? true) === false
            && (int)($kernelIntegrationsCreateDuplicatePayload['id'] ?? 0) === $createdKernelBridgeId,
        $kernelIntegrationsCreateDuplicate['raw']
    );
} finally {
    $cleanupBridgeRows = $dispatchDb->prepare('SELECT id FROM kernel_integrations WHERE name LIKE ?');
    $cleanupBridgeRows->execute([$kernelBridgeName . '%']);
    $cleanupBridgeIds = array_map('intval', $cleanupBridgeRows->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($cleanupBridgeIds !== []) {
        $cleanupPlaceholders = implode(', ', array_fill(0, count($cleanupBridgeIds), '?'));
        $dispatchDb->prepare('DELETE FROM kernel_integration_logs WHERE integration_id IN (' . $cleanupPlaceholders . ')')->execute($cleanupBridgeIds);
    }
    $dispatchDb->prepare('DELETE FROM kernel_integrations WHERE name LIKE ?')->execute([$kernelBridgeName . '%']);
}

$managedModeBridgeNames = [
    'ecommerce_wms_reserve',
    'ecommerce_wms_order_create',
    'ecommerce_wms_release',
    'ecommerce_wms_cancel_order',
    'wms_ecommerce_processing',
    'wms_ecommerce_shipped',
    'wms_ecommerce_delivered',
    'wms_ecommerce_manual_payment_complete',
    'wms_ecommerce_product_created',
    'wms_ecommerce_product_updated',
    'ecommerce_wms_product_created',
    'ecommerce_wms_product_updated',
    'WMS ↔ Ecommerce Order Sync',
    'WMS ↔ Ecommerce Order Cancel',
    'WMS ↔ Ecommerce Stock Alert',
    'WMS → Ecommerce Product Update',
    'Ecommerce → WMS Product Update',
];

try {
    $managedModePlaceholders = implode(', ', array_fill(0, count($managedModeBridgeNames), '?'));
    $managedModeIdsStmt = $dispatchDb->prepare('SELECT id FROM kernel_integrations WHERE name IN (' . $managedModePlaceholders . ')');
    $managedModeIdsStmt->execute($managedModeBridgeNames);
    $managedModeIds = array_map('intval', $managedModeIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($managedModeIds !== []) {
        $cleanupPlaceholders = implode(', ', array_fill(0, count($managedModeIds), '?'));
        $dispatchDb->prepare('DELETE FROM kernel_integration_logs WHERE integration_id IN (' . $cleanupPlaceholders . ')')->execute($managedModeIds);
    }
    $dispatchDb->prepare('DELETE FROM kernel_integrations WHERE name IN (' . $managedModePlaceholders . ')')->execute($managedModeBridgeNames);

    $applyWmsMode = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/kernel/integrations',
            'HTTP_HOST' => 'applicationos.test',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ],
        [
            'id' => 1,
            'username' => 'root',
            'name' => 'Root User',
            'role' => 'superadmin',
            'source' => 'kernel',
        ],
        <<<'PHP'
$_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken();
PHP,
        json_encode([
            '_action' => 'apply_mode',
            'mode' => 'wms_authoritative_products',
        ], JSON_UNESCAPED_SLASHES)
    );
    $applyWmsModePayload = json_decode((string)($applyWmsMode['body'] ?? ''), true);
    t(
        'kernel integrations API applies the WMS-authoritative mode',
        is_array($applyWmsModePayload)
            && ($applyWmsModePayload['ok'] ?? false) === true
            && ($applyWmsModePayload['mode'] ?? '') === 'wms_authoritative_products',
        $applyWmsMode['raw']
    );

    $modeRowsStmt = $dispatchDb->prepare('SELECT name, target_capability, integration_mode FROM kernel_integrations WHERE name IN (' . $managedModePlaceholders . ') ORDER BY name ASC');
    $modeRowsStmt->execute($managedModeBridgeNames);
    $modeRows = $modeRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $modeNames = array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $modeRows);

    t(
        'WMS-authoritative mode provisions canonical fulfillment and WMS-to-ecommerce product bridges',
        !array_diff([
            'ecommerce_wms_reserve',
            'ecommerce_wms_order_create',
            'ecommerce_wms_release',
            'ecommerce_wms_cancel_order',
            'wms_ecommerce_processing',
            'wms_ecommerce_shipped',
            'wms_ecommerce_delivered',
            'wms_ecommerce_manual_payment_complete',
            'wms_ecommerce_product_created',
            'wms_ecommerce_product_updated',
        ], $modeNames),
        json_encode($modeRows, JSON_UNESCAPED_SLASHES)
    );
    t(
        'WMS-authoritative mode does not recreate unsupported legacy stock-alert bridges',
        !in_array('WMS ↔ Ecommerce Stock Alert', $modeNames, true)
            && !in_array('WMS → Ecommerce Product Update', $modeNames, true),
        json_encode($modeRows, JSON_UNESCAPED_SLASHES)
    );
    t(
        'WMS-authoritative mode tags managed product bridges with the selected integration mode',
        count(array_filter($modeRows, static fn(array $row): bool => str_starts_with((string)($row['name'] ?? ''), 'wms_ecommerce_product_') && (string)($row['integration_mode'] ?? '') === 'wms_authoritative_products')) === 2,
        json_encode($modeRows, JSON_UNESCAPED_SLASHES)
    );

    $applyEcommerceMode = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/kernel/integrations',
            'HTTP_HOST' => 'applicationos.test',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ],
        [
            'id' => 1,
            'username' => 'root',
            'name' => 'Root User',
            'role' => 'superadmin',
            'source' => 'kernel',
        ],
        <<<'PHP'
$_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken();
PHP,
        json_encode([
            '_action' => 'apply_mode',
            'mode' => 'ecommerce_authoritative_products',
        ], JSON_UNESCAPED_SLASHES)
    );
    $applyEcommerceModePayload = json_decode((string)($applyEcommerceMode['body'] ?? ''), true);
    t(
        'kernel integrations API applies the ecommerce-authoritative mode',
        is_array($applyEcommerceModePayload)
            && ($applyEcommerceModePayload['ok'] ?? false) === true
            && ($applyEcommerceModePayload['mode'] ?? '') === 'ecommerce_authoritative_products',
        $applyEcommerceMode['raw']
    );

    $modeRowsStmt->execute($managedModeBridgeNames);
    $modeRows = $modeRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $modeNames = array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $modeRows);
    t(
        'ecommerce-authoritative mode swaps in ecommerce-to-WMS product bridges',
        in_array('ecommerce_wms_product_created', $modeNames, true)
            && in_array('ecommerce_wms_product_updated', $modeNames, true)
            && !in_array('wms_ecommerce_product_created', $modeNames, true)
            && !in_array('wms_ecommerce_product_updated', $modeNames, true),
        json_encode($modeRows, JSON_UNESCAPED_SLASHES)
    );

    $applyDecoupledMode = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/kernel/integrations',
            'HTTP_HOST' => 'applicationos.test',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ],
        [
            'id' => 1,
            'username' => 'root',
            'name' => 'Root User',
            'role' => 'superadmin',
            'source' => 'kernel',
        ],
        <<<'PHP'
$_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken();
PHP,
        json_encode([
            '_action' => 'apply_mode',
            'mode' => 'decoupled',
        ], JSON_UNESCAPED_SLASHES)
    );
    $applyDecoupledModePayload = json_decode((string)($applyDecoupledMode['body'] ?? ''), true);
    t(
        'kernel integrations API applies the decoupled mode',
        is_array($applyDecoupledModePayload)
            && ($applyDecoupledModePayload['ok'] ?? false) === true
            && ($applyDecoupledModePayload['mode'] ?? '') === 'decoupled',
        $applyDecoupledMode['raw']
    );

    $modeRowsStmt->execute($managedModeBridgeNames);
    $modeRows = $modeRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    t(
        'decoupled mode removes managed WMS and ecommerce bridge presets',
        $modeRows === [],
        json_encode($modeRows, JSON_UNESCAPED_SLASHES)
    );
} finally {
    $managedModeIdsStmt = $dispatchDb->prepare('SELECT id FROM kernel_integrations WHERE name IN (' . implode(', ', array_fill(0, count($managedModeBridgeNames), '?')) . ')');
    $managedModeIdsStmt->execute($managedModeBridgeNames);
    $managedModeIds = array_map('intval', $managedModeIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($managedModeIds !== []) {
        $cleanupPlaceholders = implode(', ', array_fill(0, count($managedModeIds), '?'));
        $dispatchDb->prepare('DELETE FROM kernel_integration_logs WHERE integration_id IN (' . $cleanupPlaceholders . ')')->execute($managedModeIds);
    }
    $dispatchDb->prepare('DELETE FROM kernel_integrations WHERE name IN (' . implode(', ', array_fill(0, count($managedModeBridgeNames), '?')) . ')')->execute($managedModeBridgeNames);
}

$checkoutSurvivalSuffix = bin2hex(random_bytes(4));
$checkoutSurvivalBridgeName = 'request_dispatch_checkout_survival_' . $checkoutSurvivalSuffix;
$checkoutOriginalHost = $_SERVER['HTTP_HOST'] ?? null;
$checkoutCustomerId = 920000 + random_int(100, 999);
$checkoutProductId = 930000 + random_int(100, 999);
$checkoutOrderId = 0;
$checkoutCartId = 0;
$wmsManifestBeforeDisable = discoverModules()['wms'] ?? [];
$wmsWasEnabledBeforeDisable = !empty($wmsManifestBeforeDisable['_enabled']);

try {
    $dispatchDb->prepare(
        'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock, integration_mode, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, 1, ?, ?, ?, NOW(), NOW())'
    )->execute([
        $checkoutSurvivalBridgeName,
        'ecommerce.order.created',
        'wms.stock.reserve@1',
        json_encode([
            'reference_type' => 'order',
            'reference_id' => '{{order.id}}',
            'items' => '{{order.items}}',
            'idempotency_key' => '{{idempotency_key}}',
        ], JSON_UNESCAPED_SLASHES),
        'eventbus',
        'wms.stock.reserve@1',
        null,
    ]);

    $dispatchDb->prepare(
        'INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)'
    )->execute([$checkoutProductId, '_is_digital', '1']);

    $dispatchDb->prepare('INSERT INTO ec_carts (user_id, created_at, updated_at) VALUES (?, NOW(), NOW())')
        ->execute([$checkoutCustomerId]);
    $checkoutCartId = (int)$dispatchDb->lastInsertId();
    $dispatchDb->prepare(
        'INSERT INTO ec_cart_items (cart_id, product_id, variant_id, qty, price_snapshot, currency, product_title, sku, options_json, created_at, updated_at) '
        . 'VALUES (?, ?, NULL, ?, ?, ?, ?, ?, NULL, NOW(), NOW())'
    )->execute([$checkoutCartId, $checkoutProductId, 1, 1499.00, 'PHP', 'Checkout Survival Fixture', 'CHK-' . strtoupper($checkoutSurvivalSuffix)]);

    unset($_SERVER['HTTP_HOST']);
    disableModule('wms');
    if ($checkoutOriginalHost !== null && $checkoutOriginalHost !== '') {
        $_SERVER['HTTP_HOST'] = $checkoutOriginalHost;
    }

    $checkoutWithoutWms = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/ecommerce/checkout',
            'HTTP_HOST' => 'applicationos.test',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ],
        [
            'id' => $checkoutCustomerId,
            'username' => 'checkout.fixture',
            'name' => 'Checkout Fixture',
            'role' => 'customer',
            'source' => 'cms',
        ],
        <<<'PHP'
$_SERVER['HTTP_X_CSRF_TOKEN'] = app()->csrfToken();
$_POST = [
    'billing' => [
        'first_name' => 'Checkout',
        'last_name' => 'Fixture',
        'email' => 'checkout-fixture@example.com',
        'address_line1' => '123 Checkout Street',
        'city' => 'Manila',
        'country' => 'PH',
    ],
    'shipping' => [
        'first_name' => 'Checkout',
        'last_name' => 'Fixture',
        'address_line1' => '123 Checkout Street',
        'city' => 'Manila',
        'country' => 'PH',
    ],
];
$_REQUEST = array_merge($_REQUEST ?? [], $_POST);
PHP
    );
    $checkoutWithoutWmsPayload = json_decode((string)($checkoutWithoutWms['body'] ?? ''), true);
    $checkoutOrderId = (int)($checkoutWithoutWmsPayload['order_id'] ?? 0);

    t(
        'ecommerce checkout still creates an order when WMS is disabled but stale WMS bridges remain active',
        is_array($checkoutWithoutWmsPayload)
            && ($checkoutWithoutWmsPayload['ok'] ?? false) === true
            && $checkoutOrderId > 0
            && ($checkoutWithoutWms['exit_code'] ?? 1) === 0,
        $checkoutWithoutWms['raw']
    );

    $createdOrderStmt = $dispatchDb->prepare('SELECT id, status, customer_id FROM ec_orders WHERE id = ? LIMIT 1');
    $createdOrderStmt->execute([$checkoutOrderId]);
    $createdOrder = $createdOrderStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    t(
        'ecommerce checkout persists the order while WMS is disabled',
        is_array($createdOrder)
            && (int)($createdOrder['id'] ?? 0) === $checkoutOrderId
            && (string)($createdOrder['status'] ?? '') === 'pending'
            && (int)($createdOrder['customer_id'] ?? 0) === $checkoutCustomerId,
        json_encode($createdOrder, JSON_UNESCAPED_SLASHES)
    );
} finally {
    unset($_SERVER['HTTP_HOST']);
    if ($wmsWasEnabledBeforeDisable) {
        enableModule('wms');
    } else {
        disableModule('wms');
    }
    if ($checkoutOriginalHost !== null && $checkoutOriginalHost !== '') {
        $_SERVER['HTTP_HOST'] = $checkoutOriginalHost;
    }

    if ($checkoutOrderId > 0) {
        foreach ([
            'DELETE FROM ec_payment_transactions WHERE order_id = ?',
            'DELETE FROM ec_order_status_history WHERE order_id = ?',
            'DELETE FROM ec_order_meta WHERE order_id = ?',
            'DELETE FROM ec_order_items WHERE order_id = ?',
            'DELETE FROM ec_orders WHERE id = ?',
        ] as $sql) {
            $dispatchDb->prepare($sql)->execute([$checkoutOrderId]);
        }
    }

    if ($checkoutCartId > 0) {
        $dispatchDb->prepare('DELETE FROM ec_cart_items WHERE cart_id = ?')->execute([$checkoutCartId]);
        $dispatchDb->prepare('DELETE FROM ec_carts WHERE id = ?')->execute([$checkoutCartId]);
    } else {
        $dispatchDb->prepare('DELETE FROM ec_cart_items WHERE cart_id IN (SELECT id FROM ec_carts WHERE user_id = ?)')->execute([$checkoutCustomerId]);
        $dispatchDb->prepare('DELETE FROM ec_carts WHERE user_id = ?')->execute([$checkoutCustomerId]);
    }

    $dispatchDb->prepare('DELETE FROM cms_content_meta WHERE content_id = ? AND meta_key = ?')->execute([$checkoutProductId, '_is_digital']);

    $checkoutBridgeIdsStmt = $dispatchDb->prepare('SELECT id FROM kernel_integrations WHERE name = ?');
    $checkoutBridgeIdsStmt->execute([$checkoutSurvivalBridgeName]);
    $checkoutBridgeIds = array_map('intval', $checkoutBridgeIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($checkoutBridgeIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($checkoutBridgeIds), '?'));
        $dispatchDb->prepare('DELETE FROM kernel_integration_logs WHERE integration_id IN (' . $placeholders . ')')->execute($checkoutBridgeIds);
    }
    $dispatchDb->prepare('DELETE FROM kernel_integrations WHERE name = ?')->execute([$checkoutSurvivalBridgeName]);
}

$customerFixture = seedCustomerOrderTimelineFixture(bin2hex(random_bytes(4)));

try {
    $customerOrdersPage = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/ecommerce/my-orders',
            'HTTP_HOST' => 'applicationos.test',
        ],
        [
            'id' => $customerFixture['customer_id'],
            'username' => 'customer.fixture',
            'name' => 'Customer Fixture',
            'role' => 'customer',
            'source' => 'cms',
        ]
    );
    t(
        'customer my-orders page renders fulfilled bridge-backed order status',
        ($customerOrdersPage['exit_code'] ?? 1) === 0
            && str_contains($customerOrdersPage['body'] ?? '', 'My Orders')
            && str_contains($customerOrdersPage['body'] ?? '', $customerFixture['order_number'])
            && str_contains($customerOrdersPage['body'] ?? '', 'Completed'),
        $customerOrdersPage['raw']
    );

    file_put_contents(STORAGE_PATH . '/logs/app.log', '');

    $customerOrderDetail = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/ecommerce/my-orders/' . $customerFixture['order_id'],
            'HTTP_HOST' => 'applicationos.test',
        ],
        [
            'id' => $customerFixture['customer_id'],
            'username' => 'customer.fixture',
            'name' => 'Customer Fixture',
            'role' => 'customer',
            'source' => 'cms',
        ]
    );
    t(
        'customer order detail renders progress timeline, payment status, and WMS bridge notes',
        ($customerOrderDetail['exit_code'] ?? 1) === 0
            && str_contains($customerOrderDetail['body'] ?? '', 'Order Progress')
            && str_contains($customerOrderDetail['body'] ?? '', 'Payment Status')
            && str_contains($customerOrderDetail['body'] ?? '', 'paid')
            && str_contains($customerOrderDetail['body'] ?? '', 'WMS marked the order as delivered.')
            && str_contains($customerOrderDetail['body'] ?? '', 'delivered'),
        $customerOrderDetail['raw']
    );

    $customerOrderDetailLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    t(
        'customer order detail render does not log ecommerce render contract mismatches',
        !str_contains($customerOrderDetailLog, 'ecommerce.render_context.contract_mismatch'),
        $customerOrderDetailLog
    );
} finally {
    cleanupCustomerOrderTimelineFixture($customerFixture);
}

$wmsDiagnosticsFixture = seedWmsDiagnosticsFixture(bin2hex(random_bytes(4)));

try {
    $wmsDiagnosticsPage = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/wms/diagnostics?external_reference=' . rawurlencode($wmsDiagnosticsFixture['external_reference']),
            'HTTP_HOST' => 'applicationos.test',
        ],
        [
            'id' => 51,
            'username' => 'wms.admin',
            'name' => 'WMS Admin',
            'role' => 'admin',
            'source' => 'wms',
        ]
    );
    t(
        'wms diagnostics route renders seeded ecommerce-linked proof data',
        ($wmsDiagnosticsPage['exit_code'] ?? 1) === 0
            && str_contains($wmsDiagnosticsPage['body'] ?? '', 'Bridge-Linked WMS Orders')
            && str_contains($wmsDiagnosticsPage['body'] ?? '', $wmsDiagnosticsFixture['order_number'])
            && str_contains($wmsDiagnosticsPage['body'] ?? '', $wmsDiagnosticsFixture['sku'])
            && str_contains($wmsDiagnosticsPage['body'] ?? '', $wmsDiagnosticsFixture['external_reference']),
        $wmsDiagnosticsPage['raw']
    );

    $missingReference = 'EC-DIAG-MISSING-' . strtoupper(bin2hex(random_bytes(3)));
    $wmsDiagnosticsMissPage = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/wms/diagnostics?external_reference=' . rawurlencode($missingReference),
            'HTTP_HOST' => 'applicationos.test',
        ],
        [
            'id' => 51,
            'username' => 'wms.admin',
            'name' => 'WMS Admin',
            'role' => 'admin',
            'source' => 'wms',
        ]
    );
    t(
        'wms diagnostics route does not leak unfiltered reservation or trace rows on missing external reference',
        ($wmsDiagnosticsMissPage['exit_code'] ?? 1) === 0
            && str_contains($wmsDiagnosticsMissPage['body'] ?? '', 'No bridge-linked WMS orders matched the current filters.')
            && str_contains($wmsDiagnosticsMissPage['body'] ?? '', 'No ecommerce reservation movements matched the current filters.')
            && str_contains($wmsDiagnosticsMissPage['body'] ?? '', 'No movement trace rows matched the current filters.')
            && !str_contains($wmsDiagnosticsMissPage['body'] ?? '', $wmsDiagnosticsFixture['order_number']),
        $wmsDiagnosticsMissPage['raw']
    );

    $wmsReservationsApi = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/wms/diagnostics/reservations?external_reference=' . rawurlencode($missingReference),
            'HTTP_HOST' => 'applicationos.test',
            'HTTP_ACCEPT' => 'application/json',
        ],
        [
            'id' => 51,
            'username' => 'wms.admin',
            'name' => 'WMS Admin',
            'role' => 'admin',
            'source' => 'wms',
        ]
    );
    $wmsReservationsPayload = json_decode((string)($wmsReservationsApi['body'] ?? ''), true);
    t(
        'wms diagnostics reservations api returns an empty list for missing external reference',
        is_array($wmsReservationsPayload)
            && ($wmsReservationsPayload['ok'] ?? false) === true
            && ($wmsReservationsPayload['reservations'] ?? []) === [],
        $wmsReservationsApi['raw']
    );

    $wmsTraceApi = runRequestThroughEntrypoint(
        [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/wms/diagnostics/trace?external_reference=' . rawurlencode($missingReference),
            'HTTP_HOST' => 'applicationos.test',
            'HTTP_ACCEPT' => 'application/json',
        ],
        [
            'id' => 51,
            'username' => 'wms.admin',
            'name' => 'WMS Admin',
            'role' => 'admin',
            'source' => 'wms',
        ]
    );
    $wmsTracePayload = json_decode((string)($wmsTraceApi['body'] ?? ''), true);
    t(
        'wms diagnostics trace api returns an empty list for missing external reference',
        is_array($wmsTracePayload)
            && ($wmsTracePayload['ok'] ?? false) === true
            && ($wmsTracePayload['trace'] ?? []) === [],
        $wmsTraceApi['raw']
    );
} finally {
    cleanupWmsDiagnosticsFixture($wmsDiagnosticsFixture);
}

$entrypointSource = (string)file_get_contents(__DIR__ . '/../public/index.php');
$securityHeadersCallPos = strpos($entrypointSource, 'SecurityHeaders())->apply();');
$tenantMaintenancePos = strpos($entrypointSource, "IK_TENANT_SUSPENDED");

t(
    'public entrypoint delegates response security headers to SecurityHeaders',
    $securityHeadersCallPos !== false,
    $entrypointSource
);
t(
    'public entrypoint applies security headers before maintenance exits',
    $securityHeadersCallPos !== false
        && $tenantMaintenancePos !== false
        && $securityHeadersCallPos < $tenantMaintenancePos,
    json_encode([$securityHeadersCallPos, $tenantMaintenancePos])
);

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);