<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function ordPageState(array $user, array $input = [], ?string $formError = null): array
{
    $rows = ordDb()->query(
        'SELECT o.id, o.order_uuid, o.patient_id, o.encounter_id, o.order_type, o.priority, o.status, o.ordered_at, o.destination_module, o.clinical_question, '
        . '(SELECT COUNT(*) FROM ehr_order_items oi WHERE oi.order_id = o.id) AS item_count, '
        . '(SELECT item_label FROM ehr_order_items oi WHERE oi.order_id = o.id ORDER BY oi.id ASC LIMIT 1) AS first_item_label '
        . 'FROM ehr_orders o ORDER BY o.ordered_at DESC, o.id DESC LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);
    $orders = ehrHydrateRecordSummaries(is_array($rows) ? $rows : [], 'orders');
    foreach ($orders as &$ordRow) {
        if (function_exists('ehrStatusBadge')) {
            $ordRow['status_badge'] = ehrStatusBadge((string)($ordRow['status'] ?? ''), 'order');
        }
    }
    unset($ordRow);

    $patientSearch = app()->cap()->call('ehr.patient.search@1', ['limit' => 50], ['caller_module' => 'orders']);
    $patientOptions = is_array($patientSearch) && !empty($patientSearch['ok']) && is_array($patientSearch['results'] ?? null)
        ? array_values($patientSearch['results'])
        : [];
    $encounterList = app()->cap()->call('ehr.encounter.list@1', ['limit' => 50], ['caller_module' => 'orders']);
    $encounterOptions = is_array($encounterList) && !empty($encounterList['ok']) && is_array($encounterList['encounters'] ?? null)
        ? array_values($encounterList['encounters'])
        : [];
    $statusCatalog = app()->cap()->call('ehr.core.status.catalog@1', ['domain' => 'order'], ['caller_module' => 'orders']);
    $statusOptions = is_array($statusCatalog) && !empty($statusCatalog['ok']) && is_array($statusCatalog['statuses'] ?? null)
        ? array_values($statusCatalog['statuses'])
        : [];

    $selectedOrderId = max(0, (int)($input['order_id'] ?? 0));
    $selectedOrder = $selectedOrderId > 0 ? ordFetchOrder($selectedOrderId) : null;
    if (is_array($selectedOrder) && function_exists('ehrStatusBadge')) {
        $selectedOrder['status_badge'] = ehrStatusBadge((string)($selectedOrder['status'] ?? ''), 'order');
    }
    $firstItem = is_array($selectedOrder['items'][0] ?? null) ? $selectedOrder['items'][0] : [];
    $formSource = is_array($selectedOrder) ? $selectedOrder : [];
    foreach (['order_id', 'patient_id', 'encounter_id', 'order_type', 'priority', 'status', 'destination_module', 'clinical_question', 'item_label'] as $key) {
        if (array_key_exists($key, $input)) {
            $formSource[$key] = $input[$key];
        }
    }

    return array_merge(
        ehrAdminContext($user, 'ehr_orders', ['page_title' => 'Orders']),
        [
            'orders' => $orders,
            'result_count' => count($orders),
            'selected_order' => $selectedOrder,
            'patient_options' => $patientOptions,
            'encounter_options' => $encounterOptions,
            'status_options' => $statusOptions,
            'form_error' => $formError,
            'form_notice' => trim((string)($input['notice'] ?? '')),
            'form_values' => [
                'order_id' => $selectedOrderId,
                'patient_id' => (int)($formSource['patient_id'] ?? 0),
                'encounter_id' => (int)($formSource['encounter_id'] ?? 0),
                'order_type' => (string)($formSource['order_type'] ?? 'lab'),
                'priority' => (string)($formSource['priority'] ?? 'routine'),
                'status' => (string)($formSource['status'] ?? 'requested'),
                'destination_module' => (string)($formSource['destination_module'] ?? 'results'),
                'clinical_question' => (string)($formSource['clinical_question'] ?? ''),
                'item_label' => (string)($formSource['item_label'] ?? ($firstItem['item_label'] ?? '')),
            ],
        ]
    );
}

function ordPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    echo ehrRender('modules/orders/admin/index.disyl', ordPageState($user, app()->input()));
}

function ordSaveOrder(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $orderId = max(0, (int)($input['order_id'] ?? 0));
    $payload = [
        'id' => $orderId,
        'patient_id' => max(0, (int)($input['patient_id'] ?? 0)),
        'encounter_id' => max(0, (int)($input['encounter_id'] ?? 0)),
        'order_type' => trim((string)($input['order_type'] ?? 'lab')),
        'priority' => trim((string)($input['priority'] ?? 'routine')),
        'status' => trim((string)($input['status'] ?? 'requested')),
        'destination_module' => trim((string)($input['destination_module'] ?? 'results')),
        'clinical_question' => trim((string)($input['clinical_question'] ?? '')),
        'item_label' => trim((string)($input['item_label'] ?? '')),
        'items' => [[
            'item_label' => trim((string)($input['item_label'] ?? '')),
            'status' => trim((string)($input['status'] ?? 'requested')),
        ]],
    ];

    $capabilityId = $orderId > 0 ? 'ehr.order.update@1' : 'ehr.order.create@1';
    $result = app()->cap()->call($capabilityId, $payload, ['caller_module' => 'orders']);
    if (is_array($result) && !empty($result['ok']) && is_array($result['order'] ?? null)) {
        $savedOrderId = (int)($result['order']['id'] ?? 0);
        $target = '/admin/ehr/orders?notice=' . rawurlencode($orderId > 0 ? 'updated' : 'created');
        if ($savedOrderId > 0) {
            $target .= '&order_id=' . $savedOrderId;
        }
        app()->redirect($target);
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to save order.'));
    echo ehrRender('modules/orders/admin/index.disyl', ordPageState($user, $input, $error));
}