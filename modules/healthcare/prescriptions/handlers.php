<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function rxPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $rows = rxDb()->query(
        'SELECT id, prescription_uuid, patient_id, encounter_id, medication_text, dose_text, route, frequency, duration_text, quantity, refills, status, issued_at, canceled_at '
        . 'FROM ehr_prescriptions ORDER BY issued_at DESC, id DESC LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);

    $prescriptions = ehrHydrateRecordSummaries(is_array($rows) ? $rows : [], 'prescriptions');

    echo ehrRender('modules/prescriptions/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_prescriptions', ['page_title' => 'Prescriptions']),
        [
            'prescriptions' => $prescriptions,
            'result_count' => count($prescriptions),
        ]
    ));
}