<?php
declare(strict_types=1);

function bbCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('billing-bridge');
    if (!$ctx) {
        throw new \RuntimeException('Billing Bridge module context unavailable');
    }

    return $ctx;
}

function bbDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return bbCtx()->db();
}

function bbDecodeJson(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function bbNormalizeDate(string $value, bool $endOfDay = false): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $value .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $timestamp);
}

function bbBuildWhere(array $data): array
{
    $where = [
        '(a.action = :appointment_action OR a.action = :order_action OR a.action = :prescription_action)',
    ];
    $params = [
        ':appointment_action' => 'ehr.appointment.updated',
        ':order_action' => 'ehr.order.created',
        ':prescription_action' => 'ehr.prescription.issued',
    ];

    $from = bbNormalizeDate((string)($data['date_from'] ?? ''), false);
    if ($from !== null) {
        $where[] = 'a.created_at >= :date_from';
        $params[':date_from'] = $from;
    }
    $to = bbNormalizeDate((string)($data['date_to'] ?? ''), true);
    if ($to !== null) {
        $where[] = 'a.created_at <= :date_to';
        $params[':date_to'] = $to;
    }

    $patientId = (int)($data['patient_id'] ?? 0);
    if ($patientId > 0) {
        $where[] = "JSON_UNQUOTE(JSON_EXTRACT(a.new_data, '$.patient_id')) = :patient_id";
        $params[':patient_id'] = (string)$patientId;
    }

    $encounterId = (int)($data['encounter_id'] ?? 0);
    if ($encounterId > 0) {
        $where[] = "JSON_UNQUOTE(JSON_EXTRACT(a.new_data, '$.encounter_id')) = :encounter_id";
        $params[':encounter_id'] = (string)$encounterId;
    }

    return [$where, $params];
}

function bbCanceledPrescriptionIds(): array
{
    $ids = [];
    $offset = 0;
    $pageSize = 200;
    do {
        $result = app()->cap()->call('ehr.audit.search@1', [
            'action' => 'ehr.prescription.canceled',
            'entity_type' => 'ehr_prescription',
            'limit' => $pageSize,
            'offset' => $offset,
        ], ['caller_module' => 'billing-bridge']);
        $entries = is_array($result) && !empty($result['ok']) && is_array($result['entries'] ?? null) ? $result['entries'] : [];
        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entityId = trim((string)($row['entity_id'] ?? ''));
            if ($entityId !== '') {
                $ids[$entityId] = true;
            }
        }
        $hasMore = is_array($result['pagination'] ?? null) && !empty($result['pagination']['has_more']);
        $offset += $pageSize;
    } while ($hasMore);

    return $ids;
}

function bbAppointmentCompleted(array $newData, array $oldData): bool
{
    $newStatus = strtolower(trim((string)($newData['status'] ?? '')));
    $oldStatus = strtolower(trim((string)($oldData['status'] ?? '')));
    return $newStatus === 'completed' && $oldStatus !== 'completed';
}

