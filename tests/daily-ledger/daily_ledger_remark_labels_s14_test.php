<?php

declare(strict_types=1);

/**
 * Daily Ledger — S14: operator-readable labels for machine values.
 *
 * Owner directive: remarks / notifications that carried machine markers or raw
 * tokens must read as human text. The stored columns KEEP their markers (audit
 * tooling, repair scripts and tests match on them); every surface an operator
 * reads is translated at render/transport time.
 *
 * Proved here:
 *   1. dl_deliveryHumanLabel() maps the full marker inventory to operator text,
 *      passes free text through verbatim, and never leaks `[brackets]`.
 *   2. Every allowed withdrawal reason code has a human label (the cashier
 *      adjustment list and the admin list serve `reason_code_label`).
 *   3. The REAL commissary admin page (render of 2026-10-11, commissary 18)
 *      contains ZERO raw markers while showing the human labels.
 *   4. The REAL apiTodayCashierWithdrawals endpoint serves `reason_code_label`.
 *
 * Tenant 207 (baron-001). Read-only: no fixture writes.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-remark-labels-s14', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines('disyl.compile.phases', 'kernel_state_cache: module_registry rebuilt', 'disyl.render.breakdown');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_remark_labels_s14_harness.php');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$harness = __DIR__ . '/daily_ledger_remark_labels_s14_harness.php';
$runHarness = static function (array $args) use ($harness): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string)$arg);
    }
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>/dev/null', $output, $exitCode);
    $decoded = json_decode(implode("\n", $output), true);
    return is_array($decoded) ? $decoded : ['_raw' => implode("\n", $output), '_exit' => $exitCode];
};

// ═══════════════════════════════════════════════════════════════════════
$h->section('1. Marker -> operator text (pure)');

$cases = [
    '[daily-sheet-entry] new' => 'Daily sheet entry',
    '[daily-sheet-entry] correction correction:encoder_omission' => 'Daily sheet correction — Encoder omission (nothing lost)',
    '[daily-sheet-entry] correction adjustment_add:spoilage' => 'Daily sheet correction — Spoilage',
    '[daily-sheet-consignee-entry] new' => 'Daily sheet entry (consignee)',
    '[daily-sheet-consignee-entry] correction' => 'Daily sheet correction (consignee)',
    '[cashier-dispatch]' => 'Cashier dispatch',
    '[captured-from-paper-dr]' => 'Captured from paper DR',
    '[auto-dr-production]' => 'Auto DR (production receive)',
    '[commissary-dispatch]' => 'Commissary dispatch',
    '[cashier-pullout-return]' => 'Pullout return',
    '[cashier-pullout-return:wastage]' => 'Pullout return — wastage',
    '[self-managed-no-delivery]' => 'Self-managed — no delivery',
    'pullout_return:9' => 'Pullout return (source branch #9)',
    'DR-0001 / driver Juan' => 'DR-0001 / driver Juan',
    'Rider left at 3pm' => 'Rider left at 3pm',
    '' => '',
];
foreach ($cases as $input => $expected) {
    $h->assertSame($expected, dl_deliveryHumanLabel((string)$input), "label: '{$input}'");
}
$h->test(
    'unknown bracketed marker is humanised, never shown with brackets',
    !str_contains(dl_deliveryHumanLabel('[some-new-marker:x]'), '[')
    && !str_contains(dl_deliveryHumanLabel('[some-new-marker:x]'), ']'),
    dl_deliveryHumanLabel('[some-new-marker:x]')
);

// ═══════════════════════════════════════════════════════════════════════
$h->section('2. Reason codes all carry human labels');

$reasonLabels = dl_dailySheetAdjustmentReasons();
$missing = [];
foreach (dl_allowedWithdrawalReasons() as $code) {
    if (!isset($reasonLabels[$code]) || trim((string)$reasonLabels[$code]) === '') {
        $missing[] = $code;
    }
}
$h->test('every allowed withdrawal reason has a non-empty label', $missing === [], implode(',', $missing));
$h->assertSame('Encoder omission (nothing lost)', (string)($reasonLabels['encoder_omission'] ?? ''), 'encoder_omission label');

// ═══════════════════════════════════════════════════════════════════════
$h->section('3. Serve + render wiring (source pins)');

$handlersSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$h->test("commissary handler decorates delivery rows with remarks_label", str_contains($handlersSrc, "\$drRow['remarks_label']"));
$h->test("commissary handler decorates pullout rows with remarks_label", str_contains($handlersSrc, "\$prRow['remarks_label']"));
$adjStart = strpos($handlersSrc, 'function apiTodayCashierWithdrawals');
$adjEnd = $adjStart === false ? false : strpos($handlersSrc, "\nfunction ", $adjStart + 10);
$adjSrc = ($adjStart === false || $adjEnd === false) ? '' : substr($handlersSrc, $adjStart, $adjEnd - $adjStart);
$h->test('apiTodayCashierWithdrawals serves reason_code_label', str_contains($adjSrc, "reason_code_label"));

$commissarySrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
$h->test('commissary template renders remarks_label', substr_count($commissarySrc, 'row.remarks_label') === 2);
$h->test('commissary template renders dr_number_label', substr_count($commissarySrc, 'row.dr_number_label') === 2);
$h->test(
    'commissary template no longer renders raw remarks/dr_number',
    !str_contains($commissarySrc, '{row.remarks |') && !str_contains($commissarySrc, '{row.dr_number |')
);
$h->test(
    'cashier ledger prefers the served reason label',
    str_contains((string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl'), 'r.reason_code_label ||')
);

// ═══════════════════════════════════════════════════════════════════════
$h->section('4. Real commissary page: zero raw markers, human labels visible');

$markerRows = (int)$db->query(
    "SELECT COUNT(*) FROM dl_deliveries WHERE delivery_date = '2026-10-11' AND status = 'posted' AND remarks LIKE '[%'"
)->fetchColumn();
$sheet = $runHarness(['sheet', '2026-10-11', '18']);
$h->test('commissary page renders', ($sheet['ok'] ?? false) === true && (int)($sheet['html_len'] ?? 0) > 50000, 'len=' . (int)($sheet['html_len'] ?? 0));
$h->test(
    'no raw machine marker appears anywhere on the commissary page',
    (int)($sheet['marker_count'] ?? -1) === 0,
    json_encode(array_slice((array)($sheet['markers'] ?? []), 0, 3))
);
if ($markerRows > 0) {
    $h->test(
        'human labels are what the marker rows became',
        (int)($sheet['labels']['Daily sheet entry'] ?? 0) > 0,
        'marker_rows=' . $markerRows . ' labels=' . json_encode($sheet['labels'] ?? [])
    );
} else {
    $h->skip('human labels visible for marker rows', 'no marker-bearing deliveries on 2026-10-11 in this dataset');
}

// ═══════════════════════════════════════════════════════════════════════
$h->section('5. Cashier adjustments endpoint serves labels');

$adjRows = (int)$db->query(
    "SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = 9 AND ledger_date = '2026-10-12'"
)->fetchColumn();
$adj = $runHarness(['adj', '9', '2026-10-12', 'AM']);
$h->test('adjustments endpoint answers ok', ($adj['ok'] ?? false) === true);
if ($adjRows > 0) {
    $ws = (array)($adj['withdrawals'] ?? []);
    $h->test('adjustment rows served', count($ws) > 0, 'db_rows=' . $adjRows);
    $bad = [];
    foreach ($ws as $r) {
        $code = trim((string)($r['reason_code'] ?? ''));
        $label = trim((string)($r['reason_code_label'] ?? ''));
        if ($code !== '' && ($label === '' || $label === $code)) {
            $bad[] = $code . '=>' . $label;
        }
    }
    $h->test('every served code carries a distinct human label', $bad === [], implode(',', $bad));
} else {
    $h->skip('adjustment rows served', 'no withdrawal rows for branch 9 on 2026-10-12 in this dataset');
}

$h->done();
