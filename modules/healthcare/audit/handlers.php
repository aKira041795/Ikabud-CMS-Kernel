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
    $input = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
    $q = trim((string)($input['q'] ?? ''));
    $breakGlassOnly = !empty($input['break_glass']);
    $format = strtolower(trim((string)($input['format'] ?? 'html')));
    $searchPayload = ['limit' => $format === 'csv' ? 1000 : 50];
    if ($q !== '') {
        $searchPayload['q'] = $q;
    }
    $search = audit_cap_ehr_audit_search_1($searchPayload, 'ehr.audit.search@1', 'audit');
    $entries = is_array($search) && !empty($search['ok']) && is_array($search['entries'] ?? null)
        ? array_values($search['entries'])
        : [];

    $eventLabels = [
        'ehr.note.viewed' => 'Note viewed',
        'ehr.note.created' => 'Note created',
        'ehr.note.signed' => 'Note signed',
        'ehr.note.amended' => 'Note amended',
        'ehr.patient.viewed' => 'Patient chart opened',
        'ehr.patient.created' => 'Patient registered',
        'ehr.patient.updated' => 'Patient updated',
        'ehr.encounter.created' => 'Visit started',
        'ehr.encounter.closed' => 'Visit closed',
        'ehr.order.created' => 'Order placed',
        'ehr.order.transitioned' => 'Order updated',
        'ehr.result.released' => 'Result released',
        'ehr.result.acknowledged' => 'Result acknowledged',
        'ehr.consent.recorded' => 'Consent recorded',
        'ehr.consent.break_glass' => 'Break-glass access',
        'ehr.prescription.created' => 'Prescription issued',
        'ehr.document.uploaded' => 'Document uploaded',
    ];
    foreach ($entries as &$entry) {
        $action = (string)($entry['action'] ?? '');
        $entry['event_label'] = $eventLabels[$action] ?? ($action !== '' ? ucfirst(str_replace(['.', '_'], ' ', $action)) : 'Unknown event');
        $entry['is_break_glass'] = str_contains($action, 'break_glass') || str_contains($action, 'break-glass');
    }
    unset($entry);

    if ($breakGlassOnly) {
        $entries = array_values(array_filter($entries, static fn(array $e): bool => !empty($e['is_break_glass'])));
    }

    if ($format === 'csv') {
        audSendCsv('ehr-audit-log.csv', $entries);
        return;
    }

    echo ehrRender('modules/audit/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_audit', ['page_title' => 'Access Activity']),
        [
            'entries' => $entries,
            'result_count' => count($entries),
            'filter_q' => $q,
            'filter_break_glass' => $breakGlassOnly,
        ]
    ));
}