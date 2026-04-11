<?php

declare(strict_types=1);

return [
    'GET' => [
        // Bridge admin dashboard (CMS admin UI)
        '/cms/admin/bridge'                  => 'wordpress-bridge:wpBridgeAdminDashboard',

        // Bridge settings page
        '/cms/admin/bridge/settings'         => 'wordpress-bridge:wpBridgeAdminSettings',

        // Bridge JSON APIs
        '/api/v1/bridge/status'              => 'wordpress-bridge:wpBridgeApiStatus',
        '/api/v1/bridge/content'             => 'wordpress-bridge:wpBridgeApiContentList',
        '/api/v1/bridge/health'              => 'wordpress-bridge:wpBridgeApiHealth',
        '/api/v1/bridge/companion/download'  => 'wordpress-bridge:wpBridgeApiCompanionDownload',
    ],

    'POST' => [
        // Event ingestion endpoint — accepts normalized content events
        '/api/v1/bridge/ingest'              => 'wordpress-bridge:wpBridgeApiIngest',

        // WXR file import (bridge-aware)
        '/api/v1/bridge/import/wxr'          => 'wordpress-bridge:wpBridgeApiImportWxr',

        // Bridge settings form save
        '/cms/admin/bridge/settings'         => 'wordpress-bridge:wpBridgeAdminSettings',

        // Token rotation
        '/api/v1/bridge/token/rotate'        => 'wordpress-bridge:wpBridgeApiTokenRotate',
    ],

    'PATCH' => [
        // Bridge-state lifecycle management
        '/api/v1/bridge/state'                    => 'wordpress-bridge:wpBridgeApiSetState',
        '/api/v1/bridge/content/{id}/claim'       => 'wordpress-bridge:wpBridgeApiContentClaim',
        '/api/v1/bridge/content/{id}/resolve'     => 'wordpress-bridge:wpBridgeApiContentResolve',
    ],
];
