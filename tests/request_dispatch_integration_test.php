<?php
declare(strict_types=1);

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

function runRequestThroughEntrypoint(array $server, ?array $user = null, ?string $hookCode = null): array
{
    $runnerPath = sys_get_temp_dir() . '/ikabud-request-dispatch-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
    $bootstrap = var_export(__DIR__ . '/../bootstrap.php', true);
    $entrypoint = var_export(__DIR__ . '/../public/index.php', true);
    $serverExport = var_export($server, true);
    $userExport = var_export($user, true);
    $hook = $hookCode ?? '';

    $script = "<?php\n"
        . "require {$bootstrap};\n"
        . "foreach ({$serverExport} as \$key => \$value) { \$_SERVER[(string) \$key] = \$value; }\n"
        . "if (!isset(\$_SERVER['REQUEST_METHOD'])) { \$_SERVER['REQUEST_METHOD'] = 'GET'; }\n"
        . "if (!isset(\$_SERVER['REQUEST_URI'])) { \$_SERVER['REQUEST_URI'] = '/'; }\n"
        . "if (!isset(\$_SERVER['HTTP_HOST'])) { \$_SERVER['HTTP_HOST'] = 'applicationos.test'; }\n"
        . "\$user = {$userExport};\n"
        . "if (is_array(\$user)) { app()->setUser(\$user); }\n"
        . $hook . "\n"
        . "register_shutdown_function(static function (): void { echo \"\\n__CONTEXT__\\n\"; echo json_encode(kernelCurrentRequestDispatchContext() ?? [], JSON_UNESCAPED_SLASHES); echo \"\\n__HEADERS__\\n\"; echo json_encode(headers_list(), JSON_UNESCAPED_SLASHES); });\n"
        . "require {$entrypoint};\n";

    file_put_contents($runnerPath, $script);
    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath), $output, $exitCode);
    @unlink($runnerPath);

    $stdout = implode("\n", $output);
    $parts = explode("\n__CONTEXT__\n", $stdout, 2);
    $contextParts = isset($parts[1]) ? explode("\n__HEADERS__\n", $parts[1], 2) : [];
    $context = isset($contextParts[0]) ? json_decode($contextParts[0], true) : [];
    if (!is_array($context)) {
        $context = [];
    }

    $headers = isset($contextParts[1]) ? json_decode($contextParts[1], true) : [];
    if (!is_array($headers)) {
        $headers = [];
    }

    return [
        'exit_code' => $exitCode,
        'body' => $parts[0] ?? '',
        'context' => $context,
        'headers' => $headers,
        'raw' => $stdout,
    ];
}

echo "\n=== REQUEST DISPATCH ENTRYPOINT ===\n";

$intercepted = runRequestThroughEntrypoint(
    [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/request-dispatch-test',
        'HTTP_HOST' => 'applicationos.test',
    ],
    null,
    <<<'PHP'
app()->hooks()->on('kernel.request.before_dispatch', static function (array $context): array {
    if (kernelRequestDispatchPath($context) !== '/request-dispatch-test') {
        return $context;
    }

    echo 'intercepted';
    $context['handled'] = true;
    return $context;
}, -5000);
PHP
);
t('public entrypoint allows pre-dispatch hook short-circuit', ($intercepted['body'] ?? '') === 'intercepted', $intercepted['raw']);

$rootRedirect = runRequestThroughEntrypoint([
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/',
    'HTTP_HOST' => 'applicationos.test',
]);
t(
    'public entrypoint redirects unauthenticated root requests to login',
    ($rootRedirect['context']['redirect'] ?? '') === '/login',
    json_encode($rootRedirect['context'])
);

$loginRedirect = runRequestThroughEntrypoint(
    [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/login',
        'HTTP_HOST' => 'applicationos.test',
    ],
    [
        'id' => 1,
        'username' => 'root',
        'name' => 'Root User',
        'role' => 'superadmin',
        'source' => 'kernel',
    ]
);
t(
    'public entrypoint redirects authenticated kernel superadmin away from login',
    ($loginRedirect['context']['redirect'] ?? '') === '/superadmin/settings',
    json_encode($loginRedirect['context'])
);

$cmsLoginRedirect = runRequestThroughEntrypoint(
    [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/cms/login',
        'HTTP_HOST' => 'applicationos.test',
    ],
    [
        'id' => 11,
        'username' => 'cmsadmin',
        'name' => 'CMS Admin',
        'role' => 'administrator',
        'source' => 'cms',
    ]
);
t(
    'public entrypoint redirects authenticated CMS users away from CMS login',
    ($cmsLoginRedirect['context']['redirect'] ?? '') === '/cms/admin',
    json_encode($cmsLoginRedirect['context'])
);

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);