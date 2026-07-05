<?php
declare(strict_types=1);

function schedCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('scheduling');
    if (!$ctx) {
        throw new \RuntimeException('Scheduling module context unavailable');
    }

    return $ctx;
}

function schedDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return schedCtx()->db();
}

function schedAppointmentStatusAllowed(string $status): bool
{
    $result = app()->cap()->call('ehr.core.status.catalog@1', ['domain' => 'appointment'], ['caller_module' => 'scheduling']);
    $statuses = is_array($result) && !empty($result['ok']) && is_array($result['statuses'] ?? null)
        ? $result['statuses']
        : [];

    return in_array($status, $statuses, true);
}

function schedPatientSummary(int $patientId): ?array
{
    $result = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'scheduling']);
    $patient = is_array($result) && !empty($result['ok']) && is_array($result['patient'] ?? null)
        ? $result['patient']
        : null;
    if (!$patient) {
        return null;
    }

    return [
        'id' => (int)($patient['id'] ?? 0),
        'patient_uuid' => (string)($patient['patient_uuid'] ?? ''),
        'first_name' => (string)($patient['first_name'] ?? ''),
        'last_name' => (string)($patient['last_name'] ?? ''),
        'birth_date' => (string)($patient['birth_date'] ?? ''),
        'sex' => (string)($patient['sex'] ?? ''),
        'status' => (string)($patient['status'] ?? ''),
    ];
}

function schedFetchAppointmentByIdOrUuid(int $id = 0, string $appointmentUuid = ''): ?array
{
    $sql = 'SELECT * FROM ehr_appointments WHERE id = :id LIMIT 1';
    $params = [':id' => $id];

    if ($id <= 0 && $appointmentUuid !== '') {
        $sql = 'SELECT * FROM ehr_appointments WHERE appointment_uuid = :appointment_uuid LIMIT 1';
        $params = [':appointment_uuid' => $appointmentUuid];
    }

    $appointment = schedDb()->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($appointment)) {
        return null;
    }

    return schedAugmentAppointmentRow($appointment);
}

function schedAllowedTransitions(): array
{
    return [
        'scheduled' => ['checked-in', 'no-show', 'canceled'],
        'checked-in' => ['waiting', 'roomed', 'canceled'],
        'waiting' => ['roomed', 'canceled'],
        'roomed' => ['completed'],
        'completed' => [],
        'no-show' => [],
        'canceled' => [],
    ];
}

function schedTimestampColumnForStatus(string $status): ?string
{
    return match ($status) {
        'checked-in' => 'checked_in_at',
        'roomed' => 'roomed_at',
        'completed' => 'completed_at',
        'no-show' => 'no_show_at',
        'canceled' => 'canceled_at',
        default => null,
    };
}

function schedNormalizeDateTime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function schedActiveQueueStatuses(): array
{
    return ['scheduled', 'checked-in', 'waiting', 'roomed'];
}

function schedTerminalAppointmentStatuses(): array
{
    return ['completed', 'no-show', 'canceled'];
}

function schedQueueDestinations(): array
{
    return [
        'front_desk' => [
            'label' => 'Reception',
            'short' => 'Front desk',
            'description' => 'Check-ins, arrivals, and same-day queue management.',
        ],
        'nurse' => [
            'label' => 'Nurse intake',
            'short' => 'Nurse',
            'description' => 'Vitals, triage, and prep before the visit.',
        ],
        'physician' => [
            'label' => 'Doctor consult',
            'short' => 'Doctor',
            'description' => 'Patients ready for the provider encounter.',
        ],
        'pharmacist' => [
            'label' => 'Pharmacy',
            'short' => 'Pharmacy',
            'description' => 'Medication counseling and dispense follow-up.',
        ],
    ];
}

function schedQueueDefaultLane(?string $role): string
{
    $role = strtolower(trim((string)$role));

    return match ($role) {
        'nurse' => 'nurse',
        'physician', 'provider', 'clinician' => 'physician',
        'pharmacist' => 'pharmacist',
        default => 'front_desk',
    };
}

