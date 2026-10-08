<?php

declare(strict_types=1);

/**
 * Slice 8 ORACLE — commercial leaves the stock sheet.
 *
 * Every case asserts an OUTCOME, not a mechanism. Discriminating cases prove the
 * new contract; pins defend a property that must hold after the slice. Each pin
 * was validated in BOTH directions before being trusted:
 *
 *   E (sheet markup money lens)   re-adding FOR COLLECTION to commissary.disyl reddens E alone.
 *   B (query money field)         re-adding SUM(addtl) AS sold_qty to the sheet query reddens B alone.
 *   C/D (report valuation)        the report derives quantity/price from dl_consignee_ledger.
 *   D (consignment NULL total)    returning 0.0 instead of null reddens D alone.
 *   F (toggle never hides)        returning [] when consignee_enabled=0 reddens F alone.
 *   G (branch untouched)          writing to dl_daily_ledger in the report reddens G alone.
 *
 * No fixture-driving HTTP handler is called here; the pure row builders are used
 * directly, so nothing can reach $ctx->json() and silently exit the process.
 *
 * Fixtures are cleaned up in `finally` and the cleanup is proven by an explicit
 * row count. Settings touched by the oracle are restored, also in `finally`.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-consignee-commercial-slice8', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/consignee-dispatch-report.disyl');
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt', 'capability.call', 'disyl.compile.phases');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$modeKey = 'consignee_sales_mode';
$originalMode = (string)(dlModuleSettings(true)[$modeKey] ?? 'consignment');
$originalEnabled = dl_isConsigneeEnabled();

$commissary = 99671;
$consignee = 99671;
$product = 99671;
$date = '2096-06-01';
$cacheDir = sys_get_temp_dir() . '/dl-consignee-slice8-' . getmypid();

$snapshot = static function () use ($db): string {
    $ledger = $db->query('SELECT * FROM dl_consignee_ledger ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $effects = $db->query('SELECT * FROM dl_consignee_ledger_effects ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return hash('sha256', serialize([$ledger, $effects]));
};
$cleanup = static function () use ($db, $commissary, $consignee, $product): void {
    $db->prepare('DELETE FROM dl_consignee_ledger WHERE consignee_id = ? OR product_id = ?')->execute([$consignee, $product]);
    $db->prepare('DELETE FROM dl_consignee_products WHERE consignee_id = ? OR product_id = ?')->execute([$consignee, $product]);
    $db->prepare('DELETE FROM dl_consignees WHERE id = ?')->execute([$consignee]);
    $db->prepare('DELETE FROM dl_products WHERE id = ?')->execute([$product]);
    $db->prepare('DELETE FROM dl_branches WHERE id = ?')->execute([$commissary]);
};

$cleanup();
$beforeOracle = $snapshot();
try {
    $sheetTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $engine = new \Ikabud\Kernel\DiSyL\TemplateEngine($base . '/templates', $cacheDir, false);
    $engine->enableCompiledMode(true);
    $renderReport = static function (array $report) use ($engine, $date): string {
        return html_entity_decode($engine->render('modules/daily-ledger/admin/consignee-dispatch-report.disyl', [
            'report' => $report,
            'date_from' => $date,
            'date_to' => $date,
            'page_title' => 'Consignee Dispatch Report',
            'app_name' => 'Daily Ledger',
            'logo_url' => '',
            'resolved_favicon_url' => '',
            'base_url' => '',
            'dl_token' => '',
            'csrf_token' => '',
            'dl_user_id' => 0,
            'tenant_scope' => '',
            'user_full_name' => 'Oracle User',
            'user_username' => 'oracle',
            'user_role' => 'admin',
            'current_page' => 'consignee_dispatch',
            'feature_pos' => false,
            'feature_formal_delivery' => false,
            'feature_price_groups' => true,
        ]), ENT_QUOTES | ENT_HTML5);
    };

    // A — discriminating: the production sheet markup is quantities only.
    $h->test('A discriminating: production sheet template references no money column (defends the stock sheet from a markup money leak)', !str_contains($sheetTemplate, 'sold_qty') && !str_contains($sheetTemplate, 'for_collection') && !str_contains($sheetTemplate, 'FOR COLLECTION'));

    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, "S8O-COM", "S8O Commissary", 1, 1)')->execute([$commissary]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?, "S8O-CONS", "S8O Consignee", ?, 1)')->execute([$consignee, $commissary]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, "S8O-P", "S8O Product", 999.99, 1)')->execute([$product]);
    $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$consignee, $product]);
    $db->prepare('INSERT INTO dl_consignee_ledger (consignee_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw) VALUES (?, ?, ?, "AM", 12.50, 2, 7, 1)')->execute([$consignee, $product, $date]);

    // B — discriminating: the QUERY exposes no money field in EITHER sales mode.
    dlPersistModuleSettings([$modeKey => 'order']);
    $orderSheetRow = dl_fetchConsigneeSheetRows($db, $date, $commissary, 'AM')[0] ?? [];
    dlPersistModuleSettings([$modeKey => 'consignment']);
    $consignmentSheetRow = dl_fetchConsigneeSheetRows($db, $date, $commissary, 'AM')[0] ?? [];
    $moneyKeys = ['sold_qty', 'for_collection', 'gross_amount'];
    $h->test('B discriminating: the sheet query returns no money field in EITHER sales mode (defends against a mode-independent query leak)', $orderSheetRow !== []
        && $consignmentSheetRow !== []
        && array_intersect($moneyKeys, array_keys($orderSheetRow)) === []
        && array_intersect($moneyKeys, array_keys($consignmentSheetRow)) === []);

    // C — discriminating: the report returns dispatch lines with quantity and a
    // price-snapshot-derived value.
    dlPersistModuleSettings([$modeKey => 'order']);
    $orderReport = dl_fetchConsigneeDispatchReport($db, ['date_from' => $date, 'date_to' => $date]);
    $orderReportRow = $orderReport['rows'][0] ?? [];
    $h->test('C discriminating: the Consignee Dispatch Report derives quantity and value from the recorded price_snapshot (defends traceability, never a live price)', (int)($orderReportRow['quantity'] ?? -1) === 7
        && abs((float)($orderReportRow['unit_price'] ?? -1) - 12.50) < 0.001
        && abs((float)($orderReportRow['dispatch_value'] ?? -1) - 87.50) < 0.001
        && abs((float)($orderReport['total_value'] ?? -1) - 87.50) < 0.001);

    // D — PIN: consignment mode must report the mode and carry a NULL total,
    // never 0 or PHP 0.00, which would read as a real zero debt.
    dlPersistModuleSettings([$modeKey => 'consignment']);
    $consignmentReport = dl_fetchConsigneeDispatchReport($db, ['date_from' => $date, 'date_to' => $date]);
    $consignmentReportHtml = $renderReport($consignmentReport);
    $h->test('D pin: consignment mode reports the mode, says sold pieces are not recorded, and carries a NULL (not zero) money total (defends against a fabricated zero debt)', ($consignmentReport['sales_mode'] ?? null) === 'consignment'
        && array_key_exists('total_value', $consignmentReport)
        && $consignmentReport['total_value'] === null
        && str_contains($consignmentReportHtml, 'sold pieces are not recorded yet')
        && !str_contains($consignmentReportHtml, 'PHP 0.00'));

    // E — PIN: the feature toggle withholds NEW work but never hides recorded
    // history. Both the custody sheet and the commercial report stay readable
    // and byte-identical while consignee_enabled is off.
    $enabledSheet = dl_fetchConsigneeSheetRows($db, $date, $commissary, 'AM');
    $enabledReport = dl_fetchConsigneeDispatchReport($db, ['date_from' => $date, 'date_to' => $date]);
    dlPersistModuleSettings(['consignee_enabled' => false]);
    $disabledSheet = dl_fetchConsigneeSheetRows($db, $date, $commissary, 'AM');
    $disabledReport = dl_fetchConsigneeDispatchReport($db, ['date_from' => $date, 'date_to' => $date]);
    dlPersistModuleSettings(['consignee_enabled' => $originalEnabled]);
    $h->test('E pin: disabling the feature leaves recorded consignee history readable and unchanged in BOTH the sheet and the report (defends history against a settings change)', count($enabledSheet) > 0
        && $disabledSheet == $enabledSheet
        && $disabledReport['rows'] == $enabledReport['rows']
        && ($disabledReport['consignee_enabled'] ?? true) === false);

    // F — PIN: this commercial slice must not touch the branch-side production
    // ledger. Probe the measured dispatch movement (branch 8 / product 53 /
    // 2026-10-07) before and after the commercial queries.
    $branchProbe = "branch_id = 8 AND product_id = 53 AND ledger_date = '2026-10-07'";
    $branchBefore = $db->query("SELECT * FROM dl_daily_ledger WHERE {$branchProbe} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    dl_fetchConsigneeSheetRows($db, '2026-10-07', 18, null);
    dl_fetchConsigneeDispatchReport($db, ['date_from' => '2026-10-07', 'date_to' => '2026-10-07']);
    $branchAfter = $db->query("SELECT * FROM dl_daily_ledger WHERE {$branchProbe} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $h->test('F pin: the measured branch-side production row is byte-identical after the commercial queries (defends the branch sheet from collateral change)', count($branchBefore) > 0 && $branchBefore === $branchAfter);
} finally {
    $cleanup();
    foreach (glob($cacheDir . '/*') ?: [] as $cacheFile) {
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
    @rmdir($cacheDir);
    dlPersistModuleSettings([$modeKey => $originalMode, 'consignee_enabled' => $originalEnabled]);
}

// G — PIN: every fixture row is gone and the tenant settings are restored.
$remaining = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id = {$commissary}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE id = {$consignee}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id = {$product}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_consignee_products WHERE consignee_id = {$consignee} OR product_id = {$product}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger WHERE consignee_id = {$consignee} OR product_id = {$product}")->fetchColumn();
$restoredMode = (string)(dlModuleSettings(true)[$modeKey] ?? '');
$h->test('G pin: every fixture row is deleted and the sales mode and feature toggle are restored (defends tenant state and proves cleanup)', $remaining === 0
    && $restoredMode === $originalMode
    && dl_isConsigneeEnabled() === $originalEnabled
    && $snapshot() === $beforeOracle);

$h->done();
