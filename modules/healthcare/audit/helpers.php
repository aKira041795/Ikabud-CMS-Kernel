<?php
declare(strict_types=1);

function audCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('audit');
    if (!$ctx) {
        throw new \RuntimeException('Audit module context unavailable');
    }

    return $ctx;
}

function audDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return audCtx()->db();
}

function audAuditLogHasColumn(string $column): bool
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
        audDb()->query('SELECT `' . $safeColumn . '` FROM audit_logs LIMIT 0');
    } catch (\Throwable) {
        $cache[$column] = false;
        return false;
    }

    $cache[$column] = true;
    return true;
}

function audSelectExpr(string $column, string $alias = ''): string
{
    $alias = $alias !== '' ? $alias : $column;
    return audAuditLogHasColumn($column)
        ? 'a.' . $column . ' AS ' . $alias
        : 'NULL AS ' . $alias;
}

function audDecodeJson(mixed $value): mixed
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function audIntOrNull(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (is_string($value) && preg_match('/^\d+$/', $value)) {
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }
    if (is_float($value)) {
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    return null;
}

function audExtractContextFromPayload(?array $payload, array &$context): void
{
    if (!$payload) {
        return;
    }

    foreach (['patient_id', 'encounter_id', 'appointment_id', 'document_id', 'order_id', 'result_id', 'prescription_id', 'consent_id'] as $field) {
        if (($context[$field] ?? null) !== null) {
            continue;
        }
        $context[$field] = audIntOrNull($payload[$field] ?? null);
    }
}

function audBuildContext(array $row): array
{
    $entityType = (string)($row['entity_type'] ?? '');
    $entityId = audIntOrNull($row['entity_id'] ?? null);
    $context = [
        'patient_id' => null,
        'encounter_id' => null,
        'appointment_id' => null,
        'document_id' => null,
        'order_id' => null,
        'result_id' => null,
        'prescription_id' => null,
        'consent_id' => null,
    ];

    if ($entityId !== null) {
        $context[match ($entityType) {
            'ehr_patient' => 'patient_id',
            'ehr_encounter' => 'encounter_id',
            'ehr_appointment' => 'appointment_id',
            'ehr_document' => 'document_id',
            'ehr_order' => 'order_id',
            'ehr_lab_result' => 'result_id',
            'ehr_prescription' => 'prescription_id',
            'ehr_consent' => 'consent_id',
            default => '_skip',
        }] = $entityType !== '' ? $entityId : null;
        unset($context['_skip']);
    }

    audExtractContextFromPayload(is_array($row['new_data'] ?? null) ? $row['new_data'] : null, $context);
    audExtractContextFromPayload(is_array($row['old_data'] ?? null) ? $row['old_data'] : null, $context);
    audExtractContextFromPayload(is_array($row['metadata'] ?? null) ? $row['metadata'] : null, $context);

    return array_filter($context, static fn(mixed $value): bool => $value !== null);
}

function audNormalizeDate(string $value, bool $endOfDay = false): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $suffix = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? ($endOfDay ? ' 23:59:59' : ' 00:00:00') : '';
    $timestamp = strtotime($value . $suffix);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function audSearchWhere(array $data): array
{
    $where = ['1=1'];
    $params = [];

    $module = trim((string)($data['module'] ?? ''));
    if ($module !== '') {
        $where[] = 'a.module = :module';
        $params[':module'] = $module;
    }

    $action = trim((string)($data['action'] ?? ''));
    if ($action !== '') {
        $where[] = 'a.action = :action';
        $params[':action'] = $action;
    }

    $entityType = trim((string)($data['entity_type'] ?? ''));
    if ($entityType !== '') {
        $where[] = 'a.entity_type = :entity_type';
        $params[':entity_type'] = $entityType;
    }

    $entityId = trim((string)($data['entity_id'] ?? ''));
    if ($entityId !== '') {
        $where[] = 'a.entity_id = :entity_id';
        $params[':entity_id'] = $entityId;
    }

    if (isset($data['branch_id']) && (int)$data['branch_id'] > 0) {
        $where[] = 'a.branch_id = :branch_id';
        $params[':branch_id'] = (int)$data['branch_id'];
    }

    if (isset($data['actor_user_id']) && (int)$data['actor_user_id'] > 0) {
        $where[] = 'a.actor_user_id = :actor_user_id';
        $params[':actor_user_id'] = (int)$data['actor_user_id'];
    }

    $actorSource = trim((string)($data['actor_source'] ?? ''));
    if ($actorSource !== '') {
        $where[] = 'a.actor_source = :actor_source';
        $params[':actor_source'] = $actorSource;
    }

    $dateFrom = audNormalizeDate((string)($data['date_from'] ?? ''), false);
    if ($dateFrom !== null) {
        $where[] = 'a.created_at >= :date_from';
        $params[':date_from'] = $dateFrom;
    }

    $dateTo = audNormalizeDate((string)($data['date_to'] ?? ''), true);
    if ($dateTo !== null) {
        $where[] = 'a.created_at <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    $patientId = (int)($data['patient_id'] ?? 0);
    if ($patientId > 0) {
        $where[] = "((a.entity_type = :patient_entity_type AND a.entity_id = :patient_entity_id) "
            . "OR JSON_UNQUOTE(JSON_EXTRACT(a.new_data, '$.patient_id')) = :patient_context_new "
            . "OR JSON_UNQUOTE(JSON_EXTRACT(a.old_data, '$.patient_id')) = :patient_context_old "
            . "OR JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.patient_id')) = :patient_context_meta)";
        $params[':patient_entity_type'] = 'ehr_patient';
        $params[':patient_entity_id'] = (string)$patientId;
        $params[':patient_context_new'] = (string)$patientId;
        $params[':patient_context_old'] = (string)$patientId;
        $params[':patient_context_meta'] = (string)$patientId;
    }

    $encounterId = (int)($data['encounter_id'] ?? 0);
    if ($encounterId > 0) {
        $where[] = "((a.entity_type = :encounter_entity_type AND a.entity_id = :encounter_entity_id) "
            . "OR JSON_UNQUOTE(JSON_EXTRACT(a.new_data, '$.encounter_id')) = :encounter_context_new "
            . "OR JSON_UNQUOTE(JSON_EXTRACT(a.old_data, '$.encounter_id')) = :encounter_context_old "
            . "OR JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.encounter_id')) = :encounter_context_meta)";
        $params[':encounter_entity_type'] = 'ehr_encounter';
        $params[':encounter_entity_id'] = (string)$encounterId;
        $params[':encounter_context_new'] = (string)$encounterId;
        $params[':encounter_context_old'] = (string)$encounterId;
        $params[':encounter_context_meta'] = (string)$encounterId;
    }

    return [$where, $params];
}

function audit_cap_ehr_audit_search_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    [$where, $params] = audSearchWhere($data);

    $limit = max(1, min(200, (int)($data['limit'] ?? 50)));
    $offset = max(0, (int)($data['offset'] ?? 0));
    $sql = 'SELECT a.id, a.module, a.actor_user_id, ' . audSelectExpr('actor_module_user_id') . ', '
        . audSelectExpr('actor_source') . ', a.branch_id, '
        . 'a.action, a.entity_type, a.entity_id, a.old_data, a.new_data, '
        . audSelectExpr('metadata_json') . ', a.created_at '
        . 'FROM audit_logs a WHERE ' . implode(' AND ', $where)
        . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    $countSql = 'SELECT COUNT(*) FROM audit_logs a WHERE ' . implode(' AND ', $where);

    try {
        $rows = audDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
        $count = (int)audDb()->query($countSql, $params)->fetchColumn();
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Audit search failed', 'details' => $e->getMessage()];
    }

    $entries = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['old_data'] = audDecodeJson($row['old_data'] ?? null);
        $row['new_data'] = audDecodeJson($row['new_data'] ?? null);
        $row['metadata'] = audDecodeJson($row['metadata_json'] ?? null);
        unset($row['metadata_json']);
        $row['context'] = audBuildContext($row);
        $entries[] = $row;
    }

    return [
        'ok' => true,
        'entries' => $entries,
        'pagination' => [
            'total' => $count,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $count,
        ],
    ];
}