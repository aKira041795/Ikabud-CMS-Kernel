<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-day-ending-shift', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

$h->section('T-A1/T-A4 resolver behaviour');
$snap = $db->prepare('SELECT single_shift_mode FROM dl_branches WHERE id = :id');
$snap->execute([':id' => $branchId]);
$original = $snap->fetchColumn();
try {
    $db->prepare('UPDATE dl_branches SET single_shift_mode = 1 WHERE id = :id')->execute([':id' => $branchId]);
    $h->test('single-shift branch resolves its day-ending shift to AM', dl_dayEndingShift($branchId) === 'AM');

    $db->prepare('UPDATE dl_branches SET single_shift_mode = 0 WHERE id = :id')->execute([':id' => $branchId]);
    $h->test('two-shift branch resolves its day-ending shift to PM', dl_dayEndingShift($branchId) === 'PM');
    $h->test('two-shift parity explicitly keeps PM as last shift', dl_isLastShiftOfDay('PM', $branchId) === true);
} finally {
    if ($original !== false && $original !== null) {
        $db->prepare('UPDATE dl_branches SET single_shift_mode = :mode WHERE id = :id')
            ->execute([':mode' => (int)$original, ':id' => $branchId]);
    }
}

$source = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$bodyOf = static function (string $name) use ($source): string {
    $start = strpos($source, 'function ' . $name . '(');
    $next = $start === false ? false : strpos($source, "\nfunction ", $start + 10);
    return $start === false ? '' : substr($source, $start, ($next === false ? strlen($source) : $next) - $start);
};

$h->section('T-A2 finalize endpoint wiring');
$finalize = $bodyOf('apiFinalizePmShift');
// 2026-11-16: the validation + recompute + finalize body moved into ONE helper shared by this
// endpoint and the close-of-day cutoff (a day must never close with its ending shift open), so
// the shift-sensitive statements are asserted where they now live, plus the delegation itself.
$core = $bodyOf('dl_finalizeDayEndingShiftCore');
$h->test('finalize resolves the day-ending shift', str_contains($finalize, '$endShift = dl_dayEndingShift((int)$branchId)'));
$h->test('shift lock uses resolved shift', str_contains($finalize, 'dl_lockShiftStatusRow($ctx->db(), $branchId, $date, $endShift)'));
$h->test(
    'finalize delegates validation + sales + status to the shared core with the resolved shift',
    str_contains($finalize, 'dl_finalizeDayEndingShiftCore($ctx->db(), $branchId, $date, $endShift, $userId,')
);
$h->test('missing-ending check uses resolved shift', $core !== '' && str_contains($core, 'dl_shiftMissingEndings($db, $branchId, $date, $endShift)'));
$h->test(
    'sales query and recompute use resolved shift',
    $core !== '' && str_contains($core, 'shift = :shift') && str_contains($core, "':shift' => \$endShift") && str_contains($core, '$userId, $endShift)')
);
$h->test(
    'finalize update and audit use resolved shift',
    $core !== '' && str_contains($core, '"{$branchId}-{$date}-{$endShift}"') && str_contains($core, "'shift' => \$endShift")
);
$h->test('finalize endpoint has no PM literal in lock, missing, SELECT, or UPDATE',
    !preg_match("/dl_lockShiftStatusRow\\([^;]*'PM'|dl_shiftMissingEndings\\([^;]*'PM'|shift\\s*=\\s*\\\\?'PM\\\\?'/", $finalize . $core));

$h->section('T-A3 close-day wiring');
$closeDay = $bodyOf('apiCloseDay');
$h->test('manual close resolves the day-ending shift', str_contains($closeDay, '$endShift = dl_dayEndingShift((int)$branchId)'));
$h->test('manual close requires the resolved shift status', str_contains($closeDay, 'dl_lockShiftStatusRow($ctx->db(), $branchId, $date, $endShift)'));
$h->test('manual close no longer locks a literal PM status', !str_contains($closeDay, "dl_lockShiftStatusRow(\$ctx->db(), \$branchId, \$date, 'PM')"));

$h->section('single-shift cashier control');
$cashierTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');
$h->test('single-shift AM context exposes the established finalize action',
    str_contains($cashierTemplate, "single_shift_mode && shift == 'AM'")
    && str_contains($cashierTemplate, 'id="finalize-pm-btn" onclick="finalizePmShift()"'));
$h->test('single-shift control has an honest day-ending label', str_contains($cashierTemplate, 'Close Day-Ending Shift'));

$h->done();
