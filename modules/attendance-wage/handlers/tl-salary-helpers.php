<?php

declare(strict_types=1);

/**
 * Team-lead salary helpers — in a separate file to bust OPcache.
 * Called from 150-team-lead.php and 140-api-groups.php.
 */

function tl_effectiveDailyRate(array $row): float
{
    $daily = (float)($row['daily_rate'] ?? 0);
    if ($daily > 0) return $daily;
    return (float)($row['basic_salary'] ?? 0);
}

function tl_computeSalary(array $es, string $dateFrom, string $dateTo): float
{
    $daysWorked = count($es['days'] ?? []);
    if ($es['salary_type'] === 'hourly') {
        $rate = ((float)($es['hourly_rate'] ?? 0)) > 0
            ? (float)$es['hourly_rate']
            : (tl_effectiveDailyRate($es) / 8);
        return round(((float)($es['total_hours'] ?? 0)) * $rate, 2);
    }
    if ($es['salary_type'] === 'fixed') {
        $workingDays = aw_workingDaysInPeriod($dateFrom, $dateTo);
        $dailyEq = $workingDays > 0 ? tl_effectiveDailyRate($es) / $workingDays : tl_effectiveDailyRate($es);
        return round($dailyEq * max(1, $daysWorked), 2);
    }
    return round(tl_effectiveDailyRate($es) * max(1, $daysWorked), 2);
}
