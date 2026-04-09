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

function runCapabilitiesRequest(array $server, array $user): array
{
    $runnerPath = sys_get_temp_dir() . '/ikabud-capabilities-api-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $entrypoint = var_export(__DIR__ . '/../public/index.php', true);
    $serverExport = var_export($server, true);
    $userExport = var_export($user, true);

    $script = "<?php\n"
        . "foreach ({$serverExport} as \$key => \$value) { \$_SERVER[(string) \$key] = \$value; }\n"
        . "if (!isset(\$_SERVER['REQUEST_METHOD'])) { \$_SERVER['REQUEST_METHOD'] = 'GET'; }\n"
        . "if (!isset(\$_SERVER['REQUEST_URI'])) { \$_SERVER['REQUEST_URI'] = '/api/v1/admin/capabilities'; }\n"
        . "if (!isset(\$_SERVER['HTTP_HOST'])) { \$_SERVER['HTTP_HOST'] = 'applicationos.test'; }\n"
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

echo "\n=== ADMIN CAPABILITIES API ===\n";

$response = runCapabilitiesRequest(
    [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/api/v1/admin/capabilities',
        'HTTP_HOST' => 'applicationos.test',
        'HTTP_ACCEPT' => 'application/json',
    ],
    [
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'kernel',
    ]
);

$superadminResponse = runCapabilitiesRequest(
    [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/api/v1/admin/capabilities',
        'HTTP_HOST' => 'applicationos.test',
        'HTTP_ACCEPT' => 'application/json',
    ],
    [
        'id' => 2,
        'username' => 'superadmin',
        'role' => 'superadmin',
        'source' => 'kernel',
    ]
);

t('request exits cleanly', $response['exit_code'] === 0, 'exit=' . $response['exit_code']);
t('response decodes as json', is_array($response['json']));

$payload = is_array($response['json']) ? $response['json'] : [];
t('response ok=true', !empty($payload['ok']));
t('response includes summary', is_array($payload['summary'] ?? null));
t('response includes modules', is_array($payload['modules'] ?? null));
t('response includes events', is_array($payload['events'] ?? null));
t('response includes capabilities', is_array($payload['capabilities'] ?? null));
t('superadmin request exits cleanly', $superadminResponse['exit_code'] === 0, 'exit=' . $superadminResponse['exit_code']);
t('superadmin response ok=true', !empty(($superadminResponse['json'] ?? [])['ok']));

$summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
t('summary includes runtime capability count', isset($summary['runtime_capability_count']));
t('summary includes declared capability count', isset($summary['declared_capability_count']));

$wmsCapability = null;
foreach (($payload['capabilities'] ?? []) as $capability) {
    if (($capability['id'] ?? '') === 'wms.order.create@1') {
        $wmsCapability = $capability;
        break;
    }
}

t('api returns wms.order.create capability entry', is_array($wmsCapability));
t('wms capability includes declared providers', is_array($wmsCapability['declared_providers'] ?? null) && !empty($wmsCapability['declared_providers'] ?? []));
t('wms capability includes runtime registration flag', is_array($wmsCapability) && array_key_exists('runtime_registered', $wmsCapability));

$wmsModule = null;
foreach (($payload['modules'] ?? []) as $module) {
    if (($module['id'] ?? '') === 'wms') {
        $wmsModule = $module;
        break;
    }
}

t('api returns wms module metadata', is_array($wmsModule));

$paymentEventFound = false;
foreach (($payload['events'] ?? []) as $event) {
    if (($event['key'] ?? '') === 'wms.order.payment_collected' && ($event['module'] ?? '') === 'wms') {
        $paymentEventFound = true;
        break;
    }
}
t('api returns wms payment collected event metadata', $paymentEventFound);

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