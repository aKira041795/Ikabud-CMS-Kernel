<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function cdsAdminPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(500);
        echo 'EHR admin shell unavailable';
        return;
    }
    $user = ehrRequireAdmin();
    $rules = cdsDb()->query('SELECT * FROM ehr_cds_rules ORDER BY domain ASC, name ASC')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $alerts = cdsDb()->query(
        'SELECT a.*, r.code AS rule_code, r.name AS rule_name FROM ehr_cds_alerts a '
        . 'LEFT JOIN ehr_cds_rules r ON r.id = a.rule_id ORDER BY a.created_at DESC LIMIT 50'
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $summary = app()->cap()->call('ehr.analytics.summary@1', ['since_days' => 30], ['caller_module' => 'analytics-cds']);
    $summary = is_array($summary) && !empty($summary['ok']) ? $summary['summary'] : [];

    $context = ehrAdminContext($user, 'ehr_analytics_cds', [
        'page_title' => 'Insights',
        'cds_rules' => $rules,
        'cds_alerts' => $alerts,
        'cds_summary' => $summary,
        'rule_create_endpoint' => '/admin/ehr/analytics/rules',
        'alert_ack_endpoint' => '/admin/ehr/analytics/alerts/acknowledge',
        'evaluate_endpoint' => '/admin/ehr/analytics/evaluate',
    ]);
    echo ehrRender('modules/analytics-cds/admin/index.disyl', $context);
}

function cdsAdminRuleCreate(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }
    app()->csrfEnforce();
    ehrRequireAdmin();
    $input = app()->input();

    $exprRaw = (string)($input['expression_json'] ?? '');
    $expression = $exprRaw !== '' ? json_decode($exprRaw, true) : null;
    if (!is_array($expression)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'expression_json must be valid JSON object']);
        return;
    }

    $result = app()->cap()->call('ehr.cds.rule.add@1', [
        'code' => (string)($input['code'] ?? ''),
        'name' => (string)($input['name'] ?? ''),
        'description' => (string)($input['description'] ?? ''),
        'domain' => (string)($input['domain'] ?? 'general'),
        'severity' => (string)($input['severity'] ?? 'info'),
        'expression' => $expression,
    ], ['caller_module' => 'analytics-cds']);
    http_response_code(is_array($result) && !empty($result['ok']) ? 200 : 422);
    echo json_encode($result);
}

function cdsAdminAlertAcknowledge(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }
    app()->csrfEnforce();
    $user = ehrRequireAdmin();
    $input = app()->input();
    $result = app()->cap()->call('ehr.cds.alert.acknowledge@1', [
        'alert_id' => (int)($input['alert_id'] ?? 0),
        'acknowledged_by_user_id' => (int)($user['id'] ?? 0),
    ], ['caller_module' => 'analytics-cds']);
    http_response_code(is_array($result) && !empty($result['ok']) ? 200 : 422);
    echo json_encode($result);
}

function cdsAdminEvaluate(array $params = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!function_exists('ehrRequireAdmin')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'EHR admin shell unavailable']);
        return;
    }
    app()->csrfEnforce();
    ehrRequireAdmin();
    $input = app()->input();
    $contextRaw = (string)($input['context_json'] ?? '');
    $ctx = $contextRaw !== '' ? json_decode($contextRaw, true) : [];
    if (!is_array($ctx)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'context_json must be valid JSON object']);
        return;
    }
    $result = app()->cap()->call('ehr.cds.evaluate@1', [
        'domain' => (string)($input['domain'] ?? ''),
        'patient_id' => (int)($input['patient_id'] ?? 0),
        'context' => $ctx,
    ], ['caller_module' => 'analytics-cds']);
    http_response_code(is_array($result) && !empty($result['ok']) ? 200 : 422);
    echo json_encode($result);
}
