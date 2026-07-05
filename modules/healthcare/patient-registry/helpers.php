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

function prPotentialDuplicate(array $payload, int $excludeId = 0): ?array
{
    $sql = 'SELECT id, patient_uuid, first_name, last_name, birth_date FROM ehr_patients '
        . 'WHERE first_name = :first_name AND last_name = :last_name AND birth_date = :birth_date '
        . 'AND status <> :status';
    $params = [
        ':first_name' => (string)$payload['first_name'],
        ':last_name' => (string)$payload['last_name'],
        ':birth_date' => (string)$payload['birth_date'],
        ':status' => 'archived',
    ];
    if ($excludeId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $sql .= ' LIMIT 1';

    $stmt = prDb()->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);

    return is_array($stmt) ? $stmt : null;
}

function prUpsertPrimaryIdentifier(int $patientId, array $identifier): void
{
    $identifierType = trim((string)($identifier['type'] ?? ''));
    $identifierValue = trim((string)($identifier['value'] ?? ''));
    if ($patientId <= 0 || $identifierType === '' || $identifierValue === '') {
        return;
    }

    $existing = prDb()->query(
        'SELECT id FROM ehr_patient_identifiers WHERE patient_id = :patient_id ORDER BY is_primary DESC, id ASC LIMIT 1',
        [':patient_id' => $patientId]
    )->fetch(\PDO::FETCH_ASSOC);

    if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
        prDb()->execute(
            'UPDATE ehr_patient_identifiers SET '
            . 'identifier_type = :identifier_type, identifier_value = :identifier_value, issuing_authority = :issuing_authority, '
            . 'valid_from = :valid_from, valid_to = :valid_to, status = :status, is_primary = 1, updated_at = NOW() '
            . 'WHERE id = :id LIMIT 1',
            [
                ':identifier_type' => $identifierType,
                ':identifier_value' => $identifierValue,
                ':issuing_authority' => trim((string)($identifier['issuing_authority'] ?? '')),
                ':valid_from' => trim((string)($identifier['valid_from'] ?? '')) ?: null,
                ':valid_to' => trim((string)($identifier['valid_to'] ?? '')) ?: null,
                ':status' => trim((string)($identifier['status'] ?? 'active')) ?: 'active',
                ':id' => (int)$existing['id'],
            ]
        );
        return;
    }

    prDb()->execute(
        'INSERT INTO ehr_patient_identifiers '
        . '(patient_id, identifier_type, identifier_value, issuing_authority, valid_from, valid_to, is_primary, status, created_at, updated_at) '
        . 'VALUES (:patient_id, :identifier_type, :identifier_value, :issuing_authority, :valid_from, :valid_to, 1, :status, NOW(), NOW())',
        [
            ':patient_id' => $patientId,
            ':identifier_type' => $identifierType,
            ':identifier_value' => $identifierValue,
            ':issuing_authority' => trim((string)($identifier['issuing_authority'] ?? '')),
            ':valid_from' => trim((string)($identifier['valid_from'] ?? '')) ?: null,
            ':valid_to' => trim((string)($identifier['valid_to'] ?? '')) ?: null,
            ':status' => trim((string)($identifier['status'] ?? 'active')) ?: 'active',
        ]
    );
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

