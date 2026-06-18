<?php

declare(strict_types=1);

/**
 * Attendance page handlers.
 */



function attendancePageClock(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/attendance/clock');
}

function attendancePageHistory(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/attendance/history');
}

function attendancePageReport(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/attendance/report');
}
