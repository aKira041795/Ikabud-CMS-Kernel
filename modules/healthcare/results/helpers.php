<?php
declare(strict_types=1);

function resCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('results');
    if (!$ctx) {
        throw new \RuntimeException('Results module context unavailable');
    }

    return $ctx;
}

function resDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return resCtx()->db();
}

function resResultStatusAllowed(string $status): bool
{
    $result = app()->cap()->call('ehr.core.status.catalog@1', ['domain' => 'result'], ['caller_module' => 'results']);
    $statuses = is_array($result) && !empty($result['ok']) && is_array($result['statuses'] ?? null)
        ? $result['statuses']
        : [];

    return in_array($status, $statuses, true);
}

function resFetchResult(int $resultId = 0): ?array
{
    $result = resDb()->query('SELECT * FROM ehr_lab_results WHERE id = :id LIMIT 1', [':id' => $resultId])->fetch(\PDO::FETCH_ASSOC);
    return is_array($result) ? $result : null;
}

function resRestrictedAccessDecision(array $result): array
{
    return ehcRestrictedAccessDecision([
        'patient_id' => (int)($result['patient_id'] ?? 0),
        'object_type' => 'result',
        'object_id' => (string)(int)($result['id'] ?? 0),
        'caller_module' => 'results',
        'allow_if_any' => true,
        'fallback_reason' => 'restricted_result',
    ]);
}

function resEmitAuditEvent(string $action, array $result, array $extra = []): void
{
    $resultId = (int)($result['id'] ?? 0);
    ehcAudit('results', $action, 'ehr_lab_result', $resultId, array_merge($result, $extra));
    app()->events()->fire($action, array_merge([
        'result_id' => $resultId,
        'patient_id' => (int)($result['patient_id'] ?? 0),
        'encounter_id' => (int)($result['encounter_id'] ?? 0),
        'order_item_id' => (int)($result['order_item_id'] ?? 0),
    ], $extra));
}

function results_cap_ehr_result_enter_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $orderId = (int)($data['order_id'] ?? 0);
    $orderItemId = (int)($data['order_item_id'] ?? 0);
    if ($orderId <= 0 || $orderItemId <= 0) {
        return ['ok' => false, 'error' => 'order_id and order_item_id are required'];
    }

    $order = app()->cap()->call('ehr.order.view@1', ['id' => $orderId], ['caller_module' => 'results']);
    if (!is_array($order) || empty($order['ok'])) {
        return ['ok' => false, 'error' => 'Order not found'];
    }

    $orderItem = null;
    foreach (($order['order']['items'] ?? []) as $item) {
        if ((int)($item['id'] ?? 0) === $orderItemId) {
            $orderItem = $item;
            break;
        }
    }
    if (!is_array($orderItem)) {
        return ['ok' => false, 'error' => 'Order item not found'];
    }

    $status = 'entered';
    if (!resResultStatusAllowed($status)) {
        return ['ok' => false, 'error' => 'Unsupported result status'];
    }

    try {
        resDb()->execute(
            'INSERT INTO ehr_lab_results '
            . '(patient_id, encounter_id, order_item_id, result_status, observed_at, value_text, value_numeric, unit, reference_range_text, abnormal_flag, entered_by_user_id, verified_by_user_id, verified_at, released_at, restricted_flag, created_at, updated_at) '
            . 'VALUES (:patient_id, :encounter_id, :order_item_id, :result_status, NOW(), :value_text, :value_numeric, :unit, :reference_range_text, :abnormal_flag, :entered_by_user_id, NULL, NULL, NULL, :restricted_flag, NOW(), NOW())',
            [
                ':patient_id' => (int)$order['order']['patient_id'],
                ':encounter_id' => (int)$order['order']['encounter_id'],
                ':order_item_id' => $orderItemId,
                ':result_status' => $status,
                ':value_text' => trim((string)($data['value_text'] ?? '')) ?: null,
                ':value_numeric' => isset($data['value_numeric']) ? (float)$data['value_numeric'] : null,
                ':unit' => trim((string)($data['unit'] ?? '')) ?: null,
                ':reference_range_text' => trim((string)($data['reference_range_text'] ?? '')) ?: null,
                ':abnormal_flag' => trim((string)($data['abnormal_flag'] ?? '')) ?: null,
                ':entered_by_user_id' => isset($data['entered_by_user_id']) ? (int)$data['entered_by_user_id'] : null,
                ':restricted_flag' => !empty($data['restricted_flag']) ? 1 : 0,
            ]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Result entry failed', 'details' => $e->getMessage()];
    }

    $resultId = (int)resDb()->lastInsertId();
    $resultRow = resFetchResult($resultId);
    ehcAudit('results', 'ehr.result.entered', 'ehr_lab_result', $resultId, $resultRow ?? []);
    app()->events()->fire('ehr.result.entered', [
        'result_id' => $resultId,
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
    ]);

    return ['ok' => true, 'result' => $resultRow];
}

