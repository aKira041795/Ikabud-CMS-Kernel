<?php

declare(strict_types=1);

function adtCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('hospital-adt');
    if (!$ctx) {
        throw new \RuntimeException('Hospital ADT module context unavailable');
    }
    return $ctx;
}

function adtDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return adtCtx()->db();
}

function adtGenerateUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function adtAudit(string $action, array $payload): void
{
    try {
        app()->cap()->call(
            'kernel.audit.record@1',
            array_merge(['action' => $action, 'actor_source' => 'hospital-adt'], $payload),
            ['caller_module' => 'hospital-adt']
        );
    } catch (\Throwable $e) {
        write_log('adt audit failed: ' . $e->getMessage(), 'warn');
    }
}

function adtFetchWard(int $id): ?array
{
    $row = adtDb()->query('SELECT * FROM ehr_wards WHERE id = :id LIMIT 1', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function adtFetchBed(int $id): ?array
{
    $row = adtDb()->query('SELECT * FROM ehr_beds WHERE id = :id LIMIT 1', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function adtFetchAdmission(int $id = 0, string $uuid = ''): ?array
{
    if ($id > 0) {
        $row = adtDb()->query('SELECT * FROM ehr_admissions WHERE id = :id LIMIT 1', [':id' => $id])->fetch(\PDO::FETCH_ASSOC);
    } elseif ($uuid !== '') {
        $row = adtDb()->query('SELECT * FROM ehr_admissions WHERE admission_uuid = :u LIMIT 1', [':u' => $uuid])->fetch(\PDO::FETCH_ASSOC);
    } else {
        return null;
    }
    return is_array($row) ? $row : null;
}

function adtSetBedStatus(int $bedId, string $status): void
{
    if ($bedId <= 0) {
        return;
    }
    adtDb()->execute('UPDATE ehr_beds SET status = :s, updated_at = NOW() WHERE id = :id', [':s' => $status, ':id' => $bedId]);
}

function hospital_adt_cap_ehr_adt_ward_list_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $rows = adtDb()->query('SELECT * FROM ehr_wards WHERE active_flag = 1 ORDER BY name ASC')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    return ['ok' => true, 'wards' => $rows];
}

function hospital_adt_cap_ehr_adt_bed_list_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $where = [];
    $params = [];
    if (!empty($data['ward_id'])) {
        $where[] = 'ward_id = :wid';
        $params[':wid'] = (int)$data['ward_id'];
    }
    if (!empty($data['status'])) {
        $where[] = 'status = :st';
        $params[':st'] = (string)$data['status'];
    }
    $sql = 'SELECT * FROM ehr_beds';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ward_id ASC, code ASC';
    $rows = adtDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    return ['ok' => true, 'beds' => $rows];
}

function hospital_adt_cap_ehr_adt_admit_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $wardId = (int)($data['ward_id'] ?? 0);
    $bedId = (int)($data['bed_id'] ?? 0);
    $admittedAt = trim((string)($data['admitted_at'] ?? '')) ?: date('Y-m-d H:i:s');
    $attendingId = (int)($data['attending_user_id'] ?? 0);
    $notes = trim((string)($data['notes'] ?? ''));

    if ($patientId <= 0 || $wardId <= 0) {
        return ['ok' => false, 'error' => 'patient_id and ward_id are required'];
    }
    $patientLookup = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'hospital-adt']);
    if (!is_array($patientLookup) || empty($patientLookup['ok'])) {
        return ['ok' => false, 'error' => 'Patient not found'];
    }
    if (!adtFetchWard($wardId)) {
        return ['ok' => false, 'error' => 'Ward not found'];
    }
    if ($bedId > 0) {
        $bed = adtFetchBed($bedId);
        if (!$bed || (int)$bed['ward_id'] !== $wardId) {
            return ['ok' => false, 'error' => 'Bed not in ward'];
        }
        if ((string)$bed['status'] !== 'available') {
            return ['ok' => false, 'error' => 'Bed not available'];
        }
    }

    $uuid = adtGenerateUuid();
    adtDb()->execute(
        'INSERT INTO ehr_admissions (admission_uuid, patient_id, ward_id, bed_id, status, admitted_at, attending_user_id, notes) '
        . 'VALUES (:uuid, :pid, :wid, :bid, :status, :admitted, :att, :notes)',
        [
            ':uuid' => $uuid,
            ':pid' => $patientId,
            ':wid' => $wardId,
            ':bid' => $bedId > 0 ? $bedId : null,
            ':status' => 'admitted',
            ':admitted' => $admittedAt,
            ':att' => $attendingId > 0 ? $attendingId : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]
    );
    $admissionId = (int)adtDb()->lastInsertId();
    if ($bedId > 0) {
        adtSetBedStatus($bedId, 'occupied');
    }
    adtDb()->execute(
        'INSERT INTO ehr_adt_events (admission_id, event_type, to_bed_id, occurred_at, performed_by_user_id, notes) '
        . 'VALUES (:aid, :et, :tb, :occurred, :perf, :notes)',
        [
            ':aid' => $admissionId,
            ':et' => 'admit',
            ':tb' => $bedId > 0 ? $bedId : null,
            ':occurred' => $admittedAt,
            ':perf' => $attendingId > 0 ? $attendingId : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]
    );

    $admission = adtFetchAdmission($admissionId);
    adtAudit('ehr.adt.admitted', [
        'patient_id' => $patientId,
        'new_data' => ['admission_uuid' => $uuid, 'ward_id' => $wardId, 'bed_id' => $bedId],
    ]);
    app()->events()->fire('ehr.adt.admitted', ['admission_id' => $admissionId, 'patient_id' => $patientId]);

    return ['ok' => true, 'admission' => $admission];
}

