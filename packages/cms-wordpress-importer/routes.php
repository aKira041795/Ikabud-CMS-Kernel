<?php

declare(strict_types=1);

return [
    'GET' => [
        '/cms/admin/wordpress-import' => 'wordpress-importer:wordpressImporterAdminPage',
    ],
    'POST' => [
        '/api/v1/cms/wordpress-importer/import' => 'wordpress-importer:wordpressImporterApiImport',
    ],
];