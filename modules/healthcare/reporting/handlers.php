<?php

declare(strict_types=1);

function rptRequestFormat(): string
{
    return strtolower(trim((string)(app()->input()['format'] ?? 'json')));
}

function rptPageSummary(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $filters = rptSummaryRequestFilters(app()->input());
    $report = app()->cap()->call('ehr.reporting.summary@1', $filters, ['caller_module' => 'reporting']);
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $csvUrl = $baseUrl . '/api/v1/ehr/reporting/summary' . rptQueryString($filters, ['format' => 'csv']);

    echo ehrRender('modules/reporting/admin/summary.disyl', array_merge(
        ehrAdminContext($user, 'ehr_reporting_summary', [
            'page_title' => 'Clinic Activity',
            'insights_tab' => 'operations',
        ]),
        [
            'filters' => [
                'facility_id' => (int)($filters['facility_id'] ?? 0),
                'department_id' => (int)($filters['department_id'] ?? 0),
                'date_from' => (string)($filters['date_from'] ?? ''),
                'date_to' => (string)($filters['date_to'] ?? ''),
            ],
            'report_ok' => is_array($report) && !empty($report['ok']),
            'report_error' => is_array($report) ? (string)($report['error'] ?? '') : 'Reporting summary unavailable',
            'report_summary' => is_array($report['summary'] ?? null) ? $report['summary'] : [],
            'api_url' => $baseUrl . '/api/v1/ehr/reporting/summary',
            'csv_url' => $csvUrl,
        ]
    ));
}

function rptApiSummary(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        app()->json(['ok' => false, 'error' => 'CMS admin runtime unavailable'], 503);
        return;
    }

    ehrRequireAdmin();
    $filters = rptSummaryRequestFilters(app()->input());
    $report = app()->cap()->call('ehr.reporting.summary@1', $filters, ['caller_module' => 'reporting']);

    if (!is_array($report) || empty($report['ok'])) {
        app()->json(is_array($report) ? $report : ['ok' => false, 'error' => 'Reporting summary unavailable'], 400);
        return;
    }

    if (rptRequestFormat() === 'csv') {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        rptSendCsv(
            'ehr-reporting-summary.csv',
            ['section', 'metric', 'value'],
            rptSummaryCsvRows($summary)
        );
        return;
    }

    app()->json($report);
}

function rptPageCompliance(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $filters = rptComplianceRequestFilters(app()->input());
    $report = app()->cap()->call('ehr.reporting.compliance@1', $filters, ['caller_module' => 'reporting']);
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $meta = is_array($report['meta'] ?? null) ? $report['meta'] : ['page' => (int)($filters['page'] ?? 1), 'limit' => (int)($filters['limit'] ?? 50), 'total' => 0, 'total_pages' => 1];
    $pageBaseUrl = $baseUrl . '/admin/ehr/reports/compliance';
    $csvUrl = $baseUrl . '/api/v1/ehr/reporting/compliance' . rptQueryString($filters, ['format' => 'csv', 'page' => 1]);

    echo ehrRender('modules/reporting/admin/compliance.disyl', array_merge(
        ehrAdminContext($user, 'ehr_reporting_compliance', [
            'page_title' => 'Privacy & Audit Report',
            'insights_tab' => 'compliance',
        ]),
        [
            'filters' => [
                'patient_id' => (int)($filters['patient_id'] ?? 0),
                'actor_user_id' => (int)($filters['actor_user_id'] ?? 0),
                'actor_module_user_id' => (int)($filters['actor_module_user_id'] ?? 0),
                'actor_source' => (string)($filters['actor_source'] ?? ''),
                'date_from' => (string)($filters['date_from'] ?? ''),
                'date_to' => (string)($filters['date_to'] ?? ''),
                'limit' => (int)($filters['limit'] ?? 50),
                'page' => (int)($filters['page'] ?? 1),
            ],
            'report_ok' => is_array($report) && !empty($report['ok']),
            'report_error' => is_array($report) ? (string)($report['error'] ?? '') : 'Compliance report unavailable',
            'report_summary' => is_array($report['summary'] ?? null) ? $report['summary'] : [],
            'report_entries' => is_array($report['entries'] ?? null) ? $report['entries'] : [],
            'api_url' => $baseUrl . '/api/v1/ehr/reporting/compliance',
            'csv_url' => $csvUrl,
            'pagination' => [
                'page' => (int)($meta['page'] ?? 1),
                'limit' => (int)($meta['limit'] ?? (int)($filters['limit'] ?? 50)),
                'total' => (int)($meta['total'] ?? 0),
                'total_pages' => (int)($meta['total_pages'] ?? 1),
                'prev_url' => (int)($meta['page'] ?? 1) > 1 ? $pageBaseUrl . rptQueryString($filters, ['page' => (int)$meta['page'] - 1]) : '',
                'next_url' => (int)($meta['page'] ?? 1) < (int)($meta['total_pages'] ?? 1) ? $pageBaseUrl . rptQueryString($filters, ['page' => (int)$meta['page'] + 1]) : '',
            ],
        ]
    ));
}

function rptApiCompliance(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        app()->json(['ok' => false, 'error' => 'CMS admin runtime unavailable'], 503);
        return;
    }

    ehrRequireAdmin();
    $filters = rptComplianceRequestFilters(app()->input());
    $report = app()->cap()->call('ehr.reporting.compliance@1', $filters, ['caller_module' => 'reporting']);

    if (!is_array($report) || empty($report['ok'])) {
        app()->json(is_array($report) ? $report : ['ok' => false, 'error' => 'Compliance report unavailable'], 400);
        return;
    }

    if (rptRequestFormat() === 'csv') {
        $entries = is_array($report['entries'] ?? null) ? $report['entries'] : [];
        rptSendCsv(
            'ehr-reporting-compliance.csv',
            ['created_at', 'category', 'action', 'module', 'patient_id', 'encounter_id', 'entity_type', 'entity_id', 'actor_source', 'actor_user_id', 'actor_module_user_id', 'denial_reason', 'attempted_action'],
            rptComplianceCsvRows($entries)
        );
        return;
    }

    app()->json($report);
}