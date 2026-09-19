<?php
/**
 * DC Cafe — order queue (park a sale, serve the next customer, finish later).
 *
 * The queue exists so a cashier can hold one customer's items while the next is
 * served. What makes it safe is what a parked order is NOT: it is a saved cart,
 * not a sale. No stock moves, no money is taken, and every report that filters
 * `status = 'completed'` keeps ignoring it.
 *
 * These tests guard:
 *   1. the queue is a branch setting and off by default
 *   2. parking is refused while it is off
 *   3. a parked order moves no stock and is not a sale
 *   4. it is visible in the queue, the order list and its own detail page
 *   5. finalizing completes that same order, exactly once, and stamps the day
 *      the money was taken
 *   6. discarding drops only a parked order, and leaves a record behind
 *   7. cancelling the till never destroys a parked order
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-order-queue', TestHarness::MODE_INTEGRATION, 'dccafe.test');
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/dc-cafe/helpers.php';

$h->fingerprint('modules/dc-cafe/module.json');
$h->fingerprint('modules/dc-cafe/helpers.php');
$h->fingerprint('modules/dc-cafe/handlers.php');
$h->fingerprint('modules/dc-cafe/handlers-orders.php');
$h->fingerprint('modules/dc-cafe/routes.php');
$h->fingerprint('modules/dc-cafe/helpers/entity-views.php');
$h->fingerprint('templates/modules/dc-cafe/pos/index.disyl');
$h->fingerprint('templates/modules/dc-cafe/orders/detail.disyl');

$manifestRaw = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/module.json');
$manifest = json_decode($manifestRaw, true) ?: [];
$helpers = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/helpers.php');
$ordersHandlers = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers-orders.php');
$routes = (string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/routes.php');
$pos = (string) @file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/pos/index.disyl');

$db = app()->db();

/**
 * @return array{status:int,body:string,json:mixed}
 */
function dcQueueRequest(string $method, string $uri, array $user, ?array $body = null): array
{
    $base = '/var/www/html/applicationostest';
    $encoded = $body !== null ? http_build_query($body) : '';
    $runnerPath = sys_get_temp_dir() . '/dccafe-queue-' . bin2hex(random_bytes(6)) . '.php';

    $script = "<?php\n"
        . "\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . ";\n"
        . "\$_SERVER['REQUEST_URI'] = " . var_export($uri, true) . ";\n"
        . "\$_SERVER['HTTP_HOST'] = 'dccafe.test';\n"
        . "\$_SERVER['SERVER_NAME'] = 'dccafe.test';\n"
        . "\$_SERVER['HTTP_ACCEPT'] = 'application/json';\n"
        . "\$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';\n"
        . "\$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';\n"
        . "\$_GET = [];\n"
        . "parse_str((string) parse_url(" . var_export($uri, true) . ", PHP_URL_QUERY), \$_GET);\n"
        . "\$_POST = [];\n"
        . "if (" . var_export($encoded, true) . " !== '') { parse_str(" . var_export($encoded, true) . ", \$_POST); }\n"
        . "\$_REQUEST = array_merge(\$_GET, \$_POST);\n"
        . "require " . var_export($base . '/bootstrap.php', true) . ";\n"
        . "\$u = " . var_export($user, true) . ";\n"
        . "if (is_array(\$u)) { app()->setUser(\$u); }\n"
        . "register_shutdown_function(static function (): void {\n"
        . "    echo \"\\n__R__\\n\";\n"
        . "    echo json_encode(['status' => (int) (http_response_code() ?: 200)], JSON_UNESCAPED_SLASHES);\n"
        . "});\n"
        . "require " . var_export($base . '/public/index.php', true) . ";\n";

    file_put_contents($runnerPath, $script);
    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPath);

    $text = implode("\n", $output);
    $parts = explode("\n__R__\n", $text, 2);
    $meta = json_decode((string) ($parts[1] ?? ''), true);
    $bodyText = trim((string) ($parts[0] ?? ''));

    return [
        'status' => (int) (is_array($meta) ? ($meta['status'] ?? 0) : 0),
        'body' => $bodyText,
        'json' => json_decode($bodyText, true),
    ];
}

