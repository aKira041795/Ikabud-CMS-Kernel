<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-production-beg-carry', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/entity-views.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/routes.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$branchId = 99771;
$productA = 99771;
$productB = 99772;
$productC = 99773;
$date = '2020-08-03';
$prior = '2020-08-02';
$user = ['id' => 27, 'sub' => 'admin:27', 'role' => 'admin', 'source' => 'daily-ledger', 'full_name' => 'Carry fixture'];
$auditor = ['id' => 27, 'sub' => 'auditor:27', 'role' => 'auditor', 'source' => 'daily-ledger'];

$cleanup = static function () use ($db, $branchId, $productA, $productB, $productC): void {
    $ids = implode(',', [$productA, $productB, $productC]);
    $db->prepare('DELETE FROM audit_logs WHERE branch_id = :b OR entity_id LIKE :prefix')->execute([':b' => $branchId, ':prefix' => $branchId . '-%']);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id = :b')->execute([':b' => $branchId]);
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$branchId} OR product_id IN ({$ids})");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id = {$branchId} OR product_id IN ({$ids})");
    $db->execute("DELETE FROM dl_products WHERE id IN ({$ids})");
    $db->prepare('DELETE FROM dl_branches WHERE id = :b')->execute([':b' => $branchId]);
};
$countFixtures = static function () use ($db, $branchId, $productA, $productB, $productC): array {
    $ids = implode(',', [$productA, $productB, $productC]);
    return [
        'branches' => (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id = {$branchId}")->fetchColumn(),
        'products' => (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id IN ({$ids})")->fetchColumn(),
        'branch_products' => (int)$db->query("SELECT COUNT(*) FROM dl_branch_products WHERE branch_id = {$branchId} OR product_id IN ({$ids})")->fetchColumn(),
        'ledger' => (int)$db->query("SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$branchId} OR product_id IN ({$ids})")->fetchColumn(),
        'statuses' => (int)$db->query("SELECT (SELECT COUNT(*) FROM dl_ledger_day_status WHERE branch_id = {$branchId}) + (SELECT COUNT(*) FROM dl_ledger_shift_status WHERE branch_id = {$branchId})")->fetchColumn(),
        'audit' => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE branch_id = {$branchId} OR entity_id LIKE '{$branchId}-%'")->fetchColumn(),
    ];
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, "self_managed", 1, 1)')
        ->execute([$branchId, 'CARRY-99771', 'Carry Fixture Commissary']);
    foreach ([[$productA, 'CARRY-A'], [$productB, 'CARRY-B'], [$productC, 'CARRY-C']] as [$pid, $sku]) {
        $db->prepare('INSERT INTO dl_products (id, sku, name, sort_order, is_active) VALUES (?, ?, ?, ?, 1)')
            ->execute([$pid, $sku, 'Carry Product ' . $pid, $pid]);
        $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$branchId, $pid]);
    }
    $insert = $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty) VALUES (?, ?, ?, ?, 0, 0, 0, 0, ?)');
    foreach ([
        [$productA, $prior, 'AM', 21], [$productA, $prior, 'PM', 31], [$productA, $date, 'AM', 41],
        [$productB, $prior, 'PM', 32], [$productB, $date, 'AM', 42],
        [$productC, $prior, 'PM', 33], [$productC, $date, 'AM', 43],
    ] as [$pid, $d, $shift, $end]) $insert->execute([$branchId, $pid, $d, $shift, $end]);

    $pm = dl_fetchCommissaryBeginningSuggestions($db, $branchId, $date, 'PM');
    $am = dl_fetchCommissaryBeginningSuggestions($db, $branchId, $date, 'AM');
    $h->test('PM source is same-date AM ending, not prior-day ending', ($pm[$productA] ?? null) === 41 && ($pm[$productB] ?? null) === 42, json_encode(['PM' => $pm]));
    $h->test('AM source is latest prior date with PM preferred over AM', ($am[$productA] ?? null) === 31 && ($am[$productB] ?? null) === 32, json_encode(['AM' => $am]));

    $tokens = dl_generateAuthTokens(['sub' => 'admin:27', 'id' => 27, 'username' => 'carry-fixture', 'name' => 'Carry Fixture', 'role' => 'admin', 'source' => 'daily-ledger']);
    $_COOKIE[dlCookieName()] = $tokens['token'];
    $_GET = ['date' => $date, 'commissary_id' => (string)$branchId, 'shift' => 'PM'];
    ob_start();
    handleAdminCommissary();
    $html = (string)ob_get_clean();
    echo 'ACCEPTANCE_PM_RENDER=' . json_encode(['product_id' => $productA, 'input_value' => 0, 'data_suggestion' => 41, 'data_carry_source' => 'am-end', 'source_label' => 'AM end: 41', 'control' => 'production-carry-beginnings-btn']) . "\n";
    $h->test('PM render exposes zero beginning, AM source, and one-press carry control',
        str_contains($html, 'id="production-beg-' . $productA . '"')
        && preg_match('/id="production-beg-' . $productA . '"[\s\S]*?value="0"[\s\S]*?data-suggestion="41"[\s\S]*?data-recorded="0"[\s\S]*?data-carry-source="am-end"/', $html) === 1
        && str_contains($html, 'AM end: 41')
        && str_contains($html, 'id="production-carry-beginnings-btn"'),
        json_encode(['row' => ['value' => 0, 'suggestion' => 41, 'source' => 'am-end'], 'control' => 'production-carry-beginnings-btn']));

    $badBatchRejected = false;
    try {
        dl_carryCommissaryBeginnings($user, [
            'date' => $date, 'shift' => 'PM', 'commissary_branch_id' => $branchId, 'idempotency_key' => 'carry-invalid-99771',
            'rows' => [['product_id' => $productA, 'beg_qty' => 41], ['product_id' => $productB, 'beg_qty' => 999]],
        ]);
    } catch (RuntimeException $e) { $badBatchRejected = str_contains($e->getMessage(), 'nothing was changed'); }
    $targetCount = static function (int $pid) use ($db, $branchId, $date): int {
        $s = $db->prepare('SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? AND product_id = ? AND ledger_date = ? AND shift = "PM"');
        $s->execute([$branchId, $pid, $date]); return (int)$s->fetchColumn();
    };
    $h->test('invalid second row aborts the whole batch', $badBatchRejected && $targetCount($productA) === 0 && $targetCount($productB) === 0);

    $result = dl_carryCommissaryBeginnings($user, [
        'date' => $date, 'shift' => 'PM', 'commissary_branch_id' => $branchId, 'idempotency_key' => 'carry-valid-99771',
        'rows' => [['product_id' => $productA, 'beg_qty' => 41], ['product_id' => $productB, 'beg_qty' => 42]],
    ]);
    $stored = $db->prepare('SELECT product_id, beg_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? AND ledger_date = ? AND shift = "PM" ORDER BY product_id');
    $stored->execute([$branchId, $date]);
    $storedRows = $stored->fetchAll(PDO::FETCH_KEY_PAIR);
    echo 'ACCEPTANCE_BATCH=' . json_encode(['request_count' => 1, 'carried' => $result['carried'], 'stored' => $storedRows]) . "\n";
    $h->test('one batch carries both unrecorded zero beginnings', $result['carried'] === 2 && array_map('intval', $storedRows) === [$productA => 41, $productB => 42], json_encode(['request_count' => 1, 'stored' => $storedRows]));

    $amResult = dl_carryCommissaryBeginnings($user, [
        'date' => $date, 'shift' => 'AM', 'commissary_branch_id' => $branchId, 'idempotency_key' => 'carry-am-99771',
        'rows' => [['product_id' => $productA, 'beg_qty' => 31]],
    ]);
    $amStored = $db->query("SELECT beg_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id={$branchId} AND product_id={$productA} AND ledger_date='{$date}' AND shift='AM'")->fetchColumn();
    echo 'ACCEPTANCE_AM_CARRY=' . json_encode(['prior_pm_end' => 31, 'stored_beg_qty' => (int)$amStored]) . "\n";
    $h->test('AM carry still stores the prior-day PM ending', $amResult['carried'] === 1 && (int)$amStored === 31, json_encode(['source' => 31, 'stored_beg_qty' => (int)$amStored]));

    dl_saveCommissaryBeginningQty($db, $branchId, $productC, $date, 9, 27, 'PM');
    dl_auditLog('save_commissary_product_beg', $branchId, 'dl_commissary_product_ledger', "{$branchId}-{$productC}-{$date}-PM", null, ['beg_qty' => 9]);
    $recordedRejected = false;
    try {
        dl_carryCommissaryBeginnings($user, [
            'date' => $date, 'shift' => 'PM', 'commissary_branch_id' => $branchId, 'idempotency_key' => 'carry-recorded-99771',
            'rows' => [['product_id' => $productC, 'beg_qty' => 43]],
        ]);
    } catch (RuntimeException $e) { $recordedRejected = str_contains($e->getMessage(), 'already recorded'); }
    $recorded = $db->query("SELECT beg_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id={$branchId} AND product_id={$productC} AND ledger_date='{$date}' AND shift='PM'")->fetchColumn();
    echo 'ACCEPTANCE_RECORDED_UNCHANGED=' . json_encode(['attempted_carry' => 43, 'stored_beg_qty' => (int)$recorded]) . "\n";
    $h->test('recorded beginning is never overwritten', $recordedRejected && (int)$recorded === 9, json_encode(['stored_beg_qty' => (int)$recorded]));

    $db->prepare('INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (?, ?, "AM", "finalized")')->execute([$branchId, $date]);
    $finalizedMessage = '';
    try { dl_carryCommissaryBeginnings($user, ['date' => $date, 'shift' => 'AM', 'commissary_branch_id' => $branchId, 'idempotency_key' => 'guard-finalized', 'rows' => [['product_id' => $productA, 'beg_qty' => 31]]]); }
    catch (RuntimeException $e) { $finalizedMessage = $e->getMessage(); }
    $h->test('finalized shift has its own refusal', str_contains($finalizedMessage, 'AM shift is finalized') && str_contains($finalizedMessage, 'Reopen the shift'), $finalizedMessage);
    $db->prepare('UPDATE dl_ledger_shift_status SET status="open" WHERE branch_id=? AND ledger_date=? AND shift="AM"')->execute([$branchId, $date]);

    $readonlyMessage = '';
    try { dl_carryCommissaryBeginnings($auditor, ['date' => $date, 'shift' => 'AM', 'commissary_branch_id' => $branchId, 'idempotency_key' => 'guard-readonly', 'rows' => [['product_id' => $productA, 'beg_qty' => 31]]]); }
    catch (RuntimeException $e) { $readonlyMessage = $e->getMessage(); }
    $h->test('read-only role has its own refusal', str_contains($readonlyMessage, 'read-only for your role'), $readonlyMessage);

    $db->prepare('INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES (?, ?, "closed")')->execute([$branchId, $date]);
    $closedMessage = '';
    try { dl_carryCommissaryBeginnings($user, ['date' => $date, 'shift' => 'AM', 'commissary_branch_id' => $branchId, 'idempotency_key' => 'guard-closed', 'rows' => [['product_id' => $productA, 'beg_qty' => 31]]]); }
    catch (RuntimeException $e) { $closedMessage = $e->getMessage(); }
    $h->test('closed day has its own refusal', str_contains($closedMessage, 'day is closed') && str_contains($closedMessage, 'Reopen the day'), $closedMessage);
    echo 'ACCEPTANCE_GUARDS=' . json_encode(['finalized' => $finalizedMessage, 'read_only' => $readonlyMessage, 'closed' => $closedMessage]) . "\n";

    $template = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $carryFunction = substr($template, strpos($template, 'function carryProductionBeginnings()'), 2600);
    echo 'ACCEPTANCE_CLIENT_REQUESTS=' . json_encode(['carry_endpoint_calls_in_action' => substr_count($carryFunction, '/api/v1/commissary/carry-beginnings'), 'auto_apply_calls' => substr_count($template, 'carryProductionBeginnings();')]) . "\n";
    $h->test('client carry is explicit, never on load, and issues one batch request',
        substr_count($carryFunction, "productionJson('{base_url}/api/v1/commissary/carry-beginnings'") === 1
        && !str_contains($template, 'carryProductionBeginnings();')
        && str_contains($carryFunction, 'rows: rows'),
        json_encode(['batch_endpoint_calls_in_action' => substr_count($carryFunction, 'productionJson('), 'auto_apply_calls' => substr_count($template, 'carryProductionBeginnings();')]));
} finally {
    $cleanup();
    $remaining = $countFixtures();
    echo 'ACCEPTANCE_CLEANUP_COUNTS=' . json_encode($remaining, JSON_UNESCAPED_SLASHES) . "\n";
    $h->test('fixture data deleted and re-query proves zero rows', array_sum($remaining) === 0, json_encode($remaining));
    modulePopContext();
}

$h->done();
