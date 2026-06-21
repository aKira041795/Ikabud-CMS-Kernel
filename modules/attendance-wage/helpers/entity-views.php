<?php

declare(strict_types=1);

/**
 * Entity view contracts for the Attendance & Wage module.
 *
 * Registers rich view contracts with the EntityViewResolver so that
 * {ikb_entity_list} tags in Disyl templates can render:
 *  - Inline POST actions with CSRF protection
 *  - Role-gated action visibility
 *  - Conditional action visibility (status-dependent buttons)
 *  - Confirmation dialogs
 *  - Custom cell renderers (money, badge, datetime)
 *
 * This file is loaded by handlers.php during module bootstrap.
 */

if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
    $views = $app->entityViews();

    // ═══════════════════════════════════════════════════════════════
    // Salary Computation — table view with action buttons
    // ═══════════════════════════════════════════════════════════════
    $views->registerView('salary_computation', 'table', [
        'fields' => [
            'employee_name', 'salary_type', 'gross_pay', 'total_additions',
            'total_deductions', 'net_pay', 'status',
        ],
        'actions' => ['view', 'recompute', 'approve', 'pay'],
        'action_urls' => [
            'view'      => '/admin/wage/computations?period_id={payroll_period_id}',
            'recompute' => '/api/v1/wage/compute',
            'approve'   => '/api/v1/wage/computations/{id}/approve',
            'pay'       => '/api/v1/wage/computations/{id}/pay',
        ],
        'action_methods' => [
            'recompute' => 'post',
            'approve'   => 'post',
            'pay'       => 'post',
        ],
        'action_labels' => [
            'view'      => 'View',
            'recompute' => '🔄 Recompute',
            'approve'   => '✅ Approve',
            'pay'       => '💰 Mark Paid',
        ],
        'action_confirm' => [
            'recompute' => 'Recompute salary for this employee? Hours will be recalculated from attendance records.',
            'approve'   => 'Approve this computation?',
            'pay'       => 'Mark this computation as paid?',
        ],
        'action_show_if' => [
            'recompute' => 'user_id != ""',
            'approve'   => 'status == "computed"',
            'pay'       => 'status == "approved"',
        ],
        'action_roles' => [
            'approve' => ['admin'],
            'pay'     => ['admin'],
        ],
        'renderers' => [
            'gross_pay'        => 'money:2',
            'total_additions'  => 'money:2',
            'total_deductions' => 'money:2',
            'net_pay'          => 'money:2',
            'status'           => 'badge:{"computed":"Computed|amber","approved":"Approved|green","paid":"Paid|blue","cancelled":"Cancelled|red"}',
            'salary_type'      => 'badge',
        ],
        'limit' => 50,
        'sort' => ['field' => 'created_at', 'direction' => 'desc'],
        'empty_state' => 'No computations for this period.',
        'exportable' => true,
        'capability' => 'entity.list.salary_computation@1',
    ]);

    // ═══════════════════════════════════════════════════════════════
    // Payroll Period — table view for reports listing
    // ═══════════════════════════════════════════════════════════════
    $views->registerView('payroll_period', 'table', [
        'fields' => [
            'period_name', 'period_type', 'start_date', 'end_date',
            'total_employees', 'total_gross', 'total_net', 'status',
        ],
        'actions' => ['view', 'compute', 'export', 'edit', 'report', 'delete'],
        'action_urls' => [
            'view'    => '/admin/wage/computations?period_id={id}',
            'compute' => '/api/v1/wage/compute/bulk',
            'export'  => '/api/v1/wage/reports/{id}/export?format=csv',
            'edit'    => '/admin/wage/periods/{id}',
            'report'  => '/admin/wage/reports/{id}',
            'delete'  => '/api/v1/wage/periods/{id}/delete',
        ],
        'action_methods' => [
            'compute' => 'post',
            'delete'  => 'post',
        ],
        'action_labels' => [
            'view'    => 'View',
            'compute' => '🧮 Compute',
            'export'  => '📥 CSV',
            'edit'    => 'Edit',
            'report'  => 'Report',
            'delete'  => 'Delete',
        ],
        'action_confirm' => [
            'compute' => 'Compute salaries for all employees in this period?',
            'delete'  => 'Delete this period and all its computations?',
        ],
        'action_show_if' => [
            'compute' => 'status != "completed"',
            'report'  => 'status != "completed" && status != "cancelled"',
            'delete'  => 'status == "draft" || status == "processing"',
        ],
        'action_roles' => [
            'delete' => ['admin'],
        ],
        'renderers' => [
            'total_gross' => 'money:2',
            'total_net'   => 'money:2',
            'status'      => 'badge:{"draft":"Draft|amber","processing":"Processing|blue","completed":"Completed|green","cancelled":"Cancelled|red"}',
            'period_type' => 'badge',
        ],
        'limit' => 15,
        'sort' => ['field' => 'start_date', 'direction' => 'desc'],
        'empty_state' => 'No payroll periods yet.',
        'exportable' => true,
        'capability' => 'entity.list.payroll_period@1',
    ]);

    // ═══════════════════════════════════════════════════════════════
    // Employee Profile — table view with row-click navigation
    // ═══════════════════════════════════════════════════════════════
    $views->registerView('employee_profile', 'table', [
        'fields' => [
            'first_name', 'last_name', 'position', 'department', 'salary_type',
            'employment_status', 'hire_date',
        ],
        'actions' => ['view', 'edit'],
        'action_urls' => [
            'view' => '/admin/wage/employees/{id}',
            'edit' => '/admin/wage/employees/{id}',
        ],
        'renderers' => [
            'salary_type'          => 'badge',
            'employment_status'    => 'badge:{"regular":"Regular|green","probationary":"Probationary|amber","contractual":"Contractual|blue","part_time":"Part-Time|gray"}',
            'hire_date'            => 'datetime:date',
            'overtime_allowed'     => 'badge:{"1":"Yes|green","0":"No|gray"}',
            'holiday_pay_enabled'  => 'badge:{"1":"Yes|green","0":"No|gray"}',
            'night_diff_enabled'   => 'badge:{"1":"Yes|green","0":"No|gray"}',
        ],
        'limit' => 25,
        'sort' => ['field' => 'last_name', 'direction' => 'asc'],
        'empty_state' => 'No employee profiles yet.',
        'exportable' => true,
        'capability' => 'entity.list.employee_profile@1',
    ]);

    // ═══════════════════════════════════════════════════════════════
    // Employee Profile — detail view (single employee)
    // ═══════════════════════════════════════════════════════════════
    $views->registerView('employee_profile', 'detailed', [
        'fields' => [
            'employee_number', 'position', 'department', 'hire_date',
            'salary_type', 'basic_salary', 'employment_status',
            'overtime_allowed', 'overtime_rate', 'max_daily_hours', 'max_weekly_hours',
            'sss_number', 'philhealth_number', 'pagibig_number', 'tin_number',
            'tax_exemption_status', 'dependents_count',
        ],
        'renderers' => [
            'salary_type'          => 'badge',
            'employment_status'    => 'badge',
            'basic_salary'         => 'money:2',
            'overtime_allowed'     => 'badge:{"1":"Yes|green","0":"No|gray"}',
        ],
        'capability' => 'entity.get.employee_profile@1',
    ]);

    // ═══════════════════════════════════════════════════════════════
    // Attendance Record — table view for history/report
    // ═══════════════════════════════════════════════════════════════
    $views->registerView('attendance_record', 'table', [
        'fields' => [
            'employee_name', 'clock_in', 'clock_out', 'location_in',
            'location_out', 'status',
        ],
        'actions' => ['view'],
        'action_urls' => [
            'view' => '/admin/attendance?employee_id={user_id}',
        ],
        'renderers' => [
            'clock_in'    => 'datetime:full',
            'clock_out'   => 'datetime:full',
            'location_in' => 'badge',
            'location_out' => 'badge',
            'status'      => 'badge:{"active":"Active|green","completed":"Completed|gray"}',
        ],
        'limit' => 30,
        'sort' => ['field' => 'clock_in', 'direction' => 'desc'],
        'empty_state' => 'No attendance records found.',
        'exportable' => true,
        'capability' => 'entity.list.attendance_record@1',
    ]);

    // Attendance Record — compact view for kiosk (employee-facing)
    $views->registerView('attendance_record', 'compact', [
        'fields' => [
            'employee_name', 'clock_in', 'clock_out', 'location_in', 'status',
        ],
        'renderers' => [
            'clock_in'    => 'datetime:time',
            'clock_out'   => 'datetime:time',
            'location_in' => 'badge',
            'status'      => 'badge:{"active":"Active|green","completed":"Completed|gray"}',
        ],
        'limit' => 10,
        'sort' => ['field' => 'clock_in', 'direction' => 'desc'],
        'empty_state' => 'No recent records.',
        'capability' => 'entity.list.attendance_record@1',
    ]);

    // ═══════════════════════════════════════════════════════════════
    // Cash Advance — table view
    // ═══════════════════════════════════════════════════════════════
    $views->registerView('cash_advance', 'table', [
        'fields' => [
            'employee_name', 'amount', 'balance', 'repayment_type',
            'request_date', 'status',
        ],
        'actions' => ['view', 'approve'],
        'action_urls' => [
            'view'    => '/admin/wage/cash-advances?id={id}',
            'approve' => '/api/v1/wage/cash-advances/{id}/approve',
        ],
        'action_methods' => [
            'approve' => 'post',
        ],
        'action_labels' => [
            'view'    => 'View',
            'approve' => '✅ Approve',
        ],
        'action_confirm' => [
            'approve' => 'Approve this cash advance? This will schedule repayments.',
        ],
        'action_show_if' => [
            'approve' => 'status == "pending"',
        ],
        'action_roles' => [
            'approve' => ['admin', 'supervisor'],
        ],
        'renderers' => [
            'amount'          => 'money:2',
            'balance'         => 'money:2',
            'status'          => 'badge:{"pending":"Pending|amber","approved":"Approved|green","active":"Active|blue","rejected":"Rejected|red","paid":"Paid|gray"}',
            'repayment_type'  => 'badge',
            'request_date'    => 'datetime:date',
        ],
        'limit' => 20,
        'sort' => ['field' => 'request_date', 'direction' => 'desc'],
        'empty_state' => 'No cash advances yet.',
        'exportable' => true,
        'capability' => 'entity.list.cash_advance@1',
    ]);

    // ═══════════════════════════════════════════════════════════════
    // Holiday — table view with search
    // ═══════════════════════════════════════════════════════════════
    $views->registerView('holiday', 'table', [
        'fields' => ['holiday_name', 'holiday_date', 'holiday_type', 'is_regular'],
        'actions' => ['view', 'edit', 'delete'],
        'action_urls' => [
            'view'   => '/admin/wage/holidays?edit={id}',
            'edit'   => '/admin/wage/holidays?edit={id}',
            'delete' => '/api/v1/wage/holidays/{id}/delete',
        ],
        'action_methods' => [
            'delete' => 'post',
        ],
        'action_confirm' => [
            'delete' => 'Delete this holiday? This cannot be undone.',
        ],
        'renderers' => [
            'holiday_date' => 'datetime:date',
            'holiday_type' => 'badge',
            'is_regular'   => 'boolean',
        ],
        'limit' => 30,
        'sort' => ['field' => 'holiday_date', 'direction' => 'asc'],
        'empty_state' => 'No holidays configured.',
        'exportable' => true,
        'capability' => 'entity.list.holiday@1',
    ]);

    // ═══════════════════════════════════════════════════════════════
    // Office Location — table view
    // ═══════════════════════════════════════════════════════════════
    $views->registerView('office_location', 'table', [
        'fields' => ['name', 'address', 'latitude', 'longitude', 'radius_meters', 'is_active'],
        'actions' => ['view', 'edit', 'delete'],
        'action_urls' => [
            'view'   => '/admin/wage/locations?id={id}',
            'edit'   => '/admin/wage/locations/{id}',
            'delete' => '/api/v1/wage/locations/{id}/delete',
        ],
        'action_methods' => [
            'delete' => 'post',
        ],
        'action_confirm' => [
            'delete' => 'Delete this office location?',
        ],
        'renderers' => [
            'is_active' => 'boolean',
        ],
        'limit' => 50,
        'sort' => ['field' => 'name', 'direction' => 'asc'],
        'empty_state' => 'No office locations configured yet.',
        'exportable' => true,
        'capability' => 'entity.list.office_location@1',
    ]);

    // ═══════════════════════════════════════════════════════════════
    // Employee Schedule — table view
    // ═══════════════════════════════════════════════════════════════
    $views->registerView('employee_schedule', 'table', [
        'fields' => [
            'employee_name', 'position', 'days_label', 'shift_type',
            'min_start', 'max_end', 'dayoff_count',
        ],
        'actions' => ['view', 'edit'],
        'action_urls' => [
            'view' => '/admin/wage/schedules?profile_id={profile_id}',
            'edit' => '/admin/wage/schedules/employee/{profile_id}',
        ],
        'renderers' => [
            'shift_type' => 'badge',
        ],
        'limit' => 50,
        'sort' => ['field' => 'employee_name', 'direction' => 'asc'],
        'empty_state' => 'No schedules configured.',
        'capability' => 'entity.list.employee_schedule@1',
    ]);

    // ═══════════════════════════════════════════════════════════════
    // Employee Deduction — table view
    // ═══════════════════════════════════════════════════════════════
    $views->registerView('employee_deduction', 'table', [
        'fields' => [
            'employee_name', 'amount', 'description', 'status',
            'deduction_date', 'source',
        ],
        'actions' => [],
        'renderers' => [
            'amount'         => 'money:2',
            'status'         => 'badge:{"pending":"Pending|amber","completed":"Completed|green","cancelled":"Cancelled|red","deducted":"Deducted|blue"}',
            'deduction_date' => 'datetime:date',
            'source'         => 'badge:{"manual":"Manual|gray","cash_advance":"Cash Advance|blue"}',
        ],
        'limit' => 20,
        'sort' => ['field' => 'deduction_date', 'direction' => 'desc'],
        'empty_state' => 'No employee deductions yet.',
        'capability' => 'entity.list.employee_deduction@1',
    ]);

    // ═══════════════════════════════════════════════════════════════
    // Salary Adjustment — table view
    // ═══════════════════════════════════════════════════════════════
    $views->registerView('salary_adjustment', 'table', [
        'fields' => [
            'employee_name', 'adjustment_type', 'amount', 'description',
            'status', 'effective_date',
        ],
        'actions' => ['approve'],
        'action_urls' => [
            'approve' => '/api/v1/wage/adjustments/{id}/approve',
        ],
        'action_methods' => [
            'approve' => 'post',
        ],
        'action_labels' => [
            'approve' => '✅ Approve',
        ],
        'action_confirm' => [
            'approve' => 'Approve this adjustment?',
        ],
        'action_show_if' => [
            'approve' => 'status == "pending"',
        ],
        'action_roles' => [
            'approve' => ['admin'],
        ],
        'renderers' => [
            'amount'          => 'money:2',
            'adjustment_type' => 'badge',
            'status'          => 'badge:{"pending":"Pending|amber","approved":"Approved|green","applied":"Applied|blue","rejected":"Rejected|red"}',
            'effective_date'  => 'datetime:date',
        ],
        'limit' => 20,
        'sort' => ['field' => 'effective_date', 'direction' => 'desc'],
        'empty_state' => 'No salary adjustments yet.',
        'capability' => 'entity.list.salary_adjustment@1',
    ]);
}
