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

    // CSV export for all employees (when no specific employee_id) or a single employee
    if ($exportCsv) {
        attendanceExportCsv($employeeId > 0 ? $employeeId : 0);
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

                // Render the records table via DefaultEntityRenderer with all columns.
                // The entity view handles: location with coordinates (location renderer),
                // photos with click-to-view (image renderer), inline editing for hours
                // (field_contracts → renderCellEditable in renderTableRow).
                $renderedRecordsTable = '';
                if (!empty($selectedRecords) && function_exists('app') && ($app = app()) !== null && method_exists($app, 'entityRenderers')) {
                    try {
                        $rows = [];
                        foreach ($selectedRecords as $rec) {
                            $photoIn = ($rec['photo_in'] ?? '') !== '' ? '/api/v1/attendance/photo/' . $rec['photo_in'] : '';
                            $photoOut = ($rec['photo_out'] ?? '') !== '' ? '/api/v1/attendance/photo/' . $rec['photo_out'] : '';
                            $rows[] = [
                                'id'           => $rec['attendance_id'],
                                'date'         => substr((string)($rec['clock_in'] ?? ''), 0, 10),
                                'clock_in'     => $rec['clock_in'] ?? '',
                                'clock_out'    => $rec['clock_out'] ?? '',
                                'hours'        => $rec['hours_logged'] ?? 0,
                                'location_in'  => $rec['location_in'] ?? '',
                                'latitude'     => $rec['latitude_in'] ?? null,
                                'longitude'    => $rec['longitude_in'] ?? null,
                                'location_out' => $rec['location_out'] ?? '',
                                'latitude_out' => $rec['latitude_out'] ?? null,
                                'longitude_out'=> $rec['longitude_out'] ?? null,
                                'status'       => $rec['status'] ?? '',
                                'photo_in'     => $photoIn,
                                'photo_out'    => $photoOut,
                            ];
                        }

                        $view = [
                            'fields' => ['date', 'clock_in', 'clock_out', 'hours', 'location_in', 'location_out', 'status', 'photo_in', 'photo_out'],
                            'view' => 'table',
                            'actions' => [],
                            'field_contracts' => [
                                'hours' => [
                                    'editable' => 'true',
                                    'update_capability' => 'attendance.record.hours.update@1',
                                ],
                            ],
                            'renderers' => [
                                'clock_in'    => 'datetime:time',
                                'clock_out'   => 'datetime:time',
                                'hours'       => 'string',
                                'location_in' => 'location',
                                'location_out'=> 'location',
                                'status'      => 'badge:{"active":"Clocked In|green","completed":"Done|blue","edited":"Edited|amber"}',
                                'photo_in'    => 'image:modal',
                                'photo_out'   => 'image:modal',
                            ],
                            'empty_state' => 'No attendance records found.',
                        ];

                        $renderedRecordsTable = $app->entityRenderers()->renderList($rows, $view, [
                            'source' => 'attendance_record.recent',
                            'view' => 'table',
                            'class' => '',
                        ]);
                    } catch (\Throwable $e) {
                        $renderedRecordsTable = '';
                    }
                }
            }
        }
    } catch (\Throwable $e) {}

    $user = attendanceWageUser();
    echo app()->render('modules/attendance-wage/attendance/records', [
        'records'                => $records,
        'selected_employee'      => $selectedEmployee,
        'selected_records'       => $selectedRecords,
        'rendered_records_table' => $renderedRecordsTable,
        'employee_id'            => $employeeId,
        'today'             => date('Y-m-d'),
        'success'           => $_GET['success'] ?? '',
        'error'             => $_GET['error'] ?? '',
        'active_nav'        => 'attendance',
        'current_user_role' => $user['role'] ?? '',
    ]);
}

/**
 * Export attendance records as CSV. If $employeeId is 0, exports ALL employees.
 */
function attendanceExportCsv(int $employeeId = 0): void
{
    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';

        if ($employeeId > 0) {
            // Single employee — get info and their records
            $es = $db->prepare(
                "SELECT CONCAT_WS(' ', first_name, last_name) AS full_name, employee_number
                 FROM employee_profiles WHERE profile_id = :pid AND tenant_id = :tid LIMIT 1"
            );
            $es->execute([':pid' => $employeeId, ':tid' => $tid]);
            $emp = $es->fetch(\PDO::FETCH_ASSOC);
            $filename = 'attendance_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $emp ? ($emp['full_name'] ?? 'Employee') : 'Employee') . '_' . date('Y-m-d') . '.csv';
            $empSql = "AND ar.user_id = (SELECT user_id FROM employee_profiles WHERE profile_id = :pid LIMIT 1)";
            $empParams = [':pid' => $employeeId];
        } else {
            // All employees
            $filename = 'attendance_all_employees_' . date('Y-m-d') . '.csv';
            $empSql = "AND ep.tenant_id = :tid";
            $empParams = [':tid' => $tid];
        }

        $er = $db->prepare(
            "SELECT CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    ep.employee_number,
                    ar.clock_in, ar.clock_out, ar.status,
                    ar.location_in, ar.location_out,
                    COALESCE(ar.latitude_in, ar.latitude_out) AS latitude,
                    COALESCE(ar.longitude_in, ar.longitude_out) AS longitude,
                    ar.photo_in, ar.photo_out,
                    TIMESTAMPDIFF(MINUTE, ar.clock_in, COALESCE(ar.clock_out, NOW())) AS minutes_logged,
                    ROUND(TIMESTAMPDIFF(MINUTE, ar.clock_in, COALESCE(ar.clock_out, NOW())) / 60, 2) AS hours_logged
             FROM attendance_records ar
             JOIN employee_profiles ep ON ep.user_id = ar.user_id
             WHERE ep.is_active = 1 {$empSql}
             ORDER BY ep.last_name ASC, ep.first_name ASC, ar.clock_in DESC
             LIMIT 500"
        );
        $er->execute($empParams);
        $records = $er->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $out = fopen('php://output', 'w');

        // CSV header
        fputcsv($out, [
            'Employee Name', 'Employee #',
            'Date', 'Clock In', 'Clock Out',
            'Hours', 'Status',
            'Location In', 'Location Out',
            'Latitude', 'Longitude',
            'Photo In', 'Photo Out',
        ]);

        foreach ($records as $r) {
            $lat = $r['latitude'] ?? '';
            $lng = $r['longitude'] ?? '';

            fputcsv($out, [
                $r['employee_name'] ?? '—',
                $r['employee_number'] ?? '—',
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