function results_cap_ehr_result_verify_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $resultId = (int)($data['result_id'] ?? 0);
    if ($resultId <= 0) {
        return ['ok' => false, 'error' => 'result_id is required'];
    }

    $result = resFetchResult($resultId);
    if (!$result) {
        return ['ok' => false, 'error' => 'Result not found'];
    }
    if ((string)($result['result_status'] ?? '') !== 'entered') {
        return ['ok' => false, 'error' => 'Only entered results can be verified'];
    }

    $verifiedAt = date('Y-m-d H:i:s');
    resDb()->execute(
        'UPDATE ehr_lab_results SET result_status = :result_status, verified_by_user_id = :verified_by_user_id, verified_at = :verified_at, updated_at = NOW() WHERE id = :id',
        [
            ':result_status' => 'verified',
            ':verified_by_user_id' => isset($data['verified_by_user_id']) ? (int)$data['verified_by_user_id'] : null,
            ':verified_at' => $verifiedAt,
            ':id' => $resultId,
        ]
    );

    $verified = resFetchResult($resultId);
    ehcAudit('results', 'ehr.result.verified', 'ehr_lab_result', $resultId, $verified ?? [], $result);
    app()->events()->fire('ehr.result.verified', [
        'result_id' => $resultId,
        'order_item_id' => (int)$result['order_item_id'],
    ]);

    return ['ok' => true, 'result' => $verified];
}

function results_cap_ehr_result_release_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $resultId = (int)($data['result_id'] ?? 0);
    if ($resultId <= 0) {
        return ['ok' => false, 'error' => 'result_id is required'];
    }

    $result = resFetchResult($resultId);
    if (!$result) {
        return ['ok' => false, 'error' => 'Result not found'];
    }
    if ((string)($result['result_status'] ?? '') !== 'verified') {
        return ['ok' => false, 'error' => 'Only verified results can be released'];
    }

    $releasedAt = date('Y-m-d H:i:s');
    resDb()->execute(
        'UPDATE ehr_lab_results SET result_status = :result_status, released_at = :released_at, updated_at = NOW() WHERE id = :id',
        [
            ':result_status' => 'released',
            ':released_at' => $releasedAt,
            ':id' => $resultId,
        ]
    );

    $released = resFetchResult($resultId);
    ehcAudit('results', 'ehr.result.released', 'ehr_lab_result', $resultId, $released ?? [], $result);
    app()->events()->fire('ehr.result.released', [
        'result_id' => $resultId,
        'order_item_id' => (int)$result['order_item_id'],
    ]);

    return ['ok' => true, 'result' => $released];
}

function results_cap_ehr_result_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $result = resFetchResult((int)($data['id'] ?? 0));
    if (!$result) {
        return ['ok' => false, 'error' => 'Result not found'];
    }

    if (!empty($result['restricted_flag'])) {
        $decision = resRestrictedAccessDecision($result);
        if (empty($decision['allowed'])) {
            $reason = (string)($decision['reason'] ?? 'restricted_result');
            resEmitAuditEvent('ehr.result.access_denied', $result, [
                'attempted_action' => 'view',
                'denial_reason' => $reason,
            ]);

            return [
                'ok' => false,
                'error' => 'Result access denied',
                'reason' => $reason,
            ];
        }
    }

    resEmitAuditEvent('ehr.result.viewed', $result);

    return ['ok' => true, 'result' => $result];
}