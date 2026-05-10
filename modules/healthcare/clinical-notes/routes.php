<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/notes' => 'clinical-notes:cnPageIndex',
    ],
    'POST' => [
        '/admin/ehr/notes' => 'clinical-notes:cnSaveNote',
        '/admin/ehr/notes/sign' => 'clinical-notes:cnSignNote',
        '/admin/ehr/notes/amend' => 'clinical-notes:cnAmendNote',
    ],
];