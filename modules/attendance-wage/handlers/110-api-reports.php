<?php

declare(strict_types=1);

/** @param resource $stream */
function aw_reportCsv($stream, array $fields): void
{
    fputcsv($stream, $fields, ',', '"', '\\');
}

/**
 * Payroll report & payslip API handlers.
 */



function wageApiPayslip(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    $computationId = (int)($params['computationId'] ?? 0);
    if ($computationId <= 0) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok' => false, 'error' => 'Missing computation ID']);
        return;
    }
    try {
        $db = aw_db();
        $stmt = $db->prepare(
            "SELECT sc.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    ep.employee_number, ep.position, ep.department, ep.salary_type, ep.hire_date,
                    ep.sss_number, ep.philhealth_number, ep.pagibig_number, ep.tin_number, ep.dependents_count,
                    pp.period_name, pp.start_date AS period_start, pp.end_date AS period_end, pp.pay_date
             FROM salary_computations sc
             JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
             JOIN payroll_periods pp ON pp.period_id = sc.payroll_period_id
             WHERE sc.computation_id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $computationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(['ok' => false, 'error' => 'Computation not found']);
            return;
        }
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok' => true, 'data' => $row]);
    } catch (\Throwable $e) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiReportExportAll(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $format = strtolower((string)($_GET['format'] ?? 'csv'));
    try {
        $db = aw_db();
        $periods = $db->query("SELECT period_id, period_name, start_date, end_date, pay_date, status FROM payroll_periods WHERE status IN ('completed','approved','paid') ORDER BY start_date DESC")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="payroll_report_all_' . date('Ymd') . '.csv"');
            $out = fopen('php://output', 'w');
            aw_reportCsv($out, ['Payroll Report — All Periods', date('Y-m-d')]);
            aw_reportCsv($out, []);
            foreach ($periods as $period) {
                aw_reportCsv($out, ['Period: ' . $period['period_name'], $period['start_date'] . ' to ' . $period['end_date'], 'Pay Date: ' . ($period['pay_date'] ?? '—'), 'Status: ' . ucfirst($period['status'])]);
                // Get computations for this period
                $comp = $db->prepare(
                    "SELECT CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                            ep.employee_number, ep.position, ep.department,
                            sc.gross_pay, sc.sss_employee, sc.philhealth_employee, sc.pagibig_employee,
                            sc.income_tax, sc.salary_deductions, sc.cash_advance_deduction, sc.other_deductions,
                            sc.net_pay, sc.status
                     FROM salary_computations sc
                     JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
                     WHERE sc.payroll_period_id = :pid
                     ORDER BY ep.last_name ASC, ep.first_name ASC"
                );
                $comp->execute([':pid' => $period['period_id']]);
                $rows = $comp->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                aw_reportCsv($out, ['Employee', 'Employee #', 'Position', 'Department', 'Gross Pay', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Income Tax', 'Manual Deductions', 'Cash Advance', 'Other Deductions', 'Total Deductions', 'Net Pay', 'Status']);
                foreach ($rows as $r) {
                    $totalDed = (float)($r['sss_employee'] ?? 0) + (float)($r['philhealth_employee'] ?? 0) + (float)($r['pagibig_employee'] ?? 0)
                        + (float)($r['income_tax'] ?? 0) + (float)($r['salary_deductions'] ?? 0) + (float)($r['cash_advance_deduction'] ?? 0) + (float)($r['other_deductions'] ?? 0);
                    aw_reportCsv($out, [
                        $r['employee_name'] ?? '—',
                        $r['employee_number'] ?? '—',
                        $r['position'] ?? '—',
                        $r['department'] ?? '—',
                        number_format((float)($r['gross_pay'] ?? 0), 2),
                        number_format((float)($r['sss_employee'] ?? 0), 2),
                        number_format((float)($r['philhealth_employee'] ?? 0), 2),
                        number_format((float)($r['pagibig_employee'] ?? 0), 2),
                        number_format((float)($r['income_tax'] ?? 0), 2),
                        number_format((float)($r['salary_deductions'] ?? 0), 2),
                        number_format((float)($r['cash_advance_deduction'] ?? 0), 2),
                        number_format((float)($r['other_deductions'] ?? 0), 2),
                        number_format($totalDed, 2),
                        number_format((float)($r['net_pay'] ?? 0), 2),
                        ucfirst($r['status'] ?? '—'),
                    ]);
                }
                aw_reportCsv($out, []); // blank line between periods
            }
            fclose($out);
            exit;
        }
        header('Location: /admin/wage/reports');
        exit;
    } catch (\Throwable $e) {
        header('Location: /admin/wage/reports?error=' . urlencode($e->getMessage()));
        exit;
    }
}

function wageApiReportExport(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $periodId = (int)($params['periodId'] ?? 0);
    if ($periodId <= 0) {
        header('Location: /admin/wage/reports?error=' . urlencode('Invalid period.'));
        exit;
    }
    $format = strtolower((string)($_GET['format'] ?? 'csv'));
    try {
        $db = aw_db();
        // Fetch period info
        $ps = $db->prepare("SELECT * FROM payroll_periods WHERE period_id = :pid LIMIT 1");
        $ps->execute([':pid' => $periodId]);
        $period = $ps->fetch(\PDO::FETCH_ASSOC);
        if (!$period) {
            header('Location: /admin/wage/reports?error=' . urlencode('Period not found.'));
            exit;
        }
        // Fetch computations
        $comp = $db->prepare(
            "SELECT sc.computation_id, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    ep.employee_number, ep.position, ep.department,
                    sc.gross_pay, sc.sss_employee, sc.philhealth_employee, sc.pagibig_employee,
                    sc.income_tax, sc.salary_deductions, sc.cash_advance_deduction, sc.other_deductions,
                    sc.net_pay, sc.status,
                    pp.period_name
             FROM salary_computations sc
             JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
             JOIN payroll_periods pp ON pp.period_id = sc.payroll_period_id
             WHERE sc.payroll_period_id = :pid
             ORDER BY ep.last_name ASC, ep.first_name ASC"
        );
        $comp->execute([':pid' => $periodId]);
        $rows = $comp->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="payroll_' . $period['period_name'] . '_' . date('Ymd') . '.csv"');
            $out = fopen('php://output', 'w');
            aw_reportCsv($out, ['Payroll Report', $period['period_name']]);
            aw_reportCsv($out, ['Period', $period['start_date'] . ' to ' . $period['end_date']]);
            aw_reportCsv($out, ['Pay Date', $period['pay_date'] ?? '—']);
            aw_reportCsv($out, []);
            aw_reportCsv($out, ['#', 'Employee', 'Employee #', 'Position', 'Department', 'Gross Pay', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Income Tax', 'Manual Deductions', 'Cash Advance', 'Other Deductions', 'Total Deductions', 'Net Pay', 'Status']);
            $i = 1;
            foreach ($rows as $r) {
                $totalDed = (float)($r['sss_employee'] ?? 0) + (float)($r['philhealth_employee'] ?? 0) + (float)($r['pagibig_employee'] ?? 0)
                    + (float)($r['income_tax'] ?? 0) + (float)($r['salary_deductions'] ?? 0) + (float)($r['cash_advance_deduction'] ?? 0) + (float)($r['other_deductions'] ?? 0);
                aw_reportCsv($out, [
                    $i++,
                    $r['employee_name'] ?? '—',
                    $r['employee_number'] ?? '—',
                    $r['position'] ?? '—',
                    $r['department'] ?? '—',
                    number_format((float)($r['gross_pay'] ?? 0), 2),
                    number_format((float)($r['sss_employee'] ?? 0), 2),
                    number_format((float)($r['philhealth_employee'] ?? 0), 2),
                    number_format((float)($r['pagibig_employee'] ?? 0), 2),
                    number_format((float)($r['income_tax'] ?? 0), 2),
                    number_format((float)($r['salary_deductions'] ?? 0), 2),
                    number_format((float)($r['cash_advance_deduction'] ?? 0), 2),
                    number_format((float)($r['other_deductions'] ?? 0), 2),
                    number_format($totalDed, 2),
                    number_format((float)($r['net_pay'] ?? 0), 2),
                    ucfirst($r['status'] ?? '—'),
                ]);
            }
            fclose($out);
            exit;
        }
        // Fallback: redirect to report detail
        header('Location: /admin/wage/reports/' . $periodId);
        exit;
    } catch (\Throwable $e) {
        header('Location: /admin/wage/reports?error=' . urlencode($e->getMessage()));
        exit;
    }
}

function wageApiBenefitsCalculate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $salary = (float)($input['salary'] ?? $input['gross_pay'] ?? 0);
    $type = trim((string)($input['type'] ?? ''));
    if ($salary <= 0) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok' => false, 'error' => 'Salary amount is required']);
        return;
    }
    try {
        $benefits = aw_calculateBenefits($salary);

        if ($type !== '' && isset($benefits[$type])) {
            // Return single benefit type (used by individual calculator cards)
            $b = $benefits[$type];
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(['ok' => true, 'data' => [
                'employee' => $b['employee'],
                'employer' => $b['employer'],
                'total'    => round($b['employee'] + $b['employer'], 2),
            ]]);
        } else {
            // Return all benefits (used by summary views)
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(['ok' => true, 'data' => [
                'gross_salary' => $salary,
                'sss' => ['employee' => $benefits['sss']['employee'], 'employer' => $benefits['sss']['employer']],
                'philhealth' => ['employee' => $benefits['philhealth']['employee'], 'employer' => $benefits['philhealth']['employer']],
                'pagibig' => ['employee' => $benefits['pagibig']['employee'], 'employer' => $benefits['pagibig']['employer']],
                'total_employee' => round($benefits['sss']['employee'] + $benefits['philhealth']['employee'] + $benefits['pagibig']['employee'], 2),
                'total_employer' => round($benefits['sss']['employer'] + $benefits['philhealth']['employer'] + $benefits['pagibig']['employer'], 2),
            ]]);
        }
    } catch (\Throwable $e) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiMigrationBulk(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();

    try {
        $db = aw_db();
        // Find attendance_wage_users without employee_profiles and create profiles
        $stmt = $db->query(
            "SELECT u.id AS user_id, u.full_name, u.username, u.email
             FROM attendance_wage_users u
             WHERE u.is_active = 1
             AND u.id NOT IN (SELECT COALESCE(user_id, 0) FROM employee_profiles WHERE user_id > 0)"
        );
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $created = 0;
        foreach ($users as $user) {
            $parts = explode(' ', trim((string)($user['full_name'] ?? '')), 2);
            $firstName = $parts[0] ?? 'Employee';
            $lastName = $parts[1] ?? $firstName;
            $db->prepare(
                "INSERT INTO employee_profiles (tenant_id, user_id, first_name, last_name, employee_number, position, salary_type, basic_salary, is_active)
                 VALUES (:tid, :uid, :fn, :ln, :en, 'Staff', 'daily', 0, 1)
                 ON DUPLICATE KEY UPDATE is_active = 1"
            )->execute([
                ':tid' => app()->tenant()->current() ?? '',
                ':uid' => (int)$user['user_id'],
                ':fn' => $firstName, ':ln' => $lastName,
                ':en' => 'AUTO-' . str_pad((string)$user['user_id'], 4, '0', STR_PAD_LEFT),
            ]);
            $created++;
        }

        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/migration?success=' . urlencode("{$created} employee profile(s) created."));
            exit;
        }
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok' => true, 'message' => "{$created} employee profile(s) created.", 'created' => $created]);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/migration?error=' . urlencode($e->getMessage())); exit; }
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Printable payslip page — renders the payslip template with full computation data.
 * Route: GET /admin/wage/payslip/{computationId}
 */
