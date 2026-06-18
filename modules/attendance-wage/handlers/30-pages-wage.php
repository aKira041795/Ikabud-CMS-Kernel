<?php

declare(strict_types=1);

/**
 * Wage page handlers — dashboard, employees, periods, computations.
 */

use Ikabud\Kernel\Contracts\ModuleContext;

function wagePageDashboard(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.read@1');
    return $ctx->render('modules/attendance-wage/wage/dashboard');
}

function wagePageEmployees(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.manage@1');
    return $ctx->render('modules/attendance-wage/wage/employees/index');
}

function wagePageEmployeeForm(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.manage@1');
    return $ctx->render('modules/attendance-wage/wage/employees/form');
}

function wagePagePeriods(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.admin@1');
    return $ctx->render('modules/attendance-wage/wage/periods/index');
}

function wagePagePeriodForm(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.admin@1');
    return $ctx->render('modules/attendance-wage/wage/periods/form');
}

function wagePageComputations(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.admin@1');
    return $ctx->render('modules/attendance-wage/wage/computations/index');
}

function wagePageAdjustments(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.manage@1');
    return $ctx->render('modules/attendance-wage/wage/adjustments/index');
}

function wagePageAdjustmentForm(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.manage@1');
    return $ctx->render('modules/attendance-wage/wage/adjustments/form');
}

function wagePageDeductions(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.manage@1');
    return $ctx->render('modules/attendance-wage/wage/deductions/index');
}

function wagePageDeductionForm(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.manage@1');
    return $ctx->render('modules/attendance-wage/wage/deductions/form');
}

function wagePageCashAdvances(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.read@1');
    return $ctx->render('modules/attendance-wage/wage/cash-advances/index');
}

function wagePageHolidays(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.admin@1');
    return $ctx->render('modules/attendance-wage/wage/holidays/index');
}

function wagePageSchedules(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.manage@1');
    return $ctx->render('modules/attendance-wage/wage/schedules/index');
}

function wagePageReports(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.manage@1');
    return $ctx->render('modules/attendance-wage/wage/reports/index');
}

function wagePageReportDetail(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.manage@1');
    return $ctx->render('modules/attendance-wage/wage/reports/detail');
}

function wagePageBenefitsCalc(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.read@1');
    return $ctx->render('modules/attendance-wage/wage/benefits-calculator');
}

function wagePageMigrationWizard(ModuleContext $ctx): string
{
    attendanceWageGuard('attendance_wage.admin@1');
    return $ctx->render('modules/attendance-wage/wage/migration-wizard');
}
