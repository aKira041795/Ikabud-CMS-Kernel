<?php
/**
 * Cms Akira Navigation Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-navigation' => 'cms-akira-navigation:pageCmsAkiraNavigationHome',
        '/api/v1/cms-akira-navigation/health' => 'cms-akira-navigation:apiCmsAkiraNavigationHealth',
    ],
    'POST' => [
        // '/api/v1/cms-akira-navigation/example' => 'cms-akira-navigation:apiCmsAkiraNavigationExample',
    ],
];
