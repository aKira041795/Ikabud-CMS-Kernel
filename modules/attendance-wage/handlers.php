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
 *   110-api-reports.php     — Payroll reports, payslips
 */

use Ikabud\Kernel\Contracts\ModuleContext;

// ── Bootstrap helpers ──
require_once __DIR__ . '/handlers/00-bootstrap.php';

// ── Page handlers ──
require_once __DIR__ . '/handlers/10-pages-attendance.php';
require_once __DIR__ . '/handlers/30-pages-wage.php';

// ── API handlers ──
require_once __DIR__ . '/handlers/20-api-attendance.php';
require_once __DIR__ . '/handlers/40-api-employees.php';
require_once __DIR__ . '/handlers/50-api-periods.php';
require_once __DIR__ . '/handlers/60-api-computations.php';
require_once __DIR__ . '/handlers/70-api-adjustments.php';
require_once __DIR__ . '/handlers/80-api-deductions.php';
require_once __DIR__ . '/handlers/90-api-cash-advances.php';
require_once __DIR__ . '/handlers/100-api-holidays.php';
require_once __DIR__ . '/handlers/110-api-reports.php';

