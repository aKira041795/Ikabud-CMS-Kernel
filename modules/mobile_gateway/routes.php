<?php
/**
 * Mobile Gateway Module — Routes
 *
 * All routes are JSON-only API endpoints, authenticated via JWT Bearer token.
 * No HTML rendering — no DiSyL involvement.
 */

return [
    'POST' => [
        '/api/v1/mobile/bootstrap'     => 'mobile-gateway:handleMobileBootstrap',
        '/api/v1/mobile/sync/pull'     => 'mobile-gateway:handleMobileSyncPull',
        '/api/v1/mobile/sync/push'     => 'mobile-gateway:handleMobileSyncPush',
        '/api/v1/mobile/device'        => 'mobile-gateway:handleMobileRegisterDevice',
    ],
    'DELETE' => [
        '/api/v1/mobile/device'        => 'mobile-gateway:handleMobileUnregisterDevice',
    ],
    'GET' => [
        '/api/v1/mobile/bootstrap'     => 'mobile-gateway:handleMobileBootstrap',
        '/api/v1/mobile/sync/pull'     => 'mobile-gateway:handleMobileSyncPull',
        '/api/v1/mobile/devices'       => 'mobile-gateway:handleMobileListDevices',
    ],
];