function hospital_adt_cap_ehr_adt_transfer_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $admissionId = (int)($data['admission_id'] ?? 0);
    $toBedId = (int)($data['to_bed_id'] ?? 0);
    $occurredAt = trim((string)($data['occurred_at'] ?? '')) ?: date('Y-m-d H:i:s');
    $perfId = (int)($data['performed_by_user_id'] ?? 0);
    $notes = trim((string)($data['notes'] ?? ''));

    $admission = adtFetchAdmission($admissionId);
    if (!$admission) {
        return ['ok' => false, 'error' => 'Admission not found'];
    }
    if ((string)$admission['status'] !== 'admitted') {
        return ['ok' => false, 'error' => 'Admission is not active'];
    }
    if ($toBedId <= 0) {
        return ['ok' => false, 'error' => 'to_bed_id is required'];
    }
    $toBed = adtFetchBed($toBedId);
    if (!$toBed || (string)$toBed['status'] !== 'available') {
        return ['ok' => false, 'error' => 'Target bed not available'];
    }

    $fromBedId = isset($admission['bed_id']) ? (int)$admission['bed_id'] : 0;

    adtDb()->execute(
        'UPDATE ehr_admissions SET bed_id = :bid, ward_id = :wid, updated_at = NOW() WHERE id = :id',
        [':bid' => $toBedId, ':wid' => (int)$toBed['ward_id'], ':id' => $admissionId]
    );
    if ($fromBedId > 0) {
        adtSetBedStatus($fromBedId, 'available');
    }
    adtSetBedStatus($toBedId, 'occupied');

    adtDb()->execute(
        'INSERT INTO ehr_adt_events (admission_id, event_type, from_bed_id, to_bed_id, occurred_at, performed_by_user_id, notes) '
        . 'VALUES (:aid, :et, :fb, :tb, :occurred, :perf, :notes)',
        [
            ':aid' => $admissionId,
            ':et' => 'transfer',
            ':fb' => $fromBedId > 0 ? $fromBedId : null,
            ':tb' => $toBedId,
            ':occurred' => $occurredAt,
            ':perf' => $perfId > 0 ? $perfId : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]
    );
    adtAudit('ehr.adt.transferred', [
        'patient_id' => (int)$admission['patient_id'],
        'old_data' => ['bed_id' => $fromBedId],
        'new_data' => ['bed_id' => $toBedId],
    ]);
    app()->events()->fire('ehr.adt.transferred', ['admission_id' => $admissionId]);

    return ['ok' => true, 'admission' => adtFetchAdmission($admissionId)];
}

function hospital_adt_cap_ehr_adt_discharge_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $admissionId = (int)($data['admission_id'] ?? 0);
    $disposition = trim((string)($data['discharge_disposition'] ?? 'home'));
    $occurredAt = trim((string)($data['occurred_at'] ?? '')) ?: date('Y-m-d H:i:s');
    $perfId = (int)($data['performed_by_user_id'] ?? 0);
    $notes = trim((string)($data['notes'] ?? ''));

    $admission = adtFetchAdmission($admissionId);
    if (!$admission) {
        return ['ok' => false, 'error' => 'Admission not found'];
    }
    if ((string)$admission['status'] !== 'admitted') {
        return ['ok' => false, 'error' => 'Admission already discharged'];
    }

    adtDb()->execute(
        'UPDATE ehr_admissions SET status = :st, discharged_at = :da, discharge_disposition = :dd, updated_at = NOW() WHERE id = :id',
        [':st' => 'discharged', ':da' => $occurredAt, ':dd' => $disposition, ':id' => $admissionId]
    );
    $bedId = isset($admission['bed_id']) ? (int)$admission['bed_id'] : 0;
    if ($bedId > 0) {
        adtSetBedStatus($bedId, 'available');
    }

    adtDb()->execute(
        'INSERT INTO ehr_adt_events (admission_id, event_type, from_bed_id, occurred_at, performed_by_user_id, notes) '
        . 'VALUES (:aid, :et, :fb, :occurred, :perf, :notes)',
        [
            ':aid' => $admissionId,
            ':et' => 'discharge',
            ':fb' => $bedId > 0 ? $bedId : null,
            ':occurred' => $occurredAt,
            ':perf' => $perfId > 0 ? $perfId : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]
    );
    adtAudit('ehr.adt.discharged', [
        'patient_id' => (int)$admission['patient_id'],
        'new_data' => ['discharge_disposition' => $disposition],
    ]);
    app()->events()->fire('ehr.adt.discharged', ['admission_id' => $admissionId]);

    return ['ok' => true, 'admission' => adtFetchAdmission($admissionId)];
}

function hospital_adt_cap_ehr_adt_admission_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $admission = adtFetchAdmission((int)($data['id'] ?? 0), trim((string)($data['admission_uuid'] ?? '')));
    if (!$admission) {
        return ['ok' => false, 'error' => 'Admission not found'];
    }
    return ['ok' => true, 'admission' => $admission];
}

function hospital_adt_cap_ehr_adt_admission_list_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $where = [];
    $params = [];
    if (!empty($data['patient_id'])) {
        $where[] = 'patient_id = :pid';
        $params[':pid'] = (int)$data['patient_id'];
    }
    if (!empty($data['status'])) {
        $where[] = 'status = :st';
        $params[':st'] = (string)$data['status'];
    }
    $limit = max(1, min(200, (int)($data['limit'] ?? 50)));
    $sql = 'SELECT * FROM ehr_admissions';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY admitted_at DESC, id DESC LIMIT ' . $limit;
    $rows = adtDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    return ['ok' => true, 'admissions' => $rows];
}
