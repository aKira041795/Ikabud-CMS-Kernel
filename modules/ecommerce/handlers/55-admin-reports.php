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
    $storeId = max(0, (int)($input['store_id'] ?? 0));
    $params = [
        'period'     => $input['period']     ?? 'month',
        'start_date' => $input['start_date'] ?? '',
        'end_date'   => $input['end_date']   ?? '',
        'store_id'   => $storeId ?: null,
    ];

    $sales     = ecReportSales($params);
    $inventory = ecReportInventory();

    // Phase 5 multi-store: pass active stores for filter dropdown.
    $stores = (function_exists('ecIsMultiStoreEnabled') ? ecIsMultiStoreEnabled() : ecStoreIsMultiStoreActive()) ? (ecStoreList(['active_only' => false])['items'] ?? []) : [];

    $ctx = ecAdminContext($user, 'reports', [
        'sales'      => $sales,
        'inventory'  => $inventory,
        'params'     => $params,
        'stores'     => $stores,
        'store_id'   => $storeId,
        'multi_store' => count($stores) > 1,
    ]);

    ecRender('modules/ecommerce/admin/reports.disyl', $ctx);
}
