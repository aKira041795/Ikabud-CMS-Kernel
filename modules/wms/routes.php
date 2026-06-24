<?php

declare(strict_types=1);

return [
    'GET' => [
        '/wms/login'             => 'wms:wmsPageLogin',
        '/wms/forgot-password'   => 'wms:wmsForgotPasswordPage',
        '/wms/reset-password'    => 'wms:wmsResetPasswordPage',
        '/wms/logout'            => 'wms:wmsLogout',
        '/wms'                   => 'wms:wmsPageDashboard',
        '/wms/inventory'         => 'wms:wmsPageInventory',
        '/wms/suppliers'         => 'wms:wmsPageSuppliers',

        '/api/v1/wms/products'           => 'wms:wmsApiProductsList',
        '/api/v1/wms/products/{id}'       => 'wms:wmsApiProductGet',
        '/api/v1/wms/warehouses'          => 'wms:wmsApiWarehousesList',
        '/api/v1/wms/warehouses/{id}'     => 'wms:wmsApiWarehouseGet',
        '/api/v1/wms/locations'           => 'wms:wmsApiLocationsList',
        '/api/v1/wms/locations/{id}'      => 'wms:wmsApiLocationGet',
        '/api/v1/wms/locations/{id}/children' => 'wms:wmsApiLocationChildren',
        '/api/v1/wms/suppliers'           => 'wms:wmsApiSuppliersList',
        '/api/v1/wms/suppliers/{id}'      => 'wms:wmsApiSupplierGet',
        '/api/v1/wms/batches'             => 'wms:wmsApiBatchesList',
        '/api/v1/wms/batches/{id}'        => 'wms:wmsApiBatchGet',
    ],
    'POST' => [
        '/wms/auth/login'              => 'wms:wmsAuthLogin',
        '/api/v1/wms/auth/login'       => 'wms:wmsAuthLogin',
        '/api/v1/wms/auth/forgot-password' => 'wms:wmsApiForgotPassword',
        '/api/v1/wms/auth/reset-password'  => 'wms:wmsApiResetPassword',

        '/api/v1/wms/products'              => 'wms:wmsApiProductCreate',
        '/api/v1/wms/products/{id}'          => 'wms:wmsApiProductUpdate',
        '/api/v1/wms/products/{id}/delete'   => 'wms:wmsApiProductDelete',
        '/api/v1/wms/warehouses'             => 'wms:wmsApiWarehouseCreate',
        '/api/v1/wms/warehouses/{id}'        => 'wms:wmsApiWarehouseUpdate',
        '/api/v1/wms/locations'              => 'wms:wmsApiLocationCreate',
        '/api/v1/wms/locations/{id}'         => 'wms:wmsApiLocationUpdate',
        '/api/v1/wms/suppliers'              => 'wms:wmsApiSupplierCreate',
        '/api/v1/wms/suppliers/{id}'         => 'wms:wmsApiSupplierUpdate',
        '/api/v1/wms/suppliers/{id}/delete'  => 'wms:wmsApiSupplierDelete',
        '/api/v1/wms/batches'                => 'wms:wmsApiBatchCreate',
    ],
];
