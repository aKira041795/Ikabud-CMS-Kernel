<?php

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-forecast', TestHarness::MODE_PURE);
$h->fingerprint('modules/daily-ledger/helpers/reporting.php');
require_once $h->basePath() . '/modules/daily-ledger/helpers/reporting.php';

$h->section('Moving Average and Adjustments');
// Every row is scoped to a BRANCH: a production suggestion is actioned at a place, so the branch is
// part of the group key. Stock on hand is the branch's OWN counted ending (ending_qty), never a
// commissary's undelivered finished goods.
$sales = [
    ['branch_id' => 1, 'branch_name' => 'Branch One', 'product_id' => 99001, 'sku' => 'T-1', 'product_name' => 'Test Bread', 'shift' => 'AM', 'ledger_date' => '2026-08-01', 'sales' => 10],
    ['branch_id' => 1, 'branch_name' => 'Branch One', 'product_id' => 99001, 'sku' => 'T-1', 'product_name' => 'Test Bread', 'shift' => 'AM', 'ledger_date' => '2026-08-02', 'sales' => 20],
    ['branch_id' => 1, 'branch_name' => 'Branch One', 'product_id' => 99001, 'sku' => 'T-1', 'product_name' => 'Test Bread', 'shift' => 'AM', 'ledger_date' => '2026-08-03', 'sales' => 30],
    ['branch_id' => 1, 'branch_name' => 'Branch One', 'product_id' => 99001, 'sku' => 'T-1', 'product_name' => 'Test Bread', 'shift' => 'PM', 'ledger_date' => '2026-08-03', 'sales' => null],
];
$variances = [
    ['branch_id' => 1, 'product_id' => 99001, 'shift' => 'AM', 'variance' => 3],
    ['branch_id' => 1, 'product_id' => 99001, 'shift' => 'AM', 'variance' => -4],
];
$inventory = [
    ['branch_id' => 1, 'product_id' => 99001, 'ledger_date' => '2026-08-01', 'wastage_qty' => 1, 'ending_qty' => 2],
    ['branch_id' => 1, 'product_id' => 99001, 'ledger_date' => '2026-08-02', 'wastage_qty' => 3, 'ending_qty' => 4],
    ['branch_id' => 1, 'product_id' => 99001, 'ledger_date' => '2026-08-03', 'wastage_qty' => 2, 'ending_qty' => 5],
];
$forecast = dl_forecastDemand($sales, $variances, $inventory, 3, 0.10);
$row = $forecast[0] ?? [];
$h->test('three-day moving average is hand-computed 20', ($row['average_sales'] ?? null) === 20.0);
$h->test('positive variance adjustment is averaged without negative netting', ($row['variance_adjustment'] ?? null) === 1.5);
$h->test('wastage adjustment averages recorded wastage', ($row['wastage_adjustment'] ?? null) === 2.0);
$h->test('projected demand includes variance and wastage', ($row['projected_demand'] ?? null) === 23.5);
$h->test('the branch\'s own latest counted ending is read as stock on hand', ($row['stock_on_hand'] ?? null) === 5);
$h->test('the row names its branch', ($row['branch_name'] ?? '') === 'Branch One' && ($row['branch_id'] ?? 0) === 1);
$h->test('suggested production applies safety and the branch\'s own stock', ($row['suggested_production'] ?? null) === 21);
$h->test('branch stock is applied only once across shifts', ($row['inventory_applied'] ?? null) === 5);
$h->test('pending null sales are excluded', count($forecast) === 1);

$h->section('Per-branch separation');
// The defect this guards: two branches selling the same product were averaged into ONE suggestion
// nobody could action. Measured on tenant 207, 356 rows served 788 branch x product x shift streams.
$perBranch = dl_forecastDemand([
    ['branch_id' => 1, 'branch_name' => 'Branch One', 'product_id' => 99003, 'product_name' => 'Shared Bread', 'shift' => 'AM', 'ledger_date' => '2026-08-03', 'sales' => 100],
    ['branch_id' => 2, 'branch_name' => 'Branch Two', 'product_id' => 99003, 'product_name' => 'Shared Bread', 'shift' => 'AM', 'ledger_date' => '2026-08-03', 'sales' => 10],
], [], [
    ['branch_id' => 1, 'product_id' => 99003, 'ledger_date' => '2026-08-03', 'ending_qty' => 0],
    ['branch_id' => 2, 'product_id' => 99003, 'ledger_date' => '2026-08-03', 'ending_qty' => 0],
], 3, 0.0);
$branchOne = null;
$branchTwo = null;
foreach ($perBranch as $perBranchRow) {
    if ((int)$perBranchRow['branch_id'] === 1) {
        $branchOne = $perBranchRow;
    }
    if ((int)$perBranchRow['branch_id'] === 2) {
        $branchTwo = $perBranchRow;
    }
}
$h->test('one product+shift sold at two branches yields two rows, not one average',
    count($perBranch) === 2 && $branchOne !== null && $branchTwo !== null);
