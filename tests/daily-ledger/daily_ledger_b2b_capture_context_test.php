<?php

declare(strict_types=1);

/**
 * Oracle for the branch-to-branch capture-context UI.
 *
 * Base-tree discrimination:
 * D1-D4 and D7-D8 fail on base. D5-D6 are survival pins and already pass on base.
 * Fixture ids are private to this oracle and are removed in finally.
 */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-b2b-capture-context', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('modules/daily-ledger/database/migrations/079_snapshot_withdrawal_encoder_name.sql');
$h->fingerprint('templates/modules/daily-ledger/cashier/dispatch_modal.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/receive_modal.disyl');
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$origin = 99881;
$destination = 99882;
$product = 99881;
$dispatcher = 99881;
$receiver = 99882;
$withdrawal = 998801;
$historical = 998802;
$electronicDelivery = 998803;
$paperDelivery = 998804;
$date = dl_businessDate();
$payloadPath = tempnam(sys_get_temp_dir(), 'dl-b2b-context-');
$incomingHarness = __DIR__ . '/daily_ledger_preserve_cashier_variance_harness.php';
$writeHarness = __DIR__ . '/daily_ledger_b2b_accountability_harness.php';

$run = static function (string $harness, array $arguments, array $payload) use ($payloadPath): array {
    file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));
    $output = [];
    $exit = 0;
    $mode = array_shift($arguments);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' '
        . escapeshellarg((string)$mode) . ' ' . escapeshellarg($payloadPath)
        . ($arguments === [] ? '' : ' ' . implode(' ', array_map('escapeshellarg', $arguments)))
        . ' 2>&1', $output, $exit);
    $raw = implode("\n", $output);
    $raw = (string)preg_replace('/\n__HTTP_STATUS__=\d+\s*$/', '', $raw);
    return ['exit' => $exit, 'body' => json_decode($raw, true), 'raw' => $raw];
};

