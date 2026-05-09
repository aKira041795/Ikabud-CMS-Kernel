<?php
declare(strict_types=1);

function ordCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('orders');
    if (!$ctx) {
        throw new \RuntimeException('Orders module context unavailable');
    }

    return $ctx;
}

function ordDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return ordCtx()->db();
}

function ordOrderStatusAllowed(string $status): bool
{
    $result = app()->cap()->call('ehr.core.status.catalog@1', ['domain' => 'order'], ['caller_module' => 'orders']);
    $statuses = is_array($result) && !empty($result['ok']) && is_array($result['statuses'] ?? null)
        ? $result['statuses']
        : [];

    return in_array($status, $statuses, true);
}

function ordFetchOrder(int $orderId = 0, string $orderUuid = ''): ?array
{
    $sql = 'SELECT * FROM ehr_orders WHERE id = :id LIMIT 1';
    $params = [':id' => $orderId];

    if ($orderId <= 0 && $orderUuid !== '') {
        $sql = 'SELECT * FROM ehr_orders WHERE order_uuid = :order_uuid LIMIT 1';
        $params = [':order_uuid' => $orderUuid];
    }

    $order = ordDb()->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($order)) {
        return null;
    }

    $items = ordDb()->query(
        'SELECT * FROM ehr_order_items WHERE order_id = :order_id ORDER BY id ASC',
        [':order_id' => (int)$order['id']]
    )->fetchAll(\PDO::FETCH_ASSOC);

    $order['items'] = is_array($items) ? $items : [];
    return $order;
}

function orders_cap_ehr_order_create_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $encounterId = (int)($data['encounter_id'] ?? 0);
    $orderType = trim((string)($data['order_type'] ?? 'lab'));
    $status = strtolower(trim((string)($data['status'] ?? 'requested')));
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];

    if ($patientId <= 0 || $encounterId <= 0) {
        return ['ok' => false, 'error' => 'patient_id and encounter_id are required'];
    }
    if ($items === []) {
        return ['ok' => false, 'error' => 'At least one order item is required'];
    }
    if (!ordOrderStatusAllowed($status)) {
        return ['ok' => false, 'error' => 'Unsupported order status'];
    }

    $patient = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'orders']);
    $encounter = app()->cap()->call('ehr.encounter.view@1', ['id' => $encounterId], ['caller_module' => 'orders']);
    if (!is_array($patient) || empty($patient['ok']) || !is_array($encounter) || empty($encounter['ok'])) {
        return ['ok' => false, 'error' => 'Patient or encounter not found'];
    }
    if ((int)($encounter['encounter']['patient_id'] ?? 0) !== $patientId) {
        return ['ok' => false, 'error' => 'Encounter does not belong to patient'];
    }

    $orderUuid = ehcGenerateRecordKey('ord');
    ordDb()->beginTransaction();
    try {
        ordDb()->execute(
            'INSERT INTO ehr_orders '
            . '(order_uuid, patient_id, encounter_id, order_type, ordering_provider_id, priority, status, ordered_at, clinical_question, destination_module, billing_ref_status, created_at, updated_at) '
            . 'VALUES (:order_uuid, :patient_id, :encounter_id, :order_type, :ordering_provider_id, :priority, :status, NOW(), :clinical_question, :destination_module, :billing_ref_status, NOW(), NOW())',
            [
                ':order_uuid' => $orderUuid,
                ':patient_id' => $patientId,
                ':encounter_id' => $encounterId,
                ':order_type' => $orderType,
                ':ordering_provider_id' => isset($data['ordering_provider_id']) ? (int)$data['ordering_provider_id'] : null,
                ':priority' => trim((string)($data['priority'] ?? 'routine')),
                ':status' => $status,
                ':clinical_question' => trim((string)($data['clinical_question'] ?? '')),
                ':destination_module' => trim((string)($data['destination_module'] ?? 'results')),
                ':billing_ref_status' => trim((string)($data['billing_ref_status'] ?? 'pending')),
            ]
        );

        $orderId = (int)ordDb()->lastInsertId();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemLabel = trim((string)($item['item_label'] ?? ''));
            if ($itemLabel === '') {
                continue;
            }
            ordDb()->execute(
                'INSERT INTO ehr_order_items '
                . '(order_id, item_code, code_system, item_label, specimen_type, body_site, laterality, status, created_at, updated_at) '
                . 'VALUES (:order_id, :item_code, :code_system, :item_label, :specimen_type, :body_site, :laterality, :status, NOW(), NOW())',
                [
                    ':order_id' => $orderId,
                    ':item_code' => trim((string)($item['item_code'] ?? '')),
                    ':code_system' => trim((string)($item['code_system'] ?? '')),
                    ':item_label' => $itemLabel,
                    ':specimen_type' => trim((string)($item['specimen_type'] ?? '')),
                    ':body_site' => trim((string)($item['body_site'] ?? '')),
                    ':laterality' => trim((string)($item['laterality'] ?? '')),
                    ':status' => trim((string)($item['status'] ?? 'requested')) ?: 'requested',
                ]
            );
        }
        ordDb()->commit();
    } catch (\Throwable $e) {
        if (ordDb()->inTransaction()) {
            ordDb()->rollBack();
        }
        return ['ok' => false, 'error' => 'Order creation failed', 'details' => $e->getMessage()];
    }

    $order = ordFetchOrder($orderId);
    ehcAudit('orders', 'ehr.order.created', 'ehr_order', $orderId, $order ?? []);
    app()->events()->fire('ehr.order.created', [
        'order_id' => $orderId,
        'order_uuid' => $orderUuid,
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
    ]);

    return ['ok' => true, 'order' => $order];
}

function orders_cap_ehr_order_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $order = ordFetchOrder((int)($data['id'] ?? 0), trim((string)($data['order_uuid'] ?? '')));
    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found'];
    }

    return ['ok' => true, 'order' => $order];
}