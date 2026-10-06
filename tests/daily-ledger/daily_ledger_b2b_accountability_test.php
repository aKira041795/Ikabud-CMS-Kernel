<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-b2b-accountability', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('templates/modules/daily-ledger/admin/deliveries.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_b2b_accountability_harness.php');
// D8 deliberately points the fail-open guard at an audit row that does not exist, so that one line
// is expected. The kernel_state_cache lines are the documented cold-cache noise (the suite runner
// deletes storage/modules.json per test), so they must not fail this oracle the way they fail a
// naive log check. Every OTHER unexpected app.log/error.log line still fails the run.
$h->allowLogLines(
    'accountability audit row is MISSING',
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'capability.call'
);

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$origin = 99731;
$destination = 99732;
$productA = 99731;
$productB = 99732;
$dispatcher = 99731;
$receiver = 99732;
$withdrawalA = 997301;
$withdrawalB = 997302;
$formalDelivery = 997303;
$date = dl_businessDate();
$dr = 'B2B-ACCOUNT-9973';
$payload = tempnam(sys_get_temp_dir(), 'dl-b2b-account-');
$harness = __DIR__ . '/daily_ledger_b2b_accountability_harness.php';
$previousFormal = dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? '0';

$runApi = static function (string $mode, array $body, int $actorId) use ($payload, $harness): array {
    file_put_contents($payload, json_encode($body, JSON_THROW_ON_ERROR));
    $lines = [];
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' '
        . escapeshellarg($mode) . ' ' . escapeshellarg($payload) . ' ' . escapeshellarg((string)$actorId) . ' 2>&1', $lines, $exit);
    $raw = implode("\n", $lines);
    $status = null;
    if (preg_match('/\n__HTTP_STATUS__=(\d+)\s*$/', $raw, $match)) {
        $status = (int)$match[1];
        $raw = (string)preg_replace('/\n__HTTP_STATUS__=\d+\s*$/', '', $raw);
    }
    return ['exit' => $exit, 'status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
};

$cleanup = static function () use ($db, $origin, $destination, $productA, $productB, $dispatcher, $receiver): void {
    $db->prepare('DELETE FROM audit_logs WHERE module = "daily-ledger" AND branch_id IN (?, ?)')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN (?, ?))')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE branch_id IN (?, ?)')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN (?, ?) OR destination_id IN (?, ?))')->execute([$origin, $destination, $origin, $destination]);
    $db->prepare('DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE branch_id IN (?, ?))')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_branch_receivings WHERE branch_id IN (?, ?)')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN (?, ?) OR destination_id IN (?, ?))')->execute([$origin, $destination, $origin, $destination]);
    $db->prepare('DELETE FROM dl_delivery_items WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN (?, ?) OR destination_id IN (?, ?))')->execute([$origin, $destination, $origin, $destination]);
    $db->prepare('DELETE FROM dl_deliveries WHERE origin_id IN (?, ?) OR destination_id IN (?, ?)')->execute([$origin, $destination, $origin, $destination]);
    $db->prepare('DELETE FROM dl_cashier_withdrawals WHERE branch_id IN (?, ?) OR target_branch_id IN (?, ?)')->execute([$origin, $destination, $origin, $destination]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?) OR product_id IN (?, ?)')->execute([$origin, $destination, $productA, $productB]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?)')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id IN (?, ?)')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) OR product_id IN (?, ?)')->execute([$origin, $destination, $productA, $productB]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (?, ?)')->execute([$dispatcher, $receiver]);
    $db->prepare('DELETE FROM dl_users WHERE id IN (?, ?)')->execute([$dispatcher, $receiver]);
    $db->prepare('DELETE FROM dl_products WHERE id IN (?, ?)')->execute([$productA, $productB]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$origin, $destination]);
};

