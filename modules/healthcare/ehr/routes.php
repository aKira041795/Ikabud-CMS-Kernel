<?php

declare(strict_types=1);

return [
    'GET' => [
        '/ehr/login' => 'ehr:ehrPageLogin',
        '/ehr/forgot-password' => 'ehr:ehrForgotPasswordPage',
        '/ehr/reset-password' => 'ehr:ehrResetPasswordPage',
        '/admin/ehr' => 'ehr:ehrDashboardPage',
        '/admin/ehr/settings' => 'ehr:ehrSettingsPage',
        '/admin/ehr/patients/{id}/chart' => 'ehr:ehrPatientChartPage',
    ],
    'POST' => [
        '/ehr/auth/login' => 'ehr:ehrAuthLogin',
        '/api/v1/ehr/auth/login' => 'ehr:ehrAuthLogin',
        '/ehr/logout' => 'ehr:ehrLogout',
        '/api/v1/ehr/auth/forgot-password' => 'ehr:ehrApiForgotPassword',
        '/api/v1/ehr/auth/reset-password' => 'ehr:ehrApiResetPassword',
        '/api/v1/ehr/settings/save' => 'ehr:ehrApiSaveSettings',
        '/api/v1/ehr/settings/branding-asset' => 'ehr:ehrApiUploadBrandingAsset',
        '/admin/ehr/patients/context/clear' => 'ehr:ehrPatientContextClear',
        '/admin/ehr/patients/context/set' => 'ehr:ehrPatientContextSet',
    ],
];
