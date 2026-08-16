<?php

declare(strict_types=1);

use Ikabud\Kernel\Contracts\ModuleDB;
use Ikabud\Kernel\Services\KernelExport;
use Ikabud\Kernel\Services\ReportManager;

const DL_REPORT_MAX_DAYS = 366;
const DL_REPORT_INLINE_ROW_LIMIT = 5000;

final class DlReportUserException extends RuntimeException
{
}

/** @return array<string,mixed> */
function dl_reportFilters(array $input, array $user): array
{
    $today = dl_businessDate();
    $dateFrom = dl_reportValidDate((string)($input['date_from'] ?? '')) ?: $today;
    $dateTo = dl_reportValidDate((string)($input['date_to'] ?? '')) ?: $dateFrom;
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    $from = new DateTimeImmutable($dateFrom);
    $to = new DateTimeImmutable($dateTo);
    if ((int)$from->diff($to)->days >= DL_REPORT_MAX_DAYS) {
        $dateFrom = $to->modify('-' . (DL_REPORT_MAX_DAYS - 1) . ' days')->format('Y-m-d');
    }

    $accessible = array_values(array_unique(array_filter(
        array_map('intval', dl_accessibleBranchIds($user)),
        static fn(int $id): bool => $id > 0
    )));
    $requestedBranch = max(0, (int)($input['branch_id'] ?? 0));
    // Preserve an explicit inaccessible branch so the downstream IN + equality
    // predicates return no rows. Falling back to zero here would silently widen
    // a tampered request to every branch the actor can access.
    $branchId = $requestedBranch;
    $shift = strtoupper(trim((string)($input['shift'] ?? '')));
    if (!in_array($shift, ['AM', 'PM'], true)) {
        $shift = '';
    }

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'branch_id' => $branchId,
        'product_id' => max(0, (int)($input['product_id'] ?? 0)),
        'shift' => $shift,
        'accessible_branch_ids' => $accessible ?: [0],
    ];
}

function dl_reportValidDate(string $date): ?string
{
    $date = trim($date);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date ? $date : null;
}

function dl_reportTenantScope(): string
{
    return (string)(function_exists('app') ? (app()->tenant()->current() ?? '') : '');
}

function dl_reportArchiveVisibleToTenant(array $archive, string $tenantScope): bool
{
    return str_starts_with((string)($archive['entity_type'] ?? ''), 'daily_ledger_')
        && $tenantScope !== ''
        && hash_equals($tenantScope, (string)($archive['tenant_scope'] ?? ''));
}