function schedQueueDestinationLabel(?string $destination): string
{
    $destination = strtolower(trim((string)$destination));
    $destinations = schedQueueDestinations();

    return (string)($destinations[$destination]['label'] ?? $destinations['front_desk']['label']);
}

function schedQueueTicketDisplay(int $ticketNumber): string
{
    if ($ticketNumber <= 0) {
        return 'Pending';
    }

    return 'T-' . str_pad((string)$ticketNumber, 3, '0', STR_PAD_LEFT);
}

function schedPatientInitials(?array $patientSummary): string
{
    if (!is_array($patientSummary)) {
        return 'PT';
    }

    $letters = '';
    foreach ([(string)($patientSummary['first_name'] ?? ''), (string)($patientSummary['last_name'] ?? '')] as $name) {
        $name = trim($name);
        if ($name !== '') {
            $letters .= strtoupper(substr($name, 0, 1));
        }
    }

    return $letters !== '' ? $letters : 'PT';
}

function schedAugmentAppointmentRow(array $appointment): array
{
    if (!isset($appointment['patient_summary']) || !is_array($appointment['patient_summary'])) {
        $appointment['patient_summary'] = schedPatientSummary((int)($appointment['patient_id'] ?? 0));
    }

    $destinations = schedQueueDestinations();
    $destination = strtolower(trim((string)($appointment['queue_destination'] ?? '')));
    if ($destination === '' || !isset($destinations[$destination])) {
        $destination = 'front_desk';
    }

    $appointment['queue_destination'] = $destination;
    $appointment['queue_destination_label'] = schedQueueDestinationLabel($destination);
    $appointment['queue_ticket_number'] = (int)($appointment['queue_ticket_number'] ?? 0);
    $appointment['queue_ticket_label'] = schedQueueTicketDisplay((int)$appointment['queue_ticket_number']);
    $appointment['patient_initials'] = schedPatientInitials(is_array($appointment['patient_summary'] ?? null) ? $appointment['patient_summary'] : null);

    return $appointment;
}

function schedEntityRow(array $appointment): array
{
    $patientSummary = is_array($appointment['patient_summary'] ?? null) ? $appointment['patient_summary'] : [];
    $patientName = trim((string)($patientSummary['last_name'] ?? ''));
    if ($patientName !== '') {
        $patientName .= ', ';
    }
    $patientName .= trim((string)($patientSummary['first_name'] ?? ''));

    $appointment['patient_name'] = trim($patientName, ', ');
    $appointment['scheduled_at'] = (string)($appointment['scheduled_start'] ?? '');

    return $appointment;
}

function schedNextQueueTicketNumber(): int
{
    $row = schedDb()->query(
        'SELECT COALESCE(MAX(queue_ticket_number), 0) AS max_ticket '
        . 'FROM ehr_appointments '
        . 'WHERE queue_ticket_number IS NOT NULL AND DATE(COALESCE(checked_in_at, created_at)) = CURDATE()'
    )->fetch(\PDO::FETCH_ASSOC);

    return max(1, ((int)($row['max_ticket'] ?? 0)) + 1);
}

function schedEnsureQueueTicketForAppointment(array $appointment): int
{
    $ticketNumber = (int)($appointment['queue_ticket_number'] ?? 0);
    if ($ticketNumber > 0) {
        return $ticketNumber;
    }

    $ticketNumber = schedNextQueueTicketNumber();
    schedDb()->execute(
        'UPDATE ehr_appointments SET queue_ticket_number = :queue_ticket_number, updated_at = NOW() WHERE id = :id AND (queue_ticket_number IS NULL OR queue_ticket_number = 0) LIMIT 1',
        [
            ':queue_ticket_number' => $ticketNumber,
            ':id' => (int)($appointment['id'] ?? 0),
        ]
    );

    return $ticketNumber;
}

