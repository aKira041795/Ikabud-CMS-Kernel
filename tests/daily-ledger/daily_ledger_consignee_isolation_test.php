<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-consignee-isolation', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('templates/modules/daily-ledger/cashier/dispatch_modal.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_consignee_isolation_harness.php');
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt', 'capability.call');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$source = 99741;
$overlap = 99742; // destination branch id and consignee id deliberately collide
$commissary = 99743;
$product = 99741;
$cashier = 99741;
$admin = 99744;
$date = dl_businessDate();
$drBranch = 'CONSIG-FIX-BRANCH-9974';
$drConsignee = 'CONSIG-FIX-CONS-9974';
$drLocked = 'CONSIG-FIX-LOCK-9974';
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-consignee-');
$harness = __DIR__ . '/daily_ledger_consignee_isolation_harness.php';
$previousFormal = dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? '0';

$runApi = static function (string $mode, array $body, int $actor, string $role = 'cashier') use ($payloadFile, $harness): array {
    file_put_contents($payloadFile, json_encode($body, JSON_THROW_ON_ERROR));
    $lines = []; $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($payloadFile) . ' ' . $actor . ' ' . escapeshellarg($role) . ' 2>&1', $lines, $exit);
    $raw = implode("\n", $lines);
    $status = null;
    if (preg_match('/\n__HTTP_STATUS__=(\d+)\s*$/', $raw, $m)) { $status = (int)$m[1]; $raw = (string)preg_replace('/\n__HTTP_STATUS__=\d+\s*$/', '', $raw); }
    return ['exit' => $exit, 'status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
};

$cleanup = static function () use ($db, $source, $overlap, $commissary, $product, $cashier, $admin): void {
    $ids = $db->query("SELECT id FROM dl_deliveries WHERE origin_id = {$source} OR consignee_id = {$overlap} OR destination_id = {$overlap}")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($ids !== []) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM dl_consignee_ledger_effects WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE delivery_id IN ($marks))")->execute($ids);
        $db->prepare("DELETE FROM dl_branch_receivings WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_delivery_items WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_deliveries WHERE id IN ($marks)")->execute($ids);
    }
    $db->prepare('DELETE FROM audit_logs WHERE module = "daily-ledger" AND branch_id IN (?, ?, ?)')->execute([$source, $overlap, $commissary]);
    $db->prepare('DELETE FROM dl_consignee_ledger WHERE consignee_id = ? OR product_id = ?')->execute([$overlap, $product]);
    // Since the consignee-depletion slice, a consignee dispatch also debits the
    // supplying commissary's projection. Delete the fixture's projection row too,
    // otherwise the FK from dl_commissary_product_ledger.product_id blocks the
    // product delete below (fixture cleanup, not a weakened assertion).
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN (?, ?, ?) OR product_id = ?')->execute([$source, $overlap, $commissary, $product]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?) OR product_id = ?')->execute([$source, $overlap, $product]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?, ?)')->execute([$source, $overlap, $commissary]);
    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id IN (?, ?, ?)')->execute([$source, $overlap, $commissary]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?, ?) OR product_id = ?')->execute([$source, $overlap, $commissary, $product]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (?, ?)')->execute([$cashier, $admin]);
    $db->prepare('DELETE FROM dl_users WHERE id IN (?, ?)')->execute([$cashier, $admin]);
    $db->prepare('DELETE FROM dl_consignees WHERE id = ?')->execute([$overlap]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$product]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?, ?)')->execute([$source, $overlap, $commissary]);
};

