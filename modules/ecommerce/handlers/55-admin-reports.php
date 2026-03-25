<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Reports (handlers/55-admin-reports.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /admin/ecommerce/reports
 */
function ecAdminReports(): void
{
    $user   = ecRequireAdmin();
    $input  = ecInput();
    $params = [
        'period'     => $input['period']     ?? 'month',
        'start_date' => $input['start_date'] ?? '',
        'end_date'   => $input['end_date']   ?? '',
    ];

    $sales     = ecReportSales($params);
    $inventory = ecReportInventory();

    $ctx = ecAdminContext($user, 'reports', [
        'sales'     => $sales,
        'inventory' => $inventory,
        'params'    => $params,
    ]);

    ecRender('modules/ecommerce/admin/reports.disyl', $ctx);
}
