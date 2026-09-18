<?php

declare(strict_types=1);

use Ikabud\Kernel\Contracts\DatabaseContract;
use Ikabud\Kernel\Contracts\ModuleDB;
use Ikabud\Kernel\Services\KernelExport;
use Ikabud\Kernel\Services\ReportManager;

const DL_REPORT_MAX_DAYS = 366;
// Inline export caps. KernelExport::exportCsv() streams row by row, so CSV can
// take far more rows; exportPdf() builds the whole table for dompdf in memory,
// which is why the PDF cap stays low.
const DL_REPORT_INLINE_ROW_LIMIT = 5000;
const DL_REPORT_INLINE_ROW_LIMIT_CSV = 50000;
// A ledger day counts as "zero-ending dominated" when the sales value derived
// from zero-ending rows exceeds this multiple of the properly encoded value.
const DL_REPORT_ZERO_DOMINANCE_RATIO = 2.0;

/**
 * Inline row cap for a format.
 */
function dl_reportInlineRowLimit(string $format): int
{
    return strtolower($format) === 'csv' ? DL_REPORT_INLINE_ROW_LIMIT_CSV : DL_REPORT_INLINE_ROW_LIMIT;
}

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
        // Exports are the complete ledger record, so rows with no ending balance
        // stay in and their state shows through the status_label column. The
        // Business Overview applies its own pending filter on top of this.
        'pending_rows_mode' => 'include',
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
                   dl.product_id, p.sku, p.name AS product_name, p.product_category, dl.beg_bal, dl.addtl, dl.withdraw,
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
    // Pending rows are dropped by default so only completed entries are counted.
    if (dl_overviewPendingRowsMode($filters['pending_rows_mode'] ?? null) === 'exclude') {
        $sql .= dl_overviewPendingPredicate('dl');
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
    $days = [];
    $products = [];
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
                'days_counted' => 0,
                'product_count' => 0,
            ];
        }
        $days[$id][(string)$row['ledger_date']] = true;
        $products[$id][(int)$row['product_id']] = true;
        $bucket = $row['status_label'] === 'official' ? 'official' : 'provisional';
        $grouped[$id][$bucket . '_units'] += (int)($row['sales'] ?? 0);
        $grouped[$id][$bucket . '_amount'] += (float)($row['amount'] ?? 0);
    }
    foreach ($grouped as $id => $row) {
        $grouped[$id]['days_counted'] = count($days[$id] ?? []);
        $grouped[$id]['product_count'] = count($products[$id] ?? []);
    }

    return ['rows' => dl_reportEnrichSummary(array_values($grouped)), 'totals' => $sales['totals']];
}

/** @return array{rows:array<int,array<string,mixed>>,totals:array<string,int|float>} */
function dl_reportMonthEndData(ModuleDB $db, array $filters): array
{
    $sales = dl_reportSalesData($db, $filters);
    $grouped = [];
    $days = [];
    $products = [];
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
                'days_counted' => 0,
                'product_count' => 0,
            ];
        }
        $days[$key][(string)$row['ledger_date']] = true;
        $products[$key][(int)$row['product_id']] = true;
        $bucket = $row['status_label'] === 'official' ? 'official' : 'provisional';
        $grouped[$key][$bucket . '_units'] += (int)($row['sales'] ?? 0);
        $grouped[$key][$bucket . '_amount'] += (float)($row['amount'] ?? 0);
    }
    foreach ($grouped as $key => $row) {
        $grouped[$key]['days_counted'] = count($days[$key] ?? []);
        $grouped[$key]['product_count'] = count($products[$key] ?? []);
    }
    ksort($grouped);

    return ['rows' => dl_reportEnrichSummary(array_values($grouped)), 'totals' => $sales['totals']];
}

/** @return array<string,array{title:string,entity_type:string,columns:array<int,string>}> */
function dl_reportDefinitions(): array
{
    return [
        'sales' => ['title' => 'Daily Sales Report', 'entity_type' => 'daily_ledger_sales', 'columns' => ['ledger_date', 'shift', 'branch_code', 'branch_name', 'product_category', 'sku', 'product_name', 'beg_bal', 'addtl', 'withdraw', 'bal_end', 'sales', 'price_snapshot', 'amount', 'status_label']],
        'variances' => ['title' => 'Variance Report', 'entity_type' => 'daily_ledger_variances', 'columns' => ['ledger_date', 'shift', 'kind', 'branch_name', 'sku', 'product_name', 'expected_end_bal', 'recorded_end_bal', 'variance', 'resolution_status']],
        'branch-summary' => ['title' => 'Branch Consolidated Summary', 'entity_type' => 'daily_ledger_branch_summary', 'columns' => ['branch_code', 'branch_name', 'days_counted', 'product_count', 'official_units', 'official_amount', 'provisional_units', 'provisional_amount', 'total_units', 'total_amount', 'deduction_pct', 'net_amount', 'share_pct']],
        'month-end' => ['title' => 'Month-End Summary', 'entity_type' => 'daily_ledger_month_end', 'columns' => ['month', 'branch_code', 'branch_name', 'days_counted', 'product_count', 'official_units', 'official_amount', 'provisional_units', 'provisional_amount', 'total_units', 'total_amount', 'deduction_pct', 'net_amount', 'share_pct']],
        'category-sales' => ['title' => 'Sales by Category', 'entity_type' => 'daily_ledger_category_sales', 'columns' => ['product_category', 'product_count', 'official_units', 'official_amount', 'provisional_units', 'provisional_amount', 'total_units', 'total_amount', 'deduction_pct', 'net_amount', 'share_pct']],
        'data-integrity' => ['title' => 'Encoding Exceptions', 'entity_type' => 'daily_ledger_data_integrity', 'columns' => ['ledger_date', 'branch_code', 'branch_name', 'rows_total', 'pending_rows', 'zero_ending_rows', 'encoded_rows', 'encoded_amount', 'unencoded_amount', 'issue']],
    ];
}

