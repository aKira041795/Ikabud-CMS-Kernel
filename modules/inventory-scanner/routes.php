<?php

declare(strict_types=1);

return [
    'GET' => [
        // Auth pages
        '/inventory-scanner/login'               => 'inventory-scanner:isPageLogin',
        '/inventory-scanner/logout'              => 'inventory-scanner:isAuthLogout',

        // Web pages
        '/inventory-scanner/scanner'             => 'inventory-scanner:isPageScanner',
        '/inventory-scanner/products'            => 'inventory-scanner:isPageProducts',
        '/inventory-scanner/history'             => 'inventory-scanner:isPageHistory',

        // API: user
        '/api/v1/inventory-scanner/me'           => 'inventory-scanner:isApiMe',

        // API: scan
        '/api/v1/inventory-scanner/scan/lookup'  => 'inventory-scanner:isApiScanLookup',
        '/api/v1/inventory-scanner/sessions'     => 'inventory-scanner:isApiSessions',
        '/api/v1/inventory-scanner/sessions/items' => 'inventory-scanner:isApiSessionItems',

        // API: products
        '/api/v1/inventory-scanner/products'     => 'inventory-scanner:isApiProducts',
        '/api/v1/inventory-scanner/categories'   => 'inventory-scanner:isApiCategories',
        '/api/v1/inventory-scanner/locations'    => 'inventory-scanner:isApiLocations',

        // API: export
        '/api/v1/inventory-scanner/export/csv'   => 'inventory-scanner:isApiExportCsv',
    ],
    'POST' => [
        // Auth
        '/inventory-scanner/auth/login'          => 'inventory-scanner:isAuthLogin',
        '/inventory-scanner/auth/refresh'        => 'inventory-scanner:isAuthRefresh',
        '/inventory-scanner/auth/logout'         => 'inventory-scanner:isAuthLogout',

        // API: scan actions
        '/api/v1/inventory-scanner/scan/save'    => 'inventory-scanner:isApiScanSave',
        '/api/v1/inventory-scanner/scan/save-batch' => 'inventory-scanner:isApiScanBatchSave',
        '/api/v1/inventory-scanner/sync'         => 'inventory-scanner:isApiSync',

        // API: sessions
        '/api/v1/inventory-scanner/sessions/close' => 'inventory-scanner:isApiCloseSession',

        // API: products
        '/api/v1/inventory-scanner/products/save' => 'inventory-scanner:isApiProductSave',
    ],
];
