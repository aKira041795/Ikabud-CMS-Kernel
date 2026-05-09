<?php
declare(strict_types=1);

function rptCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('reporting');
    if (!$ctx) {
        throw new \RuntimeException('Reporting module context unavailable');
    }

    return $ctx;
}

function rptDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return rptCtx()->db();
}

function rptNormalizeDate(string $value, bool $endOfDay = false): ?string
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

function rptApplyDateRange(array $data, string $column, array &$where, array &$params, string $prefix): void
{
    $from = rptNormalizeDate((string)($data['date_from'] ?? ''), false);
    $to = rptNormalizeDate((string)($data['date_to'] ?? ''), true);

    if ($from !== null) {
        $key = ':' . $prefix . '_from';
        $where[] = $column . ' >= ' . $key;
        $params[$key] = $from;
    }
    if ($to !== null) {
        $key = ':' . $prefix . '_to';
        $where[] = $column . ' <= ' . $key;
        $params[$key] = $to;
    }
}

function rptApplyFacilityFilters(array $data, array &$where, array &$params): void
{
    if (isset($data['facility_id']) && (int)$data['facility_id'] > 0) {
        $where[] = 'facility_id = :facility_id';
        $params[':facility_id'] = (int)$data['facility_id'];
    }
    if (isset($data['department_id']) && (int)$data['department_id'] > 0) {
        $where[] = 'department_id = :department_id';
        $params[':department_id'] = (int)$data['department_id'];
    }
}

function rptComplianceRequestFilters(array $input): array
{
    $filters = [];

    foreach (['date_from', 'date_to', 'actor_source'] as $key) {
        $value = trim((string)($input[$key] ?? ''));
        if ($value !== '') {
            $filters[$key] = $value;
        }
    }

    foreach (['patient_id', 'actor_user_id', 'actor_module_user_id'] as $key) {
        $value = (int)($input[$key] ?? 0);
        if ($value > 0) {
            $filters[$key] = $value;
        }
    }

    $filters['limit'] = max(1, min(200, (int)($input['limit'] ?? 50)));
    $filters['page'] = max(1, (int)($input['page'] ?? 1));

    return $filters;
}

function rptSummaryRequestFilters(array $input): array
{
    $filters = [];

    foreach (['date_from', 'date_to'] as $key) {
        $value = trim((string)($input[$key] ?? ''));
        if ($value !== '') {
            $filters[$key] = $value;
        }
    }

    foreach (['facility_id', 'department_id'] as $key) {
        $value = (int)($input[$key] ?? 0);
        if ($value > 0) {
            $filters[$key] = $value;
        }
    }

    return $filters;
}

function rptQueryString(array $filters, array $overrides = []): string
{
    $query = array_merge($filters, $overrides);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            unset($query[$key]);
        }
    }

    return $query === [] ? '' : '?' . http_build_query($query);
}

function rptComplianceCsvRows(array $entries): array
{
    $rows = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $details = is_array($entry['details']['new_data'] ?? null) ? $entry['details']['new_data'] : [];
        $rows[] = [
            (string)($entry['created_at'] ?? ''),
            (string)($entry['category'] ?? ''),
            (string)($entry['action'] ?? ''),
            (string)($entry['module'] ?? ''),
            (string)($entry['patient_id'] ?? ''),
            (string)($entry['encounter_id'] ?? ''),
            (string)($entry['entity_type'] ?? ''),
            (string)($entry['entity_id'] ?? ''),
            (string)($entry['actor_source'] ?? ''),
            (string)($entry['actor_user_id'] ?? ''),
            (string)($entry['actor_module_user_id'] ?? ''),
            (string)($details['denial_reason'] ?? ''),
            (string)($details['attempted_action'] ?? ''),
        ];
    }

    return $rows;
}