/**
 * Category-level sales for the period, shaped like the branch summary so every
 * summary report reads the same way on the page and in the export.
 *
 * @return array{rows:array<int,array<string,mixed>>,totals:array<string,int|float>}
 */
function dl_reportCategorySalesData(ModuleDB $db, array $filters): array
{
    $sales = dl_reportSalesData($db, $filters);
    $grouped = [];
    foreach ($sales['rows'] as $row) {
        $category = trim((string)($row['product_category'] ?? ''));
        if ($category === '') {
            $category = 'Uncategorised';
        }
        if (!isset($grouped[$category])) {
            $grouped[$category] = [
                'product_category' => $category,
                'product_count' => 0,
                'official_units' => 0,
                'official_amount' => 0.0,
                'provisional_units' => 0,
                'provisional_amount' => 0.0,
            ];
        }
        $grouped[$category]['_products'][(int)$row['product_id']] = true;
        $bucket = $row['status_label'] === 'official' ? 'official' : 'provisional';
        $grouped[$category][$bucket . '_units'] += (int)($row['sales'] ?? 0);
        $grouped[$category][$bucket . '_amount'] += (float)($row['amount'] ?? 0);
    }
    foreach ($grouped as $category => $row) {
        $grouped[$category]['product_count'] = count($row['_products']);
        unset($grouped[$category]['_products']);
    }
    // Heaviest category first — the point of the report is where the money is.
    uasort($grouped, static fn(array $a, array $b): int => ($b['official_amount'] + $b['provisional_amount']) <=> ($a['official_amount'] + $a['provisional_amount']));

    return ['rows' => dl_reportEnrichSummary(array_values($grouped)), 'totals' => $sales['totals']];
}

/**
 * Ledger days whose figures cannot be trusted: entries still pending (the
 * cashier never finished) or days dominated by zero-ending stock, where the
 * derived quantity treats everything on hand as sold.
 *
 * Only problem days are listed. This report exists to point at what needs
 * re-encoding rather than to restate the ledger.
 *
 * @return array{rows:array<int,array<string,mixed>>,totals:array<string,int|float>}
 */
function dl_reportDataIntegrityData(ModuleDB $db, array $filters): array
{
    // Pending rows are the evidence here, so they must never be filtered out.
    $filters['pending_rows_mode'] = 'include';
    $sales = dl_reportSalesData($db, $filters);

    $grouped = [];
    foreach ($sales['rows'] as $row) {
        $key = (string)$row['ledger_date'] . ':' . (int)$row['branch_id'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'ledger_date' => (string)$row['ledger_date'],
                'branch_code' => (string)$row['branch_code'],
                'branch_name' => (string)$row['branch_name'],
                'rows_total' => 0,
                'pending_rows' => 0,
                'zero_ending_rows' => 0,
                'encoded_rows' => 0,
                'encoded_amount' => 0.0,
                'unencoded_amount' => 0.0,
            ];
        }
        $grouped[$key]['rows_total']++;
        $ending = $row['bal_end'];
        if ($ending === null) {
            $grouped[$key]['pending_rows']++;
            continue;
        }
        $amount = (float)($row['amount'] ?? 0);
        if ((int)$ending === 0) {
            $grouped[$key]['zero_ending_rows']++;
            $grouped[$key]['unencoded_amount'] += $amount;
            continue;
        }
        $grouped[$key]['encoded_rows']++;
        $grouped[$key]['encoded_amount'] += $amount;
    }

    $rows = [];
    foreach ($grouped as $day) {
        $pending = (int)$day['pending_rows'];
        $dominated = $day['unencoded_amount'] > 0.0
            && $day['unencoded_amount'] > DL_REPORT_ZERO_DOMINANCE_RATIO * $day['encoded_amount'];
        if ($pending === 0 && !$dominated) {
            continue;
        }
        $day['issue'] = match (true) {
            $pending > 0 && $dominated => 'unfinished + zero-ending dominated',
            $pending > 0 => 'unfinished shift',
            default => 'zero-ending dominated',
        };
        $day['encoded_amount'] = round($day['encoded_amount'], 2);
        $day['unencoded_amount'] = round($day['unencoded_amount'], 2);
        $rows[] = $day;
    }
    // Worst exposure first, then oldest.
    usort($rows, static fn(array $a, array $b): int => ($b['unencoded_amount'] <=> $a['unencoded_amount']) ?: strcmp($a['ledger_date'], $b['ledger_date']));

    return [
        'rows' => $rows,
        'totals' => [
            'flagged_days' => count($rows),
            'days_total' => count($grouped),
            'pending_rows' => array_sum(array_column($rows, 'pending_rows')),
            'zero_ending_rows' => array_sum(array_column($rows, 'zero_ending_rows')),
            'unencoded_amount' => round(array_sum(array_column($rows, 'unencoded_amount')), 2),
        ],
    ];
}

