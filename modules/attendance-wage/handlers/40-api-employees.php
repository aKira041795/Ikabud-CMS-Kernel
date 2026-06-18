<?php

declare(strict_types=1);

/**
 * Employee profile API handlers.
 */



function wageApiEmployeesList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: List employees with filters
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => []]); return;
}

function wageApiEmployeeGet(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: Get single employee profile
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => null]); return;
}

function wageApiEmployeeCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Create employee profile
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Employee profile created']); return;
}

function wageApiEmployeeUpdate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Update employee profile
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Employee profile updated']); return;
}
