<?php
declare(strict_types=1);

function encCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('encounters');
    if (!$ctx) {
        throw new \RuntimeException('Encounters module context unavailable');
    }

    return $ctx;
}

function encDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return encCtx()->db();
}

function encEncounterStatusAllowed(string $status): bool
{
    $result = app()->cap()->call('ehr.core.status.catalog@1', ['domain' => 'encounter'], ['caller_module' => 'encounters']);
    $statuses = is_array($result) && !empty($result['ok']) && is_array($result['statuses'] ?? null)
        ? $result['statuses']
        : [];

    return in_array($status, $statuses, true);
}

function encFetchEncounterByIdOrUuid(int $id = 0, string $encounterUuid = ''): ?array
{
    $sql = 'SELECT * FROM ehr_encounters WHERE id = :id LIMIT 1';
    $params = [':id' => $id];

    if ($id <= 0 && $encounterUuid !== '') {
        $sql = 'SELECT * FROM ehr_encounters WHERE encounter_uuid = :encounter_uuid LIMIT 1';
        $params = [':encounter_uuid' => $encounterUuid];
    }

    $encounter = encDb()->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($encounter)) {
        return null;
    }

    $vitals = encDb()->query(
        'SELECT * FROM ehr_vitals WHERE encounter_id = :encounter_id ORDER BY captured_at DESC, id DESC',
        [':encounter_id' => (int)$encounter['id']]
    )->fetchAll(\PDO::FETCH_ASSOC);

    $encounter['vitals'] = is_array($vitals) ? $vitals : [];
    return $encounter;
}

function encListRecentEncounters(string $status = '', int $limit = 25): array
{
    $limit = max(1, min(50, $limit));
    $params = [];
    $sql = 'SELECT id, encounter_uuid, patient_id, encounter_type, service_line, start_at, end_at, status, reason_for_visit '
        . 'FROM ehr_encounters';

    if ($status !== '' && encEncounterStatusAllowed($status)) {
        $sql .= ' WHERE status = :status';
        $params[':status'] = $status;
    }

    $sql .= ' ORDER BY start_at DESC, id DESC LIMIT ' . $limit;
    $rows = encDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function encHydrateEncounterPatients(array $encounters): array
{
    foreach ($encounters as &$encounter) {
        if (!is_array($encounter)) {
            continue;
        }

        $patientId = (int)($encounter['patient_id'] ?? 0);
        $encounter['patient_summary'] = null;
        if ($patientId <= 0) {
            continue;
        }

        $patientResult = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'encounters']);
        if (!is_array($patientResult) || empty($patientResult['ok']) || !is_array($patientResult['patient'] ?? null)) {
            continue;
        }

        $patient = $patientResult['patient'];
        $encounter['patient_summary'] = [
            'id' => (int)($patient['id'] ?? 0),
            'patient_uuid' => (string)($patient['patient_uuid'] ?? ''),
            'first_name' => (string)($patient['first_name'] ?? ''),
            'last_name' => (string)($patient['last_name'] ?? ''),
            'birth_date' => (string)($patient['birth_date'] ?? ''),
        ];
    }
    unset($encounter);

    return $encounters;
}

function encEntityRow(array $encounter): array
{
    $patientSummary = is_array($encounter['patient_summary'] ?? null) ? $encounter['patient_summary'] : [];
    $patientName = trim((string)($patientSummary['last_name'] ?? ''));
    if ($patientName !== '') {
        $patientName .= ', ';
    }
    $patientName .= trim((string)($patientSummary['first_name'] ?? ''));

    $encounter['patient_name'] = trim($patientName, ', ');
    $encounter['encounter_started_at'] = (string)($encounter['start_at'] ?? '');
    $encounter['encounter_ended_at'] = (string)($encounter['end_at'] ?? '');

    return $encounter;
}

