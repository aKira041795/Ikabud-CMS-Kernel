<?php

declare(strict_types=1);

/**
 * Holiday calendar API handlers.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function wageApiHolidaysList(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: List holidays (with year filter, type filter)
    return ['ok' => true, 'data' => []];
}

function wageApiHolidayCreate(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Create holiday (regular, special non-working, special working)
    return ['ok' => true, 'message' => 'Holiday created'];
}