$cleanup = static function () use ($db, $origin, $destination, $product, $dispatcher, $receiver, $electronicDelivery, $paperDelivery): void {
    $db->prepare('DELETE FROM audit_logs WHERE module="daily-ledger" AND branch_id IN (?, ?)')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id IN (?, ?))')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_integrity_notifications WHERE branch_id IN (?, ?)')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_delivery_variance_flags WHERE product_id=?')->execute([$product]);
    $db->prepare('DELETE FROM dl_deliveries WHERE id IN (?, ?)')->execute([$electronicDelivery, $paperDelivery]);
    $db->prepare('DELETE FROM dl_cashier_withdrawals WHERE branch_id IN (?, ?) OR target_branch_id IN (?, ?)')->execute([$origin, $destination, $origin, $destination]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?) OR product_id=?')->execute([$origin, $destination, $product]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?)')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id IN (?, ?)')->execute([$origin, $destination]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) OR product_id=?')->execute([$origin, $destination, $product]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (?, ?)')->execute([$dispatcher, $receiver]);
    $db->prepare('DELETE FROM dl_users WHERE id IN (?, ?)')->execute([$dispatcher, $receiver]);
    $db->prepare('DELETE FROM dl_products WHERE id=?')->execute([$product]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$origin, $destination]);
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, ?, ?, 0, 1), (?, ?, ?, 0, 1)')
        ->execute([$origin, 'CTX-ORIG-9988', 'Context Source Fixture', $destination, 'CTX-DEST-9988', 'Context Destination Fixture']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 10, 1)')
        ->execute([$product, 'CTX-PROD-9988', 'Context Product Fixture']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')
        ->execute([$destination, $product]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (?, ?, "fixture", ?, "cashier", "AM", 1), (?, ?, "fixture", ?, "cashier", "PM", 1)')
        ->execute([$dispatcher, 'context-dispatch-9988', 'Frozen Dispatch Name', $receiver, 'context-receive-9988', 'Receiving Cashier Fixture']);
    $db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?, ?), (?, ?)')
        ->execute([$dispatcher, $origin, $receiver, $destination]);
    $db->prepare('INSERT INTO dl_cashier_withdrawals
        (id, branch_id, product_id, ledger_date, shift, withdrawal_type, dr_number, target_branch_id, quantity, encoded_by, encoded_by_name_snapshot, dedup_hash)
        VALUES (?, ?, ?, ?, "AM", "delivery", "CTX-SNAPSHOT-9988", ?, 3, ?, "Frozen Dispatch Name", ?),
               (?, ?, ?, ?, "AM", "delivery", "CTX-HISTORICAL-9988", ?, 2, ?, NULL, ?)')
        ->execute([
            $withdrawal, $origin, $product, $date, $destination, $dispatcher, sha1('ctx-snapshot-9988'),
            $historical, $origin, $product, $date, $destination, $dispatcher, sha1('ctx-historical-9988'),
        ]);
    $db->prepare('INSERT INTO dl_deliveries
        (id, origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, production_shift,
         status, created_by, created_by_name_snapshot, posted_by, posted_at, provenance_status)
        VALUES (?, "branch", ?, "branch", ?, "CTX-FORMAL-9988", ?, "AM", "posted", ?, "Frozen Formal Dispatcher", ?, NOW(), "none"),
               (?, "branch", ?, "branch", ?, "CTX-PAPER-9988", ?, "PM", "posted", ?, "Receiving Cashier Fixture", ?, NOW(), "paper_dr_pending")')
        ->execute([
            $electronicDelivery, $origin, $destination, $date, $dispatcher, $dispatcher,
            $paperDelivery, $origin, $destination, $date, $receiver, $receiver,
        ]);
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot) VALUES (?, ?, 4, "pcs", 10), (?, ?, 5, "pcs", 10)')
        ->execute([$electronicDelivery, $product, $paperDelivery, $product]);
    $db->prepare('UPDATE dl_users SET full_name="Renamed Live Name" WHERE id=?')->execute([$dispatcher]);

    $incoming = $run($incomingHarness, ['incoming'], ['role' => 'admin', 'get' => ['branch_id' => $destination]]);
    $groups = array_column($incoming['body']['deliveries'] ?? [], null, 'dr_number');
    $h->test('D1 discriminating: incoming payload returns dispatcher id and prefers frozen name, with live fallback for historical rows (fails on base: fields absent)',
        $incoming['exit'] === 0
        && ($groups['CTX-SNAPSHOT-9988']['dispatching_cashier_id'] ?? null) === $dispatcher
        && ($groups['CTX-SNAPSHOT-9988']['dispatching_cashier_name'] ?? null) === 'Frozen Dispatch Name'
        && ($groups['CTX-HISTORICAL-9988']['dispatching_cashier_name'] ?? null) === 'Renamed Live Name',
        $incoming['raw']);

    $handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $offline = (string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php');
    $migration = (string)file_get_contents($base . '/modules/daily-ledger/database/migrations/079_snapshot_withdrawal_encoder_name.sql');
    $h->test('D2 discriminating: withdrawal writers, both named delivery creators, and paper replay freeze display names; migration guards both columns without backfill (fails on base: snapshot columns/writes absent)',
        substr_count($handlers, 'encoded_by_name_snapshot') >= 2
        && substr_count($offline, 'encoded_by_name_snapshot') >= 1
        && substr_count($handlers, 'created_by_name_snapshot') >= 5
        && substr_count($offline, 'created_by_name_snapshot') >= 2
        && substr_count($handlers, 'dl_userDisplayNameById($ctx->db(), $actorId)') >= 2
        && str_contains($offline, 'dl_userDisplayNameById($ctx->db(), $actorId)')
        && str_contains($handlers, 'dl_userDisplayNameById($ctx->db(), $userId)')
        && str_contains($offline, 'dl_userDisplayNameById($ctx->db(), $userId)')
        && substr_count($migration, 'information_schema.columns') === 2
        && str_contains($migration, "table_name = 'dl_deliveries'")
        && !str_contains($migration, 'UPDATE dl_cashier_withdrawals')
        && !str_contains($migration, 'UPDATE dl_deliveries'));

    $dispatch = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/dispatch_modal.disyl');
    $receive = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/receive_modal.disyl');
    $h->test('D3 discriminating: dispatch renders cashier and window.SHIFT, exactly matching submitted shift (fails on base: identity line absent)',
        str_contains($dispatch, 'Sending as:') && str_contains($dispatch, '{user_name}')
        && str_contains($dispatch, 'x-text="window.SHIFT"')
        && str_contains($dispatch, "shift: (window.SHIFT || '')"));
    $h->test('D4 discriminating: receive renders sender, historical absence, receiver, and explicit paper-only state (fails on base: all context absent)',
        str_contains($receive, 'Receiving as:') && str_contains($receive, 'Sent by:')
        && str_contains($receive, 'not recorded (historical)')
        && str_contains($receive, 'not electronically dispatched — captured from paper DR'));

    $h->test('D7 discriminating: cashier-dispatched dl_deliveries payload prefers the frozen dispatcher and modal labels provenance none as Sent by (fails on base: delivery snapshot field/query absent)',
        ($groups['CTX-FORMAL-9988']['dispatching_cashier_id'] ?? null) === $dispatcher
        && ($groups['CTX-FORMAL-9988']['dispatching_cashier_name'] ?? null) === 'Frozen Formal Dispatcher'
        && ($groups['CTX-FORMAL-9988']['provenance_status'] ?? null) === 'none'
        && str_contains($receive, 'x-if="g.provenance_status === \'none\'"')
        && str_contains($receive, 'Sent by:'),
        $incoming['raw']);
    $h->test('D8 discriminating: populated paper-capture creator snapshot is guarded by provenance and never labelled Sent by (fails on base: provenance-driven label absent)',
        ($groups['CTX-PAPER-9988']['dispatching_cashier_name'] ?? null) === 'Receiving Cashier Fixture'
        && ($groups['CTX-PAPER-9988']['provenance_status'] ?? null) === 'paper_dr_pending'
        && str_contains($receive, 'x-if="g.provenance_status === \'paper_dr_pending\'"')
        && str_contains($receive, 'not electronically dispatched - captured from paper DR'),
        $incoming['raw']);
    $h->test('D5 pin: source branch, production shift, DR, required paper shift, and bound-cashier lock survive (passes on base)',
        str_contains($receive, 'x-text="g.origin_branch_name"')
        && str_contains($receive, 'x-text="g.dr_number || \'NO DR\'"')
        && str_contains($receive, 'Production shift (from paper DR) *')
        && str_contains($receive, '<select x-model="paperForm.production_shift"')
        && str_contains($receive, '<template x-if="window.SHIFT_LOCKED">'));
    // The group selector's shift now arrives ON the delivery, so only the paper-DR panel may claim the
    // shift comes from the paper. Two labels, one provenance each: if the per-group label reverts to
    // "(from paper DR)" the receiver is told to read a value the system already supplied.
    $h->test('D5b discriminating: only the paper-DR panel claims the shift comes from the paper DR; the per-group selector is plain (fails if the dispatch-sourced shift is mislabelled)',
        substr_count($receive, 'Production shift (from paper DR) *') === 1
        && str_contains($receive, 'Production shift *')
        && str_contains($receive, '<select x-model="g.production_shift"'),
        'paper-DR-labelled shifts=' . substr_count($receive, 'Production shift (from paper DR) *')
        . ', plain shift labels=' . substr_count($receive, 'Production shift *'));

    $db->prepare('INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status, finalized_at) VALUES (?, ?, "AM", "finalized", NOW())')
        ->execute([$origin, $date]);
    $late = $run($writeHarness, ['receive', (string)$receiver], [
        'branch_id' => $destination,
        'withdrawal_ids' => [$withdrawal],
        'delivery_ids' => [],
        'shift' => 'PM',
        'production_shift' => 'AM',
    ]);
    $h->test('D6 pin: late receipt remains allowed after the dispatch shift ended (passes on base)',
        $late['exit'] === 0 && ($late['body']['ok'] ?? false) === true,
        $late['raw']);
} finally {
    $cleanup();
    if (is_string($payloadPath) && is_file($payloadPath)) {
        unlink($payloadPath);
    }
}

$remaining = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ($origin,$destination)")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_users WHERE id IN ($dispatcher,$receiver)")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id=$product")->fetchColumn();
$h->test('private fixture cleanup leaves no rows', $remaining === 0, (string)$remaining);
$h->done();
