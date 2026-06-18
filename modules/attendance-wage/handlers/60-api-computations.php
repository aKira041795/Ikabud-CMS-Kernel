<?php

declare(strict_types=1);

/**
 * Salary computation API handlers.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function wageApiComputationsList(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: List salary computations for a period
    return ['ok' => true, 'data' => []];
}

function wageApiComputationGet(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: Get single computation with breakdown
    return ['ok' => true, 'data' => null];
}

function wageApiComputeEmployee(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Compute salary for one employee (attendance → hours → pay → benefits → tax → net)
    return ['ok' => true, 'message' => 'Salary computed'];
}

function wageApiBulkCompute(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Bulk compute salaries for all employees in a period
    return ['ok' => true, 'message' => 'Bulk computation started'];
}

function wageApiApproveComputation(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.approve@1');
    // TODO: Approve a salary computation (computed → approved)
    return ['ok' => true, 'message' => 'Computation approved'];
}

function wageApiPayComputation(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.approve@1');
    // TODO: Mark computation as paid (approved → paid)
    return ['ok' => true, 'message' => 'Computation marked as paid'];
}
