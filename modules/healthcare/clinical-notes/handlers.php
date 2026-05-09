<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function cnPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
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

    echo ehrRender('modules/clinical-notes/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_clinical_notes', ['page_title' => 'Clinical Notes']),
        [
            'notes' => $notes,
            'result_count' => count($notes),
        ]
    ));
}