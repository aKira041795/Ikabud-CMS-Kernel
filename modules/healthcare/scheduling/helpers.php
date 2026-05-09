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

    $appointment['patient_summary'] = schedPatientSummary((int)($appointment['patient_id'] ?? 0));
    return $appointment;
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
        $row['patient_summary'] = schedPatientSummary((int)($row['patient_id'] ?? 0));
        $appointments[] = $row;
    }

    return ['ok' => true, 'appointments' => $appointments];
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

    if ($timestampColumn !== null) {
        $sql .= ', ' . $timestampColumn . ' = COALESCE(' . $timestampColumn . ', NOW())';
    }
    if ($status === 'waiting') {
        $sql .= ', checked_in_at = COALESCE(checked_in_at, NOW())';
    }
    $sql .= ' WHERE id = :id LIMIT 1';

    try {
        schedDb()->execute($sql, $params);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Appointment transition failed', 'details' => $e->getMessage()];
    }

    $updated = schedFetchAppointmentByIdOrUuid((int)$appointment['id']);
    ehcAudit('scheduling', 'ehr.appointment.updated', 'ehr_appointment', (int)$appointment['id'], $updated ?? [], $appointment);
    app()->events()->fire('ehr.appointment.updated', [
        'appointment_id' => (int)$appointment['id'],
        'patient_id' => (int)$appointment['patient_id'],
        'status' => $status,
        'previous_status' => $currentStatus,
        'encounter_id' => $encounterId > 0 ? $encounterId : null,
    ]);

    return ['ok' => true, 'appointment' => $updated];
}