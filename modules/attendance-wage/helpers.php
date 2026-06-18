<?php

declare(strict_types=1);

/**
 * Attendance & Wage — Helpers
 *
 * Capability handlers for entity views + core business logic.
 * Auto-loaded globally when the module is enabled.
 */

app()->registerAuthTable('attendance-wage', 'attendance_wage_users');

function attendance_wage_capability_handlers(): array
{
    return [
        // Module capabilities
        'attendance_wage.clock@1'            => 'aw_cap_clock_1',
        'attendance_wage.read@1'             => 'aw_cap_read_1',
        'attendance_wage.manage@1'           => 'aw_cap_manage_1',
        'attendance_wage.approve@1'          => 'aw_cap_approve_1',
        'attendance_wage.admin@1'            => 'aw_cap_admin_1',
        // Entity list handlers
        'entity.list.attendance_record@1'    => 'aw_cap_entity_list_attendance_record_1',
        'entity.list.employee_profile@1'     => 'aw_cap_entity_list_employee_profile_1',
        'entity.list.payroll_period@1'       => 'aw_cap_entity_list_payroll_period_1',
        'entity.list.salary_computation@1'   => 'aw_cap_entity_list_salary_computation_1',
        'entity.list.salary_adjustment@1'    => 'aw_cap_entity_list_salary_adjustment_1',
        'entity.list.employee_deduction@1'   => 'aw_cap_entity_list_employee_deduction_1',
        'entity.list.holiday@1'              => 'aw_cap_entity_list_holiday_1',
        'entity.list.cash_advance@1'         => 'aw_cap_entity_list_cash_advance_1',
        'entity.get.attendance_record@1'     => 'aw_cap_entity_get_attendance_record_1',
        'entity.get.employee_profile@1'      => 'aw_cap_entity_get_employee_profile_1',
        'entity.get.payroll_period@1'        => 'aw_cap_entity_get_payroll_period_1',
        'entity.get.salary_computation@1'    => 'aw_cap_entity_get_salary_computation_1',
        'entity.get.salary_adjustment@1'     => 'aw_cap_entity_get_salary_adjustment_1',
        'entity.get.employee_deduction@1'    => 'aw_cap_entity_get_employee_deduction_1',
        'entity.get.holiday@1'               => 'aw_cap_entity_get_holiday_1',
        'entity.get.cash_advance@1'          => 'aw_cap_entity_get_cash_advance_1',
        'attendance_wage.read@1'             => 'aw_cap_read_1',
        'attendance_wage.manage@1'           => 'aw_cap_manage_1',
        'attendance_wage.approve@1'          => 'aw_cap_approve_1',
        'attendance_wage.admin@1'            => 'aw_cap_admin_1',
    ];
}

// Module capabilities
function aw_cap_clock_1(mixed $payload): array  { return ['granted' => true]; }
function aw_cap_read_1(mixed $payload): array   { return ['granted' => true]; }
function aw_cap_manage_1(mixed $payload): array { return ['granted' => true]; }
function aw_cap_approve_1(mixed $payload): array { return ['granted' => true]; }
function aw_cap_admin_1(mixed $payload): array  { return ['granted' => true]; }

function aw_db(): \PDO
{
    return \app()->dbForTenant((int)(\app()->tenant()->current() ?? 0));
}

function aw_allowedSort(mixed $payload, string $default, array $allowed): string
{
    $field = (string)($payload['sort']['field'] ?? $default);
    return in_array($field, $allowed, true) ? $field : $default;
}

function aw_sortDir(mixed $payload): string
{
    $dir = strtoupper((string)($payload['sort']['direction'] ?? 'DESC'));
    return in_array($dir, ['ASC', 'DESC'], true) ? $dir : 'DESC';
}

// ── Entity LIST handlers ──

