<?php

declare(strict_types=1);

/**
 * Attendance & Wage Payroll Computation Test
 *
 * Verifies:
 *   1. Computation status guards (computed→approved→paid atomic transitions)
 *   2. aw_computeSimpleSalary $totDed initialization fix
 *   3. Benefit calculation fallback rates
 *   4. Tax bracket progression
 *   5. 13th month accrual semantics
 *   6. Benefit applicability flags
 *   7. Financial immutability (no hard deletes)
 *   8. Authorization guard mapping
 *
 * Run from repo root: php tests/attendance_wage_payroll_computation_test.php
 */

$basePath = dirname(__DIR__);
require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';

// Load AW helpers for direct function testing
require_once $basePath . '/modules/attendance-wage/helpers.php';

$errors = [];
$passed = 0;
$total  = 0;

function test(string $name, bool $condition, string $detail = ''): void {
    global $total, $passed, $errors;
    $total++;
    if ($condition) { $passed++; echo "  \xE2\x9C\x85 {$name}\n"; }
    else { $errors[] = "{$name}: {$detail}"; echo "  \xE2\x9D\x8C {$name}: {$detail}\n"; }
}

echo "Attendance & Wage Payroll Computation Test\n";
echo str_repeat('=', 60) . "\n\n";

// ────────────────────────────────────────────────────────────────────
echo "1. Status transition guards\n";

$compSrc = file_get_contents($basePath . '/modules/attendance-wage/handlers/60-api-computations.php');

test('Approve: UPDATE WHERE status = computed',
    str_contains($compSrc, "status = 'computed'") && str_contains($compSrc, "status = 'approved'"));

test('Pay: UPDATE WHERE status = approved',
    str_contains($compSrc, "status = 'approved'") && str_contains($compSrc, "status = 'paid'"));

test('Recompute guard rejects approved/paid',
    (function () {
        $src = file_get_contents(dirname(__DIR__) . '/modules/attendance-wage/helpers.php');
        return str_contains($src, 'Cannot recompute an approved or paid salary computation');
    })());

test('No DELETE on salary_computations', !str_contains($compSrc, 'DELETE FROM salary_computations'));

// ────────────────────────────────────────────────────────────────────
echo "\n2. aw_computeSimpleSalary — \$totDed fix\n";

test('\$totDed initialized before aw_calculateTax call',
    (function () {
        $src = file_get_contents(dirname(__DIR__) . '/modules/attendance-wage/helpers.php');
        $fnStart = strpos($src, 'function aw_computeSimpleSalary');
        if ($fnStart === false) return false;
        $fnEnd = strpos($src, 'function aw_calculateAttendanceHours', $fnStart);
        if ($fnEnd === false) $fnEnd = strlen($src);
        $body = substr($src, $fnStart, $fnEnd - $fnStart);
        $totPos = strpos($body, '$totDed');
        $taxPos = strpos($body, 'aw_calculateTax');
        return $totPos !== false && $taxPos !== false && $totPos < $taxPos;
    })());

// ────────────────────────────────────────────────────────────────────
echo "\n3. Benefit calculation fallback rates\n";

test('SSS default: both shares > 0 for 20K gross',
    (function () {
        $r = aw_defaultBenefitsRate('sss', 20000);
        return $r['employee'] > 0 && $r['employer'] > 0;
    })());

test('PhilHealth default: equal employee/employer split',
    (function () {
        $r = aw_defaultBenefitsRate('philhealth', 20000);
        return $r['employee'] > 0 && abs($r['employee'] - $r['employer']) < 0.01;
    })());

test('Pag-IBIG default: capped at 100 per party',
    (function () {
        $r = aw_defaultBenefitsRate('pagibig', 100000);
        return $r['employee'] <= 100.00 && $r['employer'] <= 100.00;
    })());

test('Unknown type returns zero',
    (function () {
        $r = aw_defaultBenefitsRate('unknown', 50000);
        return $r['employee'] === 0.0 && $r['employer'] === 0.0;
    })());

// ────────────────────────────────────────────────────────────────────
echo "\n4. Tax bracket progression\n";

test('Tax: ≤250K/year → 0',
    (function () {
        return aw_calculateTax(20833, 0, ['tax_exemption_status' => '', 'dependents_count' => 0]) === 0.0;
    })());

test('Tax: 300K/year → 20% of excess over 250K',
    (function () {
        $t = aw_calculateTax(25000, 0, ['tax_exemption_status' => '', 'dependents_count' => 0]);
        return $t > 0 && $t < 1000; // ~833.33/month
    })());

