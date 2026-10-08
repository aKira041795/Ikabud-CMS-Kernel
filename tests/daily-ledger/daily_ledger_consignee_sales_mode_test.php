<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-consignee-sales-mode', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/admin/settings.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('templates/modules/daily-ledger/admin/consignee-dispatch-report.disyl');
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt', 'capability.call', 'disyl.compile.phases');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$key = 'consignee_sales_mode';
$originalMode = (string)(dlModuleSettings(true)[$key] ?? 'consignment');
$originalEnabled = dl_isConsigneeEnabled();
$commissary = 99651;
$consignee = 99651;
$product = 99651;
$date = '2096-05-01';
$cacheDir = sys_get_temp_dir() . '/dl-consignee-sales-mode-' . getmypid();

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
$beforeToggle = $snapshot();
try {
    $defaults = dlSettingsDefaults();
    $h->test('A discriminating: consignee sales mode exists and defaults to consignment (prevents accidental debtor treatment)', array_key_exists($key, $defaults) && $defaults[$key] === 'consignment');

    $savedOrder = dlPersistModuleSettings([$key => 'order']);
    $h->test('B discriminating: order round-trips through verified module persistence (defends the settings control)', $savedOrder && (dlModuleSettings(true)[$key] ?? null) === 'order');

    $savedGarbage = dlPersistModuleSettings([$key => 'not-a-real-mode']);
    $h->test('C discriminating: garbage coerces to consignment and never order (defends conservative truth)', $savedGarbage && (dlModuleSettings(true)[$key] ?? null) === 'consignment');

    $settingsTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/settings.disyl');
    $h->test('D discriminating: settings UI offers both behavioural choices and posts the selected mode (defends visible round-trip)', str_contains($settingsTemplate, 'value="consignment"') && str_contains($settingsTemplate, 'value="order"') && str_contains($settingsTemplate, 'only reported sold pieces count') && str_contains($settingsTemplate, 'full quantity is sold') && str_contains($settingsTemplate, 'consignee_sales_mode:'));

    $sheetTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $engine = new \Ikabud\Kernel\DiSyL\TemplateEngine($base . '/templates', $cacheDir, false);
    $engine->enableCompiledMode(true);

    // Render the report with the same layout-level context dlRender() supplies
    // in production, so a missing layout variable cannot pollute app.log and
    // mask a real failure.
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

    // E REPOINTED (was: "rendered consignment sheet names the mode, says sold
    // pieces are unrecorded, and presents no money"). The stock sheet is now a
    // quantity document in BOTH modes: the money lens must be absent from the
    // SOURCE and from both rendered modes, not merely hidden by a mode branch
    // (which is exactly what left it one template edit away).
    $sampleRow = ['consignee_name' => 'Render Consignee', 'consignee_code' => 'R-C', 'product_name' => 'Render Product', 'sku' => 'R-P', 'beg_bal' => 2, 'addtl' => 7, 'withdraw_qty' => 1, 'ending_qty' => 8];
    $consignmentSheetHtml = html_entity_decode($engine->render('modules/daily-ledger/admin/commissary.disyl', ['consignee_sales_mode' => 'consignment', 'consignee_sheet_rows' => [$sampleRow], 'consignee_assignment_count' => 1]), ENT_QUOTES | ENT_HTML5);
    $orderSheetHtml = html_entity_decode($engine->render('modules/daily-ledger/admin/commissary.disyl', ['consignee_sales_mode' => 'order', 'consignee_sheet_rows' => [$sampleRow], 'consignee_assignment_count' => 1]), ENT_QUOTES | ENT_HTML5);
    $h->test('E discriminating: production sheet source and BOTH rendered modes carry no SOLD/FOR COLLECTION money lens (defends the stock sheet from a query-level money leak)', !str_contains($sheetTemplate, 'sold_qty') && !str_contains($sheetTemplate, 'for_collection') && !str_contains($sheetTemplate, 'FOR COLLECTION') && !str_contains($consignmentSheetHtml, 'FOR COLLECTION') && !str_contains($orderSheetHtml, 'FOR COLLECTION') && !str_contains($consignmentSheetHtml, 'PHP 87.50') && !str_contains($orderSheetHtml, 'PHP 87.50'));

    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, "S5-COM", "S5 Commissary", 1, 1)')->execute([$commissary]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?, "S5-CONS", "S5 Consignee", ?, 1)')->execute([$consignee, $commissary]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, "S5-P", "S5 Product", 999.99, 1)')->execute([$product]);
    $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$consignee, $product]);
    $db->prepare('INSERT INTO dl_consignee_ledger (consignee_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw) VALUES (?, ?, ?, "AM", 12.50, 2, 7, 1)')->execute([$consignee, $product, $date]);
    $rows = dl_fetchConsigneeSheetRows($db, $date, $commissary, 'AM');
    $row = $rows[0] ?? [];

    // F REPOINTED (was: the ORDER sheet renders FOR COLLECTION / PHP 87.50 and
    // the returned row carries sold_qty / for_collection). The same measured
    // figure must now appear on the dispatch report, derived from the recorded
    // price_snapshot, while the sheet row exposes no money key.
    dlPersistModuleSettings([$key => 'order']);
    $orderReport = dl_fetchConsigneeDispatchReport($db, ['date_from' => $date, 'date_to' => $date]);
    $orderReportRow = $orderReport['rows'][0] ?? [];
    $orderReportHtml = $renderReport($orderReport);
    $h->test('F discriminating: the sheet row exposes no money key and the dispatch report shows the price-snapshot value (defends traceability, never live price)', count($rows) === 1
        && !array_key_exists('sold_qty', $row)
        && !array_key_exists('for_collection', $row)
        && (int)($orderReportRow['quantity'] ?? -1) === 7
        && abs((float)($orderReportRow['unit_price'] ?? -1) - 12.50) < 0.001
        && abs((float)($orderReportRow['dispatch_value'] ?? -1) - 87.50) < 0.001
        && abs((float)($orderReport['total_value'] ?? -1) - 87.50) < 0.001
        && str_contains($orderReportHtml, 'PHP 87.50'));

    // F2 pin: consignment mode is the honesty pin. The report must name the
    // mode, say sold pieces are not recorded, and carry a NULL total - never
    // 0 or PHP 0.00, which would read as a real zero debt.
    dlPersistModuleSettings([$key => 'consignment']);
    $consignmentReport = dl_fetchConsigneeDispatchReport($db, ['date_from' => $date, 'date_to' => $date]);
    $consignmentReportHtml = $renderReport($consignmentReport);
    $h->test('F2 pin: consignment mode reports the mode, states sold pieces are not recorded, and carries a NULL (not zero) money total (defends against a fabricated zero debt)', ($consignmentReport['sales_mode'] ?? null) === 'consignment'
        && array_key_exists('total_value', $consignmentReport)
        && $consignmentReport['total_value'] === null
        && str_contains($consignmentReportHtml, 'sold pieces are not recorded yet')
        && str_contains($consignmentReportHtml, 'Not reported')
        && !str_contains($consignmentReportHtml, 'PHP 0.00'));

    dlPersistModuleSettings([$key => 'order']);
    $orderSnapshot = $snapshot();
    dlPersistModuleSettings([$key => 'consignment']);
    $consignmentSnapshot = $snapshot();
    $h->test('G pin: consignee ledger and effects are byte-identical in both modes (defends lens-only behaviour and the single posting path)', $orderSnapshot === $consignmentSnapshot);

    $h->test('H pin: branch production sheet remains present and unchanged beside consignee treatment (defends the branch sheet)', str_contains($sheetTemplate, 'id="production-ledger-table"') && str_contains($sheetTemplate, 'id="consignee-production-ledger-table"'));

    // J pin: the feature toggle withholds NEW work, never recorded history.
    // Both the custody sheet and the commercial report must stay readable and
    // identical while consignee_enabled is off.
    $enabledSheet = dl_fetchConsigneeSheetRows($db, $date, $commissary, 'AM');
    dlPersistModuleSettings(['consignee_enabled' => false]);
    $disabledSheet = dl_fetchConsigneeSheetRows($db, $date, $commissary, 'AM');
    $disabledReport = dl_fetchConsigneeDispatchReport($db, ['date_from' => $date, 'date_to' => $date]);
    dlPersistModuleSettings(['consignee_enabled' => $originalEnabled]);
    $h->test('J pin: disabling the feature leaves recorded consignee history readable and unchanged in BOTH the sheet and the report (defends history against a settings change)', count($enabledSheet) > 0
        && $disabledSheet == $enabledSheet
        && count($disabledReport['rows'] ?? []) === count($enabledSheet)
        && ($disabledReport['consignee_enabled'] ?? true) === false);

    // K pin: this commercial slice must not touch the branch-side production
    // ledger. Probe the measured dispatch movement (branch 8 / product 53 /
    // 2026-10-07) before and after the commercial queries.
    $branchProbe = "branch_id = 8 AND product_id = 53 AND ledger_date = '2026-10-07'";
    $branchBefore = $db->query("SELECT * FROM dl_daily_ledger WHERE {$branchProbe} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    dl_fetchConsigneeSheetRows($db, '2026-10-07', 18, null);
    dl_fetchConsigneeDispatchReport($db, ['date_from' => '2026-10-07', 'date_to' => '2026-10-07']);
    $branchAfter = $db->query("SELECT * FROM dl_daily_ledger WHERE {$branchProbe} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $h->test('K pin: the measured branch-side production row is byte-identical after the commercial queries (defends the branch sheet from collateral change)', count($branchBefore) > 0 && $branchBefore === $branchAfter);
} finally {
    $cleanup();
    foreach (glob($cacheDir . '/*') ?: [] as $cacheFile) {
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
    @rmdir($cacheDir);
    dlPersistModuleSettings([$key => $originalMode, 'consignee_enabled' => $originalEnabled]);
}

$restoredMode = (string)(dlModuleSettings(true)[$key] ?? '');
$restoredEnabled = dl_isConsigneeEnabled();
$remaining = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id = {$commissary}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE id = {$consignee}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id = {$product}")->fetchColumn();
$h->test('I pin: live-tenant setting and fixture rows are restored (defends tenant state after the oracle)', $restoredMode === $originalMode && $restoredEnabled === $originalEnabled && $remaining === 0 && $snapshot() === $beforeToggle);
$h->done();
