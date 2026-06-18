<?php

declare(strict_types=1);

function attendanceWageUser(): ?array
{
    $user = app()->user();
    if (is_array($user) && (($user['source'] ?? '') === 'attendance-wage')) {
        return $user;
    }
    return null;
}