function schedFetchQueueRows(string $scheduledDate, string $statusFilter = ''): array
{
    $scheduledDate = trim($scheduledDate);
    if ($scheduledDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate)) {
        $scheduledDate = date('Y-m-d');
    }

    $where = ['DATE(scheduled_start) = :scheduled_date'];
    $params = [':scheduled_date' => $scheduledDate];
    $statusFilter = strtolower(trim($statusFilter));

    if ($statusFilter !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $statusFilter;
    } else {
        $where[] = "status IN ('scheduled', 'checked-in', 'waiting', 'roomed')";
    }

    $rows = schedDb()->query(
        'SELECT * FROM ehr_appointments WHERE ' . implode(' AND ', $where) . ' ORDER BY scheduled_start ASC, id ASC',
        $params
    )->fetchAll(\PDO::FETCH_ASSOC);

    $appointments = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $appointments[] = schedAugmentAppointmentRow($row);
    }

    return $appointments;
}

function schedFetchQueueMonitorRows(int $limit = 8): array
{
    $limit = max(1, min(24, $limit));
    $rows = schedDb()->query(
        'SELECT * FROM ehr_appointments '
        . "WHERE DATE(scheduled_start) = CURDATE() AND queue_called_at IS NOT NULL AND status NOT IN ('completed', 'no-show', 'canceled') "
        . 'ORDER BY queue_called_at DESC, id DESC LIMIT ' . $limit
    )->fetchAll(\PDO::FETCH_ASSOC);

    $appointments = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $appointments[] = schedAugmentAppointmentRow($row);
    }

    return $appointments;
}

function schedFetchWaitingRoomRows(int $limit = 12): array
{
    $limit = max(1, min(50, $limit));
    $rows = schedDb()->query(
        'SELECT * FROM ehr_appointments '
        . "WHERE DATE(scheduled_start) = CURDATE() AND queue_ticket_number IS NOT NULL AND status IN ('checked-in', 'waiting', 'roomed') "
        . 'ORDER BY COALESCE(queue_called_at, checked_in_at, scheduled_start) ASC, id ASC LIMIT ' . $limit
    )->fetchAll(\PDO::FETCH_ASSOC);

    $appointments = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $appointments[] = schedAugmentAppointmentRow($row);
    }

    return $appointments;
}

function schedHandleAppointmentHandoff(array $payload): array
{
    $appointment = schedFetchAppointmentByIdOrUuid((int)($payload['id'] ?? 0), trim((string)($payload['appointment_uuid'] ?? '')));
    if (!$appointment) {
        return ['ok' => false, 'error' => 'Appointment not found'];
    }

    $destination = strtolower(trim((string)($payload['destination'] ?? '')));
    $destinations = schedQueueDestinations();
    if ($destination === '' || !isset($destinations[$destination]) || $destination === 'front_desk') {
        return ['ok' => false, 'error' => 'Unsupported queue destination'];
    }

    $currentStatus = strtolower(trim((string)($appointment['status'] ?? 'scheduled')));
    if (in_array($currentStatus, schedTerminalAppointmentStatuses(), true)) {
        return ['ok' => false, 'error' => 'Completed appointments cannot be handed off'];
    }
    if (!in_array($currentStatus, ['checked-in', 'waiting', 'roomed'], true)) {
        return ['ok' => false, 'error' => 'Patient must be checked in before handoff'];
    }

    $roomAssignment = trim((string)($payload['room_assignment'] ?? ''));
    $ticketNumber = schedEnsureQueueTicketForAppointment($appointment);
    $transitionStatus = null;
    if ($destination === 'nurse' && $currentStatus === 'checked-in') {
        $transitionStatus = 'waiting';
    }
    if ($destination === 'physician' && in_array($currentStatus, ['checked-in', 'waiting'], true)) {
        $transitionStatus = 'roomed';
    }

    if ($transitionStatus !== null) {
        $transitionPayload = [
            'id' => (int)$appointment['id'],
            'status' => $transitionStatus,
            'service_line' => trim((string)($payload['service_line'] ?? 'ambulatory')),
            'attending_provider_id' => isset($payload['attending_provider_id']) ? (int)$payload['attending_provider_id'] : null,
            'suppress_audit' => true,
        ];
        if ($roomAssignment !== '') {
            $transitionPayload['room_assignment'] = $roomAssignment;
        }
        $transition = scheduling_cap_ehr_appointment_transition_1($transitionPayload, '', 'scheduling');
        if (!is_array($transition) || empty($transition['ok'])) {
            return $transition;
        }
    }

    $sql = 'UPDATE ehr_appointments SET '
        . 'queue_ticket_number = COALESCE(queue_ticket_number, :queue_ticket_number), '
        . 'queue_destination = :queue_destination, '
        . 'queue_called_at = NOW(), '
        . 'queue_called_by_user_id = :queue_called_by_user_id, '
        . 'updated_at = NOW()';
    $params = [
        ':queue_ticket_number' => $ticketNumber,
        ':queue_destination' => $destination,
        ':queue_called_by_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
        ':id' => (int)$appointment['id'],
    ];
    if ($roomAssignment !== '') {
        $sql .= ', room_assignment = :room_assignment';
        $params[':room_assignment'] = $roomAssignment;
    }
    $sql .= ' WHERE id = :id LIMIT 1';

    try {
        schedDb()->execute($sql, $params);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Queue handoff failed', 'details' => $e->getMessage()];
    }

    $updated = schedFetchAppointmentByIdOrUuid((int)$appointment['id']);
    ehcAudit('scheduling', 'ehr.appointment.handoff', 'ehr_appointment', (int)$appointment['id'], $updated ?? [], $appointment);
    app()->events()->fire('ehr.appointment.handoff', [
        'appointment_id' => (int)$appointment['id'],
        'appointment_uuid' => (string)($appointment['appointment_uuid'] ?? ''),
        'patient_id' => (int)($appointment['patient_id'] ?? 0),
        'destination' => $destination,
        'actor_user_id' => isset($payload['actor_user_id']) ? (int)$payload['actor_user_id'] : null,
        'ticket_number' => $ticketNumber,
        'status' => (string)($updated['status'] ?? $currentStatus),
    ]);

    return ['ok' => true, 'appointment' => $updated];
}

