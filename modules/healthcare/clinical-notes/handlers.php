<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function cnPageState(array $user, array $input = [], ?string $formError = null): array
{
    $rows = cnDb()->query(
        'SELECT n.id, n.note_uuid, n.patient_id, n.encounter_id, n.note_type, n.status, n.restricted_flag, n.signed_at, n.updated_at, '
        . 'nv.version_no, nv.version_kind, nv.body_text '
        . 'FROM ehr_notes n '
        . 'LEFT JOIN ehr_note_versions nv ON nv.id = n.current_version_id '
        . 'ORDER BY n.updated_at DESC, n.id DESC LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);

    $notes = ehrHydrateRecordSummaries(is_array($rows) ? $rows : [], 'clinical-notes');
    foreach ($notes as &$note) {
        if (!is_array($note)) {
            continue;
        }
        $body = trim((string)($note['body_text'] ?? ''));
        $note['excerpt'] = $body !== '' ? mb_substr($body, 0, 180) : '';
    }
    unset($note);

    $patientSearch = app()->cap()->call('ehr.patient.search@1', ['limit' => 50], ['caller_module' => 'clinical-notes']);
    $patientOptions = is_array($patientSearch) && !empty($patientSearch['ok']) && is_array($patientSearch['results'] ?? null)
        ? array_values($patientSearch['results']) : [];
    $encounterList = app()->cap()->call('ehr.encounter.list@1', ['limit' => 50], ['caller_module' => 'clinical-notes']);
    $encounterOptions = is_array($encounterList) && !empty($encounterList['ok']) && is_array($encounterList['encounters'] ?? null)
        ? array_values($encounterList['encounters']) : [];

    return array_merge(
        ehrAdminContext($user, 'ehr_clinical_notes', ['page_title' => 'Clinical Notes']),
        [
            'notes' => $notes,
            'result_count' => count($notes),
            'patient_options' => $patientOptions,
            'encounter_options' => $encounterOptions,
            'form_error' => $formError,
            'form_notice' => trim((string)($input['notice'] ?? '')),
            'form_values' => [
                'patient_id' => (int)($input['patient_id'] ?? 0),
                'encounter_id' => (int)($input['encounter_id'] ?? 0),
                'note_type' => (string)($input['note_type'] ?? 'progress'),
                'body_text' => (string)($input['body_text'] ?? ''),
            ],
        ]
    );
}

function cnPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    echo ehrRender('modules/clinical-notes/admin/index.disyl', cnPageState($user, app()->input()));
}

function cnSaveNote(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $payload = [
        'patient_id' => max(0, (int)($input['patient_id'] ?? 0)),
        'encounter_id' => max(0, (int)($input['encounter_id'] ?? 0)),
        'note_type' => trim((string)($input['note_type'] ?? 'progress')),
        'status' => 'draft',
        'body_text' => trim((string)($input['body_text'] ?? '')),
        'author_user_id' => (int)($user['id'] ?? 0),
    ];

    $result = app()->cap()->call('ehr.note.create@1', $payload, ['caller_module' => 'clinical-notes']);
    if (is_array($result) && !empty($result['ok']) && is_array($result['note'] ?? null)) {
        app()->redirect('/admin/ehr/notes?notice=created');
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to create note.'));
    echo ehrRender('modules/clinical-notes/admin/index.disyl', cnPageState($user, $input, $error));
}