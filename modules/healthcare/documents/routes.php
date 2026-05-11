<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/documents' => 'documents:docPageIndex',
        '/admin/ehr/documents/{id}/download' => 'documents:docDownload',
    ],
    'POST' => [
        '/admin/ehr/documents' => 'documents:docSaveDocument',
    ],
];