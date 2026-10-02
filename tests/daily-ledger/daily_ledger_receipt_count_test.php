<?php

declare(strict_types=1);

/**
 * Receiving without re-keying regression suite.
 *
 * Fixtures use reserved 9957x identifiers in tenant 207 and are removed in a
 * finally block. The suite prints the receipt evidence and post-cleanup counts.
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
$h->fingerprint('tests/daily-ledger/daily_ledger_receipt_count_harness.php');
$h->fingerprint('tests/daily-ledger/receive_modal_dispatch_harness.js');

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
$copiedDeliveryId = 995701;
$correctedDeliveryId = 995702;
$date = '2031-05-17';
$payloadPath = tempnam(sys_get_temp_dir(), 'dl-receive-no-rekey-');

$cleanup = static function () use ($db, $originId, $branchId, $productId, $copiedDeliveryId, $correctedDeliveryId): void {
    $deliveryIds = [$copiedDeliveryId, $correctedDeliveryId];
    $marks = implode(',', array_fill(0, count($deliveryIds), '?'));
    $receivingStmt = $db->prepare("SELECT id FROM dl_branch_receivings WHERE delivery_id IN ($marks)");
    $receivingStmt->execute($deliveryIds);
    $receivingIds = array_map('intval', $receivingStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($receivingIds !== []) {
        $receiptMarks = implode(',', array_fill(0, count($receivingIds), '?'));
        $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND entity_type = 'dl_branch_receivings' AND entity_id IN ($receiptMarks)")
            ->execute(array_map('strval', $receivingIds));
    }
    $db->prepare('DELETE FROM audit_logs WHERE module = ? AND branch_id IN (?, ?)')
        ->execute(['daily-ledger', $originId, $branchId]);
    $db->prepare('DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN (?, ?))')
        ->execute([$originId, $branchId]);
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE branch_id IN (?, ?)')->execute([$originId, $branchId]);
    $db->prepare("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN ($marks)")->execute($deliveryIds);
    $db->prepare("DELETE FROM dl_branch_receivings WHERE delivery_id IN ($marks)")->execute($deliveryIds);
    $db->prepare("DELETE FROM dl_deliveries WHERE id IN ($marks)")->execute($deliveryIds);
    $db->prepare('DELETE FROM dl_production_movements WHERE destination_branch_id IN (?, ?) OR product_id = ?')
        ->execute([$originId, $branchId, $productId]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?) AND product_id = ?')
        ->execute([$originId, $branchId, $productId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) AND product_id = ?')
        ->execute([$originId, $branchId, $productId]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$originId, $branchId]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$productId]);
};

$countFixtures = static function () use ($db, $originId, $branchId, $productId, $copiedDeliveryId, $correctedDeliveryId): array {
    $stmt = $db->prepare(
        'SELECT
          (SELECT COUNT(*) FROM dl_production_movements WHERE destination_branch_id IN (?, ?) OR product_id = ?) AS movements,
          (SELECT COUNT(*) FROM dl_branch_receivings WHERE branch_id IN (?, ?) OR delivery_id IN (?, ?)) AS receivings,
          (SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id IN (?, ?) AND product_id = ?) AS ledger'
    );
    $stmt->execute([
        $originId, $branchId, $productId,
        $originId, $branchId, $copiedDeliveryId, $correctedDeliveryId,
        $originId, $branchId, $productId,
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$runReceive = static function (array $payload) use ($payloadPath): array {
    file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));
    $output = [];
    $exit = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/daily_ledger_receipt_count_harness.php') . ' receive '
        . escapeshellarg($payloadPath) . ' 2>&1',
        $output,
        $exit
    );
    return ['exit' => $exit, 'raw' => implode("\n", $output)];
};

$cleanup();

try {
    $h->section('cashier modal defaults and request contract');
    $template = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl');
    $modalOutput = [];
    $modalExit = 0;
    exec(
        'node ' . escapeshellarg(__DIR__ . '/receive_modal_dispatch_harness.js') . ' '
        . escapeshellarg($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl') . ' 2>&1',
        $modalOutput,
        $modalExit
    );
    $modalRaw = implode("\n", $modalOutput);
    $modal = json_decode($modalRaw, true) ?: [];
    echo "ACCEPTANCE_MODAL=" . $modalRaw . "\n";
    $h->test(
        'incoming cashier modal pre-fills quantities but requires both receipt shifts before Receive',
        $modalExit === 0
        && ($modal['initialValues'] ?? null) === [10, 4]
        && ($modal['initiallyEnabled'] ?? true) === false
        && ($modal['enabledWithoutReceiving'] ?? true) === false
        && ($modal['enabledWithBoth'] ?? false) === true
        && ($modal['boundReceivingLocked'] ?? false) === true
        && ($modal['editableReceivingOnlyWhenUnlocked'] ?? false) === true,
        $modalRaw
    );
    $h->test(
        'receive payload carries receiving and production shifts',
        (($modal['untouchedPayload']['shift'] ?? null) === 'AM')
        && (($modal['untouchedPayload']['production_shift'] ?? null) === 'PM'),
        $modalRaw
    );
    $h->test(
        'zero-edit receive omits partial_qtys while a correction sends the complete corrected item map',
        !array_key_exists('partial_qtys', (array)($modal['untouchedPayload'] ?? []))
        && (($modal['correctedPayload']['partial_qtys']['995701']['101'] ?? null) === 8)
        && (($modal['correctedPayload']['partial_qtys']['995701']['102'] ?? null) === 4)
        && !array_key_exists('partial_qtys', (array)($modal['restoredPayload'] ?? [])),
        $modalRaw
    );

    $h->test(
        'guard copy tells the receiver to confirm as sent or correct a differing qty',
        str_contains(strtolower($template), 'confirm as sent, or correct the received qty if it differs')
        && !str_contains($template, 'Enter the physically counted Received value for every line')
    );
    $h->test(
        'paper DR entry still requires manually entered valid items',
        str_contains($template, 'validatePaperDelivery()')
        && str_contains($template, "this.errorMsg = 'Add at least one delivered item from the paper DR.'")
    );

    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, ?, 1)')
        ->execute([$originId, 'NR-ORIG', 'No Rekey Origin', 1]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, ?, 1)')
        ->execute([$branchId, 'NR-DEST', 'No Rekey Destination', 0]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 12.50, 1)')
        ->execute([$productId, 'NR-PROD', 'No Rekey Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$branchId, $productId]);

    $insertDelivery = static function (int $id, string $dr) use ($db, $originId, $branchId, $productId, $date): int {
        $db->prepare(
            'INSERT INTO dl_deliveries (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
             VALUES (?, "branch", ?, "branch", ?, ?, ?, "posted", ?)'
        )->execute([$id, $originId, $branchId, $dr, $date, $date . ' 07:00:00']);
        $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 10, "pcs", 12.50)')
            ->execute([$id, $productId]);
        return (int)$db->lastInsertId();
    };
    $copiedItemId = $insertDelivery($copiedDeliveryId, 'NR-COPIED-995701');
    $correctedItemId = $insertDelivery($correctedDeliveryId, 'NR-CORRECTED-995702');

    $h->section('real API receipt evidence');
    $copiedApi = $runReceive([
        'branch_id' => $branchId,
        'delivery_ids' => [$copiedDeliveryId],
        'withdrawal_ids' => [],
    ]);
    $copiedStmt = $db->prepare(
        'SELECT br.id, br.delivery_id, br.count_basis, bri.product_id, bri.quantity_received,
                n.finding_type, n.title
           FROM dl_branch_receivings br
           INNER JOIN dl_branch_receiving_items bri ON bri.receiving_id = br.id
           LEFT JOIN dl_integrity_notifications n
             ON n.entity_type = "dl_branch_receivings" AND n.entity_id = br.id AND n.finding_type = "uncounted_receipt"
          WHERE br.delivery_id = ?'
    );
    $copiedStmt->execute([$copiedDeliveryId]);
    $copiedRow = $copiedStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    echo 'ACCEPTANCE_COPIED_API=' . json_encode($copiedApi, JSON_UNESCAPED_SLASHES) . "\n";
    echo 'ACCEPTANCE_COPIED_DB=' . json_encode($copiedRow, JSON_UNESCAPED_SLASHES) . "\n";
    $h->test(
        'correct DR receives with no edits as copied and raises uncounted_receipt',
        $copiedApi['exit'] === 0
        && str_contains($copiedApi['raw'], '"ok":true')
        && ($copiedRow['count_basis'] ?? '') === 'copied'
        && (int)($copiedRow['quantity_received'] ?? -1) === 10
        && ($copiedRow['finding_type'] ?? '') === 'uncounted_receipt',
        $copiedApi['raw'] . "\n" . json_encode($copiedRow)
    );

    $correctedApi = $runReceive([
        'branch_id' => $branchId,
        'delivery_ids' => [$correctedDeliveryId],
        'withdrawal_ids' => [],
        'partial_qtys' => [
            (string)$correctedDeliveryId => [(string)$correctedItemId => 8],
        ],
    ]);
    $correctedStmt = $db->prepare(
        'SELECT br.id, br.delivery_id, br.count_basis, bri.product_id, bri.quantity_received,
                vf.sent_qty, vf.received_qty, vf.variance
           FROM dl_branch_receivings br
           INNER JOIN dl_branch_receiving_items bri ON bri.receiving_id = br.id
           LEFT JOIN dl_delivery_variance_flags vf ON vf.receiving_id = br.id AND vf.product_id = bri.product_id
          WHERE br.delivery_id = ?'
    );
    $correctedStmt->execute([$correctedDeliveryId]);
    $correctedRow = $correctedStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    echo 'ACCEPTANCE_CORRECTED_API=' . json_encode($correctedApi, JSON_UNESCAPED_SLASHES) . "\n";
    echo 'ACCEPTANCE_CORRECTED_DB=' . json_encode($correctedRow, JSON_UNESCAPED_SLASHES) . "\n";
    $h->test(
        'lower corrected qty receives as independently_counted and raises the existing variance',
        $correctedApi['exit'] === 0
        && str_contains($correctedApi['raw'], '"ok":true')
        && ($correctedRow['count_basis'] ?? '') === 'independently_counted'
        && (int)($correctedRow['quantity_received'] ?? -1) === 8
        && (int)($correctedRow['sent_qty'] ?? -1) === 10
        && (int)($correctedRow['received_qty'] ?? -1) === 8
        && (int)($correctedRow['variance'] ?? 0) === -2,
        $correctedApi['raw'] . "\n" . json_encode($correctedRow)
    );
} finally {
    $cleanup();
    $remaining = $countFixtures();
    echo 'ACCEPTANCE_CLEANUP_COUNTS=' . json_encode($remaining, JSON_UNESCAPED_SLASHES) . "\n";
    $h->test(
        'tenant 207 fixture cleanup leaves zero movement, receiving, and ledger rows',
        (int)($remaining['movements'] ?? -1) === 0
        && (int)($remaining['receivings'] ?? -1) === 0
        && (int)($remaining['ledger'] ?? -1) === 0,
        json_encode($remaining)
    );
    if (is_string($payloadPath) && is_file($payloadPath)) {
        unlink($payloadPath);
    }
}

$h->done();
