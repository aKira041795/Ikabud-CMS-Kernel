<?php

declare(strict_types=1);

/**
 * Cash advance API handlers.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function wageApiCashAdvancesList(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: List cash advances for current user or all (admin)
    return ['ok' => true, 'data' => []];
}

function wageApiCashAdvanceCreate(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: Create cash advance request (checks max amount, max active count)
    return ['ok' => true, 'message' => 'Cash advance request submitted'];
}

function wageApiCashAdvanceApprove(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.approve@1');
    // TODO: Approve/reject cash advance, set up repayment schedule
    return ['ok' => true, 'message' => 'Cash advance approved'];
}