$h->test('each branch keeps its own sales average',
    ($branchOne['average_sales'] ?? null) === 100.0 && ($branchTwo['average_sales'] ?? null) === 10.0);
$h->test('each branch gets its own suggestion, not a shared one',
    ($branchOne['suggested_production'] ?? null) === 100 && ($branchTwo['suggested_production'] ?? null) === 10);
$h->test('one branch\'s stock is never applied to another branch',
    dl_forecastDemand([
        ['branch_id' => 1, 'branch_name' => 'Branch One', 'product_id' => 99004, 'product_name' => 'Stocked Bread', 'shift' => 'AM', 'ledger_date' => '2026-08-03', 'sales' => 50],
        ['branch_id' => 2, 'branch_name' => 'Branch Two', 'product_id' => 99004, 'product_name' => 'Stocked Bread', 'shift' => 'AM', 'ledger_date' => '2026-08-03', 'sales' => 50],
    ], [], [
        ['branch_id' => 1, 'product_id' => 99004, 'ledger_date' => '2026-08-03', 'ending_qty' => 40],
    ], 3, 0.0)[1]['suggested_production'] === 50
);

$twoShifts = dl_forecastDemand([
    ['branch_id' => 1, 'branch_name' => 'Branch One', 'product_id' => 99002, 'product_name' => 'Shift Bread', 'shift' => 'AM', 'ledger_date' => '2026-08-03', 'sales' => 10],
    ['branch_id' => 1, 'branch_name' => 'Branch One', 'product_id' => 99002, 'product_name' => 'Shift Bread', 'shift' => 'PM', 'ledger_date' => '2026-08-03', 'sales' => 10],
], [], [
    ['branch_id' => 1, 'product_id' => 99002, 'ledger_date' => '2026-08-03', 'wastage_qty' => 4, 'ending_qty' => 8],
], 3, 0.0);
$h->test('product-level wastage is distributed rather than duplicated per shift',
    count($twoShifts) === 2
    && array_sum(array_column($twoShifts, 'wastage_adjustment')) === 4.0
);
$h->test('branch stock is consumed once across shift suggestions',
    array_sum(array_column($twoShifts, 'inventory_applied')) === 8
    && array_sum(array_column($twoShifts, 'suggested_production')) === 16
);

$h->section('Determinism and Read-only Contract');
$again = dl_forecastDemand($sales, $variances, $inventory, 3, 0.10);
$h->test('same recorded inputs produce identical forecast', $again === $forecast);
$source = file_get_contents($h->basePath() . '/modules/daily-ledger/helpers/reporting.php');
$forecastSlice = substr($source, strpos($source, 'function dl_forecastRows'));
$h->test('forecast SQL contains no window function', stripos($forecastSlice, ' OVER(') === false && stripos($forecastSlice, ' OVER (') === false);
$h->test('forecast SQL contains no CTE', preg_match('/\bWITH\s+[a-z_]+\s+AS\s*\(/i', $forecastSlice) !== 1);
$h->test('forecast path contains no ledger mutation statements', preg_match('/\b(INSERT|UPDATE|DELETE)\s+(INTO\s+)?dl_(daily_ledger|commissary_product_ledger|production_movements)/i', $forecastSlice) !== 1);

$now = new DateTimeImmutable('2026-08-16 09:00:00+08:00');
$h->test('daily schedule targets the previous business date', dl_reportScheduleWindow('daily', $now) === ['date_from' => '2026-08-15', 'date_to' => '2026-08-15']);
$h->test('weekly schedule targets the complete prior Monday-Sunday window', dl_reportScheduleWindow('weekly', $now) === ['date_from' => '2026-08-03', 'date_to' => '2026-08-09']);
$h->test('monthly schedule targets the complete prior calendar month', dl_reportScheduleWindow('monthly', $now) === ['date_from' => '2026-07-01', 'date_to' => '2026-07-31']);
$h->test('schedule due check prevents duplicate runs in the same cadence',
    !dl_reportScheduleIsDue('daily', '2026-08-16T01:00:00+08:00', $now)
    && !dl_reportScheduleIsDue('weekly', '2026-08-11T01:00:00+08:00', $now)
    && !dl_reportScheduleIsDue('monthly', '2026-08-01T01:00:00+08:00', $now)
);

$h->done();
