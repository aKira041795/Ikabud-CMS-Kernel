<?php

declare(strict_types=1);

/**
 * Payroll period API handlers.
 */



function wageApiPeriodsList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: List payroll periods
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => []]); return;
}

function wageApiPeriodGet(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: Get single payroll period
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => null]); return;
}

function wageApiPeriodCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Create payroll period with date overlap check
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Payroll period created']); return;
}

function wageApiPeriodUpdate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Update payroll period
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Payroll period updated']); return;
}
