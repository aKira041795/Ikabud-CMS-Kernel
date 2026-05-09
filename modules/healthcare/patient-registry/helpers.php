<?php
declare(strict_types=1);

function prCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('patient-registry');
    if (!$ctx) {
        throw new \RuntimeException('Patient Registry module context unavailable');
    }

    return $ctx;
}

function prDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return prCtx()->db();
}

function prPatientStatusAllowed(string $status): bool
{
    $result = app()->cap()->call('ehr.core.status.catalog@1', ['domain' => 'patient'], ['caller_module' => 'patient-registry']);
    $statuses = is_array($result) && !empty($result['ok']) && is_array($result['statuses'] ?? null)
        ? $result['statuses']
        : [];

    return in_array($status, $statuses, true);
}

function prFetchPatientByIdOrUuid(int $id = 0, string $patientUuid = ''): ?array
{
    $sql = 'SELECT * FROM ehr_patients WHERE id = :id LIMIT 1';
    $params = [':id' => $id];

    if ($id <= 0 && $patientUuid !== '') {
        $sql = 'SELECT * FROM ehr_patients WHERE patient_uuid = :patient_uuid LIMIT 1';
        $params = [':patient_uuid' => $patientUuid];
    }

    $stmt = prDb()->query($sql, $params);
    $patient = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($patient)) {
        return null;
    }

    $identifiers = prDb()->query(
        'SELECT id, identifier_type, identifier_value, issuing_authority, valid_from, valid_to, is_primary, status '
        . 'FROM ehr_patient_identifiers WHERE patient_id = :patient_id ORDER BY is_primary DESC, id ASC',
        [':patient_id' => (int)$patient['id']]
    )->fetchAll(\PDO::FETCH_ASSOC);

    $patient['identifiers'] = is_array($identifiers) ? $identifiers : [];
    return $patient;
}

function prPotentialDuplicate(array $payload): ?array
{
    $stmt = prDb()->query(
        'SELECT id, patient_uuid, first_name, last_name, birth_date FROM ehr_patients '
        . 'WHERE first_name = :first_name AND last_name = :last_name AND birth_date = :birth_date '
        . 'AND status <> :status LIMIT 1',
        [
            ':first_name' => (string)$payload['first_name'],
            ':last_name' => (string)$payload['last_name'],
            ':birth_date' => (string)$payload['birth_date'],
            ':status' => 'archived',
        ]
    )->fetch(\PDO::FETCH_ASSOC);

    return is_array($stmt) ? $stmt : null;
}

