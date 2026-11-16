<?php

declare(strict_types=1);

/**
 * Daily Ledger — a finalized day-ending shift SIGNS the day's sales mode
 *
 * Owner directive 2026-11-16: "review POS once more and have this tightly integrated with the daily
 * ledger system."
 *
 * The sales mode decides what "official sales" MEANS for the day: the stock-derived ledger figure
 * (manual) or the POS total (pos). Since dl_depositComparisonSales() compares a branch's cash
 * deposit against that official figure, flipping the mode after the day-ending shift was finalized
 * silently re-bases a number the office has already checked against a recorded deposit.
 *
 * The checkout path already refuses to sell into a finalized shift (SHIFT_FINALIZED). This suite
 * proves the same signature rule now covers the mode itself, while keeping correction possible:
 * reopening the shift un-finalizes it, so the mode can still be fixed after a reopen.
 *
 *   AC1  manual -> pos on a finalized day is REFUSED (SHIFT_FINALIZED).
 *   AC2  a first-ever selection on a finalized day is REFUSED (same re-basing hazard).
 *   AC3  an idempotent re-select of the SAME mode is still a no-op (no false refusal).
 *   AC4  after reopen_shift the change is allowed again (correction path intact).
 *   AC5  an unfinalized day changes modes exactly as before.
 *
 * Fixtures live on branch 99894 / 2035-08-02 and are removed in finally; pos_enabled is restored.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-pos-mode-signature', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers-pos.php');
$h->fingerprint('modules/daily-ledger/handlers.php');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$tenantId = 207;
$branchId = 99894;
$date = '2035-08-02';

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

$cleanup = static function () use ($db, $branchId, $date): void {
    foreach (['dl_shift_reconciliation', 'dl_sales_day_modes', 'dl_daily_ledger', 'dl_ledger_day_status', 'dl_ledger_shift_status'] as $t) {
        $db->prepare("DELETE FROM {$t} WHERE branch_id = :b AND ledger_date = :d")->execute([':b' => $branchId, ':d' => $date]);
    }
    $db->prepare('DELETE FROM audit_logs WHERE module = \'daily-ledger\' AND entity_id LIKE :e')
        ->execute([':e' => "{$branchId}-{$date}%"]);
    $db->prepare('DELETE FROM dl_branches WHERE id = :b')->execute([':b' => $branchId]);
};

$shiftStatus = static function (string $shift) use ($db, $branchId, $date): string {
    $stmt = $db->prepare('SELECT status FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d AND shift = :s');
    $stmt->execute([':b' => $branchId, ':d' => $date, ':s' => $shift]);
    return (string)$stmt->fetchColumn();
};

$modeRow = static function () use ($db, $branchId, $date): array {
    $stmt = $db->prepare('SELECT mode FROM dl_sales_day_modes WHERE branch_id = :b AND ledger_date = :d');
    $stmt->execute([':b' => $branchId, ':d' => $date]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$setShift = static function (string $shift, string $status) use ($db, $branchId, $date): void {
    $db->prepare(
        'INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status)
         VALUES (:b, :d, :s, :st)
         ON DUPLICATE KEY UPDATE status = VALUES(status)'
    )->execute([':b' => $branchId, ':d' => $date, ':s' => $shift, ':st' => $status]);
};

$cleanup();

try {
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => '1']);
    $refreshSettings();
    $h->test('POS is enabled for this proof', dl_isPosEnabled() === true);

    $db->prepare('INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
                  VALUES (?,?,?,?, "self_managed", 0, 1)')
        ->execute([$branchId, 'POS-MODE', 'POS Mode Signature Branch', 'Test']);
    $db->prepare("INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES (:b, :d, 'open')")
        ->execute([':b' => $branchId, ':d' => $date]);

    $endShift = dl_dayEndingShift($branchId);
    $h->test('the day-ending shift for this branch is PM', $endShift === 'PM', $endShift);

    // ─── AC5 — baseline: an unfinalized day changes mode ─────────────────
    $h->section('AC5 — an unfinalized day still changes mode');

    $first = dl_pos_selectMode($db, $branchId, $date, 'manual', 1, null);
    $h->test('a first selection on an open day works', !empty($first['ok']), json_encode($first));
    $h->test('the mode is manual', ($modeRow()['mode'] ?? '') === 'manual');

    $toPos = dl_pos_selectMode($db, $branchId, $date, 'pos', 1, null);
    $h->test('manual -> pos before any activity works', !empty($toPos['ok']), json_encode($toPos));
    $h->test('the mode is now pos', ($modeRow()['mode'] ?? '') === 'pos');

    // ─── AC3 — idempotent re-select is never refused ─────────────────────
    $h->section('AC3 — re-selecting the same mode stays a no-op, finalized or not');

    $setShift('PM', 'finalized');
    $h->test('the PM shift is finalized', $shiftStatus('PM') === 'finalized');
    $same = dl_pos_selectMode($db, $branchId, $date, 'pos', 1, null);
    $h->test('re-selecting the SAME mode on a finalized day is still fine', !empty($same['ok']), json_encode($same));

    // ─── AC1 — a real change on a finalized day is refused ───────────────
    $h->section('AC1 — a finalized day-ending shift signs the mode');

    $flip = dl_pos_selectMode($db, $branchId, $date, 'manual', 1, null);
    $h->test('pos -> manual after the shift is finalized is REFUSED', empty($flip['ok']), json_encode($flip));
    $h->test('the refusal is named SHIFT_FINALIZED', ($flip['code'] ?? '') === 'SHIFT_FINALIZED', (string)($flip['code'] ?? ''));
    $h->test('the refusal says reopen first', str_contains((string)($flip['error'] ?? ''), 'Reopen'), (string)($flip['error'] ?? ''));
    $h->test('the mode did not move', ($modeRow()['mode'] ?? '') === 'pos');

    // ─── AC2 — a first-ever selection on a finalized day is refused ──────
    $h->section('AC2 — selecting a mode for the first time cannot re-base a signed day');

    $db->prepare('DELETE FROM dl_sales_day_modes WHERE branch_id = :b AND ledger_date = :d')
        ->execute([':b' => $branchId, ':d' => $date]);
    $late = dl_pos_selectMode($db, $branchId, $date, 'pos', 1, null);
    $h->test('a first selection on a finalized day is REFUSED', empty($late['ok']), json_encode($late));
    $h->test('the late selection is also SHIFT_FINALIZED', ($late['code'] ?? '') === 'SHIFT_FINALIZED', (string)($late['code'] ?? ''));
    $h->test('no mode row was created', $modeRow() === []);

    // ─── AC4 — reopening the shift unlocks the correction ────────────────
    $h->section('AC4 — reopening the shift restores the correction path');

    $setShift('PM', 'open');
    $afterReopen = dl_pos_selectMode($db, $branchId, $date, 'pos', 1, null);
    $h->test('after reopening, the mode can be selected again', !empty($afterReopen['ok']), json_encode($afterReopen));
    $h->test('the mode is pos', ($modeRow()['mode'] ?? '') === 'pos');

    // ─── Wiring — the API surfaces SHIFT_FINALIZED as a conflict ─────────
    $h->section('The API answers a conflict, not a client error');

    $src = php_strip_whitespace($base . '/modules/daily-ledger/handlers-pos.php');
    $h->test('SHIFT_FINALIZED maps to 409 on the select-mode endpoint', str_contains($src, "'MODE_SWITCH_REQUIRES_FALLBACK', 'SHIFT_FINALIZED'"));
    // Scope the ordering check to dl_pos_selectMode's own body: the transition matrix is DEFINED
    // earlier in the file, so a file-wide strpos would compare against the definition, not the call.
    $selectStart = strpos($src, 'function dl_pos_selectMode(');
    $selectBody = $selectStart === false ? '' : substr($src, $selectStart, 4000);
    $guardAt = strpos($selectBody, 'dl_shiftIsFinalized($db, $branchId, $date, $endShift)');
    $matrixAt = strpos($selectBody, 'dl_pos_modeSelectionError(');
    $h->test(
        'the guard reads the day-ending shift status before the transition matrix',
        $guardAt !== false && $matrixAt !== false && $guardAt < $matrixAt,
        'guard=' . var_export($guardAt, true) . ' matrix=' . var_export($matrixAt, true)
    );
} finally {
    $cleanup();
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => (string)($originalSettings['pos_enabled'] ?? '0')]);
    $refreshSettings();
}

$h->done();
