<?php

declare(strict_types=1);

/**
 * Attendance page handlers.
 */



function attendancePageClock(array $params = []): void
{
    attendanceWageGuard();
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/attendance/clock', [
        'active_nav'        => 'attendance',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function attendancePageRecords(array $params = []): void
{
    attendanceWageGuard();
    $records = [];
    $selectedEmployee = null;
    $selectedRecords = [];
    $employeeId = (int)($_GET['employee_id'] ?? 0);
    $exportCsv = ($_GET['export'] ?? '') === 'csv';

    // CSV export for selected employee
    if ($exportCsv && $employeeId > 0) {
        attendanceExportCsv($employeeId);
        return;
    }

    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';

        // Fetch all active employees with their latest attendance record for today
        $stmt = $db->prepare(
            "SELECT ep.profile_id, ep.first_name, ep.last_name, ep.middle_name, ep.suffix,
                    CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS full_name,
                    ep.position, ep.department, ep.employee_number, ep.onsite_attendance,
                    ar.attendance_id, ar.clock_in, ar.clock_out, ar.location_in, ar.location_out,
                    ar.status AS att_status, ar.photo_in, ar.photo_out,
                    ar.latitude_in, ar.longitude_in
             FROM employee_profiles ep
             LEFT JOIN attendance_records ar ON ar.user_id = ep.user_id
                 AND DATE(ar.clock_in) = CURDATE()
                 AND ar.attendance_id = (
                     SELECT MAX(ar2.attendance_id) FROM attendance_records ar2
                     WHERE ar2.user_id = ep.user_id AND DATE(ar2.clock_in) = CURDATE()
                 )
             WHERE ep.tenant_id = :tid AND ep.is_active = 1
             ORDER BY ep.last_name ASC, ep.first_name ASC
             LIMIT 100"
        );
        $stmt->execute([':tid' => $tid]);
        $records = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // If an employee is selected, fetch their full history
        if ($employeeId > 0) {
            $es = $db->prepare(
                "SELECT ep.profile_id, ep.first_name, ep.last_name, ep.middle_name, ep.suffix,
                        CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS full_name,
                        ep.position, ep.department, ep.employee_number
                 FROM employee_profiles ep
                 WHERE ep.profile_id = :pid AND ep.tenant_id = :tid
                 LIMIT 1"
            );
            $es->execute([':pid' => $employeeId, ':tid' => $tid]);
            $selectedEmployee = $es->fetch(\PDO::FETCH_ASSOC) ?: null;

            if ($selectedEmployee) {
                $er = $db->prepare(
                    "SELECT ar.*, u.full_name AS user_name,
                            TIMESTAMPDIFF(MINUTE, ar.clock_in, COALESCE(ar.clock_out, NOW())) AS minutes_logged,
                            ROUND(TIMESTAMPDIFF(MINUTE, ar.clock_in, COALESCE(ar.clock_out, NOW())) / 60, 2) AS hours_logged
                     FROM attendance_records ar
                     JOIN attendance_wage_users u ON u.id = ar.user_id
                     WHERE u.id = (SELECT user_id FROM employee_profiles WHERE profile_id = :pid LIMIT 1)
                     ORDER BY ar.clock_in DESC
                     LIMIT 50"
                );
                $er->execute([':pid' => $employeeId]);
                $selectedRecords = $er->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            }
        }
    } catch (\Throwable $e) {}

    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/attendance/records', [
        'records'           => $records,
        'selected_employee' => $selectedEmployee,
        'selected_records'  => $selectedRecords,
        'employee_id'       => $employeeId,
        'today'             => date('Y-m-d'),
        'success'           => $_GET['success'] ?? '',
        'error'             => $_GET['error'] ?? '',
        'active_nav'        => 'attendance',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

/**
 * Export selected employee's attendance records as CSV.
 */
function attendanceExportCsv(int $employeeId): void
{
    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';

        // Get employee info
        $es = $db->prepare(
            "SELECT CONCAT_WS(' ', first_name, last_name) AS full_name, employee_number
             FROM employee_profiles WHERE profile_id = :pid AND tenant_id = :tid LIMIT 1"
        );
        $es->execute([':pid' => $employeeId, ':tid' => $tid]);
        $emp = $es->fetch(\PDO::FETCH_ASSOC);
        $empName = $emp ? ($emp['full_name'] ?? 'Employee') : 'Employee';
        $empNum = $emp ? ($emp['employee_number'] ?? '') : '';

        // Get attendance records with coordinates
        $er = $db->prepare(
            "SELECT ar.clock_in, ar.clock_out, ar.status,
                    ar.location_in, ar.location_out,
                    COALESCE(ar.latitude_in, ar.latitude_out) AS latitude,
                    COALESCE(ar.longitude_in, ar.longitude_out) AS longitude,
                    ar.photo_in, ar.photo_out,
                    TIMESTAMPDIFF(MINUTE, ar.clock_in, COALESCE(ar.clock_out, NOW())) AS minutes_logged,
                    ROUND(TIMESTAMPDIFF(MINUTE, ar.clock_in, COALESCE(ar.clock_out, NOW())) / 60, 2) AS hours_logged
             FROM attendance_records ar
             WHERE ar.user_id = (SELECT user_id FROM employee_profiles WHERE profile_id = :pid LIMIT 1)
             ORDER BY ar.clock_in DESC
             LIMIT 200"
        );
        $er->execute([':pid' => $employeeId]);
        $records = $er->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $filename = 'attendance_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $empName) . '_' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $out = fopen('php://output', 'w');

        // CSV header
        fputcsv($out, [
            'Date', 'Clock In', 'Clock Out',
            'Hours', 'Status',
            'Location In', 'Location Out',
            'Latitude', 'Longitude',
            'Photo In', 'Photo Out',
        ]);

        foreach ($records as $r) {
            $lat = $r['latitude'] ?? '';
            $lng = $r['longitude'] ?? '';
            $coords = ($lat !== null && $lng !== null) ? $lat . ',' . $lng : '';

            fputcsv($out, [
                substr($r['clock_in'] ?? '', 0, 10),
                substr($r['clock_in'] ?? '', 11, 8),
                $r['clock_out'] ? substr($r['clock_out'], 11, 8) : '(active)',
                $r['hours_logged'] ?? 0,
                $r['status'] ?? '',
                $r['location_in'] ?? '',
                $r['location_out'] ?? '',
                $lat ?? '',
                $lng ?? '',
                $r['photo_in'] ?? '',
                $r['photo_out'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

function attendancePageHistory(array $params = []): void
{
    attendanceWageGuard();
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/attendance/history', [
        'active_nav'        => 'attendance',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function attendancePageReport(array $params = []): void
{
    attendanceWageGuard();
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/attendance/report', [
        'active_nav'        => 'attendance',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function attendancePageKiosk(array $params = []): void
{
    // No auth required — public kiosk
    echo app()->render('modules/attendance-wage/attendance/kiosk', [
        'active_nav' => 'attendance',
    ]);
}
