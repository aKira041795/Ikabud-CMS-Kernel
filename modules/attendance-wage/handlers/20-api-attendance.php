<?php

declare(strict_types=1);

/**
 * Attendance API handlers — clock-in/out, records, photos.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function attendanceApiClockIn(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.clock@1');
    // TODO: Implement clock-in logic (photo, location, store validation)
    return ['ok' => true, 'message' => 'Clock-in recorded'];
}

function attendanceApiClockOut(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.clock@1');
    // TODO: Implement clock-out logic
    return ['ok' => true, 'message' => 'Clock-out recorded'];
}

function attendanceApiRecords(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: Return attendance records with filters
    return ['ok' => true, 'data' => []];
}

function attendanceApiPhoto(ModuleContext $ctx, string $file): void
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: Serve attendance photo from private storage
    app()->abort(404, 'Photo not found');
}
