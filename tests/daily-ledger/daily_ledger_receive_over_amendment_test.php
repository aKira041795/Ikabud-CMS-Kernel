<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-receive-over-amendment', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$originId = 99581;
$branchId = 99582;
$productId = 99581;
$overDeliveryId = 995801;
$shortDeliveryId = 995802;
$withdrawalIds = [995811, 995812, 995813]; // over, exact, short
$date = '2031-06-18';
$payloadPath = tempnam(sys_get_temp_dir(), 'dl-receive-over-');

$cleanup = static function () use ($db, $originId, $branchId, $productId, $overDeliveryId, $shortDeliveryId, $withdrawalIds): void {
    $deliveryIds = [$overDeliveryId, $shortDeliveryId];
    $dMarks = implode(',', array_fill(0, count($deliveryIds), '?'));
    $wMarks = implode(',', array_fill(0, count($withdrawalIds), '?'));
    $receivingStmt = $db->prepare("SELECT id FROM dl_branch_receivings WHERE delivery_id IN ($dMarks)");
    $receivingStmt->execute($deliveryIds);
    $receivingIds = array_map('intval', $receivingStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($receivingIds !== []) {
        $rMarks = implode(',', array_fill(0, count($receivingIds), '?'));
        $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND entity_type = 'dl_branch_receivings' AND entity_id IN ($rMarks)")
            ->execute(array_map('strval', $receivingIds));
    }
    $db->prepare("DELETE FROM dl_integrity_notifications WHERE entity_type = 'dl_cashier_withdrawals' AND entity_id IN ($wMarks)")
        ->execute($withdrawalIds);
    $db->prepare("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN ($dMarks)")->execute($deliveryIds);
    $db->prepare("DELETE FROM dl_branch_receivings WHERE delivery_id IN ($dMarks)")->execute($deliveryIds);
    $db->prepare("DELETE FROM dl_deliveries WHERE id IN ($dMarks)")->execute($deliveryIds);
    $db->prepare("DELETE FROM dl_cashier_withdrawals WHERE id IN ($wMarks)")->execute($withdrawalIds);
    $db->prepare('DELETE FROM dl_production_movements WHERE destination_branch_id IN (?, ?) OR product_id = ?')
        ->execute([$originId, $branchId, $productId]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?) AND product_id = ?')
        ->execute([$originId, $branchId, $productId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) AND product_id = ?')
        ->execute([$originId, $branchId, $productId]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$originId, $branchId]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$productId]);
};

