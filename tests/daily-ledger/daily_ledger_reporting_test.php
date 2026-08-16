<?php

declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-reporting', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/helpers/reporting.php');
$h->fingerprint('modules/daily-ledger/routes.php');
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('kernel/Services/KernelExport.php');
$h->fingerprint('kernel/Services/ReportManager.php');

require_once $h->basePath() . '/src/helpers/module-manager.php';
require_once $h->basePath() . '/modules/daily-ledger/helpers.php';
require_once $h->basePath() . '/modules/daily-ledger/handlers.php';

use Ikabud\Kernel\Services\KernelExport;
use Ikabud\Kernel\Services\ReportManager;

$h->section('Manifest and Routes');
$manifest = json_decode(file_get_contents($h->basePath() . '/modules/daily-ledger/module.json'), true);
$packs = $manifest['report_packs'] ?? [];
$h->test('four report packs are declared', count($packs) === 4);
$h->test('report packs expose only PDF and CSV', array_reduce($packs, static fn(bool $ok, array $pack): bool => $ok && ($pack['formats'] ?? []) === ['pdf', 'csv'], true));
$h->test('month-end pack is scheduled monthly', (string)($packs[3]['schedule'] ?? '') === 'monthly');
$routes = require $h->basePath() . '/modules/daily-ledger/routes.php';
$get = $routes['GET'] ?? [];
foreach (['sales', 'variances', 'branch-summary', 'month-end'] as $type) {
    $h->test("{$type} report page route exists", isset($get["/daily-ledger/admin/reports/{$type}"]));
    $h->test("{$type} report export route exists", isset($get["/daily-ledger/admin/reports/{$type}/export"]));
}
$h->test('forecast route exists', isset($get['/daily-ledger/admin/forecast']));

