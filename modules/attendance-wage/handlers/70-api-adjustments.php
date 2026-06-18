<?php

declare(strict_types=1);

/**
 * Salary adjustment API handlers.
 */



function wageApiAdjustmentsList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: List salary adjustments
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => []]); return;
}

function wageApiAdjustmentCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: Create adjustment (bonus, allowance, penalty, deduction, 13th month, holiday bonus, correction)
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Adjustment created']); return;
}

function wageApiAdjustmentApprove(array $params = []): void
{
    attendanceWageGuard('attendance_wage.approve@1');
    // TODO: Approve a salary adjustment
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Adjustment approved']); return;
}
