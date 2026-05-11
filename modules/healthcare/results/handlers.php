<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function resPageState(array $user, array $input = [], ?string $formError = null): array
{
    $triageFilter = strtolower(trim((string)($input['status'] ?? '')));
    if (!in_array($triageFilter, ['critical', 'abnormal', 'pending', 'reviewed'], true)) {
        $triageFilter = '';
    }
    $rows = resDb()->query(
        'SELECT r.id, r.patient_id, r.encounter_id, r.result_status, r.observed_at, r.value_text, r.value_numeric, r.unit, r.abnormal_flag, r.restricted_flag, r.acknowledged_at, r.acknowledged_by_user_id, '
        . 'oi.item_label, o.order_uuid '
        . 'FROM ehr_lab_results r '
        . 'LEFT JOIN ehr_order_items oi ON oi.id = r.order_item_id '
        . 'LEFT JOIN ehr_orders o ON o.id = oi.order_id '
        . 'ORDER BY r.observed_at DESC, r.id DESC LIMIT 50'
    )->fetchAll(PDO::FETCH_ASSOC);

    $allResults = ehrHydrateRecordSummaries(is_array($rows) ? $rows : [], 'results');
    $triage = ['critical' => 0, 'abnormal' => 0, 'pending' => 0, 'reviewed' => 0];
    $classify = static function (array $resRow): string {
        $abn = strtolower(trim((string)($resRow['abnormal_flag'] ?? '')));
        $status = (string)($resRow['result_status'] ?? '');
        $acked = !empty($resRow['acknowledged_at']);
        if ($abn === 'critical' || $abn === 'panic') {
            return 'critical';
        }
        if ($abn !== '' && $abn !== 'normal') {
            return 'abnormal';
        }
        if (in_array($status, ['entered', 'pending', 'verified'], true) && !$acked) {
            return 'pending';
        }
        return 'reviewed';
    };
    $filtered = [];
    foreach ($allResults as $resRow) {
        if (function_exists('ehrStatusBadge')) {
            $statusKey = (string)($resRow['result_status'] ?? '');
            if ($statusKey === '' && isset($resRow['abnormal_flag']) && trim((string)$resRow['abnormal_flag']) !== '' && trim((string)$resRow['abnormal_flag']) !== 'normal') {
                $statusKey = 'abnormal';
            }
            $resRow['status_badge'] = ehrStatusBadge($statusKey, 'result');
        }
        $bucket = $classify($resRow);
        $triage[$bucket]++;
        if ($triageFilter === '' || $triageFilter === $bucket) {
            $filtered[] = $resRow;
            if (count($filtered) >= 12) {
                // continue counting but don't add more rows
                // keep iterating so triage counts stay accurate
                continue;
            }
        }
    }
    // Trim filtered to 12 items max (preserve order)
    $results = array_slice($filtered, 0, 12);

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
            'status_filter' => $triageFilter,
            'status_tabs_data' => [
                'tabs' => [
                    ['status' => '',         'label' => 'All open', 'count' => $triage['critical'] + $triage['abnormal'] + $triage['pending'], 'tone' => 'slate', 'url' => '/admin/ehr/results', 'active' => $triageFilter === ''],
                    ['status' => 'critical', 'label' => 'Critical', 'count' => $triage['critical'], 'tone' => 'rose',  'url' => '/admin/ehr/results?status=critical', 'active' => $triageFilter === 'critical'],
                    ['status' => 'abnormal', 'label' => 'Abnormal', 'count' => $triage['abnormal'], 'tone' => 'amber', 'url' => '/admin/ehr/results?status=abnormal', 'active' => $triageFilter === 'abnormal'],
                    ['status' => 'pending',  'label' => 'Pending',  'count' => $triage['pending'],  'tone' => 'slate', 'url' => '/admin/ehr/results?status=pending',  'active' => $triageFilter === 'pending'],
                ],
                'done_url'   => '/admin/ehr/results?status=reviewed',
                'done_label' => 'Show reviewed',
                'done_count' => $triage['reviewed'],
            ],
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
        $upload = function_exists('kernelUploadedFile') ? kernelUploadedFile('result_file') : null;
        if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try {
                $orderRow = resDb()->prepare('SELECT patient_id, encounter_id FROM ehr_orders WHERE id = :id');
                $orderRow->execute([':id' => $orderId]);
                $ord = $orderRow->fetch(\PDO::FETCH_ASSOC) ?: [];
                $patientId = (int)($ord['patient_id'] ?? 0);
                $encounterId = (int)($ord['encounter_id'] ?? 0);
                if ($patientId > 0 && function_exists('docPersistUploadedFile')) {
                    $persisted = docPersistUploadedFile($upload, $patientId);
                    app()->cap()->call('ehr.document.upload@1', [
                        'patient_id' => $patientId,
                        'encounter_id' => $encounterId,
                        'title' => 'Lab result attachment ' . date('Y-m-d H:i'),
                        'document_type' => 'lab_report',
                        'mime_type' => $persisted['mime_type'],
                        'storage_key' => $persisted['storage_key'],
                        'file_size' => $persisted['file_size'],
                        'sensitivity_level' => 'normal',
                        'uploaded_by_user_id' => (int)($user['id'] ?? 0),
                        'source' => 'results-upload',
                    ], ['caller_module' => 'results']);
                }
            } catch (\Throwable $e) {
                write_log('result attachment upload failed: ' . $e->getMessage(), 'error');
            }
        }
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
function resExportCsv(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) { http_response_code(503); echo 'EHR admin runtime unavailable'; return; }
    ehrRequireAdmin();
    $rows = resDb()->query(
        'SELECT r.id, r.patient_id, r.encounter_id, r.order_item_id, r.result_status, r.observed_at, r.value_text, r.value_numeric, r.unit, r.reference_range_text, r.abnormal_flag, r.acknowledged_at, oi.item_label, oi.item_code, o.order_uuid '
        . 'FROM ehr_lab_results r LEFT JOIN ehr_order_items oi ON oi.id = r.order_item_id LEFT JOIN ehr_orders o ON o.id = oi.order_id '
        . 'ORDER BY r.observed_at DESC, r.id DESC LIMIT 1000'
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ehr-lab-results.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['id','patient_id','encounter_id','order_uuid','item_code','item_label','value_text','value_numeric','unit','reference_range','abnormal_flag','status','observed_at','acknowledged_at']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string)($r['id'] ?? ''), (string)($r['patient_id'] ?? ''), (string)($r['encounter_id'] ?? ''),
            (string)($r['order_uuid'] ?? ''), (string)($r['item_code'] ?? ''), (string)($r['item_label'] ?? ''),
            (string)($r['value_text'] ?? ''), (string)($r['value_numeric'] ?? ''), (string)($r['unit'] ?? ''),
            (string)($r['reference_range_text'] ?? ''), (string)($r['abnormal_flag'] ?? ''), (string)($r['result_status'] ?? ''),
            (string)($r['observed_at'] ?? ''), (string)($r['acknowledged_at'] ?? ''),
        ]);
    }
    fclose($out);
}
