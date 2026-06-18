<?php

declare(strict_types=1);

/**
 * Payroll report & payslip API handlers.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function wageApiPayslip(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: Generate payslip data for a computation
    return ['ok' => true, 'data' => null];
}

function wageApiBenefitsCalculate(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: Calculate SSS/PhilHealth/Pag-IBIG for a given salary
    return ['ok' => true, 'data' => [
        'sss' => ['employee' => 0, 'employer' => 0],
        'philhealth' => ['employee' => 0, 'employer' => 0],
        'pagibig' => ['employee' => 0, 'employer' => 0],
    ]];
}

function wageApiMigrationBulk(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Bulk create employee profiles from users table
    return ['ok' => true, 'message' => 'Migration started'];
}