function scheduling_cap_ehr_appointment_schedule_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $appointmentType = trim((string)($data['appointment_type'] ?? 'general'));
    $scheduledStart = schedNormalizeDateTime((string)($data['scheduled_start'] ?? ''));
    $scheduledEnd = schedNormalizeDateTime((string)($data['scheduled_end'] ?? ''));
    $status = strtolower(trim((string)($data['status'] ?? 'scheduled')));

    if ($patientId <= 0 || $appointmentType === '' || $scheduledStart === null) {
        return ['ok' => false, 'error' => 'patient_id, appointment_type, and scheduled_start are required'];
    }

    if (!schedAppointmentStatusAllowed($status)) {
        return ['ok' => false, 'error' => 'Unsupported appointment status'];
    }

    if ($scheduledEnd !== null && strtotime($scheduledEnd) < strtotime($scheduledStart)) {
        return ['ok' => false, 'error' => 'scheduled_end must be after scheduled_start'];
    }

    $patient = schedPatientSummary($patientId);
    if (!$patient) {
        return ['ok' => false, 'error' => 'Patient not found'];
    }

    $appointmentUuid = ehcGenerateRecordKey('apt');

    try {
        schedDb()->execute(
            'INSERT INTO ehr_appointments '
            . '(appointment_uuid, patient_id, encounter_id, appointment_type, scheduled_start, scheduled_end, facility_id, department_id, location_id, status, reason_for_visit, notes, created_by_user_id, created_at, updated_at) '
            . 'VALUES (:appointment_uuid, :patient_id, NULL, :appointment_type, :scheduled_start, :scheduled_end, :facility_id, :department_id, :location_id, :status, :reason_for_visit, :notes, :created_by_user_id, NOW(), NOW())',
            [
                ':appointment_uuid' => $appointmentUuid,
                ':patient_id' => $patientId,
                ':appointment_type' => $appointmentType,
                ':scheduled_start' => $scheduledStart,
                ':scheduled_end' => $scheduledEnd,
                ':facility_id' => isset($data['facility_id']) ? (int)$data['facility_id'] : null,
                ':department_id' => isset($data['department_id']) ? (int)$data['department_id'] : null,
                ':location_id' => isset($data['location_id']) ? (int)$data['location_id'] : null,
                ':status' => $status,
                ':reason_for_visit' => trim((string)($data['reason_for_visit'] ?? '')),
                ':notes' => trim((string)($data['notes'] ?? '')),
                ':created_by_user_id' => isset($data['created_by_user_id']) ? (int)$data['created_by_user_id'] : null,
            ]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Appointment scheduling failed', 'details' => $e->getMessage()];
    }

    $appointmentId = (int)schedDb()->lastInsertId();
    $appointment = schedFetchAppointmentByIdOrUuid($appointmentId);
    ehcAudit('scheduling', 'ehr.appointment.scheduled', 'ehr_appointment', $appointmentId, $appointment ?? []);
    app()->events()->fire('ehr.appointment.scheduled', [
        'appointment_id' => $appointmentId,
        'appointment_uuid' => $appointmentUuid,
        'patient_id' => $patientId,
        'status' => $status,
    ]);

    return ['ok' => true, 'appointment' => $appointment];
}

