<?php
/**
 * Cms Akira Profile Headless Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-profile-headless' => 'cms-akira-profile-headless:pageCmsAkiraProfileHeadlessHome',
        '/api/v1/cms-akira-profile-headless/health' => 'cms-akira-profile-headless:apiCmsAkiraProfileHeadlessHealth',
    ],
    'POST' => [
        // '/api/v1/cms-akira-profile-headless/example' => 'cms-akira-profile-headless:apiCmsAkiraProfileHeadlessExample',
    ],
];
