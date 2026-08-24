<?php

declare(strict_types=1);

use Ikabud\Kernel\Services\ModuleBackupService;

/**
 * Attendance & Wage — Settings page + API handlers.
 *
 * Routes:
 *   GET  /admin/wage/settings  → wagePageSettings
 *   POST /api/v1/wage/settings → wageApiSettingsSave
 */

/**
 * Render the settings page.
 *
 * Reads both module-level settings (app_name, logo, google_maps_api_key, timezone)
 * and payroll_settings (working_days, overtime, etc.) via aw_payrollSettings().
 */
function wagePageSettings(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $user = attendanceWageUser();

    $moduleSettings = getModuleSettings('attendance-wage');
    $payrollSettings = aw_payrollSettings();

    // ── Fetch all users for the user management table ──
    $db = aw_db();
    $allUsers = $db->query('SELECT id, username, email, full_name, role, is_active FROM attendance_wage_users ORDER BY full_name ASC')
        ->fetchAll(PDO::FETCH_ASSOC);

    // Data-reset group catalog (Danger Zone panel)
    $resetGroups = [];
    foreach (awResetGroups() as $key => $def) {
        $resetGroups[] = ['key' => $key, 'label' => $def['label']];
    }

    // Database backup list (Backup panel)
    $backups = [];
    if (class_exists(ModuleBackupService::class)) {
        $backups = ModuleBackupService::list('attendance-wage', aw_backupDownloadPath());
    }

    $vars = [
        'app_name'             => $moduleSettings['app_name'] ?? 'ZAP',
        'logo_url'             => $moduleSettings['logo_url'] ?? '',
        'google_maps_api_key'  => $moduleSettings['google_maps_api_key'] ?? '',
        'timezone'             => $moduleSettings['timezone'] ?? 'Asia/Manila',
        // Payroll computation defaults
        'working_days_per_month' => $payrollSettings['working_days_per_month'] ?? 22,
        'working_hours_per_day'  => $payrollSettings['working_hours_per_day'] ?? 8.00,
        'overtime_calculation'   => $payrollSettings['overtime_calculation'] ?? 'both',
        'round_hours_to'         => $payrollSettings['round_hours_to'] ?? '0.25',
        'pay_frequency'          => $payrollSettings['pay_frequency'] ?? 'semi_monthly',
        'default_rest_day'       => $payrollSettings['default_rest_day'] ?? 'sunday',
        'rest_day_rate'          => $payrollSettings['rest_day_rate'] ?? 1.30,
        'night_diff_rate'        => $payrollSettings['night_diff_rate'] ?? 0.10,
        'max_cash_advance_pct'   => $payrollSettings['max_cash_advance_pct'] ?? 50.00,
        'max_active_advances'    => $payrollSettings['max_active_advances'] ?? 2,
        // UI state
        'success'    => $_GET['success'] ?? '',
        'error'      => $_GET['error'] ?? '',
        'active_nav' => 'settings',
        'current_user_name' => $user['name'] ?? $user['username'] ?? '',
        'current_user_role' => $user['role'] ?? '',
        'csrf_token' => app()->csrfToken(),
        'users'           => $allUsers,
        'current_user_id' => aw_currentUserId(),
        'reset_groups'    => $resetGroups,
        'backups'         => $backups,
    ];

    echo app()->render('modules/attendance-wage/wage/settings', $vars);
}

/**
 * Save settings (branding + timezone + payroll defaults).
 *
 * Accepts multipart/form-data for logo upload.
 */
