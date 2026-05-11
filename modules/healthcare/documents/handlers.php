<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function docPageState(array $user, array $input = [], ?string $formError = null): array
{
    $rows = docDb()->query(
        'SELECT d.id, d.document_uuid, d.patient_id, d.encounter_id, d.document_type, d.title, d.mime_type, d.file_size, d.sensitivity_level, d.uploaded_at, '
        . 'p.consent_required_flag, p.break_glass_only_flag '
        . 'FROM ehr_documents d '
        . 'LEFT JOIN ehr_access_policies p ON p.id = d.access_policy_id '
        . 'ORDER BY d.uploaded_at DESC, d.id DESC LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);

    $documents = ehrHydrateRecordSummaries(is_array($rows) ? $rows : [], 'documents');

    $buckets = ['normal' => [], 'sensitive' => [], 'restricted' => []];
    foreach ($documents as $doc) {
        $level = strtolower((string)($doc['sensitivity_level'] ?? 'normal'));
        if (!empty($doc['break_glass_only_flag']) || in_array($level, ['restricted', 'confidential', 'high'], true)) {
            $buckets['restricted'][] = $doc;
        } elseif (!empty($doc['consent_required_flag']) || in_array($level, ['sensitive', 'protected'], true)) {
            $buckets['sensitive'][] = $doc;
        } else {
            $buckets['normal'][] = $doc;
        }
    }
    $bucketCounts = [
        'normal' => count($buckets['normal']),
        'sensitive' => count($buckets['sensitive']),
        'restricted' => count($buckets['restricted']),
    ];

    $patientSearch = app()->cap()->call('ehr.patient.search@1', ['limit' => 50], ['caller_module' => 'documents']);
    $patientOptions = is_array($patientSearch) && !empty($patientSearch['ok']) && is_array($patientSearch['results'] ?? null)
        ? array_values($patientSearch['results']) : [];
    $encounterList = app()->cap()->call('ehr.encounter.list@1', ['limit' => 50], ['caller_module' => 'documents']);
    $encounterOptions = is_array($encounterList) && !empty($encounterList['ok']) && is_array($encounterList['encounters'] ?? null)
        ? array_values($encounterList['encounters']) : [];

    return array_merge(
        ehrAdminContext($user, 'ehr_documents', ['page_title' => 'Documents']),
        [
            'documents' => $documents,
            'result_count' => count($documents),
            'document_buckets' => $buckets,
            'bucket_counts' => $bucketCounts,
            'patient_options' => $patientOptions,
            'encounter_options' => $encounterOptions,
            'form_error' => $formError,
            'form_notice' => trim((string)($input['notice'] ?? '')),
            'form_values' => [
                'patient_id' => (int)($input['patient_id'] ?? 0),
                'encounter_id' => (int)($input['encounter_id'] ?? 0),
                'title' => (string)($input['title'] ?? ''),
                'document_type' => (string)($input['document_type'] ?? 'attachment'),
                'mime_type' => (string)($input['mime_type'] ?? 'application/pdf'),
                'storage_key' => (string)($input['storage_key'] ?? ''),
                'sensitivity_level' => (string)($input['sensitivity_level'] ?? 'normal'),
            ],
        ]
    );
}

function docPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    echo ehrRender('modules/documents/admin/index.disyl', docPageState($user, app()->input()));
}

function docSaveDocument(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $storageKey = trim((string)($input['storage_key'] ?? ''));
    if ($storageKey === '') {
        $storageKey = 'ehr/manual/' . bin2hex(random_bytes(8));
    }
    $payload = [
        'patient_id' => max(0, (int)($input['patient_id'] ?? 0)),
        'encounter_id' => max(0, (int)($input['encounter_id'] ?? 0)),
        'title' => trim((string)($input['title'] ?? '')),
        'document_type' => trim((string)($input['document_type'] ?? 'attachment')),
        'mime_type' => trim((string)($input['mime_type'] ?? 'application/pdf')),
        'storage_key' => $storageKey,
        'sensitivity_level' => trim((string)($input['sensitivity_level'] ?? 'normal')),
        'consent_required_flag' => !empty($input['consent_required_flag']),
        'break_glass_only_flag' => !empty($input['break_glass_only_flag']),
        'uploaded_by_user_id' => (int)($user['id'] ?? 0),
    ];

    $result = app()->cap()->call('ehr.document.upload@1', $payload, ['caller_module' => 'documents']);
    if (is_array($result) && !empty($result['ok']) && is_array($result['document'] ?? null)) {
        app()->redirect('/admin/ehr/documents?notice=created');
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to register document.'));
    echo ehrRender('modules/documents/admin/index.disyl', docPageState($user, $input, $error));
}