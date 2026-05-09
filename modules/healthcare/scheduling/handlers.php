<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function schedDateTimeLocalValue(?string $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
}

function schedPageState(array $user, array $input = [], ?string $formError = null): array
{
    $list = scheduling_cap_ehr_appointment_list_1(['limit' => 12], 'ehr.appointment.list@1', 'scheduling');
    $appointments = is_array($list) && !empty($list['ok']) && is_array($list['appointments'] ?? null)
        ? array_values($list['appointments'])
        : [];

    $statusCounts = schedDb()->query('SELECT status, COUNT(*) AS total FROM ehr_appointments GROUP BY status ORDER BY total DESC, status ASC')->fetchAll(PDO::FETCH_ASSOC);
    $patientSearch = app()->cap()->call('ehr.patient.search@1', ['limit' => 50], ['caller_module' => 'scheduling']);
    $patientOptions = is_array($patientSearch) && !empty($patientSearch['ok']) && is_array($patientSearch['results'] ?? null)
        ? array_values($patientSearch['results'])
        : [];
    $statusCatalog = app()->cap()->call('ehr.core.status.catalog@1', ['domain' => 'appointment'], ['caller_module' => 'scheduling']);
    $statusOptions = is_array($statusCatalog) && !empty($statusCatalog['ok']) && is_array($statusCatalog['statuses'] ?? null)
        ? array_values($statusCatalog['statuses'])
        : [];

    $selectedAppointmentId = max(0, (int)($input['appointment_id'] ?? 0));
    $selectedAppointment = $selectedAppointmentId > 0 ? schedFetchAppointmentByIdOrUuid($selectedAppointmentId) : null;
    $formSource = is_array($selectedAppointment) ? $selectedAppointment : [];
    foreach (['patient_id', 'appointment_type', 'scheduled_start', 'scheduled_end', 'reason_for_visit', 'notes', 'status'] as $key) {
        if (array_key_exists($key, $input)) {
            $formSource[$key] = $input[$key];
        }
    }
    $formSource['appointment_id'] = $selectedAppointmentId > 0 ? $selectedAppointmentId : (int)($input['appointment_id'] ?? 0);

    return array_merge(
        ehrAdminContext($user, 'ehr_scheduling', ['page_title' => 'Appointments']),
        [
            'appointments' => $appointments,
            'result_count' => count($appointments),
            'status_counts' => is_array($statusCounts) ? $statusCounts : [],
            'patient_options' => $patientOptions,
            'status_options' => $statusOptions,
            'selected_appointment' => $selectedAppointment,
            'form_error' => $formError,
            'form_notice' => trim((string)($input['notice'] ?? '')),
            'form_values' => [
                'appointment_id' => (int)($formSource['appointment_id'] ?? 0),
                'patient_id' => (int)($formSource['patient_id'] ?? 0),
                'appointment_type' => (string)($formSource['appointment_type'] ?? 'general'),
                'scheduled_start' => schedDateTimeLocalValue(isset($formSource['scheduled_start']) ? (string)$formSource['scheduled_start'] : ''),
                'scheduled_end' => schedDateTimeLocalValue(isset($formSource['scheduled_end']) ? (string)$formSource['scheduled_end'] : ''),
                'reason_for_visit' => (string)($formSource['reason_for_visit'] ?? ''),
                'notes' => (string)($formSource['notes'] ?? ''),
                'status' => (string)($formSource['status'] ?? 'scheduled'),
            ],
        ]
    );
}

function schedPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    echo ehrRender('modules/scheduling/admin/index.disyl', schedPageState($user, app()->input()));
}

function schedSaveAppointment(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $appointmentId = max(0, (int)($input['appointment_id'] ?? 0));
    $payload = [
        'id' => $appointmentId,
        'patient_id' => max(0, (int)($input['patient_id'] ?? 0)),
        'appointment_type' => trim((string)($input['appointment_type'] ?? 'general')),
        'scheduled_start' => trim((string)($input['scheduled_start'] ?? '')),
        'scheduled_end' => trim((string)($input['scheduled_end'] ?? '')),
        'reason_for_visit' => trim((string)($input['reason_for_visit'] ?? '')),
        'notes' => trim((string)($input['notes'] ?? '')),
        'status' => trim((string)($input['status'] ?? 'scheduled')),
        'created_by_user_id' => (int)($user['id'] ?? 0),
    ];

    $capabilityId = $appointmentId > 0 ? 'ehr.appointment.update@1' : 'ehr.appointment.schedule@1';
    $result = app()->cap()->call($capabilityId, $payload, ['caller_module' => 'scheduling']);
    if (is_array($result) && !empty($result['ok']) && is_array($result['appointment'] ?? null)) {
        $savedAppointmentId = (int)($result['appointment']['id'] ?? 0);
        $notice = $appointmentId > 0 ? 'updated' : 'scheduled';
        $target = '/admin/ehr/appointments?notice=' . rawurlencode($notice);
        if ($savedAppointmentId > 0) {
            $target .= '&appointment_id=' . $savedAppointmentId;
        }
        app()->redirect($target);
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to save appointment.'));
    echo ehrRender('modules/scheduling/admin/index.disyl', schedPageState($user, $input, $error));
}