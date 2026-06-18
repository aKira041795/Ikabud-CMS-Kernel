<?php

declare(strict_types=1);

/**
 * Employee profile API handlers.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function wageApiEmployeesList(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: List employees with filters
    return ['ok' => true, 'data' => []];
}

function wageApiEmployeeGet(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: Get single employee profile
    return ['ok' => true, 'data' => null];
}

function wageApiEmployeeCreate(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Create employee profile
    return ['ok' => true, 'message' => 'Employee profile created'];
}

function wageApiEmployeeUpdate(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Update employee profile
    return ['ok' => true, 'message' => 'Employee profile updated'];
}