/** @return array<int,array<string,mixed>> */
function dl_reportFilterBranches(ModuleDB $db, array $filters): array
{
    $ids = $filters['accessible_branch_ids'];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, code, name FROM dl_branches WHERE is_active = 1 AND id IN ({$marks}) ORDER BY name");
    $stmt->execute($ids);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<int,array<string,mixed>> */
function dl_reportFilterProducts(ModuleDB $db, array $filters): array
{
    $ids = $filters['accessible_branch_ids'];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT DISTINCT p.id, p.sku, p.name
           FROM dl_products p
           JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.is_active = 1
          WHERE p.is_active = 1 AND bp.branch_id IN ({$marks})
          ORDER BY p.name"
    );
    $stmt->execute($ids);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array{rows:array<int,array<string,mixed>>,totals:array<string,int|float>} */
function dl_reportSalesData(ModuleDB $db, array $filters): array
{
    $ids = $filters['accessible_branch_ids'];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $qty = dl_ledgerSalesQuantitySql('dl');
    $amount = dl_ledgerSalesAmountSql('dl');
    $sql = "SELECT dl.ledger_date, dl.shift, dl.branch_id, b.code AS branch_code, b.name AS branch_name,
                   dl.product_id, p.sku, p.name AS product_name, dl.beg_bal, dl.addtl, dl.withdraw,
                   dl.bal_end, {$qty} AS sales, dl.price_snapshot, {$amount} AS amount,
                   ss.status AS shift_status
              FROM dl_daily_ledger dl
              JOIN dl_branches b ON b.id = dl.branch_id
              JOIN dl_products p ON p.id = dl.product_id
              LEFT JOIN dl_ledger_shift_status ss
                ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
               AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
             WHERE dl.branch_id IN ({$marks}) AND dl.ledger_date BETWEEN ? AND ?";
    $bind = array_merge($ids, [$filters['date_from'], $filters['date_to']]);
    if ($filters['branch_id'] > 0) {
        $sql .= ' AND dl.branch_id = ?';
        $bind[] = $filters['branch_id'];
    }
    if ($filters['product_id'] > 0) {
        $sql .= ' AND dl.product_id = ?';
        $bind[] = $filters['product_id'];
    }
    if ($filters['shift'] !== '') {
        $sql .= ' AND dl.shift = ?';
        $bind[] = $filters['shift'];
    }
    $sql .= ' ORDER BY dl.ledger_date, b.name, dl.shift, p.name';
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totals = ['official_units' => 0, 'official_amount' => 0.0, 'provisional_units' => 0, 'provisional_amount' => 0.0];
    foreach ($rows as &$row) {
        $pending = $row['bal_end'] === null;
        $provisional = $pending || ((string)$row['shift'] === 'PM' && (string)($row['shift_status'] ?? '') !== 'finalized');
        $row['status_label'] = $pending ? 'pending ending' : ($provisional ? 'provisional' : 'official');
        $bucket = $provisional ? 'provisional' : 'official';
        $totals[$bucket . '_units'] += (int)($row['sales'] ?? 0);
        $totals[$bucket . '_amount'] += (float)($row['amount'] ?? 0);
    }
    unset($row);

    return ['rows' => $rows, 'totals' => $totals];
}

/** @return array{rows:array<int,array<string,mixed>>,totals:array<string,int|float>} */
function dl_reportVarianceData(ModuleDB $db, array $filters): array
{
    $ids = $filters['accessible_branch_ids'];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT vf.ledger_date, vf.shift, vf.kind, vf.branch_id, b.code AS branch_code,
                   b.name AS branch_name, vf.product_id, p.sku, p.name AS product_name,
                   vf.expected_end_bal, vf.recorded_end_bal, vf.variance,
                   vf.resolution_status, vf.review_note, vf.frozen_at
              FROM dl_variance_flags vf
              JOIN dl_branches b ON b.id = vf.branch_id
              JOIN dl_products p ON p.id = vf.product_id
             WHERE vf.branch_id IN ({$marks}) AND vf.ledger_date BETWEEN ? AND ?";
    $bind = array_merge($ids, [$filters['date_from'], $filters['date_to']]);
    if ($filters['branch_id'] > 0) {
        $sql .= ' AND vf.branch_id = ?';
        $bind[] = $filters['branch_id'];
    }
    if ($filters['product_id'] > 0) {
        $sql .= ' AND vf.product_id = ?';
        $bind[] = $filters['product_id'];
    }
    if ($filters['shift'] !== '') {
        $sql .= ' AND vf.shift = ?';
        $bind[] = $filters['shift'];
    }
    $sql .= ' ORDER BY vf.ledger_date, b.name, p.name, vf.shift, vf.kind';
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $totals = ['rows' => count($rows), 'unreviewed' => 0, 'absolute_variance' => 0];
    foreach ($rows as $row) {
        if ((string)($row['resolution_status'] ?? '') === 'unreviewed') {
            $totals['unreviewed']++;
        }
        $totals['absolute_variance'] += abs((int)($row['variance'] ?? 0));
    }
    return ['rows' => $rows, 'totals' => $totals];
}

/** @return array{rows:array<int,array<string,mixed>>,totals:array<string,int|float>} */
function dl_reportBranchSummaryData(ModuleDB $db, array $filters): array
{
    $sales = dl_reportSalesData($db, $filters);
    $grouped = [];
    foreach ($sales['rows'] as $row) {
        $id = (int)$row['branch_id'];
        if (!isset($grouped[$id])) {
            $grouped[$id] = [
                'branch_id' => $id,
                'branch_code' => $row['branch_code'],
                'branch_name' => $row['branch_name'],
                'official_units' => 0,
                'official_amount' => 0.0,
                'provisional_units' => 0,
                'provisional_amount' => 0.0,
            ];
        }
        $bucket = $row['status_label'] === 'official' ? 'official' : 'provisional';
        $grouped[$id][$bucket . '_units'] += (int)($row['sales'] ?? 0);
        $grouped[$id][$bucket . '_amount'] += (float)($row['amount'] ?? 0);
    }
    return ['rows' => array_values($grouped), 'totals' => $sales['totals']];
}

/** @return array{rows:array<int,array<string,mixed>>,totals:array<string,int|float>} */
function dl_reportMonthEndData(ModuleDB $db, array $filters): array
{
    $sales = dl_reportSalesData($db, $filters);
    $grouped = [];
    foreach ($sales['rows'] as $row) {
        $month = substr((string)$row['ledger_date'], 0, 7);
        $key = $month . ':' . (int)$row['branch_id'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'month' => $month,
                'branch_code' => $row['branch_code'],
                'branch_name' => $row['branch_name'],
                'official_units' => 0,
                'official_amount' => 0.0,
                'provisional_units' => 0,
                'provisional_amount' => 0.0,
            ];
        }
        $bucket = $row['status_label'] === 'official' ? 'official' : 'provisional';
        $grouped[$key][$bucket . '_units'] += (int)($row['sales'] ?? 0);
        $grouped[$key][$bucket . '_amount'] += (float)($row['amount'] ?? 0);
    }
    ksort($grouped);
    return ['rows' => array_values($grouped), 'totals' => $sales['totals']];
}

