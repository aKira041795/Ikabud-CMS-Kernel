<?php

declare(strict_types=1);

return [
    'GET' => [
        // Module-owned login (namespaced)
        '/daily-ledger/login'                     => 'daily-ledger:pageDailyLedgerLogin',
        '/daily-ledger/forgot-password'           => 'daily-ledger:pageDailyLedgerForgotPassword',
        '/daily-ledger/reset-password'            => 'daily-ledger:pageDailyLedgerResetPassword',
        '/daily-ledger/logout'                    => 'daily-ledger:dailyLedgerLogout',

        // Namespaced pages
        '/daily-ledger/ledger'                     => 'daily-ledger:handleCashierLedger',
        '/daily-ledger/ledger/rows'                => 'daily-ledger:handleCashierRows',
        '/daily-ledger/admin/dashboard'            => 'daily-ledger:handleAdminDashboard',
        '/daily-ledger/admin/usage'                => 'daily-ledger:handleAdminUsage',
        '/daily-ledger/admin/production'           => 'daily-ledger:handleAdminProduction',
        '/daily-ledger/admin/production-output'    => 'daily-ledger:handleAdminProductionOutput',
        '/daily-ledger/admin/commissary'           => 'daily-ledger:handleAdminCommissary',
        '/daily-ledger/admin/sales'                => 'daily-ledger:handleAdminSales',
        '/daily-ledger/admin/variances'            => 'daily-ledger:handleAdminVariances',
        '/daily-ledger/admin/products'             => 'daily-ledger:handleAdminProducts',
        '/daily-ledger/admin/products/export'      => 'daily-ledger:handleProductsCsvExport',
        '/daily-ledger/admin/branches'             => 'daily-ledger:handleAdminBranches',
        '/daily-ledger/admin/deliveries'           => 'daily-ledger:handleAdminDeliveries',
        '/daily-ledger/admin/price-groups'         => 'daily-ledger:handleAdminPriceGroups',
        '/daily-ledger/admin/selling-accounts'     => 'daily-ledger:handleAdminSellingAccounts',
        '/daily-ledger/admin/users'                => 'daily-ledger:handleAdminUsers',
        '/daily-ledger/admin/activity'             => 'daily-ledger:handleAdminActivity',
        '/daily-ledger/admin/settings'             => 'daily-ledger:handleAdminSettings',
        '/daily-ledger/admin/branch-summary'        => 'daily-ledger:handleAdminBranchSummary',

        // API: product list for branch (used by cashier form)
        '/daily-ledger/api/v1/cashier/ledger/rows' => 'daily-ledger:apiGetLedgerRows',
        '/daily-ledger/api/v1/cashier/ledger/day-status' => 'daily-ledger:apiGetLedgerDayStatus',
        '/daily-ledger/api/v1/cashier/ledger/withdrawals' => 'daily-ledger:apiGetCashierWithdrawals',
        '/daily-ledger/api/v1/cashier/ledger/incoming-deliveries' => 'daily-ledger:apiGetIncomingDeliveries',

        // API: get current user and branches (Android sync)
        '/daily-ledger/api/v1/me'                  => 'daily-ledger:apiDailyLedgerMe',

        // Production movements
        '/daily-ledger/api/v1/production/products'      => 'daily-ledger:apiProductionProducts',
        '/daily-ledger/api/v1/production/destinations' => 'daily-ledger:apiProductionDestinations',
        '/daily-ledger/api/v1/production/movements'    => 'daily-ledger:apiProductionMovements',
        '/daily-ledger/api/v1/commissary/materials'    => 'daily-ledger:apiCommissaryMaterials',

        // Phase A: branch supply rules
        '/daily-ledger/api/v1/admin/branch-supply-rules' => 'daily-ledger:apiBranchProductSupplyRuleList',

        // Phase B: deliveries + receivings
        '/daily-ledger/api/v1/deliveries'                    => 'daily-ledger:apiListDeliveries',
        '/daily-ledger/api/v1/deliveries/receiving-detail'   => 'daily-ledger:apiGetDeliveryReceivingDetail',
        '/daily-ledger/api/v1/receivings'                    => 'daily-ledger:apiListReceivings',

        // Phase D: price groups + prices
        '/daily-ledger/api/v1/price-groups'         => 'daily-ledger:apiPriceGroupList',
        '/daily-ledger/api/v1/product-prices'       => 'daily-ledger:apiProductPriceList',

        // Phase E: selling accounts
        '/daily-ledger/api/v1/selling-accounts'           => 'daily-ledger:apiSellingAccountList',
        '/daily-ledger/api/v1/selling-accounts/ledger'    => 'daily-ledger:apiSellingAccountLedger',
        '/daily-ledger/selling-account/ledger'             => 'daily-ledger:handleSellingAccountLedger',

        // Phase F: branch consolidated summary
        '/daily-ledger/api/v1/admin/branch-summary' => 'daily-ledger:apiBranchConsolidatedSummary',
    ],
    'POST' => [
        // Module-owned login (namespaced)
        '/daily-ledger/auth/login'                => 'daily-ledger:dailyLedgerAuthLogin',
        '/daily-ledger/auth/refresh'              => 'daily-ledger:dailyLedgerAuthRefresh',
        '/daily-ledger/api/v1/auth/forgot-password' => 'daily-ledger:dailyLedgerForgotPassword',
        '/daily-ledger/api/v1/auth/reset-password' => 'daily-ledger:dailyLedgerResetPassword',

        // Cashier: auto-save single field
        '/daily-ledger/api/v1/cashier/ledger/save'       => 'daily-ledger:apiSaveLedgerField',
        // Cashier: batch save (offline sync)
        '/daily-ledger/api/v1/cashier/ledger/save-batch' => 'daily-ledger:apiSaveLedgerBatch',
        // Cashier: close day
        '/daily-ledger/api/v1/cashier/ledger/close-day'  => 'daily-ledger:apiCloseDay',

        // Cashier: detailed withdrawals
        '/daily-ledger/api/v1/cashier/ledger/withdrawals' => 'daily-ledger:apiSaveCashierWithdrawals',
        // Cashier: create posted branch dispatch from paper DR
        '/daily-ledger/api/v1/cashier/ledger/dispatch' => 'daily-ledger:apiCreateCashierDispatch',
        // Cashier: receive incoming delivery
        '/daily-ledger/api/v1/cashier/ledger/receive-delivery' => 'daily-ledger:apiReceiveDelivery',
        // Cashier: capture and receive a missing delivery from paper DR
        '/daily-ledger/api/v1/cashier/ledger/receive-paper-dr' => 'daily-ledger:apiReceivePaperDelivery',

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
        '/daily-ledger/api/v1/admin/settings/branding-asset' => 'daily-ledger:apiUploadBrandingAsset',

        // Production movements (supports transition + offline sync)
        '/daily-ledger/api/v1/production/withdrawal'      => 'daily-ledger:apiProductionWithdrawal',
        '/daily-ledger/api/v1/production/output'          => 'daily-ledger:apiProductionOutput',
        '/daily-ledger/api/v1/production/reverse'         => 'daily-ledger:apiProductionReverse',
        '/daily-ledger/api/v1/production/sync-batch'      => 'daily-ledger:apiProductionSyncBatch',

        // Commissary API
        '/daily-ledger/api/v1/commissary/run'             => 'daily-ledger:apiSaveProductionRun',
        '/daily-ledger/api/v1/commissary/material'        => 'daily-ledger:apiSaveCommissaryMaterial',
        '/daily-ledger/api/v1/commissary/dispatch'        => 'daily-ledger:apiCommissaryDispatch',

        // Phase A: branch product supply rules
        '/daily-ledger/api/v1/admin/branch-supply-rules'  => 'daily-ledger:apiBranchProductSupplyRuleUpsert',

        // Phase B: deliveries
        '/daily-ledger/api/v1/deliveries/create'          => 'daily-ledger:apiCreateDelivery',
        '/daily-ledger/api/v1/deliveries/post'            => 'daily-ledger:apiPostDelivery',
        '/daily-ledger/api/v1/deliveries/review-provenance' => 'daily-ledger:apiReviewDeliveryProvenance',
        '/daily-ledger/api/v1/deliveries/void'            => 'daily-ledger:apiVoidDelivery',

        // Phase B: branch receivings
        '/daily-ledger/api/v1/receivings/create'          => 'daily-ledger:apiCreateReceiving',
        '/daily-ledger/api/v1/receivings/post'            => 'daily-ledger:apiPostReceiving',
        '/daily-ledger/api/v1/receivings/void'            => 'daily-ledger:apiVoidReceiving',

        // Phase D: price groups + product prices
        '/daily-ledger/api/v1/admin/price-groups'         => 'daily-ledger:apiPriceGroupCreate',
        '/daily-ledger/api/v1/admin/price-groups/update'  => 'daily-ledger:apiPriceGroupUpdate',
        '/daily-ledger/api/v1/admin/product-prices'       => 'daily-ledger:apiProductPriceUpsert',

        // Phase E: selling accounts
        '/daily-ledger/api/v1/admin/selling-accounts'     => 'daily-ledger:apiSellingAccountCreate',
        '/daily-ledger/api/v1/admin/selling-accounts/update' => 'daily-ledger:apiSellingAccountUpdate',
        '/daily-ledger/api/v1/selling-accounts/save'      => 'daily-ledger:apiSellingAccountSaveField',
        '/daily-ledger/api/v1/selling-accounts/close-day' => 'daily-ledger:apiSellingAccountCloseDay',
        '/daily-ledger/api/v1/admin/selling-accounts/reopen-day' => 'daily-ledger:apiSellingAccountReopenDay',
    ],
];
