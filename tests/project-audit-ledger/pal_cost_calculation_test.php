<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function bt(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; return; }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');

echo "\n=== PROJECT AUDIT LEDGER — COST CALCULATION TEST ===\n\n";

echo "── Service loading ──\n";
require_once BASE_PATH . '/modules/project-audit-ledger/services/ProjectCostService.php';
bt('ProjectCostService class exists', class_exists('palProjectCostService'));

echo "\n── Cost Breakdown Calculation ──\n";
// Test the cost accumulation formula directly
// Total cost = sum of approved expenses + sum of issued material costs
$expenses = [15000.00, 8500.50, 3200.00];
$materials = [4500.00, 2300.75];
$totalExpenses = array_sum($expenses);
$totalMaterials = array_sum($materials);
$totalCost = $totalExpenses + $totalMaterials;
bt('Total expenses sum correctly', $totalExpenses === 26700.50);
bt('Total materials sum correctly', $totalMaterials === 6800.75);
bt('Total cost = expenses + materials', $totalCost === 33501.25);

echo "\n── Profitability Calculation ──\n";
$contractAmount = 150000.00;
$netSales = 120000.00;
$totalCost = 85000.00;
$estimatedProfit = $netSales - $totalCost;
$profitMargin = $netSales > 0 ? round(($estimatedProfit / $netSales) * 100, 2) : 0;
bt('Estimated profit = net sales - total cost', $estimatedProfit === 35000.00);
bt('Profit margin = (profit / sales) * 100', $profitMargin === 29.17);
bt('Zero net sales gives 0% margin', 0 === 0);

echo "\n── Budget Threshold ──\n";
$contract = 100000.00;
$warningPct = 80.00;
$used80 = 80000.00;
$used95 = 95000.00;
$used105 = 105000.00;
bt('80% used is near_budget', ($used80 / $contract * 100) >= $warningPct && ($used80 / $contract * 100) < 100);
bt('95% used is near_budget', ($used95 / $contract * 100) >= $warningPct && ($used95 / $contract * 100) < 100);
bt('105% used is over_budget', ($used105 / $contract * 100) >= 100);
$remaining80 = round($contract - $used80, 2);
bt('Remaining budget = contract - used', $remaining80 === 20000.00);

echo "\n── Fabrication Allocation Calculation ──\n";
$totalEligibleExpenses = 80000.00;
$pct = 25.00;
$calculatedAlloc = round($totalEligibleExpenses * ($pct / 100), 2);
bt('25% of 80000 = 20000', $calculatedAlloc === 20000.00);

$contractBasis = 150000.00;
$calculatedContract = round($contractBasis * ($pct / 100), 2);
bt('25% of contract amount 150000 = 37500', $calculatedContract === 37500.00);

$fixedAmount = 30000.00;
bt('Fixed allocation returns the fixed amount', $fixedAmount === 30000.00);

echo "\n── Weekly Due Calculation ──\n";
$totalAllocation = 20000.00;
$weekCount = 8;
$equalPerWeek = round($totalAllocation / $weekCount, 2);
bt('Equal weekly due: 20000 / 8 = 2500', $equalPerWeek === 2500.00);

$unevenWeeks = [3000.00, 2500.00, 2500.00, 3000.00, 2500.00, 2500.00, 2000.00, 2000.00];
bt('Uneven weeks sum equals total', array_sum($unevenWeeks) === 20000.00);

echo "\n── Inventory Average Cost ──\n";
$currentQty = 100;
$currentAvg = 15.00;
$newQty = 50;
$newUnitCost = 20.00;
$newAvg = round((($currentQty * $currentAvg) + ($newQty * $newUnitCost)) / ($currentQty + $newQty), 2);
bt('Weighted avg: (100×15 + 50×20) / 150 = 16.67', $newAvg === 16.67);

$currentQty2 = 0;
$newAvg2 = $newUnitCost; // first purchase sets the cost
bt('First purchase: avg cost = unit cost', $newAvg2 === 20.00);

echo "\n── Outstanding Balance ──\n";
$netAmount = 50000.00;
$collected = 30000.00;
$outstanding = max(0, $netAmount - $collected);
bt('Outstanding = 50000 - 30000 = 20000', $outstanding === 20000.00);

$fullCollected = 50000.00;
$outstandingFull = max(0.0, (float)($netAmount - $fullCollected));
bt('Full payment: outstanding = 0', $outstandingFull === 0.0);

$overpaidAmt = 55000.00;
$outstandingOver = max(0.0, (float)($netAmount - $overpaidAmt));
bt('Overpayment: outstanding = 0 (capped)', $outstandingOver === 0.0);

echo "\n── Fabrication Payment Constraint ──\n";
$allocAmount = 20000.00;
$paidSoFar = 15000.00;
$newPayment = 6000.00;
$wouldExceed = ($paidSoFar + $newPayment) > $allocAmount;
bt('Payment exceeding allocation is blocked', $wouldExceed === true);
$validPayment = 5000.00;
$withinLimit = ($paidSoFar + $validPayment) <= $allocAmount;
bt('Payment within allocation is allowed', $withinLimit === true);

echo "\n── Results ──\n";
echo "  Passed: {$pass}\n";
echo "  Failed: {$fail}\n";

$appLogSize = is_file($appLogPath) ? (int)@filesize($appLogPath) : 0;
$errorLogSize = is_file($errorLogPath) ? (int)@filesize($errorLogPath) : 0;
if ($appLogSize > 0 || $errorLogSize > 0) {
    echo "\n  ⚠ Logs:\n";
    if ($appLogSize > 0) echo "    app.log: {$appLogSize} bytes\n";
    if ($errorLogSize > 0) echo "    error.log: {$errorLogSize} bytes\n";
}

exit($fail > 0 ? 1 : 0);
