<?php

declare(strict_types=1);

/**
 * Wage page handlers — dashboard, employees, periods, computations.
 */



function wagePageDashboard(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/dashboard');
}

function wagePageEmployees(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/employees/index');
}

function wagePageEmployeeForm(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/employees/form');
}

function wagePagePeriods(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/periods/index');
}

function wagePagePeriodForm(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/periods/form');
}

function wagePageComputations(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/computations/index');
}

function wagePageAdjustments(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/adjustments/index');
}

function wagePageAdjustmentForm(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/adjustments/form');
}

function wagePageDeductions(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/deductions/index');
}

function wagePageDeductionForm(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/deductions/form');
}

function wagePageCashAdvances(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/cash-advances/index');
}

function wagePageHolidays(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/holidays/index');
}

function wagePageSchedules(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/schedules/index');
}

function wagePageReports(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/reports/index');
}

function wagePageReportDetail(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/reports/detail');
}

function wagePageBenefitsCalc(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/benefits-calculator');
}

function wagePageMigrationWizard(array $params = []): void
{
    
    echo app()->render('modules/attendance-wage/wage/migration-wizard');
}
