<?php

declare(strict_types=1);

/**
 * Daily Ledger — branch operating switches: Settings quick toggle AND the Branches Edit form.
 *
 * OWNER REQUEST (2026-10-30): "recheck branch switch wiring at admin settings. we need to ensure
 * it's tightly set."
 *
 * The two switches are dl_branches.paperless_dr_enabled (may a cashier add stock without a paper
 * DR) and dl_branches.single_shift_mode (whole-day shift). They are written from THREE places:
 * create-branch, the Settings quick toggle (apiUpdateBranchFlags), and the Branches Edit form
 * (apiUpdateBranch). Tight wiring means all three obey ONE guard and ONE audit vocabulary, and
 * that a refused toggle is never drawn as if it had been applied.
 *
 * Each assertion names what breaks if it regresses:
 * - a second copy of the guard in the Edit form: the two screens can drift, and the Edit form
 *   used to audit only the shift mode, so a paperless change left no before/after evidence;
 * - check-then-write: the first ledger row of the business day can commit between the check and
 *   the update, orphaning the PM bal_end -> next AM beg_bal chain the rule exists to protect;
 * - a no-op toggle that still writes and audits: the log fills with entries claiming a change;
 * - an optimistic checkbox that is not reverted on refusal: the panel reports ON while the
 *   database still says OFF;
 * - a cached reader: the write lands but the running request still sees the old mode.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-branch-switch-wiring', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'disyl.compile.phases',
    'disyl.render.breakdown',
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'kernel_state_cache: entity_presets rebuilt',
    'capability.call'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$branchId = 99851;
$productId = 99851;
$date = dl_businessDate();

$routes = (string) file_get_contents($base . '/modules/daily-ledger/routes.php');
$handlers = (string) file_get_contents($base . '/modules/daily-ledger/handlers.php');
$deliveriesSrc = (string) file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
$settingsTpl = (string) file_get_contents($base . '/templates/modules/daily-ledger/admin/settings.disyl');

$fnBody = static function (string $src, string $fn): string {
    $start = strpos($src, 'function ' . $fn . '(');
    if ($start === false) {
        return '';
    }
    $next = strpos($src, "\nfunction ", $start + 10);
    return substr($src, $start, ($next === false ? strlen($src) : $next) - $start);
};

$switches = static function () use ($db, $branchId): array {
    $stmt = $db->prepare('SELECT paperless_dr_enabled, single_shift_mode FROM dl_branches WHERE id = :id');
    $stmt->execute([':id' => $branchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [(int) ($row['paperless_dr_enabled'] ?? -1), (int) ($row['single_shift_mode'] ?? -1)];
};

$auditRows = static function () use ($db, $branchId): int {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM audit_logs
          WHERE module = 'daily-ledger' AND action = 'branch_flags_update' AND branch_id = :b"
    );
    $stmt->execute([':b' => $branchId]);
    return (int) $stmt->fetchColumn();
};

$cleanup = static function () use ($db, $branchId, $productId): void {
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id = ? OR product_id = ?')->execute([$branchId, $productId]);
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND branch_id = ?")->execute([$branchId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = ? OR product_id = ?')->execute([$branchId, $productId]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$productId]);
    $db->prepare('DELETE FROM dl_branches WHERE id = ?')->execute([$branchId]);
};

$cleanup();
try {
    $db->prepare(
        'INSERT INTO dl_branches (id, code, name, is_active, paperless_dr_enabled, single_shift_mode)
         VALUES (?, ?, ?, 1, 1, 0)'
    )->execute([$branchId, 'SW-99851', 'Switch Fixture Branch']);
    $db->prepare(
        'INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, ?, ?, 1, 1)'
    )->execute([$productId, 'SW-P-99851', 'Switch Fixture Product']);

    // ------------------------------------------------------------ wiring

    $h->section('the Settings toggle is wired to its own flags endpoint');

    $h->test(
        'the flags endpoint is routed to the flags handler',
        str_contains($routes, "'/daily-ledger/api/v1/admin/branches/flags'")
            && str_contains($routes, "'daily-ledger:apiUpdateBranchFlags'"),
        'the switch posts to a route that does not exist'
    );
    $h->test(
        'both switches are rendered as flag toggles',
        str_contains($settingsTpl, 'data-branch-flag="paperless_dr_enabled"')
            && str_contains($settingsTpl, 'data-branch-flag="single_shift_mode"'),
        'a switch would render without the key the endpoint reads'
    );
    $h->test(
        'the toggle disables its peers while a save is in flight',
        str_contains($settingsTpl, 'peers[i].disabled = true')
            && str_contains($settingsTpl, 'peers[m].disabled = false'),
        'two clicks could race and the second response would overwrite the first'
    );
    $h->test(
        'a refused branch is redrawn from its prior value',
        str_contains($settingsTpl, 'peers[p].checked = prior[p];'),
        'a refused switch would still look ON in the panel while the database says OFF'
    );

    // ------------------------------------------------------------ the shared applier

    $h->section('the Edit form and the Settings toggle share one guard');

    $h->test(
        'the Edit form routes both switches through dl_applyBranchFlags()',
        str_contains($fnBody($handlers, 'apiUpdateBranch'), 'dl_applyBranchFlags('),
        'the Edit form carries a second copy of the rule and can drift from the toggle'
    );
    $h->test(
        'the Edit form no longer writes the switches itself',
        !str_contains($fnBody($handlers, 'apiUpdateBranch'), 'paperless_dr_enabled = :paperless_dr')
            && !str_contains($fnBody($handlers, 'apiUpdateBranch'), 'single_shift_mode = :single_shift'),
        'two UPDATEs can disagree about the same column, and only one is guarded'
    );
    $h->test(
        'paperless changes now reach the shared before/after audit',
        str_contains($fnBody($handlers, 'dl_applyBranchFlags'), "'paperless_dr_enabled' => (int)\$before['paperless_dr_enabled']"),
        'a paperless switch written from the Edit form left no before/after evidence'
    );

    // ------------------------------------------------------------ the R7 gate

    $h->section('the mid-day guard cannot be separated from the write');

    $applyBody = $fnBody($handlers, 'dl_applyBranchFlags');
    $h->test(
        'the guard is one NOT EXISTS inside the UPDATE',
        str_contains($applyBody, 'NOT EXISTS (SELECT 1 FROM dl_daily_ledger dl_gate'),
        'a ledger row can commit between the check and the write'
    );
    $h->test(
        'there is no separate check-then-write left',
        !str_contains($applyBody, 'SELECT COUNT(*) FROM dl_daily_ledger'),
        'the guard is back to a read whose result can be stale before the UPDATE runs'
    );

    // ------------------------------------------------------------ behaviour

    $h->section('behaviour of the shared applier');

    $refused = dl_applyBranchFlags($db, $branchId, ['is_commissary' => 1]);
    $h->test(
        'an unknown switch key is refused, not silently dropped',
        ($refused['ok'] ?? true) === false && str_contains((string) ($refused['error'] ?? ''), 'Unknown branch switch'),
        'a typo in a flag name would report success and change nothing'
    );

    $empty = dl_applyBranchFlags($db, $branchId, []);
    $h->test(
        'an empty switch payload is refused',
        ($empty['ok'] ?? true) === false,
        'a caller could believe it saved something'
    );

    $missing = dl_applyBranchFlags($db, 9999999, ['single_shift_mode' => 1]);
    $h->test(
        'an unknown branch is refused',
        ($missing['ok'] ?? true) === false && str_contains((string) ($missing['error'] ?? ''), 'Branch not found'),
        'a stale panel could report a switch saved against no branch'
    );

    $applied = dl_applyBranchFlags($db, $branchId, ['paperless_dr_enabled' => 0, 'single_shift_mode' => 1]);
    $h->test(
        'a real change applies both switches and reports changed',
        ($applied['ok'] ?? false) === true
            && ($applied['changed'] ?? false) === true
            && $switches() === [0, 1],
        'the switch does not reach the branch row'
    );

    $auditBefore = $auditRows();
    $idempotent = dl_applyBranchFlags($db, $branchId, ['paperless_dr_enabled' => 0, 'single_shift_mode' => 1]);
    $h->test(
        're-applying the current value writes nothing and audits nothing',
        ($idempotent['ok'] ?? false) === true
            && ($idempotent['changed'] ?? true) === false
            && $auditRows() === $auditBefore
            && $switches() === [0, 1],
        'the audit log fills with rows whose before and after are identical'
    );

    $latest = $db->prepare(
        "SELECT old_data, new_data FROM audit_logs
          WHERE module = 'daily-ledger' AND action = 'branch_flags_update' AND branch_id = :b
          ORDER BY id DESC LIMIT 1"
    );
    $latest->execute([':b' => $branchId]);
    $auditRow = $latest->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'the audit row carries before AND after for both switches',
        str_contains((string) ($auditRow['old_data'] ?? ''), 'paperless_dr_enabled')
            && str_contains((string) ($auditRow['old_data'] ?? ''), 'single_shift_mode')
            && str_contains((string) ($auditRow['new_data'] ?? ''), 'paperless_dr_enabled')
            && str_contains((string) ($auditRow['new_data'] ?? ''), 'single_shift_mode'),
        'an admin cannot tell which switch moved, or from what'
    );

    // ------------------------------------------------------------ the guard, for real

    $db->prepare(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal) VALUES (?, ?, ?, "AM", 0)'
    )->execute([$branchId, $productId, $date]);

    $blocked = dl_applyBranchFlags($db, $branchId, ['single_shift_mode' => 0]);
    $h->test(
        'the shift mode cannot change once the business day has rows',
        ($blocked['ok'] ?? true) === false
            && str_contains((string) ($blocked['error'] ?? ''), 'before the first ledger row')
            && $switches() === [0, 1],
        'flipping mid-day orphans the carry-forward chain (PM bal_end -> next AM beg_bal)'
    );

    $paperlessStill = dl_applyBranchFlags($db, $branchId, ['paperless_dr_enabled' => 1]);
    $h->test(
        'the paperless switch is still changeable mid-day',
        ($paperlessStill['ok'] ?? false) === true
            && ($paperlessStill['changed'] ?? false) === true
            && $switches() === [1, 1],
        'the shift-mode rule would block an unrelated switch'
    );

    // ------------------------------------------------------------ readers

    $h->section('the readers cannot serve a stale mode');

    $h->test(
        'neither switch reader is memoised',
        !str_contains($fnBody($handlers, 'dl_branchSingleShiftMode'), 'static $cache')
            && !str_contains($fnBody($deliveriesSrc, 'dl_branchPaperlessDrEnabled'), 'static $cache'),
        'a cached reader makes a toggle look applied while this request still sees the old mode'
    );
    $h->test(
        'the report path gets the shift mode from its own join, not a per-row read',
        str_contains((string) file_get_contents($base . '/modules/daily-ledger/helpers/reporting.php'), 'b.single_shift_mode AS branch_single_shift_mode'),
        'report rendering would go back to one SELECT per row'
    );
} finally {
    $cleanup();
}

$remaining = [
    'branches' => (int) $db->query("SELECT COUNT(*) FROM dl_branches WHERE id = {$branchId}")->fetchColumn(),
    'products' => (int) $db->query("SELECT COUNT(*) FROM dl_products WHERE id = {$productId}")->fetchColumn(),
    'ledger' => (int) $db->query("SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id = {$branchId}")->fetchColumn(),
    'audit' => (int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND branch_id = {$branchId}")->fetchColumn(),
];
$h->test(
    'fixture cleanup pin: every private-id row is removed',
    array_sum($remaining) === 0,
    json_encode($remaining)
);

$h->done();
