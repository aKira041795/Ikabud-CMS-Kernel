<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/checkout';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/EventTriggers.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';
require_once __DIR__ . '/../modules/wms/helpers.php';

$pass = 0;
$fail = 0;
$skip = 0;
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

function s(string $label, string $detail = ''): void
{
    global $skip;
    $skip++;
    echo "  - {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function bridgeTestSuffix(): string
{
    return substr(bin2hex(random_bytes(4)), 0, 8);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== INTEGRATION BRIDGE ECOMMERCE → WMS ===\n";

if (!function_exists('module') || !module('wms') || !module('ecommerce')) {
    s('Required modules unavailable', 'Skipping ecommerce→wms bridge regression');
    exit(0);
}

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
tenantSyncKernelMigrations($db);
$runner->migrate('wms');
$runner->migrate('ecommerce');
loadModuleRoutes([
    'GET' => [],
    'POST' => [],
    'PUT' => [],
    'DELETE' => [],
]);

$suffix = bridgeTestSuffix();
$cleanup = [
    'reserve_integration_id' => 0,
    'order_create_integration_id' => 0,
    'release_integration_id' => 0,
    'cancel_order_integration_id' => 0,
    'processing_integration_id' => 0,
    'shipped_integration_id' => 0,
    'delivered_integration_id' => 0,
    'paid_integration_id' => 0,
    'order_id' => 0,
    'second_order_id' => 0,
    'sku_bridge_wms_order_id' => 0,
    'module_context_wms_order_id' => 0,
    'wms_order_id' => 0,
    'second_wms_order_id' => 0,
    'location_id' => 0,
    'warehouse_id' => 0,
    'product_id' => 0,
];

try {
    $db->prepare('DELETE FROM kernel_integration_logs WHERE integration_id IN (SELECT id FROM kernel_integrations WHERE name IN (?, ?))')->execute([
        'test_bridge_reserve_' . $suffix,
        'test_bridge_order_create_' . $suffix,
    ]);
    $bridgeNames = [
        'test_bridge_reserve_' . $suffix,
        'test_bridge_order_create_' . $suffix,
        'test_bridge_release_' . $suffix,
        'test_bridge_cancel_order_' . $suffix,
        'test_bridge_processing_' . $suffix,
        'test_bridge_shipped_' . $suffix,
        'test_bridge_delivered_' . $suffix,
        'test_bridge_paid_' . $suffix,
    ];
    $placeholders = implode(', ', array_fill(0, count($bridgeNames), '?'));
    $db->prepare('DELETE FROM kernel_integration_logs WHERE integration_id IN (SELECT id FROM kernel_integrations WHERE name IN (' . $placeholders . '))')->execute($bridgeNames);
    $db->prepare('DELETE FROM kernel_integrations WHERE name IN (' . $placeholders . ')')->execute($bridgeNames);

    $warehouseCode = 'TBW-' . strtoupper($suffix);
    $locationCode = 'TBL-' . strtoupper($suffix);
    $sku = 'TBP-' . strtoupper($suffix);

    $db->prepare('INSERT INTO wms_warehouses (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute([$warehouseCode, 'Test Bridge Warehouse ' . $suffix]);
    $cleanup['warehouse_id'] = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO wms_locations (warehouse_id, parent_id, code, name, type, capacity, capacity_unit, sort_order, is_active, created_at, updated_at) '
        . 'VALUES (?, NULL, ?, ?, ?, NULL, NULL, 0, 1, NOW(), NOW())'
    )->execute([$cleanup['warehouse_id'], $locationCode, 'Test Bridge Location ' . $suffix, 'bin']);
    $cleanup['location_id'] = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO wms_products (sku, barcode, name, unit, product_type, is_batch_tracked, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 0, 1, NOW(), NOW())'
    )->execute([$sku, $sku, 'Bridge Test Product ' . $suffix, 'pcs', 'physical']);
    $cleanup['product_id'] = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO wms_stocks (product_id, warehouse_id, location_id, batch_id, qty_on_hand, qty_reserved, qty_staged, updated_at) '
        . 'VALUES (?, ?, ?, NULL, ?, 0, 0, NOW())'
    )->execute([$cleanup['product_id'], $cleanup['warehouse_id'], $cleanup['location_id'], 10]);

    $mapping = [
        'reference_type' => 'order',
        'reference_id' => '{{order.id}}',
        'items' => '{{order.items}}',
        'idempotency_key' => '{{idempotency_key}}',
        'actor_user_id' => '{{actor_user_id}}',
    ];

    $db->prepare(
        'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock) '
        . 'VALUES (?, ?, ?, ?, 1, ?, ?)'
    )->execute([
        'test_bridge_reserve_' . $suffix,
        'ecommerce.order.created',
        'wms.stock.reserve@1',
        json_encode($mapping, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'eventbus',
        'wms.stock.reserve@1',
    ]);
    $cleanup['reserve_integration_id'] = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock) '
        . 'VALUES (?, ?, ?, ?, 1, ?, ?)'
    )->execute([
        'test_bridge_order_create_' . $suffix,
        'ecommerce.order.created',
        'wms.order.create@1',
        json_encode([
            'order_number' => '{{order.order_number}}',
            'external_reference' => '{{order.order_number}}',
            'customer_name' => '{{order.customer_name}}',
            'warehouse_id' => '{{order.warehouse_id}}',
            'ordered_at' => '{{order.created_at}}',
            'items' => '{{order.items}}',
            'meta' => [
                'source_module' => 'ecommerce',
                'ecommerce_order_id' => '{{order.id}}',
                'ecommerce_order_number' => '{{order.order_number}}',
                'customer_email' => '{{order.customer_email}}',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'eventbus',
        'wms.order.create@1',
    ]);
    $cleanup['order_create_integration_id'] = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock) '
        . 'VALUES (?, ?, ?, ?, 1, ?, ?)'
    )->execute([
        'test_bridge_release_' . $suffix,
        'ecommerce.order.cancelled',
        'wms.stock.release@1',
        json_encode($mapping, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'eventbus',
        'wms.stock.release@1',
    ]);
    $cleanup['release_integration_id'] = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock) '
        . 'VALUES (?, ?, ?, ?, 1, ?, ?)'
    )->execute([
        'test_bridge_cancel_order_' . $suffix,
        'ecommerce.order.cancelled',
        'wms.order.cancel@1',
        json_encode([
            'external_reference' => '{{order.order_number}}',
            'actor_user_id' => '{{actor_user_id}}',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'eventbus',
        'wms.order.cancel@1',
    ]);
    $cleanup['cancel_order_integration_id'] = (int)$db->lastInsertId();

    foreach ([
        ['id' => 'processing_integration_id', 'name' => 'test_bridge_processing_' . $suffix, 'event' => 'wms.order.picked', 'status' => 'processing', 'history_key' => 'wms:{{wms_order_id}}:picked', 'note' => 'WMS marked the order as picked.'],
        ['id' => 'shipped_integration_id', 'name' => 'test_bridge_shipped_' . $suffix, 'event' => 'wms.order.dispatched', 'status' => 'shipped', 'history_key' => 'wms:{{wms_order_id}}:dispatched', 'note' => 'WMS marked the order as dispatched.'],
        ['id' => 'delivered_integration_id', 'name' => 'test_bridge_delivered_' . $suffix, 'event' => 'wms.order.delivered', 'status' => 'delivered', 'history_key' => 'wms:{{wms_order_id}}:delivered', 'note' => 'WMS marked the order as delivered.'],
    ] as $statusBridge) {
        $db->prepare(
            'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock) '
            . 'VALUES (?, ?, ?, ?, 1, ?, ?)'
        )->execute([
            $statusBridge['name'],
            $statusBridge['event'],
            'ecommerce.orders.status.sync@1',
            json_encode([
                'order_id' => '{{ecommerce_order_id}}',
                'external_reference' => '{{external_reference}}',
                'status' => $statusBridge['status'],
                'source' => 'wms_bridge',
                'event' => $statusBridge['event'],
                'wms_order_id' => '{{wms_order_id}}',
                'history_key' => $statusBridge['history_key'],
                'note' => $statusBridge['note'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'eventbus',
            'ecommerce.orders.status.sync@1',
        ]);
        $cleanup[$statusBridge['id']] = (int)$db->lastInsertId();
    }

    $db->prepare(
        'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock) '
        . 'VALUES (?, ?, ?, ?, 1, ?, ?)'
    )->execute([
        'test_bridge_paid_' . $suffix,
        'wms.order.payment_collected',
        'ecommerce.orders.payment.sync@1',
        json_encode([
            'order_id' => '{{ecommerce_order_id}}',
            'external_reference' => '{{external_reference}}',
            'payment_status' => 'paid',
            'only_if_gateway' => 'manual',
            'only_if_manual_payment_mode' => 'pay_on_delivery',
            'source' => 'wms_bridge',
            'event' => 'wms.order.payment_collected',
            'wms_order_id' => '{{wms_order_id}}',
            'collected_at' => '{{collected_at}}',
            'payment_method' => '{{payment_method}}',
            'history_key' => 'wms:{{wms_order_id}}:paid',
            'note' => 'WMS collected pay-on-delivery payment.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'eventbus',
        'ecommerce.orders.payment.sync@1',
    ]);
    $cleanup['paid_integration_id'] = (int)$db->lastInsertId();

    $reserveResults = [];
    $releaseResults = [];
    app()->events()->listen('integration.result.wms.stock.reserve_v1', static function (array $payload) use (&$reserveResults): void {
        $reserveResults[] = $payload;
    }, 110, 'tests');
    app()->events()->listen('integration.result.wms.stock.release_v1', static function (array $payload) use (&$releaseResults): void {
        $releaseResults[] = $payload;
    }, 110, 'tests');

    $skuBridgeOrderId = 900000 + random_int(1000, 9999);
    $skuBridgeOrderNumber = 'BRIDGE-SKU-' . strtoupper($suffix);
    $skuBridgeProductId = $cleanup['product_id'] + 500000;
    $skuBridgePayload = [
        'order_id' => $skuBridgeOrderId,
        'order_number' => $skuBridgeOrderNumber,
        'customer_email' => 'bridge-sku-' . $suffix . '@example.com',
        'total' => 100.00,
        'source' => 'web',
        'actor_user_id' => null,
        'idempotency_key' => 'order_' . $skuBridgeOrderId . '_created',
        'order' => [
            'id' => $skuBridgeOrderId,
            'order_number' => $skuBridgeOrderNumber,
            'customer_name' => 'Bridge SKU Tester',
            'customer_email' => 'bridge-sku-' . $suffix . '@example.com',
            'warehouse_id' => $cleanup['warehouse_id'],
            'created_at' => date('Y-m-d H:i:s'),
            'items' => [[
                'product_id' => $skuBridgeProductId,
                'sku' => $sku,
                'qty' => 2,
                'warehouse_id' => $cleanup['warehouse_id'],
            ]],
        ],
    ];
    app()->events()->fire('ecommerce.order.created', $skuBridgePayload, 'ecommerce');

    $stockStmt = $db->prepare('SELECT qty_reserved FROM wms_stocks WHERE product_id = ? AND warehouse_id = ? AND location_id = ? LIMIT 1');
    $stockStmt->execute([$cleanup['product_id'], $cleanup['warehouse_id'], $cleanup['location_id']]);
    $qtyReservedFromSkuBridge = (float)($stockStmt->fetchColumn() ?: 0);
    t('SKU bridge fallback reserves stock using WMS product SKU', abs($qtyReservedFromSkuBridge - 2.0) < 0.0001, (string)$qtyReservedFromSkuBridge);

    $movementStmt = $db->prepare('SELECT COUNT(*) FROM wms_movements WHERE product_id = ? AND reference_type = ? AND reference_id = ? AND movement_type = ?');
    $movementStmt->execute([$cleanup['product_id'], 'order', $skuBridgeOrderId, 'reserved']);
    $skuBridgeMovementCount = (int)($movementStmt->fetchColumn() ?: 0);
    t('SKU bridge fallback creates reserve movement for resolved WMS product', $skuBridgeMovementCount === 1, (string)$skuBridgeMovementCount);

    $wmsOrderStmt = $db->prepare('SELECT * FROM wms_orders WHERE external_reference = ? ORDER BY id DESC LIMIT 1');
    $wmsOrderStmt->execute([$skuBridgeOrderNumber]);
    $skuBridgeWmsOrder = $wmsOrderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $cleanup['sku_bridge_wms_order_id'] = (int)($skuBridgeWmsOrder['id'] ?? 0);
    t('SKU bridge fallback creates WMS order from mismatched ecommerce product id', $cleanup['sku_bridge_wms_order_id'] > 0, json_encode($skuBridgeWmsOrder));

    $wmsOrderItemStmt = $db->prepare('SELECT product_id FROM wms_order_items WHERE order_id = ? ORDER BY id ASC LIMIT 1');
    $wmsOrderItemStmt->execute([$cleanup['sku_bridge_wms_order_id']]);
    $skuBridgeResolvedProductId = (int)($wmsOrderItemStmt->fetchColumn() ?: 0);
    t('SKU bridge fallback resolves WMS order item product id by SKU', $skuBridgeResolvedProductId === $cleanup['product_id'], (string)$skuBridgeResolvedProductId);

    $moduleContextOrderId = $skuBridgeOrderId + 1;
    $moduleContextOrderNumber = $skuBridgeOrderNumber . '-CTX';
    $moduleContextPayload = $skuBridgePayload;
    $moduleContextPayload['order_id'] = $moduleContextOrderId;
    $moduleContextPayload['order_number'] = $moduleContextOrderNumber;
    $moduleContextPayload['idempotency_key'] = 'order_' . $moduleContextOrderId . '_created';
    $moduleContextPayload['order']['id'] = $moduleContextOrderId;
    $moduleContextPayload['order']['order_number'] = $moduleContextOrderNumber;
    $moduleContextPayload['order']['customer_email'] = 'bridge-module-' . $suffix . '@example.com';
    $moduleContextPayload['customer_email'] = 'bridge-module-' . $suffix . '@example.com';

    moduleWithContext('ecommerce', static function () use ($moduleContextPayload): void {
        \Ikabud\Kernel\IntegrationBridge::handle($moduleContextPayload, 'ecommerce.order.created');
    });

    $moduleContextWmsOrderStmt = $db->prepare('SELECT * FROM wms_orders WHERE external_reference = ? ORDER BY id DESC LIMIT 1');
    $moduleContextWmsOrderStmt->execute([$moduleContextOrderNumber]);
    $moduleContextWmsOrder = $moduleContextWmsOrderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $cleanup['module_context_wms_order_id'] = (int)($moduleContextWmsOrder['id'] ?? 0);
    t('Inline bridge works inside ecommerce module DB guard', $cleanup['module_context_wms_order_id'] > 0, json_encode($moduleContextWmsOrder));

    $moduleContextLogStmt = $db->prepare(
        'SELECT COUNT(*) FROM kernel_integration_logs WHERE payload_in LIKE ? AND status = ?'
    );
    $moduleContextLogStmt->execute(['%' . $moduleContextOrderNumber . '%', 'success']);
    $moduleContextLogCount = (int)($moduleContextLogStmt->fetchColumn() ?: 0);
    t('Inline bridge writes integration logs inside ecommerce module DB guard', $moduleContextLogCount > 0, (string)$moduleContextLogCount);

    app()->events()->fire('ecommerce.order.cancelled', [
        'order_id' => $moduleContextOrderId,
        'order_number' => $moduleContextOrderNumber,
        'customer_email' => 'bridge-module-' . $suffix . '@example.com',
        'source' => 'web',
        'actor_user_id' => null,
        'idempotency_key' => 'order_' . $moduleContextOrderId . '_cancelled',
        'order' => [
            'id' => $moduleContextOrderId,
            'order_number' => $moduleContextOrderNumber,
            'warehouse_id' => $cleanup['warehouse_id'],
            'items' => [[
                'product_id' => $skuBridgeProductId,
                'sku' => $sku,
                'qty' => 2,
                'warehouse_id' => $cleanup['warehouse_id'],
                'location_id' => $cleanup['location_id'],
            ]],
        ],
    ], 'ecommerce');

    app()->events()->fire('ecommerce.order.cancelled', [
        'order_id' => $skuBridgeOrderId,
        'order_number' => $skuBridgeOrderNumber,
        'customer_email' => 'bridge-sku-' . $suffix . '@example.com',
        'source' => 'web',
        'actor_user_id' => null,
        'idempotency_key' => 'order_' . $skuBridgeOrderId . '_cancelled',
        'order' => [
            'id' => $skuBridgeOrderId,
            'order_number' => $skuBridgeOrderNumber,
            'warehouse_id' => $cleanup['warehouse_id'],
            'items' => [[
                'product_id' => $skuBridgeProductId,
                'sku' => $sku,
                'qty' => 2,
                'warehouse_id' => $cleanup['warehouse_id'],
                'location_id' => $cleanup['location_id'],
            ]],
        ],
    ], 'ecommerce');

    $stockStmt->execute([$cleanup['product_id'], $cleanup['warehouse_id'], $cleanup['location_id']]);
    $qtyReservedAfterSkuBridgeCancel = (float)($stockStmt->fetchColumn() ?: 0);
    t('SKU bridge fallback release resolves the same WMS product by SKU', abs($qtyReservedAfterSkuBridgeCancel - 0.0) < 0.0001, (string)$qtyReservedAfterSkuBridgeCancel);

    $wmsOrderStmt->execute([$skuBridgeOrderNumber]);
    $skuBridgeCancelledOrder = $wmsOrderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    t('SKU bridge fallback cancellation closes the resolved WMS order', (string)($skuBridgeCancelledOrder['status'] ?? '') === 'cancelled', (string)($skuBridgeCancelledOrder['status'] ?? ''));

    $order = ecOrderCreate([
        'cart_items' => [[
            'product_id' => $cleanup['product_id'],
            'variant_id' => null,
            'product_title' => 'Bridge Test Product',
            'sku' => $sku,
            'price_snapshot' => 100.00,
            'qty' => 2,
            'variant_label' => null,
            'warehouse_id' => $cleanup['warehouse_id'],
        ]],
        'subtotal' => 100.00,
        'discount_amount' => 0.00,
        'tax_amount' => 0.00,
        'shipping_amount' => 0.00,
        'total' => 100.00,
        'currency' => 'PHP',
        'coupon_code' => null,
        'shipping_rate_id' => null,
        'source' => 'web',
        'billing' => [
            'first_name' => 'Bridge',
            'last_name' => 'Tester',
            'email' => 'bridge-' . $suffix . '@example.com',
            'address_line1' => '123 Test St',
            'address_line2' => '',
            'city' => 'Manila',
            'state' => 'NCR',
            'postal_code' => '1000',
            'country' => 'PH',
            'phone' => '',
        ],
        'shipping' => [],
        'guest_email' => 'bridge-' . $suffix . '@example.com',
        'guest_name' => 'Bridge Tester',
        'customer_note' => '',
        'placed_by_user_id' => null,
    ]);
    $cleanup['order_id'] = (int)($order['order_id'] ?? 0);

    t('ecOrderCreate returns order id', $cleanup['order_id'] > 0, (string)$cleanup['order_id']);

    $logStmt = $db->prepare('SELECT * FROM kernel_integration_logs WHERE integration_id = ? ORDER BY id DESC LIMIT 1');
    $logStmt->execute([$cleanup['reserve_integration_id']]);
    $log = $logStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    t('Bridge execution log exists', is_array($log));
    t('Bridge execution log status success', (string)($log['status'] ?? '') === 'success', (string)($log['status'] ?? '')); 
    t('Bridge log stores request_id', trim((string)($log['request_id'] ?? '')) !== '');
    t('Bridge log stores correlation_id', trim((string)($log['correlation_id'] ?? '')) !== '');
    t('Bridge log stores duration_ms', isset($log['duration_ms']) && (int)$log['duration_ms'] >= 0, (string)($log['duration_ms'] ?? 'null'));

    $stockStmt->execute([$cleanup['product_id'], $cleanup['warehouse_id'], $cleanup['location_id']]);
    $qtyReserved = (float)($stockStmt->fetchColumn() ?: 0);
    t('WMS reserved stock updated', abs($qtyReserved - 2.0) < 0.0001, (string)$qtyReserved);

    $movementStmt->execute([$cleanup['product_id'], 'order', $cleanup['order_id'], 'reserved']);
    $movementCount = (int)($movementStmt->fetchColumn() ?: 0);
    t('Single reserve movement created', $movementCount === 1, (string)$movementCount);

    $wmsOrderStmt->execute([(string)($order['order_number'] ?? '')]);
    $wmsOrder = $wmsOrderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $cleanup['wms_order_id'] = (int)($wmsOrder['id'] ?? 0);
    t('WMS order created from checkout bridge', $cleanup['wms_order_id'] > 0, json_encode($wmsOrder));
    t('WMS order stores ecommerce external reference', (string)($wmsOrder['external_reference'] ?? '') === (string)($order['order_number'] ?? ''), (string)($wmsOrder['external_reference'] ?? ''));

    t('Reserve integration result event emitted', !empty($reserveResults));
    if (!empty($reserveResults)) {
        $lastResult = end($reserveResults);
        $resultPayload = is_array($lastResult['result'] ?? null) ? $lastResult['result'] : [];
        t('Reserve integration result payload reports ok', !empty($resultPayload['ok']), json_encode($resultPayload));
    }

    $replayPayload = [
        'order_id' => $cleanup['order_id'],
        'order_number' => (string)($order['order_number'] ?? ''),
        'customer_email' => 'bridge-' . $suffix . '@example.com',
        'total' => 100.00,
        'source' => 'web',
        'actor_user_id' => null,
        'idempotency_key' => 'order_' . $cleanup['order_id'] . '_created',
        'order' => [
            'id' => $cleanup['order_id'],
            'warehouse_id' => $cleanup['warehouse_id'],
            'items' => [[
                'product_id' => $cleanup['product_id'],
                'qty' => 2,
                'warehouse_id' => $cleanup['warehouse_id'],
            ]],
        ],
    ];
    app()->events()->fire('ecommerce.order.created', $replayPayload, 'ecommerce');

    $stockStmt->execute([$cleanup['product_id'], $cleanup['warehouse_id'], $cleanup['location_id']]);
    $qtyReservedAfterReplay = (float)($stockStmt->fetchColumn() ?: 0);
    t('Replay does not double-reserve stock', abs($qtyReservedAfterReplay - 2.0) < 0.0001, (string)$qtyReservedAfterReplay);

    $movementStmt->execute([$cleanup['product_id'], 'order', $cleanup['order_id'], 'reserved']);
    $movementCountAfterReplay = (int)($movementStmt->fetchColumn() ?: 0);
    t('Replay does not create duplicate movement', $movementCountAfterReplay === 1, (string)$movementCountAfterReplay);

    $cancelled = ecOrderUpdateStatus($cleanup['order_id'], 'cancelled');
    t('ecOrderUpdateStatus cancels the order', $cancelled === true);

    $wmsOrderStmt->execute([(string)($order['order_number'] ?? '')]);
    $cancelledWmsOrder = $wmsOrderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    t('Ecommerce cancellation also cancels linked WMS order', (string)($cancelledWmsOrder['status'] ?? '') === 'cancelled', (string)($cancelledWmsOrder['status'] ?? ''));

    $stockStmt->execute([$cleanup['product_id'], $cleanup['warehouse_id'], $cleanup['location_id']]);
    $qtyReservedAfterCancel = (float)($stockStmt->fetchColumn() ?: 0);
    t('WMS release bridge clears reserved stock', abs($qtyReservedAfterCancel - 0.0) < 0.0001, (string)$qtyReservedAfterCancel);

    $releaseMovementStmt = $db->prepare('SELECT COUNT(*) FROM wms_movements WHERE product_id = ? AND reference_type = ? AND reference_id = ? AND movement_type = ?');
    $releaseMovementStmt->execute([$cleanup['product_id'], 'order', $cleanup['order_id'], 'unreserved']);
    $releaseMovementCount = (int)($releaseMovementStmt->fetchColumn() ?: 0);
    t('Single release movement created', $releaseMovementCount === 1, (string)$releaseMovementCount);

    t('Release integration result event emitted', !empty($releaseResults));
    if (!empty($releaseResults)) {
        $lastReleaseResult = end($releaseResults);
        $releasePayload = is_array($lastReleaseResult['result'] ?? null) ? $lastReleaseResult['result'] : [];
        t('Release integration result payload reports ok', !empty($releasePayload['ok']), json_encode($releasePayload));
    }

    $cancelReplayPayload = [
        'order_id' => $cleanup['order_id'],
        'order_number' => (string)($order['order_number'] ?? ''),
        'customer_email' => 'bridge-' . $suffix . '@example.com',
        'source' => 'web',
        'actor_user_id' => null,
        'idempotency_key' => 'order_' . $cleanup['order_id'] . '_cancelled',
        'order' => [
            'id' => $cleanup['order_id'],
            'warehouse_id' => $cleanup['warehouse_id'],
            'items' => [[
                'product_id' => $cleanup['product_id'],
                'qty' => 2,
                'warehouse_id' => $cleanup['warehouse_id'],
                'location_id' => $cleanup['location_id'],
            ]],
        ],
    ];
    app()->events()->fire('ecommerce.order.cancelled', $cancelReplayPayload, 'ecommerce');

    $stockStmt->execute([$cleanup['product_id'], $cleanup['warehouse_id'], $cleanup['location_id']]);
    $qtyReservedAfterCancelReplay = (float)($stockStmt->fetchColumn() ?: 0);
    t('Cancel replay does not over-release stock', abs($qtyReservedAfterCancelReplay - 0.0) < 0.0001, (string)$qtyReservedAfterCancelReplay);

    $releaseMovementStmt->execute([$cleanup['product_id'], 'order', $cleanup['order_id'], 'unreserved']);
    $releaseMovementCountAfterReplay = (int)($releaseMovementStmt->fetchColumn() ?: 0);
    t('Cancel replay does not create duplicate release movement', $releaseMovementCountAfterReplay === 1, (string)$releaseMovementCountAfterReplay);

    $secondOrder = ecOrderCreate([
        'cart_items' => [[
            'product_id' => $cleanup['product_id'],
            'variant_id' => null,
            'product_title' => 'Bridge Test Product',
            'sku' => $sku,
            'price_snapshot' => 100.00,
            'qty' => 2,
            'variant_label' => null,
            'warehouse_id' => $cleanup['warehouse_id'],
        ]],
        'subtotal' => 100.00,
        'discount_amount' => 0.00,
        'tax_amount' => 0.00,
        'shipping_amount' => 0.00,
        'total' => 100.00,
        'currency' => 'PHP',
        'coupon_code' => null,
        'shipping_rate_id' => null,
        'source' => 'web',
        'billing' => [
            'first_name' => 'Bridge',
            'last_name' => 'Second',
            'email' => 'bridge-second-' . $suffix . '@example.com',
            'address_line1' => '123 Test St',
            'address_line2' => '',
            'city' => 'Manila',
            'state' => 'NCR',
            'postal_code' => '1000',
            'country' => 'PH',
            'phone' => '',
        ],
        'shipping' => [],
        'guest_email' => 'bridge-second-' . $suffix . '@example.com',
        'guest_name' => 'Bridge Second',
        'customer_note' => '',
        'placed_by_user_id' => null,
    ]);
    $cleanup['second_order_id'] = (int)($secondOrder['order_id'] ?? 0);
    t('Second ecOrderCreate returns order id', $cleanup['second_order_id'] > 0, (string)$cleanup['second_order_id']);

    $wmsOrderStmt->execute([(string)($secondOrder['order_number'] ?? '')]);
    $secondWmsOrder = $wmsOrderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $cleanup['second_wms_order_id'] = (int)($secondWmsOrder['id'] ?? 0);
    t('Second checkout creates linked WMS order', $cleanup['second_wms_order_id'] > 0, json_encode($secondWmsOrder));

    wmsOrderGeneratePickList($cleanup['second_wms_order_id']);
    $stockStmt->execute([$cleanup['product_id'], $cleanup['warehouse_id'], $cleanup['location_id']]);
    $qtyReservedAfterPickList = (float)($stockStmt->fetchColumn() ?: 0);
    t('Pick-list generation reuses checkout reservation without double-reserving', abs($qtyReservedAfterPickList - 2.0) < 0.0001, (string)$qtyReservedAfterPickList);

    wmsOrderPick($cleanup['second_wms_order_id']);
    $secondOrderState = ecOrderGet($cleanup['second_order_id']);
    t('WMS pick syncs ecommerce order to processing', (string)($secondOrderState['status'] ?? '') === 'processing', (string)($secondOrderState['status'] ?? ''));
    $stockStmt->execute([$cleanup['product_id'], $cleanup['warehouse_id'], $cleanup['location_id']]);
    $qtyReservedAfterPick = (float)($stockStmt->fetchColumn() ?: 0);
    t('WMS pick clears checkout reservation via stock movement projection', abs($qtyReservedAfterPick - 0.0) < 0.0001, (string)$qtyReservedAfterPick);

    wmsOrderDispatch($cleanup['second_wms_order_id']);
    $secondOrderState = ecOrderGet($cleanup['second_order_id']);
    t('WMS dispatch syncs ecommerce order to shipped', (string)($secondOrderState['status'] ?? '') === 'shipped', (string)($secondOrderState['status'] ?? ''));

    wmsOrderDeliver($cleanup['second_wms_order_id']);
    $secondOrderState = ecOrderGet($cleanup['second_order_id']);
    t('WMS delivery syncs ecommerce order to delivered', (string)($secondOrderState['status'] ?? '') === 'delivered', (string)($secondOrderState['status'] ?? ''));
    t('WMS delivery does not auto-complete manual ecommerce payment', (string)($secondOrderState['payment_status'] ?? '') === 'pending', (string)($secondOrderState['payment_status'] ?? ''));
    t('WMS delivery leaves manual payment transaction pending', (string)($secondOrderState['payment']['status'] ?? '') === 'pending', (string)($secondOrderState['payment']['status'] ?? ''));

    $paymentCollection = wmsOrderCollectPayment($cleanup['second_wms_order_id'], null, [
        'payment_method' => 'pay_on_delivery',
        'collected_at' => date('Y-m-d H:i:s'),
        'note' => 'Collected from recipient during delivery.',
    ]);
    t('WMS payment collection records pay-on-delivery settlement', (string)($paymentCollection['payment_method'] ?? '') === 'pay_on_delivery', json_encode($paymentCollection));

    $secondOrderState = ecOrderGet($cleanup['second_order_id']);
    t('WMS payment collection marks ecommerce payment as paid', (string)($secondOrderState['payment_status'] ?? '') === 'paid', (string)($secondOrderState['payment_status'] ?? ''));
    t('WMS payment collection updates payment transaction to succeeded', (string)($secondOrderState['payment']['status'] ?? '') === 'succeeded', (string)($secondOrderState['payment']['status'] ?? ''));
    t('Payment completion provenance is persisted on the order', str_contains((string)($secondOrderState['meta']['payment_completion_meta'] ?? ''), 'wms.order.payment_collected'), (string)($secondOrderState['meta']['payment_completion_meta'] ?? ''));

    $historyStatuses = array_map(static fn(array $row): string => (string)($row['status'] ?? ''), (array)($secondOrderState['status_history'] ?? []));
    t('Customer order history records round-trip statuses', $historyStatuses === ['pending', 'processing', 'shipped', 'delivered'], json_encode($historyStatuses));

    app()->events()->fire('wms.order.picked', wmsOrderBridgeEventPayload($cleanup['second_wms_order_id']), 'wms');
    $secondOrderState = ecOrderGet($cleanup['second_order_id']);
    t('Stale WMS picked event does not move delivered order backward', (string)($secondOrderState['status'] ?? '') === 'delivered', (string)($secondOrderState['status'] ?? ''));

    $db->prepare('UPDATE kernel_integrations SET version_lock = ? WHERE id = ?')->execute(['wms.stock.reserve@999', $cleanup['reserve_integration_id']]);
    app()->events()->fire('ecommerce.order.created', [
        'order_id' => $cleanup['order_id'] + 100000,
        'order_number' => 'BRIDGE-LOCK-' . strtoupper($suffix),
        'customer_email' => 'bridge-' . $suffix . '@example.com',
        'total' => 50.00,
        'source' => 'web',
        'idempotency_key' => 'order_version_lock_' . $suffix,
        'order' => [
            'id' => $cleanup['order_id'] + 100000,
            'warehouse_id' => $cleanup['warehouse_id'],
            'items' => [[
                'product_id' => $cleanup['product_id'],
                'qty' => 1,
                'warehouse_id' => $cleanup['warehouse_id'],
            ]],
        ],
    ], 'ecommerce');

    $logStmt->execute([$cleanup['reserve_integration_id']]);
    $versionLockLog = $logStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    t('Version-lock mismatch logs failure', (string)($versionLockLog['status'] ?? '') === 'failed', (string)($versionLockLog['status'] ?? ''));
    t('Version-lock mismatch error is explicit', str_contains((string)($versionLockLog['error_message'] ?? ''), 'version lock mismatch'), (string)($versionLockLog['error_message'] ?? ''));
} finally {
    foreach (['reserve_integration_id', 'order_create_integration_id', 'release_integration_id', 'cancel_order_integration_id', 'processing_integration_id', 'shipped_integration_id', 'delivered_integration_id', 'paid_integration_id'] as $integrationKey) {
        $integrationId = (int)($cleanup[$integrationKey] ?? 0);
        if ($integrationId <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM kernel_integration_logs WHERE integration_id = ?')->execute([$integrationId]);
        $db->prepare('DELETE FROM kernel_integrations WHERE id = ?')->execute([$integrationId]);
    }
    foreach (['second_wms_order_id', 'wms_order_id', 'sku_bridge_wms_order_id', 'module_context_wms_order_id'] as $wmsOrderKey) {
        $wmsOrderId = (int)($cleanup[$wmsOrderKey] ?? 0);
        if ($wmsOrderId <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM wms_order_items WHERE order_id = ?')->execute([$wmsOrderId]);
        $db->prepare('DELETE FROM wms_orders WHERE id = ?')->execute([$wmsOrderId]);
    }
    foreach (['second_order_id', 'order_id'] as $orderKey) {
        $orderId = (int)($cleanup[$orderKey] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM ec_order_status_history WHERE order_id = ?')->execute([$orderId]);
        $db->prepare('DELETE FROM ec_order_licenses WHERE order_id = ?')->execute([$orderId]);
        $db->prepare('DELETE FROM ec_order_items WHERE order_id = ?')->execute([$orderId]);
        $db->prepare('DELETE FROM ec_order_meta WHERE order_id = ?')->execute([$orderId]);
        $db->prepare('DELETE FROM ec_payment_transactions WHERE order_id = ?')->execute([$orderId]);
        $db->prepare('DELETE FROM ec_orders WHERE id = ?')->execute([$orderId]);
    }
    if ($cleanup['product_id'] > 0) {
        $db->prepare('DELETE FROM wms_idempotency_keys WHERE movement_id IN (SELECT id FROM wms_movements WHERE product_id = ?)')->execute([$cleanup['product_id']]);
        $db->prepare('DELETE FROM wms_movements WHERE product_id = ?')->execute([$cleanup['product_id']]);
        $db->prepare('DELETE FROM wms_stocks WHERE product_id = ?')->execute([$cleanup['product_id']]);
        $db->prepare('DELETE FROM wms_products WHERE id = ?')->execute([$cleanup['product_id']]);
    }
    if ($cleanup['location_id'] > 0) {
        $db->prepare('DELETE FROM wms_locations WHERE id = ?')->execute([$cleanup['location_id']]);
    }
    if ($cleanup['warehouse_id'] > 0) {
        $db->prepare('DELETE FROM wms_warehouses WHERE id = ?')->execute([$cleanup['warehouse_id']]);
    }
}

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
$criticalLines = array_values(array_filter(explode("\n", $appLog), static fn(string $line): bool => str_contains($line, '[critical]')));
$errorLines = array_values(array_filter(explode("\n", $errorLog), static fn(string $line): bool => trim($line) !== ''));

t('No app.log critical errors', empty($criticalLines), implode('; ', $criticalLines));
t('No PHP errors in error.log', empty($errorLines), implode('; ', $errorLines));

echo "\n══════════════════════════════════════════════════\n";
echo '  PASS: ' . $pass . '  FAIL: ' . $fail . '  SKIP: ' . $skip . "\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo '  - ' . $error . "\n";
    }
}

exit($fail > 0 ? 1 : 0);