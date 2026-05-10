<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/encounters' => 'encounters:encPageIndex',
    ],
    'POST' => [
        '/admin/ehr/encounters' => 'encounters:encSaveEncounter',
        '/admin/ehr/encounters/vitals' => 'encounters:encSaveVitals',
        '/admin/ehr/encounters/close' => 'encounters:encCloseEncounter',
    ],
];