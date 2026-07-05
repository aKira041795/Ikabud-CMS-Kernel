<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

\Ikabud\Kernel\DiSyL\TemplateEngine::loadViewConfigs(__DIR__ . '/helpers/views');

function schedDateTimeLocalValue(?string $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
}

function schedAppointmentsRedirectUrl(array $input, string $queryString = ''): string
{
    $redirect = trim((string)($input['redirect'] ?? ''));
    if ($redirect === '' || !preg_match('#^/admin/ehr/appointments(?:\?|$)#', $redirect)) {
        $redirect = '/admin/ehr/appointments';
    }
    if ($queryString === '') {
        return $redirect;
    }

    $separator = str_contains($redirect, '?') ? '&' : '?';
    return $redirect . $separator . ltrim($queryString, '?');
}

function schedPageState(array $user, array $input = [], ?string $formError = null): array
{
    $statusFilter = strtolower(trim((string)($input['status'] ?? '')));
    if ($statusFilter === 'all') {
        $statusFilter = '';
    }
    $queueDate = trim((string)($input['queue_date'] ?? date('Y-m-d')));
    if ($queueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $queueDate)) {
        $queueDate = date('Y-m-d');
    }
    $laneMetaMap = schedQueueDestinations();
    $queueLane = strtolower(trim((string)($input['lane'] ?? schedQueueDefaultLane((string)($user['role'] ?? '')))));
    if (!isset($laneMetaMap[$queueLane])) {
        $queueLane = schedQueueDefaultLane((string)($user['role'] ?? ''));
    }

    $queueAppointments = schedFetchQueueRows($queueDate, $statusFilter);
    $statusCountsMap = [];
    $queueCounts = array_fill_keys(array_keys($laneMetaMap), 0);
    foreach ($queueAppointments as $queueAppointment) {
        $statusCode = strtolower(trim((string)($queueAppointment['status'] ?? 'scheduled')));
        $statusCountsMap[$statusCode] = ($statusCountsMap[$statusCode] ?? 0) + 1;

        $destination = strtolower(trim((string)($queueAppointment['queue_destination'] ?? 'front_desk')));
        if (!isset($queueCounts[$destination])) {
            $destination = 'front_desk';
        }
        $queueCounts[$destination]++;
    }

    $appointments = array_values(array_filter($queueAppointments, static function (array $appointment) use ($queueLane): bool {
        if ($queueLane === 'front_desk') {
            return true;
        }

        return strtolower(trim((string)($appointment['queue_destination'] ?? 'front_desk'))) === $queueLane;
    }));

    $statusCounts = [];
    foreach ($statusCountsMap as $statusCode => $count) {
        $statusCounts[] = ['status' => $statusCode, 'total' => $count];
    }

    $statusBaseQuery = ['lane' => $queueLane];
    if ($queueDate !== date('Y-m-d')) {
        $statusBaseQuery['queue_date'] = $queueDate;
    }
    $statusBaseUrl = '/admin/ehr/appointments';
    if ($statusBaseQuery !== []) {
        $statusBaseUrl .= '?' . http_build_query($statusBaseQuery);
    }

    $currentQueueQuery = ['lane' => $queueLane];
    if ($statusFilter !== '') {
        $currentQueueQuery['status'] = $statusFilter;
    }
    if ($queueDate !== date('Y-m-d')) {
        $currentQueueQuery['queue_date'] = $queueDate;
    }
    $currentQueueUrl = '/admin/ehr/appointments';
    if ($currentQueueQuery !== []) {
        $currentQueueUrl .= '?' . http_build_query($currentQueueQuery);
    }

    $queueLaneTabs = [];
    foreach ($laneMetaMap as $laneKey => $laneMeta) {
        $queueLaneTabs[] = [
            'key' => $laneKey,
            'label' => (string)($laneMeta['short'] ?? ucfirst(str_replace('_', ' ', $laneKey))),
            'description' => (string)($laneMeta['description'] ?? ''),
            'count' => (int)($queueCounts[$laneKey] ?? 0),
            'url' => '/admin/ehr/appointments?' . http_build_query(array_filter([
                'lane' => $laneKey,
                'status' => $statusFilter !== '' ? $statusFilter : null,
                'queue_date' => $queueDate !== date('Y-m-d') ? $queueDate : null,
            ], static fn ($value): bool => $value !== null && $value !== '')),
            'active' => $queueLane === $laneKey,
        ];
    }

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

    $handoffActionPlan = static function (array $appointment, string $queueLane): array {
        $status = strtolower(trim((string)($appointment['status'] ?? 'scheduled')));
        if (!in_array($status, ['checked-in', 'waiting', 'roomed'], true)) {
            return [];
        }

        $plans = match ($queueLane) {
            'front_desk' => [
                ['destination' => 'nurse', 'label' => 'Call nurse', 'tone' => 'teal'],
                ['destination' => 'physician', 'label' => 'Call doctor', 'tone' => 'indigo', 'needs_room' => true],
                ['destination' => 'pharmacist', 'label' => 'Send pharmacy', 'tone' => 'amber'],
            ],
            'nurse' => [
                ['destination' => 'physician', 'label' => 'Send doctor', 'tone' => 'indigo', 'needs_room' => true],
                ['destination' => 'pharmacist', 'label' => 'Send pharmacy', 'tone' => 'amber'],
            ],
            'physician' => [
                ['destination' => 'pharmacist', 'label' => 'Send pharmacy', 'tone' => 'amber'],
            ],
            default => [],
        };

        $currentDestination = strtolower(trim((string)($appointment['queue_destination'] ?? 'front_desk')));
        return array_values(array_filter($plans, static function (array $plan) use ($currentDestination): bool {
            return strtolower((string)($plan['destination'] ?? '')) !== $currentDestination;
        }));
    };

    foreach ($appointments as &$a) {
        $u = (string)($a['appointment_uuid'] ?? '');
        $a['has_reschedule_request'] = isset($rescheduleByUuid[$u]);
        $a['reschedule_request'] = $rescheduleByUuid[$u] ?? null;
        $plan = $statusActionPlan((string)($a['status'] ?? ''));
        $a['action_primary'] = $plan['primary'];
        $a['action_more'] = $plan['more'];
        $a['handoff_actions'] = $handoffActionPlan($a, $queueLane);
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
                $statusBaseUrl
            ),
            'patient_options' => $patientOptions,
            'status_options' => $statusOptions,
            'reschedule_pending_total' => $reschedulePendingTotal,
            'reschedule_inbox_url' => '/admin/ehr/portal/reschedule-requests',
            'selected_appointment' => $selectedAppointment,
            'queue_lane' => $queueLane,
            'queue_lane_label' => (string)($laneMetaMap[$queueLane]['label'] ?? 'Reception'),
            'queue_lane_description' => (string)($laneMetaMap[$queueLane]['description'] ?? ''),
            'queue_lanes' => $queueLaneTabs,
            'queue_date' => $queueDate,
            'queue_monitor_url' => '/ehr/queue-monitor',
            'queue_handoff_endpoint' => '/admin/ehr/appointments/handoff',
            'current_queue_url' => $currentQueueUrl,
            'show_appointment_form' => $queueLane === 'front_desk',
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

