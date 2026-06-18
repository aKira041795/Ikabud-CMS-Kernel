<?php

declare(strict_types=1);

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
                    ep.sss_number, ep.philhealth_number, ep.pagibig_number, ep.tin_number,
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
            fputcsv($out, ['Payroll Report — All Periods', date('Y-m-d')]);
            fputcsv($out, []);
            foreach ($periods as $period) {
                fputcsv($out, ['Period: ' . $period['period_name'], $period['start_date'] . ' to ' . $period['end_date'], 'Pay Date: ' . ($period['pay_date'] ?? '—'), 'Status: ' . ucfirst($period['status'])]);
                // Get computations for this period
                $comp = $db->prepare(
                    "SELECT CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                            ep.employee_number, ep.position, ep.department,
                            sc.gross_pay, sc.total_deductions, sc.net_pay, sc.status
                     FROM salary_computations sc
                     JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
                     WHERE sc.payroll_period_id = :pid
                     ORDER BY ep.last_name ASC, ep.first_name ASC"
                );
                $comp->execute([':pid' => $period['period_id']]);
                $rows = $comp->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                fputcsv($out, ['Employee', 'Employee #', 'Position', 'Department', 'Gross Pay', 'Deductions', 'Net Pay', 'Status']);
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['employee_name'] ?? '—',
                        $r['employee_number'] ?? '—',
                        $r['position'] ?? '—',
                        $r['department'] ?? '—',
                        number_format((float)($r['gross_pay'] ?? 0), 2),
                        number_format((float)($r['total_deductions'] ?? 0), 2),
                        number_format((float)($r['net_pay'] ?? 0), 2),
                        ucfirst($r['status'] ?? '—'),
                    ]);
                }
                fputcsv($out, []); // blank line between periods
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
                    sc.gross_pay, sc.total_deductions, sc.net_pay, sc.status,
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
            fputcsv($out, ['Payroll Report', $period['period_name']]);
            fputcsv($out, ['Period', $period['start_date'] . ' to ' . $period['end_date']]);
            fputcsv($out, ['Pay Date', $period['pay_date'] ?? '—']);
            fputcsv($out, []);
            fputcsv($out, ['#', 'Employee', 'Employee #', 'Position', 'Department', 'Gross Pay', 'Deductions', 'Net Pay', 'Status']);
            $i = 1;
            foreach ($rows as $r) {
                fputcsv($out, [
                    $i++,
                    $r['employee_name'] ?? '—',
                    $r['employee_number'] ?? '—',
                    $r['position'] ?? '—',
                    $r['department'] ?? '—',
                    number_format((float)($r['gross_pay'] ?? 0), 2),
                    number_format((float)($r['total_deductions'] ?? 0), 2),
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
    if ($salary <= 0) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok' => false, 'error' => 'Salary amount is required']);
        return;
    }
    try {
        $benefits = aw_calculateBenefits($salary);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok' => true, 'data' => [
            'gross_salary' => $salary,
            'sss' => ['employee' => $benefits['sss']['employee'], 'employer' => $benefits['sss']['employer']],
            'philhealth' => ['employee' => $benefits['philhealth']['employee'], 'employer' => $benefits['philhealth']['employer']],
            'pagibig' => ['employee' => $benefits['pagibig']['employee'], 'employer' => $benefits['pagibig']['employer']],
            'total_employee' => round($benefits['sss']['employee'] + $benefits['philhealth']['employee'] + $benefits['pagibig']['employee'], 2),
            'total_employer' => round($benefits['sss']['employer'] + $benefits['philhealth']['employer'] + $benefits['pagibig']['employer'], 2),
        ]]);
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
