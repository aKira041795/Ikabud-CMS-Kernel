<?php

return [
    'GET' => [
        '/admin/navigation-route-module' => 'navigation-route-module:index',
        '/admin/navigation-route-module/items/{id}/edit' => 'navigation-route-module:edit',
        '/admin/navigation-route-module/reports' => 'navigation-route-module:reports',
        '/admin/navigation-route-module/download' => 'navigation-route-module:download',
        '/admin/navigation-route-module/logout' => 'navigation-route-module:logout',
    ],
    'POST' => [],
];
