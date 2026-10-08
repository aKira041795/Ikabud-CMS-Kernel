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
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt', 'capability.call', 'disyl.compile.phases');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$key = 'consignee_sales_mode';
$originalMode = (string)(dlModuleSettings(true)[$key] ?? 'consignment');
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
    $sampleRow = ['consignee_name' => 'Render Consignee', 'consignee_code' => 'R-C', 'product_name' => 'Render Product', 'sku' => 'R-P', 'beg_bal' => 2, 'addtl' => 7, 'withdraw_qty' => 1, 'ending_qty' => 8, 'sold_qty' => 7, 'for_collection' => 87.50];
    $consignmentHtml = html_entity_decode($engine->render('modules/daily-ledger/admin/commissary.disyl', ['consignee_sales_mode' => 'consignment', 'consignee_sheet_rows' => [$sampleRow], 'consignee_assignment_count' => 1]), ENT_QUOTES | ENT_HTML5);
    $h->test('E discriminating: rendered consignment sheet names the mode, says sold pieces are unrecorded, and presents no money (defends against a false zero debt)', str_contains($consignmentHtml, 'Active mode: Consignment') && str_contains($consignmentHtml, 'sold pieces not recorded yet') && !str_contains($consignmentHtml, 'FOR COLLECTION') && !str_contains($consignmentHtml, 'PHP 87.50'));

    $db->prepare('INSERT INTO dl_branches (id, code, name, is_commissary, is_active) VALUES (?, "S5-COM", "S5 Commissary", 1, 1)')->execute([$commissary]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?, "S5-CONS", "S5 Consignee", ?, 1)')->execute([$consignee, $commissary]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, "S5-P", "S5 Product", 999.99, 1)')->execute([$product]);
    $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$consignee, $product]);
    $db->prepare('INSERT INTO dl_consignee_ledger (consignee_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw) VALUES (?, ?, ?, "AM", 12.50, 2, 7, 1)')->execute([$consignee, $product, $date]);
    $rows = dl_fetchConsigneeSheetRows($db, $date, $commissary, 'AM');
    $row = $rows[0] ?? [];
    $orderHtml = html_entity_decode($engine->render('modules/daily-ledger/admin/commissary.disyl', ['consignee_sales_mode' => 'order', 'consignee_sheet_rows' => [$row], 'consignee_assignment_count' => 1]), ENT_QUOTES | ENT_HTML5);
    $h->test('F discriminating: rendered order sheet shows dispatched pieces and collection amount from stored price_snapshot (defends traceability, never live price)', count($rows) === 1 && (int)($row['sold_qty'] ?? -1) === 7 && abs((float)($row['for_collection'] ?? -1) - 87.50) < 0.001 && str_contains($orderHtml, 'Active mode: Order') && str_contains($orderHtml, 'FOR COLLECTION') && str_contains($orderHtml, 'PHP 87.50'));

    dlPersistModuleSettings([$key => 'order']);
    $orderSnapshot = $snapshot();
    dlPersistModuleSettings([$key => 'consignment']);
    $consignmentSnapshot = $snapshot();
    $h->test('G pin: consignee ledger and effects are byte-identical in both modes (defends lens-only behaviour and the single posting path)', $orderSnapshot === $consignmentSnapshot);

    $h->test('H pin: branch production sheet remains present and unchanged beside consignee treatment (defends the branch sheet)', str_contains($sheetTemplate, 'id="production-ledger-table"') && str_contains($sheetTemplate, 'id="consignee-production-ledger-table"'));
} finally {
    $cleanup();
    foreach (glob($cacheDir . '/*') ?: [] as $cacheFile) {
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
    @rmdir($cacheDir);
    dlPersistModuleSettings([$key => $originalMode]);
}

$restoredMode = (string)(dlModuleSettings(true)[$key] ?? '');
$remaining = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id = {$commissary}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE id = {$consignee}")->fetchColumn()
    + (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id = {$product}")->fetchColumn();
$h->test('I pin: live-tenant setting and fixture rows are restored (defends tenant state after the oracle)', $restoredMode === $originalMode && $remaining === 0 && $snapshot() === $beforeToggle);
$h->done();
