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