function aw_cap_entity_list_attendance_record_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 30), 100);
    $sortField = aw_allowedSort($payload, 'clock_in', ['attendance_id', 'clock_in', 'clock_out', 'status']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        $stmt = $db->query("SELECT ar.attendance_id AS id, CONCAT(u.first_name, ' ', u.last_name) AS employee_name, s.name AS store_name, ar.clock_in, ar.clock_out, ar.status, ar.created_at FROM attendance_records ar JOIN users u ON u.user_id = ar.user_id LEFT JOIN stores s ON s.store_id = ar.store_id ORDER BY {$sortField} {$sortDir} LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_employee_profile_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 25), 100);
    $sortField = aw_allowedSort($payload, 'hire_date', ['profile_id', 'employee_number', 'position', 'department', 'hire_date']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        $stmt = $db->query("SELECT ep.profile_id AS id, CONCAT(u.first_name, ' ', u.last_name) AS name, ep.employee_number, ep.position, ep.department, ep.salary_type, ep.basic_salary, ep.employment_status, ep.hire_date FROM employee_profiles ep JOIN users u ON u.user_id = ep.user_id WHERE ep.is_active = 1 ORDER BY {$sortField} {$sortDir} LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM employee_profiles WHERE is_active = 1')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_payroll_period_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 12), 50);
    $sortField = aw_allowedSort($payload, 'start_date', ['period_id', 'period_name', 'start_date', 'end_date', 'status']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        $stmt = $db->query("SELECT period_id AS id, period_name, period_type, start_date, end_date, pay_date, status, total_employees, total_gross_pay, total_deductions, total_net_pay FROM payroll_periods ORDER BY {$sortField} {$sortDir} LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM payroll_periods')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_salary_computation_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 25), 100);
    $sortField = aw_allowedSort($payload, 'created_at', ['computation_id', 'gross_pay', 'net_pay', 'status', 'created_at']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        $stmt = $db->query("SELECT sc.computation_id AS id, CONCAT(u.first_name, ' ', u.last_name) AS employee_name, pp.period_name, sc.gross_pay, sc.total_deductions, sc.net_pay, sc.status FROM salary_computations sc JOIN users u ON u.user_id = sc.user_id JOIN payroll_periods pp ON pp.period_id = sc.payroll_period_id ORDER BY {$sortField} {$sortDir} LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM salary_computations')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_salary_adjustment_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 20), 50);
    $sortField = aw_allowedSort($payload, 'effective_date', ['adjustment_id', 'adjustment_type', 'amount', 'status', 'effective_date']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        $stmt = $db->query("SELECT sa.adjustment_id AS id, CONCAT(u.first_name, ' ', u.last_name) AS employee_name, sa.adjustment_type, sa.amount, sa.description, sa.status, sa.effective_date FROM salary_adjustments sa JOIN users u ON u.user_id = sa.user_id ORDER BY {$sortField} {$sortDir} LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM salary_adjustments')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_employee_deduction_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 20), 50);
    $sortField = aw_allowedSort($payload, 'deduction_date', ['deduction_id', 'amount', 'status', 'deduction_date']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        $stmt = $db->query("SELECT deduction_id AS id, employee_name, amount, description, status, deduction_date FROM employee_deductions ORDER BY {$sortField} {$sortDir} LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM employee_deductions')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_holiday_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 30), 50);
    try {
        $db = aw_db();
        $year = date('Y');
        $stmt = $db->query("SELECT holiday_id AS id, holiday_name, holiday_date, holiday_type, pay_multiplier, is_recurring, is_active FROM holidays WHERE (YEAR(holiday_date) = {$year} OR is_recurring = 1) AND is_active = 1 ORDER BY holiday_date ASC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM holidays WHERE is_active = 1')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

function aw_cap_entity_list_cash_advance_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = min((int)($payload['limit'] ?? 20), 50);
    $sortField = aw_allowedSort($payload, 'request_date', ['advance_id', 'amount', 'balance', 'status', 'request_date']);
    $sortDir = aw_sortDir($payload);
    try {
        $db = aw_db();
        $stmt = $db->query("SELECT ca.advance_id AS id, CONCAT(u.first_name, ' ', u.last_name) AS employee_name, ca.amount, ca.balance, ca.repayment_type, ca.status, ca.request_date FROM cash_advances ca JOIN users u ON u.user_id = ca.user_id ORDER BY {$sortField} {$sortDir} LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $total = (int)($db->query('SELECT COUNT(*) FROM cash_advances')->fetchColumn());
        return ['rows' => $rows, 'total' => $total];
    } catch (\Throwable $e) { return ['rows' => [], 'total' => 0, 'error' => $e->getMessage()]; }
}

// ── Entity GET handlers ──

function aw_cap_entity_get_attendance_record_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare('SELECT * FROM attendance_records WHERE attendance_id=:id LIMIT 1'); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_employee_profile_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare("SELECT ep.*, CONCAT(u.first_name,' ',u.last_name) AS name FROM employee_profiles ep JOIN users u ON u.user_id=ep.user_id WHERE ep.profile_id=:id LIMIT 1"); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_payroll_period_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare('SELECT * FROM payroll_periods WHERE period_id=:id LIMIT 1'); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_salary_computation_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare("SELECT sc.*, CONCAT(u.first_name,' ',u.last_name) AS employee_name, pp.period_name FROM salary_computations sc JOIN users u ON u.user_id=sc.user_id JOIN payroll_periods pp ON pp.period_id=sc.payroll_period_id WHERE sc.computation_id=:id LIMIT 1"); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_salary_adjustment_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare('SELECT * FROM salary_adjustments WHERE adjustment_id=:id LIMIT 1'); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_employee_deduction_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare('SELECT * FROM employee_deductions WHERE deduction_id=:id LIMIT 1'); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_holiday_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare('SELECT * FROM holidays WHERE holiday_id=:id LIMIT 1'); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }
function aw_cap_entity_get_cash_advance_1(mixed $payload): array
{ $id=(int)($payload['id']??0); if($id<=0)return[]; $db=aw_db(); $s=$db->prepare("SELECT ca.*, CONCAT(u.first_name,' ',u.last_name) AS employee_name FROM cash_advances ca JOIN users u ON u.user_id=ca.user_id WHERE ca.advance_id=:id LIMIT 1"); $s->execute([':id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return is_array($r)?$r:[]; }

// ── Core salary computation (ported from CI) ──

function aw_computeSalary(int $userId, int $periodId, int $computedBy): array
{
    $db = aw_db();
    $s = $db->prepare('SELECT * FROM employee_profiles WHERE user_id=:uid AND is_active=1 LIMIT 1'); $s->execute([':uid'=>$userId]); $profile=$s->fetch(\PDO::FETCH_ASSOC);
    if(!$profile) throw new \RuntimeException('Employee profile not found');
    $s=$db->prepare('SELECT * FROM payroll_periods WHERE period_id=:pid LIMIT 1'); $s->execute([':pid'=>$periodId]); $period=$s->fetch(\PDO::FETCH_ASSOC);
    if(!$period) throw new \RuntimeException('Payroll period not found');

    $hours = aw_calculateAttendanceHours($userId, $period['start_date'], $period['end_date'], $profile);
    $hr = aw_effectiveHourlyRate($profile);

    $regPay   = $hours['regular_hours'] * $hr;
    $otPay    = $hours['overtime_hours'] * $hr * (float)$profile['overtime_rate'];
    $dotPay   = $hours['double_overtime_hours'] * $hr * (float)$profile['double_overtime_rate'];
    $holPay   = $hours['holiday_hours'] * $hr;
    $nsPay    = $hours['night_shift_hours'] * $hr * (float)($profile['night_diff_enabled'] ? $profile['night_diff_rate'] : 0);
    $rdPay    = $hours['rest_day_hours'] * $hr;
    $rdPrem   = $hours['rest_day_hours'] * $hr * ((float)$profile['rest_day_rate'] - 1.0);

    $gross = $regPay + $otPay + $dotPay + $holPay + $nsPay + $rdPay + $rdPrem;
    $benefits = aw_calculateBenefits($gross);
    $adj = aw_getAdjustmentsForPeriod($userId, $periodId);
    $ded = aw_getDeductionsForPeriod($userId, $period['start_date'], $period['end_date']);
    $caDed = aw_getCashAdvanceRepayment($userId, $periodId);
    $gross += ($adj['additions'] ?? 0);

    $totDed = $benefits['sss']['employee'] + $benefits['philhealth']['employee'] + $benefits['pagibig']['employee'] + $ded + $caDed + ($adj['deductions'] ?? 0);
    $tax = aw_calculateTax($gross, $totDed, $profile);
    $totDed += $tax;
    $netPay = $gross - $totDed;
    $erCost = $gross + $benefits['sss']['employer'] + $benefits['philhealth']['employer'] + $benefits['pagibig']['employer'];

    $data = [
        'user_id' => $userId, 'payroll_period_id' => $periodId, 'employee_profile_id' => $profile['profile_id'],
        'basic_salary' => $profile['basic_salary'], 'regular_hours' => $hours['regular_hours'], 'overtime_hours' => $hours['overtime_hours'],
        'double_overtime_hours' => $hours['double_overtime_hours'], 'holiday_hours' => $hours['holiday_hours'],
        'night_shift_hours' => $hours['night_shift_hours'], 'rest_day_hours' => $hours['rest_day_hours'],
        'regular_pay' => $regPay, 'overtime_pay' => $otPay, 'double_overtime_pay' => $dotPay,
        'holiday_pay' => $holPay, 'night_shift_pay' => $nsPay, 'rest_day_pay' => $rdPay, 'rest_day_premium' => $rdPrem,
        'gross_pay' => $gross,
        'sss_employee' => $benefits['sss']['employee'], 'philhealth_employee' => $benefits['philhealth']['employee'],
        'pagibig_employee' => $benefits['pagibig']['employee'], 'income_tax' => $tax,
        'sss_employer' => $benefits['sss']['employer'], 'philhealth_employer' => $benefits['philhealth']['employer'],
        'pagibig_employer' => $benefits['pagibig']['employer'],
        'salary_deductions' => $ded, 'cash_advance_deduction' => $caDed, 'other_deductions' => ($adj['deductions']??0),
        'total_deductions' => $totDed, 'total_additions' => ($adj['additions']??0),
        'net_pay' => $netPay, 'total_employer_cost' => $erCost,
        'status' => 'computed', 'computed_by' => $computedBy, 'computation_date' => date('Y-m-d H:i:s'),
    ];

    $s = $db->prepare('SELECT computation_id FROM salary_computations WHERE user_id=:uid AND payroll_period_id=:pid LIMIT 1');
    $s->execute([':uid'=>$userId, ':pid'=>$periodId]); $existing = $s->fetchColumn();
    if ($existing) {
        $sets = implode(', ', array_map(fn($k) => "`$k`=:$k", array_keys($data)));
        $db->prepare("UPDATE salary_computations SET {$sets} WHERE computation_id=:cid")->execute(array_merge($data, [':cid'=>(int)$existing]));
        return array_merge(['computation_id'=>(int)$existing], $data);
    }
    $cols = implode(', ', array_keys($data)); $vals = ':'.implode(', :', array_keys($data));
    $db->prepare("INSERT INTO salary_computations ({$cols}) VALUES ({$vals})")->execute($data);
    return array_merge(['computation_id'=>(int)$db->lastInsertId()], $data);
}

function aw_calculateAttendanceHours(int $userId, string $startDate, string $endDate, array $profile): array
{
    $db = aw_db();
    $s = $db->prepare("SELECT clock_in, clock_out FROM attendance_records WHERE user_id=:uid AND DATE(clock_in) BETWEEN :start AND :end AND clock_out IS NOT NULL ORDER BY clock_in ASC");
    $s->execute([':uid'=>$userId, ':start'=>$startDate, ':end'=>$endDate]); $records = $s->fetchAll(\PDO::FETCH_ASSOC);

    $reg=0.0;$ot=0.0;$dot=0.0;$hol=0.0;$ns=0.0;$rd=0.0;$weekly=[];
    $maxD=(float)($profile['max_daily_hours']??8);$maxW=(float)($profile['max_weekly_hours']??40);
    $otOk=(bool)($profile['overtime_allowed']??1);$holOk=(bool)($profile['holiday_pay_enabled']??1);
    $nsOk=(bool)($profile['night_diff_enabled']??1);$rdOk=(bool)($profile['rest_day_pay_enabled']??1);

    foreach($records as $rec){
        $ci=new \DateTime($rec['clock_in']); $co=new \DateTime($rec['clock_out']);
        $th=($co->getTimestamp()-$ci->getTimestamp())/3600.0; $dt=$ci->format('Y-m-d'); $wk=$ci->format('Y-W');
        if(!isset($weekly[$wk]))$weekly[$wk]=0.0;
        if(aw_isHoliday($dt)&&$holOk){$hol+=$th;continue;}
        if(aw_isRestDay($userId,$dt,$profile)&&$rdOk){$rd+=$th;continue;}
        if($nsOk)$ns+=aw_nightShiftOverlap($ci,$co);
        $dr=min($th,$maxD);$reg+=$dr;$weekly[$wk]+=$dr;
        if($th>$maxD&&$otOk){
            $dailyOT=$th-$maxD;
            if($th>10){$dot+=($th-10);$dailyOT-=($th-10);}
            $remW=$maxW-$weekly[$wk];
            if($remW<=0){$dot+=$dailyOT;}
            else{$wot=min($dailyOT,$remW);$ot+=$wot;$weekly[$wk]+=$wot;if($dailyOT>$wot)$dot+=($dailyOT-$wot);}
        }
    }
    return ['regular_hours'=>round($reg,2),'overtime_hours'=>round($ot,2),'double_overtime_hours'=>round($dot,2),'holiday_hours'=>round($hol,2),'night_shift_hours'=>round($ns,2),'rest_day_hours'=>round($rd,2)];
}

function aw_calculateBenefits(float $grossPay): array
{
    $db=aw_db(); $r=['sss'=>['employee'=>0.0,'employer'=>0.0],'philhealth'=>['employee'=>0.0,'employer'=>0.0],'pagibig'=>['employee'=>0.0,'employer'=>0.0]];
    foreach(['sss','philhealth','pagibig'] as $t){
        $s=$db->prepare("SELECT employee_share_pct,employer_share_pct,employee_fixed,employer_fixed,min_contribution,max_contribution FROM benefits_contribution_rates WHERE benefit_type=:t AND is_active=1 AND effective_date<=CURDATE() AND salary_from<=:s AND (salary_to IS NULL OR salary_to>=:s2) ORDER BY effective_date DESC LIMIT 1");
        $s->execute([':t'=>$t,':s'=>$grossPay,':s2'=>$grossPay]); $rate=$s->fetch(\PDO::FETCH_ASSOC);
        if($rate){
            $e=$rate['employee_fixed']??($grossPay*(float)$rate['employee_share_pct']); $e=max((float)($rate['min_contribution']??0),min($e,(float)($rate['max_contribution']??PHP_FLOAT_MAX))); $r[$t]['employee']=round($e,2);
            $er=$rate['employer_fixed']??($grossPay*(float)$rate['employer_share_pct']); $er=max((float)($rate['min_contribution']??0),min($er,(float)($rate['max_contribution']??PHP_FLOAT_MAX))); $r[$t]['employer']=round($er,2);
        }
    }
    return $r;
}

function aw_calculateTax(float $grossPay, float $deductions, array $profile): float
{
    $ag=$grossPay*12; $ad=$deductions*12; $tx=max(0,$ag-$ad); $at=0.0;
    if($tx<=250000)$at=0; elseif($tx<=400000)$at=($tx-250000)*0.20; elseif($tx<=800000)$at=30000+($tx-400000)*0.25;
    elseif($tx<=2000000)$at=130000+($tx-800000)*0.30; elseif($tx<=8000000)$at=490000+($tx-2000000)*0.32; else $at=2400000+($tx-8000000)*0.35;
    return round($at/12,2);
}

function aw_isHoliday(string $date): ?array { $db=aw_db(); $s=$db->prepare('SELECT * FROM holidays WHERE holiday_date=:d AND is_active=1 LIMIT 1'); $s->execute([':d'=>$date]); $r=$s->fetch(\PDO::FETCH_ASSOC); return $r?:null; }
function aw_isRestDay(int $userId, string $date, array $profile): bool {
    $dn=strtolower(date('l',strtotime($date))); $db=aw_db(); $s=$db->prepare('SELECT is_dayoff FROM employee_schedules WHERE user_id=:uid AND day_of_week=:dow LIMIT 1'); $s->execute([':uid'=>$userId,':dow'=>$dn]); $sc=$s->fetch(\PDO::FETCH_ASSOC);
    if($sc) return (bool)($sc['is_dayoff']??false); return $dn===strtolower($profile['rest_day_schedule']??'sunday');
}
function aw_nightShiftOverlap(\DateTime $ci, \DateTime $co): float {
    $ns=(clone $ci)->setTime(22,0,0); $ne=(clone $ci)->setTime(6,0,0)->modify('+1 day');
    if($co<=$ns||$ci>=$ne)return 0.0; $os=max($ci,$ns); $oe=min($co,$ne); return ($oe->getTimestamp()-$os->getTimestamp())/3600.0;
}
function aw_effectiveHourlyRate(array $profile): float {
    return match($profile['salary_type']??'daily'){'hourly'=>(float)($profile['hourly_rate']?:$profile['basic_salary']),'daily'=>(float)($profile['daily_rate']?:($profile['basic_salary']/((float)($profile['max_daily_hours']??8)))),'monthly'=>(float)($profile['monthly_rate']?:($profile['basic_salary']/22/((float)($profile['max_daily_hours']??8)))),'fixed'=>(float)($profile['basic_salary']/22/((float)($profile['max_daily_hours']??8))),default=>(float)($profile['basic_salary']/22/8)};
}
function aw_getAdjustmentsForPeriod(int $userId, int $periodId): array {
    $db=aw_db(); $s=$db->prepare("SELECT SUM(CASE WHEN adjustment_type IN('bonus','allowance','thirteenth_month','holiday_bonus') THEN amount ELSE 0 END) AS additions, SUM(CASE WHEN adjustment_type IN('penalty','deduction','correction') THEN amount ELSE 0 END) AS deductions FROM salary_adjustments WHERE user_id=:uid AND payroll_period_id=:pid AND status='applied'");
    $s->execute([':uid'=>$userId,':pid'=>$periodId]); $r=$s->fetch(\PDO::FETCH_ASSOC); return ['additions'=>(float)($r['additions']??0),'deductions'=>(float)($r['deductions']??0)];
}
function aw_getDeductionsForPeriod(int $userId, string $startDate, string $endDate): float {
    $db=aw_db(); $s=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM employee_deductions WHERE user_id=:uid AND status='processed' AND deduction_date BETWEEN :start AND :end");
    $s->execute([':uid'=>$userId,':start'=>$startDate,':end'=>$endDate]); return (float)$s->fetchColumn();
}
function aw_getCashAdvanceRepayment(int $userId, int $periodId): float {
    $db=aw_db(); $s=$db->prepare("SELECT COALESCE(SUM(car.amount),0) FROM cash_advance_repayments car JOIN cash_advances ca ON ca.advance_id=car.advance_id WHERE ca.user_id=:uid AND car.payroll_period_id=:pid AND car.status IN('pending','deducted')");
    $s->execute([':uid'=>$userId,':pid'=>$periodId]); return (float)$s->fetchColumn();
}