$cleanup();
dlPersistModuleSettings(['formal_delivery_workflow_enabled' => '1']);
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, assigned_commissary_id, is_commissary, is_active) VALUES (?, ?, ?, NULL, 1, 1), (?, ?, ?, ?, 0, 1), (?, ?, ?, ?, 0, 1)')->execute([$commissary, 'CF-COM-9974', 'Fixture Commissary', $source, 'CF-SRC-9974', 'Fixture Source', $commissary, $overlap, 'CF-DST-9974', 'Fixture Destination', $commissary]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?, ?, ?, ?, 1)')->execute([$overlap, 'CF-CONS-9974', 'Fixture Consignee Sentinel', $commissary]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 10, 1)')->execute([$product, 'CF-P-9974', 'Fixture Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1), (?, ?, 1)')->execute([$source, $product, $overlap, $product]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (?, ?, "fixture", "Consignee Cashier", "cashier", "PM", 1), (?, ?, "fixture", "Consignee Admin", "admin", NULL, 1)')->execute([$cashier, 'consignee-cashier-9974', $admin, 'consignee-admin-9974']);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?, ?)')->execute([$cashier, $source]);

    $common = ['branch_id' => $source, 'shift' => 'PM', 'delivery_date' => $date, 'receiving_shift' => 'PM', 'items' => [['product_id' => $product, 'quantity' => 11, 'unit' => 'pcs']]];
    $branchResult = $runApi('dispatch', $common + ['dr_number' => $drBranch, 'destination_type' => 'branch', 'destination_id' => $overlap], $cashier);
    $consigneeResult = $runApi('dispatch', array_replace($common, ['dr_number' => $drConsignee, 'destination_type' => 'consignee', 'destination_id' => null, 'consignee_id' => $overlap, 'items' => [['product_id' => $product, 'quantity' => 17, 'unit' => 'pcs']]]), $cashier);
    $consigneeDeliveryId = (int)($consigneeResult['body']['delivery_id'] ?? 0);

    $incoming = $runApi('incoming', ['branch_id' => $overlap], $admin, 'admin');
    $incomingIds = [];
    foreach (($incoming['body']['deliveries'] ?? []) as $group) foreach (($group['delivery_ids'] ?? []) as $id) $incomingIds[] = (int)$id;
    // PIN, not discriminating. Chair measured 2026-10-08: mutating the delivery to carry
    // destination_id = consignee_id (the rejected shared-id design) AND relaxing the feed's
    // destination_type filter STILL leaves this assertion green. Three independent barriers hold the
    // property: (1) the feed's destination_type = "branch" filter, (2) destination_id IS NULL for a
    // consignee, and (3) the feed's EXISTS on an active dl_branch_receivings row - which a consignee
    // never has, because by design there is no receiving step. So a single fault cannot break it, and
    // this case cannot detect one. Case B below IS the discriminating leak probe. Keep this as a
    // regression pin on the user-visible promise; do not read it as proof of isolation.
    $h->test('A pin: real Receive Stock feed includes branch DR and excludes overlapping-id consignee DR', ($branchResult['body']['ok'] ?? false) && ($consigneeResult['body']['ok'] ?? false) && count($incomingIds) === 1 && !in_array($consigneeDeliveryId, $incomingIds, true));

    $branchCollisionCount = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries d INNER JOIN dl_branches b ON b.id = d.destination_id WHERE b.id = {$overlap} AND d.dr_number IN ('{$drBranch}','{$drConsignee}')")->fetchColumn();
    $consigneeInBranches = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id = {$overlap} AND code = 'CF-CONS-9974'")->fetchColumn();
    $h->test('B discriminating: consignee is absent from branch master/report join', $branchCollisionCount === 1 && $consigneeInBranches === 0);

    $adminList = $runApi('deliveries', ['destination_type' => 'consignee'], $admin, 'admin');
    $listed = array_values(array_filter($adminList['body']['deliveries'] ?? [], static fn(array $d): bool => (int)$d['id'] === $consigneeDeliveryId));
    $h->test('C discriminating: admin Deliveries identifies consignee by name and code', count($listed) === 1 && str_contains((string)$listed[0]['destination_label'], 'Fixture Consignee Sentinel') && str_contains((string)$listed[0]['destination_label'], 'CF-CONS-9974'));

    $withdraw = (int)$db->query("SELECT withdraw FROM dl_daily_ledger WHERE branch_id = {$source} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'PM'")->fetchColumn();
    $credit = (int)$db->query("SELECT addtl - withdraw FROM dl_consignee_ledger WHERE consignee_id = {$overlap} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'PM'")->fetchColumn();
    $effects = (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger_effects WHERE delivery_id = {$consigneeDeliveryId} AND effect_status = 'applied'")->fetchColumn();
    $h->test('D discriminating: source debited both sent quantities exactly once and consignee credited sentinel exactly once', $withdraw === 28 && $credit === 17 && $effects === 1);

    $replay = $runApi('dispatch', array_replace($common, ['dr_number' => $drConsignee, 'destination_type' => 'consignee', 'destination_id' => null, 'consignee_id' => $overlap, 'items' => [['product_id' => $product, 'quantity' => 17, 'unit' => 'pcs']]]), $cashier);
    $creditAfterReplay = (int)$db->query("SELECT addtl - withdraw FROM dl_consignee_ledger WHERE consignee_id = {$overlap} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'PM'")->fetchColumn();
    $h->test('E discriminating: real-handler replay returns same delivery and does not double-credit', ($replay['body']['replayed'] ?? false) === true && (int)$replay['body']['delivery_id'] === $consigneeDeliveryId && $creditAfterReplay === 17);

    $db->prepare('INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status, finalized_by, finalized_at) VALUES (?, ?, "PM", "finalized", ?, NOW()) ON DUPLICATE KEY UPDATE status = "finalized", finalized_by = VALUES(finalized_by), finalized_at = NOW()')->execute([$source, $date, $admin]);
    $locked = $runApi('dispatch', array_replace($common, ['dr_number' => $drLocked, 'destination_type' => 'consignee', 'destination_id' => null, 'consignee_id' => $overlap, 'items' => [['product_id' => $product, 'quantity' => 19, 'unit' => 'pcs']]]), $cashier);
    $lockedRows = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE dr_number = '{$drLocked}'")->fetchColumn();
    $lockedCredit = (int)$db->query("SELECT addtl - withdraw FROM dl_consignee_ledger WHERE consignee_id = {$overlap} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'PM'")->fetchColumn();
    $h->test('F discriminating: finalized-shift guard rejects real consignee dispatch and rolls back credit', ($locked['body']['ok'] ?? true) === false && str_contains((string)($locked['body']['error'] ?? ''), 'finalized') && $lockedRows === 0 && $lockedCredit === 17);

    $unfiltered = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE dr_number IN ('{$drBranch}','{$drConsignee}')")->fetchColumn();
    $nullSeparated = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE destination_id = {$overlap} AND dr_number IN ('{$drBranch}','{$drConsignee}')")->fetchColumn();
    $typedBranch = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE destination_type = 'branch' AND destination_id = {$overlap} AND dr_number IN ('{$drBranch}','{$drConsignee}')")->fetchColumn();
    $h->test('G pin: unfiltered aggregate sees both documents while branch-id and typed branch probes see only branch DR', $unfiltered === 2 && $nullSeparated === 1 && $typedBranch === 1);

    $db->prepare('UPDATE dl_ledger_shift_status SET status = "open", finalized_by = NULL, finalized_at = NULL WHERE branch_id = ? AND ledger_date = ? AND shift = "PM"')->execute([$source, $date]);
    $voided = $runApi('void', ['delivery_id' => $consigneeDeliveryId, 'reason' => 'fixture reversal'], $admin, 'admin');
    $creditAfterVoid = (int)$db->query("SELECT addtl - withdraw FROM dl_consignee_ledger WHERE consignee_id = {$overlap} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'PM'")->fetchColumn();
    $sourceAfterVoid = (int)$db->query("SELECT withdraw FROM dl_daily_ledger WHERE branch_id = {$source} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'PM'")->fetchColumn();
    $reversedEffects = (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger_effects WHERE delivery_id = {$consigneeDeliveryId} AND effect_status = 'reversed'")->fetchColumn();
    $h->test('H discriminating: void posts an opposite movement and marks the durable credit effect reversed', ($voided['body']['ok'] ?? false) === true && $creditAfterVoid === 0 && $sourceAfterVoid === 11 && $reversedEffects === 1);
} finally {
    $db->prepare('UPDATE dl_ledger_shift_status SET status = "open", finalized_by = NULL, finalized_at = NULL WHERE branch_id = ? AND ledger_date = ? AND shift = "PM"')->execute([$source, $date]);
    $cleanup();
    dlPersistModuleSettings(['formal_delivery_workflow_enabled' => $previousFormal]);
    @unlink($payloadFile);
}

$remaining = [
    'branches' => (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$source},{$overlap},{$commissary})")->fetchColumn(),
    'consignees' => (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE id = {$overlap}")->fetchColumn(),
    'products' => (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id = {$product}")->fetchColumn(),
    'users' => (int)$db->query("SELECT COUNT(*) FROM dl_users WHERE id IN ({$cashier},{$admin})")->fetchColumn(),
    'deliveries' => (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE origin_id = {$source} OR consignee_id = {$overlap}")->fetchColumn(),
    'ledger' => (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger WHERE consignee_id = {$overlap}")->fetchColumn(),
];
$h->test('fixture cleanup pin: every private-id row is removed and settings restored', array_sum($remaining) === 0 && (string)(dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? '') === (string)$previousFormal, json_encode($remaining));
$h->done();
