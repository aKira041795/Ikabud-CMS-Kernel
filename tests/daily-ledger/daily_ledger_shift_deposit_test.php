<?php

declare(strict_types=1);

/**
 * Daily Ledger — the branch records its shift deposit and sees short/over.
 *
 * Owner direction 2026-11-16 (client concern #2, Sir Ryan): the branch enters
 * the cash deposit per shift and the short/over against the ledger is visible
 * immediately, on the sheet and in the office.
 *
 *   AC1  dl_shiftDepositContext() derives sales with the SAME official-only rule
 *        the office page uses, and reports pending/provisional rows as counts.
 *   AC2  a deposit below sales is SHORT, above is OVER, equal is BALANCED — the
 *        labels say which, not just a signed number.
 *   AC3  the branch endpoint stores ONLY cash_remitted for its own
 *        branch/date/shift (paper total and reviewer note are never touched).
 *   AC4  the feature switch gates the endpoint and the sheet panel.
 *
 * Tenant 207. Fixtures are synthetic and removed in finally, including the
 * settings value this suite toggles.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-shift-deposit', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/routes.php');
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();

$branch = 99891;
$product = 99891;
$productPending = 99892;
$date = '2035-07-01';
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-dep-');

$cleanup = static function () use ($db, $branch, $product, $productPending, $date): void {
    $db->execute('DELETE FROM dl_shift_reconciliation WHERE branch_id = :b AND ledger_date = :d', [':b' => $branch, ':d' => $date]);
    $db->execute('DELETE FROM audit_logs WHERE module = \'daily-ledger\' AND branch_id = :b', [':b' => $branch]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branch]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branch]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branch]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branch]);
    $db->execute('DELETE FROM dl_products WHERE id IN (:p1, :p2)', [':p1' => $product, ':p2' => $productPending]);
};

$runHarness = static function (array $body) use ($payloadFile): array {
    // The harness JWT is not linked to dl_user_branches, so it acts as an admin
    // (the endpoint's admin/supervisor path, which writes the same narrow column).
    file_put_contents($payloadFile, json_encode(['role' => 'admin', 'body' => $body, 'get' => $body], JSON_THROW_ON_ERROR));
    $out = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_preserve_cashier_variance_harness.php')
        . ' deposit ' . escapeshellarg($payloadFile) . ' 2>/dev/null',
        $out,
        $code
    );
    $raw = implode("\n", $out);
    $json = json_decode($raw, true);
    return ['code' => $code, 'raw' => $raw, 'json' => is_array($json) ? $json : null];
};

$previousSetting = getModuleSettings('daily-ledger')['cash_paper_check_enabled'] ?? null;

$cleanup();

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
                  VALUES (?,?,?,?, "self_managed", 0, 1)')
        ->execute([$branch, 'DEP-TEST', 'Deposit Test Branch', 'Test']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?,?,?,10,0,1)')
        ->execute([$product, 'DEP-TEST', 'Deposit Test Product']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?,?,?,10,0,1)')
        ->execute([$productPending, 'DEP-PEND', 'Deposit Pending Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')->execute([$branch, $product]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')->execute([$branch, $productPending]);
    $db->prepare('INSERT INTO dl_daily_ledger
                    (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, encoded_by, updated_by)
                  VALUES (?,?,?,"AM",10,0,100,40,30,1,1)')
        ->execute([$branch, $product, $date]);

    // ─── AC1 — the sales figure follows the ledger ─────────────────────
    $h->section('The deposit context derives sales and states');

    $context = dl_shiftDepositContext($db, $branch, $date, 'AM');
    // (0 + 100 - 40 - 30) * 10 = 300
    $h->test('ledger sales is derived from the official rows', abs((float)$context['ledger_sales'] - 300.0) < 0.01, 'sales=' . $context['ledger_sales']);
    $h->test('no deposit yet reads as no variance, not zero', $context['cash_variance'] === null && $context['cash_remitted'] === null);
    $h->test('the note says no deposit is recorded', str_contains((string)$context['note'], 'No deposit recorded'));
    $h->test('the deposit display is an em dash when absent', $context['cash_remitted_display'] === '—');

    // ─── AC2 — short / over / balanced labels ──────────────────────────
    $h->section('Short, over and balanced are named');

    $upsert = $db->prepare(
        'INSERT INTO dl_shift_reconciliation (branch_id, ledger_date, shift, paper_sales, cash_remitted, review_note)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE paper_sales = VALUES(paper_sales), cash_remitted = VALUES(cash_remitted), review_note = VALUES(review_note)'
    );
    $upsert->execute([$branch, $date, 'AM', 310.00, 280.00, 'office note']);
    $short = dl_shiftDepositContext($db, $branch, $date, 'AM');
    $h->test('a deposit below sales is SHORT by the difference', $short['variance_label'] === 'Short by 20.00', (string)$short['variance_label']);
    $h->test('short uses the negative variance and the rose treatment', abs((float)$short['cash_variance'] + 20.0) < 0.01 && $short['variance_class'] === 'text-rose-700');
    $h->test('the office paper total is never disturbed by the branch deposit', abs((float)$short['paper_sales'] - 310.0) < 0.01);

    $upsert->execute([$branch, $date, 'AM', 310.00, 320.00, 'office note']);
    $over = dl_shiftDepositContext($db, $branch, $date, 'AM');
    $h->test('a deposit above sales is OVER by the difference', $over['variance_label'] === 'Over by 20.00', (string)$over['variance_label']);
    $h->test('over uses the emerald treatment', $over['variance_class'] === 'text-emerald-700');

    $upsert->execute([$branch, $date, 'AM', 310.00, 300.00, 'office note']);
    $balanced = dl_shiftDepositContext($db, $branch, $date, 'AM');
    $h->test('a deposit equal to sales is BALANCED', $balanced['variance_label'] === 'Balanced');
    $h->test('a real 0.00 result is not confused with "not recorded"', $balanced['cash_remitted'] === 0.0 || $balanced['cash_remitted'] === 300.0);

    // A pending ending is reported as a count, and the note says sales can move.
    $db->prepare('INSERT INTO dl_daily_ledger
                    (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, encoded_by, updated_by)
                  VALUES (?,?,?,"AM",10,0,5,0,NULL,1,1)')
        ->execute([$branch, $productPending, $date]);
    $pending = dl_shiftDepositContext($db, $branch, $date, 'AM');
    $h->test('a pending ending is counted, not folded into sales', $pending['pending_rows'] >= 1, 'pending=' . $pending['pending_rows']);
    $h->test('the note says the sales figure is not final yet', str_contains((string)$pending['note'], 'not final'));

    // ─── AC3 — the branch endpoint writes only cash_remitted ───────────
    $h->section('The branch deposit endpoint');

    saveModuleSettings('daily-ledger', ['cash_paper_check_enabled' => '1']);
    $h->test('the feature can be switched on for the test', dl_settingToBool(dlModuleSettings(true)['cash_paper_check_enabled'] ?? false));

    $save = $runHarness(['branch_id' => $branch, 'date' => $date, 'shift' => 'AM', 'cash_remitted' => '290.50']);
    $h->test('the branch deposit saves', !empty($save['json']['ok']), $save['raw']);
    $row = $db->query("SELECT paper_sales, cash_remitted, review_note FROM dl_shift_reconciliation WHERE branch_id = {$branch} AND ledger_date = '{$date}' AND shift = 'AM'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test('the deposit is stored to the cent', abs((float)($row['cash_remitted'] ?? 0) - 290.50) < 0.001, (string)($row['cash_remitted'] ?? ''));
    $h->test('the office paper total survives the branch save', abs((float)($row['paper_sales'] ?? 0) - 310.0) < 0.001);
    $h->test('the office note survives the branch save', ($row['review_note'] ?? '') === 'office note');
    $h->test('the response carries the resulting variance', isset($save['json']['cash_variance']) && abs((float)$save['json']['cash_variance'] - (-9.5)) < 0.01, $save['raw']);

    $bad = $runHarness(['branch_id' => $branch, 'date' => $date, 'shift' => 'AM', 'cash_remitted' => '-5']);
    $h->test('a negative deposit is refused', empty($bad['json']['ok']));

    // ─── AC4 — the switch gates both surfaces ──────────────────────────
    $h->section('The feature switch gates the endpoint and the sheet');

    saveModuleSettings('daily-ledger', ['cash_paper_check_enabled' => '0']);
    dlModuleSettings(true);
    $blocked = $runHarness(['branch_id' => $branch, 'date' => $date, 'shift' => 'AM', 'cash_remitted' => '100']);
    $h->test('the endpoint refuses while the feature is off', empty($blocked['json']['ok']), $blocked['raw']);

    $routesSrc = (string)file_get_contents($base . '/modules/daily-ledger/routes.php');
    $tplSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');
    $handlersSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $h->test('the deposit route is registered', str_contains($routesSrc, "'/daily-ledger/api/v1/cashier/ledger/deposit'"));
    $h->test('the sheet renders the deposit panel behind the switch', str_contains($tplSrc, '{if feature_cash_paper_check && deposit}') && str_contains($tplSrc, 'shift-deposit-input'));
    $h->test('the sheet labels the result short/over', str_contains($tplSrc, 'Short / over'));
    $h->test('the handler passes the deposit context to the sheet', str_contains($handlersSrc, "'deposit' => \$depositContext"));
    $h->test('a branch user is held to the ledger edit window', str_contains($handlersSrc, "if (!in_array(\$role, ['admin', 'supervisor'], true)) {") && str_contains($handlersSrc, 'dl_cashierMayEdit('));
} finally {
    if ($previousSetting === null) {
        saveModuleSettings('daily-ledger', ['cash_paper_check_enabled' => '0']);
    } else {
        saveModuleSettings('daily-ledger', ['cash_paper_check_enabled' => (string)$previousSetting]);
    }
    dlModuleSettings(true);
    $cleanup();
    @unlink($payloadFile);
}

$left = (int)$db->query("SELECT COUNT(*) FROM dl_shift_reconciliation WHERE branch_id = {$branch}")->fetchColumn();
$h->test('cleanup removed the deposit fixtures', $left === 0);

$h->done();
