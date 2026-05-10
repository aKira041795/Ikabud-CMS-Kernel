<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/prescriptions' => 'prescriptions:rxPageIndex',
    ],
    'POST' => [
        '/admin/ehr/prescriptions' => 'prescriptions:rxSavePrescription',
        '/admin/ehr/prescriptions/cancel' => 'prescriptions:rxCancelPrescription',
        '/admin/ehr/prescriptions/refill' => 'prescriptions:rxRequestRefill',
    ],
];