/**
 * Add the figures the grouped summaries share: combined totals, the configured
 * net-sales deduction, each row's share of the period, and coverage counts.
 *
 * Net sales reuse the same helper the Business Overview uses, so a report and
 * the screen can never disagree about what "net" means.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function dl_reportEnrichSummary(array $rows): array
{
    $setting = (string)(dlModuleSettings()['net_sales_deduction_percent'] ?? '0');
    $grandTotal = 0.0;
    foreach ($rows as $row) {
        $grandTotal += (float)($row['official_amount'] ?? 0) + (float)($row['provisional_amount'] ?? 0);
    }

    foreach ($rows as &$row) {
        $units = (int)($row['official_units'] ?? 0) + (int)($row['provisional_units'] ?? 0);
        $amount = (float)($row['official_amount'] ?? 0) + (float)($row['provisional_amount'] ?? 0);
        $net = dl_overviewNetSales($amount, $setting);
        $row['total_units'] = $units;
        $row['total_amount'] = round($amount, 2);
        $row['net_amount'] = round($net['net'], 2);
        $row['deduction_pct'] = $net['percent'];
        $row['share_pct'] = $grandTotal > 0.0 ? round($amount / $grandTotal * 100, 2) : 0.0;
    }
    unset($row);

    return $rows;
}

/** @return array{rows:array<int,array<string,mixed>>,totals:array<string,int|float>} */
/**
 * Period-level data-quality headline for a report: how many ledger days carry
 * unfinished entries or sales derived from zero-ending stock, and how much money
 * sits on those days.
 *
 * Returns null when the period is clean, so callers show nothing rather than a
 * reassuring zero. The exceptions report itself is excluded — it lists these
 * days already.
 *
 * Costs one extra aggregate query per render; a total nobody can trust is worth
 * more than the query it saves.
 */
function dl_reportDataQualitySummary(ModuleDB $db, array $filters, string $type = ''): ?array
{
    if ($type === 'data-integrity') {
        return null;
    }
    $integrity = dl_reportDataIntegrityData($db, $filters);
    $flaggedDays = (int)($integrity['totals']['flagged_days'] ?? 0);
    if ($flaggedDays === 0) {
        return null;
    }

    $unfinishedDays = 0;
    $unfinishedRows = 0;
    $zeroEndingRows = 0;
    $atRisk = 0.0;
    foreach ($integrity['rows'] as $row) {
        $pending = (int)($row['pending_rows'] ?? 0);
        if ($pending > 0) {
            $unfinishedDays++;
            $unfinishedRows += $pending;
        }
        $zeroEndingRows += (int)($row['zero_ending_rows'] ?? 0);
        $atRisk += (float)($row['unencoded_amount'] ?? 0);
    }
    $atRisk = round($atRisk, 2);
    $daysTotal = (int)($integrity['totals']['days_total'] ?? 0);

    $parts = [sprintf(
        '%s of %s ledger %s flagged',
        number_format($flaggedDays),
        number_format($daysTotal),
        $daysTotal === 1 ? 'day' : 'days'
    )];
    if ($atRisk > 0.0) {
        $parts[] = 'PHP ' . number_format($atRisk, 2) . ' at risk';
    }
    if ($unfinishedDays > 0) {
        $parts[] = sprintf('%s unfinished %s', number_format($unfinishedDays), $unfinishedDays === 1 ? 'day' : 'days');
    }

    return [
        'flagged_days' => $flaggedDays,
        'days_total' => $daysTotal,
        'unfinished_days' => $unfinishedDays,
        'unfinished_rows' => $unfinishedRows,
        'zero_ending_rows' => $zeroEndingRows,
        'at_risk' => $atRisk,
        'label' => 'Data quality: ' . implode(' · ', $parts),
    ];
}

