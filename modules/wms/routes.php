<?php

declare(strict_types=1);

return [
    'GET' => [
        '/wms/login'             => 'wms:wmsPageLogin',
        '/wms/forgot-password'   => 'wms:wmsForgotPasswordPage',
        '/wms/reset-password'    => 'wms:wmsResetPasswordPage',
        '/wms/logout'            => 'wms:wmsLogout',
        '/wms'                   => 'wms:wmsPageDashboard',
    ],
    'POST' => [
        '/wms/auth/login'              => 'wms:wmsAuthLogin',
        '/api/v1/wms/auth/login'       => 'wms:wmsAuthLogin',
        '/api/v1/wms/auth/forgot-password' => 'wms:wmsApiForgotPassword',
        '/api/v1/wms/auth/reset-password'  => 'wms:wmsApiResetPassword',
    ],
];