function rptSummaryCsvRows(array $summary): array
{
    $rows = [];

    $appointmentFlow = is_array($summary['appointment_flow'] ?? null) ? $summary['appointment_flow'] : [];
    $encounterVolume = is_array($summary['encounter_volume'] ?? null) ? $summary['encounter_volume'] : [];
    $turnaround = is_array($summary['turnaround_time'] ?? null) ? $summary['turnaround_time'] : [];
    $activity = is_array($summary['user_activity'] ?? null) ? $summary['user_activity'] : [];

    $rows[] = ['appointment_flow', 'total', (string)($appointmentFlow['total'] ?? 0)];
    foreach ((array)($appointmentFlow['by_status'] ?? []) as $status => $count) {
        $rows[] = ['appointment_flow', 'status:' . (string)$status, (string)(int)$count];
    }

    $rows[] = ['encounter_volume', 'total', (string)($encounterVolume['total'] ?? 0)];
    $rows[] = ['encounter_volume', 'open_count', (string)($encounterVolume['open_count'] ?? 0)];
    $rows[] = ['encounter_volume', 'completed_count', (string)($encounterVolume['completed_count'] ?? 0)];
    foreach ((array)($encounterVolume['by_status'] ?? []) as $status => $count) {
        $rows[] = ['encounter_volume', 'status:' . (string)$status, (string)(int)$count];
    }

    foreach (['released_count', 'average_minutes', 'minimum_minutes', 'maximum_minutes'] as $metric) {
        $rows[] = ['turnaround_time', $metric, (string)($turnaround[$metric] ?? '')];
    }

    $rows[] = ['user_activity', 'total_events', (string)($activity['total_events'] ?? 0)];
    foreach ((array)($activity['top_modules'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = ['user_activity.top_modules', (string)($row['module'] ?? ''), (string)($row['count'] ?? 0)];
    }
    foreach ((array)($activity['top_actions'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = ['user_activity.top_actions', (string)($row['action'] ?? ''), (string)($row['count'] ?? 0)];
    }

    return $rows;
}

function rptSendCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        echo 'Unable to stream CSV';
        return;
    }

    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}

function rptFetchAppointmentFlow(array $data): array
{
    $where = ['1=1'];
    $params = [];
    rptApplyFacilityFilters($data, $where, $params);
    rptApplyDateRange($data, 'scheduled_start', $where, $params, 'apt');

    $sql = 'SELECT status, COUNT(*) AS aggregate_count FROM ehr_appointments WHERE ' . implode(' AND ', $where) . ' GROUP BY status';
    $rows = rptDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $byStatus = [];
    $total = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = (string)($row['status'] ?? 'unknown');
        $count = (int)($row['aggregate_count'] ?? 0);
        $byStatus[$status] = $count;
        $total += $count;
    }

    return [
        'total' => $total,
        'by_status' => $byStatus,
    ];
}

function rptFetchEncounterVolume(array $data): array
{
    $where = ['1=1'];
    $params = [];
    rptApplyFacilityFilters($data, $where, $params);
    rptApplyDateRange($data, 'start_at', $where, $params, 'enc');

    $sql = 'SELECT status, COUNT(*) AS aggregate_count FROM ehr_encounters WHERE ' . implode(' AND ', $where) . ' GROUP BY status';
    $rows = rptDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $byStatus = [];
    $total = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = (string)($row['status'] ?? 'unknown');
        $count = (int)($row['aggregate_count'] ?? 0);
        $byStatus[$status] = $count;
        $total += $count;
    }

    return [
        'total' => $total,
        'by_status' => $byStatus,
        'open_count' => (int)($byStatus['open'] ?? 0),
        'completed_count' => (int)($byStatus['completed'] ?? 0),
    ];
}

function rptFetchTurnaround(array $data): array
{
    $where = ['released_at IS NOT NULL'];
    $params = [];
    rptApplyDateRange($data, 'released_at', $where, $params, 'res');

    $sql = 'SELECT COUNT(*) AS released_count, '
        . 'AVG(TIMESTAMPDIFF(MINUTE, observed_at, released_at)) AS average_minutes, '
        . 'MIN(TIMESTAMPDIFF(MINUTE, observed_at, released_at)) AS minimum_minutes, '
        . 'MAX(TIMESTAMPDIFF(MINUTE, observed_at, released_at)) AS maximum_minutes '
        . 'FROM ehr_lab_results WHERE ' . implode(' AND ', $where);
    $row = rptDb()->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);
    $releasedCount = is_array($row) ? (int)($row['released_count'] ?? 0) : 0;

    return [
        'released_count' => $releasedCount,
        'average_minutes' => $releasedCount > 0 ? (float)($row['average_minutes'] ?? 0.0) : null,
        'minimum_minutes' => $releasedCount > 0 ? (int)($row['minimum_minutes'] ?? 0) : null,
        'maximum_minutes' => $releasedCount > 0 ? (int)($row['maximum_minutes'] ?? 0) : null,
    ];
}

