<?php

declare(strict_types=1);

/**
 * Daily Ledger — POS days close under the SAME shift-lifecycle contract as manual days
 *
 * Owner directive 2026-11-16: "wire POS. better to have POS updated than missing it when enabled."
 *
 * Before this, both closers skipped the shift entirely for POS/fallback days
 * (`if (dl_isFullyManualDay(...))`). POS's own close prechecks (no open carts, variance
 * acknowledged) are an ADDITIONAL gate — they say nothing about the shift that owns the day — so a
 * POS day could be closed while its day-ending shift was still open. That is the same dead end fixed
 * for manual days on the same date: sales provisional, and every control on the sheet gated on
 * `day_status == 'open'`, so nobody could finish them.
 *
 * Contract proved here, at the close-of-day cutoff (dl_autoCloseBranchDayAt):
 *   A) POS day, a counted movement, NO ending  -> 'deferred', day stays open, shift stays open
 *      (the branch keeps the late-count window instead of being locked out);
 *   B) POS day, ending recorded               -> the shift is FINALIZED (audited, source
 *      auto_close_cutoff) and the day closes.
 *
 * A pure POS day writes no ledger counts at all, so (B) is the normal path there; (A) only bites on
 * a mixed day, which is exactly when a missing ending means the day is not finished.
 *
 * Fixtures live on 2025-12-31 (outside the imported data range) and are removed; the tenant's
 * pos_enabled setting is captured and restored.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-pos-close-lifecycle', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-pos.php');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$tenantId = 207;
$branchId = 20;
$date = '2025-12-31';

app()->tenant()->setTenantId($tenantId);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$originalSettings = readTenantModuleSettingsForTenant('daily-ledger', $tenantId);

/** Re-read settings after a write: the preload lives in the request context, not $GLOBALS. */
$refreshSettings = static function () use ($tenantId): array {
    invalidateTenantModuleSettingsCache();
    preloadAllTenantModuleSettings();
    return dlModuleSettings(true);
};

$setPos = static function (bool $on) use ($tenantId, $refreshSettings): array {
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => $on ? '1' : '0']);
    return $refreshSettings();
};

$cleanup = static function () use ($db, $branchId, $date): void {
    foreach (['dl_daily_ledger', 'dl_ledger_day_status', 'dl_ledger_shift_status', 'dl_sales_day_modes', 'dl_variance_flags'] as $t) {
        $db->prepare("DELETE FROM {$t} WHERE branch_id = :b AND ledger_date = :d")->execute([':b' => $branchId, ':d' => $date]);
    }
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND entity_id LIKE :e")
        ->execute([':e' => "{$branchId}-{$date}%"]);
    $db->prepare("DELETE FROM dl_integrity_notifications WHERE aggregate_key LIKE :k")
        ->execute([':k' => "%pending_endings-day-{$branchId}-{$date}%"]);
};

$read = static function (string $table, string $column) use ($db, $branchId, $date): string {
    $stmt = $db->prepare("SELECT {$column} FROM {$table} WHERE branch_id = :b AND ledger_date = :d" . ($table === 'dl_ledger_shift_status' ? " AND shift = 'PM'" : '') . ' LIMIT 1');
    $stmt->execute([':b' => $branchId, ':d' => $date]);
    return (string)$stmt->fetchColumn();
};

