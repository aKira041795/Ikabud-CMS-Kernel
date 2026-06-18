<?php

declare(strict_types=1);

/**
 * Salary adjustment API handlers.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function wageApiAdjustmentsList(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: List salary adjustments
    return ['ok' => true, 'data' => []];
}

function wageApiAdjustmentCreate(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: Create adjustment (bonus, allowance, penalty, deduction, 13th month, holiday bonus, correction)
    return ['ok' => true, 'message' => 'Adjustment created'];
}

function wageApiAdjustmentApprove(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.approve@1');
    // TODO: Approve a salary adjustment
    return ['ok' => true, 'message' => 'Adjustment approved'];
}
