<?php

declare(strict_types=1);

/**
 * Payroll report & payslip API handlers.
 */



function wageApiPayslip(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: Generate payslip data for a computation
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => null]); return;
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
    // TODO: Calculate SSS/PhilHealth/Pag-IBIG for a given salary
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => [
        'sss' => ['employee' => 0, 'employer' => 0],
        'philhealth' => ['employee' => 0, 'employer' => 0],
        'pagibig' => ['employee' => 0, 'employer' => 0],
    ]]); return;
}

function wageApiMigrationBulk(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Bulk create employee profiles from users table
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Migration started']); return;
}
