<?php

declare(strict_types=1);

/**
 * Wage page handlers — dashboard, employees, periods, computations.
 */



function wagePageDashboard(array $params = []): void
{
    attendanceWageGuard();
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
        'today_clocked_in' => 0,
        'today_records' => 0,
        'today_onsite_count' => 0,
        'today_office_count' => 0,
        'today_attendance' => [],
        'recent_computations' => [],
    ];
    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';
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
        $locCount = $db->prepare("SELECT COUNT(*) FROM office_locations WHERE tenant_id = :tid AND is_active = 1");
        $locCount->execute([':tid' => $tid]);
        $data['location_count'] = (int)$locCount->fetchColumn();

        // ── Attendance stats ──
        // Today's clocked-in (active, no clock-out yet)
        $data['today_clocked_in'] = (int)$db->query(
            "SELECT COUNT(*) FROM attendance_records WHERE DATE(clock_in) = CURDATE() AND clock_out IS NULL"
        )->fetchColumn();

        // Today's total records
        $data['today_records'] = (int)$db->query(
            "SELECT COUNT(*) FROM attendance_records WHERE DATE(clock_in) = CURDATE()"
        )->fetchColumn();

        // On-site vs office breakdown for today
        $data['today_onsite_count'] = (int)$db->query(
            "SELECT COUNT(*) FROM attendance_records WHERE DATE(clock_in) = CURDATE() AND location_in LIKE 'On-site%'"
        )->fetchColumn();
        $data['today_office_count'] = $data['today_records'] - $data['today_onsite_count'];

        // Today's attendance detail: employee name, clock in, clock out, location, status
        $attStmt = $db->prepare(
            "SELECT ar.attendance_id, ar.clock_in, ar.clock_out, ar.location_in, ar.location_out, ar.status,
                    CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    ep.position, ep.employee_number, ep.onsite_attendance
             FROM attendance_records ar
             JOIN attendance_wage_users u ON u.id = ar.user_id
             JOIN employee_profiles ep ON ep.user_id = u.id
             WHERE DATE(ar.clock_in) = CURDATE()
             ORDER BY ar.clock_in DESC
             LIMIT 15"
        );
        $attStmt->execute();
        $data['today_attendance'] = $attStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Recent computations
        $compStmt = $db->query(
            "SELECT sc.computation_id, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    pp.period_name, sc.gross_pay, sc.total_deductions, sc.net_pay, sc.status
             FROM salary_computations sc
             LEFT JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
             LEFT JOIN payroll_periods pp ON pp.period_id = sc.payroll_period_id
             ORDER BY sc.created_at DESC
             LIMIT 5"
        );
        $data['recent_computations'] = $compStmt ? $compStmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    } catch (\Throwable $e) {}

    echo app()->render('modules/attendance-wage/wage/dashboard', $data + ['page_title' => 'Dashboard', 'active_nav' => 'dashboard', 'current_user_role' => (attendanceWageUser()['role'] ?? '')]);
}

