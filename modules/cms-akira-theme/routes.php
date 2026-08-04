<?php
/**
 * Cms Akira Theme Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-theme' => 'cms-akira-theme:pageCmsAkiraThemeHome',
        '/api/v1/cms-akira-theme/health' => 'cms-akira-theme:apiCmsAkiraThemeHealth',
    ],
    'POST' => [
        // '/api/v1/cms-akira-theme/example' => 'cms-akira-theme:apiCmsAkiraThemeExample',
    ],
];
