<?php

declare(strict_types=1);

/**
 * Moto Inventory — Route map.
 *
 * Literal routes are listed before parameterized routes. Pages are
 * server-rendered via DiSyL; API endpoints return consistent JSON envelopes
 * ({ok:true,data:...} / {ok:false,error:...}). All business logic lives in
 * services; handlers only validate request shape and call services.
 */

return [
    'GET' => [
        // Pages
        '/moto-inventory'                => 'moto-inventory:motoPageDashboard',
        '/moto-inventory/login'          => 'moto-inventory:motoPageLogin',
        '/moto-inventory/dashboard'      => 'moto-inventory:motoPageDashboard',
        '/moto-inventory/inventory'      => 'moto-inventory:motoPageInventory',
        '/moto-inventory/sales'          => 'moto-inventory:motoPageSales',
        '/moto-inventory/history'        => 'moto-inventory:motoPageHistory',
        '/moto-inventory/reports'        => 'moto-inventory:motoPageReports',
        '/moto-inventory/audit'          => 'moto-inventory:motoPageAudit',
        '/moto-inventory/import'         => 'moto-inventory:motoPageImport',
        '/moto-inventory/branches'       => 'moto-inventory:motoPageBranches',

        // API: session + branch scope
        '/api/v1/moto-inventory/me'                  => 'moto-inventory:motoApiMe',
        '/api/v1/moto-inventory/branches'            => 'moto-inventory:motoApiBranches',

        // API: catalog
        '/api/v1/moto-inventory/brands'              => 'moto-inventory:motoApiBrands',
        '/api/v1/moto-inventory/products'            => 'moto-inventory:motoApiProducts',

        // API: stock
        '/api/v1/moto-inventory/stock/balances'      => 'moto-inventory:motoApiStockBalances',
        '/api/v1/moto-inventory/stock/movements'     => 'moto-inventory:motoApiStockMovements',

        // API: sales + reports
        '/api/v1/moto-inventory/sales'               => 'moto-inventory:motoApiSales',
        '/api/v1/moto-inventory/reports/profit'      => 'moto-inventory:motoApiProfit',
        '/api/v1/moto-inventory/audit'               => 'moto-inventory:motoApiAudit',
        '/api/v1/moto-inventory/imports'             => 'moto-inventory:motoApiImports',
        '/api/v1/moto-inventory/backups'             => 'moto-inventory:motoApiBackups',

        // API: download endpoints
        '/api/v1/moto-inventory/export'              => 'moto-inventory:motoApiExport',
        '/api/v1/moto-inventory/imports/{id}/errors' => 'moto-inventory:motoApiImportErrors',

        // API: parameterized reads (after literal routes)
        '/api/v1/moto-inventory/products/{id}'       => 'moto-inventory:motoApiProductGet',
        '/api/v1/moto-inventory/sales/{id}'          => 'moto-inventory:motoApiSaleGet',
    ],
    'POST' => [
        // API: catalog mutations (literal first)
        '/api/v1/moto-inventory/brands'              => 'moto-inventory:motoApiBrandCreate',
        '/api/v1/moto-inventory/products'            => 'moto-inventory:motoApiProductCreate',

        // API: stock
        '/api/v1/moto-inventory/stock/adjust'        => 'moto-inventory:motoApiStockAdjust',

        // API: sales
        '/api/v1/moto-inventory/sales/complete'      => 'moto-inventory:motoApiSaleComplete',
        '/api/v1/moto-inventory/sales/undo'          => 'moto-inventory:motoApiSaleUndo',

        // API: imports + backup
        '/api/v1/moto-inventory/imports/stage'       => 'moto-inventory:motoApiImportStage',
        '/api/v1/moto-inventory/import'              => 'moto-inventory:motoApiBackupImport',

        // API: branches
        '/api/v1/moto-inventory/branches'            => 'moto-inventory:motoApiBranchCreate',

        // Settings (form POST, CSRF-protected non-API route)
        '/moto-inventory/settings'                   => 'moto-inventory:motoPageSettingsSave',

        // API: parameterized mutations (after literal routes)
        '/api/v1/moto-inventory/brands/{id}'         => 'moto-inventory:motoApiBrandUpdate',
        '/api/v1/moto-inventory/products/{id}'       => 'moto-inventory:motoApiProductUpdate',
        '/api/v1/moto-inventory/products/{id}/archive'   => 'moto-inventory:motoApiProductArchive',
        '/api/v1/moto-inventory/products/{id}/restore'   => 'moto-inventory:motoApiProductRestore',
        '/api/v1/moto-inventory/products/{id}/delete'    => 'moto-inventory:motoApiProductDelete',
        '/api/v1/moto-inventory/sales/{id}/void'     => 'moto-inventory:motoApiSaleVoid',
        '/api/v1/moto-inventory/imports/{id}/commit' => 'moto-inventory:motoApiImportCommit',
        '/api/v1/moto-inventory/branches/{id}/assign' => 'moto-inventory:motoApiBranchAssign',
    ],
];
