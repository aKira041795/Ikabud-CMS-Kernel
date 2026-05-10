<?php

declare(strict_types=1);

return [
    'GET' => [
        '/portal' => 'patient-portal:portalPageRoot',
        '/portal/login' => 'patient-portal:portalPageLogin',
        '/portal/register' => 'patient-portal:portalPageRegister',
        '/portal/forgot-password' => 'patient-portal:portalPageForgotPassword',
        '/portal/reset-password' => 'patient-portal:portalPageResetPassword',
        '/portal/dashboard' => 'patient-portal:portalPageDashboard',
        '/portal/appointments' => 'patient-portal:portalPageAppointments',
        '/portal/results' => 'patient-portal:portalPageResults',
        '/portal/prescriptions' => 'patient-portal:portalPagePrescriptions',
        '/portal/documents' => 'patient-portal:portalPageDocuments',
        '/portal/consent' => 'patient-portal:portalPageConsent',
        '/admin/ehr/portal' => 'patient-portal:portalAdminPageIndex',
    ],
    'POST' => [
        '/portal/login' => 'patient-portal:portalAuthLogin',
        '/portal/logout' => 'patient-portal:portalAuthLogout',
        '/portal/register' => 'patient-portal:portalRegister',
        '/portal/forgot-password' => 'patient-portal:portalForgotPasswordRequest',
        '/portal/reset-password' => 'patient-portal:portalResetPassword',
        '/portal/consent' => 'patient-portal:portalConsentRecord',
        '/portal/appointments/reschedule' => 'patient-portal:portalAppointmentRescheduleRequest',
        '/admin/ehr/portal/provision' => 'patient-portal:portalAdminProvision',
        '/admin/ehr/portal/deactivate' => 'patient-portal:portalAdminDeactivate',
        '/admin/ehr/portal/update' => 'patient-portal:portalAdminUpdate',
        '/admin/ehr/portal/reset-password' => 'patient-portal:portalAdminResetPassword',
        '/admin/ehr/portal/reactivate' => 'patient-portal:portalAdminReactivate',
    ],
];
