<?php

declare(strict_types=1);

/**
 * Focused oracle for tenant-login-entry-module-routing.
 *
 * DISCRIMINATING on the base tree: R1, R3, and R4.
 * PIN (passes on the base tree): R2 kernel-login and route-exemption guards.
 */

$base = dirname(__DIR__);
$appLog = $base . '/storage/logs/app.log';
$errorLog = $base . '/storage/logs/error.log';
$appLogBefore = is_file($appLog) ? (string)file_get_contents($appLog) : '';
$errorLogBefore = is_file($errorLog) ? (string)file_get_contents($errorLog) : '';
$failures = [];

function tlAssert(bool $condition, string $label, string $detail = ''): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $label . ($detail !== '' ? " ({$detail})" : '') . PHP_EOL;
    if (!$condition) {
        $failures[] = $label;
    }
}

function laneDeclaredLoginContext(array $overrides = []): array
{
    return array_merge(['source' => 'declared'], $overrides);
}

try {
    require $base . '/bootstrap.php';

    $router = new \Ikabud\Kernel\Http\TenantEntryRouter();

    $_SERVER['HTTP_HOST'] = 'baronledger.test';
    tlAssert($router->rewriteUri('/login') === '/daily-ledger/login', 'R1 DISCRIMINATING: tenant 207 /login resolves to module login');
    tlAssert($router->rewriteUri('/forgot-password') === '/daily-ledger/forgot-password', 'R1 DISCRIMINATING: module-owned forgot-password resolves when exposed');
    tlAssert($router->rewriteUri('/') === '/daily-ledger/login', 'R1 PIN: tenant 207 root landing is unchanged');
    tlAssert($router->rewriteUri('/api/v1/health') === '/api/v1/health', 'R2 PIN: API exemption remains unchanged');

    // Bakeshop exposes both /bakeshop and /bakeshop/login. This pair distinguishes
    // an explicit auth request from the legacy root-landing preference.
    $_SERVER['HTTP_HOST'] = 'juliesbakeshop.test';
    tlAssert($router->rewriteUri('/login') === '/bakeshop/login', 'R1 DISCRIMINATING: explicit /login prefers login route over module root');
    tlAssert($router->rewriteUri('/') === '/bakeshop', 'R1 PIN: root landing keeps module-root preference');

    foreach (['applicationkernel.test' => 'control-plane host', 'unknown-host.invalid' => 'unresolved host'] as $host => $label) {
        $_SERVER['HTTP_HOST'] = $host;
        tlAssert($router->rewriteUri('/login') === '/login', "R2 PIN: {$label} keeps kernel login");
    }

    $ownedAuthMethod = new ReflectionMethod($router, 'entryOwnedAuthPath');
    tlAssert($ownedAuthMethod->invoke($router, '/login', '') === null, 'R2 PIN: empty entry module keeps kernel login');
    tlAssert($ownedAuthMethod->invoke($router, '/login', 'kernel') === null, 'R2 PIN: kernel entry module keeps kernel login');
    tlAssert($ownedAuthMethod->invoke($router, '/login', 'example-notes') === null, 'R2 PIN: non-auth-owned entry module keeps kernel login');
    tlAssert($ownedAuthMethod->invoke($router, '/login', 'users') === null, 'R2 PIN: auth-owned entry with no login route keeps kernel login');

    $skipMethod = new ReflectionMethod($router, 'shouldSkipRewrite');
    foreach (['/api/example', '/admin/example', '/assets/example.css', '/superadmin/example', '/cms/example', '/daily-ledger/dashboard'] as $uri) {
        tlAssert($skipMethod->invoke($router, $uri, 'daily-ledger') === true, "R2 PIN: exemption remains for {$uri}");
    }

    // Exercise the real resolver in an isolated process so its function-based seams
    // can discriminate declared, derived-missing, healthy, and warning behavior.
    $resolverProbe = tempnam(sys_get_temp_dir(), 'tenant-login-context-');
    if ($resolverProbe === false) {
        throw new RuntimeException('Unable to create resolver probe');
    }
    $pageHandlers = var_export($base . '/src/http/page-handlers.php', true);
    $probeAppLog = var_export($appLog, true);
    $probeCode = <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['probe_module'] = 'declared-module';
$GLOBALS['probe_warnings'] = [];
$GLOBALS['probe_manifests'] = [
    'declared-module' => ['auth_owned' => ['login_context_callable' => 'fixtureLoginContext']],
    'missing-module' => ['auth_owned' => []],
];
final class ProbeTenant { public function current(): ?int { return 207; } }
final class ProbeApp { public function tenant(): ProbeTenant { static $tenant; return $tenant ??= new ProbeTenant(); } }
function app(): ProbeApp { static $app; return $app ??= new ProbeApp(); }
function tenantEntryModuleIdForTenant(int $tenantId): string { return $GLOBALS['probe_module']; }
function tenantEntryModuleDelegateId(string $moduleId): string { return $moduleId; }
function getEnabledModules(): array { return $GLOBALS['probe_manifests']; }
function loadModuleHelpers(array $module): void {}
function external_base_url(): string { return 'https://tenant.test'; }
function fixtureLoginContext(array $overrides = []): array { return array_merge(['source' => 'declared'], $overrides); }
function write_log(string $message, string $level = 'info', array $context = []): void {
    $GLOBALS['probe_warnings'][] = compact('message', 'level', 'context');
    file_put_contents(__APP_LOG__, json_encode(end($GLOBALS['probe_warnings'])) . PHP_EOL, FILE_APPEND);
}
$_SERVER['HTTP_HOST'] = 'baronledger.test';
$before = is_file(__APP_LOG__) ? (string)file_get_contents(__APP_LOG__) : '';
try {
    require __PAGE_HANDLERS__;
    $healthy = kernelResolveEntryModuleLoginContext();
    $healthyWarnings = count($GLOBALS['probe_warnings']);
    $GLOBALS['probe_module'] = 'missing-module';
    $missing = kernelResolveEntryModuleLoginContext();
    $warning = $GLOBALS['probe_warnings'][0] ?? [];
    $result = [
        'healthy_source' => $healthy['source'] ?? null,
        'healthy_warnings' => $healthyWarnings,
        'missing_defaulted' => ($missing['page_title'] ?? '') === 'Sign In',
        'warning_level' => $warning['level'] ?? null,
        'warning_context' => $warning['context'] ?? [],
    ];
} finally {
    file_put_contents(__APP_LOG__, $before);
}
echo json_encode($result, JSON_UNESCAPED_SLASHES);
PHP;
    $probeCode = str_replace(['__PAGE_HANDLERS__', '__APP_LOG__'], [$pageHandlers, $probeAppLog], $probeCode);
    file_put_contents($resolverProbe, $probeCode);
    $probeOutput = [];
    $probeStatus = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($resolverProbe), $probeOutput, $probeStatus);
    @unlink($resolverProbe);
    $probe = json_decode(implode("\n", $probeOutput), true);

    tlAssert($probeStatus === 0 && is_array($probe), 'R3/R4 resolver probe completed', implode("\n", $probeOutput));
    tlAssert(($probe['healthy_source'] ?? null) === 'declared', 'R4 DISCRIMINATING: manifest-declared login context callable is honoured');
    tlAssert(($probe['healthy_warnings'] ?? -1) === 0, 'R3 DISCRIMINATING: healthy declared callable emits no warning');
    $warningContext = is_array($probe['warning_context'] ?? null) ? $probe['warning_context'] : [];
    tlAssert(
        ($probe['warning_level'] ?? null) === 'warning'
        && ($warningContext['host'] ?? null) === 'baronledger.test'
        && ($warningContext['tenant_id'] ?? null) === 207
        && ($warningContext['entry_module_id'] ?? null) === 'missing-module'
        && ($warningContext['login_context_callable'] ?? null) === 'missing_moduleLoginPageContext',
        'R3 DISCRIMINATING: unresolved auth-owned context warning names host, tenant, module, and callable'
    );

    require_once $base . '/src/helpers/module-manager.php';
    $fixtureDir = sys_get_temp_dir() . '/tenant-login-manifest-' . bin2hex(random_bytes(5));
    mkdir($fixtureDir, 0700, true);
    $manifest = [
        'id' => 'tenant-login-fixture',
        'name' => 'Tenant Login Fixture',
        'version' => '1.0.0',
        'auth_owned' => [
            'users_table' => 'fixture_users',
            'admin_roles' => ['admin'],
            'login_context_callable' => 'laneDeclaredLoginContext',
        ],
    ];
    $manifestPath = $fixtureDir . '/module.json';
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
    $validDeclaration = validateModuleManifest($manifestPath);
    tlAssert(!empty($validDeclaration['ok']), 'R4 DISCRIMINATING: declared existing callable passes manifest validation', json_encode($validDeclaration));

    $manifest['auth_owned']['login_context_callable'] = 'laneMissingLoginContext';
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
    $missingDeclaration = validateModuleManifest($manifestPath);
    tlAssert(
        empty($missingDeclaration['ok']) && ($missingDeclaration['error_code'] ?? '') === 'manifest_invalid_auth_owned',
        'R4 DISCRIMINATING: declared missing callable fails manifest validation',
        json_encode($missingDeclaration)
    );
    @unlink($manifestPath);
    @rmdir($fixtureDir);
} catch (Throwable $e) {
    $failures[] = 'uncaught exception: ' . $e->getMessage();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
} finally {
    file_put_contents($appLog, $appLogBefore);
    file_put_contents($errorLog, $errorLogBefore);
}

exit($failures === [] ? 0 : 1);