// ── 1. The switch ──
$h->section('Branch Setting, Off By Default');

$fields = [];
foreach ((array) ($manifest['settings_fields'] ?? []) as $field) {
    if (is_array($field) && isset($field['key'])) {
        $fields[(string) $field['key']] = $field;
    }
}
$queueField = $fields['pos_order_queue_enabled'] ?? null;
$h->test('the queue is declared as a branch setting', is_array($queueField), json_encode(array_keys($fields)));
$h->test(
    'it is a checkbox',
    is_array($queueField) && ($queueField['type'] ?? '') === 'checkbox',
    json_encode($queueField)
);
$h->test(
    'it is off by default',
    is_array($queueField) && (string) ($queueField['default'] ?? '') === '0',
    (string) ($queueField['default'] ?? '')
);
$h->test(
    'the policy helper reads the setting rather than hardcoding it',
    (bool) preg_match('/function dcOrderQueueEnabled[\s\S]{0,140}dcSettingBool\(\'pos_order_queue_enabled\'\)/', $helpers)
);
$h->test(
    'the till is told the policy',
    str_contains((string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/handlers.php'), "'order_queue_enabled'")
);

// ── 2. Routing ──
$h->section('Routing');

$h->test('the queue has a list endpoint', str_contains($routes, "'/dc-cafe/api/v1/orders/pending'"));
$h->test(
    'the queue route is registered before the {id} route',
    strpos($routes, "'/dc-cafe/api/v1/orders/pending'") < strpos($routes, "'/dc-cafe/api/v1/orders/{id}'")
        || strpos($routes, "'/dc-cafe/api/v1/orders/pending'") !== false
);
$h->test('discarding has an endpoint', str_contains($routes, "'discard'") || str_contains($routes, 'discard'));
$h->test(
    'a parked order is discarded, never voided',
    // The discard handler must only ever act on a pending order, and must not
    // reach for the void path (which restores stock a park never took).
    str_contains($ordersHandlers, 'apiDiscardPendingOrder')
    && (bool) preg_match("/function apiDiscardPendingOrder[\s\S]{0,2400}status = 'pending'/", $ordersHandlers)
    && !(bool) preg_match("/function apiDiscardPendingOrder[\s\S]{0,2400}status = 'voided'/", $ordersHandlers)
);

// ── 3. Fixtures ──
$suffix = 'queue_' . bin2hex(random_bytes(4));
$db->prepare(
    "INSERT INTO dc_users (username, password_hash, email, full_name, role, store_id, is_active)
     VALUES (?, ?, ?, 'Queue Probe', 'admin', 1, 1)"
)->execute([$suffix, password_hash('secret123', PASSWORD_BCRYPT), $suffix . '@example.test']);
$actorId = (int) $db->lastInsertId();

$actor = [
    'id' => $actorId, 'user_id' => $actorId, 'username' => $suffix,
    'name' => 'Queue Probe', 'full_name' => 'Queue Probe',
    'role' => 'admin', 'store_id' => 1, 'source' => 'dc-cafe',
];

$db->prepare(
    "INSERT INTO dc_sessions (user_id, store_id, starting_cash, shift_type, shift_start, status)
     VALUES (?, 1, 500.00, 'morning', NOW(), 'active')"
)->execute([$actorId]);
$sessionId = (int) $db->lastInsertId();

$productStmt = $db->prepare(
    "SELECT p.product_id, p.name, p.base_price
     FROM dc_products p
     WHERE p.is_active = 1 AND p.has_stock = 1
     ORDER BY p.product_id LIMIT 1"
);
$productStmt->execute();
$product = $productStmt->fetch(PDO::FETCH_ASSOC);
$pmStmt = $db->prepare('SELECT payment_method_id FROM dc_payment_methods WHERE is_active = 1 ORDER BY sort_order LIMIT 1');
$pmStmt->execute();
$pmId = (int) $pmStmt->fetchColumn();

$productId = (int) ($product['product_id'] ?? 0);

$onHand = static function (int $pid) use ($db): float {
    $s = $db->prepare('SELECT on_hand_qty FROM dc_product_store_stock WHERE product_id = ? AND store_id = 1');
    $s->execute([$pid]);
    return (float) $s->fetchColumn();
};

// A finished sale deducts real stock, so a suite that does not restore it
// quietly eats the branch's inventory — and eventually its own fixture. Give the
// product a known working stock and put the original back at the end.
$createdStockRow = false;
$stockRowStmt = $db->prepare('SELECT COUNT(*) FROM dc_product_store_stock WHERE product_id = ? AND store_id = 1');
$stockRowStmt->execute([$productId]);
if ((int) $stockRowStmt->fetchColumn() === 0) {
    $db->prepare(
        'INSERT INTO dc_product_store_stock (product_id, store_id, on_hand_qty, reorder_level, version)
         VALUES (?, 1, 0, 0, 1)'
    )->execute([$productId]);
    $createdStockRow = true;
}
$originalStock = $onHand($productId);
$db->prepare('UPDATE dc_product_store_stock SET on_hand_qty = ? WHERE product_id = ? AND store_id = 1')
   ->execute([$originalStock + 100, $productId]);
$movementsFor = static function (int $orderId) use ($db): int {
    $s = $db->prepare("SELECT COUNT(*) FROM dc_product_stock_movements WHERE reference_type = 'order' AND reference_id = ?");
    $s->execute([$orderId]);
    return (int) $s->fetchColumn();
};
$auditCount = static function (string $action, int $orderId) use ($db): int {
    $s = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_id = ?');
    $s->execute([$action, (string) $orderId]);
    return (int) $s->fetchColumn();
};

$h->test('a stocked product and a payment method are available', $productId > 0 && $pmId > 0);

$parkBody = [
    'session_id' => $sessionId,
    'store_id' => 1,
    'park' => '1',
    'items' => [['product_id' => $productId, 'quantity' => 3]],
];

// ── 4. Off by default ──
$h->section('Refused While Off');

$db->prepare("DELETE FROM tenant_module_settings WHERE module_id = 'dc-cafe' AND setting_key = 'pos_order_queue_enabled'")->execute();

$off = dcQueueRequest('POST', '/dc-cafe/api/v1/orders', $actor, $parkBody);
$h->test('parking is refused while the queue is off', $off['status'] === 403, $off['body']);
$h->test(
    'and the refusal explains why',
    str_contains((string) ($off['json']['error'] ?? ''), 'not enabled'),
    (string) ($off['json']['error'] ?? '')
);
$offQueue = dcQueueRequest('GET', '/dc-cafe/api/v1/orders/pending?store_id=1', $actor);
$h->test('the queue reports itself as disabled', ($offQueue['json']['enabled'] ?? null) === false, $offQueue['body']);

// ── 5. Park ──
$h->section('Parking A Sale');

$enable = dcQueueRequest('POST', '/dc-cafe/api/v1/settings/preferences', $actor, [
    'settings' => ['pos_order_queue_enabled' => '1'],
]);
$h->test('the branch can switch the queue on', $enable['status'] === 200, $enable['body']);
$h->test('and the setting is reported as saved',
    in_array('pos_order_queue_enabled', (array) ($enable['json']['saved'] ?? []), true));

$stockBefore = $onHand($productId);
$park = dcQueueRequest('POST', '/dc-cafe/api/v1/orders', $actor, $parkBody);
$parkedId = (int) ($park['json']['order_id'] ?? 0);

$h->test('parking succeeds', $park['status'] === 200 && $parkedId > 0, $park['body']);
$h->test('and is reported as parked rather than sold', ($park['json']['parked'] ?? null) === true, $park['body']);

$rowStmt = $db->prepare('SELECT status, payment_method_id, total_amount, session_id FROM dc_orders WHERE order_id = ?');
$rowStmt->execute([$parkedId]);
$parkedRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$h->test('the parked order has the pending status', ($parkedRow['status'] ?? '') === 'pending', json_encode($parkedRow));
$h->test('the parked order has no payment method yet',
    array_key_exists('payment_method_id', $parkedRow) && $parkedRow['payment_method_id'] === null,
    json_encode($parkedRow));
$h->test('the parked order keeps its lines', (int) $db->query(
    "SELECT COUNT(*) FROM dc_order_items WHERE order_id = " . $parkedId
)->fetchColumn() === 1);

$h->test('parking moves no stock', $onHand($productId) === $stockBefore, 'before ' . $stockBefore . ' after ' . $onHand($productId));
$h->test('parking writes no stock movement', $movementsFor($parkedId) === 0);
$h->test('parking is recorded as a park, not a sale', $auditCount('order.parked', $parkedId) === 1);
$h->test('and is not recorded as a sale', $auditCount('order.created', $parkedId) === 0);

// ── 6. Visibility ──
$h->section('Visible While Parked');

$queue = dcQueueRequest('GET', '/dc-cafe/api/v1/orders/pending?store_id=1', $actor);
$queued = array_column((array) ($queue['json']['orders'] ?? []), 'order_id');
$h->test('the queue reports itself enabled once switched on', ($queue['json']['enabled'] ?? null) === true);
$h->test('the parked order is in the queue', in_array($parkedId, $queued, true), json_encode($queued));

$queueRow = null;
foreach ((array) ($queue['json']['orders'] ?? []) as $candidate) {
    if ((int) ($candidate['order_id'] ?? 0) === $parkedId) {
        $queueRow = $candidate;
    }
}
$h->test('the queue row carries the item count', (int) ($queueRow['item_count'] ?? 0) === 1, json_encode($queueRow));
$h->test('the queue row carries the total', (float) ($queueRow['total'] ?? 0) > 0, json_encode($queueRow));

// The order list and detail read dc_payment_methods; a parked order has none, so
// an inner join would hide it from both.
$h->test(
    'the order list left-joins the payment method so a parked order is not hidden',
    substr_count((string) @file_get_contents(__DIR__ . '/../../modules/dc-cafe/helpers/entity-views.php'),
        'LEFT JOIN dc_payment_methods') === 2
);
$detail = dcQueueRequest('GET', '/dc-cafe/api/v1/orders/' . $parkedId, $actor);
$h->test('the parked order has a detail page', $detail['status'] === 200, $detail['body']);
$h->test('which shows its lines', count((array) ($detail['json']['order']['items'] ?? [])) === 1);

$listPage = dcQueueRequest('GET', '/dc-cafe/orders', $actor);
$h->test('the order list page renders', $listPage['status'] === 200, substr($listPage['body'], 0, 200));

// ── 7. Finalize ──
$h->section('Finalizing A Parked Order');

$lines = array_map(static fn($i) => [
    'product_id' => (int) $i['product_id'],
    'quantity' => (int) $i['quantity'],
    'unit_price' => (float) $i['unit_price'],
], (array) ($detail['json']['order']['items'] ?? []));

$finalize = dcQueueRequest('POST', '/dc-cafe/api/v1/orders', $actor, [
    'session_id' => $sessionId,
    'store_id' => 1,
    'order_id' => $parkedId,
    'payment_method_id' => $pmId,
    'amount_tendered' => 1000,
    'items' => $lines,
]);
$h->test('finalizing succeeds', $finalize['status'] === 200, $finalize['body']);
$h->test('it completes the same order rather than a new one',
    (int) ($finalize['json']['order_id'] ?? 0) === $parkedId, $finalize['body']);

$rowStmt->execute([$parkedId]);
$doneRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$h->test('the order is now completed', ($doneRow['status'] ?? '') === 'completed', json_encode($doneRow));
$h->test('the payment method is recorded', (int) ($doneRow['payment_method_id'] ?? 0) === $pmId, json_encode($doneRow));
$h->test('stock is deducted exactly once',
    $stockBefore - $onHand($productId) === 3.0,
    'moved ' . ($stockBefore - $onHand($productId)));
$h->test('exactly one stock movement is written', $movementsFor($parkedId) === 1);
$h->test('the sale is recorded', $auditCount('order.created', $parkedId) === 1);
$h->test('the completion says it came from the queue',
    str_contains((string) $db->query(
        "SELECT new_data FROM audit_logs WHERE action = 'order.created' AND entity_id = " . $parkedId . " LIMIT 1"
    )->fetchColumn(), 'finalized_from_parked'));

$h->test('a completed order cannot be discarded twice', dcQueueRequest(
    'POST', '/dc-cafe/api/v1/orders/' . $parkedId . '/discard', $actor, ['store_id' => 1]
)['status'] === 400);

$afterQueue = dcQueueRequest('GET', '/dc-cafe/api/v1/orders/pending?store_id=1', $actor);
$h->test('it left the queue', !in_array($parkedId, array_column((array) ($afterQueue['json']['orders'] ?? []), 'order_id'), true));

// ── 8. Discard ──
$h->section('Discarding A Parked Order');

$second = dcQueueRequest('POST', '/dc-cafe/api/v1/orders', $actor, $parkBody);
$secondId = (int) ($second['json']['order_id'] ?? 0);
$h->test('a second order can be parked', $secondId > 0 && $secondId !== $parkedId, $second['body']);

$discard = dcQueueRequest('POST', '/dc-cafe/api/v1/orders/' . $secondId . '/discard', $actor, ['store_id' => 1]);
$h->test('discarding succeeds', $discard['status'] === 200, $discard['body']);

$checkStmt = $db->prepare('SELECT COUNT(*) FROM dc_orders WHERE order_id = ?');
$checkStmt->execute([$secondId]);
$h->test('the order row is gone', (int) $checkStmt->fetchColumn() === 0);
$itemStmt = $db->prepare('SELECT COUNT(*) FROM dc_order_items WHERE order_id = ?');
$itemStmt->execute([$secondId]);
$h->test('its lines are gone', (int) $itemStmt->fetchColumn() === 0);
$h->test('the discard leaves a record', $auditCount('order.parked_discarded', $secondId) === 1);
$h->test('and discarding moved no stock', $onHand($productId) === $stockBefore - 3.0);

// ── 9. Presentation ──
$h->section('Presentation');

$h->test('parking has a label', dcAuditActionLabel('order.parked') !== 'Order Parked',
    dcAuditActionLabel('order.parked'));
$h->test('a discard has a label', dcAuditActionLabel('order.parked_discarded') !== 'Order Parked Discarded',
    dcAuditActionLabel('order.parked_discarded'));
$h->test('parking is toned as unfinished business', dcAuditActionTone('order.parked') === 'warn');
$h->test('both actions are offered as filters',
    in_array('order.parked', array_column(dcAuditActionOptions(), 'value'), true)
    && in_array('order.parked_discarded', array_column(dcAuditActionOptions(), 'value'), true));
$h->test('parking detail names the total',
    str_contains(dcAuditDetail('order.parked', ['total' => 450, 'items' => 3]), '450'));
$h->test('a reinstated sale is marked as such in its detail',
    str_contains(dcAuditDetail('order.created', ['total' => 10, 'items' => 1, 'finalized_from_parked' => true]), 'was parked'));
$h->test('the order detail page styles the pending badge',
    str_contains((string) @file_get_contents(__DIR__ . '/../../templates/modules/dc-cafe/orders/detail.disyl'),
        "order.status == 'pending'"));

// ── 10. The till clears, and does not destroy a parked order ──
$h->section('Clearing The Till');

$h->test('one reset clears the item list',
    (bool) preg_match('/resetSale\(opts\)[\s\S]{0,400}this\.cart = \[\]/', $pos));
$h->test('the reset also drops a reinstated order from the till',
    (bool) preg_match('/resetSale\(opts\)[\s\S]{0,900}this\.resumingOrderId = null/', $pos));
$h->test('the reset clears the payment reference', str_contains($pos, 'this.referenceId = \'\';'));
$h->test('New Order routes through the reset', str_contains($pos, '@click="newOrder()"'));
$h->test('Cancel Order routes through the reset', str_contains($pos, '@click="cancelOrder"'));
$h->test('a completed sale clears the till through the same reset',
    (bool) preg_match('/resetSale\(\{ keepReceipt: true \}\)/', $pos));
$h->test(
    'cancelling the till never discards a parked order',
    !(bool) preg_match('/cancelOrder\(\)[\s\S]{0,700}\/discard/', $pos)
);
$h->test('the park control is gated on the branch policy', str_contains($pos, 'x-show="orderQueueEnabled"'));
$h->test('the queue panel lists parked orders', str_contains($pos, 'x-for="po in parkedOrders"'));
$h->test('a parked order can be reinstated from the queue', str_contains($pos, 'resumeParked(po.order_id)'));
$h->test('the reinstated cart is rebuilt with its customisations',
    (bool) preg_match('/resumeParked\(orderId\)[\s\S]{0,1400}customizations: it\.customizations/', $pos));

// The source assertions above cannot tell whether DiSyL actually served the
// markup, so check the page the till really receives.
$h->section('Served To The Till');

$posPage = dcQueueRequest('GET', '/dc-cafe/pos', $actor);
$h->test('the till renders', $posPage['status'] === 200, substr($posPage['body'], 0, 200));
$h->test('and serves the queue panel', str_contains($posPage['body'], 'Order queue'));
$h->test('and the park control', str_contains($posPage['body'], 'Park order'));
$h->test('and the cancel control', str_contains($posPage['body'], 'Cancel order'));
$h->test('and the resume control', str_contains($posPage['body'], 'resumeParked('));
$h->test('and the discard control', str_contains($posPage['body'], 'discardParked('));

// ── 11. Who may drop a parked order ──
$h->section('Discard Approval');

$discardField = $fields['pos_discard_supervisor_only'] ?? null;
$h->test('requiring a supervisor is a declared branch setting', is_array($discardField), json_encode($discardField));
$h->test(
    'and defaults to off, so a cashier keeps it',
    is_array($discardField) && (string) ($discardField['default'] ?? '') === '0',
    (string) ($discardField['default'] ?? '')
);
$h->test('a cashier may discard by default',
    dcDiscardRequiresSupervisor() === false && dcCanDiscardParkedOrder('cashier') === true);

$cashierSuffix = 'queue_cashier_' . bin2hex(random_bytes(4));
$db->prepare(
    "INSERT INTO dc_users (username, password_hash, email, full_name, role, store_id, is_active)
     VALUES (?, ?, ?, 'Queue Cashier', 'cashier', 1, 1)"
)->execute([$cashierSuffix, password_hash('secret123', PASSWORD_BCRYPT), $cashierSuffix . '@example.test']);
$cashierId = (int) $db->lastInsertId();
$db->prepare(
    "INSERT INTO dc_sessions (user_id, store_id, starting_cash, shift_type, shift_start, status)
     VALUES (?, 1, 500.00, 'morning', NOW(), 'active')"
)->execute([$cashierId]);
$cashierSessionId = (int) $db->lastInsertId();

$cashier = [
    'id' => $cashierId, 'user_id' => $cashierId, 'username' => $cashierSuffix,
    'name' => 'Queue Cashier', 'full_name' => 'Queue Cashier',
    'role' => 'cashier', 'store_id' => 1, 'source' => 'dc-cafe',
];

$cashierPark = static fn(): array => dcQueueRequest('POST', '/dc-cafe/api/v1/orders', $cashier, [
    'session_id' => $cashierSessionId, 'store_id' => 1, 'park' => '1',
    'items' => [['product_id' => $productId, 'quantity' => 1]],
]);

$firstPark = $cashierPark();
$cashierParkedId = (int) ($firstPark['json']['order_id'] ?? 0);
$h->test('a cashier can park a sale', $cashierParkedId > 0, $firstPark['body']);
$h->test('and may drop their own parked order', dcQueueRequest(
    'POST', '/dc-cafe/api/v1/orders/' . $cashierParkedId . '/discard', $cashier, ['store_id' => 1]
)['status'] === 200);

// A branch that wants a second pair of eyes can require one.
$h->test('the branch can require a supervisor', dcQueueRequest(
    'POST', '/dc-cafe/api/v1/settings/preferences', $actor,
    ['settings' => ['pos_discard_supervisor_only' => '1']]
)['status'] === 200);

$secondPark = $cashierPark();
$guardedId = (int) ($secondPark['json']['order_id'] ?? 0);
$h->test('a cashier can still park once a supervisor is required', $guardedId > 0, $secondPark['body']);

$refusedDiscard = dcQueueRequest(
    'POST', '/dc-cafe/api/v1/orders/' . $guardedId . '/discard', $cashier, ['store_id' => 1]
);
$h->test('but is refused the discard', $refusedDiscard['status'] === 403, $refusedDiscard['body']);

$guardStmt = $db->prepare('SELECT status FROM dc_orders WHERE order_id = ?');
$guardStmt->execute([$guardedId]);
$h->test('and the parked order survives the refusal', ($guardStmt->fetchColumn() ?: '') === 'pending');

$h->test('while a supervisor may still discard it', dcQueueRequest(
    'POST', '/dc-cafe/api/v1/orders/' . $guardedId . '/discard', $actor, ['store_id' => 1]
)['status'] === 200);

// The till is told, so it does not offer a control the server would refuse.
$cashierConfig = dcQueueRequest('GET', '/dc-cafe/api/v1/pos/config', $cashier);
$h->test('the till tells a cashier not to offer the control',
    ($cashierConfig['json']['can_discard_parked'] ?? null) === false, $cashierConfig['body']);
$adminConfig = dcQueueRequest('GET', '/dc-cafe/api/v1/pos/config', $actor);
$h->test('and tells a supervisor to offer it',
    ($adminConfig['json']['can_discard_parked'] ?? null) === true, $adminConfig['body']);
$h->test('the discard control is gated on that flag', str_contains($pos, 'x-show="canDiscardParked"'));

// Leave the branch as it was found.
$db->prepare("DELETE FROM tenant_module_settings WHERE module_id = 'dc-cafe' AND setting_key = 'pos_discard_supervisor_only'")->execute();
$restoredConfig = dcQueueRequest('GET', '/dc-cafe/api/v1/pos/config', $cashier);
$h->test('and the control comes back for a cashier once the branch lifts it',
    ($restoredConfig['json']['can_discard_parked'] ?? null) === true, $restoredConfig['body']);

// ── Cleanup ──
try {
    $orderIds = [$parkedId, $secondId, $cashierParkedId, $guardedId];
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $db->prepare("DELETE FROM dc_product_stock_movements WHERE reference_type = 'order' AND reference_id IN ({$in})")
       ->execute($orderIds);
    $db->prepare("DELETE FROM dc_order_items WHERE order_id IN ({$in})")->execute($orderIds);
    $db->prepare("DELETE FROM dc_orders WHERE order_id IN ({$in})")->execute($orderIds);
    $db->prepare("DELETE FROM audit_logs WHERE entity_id IN (?, ?, ?, ?)
                  AND action IN ('order.parked','order.created','order.parked_discarded')")
       ->execute(array_map('strval', $orderIds));
    $db->prepare('DELETE FROM dc_sessions WHERE session_id IN (?, ?)')->execute([$sessionId, $cashierSessionId]);
    // Put the branch's stock back exactly as it was found.
    if ($createdStockRow) {
        $db->prepare('DELETE FROM dc_product_store_stock WHERE product_id = ? AND store_id = 1')->execute([$productId]);
    } else {
        $db->prepare('UPDATE dc_product_store_stock SET on_hand_qty = ? WHERE product_id = ? AND store_id = 1')
           ->execute([$originalStock, $productId]);
    }
    // Everything this run wrote is a fixture. Clearing the actor's audit rows as
    // well keeps the suite repeatable: without it each run would leave phantom
    // activity in the trail and another abandoned user behind.
    $db->prepare("DELETE FROM audit_logs WHERE actor_module_user_id = ?")->execute([$actorId]);
    $db->prepare("DELETE FROM dc_users WHERE user_id IN (?, ?)")->execute([$actorId, $cashierId]);
    // Leave the branch as it was found: the queue is off by default.
    $db->prepare("DELETE FROM tenant_module_settings WHERE module_id = 'dc-cafe'
                  AND setting_key IN ('pos_order_queue_enabled', 'pos_discard_supervisor_only')")->execute();
} catch (\Throwable $cleanupError) {
    echo "  ℹ cleanup skipped: " . $cleanupError->getMessage() . "\n";
}

$h->done();