function encounters_cap_ehr_encounter_create_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $encounterType = trim((string)($data['encounter_type'] ?? 'outpatient'));
    $status = strtolower(trim((string)($data['status'] ?? 'open')));

    if ($patientId <= 0) {
        return ['ok' => false, 'error' => 'patient_id is required'];
    }

    if (!encEncounterStatusAllowed($status)) {
        return ['ok' => false, 'error' => 'Unsupported encounter status'];
    }

    $patient = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'encounters']);
    if (!is_array($patient) || empty($patient['ok'])) {
        return ['ok' => false, 'error' => 'Patient not found'];
    }

    $encounterUuid = ehcGenerateRecordKey('enc');
    try {
        encDb()->execute(
            'INSERT INTO ehr_encounters '
            . '(encounter_uuid, patient_id, encounter_type, service_line, facility_id, department_id, location_id, attending_provider_id, start_at, end_at, status, reason_for_visit, created_at, updated_at) '
            . 'VALUES (:encounter_uuid, :patient_id, :encounter_type, :service_line, :facility_id, :department_id, :location_id, :attending_provider_id, NOW(), NULL, :status, :reason_for_visit, NOW(), NOW())',
            [
                ':encounter_uuid' => $encounterUuid,
                ':patient_id' => $patientId,
                ':encounter_type' => $encounterType,
                ':service_line' => trim((string)($data['service_line'] ?? 'ambulatory')),
                ':facility_id' => isset($data['facility_id']) ? (int)$data['facility_id'] : null,
                ':department_id' => isset($data['department_id']) ? (int)$data['department_id'] : null,
                ':location_id' => isset($data['location_id']) ? (int)$data['location_id'] : null,
                ':attending_provider_id' => isset($data['attending_provider_id']) ? (int)$data['attending_provider_id'] : null,
                ':status' => $status,
                ':reason_for_visit' => trim((string)($data['reason_for_visit'] ?? '')),
            ]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Encounter creation failed', 'details' => $e->getMessage()];
    }

    $encounterId = (int)encDb()->lastInsertId();
    $encounter = encFetchEncounterByIdOrUuid($encounterId);
    ehcAudit('encounters', 'ehr.encounter.started', 'ehr_encounter', $encounterId, $encounter ?? []);
    app()->events()->fire('ehr.encounter.started', [
        'encounter_id' => $encounterId,
        'encounter_uuid' => $encounterUuid,
        'patient_id' => $patientId,
    ]);

    return ['ok' => true, 'encounter' => $encounter];
}

function encounters_cap_ehr_encounter_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $encounter = encFetchEncounterByIdOrUuid((int)($data['id'] ?? 0), trim((string)($data['encounter_uuid'] ?? '')));
    if (!$encounter) {
        return ['ok' => false, 'error' => 'Encounter not found'];
    }

    return ['ok' => true, 'encounter' => $encounter];
}

function encounters_cap_ehr_encounter_list_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $status = trim((string)($data['status'] ?? ''));
    $limit = max(1, min(50, (int)($data['limit'] ?? 25)));

    $encounters = encHydrateEncounterPatients(encListRecentEncounters($status, $limit));
    return ['ok' => true, 'encounters' => $encounters];
}

