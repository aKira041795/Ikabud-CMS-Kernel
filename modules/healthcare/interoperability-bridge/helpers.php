<?php

declare(strict_types=1);

function ibCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('interoperability-bridge');
    if (!$ctx) {
        throw new \RuntimeException('Interoperability Bridge module context unavailable');
    }
    return $ctx;
}

function ibDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return ibCtx()->db();
}

function ibAudit(string $action, array $payload): void
{
    try {
        app()->cap()->call(
            'kernel.audit.record@1',
            array_merge(['action' => $action, 'actor_source' => 'interoperability-bridge'], $payload),
            ['caller_module' => 'interoperability-bridge']
        );
    } catch (\Throwable $e) {
        write_log('interop audit failed: ' . $e->getMessage(), 'warn');
    }
}

function ibPatientToFhir(array $patient): array
{
    $name = [];
    if (!empty($patient['last_name']) || !empty($patient['first_name'])) {
        $name[] = [
            'use' => 'official',
            'family' => (string)($patient['last_name'] ?? ''),
            'given' => array_filter([(string)($patient['first_name'] ?? '')]),
        ];
    }
    $telecom = [];
    if (!empty($patient['primary_phone'])) {
        $telecom[] = ['system' => 'phone', 'value' => (string)$patient['primary_phone']];
    }
    if (!empty($patient['email'])) {
        $telecom[] = ['system' => 'email', 'value' => (string)$patient['email']];
    }

    return [
        'resourceType' => 'Patient',
        'id' => (string)($patient['patient_uuid'] ?? $patient['id'] ?? ''),
        'identifier' => [
            ['system' => 'urn:ikabud:ehr:patient', 'value' => (string)($patient['patient_uuid'] ?? '')],
        ],
        'active' => true,
        'name' => $name,
        'telecom' => $telecom,
        'gender' => (string)($patient['sex'] ?? 'unknown'),
        'birthDate' => (string)($patient['birth_date'] ?? ''),
    ];
}

function interoperability_bridge_cap_ehr_interop_fhir_patient_export_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    if ($patientId <= 0) {
        return ['ok' => false, 'error' => 'patient_id is required'];
    }
    $lookup = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'interoperability-bridge']);
    if (!is_array($lookup) || empty($lookup['ok']) || !is_array($lookup['patient'] ?? null)) {
        return ['ok' => false, 'error' => 'Patient not found'];
    }
    $resource = ibPatientToFhir($lookup['patient']);

    ibDb()->execute(
        'INSERT INTO ehr_interop_messages (direction, protocol, message_type, patient_id, status, payload_json) '
        . 'VALUES (:dir, :proto, :type, :pid, :status, :payload)',
        [
            ':dir' => 'outbound',
            ':proto' => 'fhir',
            ':type' => 'Patient',
            ':pid' => $patientId,
            ':status' => 'logged',
            ':payload' => json_encode($resource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]
    );
    $messageId = (int)ibDb()->lastInsertId();
    ibAudit('ehr.interop.exported', [
        'patient_id' => $patientId,
        'new_data' => ['protocol' => 'fhir', 'resource' => 'Patient', 'message_id' => $messageId],
    ]);
    app()->events()->fire('ehr.interop.exported', ['patient_id' => $patientId, 'message_id' => $messageId]);

    return ['ok' => true, 'resource' => $resource, 'message_id' => $messageId];
}

