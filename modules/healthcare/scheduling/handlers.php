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
    $statusFilter = strtolower(trim((string)($input['status'] ?? '')));
    if ($statusFilter === 'all') {
        $statusFilter = '';
    }
    $listPayload = ['limit' => 12];
    if ($statusFilter !== '') {
        $listPayload['status'] = $statusFilter;
    }
    $list = scheduling_cap_ehr_appointment_list_1($listPayload, 'ehr.appointment.list@1', 'scheduling');
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

    // Optional bridge to patient-portal: pending reschedule requests for the appointments we're showing.
    $rescheduleByUuid = [];
    $reschedulePendingTotal = 0;
    try {
        $apptUuids = [];
        foreach ($appointments as $a) {
            $u = (string)($a['appointment_uuid'] ?? '');
            if ($u !== '') {
                $apptUuids[] = $u;
            }
        }
        $reschedResult = app()->cap()->call('ehr.portal.reschedule.pending@1', [
            'status' => 'pending',
            'appointment_uuids' => $apptUuids,
            'limit' => 100,
        ], ['caller_module' => 'scheduling']);
        if (is_array($reschedResult) && !empty($reschedResult['ok'])) {
            foreach (($reschedResult['requests'] ?? []) as $rr) {
                $u = (string)($rr['appointment_uuid'] ?? '');
                if ($u !== '' && ($rr['status'] ?? '') === 'pending') {
                    $rescheduleByUuid[$u] = $rr;
                }
            }
        }
        // Get global pending total (across all appointments, not just this page)
        $totalResult = app()->cap()->call('ehr.portal.reschedule.pending@1', [
            'status' => 'pending',
            'limit' => 500,
        ], ['caller_module' => 'scheduling']);
        if (is_array($totalResult) && !empty($totalResult['ok'])) {
            $reschedulePendingTotal = (int)($totalResult['pending_total'] ?? 0);
        }
    } catch (\Throwable $e) {
        // patient-portal not present or capability blocked — silently skip the bridge
    }

    $selectedAppointmentId = max(0, (int)($input['appointment_id'] ?? 0));
    $selectedAppointment = $selectedAppointmentId > 0 ? schedFetchAppointmentByIdOrUuid($selectedAppointmentId) : null;
    $formSource = is_array($selectedAppointment) ? $selectedAppointment : [];
    foreach (['patient_id', 'appointment_type', 'scheduled_start', 'scheduled_end', 'reason_for_visit', 'notes', 'status'] as $key) {
        if (array_key_exists($key, $input)) {
            $formSource[$key] = $input[$key];
        }
    }
    $formSource['appointment_id'] = $selectedAppointmentId > 0 ? $selectedAppointmentId : (int)($input['appointment_id'] ?? 0);

    // Build per-appointment view-model: primary next action, more-actions list, reschedule flag.
    $statusActionPlan = static function (string $status): array {
        $status = strtolower($status);
        switch ($status) {
            case 'scheduled':
                return [
                    'primary' => ['status' => 'checked-in', 'label' => 'Check in', 'tone' => 'teal'],
                    'more' => [
                        ['status' => 'no-show', 'label' => 'Mark no-show', 'tone' => 'rose'],
                        ['status' => 'canceled', 'label' => 'Cancel appointment', 'tone' => 'slate'],
                    ],
                ];
            case 'checked-in':
                return [
                    'primary' => ['status' => 'waiting', 'label' => 'Send to waiting', 'tone' => 'amber'],
                    'more' => [
                        ['status' => 'roomed', 'label' => 'Send to room', 'tone' => 'indigo', 'needs_room' => true],
                        ['status' => 'no-show', 'label' => 'Mark no-show', 'tone' => 'rose'],
                        ['status' => 'canceled', 'label' => 'Cancel appointment', 'tone' => 'slate'],
                    ],
                ];
            case 'waiting':
                return [
                    'primary' => ['status' => 'roomed', 'label' => 'Send to room', 'tone' => 'indigo', 'needs_room' => true],
                    'more' => [
                        ['status' => 'canceled', 'label' => 'Cancel appointment', 'tone' => 'slate'],
                    ],
                ];
            case 'roomed':
                return [
                    'primary' => ['status' => 'completed', 'label' => 'Complete', 'tone' => 'emerald'],
                    'more' => [
                        ['status' => 'canceled', 'label' => 'Cancel appointment', 'tone' => 'slate'],
                    ],
                ];
            case 'completed':
            case 'canceled':
            case 'no-show':
            default:
                return ['primary' => null, 'more' => []];
        }
    };

    foreach ($appointments as &$a) {
        $u = (string)($a['appointment_uuid'] ?? '');
        $a['has_reschedule_request'] = isset($rescheduleByUuid[$u]);
        $a['reschedule_request'] = $rescheduleByUuid[$u] ?? null;
        $plan = $statusActionPlan((string)($a['status'] ?? ''));
        $a['action_primary'] = $plan['primary'];
        $a['action_more'] = $plan['more'];
    }
    unset($a);

    return array_merge(
        ehrAdminContext($user, 'ehr_scheduling', ['page_title' => 'Appointments']),
        [
            'appointments' => $appointments,
            'result_count' => count($appointments),
            'status_counts' => is_array($statusCounts) ? $statusCounts : [],
            'status_filter' => $statusFilter,
            'status_tabs_data' => ehrStatusTabs(
                'appointment',
                ['scheduled', 'checked-in', 'waiting', 'roomed', 'no-show', 'canceled', 'completed'],
                is_array($statusCounts) ? $statusCounts : [],
                $statusFilter,
                '/admin/ehr/appointments'
            ),
            'patient_options' => $patientOptions,
            'status_options' => $statusOptions,
            'reschedule_pending_total' => $reschedulePendingTotal,
            'reschedule_inbox_url' => '/admin/ehr/portal/reschedule-requests',
            'selected_appointment' => $selectedAppointment,
            'form_error' => $formError !== null ? $formError : (trim((string)($input['error'] ?? '')) !== '' ? (string)$input['error'] : null),
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
        // After save, redirect to a clean URL so the Book/Update form is not
        // pre-populated with the just-saved appointment. The notice query
        // parameter is stripped client-side after the banner renders.
        $notice = $appointmentId > 0 ? 'updated' : 'scheduled';
        app()->redirect('/admin/ehr/appointments?notice=' . rawurlencode($notice));
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to save appointment.'));
    echo ehrRender('modules/scheduling/admin/index.disyl', schedPageState($user, $input, $error));
}
function schedTransitionAppointment(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) { http_response_code(503); echo 'EHR runtime unavailable'; return; }
    $user = ehrRequireAdmin();
    app()->csrfEnforce();
    $input = app()->input();
    $allowed = ['checked-in','waiting','roomed','completed','no-show','canceled'];
    $status = strtolower(trim((string)($input['status'] ?? '')));
    if (!in_array($status, $allowed, true)) {
        app()->redirect('/admin/ehr/appointments?error=' . rawurlencode('Unsupported transition'));
        return;
    }
    $payload = [
        'id' => (int)($input['id'] ?? 0),
        'status' => $status,
        'actor_user_id' => (int)($user['id'] ?? 0),
    ];
    $room = trim((string)($input['room_assignment'] ?? ''));
    if ($room !== '') $payload['room_assignment'] = $room;
    $reason = trim((string)($input['cancel_reason'] ?? ''));
    if ($reason !== '') $payload['cancel_reason'] = $reason;
    $result = app()->cap()->call('ehr.appointment.transition@1', $payload, ['caller_module' => 'scheduling']);
    $ok = is_array($result) && !empty($result['ok']);
    $qs = $ok ? '?notice=' . rawurlencode($status) : ('?error=' . rawurlencode((string)($result['error'] ?? 'Transition failed')));
    app()->redirect('/admin/ehr/appointments' . $qs);
}