function encounters_cap_ehr_vitals_record_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $encounterId = (int)($data['encounter_id'] ?? 0);
    if ($encounterId <= 0) {
        return ['ok' => false, 'error' => 'encounter_id is required'];
    }

    $encounter = encFetchEncounterByIdOrUuid($encounterId);
    if (!$encounter) {
        return ['ok' => false, 'error' => 'Encounter not found'];
    }

    try {
        encDb()->execute(
            'INSERT INTO ehr_vitals '
            . '(patient_id, encounter_id, captured_by_user_id, captured_at, height_cm, weight_kg, bmi, temperature_c, systolic_bp, diastolic_bp, pulse_bpm, respiratory_rate, spo2, pain_score, notes, created_at, updated_at) '
            . 'VALUES (:patient_id, :encounter_id, :captured_by_user_id, NOW(), :height_cm, :weight_kg, :bmi, :temperature_c, :systolic_bp, :diastolic_bp, :pulse_bpm, :respiratory_rate, :spo2, :pain_score, :notes, NOW(), NOW())',
            [
                ':patient_id' => (int)$encounter['patient_id'],
                ':encounter_id' => $encounterId,
                ':captured_by_user_id' => isset($data['captured_by_user_id']) ? (int)$data['captured_by_user_id'] : null,
                ':height_cm' => isset($data['height_cm']) ? (float)$data['height_cm'] : null,
                ':weight_kg' => isset($data['weight_kg']) ? (float)$data['weight_kg'] : null,
                ':bmi' => isset($data['bmi']) ? (float)$data['bmi'] : null,
                ':temperature_c' => isset($data['temperature_c']) ? (float)$data['temperature_c'] : null,
                ':systolic_bp' => isset($data['systolic_bp']) ? (int)$data['systolic_bp'] : null,
                ':diastolic_bp' => isset($data['diastolic_bp']) ? (int)$data['diastolic_bp'] : null,
                ':pulse_bpm' => isset($data['pulse_bpm']) ? (int)$data['pulse_bpm'] : null,
                ':respiratory_rate' => isset($data['respiratory_rate']) ? (int)$data['respiratory_rate'] : null,
                ':spo2' => isset($data['spo2']) ? (float)$data['spo2'] : null,
                ':pain_score' => isset($data['pain_score']) ? (int)$data['pain_score'] : null,
                ':notes' => trim((string)($data['notes'] ?? '')),
            ]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Vital recording failed', 'details' => $e->getMessage()];
    }

    $vitalId = (int)encDb()->lastInsertId();
    $vital = encDb()->query('SELECT * FROM ehr_vitals WHERE id = :id LIMIT 1', [':id' => $vitalId])->fetch(\PDO::FETCH_ASSOC);
    ehcAudit('encounters', 'ehr.vitals.recorded', 'ehr_vital', $vitalId, is_array($vital) ? $vital : []);
    app()->events()->fire('ehr.vitals.recorded', [
        'vital_id' => $vitalId,
        'encounter_id' => $encounterId,
        'patient_id' => (int)$encounter['patient_id'],
    ]);

    return ['ok' => true, 'vital' => $vital];
}
function encounters_cap_ehr_encounter_close_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $encounterId = (int)($data['encounter_id'] ?? $data['id'] ?? 0);
    $encounterUuid = trim((string)($data['encounter_uuid'] ?? ''));

    $encounter = encFetchEncounterByIdOrUuid($encounterId, $encounterUuid);
    if (!is_array($encounter)) {
        return ['ok' => false, 'error' => 'Encounter not found'];
    }

    $currentStatus = strtolower((string)($encounter['status'] ?? ''));
    if ($currentStatus === 'completed') {
        return ['ok' => true, 'encounter' => $encounter, 'idempotent' => true];
    }
    if (!in_array($currentStatus, ['open', 'in-progress', 'in_progress'], true)) {
        return ['ok' => false, 'error' => 'Encounter is not in a closeable state'];
    }
    if (!encEncounterStatusAllowed('completed')) {
        return ['ok' => false, 'error' => 'Closed status is not allowed by status catalog'];
    }

    try {
        encDb()->execute(
            'UPDATE ehr_encounters SET status = :status, end_at = COALESCE(end_at, NOW()), updated_at = NOW() WHERE id = :id',
            [':status' => 'completed', ':id' => (int)$encounter['id']]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Encounter close failed', 'details' => $e->getMessage()];
    }

    $closed = encFetchEncounterByIdOrUuid((int)$encounter['id']);
    ehcAudit('encounters', 'ehr.encounter.closed', 'ehr_encounter', (int)$encounter['id'], $closed ?? [], $encounter);
    app()->events()->fire('ehr.encounter.closed', [
        'encounter_id' => (int)$encounter['id'],
        'encounter_uuid' => (string)($encounter['encounter_uuid'] ?? ''),
        'patient_id' => (int)($encounter['patient_id'] ?? 0),
        'closed_by' => isset($data['actor_user_id']) ? (int)$data['actor_user_id'] : null,
    ]);

    return ['ok' => true, 'encounter' => $closed];
}

