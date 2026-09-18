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
$h->test('six report packs are declared, each tailored to ledger data', count($packs) === 6);
$h->test('report packs expose only PDF and CSV', array_reduce($packs, static fn(bool $ok, array $pack): bool => $ok && ($pack['formats'] ?? []) === ['pdf', 'csv'], true));
$h->test('month-end pack is scheduled monthly', (string)($packs[3]['schedule'] ?? '') === 'monthly');
$packIds = array_map(static fn(array $pack): string => (string)$pack['id'], $packs);
$h->test(
    'the packs cover category sales and encoding exceptions',
    in_array('category-sales', $packIds, true) && in_array('data-integrity', $packIds, true)
);
$exposedCapabilities = array_map(
    static fn($capability): string => is_array($capability) ? (string)($capability['id'] ?? '') : (string)$capability,
    $manifest['capabilities']['exposes'] ?? []
);
$h->test(
    'every pack permission is declared as an exposed capability',
    array_reduce($packs, static fn(bool $ok, array $pack): bool => $ok && in_array((string)$pack['permission'], $exposedCapabilities, true), true)
);
$packDefinitions = dl_reportDefinitions();
$h->test(
    'every pack declares export columns',
    array_reduce($packs, static fn(bool $ok, array $pack): bool => $ok && ($packDefinitions[(string)$pack['id']]['columns'] ?? []) !== [], true)
);
$h->test(
    'every declared cadence is one the scheduled runner understands',
    array_reduce($packs, static fn(bool $ok, array $pack): bool => $ok && in_array((string)($pack['schedule'] ?? ''), ['daily', 'weekly', 'monthly'], true), true)
);
$h->test(
    'the scheduled runner takes each pack cadence from the manifest',
    dl_reportSchedule('sales') === 'daily'
        && dl_reportSchedule('month-end') === 'monthly'
        && dl_reportSchedule('variances') === 'weekly'
        && dl_reportSchedule('branch-summary') === 'weekly'
        && dl_reportSchedule('category-sales') === 'weekly'
        && dl_reportSchedule('data-integrity') === 'daily'
        && dl_reportSchedule('unknown-pack-falls-back-safely') === 'weekly'
);
$routes = require $h->basePath() . '/modules/daily-ledger/routes.php';
$get = $routes['GET'] ?? [];
foreach ($packIds as $type) {
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
    $pendingProductId = 99206;
    $db->execute('DELETE FROM dl_daily_ledger WHERE product_id = :p', [':p' => $pendingProductId]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $pendingProductId]);
    $db->execute('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, 10, 0, 1)', [':id' => $pendingProductId, ':sku' => 'SCHED-PEND', ':name' => 'Scheduled Pending Bread']);
    // An unfinished shift on the same day the scheduler will export. A scheduled
    // report must carry the same rows an operator gets from a manual download.
    $db->execute(
        "INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales) VALUES (:b, :p, :d, 'AM', 10, 7, 0, 0, NULL, NULL)",
        [':b' => $branchId, ':p' => $pendingProductId, ':d' => $reportDate]
    );
    $scheduledSummary = dl_runScheduledReports($db, ['role' => 'administrator', 'id' => 0, 'name' => 'Test Scheduler'], new DateTimeImmutable('2031-03-16 09:00:00+08:00'));
    $scheduledSales = null;
    foreach ($scheduledSummary['results'] as $scheduledResult) {
        if (($scheduledResult['type'] ?? '') === 'sales') $scheduledSales = $scheduledResult;
    }
    $scheduledArchives = is_array($scheduledSales['archives'] ?? null) ? $scheduledSales['archives'] : [];
    $h->test('scheduled worker generates and archives both PDF and CSV for a due tenant report',
        ($scheduledSummary['failed'] ?? 1) === 0
        && ($scheduledSales['status'] ?? '') === 'generated'
        && isset($scheduledArchives['pdf'], $scheduledArchives['csv'])
        && is_array(ReportManager::getArchivedReport((string)$scheduledArchives['pdf']))
        && is_array(ReportManager::getArchivedReport((string)$scheduledArchives['csv'])),
        json_encode($scheduledSummary, JSON_UNESCAPED_SLASHES)
    );
    $scheduledIntegrity = null;
    foreach ($scheduledSummary['results'] as $scheduledResult) {
        if (($scheduledResult['type'] ?? '') === 'data-integrity') $scheduledIntegrity = $scheduledResult;
    }
    $h->test(
        'the exceptions pack runs on its declared daily window, not the weekly default',
        ($scheduledIntegrity['status'] ?? '') === 'generated'
            && ($scheduledIntegrity['window']['date_from'] ?? '') === $reportDate
            && ($scheduledIntegrity['window']['date_to'] ?? '') === $reportDate
    );
    $h->test(
        'daily packs look at the previous day while weekly packs stay on the previous week',
        dl_reportScheduleWindow('daily', new DateTimeImmutable('2031-03-16'))['date_from'] === '2031-03-15'
            && dl_reportScheduleWindow('daily', new DateTimeImmutable('2031-03-16'))['date_to'] === '2031-03-15'
            && dl_reportScheduleWindow('weekly', new DateTimeImmutable('2031-03-16'))['date_to'] === '2031-03-09'
            && dl_reportSchedule('branch-summary') === 'weekly'
            && dl_reportSchedule('category-sales') === 'weekly'
    );
    $scheduledCsvArchive = ReportManager::getArchivedReport((string)($scheduledArchives['csv'] ?? ''));
    $scheduledCsv = is_array($scheduledCsvArchive) && is_file((string)($scheduledCsvArchive['file'] ?? ''))
        ? (string)file_get_contents((string)$scheduledCsvArchive['file'])
        : '';
    $h->test(
        'a scheduled report keeps the unfinished rows a manual export keeps',
        str_contains($scheduledCsv, 'SCHED-PEND') && str_contains($scheduledCsv, 'pending ending')
    );
    foreach ($scheduledSummary['results'] as $scheduledResult) {
        foreach ((array)($scheduledResult['archives'] ?? []) as $scheduledArchiveId) {
            $scheduledArchive = ReportManager::getArchivedReport((string)$scheduledArchiveId);
            $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND action = 'report_export' AND entity_id = ?")->execute([(string)$scheduledArchiveId]);
            if (is_array($scheduledArchive) && is_file((string)($scheduledArchive['file'] ?? ''))) @unlink((string)$scheduledArchive['file']);
            $scheduledMeta = STORAGE_PATH . '/report-archive/' . $scheduledArchiveId . '.json';
            if (is_file($scheduledMeta)) @unlink($scheduledMeta);
        }
    }
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $pendingProductId]);
} else {
    $h->fail('daily-ledger module context is available for report integration');
}

