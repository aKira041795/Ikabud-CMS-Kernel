<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-consignee-toggle', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/settings.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/branches.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/products.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/dispatch_modal.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_consignee_toggle_harness.php');
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt', 'capability.call');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$key = 'consignee_enabled';
$originalEnabled = dl_isConsigneeEnabled();
$originalFormal = dl_settingToBool(dlModuleSettings(true)['formal_delivery_workflow_enabled'] ?? false);

$source = 99661;
$destination = 99662;
$consignee = 99661;
$product = 99661;
$cashier = 99661;
$admin = 99663;
$date = dl_businessDate();
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-consignee-toggle-');
$child = __DIR__ . '/daily_ledger_consignee_toggle_harness.php';

$runApi = static function (array $body, string $mode = 'dispatch', string $role = 'cashier', int $actorId = 0) use ($payloadFile, $child, $cashier): array {
    $actorId = $actorId > 0 ? $actorId : $cashier;
    file_put_contents($payloadFile, json_encode($body, JSON_THROW_ON_ERROR));
    $lines = [];
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child) . ' '
        . escapeshellarg($payloadFile) . ' ' . escapeshellarg((string)$actorId) . ' '
        . escapeshellarg($mode) . ' ' . escapeshellarg($role) . ' 2>&1', $lines, $exit);
    $raw = implode("\n", $lines);
    $status = null;
    if (preg_match('/\n__HTTP_STATUS__=(\d+)\s*$/', $raw, $match)) {
        $status = (int)$match[1];
        $raw = (string)preg_replace('/\n__HTTP_STATUS__=\d+\s*$/', '', $raw);
    }
    return ['exit' => $exit, 'status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
};

$cleanup = static function () use ($db, $source, $destination, $consignee, $product, $cashier, $admin): void {
    $deliveryIds = $db->query("SELECT id FROM dl_deliveries WHERE origin_id IN ($source,$destination) OR destination_id IN ($source,$destination) OR consignee_id = $consignee")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($deliveryIds !== []) {
        $marks = implode(',', array_fill(0, count($deliveryIds), '?'));
        $db->prepare("DELETE FROM dl_consignee_ledger_effects WHERE delivery_id IN ($marks)")->execute($deliveryIds);
        $db->prepare("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN ($marks)")->execute($deliveryIds);
        $db->prepare("DELETE FROM dl_delivery_items WHERE delivery_id IN ($marks)")->execute($deliveryIds);
        $db->prepare("DELETE FROM dl_deliveries WHERE id IN ($marks)")->execute($deliveryIds);
    }
    $db->prepare('DELETE FROM audit_logs WHERE module = "daily-ledger" AND (branch_id IN (?, ?) OR (entity_type = "dl_consignees" AND entity_id = ?))')->execute([$source, $destination, (string)$consignee]);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?) OR product_id = ?')->execute([$source, $destination, $product]);
    $db->prepare('DELETE FROM dl_consignee_ledger WHERE consignee_id = ? OR product_id = ?')->execute([$consignee, $product]);
    $db->prepare('DELETE FROM dl_consignee_products WHERE consignee_id = ? OR product_id = ?')->execute([$consignee, $product]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) OR product_id = ?')->execute([$source, $destination, $product]);
    $db->prepare('DELETE FROM dl_user_branches WHERE user_id IN (?, ?) OR branch_id IN (?, ?)')->execute([$cashier, $admin, $source, $destination]);
    $db->prepare('DELETE FROM dl_users WHERE id IN (?, ?)')->execute([$cashier, $admin]);
    $db->prepare('DELETE FROM dl_consignees WHERE id = ?')->execute([$consignee]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$product]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$source, $destination]);
};

$historySnapshot = static function () use ($db, $consignee): string {
    $consignees = $db->query("SELECT id, code, name, assigned_commissary_id, is_active FROM dl_consignees WHERE id = $consignee ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $deliveries = $db->query("SELECT id, destination_type, consignee_id, dr_number, status FROM dl_deliveries WHERE consignee_id = $consignee ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ledger = $db->query("SELECT consignee_id, product_id, ledger_date, shift, beg_bal, addtl, withdraw FROM dl_consignee_ledger WHERE consignee_id = $consignee ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $audit = $db->query("SELECT action, entity_type, entity_id, old_data, new_data FROM audit_logs WHERE module = 'daily-ledger' AND entity_type = 'dl_consignees' AND entity_id = '$consignee' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return hash('sha256', serialize([$consignees, $deliveries, $ledger, $audit]));
};

$cleanup();
try {
    $defaults = dlSettingsDefaults();
    $h->test('A discriminating: consignee_enabled exists and defaults to enabled (defends live capability on upgrade)', array_key_exists($key, $defaults) && $defaults[$key] === true);

    $roundTrip = dlPersistModuleSettings([$key => false]);
    $h->test('B discriminating: consignee_enabled round-trips through dlPersistModuleSettings (defends the settings control)', $roundTrip && dlModuleSettings(true)[$key] === false);

    $garbageSaved = dlPersistModuleSettings([$key => 'not-a-boolean']);
    $h->test('C discriminating: garbage setting coerces to enabled, never disabled (defends fail-open default)', $garbageSaved && dlModuleSettings(true)[$key] === true);

    $settingsTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/settings.disyl');
    $h->test('D discriminating: settings UI has checkbox, status pill, and save payload (defends visible round-trip)', str_contains($settingsTemplate, 'id="feature-consignee"') && str_contains($settingsTemplate, 'Consignees are {if consignee_enabled}Enabled') && str_contains($settingsTemplate, 'payload.consignee_enabled = featureConsignee.checked'));

    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, "S6-SRC", "S6 Source", 1, 1), (?, "S6-DST", "S6 Destination", 0, 1)')->execute([$source, $destination]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, "S6-P", "S6 Product", 25, 1)')->execute([$product]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1), (?, ?, 1)')->execute([$source, $product, $destination, $product]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (?, "s6-toggle-cashier", "fixture", "S6 Toggle Cashier", "cashier", "AM", 1)')->execute([$cashier]);
    $db->prepare('INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$admin, 's6-toggle-admin', 'fixture', 'S6 Toggle Admin', 'admin', null, 1]);
$db->prepare('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (?, ?)')->execute([$cashier, $source]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?, "S6-CONS", "S6 Consignee", ?, 1)')->execute([$consignee, $source]);
    $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$consignee, $product]);
    $db->prepare('INSERT INTO dl_consignee_ledger (consignee_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw) VALUES (?, ?, ?, "AM", 25, 2, 7, 1)')->execute([$consignee, $product, $date]);
    $db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, consignee_id, dr_number, delivery_date, production_shift, receipt_required, status, posted_at) VALUES ("branch", ?, "consignee", ?, "S6-HISTORY", ?, "AM", 0, "posted", NOW())')->execute([$source, $consignee, $date]);
    $historyDeliveryId = (int)$db->lastInsertId();
