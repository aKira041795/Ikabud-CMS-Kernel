<?php

declare(strict_types=1);

return [
    'GET' => [
        // Bridge admin dashboard (CMS admin UI)
        '/cms/admin/bridge'                  => 'content-ingestion:wpBridgeAdminDashboard',

        // Bridge settings page
        '/cms/admin/bridge/settings'         => 'content-ingestion:wpBridgeAdminSettings',

        // Bridge JSON APIs
        '/api/v1/bridge/status'              => 'content-ingestion:wpBridgeApiStatus',
        '/api/v1/bridge/content'             => 'content-ingestion:wpBridgeApiContentList',
        '/api/v1/bridge/health'              => 'content-ingestion:wpBridgeApiHealth',
        '/api/v1/bridge/companion/download'  => 'content-ingestion:wpBridgeApiCompanionDownload',
    ],

    'POST' => [
        // Event ingestion endpoint — accepts normalized content events
        '/api/v1/bridge/ingest'              => 'content-ingestion:wpBridgeApiIngest',

        // WXR file import (bridge-aware)
        '/api/v1/bridge/import/wxr'          => 'content-ingestion:wpBridgeApiImportWxr',

        // Bridge settings form save
        '/cms/admin/bridge/settings'         => 'content-ingestion:wpBridgeAdminSettings',

        // Token rotation
        '/api/v1/bridge/token/rotate'        => 'content-ingestion:wpBridgeApiTokenRotate',
    ],

    'PATCH' => [
        // Bridge-state lifecycle management
        '/api/v1/bridge/state'                    => 'content-ingestion:wpBridgeApiSetState',
        '/api/v1/bridge/content/{id}/claim'       => 'content-ingestion:wpBridgeApiContentClaim',
        '/api/v1/bridge/content/{id}/resolve'     => 'content-ingestion:wpBridgeApiContentResolve',
    ],
];
