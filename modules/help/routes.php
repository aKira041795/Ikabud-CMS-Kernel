<?php
/**
 * Help Module — Routes
 *
 * Format: 'METHOD' => [ '/path' => 'module-id:handlerFunction' ]
 * URL parameters: '/path/{id}' passes $params['id'] to the handler.
 */

declare(strict_types=1);

return [
    'GET' => [
        '/admin/help' => 'help:pageHelpHome',
        '/api/v1/help/health' => 'help:apiHelpHealth',
    ],
    'POST' => [
        // '/api/v1/help/example' => 'help:apiHelpExample',
    ],
];
