<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/documents' => 'documents:docPageIndex',
    ],
    'POST' => [
        '/admin/ehr/documents' => 'documents:docSaveDocument',
    ],
];