<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-consignee-audit', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('templates/modules/daily-ledger/admin/deliveries.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_consignee_isolation_harness.php');
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt', 'capability.call');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$source = 99641;
$commissary = 99642;
$consignee = 99641;
$product = 99641;
$admin = 99641;
$date = dl_businessDate();
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-consignee-audit-');
$harness = __DIR__ . '/daily_ledger_consignee_isolation_harness.php';

$runApi = static function (string $mode, array $body) use ($payloadFile, $harness, $admin): array {
    file_put_contents($payloadFile, json_encode($body, JSON_THROW_ON_ERROR));
    $lines = []; $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($payloadFile) . ' ' . $admin . ' admin 2>&1', $lines, $exit);
    $raw = implode("\n", $lines);
    $status = null;
    if (preg_match('/\n__HTTP_STATUS__=(\d+)\s*$/', $raw, $match)) {
        $status = (int)$match[1];
        $raw = (string)preg_replace('/\n__HTTP_STATUS__=\d+\s*$/', '', $raw);
    }
    return ['exit' => $exit, 'status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw];
};

$cleanup = static function () use ($db, $source, $commissary, $consignee, $product, $admin): void {
    $ids = $db->query("SELECT id FROM dl_deliveries WHERE origin_id = {$source} OR consignee_id = {$consignee}")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($ids !== []) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_consignee_ledger_effects WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_delivery_items WHERE delivery_id IN ($marks)")->execute($ids);
        $db->prepare("DELETE FROM dl_deliveries WHERE id IN ($marks)")->execute($ids);
    }
    $db->prepare('DELETE FROM audit_logs WHERE module = "daily-ledger" AND branch_id IN (?, ?)')->execute([$source, $commissary]);
    $db->prepare('DELETE FROM dl_consignee_ledger WHERE consignee_id = ? OR product_id = ?')->execute([$consignee, $product]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?)')->execute([$source, $commissary]);
    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id IN (?, ?)')->execute([$source, $commissary]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id = ?')->execute([$admin]);
    $db->prepare('DELETE FROM dl_users WHERE id = ?')->execute([$admin]);
    $db->prepare('DELETE FROM dl_consignees WHERE id = ?')->execute([$consignee]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$product]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$source, $commissary]);
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, assigned_commissary_id, is_commissary, is_active) VALUES (?, "S2-COM", "S2 Commissary", NULL, 1, 1), (?, "S2-SRC", "S2 Source", ?, 0, 1)')->execute([$commissary, $source, $commissary]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?, "S2-CONS", "S2 Consignee", ?, 1)')->execute([$consignee, $commissary]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, "S2-P", "S2 Product", 10, 1)')->execute([$product]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, is_active) VALUES (?, "s2-audit-admin", "fixture", "S2 Audit Admin", "admin", 1)')->execute([$admin]);

    $deliveryInsert = $db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, consignee_id, dr_number, delivery_date, production_shift, status, provenance_status, remarks, created_by, posted_by, posted_at) VALUES ("branch", :origin, :type, :destination, :consignee, :dr, :date, "AM", "posted", "paper_dr_pending", "[cashier-dispatch]", :user, :user2, NOW())');
    $deliveryInsert->execute([':origin' => $source, ':type' => 'branch', ':destination' => $commissary, ':consignee' => null, ':dr' => 'S2-BRANCH-DR', ':date' => $date, ':user' => $admin, ':user2' => $admin]);
    $branchDelivery = (int)$db->lastInsertId();
    $deliveryInsert->execute([':origin' => $source, ':type' => 'consignee', ':destination' => null, ':consignee' => $consignee, ':dr' => 'S2-CONSIGNEE-DR', ':date' => $date, ':user' => $admin, ':user2' => $admin]);
    $consigneeDelivery = (int)$db->lastInsertId();
    $itemInsert = $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, unit_cost_snapshot, price_snapshot, remarks) VALUES (?, ?, 13, "pcs", 0, 0, "[cashier-dispatch]")');
    $itemInsert->execute([$branchDelivery, $product]);
    $itemInsert->execute([$consigneeDelivery, $product]);
    $consigneeItem = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_consignee_ledger (consignee_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, encoded_by) VALUES (?, ?, ?, "AM", 0, 0, 13, 0, ?)')->execute([$consignee, $product, $date, $admin]);
    $db->prepare('INSERT INTO dl_consignee_ledger_effects (delivery_id, delivery_item_id, consignee_id, source_branch_id, product_id, ledger_date, shift, quantity, effect_kind, effect_status, applied_by, applied_at, before_qty, after_qty) VALUES (?, ?, ?, ?, ?, ?, "AM", 13, "credit", "applied", ?, NOW(), 0, 13)')->execute([$consigneeDelivery, $consigneeItem, $consignee, $source, $product, $date, $admin]);

    $branchReview = $runApi('review', ['delivery_id' => $branchDelivery, 'action' => 'accepted']);
    $branchState = (string)$db->query("SELECT provenance_status FROM dl_deliveries WHERE id = {$branchDelivery}")->fetchColumn();
    $h->test('A discriminating: branch cashier dispatch is still refused by provenance (guards the narrow consignee-only exception)', ($branchReview['status'] ?? 0) === 422 && $branchState === 'paper_dr_pending');

    $before = (string)$db->query("SELECT CONCAT(beg_bal, ':', addtl, ':', withdraw) FROM dl_consignee_ledger WHERE consignee_id = {$consignee} AND product_id = {$product}")->fetchColumn();
    $effectsBeforeVerify = (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger_effects WHERE delivery_id = {$consigneeDelivery}")->fetchColumn();
    $verify = $runApi('review', ['delivery_id' => $consigneeDelivery, 'action' => 'accepted', 'note' => 'paper DR seen']);
    $verified = $db->query("SELECT provenance_status, provenance_reviewed_by FROM dl_deliveries WHERE id = {$consigneeDelivery}")->fetch(PDO::FETCH_ASSOC);
    $after = (string)$db->query("SELECT CONCAT(beg_bal, ':', addtl, ':', withdraw) FROM dl_consignee_ledger WHERE consignee_id = {$consignee} AND product_id = {$product}")->fetchColumn();
    $effectsAfterVerify = (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger_effects WHERE delivery_id = {$consigneeDelivery}")->fetchColumn();
    $h->test('B discriminating: verify records accepted and reviewer while posting no quantity (guards evidence-only verification)', ($verify['json']['ok'] ?? false) === true && $verified['provenance_status'] === 'accepted' && (int)$verified['provenance_reviewed_by'] === $admin && $before === $after && $effectsBeforeVerify === $effectsAfterVerify);

    $reopen = $runApi('review', ['delivery_id' => $consigneeDelivery, 'action' => 'reopen']);
    $reopened = $db->query("SELECT provenance_status, provenance_reviewed_by FROM dl_deliveries WHERE id = {$consigneeDelivery}")->fetch(PDO::FETCH_ASSOC);
    $h->test('C pin: reopen restores pending and clears reviewer (defends the existing review lifecycle)', ($reopen['json']['ok'] ?? false) === true && $reopened['provenance_status'] === 'paper_dr_pending' && $reopened['provenance_reviewed_by'] === null);

    $discrepancyBody = ['delivery_id' => $consigneeDelivery, 'action' => 'discrepant', 'note' => 'paper DR says zero', 'discrepancies' => [['product_id' => $product, 'received_qty' => 0]]];
    $db->prepare('INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status, finalized_by, finalized_at) VALUES (?, ?, "AM", "finalized", ?, NOW())')->execute([$source, $date, $admin]);
    $evidence = $runApi('review', $discrepancyBody);
    $guarded = $runApi('correct', $discrepancyBody);
    $guardedNet = (int)$db->query("SELECT addtl - withdraw FROM dl_consignee_ledger WHERE consignee_id = {$consignee} AND product_id = {$product}")->fetchColumn();
    $guardedFlags = (int)$db->query("SELECT COUNT(*) FROM dl_delivery_variance_flags WHERE delivery_id = {$consigneeDelivery} AND variance = -13")->fetchColumn();
    $guardedAdjustments = (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger_effects WHERE delivery_id = {$consigneeDelivery} AND effect_kind = 'adjustment'")->fetchColumn();
    $h->test('D discriminating: finalized shift keeps correction immutable without blocking discrepancy evidence (guards R6 and independent verification)', ($evidence['json']['ok'] ?? false) === true && ($guarded['json']['ok'] ?? true) === false && str_contains((string)($guarded['json']['error'] ?? ''), 'finalized') && $guardedFlags === 1 && $guardedAdjustments === 0 && $guardedNet === 13);

    $db->prepare('UPDATE dl_ledger_shift_status SET status = "open", finalized_by = NULL, finalized_at = NULL WHERE branch_id = ? AND ledger_date = ? AND shift = "AM"')->execute([$source, $date]);
    $discrepant = $runApi('correct', $discrepancyBody);
    $retry = $runApi('correct', $discrepancyBody);
    $net = (int)$db->query("SELECT addtl - withdraw FROM dl_consignee_ledger WHERE consignee_id = {$consignee} AND product_id = {$product}")->fetchColumn();
    $flags = (int)$db->query("SELECT COUNT(*) FROM dl_delivery_variance_flags WHERE delivery_id = {$consigneeDelivery} AND product_id = {$product} AND variance = -13")->fetchColumn();
    $adjustments = (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger_effects WHERE delivery_id = {$consigneeDelivery} AND effect_kind = 'adjustment' AND effect_status = 'applied' AND quantity = -13")->fetchColumn();
    $h->test('E discriminating: discrepancy is corrected once by a reversing movement (guards append-only and exactly-once correction)', ($discrepant['json']['ok'] ?? false) === true && ($retry['json']['ok'] ?? false) === true && $flags === 1 && $adjustments === 1 && $net === 0);

    $sheet = $runApi('commissary', ['date' => $date, 'commissary_id' => $commissary, 'shift' => 'AM']);
    // F REPOINTED (commissary-consignee-depletion, R5): the consignee view is now a
    // destination filter on the one Daily Sheet table, not a second table.
    $h->test('F pin: the one Daily Sheet table renders with its destination filter (defends additive UI)', $sheet['exit'] === 0 && str_contains($sheet['raw'], 'id="production-ledger-table"') && str_contains($sheet['raw'], 'production-destination-filter-branches') && !str_contains($sheet['raw'], 'id="consignee-production-ledger-table"'));
} finally {
    $cleanup();
    @unlink($payloadFile);
}

$remaining = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE origin_id = {$source} OR consignee_id = {$consignee}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE id = {$consignee}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger WHERE consignee_id = {$consignee}")->fetchColumn();
$h->test('G pin: audit fixture cleanup removes every private row', $remaining === 0);
$h->done();
