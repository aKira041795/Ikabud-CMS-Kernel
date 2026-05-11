<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/results' => 'results:resPageIndex',
        '/admin/ehr/results/export' => 'results:resExportCsv',
    ],
    'POST' => [
        '/admin/ehr/results' => 'results:resSaveResult',
        '/admin/ehr/results/transition' => 'results:resTransitionResult',
    ],
];