function rptFetchActivity(array $data): array
{
    $where = ['1=1'];
    $params = [];
    rptApplyDateRange($data, 'created_at', $where, $params, 'aud');

    $countRow = rptDb()->query('SELECT COUNT(*) FROM audit_logs WHERE ' . implode(' AND ', $where), $params)->fetchColumn();
    $moduleSql = 'SELECT module, COUNT(*) AS aggregate_count FROM audit_logs WHERE ' . implode(' AND ', $where) . ' GROUP BY module ORDER BY aggregate_count DESC, module ASC LIMIT 10';
    $actionSql = 'SELECT action, COUNT(*) AS aggregate_count FROM audit_logs WHERE ' . implode(' AND ', $where) . ' GROUP BY action ORDER BY aggregate_count DESC, action ASC LIMIT 10';
    $moduleRows = rptDb()->query($moduleSql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $actionRows = rptDb()->query($actionSql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $modules = [];
    foreach ($moduleRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $modules[] = [
            'module' => (string)($row['module'] ?? ''),
            'count' => (int)($row['aggregate_count'] ?? 0),
        ];
    }

    $actions = [];
    foreach ($actionRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $actions[] = [
            'action' => (string)($row['action'] ?? ''),
            'count' => (int)($row['aggregate_count'] ?? 0),
        ];
    }

    return [
        'total_events' => (int)$countRow,
        'top_modules' => $modules,
        'top_actions' => $actions,
    ];
}

function rptDecodeJson(mixed $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function rptAuditLogHasColumn(string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $safeColumn = preg_replace('/[^a-z0-9_]+/i', '', $column);
    if ($safeColumn === '') {
        $cache[$column] = false;
        return false;
    }

    try {
        rptDb()->query('SELECT `' . $safeColumn . '` FROM audit_logs LIMIT 0');
    } catch (\Throwable) {
        $cache[$column] = false;
        return false;
    }

    $cache[$column] = true;
    return true;
}

function rptApplyComplianceActorFilters(array $data, array &$where, array &$params): void
{
    if (isset($data['actor_user_id']) && (int)$data['actor_user_id'] > 0) {
        if (!rptAuditLogHasColumn('actor_user_id')) {
            $where[] = '1=0';
            return;
        }
        $where[] = 'actor_user_id = :cmp_actor_user_id';
        $params[':cmp_actor_user_id'] = (int)$data['actor_user_id'];
    }

    if (isset($data['actor_module_user_id']) && (int)$data['actor_module_user_id'] > 0) {
        if (!rptAuditLogHasColumn('actor_module_user_id')) {
            $where[] = '1=0';
            return;
        }
        $where[] = 'actor_module_user_id = :cmp_actor_module_user_id';
        $params[':cmp_actor_module_user_id'] = (int)$data['actor_module_user_id'];
    }

    $actorSource = trim((string)($data['actor_source'] ?? ''));
    if ($actorSource !== '') {
        if (!rptAuditLogHasColumn('actor_source')) {
            $where[] = '1=0';
            return;
        }
        $where[] = 'actor_source = :cmp_actor_source';
        $params[':cmp_actor_source'] = $actorSource;
    }
}

function rptSensitiveDocumentView(array $newData): bool
{
    $sensitivity = strtolower(trim((string)($newData['sensitivity_level'] ?? '')));
    $policy = is_array($newData['policy'] ?? null) ? $newData['policy'] : [];
    $policySensitivity = strtolower(trim((string)($policy['sensitivity_level'] ?? '')));

    return $sensitivity === 'restricted'
        || $policySensitivity === 'restricted'
        || !empty($policy['break_glass_only_flag'])
        || !empty($policy['consent_required_flag']);
}

function rptSensitiveRestrictedFlag(array $newData, array $oldData = []): bool
{
    return !empty($newData['restricted_flag']) || !empty($oldData['restricted_flag']);
}

function rptComplianceWhere(array $data): array
{
    $where = [
        '(action = :doc_view OR action = :doc_denied OR action = :doc_print OR action = :doc_export OR action = :doc_restrict OR action = :note_view OR action = :note_denied OR action = :result_view OR action = :result_denied OR action = :break_glass)',
    ];
    $params = [
        ':doc_view' => 'ehr.document.viewed',
        ':doc_denied' => 'ehr.document.access_denied',
        ':doc_print' => 'ehr.document.printed',
        ':doc_export' => 'ehr.document.exported',
        ':doc_restrict' => 'ehr.document.restricted',
        ':note_view' => 'ehr.note.viewed',
        ':note_denied' => 'ehr.note.access_denied',
        ':result_view' => 'ehr.result.viewed',
        ':result_denied' => 'ehr.result.access_denied',
        ':break_glass' => 'ehr.break_glass.accessed',
    ];
    rptApplyDateRange($data, 'created_at', $where, $params, 'cmp');
    if (isset($data['patient_id']) && (int)$data['patient_id'] > 0) {
        $where[] = "(JSON_UNQUOTE(JSON_EXTRACT(new_data, '$.patient_id')) = :cmp_patient_new OR JSON_UNQUOTE(JSON_EXTRACT(old_data, '$.patient_id')) = :cmp_patient_old)";
        $params[':cmp_patient_new'] = (string)(int)$data['patient_id'];
        $params[':cmp_patient_old'] = (string)(int)$data['patient_id'];
    }
    rptApplyComplianceActorFilters($data, $where, $params);

    return [$where, $params];
}

function rptComplianceEntry(array $row): ?array
{
    $newData = rptDecodeJson($row['new_data'] ?? null);
    $oldData = rptDecodeJson($row['old_data'] ?? null);
    $action = (string)($row['action'] ?? '');
    $attemptedAction = strtolower(trim((string)($newData['attempted_action'] ?? '')));
    $category = null;

    if ($action === 'ehr.break_glass.accessed') {
        $category = 'break_glass';
    } elseif ($action === 'ehr.document.access_denied') {
        if ($attemptedAction === 'print') {
            $category = 'restricted_document_print_denial';
        } elseif ($attemptedAction === 'export') {
            $category = 'restricted_document_export_denial';
        } else {
            $category = 'restricted_document_view_denial';
        }
    } elseif ($action === 'ehr.note.access_denied') {
        $category = 'restricted_note_view_denial';
    } elseif ($action === 'ehr.result.access_denied') {
        $category = 'restricted_result_view_denial';
    } elseif ($action === 'ehr.document.printed') {
        $category = 'record_print';
    } elseif ($action === 'ehr.document.exported') {
        $category = 'record_export';
    } elseif ($action === 'ehr.document.restricted') {
        $category = 'restricted_policy_change';
    } elseif ($action === 'ehr.document.viewed' && rptSensitiveDocumentView($newData)) {
        $category = 'restricted_document_access';
    } elseif ($action === 'ehr.note.viewed' && rptSensitiveRestrictedFlag($newData, $oldData)) {
        $category = 'restricted_note_access';
    } elseif ($action === 'ehr.result.viewed' && rptSensitiveRestrictedFlag($newData, $oldData)) {
        $category = 'restricted_result_access';
    }

    if ($category === null) {
        return null;
    }

    return [
        'category' => $category,
        'action' => $action,
        'module' => (string)($row['module'] ?? ''),
        'entity_type' => (string)($row['entity_type'] ?? ''),
        'entity_id' => trim((string)($row['entity_id'] ?? '')),
        'actor_user_id' => isset($row['actor_user_id']) ? (int)$row['actor_user_id'] : 0,
        'actor_module_user_id' => isset($row['actor_module_user_id']) ? (int)$row['actor_module_user_id'] : 0,
        'actor_source' => (string)($row['actor_source'] ?? ''),
        'patient_id' => (int)($newData['patient_id'] ?? $oldData['patient_id'] ?? 0),
        'encounter_id' => (int)($newData['encounter_id'] ?? $oldData['encounter_id'] ?? 0),
        'created_at' => (string)($row['created_at'] ?? ''),
        'details' => [
            'new_data' => $newData,
            'old_data' => $oldData,
        ],
    ];
}

function rptFetchCompliance(array $data): array
{
    [$where, $params] = rptComplianceWhere($data);
    $limit = max(1, min(200, (int)($data['limit'] ?? 50)));
    $page = max(1, (int)($data['page'] ?? 1));
    $offset = ($page - 1) * $limit;
    $hasActorUserId = rptAuditLogHasColumn('actor_user_id');
    $hasActorModuleUserId = rptAuditLogHasColumn('actor_module_user_id');
    $hasActorSource = rptAuditLogHasColumn('actor_source');
    $select = 'id, module, action, entity_type, entity_id, old_data, new_data, created_at';
    if ($hasActorUserId) {
        $select .= ', actor_user_id';
    }
    if ($hasActorModuleUserId) {
        $select .= ', actor_module_user_id';
    }
    if ($hasActorSource) {
        $select .= ', actor_source';
    }
    $countSql = 'SELECT COUNT(*) FROM audit_logs WHERE ' . implode(' AND ', $where);
    $total = (int)rptDb()->query($countSql, $params)->fetchColumn();
    $sql = 'SELECT ' . $select . ' '
        . 'FROM audit_logs WHERE ' . implode(' AND ', $where)
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    $rows = rptDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $entries = [];
    $summary = [
        'total_sensitive_events' => 0,
        'break_glass_events' => 0,
        'denied_access_events' => 0,
        'denied_view_events' => 0,
        'denied_print_events' => 0,
        'denied_export_events' => 0,
        'print_events' => 0,
        'export_events' => 0,
        'restricted_record_views' => 0,
        'restricted_document_views' => 0,
        'restricted_note_views' => 0,
        'restricted_result_views' => 0,
        'restricted_policy_changes' => 0,
    ];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $entry = rptComplianceEntry($row);
        if ($entry === null) {
            continue;
        }
        $entries[] = $entry;
        $summary['total_sensitive_events']++;
        if ($entry['category'] === 'break_glass') {
            $summary['break_glass_events']++;
        } elseif ($entry['category'] === 'restricted_document_view_denial') {
            $summary['denied_access_events']++;
            $summary['denied_view_events']++;
        } elseif ($entry['category'] === 'restricted_note_view_denial') {
            $summary['denied_access_events']++;
            $summary['denied_view_events']++;
        } elseif ($entry['category'] === 'restricted_result_view_denial') {
            $summary['denied_access_events']++;
            $summary['denied_view_events']++;
        } elseif ($entry['category'] === 'restricted_document_print_denial') {
            $summary['denied_access_events']++;
            $summary['denied_print_events']++;
        } elseif ($entry['category'] === 'restricted_document_export_denial') {
            $summary['denied_access_events']++;
            $summary['denied_export_events']++;
        } elseif ($entry['category'] === 'record_print') {
            $summary['print_events']++;
        } elseif ($entry['category'] === 'record_export') {
            $summary['export_events']++;
        } elseif ($entry['category'] === 'restricted_document_access') {
            $summary['restricted_record_views']++;
            $summary['restricted_document_views']++;
        } elseif ($entry['category'] === 'restricted_note_access') {
            $summary['restricted_record_views']++;
            $summary['restricted_note_views']++;
        } elseif ($entry['category'] === 'restricted_result_access') {
            $summary['restricted_record_views']++;
            $summary['restricted_result_views']++;
        } elseif ($entry['category'] === 'restricted_policy_change') {
            $summary['restricted_policy_changes']++;
        }
    }

    return [
        'summary' => $summary,
        'entries' => $entries,
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $limit > 0 ? max(1, (int)ceil($total / $limit)) : 1,
        ],
    ];
}

function reporting_cap_ehr_reporting_summary_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];

    try {
        return [
            'ok' => true,
            'summary' => [
                'appointment_flow' => rptFetchAppointmentFlow($data),
                'encounter_volume' => rptFetchEncounterVolume($data),
                'turnaround_time' => rptFetchTurnaround($data),
                'user_activity' => rptFetchActivity($data),
            ],
        ];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Reporting summary failed', 'details' => $e->getMessage()];
    }
}

function reporting_cap_ehr_reporting_compliance_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];

    try {
        $report = rptFetchCompliance($data);
        return [
            'ok' => true,
            'summary' => $report['summary'],
            'entries' => $report['entries'],
            'meta' => $report['meta'],
        ];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Compliance reporting failed', 'details' => $e->getMessage()];
    }
}