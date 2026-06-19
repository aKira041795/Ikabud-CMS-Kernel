<?php

declare(strict_types=1);

/**
 * Attendance page handlers.
 */



function attendancePageClock(array $params = []): void
{
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/attendance/clock', [
        'active_nav'        => 'attendance',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function attendancePageRecords(array $params = []): void
{
    $records = [];
    $selectedEmployee = null;
    $selectedRecords = [];
    $employeeId = (int)($_GET['employee_id'] ?? 0);

    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';

        // Fetch all active employees with their latest attendance record for today
        $stmt = $db->prepare(
            "SELECT ep.profile_id, ep.first_name, ep.last_name, ep.middle_name, ep.suffix,
                    CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS full_name,
                    ep.position, ep.department, ep.employee_number, ep.onsite_attendance,
                    ar.attendance_id, ar.clock_in, ar.clock_out, ar.location_in, ar.location_out,
                    ar.status AS att_status, ar.photo_in, ar.photo_out
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
                    "SELECT ar.*, u.full_name AS user_name
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

function attendancePageHistory(array $params = []): void
{
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/attendance/history', [
        'active_nav'        => 'attendance',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function attendancePageReport(array $params = []): void
{
    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/attendance/report', [
        'active_nav'        => 'attendance',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function attendancePageKiosk(array $params = []): void
{
    // No auth required — public kiosk
    echo app()->render('modules/attendance-wage/attendance/kiosk');
}
