<?php
/**
 * Cms Akira Profile Visual Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-profile-visual' => 'cms-akira-profile-visual:pageCmsAkiraProfileVisualHome',
        '/api/v1/cms-akira-profile-visual/health' => 'cms-akira-profile-visual:apiCmsAkiraProfileVisualHealth',
    ],
    'POST' => [
        // '/api/v1/cms-akira-profile-visual/example' => 'cms-akira-profile-visual:apiCmsAkiraProfileVisualExample',
    ],
];