function wageApiSettingsSave(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');

    // CSRF already enforced by attendanceWageGuard()

    $moduleSettings = getModuleSettings('attendance-wage');

    // ── Module-level settings (stored via module settings system) ──

    // App name
    if (isset($_POST['app_name'])) {
        $moduleSettings['app_name'] = trim((string)$_POST['app_name']);
    }

    // Logo upload
    if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['logo']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMime, true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid logo format. Allowed: JPEG, PNG, WebP, SVG.']);
            exit;
        }
        if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Logo too large. Maximum 2 MB.']);
            exit;
        }

        $ext = match ($mime) {
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg',
            default         => 'png',
        };
        $filename = 'logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $uploadDir = STORAGE_PATH . '/uploads/logos';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }
        $dest = $uploadDir . '/' . $filename;
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
            // Remove old logo if exists
            if (!empty($moduleSettings['logo_url'])) {
                $oldPath = str_replace(external_base_url() . '/storage/', STORAGE_PATH . '/', $moduleSettings['logo_url']);
                $oldLocal = str_replace('/storage/', STORAGE_PATH . '/', $moduleSettings['logo_url']);
                $localPath = is_file($oldPath) ? $oldPath : (is_file($oldLocal) ? $oldLocal : null);
                if ($localPath && $localPath !== $dest) {
                    @unlink($localPath);
                }
            }
            $moduleSettings['logo_url'] = external_base_url() . '/storage/uploads/logos/' . rawurlencode($filename);
        }
    }

    // Google Maps API key
    if (isset($_POST['google_maps_api_key'])) {
        $moduleSettings['google_maps_api_key'] = trim((string)$_POST['google_maps_api_key']);
    }

    // Timezone
    if (isset($_POST['timezone'])) {
        $tz = trim((string)$_POST['timezone']);
        // Validate timezone
        if (in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $moduleSettings['timezone'] = $tz;
        }
    }

    saveModuleSettings('attendance-wage', $moduleSettings);

    // ── Payroll settings (stored in payroll_settings table) ──
    $payrollFields = [
        'working_days_per_month' => 'int',
        'working_hours_per_day'  => 'float',
        'overtime_calculation'   => 'string',
        'round_hours_to'         => 'string',
        'pay_frequency'          => 'string',
        'default_rest_day'       => 'string',
        'rest_day_rate'          => 'float',
        'night_diff_rate'        => 'float',
        'max_cash_advance_pct'   => 'float',
        'max_active_advances'    => 'int',
    ];

    $updates = [];
    foreach ($payrollFields as $field => $type) {
        if (isset($_POST[$field])) {
            $val = $_POST[$field];
            $updates[$field] = match ($type) {
                'int'   => (int)$val,
                'float' => (float)$val,
                default => trim((string)$val),
            };
        }
    }

    if (!empty($updates)) {
        try {
            $db = aw_db();
            $tid = app()->tenant()->current() ?? '';
            $sets = [];
            $params = [':tid' => $tid];
            foreach ($updates as $field => $val) {
                $sets[] = "`{$field}` = :update_{$field}";
                $params[":{$field}"] = $val;
                $params[":update_{$field}"] = $val;
            }
            $sql = "INSERT INTO payroll_settings (tenant_id, " . implode(', ', array_map(fn($f) => "`{$f}`", array_keys($updates))) . ")
                    VALUES (:tid, " . implode(', ', array_map(fn($f) => ":{$f}", array_keys($updates))) . ")
                    ON DUPLICATE KEY UPDATE " . implode(', ', $sets);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            write_log('attendance_wage_settings_save_error', 'error', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to save payroll settings.']);
            exit;
        }
    }

    // Clear payroll settings cache so next read picks up changes
    $ref = new \ReflectionFunction('aw_payrollSettings');
    // The static cache will be reset on next request (FPM lifecycle)

    // Invalidate cached settings page so the next visitor gets fresh content
    if (class_exists(\Ikabud\Kernel\DiSyL\Cache\FragmentStore::class)) {
        try {
            $store = new \Ikabud\Kernel\DiSyL\Cache\FragmentStore();
            $store->invalidate(['attendance_settings'], app()->tenant()->current() ?? '_global');
        } catch (\Throwable $e) {
            // Non-fatal: cache invalidation is best-effort
        }
    }

    echo json_encode(['ok' => true, 'message' => 'Settings saved successfully.']);
    exit;
}

