<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

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

function runPlatformRequest(array $user): array
{
    $runnerPath = sys_get_temp_dir() . '/ikabud-platform-api-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $entrypoint = var_export(__DIR__ . '/../public/index.php', true);
    $serverExport = var_export([
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/api/v1/platform',
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

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$db = app()->db();
tenantSyncKernelMigrations($db);

$suffix = strtoupper(bin2hex(random_bytes(4)));
$externalReference = 'PLATFORM-' . $suffix;
$triggerMeta = json_encode(['test' => 'platform-api', 'suffix' => $suffix], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$db->prepare('DELETE FROM kernel_event_triggers WHERE module = ? AND event_key = ? AND capability_id = ?')
    ->execute(['kernel', 'workflow.transitioned', 'kernel.http.request_context@1']);

$db->prepare(
    'INSERT INTO kernel_event_triggers (module, event_key, capability_id, provider, is_enabled, priority, template, max_per_minute, retry_count, timeout_ms, meta, updated_by, created_at, updated_at) '
    . 'VALUES (?, ?, ?, NULL, 1, 80, ?, 20, 0, 4500, ?, 1, NOW(), NOW())'
)->execute([
    'kernel',
    'workflow.transitioned',
    'kernel.http.request_context@1',
    'Platform trace for {entity_id}',
    $triggerMeta,
]);

kernelEmitEvent('workflow.transitioned', [
    'workflow_key' => 'cms.content',
    'module' => 'kernel',
    'entity_type' => 'workflow_transition',
    'entity_id' => '987',
    'from_state' => 'draft',
    'to_state' => 'published',
    'action' => 'publish',
    'external_reference' => $externalReference,
], 'kernel');

// Clear the log file after the execution so the platform payload must use persisted history.
file_put_contents(STORAGE_PATH . '/logs/app.log', '');

echo "\n=== ADMIN PLATFORM API ===\n";

$response = runPlatformRequest([
    'id' => 1,
    'username' => 'admin',
    'role' => 'admin',
    'source' => 'kernel',
]);

t('platform request exits cleanly', $response['exit_code'] === 0, 'exit=' . $response['exit_code']);
t('platform response decodes as json', is_array($response['json']));

$payload = is_array($response['json']) ? $response['json'] : [];
t('platform payload ok=true', !empty($payload['ok']));
t('platform payload includes traces', is_array($payload['traces'] ?? null));
t('platform payload includes trace timelines', is_array($payload['trace_timelines'] ?? null));

$traceRow = null;
foreach (($payload['traces'] ?? []) as $trace) {
    if (($trace['event'] ?? '') === 'workflow.transitioned' && ($trace['external_reference'] ?? '') === $externalReference) {
        $traceRow = $trace;
        break;
    }
}

t('platform traces include persisted execution after app log clear', is_array($traceRow), json_encode($payload['traces'] ?? []));
t('platform trace marks success status', is_array($traceRow) && ($traceRow['status'] ?? '') === 'success', json_encode($traceRow));
t('platform trace includes request id', is_array($traceRow) && is_string($traceRow['request_id'] ?? null) && ($traceRow['request_id'] ?? '') !== '', json_encode($traceRow));

$timelineRow = null;
foreach (($payload['trace_timelines'] ?? []) as $timeline) {
    if (($timeline['external_reference'] ?? '') === $externalReference) {
        $timelineRow = $timeline;
        break;
    }
}

t('platform timelines include external-reference group', is_array($timelineRow), json_encode($payload['trace_timelines'] ?? []));
t('platform timeline records latest success status', is_array($timelineRow) && ($timelineRow['latest_status'] ?? '') === 'success', json_encode($timelineRow));
t('platform trigger summary includes execution count', (int)(($payload['triggers'] ?? [])['executions'] ?? 0) >= 1, json_encode($payload['triggers'] ?? []));

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