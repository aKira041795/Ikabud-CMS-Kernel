<?php
/**
 * Anti-Spam Module — Routes
 */

return [
    'GET' => [
        '/admin/anti-spam'          => 'anti-spam:pageAntiSpamDashboard',
        '/admin/anti-spam/log'      => 'anti-spam:pageAntiSpamLog',
        '/admin/anti-spam/blocked'  => 'anti-spam:pageAntiSpamBlocked',
        '/admin/anti-spam/settings' => 'anti-spam:pageAntiSpamSettings',
    ],
    'POST' => [
        '/api/v1/anti-spam/settings'    => 'anti-spam:apiSaveSettings',
        '/api/v1/anti-spam/block-ip'    => 'anti-spam:apiBlockIp',
        '/api/v1/anti-spam/unblock-ip'  => 'anti-spam:apiUnblockIp',
        '/api/v1/anti-spam/clear-log'   => 'anti-spam:apiClearLog',
    ],
];