/** @return array<string,array{title:string,entity_type:string,columns:array<int,string>}> */
function dl_reportDefinitions(): array
{
    return [
        'sales' => ['title' => 'Daily Sales Report', 'entity_type' => 'daily_ledger_sales', 'columns' => ['ledger_date', 'shift', 'branch_name', 'sku', 'product_name', 'bal_end', 'sales', 'price_snapshot', 'amount', 'status_label']],
        'variances' => ['title' => 'Variance Report', 'entity_type' => 'daily_ledger_variances', 'columns' => ['ledger_date', 'shift', 'kind', 'branch_name', 'sku', 'product_name', 'expected_end_bal', 'recorded_end_bal', 'variance', 'resolution_status']],
        'branch-summary' => ['title' => 'Branch Consolidated Summary', 'entity_type' => 'daily_ledger_branch_summary', 'columns' => ['branch_code', 'branch_name', 'official_units', 'official_amount', 'provisional_units', 'provisional_amount']],
        'month-end' => ['title' => 'Month-End Summary', 'entity_type' => 'daily_ledger_month_end', 'columns' => ['month', 'branch_code', 'branch_name', 'official_units', 'official_amount', 'provisional_units', 'provisional_amount']],
    ];
}

/** @return array{rows:array<int,array<string,mixed>>,totals:array<string,int|float>} */
function dl_reportDataForType(ModuleDB $db, string $type, array $filters): array
{
    return match ($type) {
        'variances' => dl_reportVarianceData($db, $filters),
        'branch-summary' => dl_reportBranchSummaryData($db, $filters),
        'month-end' => dl_reportMonthEndData($db, $filters),
        default => dl_reportSalesData($db, $filters),
    };
}

/** @return array<int,array<string,mixed>> */
function dl_reportExportRows(array $rows, array $columns): array
{
    $out = [];
    foreach ($rows as $row) {
        $item = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            $item[$column] = is_float($value) ? number_format($value, 2, '.', '') : $value;
        }
        $out[] = $item;
    }
    return $out;
}

function dl_reportFilename(string $type, array $filters, string $format, string $branchLabel = 'all'): string
{
    $branch = preg_replace('/[^a-z0-9-]+/i', '-', strtolower($branchLabel)) ?: 'all';
    return sprintf('%s_%s_%s_%s_%s.%s', $type, $branch, $filters['date_from'], $filters['date_to'], date('Ymd-His'), $format);
}