function scheduling_cap_ehr_appointment_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $appointment = schedFetchAppointmentByIdOrUuid((int)($data['id'] ?? 0), trim((string)($data['appointment_uuid'] ?? '')));
    if (!$appointment) {
        return ['ok' => false, 'error' => 'Appointment not found'];
    }

    return ['ok' => true, 'appointment' => $appointment];
}

function scheduling_cap_ehr_appointment_list_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $limit = max(1, min(100, (int)($data['limit'] ?? 25)));
    $where = [];
    $params = [];

    $status = strtolower(trim((string)($data['status'] ?? '')));
    if ($status !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }

    if (isset($data['facility_id'])) {
        $where[] = 'facility_id = :facility_id';
        $params[':facility_id'] = (int)$data['facility_id'];
    }

    if (isset($data['department_id'])) {
        $where[] = 'department_id = :department_id';
        $params[':department_id'] = (int)$data['department_id'];
    }

    $scheduledDate = trim((string)($data['scheduled_date'] ?? ''));
    if ($scheduledDate !== '') {
        $where[] = 'DATE(scheduled_start) = :scheduled_date';
        $params[':scheduled_date'] = $scheduledDate;
    }

    $queueDestination = strtolower(trim((string)($data['queue_destination'] ?? '')));
    if ($queueDestination !== '') {
        $where[] = 'queue_destination = :queue_destination';
        $params[':queue_destination'] = $queueDestination;
    }

    $sql = 'SELECT * FROM ehr_appointments';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY scheduled_start ASC, id ASC LIMIT ' . $limit;

    $rows = schedDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    $appointments = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $appointments[] = schedAugmentAppointmentRow($row);
    }

    return ['ok' => true, 'appointments' => $appointments];
}

