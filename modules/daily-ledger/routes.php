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
        '/daily-ledger/admin/sales'                => 'daily-ledger:handleAdminSales',
        '/daily-ledger/admin/variances'            => 'daily-ledger:handleAdminVariances',
        '/daily-ledger/admin/products'             => 'daily-ledger:handleAdminProducts',
        '/daily-ledger/admin/branches'             => 'daily-ledger:handleAdminBranches',
        '/daily-ledger/admin/users'                => 'daily-ledger:handleAdminUsers',
        '/daily-ledger/admin/activity'             => 'daily-ledger:handleAdminActivity',
        '/daily-ledger/admin/settings'             => 'daily-ledger:handleAdminSettings',

        // API: product list for branch (used by cashier form)
        '/daily-ledger/api/v1/cashier/ledger/rows' => 'daily-ledger:apiGetLedgerRows',

        // Production movements
        '/daily-ledger/api/v1/production/destinations' => 'daily-ledger:apiProductionDestinations',
        '/daily-ledger/api/v1/production/movements'    => 'daily-ledger:apiProductionMovements',
    ],
    'POST' => [
        // Module-owned login (namespaced)
        '/daily-ledger/auth/login'                => 'daily-ledger:dailyLedgerAuthLogin',

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

        // Admin: branch management
        '/daily-ledger/api/v1/admin/branches'            => 'daily-ledger:apiCreateBranch',
        '/daily-ledger/api/v1/admin/branches/update'     => 'daily-ledger:apiUpdateBranch',

        // Admin: user management
        '/daily-ledger/api/v1/admin/dl-users'            => 'daily-ledger:apiCreateUser',
        '/daily-ledger/api/v1/admin/dl-users/update'     => 'daily-ledger:apiUpdateUser',

        // Admin: variance status
        '/daily-ledger/api/v1/admin/variances/update'    => 'daily-ledger:apiUpdateVarianceStatus',
        '/daily-ledger/api/v1/admin/settings/permissions' => 'daily-ledger:apiSaveRolePermissions',

        // Production movements (supports transition + offline sync)
        '/daily-ledger/api/v1/production/withdrawal'      => 'daily-ledger:apiProductionWithdrawal',
        '/daily-ledger/api/v1/production/output'          => 'daily-ledger:apiProductionOutput',
        '/daily-ledger/api/v1/production/reverse'         => 'daily-ledger:apiProductionReverse',
        '/daily-ledger/api/v1/production/sync-batch'      => 'daily-ledger:apiProductionSyncBatch',
    ],
];
