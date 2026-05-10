<?php

declare(strict_types=1);

function cdsCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('analytics-cds');
    if (!$ctx) {
        throw new \RuntimeException('Analytics & CDS module context unavailable');
    }
    return $ctx;
}

function cdsDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return cdsCtx()->db();
}

function cdsGenerateUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function cdsAudit(string $action, array $payload): void
{
    try {
        app()->cap()->call(
            'kernel.audit.record@1',
            array_merge(['action' => $action, 'actor_source' => 'analytics-cds'], $payload),
            ['caller_module' => 'analytics-cds']
        );
    } catch (\Throwable $e) {
        write_log('cds audit failed: ' . $e->getMessage(), 'warn');
    }
}

/**
 * Evaluate a single comparison expression against the supplied context.
 * Supported shapes:
 *   ['field' => 'a.b', 'op' => '>', 'value' => 5]
 *   ['all' => [ ... ]]  (AND)
 *   ['any' => [ ... ]]  (OR)
 */
function cdsEvalExpression(array $expr, array $context): bool
{
    if (isset($expr['all']) && is_array($expr['all'])) {
        foreach ($expr['all'] as $sub) {
            if (!is_array($sub) || !cdsEvalExpression($sub, $context)) {
                return false;
            }
        }
        return true;
    }
    if (isset($expr['any']) && is_array($expr['any'])) {
        foreach ($expr['any'] as $sub) {
            if (is_array($sub) && cdsEvalExpression($sub, $context)) {
                return true;
            }
        }
        return false;
    }

    $field = (string)($expr['field'] ?? '');
    $op = (string)($expr['op'] ?? '==');
    $expected = $expr['value'] ?? null;
    if ($field === '') {
        return false;
    }

    $actual = $context;
    foreach (explode('.', $field) as $segment) {
        if (is_array($actual) && array_key_exists($segment, $actual)) {
            $actual = $actual[$segment];
        } else {
            $actual = null;
            break;
        }
    }

    return match ($op) {
        '==', 'eq' => $actual == $expected,
        '!=', 'ne' => $actual != $expected,
        '>', 'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
        '<', 'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
        '>=', 'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
        '<=', 'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
        'in' => is_array($expected) && in_array($actual, $expected, true),
        'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
        default => false,
    };
}

