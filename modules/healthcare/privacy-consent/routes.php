<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/privacy' => 'privacy-consent:pcPageIndex',
    ],
    'POST' => [
        '/admin/ehr/privacy/consents' => 'privacy-consent:pcSaveConsent',
        '/admin/ehr/privacy/break-glass' => 'privacy-consent:pcSaveBreakGlass',
    ],
];