<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function encPageState(array $user, array $input = [], ?string $formError = null): array
{
    $statusFilter = strtolower(trim((string)($input['status'] ?? '')));
    if ($statusFilter !== '' && !encEncounterStatusAllowed($statusFilter)) {
        $statusFilter = '';
    }

    $selectedEncounterId = max(0, (int)($input['encounter_id'] ?? 0));
    $encounters = encHydrateEncounterPatients(encListRecentEncounters($statusFilter, 25));
    $segments = ['active' => [], 'today' => [], 'recent' => []];
    $todayPrefix = date('Y-m-d');
    foreach ($encounters as &$encRow) {
        if (function_exists('ehrStatusBadge')) {
            $encRow['status_badge'] = ehrStatusBadge((string)($encRow['status'] ?? ''), 'encounter');
        }
        $st = strtolower((string)($encRow['status'] ?? ''));
        $startedAt = (string)($encRow['started_at'] ?? $encRow['encounter_started_at'] ?? $encRow['created_at'] ?? '');
        if (in_array($st, ['open', 'in-progress', 'in_progress'], true)) {
            $segments['active'][] = $encRow;
        } elseif ($startedAt !== '' && str_starts_with($startedAt, $todayPrefix)) {
            $segments['today'][] = $encRow;
        } else {
            $segments['recent'][] = $encRow;
        }
    }
    unset($encRow);

    $selectedEncounter = null;
    if ($selectedEncounterId > 0) {
        $selectedEncounter = encFetchEncounterByIdOrUuid($selectedEncounterId);
        if (is_array($selectedEncounter)) {
            $selectedEncounter = encHydrateEncounterPatients([$selectedEncounter])[0] ?? $selectedEncounter;
            if (function_exists('ehrStatusBadge')) {
                $selectedEncounter['status_badge'] = ehrStatusBadge((string)($selectedEncounter['status'] ?? ''), 'encounter');
            }
        }
    }

    $patientSearch = app()->cap()->call('ehr.patient.search@1', ['limit' => 50], ['caller_module' => 'encounters']);
    $patientOptions = is_array($patientSearch) && !empty($patientSearch['ok']) && is_array($patientSearch['results'] ?? null)
        ? array_values($patientSearch['results'])
        : [];
    $statusCatalog = app()->cap()->call('ehr.core.status.catalog@1', ['domain' => 'encounter'], ['caller_module' => 'encounters']);
    $statusOptions = is_array($statusCatalog) && !empty($statusCatalog['ok']) && is_array($statusCatalog['statuses'] ?? null)
        ? array_values($statusCatalog['statuses'])
        : ['open', 'completed', 'cancelled'];

    return array_merge(
        ehrAdminContext($user, 'ehr_encounters', ['page_title' => 'Visits']),
        [
            'status_filter' => $statusFilter,
            'encounters' => $encounters,
            'result_count' => count($encounters),
            'segment_counts' => [
                'active' => count($segments['active']),
                'today' => count($segments['today']),
                'recent' => count($segments['recent']),
            ],
            'selected_encounter' => $selectedEncounter,
            'patient_options' => $patientOptions,
            'status_options' => $statusOptions,
            'form_error' => $formError !== null ? $formError : (trim((string)($input['error'] ?? '')) !== '' ? (string)$input['error'] : null),
            'form_notice' => trim((string)($input['notice'] ?? '')),
            'form_values' => [
                'patient_id' => (int)($input['patient_id'] ?? 0),
                'encounter_type' => (string)($input['encounter_type'] ?? 'outpatient'),
                'service_line' => (string)($input['service_line'] ?? 'ambulatory'),
                'status' => (string)($input['status'] ?? 'open'),
                'reason_for_visit' => (string)($input['reason_for_visit'] ?? ''),
            ],
            'vitals_values' => [
                'encounter_id' => (int)($input['vitals_encounter_id'] ?? $selectedEncounterId),
                'temperature_c' => (string)($input['temperature_c'] ?? ''),
                'pulse_bpm' => (string)($input['pulse_bpm'] ?? ''),
                'systolic_bp' => (string)($input['systolic_bp'] ?? ''),
                'diastolic_bp' => (string)($input['diastolic_bp'] ?? ''),
                'spo2' => (string)($input['spo2'] ?? ''),
            ],
        ]
    );
}

function encPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    echo ehrRender('modules/encounters/admin/index.disyl', encPageState($user, app()->input()));
}

function encSaveEncounter(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $payload = [
        'patient_id' => max(0, (int)($input['patient_id'] ?? 0)),
        'encounter_type' => trim((string)($input['encounter_type'] ?? 'outpatient')),
        'service_line' => trim((string)($input['service_line'] ?? 'ambulatory')),
        'status' => trim((string)($input['status'] ?? 'open')),
        'reason_for_visit' => trim((string)($input['reason_for_visit'] ?? '')),
    ];

    $result = app()->cap()->call('ehr.encounter.create@1', $payload, ['caller_module' => 'encounters']);
    if (is_array($result) && !empty($result['ok']) && is_array($result['encounter'] ?? null)) {
        $encounterId = (int)($result['encounter']['id'] ?? 0);
        $target = '/admin/ehr/encounters?notice=created';
        if ($encounterId > 0) {
            $target .= '&encounter_id=' . $encounterId;
        }
        app()->redirect($target);
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to start encounter.'));
    echo ehrRender('modules/encounters/admin/index.disyl', encPageState($user, $input, $error));
}

function encSaveVitals(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $encounterId = max(0, (int)($input['encounter_id'] ?? 0));
    $payload = [
        'encounter_id' => $encounterId,
        'captured_by_user_id' => (int)($user['id'] ?? 0),
        'temperature_c' => $input['temperature_c'] ?? null,
        'pulse_bpm' => $input['pulse_bpm'] ?? null,
        'systolic_bp' => $input['systolic_bp'] ?? null,
        'diastolic_bp' => $input['diastolic_bp'] ?? null,
        'spo2' => $input['spo2'] ?? null,
        'notes' => trim((string)($input['notes'] ?? '')),
    ];
    foreach (['temperature_c', 'pulse_bpm', 'systolic_bp', 'diastolic_bp', 'spo2'] as $k) {
        if ($payload[$k] === '' || $payload[$k] === null) {
            unset($payload[$k]);
        }
    }

    $result = app()->cap()->call('ehr.vitals.record@1', $payload, ['caller_module' => 'encounters']);
    if (is_array($result) && !empty($result['ok'])) {
        $target = '/admin/ehr/encounters?notice=vitals';
        if ($encounterId > 0) {
            $target .= '&encounter_id=' . $encounterId;
        }
        app()->redirect($target);
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to record vitals.'));
    echo ehrRender('modules/encounters/admin/index.disyl', encPageState($user, $input, $error));
}
function encCloseEncounter(array $params = []): void
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
    $encounterId = max(0, (int)($input['encounter_id'] ?? 0));
    if ($encounterId <= 0) {
        app()->redirect('/admin/ehr/encounters?error=' . urlencode('Encounter is required.'));
        return;
    }

    $result = app()->cap()->call('ehr.encounter.close@1', [
        'encounter_id' => $encounterId,
        'actor_user_id' => (int)($user['id'] ?? 0),
    ], ['caller_module' => 'encounters']);

    if (is_array($result) && !empty($result['ok'])) {
        app()->redirect('/admin/ehr/encounters?notice=closed&encounter_id=' . $encounterId);
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to close encounter.'));
    app()->redirect('/admin/ehr/encounters?error=' . urlencode($error) . '&encounter_id=' . $encounterId);
}