function wagePageEmployees(array $params = []): void
{
    attendanceWageGuard();
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/employees/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'active_nav'        => 'employees',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageEmployeeForm(array $params = []): void
{
    attendanceWageGuard();
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
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/employees/form', $vars + [
        'active_nav'        => 'employees',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageEmployeeView(array $params = []): void
{
    attendanceWageGuard();
    $viewId = (int)($params['id'] ?? 0);
    $vars = ['id' => $viewId];
    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT * FROM employee_profiles WHERE profile_id = :id");
        $stmt->execute([':id' => $viewId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            foreach ($row as $k => $v) {
                if (!is_array($v) && $v !== null) { $vars[$k] = $v; }
            }
        }
    } catch (\Throwable $e) {
        echo app()->render('modules/attendance-wage/wage/employees/view', ['id' => $viewId, 'error' => 'Employee not found.']);
        return;
    }
    if (empty($vars['last_name'])) {
        echo app()->render('modules/attendance-wage/wage/employees/view', ['id' => $viewId, 'error' => 'Employee not found.']);
        return;
    }
    // Normalize display values
    $vars['tax_status_label'] = aw_formatLookup('tax_exemption_status', $vars['tax_exemption_status'] ?? null);
    $vars['employment_status_label'] = aw_formatLookup('employment_status', $vars['employment_status'] ?? null);
    $vars['salary_type_label'] = aw_formatLookup('salary_type', $vars['salary_type'] ?? null);
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/employees/view', $vars + [
        'active_nav'        => 'employees',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePagePeriods(array $params = []): void
{
    attendanceWageGuard();
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

    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/periods/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'stats' => $stats,
        'pending_adjustments' => $pendingAdjustments,
        'active_nav'        => 'periods',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePagePeriodForm(array $params = []): void
{
    attendanceWageGuard();
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
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/periods/form', $vars + [
        'active_nav'        => 'periods',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageComputations(array $params = []): void
{
    attendanceWageGuard();
    $selectedPeriodId = (int)($_GET['period_id'] ?? 0);
    $totals = ['gross' => 0, 'deductions' => 0, 'net' => 0, 'additions' => 0, 'adj_deductions' => 0];
    $canApprove = false;
    $canPay = false;
    $periodStatus = '';
    $periodPayDate = '';
    $now = date('Y-m-d');
    try {
        $db = aw_db();

        // If a period is selected, aggregate totals + determine approve/pay eligibility
        if ($selectedPeriodId > 0) {
            $agg = $db->prepare(
                "SELECT COALESCE(SUM(gross_pay),0) AS gross, COALESCE(SUM(total_deductions),0) AS deductions,
                        COALESCE(SUM(net_pay),0) AS net, COALESCE(SUM(total_additions),0) AS additions,
                        COALESCE(SUM(other_deductions),0) AS adj_deductions,
                        COUNT(*) AS total_rows,
                        SUM(CASE WHEN status = 'computed' THEN 1 ELSE 0 END) AS computed_count,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count
                 FROM salary_computations WHERE payroll_period_id = :pid"
            );
            $agg->execute([':pid' => $selectedPeriodId]);
            $row = $agg->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $totals['gross'] = (float)$row['gross'];
                $totals['deductions'] = (float)$row['deductions'];
                $totals['net'] = (float)$row['net'];
                $totals['additions'] = (float)$row['additions'];
                $totals['adj_deductions'] = (float)$row['adj_deductions'];
            }

            // Fetch period details for payday-gated approve
            $ps = $db->prepare("SELECT status, pay_date FROM payroll_periods WHERE period_id = :pid LIMIT 1");
            $ps->execute([':pid' => $selectedPeriodId]);
            $pinfo = $ps->fetch(\PDO::FETCH_ASSOC);
            if ($pinfo) {
                $periodStatus = $pinfo['status'] ?? '';
                $periodPayDate = $pinfo['pay_date'] ?? '';
                $payDatePassed = ($periodPayDate !== '' && $periodPayDate <= $now);
                $hasComputed = ($row['computed_count'] ?? 0) > 0;
                $hasApproved = ($row['approved_count'] ?? 0) > 0;
                $canApprove = $payDatePassed && $hasComputed && in_array($periodStatus, ['processing', 'draft']);
                $canPay = $payDatePassed && $hasApproved && in_array($periodStatus, ['approved', 'processing']);
            }
        }
    } catch (\Throwable $e) {}

    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/computations/index', [
        'selectedPeriodId' => $selectedPeriodId,
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
        'page_title' => 'Payroll Computations',
        'active_nav'        => 'computations',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageComputationDetail(array $params = []): void
{
    attendanceWageGuard();
    $computationId = (int)($params['id'] ?? 0);
    if ($computationId <= 0) {
        header('Location: ' . awBaseUrl() . '/admin/wage/computations');
        exit;
    }
    // Redirect to payslip page which renders the full detail
    header('Location: ' . awBaseUrl() . '/admin/wage/payslip/' . $computationId);
    exit;
}

function wagePageAdjustments(array $params = []): void
{
    attendanceWageGuard();
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/adjustments/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'active_nav'        => 'adjustments',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageAdjustmentForm(array $params = []): void
{
    attendanceWageGuard();
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
    $user = attendanceWageUser();
    $vars['employees'] = $employees;
    $vars['current_period'] = $currentPeriod;
    echo app()->render('modules/attendance-wage/wage/adjustments/form', $vars + [
        'active_nav'        => 'adjustments',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageDeductions(array $params = []): void
{
    attendanceWageGuard();
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/deductions/index', [
        'active_nav'        => 'deductions',
        'page_title'        => 'Employee Deductions',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageDeductionForm(array $params = []): void
{
    attendanceWageGuard();
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/deductions/form', [
        'active_nav'        => 'deductions',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageDeductionDetail(array $params = []): void
{
    attendanceWageGuard();
    $user = attendanceWageUser();
    $employeeName = rawurldecode((string)($params['employeeName'] ?? ''));
    if ($employeeName === '') {
        header('Location: ' . awBaseUrl() . '/admin/wage/deductions');
        exit;
    }
    try {
        $db = aw_db();
        // Fetch individual deduction line items for this employee from all sources
        $sql = "
            (SELECT 'manual' AS type, employee_deductions.deduction_id AS source_id, employee_deductions.amount, employee_deductions.description, employee_deductions.status, employee_deductions.deduction_date AS date
             FROM employee_deductions WHERE employee_deductions.employee_name = :name)
            UNION ALL
            (SELECT 'cash_advance' AS type, cash_advance_repayments.repayment_id AS source_id, cash_advance_repayments.amount,
                    CONCAT('Cash Advance #', cash_advances.advance_id) AS description,
                    IF(cash_advance_repayments.status='deducted','completed',cash_advance_repayments.status) AS status, cash_advance_repayments.created_at AS date
             FROM cash_advance_repayments
             JOIN cash_advances ON cash_advances.advance_id = cash_advance_repayments.advance_id
             LEFT JOIN employee_profiles ON employee_profiles.profile_id = cash_advances.employee_profile_id
             WHERE CONCAT_WS(' ', NULLIF(employee_profiles.first_name,''), NULLIF(employee_profiles.middle_name,''), NULLIF(employee_profiles.last_name,''), NULLIF(employee_profiles.suffix,'')) = :name2)
            UNION ALL
            (SELECT 'sss' AS type, salary_computations.computation_id AS source_id, salary_computations.sss_employee AS amount,
                    CONCAT('SSS — ', payroll_periods.period_name) AS description, salary_computations.status, salary_computations.computation_date AS date
             FROM salary_computations
             JOIN payroll_periods ON payroll_periods.period_id = salary_computations.payroll_period_id
             LEFT JOIN employee_profiles ON employee_profiles.profile_id = salary_computations.employee_profile_id
             WHERE CONCAT_WS(' ', NULLIF(employee_profiles.first_name,''), NULLIF(employee_profiles.middle_name,''), NULLIF(employee_profiles.last_name,''), NULLIF(employee_profiles.suffix,'')) = :name3 AND salary_computations.sss_employee > 0 AND employee_profiles.sss_applicable = 1)
            UNION ALL
            (SELECT 'philhealth' AS type, salary_computations.computation_id AS source_id, salary_computations.philhealth_employee AS amount,
                    CONCAT('PhilHealth — ', payroll_periods.period_name) AS description, salary_computations.status, salary_computations.computation_date AS date
             FROM salary_computations
             JOIN payroll_periods ON payroll_periods.period_id = salary_computations.payroll_period_id
             LEFT JOIN employee_profiles ON employee_profiles.profile_id = salary_computations.employee_profile_id
             WHERE CONCAT_WS(' ', NULLIF(employee_profiles.first_name,''), NULLIF(employee_profiles.middle_name,''), NULLIF(employee_profiles.last_name,''), NULLIF(employee_profiles.suffix,'')) = :name4 AND salary_computations.philhealth_employee > 0 AND employee_profiles.philhealth_applicable = 1)
            UNION ALL
            (SELECT 'pagibig' AS type, salary_computations.computation_id AS source_id, salary_computations.pagibig_employee AS amount,
                    CONCAT('Pag-IBIG — ', payroll_periods.period_name) AS description, salary_computations.status, salary_computations.computation_date AS date
             FROM salary_computations
             JOIN payroll_periods ON payroll_periods.period_id = salary_computations.payroll_period_id
             LEFT JOIN employee_profiles ON employee_profiles.profile_id = salary_computations.employee_profile_id
             WHERE CONCAT_WS(' ', NULLIF(employee_profiles.first_name,''), NULLIF(employee_profiles.middle_name,''), NULLIF(employee_profiles.last_name,''), NULLIF(employee_profiles.suffix,'')) = :name5 AND salary_computations.pagibig_employee > 0 AND employee_profiles.pagibig_applicable = 1)
            ORDER BY date DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([':name' => $employeeName, ':name2' => $employeeName, ':name3' => $employeeName, ':name4' => $employeeName, ':name5' => $employeeName]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        // Format amounts for display
        foreach ($rows as &$r) { $r['amount_fmt'] = number_format((float)$r['amount'], 2); }
        unset($r);
        $totalDeductions = array_sum(array_column($rows, 'amount'));
    } catch (\Throwable $e) {
        $rows = [];
        $totalDeductions = 0;
    }
    echo app()->render('modules/attendance-wage/wage/deductions/detail', [
        'active_nav'        => 'deductions',
        'page_title'        => 'Deductions — ' . $employeeName,
        'current_user_role' => $user['role'] ?? '',
        'employee_name'     => $employeeName,
        'deductions'        => $rows,
        'total_deductions'  => number_format($totalDeductions, 2),
    ]);
}

function wagePageCashAdvances(array $params = []): void
{
    attendanceWageGuard();
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
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/cash-advances/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'summary' => $summary,
        'selectedCA' => $selectedCA,
        'employeeCAs' => $employeeCAs,
        'active_nav'        => 'cash-advances',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageCashAdvanceForm(array $params = []): void
{
    attendanceWageGuard();
    $employees = [];
    try {
        $db = aw_db();
        $employees = $db->query("SELECT profile_id, CONCAT_WS(' ', first_name, middle_name, last_name, suffix) AS full_name, position FROM employee_profiles WHERE is_active = 1 ORDER BY last_name ASC, first_name ASC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/cash-advances/form', [
        'employees' => $employees,
        'today' => date('Y-m-d'),
        'active_nav'        => 'cash-advances',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageHolidays(array $params = []): void
{
    attendanceWageGuard();
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
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/holidays/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'edit_holiday' => $editHoliday,
        'active_nav'        => 'holidays',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageSchedules(array $params = []): void
{
    attendanceWageGuard();
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
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/schedules/index', [
        'schedules' => $schedules,
        'employees' => $employees,
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'active_nav'        => 'schedules',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageReports(array $params = []): void
{
    attendanceWageGuard();
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/reports/index', [
        'active_nav'        => 'reports',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageReportDetail(array $params = []): void
{
    attendanceWageGuard();
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

    $user = attendanceWageUser();
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
        'active_nav'        => 'reports',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageBenefitsCalc(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/benefits-calculator', [
        'active_nav'        => 'benefits',
        'page_title'        => 'Benefits Calculator',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageMigrationWizard(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/migration-wizard', [
        'active_nav'        => 'migration',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function wagePageLocations(array $params = []): void
{
    attendanceWageGuard();
    // Entity view (ikb_entity_list) handles data — page only passes context
    echo app()->render('modules/attendance-wage/wage/locations/index', [
        'success' => $_GET['success'] ?? '',
        'error' => $_GET['error'] ?? '',
        'active_nav' => 'locations',
    ]);
}

function wagePageLocationForm(array $params = []): void
{
    attendanceWageGuard();
    $editId = (int)($params['id'] ?? 0);
    $settings = getModuleSettings('attendance-wage');
    $tid = app()->tenant()->current() ?? '';
    $vars = ['id' => $editId, 'radius_meters' => 100, 'maps_api_key' => $settings['google_maps_api_key'] ?? '', 'active_nav' => 'locations'];
    if ($editId > 0) {
        try {
            $db = aw_db();
            $stmt = $db->prepare("SELECT * FROM office_locations WHERE location_id = :id AND tenant_id = :tid");
            $stmt->execute([':id' => $editId, ':tid' => $tid]);
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

function wagePageProfile(array $params = []): void
{
    attendanceWageGuard();
    $user = attendanceWageUser();
    if (!$user) {
        header('Location: ' . awBaseUrl() . '/attendance-wage/login');
        exit;
    }
    // Read fresh user data from DB (not session/JWT) so edits show immediately.
    $userId = aw_extractUserId($user);
    $dbUser = $user;
    if ($userId > 0) {
        try {
            $db = aw_db();
            $stmt = $db->prepare("SELECT username, email, full_name, role FROM attendance_wage_users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) { $dbUser = $row; }
        } catch (\Throwable $e) {}
    }
    echo app()->render('modules/attendance-wage/wage/profile', [
        'success'           => $_GET['success'] ?? '',
        'error'             => $_GET['error'] ?? '',
        'active_nav'        => 'profile',
        'current_user_role' => $dbUser['role'] ?? '',
        'user_full_name'    => $dbUser['full_name'] ?? '',
        'user_username'     => $dbUser['username'] ?? '',
        'user_email'        => $dbUser['email'] ?? '',
        'user_role'         => $dbUser['role'] ?? '',
    ]);
}