function wagePagePayslip(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    $user = attendanceWageUser();
    $computationId = (int)($params['computationId'] ?? 0);
    if ($computationId <= 0) { echo "Missing computation ID."; return; }

    try {
        $db = aw_db();
        $stmt = $db->prepare(
            "SELECT sc.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    ep.employee_number, ep.position, ep.department, ep.salary_type, ep.hire_date,
                    ep.sss_number, ep.philhealth_number, ep.pagibig_number, ep.tin_number,
                    ep.photo_url, ep.dependents_count,
                    pp.period_name, pp.start_date AS period_start, pp.end_date AS period_end, pp.pay_date
             FROM salary_computations sc
             JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
             JOIN payroll_periods pp ON pp.period_id = sc.payroll_period_id
             WHERE sc.computation_id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $computationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { echo "Payslip not found."; return; }

        $vars = [
            'employee_name'     => $row['employee_name'] ?? '—',
            'employee_number'   => $row['employee_number'] ?? '—',
            'position'          => $row['position'] ?? '—',
            'department'        => $row['department'] ?? '—',
            'photo_url'         => $row['photo_url'] ?? '',
            'tin_number'        => $row['tin_number'] ?? '—',
            'sss_number'        => $row['sss_number'] ?? '—',
            'period_name'       => $row['period_name'] ?? '—',
            'period_start'      => $row['period_start'] ?? '—',
            'period_end'        => $row['period_end'] ?? '—',
            'pay_date'          => $row['pay_date'] ?? '—',
            'basic_pay'         => (float)($row['basic_pay'] ?? 0),
            'overtime_pay'      => (float)($row['overtime_pay'] ?? 0),
            'overtime_hours'    => (float)($row['overtime_hours'] ?? 0),
            'double_overtime_pay' => (float)($row['double_overtime_pay'] ?? 0),
            'double_overtime_hours' => (float)($row['double_overtime_hours'] ?? 0),
            'holiday_pay'       => (float)($row['holiday_pay'] ?? 0),
            'night_shift_pay'   => (float)($row['night_shift_pay'] ?? 0),
            'rest_day_pay'      => (float)($row['rest_day_pay'] ?? 0),
            'gross_pay'         => (float)($row['gross_pay'] ?? 0),
            'sss_employee'      => (float)($row['sss_employee'] ?? 0),
            'philhealth_employee' => (float)($row['philhealth_employee'] ?? 0),
            'pagibig_employee'  => (float)($row['pagibig_employee'] ?? 0),
            'total_adjustments' => (float)($row['total_adjustments'] ?? 0),
            'total_deductions'  => (float)($row['total_deductions'] ?? 0),
            'total_deductions_formatted' => number_format((float)($row['total_deductions'] ?? 0), 2),
            'net_pay'           => (float)($row['net_pay'] ?? 0),
            'generated_at'      => date('Y-m-d h:i A'),
        ];

        echo app()->render('modules/attendance-wage/wage/payslip', $vars);
    } catch (\Throwable $e) {
        echo "Error loading payslip: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Summary report page.
 */
function wagePageReportSummary(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/wage/reports/summary', [
        'active_nav'        => 'reports',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

/**
 * API: Get summary report data with filters.
 * GET /api/v1/wage/reports/summary?period_id=X&department=Y&status=Z
 */
function wageApiReportSummary(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    $periodId   = (int)($_GET['period_id'] ?? 0);
    $department = trim((string)($_GET['department'] ?? ''));
    $status     = trim((string)($_GET['status'] ?? ''));

    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';

        $where = 'sc.tenant_id = :tid';
        $binds = [':tid' => $tid];
        if ($periodId > 0) { $where .= ' AND sc.payroll_period_id = :pid'; $binds[':pid'] = $periodId; }
        if ($department !== '') { $where .= ' AND ep.department = :dept'; $binds[':dept'] = $department; }
        if ($status !== '') { $where .= ' AND sc.status = :st'; $binds[':st'] = $status; }

        $rows = $db->prepare(
            "SELECT sc.computation_id AS id,
                    CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    ep.department, ep.salary_type,
                    sc.gross_pay, sc.overtime_pay, sc.total_deductions, sc.net_pay,
                    (sc.total_additions - sc.other_deductions) AS total_adjustments,
                    (sc.sss_employee + sc.philhealth_employee + sc.pagibig_employee) AS benefits_total,
                    sc.status,
                    pp.period_name
             FROM salary_computations sc
             JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
             JOIN payroll_periods pp ON pp.period_id = sc.payroll_period_id
             WHERE {$where}
             ORDER BY pp.start_date DESC, ep.last_name ASC
             LIMIT 500"
        );
        $rows->execute($binds);
        $data = $rows->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $totals = ['gross' => 0, 'overtime' => 0, 'deductions' => 0, 'net' => 0];
        foreach ($data as &$r) {
            $totals['gross'] += (float)($r['gross_pay'] ?? 0);
            $totals['overtime'] += (float)($r['overtime_pay'] ?? 0);
            $totals['deductions'] += (float)($r['total_deductions'] ?? 0);
            $totals['net'] += (float)($r['net_pay'] ?? 0);
        }

        awJsonOut(['ok' => true, 'rows' => $data, 'totals' => $totals]);
    } catch (\Throwable $e) {
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * API: Get filter options for summary report.
 * GET /api/v1/wage/reports/summary-filters
 */
function wageApiReportSummaryFilters(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';
        $depts = $db->query("SELECT DISTINCT department FROM employee_profiles WHERE tenant_id = '{$tid}' AND is_active = 1 AND department IS NOT NULL AND department != '' ORDER BY department ASC")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        awJsonOut(['ok' => true, 'departments' => $depts]);
    } catch (\Throwable $e) {
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}
