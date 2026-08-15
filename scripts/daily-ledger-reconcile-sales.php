<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

function dailyLedgerSalesReconcileUsage(): void
{
    echo "Daily Ledger Sales Reconciliation\n";
    echo "\n";
    echo "Usage:\n";
    echo "  php scripts/daily-ledger-reconcile-sales.php --tenant=ID [--branch=ID] [--date-from=YYYY-MM-DD] [--date-to=YYYY-MM-DD] [--apply]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --tenant=ID         Required tenant id.\n";
    echo "  --branch=ID         Optional branch filter.\n";
    echo "  --date-from=DATE    Optional inclusive lower ledger_date bound.\n";
    echo "  --date-to=DATE      Optional inclusive upper ledger_date bound.\n";
    echo "  --apply             Persist corrected sales values. Default is dry-run.\n";
    echo "  --help              Show this message.\n";
}

function dailyLedgerSalesReconcileArg(?string $prefix): ?string
{
    if ($prefix === null) {
        return null;
    }

    foreach ($_SERVER['argv'] ?? [] as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }

    return null;
}

function dailyLedgerSalesReconcileDate(?string $value, string $label): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        fwrite(STDERR, "Invalid {$label}: {$value}\n");
        exit(1);
    }

    return $value;
}

$argvList = $_SERVER['argv'] ?? [];
if (in_array('--help', $argvList, true)) {
    dailyLedgerSalesReconcileUsage();
    exit(0);
}

$tenantId = (int)(dailyLedgerSalesReconcileArg('--tenant=') ?? 0);
$branchId = (int)(dailyLedgerSalesReconcileArg('--branch=') ?? 0);
$dateFrom = dailyLedgerSalesReconcileDate(dailyLedgerSalesReconcileArg('--date-from='), 'date-from');
$dateTo = dailyLedgerSalesReconcileDate(dailyLedgerSalesReconcileArg('--date-to='), 'date-to');
$apply = in_array('--apply', $argvList, true);

if ($tenantId <= 0) {
    dailyLedgerSalesReconcileUsage();
    fwrite(STDERR, "\nMissing required --tenant=ID\n");
    exit(1);
}

$db = app()->dbForTenant($tenantId);
if (!$db instanceof PDO) {
    fwrite(STDERR, "Unable to resolve tenant DB for tenant {$tenantId}\n");
    exit(1);
}

// Null-aware canonical sales expression: an uncounted ending (bal_end NULL)
// yields NULL (pending), never a fabricated counted value. Mismatch rows are
// `sales <> expr`, so pending rows are excluded and --apply can never stamp a
// pending row as sold.
$salesExpr = 'CASE WHEN bal_end IS NULL THEN NULL ELSE GREATEST(0, COALESCE(beg_bal,0) + COALESCE(addtl,0) - COALESCE(withdraw,0) - COALESCE(bal_end,0)) END';
$amountExpr = '(' . $salesExpr . ') * COALESCE(price_snapshot,0)';
$scopeWhere = [];
$mismatchWhere = ['sales <> ' . $salesExpr];
$params = [];

if ($branchId > 0) {
    $scopeWhere[] = 'branch_id = :branch_id';
    $mismatchWhere[] = 'branch_id = :branch_id';
    $params[':branch_id'] = $branchId;
}
if ($dateFrom !== null) {
    $scopeWhere[] = 'ledger_date >= :date_from';
    $mismatchWhere[] = 'ledger_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== null) {
    $scopeWhere[] = 'ledger_date <= :date_to';
    $mismatchWhere[] = 'ledger_date <= :date_to';
    $params[':date_to'] = $dateTo;
}

$scopeWhereSql = $scopeWhere !== [] ? implode(' AND ', $scopeWhere) : '1=1';
$mismatchWhereSql = implode(' AND ', $mismatchWhere);
$summarySql = 'SELECT COUNT(*) AS affected_rows,
                      COALESCE(SUM(sales),0) AS legacy_qty,
                      COALESCE(SUM(sales * COALESCE(price_snapshot,0)),0) AS legacy_amount,
                      COALESCE(SUM(' . $salesExpr . '),0) AS computed_qty,
                      COALESCE(SUM(' . $amountExpr . '),0) AS computed_amount,
                      MIN(ledger_date) AS first_date,
                      MAX(ledger_date) AS last_date
                 FROM dl_daily_ledger
                WHERE ' . $mismatchWhereSql;
$scopeSummarySql = 'SELECT COUNT(*) AS scoped_rows,
                           COALESCE(SUM(sales),0) AS stored_qty,
                           COALESCE(SUM(sales * COALESCE(price_snapshot,0)),0) AS stored_amount,
                           COALESCE(SUM(' . $salesExpr . '),0) AS computed_qty,
                           COALESCE(SUM(' . $amountExpr . '),0) AS computed_amount,
                           MIN(ledger_date) AS first_date,
                           MAX(ledger_date) AS last_date
                      FROM dl_daily_ledger
                     WHERE ' . $scopeWhereSql;
$sampleSql = 'SELECT branch_id, product_id, ledger_date, sales AS legacy_sales,
                     ' . $salesExpr . ' AS computed_sales,
                     COALESCE(price_snapshot,0) AS price_snapshot
                FROM dl_daily_ledger
               WHERE ' . $mismatchWhereSql . '
               ORDER BY ledger_date ASC, branch_id ASC, product_id ASC
               LIMIT 20';

$summaryStmt = $db->prepare($summarySql);
$summaryStmt->execute($params);
$before = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$scopeSummaryStmt = $db->prepare($scopeSummarySql);
$scopeSummaryStmt->execute($params);
$scopeBefore = $scopeSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$sampleStmt = $db->prepare($sampleSql);
$sampleStmt->execute($params);
$sampleRows = $sampleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$result = [
    'tenant_id' => $tenantId,
    'branch_id' => $branchId > 0 ? $branchId : null,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'apply' => $apply,
    'before' => $before,
    'scope_before' => $scopeBefore,
    'sample_rows' => $sampleRows,
];

if (!$apply || (int)($before['affected_rows'] ?? 0) === 0) {
    write_log('daily-ledger sales reconciliation dry-run', 'info', $result);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$updateSql = 'UPDATE dl_daily_ledger
                 SET sales = ' . $salesExpr . ',
                     updated_at = CURRENT_TIMESTAMP
               WHERE ' . $mismatchWhereSql;

$db->beginTransaction();
try {
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->execute($params);
    $updatedRows = $updateStmt->rowCount();

    $remainingStmt = $db->prepare($summarySql);
    $remainingStmt->execute($params);
    $remaining = $remainingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $afterStmt = $db->prepare($scopeSummarySql);
    $afterStmt->execute($params);
    $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $db->commit();

    $result['updated_rows'] = $updatedRows;
    $result['remaining_mismatches'] = $remaining;
    $result['after'] = $after;
    $result['reconciled_at'] = gmdate('c');
    write_log('daily-ledger sales reconciliation apply', 'info', $result);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $result['error'] = $e->getMessage();
    write_log('daily-ledger sales reconciliation failed', 'error', $result);
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
