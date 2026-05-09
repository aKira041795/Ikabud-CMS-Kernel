<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/ehr/reports' => 'reporting:rptPageSummary',
        '/admin/ehr/reports/summary' => 'reporting:rptPageSummary',
        '/admin/ehr/reports/compliance' => 'reporting:rptPageCompliance',
        '/api/v1/ehr/reporting/summary' => 'reporting:rptApiSummary',
        '/api/v1/ehr/reporting/compliance' => 'reporting:rptApiCompliance',
    ],
];