/** @return array<string,mixed>|null */
function dl_generateGovernedReport(string $type, string $format, array $data, array $filters, array $user, string $branchLabel = 'all', bool $background = false): ?array
{
    $definitions = dl_reportDefinitions();
    $definition = $definitions[$type] ?? null;
    if (!is_array($definition) || !in_array($format, ['pdf', 'csv'], true)) {
        return null;
    }
    if (!ReportManager::canExport($definition['entity_type'], $format, $user)) {
        throw new DlReportUserException('Export permission denied.');
    }
    if (!$background && count($data['rows']) > DL_REPORT_INLINE_ROW_LIMIT) {
        // ReportManager persists recurring schedule definitions, but this checkout
        // has no worker that can execute a one-off Daily Ledger export request.
        // Do not return a false 202 success for work that will never run.
        throw new DlReportUserException('This report is too large for inline export; background report generation is not configured. Narrow the filters and try again.');
    }

    $rows = dl_reportExportRows($data['rows'], $definition['columns']);
    if (!$rows) {
        throw new DlReportUserException('No report rows match the selected filters.');
    }
    $filename = dl_reportFilename($type, $filters, $format, $branchLabel);
    $options = [
        'title' => $definition['title'],
        'filename' => $filename,
        'columns' => $definition['columns'],
        'orientation' => count($definition['columns']) > 7 ? 'landscape' : 'portrait',
        'signature_block' => true,
        'company_name' => trim((string)(dlModuleSettings()['app_name'] ?? 'Daily Ledger')) ?: 'Daily Ledger',
        'filter_summary' => dl_reportFilterSummary($filters, $branchLabel),
        'generated_by' => (string)($user['full_name'] ?? $user['name'] ?? $user['username'] ?? 'Unknown'),
        'totals' => $data['totals'],
    ];
    $export = KernelExport::export($definition['entity_type'], $format, $rows, $options);
    if (!is_array($export)) {
        return null;
    }
    $archiveId = ReportManager::archiveReport($definition['entity_type'], $format, $export['path'], $definition['title'], [
        'filename' => $filename,
        'filters' => $filters,
        'generated_by' => $options['generated_by'],
        'generated_by_id' => dl_getActorUserId($user),
        'totals' => $data['totals'],
        'request_id' => function_exists('request_id') ? request_id() : '',
        'tenant_scope' => dl_reportTenantScope(),
        'background' => $background,
        'schedule_key' => (string)($filters['schedule_key'] ?? ''),
    ]);
    if ($archiveId === null) {
        return null;
    }
    dl_auditLog('report_export', null, $definition['entity_type'], $archiveId, null, [
        'format' => $format,
        'filename' => $filename,
        'filters' => $filters,
        'rows' => count($rows),
    ]);
    $ctx = function_exists('module') ? module() : null;
    $auditPersisted = false;
    if ($ctx) {
        try {
            $auditStmt = $ctx->db()->prepare(
                'SELECT id FROM audit_logs WHERE module = ? AND action = ? AND entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1'
            );
            $auditStmt->execute(['daily-ledger', 'report_export', $definition['entity_type'], $archiveId]);
            $auditPersisted = $auditStmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            $auditPersisted = false;
        }
    }
    if (!$auditPersisted) {
        $archived = ReportManager::getArchivedReport($archiveId);
        if (is_array($archived) && is_file((string)($archived['file'] ?? ''))) {
            @unlink((string)$archived['file']);
        }
        $archiveMeta = STORAGE_PATH . '/report-archive/' . $archiveId . '.json';
        if (is_file($archiveMeta)) {
            @unlink($archiveMeta);
        }
        if (is_file((string)($export['path'] ?? ''))) {
            @unlink((string)$export['path']);
        }
        throw new DlReportUserException('Report audit could not be persisted; export was cancelled.');
    }
    KernelExport::auditExport($definition['entity_type'], $format, $filename, dl_getActorUserId($user), function_exists('request_id') ? request_id() : '');
    // KernelExport returns its temporary path basename as `filename`; the HTTP
    // contract must expose the governed, human-readable report filename.
    $export['filename'] = $filename;
    $export['archive_id'] = $archiveId;
    return $export;
}

/** @return array{date_from:string,date_to:string} */
function dl_reportScheduleWindow(string $schedule, DateTimeImmutable $now): array
{
    return match ($schedule) {
        'weekly' => [
            'date_from' => $now->modify('monday last week')->format('Y-m-d'),
            'date_to' => $now->modify('sunday last week')->format('Y-m-d'),
        ],
        'monthly' => [
            'date_from' => $now->modify('first day of last month')->format('Y-m-d'),
            'date_to' => $now->modify('last day of last month')->format('Y-m-d'),
        ],
        default => [
            'date_from' => $now->modify('-1 day')->format('Y-m-d'),
            'date_to' => $now->modify('-1 day')->format('Y-m-d'),
        ],
    };
}

