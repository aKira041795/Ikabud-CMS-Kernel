<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-delta-audit', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'kernel_state_cache: entity_presets rebuilt'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/reporting.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$branchId = 15;
$ledgerDate = '2099-12-27';
$shift = 'AM';
$actorId = 1;

$productStmt = $db->query('SELECT id FROM dl_products WHERE is_active = 1 ORDER BY id LIMIT 1');
$productId = (int)$productStmt->fetchColumn();
$h->test('fixture has an active product', $productId > 0);

$source = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$start = strpos($source, 'function dl_applyLedgerDelta(');
$next = $start === false ? false : strpos($source, "\nfunction ", $start + 10);
$deltaBody = $start === false ? '' : substr($source, $start, ($next === false ? strlen($source) : $next) - $start);
$h->test('INSERT and UPDATE branches both emit the trail', substr_count($deltaBody, "dl_auditLog('ledger_delta_applied'") === 2);

$db->beginTransaction();
try {
    $beforeStmt = $db->prepare('SELECT id, addtl FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift LIMIT 1');
    $beforeStmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $ledgerDate, ':shift' => $shift]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $beforeValue = (int)($before['addtl'] ?? 0);

    $result = dl_applyLedgerDelta($branchId, $productId, $ledgerDate, 7, $actorId, 'addtl', $shift);

    $afterStmt = $db->prepare('SELECT id, addtl FROM dl_daily_ledger WHERE branch_id = :bid AND product_id = :pid AND ledger_date = :d AND shift = :shift LIMIT 1');
    $afterStmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $ledgerDate, ':shift' => $shift]);
    $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $entityId = (string)($after['id'] ?? '');

    $auditStmt = $db->prepare(
        "SELECT actor_user_id, branch_id, new_data FROM audit_logs
          WHERE module = 'daily-ledger' AND action = 'ledger_delta_applied'
            AND entity_type = 'dl_daily_ledger' AND entity_id = :entity
          ORDER BY id"
    );
    $auditStmt->execute([':entity' => $entityId]);
    $audits = $auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $payload = json_decode((string)($audits[count($audits) - 1]['new_data'] ?? ''), true) ?: [];

    $h->section('T-C1 one trail row per mutation');
    $h->test('one call writes exactly one matching audit row', count($audits) === 1);
    $h->test('ledger value is exactly baseline plus delta', (int)($after['addtl'] ?? -1) === $beforeValue + 7 && (int)($result['addtl'] ?? -1) === $beforeValue + 7);
    $h->test('audit identifies actor and delta', (int)($payload['actor_id'] ?? 0) === $actorId && (int)($payload['delta'] ?? 0) === 7);
    $h->test('audit identifies movement scope',
        (int)($payload['branch_id'] ?? 0) === $branchId
        && (int)($payload['product_id'] ?? 0) === $productId
        && ($payload['ledger_date'] ?? '') === $ledgerDate
        && ($payload['shift'] ?? '') === $shift
        && ($payload['column'] ?? '') === 'addtl'
    );
    $h->test('audit records before and after without changing the result',
        (int)($payload['value_before'] ?? -1) === $beforeValue
        && (int)($payload['value_after'] ?? -1) === $beforeValue + 7
    );

    dl_applyLedgerDelta($branchId, $productId, $ledgerDate, 2, $actorId, 'addtl', $shift);
    $auditStmt->execute([':entity' => $entityId]);
    $twoCalls = $auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $afterStmt->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $ledgerDate, ':shift' => $shift]);
    $afterUpdate = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test('UPDATE call adds exactly one more audit row', count($twoCalls) === 2);
    $h->test('UPDATE audit does not alter ledger arithmetic', (int)($afterUpdate['addtl'] ?? -1) === $beforeValue + 9);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$h->done();
