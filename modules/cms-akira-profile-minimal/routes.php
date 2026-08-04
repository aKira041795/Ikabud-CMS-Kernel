<?php
/**
 * Cms Akira Profile Minimal Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-profile-minimal' => 'cms-akira-profile-minimal:pageCmsAkiraProfileMinimalHome',
        '/api/v1/cms-akira-profile-minimal/health' => 'cms-akira-profile-minimal:apiCmsAkiraProfileMinimalHealth',
    ],
    'POST' => [
        // '/api/v1/cms-akira-profile-minimal/example' => 'cms-akira-profile-minimal:apiCmsAkiraProfileMinimalExample',
    ],
];
