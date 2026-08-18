<?php
/**
 * DC Cafe POS Module — Routes
 *
 * Format: ['METHOD' => ['/path' => 'module-id:handlerFunctionName']]
 * All handler functions defined in handlers.php and handlers-*.php.
 *
 * @see docs/module-development-guide.md
 */

declare(strict_types=1);

return [
    'GET' => [
        // Module-owned auth pages
        '/dc-cafe/login'                     => 'dc-cafe:pageDcCafeLogin',
        '/dc-cafe/logout'                    => 'dc-cafe:handleLogout',
        '/dc-cafe/forgot-password'           => 'dc-cafe:pageDcCafeForgotPassword',
        '/dc-cafe/reset-password'            => 'dc-cafe:pageDcCafeResetPassword',

        // Main POS pages
        '/dc-cafe/pos'                        => 'dc-cafe:pageDcCafePos',
        '/dc-cafe/pos/session-start'          => 'dc-cafe:pageSessionStart',
        '/dc-cafe/pos/session-end'            => 'dc-cafe:pageSessionEnd',

        // Dashboard
        '/dc-cafe/dashboard'                  => 'dc-cafe:pageDashboard',

        // Order views
        '/dc-cafe/orders'                     => 'dc-cafe:pageOrderList',
        '/dc-cafe/orders/{id}'                => 'dc-cafe:pageOrderDetail',

        // Inventory views
        '/dc-cafe/inventory'                  => 'dc-cafe:pageInventory',
        '/dc-cafe/inventory/receive'          => 'dc-cafe:pageReceiveStock',
        '/dc-cafe/inventory/ledger'           => 'dc-cafe:pageInventoryLedger',
        '/dc-cafe/products/receive'           => 'dc-cafe:pageReceiveProducts',
        '/dc-cafe/ingredients'                => 'dc-cafe:pageIngredientList',
        '/dc-cafe/suppliers'                  => 'dc-cafe:pageSupplierList',
        '/dc-cafe/settings'                   => 'dc-cafe:pageDcCafeSettings',

        // Customer views
        '/dc-cafe/customers'                  => 'dc-cafe:pageCustomerList',
        '/dc-cafe/customers/{id}'             => 'dc-cafe:pageCustomerDetail',

        // API: products
        '/dc-cafe/api/v1/products'            => 'dc-cafe:apiGetProducts',
        '/dc-cafe/api/v1/products/stock'      => 'dc-cafe:apiGetProductStockLevels',
        '/dc-cafe/api/v1/products/{id}'       => 'dc-cafe:apiGetProduct',

        // API: orders
        '/dc-cafe/api/v1/orders'              => 'dc-cafe:apiListOrders',
        '/dc-cafe/api/v1/orders/export'       => 'dc-cafe:apiExportOrdersCsv',
        '/dc-cafe/api/v1/orders/{id}'         => 'dc-cafe:apiGetOrder',

        // API: sessions
        '/dc-cafe/api/v1/sessions/current'    => 'dc-cafe:apiGetCurrentSession',

        // API: customers
        '/dc-cafe/api/v1/customers'           => 'dc-cafe:apiSearchCustomers',
        '/dc-cafe/api/v1/customers/{id}'      => 'dc-cafe:apiGetCustomer',
        '/dc-cafe/api/v1/customers/{id}/orders' => 'dc-cafe:apiGetCustomerOrders',

        // API: inventory
        '/dc-cafe/api/v1/inventory'           => 'dc-cafe:apiGetStockLevels',
        '/dc-cafe/api/v1/inventory/reconciliation/{session_id}' => 'dc-cafe:apiGetReconciliation',
        '/dc-cafe/api/v1/inventory/progress/{session_id}' => 'dc-cafe:apiGetInventoryProgress',

        // API: soft-serve options
        '/dc-cafe/api/v1/soft-serve/bases'       => 'dc-cafe:apiGetSoftServeBases',
        '/dc-cafe/api/v1/soft-serve/bases/list'  => 'dc-cafe:apiListSoftServeBases',
        '/dc-cafe/api/v1/soft-serve/sauces'      => 'dc-cafe:apiGetSoftServeSauces',
        '/dc-cafe/api/v1/soft-serve/sauces/list' => 'dc-cafe:apiListSoftServeSauces',
        '/dc-cafe/api/v1/soft-serve/toppings'    => 'dc-cafe:apiGetSoftServeToppings',
        '/dc-cafe/api/v1/soft-serve/toppings/list' => 'dc-cafe:apiListSoftServeToppings',
        '/dc-cafe/api/v1/soft-serve/addons'       => 'dc-cafe:apiGetSoftServeAddons',
        '/dc-cafe/api/v1/soft-serve/addons/list'  => 'dc-cafe:apiListSoftServeAddons',
        // API: payment methods
        '/dc-cafe/api/v1/payment-methods'     => 'dc-cafe:apiGetPaymentMethods',
        '/dc-cafe/api/v1/payment-methods/list' => 'dc-cafe:apiListPaymentMethods',

        // API: suppliers
        '/dc-cafe/api/v1/suppliers'           => 'dc-cafe:apiListSuppliers',
        '/dc-cafe/api/v1/suppliers/{id}'      => 'dc-cafe:apiGetSupplier',

        // API: ingredients
        '/dc-cafe/api/v1/ingredients'         => 'dc-cafe:apiListIngredients',
        '/dc-cafe/api/v1/ingredients/{id}'    => 'dc-cafe:apiGetIngredient',

        // API: stores
        '/dc-cafe/api/v1/stores'              => 'dc-cafe:apiListStores',
        '/dc-cafe/api/v1/stores/{id}'         => 'dc-cafe:apiGetStore',

        // API: users
        '/dc-cafe/api/v1/users'               => 'dc-cafe:apiListUsers',
        '/dc-cafe/api/v1/users/{id}'          => 'dc-cafe:apiGetUser',

        // API: dashboard
        '/dc-cafe/api/v1/dashboard/today'     => 'dc-cafe:apiGetTodaySalesData',

        // API: backup download
        '/dc-cafe/api/v1/backup/download'     => 'dc-cafe:handleBackupDownload',

        // API: settings / ledger groups
        '/dc-cafe/api/v1/settings/ledger-groups' => 'dc-cafe:apiListLedgerGroups',

        // API: sales report export
        '/dc-cafe/api/v1/sales-report/export' => 'dc-cafe:apiExportSalesReportCsv',
    ],
    'POST' => [
        // Auth
        '/dc-cafe/auth/login'                 => 'dc-cafe:handleAuthLogin',
        '/dc-cafe/api/v1/auth/forgot-password' => 'dc-cafe:apiDcCafeForgotPassword',
        '/dc-cafe/api/v1/auth/reset-password'  => 'dc-cafe:apiDcCafeResetPassword',

        // Session
        '/dc-cafe/api/v1/sessions/start'      => 'dc-cafe:apiStartSession',
        '/dc-cafe/api/v1/sessions/end'        => 'dc-cafe:apiEndSession',

        // Orders
        '/dc-cafe/api/v1/orders'              => 'dc-cafe:apiCreateOrder',
        '/dc-cafe/api/v1/orders/{id}/void'    => 'dc-cafe:apiVoidOrder',

        // Customers
        '/dc-cafe/api/v1/customers'           => 'dc-cafe:apiCreateCustomer',

        // Products
        '/dc-cafe/api/v1/products/receive/batch'  => 'dc-cafe:apiReceiveProductsBatch',
        '/dc-cafe/api/v1/products/stock'          => 'dc-cafe:apiGetProductStockLevels',
        '/dc-cafe/api/v1/products/reset-inventory' => 'dc-cafe:apiResetProductInventory',

        // Inventory
        '/dc-cafe/api/v1/inventory/receive/batch' => 'dc-cafe:apiReceiveStockBatch',
        '/dc-cafe/api/v1/inventory/receive'       => 'dc-cafe:apiReceiveStock',
        '/dc-cafe/api/v1/inventory/adjust'        => 'dc-cafe:apiAdjustStock',
        '/dc-cafe/api/v1/inventory/progress'  => 'dc-cafe:apiSaveInventoryProgress',

        // Suppliers
        '/dc-cafe/api/v1/suppliers'           => 'dc-cafe:apiCreateSupplier',
        '/dc-cafe/api/v1/suppliers/{id}'      => 'dc-cafe:apiUpdateSupplier',

        // Ingredients
        '/dc-cafe/api/v1/ingredients'         => 'dc-cafe:apiCreateIngredient',
        '/dc-cafe/api/v1/ingredients/{id}'    => 'dc-cafe:apiUpdateIngredient',

        // Payment methods management
        '/dc-cafe/api/v1/payment-methods/create' => 'dc-cafe:apiCreatePaymentMethod',
        '/dc-cafe/api/v1/payment-methods/{id}'   => 'dc-cafe:apiUpdatePaymentMethod',

        // Store profile management
        '/dc-cafe/api/v1/stores/{id}'            => 'dc-cafe:apiUpdateStore',

        // User account management
        '/dc-cafe/api/v1/users/create'             => 'dc-cafe:apiCreateUser',
        '/dc-cafe/api/v1/users/{id}/toggle-active' => 'dc-cafe:apiToggleUserActive',

        // Soft-serve option management
        '/dc-cafe/api/v1/soft-serve/bases/create'    => 'dc-cafe:apiCreateSoftServeBase',
        '/dc-cafe/api/v1/soft-serve/bases/{id}'      => 'dc-cafe:apiUpdateSoftServeBase',
        '/dc-cafe/api/v1/soft-serve/sauces/create'   => 'dc-cafe:apiCreateSoftServeSauce',
        '/dc-cafe/api/v1/soft-serve/sauces/{id}'     => 'dc-cafe:apiUpdateSoftServeSauce',
        '/dc-cafe/api/v1/soft-serve/toppings/create' => 'dc-cafe:apiCreateSoftServeTopping',
        '/dc-cafe/api/v1/soft-serve/toppings/{id}'   => 'dc-cafe:apiUpdateSoftServeTopping',
        '/dc-cafe/api/v1/soft-serve/addons/create'   => 'dc-cafe:apiCreateSoftServeAddon',
        '/dc-cafe/api/v1/soft-serve/addons/{id}'     => 'dc-cafe:apiUpdateSoftServeAddon',

        // Ledger group management
        '/dc-cafe/api/v1/settings/ledger-groups/create' => 'dc-cafe:apiCreateLedgerGroup',
        '/dc-cafe/api/v1/settings/ledger-groups/remap'  => 'dc-cafe:apiRemapLedgerGroupCategory',

        // Vouchers
        '/dc-cafe/api/v1/vouchers/validate'   => 'dc-cafe:apiValidateVoucher',

        // Backup
        '/dc-cafe/api/v1/backup/generate'     => 'dc-cafe:apiGenerateBackup',
    ],
    'PUT' => [
        '/dc-cafe/api/v1/suppliers/{id}'      => 'dc-cafe:apiUpdateSupplier',
        '/dc-cafe/api/v1/ingredients/{id}'    => 'dc-cafe:apiUpdateIngredient',
        '/dc-cafe/api/v1/payment-methods/{id}' => 'dc-cafe:apiUpdatePaymentMethod',
        '/dc-cafe/api/v1/stores/{id}'          => 'dc-cafe:apiUpdateStore',
        '/dc-cafe/api/v1/users/{id}'           => 'dc-cafe:apiUpdateUser',
        '/dc-cafe/api/v1/soft-serve/bases/{id}'    => 'dc-cafe:apiUpdateSoftServeBase',
        '/dc-cafe/api/v1/soft-serve/sauces/{id}'   => 'dc-cafe:apiUpdateSoftServeSauce',
        '/dc-cafe/api/v1/soft-serve/toppings/{id}' => 'dc-cafe:apiUpdateSoftServeTopping',
        '/dc-cafe/api/v1/soft-serve/addons/{id}'   => 'dc-cafe:apiUpdateSoftServeAddon',
        '/dc-cafe/api/v1/settings/ledger-groups/{id}' => 'dc-cafe:apiUpdateLedgerGroup',
    ],
    'PATCH' => [
        '/dc-cafe/api/v1/inventory/stock/{id}'          => 'dc-cafe:apiUpdateInventoryStock',
        '/dc-cafe/api/v1/inventory/product-stock/{id}'   => 'dc-cafe:apiUpdateProductStock',
    ],
];