function dl_reportDataForType(ModuleDB $db, string $type, array $filters): array
{
    $data = match ($type) {
        'variances' => dl_reportVarianceData($db, $filters),
        'branch-summary' => dl_reportBranchSummaryData($db, $filters),
        'month-end' => dl_reportMonthEndData($db, $filters),
        'category-sales' => dl_reportCategorySalesData($db, $filters),
        'data-integrity' => dl_reportDataIntegrityData($db, $filters),
        default => dl_reportSalesData($db, $filters),
    };
    $data['data_quality'] = dl_reportDataQualitySummary($db, $filters, $type);

    return $data;
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
    $rowCount = count($data['rows']);
    $inlineLimit = dl_reportInlineRowLimit($format);
    if (!$background && $rowCount > $inlineLimit) {
        // ReportManager persists recurring schedule definitions, but this checkout
        // has no worker that can execute a one-off Daily Ledger export request.
        // Do not return a false 202 success for work that will never run — and
        // state the real numbers so the operator knows how far to narrow.
        throw new DlReportUserException(trim(sprintf(
            'This %s export covers %s rows, above the %s-row inline limit. Narrow the filters%s.',
            strtoupper($format),
            number_format($rowCount),
            number_format($inlineLimit),
            $format === 'csv' ? '' : ', or download the CSV which handles much larger exports'
        )));
    }

    $rows = dl_reportExportRows($data['rows'], $definition['columns']);
    if (!$rows) {
        throw new DlReportUserException('No report rows match the selected filters.');
    }
    $filename = dl_reportFilename($type, $filters, $format, $branchLabel);
    $quality = is_array($data['data_quality'] ?? null) ? $data['data_quality'] : null;
    // The PDF carries a real header block, so the warning travels with the
    // document that gets printed and signed. CSV stays clean and machine
    // readable (columns only) and records the same warning in its archive
    // metadata instead.
    $filterSummary = dl_reportFilterSummary($filters, $branchLabel);
    $options = [
        'title' => $definition['title'],
        'filename' => $filename,
        'columns' => $definition['columns'],
        'orientation' => count($definition['columns']) > 7 ? 'landscape' : 'portrait',
        'signature_block' => true,
        'company_name' => trim((string)(dlModuleSettings()['app_name'] ?? 'Daily Ledger')) ?: 'Daily Ledger',
        'filter_summary' => $filterSummary,
        'notice' => $quality !== null ? (string)$quality['label'] : '',
        'generated_by' => (string)($user['full_name'] ?? $user['name'] ?? $user['username'] ?? 'Unknown'),
        'totals' => $data['totals'],
    ];
    $export = KernelExport::export($definition['entity_type'], $format, $rows, $options);
    if (!is_array($export)) {
        write_log('daily-ledger report export: file generation failed', 'error', [
            'type' => $type,
            'format' => $format,
            'rows' => count($rows),
        ]);

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
        'data_quality' => $quality,
    ]);
    if ($archiveId === null) {
        // ArchiveReport returns null without detail when the copy fails — most
        // often because storage/report-archive is not writable by the web user.
        // Log the stage so the generic "Unable to generate report" message is
        // not the only clue left in the logs.
        write_log('daily-ledger report export: archiving failed — check that storage/report-archive is writable by the web server user', 'error', [
            'type' => $type,
            'format' => $format,
            'path' => (string)($export['path'] ?? ''),
        ]);

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
/**
 * Intended cadence for a report type. Read from the module manifest so the
 * Reports page and the scheduled runner can never disagree about how often a
 * pack is meant to run.
 */
function dl_reportSchedule(string $type): string
{
    static $schedules = null;
    if ($schedules === null) {
        $schedules = [];
        foreach (ReportManager::moduleReportPacks() as $pack) {
            if (($pack['module'] ?? '') !== 'daily-ledger') {
                continue;
            }
            $declared = strtolower((string)($pack['schedule'] ?? ''));
            $schedules[(string)$pack['report_id']] = in_array($declared, ['daily', 'weekly', 'monthly'], true)
                ? $declared
                : 'weekly';
        }
    }

    return $schedules[$type] ?? 'weekly';
}

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
        $schedule = dl_reportSchedule($type);
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
            // Same completeness contract as a manual export: a scheduled report
            // must not contain fewer rows than the one an operator downloads.
            'pending_rows_mode' => 'include',
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

// ─── Business Overview Analytics ────────────────────────────────────────
// Read-only aggregations rendered on /daily-ledger/admin/overview. All SQL is
// set-based and MySQL 5.7 compatible (no CTE, window function, or JSON_TABLE).

const DL_OVERVIEW_TOP_PRODUCTS_LIMIT = 10;
const DL_OVERVIEW_PER_BRANCH_PRODUCTS_LIMIT = 5;
const DL_OVERVIEW_FORECAST_WINDOW_DEFAULT = 14;
const DL_OVERVIEW_FORECAST_WINDOW_MIN = 3;
const DL_OVERVIEW_FORECAST_WINDOW_MAX = 90;
const DL_OVERVIEW_PARETO_THRESHOLD = 0.80;
const DL_OVERVIEW_SORT_FIELDS = ['amount', 'units'];
const DL_OVERVIEW_CHART_LIMIT_DEFAULT = 10;
const DL_OVERVIEW_CHART_LIMIT_MAX = 25;

/**
 * Forecast period multipliers (prod-unit horizon).
 *
 * @var array<string,int>
 */
const DL_OVERVIEW_FORECAST_PERIODS = [
    'daily' => 1,
    'weekly' => 7,
    'monthly' => 30,
];

/**
 * Parse the configurable net-sales deduction percentage. Non-numeric input is
 * treated as 0 ("not configured") and the result is clamped to 0..100.
 *
 * @param mixed $value
 */
function dl_overviewNetSalesPercent($value): float
{
    if (!is_numeric($value)) {
        return 0.0;
    }

    $percent = (float)$value;
    if ($percent <= 0.0) {
        return 0.0;
    }
    if ($percent >= 100.0) {
        return 100.0;
    }

    return $percent;
}

/**
 * Resolve gross sales into a labelled net value. A 0% deduction is valid but
 * operationally ambiguous, so it is reported as "not configured" instead of
 * echoing the gross figure as if a net calculation had been applied.
 *
 * @param mixed $settingValue
 * @return array{percent:float,configured:bool,gross:float,net:float}
 */
function dl_overviewNetSales(float $gross, $settingValue): array
{
    $percent = dl_overviewNetSalesPercent($settingValue);
    $configured = $percent > 0.0;

    return [
        'percent' => $percent,
        'configured' => $configured,
        'gross' => $gross,
        'net' => $configured ? $gross * (1 - $percent / 100) : $gross,
    ];
}

/**
 * Complete per-product totals for the period, ranked amount DESC then units
 * DESC with a stable id tie-breaker. One grouped query feeds both the overall
 * top-N slice and the Pareto accumulation.
 *
 * @param array<string,mixed> $filters
 * @return array<int,array<string,mixed>>
 */
function dl_overviewProductTotals(DatabaseContract $db, array $filters): array
{
    $ids = array_values(array_filter(array_map('intval', $filters['accessible_branch_ids'] ?? []), static fn(int $id): bool => $id > 0));
    if ($ids === []) {
        return [];
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $qty = dl_ledgerSalesQuantitySql('dl');
    $amount = dl_ledgerSalesAmountSql('dl');
    $sql = "SELECT p.id, p.name, p.sku, p.product_category,
                   COALESCE(SUM({$qty}), 0) AS units,
                   COALESCE(SUM({$amount}), 0) AS amount,
                   COUNT(DISTINCT dl.branch_id) AS branch_count
              FROM dl_daily_ledger dl
              INNER JOIN dl_products p ON p.id = dl.product_id
             WHERE dl.ledger_date BETWEEN ? AND ?
               AND dl.branch_id IN ({$marks})";
    $bind = array_merge([$filters['date_from'] ?? '', $filters['date_to'] ?? ''], $ids);
    if (($filters['branch_id'] ?? 0) > 0) {
        $sql .= ' AND dl.branch_id = ?';
        $bind[] = (int)$filters['branch_id'];
    }
    if (dl_overviewPendingRowsMode($filters['pending_rows_mode'] ?? null) === 'exclude') {
        $sql .= dl_overviewPendingPredicate('dl');
    }
    // HAVING units > 0 drops products with no completed sales. A dl_daily_ledger
    // row can exist while nothing was sold (a pending row yields a NULL quantity
    // which COALESCE turns into 0), and those products must never be ranked as
    // saleable products nor take part in the 80/20 rule.
    $sql .= ' GROUP BY p.id, p.name, p.sku, p.product_category
              HAVING units > 0
              ORDER BY amount DESC, units DESC, p.id ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['units'] = (int)($row['units'] ?? 0);
        $row['amount'] = (float)($row['amount'] ?? 0);
        $row['branch_count'] = (int)($row['branch_count'] ?? 0);
        return $row;
    }, $rows);
}