$countFixtures = static function () use ($db, $originId, $branchId, $productId, $overDeliveryId, $shortDeliveryId, $withdrawalIds): array {
    $deliveryIds = [$overDeliveryId, $shortDeliveryId];
    $dMarks = implode(',', array_fill(0, count($deliveryIds), '?'));
    $wMarks = implode(',', array_fill(0, count($withdrawalIds), '?'));
    $sql = "SELECT
        (SELECT COUNT(*) FROM dl_production_movements WHERE destination_branch_id IN (?, ?) OR product_id = ?) movements,
        (SELECT COUNT(*) FROM dl_branch_receivings WHERE branch_id IN (?, ?) OR delivery_id IN ($dMarks)) receivings,
        (SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id IN (?, ?) AND product_id = ?) ledger,
        (SELECT COUNT(*) FROM dl_deliveries WHERE id IN ($dMarks)) deliveries,
        (SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE id IN ($wMarks)) withdrawals,
        (SELECT COUNT(*) FROM dl_delivery_variance_flags WHERE delivery_id IN ($dMarks)) variance_flags,
        (SELECT COUNT(*) FROM dl_integrity_notifications WHERE entity_type = 'dl_cashier_withdrawals' AND entity_id IN ($wMarks)) notifications";
    $bind = [
        $originId, $branchId, $productId,
        $originId, $branchId, ...$deliveryIds,
        $originId, $branchId, $productId,
        ...$deliveryIds, ...$withdrawalIds, ...$deliveryIds, ...$withdrawalIds,
    ];
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$runReceive = static function (array $payload) use ($payloadPath): array {
    file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));
    $output = [];
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_receipt_count_harness.php')
        . ' receive ' . escapeshellarg($payloadPath) . ' 2>&1', $output, $exit);
    return ['exit' => $exit, 'raw' => implode("\n", $output)];
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, 1, 1)')
        ->execute([$originId, 'OA-ORIG', 'Over Amendment Commissary']);
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, 0, 1)')
        ->execute([$branchId, 'OA-DEST', 'Over Amendment Branch']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 12.50, 1)')
        ->execute([$productId, 'OA-PROD', 'Over Amendment Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$branchId, $productId]);

    $insertDelivery = static function (int $id, string $dr) use ($db, $originId, $branchId, $productId, $date): int {
        $db->prepare('INSERT INTO dl_deliveries
            (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
            VALUES (?, "commissary", ?, "branch", ?, ?, ?, "posted", ?)')
            ->execute([$id, $originId, $branchId, $dr, $date, $date . ' 07:00:00']);
        $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot)
            VALUES (?, ?, 10, "pcs", 12.50)')->execute([$id, $productId]);
        return (int)$db->lastInsertId();
    };
    $overItemId = $insertDelivery($overDeliveryId, 'OA-FORMAL-OVER');
    $shortItemId = $insertDelivery($shortDeliveryId, 'OA-FORMAL-SHORT');

    $modalOutput = [];
    $modalExit = 0;
    exec('node ' . escapeshellarg(__DIR__ . '/receive_modal_dispatch_harness.js') . ' '
        . escapeshellarg($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl') . ' 2>&1', $modalOutput, $modalExit);
    $modalRaw = implode("\n", $modalOutput);
    $modal = json_decode($modalRaw, true) ?: [];
    $template = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl');
    echo 'AMENDMENT_CLIENT=' . $modalRaw . "\n";
    $h->test('client accepts an over correction without a max attribute or clamp',
        $modalExit === 0
        && (($modal['overPayload']['partial_qtys']['995701']['101'] ?? null) === 13)
        && !str_contains($template, ':max="it.quantity"')
        && !str_contains($template, 'Math.min(parsed, it.quantity)'), $modalRaw);

    $formalOverApi = $runReceive([
        'branch_id' => $branchId, 'delivery_ids' => [$overDeliveryId], 'withdrawal_ids' => [],
        'partial_qtys' => [(string)$overDeliveryId => [(string)$overItemId => 13]],
    ]);
    $formalStmt = $db->prepare('SELECT br.id receiving_id, br.count_basis, bri.quantity_received,
        vf.sent_qty, vf.received_qty, vf.variance
        FROM dl_branch_receivings br
        JOIN dl_branch_receiving_items bri ON bri.receiving_id = br.id
        LEFT JOIN dl_delivery_variance_flags vf ON vf.receiving_id = br.id AND vf.product_id = bri.product_id
        WHERE br.delivery_id = ?');
    $formalStmt->execute([$overDeliveryId]);
    $formalOver = $formalStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    echo 'ACCEPTANCE_7_FORMAL_OVER_API=' . json_encode($formalOverApi, JSON_UNESCAPED_SLASHES) . "\n";
    echo 'ACCEPTANCE_7_FORMAL_OVER_DB=' . json_encode($formalOver, JSON_UNESCAPED_SLASHES) . "\n";
    $h->test('7 formal over-receipt persists and creates a positive variance flag',
        $formalOverApi['exit'] === 0 && str_contains($formalOverApi['raw'], '"ok":true')
        && (int)($formalOver['quantity_received'] ?? -1) === 13
        && (int)($formalOver['sent_qty'] ?? -1) === 10
        && (int)($formalOver['received_qty'] ?? -1) === 13
        && (int)($formalOver['variance'] ?? 0) === 3, $formalOverApi['raw'] . "\n" . json_encode($formalOver));

    $insertWithdrawal = $db->prepare('INSERT INTO dl_cashier_withdrawals
        (id, branch_id, product_id, ledger_date, withdrawal_type, dr_number, target_branch_id, quantity, dedup_hash)
        VALUES (?, ?, ?, ?, "delivery", ?, ?, 10, ?)');
    $insertWithdrawal->execute([$withdrawalIds[0], $originId, $productId, $date, 'OA-INFORMAL-OVER', $branchId, sha1('OA-INFORMAL-OVER')]);
    $insertWithdrawal->execute([$withdrawalIds[1], $originId, $productId, $date, 'OA-INFORMAL-EXACT', $branchId, sha1('OA-INFORMAL-EXACT')]);
    $insertWithdrawal->execute([$withdrawalIds[2], $originId, $productId, $date, 'OA-INFORMAL-SHORT', $branchId, sha1('OA-INFORMAL-SHORT')]);

    $informalCases = [[$withdrawalIds[0], 13, 'OVER'], [$withdrawalIds[1], 10, 'EXACT'], [$withdrawalIds[2], 8, 'SHORT']];
    $informalEvidence = [];
    foreach ($informalCases as [$withdrawalId, $received, $label]) {
        $api = $runReceive([
            'branch_id' => $branchId, 'delivery_ids' => [], 'withdrawal_ids' => [$withdrawalId],
            'informal_partial_qtys' => [(string)$withdrawalId => $received],
        ]);
        $stmt = $db->prepare('SELECT w.id, w.quantity sent_qty, w.received_qty, w.received_at,
            n.id notification_id, n.finding_type, n.title,
            (SELECT COUNT(*) FROM dl_integrity_notification_recipients nr
              JOIN dl_users u ON u.id = nr.user_id
             WHERE nr.notification_id = n.id AND u.role = "admin" AND u.is_active = 1) admin_recipients
            FROM dl_cashier_withdrawals w
            LEFT JOIN dl_integrity_notifications n ON n.entity_type = "dl_cashier_withdrawals" AND n.entity_id = w.id
            WHERE w.id = ?');
        $stmt->execute([$withdrawalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $informalEvidence[$label] = ['api' => $api, 'db' => $row];
        echo 'ACCEPTANCE_7B_INFORMAL_' . $label . '_API=' . json_encode($api, JSON_UNESCAPED_SLASHES) . "\n";
        echo 'ACCEPTANCE_7B_INFORMAL_' . $label . '_DB=' . json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
    }
    $over = $informalEvidence['OVER'];
    $exact = $informalEvidence['EXACT'];
    $shortInformal = $informalEvidence['SHORT'];
    $h->test('7b informal over persists and is surfaced to an active admin',
        $over['api']['exit'] === 0 && str_contains($over['api']['raw'], '"ok":true')
        && (int)($over['db']['received_qty'] ?? -1) === 13
        && ($over['db']['finding_type'] ?? '') === 'receipt_mismatch'
        && (int)($over['db']['admin_recipients'] ?? 0) > 0, json_encode($over));
    $h->test('7b exact informal receipt stays quiet',
        $exact['api']['exit'] === 0 && str_contains($exact['api']['raw'], '"ok":true')
        && (int)($exact['db']['received_qty'] ?? -1) === 10
        && $exact['db']['notification_id'] === null, json_encode($exact));
    $h->test('informal short receipt also raises the distinct mismatch notification',
        $shortInformal['api']['exit'] === 0 && (int)($shortInformal['db']['received_qty'] ?? -1) === 8
        && ($shortInformal['db']['finding_type'] ?? '') === 'receipt_mismatch', json_encode($shortInformal));

    $formalShortApi = $runReceive([
        'branch_id' => $branchId, 'delivery_ids' => [$shortDeliveryId], 'withdrawal_ids' => [],
        'partial_qtys' => [(string)$shortDeliveryId => [(string)$shortItemId => 8]],
    ]);
    $formalStmt->execute([$shortDeliveryId]);
    $formalShort = $formalStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    echo 'ACCEPTANCE_8_FORMAL_SHORT_API=' . json_encode($formalShortApi, JSON_UNESCAPED_SLASHES) . "\n";
    echo 'ACCEPTANCE_8_FORMAL_SHORT_DB=' . json_encode($formalShort, JSON_UNESCAPED_SLASHES) . "\n";
    $h->test('8 formal short receipt still persists and creates a negative variance flag',
        $formalShortApi['exit'] === 0 && str_contains($formalShortApi['raw'], '"ok":true')
        && (int)($formalShort['quantity_received'] ?? -1) === 8
        && (int)($formalShort['sent_qty'] ?? -1) === 10
        && (int)($formalShort['received_qty'] ?? -1) === 8
        && (int)($formalShort['variance'] ?? 0) === -2, $formalShortApi['raw'] . "\n" . json_encode($formalShort));
} finally {
    $cleanup();
    $remaining = $countFixtures();
    echo 'ACCEPTANCE_9_CLEANUP_COUNTS=' . json_encode($remaining, JSON_UNESCAPED_SLASHES) . "\n";
    $h->test('9 amendment acceptance fixtures are deleted',
        array_sum(array_map('intval', $remaining)) === 0, json_encode($remaining));
    if (is_string($payloadPath) && is_file($payloadPath)) unlink($payloadPath);
}

$h->done();
