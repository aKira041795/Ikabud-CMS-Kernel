<?php
/**
 * Cms Akira Search Adapter Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-search-adapter' => 'cms-akira-search-adapter:pageCmsAkiraSearchAdapterHome',
        '/api/v1/cms-akira-search-adapter/health' => 'cms-akira-search-adapter:apiCmsAkiraSearchAdapterHealth',
    ],
    'POST' => [
        '/api/v1/cms-akira-search-adapter/build-document' => 'cms-akira-search-adapter:apiCmsAkiraSearchAdapterBuildDocument',
    ],
];