$h->section('Encoding Exceptions and Category Sales');
if ($db) {
    $exBranch = 99204;
    $exProductBread = 99204;
    $exProductCake = 99205;
    $exDirtyDate = '2031-04-10';
    $exCleanDate = '2031-04-11';
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $exBranch]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $exBranch]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $exBranch]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $exBranch]);
    $db->execute('DELETE FROM dl_products WHERE id IN (:a, :b)', [':a' => $exProductBread, ':b' => $exProductCake]);
    $db->execute('INSERT INTO dl_branches (id, code, name, is_active) VALUES (:id, :code, :name, 1)', [':id' => $exBranch, ':code' => 'T-EXC', ':name' => 'Exceptions Test Branch']);
    $products = $db->prepare('INSERT INTO dl_products (id, sku, name, product_category, current_price, sort_order, is_active) VALUES (:id, :sku, :name, :category, 10, 0, 1)');
    $products->execute([':id' => $exProductBread, ':sku' => 'EXC-BREAD', ':name' => 'Exception Bread', ':category' => 'bread']);
    $products->execute([':id' => $exProductCake, ':sku' => 'EXC-CAKE', ':name' => 'Exception Cake', ':category' => 'cake']);
    $link = $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)');
    $link->execute([':b' => $exBranch, ':p' => $exProductBread]);
    $link->execute([':b' => $exBranch, ':p' => $exProductCake]);
    $entry = $db->prepare('INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales) VALUES (:b, :p, :d, :s, 10, :beg, 0, 0, :end, :sales)');
    $exceptionsSource = (string)file_get_contents($h->basePath() . '/modules/daily-ledger/helpers/reporting.php');
    // Dirty day: one properly encoded row, one row with no ending balance at all
    // (a shift nobody finished), and one zero-ending row. The cake row stores
    // sales = 5 but has 9 on hand and ends at zero, so the report's derived
    // quantity is 9 units / 90.00 — the exact shape that inflated August sales
    // for this tenant, and proof the report reads the derived value rather than
    // the stored column.
    $entry->execute([':b' => $exBranch, ':p' => $exProductBread, ':d' => $exDirtyDate, ':s' => 'AM', ':beg' => 10, ':end' => 8, ':sales' => 2]);
    $entry->execute([':b' => $exBranch, ':p' => $exProductBread, ':d' => $exDirtyDate, ':s' => 'PM', ':beg' => 8, ':end' => null, ':sales' => null]);
    $entry->execute([':b' => $exBranch, ':p' => $exProductCake, ':d' => $exDirtyDate, ':s' => 'AM', ':beg' => 9, ':end' => 0, ':sales' => 5]);
    // Clean day: fully encoded, nothing to report.
    $entry->execute([':b' => $exBranch, ':p' => $exProductBread, ':d' => $exCleanDate, ':s' => 'AM', ':beg' => 5, ':end' => 3, ':sales' => 2]);
    $entry->execute([':b' => $exBranch, ':p' => $exProductCake, ':d' => $exCleanDate, ':s' => 'AM', ':beg' => 4, ':end' => 2, ':sales' => 2]);

    $exFilters = ['date_from' => $exDirtyDate, 'date_to' => $exDirtyDate, 'branch_id' => $exBranch, 'product_id' => 0, 'shift' => '', 'accessible_branch_ids' => [$exBranch]];
    $integrity = dl_reportDataIntegrityData($db, $exFilters);
    $integrityRow = $integrity['rows'][0] ?? [];
    $h->test(
        'the exceptions report lists only the ledger day that cannot be trusted',
        count($integrity['rows']) === 1 && ($integrityRow['ledger_date'] ?? '') === $exDirtyDate
    );
    $h->test(
        'the exceptions report separates pending, zero-ending and encoded rows',
        ($integrityRow['rows_total'] ?? 0) === 3
            && ($integrityRow['pending_rows'] ?? 0) === 1
            && ($integrityRow['zero_ending_rows'] ?? 0) === 1
            && ($integrityRow['encoded_rows'] ?? 0) === 1
            && round((float)($integrityRow['encoded_amount'] ?? 0), 2) === 20.0
            && round((float)($integrityRow['unencoded_amount'] ?? 0), 2) === 90.0
    );
    $h->test(
        'the exceptions report names the problem and totals the amount at risk',
        ($integrityRow['issue'] ?? '') === 'unfinished + zero-ending dominated'
            && ($integrity['totals']['flagged_days'] ?? 0) === 1
            && ($integrity['totals']['pending_rows'] ?? 0) === 1
            && round((float)($integrity['totals']['unencoded_amount'] ?? 0), 2) === 90.0
    );
    $cleanIntegrity = dl_reportDataIntegrityData($db, array_merge($exFilters, ['date_from' => $exCleanDate, 'date_to' => $exCleanDate]));
    $h->test(
        'a fully encoded ledger day is not reported as an exception',
        count($cleanIntegrity['rows']) === 0 && ($cleanIntegrity['totals']['flagged_days'] ?? -1) === 0
    );
    $h->test(
        'pending entries stay visible to the exceptions report instead of being filtered away',
        str_contains($exceptionsSource, "\$filters['pending_rows_mode'] = 'include';")
    );
    $reportFilters = dl_reportFilters(['date_from' => $exDirtyDate, 'date_to' => $exDirtyDate], ['role' => 'admin', 'id' => 1, 'branch_ids' => [$exBranch]]);
    $h->test(
        'exports keep unfinished rows and let the status column explain them',
        dl_overviewPendingRowsMode('exclude') === 'exclude'
            && ($reportFilters['pending_rows_mode'] ?? '') === 'include'
            && count(dl_reportSalesData($db, $reportFilters)['rows']) === 3
    );

    $category = dl_reportCategorySalesData($db, $exFilters);
    $h->test(
        'category sales group the period by product category, heaviest first',
        array_column($category['rows'], 'product_category') === ['cake', 'bread']
    );
    $h->test(
        'category sales carry units, amounts and each category share of the period',
        ($category['rows'][0]['total_units'] ?? 0) === 9
            && round((float)($category['rows'][0]['total_amount'] ?? 0), 2) === 90.0
            && round((float)($category['rows'][0]['share_pct'] ?? 0), 2) === 81.82
            && ($category['rows'][0]['product_count'] ?? 0) === 1
            && ($category['rows'][1]['total_units'] ?? 0) === 2
            && round((float)($category['rows'][1]['share_pct'] ?? 0), 2) === 18.18
    );
    $h->test(
        'category net sales follow the configured deduction',
        round((float)$category['rows'][0]['net_amount'], 2)
            === round((float)$category['rows'][0]['total_amount'] * (100 - (float)$category['rows'][0]['deduction_pct']) / 100, 2)
            && round((float)$category['rows'][0]['deduction_pct'], 2) === 10.0
    );
    $h->test(
        'category sales report the same grand totals as the sales report',
        $category['totals'] === dl_reportSalesData($db, $exFilters)['totals']
    );

    $h->section('Data Quality Warning');
    $reportTemplate = (string)file_get_contents($h->basePath() . '/templates/modules/daily-ledger/admin/reports.disyl');
    $reportHandlersSource = (string)file_get_contents($h->basePath() . '/modules/daily-ledger/handlers.php');
    $bothDays = array_merge($exFilters, ['date_from' => $exDirtyDate, 'date_to' => $exCleanDate]);
    $quality = dl_reportDataQualitySummary($db, $bothDays, 'sales');
    $h->test(
        'the data quality warning counts flagged days against ledger days, not calendar days',
        is_array($quality)
            && $quality['flagged_days'] === 1
            && $quality['days_total'] === 2
            && $quality['unfinished_days'] === 1
            && $quality['unfinished_rows'] === 1
            && $quality['at_risk'] === 90.0
            && str_starts_with($quality['label'], 'Data quality: 1 of 2 ledger days flagged')
    );
    $h->test(
        'a clean period produces no warning at all rather than a reassuring zero',
        dl_reportDataQualitySummary($db, array_merge($exFilters, ['date_from' => $exCleanDate, 'date_to' => $exCleanDate]), 'sales') === null
    );
    $h->test(
        'the exceptions report does not repeat the warning it already lists',
        dl_reportDataQualitySummary($db, $bothDays, 'data-integrity') === null
    );
    $qualityByType = [];
    foreach (['sales', 'branch-summary', 'month-end', 'category-sales', 'variances', 'data-integrity'] as $qualityType) {
        $qualityByType[$qualityType] = dl_reportDataForType($db, $qualityType, $bothDays)['data_quality'] ?? null;
    }
    $h->test(
        'every report payload carries the warning except the exceptions report',
        $qualityByType['data-integrity'] === null
            && array_reduce(
                ['sales', 'branch-summary', 'month-end', 'category-sales', 'variances'],
                static fn(bool $ok, string $type): bool => $ok && is_array($qualityByType[$type]) && str_contains((string)$qualityByType[$type]['label'], 'at risk'),
                true
            )
    );
    $h->test(
        'the report page banner is gated on the warning label',
        str_contains($reportTemplate, '{if data_quality_label}')
            && str_contains($reportTemplate, '{data_quality_label}')
            && str_contains($reportHandlersSource, "'data_quality_label' =>")
    );
    $h->test(
        'the PDF export passes the warning as its own header notice',
        str_contains($exceptionsSource, "'notice' => \$quality !== null")
            && str_contains((string)file_get_contents($h->basePath() . '/kernel/Services/KernelExport.php'), "\$options['notice']")
    );
    $warnedExport = dl_generateGovernedReport(
        'category-sales',
        'csv',
        dl_reportDataForType($db, 'category-sales', $bothDays),
        $bothDays,
        ['role' => 'administrator', 'id' => 1, 'name' => 'Test Runner'],
        'all'
    );
    $warnedArchive = is_array($warnedExport) ? ReportManager::getArchivedReport((string)($warnedExport['archive_id'] ?? '')) : null;
    $h->test(
        'the exported file records the warning it was generated under',
        is_array($warnedExport)
            && is_array($warnedArchive)
            && (int)($warnedArchive['data_quality']['flagged_days'] ?? 0) === 1
            && str_contains((string)($warnedArchive['data_quality']['label'] ?? ''), 'at risk')
    );
    if (is_array($warnedExport)) {
        foreach ([$warnedExport['path'] ?? '', $warnedArchive['file'] ?? '', STORAGE_PATH . '/report-archive/' . (string)($warnedExport['archive_id'] ?? '') . '.json'] as $warnedPath) {
            if (is_string($warnedPath) && $warnedPath !== '' && is_file($warnedPath)) @unlink($warnedPath);
        }
        $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND action = 'report_export' AND entity_id = ?")->execute([(string)($warnedExport['archive_id'] ?? '')]);
    }

    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $exBranch]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $exBranch]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $exBranch]);
    $db->execute('DELETE FROM dl_products WHERE id IN (:a, :b)', [':a' => $exProductBread, ':b' => $exProductCake]);
}