/**
 * Per-branch product totals from one grouped (branch_id, product_id) query,
 * sliced in PHP to the top N per branch. Never issues a query per branch.
 *
 * @param array<string,mixed> $filters
 * @return array<int,array{branch_id:int,branch_name:string,products:array<int,array<string,mixed>>}>
 */
function dl_overviewBranchProductTotals(
    DatabaseContract $db,
    array $filters,
    int $limitPerBranch = DL_OVERVIEW_PER_BRANCH_PRODUCTS_LIMIT,
    string $sortBy = 'amount',
    string $sortDir = 'desc'
): array {
    $ids = array_values(array_filter(array_map('intval', $filters['accessible_branch_ids'] ?? []), static fn(int $id): bool => $id > 0));
    if ($ids === []) {
        return [];
    }

    $limitPerBranch = max(1, $limitPerBranch);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $qty = dl_ledgerSalesQuantitySql('dl');
    $amount = dl_ledgerSalesAmountSql('dl');
    $sql = "SELECT dl.branch_id, b.name AS branch_name,
                   p.id AS product_id, p.name, p.sku, p.product_category,
                   COALESCE(SUM({$qty}), 0) AS units,
                   COALESCE(SUM({$amount}), 0) AS amount
              FROM dl_daily_ledger dl
              INNER JOIN dl_branches b ON b.id = dl.branch_id
              INNER JOIN dl_products p ON p.id = dl.product_id
             WHERE dl.ledger_date BETWEEN ? AND ?
               AND dl.branch_id IN ({$marks})";
    $bind = array_merge([$filters['date_from'] ?? '', $filters['date_to'] ?? ''], $ids);
    if (($filters['branch_id'] ?? 0) > 0) {
        $sql .= ' AND dl.branch_id = ?';
        $bind[] = (int)$filters['branch_id'];
    }
    if (dl_overviewPendingRowsMode($filters['pending_rows_mode'] ?? null) === 'exclude') {
        $sql .= dl_overviewPendingPredicate('dl');
    }
    // Same no-sales exclusion as the overall totals: only products that actually
    // sold are ranked per branch.
    $sql .= ' GROUP BY dl.branch_id, b.name, p.id, p.name, p.sku, p.product_category
              HAVING units > 0';

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $grouped = [];
    foreach ($rows as $row) {
        $bid = (int)$row['branch_id'];
        if (!isset($grouped[$bid])) {
            $grouped[$bid] = [
                'branch_id' => $bid,
                'branch_name' => (string)($row['branch_name'] ?? ''),
                'products' => [],
            ];
        }
        $grouped[$bid]['products'][] = [
            'id' => (int)$row['product_id'],
            'name' => (string)($row['name'] ?? ''),
            'sku' => (string)($row['sku'] ?? ''),
            'product_category' => (string)($row['product_category'] ?? ''),
            'units' => (int)$row['units'],
            'amount' => (float)$row['amount'],
        ];
    }

    // Sorting happens in PHP so the requested direction applies inside each
    // branch; the slice then keeps that branch's N strongest (or weakest)
    // products. Branch order stays deterministic by id.
    $branches = [];
    foreach ($grouped as $branch) {
        $branch['products'] = array_slice(
            dl_overviewSortProducts($branch['products'], $sortBy, $sortDir),
            0,
            $limitPerBranch
        );
        $branches[] = $branch;
    }
    usort($branches, static fn(array $a, array $b): int => $a['branch_id'] <=> $b['branch_id']);

    return $branches;
}

