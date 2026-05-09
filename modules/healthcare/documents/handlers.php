<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function docPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $rows = docDb()->query(
        'SELECT d.id, d.document_uuid, d.patient_id, d.encounter_id, d.document_type, d.title, d.mime_type, d.file_size, d.sensitivity_level, d.uploaded_at, '
        . 'p.consent_required_flag, p.break_glass_only_flag '
        . 'FROM ehr_documents d '
        . 'LEFT JOIN ehr_access_policies p ON p.id = d.access_policy_id '
        . 'ORDER BY d.uploaded_at DESC, d.id DESC LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);

    $documents = ehrHydrateRecordSummaries(is_array($rows) ? $rows : [], 'documents');

    echo ehrRender('modules/documents/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_documents', ['page_title' => 'Documents']),
        [
            'documents' => $documents,
            'result_count' => count($documents),
        ]
    ));
}