<?php

declare(strict_types=1);

/**
 * Routes for the Attendance & Wage module.
 *
 * Handler references use the "module-id:functionName" convention.
 */

return [
    // ── Attendance Pages ──
    'GET /admin/attendance'              => 'attendance-wage:attendancePageClock',
    'GET /admin/attendance/history'      => 'attendance-wage:attendancePageHistory',
    'GET /admin/attendance/report'       => 'attendance-wage:attendancePageReport',

    // ── Wage Pages ──
    'GET /admin/wage'                    => 'attendance-wage:wagePageDashboard',
    'GET /admin/wage/employees'          => 'attendance-wage:wagePageEmployees',
    'GET /admin/wage/employees/create'   => 'attendance-wage:wagePageEmployeeForm',
    'GET /admin/wage/employees/{id}'     => 'attendance-wage:wagePageEmployeeForm',
    'GET /admin/wage/periods'            => 'attendance-wage:wagePagePeriods',
    'GET /admin/wage/periods/create'     => 'attendance-wage:wagePagePeriodForm',
    'GET /admin/wage/computations'       => 'attendance-wage:wagePageComputations',
    'GET /admin/wage/adjustments'        => 'attendance-wage:wagePageAdjustments',
    'GET /admin/wage/adjustments/create' => 'attendance-wage:wagePageAdjustmentForm',
    'GET /admin/wage/deductions'         => 'attendance-wage:wagePageDeductions',
    'GET /admin/wage/deductions/create'  => 'attendance-wage:wagePageDeductionForm',
    'GET /admin/wage/cash-advances'      => 'attendance-wage:wagePageCashAdvances',
    'GET /admin/wage/holidays'           => 'attendance-wage:wagePageHolidays',
    'GET /admin/wage/schedules'          => 'attendance-wage:wagePageSchedules',
    'GET /admin/wage/reports'            => 'attendance-wage:wagePageReports',
    'GET /admin/wage/reports/{periodId}' => 'attendance-wage:wagePageReportDetail',
    'GET /admin/wage/benefits-calculator'=> 'attendance-wage:wagePageBenefitsCalc',
    'GET /admin/wage/migration'          => 'attendance-wage:wagePageMigrationWizard',

    // ── Attendance API (Read) ──
    'GET /api/v1/attendance/photo/{file}' => 'attendance-wage:attendanceApiPhoto',
    'GET /api/v1/attendance/records'      => 'attendance-wage:attendanceApiRecords',

    // ── Wage API (Read) ──
    'GET /api/v1/wage/employees'          => 'attendance-wage:wageApiEmployeesList',
    'GET /api/v1/wage/employees/{id}'     => 'attendance-wage:wageApiEmployeeGet',
    'GET /api/v1/wage/periods'            => 'attendance-wage:wageApiPeriodsList',
    'GET /api/v1/wage/periods/{id}'       => 'attendance-wage:wageApiPeriodGet',
    'GET /api/v1/wage/computations'       => 'attendance-wage:wageApiComputationsList',
    'GET /api/v1/wage/computations/{id}'  => 'attendance-wage:wageApiComputationGet',
    'GET /api/v1/wage/adjustments'        => 'attendance-wage:wageApiAdjustmentsList',
    'GET /api/v1/wage/deductions'         => 'attendance-wage:wageApiDeductionsList',
    'GET /api/v1/wage/cash-advances'      => 'attendance-wage:wageApiCashAdvancesList',
    'GET /api/v1/wage/holidays'           => 'attendance-wage:wageApiHolidaysList',
    'GET /api/v1/wage/payslip/{computationId}' => 'attendance-wage:wageApiPayslip',

    // ── Attendance API (Write) ──
    'POST /api/v1/attendance/clock-in'        => 'attendance-wage:attendanceApiClockIn',
    'POST /api/v1/attendance/clock-out'       => 'attendance-wage:attendanceApiClockOut',

    // ── Wage API (Write) ──
    'POST /api/v1/wage/employees'             => 'attendance-wage:wageApiEmployeeCreate',
    'POST /api/v1/wage/employees/{id}'        => 'attendance-wage:wageApiEmployeeUpdate',
    'POST /api/v1/wage/periods'               => 'attendance-wage:wageApiPeriodCreate',
    'POST /api/v1/wage/periods/{id}'          => 'attendance-wage:wageApiPeriodUpdate',
    'POST /api/v1/wage/compute'               => 'attendance-wage:wageApiComputeEmployee',
    'POST /api/v1/wage/compute/bulk'          => 'attendance-wage:wageApiBulkCompute',
    'POST /api/v1/wage/computations/{id}/approve' => 'attendance-wage:wageApiApproveComputation',
    'POST /api/v1/wage/computations/{id}/pay'      => 'attendance-wage:wageApiPayComputation',
    'POST /api/v1/wage/adjustments'            => 'attendance-wage:wageApiAdjustmentCreate',
    'POST /api/v1/wage/adjustments/{id}/approve' => 'attendance-wage:wageApiAdjustmentApprove',
    'POST /api/v1/wage/deductions'             => 'attendance-wage:wageApiDeductionCreate',
    'POST /api/v1/wage/deductions/{id}/status' => 'attendance-wage:wageApiDeductionStatus',
    'POST /api/v1/wage/cash-advances'          => 'attendance-wage:wageApiCashAdvanceCreate',
    'POST /api/v1/wage/cash-advances/{id}/approve' => 'attendance-wage:wageApiCashAdvanceApprove',
    'POST /api/v1/wage/holidays'               => 'attendance-wage:wageApiHolidayCreate',
    'POST /api/v1/wage/benefits/calculate'     => 'attendance-wage:wageApiBenefitsCalculate',
    'POST /api/v1/wage/migration/bulk'         => 'attendance-wage:wageApiMigrationBulk',
];

