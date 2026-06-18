<?php

declare(strict_types=1);

/**
 * Payroll period API handlers.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function wageApiPeriodsList(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: List payroll periods
    return ['ok' => true, 'data' => []];
}

function wageApiPeriodGet(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: Get single payroll period
    return ['ok' => true, 'data' => null];
}

function wageApiPeriodCreate(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Create payroll period with date overlap check
    return ['ok' => true, 'message' => 'Payroll period created'];
}

function wageApiPeriodUpdate(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Update payroll period
    return ['ok' => true, 'message' => 'Payroll period updated'];
}
