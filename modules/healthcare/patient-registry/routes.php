<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/patients' => 'patient-registry:prPageIndex',
    ],
    'POST' => [
        '/admin/ehr/patients' => 'patient-registry:prSavePatient',
    ],
];