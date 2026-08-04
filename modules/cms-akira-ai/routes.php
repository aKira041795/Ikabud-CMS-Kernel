<?php
/**
 * Cms Akira Ai Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-ai' => 'cms-akira-ai:pageCmsAkiraAiHome',
        '/api/v1/cms-akira-ai/health' => 'cms-akira-ai:apiCmsAkiraAiHealth',
    ],
    'POST' => [
        // '/api/v1/cms-akira-ai/example' => 'cms-akira-ai:apiCmsAkiraAiExample',
    ],
];