$h->section('DB-backed Report Totals');
app()->tenant()->setTenantId(207);
$dlContext = modulePushContext('daily-ledger');
$db = $dlContext ? $dlContext->db() : null;
$branchId = 99201;
$productId = 99201;
$reportDate = '2031-03-15';
if ($db) {
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
    $db->execute('INSERT INTO dl_branches (id, code, name, is_active) VALUES (:id, :code, :name, 1)', [':id' => $branchId, ':code' => 'T-RPT', ':name' => 'Report Test Branch']);
    $db->execute('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 10, 0, 1)', [':id' => $productId, ':sku' => 'RPT-1', ':name' => 'Report Product']);
    $db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $branchId, ':p' => $productId]);
    $ledger = $db->prepare('INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales) VALUES (:b, :p, :d, :s, 10, :beg, 0, 0, :end, :sales)');
    $ledger->execute([':b' => $branchId, ':p' => $productId, ':d' => $reportDate, ':s' => 'AM', ':beg' => 10, ':end' => 4, ':sales' => 6]);
    $ledger->execute([':b' => $branchId, ':p' => $productId, ':d' => $reportDate, ':s' => 'PM', ':beg' => 4, ':end' => 0, ':sales' => 4]);
    $db->execute("INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (:b, :d, 'PM', 'open')", [':b' => $branchId, ':d' => $reportDate]);
    $filters = ['date_from' => $reportDate, 'date_to' => $reportDate, 'branch_id' => $branchId, 'product_id' => $productId, 'shift' => '', 'accessible_branch_ids' => [$branchId]];
    $reportData = dl_reportSalesData($db, $filters);
    $h->test('canonical report totals official AM sales', $reportData['totals']['official_units'] === 6 && $reportData['totals']['official_amount'] === 60.0);
    $h->test('unfinalized PM is split as provisional', $reportData['totals']['provisional_units'] === 4 && $reportData['totals']['provisional_amount'] === 40.0);
    $db->execute("UPDATE dl_ledger_shift_status SET status = 'finalized' WHERE branch_id = :b AND ledger_date = :d AND shift = 'PM'", [':b' => $branchId, ':d' => $reportDate]);
    $finalData = dl_reportSalesData($db, $filters);
    $h->test('finalized PM moves into official totals', $finalData['totals']['official_units'] === 10 && $finalData['totals']['provisional_units'] === 0);
    $summary = dl_reportBranchSummaryData($db, $filters);
    $h->test('branch summary matches canonical sales totals', ($summary['rows'][0]['official_units'] ?? null) === 10 && $summary['totals'] === $finalData['totals']);
    $beforeLedger = (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger')->fetchColumn();
    $beforeCommissary = (int)$db->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn();
    $beforeMovements = (int)$db->query('SELECT COUNT(*) FROM dl_production_movements')->fetchColumn();
    $forecastRows = dl_forecastRows($db, $filters, '2031-03-16', 3);
    $h->test('DB forecast reads the seeded finalized per-shift sales', count($forecastRows) === 2 && ($forecastRows[0]['sample_days'] ?? 0) === 1);
    $h->test('DB forecast performs zero ledger or production writes',
        $beforeLedger === (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger')->fetchColumn()
        && $beforeCommissary === (int)$db->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn()
        && $beforeMovements === (int)$db->query('SELECT COUNT(*) FROM dl_production_movements')->fetchColumn()
    );
    $scheduledSummary = dl_runScheduledReports($db, ['role' => 'administrator', 'id' => 0, 'name' => 'Test Scheduler'], new DateTimeImmutable('2031-03-16 09:00:00+08:00'));
    $scheduledSales = null;
    foreach ($scheduledSummary['results'] as $scheduledResult) {
        if (($scheduledResult['type'] ?? '') === 'sales') $scheduledSales = $scheduledResult;
    }
    $scheduledArchives = is_array($scheduledSales['archives'] ?? null) ? $scheduledSales['archives'] : [];
    $h->test('scheduled worker generates and archives both PDF and CSV for a due tenant report',
        ($scheduledSummary['generated'] ?? 0) === 1
        && isset($scheduledArchives['pdf'], $scheduledArchives['csv'])
        && is_array(ReportManager::getArchivedReport((string)$scheduledArchives['pdf']))
        && is_array(ReportManager::getArchivedReport((string)$scheduledArchives['csv'])),
        json_encode($scheduledSummary, JSON_UNESCAPED_SLASHES)
    );
    foreach ($scheduledArchives as $scheduledArchiveId) {
        $scheduledArchive = ReportManager::getArchivedReport((string)$scheduledArchiveId);
        $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND action = 'report_export' AND entity_id = ?")->execute([(string)$scheduledArchiveId]);
        if (is_array($scheduledArchive) && is_file((string)($scheduledArchive['file'] ?? ''))) @unlink((string)$scheduledArchive['file']);
        $scheduledMeta = STORAGE_PATH . '/report-archive/' . $scheduledArchiveId . '.json';
        if (is_file($scheduledMeta)) @unlink($scheduledMeta);
    }
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
} else {
    $h->fail('daily-ledger module context is available for report integration');
}

$h->section('Canonical Query Contract');
$reportingSource = file_get_contents($h->basePath() . '/modules/daily-ledger/helpers/reporting.php');
$h->test('sales report reuses canonical quantity SQL', str_contains($reportingSource, "dl_ledgerSalesQuantitySql('dl')"));
$h->test('sales report reuses canonical amount SQL', str_contains($reportingSource, "dl_ledgerSalesAmountSql('dl')"));
$h->test('report query is bounded by date range', str_contains($reportingSource, 'BETWEEN ? AND ?'));
$h->test('inline report row limit is enforced', str_contains($reportingSource, 'DL_REPORT_INLINE_ROW_LIMIT'));
$h->test('large reports fail truthfully when no executable worker exists',
    str_contains($reportingSource, 'background report generation is not configured')
    && !str_contains($reportingSource, "ReportManager::scheduleReport")
);
$h->test('archive visibility is tenant scoped',
    dl_reportArchiveVisibleToTenant(['entity_type' => 'daily_ledger_sales', 'tenant_scope' => '207'], '207')
    && !dl_reportArchiveVisibleToTenant(['entity_type' => 'daily_ledger_sales', 'tenant_scope' => '208'], '207')
    && !dl_reportArchiveVisibleToTenant(['entity_type' => 'daily_ledger_sales'], '207')
);
$registry = app()->capabilities();
if (!$registry->has('export.daily_ledger_sales@1')) {
    $registry->register('export.daily_ledger_sales@1', 'daily-ledger-test', static fn(array $input): array => ['ok' => true], 50, ['first']);
}
$h->test('Daily Ledger admin passes the resolved export capability gate', ReportManager::canExport('daily_ledger_sales', 'pdf', ['role' => 'admin']) === true);
$largeReportBlocked = false;
try {
    dl_generateGovernedReport(
        'sales',
        'csv',
        ['rows' => array_fill(0, DL_REPORT_INLINE_ROW_LIMIT + 1, []), 'totals' => []],
        ['date_from' => '2026-08-15', 'date_to' => '2026-08-15', 'branch_id' => 0, 'product_id' => 0, 'shift' => ''],
        ['role' => 'admin', 'id' => 1],
        'all'
    );
} catch (RuntimeException $e) {
    $largeReportBlocked = str_contains($e->getMessage(), 'background report generation is not configured');
}
$h->test('large export requests do not return false queued success', $largeReportBlocked);
$h->test('forecast inventory is restricted to accessible branch commissaries',
    str_contains($reportingSource, 'SELECT DISTINCT assigned_commissary_id')
    && str_contains($reportingSource, '$inventoryBranchIds')
);
$narrowed = dl_reportFilters(['branch_id' => 999999], ['id' => 1, 'role' => 'admin', 'branch_ids' => [$branchId]]);
$h->test('inaccessible explicit branch filter is preserved and cannot widen to all branches',
    ($narrowed['branch_id'] ?? null) === 999999
);

$h->section('Kernel PDF and CSV');
KernelExport::registerDefaults();
$rows = [['date' => '2026-08-15', 'branch' => 'TEST', 'units' => 12, 'amount' => '1200.00']];
$options = [
    'title' => 'Daily Sales Report',
    'company_name' => 'Daily Ledger Test',
    'filter_summary' => '2026-08-15 to 2026-08-15 | Branch: TEST',
    'generated_by' => 'Test Runner',
    'totals' => ['official_units' => 12, 'official_amount' => 1200.0],
    'columns' => ['date', 'branch', 'units', 'amount'],
    'filename' => 'daily-sales_TEST_2026-08-15_2026-08-15_test.pdf',
];
$pdf = KernelExport::export('daily_ledger_sales', 'pdf', $rows, $options);
$csv = KernelExport::export('daily_ledger_sales', 'csv', $rows, array_merge($options, ['filename' => 'daily-sales_TEST_2026-08-15_2026-08-15_test.csv']));
$h->test('PDF export returns a real PDF file', is_array($pdf) && is_file($pdf['path']) && str_starts_with((string)file_get_contents($pdf['path'], false, null, 0, 4), '%PDF'));
$h->test('CSV export returns a CSV file', is_array($csv) && is_file($csv['path']) && ($csv['size'] ?? 0) > 0);

$archiveId = is_array($pdf) ? ReportManager::archiveReport('daily_ledger_sales', 'pdf', $pdf['path'], 'Daily Sales Report', ['generated_by' => 'Test Runner', 'tenant_scope' => dl_reportTenantScope()]) : null;
$archive = $archiveId ? ReportManager::getArchivedReport($archiveId) : null;
$h->test('tenant-scoped report archive metadata is created', is_string($archiveId) && is_array($archive) && is_file((string)$archive['file']) && ($archive['tenant_scope'] ?? '') === dl_reportTenantScope());

$governed = dl_generateGovernedReport(
    'sales',
    'csv',
    ['rows' => [[
        'ledger_date' => '2026-08-15', 'shift' => 'AM', 'branch_name' => 'TEST',
        'sku' => 'T-1', 'product_name' => 'Test Bread', 'bal_end' => 1,
        'sales' => 2, 'price_snapshot' => 10, 'amount' => 20.0, 'status_label' => 'official',
    ]], 'totals' => ['official_units' => 2, 'official_amount' => 20.0]],
    ['date_from' => '2026-08-15', 'date_to' => '2026-08-15', 'branch_id' => 0, 'product_id' => 0, 'shift' => ''],
    ['role' => 'admin', 'id' => 1, 'name' => 'Test Runner'],
    'all'
);
$governedArchiveId = (string)($governed['archive_id'] ?? '');
$auditStmt = $db->prepare("SELECT id FROM audit_logs WHERE module = 'daily-ledger' AND action = 'report_export' AND entity_id = ?");
$auditStmt->execute([$governedArchiveId]);
$governedAuditId = $auditStmt->fetchColumn();
$h->test('governed export persists its required audit row before returning',
    is_array($governed) && $governedArchiveId !== '' && $governedAuditId !== false
);

if (is_array($pdf) && is_file($pdf['path'])) @unlink($pdf['path']);
if (is_array($csv) && is_file($csv['path'])) @unlink($csv['path']);
if (is_array($archive) && is_file((string)$archive['file'])) @unlink((string)$archive['file']);
if (is_string($archiveId)) {
    $metaPath = STORAGE_PATH . '/report-archive/' . $archiveId . '.json';
    if (is_file($metaPath)) @unlink($metaPath);
}
if ($governedAuditId !== false) $db->prepare('DELETE FROM audit_logs WHERE id = ?')->execute([$governedAuditId]);
if (is_array($governed) && is_file((string)($governed['path'] ?? ''))) @unlink((string)$governed['path']);
if ($governedArchiveId !== '') {
    $governedArchive = ReportManager::getArchivedReport($governedArchiveId);
    if (is_array($governedArchive) && is_file((string)($governedArchive['file'] ?? ''))) @unlink((string)$governedArchive['file']);
    $governedMetaPath = STORAGE_PATH . '/report-archive/' . $governedArchiveId . '.json';
    if (is_file($governedMetaPath)) @unlink($governedMetaPath);
}

$h->done();