function patient_registry_cap_ehr_patient_update_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patient = prFetchPatientByIdOrUuid((int)($data['id'] ?? 0), trim((string)($data['patient_uuid'] ?? '')));
    if (!$patient) {
        return ['ok' => false, 'error' => 'Patient not found'];
    }

    $firstName = trim((string)($data['first_name'] ?? ($patient['first_name'] ?? '')));
    $lastName = trim((string)($data['last_name'] ?? ($patient['last_name'] ?? '')));
    $birthDate = trim((string)($data['birth_date'] ?? ($patient['birth_date'] ?? '')));
    $status = strtolower(trim((string)($data['status'] ?? ($patient['status'] ?? 'active'))));

    if ($firstName === '' || $lastName === '' || $birthDate === '') {
        return ['ok' => false, 'error' => 'first_name, last_name, and birth_date are required'];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
        return ['ok' => false, 'error' => 'birth_date must use YYYY-MM-DD'];
    }

    if (!prPatientStatusAllowed($status)) {
        return ['ok' => false, 'error' => 'Unsupported patient status'];
    }

    $duplicate = prPotentialDuplicate([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'birth_date' => $birthDate,
    ], (int)$patient['id']);
    if ($duplicate) {
        return ['ok' => false, 'error' => 'Potential duplicate patient exists', 'duplicate' => $duplicate];
    }

    prDb()->beginTransaction();
    try {
        prDb()->execute(
            'UPDATE ehr_patients SET '
            . 'first_name = :first_name, last_name = :last_name, middle_name = :middle_name, sex = :sex, birth_date = :birth_date, status = :status, '
            . 'primary_phone = :primary_phone, email = :email, address_json = :address_json, updated_at = NOW() '
            . 'WHERE id = :id LIMIT 1',
            [
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':middle_name' => trim((string)($data['middle_name'] ?? ($patient['middle_name'] ?? ''))),
                ':sex' => strtolower(trim((string)($data['sex'] ?? ($patient['sex'] ?? 'unknown')))),
                ':birth_date' => $birthDate,
                ':status' => $status,
                ':primary_phone' => trim((string)($data['primary_phone'] ?? ($patient['primary_phone'] ?? ''))),
                ':email' => trim((string)($data['email'] ?? ($patient['email'] ?? ''))),
                ':address_json' => !empty($data['address']) ? json_encode($data['address'], JSON_UNESCAPED_SLASHES) : ($patient['address_json'] ?? null),
                ':id' => (int)$patient['id'],
            ]
        );

        prUpsertPrimaryIdentifier((int)$patient['id'], [
            'type' => trim((string)($data['identifier_type'] ?? '')),
            'value' => trim((string)($data['identifier_value'] ?? '')),
            'issuing_authority' => trim((string)($data['identifier_issuing_authority'] ?? '')),
            'status' => 'active',
        ]);

        prDb()->commit();
    } catch (\Throwable $e) {
        if (prDb()->inTransaction()) {
            prDb()->rollBack();
        }
        return ['ok' => false, 'error' => 'Patient update failed', 'details' => $e->getMessage()];
    }

    $updated = prFetchPatientByIdOrUuid((int)$patient['id']);
    ehcAudit('patient-registry', 'ehr.patient.updated', 'ehr_patient', (int)$patient['id'], $updated ?? [], $patient);
    app()->events()->fire('ehr.patient.updated', [
        'patient_id' => (int)$patient['id'],
        'patient_uuid' => (string)($patient['patient_uuid'] ?? ''),
    ]);

    return ['ok' => true, 'patient' => $updated];
}

function patient_registry_cap_ehr_patient_search_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $limit = max(1, min(50, (int)($data['limit'] ?? 20)));
    $q = trim((string)($data['q'] ?? ''));
    $params = [];
    $sql = 'SELECT id, patient_uuid, first_name, last_name, birth_date, sex, status, primary_phone, email '
        . 'FROM ehr_patients';

    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql .= ' WHERE first_name LIKE :q_first OR last_name LIKE :q_last OR patient_uuid LIKE :q_uuid OR primary_phone LIKE :q_phone OR email LIKE :q_email';
        $params = [
            ':q_first' => $like,
            ':q_last' => $like,
            ':q_uuid' => $like,
            ':q_phone' => $like,
            ':q_email' => $like,
        ];
    }

    $sql .= ' ORDER BY last_name ASC, first_name ASC LIMIT ' . $limit;
    $rows = prDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

    return ['ok' => true, 'results' => is_array($rows) ? $rows : []];
}

function patient_registry_cap_entity_list_ehr_patient_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $search = patient_registry_cap_ehr_patient_search_1([
        'q' => trim((string)($data['q'] ?? '')),
        'limit' => max(1, min(100, (int)($data['limit'] ?? 25))),
    ], $resolvedCapabilityId, $providerId);

    $rows = is_array($search['results'] ?? null) ? array_values($search['results']) : [];
    $status = strtolower(trim((string)($data['status'] ?? '')));
    if ($status !== '') {
        $rows = array_values(array_filter($rows, static function (mixed $row) use ($status): bool {
            return is_array($row) && strtolower((string)($row['status'] ?? '')) === $status;
        }));
    }

    return ['rows' => $rows, 'total' => count($rows)];
}

function patient_registry_cap_entity_get_ehr_patient_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patient = prFetchPatientByIdOrUuid(
        (int)($data['id'] ?? $data['entity_id'] ?? 0),
        trim((string)($data['patient_uuid'] ?? ''))
    );

    return is_array($patient) ? $patient : [];
}