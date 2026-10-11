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

    // One presentation-scope consumer for reports, exports, and the Overview. Do not repeat the
    // role predicate or the authorization/scope intersection here: that was how the viewer picker
    // and report data were allowed to disagree.
    $accessible = array_values(array_unique(array_filter(
        array_map('intval', dl_adminViewBranchIds($user, $input)),
        static fn(int $id): bool => $id > 0
    )));
    $viewScope = function_exists('dl_adminAreaScope') ? dl_adminAreaScope($user, $input) : ['type' => 'ALL', 'branch_ids' => [], 'value' => 'ALL'];
    $requestedBranch = max(0, (int)($input['branch_id'] ?? 0));
    // The Area scope is the parent of the Branch filter. A branch that is authorized but sits
    // outside the now-effective scope (because the Area changed under it) is a stale dependent:
    // reset it to All Branches instead of letting the explicit-branch predicate match nothing.
    // Deny-by-default is preserved: a branch the actor cannot access AT ALL is left in place, so
    // the downstream IN + equality predicates still return no rows rather than widening.
    if ($requestedBranch > 0 && !in_array($requestedBranch, $accessible, true)) {
        $authorized = array_values(array_unique(array_filter(
            array_map('intval', dl_accessibleBranchIds($user)),
            static fn(int $id): bool => $id > 0
        )));
        if (in_array($requestedBranch, $authorized, true)) {
            $requestedBranch = 0;
        }
    }
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
        'view_scope' => (string)($viewScope['value'] ?? 'ALL'),
        'view_scope_label' => (string)($viewScope['label'] ?? 'All branches'),
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

    // The report's own defaults (dl_reportFilters()): date_from falls back to the
    // current business date, date_to to date_from, and the pair is swapped when
    // inverted. dl_reportFilters() normally normalises these already; deriving
    // them here keeps a caller that omits them from silently seeing no rows.
    $dateFrom = dl_reportValidDate((string)($filters['date_from'] ?? '')) ?: dl_businessDate();
    $dateTo = dl_reportValidDate((string)($filters['date_to'] ?? '')) ?: $dateFrom;
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    // ADDITIVE filter. (a) is today's behaviour: active-assignment products in the
    // accessible branches. (b) adds every product that HAS a ledger row in the
    // selected scope/range, so a recorded product whose assignment was hidden or
    // deactivated can still be selected and will not vanish from the report.
    // (b) must NOT add `p.is_active = 1`: dl_reportSalesData() joins dl_products
    // with no such predicate, so an inactive product that still holds rows is part
    // of the RECORD. MySQL 5.7-safe: plain UNION, no CTE/window/JSON_TABLE/
    // LIMIT-in-IN.
    $sql = "SELECT DISTINCT p.id, p.sku, p.name
              FROM dl_products p
              JOIN dl_branch_products bp ON bp.product_id = p.id AND bp.is_active = 1
             WHERE p.is_active = 1 AND bp.branch_id IN ({$marks})
            UNION
            SELECT DISTINCT p.id, p.sku, p.name
              FROM dl_products p
              JOIN dl_daily_ledger dl ON dl.product_id = p.id
             WHERE dl.branch_id IN ({$marks})
               AND dl.ledger_date BETWEEN ? AND ?";
    $bind = array_merge($ids, $ids, [$dateFrom, $dateTo]);

    // Mirror the row predicates dl_reportSalesData() applies so the offered set
    // agrees with the record for the same filters.
    if ((int)($filters['branch_id'] ?? 0) > 0) {
        $sql .= ' AND dl.branch_id = ?';
        $bind[] = (int)$filters['branch_id'];
    }
    if ((int)($filters['product_id'] ?? 0) > 0) {
        $sql .= ' AND dl.product_id = ?';
        $bind[] = (int)$filters['product_id'];
    }
    $shift = strtoupper(trim((string)($filters['shift'] ?? '')));
    if (in_array($shift, ['AM', 'PM'], true)) {
        $sql .= ' AND dl.shift = ?';
        $bind[] = $shift;
    }
    if (dl_overviewPendingRowsMode($filters['pending_rows_mode'] ?? null) === 'exclude') {
        $sql .= dl_overviewPendingPredicate('dl');
    }
    $sql .= ' ORDER BY name';

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Is this ledger row signed off, or merely provisional? AM and PM alike.
 *
 * A NULL ending is pending, hence provisional. A shift row that EXISTS and is not
 * 'finalized' is unambiguously unsigned — an unfinalized AM is exactly as unsigned
 * as an unfinalized PM (R5) — so a recorded status is never special-cased by shift.
 *
 * A MISSING shift-status row is AMBIGUOUS: it can mean "never finalized" OR "this
 * shift was never tracked at all". Because it proves nothing either way, the no-row
 * case is deliberately bucketed the OLD way (AM official, PM provisional) to PRESERVE
 * history rather than restate it. Measured on tenant 207: 3,149 AM rows / 20,680 units
 * hang on this, versus 46 PM rows. Do NOT "correct" the backwards-looking `=== 'PM'`
 * below into `!== 'finalized'` — that would move 20,680 units of AM sales out of the
 * official total on the strength of an ambiguity, i.e. restate history.
 */
function dl_rowIsProvisional(array $row): bool
{
    // C1: a DERIVED ending is never a count. A settled row can be PROVISIONAL at most,
    // whatever the shift status says, until an admin verifies it and clears end_source.
    $endSource = (string)($row['end_source'] ?? '');
    if ($endSource === 'derived-from-movements' || $endSource === 'zero-forced') {
        return true;
    }

    if (($row['bal_end'] ?? null) === null) {
        return true;                                   // a missing ending is pending
    }

    $status = $row['shift_status'] ?? null;
    if ($status === null) {
        // No shift row is AMBIGUOUS: "never finalized" or "never tracked". Preserve the
        // historical bucketing exactly - PM was provisional, AM was official. Measured:
        // 3,149 AM rows / 20,680 units hang on this, versus 46 PM rows.
        return (string)($row['shift'] ?? '') === 'PM';
    }

    // A shift row that EXISTS and is not finalized is unambiguous, and the shift does not
    // matter: an unsigned AM is exactly as unsigned as an unsigned PM.
    return (string)$status !== 'finalized';
}

/**
 * The SQL twin of dl_rowIsProvisional(): the one fragment every aggregate uses to decide
 * whether a ledger row is provisional.
 *
 * It mirrors dl_rowIsProvisional() clause for clause, in the SAME spirit as the
 * "the bucket must follow the SAME predicate" comment further down this file:
 *   - a DERIVED ending (end_source is a ladder rung) is provisional (C1);
 *   - bal_end IS NULL is provisional;
 *   - a shift row that EXISTS and is not 'finalized' is provisional for ANY shift, AM
 *     included (R5) — this is the clause the hand-written dashboard/sales copies were
 *     missing, gating it on `shift = 'PM'` and silently counting an unfinalized AM as
 *     official;
 *   - a MISSING shift row is AMBIGUOUS and keeps the historical bucketing (AM official,
 *     PM provisional), so history is preserved rather than restated.
 *
 * Keep this and dl_rowIsProvisional() in lockstep: change one, change the other in the
 * same commit. Do NOT re-hand-write the rule in a query — that duplication is the defect
 * this function removes.
 *
 * @param string $ledgerAlias Alias of dl_daily_ledger in the enclosing query.
 * @param string $shiftAlias  Alias of the LEFT JOINed dl_ledger_shift_status row.
 */
function dl_provisionalSqlExpr(string $ledgerAlias, string $shiftAlias): string
{
    $ledger = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ledgerAlias) ? $ledgerAlias : 'dl';
    $shift = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $shiftAlias) ? $shiftAlias : 'ss';

    return '('
        . "{$ledger}.end_source IN ('derived-from-movements','zero-forced')"
        . " OR {$ledger}.bal_end IS NULL"
        . " OR ({$shift}.status IS NULL AND {$ledger}.shift = 'PM')"
        . " OR ({$shift}.status IS NOT NULL AND {$shift}.status <> 'finalized')"
        . ')';
}

/**
 * Canonical status label for a rendered sales row.
 *
 * 'no record'      — the product-driven sheet found no ledger row for this shift (has_ledger_row
 *                    is set and false). No recorded values, hence no money and no gap (D3).
 * 'pending ending' — bal_end IS NULL AND a row exists: nobody has entered a count yet.
 * 'provisional'    — ending present, but dl_rowIsProvisional() is true.
 * 'official'       — finalized/counted.
 *
 * It MUST delegate to dl_rowIsProvisional(): the bucket that totals use and the badge a
 * row renders are then the same rule, so a row can never be labelled differently from how
 * it is totalled. Do not re-implement the predicate here.
 */
function dl_salesRowStatusLabel(array $row): string
{
    // The admin Sales view (and the cashier sheet) are PRODUCT-DRIVEN: they render one row per
    // active product, so a row can arrive with NO ledger row at all. A product with no record has
    // no recorded values: it is NOT a gap (D3 counts only ACTIVITY-BEARING rows as missing an
    // ending) and it must never be labelled pending. Callers on the ledger-driven path do not set
    // this key, so their labels are byte-identical to before. This ADDS a fourth state; it does
    // not weaken or re-route the existing three.
    if (array_key_exists('has_ledger_row', $row) && !(int)$row['has_ledger_row']) {
        return 'no record';
    }
    if (($row['bal_end'] ?? null) === null) {
        return 'pending ending';
    }
    return dl_rowIsProvisional($row) ? 'provisional' : 'official';
}

/** @return array{rows:array<int,array<string,mixed>>,totals:array<string,int|float>} */
function dl_reportSalesData(ModuleDB $db, array $filters): array
{
    $ids = $filters['accessible_branch_ids'];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $qty = dl_ledgerSalesQuantitySql('dl');
    $amount = dl_ledgerSalesAmountSql('dl');
    // dl.end_source is REQUIRED by C1 in dl_rowIsProvisional(): a derived, unverified ending must
    // stay provisional whatever the shift status says. Do not drop this column from the SELECT.
    // NOTE: dl_reportSalesData() stays ROW-DRIVEN on purpose. It is the ledger RECORD used by the
    // Daily Sales/Branch/Month-End/Category reports and the scheduled exports, where a "row" means
    // "a ledger entry", and it runs across the whole accessible branch set at once. The full-sheet
    // "every active product" view is the admin Sales page (handleAdminSales), which is scoped to
    // the viewed branch/date/shift and carries its own product-driven query. Product-driving this
    // report would turn a three-row exceptions export into thousands of synthetic rows.
    $sql = "SELECT dl.ledger_date, dl.shift, dl.branch_id, b.code AS branch_code, b.name AS branch_name,
                   dl.product_id, p.sku, p.name AS product_name, p.product_category, dl.beg_bal, dl.addtl, dl.withdraw,
                   dl.bal_end, dl.end_source, {$qty} AS sales, dl.price_snapshot, {$amount} AS amount,
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
        $provisional = dl_rowIsProvisional($row);
        $row['status_label'] = dl_salesRowStatusLabel($row);

        // Settle the row from THIS sheet's own movement invariant — cashier:
        // beg_bal + addtl - withdraw (R6). The ladder is the single source of the
        // rung/ending/sales, and only the 'counted' rung is official, so no
        // derived value can enter the official total (R3). The derived ending is
        // COMPUTED for this report only; the stored bal_end stays NULL and is
        // never written back (R2).
        $movements = (int)($row['beg_bal'] ?? 0) + (int)($row['addtl'] ?? 0) - (int)($row['withdraw'] ?? 0);
        $settled = dl_settleUnfinalizedRow(
            $pending ? null : (int)$row['bal_end'],
            (string)($row['shift_status'] ?? '') === 'finalized',
            $movements,
            null,
            false
        );
        $bucket = $provisional ? 'provisional' : 'official';
        // The BUCKET must follow the SAME predicate as $row['status_label'] above, so a row can
        // never be labelled provisional and totalled as official. The ladder decides the RUNG and
        // the settled value; it must NOT decide the bucket, because the ladder calls ANY
        // unfinalized shift 'counted-unsigned' - while dl_rowIsProvisional() deliberately treats a
        // MISSING shift row as historically official. Deriving the bucket from the ladder's
        // 'official' flag therefore silently restated the 3,149 AM rows / 20,680 units that have a
        // recorded ending and no shift row - the exact restatement that predicate exists to
        // prevent. Caught by daily_ledger_reporting_test; a predicate-only comparison missed it.
        $settledSales = max(0, (int)($settled['sales'] ?? 0));
        $row['settled_rung'] = (string)($settled['rung'] ?? '');
        $row['sales'] = $settledSales;
        $row['amount'] = $settledSales * (float)($row['price_snapshot'] ?? 0);
        $totals[$bucket . '_units'] += $settledSales;
        $totals[$bucket . '_amount'] += (float)$row['amount'];
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

    return ['rows' => dl_reportEnrichSummary(array_values($grouped), true), 'totals' => $sales['totals']];
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

    return ['rows' => dl_reportEnrichSummary(array_values($grouped), true), 'totals' => $sales['totals']];
}