function bbCandidateFromAudit(array $row, array $canceledPrescriptionIds): ?array
{
    $action = (string)($row['action'] ?? '');
    $entityId = trim((string)($row['entity_id'] ?? ''));
    $newData = bbDecodeJson($row['new_data'] ?? null);
    $oldData = bbDecodeJson($row['old_data'] ?? null);

    if ($action === 'ehr.appointment.updated') {
        if (!bbAppointmentCompleted($newData, $oldData)) {
            return null;
        }

        return [
            'candidate_key' => 'audit:' . (string)($row['id'] ?? ''),
            'candidate_type' => 'consultation',
            'billing_code' => 'consultation.completed',
            'source_action' => $action,
            'source_module' => (string)($row['module'] ?? ''),
            'source_entity_type' => (string)($row['entity_type'] ?? ''),
            'source_entity_id' => $entityId,
            'patient_id' => (int)($newData['patient_id'] ?? 0),
            'encounter_id' => (int)($newData['encounter_id'] ?? 0),
            'event_at' => (string)($row['created_at'] ?? ''),
            'label' => trim((string)($newData['appointment_type'] ?? 'Consultation')) ?: 'Consultation',
            'quantity' => 1,
            'details' => [
                'status' => (string)($newData['status'] ?? ''),
                'reason_for_visit' => (string)($newData['reason_for_visit'] ?? ''),
            ],
        ];
    }

    if ($action === 'ehr.order.created') {
        $items = is_array($newData['items'] ?? null) ? $newData['items'] : [];
        $firstItem = is_array($items[0] ?? null) ? $items[0] : [];
        return [
            'candidate_key' => 'audit:' . (string)($row['id'] ?? ''),
            'candidate_type' => 'order',
            'billing_code' => 'order.' . trim((string)($newData['order_type'] ?? 'general')),
            'source_action' => $action,
            'source_module' => (string)($row['module'] ?? ''),
            'source_entity_type' => (string)($row['entity_type'] ?? ''),
            'source_entity_id' => $entityId,
            'patient_id' => (int)($newData['patient_id'] ?? 0),
            'encounter_id' => (int)($newData['encounter_id'] ?? 0),
            'event_at' => (string)($row['created_at'] ?? ''),
            'label' => trim((string)($firstItem['item_label'] ?? $newData['order_type'] ?? 'Order')) ?: 'Order',
            'quantity' => max(1, count($items)),
            'details' => [
                'order_type' => (string)($newData['order_type'] ?? ''),
                'billing_ref_status' => (string)($newData['billing_ref_status'] ?? ''),
                'items' => $items,
            ],
        ];
    }

    if ($action === 'ehr.prescription.issued') {
        if ($entityId !== '' && isset($canceledPrescriptionIds[$entityId])) {
            return null;
        }

        return [
            'candidate_key' => 'audit:' . (string)($row['id'] ?? ''),
            'candidate_type' => 'prescription',
            'billing_code' => 'prescription.issued',
            'source_action' => $action,
            'source_module' => (string)($row['module'] ?? ''),
            'source_entity_type' => (string)($row['entity_type'] ?? ''),
            'source_entity_id' => $entityId,
            'patient_id' => (int)($newData['patient_id'] ?? 0),
            'encounter_id' => (int)($newData['encounter_id'] ?? 0),
            'event_at' => (string)($row['created_at'] ?? ''),
            'label' => trim((string)($newData['medication_text'] ?? 'Prescription')) ?: 'Prescription',
            'quantity' => 1,
            'details' => [
                'medication_code' => (string)($newData['medication_code'] ?? ''),
                'medication_text' => (string)($newData['medication_text'] ?? ''),
                'status' => (string)($newData['status'] ?? ''),
            ],
        ];
    }

    return null;
}

function billing_bridge_cap_ehr_billing_charge_candidates_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $limit = max(1, min(200, (int)($data['limit'] ?? 50)));
    $offset = max(0, (int)($data['offset'] ?? 0));

    $searchPayload = [
        'actions' => [
            'ehr.appointment.updated',
            'ehr.order.created',
            'ehr.prescription.issued',
        ],
        'limit' => $limit,
        'offset' => $offset,
    ];
    $from = bbNormalizeDate((string)($data['date_from'] ?? ''), false);
    if ($from !== null) {
        $searchPayload['date_from'] = $from;
    }
    $to = bbNormalizeDate((string)($data['date_to'] ?? ''), true);
    if ($to !== null) {
        $searchPayload['date_to'] = $to;
    }
    $patientId = (int)($data['patient_id'] ?? 0);
    if ($patientId > 0) {
        $searchPayload['patient_id'] = $patientId;
    }
    $encounterId = (int)($data['encounter_id'] ?? 0);
    if ($encounterId > 0) {
        $searchPayload['encounter_id'] = $encounterId;
    }

    $result = app()->cap()->call('ehr.audit.search@1', $searchPayload, ['caller_module' => 'billing-bridge']);
    if (!is_array($result) || empty($result['ok'])) {
        return ['ok' => false, 'error' => 'Charge candidate generation failed', 'details' => (string)($result['error'] ?? 'audit cap unavailable')];
    }

    $rows = is_array($result['entries'] ?? null) ? $result['entries'] : [];
    $total = is_array($result['pagination'] ?? null) ? (int)($result['pagination']['total'] ?? 0) : 0;

    $canceledPrescriptionIds = bbCanceledPrescriptionIds();
    $candidates = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidate = bbCandidateFromAudit($row, $canceledPrescriptionIds);
        if ($candidate !== null) {
            $candidates[] = $candidate;
        }
    }

    return [
        'ok' => true,
        'candidates' => $candidates,
        'pagination' => [
            'total_events' => $total,
            'returned_candidates' => count($candidates),
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
        ],
    ];
}