try {
    $h->section('POS is actually switched on for this proof');

    $posSettings = $setPos(true);
    $h->test('the tenant reports POS enabled', dl_isPosEnabled() === true);

    $productId = (int)$db->query("SELECT product_id FROM dl_branch_products WHERE branch_id = {$branchId} AND is_active = 1 LIMIT 1")->fetchColumn();
    $h->test('a branch product exists for the fixture', $productId > 0);

    // ── A: counted movement with no ending → defer, do not close ──────────
    $h->section('A: POS day with a missing ending is left open (not closed with an open shift)');

    $cleanup();
    $db->prepare("INSERT INTO dl_sales_day_modes (branch_id, ledger_date, mode, status) VALUES (:b, :d, 'pos', 'active')")
        ->execute([':b' => $branchId, ':d' => $date]);
    $h->test('a POS-mode day is NOT a fully-manual day', dl_isFullyManualDay($db, $branchId, $date) === false);
    $db->prepare(
        "INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, created_at, updated_at)
         VALUES (:b, :p, :d, 'PM', 1.00, 0, 5, 0, NULL, NOW(), NOW())"
    )->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);

    $outcomeA = dl_autoCloseBranchDayAt($ctx, $branchId, $date, dl_closeOfDaySettings(), 20, true);
    $h->test("cutoff closer defers the POS day (got '{$outcomeA}')", $outcomeA === 'deferred');
    $h->test('the day stays OPEN so the ending can still be recorded', $read('dl_ledger_day_status', 'status') === 'open');
    $h->test('the shift stays OPEN (no phantom finalize)', $read('dl_ledger_shift_status', 'status') === 'open');

    // ── B: ending recorded → finalize the shift, then close ───────────────
    $h->section('B: POS day with the ending recorded finalizes the shift and closes');

    $db->prepare("UPDATE dl_daily_ledger SET bal_end = 0 WHERE branch_id = :b AND ledger_date = :d AND shift = 'PM'")
        ->execute([':b' => $branchId, ':d' => $date]);

    $outcomeB = dl_autoCloseBranchDayAt($ctx, $branchId, $date, dl_closeOfDaySettings(), 20, true);
    $h->test("cutoff closer closes the POS day (got '{$outcomeB}')", $outcomeB === 'closed');
    $h->test('the day is CLOSED', $read('dl_ledger_day_status', 'status') === 'closed');
    $h->test('the PM shift is FINALIZED', $read('dl_ledger_shift_status', 'status') === 'finalized');
    $auditStmt = $db->prepare(
        "SELECT COUNT(*) FROM audit_logs
          WHERE module = 'daily-ledger' AND action = 'finalize_shift'
            AND entity_id = :e AND new_data LIKE '%auto_close_cutoff%'"
    );
    $auditStmt->execute([':e' => "{$branchId}-{$date}-PM"]);
    $h->test('the automatic finalize is audited with its source', (int)$auditStmt->fetchColumn() === 1);
    $h->test(
        'the closing day is not flagged as closed-without-finalize',
        (string)$db->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND action = 'auto_close_day' AND entity_id = '{$branchId}-{$date}-PM' AND new_data LIKE '%closed_without_pm_finalize%'")->fetchColumn() === '0'
    );

    // ── C: a pure POS day records no ledger counts at all → finalize + close ─
    $h->section('C: the normal POS day (no ledger counts) closes with its shift finalized');

    $cleanup();
    $db->prepare("INSERT INTO dl_sales_day_modes (branch_id, ledger_date, mode, status) VALUES (:b, :d, 'pos', 'active')")
        ->execute([':b' => $branchId, ':d' => $date]);
    $rowsOnSheet = (int)$db->query("SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id = {$branchId} AND ledger_date = '{$date}'")->fetchColumn();
    $h->test('the day really has no sheet rows (POS writes none)', $rowsOnSheet === 0);

    $outcomeC = dl_autoCloseBranchDayAt($ctx, $branchId, $date, dl_closeOfDaySettings(), 20, true);
    $h->test("cutoff closer closes it without asking for counts (got '{$outcomeC}')", $outcomeC === 'closed');
    $h->test('the day is CLOSED', $read('dl_ledger_day_status', 'status') === 'closed');
    $h->test('the PM shift is FINALIZED even though nothing was counted', $read('dl_ledger_shift_status', 'status') === 'finalized');

    // ── Wiring: the manual close no longer bypasses POS ───────────────────
    $h->section('Manual close applies the same rule');

    $handlers = php_strip_whitespace($base . '/modules/daily-ledger/handlers.php');
    $h->test('apiCloseDay exists', str_contains($handlers, 'function apiCloseDay('));
    $h->test('the close-day endpoint still refuses an unfinalized day-ending shift', str_contains($handlers, '_ENDING_PENDING'));
    $h->test(
        'no close path is gated on the manual-day test any more (definition only)',
        substr_count($handlers, 'dl_isFullyManualDay(') === 1
    );
    $h->test(
        'both closers lock the same day-ending shift row',
        substr_count($handlers, 'dl_lockShiftStatusRow($ctx->db(), $branchId, $date, $endShift)') >= 2
    );
} finally {
    $cleanup();
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => (string)($originalSettings['pos_enabled'] ?? '0')]);
    $refreshSettings();
}

$h->done();
