<?php

declare(strict_types=1);

/**
 * Daily Ledger — a POS branch's deposit is checked against POS SALES, per shift
 *
 * Owner directive 2026-11-16: "review POS once more and have this tightly integrated with the daily
 * ledger system."
 *
 * The defect this proves out: POS never writes ledger counts, so `ledger_sales` derived from
 * dl_daily_ledger is 0 on a POS day. The branch's cash deposit was therefore compared against 0 and
 * every remittance read "Over by <the whole deposit>" — on the sheet and on Cash & Paper Check.
 *
 *   AC1  manual day          -> the ledger-derived figure, unchanged (source 'ledger').
 *   AC2  POS day             -> the deposit is compared against ITS OWN shift's POS net sales,
 *                               because dl_pos_sales.shift exists (source 'pos').
 *   AC3  POS day with a shift-less sale -> per-shift comparison is not honest: the whole-day total is
 *                               compared once, on the day-ending shift, and every other shift reports
 *                               NOT COMPARABLE ("—") instead of a fabricated variance.
 *   AC4  fallback day        -> day-level official total (POS before checkpoint + manual after), on
 *                               the day-ending shift only.
 *   AC5  POS disabled        -> the ledger basis, even if a POS mode row exists: a switched-off
 *                               feature must not change what the office reads.
 *
 * Fixtures are synthetic (branch 99893, 2035-08-01) and removed in finally; the tenant's
 * pos_enabled setting is captured and restored.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-pos-deposit-basis', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-pos.php');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$tenantId = 207;
$branchId = 99893;
$productId = 99893;
$date = '2035-08-01';

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

$setPos = static function (bool $on) use ($tenantId, $refreshSettings): array {
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => $on ? '1' : '0']);
    return $refreshSettings();
};

$cleanup = static function () use ($db, $branchId, $productId, $date): void {
    foreach (['dl_pos_sales', 'dl_shift_reconciliation', 'dl_sales_day_modes', 'dl_daily_ledger', 'dl_ledger_day_status', 'dl_ledger_shift_status'] as $t) {
        $db->prepare("DELETE FROM {$t} WHERE branch_id = :b AND ledger_date = :d")->execute([':b' => $branchId, ':d' => $date]);
    }
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_branches WHERE id = :b')->execute([':b' => $branchId]);
    $db->prepare('DELETE FROM dl_products WHERE id = :p')->execute([':p' => $productId]);
};

$addSale = static function (string $uuid, ?string $shift, int $cents, string $kind = 'sale') use ($db, $branchId, $date): void {
    $db->prepare(
        "INSERT INTO dl_pos_sales
            (sale_uuid, client_operation_key, branch_id, ledger_date, cashier_id, shift, receipt_no,
             status, sale_kind, total_cents, completed_at)
         VALUES (:u, :k, :b, :d, 1, :s, :r, 'completed', :kind, :c, NOW())"
    )->execute([
        ':u' => $uuid, ':k' => $uuid, ':b' => $branchId, ':d' => $date,
        ':s' => $shift, ':r' => 'R-' . substr($uuid, -12), ':kind' => $kind, ':c' => $cents,
    ]);
};

$setDeposit = static function (string $shift, float $cash) use ($db, $branchId, $date): void {
    $db->prepare(
        'INSERT INTO dl_shift_reconciliation (branch_id, ledger_date, shift, cash_remitted)
         VALUES (:b, :d, :s, :c)
         ON DUPLICATE KEY UPDATE cash_remitted = VALUES(cash_remitted)'
    )->execute([':b' => $branchId, ':d' => $date, ':s' => $shift, ':c' => $cash]);
};

$setMode = static function (string $mode) use ($db, $branchId, $date): void {
    $db->prepare('DELETE FROM dl_sales_day_modes WHERE branch_id = :b AND ledger_date = :d')
        ->execute([':b' => $branchId, ':d' => $date]);
    $db->prepare("INSERT INTO dl_sales_day_modes (branch_id, ledger_date, mode, status) VALUES (:b, :d, :m, 'active')")
        ->execute([':b' => $branchId, ':d' => $date, ':m' => $mode]);
};

$cleanup();

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
                  VALUES (?,?,?,?, "self_managed", 0, 1)')
        ->execute([$branchId, 'POS-DEP', 'POS Deposit Basis Branch', 'Test']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?,?,?,10,0,1)')
        ->execute([$productId, 'POS-DEP-P', 'POS Deposit Basis Product']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?,?,1)')
        ->execute([$branchId, $productId]);

    // ─── AC5 bootstrap: POS OFF, a manual ledger row is the basis ──────────
    $h->section('AC1 — a manual day still compares against the ledger');

    $setPos(false);
    $h->test('POS is off for the baseline', dl_isPosEnabled() === false);

    $db->prepare(
        "INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, created_at, updated_at)
         VALUES (:b, :p, :d, 'AM', 10, 0, 100, 40, 30, NOW(), NOW())"
    )->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);

    $basis = dl_depositComparisonSales($db, $branchId, $date, 'AM', 300.0);
    $h->test('the manual basis is the ledger figure', abs($basis['sales'] - 300.0) < 0.01, (string)$basis['sales']);
    $h->test('the manual basis names itself "Ledger sales"', $basis['label'] === 'Ledger sales', (string)$basis['label']);
    $h->test('the manual basis is comparable', $basis['comparable'] === true);
    $h->test('the manual source is ledger', $basis['source'] === 'ledger');

    // ─── AC5: POS switched OFF ignores a stale POS mode row ───────────────
    $setMode('pos');
    $offBasis = dl_depositComparisonSales($db, $branchId, $date, 'AM', 300.0);
    $h->test('with POS disabled a stale mode row cannot change the basis', $offBasis['source'] === 'ledger' && abs($offBasis['sales'] - 300.0) < 0.01, json_encode($offBasis));

    // ─── AC2: POS day, shift-attributed sales ─────────────────────────────
    $h->section('AC2 — a POS day compares each deposit against that shift POS sales');

    $setPos(true);
    $h->test('POS is on for the POS proofs', dl_isPosEnabled() === true);
    $setMode('pos');
    $addSale('aaaaaaaa-0000-4000-8000-000000000001', 'AM', 40000);
    $addSale('aaaaaaaa-0000-4000-8000-000000000002', 'PM', 60000);
    $addSale('aaaaaaaa-0000-4000-8000-000000000003', 'PM', 5000, 'refund');

    $amBasis = dl_depositComparisonSales($db, $branchId, $date, 'AM', 300.0);
    $pmBasis = dl_depositComparisonSales($db, $branchId, $date, 'PM', 300.0);
    $h->test('the AM basis is the AM POS sales (400.00), not the ledger row (300.00)', abs($amBasis['sales'] - 400.0) < 0.01, (string)$amBasis['sales']);
    $h->test('the PM basis nets the refund (600 - 50 = 550.00)', abs($pmBasis['sales'] - 550.0) < 0.01, (string)$pmBasis['sales']);
    $h->test('a POS basis names itself "POS sales"', $amBasis['label'] === 'POS sales', (string)$amBasis['label']);
    $h->test('both POS shifts are comparable', $amBasis['comparable'] === true && $pmBasis['comparable'] === true);

    $amContext = dl_shiftDepositContext($db, $branchId, $date, 'AM');
    $h->test('the deposit panel is compared against POS sales', abs((float)$amContext['ledger_sales'] - 400.0) < 0.01, (string)$amContext['ledger_sales']);
    $h->test('the panel carries the POS basis name', $amContext['sales_label'] === 'POS sales', (string)$amContext['sales_label']);
    $h->test('the panel exposes the basis source', $amContext['sales_source'] === 'pos');

    $setDeposit('AM', 380.00);
    $short = dl_shiftDepositContext($db, $branchId, $date, 'AM');
    $h->test('a deposit below POS sales is SHORT, not "over by everything"', $short['variance_label'] === 'Short by 20.00', (string)$short['variance_label']);

    $setDeposit('PM', 550.00);
    $balanced = dl_shiftDepositContext($db, $branchId, $date, 'PM');
    $h->test('a deposit equal to POS sales is BALANCED', $balanced['variance_label'] === 'Balanced', (string)$balanced['variance_label']);
    $h->test('the balanced shift reports a zero variance, not null', abs((float)$balanced['cash_variance']) < 0.01, var_export($balanced['cash_variance'], true));

    // ─── AC3: a shift-less sale makes per-shift comparison dishonest ──────
    $h->section('AC3 — a shift-less POS sale moves the comparison to the day-ending shift');

    $addSale('aaaaaaaa-0000-4000-8000-000000000004', null, 5000);
    $h->test('the day-ending shift is PM for this branch', dl_dayEndingShift($branchId) === 'PM');

    $amPartial = dl_depositComparisonSales($db, $branchId, $date, 'AM', 300.0);
    $pmPartial = dl_depositComparisonSales($db, $branchId, $date, 'PM', 300.0);
    $h->test('the AM shift is NOT comparable once a sale has no shift', $amPartial['comparable'] === false);
    $h->test('the non-comparable shift reports no sales figure', abs($amPartial['sales']) < 0.001);
    $h->test('the non-comparable shift names the whole-day basis', $amPartial['label'] === 'POS sales (whole day)', (string)$amPartial['label']);
    $h->test('the day-ending shift compares the whole day (400 + 550 + 50 = 1000.00)', $pmPartial['comparable'] === true && abs($pmPartial['sales'] - 1000.0) < 0.01, (string)$pmPartial['sales']);

    $amPartialContext = dl_shiftDepositContext($db, $branchId, $date, 'AM');
    $h->test('the sheet shows no sales figure for the non-comparable shift', $amPartialContext['ledger_sales'] === null);
    $h->test('the sheet shows "—" instead of a fabricated variance', $amPartialContext['variance_label'] === '—', (string)$amPartialContext['variance_label']);
    $h->test('the sheet variance figure is em dash', $amPartialContext['cash_variance_display'] === '—', (string)$amPartialContext['cash_variance_display']);
    $h->test('the note explains why short/over is on the day-ending shift', str_contains((string)$amPartialContext['note'], 'day-ending shift'), (string)$amPartialContext['note']);

    $pmContext = dl_shiftDepositContext($db, $branchId, $date, 'PM');
    $h->test('the day-ending deposit is compared against the whole day', abs((float)$pmContext['ledger_sales'] - 1000.0) < 0.01, (string)$pmContext['ledger_sales']);

    // ─── AC4: fallback reconciles the day as one cash figure ──────────────
    $h->section('AC4 — a fallback day compares the day official total');

    // Remove the shift-less sale so the fallback proof is unambiguous.
    $db->prepare("DELETE FROM dl_pos_sales WHERE sale_uuid = 'aaaaaaaa-0000-4000-8000-000000000004'")->execute();
    $setMode('fallback');
    $db->prepare('UPDATE dl_sales_day_modes SET fallback_at = NOW() WHERE branch_id = :b AND ledger_date = :d')
        ->execute([':b' => $branchId, ':d' => $date]);

    $fbAm = dl_depositComparisonSales($db, $branchId, $date, 'AM', 300.0);
    $fbPm = dl_depositComparisonSales($db, $branchId, $date, 'PM', 300.0);
    $h->test('the fallback AM shift is not comparable', $fbAm['comparable'] === false);
    $h->test('the fallback day-ending shift is comparable', $fbPm['comparable'] === true);
    $h->test('the fallback basis is named POS + manual sales', $fbPm['label'] === 'POS + manual sales', (string)$fbPm['label']);
    $h->test('the fallback basis is the day official total (950.00 after the checkpoint)', abs($fbPm['sales'] - 950.0) < 0.01, (string)$fbPm['sales']);

    // ─── The office page reads the same function ──────────────────────────
    $h->section('One computation, two surfaces');

    $handlers = php_strip_whitespace($base . '/modules/daily-ledger/handlers.php');
    $h->test('the deposit panel uses the shared basis', substr_count($handlers, 'dl_depositComparisonSales(') >= 3);
    $h->test('the office list calls the same basis', str_contains($handlers, 'dl_depositComparisonSales($ctx->db(), $branchId, $ledgerDate, $shiftName, $ledgerSales, $basisCache)'));
    $h->test('the office list owns its basis cache (one lookup per branch-day)', str_contains($handlers, '$basisCache = [];'));
    $h->test('the basis helper is defined once', substr_count($handlers, 'function dl_depositComparisonSales(') === 1);
    $h->test('POS shift bucketing is defined once', substr_count($handlers, 'function dl_pos_salesByShift(') === 1);
    // The cache must be the CALLER's: a function static would serve a stale basis to a caller that
    // changes a day's mode or sales inside the same request.
    $h->test('the basis cache is a caller-owned parameter, not a function static', !str_contains(
        (string)substr($handlers, (int)strpos($handlers, 'function dl_depositComparisonSales('), 1600),
        'static $'
    ));

    // ─── The rendered expressions, not just the PHP ───────────────────────
    // A new row key that the template cannot evaluate is exactly how a DiSyL page silently
    // demotes to the interpreted pipeline, so the expressions are rendered here for real.
    $h->section('The sheet and the office list render the new basis keys');

    $engine = new \Ikabud\Kernel\DiSyL\TemplateEngine(sys_get_temp_dir(), sys_get_temp_dir() . '/disyl_pos_basis_cache', false);
    $officeRow = [
        'sales_comparable' => true, 'ledger_sales_display' => '1,000.00', 'sales_label' => 'POS sales',
        'has_pending_ending' => false, 'is_provisional' => false,
    ];
    $blockedRow = [
        'sales_comparable' => false, 'ledger_sales_display' => '—', 'sales_label' => 'POS sales (whole day)',
        'has_pending_ending' => false, 'is_provisional' => false,
    ];
    $snippet = '{if row.sales_comparable}PHP {row.ledger_sales_display}{else}&mdash;{/if}<i>{row.sales_label}</i>';
    $rendered = $engine->renderString($snippet, ['row' => $officeRow]);
    $h->test('a comparable row prints its figure and its label', str_contains($rendered, 'PHP 1,000.00') && str_contains($rendered, 'POS sales'), $rendered);
    $blockedOut = $engine->renderString($snippet, ['row' => $blockedRow]);
    $h->test('a non-comparable row prints a dash, never a zero', str_contains($blockedOut, '&mdash;') && !str_contains($blockedOut, '0.00'), $blockedOut);

    $tplSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/reconciliation.disyl');
    $h->test('the office page prints the basis display, not a raw float', str_contains($tplSrc, 'row.ledger_sales_display') && !str_contains($tplSrc, 'row.ledger_sales | number_format'));
    $h->test('the office page prints the basis name per row', str_contains($tplSrc, '{row.sales_label}'));
} finally {
    $cleanup();
    saveTenantModuleSettingsForTenant('daily-ledger', $tenantId, ['pos_enabled' => (string)($originalSettings['pos_enabled'] ?? '0')]);
    $refreshSettings();
}

$h->done();
