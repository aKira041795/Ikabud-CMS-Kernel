<?php

declare(strict_types=1);

/**
 * Daily Ledger — a POS variance needs something to differ FROM
 *
 * Owner directive 2026-11-16: "review POS once more and have this tightly integrated with the daily
 * ledger system."
 *
 * POS writes no ledger counts, so on a pure POS day the stock-derived total is 0 by construction and
 * `variance_amount` equals the day's entire takings. Two consequences were both wrong:
 *
 *   - the manual close demanded the branch tick "POS and stock-derived sales differ by PHP <the whole
 *     day>. Acknowledge the variance to close the day?" EVERY day — a confirmation that means nothing,
 *     which is exactly how the one real mixed-day variance gets waved through;
 *   - the clock closer recorded `pos_variance_unattended` every day, so the alert the office is meant
 *     to read on a mixed day was buried in daily noise.
 *
 * The rule now matches the deposit basis: no counted rows means no comparison.
 *
 *   AC1  a pure POS day (no sheet rows) needs NO acknowledgement to close.
 *   AC2  the clock closer writes no `pos_variance_unattended` for it.
 *   AC3  a counted POS day with a real difference still requires the acknowledgement.
 *   AC4  acknowledging it is still audited, and the clock closer still records the unattended one.
 *   AC5  a counted POS day that agrees needs no acknowledgement.
 *
 * Fixtures on branch 99895 / 2035-08-03, removed in finally; pos_enabled restored.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-pos-variance-basis', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-pos.php');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$tenantId = 207;
$branchId = 99895;
$productId = 99895;
$date = '2035-08-03';

app()->tenant()->setTenantId($tenantId);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$originalSettings = readTenantModuleSettingsForTenant('daily-ledger', $tenantId);

$refreshSettings = static function () use ($tenantId): array {
    invalidateTenantModuleSettingsCache();
    preloadAllTenantModuleSettings();
    return dlModuleSettings(true);
};

$cleanup = static function () use ($db, $branchId, $productId, $date): void {
    foreach (['dl_pos_sales', 'dl_sales_day_modes', 'dl_daily_ledger', 'dl_ledger_day_status', 'dl_ledger_shift_status'] as $t) {
        $db->prepare("DELETE FROM {$t} WHERE branch_id = :b AND ledger_date = :d")->execute([':b' => $branchId, ':d' => $date]);
    }
    $db->prepare('DELETE FROM audit_logs WHERE module = \'daily-ledger\' AND entity_id LIKE :e')
        ->execute([':e' => "{$branchId}-{$date}%"]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_branches WHERE id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_products WHERE id = :p')->execute([':p' => $productId]);
};

$auditCount = static function (string $action) use ($db, $branchId, $date): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE module = \'daily-ledger\' AND action = :a AND entity_id = :e');
    $stmt->execute([':a' => $action, ':e' => "{$branchId}-{$date}"]);
    return (int)$stmt->fetchColumn();
};

$cleanup();

try {
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => '1']);
    $refreshSettings();
    $h->test('POS is enabled for this proof', dl_isPosEnabled() === true);

    $db->prepare('INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
                  VALUES (?,?,?,?, "self_managed", 0, 1)')
        ->execute([$branchId, 'POS-VAR', 'POS Variance Branch', 'Test']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?,?,?,100,0,1)')
        ->execute([$productId, 'POS-VAR-P', 'POS Variance Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')
        ->execute([$branchId, $productId]);
    $db->prepare("INSERT INTO dl_sales_day_modes (branch_id, ledger_date, mode, status) VALUES (:b, :d, 'pos', 'active')")
        ->execute([':b' => $branchId, ':d' => $date]);
    $db->prepare("INSERT INTO dl_pos_sales (sale_uuid, client_operation_key, branch_id, ledger_date, cashier_id, shift, receipt_no, status, sale_kind, total_cents, completed_at)
                  VALUES ('bbbbbbbb-0000-4000-8000-000000000001', 'k-var-1', :b, :d, 1, 'PM', 'R-VAR-1', 'completed', 'sale', 50000, NOW())")
        ->execute([':b' => $branchId, ':d' => $date]);
    $db->prepare("INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES (:b, :d, 'open')")
        ->execute([':b' => $branchId, ':d' => $date]);

    // ─── AC1 / AC2 — pure POS day: no counts, no comparison ──────────────
    $h->section('AC1/AC2 — a pure POS day has no variance to acknowledge or report');

    $summary = dl_pos_salesSummary($db, $branchId, $date);
    $h->test('the day has zero sheet rows', ($summary['stock_row_count'] ?? -1) === 0, (string)($summary['stock_row_count'] ?? 'missing'));
    $h->test('the variance is flagged NOT comparable', ($summary['variance_comparable'] ?? true) === false);
    $h->test('the raw variance would be the whole day (an artefact, kept for the maths)', abs((float)$summary['variance_amount'] - 500.0) < 0.01, (string)$summary['variance_amount']);

    $precheck = dl_pos_dayClosePrecheck($db, $branchId, $date, []);
    $h->test('closing needs NO acknowledgement on a pure POS day', $precheck === null, json_encode($precheck));

    $outcome = dl_autoCloseBranchDayAt($ctx, $branchId, $date, dl_closeOfDaySettings(), 20, true);
    $h->test('the clock closer closes the day', $outcome === 'closed', $outcome);
    $h->test('no unattended POS-variance alert is recorded for it', $auditCount('pos_variance_unattended') === 0, (string)$auditCount('pos_variance_unattended'));

    // ─── AC3 — a counted POS day with a real difference ──────────────────
    $h->section('AC3/AC4 — a COUNTED POS day still demands the acknowledgement');

    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d')
        ->execute([':b' => $branchId, ':d' => $date]);
    $db->prepare("INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES (:b, :d, 'open')")
        ->execute([':b' => $branchId, ':d' => $date]);
    // 1 unit counted at price 100 = 100.00 stock-derived vs 500.00 POS -> a real 400.00 difference.
    $db->prepare(
        "INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, created_at, updated_at)
         VALUES (:b, :p, :d, 'PM', 100, 0, 1, 0, 0, NOW(), NOW())"
    )->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);

    $counted = dl_pos_salesSummary($db, $branchId, $date);
    $h->test('the counted day is comparable', ($counted['variance_comparable'] ?? false) === true);
    $h->test('the variance is the real difference (500 - 100 = 400.00)', abs((float)$counted['variance_amount'] - 400.0) < 0.01, (string)$counted['variance_amount']);

    $needsAck = dl_pos_dayClosePrecheck($db, $branchId, $date, []);
    $h->test('closing WITHOUT the acknowledgement is refused', is_array($needsAck) && ($needsAck['code'] ?? '') === 'POS_VARIANCE_ACK_REQUIRED', json_encode($needsAck));
    $h->test('the refusal carries the summary the branch must review', isset($needsAck['summary']['variance_amount']));

    $acked = dl_pos_dayClosePrecheck($db, $branchId, $date, ['acknowledge_variance' => true]);
    $h->test('with the acknowledgement it proceeds', $acked === null, json_encode($acked));
    $h->test('the acknowledgement is audited', $auditCount('pos_close_variance_acknowledged') === 1, (string)$auditCount('pos_close_variance_acknowledged'));

    // The clock closer sees the same real variance and records it as unattended.
    $outcomeB = dl_autoCloseBranchDayAt($ctx, $branchId, $date, dl_closeOfDaySettings(), 20, true);
    $h->test('the clock closer still closes the counted day', $outcomeB === 'closed', $outcomeB);
    $h->test('the unattended variance IS recorded when there was a real difference', $auditCount('pos_variance_unattended') === 1, (string)$auditCount('pos_variance_unattended'));

    // ─── AC5 — counted and agreeing needs no acknowledgement ─────────────
    $h->section('AC5 — a counted day that agrees needs no acknowledgement');

    $db->prepare('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d')
        ->execute([':b' => $branchId, ':d' => $date]);
    $db->prepare("INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES (:b, :d, 'open')")
        ->execute([':b' => $branchId, ':d' => $date]);
    // 5 units at 100 = 500.00, matching the POS total exactly.
    $db->prepare('UPDATE dl_daily_ledger SET addtl = 5 WHERE branch_id = :b AND ledger_date = :d')
        ->execute([':b' => $branchId, ':d' => $date]);

    $agreeing = dl_pos_salesSummary($db, $branchId, $date);
    $h->test('the two figures agree', abs((float)$agreeing['variance_amount']) < 0.01, (string)$agreeing['variance_amount']);
    $h->test('an agreeing counted day still needs no acknowledgement', dl_pos_dayClosePrecheck($db, $branchId, $date, []) === null);
} finally {
    $cleanup();
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => (string)($originalSettings['pos_enabled'] ?? '0')]);
    $refreshSettings();
}

$h->done();
