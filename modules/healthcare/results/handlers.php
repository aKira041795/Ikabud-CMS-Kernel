<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function resPageState(array $user, array $input = [], ?string $formError = null): array
{
    $rows = resDb()->query(
        'SELECT r.id, r.patient_id, r.encounter_id, r.result_status, r.observed_at, r.value_text, r.value_numeric, r.unit, r.abnormal_flag, r.restricted_flag, r.acknowledged_at, r.acknowledged_by_user_id, '
        . 'oi.item_label, o.order_uuid '
        . 'FROM ehr_lab_results r '
        . 'LEFT JOIN ehr_order_items oi ON oi.id = r.order_item_id '
        . 'LEFT JOIN ehr_orders o ON o.id = oi.order_id '
        . 'ORDER BY r.observed_at DESC, r.id DESC LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);

    $results = ehrHydrateRecordSummaries(is_array($rows) ? $rows : [], 'results');
    $triage = ['critical' => 0, 'abnormal' => 0, 'pending' => 0, 'reviewed' => 0];
    foreach ($results as &$resRow) {
        if (function_exists('ehrStatusBadge')) {
            $statusKey = (string)($resRow['result_status'] ?? '');
            if ($statusKey === '' && isset($resRow['abnormal_flag']) && trim((string)$resRow['abnormal_flag']) !== '' && trim((string)$resRow['abnormal_flag']) !== 'normal') {
                $statusKey = 'abnormal';
            }
            $resRow['status_badge'] = ehrStatusBadge($statusKey, 'result');
        }
        $abn = strtolower(trim((string)($resRow['abnormal_flag'] ?? '')));
        $status = (string)($resRow['result_status'] ?? '');
        $acked = !empty($resRow['acknowledged_at']);
        if ($abn === 'critical' || $abn === 'panic') {
            $triage['critical']++;
        } elseif ($abn !== '' && $abn !== 'normal') {
            $triage['abnormal']++;
        } elseif (in_array($status, ['entered', 'pending', 'verified'], true) && !$acked) {
            $triage['pending']++;
        } else {
            $triage['reviewed']++;
        }
    }
    unset($resRow);

    $itemRows = resDb()->query(
        'SELECT oi.id AS order_item_id, oi.order_id, oi.item_label, oi.item_code, o.order_uuid, o.patient_id, o.encounter_id '
        . 'FROM ehr_order_items oi INNER JOIN ehr_orders o ON o.id = oi.order_id '
        . "WHERE o.status IN ('placed','in_progress') ORDER BY o.id DESC, oi.id DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);
    $orderItemOptions = is_array($itemRows) ? $itemRows : [];

    return array_merge(
        ehrAdminContext($user, 'ehr_results', ['page_title' => 'Results']),
        [
            'results' => $results,
            'result_count' => count($results),
            'triage_counts' => $triage,
            'order_item_options' => $orderItemOptions,
            'form_error' => $formError,
            'form_notice' => trim((string)($input['notice'] ?? '')),
            'form_values' => [
                'order_item_id' => (int)($input['order_item_id'] ?? 0),
                'value_text' => (string)($input['value_text'] ?? ''),
                'value_numeric' => (string)($input['value_numeric'] ?? ''),
                'unit' => (string)($input['unit'] ?? ''),
                'reference_range_text' => (string)($input['reference_range_text'] ?? ''),
                'abnormal_flag' => (string)($input['abnormal_flag'] ?? ''),
            ],
        ]
    );
}

function resPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    echo ehrRender('modules/results/admin/index.disyl', resPageState($user, app()->input()));
}

function resSaveResult(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $orderItemId = max(0, (int)($input['order_item_id'] ?? 0));
    $orderId = max(0, (int)($input['order_id'] ?? 0));
    if ($orderId <= 0 && $orderItemId > 0) {
        $row = resDb()->prepare('SELECT order_id FROM ehr_order_items WHERE id = :id');
        $row->execute([':id' => $orderItemId]);
        $orderId = (int)($row->fetchColumn() ?: 0);
    }

    $payload = [
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
        'value_text' => trim((string)($input['value_text'] ?? '')),
        'value_numeric' => $input['value_numeric'] ?? null,
        'unit' => trim((string)($input['unit'] ?? '')),
        'reference_range_text' => trim((string)($input['reference_range_text'] ?? '')),
        'abnormal_flag' => trim((string)($input['abnormal_flag'] ?? '')),
        'entered_by_user_id' => (int)($user['id'] ?? 0),
    ];
    if ($payload['value_numeric'] === '' || $payload['value_numeric'] === null) {
        unset($payload['value_numeric']);
    }

    $result = app()->cap()->call('ehr.result.enter@1', $payload, ['caller_module' => 'results']);
    if (is_array($result) && !empty($result['ok']) && is_array($result['result'] ?? null)) {
        app()->redirect('/admin/ehr/results?notice=created');
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to enter result.'));
    echo ehrRender('modules/results/admin/index.disyl', resPageState($user, $input, $error));
}

function resTransitionResult(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $resultId = max(0, (int)($input['result_id'] ?? 0));
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $capMap = [
        'verify' => 'ehr.result.verify@1',
        'release' => 'ehr.result.release@1',
        'acknowledge' => 'ehr.result.acknowledge@1',
    ];
    $cap = $capMap[$action] ?? '';
    if ($resultId <= 0 || $cap === '') {
        echo ehrRender('modules/results/admin/index.disyl', resPageState($user, $input, 'Invalid transition request.'));
        return;
    }

    $payload = ['result_id' => $resultId];
    if ($cap === 'ehr.result.verify@1') {
        $payload['verified_by_user_id'] = (int)($user['id'] ?? 0);
    }
    if ($cap === 'ehr.result.acknowledge@1') {
        $payload['actor_user_id'] = (int)($user['id'] ?? 0);
    }
    $result = app()->cap()->call($cap, $payload, ['caller_module' => 'results']);
    if (is_array($result) && !empty($result['ok'])) {
        $noticeMap = ['verify' => 'verified', 'release' => 'released', 'acknowledge' => 'acknowledged'];
        app()->redirect('/admin/ehr/results?notice=' . $noticeMap[$action]);
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to transition result.'));
    echo ehrRender('modules/results/admin/index.disyl', resPageState($user, $input, $error));
}