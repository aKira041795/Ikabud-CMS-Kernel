<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function prPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
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

    $selectedPatient = null;
    if ($selectedPatientId > 0) {
        $viewResult = app()->cap()->call('ehr.patient.view@1', [
            'id' => $selectedPatientId,
        ], [
            'caller_module' => 'patient-registry',
        ]);
        if (is_array($viewResult) && !empty($viewResult['ok']) && is_array($viewResult['patient'] ?? null)) {
            $selectedPatient = $viewResult['patient'];
        }
    }

    echo ehrRender('modules/patient-registry/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_patient_registry', [
            'page_title' => 'Patient Registry',
        ]),
        [
            'search_query' => $query,
            'patients' => $patients,
            'result_count' => count($patients),
            'selected_patient' => $selectedPatient,
        ]
    ));
}