<?php

declare(strict_types=1);

/**
 * Wage page handlers — dashboard, employees, periods, computations.
 */



function wagePageDashboard(array $params = []): void
{
    $data = [
        'employee_count' => 0,
        'current_period_name' => '—',
        'pending_approvals' => 0,
        'total_net_pay' => '0.00',
        'ca_pending_count' => 0,
        'ca_pending_amount' => '0.00',
        'ca_active_count' => 0,
        'ca_outstanding' => '0.00',
        'location_count' => 0,
    ];
    try {
        $db = aw_db();
        $data['employee_count'] = (int)$db->query("SELECT COUNT(*) FROM employee_profiles WHERE is_active = 1")->fetchColumn();
        $current = $db->query("SELECT period_name FROM payroll_periods WHERE status IN ('draft','processing') ORDER BY start_date DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if ($current) { $data['current_period_name'] = $current['period_name']; }
        $data['pending_approvals'] = (int)$db->query("SELECT COUNT(*) FROM salary_computations WHERE status = 'computed'")->fetchColumn();
        $net = $db->query("SELECT COALESCE(SUM(net_pay),0) FROM salary_computations WHERE status IN ('computed','approved','paid')")->fetchColumn();
        $data['total_net_pay'] = number_format((float)$net, 2);
        // Cash advance stats
        $data['ca_pending_count'] = (int)$db->query("SELECT COUNT(*) FROM cash_advances WHERE status = 'pending'")->fetchColumn();
        $data['ca_pending_amount'] = number_format((float)$db->query("SELECT COALESCE(SUM(amount),0) FROM cash_advances WHERE status = 'pending'")->fetchColumn(), 2);
        $data['ca_active_count'] = (int)$db->query("SELECT COUNT(*) FROM cash_advances WHERE status IN ('approved','active')")->fetchColumn();
        $data['ca_outstanding'] = number_format((float)$db->query("SELECT COALESCE(SUM(balance),0) FROM cash_advances WHERE status IN ('approved','active')")->fetchColumn(), 2);
        $data['location_count'] = (int)$db->query("SELECT COUNT(*) FROM office_locations WHERE is_active = 1")->fetchColumn();
    } catch (\Throwable $e) {}

    echo app()->render('modules/attendance-wage/wage/dashboard', $data);
}

function wagePageEmployees(array $params = []): void
{
    echo app()->render('modules/attendance-wage/wage/employees/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
    ]);
}

function wagePageEmployeeForm(array $params = []): void
{
    $editId = (int)($params['id'] ?? 0);
    $vars = ['id' => $editId, 'salary_type' => 'daily'];
    if ($editId > 0) {
        try {
            $db = aw_db();
            $stmt = $db->prepare("SELECT * FROM employee_profiles WHERE profile_id = :id");
            $stmt->execute([':id' => $editId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                foreach ($row as $k => $v) {
                    if (!is_array($v) && $v !== null) { $vars[$k] = $v; }
                }
            }
        } catch (\Throwable $e) {}
    }
    echo app()->render('modules/attendance-wage/wage/employees/form', $vars);
}

function wagePagePeriods(array $params = []): void
{
    // Salary type stats
    $stats = ['hourly' => 0, 'daily' => 0, 'monthly' => 0, 'fixed' => 0, 'total' => 0];
    try {
        $db = aw_db();
        $rows = $db->query("SELECT salary_type, COUNT(*) AS cnt FROM employee_profiles WHERE is_active = 1 GROUP BY salary_type")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $t = $r['salary_type'] ?? '';
            if (isset($stats[$t])) { $stats[$t] = (int)$r['cnt']; $stats['total'] += (int)$r['cnt']; }
        }
    } catch (\Throwable $e) {}

    // Existing payroll periods with computed totals
    $periods = [];
    try {
        $db = aw_db();
        $periods = $db->query("SELECT * FROM payroll_periods ORDER BY start_date DESC LIMIT 20")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}

    // Pending adjustments summary
    $pendingAdjustments = ['count' => 0, 'additions' => 0, 'deductions' => 0];
    try {
        $db = aw_db();
        $pa = $db->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN adjustment_type IN('bonus','allowance','thirteenth_month','holiday_bonus') THEN amount ELSE 0 END),0) AS adds, COALESCE(SUM(CASE WHEN adjustment_type IN('penalty','deduction','correction') THEN amount ELSE 0 END),0) AS deds FROM salary_adjustments WHERE status IN('pending','approved')")->fetch(\PDO::FETCH_ASSOC);
        if ($pa) {
            $pendingAdjustments['count'] = (int)$pa['cnt'];
            $pendingAdjustments['additions'] = (float)$pa['adds'];
            $pendingAdjustments['deductions'] = (float)$pa['deds'];
        }
    } catch (\Throwable $e) {}

    echo app()->render('modules/attendance-wage/wage/periods/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'stats' => $stats,
        'periods' => $periods,
        'pending_adjustments' => $pendingAdjustments,
    ]);
}

