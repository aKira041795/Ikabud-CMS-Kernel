<?php

declare(strict_types=1);

if (getenv('AW_E2E_TENANT_ID') !== '441' || getenv('AW_E2E_ALLOW_RESET') !== '1') {
    fwrite(STDERR, "Refusing financial evidence read outside guarded tenant 441 E2E.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = parse_url(getenv('APP_URL') ?: 'http://zapattendance.test', PHP_URL_HOST) ?: 'zapattendance.test';
$_SERVER['REQUEST_METHOD'] = 'CLI';
$basePath = dirname(__DIR__, 5);
require_once $basePath . '/bootstrap.php';
require_once $basePath . '/modules/attendance-wage/helpers.php';
if (aw_tenant_id() !== 441) throw new RuntimeException('Resolved tenant is not 441.');

$periodId = (int)(getenv('AW_E2E_PERIOD_ID') ?: 0);
$advanceId = (int)(getenv('AW_E2E_ADVANCE_ID') ?: 0);
if ($periodId <= 0) throw new RuntimeException('AW_E2E_PERIOD_ID is required.');
$db = aw_db();
$query = static function (PDO $db, string $sql, array $params): array {
    $stmt = $db->prepare($sql); $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
};
$computations = $query($db, 'SELECT computation_id,employee_profile_id,status,gross_pay,total_deductions,net_pay,payment_date FROM salary_computations WHERE tenant_id=:tid AND payroll_period_id=:pid ORDER BY employee_profile_id', [':tid'=>'441', ':pid'=>$periodId]);
$repayments = $advanceId > 0 ? $query($db, 'SELECT repayment_id,advance_id,payroll_period_id,amount,status FROM cash_advance_repayments WHERE advance_id=:aid ORDER BY repayment_id', [':aid'=>$advanceId]) : [];
$advances = $advanceId > 0 ? $query($db, 'SELECT advance_id,status,amount,balance,paid_installments FROM cash_advances WHERE tenant_id=:tid AND advance_id=:aid', [':tid'=>'441', ':aid'=>$advanceId]) : [];
$adjustments = $query($db, 'SELECT adjustment_id,user_id,adjustment_type,amount,status FROM salary_adjustments WHERE tenant_id=:tid AND payroll_period_id=:pid ORDER BY adjustment_id', [':tid'=>'441', ':pid'=>$periodId]);
$deductions = $query($db, 'SELECT deduction_id,user_id,amount,status FROM employee_deductions WHERE tenant_id=:tid AND deduction_date BETWEEN (SELECT start_date FROM payroll_periods WHERE period_id=:pid1) AND (SELECT end_date FROM payroll_periods WHERE period_id=:pid2) ORDER BY deduction_id', [':tid'=>'441', ':pid1'=>$periodId, ':pid2'=>$periodId]);
echo json_encode(['computations'=>$computations,'repayments'=>$repayments,'advances'=>$advances,'adjustments'=>$adjustments,'deductions'=>$deductions], JSON_THROW_ON_ERROR) . PHP_EOL;