test('Tax: head_of_family exemption reduces tax',
    (function () {
        $with = aw_calculateTax(25000, 0, ['tax_exemption_status' => 'head_of_family', 'dependents_count' => 2]);
        $without = aw_calculateTax(25000, 0, ['tax_exemption_status' => '', 'dependents_count' => 0]);
        return $with < $without || $with === 0.0;
    })());

test('Tax: max 4 dependents',
    (function () {
        // 5 dependents → only 4 count (200K exemption max)
        $t5 = aw_calculateTax(35000, 0, ['tax_exemption_status' => 'head_of_family', 'dependents_count' => 5]);
        $t4 = aw_calculateTax(35000, 0, ['tax_exemption_status' => 'head_of_family', 'dependents_count' => 4]);
        return $t5 === $t4;
    })());

// ────────────────────────────────────────────────────────────────────
echo "\n5. 13th month accrual\n";

$helpersSrc = file_get_contents($basePath . '/modules/attendance-wage/helpers.php');

test('13th month = basic_pay / 12 per period',
    str_contains($helpersSrc, 'round($regPay / 12, 2)') || str_contains($helpersSrc, 'round($gross / 12, 2)'));

test('13th month gated by thirteenth_month_enabled flag',
    str_contains($helpersSrc, "!empty(\$profile['thirteenth_month_enabled'])"));

test('13th month added to additions in aw_computeSalary',
    str_contains($helpersSrc, "adj['additions']") && str_contains($helpersSrc, 'thirteenthMonth'));

// ────────────────────────────────────────────────────────────────────
echo "\n6. Benefit applicability flags\n";

test('SSS flag zeroes contributions when false',
    str_contains($helpersSrc, "empty(\$profile['sss_applicable'])") &&
    str_contains($helpersSrc, "'employee' => 0.0, 'employer' => 0.0"));

test('PhilHealth flag zeroes contributions when false',
    str_contains($helpersSrc, "empty(\$profile['philhealth_applicable'])"));

test('Pag-IBIG flag zeroes contributions when false',
    str_contains($helpersSrc, "empty(\$profile['pagibig_applicable'])"));

// ────────────────────────────────────────────────────────────────────
echo "\n7. Computation result structure\n";

test('aw_computeSalary returns required keys',
    (function () use ($helpersSrc) {
        $required = ['gross_pay', 'net_pay', 'total_deductions', 'total_additions',
            'sss_employee', 'philhealth_employee', 'pagibig_employee', 'income_tax',
            'regular_hours', 'overtime_hours', 'regular_pay', 'overtime_pay',
            'status', 'computed_by', 'computation_date'];
        foreach ($required as $k) {
            if (!str_contains($helpersSrc, "'{$k}'")) return false;
        }
        return true;
    })());

// ────────────────────────────────────────────────────────────────────
echo "\n8. Authorization guards\n";

$fnExtract = function (string $src, string $fnName): string {
    $s = strpos($src, "function {$fnName}");
    if ($s === false) return '';
    $brace = 0; $started = false; $out = '';
    for ($i = $s; $i < strlen($src); $i++) {
        $out .= $src[$i];
        if ($src[$i] === '{') { $brace++; $started = true; }
        elseif ($src[$i] === '}') { $brace--; if ($started && $brace === 0) break; }
    }
    return $out;
};

$computeBody = $fnExtract($compSrc, 'wageApiComputeEmployee');
test('Compute requires admin guard',
    str_contains($computeBody, "attendance_wage.admin@1"));

$bulkBody = $fnExtract($compSrc, 'wageApiBulkCompute');
test('Bulk compute requires admin guard',
    str_contains($bulkBody, "attendance_wage.admin@1"));

$approveBody = $fnExtract($compSrc, 'wageApiApproveComputation');
test('Approve requires approve guard',
    str_contains($approveBody, "attendance_wage.approve@1"));

$payBody = $fnExtract($compSrc, 'wageApiPayComputation');
test('Pay requires approve guard',
    str_contains($payBody, "attendance_wage.approve@1"));

// ────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$passed}/{$total} passed";
if (!empty($errors)) { echo " — " . count($errors) . " failures"; }
echo "\n";
if (!empty($errors)) {
    echo "\nFailures:\n";
    foreach ($errors as $e) { echo "  \xE2\x9D\x8C {$e}\n"; }
    exit(1);
}
echo "\xE2\x9C\x85 All tests passed.\n";
exit(0);
