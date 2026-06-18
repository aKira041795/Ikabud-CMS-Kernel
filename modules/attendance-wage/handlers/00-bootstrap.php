<?php

declare(strict_types=1);

/**
 * Bootstrap / Auth guards for the Attendance & Wage module.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

/**
 * Guard: require a specific capability. Aborts 403 if missing.
 */
function attendanceWageGuard(string $capability): void
{
    $userId = app()->auth()->userId();
    if (!$userId || !app()->capabilities()->userHas($userId, $capability)) {
        app()->abort(403, 'Insufficient permissions: ' . $capability);
    }
}

/**
 * Get the current user's accessible store IDs (scoped for supervisors).
 */
function attendanceWageAccessibleStoreIds(): array
{
    $userId = app()->auth()->userId();
    $db = module()->db();

    // Admin/HR sees all stores
    if (app()->capabilities()->userHas($userId, 'attendance_wage.admin@1')
        || app()->capabilities()->userHas($userId, 'attendance_wage.approve@1')) {
        $rows = $db->query('SELECT store_id FROM stores WHERE is_active = 1')->fetchAll(\PDO::FETCH_COLUMN);
        return $rows ?: [];
    }

    // Supervisor sees assigned stores
    $stmt = $db->prepare('SELECT store_id FROM user_stores WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
}

/**
 * Get module settings for the current tenant.
 */
function attendanceWageSettings(): array
{
    return module()->settings()->all();
}
