<?php
/**
 * Cms Akira Seo Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-seo' => 'cms-akira-seo:pageCmsAkiraSeoHome',
        '/api/v1/cms-akira-seo/health' => 'cms-akira-seo:apiCmsAkiraSeoHealth',
    ],
    'POST' => [
        // '/api/v1/cms-akira-seo/example' => 'cms-akira-seo:apiCmsAkiraSeoExample',
    ],
];
