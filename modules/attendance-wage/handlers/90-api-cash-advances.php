<?php

declare(strict_types=1);

/**
 * Cash advance API handlers.
 */



function wageApiCashAdvancesList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: List cash advances for current user or all (admin)
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => []]); return;
}

function wageApiCashAdvanceCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: Create cash advance request (checks max amount, max active count)
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Cash advance request submitted']); return;
}

function wageApiCashAdvanceApprove(array $params = []): void
{
    attendanceWageGuard('attendance_wage.approve@1');
    // TODO: Approve/reject cash advance, set up repayment schedule
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Cash advance approved']); return;
}