function dl_reportScheduleIsDue(string $schedule, ?string $lastCreatedAt, DateTimeImmutable $now): bool
{
    if ($lastCreatedAt === null || $lastCreatedAt === '') {
        return true;
    }
    try {
        $last = new DateTimeImmutable($lastCreatedAt);
    } catch (Throwable) {
        return true;
    }
    return match ($schedule) {
        'weekly' => $last->format('o-W') !== $now->format('o-W'),
        'monthly' => $last->format('Y-m') !== $now->format('Y-m'),
        default => $last->format('Y-m-d') !== $now->format('Y-m-d'),
    };
}

/** @return array{generated:int,skipped:int,failed:int,results:array<int,array<string,mixed>>} */
function dl_runScheduledReports(ModuleDB $db, array $user, DateTimeImmutable $now): array
{
    $tenantScope = dl_reportTenantScope();
    if ($tenantScope === '') {
        throw new RuntimeException('Scheduled reports require an explicit tenant scope.');
    }
    $archives = ReportManager::listArchived();
    $branchStmt = $db->query('SELECT id FROM dl_branches WHERE is_active = 1 ORDER BY id');
    $scheduledBranchIds = array_values(array_filter(array_map(
        'intval',
        $branchStmt ? ($branchStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : []
    ), static fn(int $id): bool => $id > 0));
    $summary = ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'results' => []];
    foreach (dl_reportDefinitions() as $type => $definition) {
        $schedule = $type === 'sales' ? 'daily' : ($type === 'month-end' ? 'monthly' : 'weekly');
        $scheduleKey = 'daily-ledger:' . $tenantScope . ':' . $type;
        $lastCreatedAt = null;
        foreach ($archives as $archive) {
            if (($archive['tenant_scope'] ?? '') === $tenantScope && ($archive['schedule_key'] ?? '') === $scheduleKey) {
                $lastCreatedAt = (string)($archive['created_at'] ?? '');
                break;
            }
        }
        if (!dl_reportScheduleIsDue($schedule, $lastCreatedAt, $now)) {
            $summary['skipped']++;
            $summary['results'][] = ['type' => $type, 'status' => 'not_due'];
            continue;
        }
        $window = dl_reportScheduleWindow($schedule, $now);
        $filters = $window + [
            'branch_id' => 0,
            'product_id' => 0,
            'shift' => '',
            'accessible_branch_ids' => $scheduledBranchIds ?: [0],
        ];
        $filters['schedule_key'] = $scheduleKey;
        try {
            $data = dl_reportDataForType($db, $type, $filters);
            if ($data['rows'] === []) {
                $summary['skipped']++;
                $summary['results'][] = ['type' => $type, 'status' => 'no_data', 'window' => $window];
                continue;
            }
            $archiveIds = [];
            foreach (['pdf', 'csv'] as $format) {
                $export = dl_generateGovernedReport($type, $format, $data, $filters, $user, 'all', true);
                if (!is_array($export)) {
                    throw new RuntimeException("Unable to generate scheduled {$format} report.");
                }
                $archiveIds[$format] = (string)$export['archive_id'];
                if (is_file((string)($export['path'] ?? ''))) {
                    @unlink((string)$export['path']);
                }
            }
            $summary['generated']++;
            $summary['results'][] = ['type' => $type, 'status' => 'generated', 'window' => $window, 'archives' => $archiveIds];
        } catch (Throwable $e) {
            $summary['failed']++;
            $summary['results'][] = ['type' => $type, 'status' => 'failed', 'error' => $e->getMessage(), 'window' => $window];
        }
    }
    return $summary;
}

function dl_reportFilterSummary(array $filters, string $branchLabel): string
{
    return sprintf(
        '%s to %s | Branch: %s | Shift: %s | Product: %s',
        $filters['date_from'],
        $filters['date_to'],
        $branchLabel,
        $filters['shift'] ?: 'All',
        $filters['product_id'] > 0 ? (string)$filters['product_id'] : 'All'
    );
}

