<?php

declare(strict_types=1);

return [
    'GET' => [
        '/portal' => 'patient-portal:portalPageRoot',
        '/portal/login' => 'patient-portal:portalPageLogin',
        '/portal/dashboard' => 'patient-portal:portalPageDashboard',
        '/portal/appointments' => 'patient-portal:portalPageAppointments',
        '/admin/ehr/portal' => 'patient-portal:portalAdminPageIndex',
    ],
    'POST' => [
        '/portal/login' => 'patient-portal:portalAuthLogin',
        '/portal/logout' => 'patient-portal:portalAuthLogout',
        '/admin/ehr/portal/provision' => 'patient-portal:portalAdminProvision',
        '/admin/ehr/portal/deactivate' => 'patient-portal:portalAdminDeactivate',
    ],
];
