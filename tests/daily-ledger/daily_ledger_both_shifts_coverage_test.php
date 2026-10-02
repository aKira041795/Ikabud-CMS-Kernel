<?php

declare(strict_types=1);

/**
 * Permanent integration coverage for receiving-shift accountability.
 *
 * The suite drives the real HTTP handlers in subprocesses, asserts persisted
 * rows (including the shift-keyed stock ledger), and removes every 9964x
 * fixture in finally. Historical NULL receipt shifts are counted before and
 * after to prove that receipt handling does not backfill old evidence.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-both-shifts-coverage', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('templates/modules/daily-ledger/cashier/receive_modal.disyl');
$h->fingerprint('tests/daily-ledger/receive_modal_dispatch_harness.js');
$h->fingerprint('tests/daily-ledger/daily_ledger_both_shifts_harness.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$originId = 99641;
$branchId = 99642;
$productId = 99641;
$boundUserId = 99641;
$unboundUserId = 99642;
$boundDeliveryId = 996401;
$unboundDeliveryId = 996402;
$withdrawalId = 996401;
$date = dl_businessDate();
$paperDr = 'SHIFT-PAPER-996403';
$payloadPath = tempnam(sys_get_temp_dir(), 'dl-both-shifts-');
$harnessPath = __DIR__ . '/daily_ledger_both_shifts_harness.php';

$cleanup = static function () use (
    $db, $originId, $branchId, $productId, $boundUserId, $unboundUserId,
    $boundDeliveryId, $unboundDeliveryId, $withdrawalId, $paperDr
): void {
    $db->prepare('DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN (?, ?))')
        ->execute([$originId, $branchId]);
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE branch_id IN (?, ?)')->execute([$originId, $branchId]);
    $db->prepare('DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN (?, ?) OR receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN (?, ?))')
        ->execute([$boundDeliveryId, $unboundDeliveryId, $originId, $branchId]);
    $db->prepare('DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN (?, ?))')
        ->execute([$originId, $branchId]);
    $db->prepare('DELETE FROM dl_branch_receivings WHERE branch_id IN (?, ?) OR delivery_id IN (SELECT id FROM dl_deliveries WHERE destination_id IN (?, ?))')
        ->execute([$originId, $branchId, $originId, $branchId]);
    $db->prepare('DELETE FROM audit_logs WHERE branch_id IN (?, ?) OR (entity_type = "dl_deliveries" AND entity_id IN (SELECT CAST(id AS CHAR) FROM dl_deliveries WHERE destination_id IN (?, ?)))')
        ->execute([$originId, $branchId, $originId, $branchId]);
    $db->prepare('DELETE FROM dl_cashier_withdrawals WHERE id = ? OR branch_id IN (?, ?) OR target_branch_id IN (?, ?)')
        ->execute([$withdrawalId, $originId, $branchId, $originId, $branchId]);
    $db->prepare('DELETE FROM dl_delivery_items WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE destination_id IN (?, ?) OR origin_id IN (?, ?))')
        ->execute([$originId, $branchId, $originId, $branchId]);
    $db->prepare('DELETE FROM dl_deliveries WHERE id IN (?, ?) OR destination_id IN (?, ?) OR dr_number = ?')
        ->execute([$boundDeliveryId, $unboundDeliveryId, $originId, $branchId, $paperDr]);
    $db->prepare('DELETE FROM dl_production_movements WHERE product_id = ? OR destination_branch_id IN (?, ?)')
        ->execute([$productId, $originId, $branchId]);
    $db->prepare('DELETE FROM dl_production_runs WHERE product_id = ? OR destination_branch_id IN (?, ?)')
        ->execute([$productId, $originId, $branchId]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE product_id = ? OR branch_id IN (?, ?)')
        ->execute([$productId, $originId, $branchId]);
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE product_id = ? OR commissary_branch_id IN (?, ?)')
        ->execute([$productId, $originId, $branchId]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?)')->execute([$originId, $branchId]);
    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id IN (?, ?)')->execute([$originId, $branchId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE product_id = ? OR branch_id IN (?, ?)')
        ->execute([$productId, $originId, $branchId]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (?, ?)')->execute([$boundUserId, $unboundUserId]);
    $db->prepare('DELETE FROM dl_users WHERE id IN (?, ?)')->execute([$boundUserId, $unboundUserId]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$productId]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$originId, $branchId]);
};

$countFixtures = static function () use ($db, $originId, $branchId, $productId, $boundUserId, $unboundUserId): array {
    $queries = [
        'branches' => "SELECT COUNT(*) FROM dl_branches WHERE id IN ({$originId},{$branchId})",
        'products' => "SELECT COUNT(*) FROM dl_products WHERE id = {$productId}",
        'users' => "SELECT COUNT(*) FROM dl_users WHERE id IN ({$boundUserId},{$unboundUserId})",
        'user_branches' => "SELECT COUNT(*) FROM dl_user_branches WHERE user_id IN ({$boundUserId},{$unboundUserId})",
        'branch_products' => "SELECT COUNT(*) FROM dl_branch_products WHERE branch_id IN ({$originId},{$branchId}) OR product_id = {$productId}",
        'deliveries' => "SELECT COUNT(*) FROM dl_deliveries WHERE destination_id IN ({$originId},{$branchId}) OR origin_id IN ({$originId},{$branchId})",
        'delivery_items' => "SELECT COUNT(*) FROM dl_delivery_items WHERE product_id = {$productId}",
        'receivings' => "SELECT COUNT(*) FROM dl_branch_receivings WHERE branch_id IN ({$originId},{$branchId})",
        'receiving_items' => "SELECT COUNT(*) FROM dl_branch_receiving_items WHERE product_id = {$productId}",
        'withdrawals' => "SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id IN ({$originId},{$branchId}) OR target_branch_id IN ({$originId},{$branchId})",
        'variance_flags' => "SELECT COUNT(*) FROM dl_delivery_variance_flags WHERE product_id = {$productId}",
        'notifications' => "SELECT COUNT(*) FROM dl_integrity_notifications WHERE branch_id IN ({$originId},{$branchId}) OR (entity_type = 'dl_cashier_withdrawals' AND entity_id = 996401)",
        'notification_recipients' => "SELECT COUNT(*) FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN ({$originId},{$branchId}))",
        'ledger' => "SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id IN ({$originId},{$branchId}) OR product_id = {$productId}",
        'commissary_ledger' => "SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$originId},{$branchId}) OR product_id = {$productId}",
        'movements' => "SELECT COUNT(*) FROM dl_production_movements WHERE destination_branch_id IN ({$originId},{$branchId}) OR product_id = {$productId}",
        'production_runs' => "SELECT COUNT(*) FROM dl_production_runs WHERE destination_branch_id IN ({$originId},{$branchId}) OR product_id = {$productId}",
        'shift_status' => "SELECT COUNT(*) FROM dl_ledger_shift_status WHERE branch_id IN ({$originId},{$branchId})",
        'day_status' => "SELECT COUNT(*) FROM dl_ledger_day_status WHERE branch_id IN ({$originId},{$branchId})",
        'audit_logs' => "SELECT COUNT(*) FROM audit_logs WHERE branch_id IN ({$originId},{$branchId}) OR entity_id IN ('996401','996402') OR new_data LIKE '%9964%' OR old_data LIKE '%9964%'",
    ];
    $counts = [];
    foreach ($queries as $name => $sql) {
        $counts[$name] = (int)$db->query($sql)->fetchColumn();
    }
    return $counts;
};

$nullCounts = static function () use ($db): array {
    return [
        'receivings' => (int)$db->query('SELECT COUNT(*) FROM dl_branch_receivings WHERE received_shift IS NULL')->fetchColumn(),
        'withdrawals' => (int)$db->query('SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE received_shift IS NULL')->fetchColumn(),
    ];
};

$runApi = static function (string $mode, array $payload, int $actorId) use ($payloadPath, $harnessPath): array {
    file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));
    $output = [];
    $exit = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessPath) . ' '
        . escapeshellarg($mode) . ' ' . escapeshellarg($payloadPath) . ' ' . escapeshellarg((string)$actorId) . ' 2>&1',
        $output,
        $exit
    );
    $raw = implode("\n", $output);
    $status = null;
    if (preg_match('/\n__HTTP_STATUS__=(\d+)\s*$/', $raw, $match)) {
        $status = (int)$match[1];
        $raw = (string)preg_replace('/\n__HTTP_STATUS__=\d+\s*$/', '', $raw);
    }
    return ['exit' => $exit, 'status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
};

$cleanup();
$historicalBefore = $nullCounts();
echo 'ACCEPTANCE_NULL_COUNTS_BEFORE=' . json_encode($historicalBefore, JSON_UNESCAPED_SLASHES) . "\n";

try {
    $h->section('modal payload contract');
    $modalOutput = [];
    $modalExit = 0;
    exec('node ' . escapeshellarg(__DIR__ . '/receive_modal_dispatch_harness.js') . ' '
        . escapeshellarg($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl') . ' 2>&1', $modalOutput, $modalExit);
    $modalRaw = implode("\n", $modalOutput);
    $modal = json_decode($modalRaw, true) ?: [];
    echo 'ACCEPTANCE_MODAL=' . $modalRaw . "\n";
    $h->test('accept requires both shifts and bound cashier receiving control is locked',
        $modalExit === 0
        && ($modal['initiallyEnabled'] ?? true) === false
        && ($modal['enabledWithoutReceiving'] ?? true) === false
        && ($modal['enabledWithBoth'] ?? false) === true
        && ($modal['boundReceivingLocked'] ?? false) === true
        && ($modal['editableReceivingOnlyWhenUnlocked'] ?? false) === true,
        $modalRaw);
    $h->test('modal dispatch carries shift and production_shift',
        ($modal['untouchedPayload']['shift'] ?? null) === 'AM'
        && ($modal['untouchedPayload']['production_shift'] ?? null) === 'PM',
        $modalRaw);

    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, 1, 1)')
        ->execute([$originId, 'SHIFT-ORIG', 'Both Shifts Origin']);
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, 0, 1)')
        ->execute([$branchId, 'SHIFT-DEST', 'Both Shifts Destination']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 15, 1)')
        ->execute([$productId, 'SHIFT-PROD', 'Both Shifts Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$branchId, $productId]);
    // These are synthetic, non-login users; no existing password is read or changed.
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (?, ?, ?, ?, "cashier", ?, 1)')
        ->execute([$boundUserId, 'both-shifts-bound', 'fixture-not-a-login', 'Bound AM Fixture', 'AM']);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (?, ?, ?, ?, "cashier", NULL, 1)')
        ->execute([$unboundUserId, 'both-shifts-unbound', 'fixture-not-a-login', 'Unbound Fixture']);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?, ?), (?, ?)')
        ->execute([$boundUserId, $branchId, $unboundUserId, $branchId]);

    $insertDelivery = static function (int $id, string $dr) use ($db, $originId, $branchId, $productId, $date): void {
        $db->prepare('INSERT INTO dl_deliveries
            (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, production_shift, status, posted_at)
            VALUES (?, "commissary", ?, "branch", ?, ?, ?, "AM", "posted", NOW())')
            ->execute([$id, $originId, $branchId, $dr, $date]);
        $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 5, "pcs", 15)')
            ->execute([$id, $productId]);
    };
    $insertDelivery($boundDeliveryId, 'SHIFT-BOUND-996401');
    $insertDelivery($unboundDeliveryId, 'SHIFT-UNBOUND-996402');

    $h->section('real API and database shift evidence');
    $boundApi = $runApi('receive', [
        'branch_id' => $branchId, 'delivery_ids' => [$boundDeliveryId], 'withdrawal_ids' => [],
        'shift' => 'PM', 'production_shift' => 'PM',
    ], $boundUserId);
    $stmt = $db->prepare('SELECT received_shift FROM dl_branch_receivings WHERE delivery_id = ?');
    $stmt->execute([$boundDeliveryId]);
    $boundStored = $stmt->fetchColumn();
    echo 'ACCEPTANCE_BOUND_OVERRIDE=' . json_encode(['api' => $boundApi, 'stored_received_shift' => $boundStored], JSON_UNESCAPED_SLASHES) . "\n";
    $h->test('bound AM cashier posting shift=PM is stored as AM',
        $boundApi['exit'] === 0 && ($boundApi['body']['ok'] ?? false) === true
        && ($boundApi['body']['received_shift'] ?? null) === 'AM' && $boundStored === 'AM',
        json_encode($boundApi) . ' stored=' . var_export($boundStored, true));

    $unboundApi = $runApi('receive', [
        'branch_id' => $branchId, 'delivery_ids' => [$unboundDeliveryId], 'withdrawal_ids' => [],
        'shift' => 'PM', 'production_shift' => 'AM',
    ], $unboundUserId);
    $stmt->execute([$unboundDeliveryId]);
    $unboundStored = $stmt->fetchColumn();
    $ledgerStmt = $db->prepare('SELECT shift, addtl FROM dl_daily_ledger WHERE branch_id = ? AND product_id = ? AND ledger_date = ? ORDER BY shift');
    $ledgerStmt->execute([$branchId, $productId, $date]);
    $ledgerRows = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo 'ACCEPTANCE_UNBOUND_PM=' . json_encode(['api' => $unboundApi, 'stored_received_shift' => $unboundStored, 'ledger' => $ledgerRows], JSON_UNESCAPED_SLASHES) . "\n";
    $pmRows = array_values(array_filter($ledgerRows, static fn(array $row): bool => $row['shift'] === 'PM'));
    $h->test('unbound cashier PM is persisted and stock lands in the PM ledger row',
        $unboundApi['exit'] === 0 && ($unboundApi['body']['received_shift'] ?? null) === 'PM'
        && $unboundStored === 'PM' && count($pmRows) === 1 && (int)$pmRows[0]['addtl'] === 5,
        json_encode($ledgerRows));

    $db->prepare('INSERT INTO dl_cashier_withdrawals
        (id, branch_id, product_id, ledger_date, shift, withdrawal_type, dr_number, target_branch_id, quantity, dedup_hash)
        VALUES (?, ?, ?, ?, "AM", "delivery", ?, ?, 3, ?)')
        ->execute([$withdrawalId, $originId, $productId, $date, 'SHIFT-INFORMAL-996401', $branchId, sha1('SHIFT-INFORMAL-996401')]);
    $informalApi = $runApi('receive', [
        'branch_id' => $branchId, 'delivery_ids' => [], 'withdrawal_ids' => [$withdrawalId],
        'shift' => 'PM', 'production_shift' => 'AM',
    ], $unboundUserId);
    $withdrawalStmt = $db->prepare('SELECT shift, received_shift FROM dl_cashier_withdrawals WHERE id = ?');
    $withdrawalStmt->execute([$withdrawalId]);
    $withdrawal = $withdrawalStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    echo 'ACCEPTANCE_FORMAL_INFORMAL=' . json_encode([
        'formal_received_shift' => $unboundStored, 'informal_api' => $informalApi, 'informal_row' => $withdrawal,
    ], JSON_UNESCAPED_SLASHES) . "\n";
    $h->test('formal and informal receipts persist received_shift while informal keeps sending shift',
        $unboundStored === 'PM' && ($informalApi['body']['ok'] ?? false) === true
        && ($withdrawal['received_shift'] ?? null) === 'PM' && ($withdrawal['shift'] ?? null) === 'AM',
        json_encode($withdrawal));

    $paperApi = $runApi('paper', [
        'branch_id' => $branchId, 'shift' => 'AM', 'production_shift' => 'PM',
        'origin_type' => 'commissary', 'origin_id' => null,
        'delivery_date' => $date, 'receive_date' => $date, 'dr_number' => $paperDr,
        'items' => [['product_id' => $productId, 'quantity' => 2, 'unit' => 'pcs']],
    ], $boundUserId);
    $paperStmt = $db->prepare('SELECT id, production_shift FROM dl_deliveries WHERE dr_number = ? AND destination_id = ?');
    $paperStmt->execute([$paperDr, $branchId]);
    $paperRow = $paperStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    echo 'ACCEPTANCE_PAPER_PRODUCTION_SHIFT=' . json_encode(['api' => $paperApi, 'delivery' => $paperRow], JSON_UNESCAPED_SLASHES) . "\n";
    $h->test('paper DR capture stores production_shift on its created delivery',
        $paperApi['exit'] === 0 && ($paperApi['body']['ok'] ?? false) === true
        && ($paperApi['body']['production_shift'] ?? null) === 'PM'
        && ($paperRow['production_shift'] ?? null) === 'PM',
        json_encode($paperApi) . "\n" . json_encode($paperRow));
} finally {
    $cleanup();
    $remaining = $countFixtures();
    $historicalAfter = $nullCounts();
    echo 'ACCEPTANCE_CLEANUP_COUNTS=' . json_encode($remaining, JSON_UNESCAPED_SLASHES) . "\n";
    echo 'ACCEPTANCE_NULL_COUNTS_AFTER=' . json_encode($historicalAfter, JSON_UNESCAPED_SLASHES) . "\n";
    $h->test('all both-shifts fixture rows are deleted and re-query returns zero',
        array_sum($remaining) === 0, json_encode($remaining));
    $h->test('historical NULL received_shift counts are unchanged (no backfill)',
        $historicalAfter === $historicalBefore,
        json_encode(['before' => $historicalBefore, 'after' => $historicalAfter]));
    if (is_string($payloadPath) && is_file($payloadPath)) {
        unlink($payloadPath);
    }
}

$h->done();
