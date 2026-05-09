<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function audPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $search = audit_cap_ehr_audit_search_1(['limit' => 20], 'ehr.audit.search@1', 'audit');
    $entries = is_array($search) && !empty($search['ok']) && is_array($search['entries'] ?? null)
        ? array_values($search['entries'])
        : [];

    echo ehrRender('modules/audit/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_audit', ['page_title' => 'Audit Trail']),
        [
            'entries' => $entries,
            'result_count' => count($entries),
        ]
    ));
}