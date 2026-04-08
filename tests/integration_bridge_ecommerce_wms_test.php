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
    'release_integration_id' => 0,
    'order_id' => 0,
    'location_id' => 0,
    'warehouse_id' => 0,
    'product_id' => 0,
];

try {
    $db->prepare('DELETE FROM kernel_integration_logs WHERE integration_id IN (SELECT id FROM kernel_integrations WHERE name IN (?, ?))')->execute([
        'test_bridge_reserve_' . $suffix,
        'test_bridge_release_' . $suffix,
    ]);
    $db->prepare('DELETE FROM kernel_integrations WHERE name IN (?, ?)')->execute([
        'test_bridge_reserve_' . $suffix,
        'test_bridge_release_' . $suffix,
    ]);

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
        'test_bridge_release_' . $suffix,
        'ecommerce.order.cancelled',
        'wms.stock.release@1',
        json_encode($mapping, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'eventbus',
        'wms.stock.release@1',
    ]);
    $cleanup['release_integration_id'] = (int)$db->lastInsertId();

    $reserveResults = [];
    $releaseResults = [];
    app()->events()->listen('ecommerce.order.created', static function (array $payload, string $event): void {
        \Ikabud\Kernel\IntegrationBridge::handle($payload, $event);
    }, 100, 'kernel');
    app()->events()->listen('ecommerce.order.cancelled', static function (array $payload, string $event): void {
        \Ikabud\Kernel\IntegrationBridge::handle($payload, $event);
    }, 100, 'kernel');
    app()->events()->listen('integration.result.wms.stock.reserve_v1', static function (array $payload) use (&$reserveResults): void {
        $reserveResults[] = $payload;
    }, 110, 'tests');
    app()->events()->listen('integration.result.wms.stock.release_v1', static function (array $payload) use (&$releaseResults): void {
        $releaseResults[] = $payload;
    }, 110, 'tests');

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

    $stockStmt = $db->prepare('SELECT qty_reserved FROM wms_stocks WHERE product_id = ? AND warehouse_id = ? AND location_id = ? LIMIT 1');
    $stockStmt->execute([$cleanup['product_id'], $cleanup['warehouse_id'], $cleanup['location_id']]);
    $qtyReserved = (float)($stockStmt->fetchColumn() ?: 0);
    t('WMS reserved stock updated', abs($qtyReserved - 2.0) < 0.0001, (string)$qtyReserved);

    $movementStmt = $db->prepare('SELECT COUNT(*) FROM wms_movements WHERE product_id = ? AND reference_type = ? AND reference_id = ? AND movement_type = ?');
    $movementStmt->execute([$cleanup['product_id'], 'order', $cleanup['order_id'], 'reserved']);
    $movementCount = (int)($movementStmt->fetchColumn() ?: 0);
    t('Single reserve movement created', $movementCount === 1, (string)$movementCount);

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
    foreach (['reserve_integration_id', 'release_integration_id'] as $integrationKey) {
        $integrationId = (int)($cleanup[$integrationKey] ?? 0);
        if ($integrationId <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM kernel_integration_logs WHERE integration_id = ?')->execute([$integrationId]);
        $db->prepare('DELETE FROM kernel_integrations WHERE id = ?')->execute([$integrationId]);
    }
    if ($cleanup['order_id'] > 0) {
        $db->prepare('DELETE FROM ec_order_licenses WHERE order_id = ?')->execute([$cleanup['order_id']]);
        $db->prepare('DELETE FROM ec_order_items WHERE order_id = ?')->execute([$cleanup['order_id']]);
        $db->prepare('DELETE FROM ec_order_meta WHERE order_id = ?')->execute([$cleanup['order_id']]);
        $db->prepare('DELETE FROM ec_payment_transactions WHERE order_id = ?')->execute([$cleanup['order_id']]);
        $db->prepare('DELETE FROM ec_orders WHERE id = ?')->execute([$cleanup['order_id']]);
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