/**
 * Pure PHP forecast aggregation. Sales rows are grouped per product and shift;
 * MySQL only supplies plain grouped/raw inputs for 5.7 compatibility.
 *
 * @return array<int,array<string,mixed>>
 */
function dl_forecastDemand(array $salesRows, array $varianceRows, array $inventoryRows, int $window = 14, float $safetyRate = 0.10): array
{
    $window = max(1, min(90, $window));
    $variance = [];
    foreach ($varianceRows as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        $shift = strtoupper((string)($row['shift'] ?? 'AM')) === 'PM' ? 'PM' : 'AM';
        $key = $pid . ':' . $shift;
        $variance[$key][] = max(0, (int)($row['variance'] ?? 0));
    }

    $inventory = [];
    foreach ($inventoryRows as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        $date = (string)($row['ledger_date'] ?? '');
        $inventory[$pid]['wastage'][$date] = ($inventory[$pid]['wastage'][$date] ?? 0) + max(0, (int)($row['wastage_qty'] ?? 0));
        if (!isset($inventory[$pid]['latest_date']) || $date > $inventory[$pid]['latest_date']) {
            $inventory[$pid]['latest_date'] = $date;
            $inventory[$pid]['remaining'] = 0;
        }
        if ($date === ($inventory[$pid]['latest_date'] ?? '')) {
            $inventory[$pid]['remaining'] += (int)($row['remaining_qty'] ?? 0);
        }
    }

    $groups = [];
    foreach ($salesRows as $row) {
        if (($row['sales'] ?? null) === null) {
            continue;
        }
        $pid = (int)($row['product_id'] ?? 0);
        $shift = strtoupper((string)($row['shift'] ?? 'AM')) === 'PM' ? 'PM' : 'AM';
        $key = $pid . ':' . $shift;
        $groups[$key]['product_id'] = $pid;
        $groups[$key]['sku'] = (string)($row['sku'] ?? '');
        $groups[$key]['product_name'] = (string)($row['product_name'] ?? '');
        $groups[$key]['shift'] = $shift;
        $date = (string)($row['ledger_date'] ?? '');
        $groups[$key]['daily'][$date] = ($groups[$key]['daily'][$date] ?? 0) + max(0, (int)$row['sales']);
    }

    $result = [];
    foreach ($groups as $key => $group) {
        ksort($group['daily']);
        $daily = array_slice(array_values($group['daily']), -$window);
        $days = max(1, count($daily));
        $average = array_sum($daily) / $days;
        $varianceValues = $variance[$key] ?? [];
        $varianceAdjustment = $varianceValues ? array_sum($varianceValues) / count($varianceValues) : 0.0;
        $wastageValues = array_values($inventory[$group['product_id']]['wastage'] ?? []);
        $productWastage = $wastageValues ? array_sum($wastageValues) / count($wastageValues) : 0.0;
        $projected = $average + $varianceAdjustment;
        $safety = $projected * max(0.0, min(1.0, $safetyRate));
        $remaining = (int)($inventory[$group['product_id']]['remaining'] ?? 0);
        $result[] = [
            'product_id' => $group['product_id'],
            'sku' => $group['sku'],
            'product_name' => $group['product_name'],
            'shift' => $group['shift'],
            'sample_days' => count($daily),
            'average_sales' => round($average, 2),
            'variance_adjustment' => round($varianceAdjustment, 2),
            'product_wastage' => $productWastage,
            'wastage_adjustment' => 0.0,
            'projected_demand' => round($projected, 2),
            'safety_margin' => round($safety, 2),
            'remaining_qty' => $remaining,
            'inventory_applied' => 0,
            'suggested_production' => 0,
        ];
    }
    usort($result, static fn(array $a, array $b): int => [$a['product_name'], $a['shift']] <=> [$b['product_name'], $b['shift']]);

    // Commissary wastage and remaining stock are product-level values, not
    // shift-level values. Distribute wastage by each shift's demand share and
    // consume remaining stock once (AM before PM) instead of applying both in
    // full to every shift.
    $productIndexes = [];
    foreach ($result as $index => $row) {
        $productIndexes[(int)$row['product_id']][] = $index;
    }
    foreach ($productIndexes as $indexes) {
        $demandTotal = array_sum(array_map(static fn(int $index): float => (float)$result[$index]['average_sales'], $indexes));
        $remainingAvailable = max(0, (int)$result[$indexes[0]]['remaining_qty']);
        foreach ($indexes as $index) {
            $share = $demandTotal > 0.0
                ? (float)$result[$index]['average_sales'] / $demandTotal
                : 1.0 / count($indexes);
            $wastageAdjustment = (float)$result[$index]['product_wastage'] * $share;
            $projected = (float)$result[$index]['average_sales'] + (float)$result[$index]['variance_adjustment'] + $wastageAdjustment;
            $safety = $projected * max(0.0, min(1.0, $safetyRate));
            $grossNeed = max(0, (int)ceil($projected + $safety));
            $inventoryApplied = min($remainingAvailable, $grossNeed);
            $remainingAvailable -= $inventoryApplied;
            $result[$index]['wastage_adjustment'] = round($wastageAdjustment, 2);
            $result[$index]['projected_demand'] = round($projected, 2);
            $result[$index]['safety_margin'] = round($safety, 2);
            $result[$index]['inventory_applied'] = $inventoryApplied;
            $result[$index]['suggested_production'] = $grossNeed - $inventoryApplied;
            unset($result[$index]['product_wastage']);
        }
    }
    return $result;
}

