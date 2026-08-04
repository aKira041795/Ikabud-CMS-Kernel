<?php
/**
 * Cms Akira Builder Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-builder' => 'cms-akira-builder:pageCmsAkiraBuilderHome',
        '/api/v1/cms-akira-builder/health' => 'cms-akira-builder:apiCmsAkiraBuilderHealth',
    ],
    'POST' => [
        // '/api/v1/cms-akira-builder/example' => 'cms-akira-builder:apiCmsAkiraBuilderExample',
    ],
];
