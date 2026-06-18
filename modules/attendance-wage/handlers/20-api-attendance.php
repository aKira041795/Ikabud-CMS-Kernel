<?php

declare(strict_types=1);

/**
 * Attendance API handlers — clock-in/out, records, photos.
 */



function attendanceApiClockIn(array $params = []): void
{
    attendanceWageGuard('attendance_wage.clock@1');
    // TODO: Implement clock-in logic (photo, location, store validation)
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Clock-in recorded']); return;
}

function attendanceApiClockOut(array $params = []): void
{
    attendanceWageGuard('attendance_wage.clock@1');
    // TODO: Implement clock-out logic
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Clock-out recorded']); return;
}

function attendanceApiRecords(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: Return attendance records with filters
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => []]); return;
}

function attendanceApiPhoto(array $params = [], string $file = ''): void
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: Serve attendance photo from private storage
    app()->abort(404, 'Photo not found');
}