function wagePagePeriodForm(array $params = []): void
{
    $editId = (int)($params['id'] ?? 0);
    $vars = ['id' => $editId];
    if ($editId > 0) {
        try {
            $db = aw_db();
            $stmt = $db->prepare("SELECT * FROM payroll_periods WHERE period_id = :id");
            $stmt->execute([':id' => $editId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                foreach ($row as $k => $v) { if (!is_array($v)) { $vars[$k] = $v; } }
            }
        } catch (\Throwable $e) {}
    }
    echo app()->render('modules/attendance-wage/wage/periods/form', $vars);
}

function wagePageComputations(array $params = []): void
{
    $periods = [];
    $selectedPeriodId = (int)($_GET['period_id'] ?? 0);
    $computations = [];
    $totals = ['gross' => 0, 'deductions' => 0, 'net' => 0, 'additions' => 0, 'adj_deductions' => 0];
    $canApprove = false;
    $canPay = false;
    $periodStatus = '';
    $periodPayDate = '';
    $now = date('Y-m-d');
    try {
        $db = aw_db();
        // Fetch all periods with computations
        $periods = $db->query("SELECT pp.*, COUNT(sc.computation_id) AS comp_count, COALESCE(SUM(sc.gross_pay),0) AS total_gross, COALESCE(SUM(sc.net_pay),0) AS total_net FROM payroll_periods pp LEFT JOIN salary_computations sc ON sc.payroll_period_id = pp.period_id GROUP BY pp.period_id ORDER BY pp.start_date DESC LIMIT 15")->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // If a period is selected, show its computations
        if ($selectedPeriodId > 0) {
            $comp = $db->prepare(
                "SELECT sc.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                        ep.position, ep.department, ep.salary_type
                 FROM salary_computations sc
                 JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
                 WHERE sc.payroll_period_id = :pid
                 ORDER BY ep.last_name ASC, ep.first_name ASC"
            );
            $comp->execute([':pid' => $selectedPeriodId]);
            $computations = $comp->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($computations as $c) {
                $totals['gross'] += (float)($c['gross_pay'] ?? 0);
                $totals['deductions'] += (float)($c['total_deductions'] ?? 0);
                $totals['net'] += (float)($c['net_pay'] ?? 0);
                $totals['additions'] += (float)($c['total_additions'] ?? 0);
                $totals['adj_deductions'] += (float)($c['other_deductions'] ?? 0);
            }
            // Fetch period details for payday-gated approve
            $ps = $db->prepare("SELECT status, pay_date FROM payroll_periods WHERE period_id = :pid LIMIT 1");
            $ps->execute([':pid' => $selectedPeriodId]);
            $pinfo = $ps->fetch(\PDO::FETCH_ASSOC);
            if ($pinfo) {
                $periodStatus = $pinfo['status'] ?? '';
                $periodPayDate = $pinfo['pay_date'] ?? '';
                $payDatePassed = ($periodPayDate !== '' && $periodPayDate <= $now);
                $hasComputed = !empty(array_filter($computations, fn($c) => ($c['status'] ?? '') === 'computed'));
                $hasApproved = !empty(array_filter($computations, fn($c) => ($c['status'] ?? '') === 'approved'));
                $canApprove = $payDatePassed && $hasComputed && in_array($periodStatus, ['processing', 'draft']);
                $canPay = $payDatePassed && $hasApproved && in_array($periodStatus, ['approved', 'processing']);
            }
        }
    } catch (\Throwable $e) {}

    echo app()->render('modules/attendance-wage/wage/computations/index', [
        'periods' => $periods,
        'selectedPeriodId' => $selectedPeriodId,
        'computations' => $computations,
        'total_gross' => number_format($totals['gross'], 2),
        'total_deductions' => number_format($totals['deductions'], 2),
        'total_net' => number_format($totals['net'], 2),
        'total_additions' => number_format($totals['additions'], 2),
        'total_adj_deductions' => number_format($totals['adj_deductions'], 2),
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'can_approve' => $canApprove,
        'can_pay' => $canPay,
        'period_status' => $periodStatus,
        'period_pay_date' => $periodPayDate,
        'today' => $now,
    ]);
}

function wagePageAdjustments(array $params = []): void
{
    echo app()->render('modules/attendance-wage/wage/adjustments/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
    ]);
}

