<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function bbPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $format = strtolower(trim((string)($input['format'] ?? 'html')));
    $result = billing_bridge_cap_ehr_billing_charge_candidates_1(['limit' => $format === 'csv' ? 500 : 20], 'ehr.billing.charge_candidates@1', 'billing-bridge');
    $candidates = is_array($result) && !empty($result['ok']) && is_array($result['candidates'] ?? null)
        ? array_values($result['candidates'])
        : [];
    $candidates = ehrHydrateRecordSummaries($candidates, 'billing-bridge');

    if ($format === 'csv') {
        bbSendCandidatesCsv($candidates);
        return;
    }

    echo ehrRender('modules/billing-bridge/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_billing_bridge', ['page_title' => 'Billing Queue']),
        [
            'candidates' => $candidates,
            'result_count' => count($candidates),
        ]
    ));
}