$h->section('Canonical Query Contract');
$reportingSource = file_get_contents($h->basePath() . '/modules/daily-ledger/helpers/reporting.php');
$h->test('sales report reuses canonical quantity SQL', str_contains($reportingSource, "dl_ledgerSalesQuantitySql('dl')"));
$h->test('sales report reuses canonical amount SQL', str_contains($reportingSource, "dl_ledgerSalesAmountSql('dl')"));
$h->test('report query is bounded by date range', str_contains($reportingSource, 'BETWEEN ? AND ?'));
$h->test('inline report row limit is enforced', str_contains($reportingSource, 'DL_REPORT_INLINE_ROW_LIMIT'));
$h->test('large reports fail truthfully when no executable worker exists',
    str_contains($reportingSource, 'above the %s-row inline limit')
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
$h->test(
    'the export capability gate also admits the viewer role',
    ReportManager::canExport('daily_ledger_sales', 'pdf', ['role' => 'viewer']) === true
        && ReportManager::canExport('daily_ledger_sales', 'csv', ['role' => 'viewer']) === true
);
$handlersSource = (string)file_get_contents($h->basePath() . '/modules/daily-ledger/handlers.php');
$viewerAdmitted = "dlRequireAuth(['admin', 'supervisor', 'auditor', 'viewer'])";
$h->test(
    'every report entry point admits the viewer role',
    substr_count($handlersSource, $viewerAdmitted) === 3
);
$layoutSource = (string)file_get_contents($h->basePath() . '/templates/modules/daily-ledger/layouts/app.disyl');
$reportsLinkPos = strpos($layoutSource, 'href="{base_url}/admin/reports"');
$reportsGatePos = $reportsLinkPos === false ? false : strrpos(substr($layoutSource, 0, $reportsLinkPos), "{if user_role == 'admin' || user_role == 'supervisor' || user_role == 'auditor' || user_role == 'viewer'}");
$h->test(
    'the sidebar Reports link sits behind a viewer-inclusive gate',
    $reportsLinkPos !== false && $reportsGatePos !== false
);
$h->test(
    'a failed export logs the failing stage rather than only a generic message',
    str_contains($reportingSource, 'report export: file generation failed')
        && str_contains($reportingSource, 'report export: archiving failed')
);
$inlineRefusal = static function (string $format, int $rows): string {
    try {
        dl_generateGovernedReport(
            'sales',
            $format,
            ['rows' => array_fill(0, $rows, []), 'totals' => []],
            ['date_from' => '2026-08-15', 'date_to' => '2026-08-15', 'branch_id' => 0, 'product_id' => 0, 'shift' => ''],
            ['role' => 'admin', 'id' => 1],
            'all'
        );
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    return '';
};
$h->test(
    'large export requests do not return false queued success',
    str_contains($inlineRefusal('csv', DL_REPORT_INLINE_ROW_LIMIT_CSV + 1), 'above the')
);
$h->test(
    'a refused export states the real row count and the limit that applied',
    str_contains($inlineRefusal('pdf', DL_REPORT_INLINE_ROW_LIMIT + 1), number_format(DL_REPORT_INLINE_ROW_LIMIT + 1))
        && str_contains($inlineRefusal('pdf', DL_REPORT_INLINE_ROW_LIMIT + 1), number_format(DL_REPORT_INLINE_ROW_LIMIT))
        && str_contains($inlineRefusal('pdf', DL_REPORT_INLINE_ROW_LIMIT + 1), 'download the CSV')
);
$h->test(
    'CSV streams far more rows than PDF before refusing',
    DL_REPORT_INLINE_ROW_LIMIT_CSV > DL_REPORT_INLINE_ROW_LIMIT
        && dl_reportInlineRowLimit('csv') === DL_REPORT_INLINE_ROW_LIMIT_CSV
        && dl_reportInlineRowLimit('CSV') === DL_REPORT_INLINE_ROW_LIMIT_CSV
        && dl_reportInlineRowLimit('pdf') === DL_REPORT_INLINE_ROW_LIMIT
);
$h->test('forecast inventory is restricted to accessible branch commissaries',
    str_contains($reportingSource, 'SELECT DISTINCT assigned_commissary_id')
    && str_contains($reportingSource, '$inventoryBranchIds')
);
$narrowed = dl_reportFilters(['branch_id' => 999999], ['id' => 1, 'role' => 'admin', 'branch_ids' => [$branchId]]);
$h->test('inaccessible explicit branch filter is preserved and cannot widen to all branches',
    ($narrowed['branch_id'] ?? null) === 999999
);

$h->section('Report Data Enrichment');

$reportDefinitions = dl_reportDefinitions();
$h->test(
    'sales report exports the movement columns the ledger already holds',
    in_array('beg_bal', $reportDefinitions['sales']['columns'], true)
        && in_array('addtl', $reportDefinitions['sales']['columns'], true)
        && in_array('withdraw', $reportDefinitions['sales']['columns'], true)
        && in_array('product_category', $reportDefinitions['sales']['columns'], true)
        && in_array('branch_code', $reportDefinitions['sales']['columns'], true)
);
$h->test(
    'summaries export net sales and coverage figures',
    in_array('net_amount', $reportDefinitions['branch-summary']['columns'], true)
        && in_array('share_pct', $reportDefinitions['branch-summary']['columns'], true)
        && in_array('days_counted', $reportDefinitions['month-end']['columns'], true)
        && in_array('product_count', $reportDefinitions['month-end']['columns'], true)
);
$h->test(
    'the sales query actually selects the product category it now exports',
    str_contains($reportingSource, 'p.product_category')
);

$originalNetSetting = (string)(dlModuleSettings()['net_sales_deduction_percent'] ?? '0');
saveModuleSettings('daily-ledger', ['net_sales_deduction_percent' => '10']);
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}
dlModuleSettings(true);
$enriched = dl_reportEnrichSummary([
    ['branch_code' => 'A', 'branch_name' => 'Alpha', 'official_units' => 10, 'official_amount' => 100.0, 'provisional_units' => 5, 'provisional_amount' => 50.0, 'days_counted' => 2, 'product_count' => 3],
    ['branch_code' => 'B', 'branch_name' => 'Beta', 'official_units' => 0, 'official_amount' => 0.0, 'provisional_units' => 3, 'provisional_amount' => 150.0, 'days_counted' => 1, 'product_count' => 2],
]);
$h->test(
    'summary rows combine official and provisional amounts',
    $enriched[0]['total_units'] === 15 && $enriched[0]['total_amount'] === 150.0 && $enriched[1]['total_amount'] === 150.0
);
$h->test(
    'summary rows carry their share of the period',
    $enriched[0]['share_pct'] === 50.0 && $enriched[1]['share_pct'] === 50.0
);
$h->test(
    'summary net sales reuse the configured deduction',
    $enriched[0]['deduction_pct'] === 10.0 && $enriched[0]['net_amount'] === 135.0
);
$h->test(
    'summary rows keep the coverage counts they were given',
    $enriched[0]['days_counted'] === 2 && $enriched[0]['product_count'] === 3 && $enriched[1]['product_count'] === 2
);

saveModuleSettings('daily-ledger', ['net_sales_deduction_percent' => '0']);
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}
dlModuleSettings(true);
$zeroDeduction = dl_reportEnrichSummary([
    ['branch_code' => 'A', 'branch_name' => 'Alpha', 'official_units' => 1, 'official_amount' => 200.0, 'provisional_units' => 0, 'provisional_amount' => 0.0],
]);
$h->test(
    'a zero deduction reports net equal to gross without distorting the share',
    $zeroDeduction[0]['deduction_pct'] === 0.0
        && $zeroDeduction[0]['net_amount'] === 200.0
        && $zeroDeduction[0]['share_pct'] === 100.0
);

saveModuleSettings('daily-ledger', ['net_sales_deduction_percent' => $originalNetSetting]);
if (function_exists('invalidateTenantModuleSettingsCache')) {
    invalidateTenantModuleSettingsCache();
}
dlModuleSettings(true);

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