/**
 * Slice the already amount-ranked product totals to the overall top N.
 *
 * @param array<int,array<string,mixed>> $productTotals
 * @return array<int,array<string,mixed>>
 */
function dl_overviewTopProducts(array $productTotals, int $limit = DL_OVERVIEW_TOP_PRODUCTS_LIMIT): array
{
    if ($limit <= 0) {
        return [];
    }

    return array_slice($productTotals, 0, $limit);
}

/**
 * Normalize the requested sort field to a supported metric.
 *
 * @param mixed $value
 */
function dl_overviewNormalizeSortBy($value): string
{
    $by = strtolower(trim((string)$value));

    return in_array($by, DL_OVERVIEW_SORT_FIELDS, true) ? $by : 'amount';
}

/**
 * Normalize the requested sort direction. Anything other than "asc" is
 * descending, so the default view keeps the previous highest-first behaviour.
 *
 * @param mixed $value
 */
function dl_overviewNormalizeSortDir($value): string
{
    return strtolower(trim((string)$value)) === 'asc' ? 'asc' : 'desc';
}

/**
 * Resolve the pending-rows control.
 *
 * The form posts `pending_rows` twice: a hidden "include" followed by the
 * checkbox "exclude". A browser submits both while the box is ticked and only
 * the hidden field once it is cleared, so the surviving value is "exclude" only
 * while the box stays ticked. An absent parameter — a plain page load — means the
 * default: pending rows are excluded.
 *
 * @param mixed $value
 */
function dl_overviewPendingRowsMode($value): string
{
    return strtolower(trim((string)$value)) === 'include' ? 'include' : 'exclude';
}

/**
 * SQL predicate dropping pending rows (no ending recorded). A pending row
 * carries a NULL quantity, so it can never contribute a sale — this predicate
 * makes the "completed entries only" contract explicit instead of relying on the
 * quantity maths.
 */
function dl_overviewPendingPredicate(string $alias = 'dl'): string
{
    $safeAlias = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) ? $alias : 'dl';

    return " AND {$safeAlias}.bal_end IS NOT NULL";
}

/**
 * Human-readable caption for the active sort, shown on the ranked tables.
 */
function dl_overviewSortLabel(string $sortBy, string $sortDir): string
{
    $metric = dl_overviewNormalizeSortBy($sortBy) === 'units' ? 'units sold' : 'sales amount';

    return $metric . (dl_overviewNormalizeSortDir($sortDir) === 'asc' ? ' (lowest first)' : ' (highest first)');
}

