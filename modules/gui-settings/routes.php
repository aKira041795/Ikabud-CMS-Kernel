<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/gui-settings' => 'gui-settings:handleGuiSettings',
    ],
    'POST' => [
        '/api/v1/admin/gui-settings' => 'gui-settings:apiSaveGuiSettings',
        '/api/v1/admin/gui-settings/reset' => 'gui-settings:apiResetGuiSettings',
    ],
];