function scheduling_cap_ehr_appointment_update_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $appointment = schedFetchAppointmentByIdOrUuid((int)($data['id'] ?? 0), trim((string)($data['appointment_uuid'] ?? '')));
    if (!$appointment) {
        return ['ok' => false, 'error' => 'Appointment not found'];
    }

    $patientId = (int)($data['patient_id'] ?? ($appointment['patient_id'] ?? 0));
    $appointmentType = trim((string)($data['appointment_type'] ?? ($appointment['appointment_type'] ?? 'general')));
    $scheduledStart = schedNormalizeDateTime((string)($data['scheduled_start'] ?? (string)($appointment['scheduled_start'] ?? '')));
    $scheduledEnd = schedNormalizeDateTime((string)($data['scheduled_end'] ?? (string)($appointment['scheduled_end'] ?? '')));
    $status = strtolower(trim((string)($data['status'] ?? ($appointment['status'] ?? 'scheduled'))));

    if ($patientId <= 0 || $appointmentType === '' || $scheduledStart === null) {
        return ['ok' => false, 'error' => 'patient_id, appointment_type, and scheduled_start are required'];
    }

    if (!schedAppointmentStatusAllowed($status)) {
        return ['ok' => false, 'error' => 'Unsupported appointment status'];
    }

    if ($scheduledEnd !== null && strtotime($scheduledEnd) < strtotime($scheduledStart)) {
        return ['ok' => false, 'error' => 'scheduled_end must be after scheduled_start'];
    }

    $patient = schedPatientSummary($patientId);
    if (!$patient) {
        return ['ok' => false, 'error' => 'Patient not found'];
    }

    try {
        schedDb()->execute(
            'UPDATE ehr_appointments SET '
            . 'patient_id = :patient_id, appointment_type = :appointment_type, scheduled_start = :scheduled_start, scheduled_end = :scheduled_end, '
            . 'facility_id = :facility_id, department_id = :department_id, location_id = :location_id, reason_for_visit = :reason_for_visit, notes = :notes, updated_at = NOW() '
            . 'WHERE id = :id LIMIT 1',
            [
                ':patient_id' => $patientId,
                ':appointment_type' => $appointmentType,
                ':scheduled_start' => $scheduledStart,
                ':scheduled_end' => $scheduledEnd,
                ':facility_id' => isset($data['facility_id']) ? (int)$data['facility_id'] : (isset($appointment['facility_id']) ? (int)$appointment['facility_id'] : null),
                ':department_id' => isset($data['department_id']) ? (int)$data['department_id'] : (isset($appointment['department_id']) ? (int)$appointment['department_id'] : null),
                ':location_id' => isset($data['location_id']) ? (int)$data['location_id'] : (isset($appointment['location_id']) ? (int)$appointment['location_id'] : null),
                ':reason_for_visit' => trim((string)($data['reason_for_visit'] ?? (string)($appointment['reason_for_visit'] ?? ''))),
                ':notes' => trim((string)($data['notes'] ?? (string)($appointment['notes'] ?? ''))),
                ':id' => (int)$appointment['id'],
            ]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Appointment update failed', 'details' => $e->getMessage()];
    }

    $currentStatus = strtolower(trim((string)($appointment['status'] ?? 'scheduled')));
    $updated = schedFetchAppointmentByIdOrUuid((int)$appointment['id']);
    if ($status !== $currentStatus) {
        $transition = scheduling_cap_ehr_appointment_transition_1([
            'id' => (int)$appointment['id'],
            'status' => $status,
            'service_line' => trim((string)($data['service_line'] ?? 'ambulatory')),
            'attending_provider_id' => isset($data['attending_provider_id']) ? (int)$data['attending_provider_id'] : null,
            'suppress_audit' => true,
        ], $resolvedCapabilityId, $providerId);
        if (!is_array($transition) || empty($transition['ok'])) {
            return $transition;
        }
        $updated = is_array($transition['appointment'] ?? null) ? $transition['appointment'] : schedFetchAppointmentByIdOrUuid((int)$appointment['id']);
    }

    ehcAudit('scheduling', 'ehr.appointment.updated', 'ehr_appointment', (int)$appointment['id'], $updated ?? [], $appointment);
    app()->events()->fire('ehr.appointment.updated', [
        'appointment_id' => (int)$appointment['id'],
        'patient_id' => $patientId,
        'status' => $status,
        'previous_status' => $currentStatus,
        'encounter_id' => isset($updated['encounter_id']) ? (int)$updated['encounter_id'] : null,
    ]);

    return ['ok' => true, 'appointment' => $updated];
}