/**
 * Deterministically sort product aggregate rows by amount or units in either
 * direction. Ties fall back to the other metric, then to the product id, so the
 * same data always renders in the same order.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function dl_overviewSortProducts(array $rows, string $sortBy = 'amount', string $sortDir = 'desc'): array
{
    $by = dl_overviewNormalizeSortBy($sortBy);
    $ascending = dl_overviewNormalizeSortDir($sortDir) === 'asc';
    $other = $by === 'amount' ? 'units' : 'amount';

    usort($rows, static function (array $a, array $b) use ($by, $other, $ascending): int {
        $compare = (float)($a[$by] ?? 0) <=> (float)($b[$by] ?? 0);
        if ($compare === 0) {
            $compare = (float)($a[$other] ?? 0) <=> (float)($b[$other] ?? 0);
        }
        if ($compare === 0) {
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        }

        return $ascending ? $compare : -$compare;
    });

    return $rows;
}

/**
 * Attach a 0..100 bar percentage relative to the largest value so templates can
 * render dependency-free bar charts with an inline width style.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function dl_overviewBarPercentages(array $rows, string $key = 'amount'): array
{
    $max = 0.0;
    foreach ($rows as $row) {
        $max = max($max, max(0.0, (float)($row[$key] ?? 0)));
    }

    foreach ($rows as &$row) {
        $value = max(0.0, (float)($row[$key] ?? 0));
        $row['bar_pct'] = $max > 0.0 ? round($value / $max * 100, 2) : 0.0;
    }
    unset($row);

    return $rows;
}

/**
 * Attach each row's percentage share of the series total. Displayed next to the
 * bar so a reader can see contribution without doing arithmetic.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function dl_overviewValueShares(array $rows, string $key = 'amount'): array
{
    $total = 0.0;
    foreach ($rows as $row) {
        $total += max(0.0, (float)($row[$key] ?? 0));
    }

    foreach ($rows as &$row) {
        $value = max(0.0, (float)($row[$key] ?? 0));
        $row['share_pct'] = $total > 0.0 ? round($value / $total * 100, 2) : 0.0;
    }
    unset($row);

    return $rows;
}

/**
 * Bounded series length for the charts. 0 (or a non-numeric value meaning "all")
 * is preserved as "no limit"; anything above the maximum is clamped so a chart
 * can never render an unbounded number of rows.
 *
 * @param mixed $value
 */
function dl_overviewNormalizeChartLimit($value): int
{
    if (!is_numeric($value)) {
        return DL_OVERVIEW_CHART_LIMIT_DEFAULT;
    }

    $limit = (int)$value;
    if ($limit <= 0) {
        return 0;
    }

    return min(DL_OVERVIEW_CHART_LIMIT_MAX, $limit);
}

/**
 * Build a chart series from rows that must already be ranked strongest-first.
 * Rows without a positive value are dropped unless $includeEmpty is set, which
 * is what keeps empty branches from rendering as long grey tracks.
 *
 * @param array<int,array<string,mixed>> $rows ranked by $key DESC
 * @return array<int,array<string,mixed>>
 */
function dl_overviewChartSeries(array $rows, int $limit, bool $includeEmpty, string $key = 'amount'): array
{
    if (!$includeEmpty) {
        $rows = array_values(array_filter($rows, static fn(array $row): bool => (float)($row[$key] ?? 0) > 0.0));
    }
    if ($limit > 0) {
        $rows = array_slice($rows, 0, $limit);
    }

    return $rows;
}

/**
 * Pareto (80/20) accumulation over the complete amount-desc product set.
 * Stops on the first row whose cumulative amount reaches the threshold. The
 * total is never used as a divisor when it is zero, so an empty period yields
 * an empty state instead of a division error.
 *
 * @param array<int,array<string,mixed>> $productTotals amount DESC, deterministic
 * @return array{total_amount:float,scope_count:int,contributors:array<int,array<string,mixed>>,contributor_count:int,contributor_amount:float,contributor_share:float,bottom_count:int,has_data:bool}
 */
function dl_overviewPareto(array $productTotals, float $threshold = DL_OVERVIEW_PARETO_THRESHOLD): array
{
    // Only products that actually earned sales value take part in the 80/20 rule.
    // Products with no completed sales (and zero-value rows) are dropped up front
    // so they are never listed as contributors and never inflate the bottom
    // count or the "N of M products" denominator.
    $sellable = [];
    $total = 0.0;
    foreach ($productTotals as $row) {
        $amount = (float)($row['amount'] ?? 0);
        if ($amount <= 0.0) {
            continue;
        }
        $sellable[] = $row;
        $total += $amount;
    }

    $result = [
        'total_amount' => $total,
        'scope_count' => count($sellable),
        'contributors' => [],
        'contributor_count' => 0,
        'contributor_amount' => 0.0,
        'contributor_share' => 0.0,
        'bottom_count' => count($sellable),
        'has_data' => false,
    ];

    if ($total <= 0.0) {
        return $result;
    }

    $threshold = max(0.0, min(1.0, $threshold));
    $cumulative = 0.0;
    foreach ($sellable as $row) {
        $cumulative += (float)($row['amount'] ?? 0);
        $item = $row;
        $item['cumulative_amount'] = $cumulative;
        $item['cumulative_share'] = $cumulative / $total * 100;
        $result['contributors'][] = $item;
        if (($cumulative / $total) >= $threshold) {
            break;
        }
    }

    $result['has_data'] = true;
    $result['contributor_count'] = count($result['contributors']);
    $result['contributor_amount'] = $cumulative;
    $result['contributor_share'] = $cumulative / $total * 100;
    $result['bottom_count'] = max(0, count($sellable) - $result['contributor_count']);

    return $result;
}

