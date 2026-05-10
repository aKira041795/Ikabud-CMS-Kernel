<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/adt' => 'hospital-adt:adtAdminPageIndex',
    ],
    'POST' => [
        '/admin/ehr/adt/wards' => 'hospital-adt:adtAdminWardCreate',
        '/admin/ehr/adt/beds' => 'hospital-adt:adtAdminBedCreate',
        '/admin/ehr/adt/admit' => 'hospital-adt:adtAdminAdmit',
        '/admin/ehr/adt/transfer' => 'hospital-adt:adtAdminTransfer',
        '/admin/ehr/adt/discharge' => 'hospital-adt:adtAdminDischarge',
    ],
];
