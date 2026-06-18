<?php

declare(strict_types=1);

/**
 * Holiday calendar API handlers.
 */



function wageApiHolidaysList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    // TODO: List holidays (with year filter, type filter)
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'data' => []]); return;
}

function wageApiHolidayCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    // TODO: Create holiday (regular, special non-working, special working)
    header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok' => true, 'message' => 'Holiday created']); return;
}