function scheduling_cap_ehr_appointment_transition_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $appointment = schedFetchAppointmentByIdOrUuid((int)($data['id'] ?? 0), trim((string)($data['appointment_uuid'] ?? '')));
    if (!$appointment) {
        return ['ok' => false, 'error' => 'Appointment not found'];
    }

    $status = strtolower(trim((string)($data['status'] ?? '')));
    if (!schedAppointmentStatusAllowed($status)) {
        return ['ok' => false, 'error' => 'Unsupported appointment status'];
    }

    $currentStatus = strtolower(trim((string)($appointment['status'] ?? 'scheduled')));
    if ($status === $currentStatus) {
        return ['ok' => true, 'appointment' => $appointment];
    }

    $allowed = schedAllowedTransitions();
    if (!in_array($status, $allowed[$currentStatus] ?? [], true)) {
        return ['ok' => false, 'error' => 'Invalid appointment transition'];
    }

    $encounterId = (int)($appointment['encounter_id'] ?? 0);
    if ($status === 'roomed' && $encounterId <= 0) {
        $encounterResult = app()->cap()->call('ehr.encounter.create@1', [
            'patient_id' => (int)$appointment['patient_id'],
            'encounter_type' => 'outpatient',
            'service_line' => trim((string)($data['service_line'] ?? 'ambulatory')),
            'facility_id' => isset($appointment['facility_id']) ? (int)$appointment['facility_id'] : null,
            'department_id' => isset($appointment['department_id']) ? (int)$appointment['department_id'] : null,
            'location_id' => isset($appointment['location_id']) ? (int)$appointment['location_id'] : null,
            'attending_provider_id' => isset($data['attending_provider_id']) ? (int)$data['attending_provider_id'] : null,
            'reason_for_visit' => (string)($appointment['reason_for_visit'] ?? ''),
        ], ['caller_module' => 'scheduling']);

        if (!is_array($encounterResult) || empty($encounterResult['ok'])) {
            return ['ok' => false, 'error' => 'Encounter creation failed for roomed appointment'];
        }

        $encounterId = (int)($encounterResult['encounter']['id'] ?? 0);
    }

    $timestampColumn = schedTimestampColumnForStatus($status);
    $sql = 'UPDATE ehr_appointments SET status = :status, encounter_id = :encounter_id, updated_at = NOW()';
    $params = [
        ':status' => $status,
        ':encounter_id' => $encounterId > 0 ? $encounterId : null,
        ':id' => (int)$appointment['id'],
    ];
    $roomAssignment = trim((string)($data['room_assignment'] ?? ''));

    if ($timestampColumn !== null) {
        $sql .= ', ' . $timestampColumn . ' = COALESCE(' . $timestampColumn . ', NOW())';
    }
    if ($status === 'waiting') {
        $sql .= ', checked_in_at = COALESCE(checked_in_at, NOW())';
    }
    if ($status === 'checked-in') {
        $sql .= ", queue_destination = COALESCE(NULLIF(queue_destination, ''), 'front_desk'), queue_ticket_number = COALESCE(queue_ticket_number, :queue_ticket_number)";
        $params[':queue_ticket_number'] = schedEnsureQueueTicketForAppointment($appointment);
    }
    if ($roomAssignment !== '') {
        $sql .= ', room_assignment = :room_assignment';
        $params[':room_assignment'] = $roomAssignment;
    }
    $sql .= ' WHERE id = :id LIMIT 1';

    try {
        schedDb()->execute($sql, $params);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Appointment transition failed', 'details' => $e->getMessage()];
    }

    $updated = schedFetchAppointmentByIdOrUuid((int)$appointment['id']);
    if (empty($data['suppress_audit'])) {
        ehcAudit('scheduling', 'ehr.appointment.updated', 'ehr_appointment', (int)$appointment['id'], $updated ?? [], $appointment);
        app()->events()->fire('ehr.appointment.updated', [
            'appointment_id' => (int)$appointment['id'],
            'patient_id' => (int)$appointment['patient_id'],
            'status' => $status,
            'previous_status' => $currentStatus,
            'encounter_id' => $encounterId > 0 ? $encounterId : null,
        ]);
    }

    return ['ok' => true, 'appointment' => $updated];
}

function scheduling_cap_entity_list_ehr_appointment_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $result = scheduling_cap_ehr_appointment_list_1([
        'limit' => max(1, min(100, (int)($data['limit'] ?? 25))),
        'status' => trim((string)($data['status'] ?? '')),
        'scheduled_date' => trim((string)($data['scheduled_date'] ?? '')),
        'queue_destination' => trim((string)($data['queue_destination'] ?? '')),
    ], $resolvedCapabilityId, $providerId);

    $rows = is_array($result['appointments'] ?? null) ? array_values($result['appointments']) : [];
    $rows = array_values(array_map(static function (mixed $row): array {
        return schedEntityRow(is_array($row) ? $row : []);
    }, $rows));

    return ['rows' => $rows, 'total' => count($rows)];
}

function scheduling_cap_entity_get_ehr_appointment_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $appointment = schedFetchAppointmentByIdOrUuid(
        (int)($data['id'] ?? $data['entity_id'] ?? 0),
        trim((string)($data['appointment_uuid'] ?? ''))
    );
    if (!is_array($appointment)) {
        return [];
    }

    return schedEntityRow($appointment);
}