function encounters_cap_ehr_encounter_progress_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $encounterId = (int)($data['encounter_id'] ?? $data['id'] ?? 0);
    $encounterUuid = trim((string)($data['encounter_uuid'] ?? ''));

    $encounter = encFetchEncounterByIdOrUuid($encounterId, $encounterUuid);
    if (!is_array($encounter)) {
        return ['ok' => false, 'error' => 'Encounter not found'];
    }

    $stages = [
        ['key' => 'scheduled',    'label' => 'Scheduled'],
        ['key' => 'checked-in',   'label' => 'Checked In'],
        ['key' => 'waiting',      'label' => 'Waiting'],
        ['key' => 'roomed',       'label' => 'Roomed'],
        ['key' => 'in-progress',  'label' => 'In Progress'],
        ['key' => 'completed',    'label' => 'Completed'],
    ];

    $current = strtolower((string)($encounter['status'] ?? 'open'));
    $aliasMap = ['open' => 'in-progress', 'in_progress' => 'in-progress', 'cancelled' => 'completed'];
    $current = $aliasMap[$current] ?? $current;

    $currentIndex = -1;
    foreach ($stages as $i => $stage) {
        if ($stage['key'] === $current) {
            $currentIndex = $i;
            break;
        }
    }

    foreach ($stages as $i => &$stage) {
        if ($currentIndex < 0) {
            $stage['state'] = 'pending';
        } elseif ($i < $currentIndex) {
            $stage['state'] = 'done';
        } elseif ($i === $currentIndex) {
            $stage['state'] = 'current';
        } else {
            $stage['state'] = 'pending';
        }
    }
    unset($stage);

    $startedAt = (string)($encounter['start_at'] ?? '');
    $endedAt = (string)($encounter['end_at'] ?? '');
    $durationMinutes = null;
    if ($startedAt !== '') {
        $start = strtotime($startedAt);
        $end = $endedAt !== '' ? strtotime($endedAt) : time();
        if ($start !== false && $end !== false && $end >= $start) {
            $durationMinutes = (int)floor(($end - $start) / 60);
        }
    }

    return [
        'ok' => true,
        'progress' => [
            'encounter_id' => (int)$encounter['id'],
            'encounter_uuid' => (string)($encounter['encounter_uuid'] ?? ''),
            'patient_id' => (int)($encounter['patient_id'] ?? 0),
            'stages' => $stages,
            'current' => $current,
            'current_index' => $currentIndex,
            'started_at' => $startedAt !== '' ? $startedAt : null,
            'ended_at' => $endedAt !== '' ? $endedAt : null,
            'duration_minutes' => $durationMinutes,
        ],
    ];
}

function encounters_cap_entity_list_ehr_encounter_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $status = strtolower(trim((string)($data['status'] ?? '')));
    $limit = max(1, min(100, (int)($data['limit'] ?? 25)));

    $rows = encHydrateEncounterPatients(encListRecentEncounters($status, $limit));
    $rows = array_values(array_map(static function (mixed $row): array {
        return encEntityRow(is_array($row) ? $row : []);
    }, $rows));

    return ['rows' => $rows, 'total' => count($rows)];
}

function encounters_cap_entity_get_ehr_encounter_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $encounter = encFetchEncounterByIdOrUuid(
        (int)($data['id'] ?? $data['entity_id'] ?? 0),
        trim((string)($data['encounter_uuid'] ?? ''))
    );
    if (!is_array($encounter)) {
        return [];
    }

    $hydrated = encHydrateEncounterPatients([$encounter]);
    return encEntityRow(is_array($hydrated[0] ?? null) ? $hydrated[0] : $encounter);
}