function schedPageMonitor(array $params = []): void
{
    echo app()->render('modules/scheduling/monitor.disyl', [
        'page_title' => 'Queue monitor',
        'current_calls' => schedFetchQueueMonitorRows(6),
        'waiting_board' => schedFetchWaitingRoomRows(12),
        'monitor_refresh_seconds' => 15,
        'monitor_updated_at' => date('Y-m-d H:i:s'),
    ]);
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
    $qs = $ok ? 'notice=' . rawurlencode($status) : ('error=' . rawurlencode((string)($result['error'] ?? 'Transition failed')));
    app()->redirect(schedAppointmentsRedirectUrl($input, $qs));
}

function schedHandoffAppointment(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) { http_response_code(503); echo 'EHR runtime unavailable'; return; }

    $user = ehrRequireAdmin();
    app()->csrfEnforce();
    $input = app()->input();
    $destination = strtolower(trim((string)($input['destination'] ?? '')));

    $result = schedHandleAppointmentHandoff([
        'id' => (int)($input['id'] ?? 0),
        'destination' => $destination,
        'room_assignment' => trim((string)($input['room_assignment'] ?? '')),
        'actor_user_id' => (int)($user['id'] ?? 0),
    ]);

    $notice = match ($destination) {
        'nurse' => 'called_nurse',
        'physician' => 'called_physician',
        'pharmacist' => 'called_pharmacist',
        default => 'updated',
    };

    $qs = is_array($result) && !empty($result['ok'])
        ? 'notice=' . rawurlencode($notice)
        : 'error=' . rawurlencode((string)($result['error'] ?? 'Queue handoff failed'));
    app()->redirect(schedAppointmentsRedirectUrl($input, $qs));
}
