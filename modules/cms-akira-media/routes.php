<?php
/**
 * Cms Akira Media Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-media' => 'cms-akira-media:pageCmsAkiraMediaHome',
        '/api/v1/cms-akira-media/health' => 'cms-akira-media:apiCmsAkiraMediaHealth',
    ],
    'POST' => [
        // '/api/v1/cms-akira-media/example' => 'cms-akira-media:apiCmsAkiraMediaExample',
    ],
];
