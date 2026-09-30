<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-production-sheet', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/entity-views.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}
$db = $ctx->db();
$branchId = 98991;
$retailId = 98992;
$productA = 98991;
$productB = 98992;
$productC = 98993;
$dates = ['2020-01-01', '2020-01-02', '2020-01-03'];

$cleanup = static function () use ($db, $branchId, $retailId, $productA, $productB, $productC): void {
    // Movements reference products and branches with RESTRICT, so they must go first.
    $db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id IN ({$branchId},{$retailId}) OR product_id IN ({$productA},{$productB},{$productC})");
    $db->execute("DELETE FROM audit_logs WHERE branch_id IN ({$branchId},{$retailId})");
    $db->execute("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$branchId},{$retailId})");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$branchId},{$retailId})");
    $db->execute("DELETE FROM dl_production_runs WHERE product_id IN ({$productA},{$productB},{$productC})");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN (SELECT id FROM dl_deliveries WHERE origin_id IN ({$branchId},{$retailId}) OR destination_id IN ({$branchId},{$retailId}))");
    $db->execute("DELETE FROM dl_deliveries WHERE origin_id IN ({$branchId},{$retailId}) OR destination_id IN ({$branchId},{$retailId})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branchId},{$retailId})");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$branchId},{$retailId}) OR product_id IN ({$productA},{$productB},{$productC})");
    $db->execute("DELETE FROM dl_products WHERE id IN ({$productA},{$productB},{$productC})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$branchId},{$retailId})");
};
$cleanup();

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$branchId, 'PS-COMM', 'Production Sheet Commissary', 'self_managed', 1]);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$retailId, 'PS-RETAIL', 'Production Sheet Retail', 'commissary_supplied', 0]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, product_category, sort_order, output_pieces_per_batch, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$productA, 'PS-A', 'Zulu Sheet Product', 'cake', 20, 12]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, product_category, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$productB, 'PS-B', 'Alpha Excluded Product', 'bread', 10]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, product_category, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$productC, 'PS-C', 'Beta Included Product', 'other', 10]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, ?)')
        ->execute([$branchId, $productA, 1]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, ?)')
        ->execute([$branchId, $productB, 0]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, ?)')
        ->execute([$branchId, $productC, 1]);

    $products = dl_fetchProductionSheetProducts($db, $branchId);
    $h->test('sheet set equals cashier branch-product set on differing fixture', array_column($products, 'id') === [$productC, $productA]);
    $h->test('sheet ordering is sort_order then name, independent of category', array_column($products, 'name') === ['Beta Included Product', 'Zulu Sheet Product']);
    $h->test('sheet product source carries the existing yield profile', (int)$products[1]['output_pieces_per_batch'] === 12);

    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, produced_qty, dispatched_qty, wastage_qty) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$branchId, $productA, $dates[0], 100, 20, 5]);
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, produced_qty, dispatched_qty, wastage_qty) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$branchId, $productA, $dates[1], 30, 10, 0]);

    $changedFirst = dl_ensureCommissaryProductLedgerRow($db, $branchId, $productA, $dates[2], 0);
    $changedSecond = dl_ensureCommissaryProductLedgerRow($db, $branchId, $productA, $dates[2], 0);
    $rowStmt = $db->prepare('SELECT * FROM dl_commissary_product_ledger WHERE commissary_branch_id=? AND product_id=? AND ledger_date=?');
    $rowStmt->execute([$branchId, $productA, $dates[2]]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    $h->test('past-date carry row is created', $changedFirst === 1);
    $h->test('BEG is cumulative prior produced-dispatched-wastage', (int)$row['beg_qty'] === 95);
    $h->test('second carry changes zero rows structurally', $changedSecond === 0);

    dl_applyCommissaryProductLedgerDelta($db, $branchId, $productA, $dates[2], 20, 5, 0, 2);
    $db->prepare("INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, delivery_date, status) VALUES ('commissary', ?, 'branch', ?, ?, 'posted')")
        ->execute([$branchId, $retailId, $dates[2]]);
    $deliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity) VALUES (?, ?, ?)')
        ->execute([$deliveryId, $productA, 5]);
    $matrix = dl_fetchProductionSheetDispatchMatrix($db, $dates[2], $branchId);
    $h->test('matrix TOTAL source equals delivery-item quantity for the day', (int)($matrix[$productA][$retailId] ?? 0) === 5);
    $ledgerBook = dl_readCommissaryProductLedgerRow($db, $branchId, $productA, $dates[2]);
    $h->test(
        'the ledger book basis BEG + ADDTL − TOTAL − WASTAGE equals the agreeing count',
        is_array($ledgerBook)
        && (int)$ledgerBook['book_balance'] === 108
        && (int)$ledgerBook['beg_qty'] === 95
        && (int)$ledgerBook['produced_qty'] === 20
        && (int)$ledgerBook['dispatched_qty'] === 5
        && (int)$ledgerBook['wastage_qty'] === 2,
        json_encode($ledgerBook)
    );

    $counted = dl_saveCommissaryActualEndQty($db, $branchId, $productA, $dates[2], 148, 0);
    $h->test('count book plus 40 gives variance plus 40', (int)$counted['calc_variance'] === 40);
    $blank = dl_saveCommissaryActualEndQty($db, $branchId, $productA, $dates[2], null, 0);
    $h->test('blank count keeps NULL variance', $blank['actual_end_qty'] === null && $blank['calc_variance'] === null);
    $equal = dl_saveCommissaryActualEndQty($db, $branchId, $productA, $dates[2], 108, 0);
    $h->test('count equal to suggestion is stored and variance zero', (int)$equal['actual_end_qty'] === 108 && (int)$equal['calc_variance'] === 0);

    // C8: this product has history but no row on the count date (dead stock that day).
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, produced_qty, dispatched_qty, wastage_qty) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$branchId, $productC, $dates[0], 10, 2, 1]);
    $deadStock = dl_saveCommissaryActualEndQty($db, $branchId, $productC, $dates[2], 9, 0);
    $h->test('C8 count without movement creates correctly carried ledger row', (int)$deadStock['beg_qty'] === 7 && (int)$deadStock['actual_end_qty'] === 9);
    $h->test('C8 new dead-stock row returns stored variance', (int)$deadStock['calc_variance'] === 2);

    $user = ['id' => 1, 'sub' => 'admin:1', 'role' => 'admin', 'source' => 'daily-ledger'];
    $dailyBefore = (int)$db->query("SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id IN ({$branchId},{$retailId})")->fetchColumn();
    $deliveriesBefore = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE origin_id IN ({$branchId},{$retailId}) OR destination_id IN ({$branchId},{$retailId})")->fetchColumn();
    // ── S7b: the modal records an additive addition, deduped by submission id. ──
    $add1 = dl_recordProductionAddition($user, [
        'date' => $dates[2],
        'commissary_branch_id' => $branchId,
        'product_id' => $productC,
        'quantity' => 24,
        'reason' => 'test addition one',
        'submission_id' => 's7b-test-add-1',
    ]);
    $h->test('S7b modal addition persists produced_qty', (int)$add1['row']['produced_qty'] === 24);
    $runYield = $db->query("SELECT yield_qty FROM dl_production_runs WHERE ledger_date = '{$dates[2]}' AND product_id = {$productC} AND destination_branch_id = {$branchId} ORDER BY id DESC LIMIT 1")->fetchColumn();
    $h->test('S7b addition retains production-run yield capture', (int)$runYield === 24);
    $add2 = dl_recordProductionAddition($user, [
        'date' => $dates[2],
        'commissary_branch_id' => $branchId,
        'product_id' => $productC,
        'quantity' => 6,
        'reason' => 'test addition two',
        'submission_id' => 's7b-test-add-2',
    ]);
    $h->test('S7b two submissions with different ids create two additions', (int)$add2['row']['produced_qty'] === 30);
    $replay = dl_recordProductionAddition($user, [
        'date' => $dates[2],
        'commissary_branch_id' => $branchId,
        'product_id' => $productC,
        'quantity' => 24,
        'reason' => 'test addition one',
        'submission_id' => 's7b-test-add-1',
    ]);
    $h->test('S7b replayed submission creates one addition', !empty($replay['duplicate']) && (int)$replay['row']['produced_qty'] === 30);
    $h->test('S7b absent submission id never falls back to a content hash',
        dl_withdrawalSubmissionId('') !== dl_withdrawalSubmissionId('') && str_starts_with(dl_withdrawalSubmissionId(''), 'auto-'));
    $h->test('S7b modal addition does not write branch ledger or deliveries',
        (int)$db->query("SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id IN ({$branchId},{$retailId})")->fetchColumn() === $dailyBefore
        && (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE origin_id IN ({$branchId},{$retailId}) OR destination_id IN ({$branchId},{$retailId})")->fetchColumn() === $deliveriesBefore);

    // ── S7b: the bottom log lists changes with before/after/actor/reason. ──
    $log = dl_fetchProductionLedgerLog($db, $branchId, $dates[2]);
    $logEntry = null;
    foreach ($log as $entry) {
        if ((int)$entry['product_id'] === $productC && $entry['field'] === 'ADDTL') {
            $logEntry = $entry;
            break;
        }
    }
    $h->test('S7b bottom log lists the day\'s changes', $logEntry !== null && $logEntry['before_display'] !== '' && $logEntry['after_display'] !== '');
    $h->test('S7b log entry carries actor and reason', $logEntry !== null && $logEntry['who'] !== '' && $logEntry['reason'] !== '');

    // ── S7b: editing a log row applies the change and appends an audit record. ──
    $auditBefore = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'production_ledger_change' AND branch_id = {$branchId}")->fetchColumn();
    $edit = dl_changeProductionLedgerField($user, [
        'field' => 'produced_qty',
        'date' => $dates[2],
        'commissary_branch_id' => $branchId,
        'product_id' => $productC,
        'value' => 11,
        'reason' => 'test log edit',
        'submission_id' => 's7b-test-edit-1',
    ]);
    $h->test('S7b log edit applies the change', (int)$edit['row']['produced_qty'] === 11);
    $auditAfter = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'production_ledger_change' AND branch_id = {$branchId}")->fetchColumn();
    $h->test('S7b log edit appends a new audit record', $auditAfter === $auditBefore + 1);
    $h->test('S7b prior records remain after the edit',
        (int)$db->query("SELECT COUNT(*) FROM dl_production_movements WHERE destination_branch_id = {$branchId}")->fetchColumn() >= 2
        && (int)$db->query("SELECT COUNT(*) FROM dl_production_runs WHERE destination_branch_id = {$branchId}")->fetchColumn() >= 1);
    $reasonRefused = false;
    try {
        dl_changeProductionLedgerField($user, [
            'field' => 'produced_qty', 'date' => $dates[2], 'commissary_branch_id' => $branchId,
            'product_id' => $productC, 'value' => 12, 'reason' => '',
        ]);
    } catch (\RuntimeException $e) {
        $reasonRefused = str_contains($e->getMessage(), 'reason');
    }
    $h->test('S7b log edit requires a reason', $reasonRefused);

    // ── S7b: BEG editable persists. ──
    $begRow = dl_saveCommissaryBeginningQty($db, $branchId, $productC, $dates[2], 50, 0);
    $h->test('S7b BEG persists the user value', (int)$begRow['beg_qty'] === 50);

    // ── S7b forwarding: last count + movement after it (incl. the gap case). ──
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$branchId, $productB, '2020-02-01', 0, 10, 5, 0, 145]);
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$branchId, $productB, '2020-02-02', 0, 20, 3, 2, null]);
    $h->test('S7b forwarding = last count + movement after', dl_suggestCommissaryBeginning($db, $branchId, $productB, '2020-02-03') === 160);
    // productA has movement on 2020-01-01/02 and no count before 2020-01-03.
    $h->test('S7b forwarding fallback: no counts = cumulative movement', dl_suggestCommissaryBeginning($db, $branchId, $productA, $dates[2]) === 95);
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$branchId, $productB, '2020-02-04', 0, 5, 0, 0, 50]);
    $h->test('S7b forwarding across a gap uses the later count', dl_suggestCommissaryBeginning($db, $branchId, $productB, '2020-02-05') === 50);
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$branchId, $productB, '2020-03-01', 0, 0, 0, 0, 5]);
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$branchId, $productB, '2020-03-02', 0, 1, 9, 0, null]);
    $h->test('S7b negative suggestion is surfaced, not clamped', dl_suggestCommissaryBeginning($db, $branchId, $productB, '2020-03-03') === -3);

    $column = $db->query("SHOW COLUMNS FROM dl_commissary_product_ledger LIKE 'calc_variance'")->fetch(PDO::FETCH_ASSOC);
    $h->test('calc_variance is STORED GENERATED', stripos((string)($column['Extra'] ?? ''), 'STORED GENERATED') !== false);

    $template = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/commissary.disyl');
    $handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
    $dailySheetStart = strpos($template, '<!-- Tab 1: Daily Sheet -->');
    $dailySheetEnd = strpos($template, '<!-- Dispatch to Branch -->', $dailySheetStart ?: 0);
    $dailySheetBlock = $dailySheetStart !== false && $dailySheetEnd !== false
        ? substr($template, $dailySheetStart, $dailySheetEnd - $dailySheetStart)
        : '';
    $h->test('count persistence is explicit-save only', str_contains($template, 'onclick="saveProductionCount(this)"') && !preg_match('/on(?:blur|change)="[^"]*saveProductionCount/', $template));
    $h->test('S6 Daily Sheet is first and stale tab storage falls back to it',
        strpos($template, 'id="tab-btn-daily-sheet"') < strpos($template, 'id="tab-btn-inventory"')
        && str_contains($template, "switchCommissaryTab(saved === 'daily-sheet' ? saved : 'daily-sheet')"));
    $h->test('S6 Daily Sheet drops SKU without changing other tabs',
        $dailySheetBlock !== '' && !str_contains($dailySheetBlock, '<th>SKU</th>') && str_contains($template, '<th>SKU</th>'));
    $h->test('S6 branch headers are vertical on screen and print',
        substr_count($template, 'writing-mode: vertical-rl') >= 2
        && substr_count($template, 'transform: rotate(180deg)') >= 2);
    $h->test('S7b batch input, no-profile label and per-row Save are gone',
        substr_count($dailySheetBlock, 'production-batch-input') === 0
        && !str_contains($dailySheetBlock, 'no profile')
        && !str_contains($dailySheetBlock, 'saveProductionLedgerRow'));
    $h->test('S13 Daily Sheet no longer hard-codes a tenant commissary; default is All',
        !str_contains($handlers, 'RIZAL-COMMIS')
        && preg_match('/\$selectedCommissaryId = in_array\(\$requestedCommissaryId, \$availableCommissaryIds, true\)\s*\?\s*\$requestedCommissaryId\s*:\s*0;/', $handlers) === 1);
    $h->test('daily sheet has own production columns', str_contains($template, '>BEG<') && str_contains($template, '>ADDTL<') && str_contains($template, '>ACTUAL BAL<') && str_contains($template, '>VARIANCE<'));
    $h->test('all four unresolved labels are marked without guessed map', dl_unresolvedProductionSheetLabels() === ['BDAY CAKE ORD', 'BDAY CAKE ORD HALF', 'UBE CAKE HALF', 'CUSTARD BIG']);

    $routes = (string)file_get_contents($base . '/modules/daily-ledger/routes.php');
    $layout = (string)file_get_contents($base . '/templates/modules/daily-ledger/layouts/app.disyl');
    $h->test('S5 ledger table is relocated to the Commissary Daily Sheet',
        substr_count($template, 'id="production-ledger-table"') === 1
        && str_contains($template, '>BEG<')
        && str_contains($template, '>ADDTL<')
        && str_contains($template, '>TOTAL<')
        && str_contains($template, '>ACTUAL BAL<')
        && str_contains($template, '>VARIANCE<'));
    $h->test('relocated ledger has no Bread/Cakes category split',
        !str_contains($template, 'out-tbody-bread') && !str_contains($template, 'out-tbody-cake'));
    $h->test('relocated branch cells are read-only and active-branch driven',
        str_contains($template, 'daily-sheet-branch-column')
        && str_contains($template, 'production-branch-cell')
        && !str_contains($template, 'LP</th>')
        && !str_contains($template, 'SM1</th>'));
    $h->test('S7b ADDTL is a displayed value',
        str_contains($dailySheetBlock, 'production-addtl-value')
        && !str_contains($dailySheetBlock, 'production-addtl-input'));
    $h->test('S7c ADDTL has a per-row trigger that preselects the row product',
        str_contains($dailySheetBlock, 'addtl-trigger')
        && str_contains($dailySheetBlock, 'onclick="openProductionAdditionModal({row.product_id})"'));
    $h->test('S7c modal opener takes an optional product id and the toolbar stays arg-less',
        str_contains($template, 'function openProductionAdditionModal(productId)')
        && str_contains($template, 'onclick="openProductionAdditionModal()"')
        && str_contains($template, 'product.value = productId ? String(productId) : \'\''));
    $h->test('S7c numeric headers right-pad to match their cell content and inputs are flush right',
        str_contains($template, 'text-right pr-1">BEG<')
        && str_contains($template, 'text-right pr-2">ADDTL<')
        && str_contains($template, 'text-right pr-1">ACTUAL BAL<')
        && str_contains($template, 'w-20 ml-auto text-right production-beg-input')
        && str_contains($template, 'w-24 ml-auto text-right production-actual-input'));
    $h->test('S7b BEG and ACTUAL BAL save on explicit change',
        str_contains($dailySheetBlock, 'onchange="saveProductionBeg(')
        && str_contains($dailySheetBlock, 'onchange="saveProductionActual('));
    $h->test('S7b modal and bottom log are present',
        str_contains($template, 'production-addition-modal')
        && str_contains($template, 'production-ledger-log-table')
        && str_contains($handlers, 'dl_fetchProductionLedgerLog')
        && str_contains($handlers, 'dl_recordProductionAddition')
        && str_contains($handlers, 'dl_changeProductionLedgerField'));
    $h->test('S7b log edit requires an operator reason',
        str_contains($handlers, 'A reason is required to edit a recorded entry.'));
    $ledgerColumns = array_map('strtolower', $db->query('SHOW COLUMNS FROM dl_commissary_product_ledger')->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $migration064 = (string)file_get_contents($base . '/modules/daily-ledger/database/migrations/064_add_production_sheet_balances.sql');
    $migration065 = (string)file_get_contents($base . '/modules/daily-ledger/database/migrations/065_add_branch_sort_order.sql');
    $h->test('S7b introduced no inline product-ledger DDL; the balance columns come from migration 064',
        !str_contains($handlers, 'ALTER TABLE dl_commissary_product_ledger ADD')
        && in_array('beg_qty', $ledgerColumns, true)
        && in_array('actual_end_qty', $ledgerColumns, true)
        && in_array('calc_variance', $ledgerColumns, true)
        && is_file($base . '/modules/daily-ledger/database/migrations/064_add_production_sheet_balances.sql')
        && str_contains($migration064, 'ADD COLUMN beg_qty')
        && str_contains($migration064, 'ADD COLUMN actual_end_qty')
        && str_contains($migration064, 'ADD COLUMN calc_variance')
        && is_file($base . '/modules/daily-ledger/database/migrations/065_add_branch_sort_order.sql')
        && !str_contains($migration065, 'dl_commissary_product_ledger'));
    $h->test('Production Output view is removed and its URL redirects',
        !is_file($base . '/templates/modules/daily-ledger/admin/production-output.disyl')
        && !str_contains($layout, '>Production Output</span>')
        && str_contains($routes, "'daily-ledger:handleAdminProductionOutputRedirect'")
        && str_contains($handlers, "dlRedirect('/daily-ledger/admin/commissary')"));
    $h->test('production and commissary API routes remain registered',
        str_contains($routes, "'/daily-ledger/api/v1/production/output'")
        && str_contains($routes, "'/daily-ledger/api/v1/production/withdrawal'")
        && str_contains($routes, "'/daily-ledger/api/v1/production/reverse'")
        && str_contains($routes, "'/daily-ledger/api/v1/production/sync-batch'")
        && str_contains($routes, "'/daily-ledger/api/v1/commissary/run'")
        && str_contains($routes, "'/daily-ledger/api/v1/commissary/material'")
        && str_contains($routes, "'/daily-ledger/api/v1/commissary/dispatch'"));
} finally {
    $cleanup();
}

$h->test('fixture cleanup leaves only the suite\'s own production-ledger rows absent',
    (int)$db->query("SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branchId},{$retailId}) OR product_id IN ({$productA},{$productB},{$productC})")->fetchColumn() === 0);
$h->done();
