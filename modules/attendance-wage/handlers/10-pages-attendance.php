<?php

declare(strict_types=1);

/**
 * Attendance page handlers.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function attendancePageClock(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.clock@1');
    return $ctx->render('modules/attendance-wage/attendance/clock');
}

function attendancePageHistory(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.clock@1');
    return $ctx->render('modules/attendance-wage/attendance/history');
}

function attendancePageReport(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.manage@1');
    return $ctx->render('modules/attendance-wage/attendance/report');
}
