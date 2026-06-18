<?php

declare(strict_types=1);

/**
 * Salary computation API handlers.
 */



function wageApiComputationsList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    // TODO: List salary computations for a period
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => []]); return;
}

function wageApiComputationGet(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: Get single computation with breakdown
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => null]); return;
}

function wageApiComputeEmployee(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Compute salary for one employee (attendance → hours → pay → benefits → tax → net)
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Salary computed']); return;
}

function wageApiBulkCompute(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Bulk compute salaries for all employees in a period
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Bulk computation started']); return;
}

function wageApiApproveComputation(array $params = []): void
{
    attendanceWageGuard('attendance_wage.approve@1');
    // TODO: Approve a salary computation (computed → approved)
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Computation approved']); return;
}

function wageApiPayComputation(array $params = []): void
{
    attendanceWageGuard('attendance_wage.approve@1');
    // TODO: Mark computation as paid (approved → paid)
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Computation marked as paid']); return;
}
