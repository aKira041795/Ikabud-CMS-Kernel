<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function rxPageState(array $user, array $input = [], ?string $formError = null): array
{
    $rows = rxDb()->query(
        'SELECT id, prescription_uuid, patient_id, encounter_id, medication_text, dose_text, route, frequency, duration_text, quantity, refills, status, issued_at, canceled_at, cancellation_reason '
        . 'FROM ehr_prescriptions ORDER BY issued_at DESC, id DESC LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);

    $prescriptions = ehrHydrateRecordSummaries(is_array($rows) ? $rows : [], 'prescriptions');

    $patientSearch = app()->cap()->call('ehr.patient.search@1', ['limit' => 50], ['caller_module' => 'prescriptions']);
    $patientOptions = is_array($patientSearch) && !empty($patientSearch['ok']) && is_array($patientSearch['results'] ?? null)
        ? array_values($patientSearch['results']) : [];
    $encounterList = app()->cap()->call('ehr.encounter.list@1', ['limit' => 50], ['caller_module' => 'prescriptions']);
    $encounterOptions = is_array($encounterList) && !empty($encounterList['ok']) && is_array($encounterList['encounters'] ?? null)
        ? array_values($encounterList['encounters']) : [];

    return array_merge(
        ehrAdminContext($user, 'ehr_prescriptions', ['page_title' => 'Prescriptions']),
        [
            'prescriptions' => $prescriptions,
            'result_count' => count($prescriptions),
            'patient_options' => $patientOptions,
            'encounter_options' => $encounterOptions,
            'form_error' => $formError !== null ? $formError : (trim((string)($input['error'] ?? '')) !== '' ? (string)$input['error'] : null),
            'form_notice' => trim((string)($input['notice'] ?? '')),
            'form_values' => [
                'patient_id' => (int)($input['patient_id'] ?? 0),
                'encounter_id' => (int)($input['encounter_id'] ?? 0),
                'medication_text' => (string)($input['medication_text'] ?? ''),
                'dose_text' => (string)($input['dose_text'] ?? ''),
                'route' => (string)($input['route'] ?? ''),
                'frequency' => (string)($input['frequency'] ?? ''),
            ],
        ]
    );
}

function rxPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    echo ehrRender('modules/prescriptions/admin/index.disyl', rxPageState($user, app()->input()));
}

function rxSavePrescription(array $params = []): void
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
        'medication_text' => trim((string)($input['medication_text'] ?? '')),
        'status' => 'issued',
        'dose_text' => trim((string)($input['dose_text'] ?? '')),
        'route' => trim((string)($input['route'] ?? '')),
        'frequency' => trim((string)($input['frequency'] ?? '')),
        'prescriber_user_id' => (int)($user['id'] ?? 0),
    ];

    $result = app()->cap()->call('ehr.prescription.issue@1', $payload, ['caller_module' => 'prescriptions']);
    if (is_array($result) && !empty($result['ok']) && is_array($result['prescription'] ?? null)) {
        app()->redirect('/admin/ehr/prescriptions?notice=created');
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to issue prescription.'));
    echo ehrRender('modules/prescriptions/admin/index.disyl', rxPageState($user, $input, $error));
}
function rxCancelPrescription(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }
    $user = ehrRequireAdmin();
    if (function_exists('csrfEnforce')) {
        csrfEnforce();
    }
    $input = app()->input();
    $prescriptionId = max(0, (int)($input['prescription_id'] ?? 0));
    $reason = trim((string)($input['reason'] ?? ''));
    if ($prescriptionId <= 0) {
        app()->redirect('/admin/ehr/prescriptions?error=' . urlencode('Prescription is required.'));
        return;
    }
    if ($reason === '') {
        app()->redirect('/admin/ehr/prescriptions?error=' . urlencode('Cancellation reason is required.'));
        return;
    }
    $result = app()->cap()->call('ehr.prescription.cancel@1', [
        'prescription_id' => $prescriptionId,
        'reason' => $reason,
        'actor_user_id' => (int)($user['id'] ?? 0),
    ], ['caller_module' => 'prescriptions']);
    if (is_array($result) && !empty($result['ok'])) {
        app()->redirect('/admin/ehr/prescriptions?notice=canceled');
        return;
    }
    $error = trim((string)($result['error'] ?? 'Unable to cancel prescription.'));
    app()->redirect('/admin/ehr/prescriptions?error=' . urlencode($error));
}

function rxRequestRefill(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }
    $user = ehrRequireAdmin();
    if (function_exists('csrfEnforce')) {
        csrfEnforce();
    }
    $input = app()->input();
    $prescriptionId = max(0, (int)($input['prescription_id'] ?? 0));
    $reason = trim((string)($input['reason'] ?? ''));
    if ($prescriptionId <= 0) {
        app()->redirect('/admin/ehr/prescriptions?error=' . urlencode('Prescription is required.'));
        return;
    }
    $result = app()->cap()->call('ehr.prescription.request_refill@1', [
        'prescription_id' => $prescriptionId,
        'reason' => $reason,
        'actor_user_id' => (int)($user['id'] ?? 0),
    ], ['caller_module' => 'prescriptions']);
    if (is_array($result) && !empty($result['ok'])) {
        app()->redirect('/admin/ehr/prescriptions?notice=refill_requested');
        return;
    }
    $error = trim((string)($result['error'] ?? 'Unable to request refill.'));
    app()->redirect('/admin/ehr/prescriptions?error=' . urlencode($error));
}
