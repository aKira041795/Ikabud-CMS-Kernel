<?php

declare(strict_types=1);

/**
 * Handlers for the Attendance & Wage module.
 *
 * Each handler receives a ModuleContext and returns a response.
 *
 * Handler files are split by domain under handlers/:
 *   00-bootstrap.php      — Auth guards, capability checks
 *   10-pages-attendance.php — Attendance clock, history, report pages
 *   20-api-attendance.php   — Clock-in/out, records, photo
 *   30-pages-wage.php       — Dashboard, employees, periods, computations pages
 *   40-api-employees.php    — Employee profile CRUD
 *   50-api-periods.php      — Payroll period CRUD
 *   60-api-computations.php — Compute, bulk, approve, pay
 *   70-api-adjustments.php  — Salary adjustments CRUD
 *   80-api-deductions.php   — Employee deductions CRUD
 *   90-api-cash-advances.php — Cash advance requests, repayments
 *   100-api-holidays.php    — Holiday calendar CRUD
 *   110-api-schedules.php  — Employee schedule CRUD
 *   110-api-reports.php     — Payroll reports, payslips
 */

use Ikabud\Kernel\Contracts\ModuleContext;

// ── Bootstrap helpers ──
require_once __DIR__ . '/handlers/00-bootstrap.php';

// ── Entity view contracts (rich action support for {ikb_entity_list}) ──
require_once __DIR__ . '/helpers/entity-views.php';

// ── Inject branding settings into render context for all attendance-wage templates ──
app()->hooks()->on('kernel.render_context', function (array $context, string $template) {
    // Only inject for attendance-wage templates (skip non-attendance-wage pages)
    if (!str_starts_with($template, 'modules/attendance-wage/')) {
        return $context;
    }
    try {
        $settings = getModuleSettings('attendance-wage');
        // Override app_name from module settings; default to "ZAP" if not set
        $context['app_name'] = !empty($settings['app_name']) ? $settings['app_name'] : 'ZAP';
        if (!empty($settings['logo_url'])) {
            $context['logo_url'] = $settings['logo_url'];
        }
        // Timezone for attendance clock-in/out display
        $context['timezone'] = $settings['timezone'] ?? 'Asia/Manila';
        if (!empty($settings['google_maps_api_key'])) {
            $context['google_maps_api_key'] = $settings['google_maps_api_key'];
        }
        // Also provide as aw_app_name for templates that need explicit control
        $context['aw_app_name'] = $context['app_name'];
    } catch (\Throwable $e) {
        $context['app_name'] = 'ZAP';
    }
    return $context;
});

// ── Auth handlers ──
require_once __DIR__ . '/handlers/05-auth.php';

// ── Page handlers ──
require_once __DIR__ . '/handlers/10-pages-attendance.php';
require_once __DIR__ . '/handlers/30-pages-wage.php';

// ── API handlers ──
require_once __DIR__ . '/handlers/130-api-kiosk.php';
require_once __DIR__ . '/handlers/20-api-attendance.php';
require_once __DIR__ . '/handlers/40-api-employees.php';
require_once __DIR__ . '/handlers/50-api-periods.php';
require_once __DIR__ . '/handlers/60-api-computations.php';
require_once __DIR__ . '/handlers/70-api-adjustments.php';
require_once __DIR__ . '/handlers/80-api-deductions.php';
require_once __DIR__ . '/handlers/90-api-cash-advances.php';
require_once __DIR__ . '/handlers/100-api-holidays.php';
require_once __DIR__ . '/handlers/110-api-schedules.php';
require_once __DIR__ . '/handlers/110-api-reports.php';
require_once __DIR__ . '/handlers/120-api-locations.php';

// ── Settings (page + API) ──
require_once __DIR__ . '/handlers/95-api-settings.php';