function analytics_cds_cap_ehr_cds_rule_add_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $code = trim((string)($data['code'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));
    $expression = $data['expression'] ?? null;
    if ($code === '' || $name === '' || !is_array($expression)) {
        return ['ok' => false, 'error' => 'code, name, and expression (array) are required'];
    }

    $uuid = cdsGenerateUuid();
    try {
        cdsDb()->execute(
            'INSERT INTO ehr_cds_rules (rule_uuid, code, name, description, domain, severity, expression_json, active_flag) '
            . 'VALUES (:u, :c, :n, :d, :dom, :sev, :exp, :a)',
            [
                ':u' => $uuid,
                ':c' => $code,
                ':n' => $name,
                ':d' => trim((string)($data['description'] ?? '')) ?: null,
                ':dom' => trim((string)($data['domain'] ?? 'general')),
                ':sev' => trim((string)($data['severity'] ?? 'info')),
                ':exp' => json_encode($expression, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':a' => !empty($data['active_flag']) || !isset($data['active_flag']) ? 1 : 0,
            ]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Could not add rule', 'details' => $e->getMessage()];
    }
    $id = (int)cdsDb()->lastInsertId();
    cdsAudit('ehr.cds.rule.added', ['new_data' => ['rule_id' => $id, 'code' => $code]]);
    return ['ok' => true, 'rule_id' => $id, 'rule_uuid' => $uuid];
}

function analytics_cds_cap_ehr_cds_rule_list_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $where = ['1=1'];
    $params = [];
    if (!empty($data['domain'])) {
        $where[] = 'domain = :dom';
        $params[':dom'] = (string)$data['domain'];
    }
    if (array_key_exists('active_flag', $data)) {
        $where[] = 'active_flag = :a';
        $params[':a'] = !empty($data['active_flag']) ? 1 : 0;
    }
    $rows = cdsDb()->query(
        'SELECT * FROM ehr_cds_rules WHERE ' . implode(' AND ', $where) . ' ORDER BY domain ASC, name ASC',
        $params
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    return ['ok' => true, 'rules' => $rows];
}

function analytics_cds_cap_ehr_cds_evaluate_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $domain = trim((string)($data['domain'] ?? ''));
    $context = is_array($data['context'] ?? null) ? $data['context'] : [];
    $patientId = (int)($data['patient_id'] ?? 0);

    $where = ['active_flag = 1'];
    $params = [];
    if ($domain !== '') {
        $where[] = 'domain = :dom';
        $params[':dom'] = $domain;
    }
    $rules = cdsDb()->query(
        'SELECT * FROM ehr_cds_rules WHERE ' . implode(' AND ', $where),
        $params
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $alerts = [];
    foreach ($rules as $rule) {
        $expr = json_decode((string)$rule['expression_json'], true);
        if (!is_array($expr)) {
            continue;
        }
        $matched = cdsEvalExpression($expr, $context);

        cdsDb()->execute(
            'INSERT INTO ehr_cds_evaluations (rule_id, patient_id, context_json, matched_flag) VALUES (:r, :p, :c, :m)',
            [
                ':r' => (int)$rule['id'],
                ':p' => $patientId > 0 ? $patientId : null,
                ':c' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':m' => $matched ? 1 : 0,
            ]
        );

        if (!$matched) {
            continue;
        }

        $message = (string)($rule['name'] ?? 'CDS alert');
        cdsDb()->execute(
            'INSERT INTO ehr_cds_alerts (rule_id, patient_id, severity, message, context_json) VALUES (:r, :p, :s, :m, :c)',
            [
                ':r' => (int)$rule['id'],
                ':p' => $patientId > 0 ? $patientId : null,
                ':s' => (string)($rule['severity'] ?? 'info'),
                ':m' => $message,
                ':c' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
        $alertId = (int)cdsDb()->lastInsertId();
        $alerts[] = [
            'alert_id' => $alertId,
            'rule_id' => (int)$rule['id'],
            'rule_code' => (string)$rule['code'],
            'severity' => (string)$rule['severity'],
            'message' => $message,
        ];
        cdsAudit('ehr.cds.alert.fired', [
            'patient_id' => $patientId > 0 ? $patientId : null,
            'new_data' => ['alert_id' => $alertId, 'rule_id' => (int)$rule['id']],
        ]);
        app()->events()->fire('ehr.cds.alert.fired', ['alert_id' => $alertId, 'rule_id' => (int)$rule['id'], 'patient_id' => $patientId]);
    }

    return ['ok' => true, 'alerts' => $alerts, 'evaluated' => count($rules)];
}

function analytics_cds_cap_ehr_cds_alert_list_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $where = ['1=1'];
    $params = [];
    if (!empty($data['status'])) {
        $where[] = 'status = :st';
        $params[':st'] = (string)$data['status'];
    }
    if (!empty($data['patient_id'])) {
        $where[] = 'patient_id = :pid';
        $params[':pid'] = (int)$data['patient_id'];
    }
    $limit = max(1, min(200, (int)($data['limit'] ?? 50)));
    $rows = cdsDb()->query(
        'SELECT a.*, r.code AS rule_code, r.name AS rule_name FROM ehr_cds_alerts a '
        . 'LEFT JOIN ehr_cds_rules r ON r.id = a.rule_id WHERE ' . implode(' AND ', $where) . ' '
        . 'ORDER BY a.created_at DESC LIMIT ' . $limit,
        $params
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    return ['ok' => true, 'alerts' => $rows];
}

function analytics_cds_cap_ehr_cds_alert_acknowledge_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $alertId = (int)($data['alert_id'] ?? 0);
    $userId = (int)($data['acknowledged_by_user_id'] ?? 0);
    if ($alertId <= 0) {
        return ['ok' => false, 'error' => 'alert_id is required'];
    }
    $existing = cdsDb()->query('SELECT * FROM ehr_cds_alerts WHERE id = :id LIMIT 1', [':id' => $alertId])->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($existing)) {
        return ['ok' => false, 'error' => 'Alert not found'];
    }
    if ((string)$existing['status'] === 'acknowledged') {
        return ['ok' => true, 'alert' => $existing];
    }
    cdsDb()->execute(
        'UPDATE ehr_cds_alerts SET status = :st, acknowledged_by_user_id = :u, acknowledged_at = NOW() WHERE id = :id',
        [':st' => 'acknowledged', ':u' => $userId > 0 ? $userId : null, ':id' => $alertId]
    );
    cdsAudit('ehr.cds.alert.acknowledged', ['new_data' => ['alert_id' => $alertId]]);
    return ['ok' => true];
}

function analytics_cds_cap_ehr_analytics_summary_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $sinceDays = max(1, min(365, (int)($data['since_days'] ?? 30)));
    $rules = (int)cdsDb()->query('SELECT COUNT(*) AS n FROM ehr_cds_rules WHERE active_flag = 1')->fetch(\PDO::FETCH_ASSOC)['n'];
    $evals = (int)cdsDb()->query(
        'SELECT COUNT(*) AS n FROM ehr_cds_evaluations WHERE evaluated_at > (NOW() - INTERVAL :d DAY)',
        [':d' => $sinceDays]
    )->fetch(\PDO::FETCH_ASSOC)['n'];
    $matched = (int)cdsDb()->query(
        'SELECT COUNT(*) AS n FROM ehr_cds_evaluations WHERE matched_flag = 1 AND evaluated_at > (NOW() - INTERVAL :d DAY)',
        [':d' => $sinceDays]
    )->fetch(\PDO::FETCH_ASSOC)['n'];
    $openAlerts = (int)cdsDb()->query("SELECT COUNT(*) AS n FROM ehr_cds_alerts WHERE status = 'open'")->fetch(\PDO::FETCH_ASSOC)['n'];

    return [
        'ok' => true,
        'summary' => [
            'window_days' => $sinceDays,
            'active_rules' => $rules,
            'evaluations' => $evals,
            'matched' => $matched,
            'open_alerts' => $openAlerts,
            'match_rate' => $evals > 0 ? round($matched / $evals, 4) : 0,
        ],
    ];
}
