<?php
/**
 * Example Notes Module — Routes
 *
 * This file is the canonical route map for this module.
 * Format: ['METHOD' => ['/path' => 'module-id:handlerFunctionName']]
 * All handler functions are defined in handlers.php.
 *
 * @see docs/module-development-guide.md — Routes section
 * @see docs/module-quickstart.md — Step 4
 */

declare(strict_types=1);

return [
    'GET' => [
        // Admin pages
        '/admin/example-notes'      => 'example-notes:pageExampleNotesList',
        '/admin/example-notes/new'  => 'example-notes:pageExampleNotesNew',
        '/admin/example-notes/{id}' => 'example-notes:pageExampleNotesView',
    ],
    'POST' => [
        // JSON API endpoints
        '/api/v1/example-notes/notes'            => 'example-notes:apiExampleNotesCreate',
        '/api/v1/example-notes/notes/{id}'       => 'example-notes:apiExampleNotesUpdate',
        '/api/v1/example-notes/notes/{id}/delete' => 'example-notes:apiExampleNotesDelete',
    ],
];
