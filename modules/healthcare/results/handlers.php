<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function resPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $rows = resDb()->query(
        'SELECT r.id, r.patient_id, r.encounter_id, r.result_status, r.observed_at, r.value_text, r.value_numeric, r.unit, r.abnormal_flag, r.restricted_flag, '
        . 'oi.item_label, o.order_uuid '
        . 'FROM ehr_lab_results r '
        . 'LEFT JOIN ehr_order_items oi ON oi.id = r.order_item_id '
        . 'LEFT JOIN ehr_orders o ON o.id = oi.order_id '
        . 'ORDER BY r.observed_at DESC, r.id DESC LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);

    $results = ehrHydrateRecordSummaries(is_array($rows) ? $rows : [], 'results');

    echo ehrRender('modules/results/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_results', ['page_title' => 'Results']),
        [
            'results' => $results,
            'result_count' => count($results),
        ]
    ));
}