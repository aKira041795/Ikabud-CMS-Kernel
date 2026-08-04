<?php
/**
 * Cms Akira Profile Standard Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-profile-standard' => 'cms-akira-profile-standard:pageCmsAkiraProfileStandardHome',
        '/api/v1/cms-akira-profile-standard/health' => 'cms-akira-profile-standard:apiCmsAkiraProfileStandardHealth',
    ],
    'POST' => [
        // '/api/v1/cms-akira-profile-standard/example' => 'cms-akira-profile-standard:apiCmsAkiraProfileStandardExample',
    ],
];
