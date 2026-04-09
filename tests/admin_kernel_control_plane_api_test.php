<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

use Ikabud\Kernel\Database\MigrationRunner;

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function runKernelApiRequest(string $uri, array $user): array
{
    $runnerPath = sys_get_temp_dir() . '/ikabud-kernel-control-plane-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $entrypoint = var_export(__DIR__ . '/../public/index.php', true);
    $serverExport = var_export([
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => $uri,
        'HTTP_HOST' => 'applicationos.test',
        'HTTP_ACCEPT' => 'application/json',
    ], true);
    $userExport = var_export($user, true);

    $script = "<?php\n"
        . "foreach ({$serverExport} as \$key => \$value) { \$_SERVER[(string) \$key] = \$value; }\n"
        . "\$_GET = [];\n"
        . "\$_REQUEST = [];\n"
        . "\$_SERVER['SCRIPT_NAME'] = '/public/index.php';\n"
        . "\$_SERVER['PHP_SELF'] = '/public/index.php';\n"
        . "require {$bootstrap};\n"
        . "app()->setUser({$userExport});\n"
        . "register_shutdown_function(static function (): void { echo \"\\n__HEADERS__\\n\"; echo json_encode(headers_list(), JSON_UNESCAPED_SLASHES); });\n"
        . "require {$entrypoint};\n";

    file_put_contents($runnerPath, $script);
    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPath);

    $stdout = implode("\n", $output);
    $parts = explode("\n__HEADERS__\n", $stdout, 2);
    $body = $parts[0] ?? '';
    $headers = isset($parts[1]) ? json_decode($parts[1], true) : [];
    if (!is_array($headers)) {
        $headers = [];
    }

    $decoded = json_decode($body, true);

    return [
        'exit_code' => $exitCode,
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
        'headers' => $headers,
    ];
}

