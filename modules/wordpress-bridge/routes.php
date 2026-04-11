<?php

declare(strict_types=1);

return [
    'GET' => [
        // Bridge admin dashboard (CMS admin UI)
        '/cms/admin/bridge'          => 'wordpress-bridge:wpBridgeAdminDashboard',

        // Bridge JSON APIs
        '/api/v1/bridge/status'      => 'wordpress-bridge:wpBridgeApiStatus',
        '/api/v1/bridge/content'     => 'wordpress-bridge:wpBridgeApiContentList',
    ],

    'POST' => [
        // Event ingestion endpoint — accepts normalized content events
        '/api/v1/bridge/ingest'      => 'wordpress-bridge:wpBridgeApiIngest',

        // WXR file import (bridge-aware)
        '/api/v1/bridge/import/wxr'  => 'wordpress-bridge:wpBridgeApiImportWxr',
    ],

    'PATCH' => [
        // Bridge-state lifecycle management
        '/api/v1/bridge/state'                    => 'wordpress-bridge:wpBridgeApiSetState',
        '/api/v1/bridge/content/{id}/claim'       => 'wordpress-bridge:wpBridgeApiContentClaim',
        '/api/v1/bridge/content/{id}/resolve'     => 'wordpress-bridge:wpBridgeApiContentResolve',
    ],
];