function wagePageAdjustmentForm(array $params = []): void
{
    $editId = (int)($params['id'] ?? 0);
    $vars = ['id' => $editId, 'effective_date' => date('Y-m-d')];
    $employees = [];
    $currentPeriod = null;
    try {
        $db = aw_db();
        $employees = $db->query("SELECT u.id AS user_id, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS full_name, ep.position FROM attendance_wage_users u JOIN employee_profiles ep ON ep.user_id = u.id WHERE u.is_active = 1 ORDER BY ep.last_name ASC, ep.first_name ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        // Auto-resolve current active/draft payroll period
        $currentPeriod = $db->query("SELECT period_id, period_name, pay_date FROM payroll_periods WHERE status IN ('draft','processing') ORDER BY start_date DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
    if ($editId > 0) {
        try {
            $db = aw_db();
            $stmt = $db->prepare("SELECT * FROM salary_adjustments WHERE adjustment_id = :id");
            $stmt->execute([':id' => $editId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                foreach ($row as $k => $v) {
                    if (!is_array($v)) { $vars[$k] = $v; }
                }
            }
        } catch (\Throwable $e) {}
    }
    // Default to current period if creating new
    if ($editId <= 0 && $currentPeriod) {
        $vars['payroll_period_id'] = $currentPeriod['period_id'];
        $vars['current_period_name'] = $currentPeriod['period_name'];
        $vars['current_period_pay_date'] = $currentPeriod['pay_date'] ?? '—';
    }
    $vars['employees'] = $employees;
    $vars['current_period'] = $currentPeriod;
    echo app()->render('modules/attendance-wage/wage/adjustments/form', $vars);
}

function wagePageDeductions(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/deductions/index');
}

function wagePageDeductionForm(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/deductions/form');
}

function wagePageCashAdvances(array $params = []): void
{
    $summary = ['total' => 0, 'pending' => 0, 'approved' => 0, 'active' => 0];
    $selectedCA = null;
    $employeeCAs = [];
    try {
        $db = aw_db();
        $summary['total'] = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM cash_advances")->fetchColumn();
        $summary['pending'] = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM cash_advances WHERE status='pending'")->fetchColumn();
        $summary['approved'] = (float)$db->query("SELECT COALESCE(SUM(balance),0) FROM cash_advances WHERE status IN ('approved','active')")->fetchColumn();
        $summary['active'] = (int)$db->query("SELECT COUNT(*) FROM cash_advances WHERE status IN ('approved','active')")->fetchColumn();

        // View single CA detail if ?id= is present
        $viewId = (int)($_GET['id'] ?? 0);
        if ($viewId > 0) {
            $s = $db->prepare("SELECT ca.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name, ep.position, ep.department FROM cash_advances ca LEFT JOIN employee_profiles ep ON ep.profile_id = ca.employee_profile_id WHERE ca.advance_id = :id LIMIT 1");
            $s->execute([':id' => $viewId]);
            $selectedCA = $s->fetch(\PDO::FETCH_ASSOC) ?: null;
            // Get all CAs for this employee
            if ($selectedCA && ($selectedCA['employee_profile_id'] ?? 0) > 0) {
                $s2 = $db->prepare("SELECT * FROM cash_advances WHERE employee_profile_id = :eid ORDER BY request_date DESC");
                $s2->execute([':eid' => $selectedCA['employee_profile_id']]);
                $employeeCAs = $s2->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            }
        }
    } catch (\Throwable $e) {}
    echo app()->render('modules/attendance-wage/wage/cash-advances/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'summary' => $summary,
        'selectedCA' => $selectedCA,
        'employeeCAs' => $employeeCAs,
    ]);
}

function wagePageCashAdvanceForm(array $params = []): void
{
    $employees = [];
    try {
        $db = aw_db();
        $employees = $db->query("SELECT profile_id, CONCAT_WS(' ', first_name, middle_name, last_name, suffix) AS full_name, position FROM employee_profiles WHERE is_active = 1 ORDER BY last_name ASC, first_name ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}
    echo app()->render('modules/attendance-wage/wage/cash-advances/form', [
        'employees' => $employees,
        'today' => date('Y-m-d'),
    ]);
}

function wagePageHolidays(array $params = []): void
{
    $editHoliday = null;
    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0) {
        try {
            $db = aw_db();
            $s = $db->prepare("SELECT * FROM holidays WHERE holiday_id = :id LIMIT 1");
            $s->execute([':id' => $editId]);
            $editHoliday = $s->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {}
    }
    echo app()->render('modules/attendance-wage/wage/holidays/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'edit_holiday' => $editHoliday,
    ]);
}

function wagePageSchedules(array $params = []): void
{
    $schedules = [];
    $employees = [];
    try {
        $db = aw_db();
        // Grouped: one row per employee with schedule summary
        $schedules = $db->query(
            "SELECT ep.profile_id, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    ep.position, ep.department,
                    GROUP_CONCAT(DISTINCT CONCAT(UCASE(LEFT(es.day_of_week,1)), SUBSTRING(es.day_of_week,2,2)) ORDER BY FIELD(es.day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday') SEPARATOR ', ') AS days_label,
                    GROUP_CONCAT(DISTINCT es.day_of_week ORDER BY FIELD(es.day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')) AS days_csv,
                    MAX(es.shift_type) AS shift_type,
                    MIN(COALESCE(es.start_time, NULL)) AS min_start,
                    MAX(COALESCE(es.end_time, NULL)) AS max_end,
                    SUM(es.is_dayoff) AS dayoff_count,
                    COUNT(*) AS total_days
             FROM employee_schedules es
             JOIN employee_profiles ep ON ep.user_id = es.user_id
             WHERE ep.is_active = 1
             GROUP BY ep.profile_id, ep.first_name, ep.middle_name, ep.last_name, ep.suffix, ep.position, ep.department
             ORDER BY ep.last_name ASC, ep.first_name ASC"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $employees = $db->query("SELECT profile_id, CONCAT_WS(' ', first_name, middle_name, last_name, suffix) AS full_name, position, department, employee_number FROM employee_profiles WHERE is_active = 1 ORDER BY last_name ASC, first_name ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}
    echo app()->render('modules/attendance-wage/wage/schedules/index', [
        'schedules' => $schedules,
        'employees' => $employees,
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
    ]);
}

function wagePageReports(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/reports/index');
}

function wagePageReportDetail(array $params = []): void
{
    $periodId = (int)($params['periodId'] ?? 0);
    $period = null;
    $computations = [];
    $totals = ['gross' => 0, 'deductions' => 0, 'net' => 0, 'employees' => 0, 'additions' => 0, 'adj_deductions' => 0];

    if ($periodId > 0) {
        try {
            $db = aw_db();
            // Fetch period
            $s = $db->prepare("SELECT * FROM payroll_periods WHERE period_id = :pid");
            $s->execute([':pid' => $periodId]);
            $period = $s->fetch(\PDO::FETCH_ASSOC);

            // Fetch computations with employee names
            $computations = $db->prepare(
                "SELECT sc.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                        ep.position, ep.department, ep.salary_type
                 FROM salary_computations sc
                 JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
                 WHERE sc.payroll_period_id = :pid
                 ORDER BY ep.last_name ASC, ep.first_name ASC"
            );
            $computations->execute([':pid' => $periodId]);
            $computations = $computations->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            foreach ($computations as $c) {
                $totals['gross'] += (float)($c['gross_pay'] ?? 0);
                $totals['deductions'] += (float)($c['total_deductions'] ?? 0);
                $totals['net'] += (float)($c['net_pay'] ?? 0);
                $totals['additions'] += (float)($c['total_additions'] ?? 0);
                $totals['adj_deductions'] += (float)($c['other_deductions'] ?? 0);
                $totals['employees']++;
            }
        } catch (\Throwable $e) {}
    }

    $now = date('Y-m-d');
    $isFinal = $period && ($period['pay_date'] ?? '') <= $now;
    $payDatePassed = $period ? (($period['pay_date'] ?? '9999-12-31') <= $now) : false;

    echo app()->render('modules/attendance-wage/wage/reports/detail', [
        'period' => $period,
        'computations' => $computations,
        'total_gross' => number_format($totals['gross'], 2),
        'total_deductions' => number_format($totals['deductions'], 2),
        'total_net' => number_format($totals['net'], 2),
        'total_employees' => $totals['employees'],
        'total_additions' => number_format($totals['additions'], 2),
        'total_adj_deductions' => number_format($totals['adj_deductions'], 2),
        'is_final' => $payDatePassed,
    ]);
}

function wagePageBenefitsCalc(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/benefits-calculator');
}

function wagePageMigrationWizard(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/migration-wizard');
}

function wagePageLocations(array $params = []): void
{
    $locations = [];
    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT * FROM office_locations ORDER BY name ASC");
        $stmt->execute();
        $locations = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}
    echo app()->render('modules/attendance-wage/wage/locations/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'locations' => $locations,
    ]);
}

function wagePageLocationForm(array $params = []): void
{
    $editId = (int)($params['id'] ?? 0);
    $settings = getModuleSettings('attendance-wage');
    $vars = ['id' => $editId, 'radius_meters' => 100, 'maps_api_key' => $settings['google_maps_api_key'] ?? ''];
    if ($editId > 0) {
        try {
            $db = aw_db();
            $stmt = $db->prepare("SELECT * FROM office_locations WHERE location_id = :id");
            $stmt->execute([':id' => $editId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                foreach ($row as $k => $v) {
                    if (!is_array($v) && $v !== null) { $vars[$k] = $v; }
                }
            }
        } catch (\Throwable $e) {}
    }
    echo app()->render('modules/attendance-wage/wage/locations/form', $vars);
}

function wagePageSettings(array $params = []): void
{
    $settings = getModuleSettings('attendance-wage');
    echo app()->render('modules/attendance-wage/wage/settings', [
        'success' => $_GET['success'] ?? '',
        'error'   => $_GET['error'] ?? '',
        'google_maps_api_key' => $settings['google_maps_api_key'] ?? '',
    ]);
}