function findByField(array $rows, string $field, string $value): ?array
{
    foreach ($rows as $row) {
        if (is_array($row) && (string)($row[$field] ?? '') === $value) {
            return $row;
        }
    }

    return null;
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$db = app()->db();
tenantSyncKernelMigrations($db);
$runner = new MigrationRunner($db);
$runner->migrate('wms');

$suffix = strtoupper(bin2hex(random_bytes(4)));
$triggerMeta = json_encode(['test' => 'kernel-control-plane', 'suffix' => $suffix], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$integrationName = 'Kernel Control Plane ' . $suffix;
$externalReference = 'TRACE-' . $suffix;
$mapping = [
    'module' => '_kernel',
    'action' => '{{action}}',
    'entity_type' => 'workflow_transition',
    'entity_id' => '{{entity_id}}',
];

$db->prepare('DELETE FROM kernel_event_triggers WHERE module = ? AND event_key = ? AND capability_id = ?')
    ->execute(['kernel', 'workflow.transitioned', 'kernel.http.request_context@1']);
$db->prepare('DELETE FROM kernel_integration_logs WHERE integration_id IN (SELECT id FROM kernel_integrations WHERE name = ?)')
    ->execute([$integrationName]);
$db->prepare('DELETE FROM kernel_integrations WHERE name = ?')->execute([$integrationName]);

$db->prepare(
    'INSERT INTO kernel_event_triggers (module, event_key, capability_id, provider, is_enabled, priority, template, max_per_minute, retry_count, timeout_ms, meta, updated_by, created_at, updated_at) '
    . 'VALUES (?, ?, ?, NULL, 1, 70, ?, 15, 1, 4500, ?, 1, NOW(), NOW())'
)->execute([
    'kernel',
    'workflow.transitioned',
    'kernel.http.request_context@1',
    'Workflow moved from {from_state} to {to_state}',
    $triggerMeta,
]);

$db->prepare(
    'INSERT INTO kernel_integrations (name, trigger_event, target_capability, mapping_json, is_active, event_source, version_lock, created_at, updated_at) '
    . 'VALUES (?, ?, ?, ?, 1, ?, NULL, NOW(), NOW())'
)->execute([
    $integrationName,
    'workflow.transitioned',
    'kernel.http.request_context',
    json_encode($mapping, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    'eventbus',
]);
$integrationId = (int)$db->lastInsertId();

$db->prepare(
    'INSERT INTO kernel_integration_logs (integration_id, status, payload_in, payload_out, error_message, created_at) VALUES (?, ?, ?, ?, NULL, NOW())'
)->execute([
    $integrationId,
    'success',
    json_encode(['workflow_key' => 'cms.content', 'action' => 'publish', 'entity_id' => '123'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    json_encode(['ok' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);

kernelEmitEvent('workflow.transitioned', [
    'workflow_key' => 'cms.content',
    'module' => 'kernel',
    'entity_type' => 'workflow_transition',
    'entity_id' => '123',
    'from_state' => 'review',
    'to_state' => 'published',
    'action' => 'publish',
    'external_reference' => $externalReference,
], 'kernel');

echo "\n=== ADMIN KERNEL CONTROL PLANE API ===\n";

$adminUser = [
    'id' => 1,
    'username' => 'admin',
    'role' => 'admin',
    'source' => 'kernel',
];

$superadminUser = [
    'id' => 2,
    'username' => 'superadmin',
    'role' => 'superadmin',
    'source' => 'kernel',
];

$eventsResponse = runKernelApiRequest('/api/v1/admin/kernel/events', $adminUser);
$triggersResponse = runKernelApiRequest('/api/v1/admin/kernel/triggers', $adminUser);
$executionsResponse = runKernelApiRequest('/api/v1/admin/kernel/trigger-executions?external_reference=' . rawurlencode($externalReference), $adminUser);
$integrationsResponse = runKernelApiRequest('/api/v1/kernel/integrations', $adminUser);
$superadminTriggersResponse = runKernelApiRequest('/api/v1/admin/kernel/triggers', $superadminUser);
$superadminExecutionsResponse = runKernelApiRequest('/api/v1/admin/kernel/trigger-executions?external_reference=' . rawurlencode($externalReference), $superadminUser);

t('events request exits cleanly', $eventsResponse['exit_code'] === 0, 'exit=' . $eventsResponse['exit_code']);
t('triggers request exits cleanly', $triggersResponse['exit_code'] === 0, 'exit=' . $triggersResponse['exit_code']);
t('executions request exits cleanly', $executionsResponse['exit_code'] === 0, 'exit=' . $executionsResponse['exit_code']);
t('integrations request exits cleanly', $integrationsResponse['exit_code'] === 0, 'exit=' . $integrationsResponse['exit_code']);
t('superadmin triggers request exits cleanly', $superadminTriggersResponse['exit_code'] === 0, 'exit=' . $superadminTriggersResponse['exit_code']);
t('superadmin executions request exits cleanly', $superadminExecutionsResponse['exit_code'] === 0, 'exit=' . $superadminExecutionsResponse['exit_code']);

$eventsPayload = is_array($eventsResponse['json']) ? $eventsResponse['json'] : [];
$triggersPayload = is_array($triggersResponse['json']) ? $triggersResponse['json'] : [];
$executionsPayload = is_array($executionsResponse['json']) ? $executionsResponse['json'] : [];
$integrationsPayload = is_array($integrationsResponse['json']) ? $integrationsResponse['json'] : [];
$superadminTriggersPayload = is_array($superadminTriggersResponse['json']) ? $superadminTriggersResponse['json'] : [];
$superadminExecutionsPayload = is_array($superadminExecutionsResponse['json']) ? $superadminExecutionsResponse['json'] : [];

t('events payload ok=true', !empty($eventsPayload['ok']));
t('triggers payload ok=true', !empty($triggersPayload['ok']));
t('executions payload ok=true', !empty($executionsPayload['ok']));
t('integrations payload ok=true', !empty($integrationsPayload['ok']));
t('superadmin triggers payload ok=true', !empty($superadminTriggersPayload['ok']));
t('superadmin executions payload ok=true', !empty($superadminExecutionsPayload['ok']));

t('events payload includes summary', is_array($eventsPayload['summary'] ?? null));
t('triggers payload includes summary', is_array($triggersPayload['summary'] ?? null));
t('executions payload includes summary', is_array($executionsPayload['summary'] ?? null));
t('integrations payload includes summary', is_array($integrationsPayload['summary'] ?? null));

$workflowEvent = null;
foreach (($eventsPayload['events'] ?? []) as $event) {
    if (($event['module'] ?? '') === 'kernel' && ($event['event_key'] ?? '') === 'workflow.transitioned') {
        $workflowEvent = $event;
        break;
    }
}

t('events payload contains workflow.transitioned', is_array($workflowEvent));
t('workflow event is marked registered', is_array($workflowEvent) && !empty($workflowEvent['registered']));
t('workflow event exposes trigger count', is_array($workflowEvent) && (int)($workflowEvent['trigger_count'] ?? 0) >= 1, json_encode($workflowEvent));
t('workflow event exposes available vars', is_array($workflowEvent) && in_array('workflow_key', $workflowEvent['available_vars'] ?? [], true), json_encode($workflowEvent));

$triggerRow = null;
foreach (($triggersPayload['triggers'] ?? []) as $trigger) {
    if (($trigger['module'] ?? '') === 'kernel'
        && ($trigger['event_key'] ?? '') === 'workflow.transitioned'
        && ($trigger['capability_id'] ?? '') === 'kernel.http.request_context@1') {
        $triggerRow = $trigger;
        break;
    }
}

t('triggers payload contains seeded trigger', is_array($triggerRow));
t('trigger row marks event registered', is_array($triggerRow) && !empty($triggerRow['event_registered']));
t('trigger row resolves capability metadata', is_array($triggerRow) && ($triggerRow['resolved_capability'] ?? '') === 'kernel.http.request_context@1', json_encode($triggerRow));
t('trigger row includes runtime registration flag', is_array($triggerRow) && !empty($triggerRow['capability_runtime_registered']));
t('trigger row includes event vars', is_array($triggerRow) && in_array('action', $triggerRow['available_vars'] ?? [], true), json_encode($triggerRow));
t('trigger row exposes last execution status', is_array($triggerRow) && ($triggerRow['last_execution_status'] ?? '') === 'success', json_encode($triggerRow));

$integrationRow = findByField($integrationsPayload['integrations'] ?? [], 'name', $integrationName);
t('integrations payload contains seeded bridge', is_array($integrationRow));
t('integration row marks event registered', is_array($integrationRow) && !empty($integrationRow['event_registered']));
t('integration row resolves alias to exact capability', is_array($integrationRow) && ($integrationRow['resolved_target_capability'] ?? '') === 'kernel.http.request_context@1', json_encode($integrationRow));
t('integration row exposes mapping vars', is_array($integrationRow) && in_array('action', $integrationRow['mapping_vars'] ?? [], true), json_encode($integrationRow));
t('integration row exposes last log status', is_array($integrationRow) && ($integrationRow['last_status'] ?? '') === 'success', json_encode($integrationRow));

$executionRow = null;
foreach (($executionsPayload['executions'] ?? []) as $execution) {
    if (($execution['event_key'] ?? '') === 'workflow.transitioned'
        && ($execution['capability_id'] ?? '') === 'kernel.http.request_context@1'
        && ($execution['external_reference'] ?? '') === $externalReference) {
        $executionRow = $execution;
        break;
    }
}

t('executions payload contains persisted trigger run', is_array($executionRow));
t('execution row records success status', is_array($executionRow) && ($executionRow['status'] ?? '') === 'success', json_encode($executionRow));
t('execution row resolves capability metadata', is_array($executionRow) && ($executionRow['resolved_capability'] ?? '') === 'kernel.http.request_context@1', json_encode($executionRow));
t('execution row keeps external reference filter', is_array($executionRow) && ($executionRow['external_reference'] ?? '') === $externalReference, json_encode($executionRow));
t('execution row stores correlation id', is_array($executionRow) && is_string($executionRow['correlation_id'] ?? null) && ($executionRow['correlation_id'] ?? '') !== '', json_encode($executionRow));

$logRow = null;
foreach (($integrationsPayload['logs'] ?? []) as $log) {
    if ((int)($log['integration_id'] ?? 0) === $integrationId) {
        $logRow = $log;
        break;
    }
}

t('integrations payload exposes seeded log row', is_array($logRow));
t('log row includes trigger event metadata', is_array($logRow) && ($logRow['trigger_event'] ?? '') === 'workflow.transitioned', json_encode($logRow));

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$appErrors = array_filter(explode("\n", $appLog), static fn(string $line): bool => str_contains($line, '[error]'));
t('no unexpected app.log errors', empty($appErrors), implode('; ', array_slice($appErrors, 0, 3)));

$phpErrors = array_filter(explode("\n", $errLog), static function (string $line): bool {
    $line = trim($line);
    if ($line === '') {
        return false;
    }
    if (str_contains($line, 'Ikabud Cache:')) {
        return false;
    }
    return true;
});
t('no php errors in error.log', empty($phpErrors), implode('; ', array_slice($phpErrors, 0, 3)));

$db->prepare('DELETE FROM kernel_integration_logs WHERE integration_id = ?')->execute([$integrationId]);
$db->prepare('DELETE FROM kernel_integrations WHERE id = ?')->execute([$integrationId]);
$db->prepare('DELETE FROM kernel_trigger_executions WHERE external_reference = ?')->execute([$externalReference]);
$db->prepare('DELETE FROM kernel_event_triggers WHERE module = ? AND event_key = ? AND capability_id = ?')
    ->execute(['kernel', 'workflow.transitioned', 'kernel.http.request_context@1']);

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);