function patient_registry_cap_ehr_patient_create_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $firstName = trim((string)($data['first_name'] ?? ''));
    $lastName = trim((string)($data['last_name'] ?? ''));
    $birthDate = trim((string)($data['birth_date'] ?? ''));
    $status = strtolower(trim((string)($data['status'] ?? 'active')));

    if ($firstName === '' || $lastName === '' || $birthDate === '') {
        return ['ok' => false, 'error' => 'first_name, last_name, and birth_date are required'];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
        return ['ok' => false, 'error' => 'birth_date must use YYYY-MM-DD'];
    }

    if (!prPatientStatusAllowed($status)) {
        return ['ok' => false, 'error' => 'Unsupported patient status'];
    }

    $candidate = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'birth_date' => $birthDate,
    ];
    $duplicate = prPotentialDuplicate($candidate);
    if ($duplicate) {
        return ['ok' => false, 'error' => 'Potential duplicate patient exists', 'duplicate' => $duplicate];
    }

    $patientUuid = ehcGenerateRecordKey('pat');
    $sex = strtolower(trim((string)($data['sex'] ?? 'unknown')));
    $identifiers = is_array($data['identifiers'] ?? null) ? $data['identifiers'] : [];

    prDb()->beginTransaction();
    try {
        prDb()->execute(
            'INSERT INTO ehr_patients '
            . '(patient_uuid, first_name, last_name, middle_name, sex, birth_date, status, primary_phone, email, address_json, created_at, updated_at) '
            . 'VALUES (:patient_uuid, :first_name, :last_name, :middle_name, :sex, :birth_date, :status, :primary_phone, :email, :address_json, NOW(), NOW())',
            [
                ':patient_uuid' => $patientUuid,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':middle_name' => trim((string)($data['middle_name'] ?? '')),
                ':sex' => $sex,
                ':birth_date' => $birthDate,
                ':status' => $status,
                ':primary_phone' => trim((string)($data['primary_phone'] ?? '')),
                ':email' => trim((string)($data['email'] ?? '')),
                ':address_json' => !empty($data['address']) ? json_encode($data['address'], JSON_UNESCAPED_SLASHES) : null,
            ]
        );

        $patientId = (int)prDb()->lastInsertId();
        foreach ($identifiers as $index => $identifier) {
            if (!is_array($identifier)) {
                continue;
            }

            $identifierType = trim((string)($identifier['type'] ?? ''));
            $identifierValue = trim((string)($identifier['value'] ?? ''));
            if ($identifierType === '' || $identifierValue === '') {
                continue;
            }

            prDb()->execute(
                'INSERT INTO ehr_patient_identifiers '
                . '(patient_id, identifier_type, identifier_value, issuing_authority, valid_from, valid_to, is_primary, status, created_at, updated_at) '
                . 'VALUES (:patient_id, :identifier_type, :identifier_value, :issuing_authority, :valid_from, :valid_to, :is_primary, :status, NOW(), NOW())',
                [
                    ':patient_id' => $patientId,
                    ':identifier_type' => $identifierType,
                    ':identifier_value' => $identifierValue,
                    ':issuing_authority' => trim((string)($identifier['issuing_authority'] ?? '')),
                    ':valid_from' => trim((string)($identifier['valid_from'] ?? '')) ?: null,
                    ':valid_to' => trim((string)($identifier['valid_to'] ?? '')) ?: null,
                    ':is_primary' => !empty($identifier['is_primary']) || $index === 0 ? 1 : 0,
                    ':status' => trim((string)($identifier['status'] ?? 'active')) ?: 'active',
                ]
            );
        }

        prDb()->commit();
    } catch (\Throwable $e) {
        if (prDb()->inTransaction()) {
            prDb()->rollBack();
        }
        return ['ok' => false, 'error' => 'Patient creation failed', 'details' => $e->getMessage()];
    }

    $patient = prFetchPatientByIdOrUuid($patientId);
    ehcAudit('patient-registry', 'ehr.patient.created', 'ehr_patient', $patientId, $patient ?? []);
    app()->events()->fire('ehr.patient.created', [
        'patient_id' => $patientId,
        'patient_uuid' => $patientUuid,
    ]);

    return ['ok' => true, 'patient' => $patient];
}

function patient_registry_cap_ehr_patient_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patient = prFetchPatientByIdOrUuid((int)($data['id'] ?? 0), trim((string)($data['patient_uuid'] ?? '')));

    if (!$patient) {
        return ['ok' => false, 'error' => 'Patient not found'];
    }

    return ['ok' => true, 'patient' => $patient];
}

function patient_registry_cap_ehr_patient_search_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $limit = max(1, min(50, (int)($data['limit'] ?? 20)));
    $q = trim((string)($data['q'] ?? ''));
    $params = [':limit' => $limit];
    $sql = 'SELECT id, patient_uuid, first_name, last_name, birth_date, sex, status, primary_phone, email '
        . 'FROM ehr_patients';

    if ($q !== '') {
        $sql .= ' WHERE first_name LIKE :q OR last_name LIKE :q OR patient_uuid LIKE :q OR primary_phone LIKE :q OR email LIKE :q';
        $params[':q'] = '%' . $q . '%';
    }

    $sql .= ' ORDER BY last_name ASC, first_name ASC LIMIT ' . $limit;
    $rows = prDb()->query($sql, $q !== '' ? $params : [])->fetchAll(\PDO::FETCH_ASSOC);

    return ['ok' => true, 'results' => is_array($rows) ? $rows : []];
}