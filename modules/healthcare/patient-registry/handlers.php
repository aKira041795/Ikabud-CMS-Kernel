<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function prPageState(array $user, array $input = [], ?string $formError = null): array
{
    $query = trim((string)($input['q'] ?? ''));
    $selectedPatientId = max(0, (int)($input['patient_id'] ?? 0));

    $searchResult = app()->cap()->call('ehr.patient.search@1', [
        'q' => $query,
        'limit' => 25,
    ], [
        'caller_module' => 'patient-registry',
    ]);

    $patients = is_array($searchResult) && !empty($searchResult['ok']) && is_array($searchResult['results'] ?? null)
        ? array_values($searchResult['results'])
        : [];

    $selectedPatient = $selectedPatientId > 0 ? prFetchPatientByIdOrUuid($selectedPatientId) : null;
    $portalAccount = null;
    if ($selectedPatientId > 0) {
        $portalResult = app()->cap()->call('ehr.portal.account.view@1', [
            'patient_id' => $selectedPatientId,
        ], ['caller_module' => 'patient-registry']);
        if (is_array($portalResult) && !empty($portalResult['ok']) && is_array($portalResult['account'] ?? null)) {
            $portalAccount = $portalResult['account'];
        }
    }
    $primaryIdentifier = is_array($selectedPatient['identifiers'][0] ?? null) ? $selectedPatient['identifiers'][0] : [];
    $formSource = is_array($selectedPatient) ? $selectedPatient : [];
    foreach (['patient_id', 'first_name', 'last_name', 'middle_name', 'birth_date', 'sex', 'status', 'primary_phone', 'email', 'identifier_type', 'identifier_value', 'identifier_issuing_authority'] as $key) {
        if (array_key_exists($key, $input)) {
            $formSource[$key] = $input[$key];
        }
    }

    return array_merge(
        ehrAdminContext($user, 'ehr_patient_registry', [
            'page_title' => 'Patient Registry',
        ]),
        [
            'search_query' => $query,
            'patients' => $patients,
            'result_count' => count($patients),
            'selected_patient' => $selectedPatient,
            'portal_account' => $portalAccount,
            'form_error' => $formError,
            'form_notice' => trim((string)($input['notice'] ?? '')),
            'form_values' => [
                'patient_id' => $selectedPatientId,
                'first_name' => (string)($formSource['first_name'] ?? ''),
                'last_name' => (string)($formSource['last_name'] ?? ''),
                'middle_name' => (string)($formSource['middle_name'] ?? ''),
                'birth_date' => (string)($formSource['birth_date'] ?? ''),
                'sex' => (string)($formSource['sex'] ?? 'unknown'),
                'status' => (string)($formSource['status'] ?? 'active'),
                'primary_phone' => (string)($formSource['primary_phone'] ?? ''),
                'email' => (string)($formSource['email'] ?? ''),
                'identifier_type' => (string)($formSource['identifier_type'] ?? ($primaryIdentifier['identifier_type'] ?? '')),
                'identifier_value' => (string)($formSource['identifier_value'] ?? ($primaryIdentifier['identifier_value'] ?? '')),
                'identifier_issuing_authority' => (string)($formSource['identifier_issuing_authority'] ?? ($primaryIdentifier['issuing_authority'] ?? '')),
            ],
        ]
    );
}

function prPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    echo ehrRender('modules/patient-registry/admin/index.disyl', prPageState($user, app()->input()));
}

function prSavePatient(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $patientId = max(0, (int)($input['patient_id'] ?? 0));
    $payload = [
        'id' => $patientId,
        'first_name' => trim((string)($input['first_name'] ?? '')),
        'last_name' => trim((string)($input['last_name'] ?? '')),
        'middle_name' => trim((string)($input['middle_name'] ?? '')),
        'birth_date' => trim((string)($input['birth_date'] ?? '')),
        'sex' => trim((string)($input['sex'] ?? 'unknown')),
        'status' => trim((string)($input['status'] ?? 'active')),
        'primary_phone' => trim((string)($input['primary_phone'] ?? '')),
        'email' => trim((string)($input['email'] ?? '')),
        'identifier_type' => trim((string)($input['identifier_type'] ?? '')),
        'identifier_value' => trim((string)($input['identifier_value'] ?? '')),
        'identifier_issuing_authority' => trim((string)($input['identifier_issuing_authority'] ?? '')),
        'identifiers' => [[
            'type' => trim((string)($input['identifier_type'] ?? '')),
            'value' => trim((string)($input['identifier_value'] ?? '')),
            'issuing_authority' => trim((string)($input['identifier_issuing_authority'] ?? '')),
            'is_primary' => true,
            'status' => 'active',
        ]],
    ];

    $capabilityId = $patientId > 0 ? 'ehr.patient.update@1' : 'ehr.patient.create@1';
    $result = app()->cap()->call($capabilityId, $payload, ['caller_module' => 'patient-registry']);
    if (is_array($result) && !empty($result['ok']) && is_array($result['patient'] ?? null)) {
        $savedPatientId = (int)($result['patient']['id'] ?? 0);
        $target = '/admin/ehr/patients?notice=' . rawurlencode($patientId > 0 ? 'updated' : 'created');
        if ($savedPatientId > 0) {
            $target .= '&patient_id=' . $savedPatientId;
        }
        $searchQuery = trim((string)($input['q'] ?? ''));
        if ($searchQuery !== '') {
            $target .= '&q=' . rawurlencode($searchQuery);
        }
        app()->redirect($target);
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to save patient.'));
    echo ehrRender('modules/patient-registry/admin/index.disyl', prPageState($user, $input, $error));
}