function interoperability_bridge_cap_ehr_interop_message_log_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $direction = strtolower(trim((string)($data['direction'] ?? '')));
    $protocol = strtolower(trim((string)($data['protocol'] ?? '')));
    $messageType = trim((string)($data['message_type'] ?? ''));

    if (!in_array($direction, ['inbound', 'outbound'], true)) {
        return ['ok' => false, 'error' => 'direction must be inbound or outbound'];
    }
    if (!in_array($protocol, ['fhir', 'hl7', 'dicom', 'custom'], true)) {
        return ['ok' => false, 'error' => 'unsupported protocol'];
    }
    if ($messageType === '') {
        return ['ok' => false, 'error' => 'message_type is required'];
    }

    ibDb()->execute(
        'INSERT INTO ehr_interop_messages (direction, protocol, message_type, patient_id, correlation_id, status, payload_json, error_text) '
        . 'VALUES (:dir, :proto, :type, :pid, :cid, :status, :payload, :err)',
        [
            ':dir' => $direction,
            ':proto' => $protocol,
            ':type' => $messageType,
            ':pid' => isset($data['patient_id']) ? (int)$data['patient_id'] : null,
            ':cid' => trim((string)($data['correlation_id'] ?? '')) ?: null,
            ':status' => trim((string)($data['status'] ?? 'logged')),
            ':payload' => isset($data['payload']) ? (is_string($data['payload']) ? $data['payload'] : json_encode($data['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : null,
            ':err' => trim((string)($data['error_text'] ?? '')) ?: null,
        ]
    );
    $id = (int)ibDb()->lastInsertId();
    ibAudit('ehr.interop.message.logged', ['new_data' => ['id' => $id, 'protocol' => $protocol, 'direction' => $direction]]);
    app()->events()->fire('ehr.interop.message.logged', ['message_id' => $id]);

    return ['ok' => true, 'message_id' => $id];
}

function interoperability_bridge_cap_ehr_interop_message_list_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $where = [];
    $params = [];
    foreach (['direction', 'protocol', 'status'] as $f) {
        if (!empty($data[$f])) {
            $where[] = "$f = :$f";
            $params[":$f"] = (string)$data[$f];
        }
    }
    if (!empty($data['patient_id'])) {
        $where[] = 'patient_id = :pid';
        $params[':pid'] = (int)$data['patient_id'];
    }
    $limit = max(1, min(200, (int)($data['limit'] ?? 50)));
    $sql = 'SELECT id, direction, protocol, message_type, patient_id, correlation_id, status, occurred_at FROM ehr_interop_messages';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY occurred_at DESC, id DESC LIMIT ' . $limit;
    $rows = ibDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    return ['ok' => true, 'messages' => $rows];
}

function interoperability_bridge_cap_ehr_interop_identifier_map_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $entity = trim((string)($data['local_entity'] ?? ''));
    $localId = (int)($data['local_id'] ?? 0);
    $system = trim((string)($data['external_system'] ?? ''));
    $externalId = trim((string)($data['external_id'] ?? ''));

    if ($entity === '' || $localId <= 0 || $system === '' || $externalId === '') {
        return ['ok' => false, 'error' => 'local_entity, local_id, external_system, external_id are required'];
    }

    try {
        ibDb()->execute(
            'INSERT INTO ehr_interop_identifier_map (local_entity, local_id, external_system, external_id, metadata_json) '
            . 'VALUES (:e, :lid, :s, :ext, :meta) '
            . 'ON DUPLICATE KEY UPDATE external_id = VALUES(external_id), metadata_json = VALUES(metadata_json), updated_at = NOW()',
            [
                ':e' => $entity,
                ':lid' => $localId,
                ':s' => $system,
                ':ext' => $externalId,
                ':meta' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Could not map identifier', 'details' => $e->getMessage()];
    }

    ibAudit('ehr.interop.identifier.mapped', ['new_data' => ['local_entity' => $entity, 'local_id' => $localId, 'external_system' => $system]]);
    return ['ok' => true];
}

function interoperability_bridge_cap_ehr_interop_identifier_lookup_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $entity = trim((string)($data['local_entity'] ?? ''));
    $system = trim((string)($data['external_system'] ?? ''));

    if ($entity === '' || $system === '') {
        return ['ok' => false, 'error' => 'local_entity and external_system are required'];
    }

    if (!empty($data['local_id'])) {
        $row = ibDb()->query(
            'SELECT * FROM ehr_interop_identifier_map WHERE local_entity = :e AND local_id = :lid AND external_system = :s LIMIT 1',
            [':e' => $entity, ':lid' => (int)$data['local_id'], ':s' => $system]
        )->fetch(\PDO::FETCH_ASSOC);
        return ['ok' => true, 'mapping' => is_array($row) ? $row : null];
    }
    if (!empty($data['external_id'])) {
        $row = ibDb()->query(
            'SELECT * FROM ehr_interop_identifier_map WHERE local_entity = :e AND external_system = :s AND external_id = :ext LIMIT 1',
            [':e' => $entity, ':s' => $system, ':ext' => (string)$data['external_id']]
        )->fetch(\PDO::FETCH_ASSOC);
        return ['ok' => true, 'mapping' => is_array($row) ? $row : null];
    }
    return ['ok' => false, 'error' => 'local_id or external_id must be supplied'];
}