/** Download URL path for attendance-wage backups. */
function aw_backupDownloadPath(): string
{
    return '/api/v1/wage/settings/backup/download';
}

/**
 * Tenant-scoped ModuleContext for backup tooling.
 *
 * The default module('attendance-wage') context points at the base app DB,
 * but AW data lives in the tenant DB. Wrap the tenant PDO in a ModuleDB so
 * ModuleBackupService dumps the correct database.
 */
function awBackupCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $base = function_exists('moduleContextFor') ? moduleContextFor('attendance-wage') : null;
    $manifest = $base ? $base->manifest() : [];
    $owns = is_array($manifest['owns_tables'] ?? null) ? $manifest['owns_tables'] : [];
    $reads = is_array($manifest['reads_tables'] ?? null) ? $manifest['reads_tables'] : [];
    $db = new \Ikabud\Kernel\Contracts\ModuleDB(aw_db(), 'attendance-wage', $owns, $reads);
    return new \Ikabud\Kernel\Contracts\ModuleContext(app(), 'attendance-wage', $db, $manifest);
}

/**
 * POST /api/v1/wage/settings/backup
 * Generate a data-only SQL backup of all attendance-wage tenant tables and
 * return the file metadata + existing backup list (admin only).
 */
function wageApiSettingsBackupGenerate(): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $uid = aw_currentUserId();

    try {
        $result = ModuleBackupService::generate(awBackupCtx(), '', 'manual backup', [
            'download_path' => aw_backupDownloadPath(),
            'retention_days' => 14,
            'event'          => 'attendance_wage.backup.created',
            'by_user'        => $uid,
        ]);
        $backups = ModuleBackupService::list('attendance-wage', aw_backupDownloadPath());
    } catch (\Throwable $e) {
        write_log('attendance_wage.backup.error', 'error', ['message' => $e->getMessage()]);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Failed to generate backup.']);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'      => true,
        'backup'  => $result,
        'backups' => $backups,
        'message' => 'Backup created: ' . $result['file_name'],
    ]);
    exit;
}

/**
 * GET /api/v1/wage/settings/backup/download?file=<name>
 * Stream a generated backup file as a download (admin only).
 */
function wageApiSettingsBackupDownload(): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    ModuleBackupService::download(awBackupCtx(), '', (string)($_GET['file'] ?? ''));
}

/**
 * Data-reset group catalog for the Attendance & Wage tenant database.
 * Mirrors PAL's palResetGroups() so the same logical groups can be wiped
 * granularly. Configuration (payroll_settings) is intentionally NOT a reset
 * group — it is always preserved, like PAL preserves its settings.
 */
function awResetGroups(): array
{
    return [
        'employees'     => ['label' => 'Employees & Profiles',               'tables' => ['employee_profiles', 'employee_schedules']],
        'attendance'    => ['label' => 'Attendance Records',                 'tables' => ['attendance_records']],
        'groups'        => ['label' => 'Teams / Groups & Members',           'tables' => ['attendance_team_lead_otps', 'attendance_group_members', 'attendance_groups']],
        'payroll'       => ['label' => 'Payroll & Salary Computations',      'tables' => ['payroll_periods', 'salary_computations', 'salary_adjustments', 'employee_deductions']],
        'cash_advances' => ['label' => 'Cash Advances & Repayments',         'tables' => ['cash_advances', 'cash_advance_repayments']],
        'benefits'      => ['label' => 'Benefits Contribution Rates',        'tables' => ['benefits_contribution_rates']],
        'holidays'      => ['label' => 'Holidays',                           'tables' => ['holidays']],
        'locations'     => ['label' => 'Office Locations',                   'tables' => ['office_locations']],
    ];
}

/**
 * Wipe the selected data groups for the active tenant.
 * - Tenant-scoped tables (have tenant_id) are filtered by tenant id.
 * - cash_advance_repayments has no tenant_id (per-tenant DB) → full delete.
 * - Full mode wipes all user accounts except the logged-in admin, unless
 *   $keepUsers is true (the "exclude users" option).
 * - payroll_settings (config) is always preserved.
 */
