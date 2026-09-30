<?php

declare(strict_types=1);

/**
 * Receipt count regression suite.
 *
 * Every database assertion is scoped to 9957x fixtures and finally removes
 * those fixtures. TEST-VAR-001 / TEST-VAR-002 are never read or changed.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-receipt-count', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('templates/modules/daily-ledger/cashier/receive_modal.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/trace.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_receipt_count_harness.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$originId = 99571;
$branchId = 99572;
$productId = 99571;
$shortDeliveryId = 995701;
$equalDeliveryId = 995702;
$date = '2031-05-17';
$receivingIds = [];
$payloadPath = tempnam(sys_get_temp_dir(), 'dl-receipt-count-');

$cleanup = static function () use ($db, $originId, $branchId, $productId, $shortDeliveryId, $equalDeliveryId): void {
    $deliveryIds = [$shortDeliveryId, $equalDeliveryId];
    $marks = implode(',', array_fill(0, count($deliveryIds), '?'));
    $receivingIds = $db->prepare("SELECT id FROM dl_branch_receivings WHERE delivery_id IN ($marks)");
    $receivingIds->execute($deliveryIds);
    $receiptIds = array_map('intval', $receivingIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($receiptIds !== []) {
        $receiptMarks = implode(',', array_fill(0, count($receiptIds), '?'));
        $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND entity_type = 'dl_branch_receivings' AND entity_id IN ($receiptMarks)")
            ->execute(array_map('strval', $receiptIds));
    }
    $db->prepare("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN ($marks)")->execute($deliveryIds);
    $db->prepare("DELETE FROM dl_branch_receivings WHERE delivery_id IN ($marks)")->execute($deliveryIds);
    $db->prepare("DELETE FROM dl_deliveries WHERE id IN ($marks)")->execute($deliveryIds);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id = ? AND product_id = ?')->execute([$branchId, $productId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) AND product_id = ?')->execute([$originId, $branchId, $productId]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$originId, $branchId]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$productId]);
};

$cleanup();

try {
    $template = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl');

    $h->section('modal and API count contract');
    $h->test(
        'default Received is empty, not sent (revert returns it.quantity)',
        str_contains($template, "? map[it.id] : ''") && !str_contains($template, ': it.quantity;')
    );
    $h->test(
        'Receive Now is disabled until every line is counted (revert only checks busyKey)',
        str_contains($template, 'busyKey === g.group_key || !allCountsEntered(g)')
        && str_contains($template, 'Enter the physically counted Received value for every line')
    );
    $h->test(
        'all explicit counts enable acceptance, including zero (revert has no all-line gate)',
        dl_requireReceiptCounts([$productId => 10], [$productId => 0]) === [$productId => 0]
        && dl_requireReceiptCounts([$productId => 10], [$productId => 10]) === [$productId => 10]
    );

    $missingRejected = false;
    try {
        dl_requireReceiptCounts([$productId => 10], []);
    } catch (RuntimeException $e) {
        $missingRejected = str_contains($e->getMessage(), 'required for every line');
    }
    $h->test(
        'API count validation rejects an absent/empty line (revert silently records sent)',
        $missingRejected
    );

    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, ?, 1)')
        ->execute([$originId, 'RC-ORIG', 'Receipt Count Origin', 1]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, ?, 1)')
        ->execute([$branchId, 'RC-DEST', 'Receipt Count Destination', 0]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 12.50, 1)')
        ->execute([$productId, 'RC-PROD', 'Receipt Count Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$branchId, $productId]);

    $insertDelivery = static function (int $id, string $dr) use ($db, $originId, $branchId, $productId, $date): void {
        $db->prepare(
            'INSERT INTO dl_deliveries (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
             VALUES (?, "commissary", ?, "branch", ?, ?, ?, "posted", ? )'
        )->execute([$id, $originId, $branchId, $dr, $date, $date . ' 07:00:00']);
        $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 10, "pcs", 12.50)')
            ->execute([$id, $productId]);
    };
    $insertDelivery($shortDeliveryId, 'RC-SHORT-995701');
    $insertDelivery($equalDeliveryId, 'RC-EQUAL-995702');

    $h->section('recorded receipt evidence');

    file_put_contents($payloadPath, json_encode([
        'branch_id' => $branchId,
        'delivery_ids' => [$shortDeliveryId],
        'withdrawal_ids' => [],
    ], JSON_THROW_ON_ERROR));
    $apiOutput = [];
    $apiExit = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/daily_ledger_receipt_count_harness.php') . ' receive '
        . escapeshellarg($payloadPath) . ' 2>/dev/null',
        $apiOutput,
        $apiExit
    );
    $apiBody = implode("\n", $apiOutput);
    $receiptCheck = $db->prepare('SELECT COUNT(*) FROM dl_branch_receivings WHERE delivery_id = ?');
    $receiptCheck->execute([$shortDeliveryId]);
    $h->test(
        'submitting no Received values through the real API is rejected without a receipt (revert returns ok)',
        $apiExit === 0
        && str_contains($apiBody, 'counted Received value is required for every line')
        && (int)$receiptCheck->fetchColumn() === 0,
        $apiBody
    );

    $shortCounts = dl_requireReceiptCounts([$productId => 10], [$productId => 8]);
    $receivingIds[] = dl_acceptFormalDelivery($db, $branchId, $shortDeliveryId, 0, $date, $shortCounts, 'AM');
    $shortReceipt = $db->prepare(
        'SELECT bri.quantity_received, vf.sent_qty, vf.received_qty, vf.variance
           FROM dl_branch_receiving_items bri
           INNER JOIN dl_branch_receivings br ON br.id = bri.receiving_id
           LEFT JOIN dl_delivery_variance_flags vf ON vf.receiving_id = br.id AND vf.product_id = bri.product_id
          WHERE br.delivery_id = ? AND bri.product_id = ?'
    );
    $shortReceipt->execute([$shortDeliveryId, $productId]);
    $shortRow = $shortReceipt->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'below-sent count records 8 and creates variance -2 (revert records 10 and no flag)',
        (int)($shortRow['quantity_received'] ?? -1) === 8
        && (int)($shortRow['sent_qty'] ?? -1) === 10
        && (int)($shortRow['received_qty'] ?? -1) === 8
        && (int)($shortRow['variance'] ?? 0) === -2
    );

    $equalCounts = dl_requireReceiptCounts([$productId => 10], [$productId => 10]);
    $receivingIds[] = dl_acceptFormalDelivery($db, $branchId, $equalDeliveryId, 0, $date, $equalCounts, 'AM');
    $equalReceipt = $db->prepare(
        'SELECT bri.quantity_received,
                (SELECT COUNT(*) FROM dl_delivery_variance_flags vf WHERE vf.delivery_id = ?) AS variance_count
           FROM dl_branch_receiving_items bri
           INNER JOIN dl_branch_receivings br ON br.id = bri.receiving_id
          WHERE br.delivery_id = ? AND bri.product_id = ?'
    );
    $equalReceipt->execute([$equalDeliveryId, $equalDeliveryId, $productId]);
    $equalRow = $equalReceipt->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'entered sent count records equality and no variance flag (revert-proof explicit count path)',
        (int)($equalRow['quantity_received'] ?? -1) === 10
        && (int)($equalRow['variance_count'] ?? -1) === 0
    );

    $runList = static function (string $query): array {
        $output = [];
        $exit = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__DIR__ . '/daily_ledger_receipt_count_harness.php') . ' list '
            . escapeshellarg($query) . ' 2>/dev/null',
            $output,
            $exit
        );
        $raw = implode("\n", $output);
        $raw = preg_replace('/\n__HTTP_STATUS__=.*$/s', '', $raw) ?? $raw;
        return ['exit' => $exit, 'json' => json_decode($raw, true)];
    };
    $receivedList = $runList('status=received&branch_id=' . $branchId . '&date_from=' . $date . '&date_to=' . $date);
    $waitingList = $runList('status=posted&branch_id=' . $branchId . '&date_from=' . $date . '&date_to=' . $date);
    $receivedIds = array_map('intval', array_column((array)($receivedList['json']['deliveries'] ?? []), 'id'));
    $waitingIds = array_map('intval', array_column((array)($waitingList['json']['deliveries'] ?? []), 'id'));
    $h->test(
        'Delivered Received filter returns receipted posted documents and waiting excludes them (revert matches stored status)',
        $receivedList['exit'] === 0
        && in_array($shortDeliveryId, $receivedIds, true)
        && in_array($equalDeliveryId, $receivedIds, true)
        && !in_array($shortDeliveryId, $waitingIds, true)
        && !in_array($equalDeliveryId, $waitingIds, true)
    );
} finally {
    $cleanup();
    if (is_string($payloadPath) && is_file($payloadPath)) {
        unlink($payloadPath);
    }
}

$h->done();
