<?php
/**
 * Cms Akira Editor Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/cms-akira-editor' => 'cms-akira-editor:pageCmsAkiraEditorHome',
        '/api/v1/cms-akira-editor/health' => 'cms-akira-editor:apiCmsAkiraEditorHealth',
    ],
    'POST' => [
        // '/api/v1/cms-akira-editor/example' => 'cms-akira-editor:apiCmsAkiraEditorExample',
    ],
];
