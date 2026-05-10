<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/privacy' => 'privacy-consent:pcPageIndex',
    ],
    'POST' => [
        '/admin/ehr/privacy/consents' => 'privacy-consent:pcSaveConsent',
        '/admin/ehr/privacy/consents/revoke' => 'privacy-consent:pcRevokeConsent',
        '/admin/ehr/privacy/break-glass' => 'privacy-consent:pcSaveBreakGlass',
        '/admin/ehr/privacy/break-glass/revoke' => 'privacy-consent:pcRevokeBreakGlass',
    ],
];