dl_auditLog('consignee_created', $source, 'dl_consignees', (string)$consignee, null, ['code' => 'S6-CONS']);

    // The baseline deliberately follows fixture creation: the fixture itself must not look like toggle damage.
    $historyBefore = $historySnapshot();
    $rowsBefore = dl_fetchConsigneeSheetRows($db, $date, $source, 'AM');

    dlPersistModuleSettings([$key => false, 'formal_delivery_workflow_enabled' => true]);
    $refusal = $runApi([
        'branch_id' => $source, 'shift' => 'AM', 'delivery_date' => $date,
        'dr_number' => 'S6-REFUSED', 'destination_type' => 'consignee', 'consignee_id' => $consignee,
        'items' => [['product_id' => $product, 'quantity' => 1, 'unit' => 'pcs']],
    ]);
    $error = (string)($refusal['body']['error'] ?? '');
    $h->test('E discriminating: disabled dispatch API refuses a consignee destination and names the feature', ($refusal['body']['ok'] ?? null) === false && $refusal['status'] === 403 && stripos($error, 'consignee') !== false && stripos($error, 'disabled') !== false, json_encode($refusal, JSON_UNESCAPED_SLASHES));

    $rowsAfter = dl_fetchConsigneeSheetRows($db, $date, $source, 'AM');
    $historyAfter = $historySnapshot();
    $h->test('F pin: disabled feature still returns pre-existing consignee history byte-identically (defends deliveries, ledger, consignee, and audit visibility)', $rowsBefore !== [] && $rowsAfter === $rowsBefore && $historyAfter === $historyBefore);

    $deliveriesView = $runApi(['destination_type' => 'consignee'], 'deliveries', 'admin', $admin);
    $listedDelivery = null;
    foreach (($deliveriesView['body']['deliveries'] ?? []) as $row) {
        if ((int)($row['id'] ?? 0) === $historyDeliveryId) { $listedDelivery = $row; break; }
    }
    $h->test('F2 pin: disabled feature still returns the consignee delivery in the admin Deliveries history view (defends the deliveries list against hide-on-disable)', is_array($listedDelivery) && (string)($listedDelivery['destination_type'] ?? '') === 'consignee' && (int)($listedDelivery['consignee_id'] ?? 0) === $consignee && (string)($listedDelivery['dr_number'] ?? '') === 'S6-HISTORY', json_encode($deliveriesView, JSON_UNESCAPED_SLASHES));

    dlPersistModuleSettings([$key => true]);
    $branchDispatch = $runApi([
        'branch_id' => $source, 'shift' => 'AM', 'delivery_date' => $date,
        'dr_number' => 'S6-BRANCH-PIN', 'destination_type' => 'branch', 'destination_id' => $destination,
        'receiving_shift' => 'AM',
        'items' => [['product_id' => $product, 'quantity' => 1, 'unit' => 'pcs']],
    ]);
    $h->test('G pin: enabling preserves today\'s branch dispatch behaviour (defends unrelated branch delivery capability)', ($branchDispatch['body']['ok'] ?? false) === true && (int)($branchDispatch['body']['delivery_id'] ?? 0) > 0, json_encode($branchDispatch, JSON_UNESCAPED_SLASHES));
} finally {
    $cleanup();
    @unlink($payloadFile);
    dlPersistModuleSettings([$key => $originalEnabled, 'formal_delivery_workflow_enabled' => $originalFormal]);
}

$restored = dl_isConsigneeEnabled() === $originalEnabled
    && dl_isFormalDeliveryEnabled() === $originalFormal;
$remaining = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ($source,$destination)")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE id = $consignee")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id = $product")->fetchColumn();
$h->test('H pin: live settings are restored and fixture rows removed (defends tenant state after the oracle)', $restored && $remaining === 0);
$h->done();