/** @return array<int,array<string,mixed>> */
function dl_forecastRows(ModuleDB $db, array $filters, string $targetDate, int $window = 14): array
{
    $target = new DateTimeImmutable($targetDate);
    $historyTo = $target->modify('-1 day')->format('Y-m-d');
    $historyFrom = $target->modify('-' . max(1, min(90, $window)) . ' days')->format('Y-m-d');
    $historyFilters = $filters;
    $historyFilters['date_from'] = $historyFrom;
    $historyFilters['date_to'] = $historyTo;
    $sales = dl_reportSalesData($db, $historyFilters)['rows'];
    $sales = array_values(array_filter($sales, static fn(array $row): bool => $row['status_label'] === 'official'));

    $ids = $filters['accessible_branch_ids'];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $varianceSql = "SELECT product_id, shift, variance FROM dl_variance_flags
                     WHERE branch_id IN ({$marks}) AND ledger_date BETWEEN ? AND ?
                       AND kind IN ('ending','sales')";
    $bind = array_merge($ids, [$historyFrom, $historyTo]);
    if ($filters['branch_id'] > 0) {
        $varianceSql .= ' AND branch_id = ?';
        $bind[] = $filters['branch_id'];
    }
    if ($filters['product_id'] > 0) {
        $varianceSql .= ' AND product_id = ?';
        $bind[] = $filters['product_id'];
    }
    if ($filters['shift'] !== '') {
        $varianceSql .= ' AND shift = ?';
        $bind[] = $filters['shift'];
    }
    $stmt = $db->prepare($varianceSql);
    $stmt->execute($bind);
    $variance = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $inventoryBranchIds = $filters['branch_id'] > 0 ? [$filters['branch_id']] : $ids;
    $inventoryMarks = implode(',', array_fill(0, count($inventoryBranchIds), '?'));
    $inventorySql = "SELECT product_id, ledger_date, wastage_qty, remaining_qty
                       FROM dl_commissary_product_ledger
                      WHERE ledger_date BETWEEN ? AND ?
                        AND commissary_branch_id IN (
                            SELECT DISTINCT assigned_commissary_id
                              FROM dl_branches
                             WHERE id IN ({$inventoryMarks})
                               AND assigned_commissary_id IS NOT NULL
                        )";
    $inventoryBind = array_merge([$historyFrom, $historyTo], $inventoryBranchIds);
    if ($filters['product_id'] > 0) {
        $inventorySql .= ' AND product_id = ?';
        $inventoryBind[] = $filters['product_id'];
    }
    $stmt = $db->prepare($inventorySql);
    $stmt->execute($inventoryBind);
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return dl_forecastDemand($sales, $variance, $inventory, $window);
}
