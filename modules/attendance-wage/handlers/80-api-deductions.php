<?php

declare(strict_types=1);

/**
 * Employee deduction API handlers.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function wageApiDeductionsList(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: List employee deductions
    return ['ok' => true, 'data' => []];
}

function wageApiDeductionCreate(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: Create employee deduction (store-level: cash shortage, advance, etc.)
    return ['ok' => true, 'message' => 'Deduction created'];
}

function wageApiDeductionStatus(ModuleContext $ctx): array
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: Update deduction status (pending → approved → processed)
    return ['ok' => true, 'message' => 'Deduction status updated'];
}
