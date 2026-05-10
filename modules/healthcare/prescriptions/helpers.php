<?php
declare(strict_types=1);

function rxCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('prescriptions');
    if (!$ctx) {
        throw new \RuntimeException('Prescriptions module context unavailable');
    }

    return $ctx;
}

function rxDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return rxCtx()->db();
}

function rxPrescriptionStatusAllowed(string $status): bool
{
    $result = app()->cap()->call('ehr.core.status.catalog@1', ['domain' => 'prescription'], ['caller_module' => 'prescriptions']);
    $statuses = is_array($result) && !empty($result['ok']) && is_array($result['statuses'] ?? null)
        ? $result['statuses']
        : [];

    return in_array($status, $statuses, true);
}

function rxFetchPrescription(int $prescriptionId = 0, string $prescriptionUuid = ''): ?array
{
    $sql = 'SELECT * FROM ehr_prescriptions WHERE id = :id LIMIT 1';
    $params = [':id' => $prescriptionId];

    if ($prescriptionId <= 0 && $prescriptionUuid !== '') {
        $sql = 'SELECT * FROM ehr_prescriptions WHERE prescription_uuid = :prescription_uuid LIMIT 1';
        $params = [':prescription_uuid' => $prescriptionUuid];
    }

    $prescription = rxDb()->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);
    return is_array($prescription) ? $prescription : null;
}

function prescriptions_cap_ehr_prescription_issue_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $encounterId = (int)($data['encounter_id'] ?? 0);
    $medicationText = trim((string)($data['medication_text'] ?? ''));
    $status = strtolower(trim((string)($data['status'] ?? 'issued')));

    if ($patientId <= 0 || $encounterId <= 0) {
        return ['ok' => false, 'error' => 'patient_id and encounter_id are required'];
    }
    if ($medicationText === '') {
        return ['ok' => false, 'error' => 'medication_text is required'];
    }
    if ($status !== 'issued' || !rxPrescriptionStatusAllowed($status)) {
        return ['ok' => false, 'error' => 'Prescription issuance must start in issued status'];
    }

    $patient = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'prescriptions']);
    $encounter = app()->cap()->call('ehr.encounter.view@1', ['id' => $encounterId], ['caller_module' => 'prescriptions']);
    if (!is_array($patient) || empty($patient['ok']) || !is_array($encounter) || empty($encounter['ok'])) {
        return ['ok' => false, 'error' => 'Patient or encounter not found'];
    }
    if ((int)($encounter['encounter']['patient_id'] ?? 0) !== $patientId) {
        return ['ok' => false, 'error' => 'Encounter does not belong to patient'];
    }

    $prescriptionUuid = ehcGenerateRecordKey('rx');
    try {
        rxDb()->execute(
            'INSERT INTO ehr_prescriptions '
            . '(prescription_uuid, patient_id, encounter_id, medication_code, code_system, medication_text, prescriber_user_id, dose_text, route, frequency, duration_text, quantity, refills, indication, status, issued_at, canceled_at, cancellation_reason, created_at, updated_at) '
            . 'VALUES (:prescription_uuid, :patient_id, :encounter_id, :medication_code, :code_system, :medication_text, :prescriber_user_id, :dose_text, :route, :frequency, :duration_text, :quantity, :refills, :indication, :status, NOW(), NULL, NULL, NOW(), NOW())',
            [
                ':prescription_uuid' => $prescriptionUuid,
                ':patient_id' => $patientId,
                ':encounter_id' => $encounterId,
                ':medication_code' => trim((string)($data['medication_code'] ?? '')) ?: null,
                ':code_system' => trim((string)($data['code_system'] ?? '')) ?: null,
                ':medication_text' => $medicationText,
                ':prescriber_user_id' => isset($data['prescriber_user_id']) ? (int)$data['prescriber_user_id'] : null,
                ':dose_text' => trim((string)($data['dose_text'] ?? '')) ?: null,
                ':route' => trim((string)($data['route'] ?? '')) ?: null,
                ':frequency' => trim((string)($data['frequency'] ?? '')) ?: null,
                ':duration_text' => trim((string)($data['duration_text'] ?? '')) ?: null,
                ':quantity' => isset($data['quantity']) ? (string)$data['quantity'] : null,
                ':refills' => isset($data['refills']) ? (int)$data['refills'] : null,
                ':indication' => trim((string)($data['indication'] ?? '')) ?: null,
                ':status' => 'issued',
            ]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Prescription issuance failed', 'details' => $e->getMessage()];
    }

    $prescriptionId = (int)rxDb()->lastInsertId();
    $prescription = rxFetchPrescription($prescriptionId);
    ehcAudit('prescriptions', 'ehr.prescription.issued', 'ehr_prescription', $prescriptionId, $prescription ?? []);
    app()->events()->fire('ehr.prescription.issued', [
        'prescription_id' => $prescriptionId,
        'prescription_uuid' => $prescriptionUuid,
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
    ]);

    return ['ok' => true, 'prescription' => $prescription];
}

