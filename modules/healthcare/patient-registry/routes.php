<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/patients' => 'patient-registry:prPageIndex',
        '/admin/ehr/patients/export' => 'patient-registry:prExportCsv',
    ],
    'POST' => [
        '/admin/ehr/patients' => 'patient-registry:prSavePatient',
        '/admin/ehr/patients/import' => 'patient-registry:prImportCsv',
    ],
];