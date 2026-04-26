<?php

declare(strict_types=1);

return [
    'GET' => [
        // Module-owned login (namespaced)
        '/daily-ledger/login'                     => 'daily-ledger:pageDailyLedgerLogin',
        '/daily-ledger/logout'                    => 'daily-ledger:dailyLedgerLogout',

        // Namespaced pages
        '/daily-ledger/ledger'                     => 'daily-ledger:handleCashierLedger',
        '/daily-ledger/ledger/rows'                => 'daily-ledger:handleCashierRows',
        '/daily-ledger/admin/dashboard'            => 'daily-ledger:handleAdminDashboard',
        '/daily-ledger/admin/production'           => 'daily-ledger:handleAdminProduction',
        '/daily-ledger/admin/production-output'    => 'daily-ledger:handleAdminProductionOutput',
        '/daily-ledger/admin/commissary'           => 'daily-ledger:handleAdminCommissary',
        '/daily-ledger/admin/sales'                => 'daily-ledger:handleAdminSales',
        '/daily-ledger/admin/variances'            => 'daily-ledger:handleAdminVariances',
        '/daily-ledger/admin/products'             => 'daily-ledger:handleAdminProducts',
        '/daily-ledger/admin/products/export'      => 'daily-ledger:handleProductsCsvExport',
        '/daily-ledger/admin/branches'             => 'daily-ledger:handleAdminBranches',
        '/daily-ledger/admin/users'                => 'daily-ledger:handleAdminUsers',
        '/daily-ledger/admin/activity'             => 'daily-ledger:handleAdminActivity',
        '/daily-ledger/admin/settings'             => 'daily-ledger:handleAdminSettings',

        // API: product list for branch (used by cashier form)
        '/daily-ledger/api/v1/cashier/ledger/rows' => 'daily-ledger:apiGetLedgerRows',
        '/daily-ledger/api/v1/cashier/ledger/day-status' => 'daily-ledger:apiGetLedgerDayStatus',

        // API: get current user and branches (Android sync)
        '/daily-ledger/api/v1/me'                  => 'daily-ledger:apiDailyLedgerMe',

        // Production movements
        '/daily-ledger/api/v1/production/products'      => 'daily-ledger:apiProductionProducts',
        '/daily-ledger/api/v1/production/destinations' => 'daily-ledger:apiProductionDestinations',
        '/daily-ledger/api/v1/production/movements'    => 'daily-ledger:apiProductionMovements',
        '/daily-ledger/api/v1/commissary/materials'    => 'daily-ledger:apiCommissaryMaterials',
    ],
    'POST' => [
        // Module-owned login (namespaced)
        '/daily-ledger/auth/login'                => 'daily-ledger:dailyLedgerAuthLogin',
        '/daily-ledger/auth/refresh'              => 'daily-ledger:dailyLedgerAuthRefresh',

        // Cashier: auto-save single field
        '/daily-ledger/api/v1/cashier/ledger/save'       => 'daily-ledger:apiSaveLedgerField',
        // Cashier: batch save (offline sync)
        '/daily-ledger/api/v1/cashier/ledger/save-batch' => 'daily-ledger:apiSaveLedgerBatch',
        // Cashier: close day
        '/daily-ledger/api/v1/cashier/ledger/close-day'  => 'daily-ledger:apiCloseDay',

        // Admin: reopen day
        '/daily-ledger/api/v1/admin/reopen-day'          => 'daily-ledger:apiReopenDay',

        // Admin: product management
        '/daily-ledger/api/v1/admin/products'            => 'daily-ledger:apiCreateProduct',
        '/daily-ledger/api/v1/admin/products/update'     => 'daily-ledger:apiUpdateProduct',
        '/daily-ledger/api/v1/admin/products/import-csv' => 'daily-ledger:apiProductsImportCsv',

        // Admin: branch management
        '/daily-ledger/api/v1/admin/branches'            => 'daily-ledger:apiCreateBranch',
        '/daily-ledger/api/v1/admin/branches/update'     => 'daily-ledger:apiUpdateBranch',

        // Admin: user management
        '/daily-ledger/api/v1/admin/dl-users'            => 'daily-ledger:apiCreateUser',
        '/daily-ledger/api/v1/admin/dl-users/update'     => 'daily-ledger:apiUpdateUser',
        '/daily-ledger/api/v1/admin/dl-users/delete'     => 'daily-ledger:apiDeleteUser',
        '/daily-ledger/api/v1/admin/dl-users/restore'    => 'daily-ledger:apiRestoreUser',

        // Admin: variance status
        '/daily-ledger/api/v1/admin/variances/update'    => 'daily-ledger:apiUpdateVarianceStatus',
        '/daily-ledger/api/v1/admin/settings/permissions' => 'daily-ledger:apiSaveRolePermissions',

        // Production movements (supports transition + offline sync)
        '/daily-ledger/api/v1/production/withdrawal'      => 'daily-ledger:apiProductionWithdrawal',
        '/daily-ledger/api/v1/production/output'          => 'daily-ledger:apiProductionOutput',
        '/daily-ledger/api/v1/production/reverse'         => 'daily-ledger:apiProductionReverse',
        '/daily-ledger/api/v1/production/sync-batch'      => 'daily-ledger:apiProductionSyncBatch',

        // Commissary API
        '/daily-ledger/api/v1/commissary/run'             => 'daily-ledger:apiSaveProductionRun',
        '/daily-ledger/api/v1/commissary/material'        => 'daily-ledger:apiSaveCommissaryMaterial',
    ],
];
