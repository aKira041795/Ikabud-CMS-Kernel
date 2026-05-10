<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/analytics' => 'analytics-cds:cdsAdminPageIndex',
    ],
    'POST' => [
        '/admin/ehr/analytics/rules' => 'analytics-cds:cdsAdminRuleCreate',
        '/admin/ehr/analytics/alerts/acknowledge' => 'analytics-cds:cdsAdminAlertAcknowledge',
        '/admin/ehr/analytics/evaluate' => 'analytics-cds:cdsAdminEvaluate',
    ],
];
