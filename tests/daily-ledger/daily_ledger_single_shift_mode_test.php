<?php

declare(strict_types=1);

/**
 * Daily Ledger — per-branch whole-day (single) shift mode (client request 3, 2026-10-22).
 *
 * Contract: .ai/daily-ledger-client-batch-2026-10-22.contract.md
 *
 * Owner decisions locked:
 *   - single-shift is a BRANCH MODE, not a third ENUM value (rows stay 'AM')
 *   - the day AUTO-CLOSES for such a branch (no manual finalize-pm step)
 *   - pilot branches: Fishport 15, Bagting 17, Minaog 16
 *
 * Each assertion names what breaks if it regresses:
 * - a branch stuck in single-shift mode after an admin turns it off keeps collapsing PM into AM;
 * - if an explicit PM request is not collapsed on a single-shift branch, the operator writes into a
 *   shift that has no data and the day's carry-forward chain skips a link;
 * - if "last shift of the day" still means PM everywhere, a single-shift branch NEVER auto-closes;
 * - if it means AM everywhere, every two-shift branch would auto-close at noon - the worst outcome
 *   here, and the reason the mode must be read per branch rather than globally;
 * - a seam that exists but is not wired enforces nothing.
 *
 * Runs against tenant 207 (baronledger) on branch 15. The mode flag is snapshotted and restored in
 * finally, so a failed run cannot leave a branch in the wrong mode.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-single-shift-mode', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'disyl.compile.phases',
    'disyl.render.breakdown',
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

$branchId = 15; // Fishport — approved pilot branch
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$h->section('seam');

$hasMode = function_exists('dl_branchSingleShiftMode');
$hasLast = function_exists('dl_isLastShiftOfDay');
$h->test('dl_branchSingleShiftMode() exists', $hasMode, 'contract R5 seam missing');
$h->test('dl_isLastShiftOfDay() exists', $hasLast, 'contract R5 seam missing');

$original = null;
if ($hasMode && $hasLast) {
    $h->section('behaviour');

    $snap = $db->prepare('SELECT single_shift_mode FROM dl_branches WHERE id = :id');
    $snap->execute([':id' => $branchId]);
    $original = $snap->fetchColumn();

    try {
        // ── mode OFF: exactly today's behaviour ──────────────────────────
        $db->prepare('UPDATE dl_branches SET single_shift_mode = 0 WHERE id = :id')->execute([':id' => $branchId]);

        $h->test(
            'mode OFF: branch reports two-shift mode',
            dl_branchSingleShiftMode($branchId) === false,
            'a branch that was never converted must stay two-shift'
        );
        $h->test(
            'mode OFF: explicit PM is honoured (today\'s behaviour)',
            (dl_resolveLedgerShift(['role' => 'admin'], ['shift' => 'PM', 'branch_id' => $branchId])['shift'] ?? '') === 'PM',
            'the correction path must keep working on two-shift branches'
        );
        $h->test(
            'mode OFF: AM is NOT the last shift of the day',
            dl_isLastShiftOfDay('AM', $branchId) === false,
            'treating AM as day-end would auto-close every two-shift branch at noon'
        );
        $h->test(
            'mode OFF: PM is the last shift of the day',
            dl_isLastShiftOfDay('PM', $branchId) === true,
            'PM must still close the day'
        );

        // ── mode ON ─────────────────────────────────────────────────────
        $db->prepare('UPDATE dl_branches SET single_shift_mode = 1 WHERE id = :id')->execute([':id' => $branchId]);

        $h->test(
            'mode ON: branch reports single-shift mode',
            dl_branchSingleShiftMode($branchId) === true,
            'the branch switch has no effect'
        );
        $h->test(
            'mode ON: an explicit PM request collapses to AM',
            (dl_resolveLedgerShift(['role' => 'admin'], ['shift' => 'PM', 'branch_id' => $branchId])['shift'] ?? '') === 'AM',
            'PM rows would be created on a branch that only has one shift, breaking carry-forward'
        );
        $h->test(
            'mode ON: AM is the last shift of the day',
            dl_isLastShiftOfDay('AM', $branchId) === true,
            'a single-shift branch would never auto-close'
        );
        $h->test(
            'mode ON: PM is still reported as a day-end shift (harmless, no PM data exists)',
            dl_isLastShiftOfDay('PM', $branchId) === true,
            'historical PM rows must still be closeable'
        );
    } finally {
        if ($original !== false && $original !== null) {
            $db->prepare('UPDATE dl_branches SET single_shift_mode = :v WHERE id = :id')
                ->execute([':v' => (int)$original, ':id' => $branchId]);
        }
    }
}

$h->section('report rows carry the flag instead of re-querying it');

// The report queries already join dl_branches, so they select single_shift_mode and the row
// predicate reads it. Measured before this seam: dl_rowIsProvisional() issued its own SELECT
// for every rendered row, because dl_isLastShiftOfDay() had no way to accept a value it was
// already holding.
//
// Discriminating: the DATABASE says whole-day, the ROW says two-shift. If the predicate still
// queried, the row value could not win.
$db->prepare('UPDATE dl_branches SET single_shift_mode = 1 WHERE id = :id')->execute([':id' => $branchId]);
try {
    $rowFromJoin = [
        'shift' => 'AM',
        'branch_id' => $branchId,
        'shift_status' => null,
        'bal_end' => 5,
        'end_source' => 'counted',
        'branch_single_shift_mode' => 0,
    ];
    $h->test(
        'a joined branch flag wins over a live read',
        dl_rowIsProvisional($rowFromJoin) === false,
        'the report still queries per row, or ignores the value it already joined'
    );

    $rowWithoutFlag = $rowFromJoin;
    unset($rowWithoutFlag['branch_single_shift_mode']);
    $h->test(
        'without the column the live read still applies',
        dl_rowIsProvisional($rowWithoutFlag) === true,
        'a caller whose query does not join the branch must keep today\'s behaviour'
    );

    // Mirror case: the DATABASE now says two-shift while the ROW says whole-day. The two
    // directions together are the proof that the row value is what the predicate uses - and a
    // value is only usable without a query if the query is skipped. (A row count per report
    // cannot be sampled here: the module DB contract forbids SHOW/performance_schema.)
    $db->prepare('UPDATE dl_branches SET single_shift_mode = 0 WHERE id = :id')->execute([':id' => $branchId]);
    $rowWholeDay = $rowFromJoin;
    $rowWholeDay['branch_single_shift_mode'] = 1;
    $h->test(
        'the row flag is honoured in both directions',
        dl_rowIsProvisional($rowWholeDay) === true,
        'the predicate ignores the joined value, so each report row queries the branch again'
    );
} finally {
    $db->prepare('UPDATE dl_branches SET single_shift_mode = :v WHERE id = :id')
        ->execute([':v' => (int)($original ?? 0), ':id' => $branchId]);
}

$h->section('enforcement wiring');

$sources = [];
foreach (['handlers.php', 'handlers-deliveries.php', 'helpers/reporting.php'] as $rel) {
    $sources[$rel] = (string)@file_get_contents($base . '/modules/daily-ledger/' . $rel);
}

$callsInside = static function (string $fn, string $needle) use ($sources): bool {
    foreach ($sources as $src) {
        $start = strpos($src, 'function ' . $fn . '(');
        if ($start === false) {
            continue;
        }
        $next = strpos($src, "\nfunction ", $start + 10);
        $body = substr($src, $start, ($next === false ? strlen($src) : $next) - $start);
        if (str_contains($body, $needle)) {
            return true;
        }
    }
    return false;
};

$h->test(
    'dl_resolveLedgerShift() consults the branch mode',
    $callsInside('dl_resolveLedgerShift', 'dl_branchSingleShiftMode'),
    'shift resolution ignores the branch mode'
);
$h->test(
    'dl_maybeAutoFinalizeCommissaryPmShift() uses the last-shift predicate',
    $callsInside('dl_maybeAutoFinalizeCommissaryPmShift', 'dl_isLastShiftOfDay'),
    'auto-close still hardcodes PM'
);
$h->test(
    'shift reconciliation uses the last-shift predicate',
    str_contains($sources['helpers/reporting.php'], 'dl_isLastShiftOfDay'),
    'reports would assume PM is day-end on single-shift branches'
);
$h->test(
    'both report queries select the branch flag they already join',
    str_contains($sources['helpers/reporting.php'], 'b.single_shift_mode AS branch_single_shift_mode')
        && str_contains($sources['handlers.php'], 'b.single_shift_mode AS branch_single_shift_mode'),
    'a report would silently fall back to reading the branch once per row'
);
$h->test(
    'the row predicate reads the joined flag and passes it through',
    $callsInside('dl_rowIsProvisional', 'branch_single_shift_mode')
        && str_contains($sources['handlers.php'], 'function dl_isLastShiftOfDay(string $shift, int $branchId, ?bool $singleShiftMode = null)'),
    'there is no seam a report can pass the value through, so the cost comes back'
);

$h->done();
