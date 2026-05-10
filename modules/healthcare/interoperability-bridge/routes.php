<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/interop' => 'interoperability-bridge:interopAdminPageIndex',
    ],
    'POST' => [
        '/admin/ehr/interop/identifier' => 'interoperability-bridge:interopAdminMapIdentifier',
        '/admin/ehr/interop/export-patient' => 'interoperability-bridge:interopAdminExportPatient',
    ],
];
