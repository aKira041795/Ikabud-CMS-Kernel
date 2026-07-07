<?php

declare(strict_types=1);

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
                $sets[] = "`{$field}` = :{$field}";
                $params[":{$field}"] = $val;
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