/**
 * Validate the configurable forecast window to the established bounded range.
 * Missing/non-numeric input falls back to the default of 14 days.
 *
 * @param mixed $value
 */
function dl_overviewForecastWindow($value): int
{
    if (!is_numeric($value)) {
        return DL_OVERVIEW_FORECAST_WINDOW_DEFAULT;
    }

    return max(DL_OVERVIEW_FORECAST_WINDOW_MIN, min(DL_OVERVIEW_FORECAST_WINDOW_MAX, (int)$value));
}

function dl_overviewNormalizeForecastPeriod(string $period): string
{
    $period = strtolower(trim($period));

    return array_key_exists($period, DL_OVERVIEW_FORECAST_PERIODS) ? $period : 'daily';
}

function dl_overviewForecastPeriodDays(string $period): int
{
    return DL_OVERVIEW_FORECAST_PERIODS[dl_overviewNormalizeForecastPeriod($period)];
}

/**
 * Anchor the forecast history window at the selected date_to.
 *
 * @param array<string,mixed> $filters
 * @return array<int,array<string,mixed>>
 */
function dl_overviewForecastRows(DatabaseContract $db, array $filters, string $anchorDate, int $window = DL_OVERVIEW_FORECAST_WINDOW_DEFAULT): array
{
    $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $anchorDate);
    if (!$anchor instanceof DateTimeImmutable || $anchor->format('Y-m-d') !== $anchorDate) {
        return [];
    }

    // dl_forecastRows() keeps its ModuleDB contract; the overview handler passes
    // the module-scoped gateway, so this guard is a static-narrowing no-op at runtime.
    if (!$db instanceof ModuleDB) {
        return [];
    }

    $target = $anchor->modify('+1 day')->format('Y-m-d');

    return dl_forecastRows($db, $filters, $target, dl_overviewForecastWindow($window));
}

/**
 * Aggregate shift-level forecast rows into per-product daily averages and
 * apply the explicit period multiplier. Only a product's recorded per-day
 * average_sales is summed; projected/suggested fields are not reused here.
 * Absent history produces no rows rather than fabricated values.
 *
 * @param array<int,array<string,mixed>> $forecastRows
 * @return array{period:string,period_days:int,products:array<int,array<string,mixed>>,total_daily:float,total_units:int}
 */
function dl_overviewForecastSummary(array $forecastRows, string $period = 'daily'): array
{
    $period = dl_overviewNormalizeForecastPeriod($period);
    $days = dl_overviewForecastPeriodDays($period);

    $grouped = [];
    foreach ($forecastRows as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        if (!isset($grouped[$pid])) {
            $grouped[$pid] = [
                'product_id' => $pid,
                'sku' => (string)($row['sku'] ?? ''),
                'product_name' => (string)($row['product_name'] ?? ''),
                'daily_average' => 0.0,
                'sample_days' => 0,
            ];
        }
        $grouped[$pid]['daily_average'] += (float)($row['average_sales'] ?? 0);
        $grouped[$pid]['sample_days'] = max($grouped[$pid]['sample_days'], (int)($row['sample_days'] ?? 0));
    }

    $totalDaily = 0.0;
    $products = [];
    foreach ($grouped as $product) {
        $product['daily_average'] = round($product['daily_average'], 2);
        // Products with no units sold in the history window are omitted. The
        // sales source returns a row per ledger entry, including entries where
        // nothing moved, so a zero average means "did not sell" rather than
        // "sold nothing this period". A zero-unit projection carries no
        // production information and would only pad the table.
        if ($product['daily_average'] <= 0.0) {
            continue;
        }
        $product['projected_units'] = (int)round($product['daily_average'] * $days);
        $totalDaily += $product['daily_average'];
        $products[] = $product;
    }
    usort($products, static function (array $a, array $b): int {
        return [$b['projected_units'], $a['product_name']] <=> [$a['projected_units'], $b['product_name']];
    });

    return [
        'period' => $period,
        'period_days' => $days,
        'products' => $products,
        'total_daily' => round($totalDaily, 2),
        'total_units' => array_sum(array_map(static fn(array $product): int => (int)$product['projected_units'], $products)),
    ];
}