$cleanup();
dlPersistModuleSettings(['formal_delivery_workflow_enabled' => '1']);

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, 0, 1), (?, ?, ?, 0, 1)')
        ->execute([$origin, 'B2B-A-9973', 'B2B Source Fixture', $destination, 'B2B-B-9973', 'B2B Destination Fixture']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 12, 1), (?, ?, ?, 18, 1)')
        ->execute([$productA, 'B2B-P-A-9973', 'B2B Apples Fixture', $productB, 'B2B-P-B-9973', 'B2B Bread Fixture']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1), (?, ?, 1), (?, ?, 1), (?, ?, 1)')
        ->execute([$origin, $productA, $origin, $productB, $destination, $productA, $destination, $productB]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (?, ?, "fixture", ?, "cashier", "AM", 1), (?, ?, "fixture", ?, "cashier", "PM", 1)')
        ->execute([$dispatcher, 'b2b-dispatch-9973', 'Dispatch Cashier Snapshot', $receiver, 'b2b-receive-9973', 'Receive Cashier Snapshot']);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?, ?), (?, ?)')
        ->execute([$dispatcher, $origin, $receiver, $destination]);

    $h->section('D2 — discriminating dispatch audit');
    $dispatchApi = $runApi('dispatch', [
        'branch_id' => $origin,
        'shift' => 'PM',
        'delivery_date' => $date,
        'dr_number' => $dr . '-DISPATCH',
        'destination_type' => 'branch',
        'destination_id' => $destination,
        'items' => [['product_id' => $productA, 'quantity' => 4, 'unit' => 'pcs']],
    ], $dispatcher);
    $dispatchId = (int)($dispatchApi['body']['delivery_id'] ?? 0);
    $dispatchAuditStmt = $db->prepare('SELECT new_data FROM audit_logs WHERE module = "daily-ledger" AND action = "create_delivery" AND entity_type = "dl_deliveries" AND entity_id = ? ORDER BY id DESC LIMIT 1');
    $dispatchAuditStmt->execute([(string)$dispatchId]);
    $dispatchAudit = json_decode((string)$dispatchAuditStmt->fetchColumn(), true) ?: [];
    $h->test('D2 discriminating: dispatch audit snapshots cashier name, forced shift, branches, time, and line count (fails on base: neither cashier name nor shift)',
        ($dispatchApi['body']['ok'] ?? false) === true
        && ($dispatchAudit['dispatching_cashier_id'] ?? 0) === $dispatcher
        && ($dispatchAudit['dispatching_cashier_name'] ?? '') === 'Dispatch Cashier Snapshot'
        && ($dispatchAudit['dispatch_shift'] ?? '') === 'AM'
        && ($dispatchAudit['origin_branch_id'] ?? 0) === $origin
        && ($dispatchAudit['destination_branch_id'] ?? 0) === $destination
        && !empty($dispatchAudit['dispatched_at'])
        && ($dispatchAudit['line_count'] ?? 0) === 1,
        json_encode([$dispatchApi, $dispatchAudit], JSON_UNESCAPED_SLASHES));

    $db->prepare('INSERT INTO dl_cashier_withdrawals
        (id, branch_id, product_id, ledger_date, shift, withdrawal_type, dr_number, target_branch_id, quantity, unit, dedup_hash)
        VALUES (?, ?, ?, ?, "AM", "delivery", ?, ?, 5, "pcs", ?),
               (?, ?, ?, ?, "AM", "delivery", ?, ?, 3, "pcs", ?)')
        ->execute([
            $withdrawalA, $origin, $productA, $date, $dr, $destination, sha1($dr . '-a'),
            $withdrawalB, $origin, $productB, $date, $dr, $destination, sha1($dr . '-b'),
        ]);

    $h->section('D4 — discriminating unresolved-shift refusal');
    $beforeD4 = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE id IN ($withdrawalA,$withdrawalB) AND received_at IS NOT NULL")->fetchColumn();
    $d4Refused = false;
    try {
        dl_requireResolvedReceiveShift(['shift' => null]);
    } catch (RuntimeException $e) {
        $d4Refused = $e->getCode() === 422;
    }
    $afterD4 = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE id IN ($withdrawalA,$withdrawalB) AND received_at IS NOT NULL")->fetchColumn();
    $h->test('D4 discriminating: an unresolved receive shift is refused before any receive write (fails on base: no invariant guard)',
        $d4Refused && $beforeD4 === 0 && $afterD4 === 0);

    $h->section('D1/D3 discriminating and D5/D6 pins');
    $receiveBody = [
        'branch_id' => $destination,
        'withdrawal_ids' => [$withdrawalA, $withdrawalB],
        'delivery_ids' => [],
        'shift' => 'AM',
        'production_shift' => 'AM',
        'informal_partial_qtys' => [(string)$withdrawalA => 4, (string)$withdrawalB => 3],
    ];
    $receiveApi = $runApi('receive', $receiveBody, $receiver);
    $receiveAuditStmt = $db->prepare('SELECT new_data FROM audit_logs WHERE module = "daily-ledger" AND action = "delivery_received" AND entity_type = "dl_cashier_withdrawals" AND entity_id = ? ORDER BY id DESC LIMIT 1');
    $receiveAuditStmt->execute([(string)$withdrawalA]);
    $receiveAudit = json_decode((string)$receiveAuditStmt->fetchColumn(), true) ?: [];
    $h->test('D1 discriminating: b2b receive audit snapshots receiving cashier name and shift (fails on base: no receive audit row)',
        ($receiveApi['body']['ok'] ?? false) === true
        && ($receiveAudit['receiving_cashier_id'] ?? 0) === $receiver
        && ($receiveAudit['receiving_cashier_name'] ?? '') === 'Receive Cashier Snapshot'
        && ($receiveAudit['received_shift'] ?? '') === 'PM'
        && ($receiveAudit['source_branch_id'] ?? 0) === $origin
        && ($receiveAudit['destination_branch_id'] ?? 0) === $destination
        && !empty($receiveAudit['received_at'])
        && ($receiveAudit['line_count'] ?? 0) === 2,
        json_encode($receiveAudit, JSON_UNESCAPED_SLASHES));

    $receipt = dl_branchTransferReceiptViewModel($db, $withdrawalA);
    $h->test('D3 discriminating: branch-transfer receipt reader returns products, received quantities, actor, shift, timestamp, and branches (fails on base: reader absent)',
        count($receipt['items'] ?? []) === 2
        && array_column($receipt['items'], 'received_qty') === [4, 3]
        && (($receipt['receiving']['received_by_name'] ?? '') === 'Receive Cashier Snapshot')
        && (($receipt['receiving']['received_shift'] ?? '') === 'PM')
        && !empty($receipt['receiving']['received_at'])
        && (($receipt['receiving']['source_branch_id'] ?? 0) === $origin)
        && (($receipt['receiving']['destination_branch_id'] ?? 0) === $destination),
        json_encode($receipt, JSON_UNESCAPED_SLASHES));

    $h->test('D5 pin: conflicting AM request by shift-bound PM cashier lands on PM (passes on base)',
        ($receiveApi['body']['received_shift'] ?? '') === 'PM'
        && (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE id IN ($withdrawalA,$withdrawalB) AND received_shift='PM'")->fetchColumn() === 2);

    $ledgerBeforeReplay = (int)$db->query("SELECT COALESCE(SUM(addtl),0) FROM dl_daily_ledger WHERE branch_id=$destination AND product_id IN ($productA,$productB) AND ledger_date='$date' AND shift='PM'")->fetchColumn();
    $rowBeforeReplay = $db->query("SELECT received_by, received_shift, received_at FROM dl_cashier_withdrawals WHERE id=$withdrawalA")->fetch(PDO::FETCH_ASSOC);
    $replay = $runApi('receive', $receiveBody, $receiver);
    $ledgerAfterReplay = (int)$db->query("SELECT COALESCE(SUM(addtl),0) FROM dl_daily_ledger WHERE branch_id=$destination AND product_id IN ($productA,$productB) AND ledger_date='$date' AND shift='PM'")->fetchColumn();
    $rowAfterReplay = $db->query("SELECT received_by, received_shift, received_at FROM dl_cashier_withdrawals WHERE id=$withdrawalA")->fetch(PDO::FETCH_ASSOC);
    $successAudits = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE module='daily-ledger' AND action='delivery_received' AND entity_type='dl_cashier_withdrawals' AND entity_id='$withdrawalA'")->fetchColumn();
    $h->test('D6 pin: replay neither credits nor overwrites nor emits a second success audit (passes on base for stock/identity; base has zero success audits)',
        empty($replay['body']['ok']) && $ledgerAfterReplay === $ledgerBeforeReplay
        && $rowAfterReplay === $rowBeforeReplay && $successAudits === 1,
        json_encode([$replay, $ledgerBeforeReplay, $ledgerAfterReplay, $rowBeforeReplay, $rowAfterReplay, $successAudits], JSON_UNESCAPED_SLASHES));

    $h->section('D7 — formal receive pin with additive cashier snapshot');
    $db->prepare('INSERT INTO dl_deliveries (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, production_shift, status, posted_at) VALUES (?, "branch", ?, "branch", ?, ?, ?, "AM", "posted", NOW())')
        ->execute([$formalDelivery, $origin, $destination, $dr . '-FORMAL', $date]);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 2, "pcs", 12)')
        ->execute([$formalDelivery, $productA]);
    $db->beginTransaction();
    $formalReceiving = dl_acceptFormalDelivery($db, $destination, $formalDelivery, $receiver, $date, [$productA => 2], 'PM');
    $db->commit();
    $formalAuditStmt = $db->prepare('SELECT new_data FROM audit_logs WHERE module="daily-ledger" AND action="create_receiving" AND entity_type="dl_branch_receivings" AND entity_id=?');
    $formalAuditStmt->execute([(string)$formalReceiving]);
    $formalAudit = json_decode((string)$formalAuditStmt->fetchColumn(), true) ?: [];
    $db->prepare('UPDATE dl_branch_receivings SET posted_by = ? WHERE id = ?')->execute([$dispatcher, $formalReceiving]);
    $formalReceipt = dl_formalReceiptViewModel($db, $formalDelivery);
    $h->test('D7 pin: formal receive remains audited and now snapshots the receiving cashier (base passes audit existence but lacks cashier)',
        ($formalAudit['receiving_cashier_id'] ?? 0) === $receiver
        && ($formalAudit['receiving_cashier_name'] ?? '') === 'Receive Cashier Snapshot'
        && ($formalAudit['received_shift'] ?? '') === 'PM'
        && ($formalAudit['source_branch_id'] ?? 0) === $origin
        && ($formalAudit['destination_branch_id'] ?? 0) === $destination
        && !empty($formalAudit['received_at'])
        && ($formalAudit['line_count'] ?? 0) === 1,
        json_encode($formalAudit, JSON_UNESCAPED_SLASHES));
    $h->test('formal receipt identity follows received_by, not the deliberately changed posted_by (latent trap on base)',
        ($formalReceipt['receiving']['received_by_name'] ?? '') === 'Receive Cashier Snapshot');

    // ── D8 fail-open guard ───────────────────────────────────────────────────────────────────
    // Owner: "thus, i have an issue of blocking the operator". The accountability the client asked
    // for lives in the ROW (received_by / received_shift / received_at), which the row write has
    // already set and which the receipt reads. The audit row is the TRAIL, not the data. So a
    // missing trail must be LOUD and must NEVER be a locked door: refusing here would turn an
    // infrastructure fault into operators unable to record goods that really moved.
    $appLogPath = $base . '/storage/logs/app.log';
    $logBefore = is_file($appLogPath) ? (string)file_get_contents($appLogPath) : '';
    $guardThrew = null;
    try {
        dl_accountabilityAuditGuard($db, 'b2b_probe_absent_action', 'b2b_probe', 'absent-row', $destination);
    } catch (\Throwable $e) {
        $guardThrew = get_class($e) . ': ' . $e->getMessage();
    }
    $logWritten = substr((string)(is_file($appLogPath) ? file_get_contents($appLogPath) : ''), strlen($logBefore));
    $h->test('D8 must-allow: a missing accountability audit row is LOUD and never blocks the stock write',
        $guardThrew === null
        && str_contains($logWritten, 'accountability audit row is MISSING')
        && str_contains($logWritten, 'b2b_probe_absent_action'),
        'threw=' . var_export($guardThrew, true) . ' logged=' . substr($logWritten, 0, 160));

    // Leave NO residue. This probe writes a genuine [error] line into the real app.log, and a stray
    // unexplained error there is exactly the wrong evidence an admin would chase during a live
    // incident. The assertion above has already proven the line was written, so restore the log to
    // its pre-probe bytes.
    file_put_contents($appLogPath, $logBefore);

    // The other half: a row that IS present must stay completely silent, or the guard becomes
    // noise and the real gap stops standing out.
    $logBeforeOk = (string)file_get_contents($appLogPath);
    dl_accountabilityAuditGuard($db, 'create_receiving', 'dl_branch_receivings', (string)$formalReceiving, $destination);
    $logWrittenOk = substr((string)file_get_contents($appLogPath), strlen($logBeforeOk));
    $h->test('D8b must-stay-silent: an accountability audit row that exists logs nothing',
        !str_contains($logWrittenOk, 'MISSING'), substr($logWrittenOk, 0, 160));
} finally {
    $cleanup();
    dlPersistModuleSettings(['formal_delivery_workflow_enabled' => $previousFormal]);
    if (is_string($payload) && is_file($payload)) {
        unlink($payload);
    }
}

$remaining = [
    'branches' => (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ($origin,$destination)")->fetchColumn(),
    'products' => (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id IN ($productA,$productB)")->fetchColumn(),
    'users' => (int)$db->query("SELECT COUNT(*) FROM dl_users WHERE id IN ($dispatcher,$receiver)")->fetchColumn(),
    'withdrawals' => (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id IN ($origin,$destination) OR target_branch_id IN ($origin,$destination)")->fetchColumn(),
];
$h->test('fixture cleanup removes every private-id row and restores the formal-workflow setting',
    array_sum($remaining) === 0
    && (string)(dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? '') === (string)$previousFormal,
    json_encode($remaining));
$h->done();