function prescriptions_cap_ehr_prescription_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $prescription = rxFetchPrescription((int)($data['id'] ?? 0), trim((string)($data['prescription_uuid'] ?? '')));
    if (!$prescription) {
        return ['ok' => false, 'error' => 'Prescription not found'];
    }

    return ['ok' => true, 'prescription' => $prescription];
}

function prescriptions_cap_ehr_prescription_list_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    if ($patientId <= 0) {
        return ['ok' => false, 'error' => 'patient_id is required'];
    }
    $limit = max(1, min(100, (int)($data['limit'] ?? 25)));
    $status = trim((string)($data['status'] ?? ''));

    $where = ['patient_id = :pid'];
    $params = [':pid' => $patientId];
    if ($status !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }

    $sql = 'SELECT * FROM ehr_prescriptions WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
    $rows = rxDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    return ['ok' => true, 'prescriptions' => $rows];
}

function prescriptions_cap_ehr_prescription_cancel_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $prescriptionId = (int)($data['prescription_id'] ?? 0);
    $reason = trim((string)($data['reason'] ?? ''));
    if ($prescriptionId <= 0) {
        return ['ok' => false, 'error' => 'prescription_id is required'];
    }
    if ($reason === '') {
        return ['ok' => false, 'error' => 'reason is required'];
    }

    $prescription = rxFetchPrescription($prescriptionId);
    if (!$prescription) {
        return ['ok' => false, 'error' => 'Prescription not found'];
    }
    if ((string)($prescription['status'] ?? '') !== 'issued') {
        return ['ok' => false, 'error' => 'Only issued prescriptions can be canceled'];
    }

    $canceledAt = date('Y-m-d H:i:s');
    rxDb()->execute(
        'UPDATE ehr_prescriptions SET status = :status, canceled_at = :canceled_at, cancellation_reason = :cancellation_reason, updated_at = NOW() WHERE id = :id',
        [
            ':status' => 'canceled',
            ':canceled_at' => $canceledAt,
            ':cancellation_reason' => $reason,
            ':id' => $prescriptionId,
        ]
    );

    $canceled = rxFetchPrescription($prescriptionId);
    ehcAudit('prescriptions', 'ehr.prescription.canceled', 'ehr_prescription', $prescriptionId, $canceled ?? [], $prescription);
    app()->events()->fire('ehr.prescription.canceled', [
        'prescription_id' => $prescriptionId,
        'patient_id' => (int)$prescription['patient_id'],
        'encounter_id' => (int)$prescription['encounter_id'],
    ]);

    return ['ok' => true, 'prescription' => $canceled];
}
function prescriptions_cap_ehr_prescription_request_refill_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $prescriptionId = (int)($data['prescription_id'] ?? $data['id'] ?? 0);
    $reason = trim((string)($data['reason'] ?? ''));

    if ($prescriptionId <= 0) {
        return ['ok' => false, 'error' => 'prescription_id is required'];
    }

    $prescription = rxFetchPrescription($prescriptionId);
    if (!$prescription) {
        return ['ok' => false, 'error' => 'Prescription not found'];
    }
    if ((string)($prescription['status'] ?? '') !== 'issued') {
        return ['ok' => false, 'error' => 'Only issued prescriptions can be refilled'];
    }

    $remaining = $prescription['refills'] !== null ? (int)$prescription['refills'] : null;
    if ($remaining !== null && $remaining <= 0) {
        return ['ok' => false, 'error' => 'No refills remaining'];
    }

    if ($remaining !== null) {
        rxDb()->execute(
            'UPDATE ehr_prescriptions SET refills = refills - 1, updated_at = NOW() WHERE id = :id AND refills > 0',
            [':id' => $prescriptionId]
        );
    }

    $updated = rxFetchPrescription($prescriptionId);
    ehcAudit('prescriptions', 'ehr.prescription.refill.requested', 'ehr_prescription', $prescriptionId, $updated ?? [], $prescription);
    app()->events()->fire('ehr.prescription.refill.requested', [
        'prescription_id' => $prescriptionId,
        'patient_id' => (int)$prescription['patient_id'],
        'encounter_id' => (int)$prescription['encounter_id'],
        'reason' => $reason,
        'requested_by' => isset($data['actor_user_id']) ? (int)$data['actor_user_id'] : null,
        'refills_remaining' => $updated['refills'] ?? null,
    ]);

    return ['ok' => true, 'prescription' => $updated];
}
