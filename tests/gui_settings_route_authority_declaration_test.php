<?php

/**
 * GUI Settings route-authority declaration and dispatch coverage.
 *
 * ROLLBACK FALSIFIER: if any previously authorized caller is denied under the
 * same valid identity and tenant context, roll back the declaration. Temporary
 * no-deploy recovery: KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

use Ikabud\Kernel\Capabilities\AuthorityScope;
use Ikabud\Kernel\Capabilities\AuthorityScopeResolver;
use Ikabud\Kernel\Http\AuthorityDispatchGuard;

$passed = 0;
$failed = 0;
$lines = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed, &$lines): void {
    $ok ? ++$passed : ++$failed;
    $lines[] = ($ok ? 'PASS: ' : 'FAIL: ') . $label . (!$ok && $detail !== '' ? ' — ' . $detail : '');
};

$manifestPath = __DIR__ . '/../modules/gui-settings/module.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$expectedRoutes = [
    'GET /admin/gui-settings',
    'POST /api/v1/admin/gui-settings',
    'POST /api/v1/admin/gui-settings/reset',
];
$declaredRoutes = $manifest['capabilities']['routes'] ?? null;

$check(
    'REAL-MANIFEST pins exactly the three GUI Settings routes',
    is_array($declaredRoutes) && array_keys($declaredRoutes) === $expectedRoutes,
    json_encode(array_keys(is_array($declaredRoutes) ? $declaredRoutes : []))
);
foreach ($expectedRoutes as $routeKey) {
    $declaration = is_array($declaredRoutes) ? ($declaredRoutes[$routeKey] ?? null) : null;
    $check(
        "REAL-MANIFEST {$routeKey} pins admin and gui_settings.apply@1",
        is_array($declaration)
            && ($declaration['allowed_roles'] ?? null) === ['admin']
            && ($declaration['capability'] ?? null) === 'gui_settings.apply@1',
        json_encode($declaration)
    );
}

$previousEnv = getenv(AuthorityDispatchGuard::ENV_FLAG);
$previousEnvArray = $_ENV[AuthorityDispatchGuard::ENV_FLAG] ?? null;
putenv(AuthorityDispatchGuard::ENV_FLAG . '=true');
$_ENV[AuthorityDispatchGuard::ENV_FLAG] = 'true';

$scopeCalls = [];
$resolver = new AuthorityScopeResolver(
    static fn(int $tenantId): ?PDO => null,
    static function (?array $actor) use (&$scopeCalls): ?int {
        $scopeCalls[] = $actor;
        return ($actor['tenant_id'] ?? null) === 42 ? 42 : null;
    },
);

$identityShapes = [
    'tenant-session admin' => ['id' => 101, 'role' => 'admin', 'source' => 'gui-settings', 'tenant_id' => 42],
    'kernel-source admin' => ['id' => 102, 'role' => 'admin', 'source' => 'kernel', 'tenant_id' => 42],
    'token/API admin (normalized actor)' => ['id' => 103, 'role' => 'admin', 'source' => 'kernel', 'tenant_id' => 42, 'auth_transport' => 'bearer'],
    'impersonated tenant admin (normalized actor)' => ['id' => 104, 'role' => 'admin', 'source' => 'gui-settings', 'tenant_id' => 42, 'impersonator_id' => 7],
];

foreach ($expectedRoutes as $routeKey) {
    [$method, $path] = explode(' ', $routeKey, 2);
    foreach ($identityShapes as $identityLabel => $actor) {
        $decision = AuthorityDispatchGuard::decide('gui-settings', $method, $path, $path, $manifest, $actor, $resolver);
        $check(
            "MUST-ALLOW {$routeKey}: {$identityLabel}",
            $decision['allowed'] && $decision['configured'] && $decision['reason'] === 'allowed',
            json_encode($decision)
        );
    }
}

$scopeProbe = $resolver->resolve(AuthorityScopeResolver::WEB, ['actor' => $identityShapes['tenant-session admin']]);
$check(
    'MUST-ALLOW context resolves the verified tenant 42 web authority scope',
    $scopeProbe instanceof AuthorityScope
        && $scopeProbe->tenantId === 42
        && $scopeProbe->entryPoint === AuthorityScopeResolver::WEB
        && count($scopeCalls) === (count($expectedRoutes) * count($identityShapes)) + 1,
    $scopeProbe instanceof AuthorityScope ? json_encode(['tenant_id' => $scopeProbe->tenantId, 'entry_point' => $scopeProbe->entryPoint]) : 'scope unresolved'
);

foreach ($expectedRoutes as $routeKey) {
    [$method, $path] = explode(' ', $routeKey, 2);
    $decision = AuthorityDispatchGuard::decide(
        'gui-settings',
        $method,
        $path,
        $path,
        $manifest,
        ['id' => 201, 'role' => 'viewer', 'source' => 'gui-settings', 'tenant_id' => 42],
        $resolver,
    );
    $check(
        "MUST-REFUSE {$routeKey}: non-admin gets role_not_allowed",
        !$decision['allowed'] && $decision['configured'] && $decision['reason'] === 'role_not_allowed',
        json_encode($decision)
    );
}

$undeclared = AuthorityDispatchGuard::decide(
    'gui-settings',
    'GET',
    '/admin/gui-settings/undeclared',
    '/admin/gui-settings/undeclared',
    $manifest,
    ['id' => 202, 'role' => 'viewer', 'source' => 'gui-settings', 'tenant_id' => 42],
    $resolver,
);
$check(
    'PARITY: an undeclared GUI Settings route remains permitted',
    $undeclared['allowed'] && !$undeclared['configured'] && $undeclared['reason'] === 'no_authority_declared',
    json_encode($undeclared)
);

// Full executeModuleHandler proof with a temporary discovered module. The
// marker is written only by the fixture handler body, after the real guard and
// handler resolution points in executeModuleHandler.
$tempRoot = sys_get_temp_dir() . '/gui-settings-authority-' . bin2hex(random_bytes(6));
$marker = $tempRoot . '/handler-ran';
$originalServer = $_SERVER;
$hadDiscoveredModules = array_key_exists('_kernel_discovered_modules', $GLOBALS);
$originalDiscoveredModules = $GLOBALS['_kernel_discovered_modules'] ?? null;
$app = app();
$appReflection = new ReflectionObject($app);
$currentUserProperty = $appReflection->getProperty('currentUser');
$originalUser = $currentUserProperty->getValue($app);
$tenantResolver = $app->tenant();
$originalTenant = $tenantResolver->current();

$removeFixture = static function (string $path): void {
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($path);
};

try {
    mkdir($tempRoot, 0777, true);
    file_put_contents($tempRoot . '/handlers.php', <<<'PHP'
<?php
function guiSettingsAuthorityFixtureHandler(array $params = []): void
{
    file_put_contents((string)$GLOBALS['gui_settings_authority_fixture_marker'], 'executed');
    echo 'fixture-handler-executed';
}
PHP);

    $fixtureId = 'gui-settings-authority-fixture';
    $GLOBALS['gui_settings_authority_fixture_marker'] = $marker;
    $GLOBALS['_kernel_discovered_modules'] = [
        $fixtureId => [
            'id' => $fixtureId,
            'name' => 'GUI Settings Authority Fixture',
            'version' => '1.0.0',
            'owns_tables' => [],
            'reads_tables' => [],
            'events' => [],
            'routes' => true,
            'capabilities' => [
                'exposes' => [['id' => 'gui_settings.apply@1']],
                'depends' => [],
                'routes' => [
                    'GET /authority-fixture' => [
                        'capability' => 'gui_settings.apply@1',
                        'allowed_roles' => ['admin'],
                    ],
                ],
            ],
            '_path' => $tempRoot,
            '_enabled' => true,
        ],
    ];
    // Tenant 1 is the repository's configured kernel test tenant; using a
    // resolvable tenant lets the full dispatcher construct ModuleContext.
    $tenantResolver->setTenantId(1);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/authority-fixture';
    unset($_SERVER['HTTP_ACCEPT']);

    $currentUserProperty->setValue($app, ['id' => 301, 'role' => 'viewer', 'source' => $fixtureId, 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':guiSettingsAuthorityFixtureHandler', [], '/authority-fixture', 'GET');
    $deniedBody = (string)ob_get_clean();
    $deniedStatus = http_response_code();
    $check(
        'PRE-HANDLER: declared non-admin dispatch is refused by executeModuleHandler',
        $deniedStatus === 403 && $deniedBody === 'Forbidden',
        'status=' . $deniedStatus . ' body=' . $deniedBody
    );
    $check('PRE-HANDLER: refusal leaves the fixture handler marker unset', !is_file($marker));

    $currentUserProperty->setValue($app, ['id' => 302, 'role' => 'admin', 'source' => $fixtureId, 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':guiSettingsAuthorityFixtureHandler', [], '/authority-fixture', 'GET');
    $allowedBody = (string)ob_get_clean();
    $allowedStatus = http_response_code();
    $check(
        'PRE-HANDLER: declared admin dispatch reaches the fixture handler',
        $allowedStatus === 200 && $allowedBody === 'fixture-handler-executed' && is_file($marker),
        'status=' . $allowedStatus . ' body=' . $allowedBody
    );
} finally {
    $currentUserProperty->setValue($app, $originalUser);
    $tenantResolver->setTenantId(is_numeric($originalTenant) ? (int)$originalTenant : null);
    $_SERVER = $originalServer;
    unset($GLOBALS['gui_settings_authority_fixture_marker']);
    if ($hadDiscoveredModules) {
        $GLOBALS['_kernel_discovered_modules'] = $originalDiscoveredModules;
    } else {
        unset($GLOBALS['_kernel_discovered_modules']);
    }
    $removeFixture($tempRoot);

    if ($previousEnv === false) {
        putenv(AuthorityDispatchGuard::ENV_FLAG);
    } else {
        putenv(AuthorityDispatchGuard::ENV_FLAG . '=' . $previousEnv);
    }
    if ($previousEnvArray === null) {
        unset($_ENV[AuthorityDispatchGuard::ENV_FLAG]);
    } else {
        $_ENV[AuthorityDispatchGuard::ENV_FLAG] = $previousEnvArray;
    }
}

$lines[] = 'not applicable: raw session cookies, bearer strings, and impersonation controls are not guard inputs; executeModuleHandler passes their normalized actor array, covered above';
foreach ($lines as $line) {
    echo $line . "\n";
}
echo "result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