function awResetTenantData(\PDO $db, string $tenantId, int $uid, array $groups, bool $full, bool $keepUsers = false): void
{
    // Tables that carry a tenant_id column → scoped delete.
    $scopedTables = [
        'employee_profiles', 'employee_schedules', 'attendance_records',
        'attendance_team_lead_otps', 'attendance_group_members', 'attendance_groups', 'payroll_periods',
        'salary_computations', 'salary_adjustments', 'employee_deductions',
        'benefits_contribution_rates', 'holidays', 'cash_advances',
        'office_locations',
    ];
    // Tables that are inherently per-tenant (no tenant_id column) → full delete.
    $unscopedTables = ['cash_advance_repayments'];

    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($groups as $key) {
            $def = awResetGroups()[$key] ?? null;
            if (!$def) {
                continue;
            }
            foreach ($def['tables'] as $table) {
                if (in_array($table, $scopedTables, true)) {
                    $stmt = $db->prepare("DELETE FROM `{$table}` WHERE tenant_id = :tid");
                    $stmt->execute([':tid' => $tenantId]);
                } elseif (in_array($table, $unscopedTables, true)) {
                    $db->exec("DELETE FROM `{$table}`");
                }
            }
        }
        if ($full && !$keepUsers) {
            $stmt = $db->prepare('DELETE FROM attendance_wage_users WHERE id <> :uid');
            $stmt->execute([':uid' => $uid]);
            $db->prepare('DELETE FROM attendance_wage_password_resets WHERE user_id <> :uid')
                ->execute([':uid' => $uid]);
        }
    } finally {
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

/**
 * POST /api/v1/wage/settings/data-reset
 *
 * Full or granular data reset for the active tenant (admin only).
 * Mirrors PAL's palApiSettingsDataReset(): mode=full wipes all groups + all
 * users except the logged-in admin; mode=granular wipes the selected groups.
 */
function wageApiSettingsDataReset(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $uid = aw_currentUserId();

    $mode = (string)($_POST['mode'] ?? '');
    $keepUsers = (int)($_POST['keep_users'] ?? 0) === 1;
    $requested = array_values(array_filter(array_map('strval', (array)($_POST['groups'] ?? []))));

    $catalog = awResetGroups();
    if ($mode === 'full') {
        $resetGroups = array_keys($catalog);
    } elseif ($mode === 'granular') {
        $resetGroups = array_values(array_intersect($requested, array_keys($catalog)));
        if ($resetGroups === []) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Select at least one data group to reset.']);
            exit;
        }
    } else {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid reset mode.']);
        exit;
    }

    $tenantId = (string)aw_tenant_id();
    try {
        awResetTenantData(aw_db(), $tenantId, $uid, $resetGroups, $mode === 'full', $keepUsers);
    } catch (\Throwable $e) {
        write_log('attendance_wage_data_reset_error', 'error', ['error' => $e->getMessage()]);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Failed to reset data.']);
        exit;
    }

    write_log('attendance_wage.data.reset', 'info', [
        'tenant_id' => $tenantId,
        'mode'      => $mode,
        'groups'    => $resetGroups,
        'by_user'   => $uid,
    ]);

    // Invalidate cached settings page so the next visitor gets fresh content.
    if (class_exists(\Ikabud\Kernel\DiSyL\Cache\FragmentStore::class)) {
        try {
            $store = new \Ikabud\Kernel\DiSyL\Cache\FragmentStore();
            $store->invalidate(['attendance_settings'], app()->tenant()->current() ?? '_global');
        } catch (\Throwable $e) {
            // Non-fatal: cache invalidation is best-effort
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'      => true,
        'message' => $mode === 'full'
            ? 'All tenant data has been reset.'
            : 'Selected data groups have been reset.',
    ]);
    exit;
}
