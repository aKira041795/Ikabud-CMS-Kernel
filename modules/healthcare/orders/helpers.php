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

function ordUpsertPrimaryItem(int $orderId, array $item): void
{
    $itemLabel = trim((string)($item['item_label'] ?? ''));
    if ($orderId <= 0 || $itemLabel === '') {
        return;
    }

    $existing = ordDb()->query('SELECT id FROM ehr_order_items WHERE order_id = :order_id ORDER BY id ASC LIMIT 1', [':order_id' => $orderId])->fetch(\PDO::FETCH_ASSOC);
    $params = [
        ':item_code' => trim((string)($item['item_code'] ?? '')),
        ':code_system' => trim((string)($item['code_system'] ?? '')),
        ':item_label' => $itemLabel,
        ':specimen_type' => trim((string)($item['specimen_type'] ?? '')),
        ':body_site' => trim((string)($item['body_site'] ?? '')),
        ':laterality' => trim((string)($item['laterality'] ?? '')),
        ':status' => trim((string)($item['status'] ?? 'requested')) ?: 'requested',
    ];

    if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
        $params[':id'] = (int)$existing['id'];
        ordDb()->execute(
            'UPDATE ehr_order_items SET item_code = :item_code, code_system = :code_system, item_label = :item_label, specimen_type = :specimen_type, body_site = :body_site, laterality = :laterality, status = :status, updated_at = NOW() WHERE id = :id LIMIT 1',
            $params
        );
        return;
    }

    $params[':order_id'] = $orderId;
    ordDb()->execute(
        'INSERT INTO ehr_order_items '
        . '(order_id, item_code, code_system, item_label, specimen_type, body_site, laterality, status, created_at, updated_at) '
        . 'VALUES (:order_id, :item_code, :code_system, :item_label, :specimen_type, :body_site, :laterality, :status, NOW(), NOW())',
        $params
    );
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

function orders_cap_ehr_order_update_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $order = ordFetchOrder((int)($data['id'] ?? 0), trim((string)($data['order_uuid'] ?? '')));
    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found'];
    }

    $patientId = (int)($data['patient_id'] ?? ($order['patient_id'] ?? 0));
    $encounterId = (int)($data['encounter_id'] ?? ($order['encounter_id'] ?? 0));
    $status = strtolower(trim((string)($data['status'] ?? ($order['status'] ?? 'requested'))));
    $itemLabel = trim((string)($data['item_label'] ?? ($order['items'][0]['item_label'] ?? '')));

    if ($patientId <= 0 || $encounterId <= 0) {
        return ['ok' => false, 'error' => 'patient_id and encounter_id are required'];
    }
    if ($itemLabel === '') {
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

    ordDb()->beginTransaction();
    try {
        ordDb()->execute(
            'UPDATE ehr_orders SET '
            . 'patient_id = :patient_id, encounter_id = :encounter_id, order_type = :order_type, ordering_provider_id = :ordering_provider_id, priority = :priority, '
            . 'status = :status, clinical_question = :clinical_question, destination_module = :destination_module, billing_ref_status = :billing_ref_status, updated_at = NOW() '
            . 'WHERE id = :id LIMIT 1',
            [
                ':patient_id' => $patientId,
                ':encounter_id' => $encounterId,
                ':order_type' => trim((string)($data['order_type'] ?? ($order['order_type'] ?? 'lab'))),
                ':ordering_provider_id' => isset($data['ordering_provider_id']) ? (int)$data['ordering_provider_id'] : (isset($order['ordering_provider_id']) ? (int)$order['ordering_provider_id'] : null),
                ':priority' => trim((string)($data['priority'] ?? ($order['priority'] ?? 'routine'))),
                ':status' => $status,
                ':clinical_question' => trim((string)($data['clinical_question'] ?? ($order['clinical_question'] ?? ''))),
                ':destination_module' => trim((string)($data['destination_module'] ?? ($order['destination_module'] ?? 'results'))),
                ':billing_ref_status' => trim((string)($data['billing_ref_status'] ?? ($order['billing_ref_status'] ?? 'pending'))),
                ':id' => (int)$order['id'],
            ]
        );

        ordUpsertPrimaryItem((int)$order['id'], [
            'item_code' => trim((string)($data['item_code'] ?? ($order['items'][0]['item_code'] ?? ''))),
            'code_system' => trim((string)($data['code_system'] ?? ($order['items'][0]['code_system'] ?? ''))),
            'item_label' => $itemLabel,
            'specimen_type' => trim((string)($data['specimen_type'] ?? ($order['items'][0]['specimen_type'] ?? ''))),
            'body_site' => trim((string)($data['body_site'] ?? ($order['items'][0]['body_site'] ?? ''))),
            'laterality' => trim((string)($data['laterality'] ?? ($order['items'][0]['laterality'] ?? ''))),
            'status' => trim((string)($data['item_status'] ?? ($order['items'][0]['status'] ?? 'requested'))),
        ]);

        ordDb()->commit();
    } catch (\Throwable $e) {
        if (ordDb()->inTransaction()) {
            ordDb()->rollBack();
        }
        return ['ok' => false, 'error' => 'Order update failed', 'details' => $e->getMessage()];
    }

    $updated = ordFetchOrder((int)$order['id']);
    ehcAudit('orders', 'ehr.order.updated', 'ehr_order', (int)$order['id'], $updated ?? [], $order);
    app()->events()->fire('ehr.order.updated', [
        'order_id' => (int)$order['id'],
        'order_uuid' => (string)($order['order_uuid'] ?? ''),
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
    ]);

    return ['ok' => true, 'order' => $updated];
}