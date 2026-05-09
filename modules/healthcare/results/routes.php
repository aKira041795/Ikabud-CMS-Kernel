<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/results' => 'results:resPageIndex',
    ],
    'POST' => [
        '/admin/ehr/results' => 'results:resSaveResult',
        '/admin/ehr/results/transition' => 'results:resTransitionResult',
    ],
];