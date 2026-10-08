<?php

declare(strict_types=1);

/** Daily Sheet consignee-cell entry and explicit unassigned override oracle. */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-consignee-cell-entry', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$commissary = 99861;
$consignee = 99862;
$product = 99863;
$date = '2098-06-11';
$user = ['id' => 1, 'sub' => 'admin:1', 'role' => 'admin', 'source' => 'daily-ledger', 'name' => 'Test Admin'];

$cleanup = static function () use ($db, $commissary, $consignee, $product): void {
    $deliveryIds = $db->query("SELECT id FROM dl_deliveries WHERE origin_id = {$commissary} OR consignee_id = {$consignee}")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($deliveryIds !== []) {
        $ids = implode(',', array_map('intval', $deliveryIds));
        $db->execute("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN ({$ids})");
        $db->execute("DELETE FROM dl_consignee_ledger_effects WHERE delivery_id IN ({$ids}) OR consignee_id = {$consignee}");
        $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN ({$ids})");
        $db->execute("DELETE FROM dl_deliveries WHERE id IN ({$ids})");
    } else {
        $db->execute("DELETE FROM dl_consignee_ledger_effects WHERE consignee_id = {$consignee}");
    }
    $db->execute("DELETE FROM audit_logs WHERE branch_id = {$commissary}");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id = {$commissary}");
    $db->execute("DELETE FROM dl_consignee_ledger WHERE consignee_id = {$consignee} OR product_id = {$product}");
    $db->execute("DELETE FROM dl_consignee_products WHERE consignee_id = {$consignee} OR product_id = {$product}");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} OR product_id = {$product}");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id = {$commissary} OR product_id = {$product}");
    $db->execute("DELETE FROM dl_consignees WHERE id = {$consignee}");
    $db->execute("DELETE FROM dl_products WHERE id = {$product}");
    $db->execute("DELETE FROM dl_branches WHERE id = {$commissary}");
};
$cleanup();

try {
    $tpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $h->test('consignee cell is a visible trigger opening its modal',
        str_contains($tpl, 'class="ledger-trigger ledger-trigger-add consignee-sheet-trigger"')
        && str_contains($tpl, 'onclick="openConsigneeCellModal({row.product_id}, {cell.consignee_id})"')
        && str_contains($tpl, 'id="consignee-cell-modal"'));
    $h->test('both unassigned entry modals require explicit confirmation',
        str_contains($tpl, 'id="branch-cell-unassigned-confirm"')
        && str_contains($tpl, 'id="consignee-cell-unassigned-confirm"')
        && substr_count($tpl, 'unassigned_override:') >= 2);

    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, "CCE-C", "CCE Commissary", 1, 1)')->execute([$commissary]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, "CCE-P", "CCE Product", 12.00, 1)')->execute([$product]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$commissary, $product]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?, "CCE-D", "CCE Consignee", ?, 1)')->execute([$consignee, $commissary]);
    $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?, ?, 0)')->execute([$consignee, $product]);

    $baseInput = [
        'date' => $date, 'shift' => 'AM', 'commissary_branch_id' => $commissary,
        'consignee_id' => $consignee, 'product_id' => $product, 'quantity' => 6,
    ];
    $before = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE consignee_id = {$consignee}")->fetchColumn();
    $message = '';
    try {
        dl_recordDailySheetConsigneeEntry($user, $baseInput + ['submission_id' => 'cce-no-override']);
    } catch (DlProductNotAssignedException $e) {
        $message = $e->getMessage();
    }
    $pair = (int)$db->query("SELECT is_active FROM dl_consignee_products WHERE consignee_id = {$consignee} AND product_id = {$product}")->fetchColumn();
    $afterRefusal = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE consignee_id = {$consignee}")->fetchColumn();
    $h->test('hidden destination without override is refused and remains hidden',
        str_contains($message, 'Confirm the unassigned-destination override') && $pair === 0 && $before === $afterRefusal,
        $message);

    $saved = dl_recordDailySheetConsigneeEntry($user, $baseInput + [
        'submission_id' => 'cce-with-override', 'unassigned_override' => '1',
    ]);
    $ledger = $db->prepare('SELECT addtl, withdraw FROM dl_consignee_ledger WHERE consignee_id = ? AND product_id = ? AND ledger_date = ? AND shift = "AM"');
    $ledger->execute([$consignee, $product, $date]);
    $ledgerRow = $ledger->fetch(PDO::FETCH_ASSOC) ?: [];
    $delivery = $db->prepare('SELECT origin_type, origin_id, destination_type, consignee_id, status FROM dl_deliveries WHERE id = ?');
    $delivery->execute([(int)$saved['delivery_id']]);
    $deliveryRow = $delivery->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test('the same entry with override persists through the existing consignee custody path',
        !empty($saved['consignee_link_revived'])
        && (int)($ledgerRow['addtl'] ?? 0) === 6 && (int)($ledgerRow['withdraw'] ?? 0) === 0
        && ($deliveryRow['origin_type'] ?? '') === 'commissary'
        && (int)($deliveryRow['origin_id'] ?? 0) === $commissary
        && ($deliveryRow['destination_type'] ?? '') === 'consignee'
        && (int)($deliveryRow['consignee_id'] ?? 0) === $consignee
        && ($deliveryRow['status'] ?? '') === 'posted');

    dl_recordDailySheetConsigneeEntry($user, array_merge($baseInput, [
        'quantity' => -2, 'submission_id' => 'cce-correction',
        'type' => 'correction', 'reason_code' => 'encoder_omission',
    ]));
    $corrected = dl_fetchProductionSheetConsigneeCells($db, $date, $commissary, 'AM');
    $h->test('a signed correction uses custody guards and changes the displayed dispatch quantity',
        (int)($corrected['cells'][$product][$consignee] ?? 0) === 4,
        'payload=' . json_encode($corrected['cells'][$product][$consignee] ?? null));

    dl_setConsigneeProductActive($db, $consignee, $product, false, 1);
    $payload = dl_fetchProductionSheetConsigneeCells($db, $date, $commissary, 'AM');
    $h->test('hiding after dispatch does not erase the historical cell',
        (int)($payload['cells'][$product][$consignee] ?? 0) === 4
        && isset($payload['consignees'][$consignee])
        && !isset($payload['assignments'][$product][$consignee]));
} finally {
    $cleanup();
}

$h->done();