/** @return array<string,array{title:string,entity_type:string,columns:array<int,string>}> */
function dl_reportDefinitions(): array
{
    return [
        'sales' => ['title' => 'Daily Sales Report', 'entity_type' => 'daily_ledger_sales', 'columns' => ['ledger_date', 'shift', 'branch_code', 'branch_name', 'product_category', 'sku', 'product_name', 'beg_bal', 'addtl', 'withdraw', 'bal_end', 'sales', 'price_snapshot', 'amount', 'status_label']],
        'variances' => ['title' => 'Variance Report', 'entity_type' => 'daily_ledger_variances', 'columns' => ['ledger_date', 'shift', 'kind', 'branch_name', 'sku', 'product_name', 'expected_end_bal', 'recorded_end_bal', 'variance', 'resolution_status']],
        'branch-summary' => ['title' => 'Branch Consolidated Summary', 'entity_type' => 'daily_ledger_branch_summary', 'columns' => ['branch_code', 'branch_name', 'days_counted', 'product_count', 'official_units', 'official_amount', 'provisional_units', 'total_units', 'total_amount', 'deduction_pct', 'net_amount', 'share_pct']],
        'month-end' => ['title' => 'Month-End Summary', 'entity_type' => 'daily_ledger_month_end', 'columns' => ['month', 'branch_code', 'branch_name', 'days_counted', 'product_count', 'official_units', 'official_amount', 'provisional_units', 'total_units', 'total_amount', 'deduction_pct', 'net_amount', 'share_pct']],
        'category-sales' => ['title' => 'Sales by Category', 'entity_type' => 'daily_ledger_category_sales', 'columns' => ['product_category', 'product_count', 'official_units', 'official_amount', 'provisional_units', 'total_units', 'total_amount', 'deduction_pct', 'net_amount', 'share_pct']],
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

    return ['rows' => dl_reportEnrichSummary(array_values($grouped), true), 'totals' => $sales['totals']];
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
 * MONEY IS OFFICIAL ONLY. When $officialOnly is true (the report screens and
 * their exports), total_amount, net_amount and share_pct are computed from
 * official_amount alone: a provisional row is not money the owner may see
 * presented as sales. UNITS keep the official + provisional breakdown because
 * outstanding units are operational information, not money.
 *
 * The default (false) preserves the historical combined-total behaviour for
 * callers that still need the bucket breakdown; the report data builders pass
 * true so nothing provisional-inclusive reaches a screen or an export.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function dl_reportEnrichSummary(array $rows, bool $officialOnly = false): array
{
    $setting = (string)(dlModuleSettings()['net_sales_deduction_percent'] ?? '0');
    $grandTotal = 0.0;
    foreach ($rows as $row) {
        $grandTotal += (float)($row['official_amount'] ?? 0)
            + ($officialOnly ? 0.0 : (float)($row['provisional_amount'] ?? 0));
    }

    foreach ($rows as &$row) {
        $units = (int)($row['official_units'] ?? 0) + (int)($row['provisional_units'] ?? 0);
        $amount = (float)($row['official_amount'] ?? 0)
            + ($officialOnly ? 0.0 : (float)($row['provisional_amount'] ?? 0));
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

/**
 * Shape report rows for CSV/PDF output.
 *
 * For the Daily Sales export a non-official (pending/provisional) row's computed
 * `amount` is NOT money to export: it is blanked so it can never be summed or
 * read as revenue. The `status_label` column is kept, so the row still explains
 * itself and nothing is silently dropped.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param array<int,string> $columns
 * @return array<int,array<string,mixed>>
 */
function dl_reportExportRows(array $rows, array $columns, string $type = ''): array
{
    $out = [];
    foreach ($rows as $row) {
        $item = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            if ($column === 'amount' && $type === 'sales' && (string)($row['status_label'] ?? '') !== 'official') {
                $value = '';
            }
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

    $rows = dl_reportExportRows($data['rows'], $definition['columns'], $type);
    if (!$rows) {
        throw new DlReportUserException('No report rows match the selected filters.');
    }
    // The PDF renders its totals block from this array. A provisional money figure
    // must not surface in an export any more than it does on screen; the official
    // totals are unchanged, so only the display key is dropped here.
    $exportTotals = is_array($data['totals'] ?? null) ? $data['totals'] : [];
    unset($exportTotals['provisional_amount']);
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
        'totals' => $exportTotals,
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
/**
 * Pure PHP forecast aggregation. Rows are grouped per BRANCH, product and shift.
 *
 * WHY THE BRANCH IS IN THE KEY. The previous version grouped on `product:shift` only, so at
 * all-branch scope every branch's sales were averaged into ONE suggestion that no branch could
 * action: measured on tenant 207 (2026-09-01..2026-10-11) that produced 356 rows for 788 real
 * branch x product x shift demand streams, and pid 110 PANDESAL AM - sold at branches 8 and 9 -
 * rendered as a single averaged figure. A production suggestion is actioned at a place.
 *
 * STOCK ON HAND IS THE BRANCH'S OWN. The offset is `ending_qty`, the branch's latest counted
 * closing, not the commissary's unsold finished goods. Crediting a destination branch with its
 * source's undelivered stock (and crediting EVERY branch supplied by that source with the whole
 * of it) is the same class of error P1 removed from the efficiency view.
 *
 * @param array<int,array<string,mixed>> $salesRows
 * @param array<int,array<string,mixed>> $varianceRows
 * @param array<int,array<string,mixed>> $inventoryRows
 * @param array<string,array<string,bool>> $recordedCells Cells the BRANCH actually recorded, keyed
 *        `branch:shift` then date. Without this the denominator is the product's own row-days, which
 *        silently treats "the branch traded and this product was absent" the same as "nobody
 *        reported": Sol measured most streams at 2, 5 or 11 of 14 days, so dividing by row-days
 *        turned "20 units on the 2 days it appeared" into a daily demand of 10.
 * @return array<int,array<string,mixed>>
 */
function dl_forecastDemand(array $salesRows, array $varianceRows, array $inventoryRows, int $window = 14, float $safetyRate = 0.10, ?array $spoilageRates = null, array $recordedCells = []): array
{
    $window = max(1, min(90, $window));
    $variance = [];
    foreach ($varianceRows as $row) {
        $bid = (int)($row['branch_id'] ?? 0);
        $pid = (int)($row['product_id'] ?? 0);
        $shift = strtoupper((string)($row['shift'] ?? 'AM')) === 'PM' ? 'PM' : 'AM';
        $key = $bid . ':' . $pid . ':' . $shift;
        $variance[$key][] = max(0, (int)($row['variance'] ?? 0));
    }

    // The branch's own shelf. One row per branch+product is expected (the latest counted shift);
    // a caller that supplies no ending contributes no offset rather than borrowing one.
    $inventory = [];
    foreach ($inventoryRows as $row) {
        if (!array_key_exists('ending_qty', $row)) {
            continue;
        }
        $bid = (int)($row['branch_id'] ?? 0);
        $pid = (int)($row['product_id'] ?? 0);
        $key = $bid . ':' . $pid;
        $date = (string)($row['ledger_date'] ?? '');
        $inventory[$key]['wastage'][$date] = ($inventory[$key]['wastage'][$date] ?? 0) + max(0, (int)($row['wastage_qty'] ?? 0));
        if (!isset($inventory[$key]['latest_date']) || $date > $inventory[$key]['latest_date']) {
            $inventory[$key]['latest_date'] = $date;
            $inventory[$key]['remaining'] = 0;
        }
        if ($date === ($inventory[$key]['latest_date'] ?? '')) {
            $inventory[$key]['remaining'] += max(0, (int)$row['ending_qty']);
        }
    }

    $groups = [];
    foreach ($salesRows as $row) {
        // A row with NO sales value is normally skipped: it is an unfinalized entry and contributes
        // neither a sale nor a measured zero. A movement-only row is different - it is a real
        // branch+product+shift that carries stock movement but no measured sales, and the owner
        // asked to see exactly those ("all products with movement"). It creates the row without
        // adding anything to the daily series, so it can never dilute the average.
        $hasSales = ($row['sales'] ?? null) !== null;
        $isMovementOnly = !empty($row['movement_only']);
        if (!$hasSales && !$isMovementOnly) {
            continue;
        }
        $bid = (int)($row['branch_id'] ?? 0);
        $pid = (int)($row['product_id'] ?? 0);
        $shift = strtoupper((string)($row['shift'] ?? 'AM')) === 'PM' ? 'PM' : 'AM';
        $key = $bid . ':' . $pid . ':' . $shift;
        $groups[$key]['branch_id'] = $bid;
        $groups[$key]['branch_name'] = (string)($row['branch_name'] ?? '');
        $groups[$key]['source_class'] = (string)($row['source_class'] ?? 'unknown');
        $groups[$key]['source_label'] = (string)($row['source_label'] ?? 'source not declared');
        $groups[$key]['product_id'] = $pid;
        $groups[$key]['sku'] = (string)($row['sku'] ?? '');
        $groups[$key]['product_name'] = (string)($row['product_name'] ?? '');
        $groups[$key]['shift'] = $shift;
        $groups[$key]['movement_rows'] = max((int)($groups[$key]['movement_rows'] ?? 0), (int)($row['movement_rows'] ?? 0));
        if ($hasSales) {
            $date = (string)($row['ledger_date'] ?? '');
            $groups[$key]['daily'][$date] = ($groups[$key]['daily'][$date] ?? 0) + max(0, (int)$row['sales']);
        }
    }

    $result = [];
    foreach ($groups as $key => $group) {
        // A movement-only group has no daily series at all - it was created by a key with no measured
        // sales - so the series is defaulted rather than assumed.
        $dailySeries = is_array($group['daily'] ?? null) ? $group['daily'] : [];
        ksort($dailySeries);
        $daily = array_slice(array_values($dailySeries), -$window);
        $productDays = count($daily);
        // The DENOMINATOR is the branch's recorded days for that shift, not the product's row-days.
        // A branch-cell the branch recorded but the product is absent from is a MEASURED ZERO sale
        // for this product: the branch traded and this product did not move. A cell nobody recorded
        // is unreported, and it is excluded rather than counted as a sale of zero.
        $cellKey = (int)$group['branch_id'] . ':' . $group['shift'];
        $recordedCount = count($recordedCells[$cellKey] ?? []);
        // A group with NO daily series at all is a movement-only key: there is no official sales row
        // for this branch, product and shift anywhere in the window. The branch recording OTHER days
        // does not turn this product's silence into a measured zero, so it gets no denominator and
        // its suggestion is forced to 0 below - otherwise a movement row would read as demand
        // (Sol F4). Only a group that HAS a measured series may use the branch's recorded days.
        $denominator = $daily === [] ? 0 : ($recordedCount > 0 ? $recordedCount : $productDays);
        $days = max(1, $denominator);
        $average = array_sum($daily) / $days;
        $varianceValues = $variance[$key] ?? [];
        $varianceAdjustment = $varianceValues ? array_sum($varianceValues) / count($varianceValues) : 0.0;
        $stockKey = (int)$group['branch_id'] . ':' . (int)$group['product_id'];
        $wastageValues = array_values($inventory[$stockKey]['wastage'] ?? []);
        $productWastage = $wastageValues ? array_sum($wastageValues) / count($wastageValues) : 0.0;
        $projected = $average + $varianceAdjustment;
        $safety = $projected * max(0.0, min(1.0, $safetyRate));
        $remaining = (int)($inventory[$stockKey]['remaining'] ?? 0);
        $result[] = [
            'branch_id' => (int)$group['branch_id'],
            'branch_name' => (string)$group['branch_name'],
            'source_class' => (string)$group['source_class'],
            'source_label' => (string)$group['source_label'],
            'product_id' => $group['product_id'],
            'sku' => $group['sku'],
            'product_name' => $group['product_name'],
            'shift' => $group['shift'],
            // sample_days is the DENOMINATOR the average actually divided by; product_days is how many
            // of those days the product itself had a row. The difference is measured zero-sales days.
            // It is the RAW denominator, not `max(1, ...)`: that clamp is only for the division below,
            // and reporting it here made a movement-only row claim 1 sample day, which silently
            // disarmed the "no measured sales => no suggestion" guard further down (Sol F8).
            'sample_days' => $denominator,
            'product_days' => $productDays,
            'window_days' => $window,
            'zero_sale_days' => max(0, $denominator - $productDays),
            'coverage_state' => $productDays === 0
                ? 'no sales recorded for this branch, product and shift in this window'
                : ($days >= $window
                    ? ''
                    : sprintf('the branch recorded %d of %d day(s) in this window, so the average is per recorded day', $days, $window)),
            // Why a row can carry no average: it is a movement-only key, so a 0 here means "no sales
            // were measured for this branch, product and shift in this window", NOT "demand was
            // measured as zero". The page states which, so a 0 suggestion is never ambiguous.
            'sales_basis' => $daily === []
                ? 'movement only: no measured sales for this branch, product and shift in this window'
                : '',
            'movement_rows' => (int)($group['movement_rows'] ?? 0),
            'average_sales' => round($average, 2),
            'variance_adjustment' => round($varianceAdjustment, 2),
            'product_wastage' => $productWastage,
            'wastage_adjustment' => 0.0,
            'projected_demand' => round($projected, 2),
            'safety_margin' => round($safety, 2),
            'stock_on_hand' => $remaining,
            'inventory_applied' => 0,
            'suggested_production' => 0,
        ];
    }
    usort($result, static fn(array $a, array $b): int => [$a['branch_name'], $a['product_name'], $a['shift']] <=> [$b['branch_name'], $b['product_name'], $b['shift']]);

    // Commissary wastage and remaining stock are product-level values, not
    // shift-level values. Distribute wastage by each shift's demand share and
    // consume remaining stock once (AM before PM) instead of applying both in
    // full to every shift.
    // Stock and spoilage are per BRANCH and product, not per product. Two branches supplied by the
    // same commissary hold DIFFERENT stock, and applying one branch's shelf to another branch's
    // suggestion is the same class of error P1 removed from the efficiency view.
    $branchProductIndexes = [];
    foreach ($result as $index => $row) {
        $branchProductIndexes[(int)$row['branch_id'] . ':' . (int)$row['product_id']][] = $index;
    }
    foreach ($branchProductIndexes as $indexes) {
        $pid = (int)$result[$indexes[0]]['product_id'];
        // Per-product spoilage for the standard gross-up. A rate at or above
        // 100% cannot be grossed up (the target would be unbounded), so it is
        // treated as a stated gap rather than silently clamped or invented.
        $spoilageRate = null;
        $spoilageAvailable = false;
        if (is_array($spoilageRates) && array_key_exists($pid, $spoilageRates)) {
            $candidate = $spoilageRates[$pid];
            if (is_numeric($candidate) && (float)$candidate >= 0.0 && (float)$candidate < 1.0) {
                $spoilageRate = (float)$candidate;
                $spoilageAvailable = true;
            }
        }
        $demandTotal = array_sum(array_map(static fn(int $index): float => (float)$result[$index]['average_sales'], $indexes));
        $remainingAvailable = max(0, (int)$result[$indexes[0]]['stock_on_hand']);
        foreach ($indexes as $index) {
            $share = $demandTotal > 0.0
                ? (float)$result[$index]['average_sales'] / $demandTotal
                : 1.0 / count($indexes);
            $wastageAdjustment = (float)$result[$index]['product_wastage'] * $share;
            if ($spoilageRates === null) {
                // Legacy flat safety margin (kept for the pure forecast contract).
                $expectedSales = (float)$result[$index]['average_sales']
                    + (float)$result[$index]['variance_adjustment']
                    + $wastageAdjustment;
                $safety = $expectedSales * max(0.0, min(1.0, $safetyRate));
                $target = $expectedSales + $safety;
                $grossUp = $safety;
            } else {
                // Standard gross-up for expected loss:
                //   target production = expected sales / (1 - spoilage rate)
                // where the rate is the product's own wastage / produced, taken
                // from the production-efficiency view rather than redefined here.
                $expectedSales = (float)$result[$index]['average_sales']
                    + (float)$result[$index]['variance_adjustment'];
                $target = $spoilageAvailable ? $expectedSales / (1.0 - $spoilageRate) : $expectedSales;
                $grossUp = $target - $expectedSales;
            }
            $grossNeed = max(0, (int)ceil($target));
            // A row with NO measured sales must never be given a production number. Its zero average
            // means "no sales were recorded", not "demand was measured as zero", and a variance flag
            // on such a row was previously turned into expected demand. Sol F8 measured 14 rows
            // issuing positive suggestions on nothing but a variance adjustment.
            if ((int)$result[$index]['sample_days'] === 0) {
                $expectedSales = 0.0;
                $target = 0.0;
                $grossUp = 0.0;
                $grossNeed = 0;
            }
            $inventoryApplied = min($remainingAvailable, $grossNeed);
            $remainingAvailable -= $inventoryApplied;
            $result[$index]['wastage_adjustment'] = round($wastageAdjustment, 2);
            $result[$index]['expected_sales'] = round($expectedSales, 2);
            $result[$index]['spoilage_rate'] = $spoilageAvailable ? round($spoilageRate * 100, 2) : null;
            $result[$index]['spoilage_available'] = $spoilageAvailable;
            $result[$index]['spoilage_gross_up'] = round($grossUp, 2);
            $result[$index]['target_production'] = round($target, 2);
            $result[$index]['projected_demand'] = round($expectedSales, 2);
            $result[$index]['safety_margin'] = round($grossUp, 2);
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

    // Say WHERE each suggestion acts and whether it is a production requirement or a supply
    // requirement. The classifier is the same evidence-based one the production-efficiency view
    // uses, so the two pages cannot disagree about what a branch is.
    $forecastSources = dl_efficiencySupplySources($db, $filters['accessible_branch_ids'], $historyFrom, $historyTo);
    foreach ($sales as &$salesRow) {
        $sourceInfo = $forecastSources[(int)($salesRow['branch_id'] ?? 0)] ?? null;
        $salesRow['source_class'] = (string)($sourceInfo['class'] ?? 'unknown');
        $salesRow['source_label'] = (string)($sourceInfo['label'] ?? 'source not declared');
    }
    unset($salesRow);

    $ids = $filters['accessible_branch_ids'];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $varianceSql = "SELECT branch_id, product_id, shift, variance FROM dl_variance_flags
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

    // ── Stock on hand: the BRANCH's own latest counted ending, at or BEFORE the window end ──
    // The lookup deliberately has no lower bound. Sol F4: restricting it to the window meant a valid
    // count from the day before the window read as ZERO stock while a 9-day-old count inside the
    // window was accepted, and neither row carried an as-of date to tell them apart.
    $inventoryBranchIds = $filters['branch_id'] > 0 ? [(int)$filters['branch_id']] : $ids;
    $inventoryMarks = implode(',', array_fill(0, count($inventoryBranchIds), '?'));
    $inventorySql = "SELECT latest.branch_id, latest.product_id, latest.ledger_date,
                            latest.bal_end AS ending_qty
                       FROM dl_daily_ledger latest
                       INNER JOIN (
                            SELECT dl.branch_id, dl.product_id,
                                   MAX(CONCAT(dl.ledger_date, ':', COALESCE(dl.shift, ''))) AS latest_key
                              FROM dl_daily_ledger dl
                             WHERE dl.ledger_date <= ?
                               AND dl.bal_end IS NOT NULL
                               AND dl.branch_id IN ({$inventoryMarks})
                             GROUP BY dl.branch_id, dl.product_id
                       ) newest
                         ON newest.branch_id = latest.branch_id
                        AND newest.product_id = latest.product_id
                        AND newest.latest_key = CONCAT(latest.ledger_date, ':', COALESCE(latest.shift, ''))
                      WHERE latest.ledger_date <= ?
                        AND latest.bal_end IS NOT NULL";
    $inventoryBind = array_merge([$historyTo], $inventoryBranchIds, [$historyTo]);
    if ($filters['product_id'] > 0) {
        $inventorySql .= ' AND latest.product_id = ?';
        $inventoryBind[] = $filters['product_id'];
    }
    $stmt = $db->prepare($inventorySql);
    $stmt->execute($inventoryBind);
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stockAsOf = [];
    foreach ($inventory as $invRow) {
        $stockAsOf[(int)$invRow['branch_id'] . ':' . (int)$invRow['product_id']] = (string)$invRow['ledger_date'];
    }

    // ── The cells each BRANCH recorded, so a product absent from a traded day is a MEASURED zero ──
    // Official-only, exactly like the sales rows that form the numerator: mixing an inclusive
    // denominator with an official numerator would divide two populations.
    $cellStmt = $db->prepare(
        "SELECT DISTINCT dl.branch_id, dl.ledger_date, dl.shift
           FROM dl_daily_ledger dl
           LEFT JOIN dl_ledger_shift_status ss
             ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
            AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
          WHERE dl.ledger_date BETWEEN ? AND ?
            AND dl.branch_id IN ({$marks})"
        . dl_officialOnlySqlPredicate('dl', 'ss')
    );
    $cellStmt->execute(array_merge([$historyFrom, $historyTo], $ids));
    $recordedCells = [];
    foreach ($cellStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cellRow) {
        $cellShift = strtoupper((string)($cellRow['shift'] ?? 'AM')) === 'PM' ? 'PM' : 'AM';
        $recordedCells[(int)$cellRow['branch_id'] . ':' . $cellShift][(string)$cellRow['ledger_date']] = true;
    }

    // ── Every branch+product+shift that MOVED in the window ──
    // The forecast is otherwise driven by official sales rows, so a pair that carried movement but no
    // sales produced NO row at all. Measured on tenant 207 (2026-09-01..2026-10-11): 420 branch-product
    // pairs carried movement and 209 of them sold nothing, so those simply vanished from the page - an
    // owner could not see dead stock, nor a product a branch stocks and never sells.
    // Owner: "all products with movement".
    // Scoped to the SELECTED branch: dl_reportSalesData() narrows its own rows with
    // `AND dl.branch_id = ?`, so a movement query that used the whole accessible set would add rows
    // for every branch the user can reach and the branch filter would appear not to work.
    $movementIds = ($filters['branch_id'] ?? 0) > 0 ? [(int)$filters['branch_id']] : $ids;
    $movementMarks = implode(',', array_fill(0, count($movementIds), '?'));
    $movementStmt = $db->prepare(
        "SELECT dl.branch_id, dl.product_id, dl.shift, p.sku, p.name AS product_name,
                COUNT(*) AS movement_rows
           FROM dl_daily_ledger dl
           INNER JOIN dl_products p ON p.id = dl.product_id
          WHERE dl.ledger_date BETWEEN ? AND ?
            AND dl.branch_id IN ({$movementMarks})"
        . ($filters['product_id'] > 0 ? ' AND dl.product_id = ?' : '')
        . ($filters['shift'] !== '' ? ' AND dl.shift = ?' : '')
        . ' GROUP BY dl.branch_id, dl.product_id, dl.shift, p.sku, p.name'
    );
    $movementBind = array_merge([$historyFrom, $historyTo], $movementIds);
    if ($filters['product_id'] > 0) {
        $movementBind[] = $filters['product_id'];
    }
    if ($filters['shift'] !== '') {
        $movementBind[] = $filters['shift'];
    }
    $movementStmt->execute($movementBind);

    $keysWithMeasuredSales = [];
    foreach ($sales as $salesRow) {
        $keysWithMeasuredSales[(int)($salesRow['branch_id'] ?? 0) . ':' . (int)($salesRow['product_id'] ?? 0)
            . ':' . strtoupper((string)($salesRow['shift'] ?? ''))] = true;
    }
    $addedMovementKeys = [];
    foreach ($movementStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $movedRow) {
        $movedShift = strtoupper((string)($movedRow['shift'] ?? 'AM')) === 'PM' ? 'PM' : 'AM';
        $movedKey = (int)$movedRow['branch_id'] . ':' . (int)$movedRow['product_id'] . ':' . $movedShift;
        if (isset($keysWithMeasuredSales[$movedKey]) || isset($addedMovementKeys[$movedKey])) {
            continue;
        }
        $addedMovementKeys[$movedKey] = true;
        $movedSource = $forecastSources[(int)$movedRow['branch_id']] ?? null;
        // sales => null so it contributes NOTHING to the daily series; movement_only so the row is
        // still created. A 0 average on this row means "no sales were measured", which the page says.
        $sales[] = [
            'branch_id' => (int)$movedRow['branch_id'],
            'branch_name' => (string)($movedSource['name'] ?? ''),
            'product_id' => (int)$movedRow['product_id'],
            'sku' => (string)($movedRow['sku'] ?? ''),
            'product_name' => (string)($movedRow['product_name'] ?? ''),
            'shift' => $movedShift,
            'ledger_date' => '',
            'sales' => null,
            'movement_only' => true,
            'movement_rows' => (int)$movedRow['movement_rows'],
            'source_class' => (string)($movedSource['class'] ?? 'unknown'),
            'source_label' => (string)($movedSource['label'] ?? 'source not declared'),
        ];
    }

    // Reuse the production-efficiency view's own spoilage rate (wasted / produced)
    // instead of inventing a second definition. The efficiency aggregate runs over
    // the SAME history window as the forecast, so the gross-up and the efficiency
    // table cannot disagree about a product's rate. A product with no measurable
    // rate is simply absent here; the forecast states that gap explicitly.
    $spoilageRates = [];
    $efficiency = dl_productionEfficiencyData($db, $historyFilters);
    foreach ($efficiency['products'] as $efficiencyProduct) {
        $efficiencyPid = (int)($efficiencyProduct['product_id'] ?? 0);
        $ratePct = $efficiencyProduct['wastage_rate_pct'] ?? null;
        if ($efficiencyPid > 0 && $ratePct !== null) {
            $spoilageRates[$efficiencyPid] = (float)$ratePct / 100.0;
        }
    }

    $forecastRows = dl_forecastDemand($sales, $variance, $inventory, $window, 0.10, $spoilageRates, $recordedCells);

    // Stamp the age of the stock each row applied. An undated level cannot be judged stale or
    // current, which is why a 9-day-old count and a count from the day before the window were
    // previously indistinguishable (Sol F4).
    foreach ($forecastRows as &$forecastRow) {
        $stockKey = (int)$forecastRow['branch_id'] . ':' . (int)$forecastRow['product_id'];
        $asOf = $stockAsOf[$stockKey] ?? '';
        $ageDays = null;
        if ($asOf !== '') {
            try {
                $ageDays = (new DateTimeImmutable($historyTo))->diff(new DateTimeImmutable($asOf))->days;
            } catch (Throwable) {
                $ageDays = null;
            }
        }
        $forecastRow['stock_as_of'] = $asOf;
        $forecastRow['stock_age_days'] = $ageDays;
        $forecastRow['stock_state'] = $asOf === ''
            ? 'no counted ending recorded for this branch and product'
            : ($ageDays !== null && $ageDays > $window
                ? sprintf('stale: last counted %d day(s) before the window end', $ageDays)
                : '');
    }
    unset($forecastRow);

    return $forecastRows;
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
const DL_OVERVIEW_SORT_FIELDS = ['amount', 'units', 'cumulative'];
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
 * Whether the period's activity is still awaiting an ending count.
 *
 * The ranked product tables drop pending rows, so an all-pending day renders an empty
 * table. Reporting "No sales recorded" there states a business result the data cannot
 * support: nothing has been finalised yet, so nothing has been ruled out. This counts
 * the ledger rows in scope so the overview can tell "nobody has finished counting"
 * apart from "there was no activity at all".
 *
 * @param array<string,mixed> $filters
 * @return array{rows_total:int,pending:int,completed:int}
 */
function dl_overviewLedgerCompleteness(DatabaseContract $db, array $filters): array
{
    $ids = array_values(array_filter(array_map('intval', $filters['accessible_branch_ids'] ?? []), static fn(int $id): bool => $id > 0));
    if ($ids === []) {
        return ['rows_total' => 0, 'pending' => 0, 'completed' => 0];
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT COUNT(*) AS rows_total,
                   SUM(CASE WHEN dl.bal_end IS NULL THEN 1 ELSE 0 END) AS pending,
                   SUM(CASE WHEN dl.bal_end IS NOT NULL THEN 1 ELSE 0 END) AS completed
              FROM dl_daily_ledger dl
             WHERE dl.ledger_date BETWEEN ? AND ?
               AND dl.branch_id IN ({$marks})";
    $bind = array_merge([$filters['date_from'] ?? '', $filters['date_to'] ?? ''], $ids);
    if (($filters['branch_id'] ?? 0) > 0) {
        $sql .= ' AND dl.branch_id = ?';
        $bind[] = (int)$filters['branch_id'];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'rows_total' => (int)($row['rows_total'] ?? 0),
        'pending' => (int)($row['pending'] ?? 0),
        'completed' => (int)($row['completed'] ?? 0),
    ];
}

/**
 * Per-branch activity, completeness, and unresolved variance signals for the
 * Business Health view. A missing map entry means no ledger activity, not zero
 * performance. Delivery variances are included because the canonical
 * dl_variance_flags table records them with kind=delivery.
 *
 * @param array<string,mixed> $filters
 * @return array<int,array{rows_total:int,pending:int,completed:int,unresolved_variances:int,absolute_variance:int}>
 */
function dl_overviewBranchSignals(DatabaseContract $db, array $filters): array
{
    $ids = array_values(array_filter(array_map('intval', $filters['accessible_branch_ids'] ?? []), static fn(int $id): bool => $id > 0));
    if (($filters['branch_id'] ?? 0) > 0) {
        $ids = in_array((int)$filters['branch_id'], $ids, true) ? [(int)$filters['branch_id']] : [];
    }
    if ($ids === []) {
        return [];
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $bind = array_merge([$filters['date_from'] ?? '', $filters['date_to'] ?? ''], $ids);
    $ledger = $db->prepare(
        "SELECT branch_id, COUNT(*) rows_total,
                SUM(CASE WHEN bal_end IS NULL THEN 1 ELSE 0 END) pending,
                SUM(CASE WHEN bal_end IS NOT NULL THEN 1 ELSE 0 END) completed
           FROM dl_daily_ledger
          WHERE ledger_date BETWEEN ? AND ? AND branch_id IN ({$marks})
          GROUP BY branch_id"
    );
    $ledger->execute($bind);
    $signals = [];
    foreach ($ledger->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $signals[(int)$row['branch_id']] = [
            'rows_total' => (int)$row['rows_total'],
            'pending' => (int)$row['pending'],
            'completed' => (int)$row['completed'],
            'unresolved_variances' => 0,
            'absolute_variance' => 0,
        ];
    }

    $variance = $db->prepare(
        "SELECT branch_id, COUNT(*) unresolved_variances,
                COALESCE(SUM(ABS(variance)), 0) absolute_variance
           FROM dl_variance_flags
          WHERE ledger_date BETWEEN ? AND ? AND branch_id IN ({$marks})
            AND resolution_status <> 'corrected'
          GROUP BY branch_id"
    );
    $variance->execute($bind);
    foreach ($variance->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int)$row['branch_id'];
        $signals[$id] ??= ['rows_total' => 0, 'pending' => 0, 'completed' => 0, 'unresolved_variances' => 0, 'absolute_variance' => 0];
        $signals[$id]['unresolved_variances'] = (int)$row['unresolved_variances'];
        $signals[$id]['absolute_variance'] = (int)$row['absolute_variance'];
    }

    return $signals;
}

/**
 * Official sales total for a comparison period. This uses the same quantity,
 * amount, provisional, and pending predicates as the overview total.
 *
 * @param array<string,mixed> $filters
 */
function dl_overviewOfficialSalesTotal(DatabaseContract $db, array $filters): float
{
    $ids = array_values(array_filter(array_map('intval', $filters['accessible_branch_ids'] ?? []), static fn(int $id): bool => $id > 0));
    if (($filters['branch_id'] ?? 0) > 0) {
        $ids = in_array((int)$filters['branch_id'], $ids, true) ? [(int)$filters['branch_id']] : [];
    }
    if ($ids === []) {
        return 0.0;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $amount = dl_ledgerSalesAmountSql('dl');
    $sql = 'SELECT COALESCE(SUM(CASE WHEN ' . dl_provisionalSqlExpr('dl', 'ss')
        . ' THEN 0 ELSE ' . $amount . " END), 0)
          FROM dl_daily_ledger dl
          LEFT JOIN dl_ledger_shift_status ss
            ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
           AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
         WHERE dl.ledger_date BETWEEN ? AND ? AND dl.branch_id IN ({$marks})"
        . dl_overviewPendingPredicate('dl');
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$filters['date_from'] ?? '', $filters['date_to'] ?? ''], $ids));
    return (float)$stmt->fetchColumn();
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
    // Money is OFFICIAL ONLY: a provisional (unfinalized/derived) row contributes
    // units for operational visibility but never a sales amount. The units stay
    // inclusive so the outstanding work is still visible in the ranking.
    $officialAmount = 'CASE WHEN ' . dl_provisionalSqlExpr('dl', 'ss') . " THEN 0 ELSE {$amount} END";
    $sql = "SELECT p.id, p.name, p.sku, p.product_category,
                   COALESCE(SUM({$qty}), 0) AS units,
                   COALESCE(SUM({$officialAmount}), 0) AS amount,
                   COUNT(DISTINCT dl.branch_id) AS branch_count
              FROM dl_daily_ledger dl
              INNER JOIN dl_products p ON p.id = dl.product_id
              LEFT JOIN dl_ledger_shift_status ss
                     ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
                    AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
             WHERE dl.ledger_date BETWEEN ? AND ?
               AND dl.branch_id IN ({$marks})";
    $bind = array_merge([$filters['date_from'] ?? '', $filters['date_to'] ?? ''], $ids);
    if (($filters['branch_id'] ?? 0) > 0) {
        $sql .= ' AND dl.branch_id = ?';
        $bind[] = (int)$filters['branch_id'];
    }
    if (dl_overviewPendingRowsMode($filters['pending_rows_mode'] ?? null) === 'exclude') {
        // "Official rows only" must MEAN official. The pending predicate alone removes nothing from a
        // units total - a pending row's quantity is NULL and SUM() already ignores it, measured - so
        // the 2,697 provisional rows carrying 16,348 units in this window survived it.
        $sql .= dl_officialOnlySqlPredicate('dl', 'ss');
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
    // Same official-only money rule as the overall totals: provisional rows keep
    // their units for visibility but contribute no sales amount.
    $officialAmount = 'CASE WHEN ' . dl_provisionalSqlExpr('dl', 'ss') . " THEN 0 ELSE {$amount} END";
    $sql = "SELECT dl.branch_id, b.name AS branch_name,
                   p.id AS product_id, p.name, p.sku, p.product_category,
                   COALESCE(SUM({$qty}), 0) AS units,
                   COALESCE(SUM({$officialAmount}), 0) AS amount
              FROM dl_daily_ledger dl
              INNER JOIN dl_branches b ON b.id = dl.branch_id
              INNER JOIN dl_products p ON p.id = dl.product_id
              LEFT JOIN dl_ledger_shift_status ss
                     ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
                    AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
             WHERE dl.ledger_date BETWEEN ? AND ?
               AND dl.branch_id IN ({$marks})";
    $bind = array_merge([$filters['date_from'] ?? '', $filters['date_to'] ?? ''], $ids);
    if (($filters['branch_id'] ?? 0) > 0) {
        $sql .= ' AND dl.branch_id = ?';
        $bind[] = (int)$filters['branch_id'];
    }
    if (dl_overviewPendingRowsMode($filters['pending_rows_mode'] ?? null) === 'exclude') {
        // Same official definition as the overall product totals, so the two tables on one page can
        // never disagree about which rows the basis admitted.
        $sql .= dl_officialOnlySqlPredicate('dl', 'ss');
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
    // 'cumulative' is offered so the cumulative-share column can be clicked. The cumulative figure is
    // monotonic by construction - it is the running total in ranking order - so it cannot be its own
    // sort key. Clicking that column therefore chooses the DIRECTION the running total is built from,
    // which is the ranking basis (amount/units) in that direction. Mapping it here rather than
    // reading $a['cumulative'] keeps it from sorting on a key the totals rows do not carry.
    if ($by === 'cumulative') {
        $by = 'amount';
    }
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

// ─── Production Efficiency (own view) ───────────────────────────────────
// Read-only aggregates for /daily-ledger/admin/production-efficiency. All SQL
// is set-based and MySQL 5.7 compatible (no CTE, window function, or JSON_TABLE).

/**
 * Supply-source classification for the branches in scope, decided from EVIDENCE.
 *
 * Sol's review (docs/reviews/sol-production-attribution-2026-10-11.md) rejected a
 * configuration-only predicate and the objection is correct: `default_supply_mode='hybrid'`
 * says a branch MAY produce its own goods, not that it DOES. Branch 8 is hybrid and its
 * production evidence is zero, so calling it a producer would fabricate output.
 *
 * Measured on tenant 207 for 2026-09-01..2026-10-11 - the evidence is not ambiguous:
 *   dl_commissary_product_ledger with produced_qty > 0 : branch 18 = 69 rows; all others 0
 *   dl_production_runs                                 : branch 18 = 41 rows; all others 0
 *   posted receivings, origin_type='commissary'        : branches 8, 9, 11, 18
 * So branch 18 produces; branches 8, 9 and 11 are supplied and produce nothing.
 *
 * Class per branch, from what was RECORDED:
 *   producer - production evidence, no commissary receipts  -> it makes its own goods
 *   supplied - commissary receipts, no production evidence  -> it is delivered to
 *   mixed    - both                                        -> NEVER forced to one side
 *   unknown  - neither; no source is declared anywhere
 *
 * The configuration is carried alongside (`mode`, `assigned`) so the page can show WHY a
 * branch was classified as it was, without treating the configuration as the finding.
 *
 * @param array<int,int> $ids branches in scope
 * @return array<int,array<string,mixed>> keyed by branch id
 */
function dl_efficiencySupplySources(DatabaseContract $db, array $ids, string $dateFrom, string $dateTo): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
    if ($ids === [] || $dateFrom === '' || $dateTo === '') {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));

    $branches = [];
    $stmt = $db->prepare(
        "SELECT id, name, is_commissary, default_supply_mode, assigned_commissary_id
           FROM dl_branches WHERE id IN ({$marks})"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int)$row['id'];
        $branches[$id] = [
            'id' => $id,
            'name' => (string)($row['name'] ?? ''),
            'is_commissary' => (int)($row['is_commissary'] ?? 0) === 1,
            'mode' => (string)($row['default_supply_mode'] ?? ''),
            'assigned' => $row['assigned_commissary_id'] !== null ? (int)$row['assigned_commissary_id'] : null,
            'production_rows' => 0,
            'runs' => 0,
            'commissary_qty' => 0,
            'transfer_qty' => 0,
            'class' => 'unknown',
            'label' => 'source not declared',
        ];
    }
    if ($branches === []) {
        return [];
    }

    // Production evidence, from both ledgers that can carry it. A branch that ran a
    // production run has produced even if its product ledger rows are not written yet.
    $prodStmt = $db->prepare(
        "SELECT commissary_branch_id AS id, COUNT(*) AS rows_n
           FROM dl_commissary_product_ledger
          WHERE ledger_date BETWEEN ? AND ? AND produced_qty > 0
            AND commissary_branch_id IN ({$marks})
          GROUP BY commissary_branch_id"
    );
    $prodStmt->execute(array_merge([$dateFrom, $dateTo], $ids));
    foreach ($prodStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int)$row['id'];
        if (isset($branches[$id])) {
            $branches[$id]['production_rows'] = (int)$row['rows_n'];
        }
    }

    $runStmt = $db->prepare(
        "SELECT destination_branch_id AS id, COUNT(*) AS rows_n
           FROM dl_production_runs
          WHERE ledger_date BETWEEN ? AND ?
            AND destination_branch_id IN ({$marks})
          GROUP BY destination_branch_id"
    );
    $runStmt->execute(array_merge([$dateFrom, $dateTo], $ids));
    foreach ($runStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int)$row['id'];
        if (isset($branches[$id])) {
            $branches[$id]['runs'] = (int)$row['rows_n'];
        }
    }

    // Receipt evidence, split by who sent the stock. origin_type='branch' is an internal
    // transfer: it is inflow to the destination and NOT new stock for the network.
    $rcvStmt = $db->prepare(
        "SELECT br.branch_id AS id, br.origin_type AS origin,
                COALESCE(SUM(bri.quantity_received), 0) AS qty
           FROM dl_branch_receivings br
           INNER JOIN dl_branch_receiving_items bri ON bri.receiving_id = br.id
          WHERE br.status = 'posted'
            AND br.received_ledger_date BETWEEN ? AND ?
            AND br.branch_id IN ({$marks})
          GROUP BY br.branch_id, br.origin_type"
    );
    $rcvStmt->execute(array_merge([$dateFrom, $dateTo], $ids));
    foreach ($rcvStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int)$row['id'];
        if (!isset($branches[$id])) {
            continue;
        }
        $qty = (int)$row['qty'];
        if ((string)$row['origin'] === 'branch') {
            $branches[$id]['transfer_qty'] += $qty;
        } else {
            $branches[$id]['commissary_qty'] += $qty;
        }
    }

    foreach ($branches as $id => $info) {
        $produces = $info['production_rows'] > 0 || $info['runs'] > 0;
        $supplied = $info['commissary_qty'] > 0;
        if ($produces && $supplied) {
            $class = 'mixed';
        } elseif ($produces) {
            $class = 'producer';
        } elseif ($supplied) {
            $class = 'supplied';
        } else {
            $class = 'unknown';
        }
        $branches[$id]['class'] = $class;
        $branches[$id]['label'] = match ($class) {
            'producer' => 'produces its own output',
            'supplied' => 'supplied by ' . ($branches[(int)($info['assigned'] ?? 0)]['name'] ?? 'a commissary'),
            'mixed' => 'both own production and supplied stock',
            default => 'source not declared',
        };
    }

    // Fill in the supplying branch's NAME. The commissary is often OUTSIDE the selected scope (a
    // single retail branch is selected but its commissary is not), so its name is resolved with one
    // extra lookup rather than falling back to "branch 18" on the page an owner reads.
    $missingNames = [];
    foreach ($branches as $info) {
        $assigned = $info['assigned'];
        if ($assigned !== null && !isset($branches[$assigned])) {
            $missingNames[$assigned] = true;
        }
    }
    $externalNames = [];
    if ($missingNames !== []) {
        $missingIds = array_keys($missingNames);
        $nameMarks = implode(',', array_fill(0, count($missingIds), '?'));
        $nameStmt = $db->prepare("SELECT id, name FROM dl_branches WHERE id IN ({$nameMarks})");
        $nameStmt->execute($missingIds);
        foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $nameRow) {
            $externalNames[(int)$nameRow['id']] = (string)($nameRow['name'] ?? '');
        }
    }

    foreach ($branches as $id => $info) {
        if ($info['class'] !== 'supplied') {
            continue;
        }
        $assigned = $info['assigned'];
        if ($assigned === null) {
            $branches[$id]['label'] = 'supplied by a commissary';
            continue;
        }
        $sourceName = $branches[$assigned]['name'] ?? ($externalNames[$assigned] ?? ('branch ' . $assigned));
        $branches[$id]['label'] = 'supplied by ' . $sourceName;
    }

    return $branches;
}

/**
 * SQL predicate dropping PROVISIONAL rows, so an "official only" basis is the module's single
 * definition of official (dl_rowIsProvisional / dl_provisionalSqlExpr) and not a second one.
 *
 * `dl_overviewPendingPredicate()` is NOT enough on its own, and that is measured: it drops only
 * `bal_end IS NULL`, whose quantity is NULL, so SUM() already ignored those rows. On tenant 207
 * (2026-09-01..2026-10-11) the pending filter changes the units total by ZERO (102,615 either way)
 * while 2,697 rows are provisional and carry 16,348 units - 2,112 of them because the SHIFT was
 * never finalized. A control labelled "Official rows only" that removes nothing is a lie of the
 * same kind as the ratios P1 removed.
 *
 * WRITTEN AS `COALESCE(expr, 0) = 0`, NOT `NOT (expr)`, AND THAT IS LOAD-BEARING. The twin's first
 * term is `end_source IN (...)`; for a row whose end_source is NULL that evaluates to NULL, so
 * `NOT (FALSE OR NULL OR FALSE OR FALSE)` is NULL and WHERE drops the row. Measured: `AND NOT (...)`
 * matched 0 of 14,401 rows where 11,704 were official - it emptied the Products page completely.
 * COALESCE(...,0) = 0 keeps the twin in lockstep with dl_rowIsProvisional(), which treats a NULL
 * end_source as NOT provisional (it is only `in_array(null, [...])` = false).
 *
 * The caller must have LEFT JOINed dl_ledger_shift_status under $shiftAlias.
 */
function dl_officialOnlySqlPredicate(string $alias = 'dl', string $shiftAlias = 'ss'): string
{
    return ' AND COALESCE(' . dl_provisionalSqlExpr($alias, $shiftAlias) . ', 0) = 0';
}

/**
 * How many of a branch's rows in this window are provisional, and how many units they carry.
 * Stated on the page so an owner can see what an official-only basis left out.
 *
 * @return array{rows:int,units:int}
 */
function dl_provisionalExclusion(DatabaseContract $db, array $ids, string $dateFrom, string $dateTo): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
    if ($ids === [] || $dateFrom === '' || $dateTo === '') {
        return ['rows' => 0, 'units' => 0];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $qty = dl_ledgerSalesQuantitySql('dl');
    $prov = dl_provisionalSqlExpr('dl', 'ss');
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(CASE WHEN {$prov} THEN 1 ELSE 0 END), 0) AS rows_n,
                COALESCE(SUM(CASE WHEN {$prov} THEN COALESCE({$qty}, 0) ELSE 0 END), 0) AS units
           FROM dl_daily_ledger dl
           LEFT JOIN dl_ledger_shift_status ss
             ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
            AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
          WHERE dl.ledger_date BETWEEN ? AND ? AND dl.branch_id IN ({$marks})"
    );
    $stmt->execute(array_merge([$dateFrom, $dateTo], $ids));
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return ['rows' => (int)($row['rows_n'] ?? 0), 'units' => (int)($row['units'] ?? 0)];
}

/**
 * How a pullout/return reason maps onto the efficiency panel.
 *
 * This is deliberately NOT a new vocabulary: it labels the result of
 * dl_classifyPulloutReturnReason(), which is the module's existing owner-ruling
 * on what counts as loss. 'wastage' is spoilage/damage; 'consumed_no_delta' is
 * goods consumed at the branch (staff meal, sampling, testing, promo, donation)
 * that never came back; everything else returned saleable stock.
 */
function dl_productionEfficiencyClassificationLabel(string $classification): string
{
    return match ($classification) {
        'wastage' => 'Wastage (spoilage/damage)',
        'consumed_no_delta' => 'Consumed at branch (not waste, not stock)',
        default => 'Returned saleable (back to stock)',
    };
}

/**
 * Direction of a per-product trend against a comparable prior figure.
 *
 * 'none' means there is NO comparable prior value - it is a stated gap, never
 * zero movement. 'flat' means the two figures are equal within a small
 * tolerance so rounding noise does not manufacture a direction.
 */
function dl_efficiencyTrendDirection(mixed $current, mixed $prior): string
{
    if (!is_numeric($current) || !is_numeric($prior)) {
        return 'none';
    }
    $current = (float)$current;
    $prior = (float)$prior;
    if ($current > $prior + 0.005) {
        return 'up';
    }
    if ($current < $prior - 0.005) {
        return 'down';
    }

    return 'flat';
}

/**
 * The direction of "good" is METRIC-DEPENDENT on the efficiency view:
 *
 *   gain metrics  yield %, sell-through %  up is good  -> green up,   red down
 *   loss metrics  wastage %, residual %     up is bad   -> red up,     green down
 *
 * The returned class carries the metric slug and the direction, so one single
 * inversion here cannot paint a rising wastage rate green: the slug lets a test
 * assert that the loss metrics use the opposite mapping from the gain metrics.
 */
function dl_efficiencyTrendClass(string $metric, string $direction): string
{
    $slug = match ($metric) {
        'yield' => 'yield-rate',
        'sell_through' => 'sell-through-rate',
        'wastage' => 'wastage-rate',
        'shrinkage' => 'residual-rate',
        default => 'rate',
    };
    if ($direction !== 'up' && $direction !== 'down') {
        return $slug . ' trend-' . $direction . ' text-slate-400';
    }
    $isGain = in_array($metric, ['yield', 'sell_through'], true);
    $improving = $isGain ? ($direction === 'up') : ($direction === 'down');

    return $slug . ' trend-' . $direction . ' ' . ($improving ? 'text-emerald-700' : 'text-rose-700');
}

function dl_efficiencyTrendArrow(string $direction): string
{
    return match ($direction) {
        'up' => "\u{25B2}",
        'down' => "\u{25BC}",
        'flat' => "\u{25A0}",
        default => "\u{2014}",
    };
}

function dl_efficiencyTrendTitle(string $metric, string $direction): string
{
    $isGain = in_array($metric, ['yield', 'sell_through'], true);
    $metricLabel = match ($metric) {
        'yield' => 'yield',
        'sell_through' => 'sell-through',
        'wastage' => 'wastage rate',
        'shrinkage' => 'residual',
        default => 'rate',
    };

    return match ($direction) {
        'up' => $isGain
            ? $metricLabel . ': higher than the previous period - improvement'
            : $metricLabel . ': higher than the previous period - worse',
        'down' => $isGain
            ? $metricLabel . ': lower than the previous period - worse'
            : $metricLabel . ': lower than the previous period - improvement',
        'flat' => $metricLabel . ': unchanged from the previous period',
        default => $metricLabel . ': no comparable previous period',
    };
}

/**
 * The immediately preceding period of the same inclusive length.
 * Returns ['', ''] when the input dates are unusable.
 *
 * @return array{0:string,1:string}
 */
function dl_efficiencyPriorPeriod(string $dateFrom, string $dateTo): array
{
    try {
        $from = new DateTimeImmutable($dateFrom);
        $to = new DateTimeImmutable($dateTo);
    } catch (Throwable) {
        return ['', ''];
    }
    if ($to < $from) {
        return ['', ''];
    }
    $days = $from->diff($to)->days + 1;
    $priorTo = $from->modify('-1 day');
    $priorFrom = $priorTo->modify('-' . max(0, $days - 1) . ' days');

    return [$priorFrom->format('Y-m-d'), $priorTo->format('Y-m-d')];
}

/**
 * Production efficiency aggregates for the selected period and scope.
 *
 * READ-ONLY. The columns already exist and the invariant is already in the code:
 *   - produced_qty / wastage_qty / remaining_qty (migration 037)
 *   - beg_qty / actual_end_qty / calc_variance (migration 064)
 *   - the balance is beg_qty + produced_qty - dispatched_qty - wastage_qty
 *   - sales units are read through dl_ledgerSalesQuantitySql(), the SAME integer
 *     quantity the product ranking sums
 *
 * All quantities are INTEGERS IN ONE UNIT (pieces), so the rates are real ratios.
 *
 * FIVE MEASURES, each with its own formula and its own explicit-state rule:
 *   Yield %        produced output / theoretical output * 100      (production efficiency)
 *                  theoretical output = FLOOR(input_qty / batch_input_qty) * output_pieces_per_batch
 *                  (migration 016; batch_egg_qty for egg products)
 *   Sell-through % sold / produced * 100                           (demand conversion)
 *   Waste rate %   wasted / produced * 100
 *   Shrinkage %    (produced - sold - wasted - remaining) / produced * 100  (unexplained loss)
 *   Mass balance   produced = sold + wasted + remaining + unaccounted
 *
 * Yield is NOT sell-through. sold/produced says how much output demand pulled;
 * output/theoretical-output says how much saleable output the input produced.
 * Presenting the first as the second is the defect this separation removes.
 *
 * remaining_qty is a GENERATED column equal to produced - dispatched - wastage.
 * It is STOCK, never waste; the wastage rate divides by PRODUCED, never by it.
 *
 * A ratio is computed only when its own denominator exists. A row that cannot
 * support one carries an explicit state string instead of a 0 that would read as
 * failure, and the yield coverage counts name how many products that affects.
 *
 * @param array<string,mixed> $filters dl_reportFilters() output
 * @return array<string,mixed>
 */
function dl_productionEfficiencyData(DatabaseContract $db, array $filters): array
{
    $empty = [
        // Source production panel (producers only).
        'produced' => 0,
        'wasted' => 0,
        'dispatched' => 0,
        'opening' => 0,
        'closing_calculated' => 0,
        'closing_state' => '',
        'physical_end' => 0,
        'counted_rows' => 0,
        'counted_variance' => 0,
        'has_producer_in_scope' => false,
        'producer_branch_ids' => [],
        'supplied_labels' => [],
        'sources' => [],
        'wastage_rate_pct' => null,
        'yield_pct' => null,
        'yield_state' => 'not computable: no production input recorded in this period',
        'input_kilo' => 0.0,
        'input_egg' => 0.0,
        'theoretical_output' => 0,
        'yield_products_total' => 0,
        'yield_products_computable' => 0,
        'yield_products_missing_basis' => 0,
        'yield_products_missing_input' => 0,
        'production_min_date' => null,
        'production_max_date' => null,
        'production_days' => 0,
        'production_positive_days' => 0,
        // Destination panel.
        'sold' => 0,
        'official_only' => false,
        'excluded_units' => 0,
        'units_basis' => '',
        'received' => 0,
        'received_internal' => 0,
        'adjustment_add' => 0,
        'receipt_docs' => 0,
        'receipt_days' => 0,
        'conversion_pct' => null,
        'conversion_applies' => true,
        'conversion_state' => 'no stock received and no sales in this period',
        'conversion_exceeds' => false,
        'products' => [],
        'pullout_rows' => [],
        'pullout_totals' => ['wastage_qty' => 0, 'consumed_qty' => 0, 'saleable_qty' => 0, 'movements' => 0],
    ];

    $ids = array_values(array_filter(
        array_map('intval', $filters['accessible_branch_ids'] ?? []),
        static fn(int $id): bool => $id > 0
    ));
    if (($filters['branch_id'] ?? 0) > 0) {
        $ids = in_array((int)$filters['branch_id'], $ids, true) ? [(int)$filters['branch_id']] : [];
    }
    if ($ids === []) {
        return $empty;
    }

    $dateFrom = (string)($filters['date_from'] ?? '');
    $dateTo = (string)($filters['date_to'] ?? '');
    $productId = (int)($filters['product_id'] ?? 0);
    $shift = strtoupper(trim((string)($filters['shift'] ?? '')));
    if (!in_array($shift, ['AM', 'PM'], true)) {
        $shift = '';
    }

    // ── Which branches actually PRODUCE? ──
    // This is the fix for the defect Sol confirmed (C1). The old aggregation ran the
    // production ledger over EVERY selected branch, so a supplying branch had no
    // production rows and rendered "0 produced, 0% wastage" as if that were a
    // measurement. It then divided destination sales by source output, which is how
    // the all-branch scope reported 1162.38% sell-through. Production is now computed
    // for branches with production EVIDENCE only, and every other branch is named for
    // what it is instead of being shown as producing nothing.
    $sources = dl_efficiencySupplySources($db, $ids, $dateFrom, $dateTo);
    $producerIds = [];
    $suppliedLabels = [];
    foreach ($sources as $sourceId => $sourceInfo) {
        if (in_array($sourceInfo['class'], ['producer', 'mixed'], true)) {
            $producerIds[] = (int)$sourceId;
        } else {
            $suppliedLabels[(int)$sourceId] = (string)$sourceInfo['label'];
        }
    }
    $hasProducerInScope = $producerIds !== [];
    $producerMarks = $producerIds === []
        ? 'NULL'
        : implode(',', array_fill(0, count($producerIds), '?'));
    $marks = implode(',', array_fill(0, count($ids), '?'));

    // ── Production side, per product, for PRODUCERS ONLY ──
    // remaining_qty is deliberately NOT selected. Migration 037 defines it as a GENERATED
    // column equal to produced - dispatched - wastage, which omits opening stock: on tenant 207
    // it sums to -3838 for branch 18 while the book balance including beg_qty is 74058. Reading
    // it as closing stock is what produced a phantom deficit. Closing is calculated from the real
    // flow instead, and only labelled a PHYSICAL closing when a count exists (actual_end_qty).
    $prodSql = "SELECT cpl.product_id, p.name AS product_name, p.sku,
                       p.batch_input_qty, p.batch_egg_qty, p.output_pieces_per_batch, p.output_unit_label,
                       COALESCE(SUM(cpl.produced_qty), 0) AS produced_qty,
                       COALESCE(SUM(cpl.dispatched_qty), 0) AS dispatched_qty,
                       COALESCE(SUM(cpl.wastage_qty), 0) AS wastage_qty,
                       COALESCE(SUM(CASE WHEN cpl.actual_end_qty IS NOT NULL THEN cpl.actual_end_qty ELSE 0 END), 0) AS physical_end_qty,
                       COALESCE(SUM(CASE WHEN cpl.actual_end_qty IS NOT NULL THEN 1 ELSE 0 END), 0) AS counted_rows,
                       COALESCE(SUM(CASE WHEN cpl.actual_end_qty IS NOT NULL THEN cpl.calc_variance ELSE 0 END), 0) AS counted_variance
                  FROM dl_commissary_product_ledger cpl
                  INNER JOIN dl_products p ON p.id = cpl.product_id
                 WHERE cpl.ledger_date BETWEEN ? AND ?
                   AND cpl.commissary_branch_id IN ({$producerMarks})";
    $prodBind = array_merge([$dateFrom, $dateTo], $producerIds);
    if ($productId > 0) {
        $prodSql .= ' AND cpl.product_id = ?';
        $prodBind[] = $productId;
    }
    if ($shift !== '') {
        $prodSql .= ' AND cpl.shift = ?';
        $prodBind[] = $shift;
    }
    $prodSql .= ' GROUP BY cpl.product_id, p.name, p.sku, p.batch_input_qty, p.batch_egg_qty, p.output_pieces_per_batch, p.output_unit_label';
    $prodRows = [];
    if ($hasProducerInScope) {
        $prodStmt = $db->prepare($prodSql);
        $prodStmt->execute($prodBind);
        $prodRows = $prodStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // Opening stock: beg_qty on each branch-product's FIRST covered day. This is a LEVEL
    // taken once, never a sum of daily beginnings — summing them multiplies a month of
    // openings into one meaningless total.
    $openingByProduct = [];
    if ($hasProducerInScope) {
        $openingSql = "SELECT cpl.product_id, COALESCE(SUM(cpl.beg_qty), 0) AS opening_qty
                         FROM dl_commissary_product_ledger cpl
                         INNER JOIN (
                              SELECT commissary_branch_id, product_id, MIN(ledger_date) AS first_date
                                FROM dl_commissary_product_ledger
                               WHERE ledger_date BETWEEN ? AND ?
                                 AND commissary_branch_id IN ({$producerMarks})
                               GROUP BY commissary_branch_id, product_id
                         ) f ON f.commissary_branch_id = cpl.commissary_branch_id
                            AND f.product_id = cpl.product_id
                            AND f.first_date = cpl.ledger_date
                        WHERE cpl.ledger_date BETWEEN ? AND ?
                          AND cpl.commissary_branch_id IN ({$producerMarks})
                          AND cpl.beg_qty > 0
                        GROUP BY cpl.product_id";
        $openingBind = array_merge([$dateFrom, $dateTo], $producerIds, [$dateFrom, $dateTo], $producerIds);
        if ($productId > 0) {
            $openingSql = str_replace('GROUP BY cpl.product_id', 'AND cpl.product_id = ? GROUP BY cpl.product_id', $openingSql);
            $openingBind[] = $productId;
        }
        $openingStmt = $db->prepare($openingSql);
        $openingStmt->execute($openingBind);
        foreach ($openingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $openingRow) {
            $openingByProduct[(int)$openingRow['product_id']] = (int)$openingRow['opening_qty'];
        }
    }

    // ── Destination inflow, from the RECEIVING tables, not from `addtl` ──
    // `addtl` is NOT a stock-received series. Sol's review falsified that premise and the
    // measurement agrees: on tenant 207 branch 8 has addtl 104210 against posted receipts of
    // 83783 and cashier Add Stock (adjustment_add) of 23761 - the accumulator is receipts PLUS
    // adjustments PLUS direct ledger edits (dl_applyLedgerDelta, handlers.php:10959 / 11337).
    // Using it as "stock received" would fold operator corrections into a performance ratio.
    // The receiving tables are the module's own declared evidence (dl_reconcileAddtl() sums
    // exactly them), and they carry origin_type / origin_id, which is what attribution needs.
    $receiptSql = "SELECT bri.product_id,
                          COALESCE(SUM(bri.quantity_received), 0) AS received_qty,
                          COALESCE(SUM(CASE WHEN br.origin_type = 'branch' THEN bri.quantity_received ELSE 0 END), 0) AS transfer_qty
                     FROM dl_branch_receivings br
                     INNER JOIN dl_branch_receiving_items bri ON bri.receiving_id = br.id
                    WHERE br.status = 'posted'
                      AND br.received_ledger_date BETWEEN ? AND ?
                      AND br.branch_id IN ({$marks})"
        // A transfer whose ORIGIN is also inside the selected scope is internal movement, not network
        // inflow: counting it made the same stock enter the denominator twice. At a single-branch
        // scope the origin is outside, so the branch's receipt is real inflow and is kept.
        . " AND NOT (br.origin_type = 'branch' AND br.origin_id IN ({$marks}))";
    $receiptBind = array_merge([$dateFrom, $dateTo], $ids, $ids);
    if ($productId > 0) {
        $receiptSql .= ' AND bri.product_id = ?';
        $receiptBind[] = $productId;
    }
    $receiptSql .= ' GROUP BY bri.product_id';
    $receiptStmt = $db->prepare($receiptSql);
    $receiptStmt->execute($receiptBind);
    $receivedByProduct = [];
    $receivedTotal = 0;
    foreach ($receiptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $receiptRow) {
        $receivedByProduct[(int)$receiptRow['product_id']] = (int)$receiptRow['received_qty'];
        $receivedTotal += (int)$receiptRow['received_qty'];
    }

    // The internal transfers the figure above EXCLUDED, so the page DISCLOSES what it left out rather
    // than quietly shrinking the denominator (Sol F5).
    $internalStmt = $db->prepare(
        "SELECT COALESCE(SUM(bri.quantity_received), 0)
           FROM dl_branch_receivings br
           INNER JOIN dl_branch_receiving_items bri ON bri.receiving_id = br.id
          WHERE br.status = 'posted' AND br.received_ledger_date BETWEEN ? AND ?
            AND br.origin_type = 'branch' AND br.origin_id IN ({$marks})
            AND br.branch_id IN ({$marks})"
    );
    $internalStmt->execute(array_merge([$dateFrom, $dateTo], $ids, $ids));
    $transferTotal = (int)$internalStmt->fetchColumn();

    // Receipt coverage, so the page can state the window its inflow figure actually covers,
    // and the Add Stock adjustment TOTAL, which is disclosed as a reconciliation figure and
    // never counted as received production.
    $receiptMeta = ['docs' => 0, 'days' => 0];
    $receiptMetaStmt = $db->prepare(
        "SELECT COUNT(DISTINCT br.id) AS docs, COUNT(DISTINCT br.received_ledger_date) AS days
           FROM dl_branch_receivings br
          WHERE br.status = 'posted' AND br.received_ledger_date BETWEEN ? AND ?
            AND br.branch_id IN ({$marks})"
    );
    $receiptMetaStmt->execute(array_merge([$dateFrom, $dateTo], $ids));
    $receiptMetaRow = $receiptMetaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $receiptMeta = ['docs' => (int)($receiptMetaRow['docs'] ?? 0), 'days' => (int)($receiptMetaRow['days'] ?? 0)];

    $adjustmentStmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity), 0) AS qty
           FROM dl_cashier_withdrawals
          WHERE withdrawal_type = 'adjustment_add' AND ledger_date BETWEEN ? AND ?
            AND branch_id IN ({$marks})"
    );
    $adjustmentStmt->execute(array_merge([$dateFrom, $dateTo], $ids));
    $adjustmentAddTotal = (int)$adjustmentStmt->fetchColumn();

    // The production ledger's own date coverage. The efficiency ratio compares
    // the selected period's SALES with the days that actually carry output
    // records; when those windows differ (a newly enabled production ledger, or
    // a branch with no production rows), the ratio is NOT period-matched and the
    // view must say so rather than print a percentage that looks like an
    // efficiency. Measured on tenant 207: production rows cover 4 days of a
    // 41-day period, so raw sold/produced reads 1162%.
    //
    // Row-covered days and positive-output days are counted SEPARATELY. Four days carry
    // rows for branch 18 but only three carry output, and reporting "4" alone equates
    // "a day was recorded" with "output happened" — two different facts.
    $coverageSql = "SELECT MIN(cpl.ledger_date) AS min_date,
                           MAX(cpl.ledger_date) AS max_date,
                           COUNT(DISTINCT cpl.ledger_date) AS days,
                           COUNT(DISTINCT CASE WHEN cpl.produced_qty > 0 THEN cpl.ledger_date END) AS positive_days
                      FROM dl_commissary_product_ledger cpl
                     WHERE cpl.ledger_date BETWEEN ? AND ?
                       AND cpl.commissary_branch_id IN ({$producerMarks})";
    $coverageBind = array_merge([$dateFrom, $dateTo], $producerIds);
    if ($productId > 0) {
        $coverageSql .= ' AND cpl.product_id = ?';
        $coverageBind[] = $productId;
    }
    if ($shift !== '') {
        $coverageSql .= ' AND cpl.shift = ?';
        $coverageBind[] = $shift;
    }
    $coverage = [];
    if ($hasProducerInScope) {
        $coverageStmt = $db->prepare($coverageSql);
        $coverageStmt->execute($coverageBind);
        $coverage = $coverageStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    $productionMinDate = isset($coverage['min_date']) && $coverage['min_date'] !== null ? (string)$coverage['min_date'] : null;
    $productionMaxDate = isset($coverage['max_date']) && $coverage['max_date'] !== null ? (string)$coverage['max_date'] : null;
    $productionDays = (int)($coverage['days'] ?? 0);
    $productionPositiveDays = (int)($coverage['positive_days'] ?? 0);

    // ── Sales side, per product (the ranking's own quantity expression) ──
    // BOTH bases are summed in one pass so an official-only figure and the volume it removed come
    // from the same scan. A pending row's quantity is NULL and SUM() already ignores it, so the only
    // basis that changes anything is the fuller dl_rowIsProvisional() definition used here.
    $qty = dl_ledgerSalesQuantitySql('dl');
    $provExpr = dl_provisionalSqlExpr('dl', 'ss');
    $officialOnly = dl_overviewPendingRowsMode($filters['pending_rows_mode'] ?? null) === 'exclude';
    $salesSql = "SELECT dl.product_id, p.name AS product_name, p.sku,
                        COALESCE(SUM({$qty}), 0) AS sold_qty,
                        COALESCE(SUM(CASE WHEN {$provExpr} THEN 0 ELSE {$qty} END), 0) AS sold_official_qty
                   FROM dl_daily_ledger dl
                   INNER JOIN dl_products p ON p.id = dl.product_id
                   LEFT JOIN dl_ledger_shift_status ss
                     ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
                    AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
                  WHERE dl.ledger_date BETWEEN ? AND ?
                    AND dl.branch_id IN ({$marks})";
    $salesBind = array_merge([$dateFrom, $dateTo], $ids);
    if ($productId > 0) {
        $salesSql .= ' AND dl.product_id = ?';
        $salesBind[] = $productId;
    }
    if ($shift !== '') {
        $salesSql .= ' AND dl.shift = ?';
        $salesBind[] = $shift;
    }
    $salesSql .= ' GROUP BY dl.product_id, p.name, p.sku';
    $salesStmt = $db->prepare($salesSql);
    $salesStmt->execute($salesBind);
    $salesRows = $salesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Input side, per product (the yield basis) ──
    // Migration 011 records the raw input for each production run as
    // primary_input_qty with a unit type ('kilo' or 'egg'); migration 016's
    // batch basis converts that to an expected output. No input value is
    // invented: a product with no run in the period has no input here and the
    // template states that rather than defaulting the yield.
    $runsSql = "SELECT pr.product_id,
                       COALESCE(SUM(CASE WHEN pr.primary_input_type = 'egg' THEN pr.primary_input_qty ELSE 0 END), 0) AS egg_input_qty,
                       COALESCE(SUM(CASE WHEN pr.primary_input_type <> 'egg' THEN pr.primary_input_qty ELSE 0 END), 0) AS kilo_input_qty
                  FROM dl_production_runs pr
                 WHERE pr.ledger_date BETWEEN ? AND ?
                   AND pr.destination_branch_id IN ({$marks})";
    $runsBind = array_merge([$dateFrom, $dateTo], $ids);
    if ($productId > 0) {
        $runsSql .= ' AND pr.product_id = ?';
        $runsBind[] = $productId;
    }
    if ($shift !== '') {
        $runsSql .= ' AND pr.shift = ?';
        $runsBind[] = $shift;
    }
    $runsSql .= ' GROUP BY pr.product_id';
    $runsStmt = $db->prepare($runsSql);
    $runsStmt->execute($runsBind);
    $runInput = [];
    foreach ($runsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $runRow) {
        $runInput[(int)$runRow['product_id']] = [
            'kilo' => (float)$runRow['kilo_input_qty'],
            'egg' => (float)$runRow['egg_input_qty'],
        ];
    }

    // A closing count is a LEVEL, so the physical ending and its variance are taken from each
    // product's LATEST counted day, never summed across every counted snapshot. Measured (Sol F6):
    // summing the 312 counted snapshots reported 45,692 pcs of "physical count" and 5,465 of
    // "counted variance" where the latest-per-product values are 22,216 and 3,957 - the same class
    // of error P1 removed from closing stock.
    $countedVariance = 0;
    $countedRows = 0;
    $physicalEndTotal = 0;
    if ($hasProducerInScope) {
        // Same rule the forecast uses for stock on hand: the latest DATE, then the latest SHIFT on
        // that date. Summing AM and PM endings of the same day counts one closing twice.
        $latestCountSql = "SELECT cpl.product_id,
                                  COALESCE(SUM(cpl.actual_end_qty), 0) AS physical_end,
                                  COALESCE(SUM(cpl.calc_variance), 0) AS counted_variance
                             FROM dl_commissary_product_ledger cpl
                             INNER JOIN (
                                  SELECT product_id,
                                         MAX(CONCAT(ledger_date, ':', COALESCE(shift, ''))) AS latest_key
                                    FROM dl_commissary_product_ledger
                                   WHERE ledger_date BETWEEN ? AND ?
                                     AND actual_end_qty IS NOT NULL
                                     AND commissary_branch_id IN ({$producerMarks})
                                   GROUP BY product_id
                             ) c ON c.product_id = cpl.product_id
                                AND c.latest_key = CONCAT(cpl.ledger_date, ':', COALESCE(cpl.shift, ''))
                            WHERE cpl.ledger_date BETWEEN ? AND ?
                              AND cpl.actual_end_qty IS NOT NULL
                              AND cpl.commissary_branch_id IN ({$producerMarks})";
        $latestCountBind = array_merge([$dateFrom, $dateTo], $producerIds, [$dateFrom, $dateTo], $producerIds);
        if ($productId > 0) {
            $latestCountSql .= ' AND cpl.product_id = ?';
            $latestCountBind[] = $productId;
        }
        $latestCountSql .= ' GROUP BY cpl.product_id';
        $latestCountStmt = $db->prepare($latestCountSql);
        $latestCountStmt->execute($latestCountBind);
        foreach ($latestCountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $countRow) {
            $physicalEndTotal += (int)$countRow['physical_end'];
            $countedVariance += (int)$countRow['counted_variance'];
            $countedRows++;
        }
    }

    $products = [];
    $producedTotal = 0;
    $wastedTotal = 0;
    $dispatchedTotal = 0;
    $openingTotal = 0;
    foreach ($prodRows as $row) {
        $pid = (int)$row['product_id'];
        $openingQty = (int)($openingByProduct[$pid] ?? 0);
        $products[$pid] = [
            'product_id' => $pid,
            'product_name' => (string)($row['product_name'] ?? ''),
            'sku' => (string)($row['sku'] ?? ''),
            'produced' => (int)$row['produced_qty'],
            'sold' => 0,
            'wasted' => (int)$row['wastage_qty'],
            'dispatched' => (int)$row['dispatched_qty'],
            'opening' => $openingQty,
            'received' => 0,
            'physical_end' => (int)$row['physical_end_qty'],
            'counted_rows' => (int)$row['counted_rows'],
            'counted_variance' => (int)$row['counted_variance'],
            // The batch input basis (migration 016). NULL on the product means
            // "not configured", which is a stated yield gap, never 100%.
            'batch_input_qty' => isset($row['batch_input_qty']) ? (float)$row['batch_input_qty'] : 0.0,
            'batch_egg_qty' => isset($row['batch_egg_qty']) ? (float)$row['batch_egg_qty'] : 0.0,
            'output_pieces_per_batch' => (int)($row['output_pieces_per_batch'] ?? 0),
            'output_unit_label' => (string)($row['output_unit_label'] ?? 'pcs'),
        ];
        $producedTotal += (int)$row['produced_qty'];
        $wastedTotal += (int)$row['wastage_qty'];
        $dispatchedTotal += (int)$row['dispatched_qty'];
        $openingTotal += $openingQty;
    }

    // A product that SOLD but has no production row this period was produced in
    // an earlier period. It must still appear, with produced = 0 and an explicit
    // "not computable" state, rather than being dropped or shown as 0% failure.
    $salesInclusiveTotal = 0;
    foreach ($salesRows as $row) {
        $pid = (int)$row['product_id'];
        $soldInclusive = (int)$row['sold_qty'];
        $sold = $officialOnly ? (int)$row['sold_official_qty'] : $soldInclusive;
        $salesInclusiveTotal += $soldInclusive;
        if (!isset($products[$pid])) {
            $products[$pid] = [
                'product_id' => $pid,
                'product_name' => (string)($row['product_name'] ?? ''),
                'sku' => (string)($row['sku'] ?? ''),
                'produced' => 0,
                'sold' => 0,
                'wasted' => 0,
                'dispatched' => 0,
                'opening' => 0,
                'received' => 0,
                'physical_end' => 0,
                'counted_rows' => 0,
                'counted_variance' => 0,
                'batch_input_qty' => 0.0,
                'batch_egg_qty' => 0.0,
                'output_pieces_per_batch' => 0,
                'output_unit_label' => 'pcs',
            ];
        }
        $products[$pid]['sold'] = $sold;
    }

    // Attach the recorded input to each product. A product with no run in the
    // period keeps zero input and its yield state says so explicitly.
    foreach ($products as &$productRef) {
        $refPid = (int)$productRef['product_id'];
        $productRef['input_kilo'] = $runInput[$refPid]['kilo'] ?? 0.0;
        $productRef['input_egg'] = $runInput[$refPid]['egg'] ?? 0.0;
        $productRef['received'] = (int)($receivedByProduct[$refPid] ?? 0);
    }
    unset($productRef);

    $soldTotal = 0;
    $theoreticalOutputTotal = 0;
    $inputKiloTotal = 0.0;
    $inputEggTotal = 0.0;
    $yieldProductsTotal = 0;
    $yieldProductsComputable = 0;
    $yieldProductsMissingBasis = 0;
    $yieldProductsMissingInput = 0;
    foreach ($products as &$product) {
        $soldTotal += (int)$product['sold'];
        $produced = (int)$product['produced'];
        $sold = (int)$product['sold'];
        $wasted = (int)$product['wasted'];
        $dispatchedProduct = (int)$product['dispatched'];
        $opening = (int)$product['opening'];
        $received = (int)$product['received'];
        // Yield coverage is measured against the PRODUCED population, not every product with sales or
        // receipts. Sol F7: counting all 178 rendered "0 of 178" when only 36 products produced
        // anything, which makes a configuration gap look catalogue-wide.
        if ($produced > 0) {
            $yieldProductsTotal++;
        }

        // Yield basis from migration 016: theoretical output is whole batches of
        // input times the pieces each batch yields. BOTH the batch size and the
        // pieces-per-batch are required; a NULL batch_input_qty is a stated gap.
        $biq = (float)$product['batch_input_qty'];
        $beq = (float)$product['batch_egg_qty'];
        $oppb = (int)$product['output_pieces_per_batch'];
        $inputKilo = (float)($product['input_kilo'] ?? 0.0);
        $inputEgg = (float)($product['input_egg'] ?? 0.0);
        $theoreticalOutput = 0;
        if ($oppb > 0) {
            if ($biq > 0 && $inputKilo > 0) {
                $theoreticalOutput += (int)floor($inputKilo / $biq) * $oppb;
            }
            if ($beq > 0 && $inputEgg > 0) {
                $theoreticalOutput += (int)floor($inputEgg / $beq) * $oppb;
            }
        }
        $basisConfigured = $oppb > 0 && ($biq > 0 || $beq > 0);
        $hasInput = $inputKilo > 0 || $inputEgg > 0;
        $product['input_qty'] = round($inputKilo + $inputEgg, 3);
        $product['theoretical_output'] = $theoreticalOutput;
        $inputKiloTotal += $inputKilo;
        $inputEggTotal += $inputEgg;
        $theoreticalOutputTotal += $theoreticalOutput;

        if ($theoreticalOutput > 0) {
            // Production efficiency: what came out against what the input should
            // have yielded. This is the standard yield, distinct from sell-through.
            $product['yield_pct'] = round($produced / $theoreticalOutput * 100, 2);
            $product['yield_state'] = '';
            $product['yield_exceeds'] = $product['yield_pct'] > 100;
            $yieldProductsComputable++;
        } else {
            $product['yield_pct'] = null;
            $product['yield_exceeds'] = false;
            if (!$basisConfigured) {
                $product['yield_state'] = 'not computable: no batch input basis configured (batch_input_qty / batch_egg_qty with output_pieces_per_batch)';
                if ($produced > 0) {
                    $yieldProductsMissingBasis++;
                }
            } elseif (!$hasInput) {
                $product['yield_state'] = 'not computable: no production input recorded for this product in the period';
                if ($produced > 0) {
                    $yieldProductsMissingInput++;
                }
            } else {
                $product['yield_state'] = 'not computable: the recorded input does not complete a whole batch at the configured batch size';
                if ($produced > 0) {
                    $yieldProductsMissingInput++;
                }
            }
        }

        if ($produced > 0) {
            // Wastage rate: producer-side loss against the output that could have carried it.
            $product['wastage_rate_pct'] = round($wasted / $produced * 100, 2);
            $product['wastage_state'] = '';
            // Closing stock INCLUDES opening stock. The generated remaining_qty does not, which
            // is why it reads -3838 for branch 18 where the book balance is 74058.
            //
            // A NEGATIVE result is not printed. Tenant 207 measures it: branch 18's first covered
            // day (2026-10-08) carries beg_qty 50 while the next day carries 42320, so
            // 50 + 8828 - 12547 - 119 = -3788 is arithmetically correct and factually meaningless.
            // A negative stock figure is the same class of lie as the 1162% this change removes, so
            // it becomes a named gap.
            $closingProduct = $opening + $produced - $dispatchedProduct - $wasted;
            if ($closingProduct < 0) {
                $product['closing_calculated'] = null;
                $product['closing_state'] = 'book balance does not close: no opening balance recorded for the first covered day';
            } else {
                $product['closing_calculated'] = $closingProduct;
                $product['closing_state'] = '';
            }
        } else {
            $product['wastage_rate_pct'] = null;
            $product['wastage_state'] = 'not recorded: no production for this product in this period';
            $product['closing_calculated'] = null;
            $product['closing_state'] = 'not recorded: no production for this product in this period';
        }

        // Stock conversion is a DESTINATION measure: of the stock RECEIVED in this period, how
        // much was sold. It is deliberately NOT sold/produced. The old sell-through divided a
        // destination flow by a source flow, which is how the all-branch scope reported 1162.38%
        // as if it were a performance figure.
        if ($received > 0) {
            $product['conversion_pct'] = round($sold / $received * 100, 2);
            $product['conversion_state'] = '';
            $product['conversion_exceeds'] = $product['conversion_pct'] > 100;
        } else {
            $product['conversion_pct'] = null;
            $product['conversion_exceeds'] = false;
            $product['conversion_state'] = $sold > 0
                ? 'no stock received in this period; these units came from opening stock'
                : 'no stock received and no sales in this period';
        }

        // Unexplained loss, the control the old "shrinkage" claimed to be: book closing against a
        // PHYSICAL count. Only a product that was actually counted can carry one, so an uncounted
        // product reports a stated gap rather than a residual invented from empty stock.
        $product['unaccounted'] = ($produced > 0 && (int)$product['counted_rows'] > 0)
            ? ($opening + $produced - $dispatchedProduct - $wasted) - (int)$product['physical_end']
            : null;
        $product['unit'] = 'pcs';
    }
    unset($product);
    // Heaviest output first; a sale-only row (produced 0) sorts after real output.
    usort($products, static function (array $a, array $b): int {
        return [$b['produced'], $b['sold'], $a['product_name']] <=> [$a['produced'], $a['sold'], $b['product_name']];
    });

    // ── Pullout / unsellable movements, by the existing reason vocabulary ──
    $pulloutRows = [];
    $pulloutTotals = $empty['pullout_totals'];
    $pullSql = "SELECT cw.product_id, p.name AS product_name, p.sku, cw.reason_code,
                       COUNT(*) AS movements, COALESCE(SUM(cw.quantity), 0) AS qty
                  FROM dl_cashier_withdrawals cw
                  INNER JOIN dl_products p ON p.id = cw.product_id
                 WHERE cw.ledger_date BETWEEN ? AND ?
                   AND cw.branch_id IN ({$marks})
                   AND cw.withdrawal_type = 'pullout'";
    $pullBind = array_merge([$dateFrom, $dateTo], $ids);
    if ($productId > 0) {
        $pullSql .= ' AND cw.product_id = ?';
        $pullBind[] = $productId;
    }
    if ($shift !== '') {
        $pullSql .= ' AND cw.shift = ?';
        $pullBind[] = $shift;
    }
    $pullSql .= ' GROUP BY cw.product_id, p.name, p.sku, cw.reason_code ORDER BY p.name ASC, qty DESC';
    $pullStmt = $db->prepare($pullSql);
    $pullStmt->execute($pullBind);
    foreach ($pullStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $reason = (string)($row['reason_code'] ?? '');
        $classification = dl_classifyPulloutReturnReason($reason);
        $qty = (int)$row['qty'];
        $pulloutRows[] = [
            'product_id' => (int)$row['product_id'],
            'product_name' => (string)($row['product_name'] ?? ''),
            'sku' => (string)($row['sku'] ?? ''),
            'reason_code' => $reason,
            'reason_label' => $reason === '' ? 'Unspecified' : dlHumanizeToken($reason),
            'classification' => $classification,
            'classification_label' => dl_productionEfficiencyClassificationLabel($classification),
            'movements' => (int)$row['movements'],
            'qty' => $qty,
        ];
        $pulloutTotals['movements'] += (int)$row['movements'];
        if ($classification === 'wastage') {
            $pulloutTotals['wastage_qty'] += $qty;
        } elseif ($classification === 'consumed_no_delta') {
            $pulloutTotals['consumed_qty'] += $qty;
        } else {
            $pulloutTotals['saleable_qty'] += $qty;
        }
    }

    // ── Source panel (producers only) vs destination panel (every branch in scope) ──
    // They are returned as separate figures and are never divided by one another. The
    // blended ratio this replaces reported 1162.38% at all-branch scope by dividing
    // destination sales by source output over windows that do not match.
    $closingCalculated = $openingTotal + $producedTotal - $dispatchedTotal - $wastedTotal;
    // A negative book balance is reported as a named gap, never as a negative stock figure. Measured:
    // branch 18's first covered day carries beg_qty 50 against 42320 the next day, so the balance
    // does not close. Printing -3788 would be arithmetically correct and meaningless.
    $closingState = '';
    if ($producedTotal > 0 && $closingCalculated < 0) {
        $closingState = 'book balance does not close: the production ledger records no opening balance for '
            . 'its first covered day, so opening + produced - dispatched - wastage is negative. Reported as a gap.';
        $closingCalculated = null;
    }
    // A producer's output leaves by DISPATCH, not by retail sale, so "stock conversion" is not its
    // measure. Rendering 0.00% for a commissary that took returns in and sold nothing retail reads as
    // failure when it is the expected state (Sol F5). The ratio is withheld when the scope holds no
    // supplying branch at all.
    $conversionApplies = $suppliedLabels !== [];
    $conversionPct = $receivedTotal > 0 ? round($soldTotal / $receivedTotal * 100, 2) : null;
    if (!$conversionApplies) {
        $conversionPct = null;
    }
    $conversionState = !$conversionApplies
        ? 'not applicable: no branch in this scope is supplied by a commissary, so a producer\'s output leaves by dispatch and retail conversion is not its measure'
        : ($receivedTotal > 0
            ? ''
            : ($soldTotal > 0
                ? 'no stock received in this period; these units came from opening stock'
                : 'no stock received and no sales in this period'));

    return [
        // Source production. Zero here means "no production recorded in this scope", which the
        // template renders as a named condition - never as a measured zero.
        'produced' => $producedTotal,
        'wasted' => $wastedTotal,
        'dispatched' => $dispatchedTotal,
        'opening' => $openingTotal,
        'closing_calculated' => $closingCalculated,
        'closing_state' => $closingState,
        'physical_end' => $physicalEndTotal,
        'counted_rows' => $countedRows,
        'counted_variance' => $countedVariance,
        'has_producer_in_scope' => $hasProducerInScope,
        'producer_branch_ids' => $producerIds,
        'supplied_labels' => $suppliedLabels,
        'sources' => array_values($sources),
        // Yield: production efficiency (produced output / theoretical output on
        // the batch basis). NULL when no product in scope has a computable basis;
        // the coverage counts below carry the reason.
        'yield_pct' => $theoreticalOutputTotal > 0 ? round($producedTotal / $theoreticalOutputTotal * 100, 2) : null,
        'yield_state' => $theoreticalOutputTotal > 0
            ? ''
            : ($yieldProductsMissingBasis > 0
                ? 'not computable: no batch input basis configured for the products in this scope'
                : 'not computable: no production input recorded in this period'),
        'wastage_rate_pct' => $producedTotal > 0 ? round($wastedTotal / $producedTotal * 100, 2) : null,
        'input_kilo' => round($inputKiloTotal, 3),
        'input_egg' => round($inputEggTotal, 3),
        'theoretical_output' => $theoreticalOutputTotal,
        'yield_products_total' => $yieldProductsTotal,
        'yield_products_computable' => $yieldProductsComputable,
        'yield_products_missing_basis' => $yieldProductsMissingBasis,
        'yield_products_missing_input' => $yieldProductsMissingInput,
        'production_min_date' => $productionMinDate,
        'production_max_date' => $productionMaxDate,
        // Row-covered days and positive-output days are separate facts: 4 days carry rows for
        // branch 18, only 3 carry output. Reporting one number equates "recorded" with "happened".
        'production_days' => $productionDays,
        'production_positive_days' => $productionPositiveDays,

        // Destination panel. `received` comes from the posted receiving tables, NOT from
        // dl_daily_ledger.addtl: measured on tenant 207 branch 8 has addtl 104210 against
        // receipts 83783 plus Add Stock adjustments 23761, so addtl is an accumulator of
        // receipts and operator corrections and cannot serve as "stock received".
        'sold' => $soldTotal,
        // The basis the unit counts are on, and what an official-only basis removed. Stating the
        // exclusion is what stops an official figure being read as the whole picture.
        'official_only' => $officialOnly,
        'excluded_units' => max(0, $salesInclusiveTotal - $soldTotal),
        'units_basis' => $officialOnly
            ? 'official only: provisional rows (unfinalized shifts, derived or zero-forced endings, and pending entries) are excluded from every unit count on this page'
            : 'official + provisional: provisional rows are INCLUDED, so the unit counts are larger than an official-only view would show',
        'received' => $receivedTotal,
        'received_internal' => $transferTotal,
        'adjustment_add' => $adjustmentAddTotal,
        'receipt_docs' => $receiptMeta['docs'],
        'receipt_days' => $receiptMeta['days'],
        'conversion_pct' => $conversionPct,
        'conversion_applies' => $conversionApplies,
        'conversion_state' => $conversionState,
        'conversion_exceeds' => $conversionPct !== null && $conversionPct > 100.0,
        'products' => array_values($products),
        'pullout_rows' => $pulloutRows,
        'pullout_totals' => $pulloutTotals,
    ];
}
