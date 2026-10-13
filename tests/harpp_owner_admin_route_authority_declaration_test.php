<?php

/**
 * HARPP owner/admin control-surface route-authority coverage.
 *
 * ROLLBACK FALSIFIER: any HARPP owner/admin or kernel superadmin caller denied
 * at dispatch under the same valid identity/tenant context requires rolling
 * back the declaration. No-deploy recovery:
 * KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.
 *
 * A kernel-source actor with role 'admin' passes the role-only dispatch guard,
 * but the handler still refuses it because it is neither a HARPP admin nor a
 * kernel superadmin. This is identical to pre-declaration behavior: the
 * undeclared route previously passed dispatch too. Wrong-role callers formerly
 * received the handler's 403 forbidden JSON; they now receive the earlier,
 * uniform dispatch 403 route_authority_denied JSON (same status).
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

$manifestPath = __DIR__ . '/../modules/harpp/module.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$expectedRoutes = [
    'POST /api/v1/harpp/settings' => 'harpp.settings.manage@1',
    'POST /api/v1/harpp/users' => 'harpp.users.manage@1',
    'PUT /api/v1/harpp/users/{id}' => 'harpp.users.manage@1',
    'PATCH /api/v1/harpp/users/{id}' => 'harpp.users.manage@1',
    'DELETE /api/v1/harpp/users/{id}' => 'harpp.users.manage@1',
    'POST /api/v1/harpp/users/{id}/reset-password' => 'harpp.users.manage@1',
    'POST /api/v1/harpp/deploys' => 'harpp.deploy.request@1',
    'POST /api/v1/harpp/deploys/{id}/cancel' => 'harpp.deploy.request@1',
    'GET /api/v1/harpp/settings' => 'harpp.settings.read@1',
    'GET /api/v1/harpp/users' => 'harpp.users.manage@1',
];
$allowedRoles = ['owner', 'admin', 'superadmin'];
$declaredRoutes = $manifest['capabilities']['routes'] ?? null;

$check(
    'REAL-MANIFEST pins exactly the ten HARPP owner/admin control-surface routes',
    is_array($declaredRoutes) && array_keys($declaredRoutes) === array_keys($expectedRoutes),
    json_encode(array_keys(is_array($declaredRoutes) ? $declaredRoutes : []))
);
foreach ($expectedRoutes as $routeKey => $capabilityId) {
    $declaration = is_array($declaredRoutes) ? ($declaredRoutes[$routeKey] ?? null) : null;
    $check(
        "REAL-MANIFEST {$routeKey} pins {$capabilityId} and exact owner/admin/superadmin roles",
        is_array($declaration)
            && ($declaration['capability'] ?? null) === $capabilityId
            && ($declaration['allowed_roles'] ?? null) === $allowedRoles,
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
    'HARPP owner' => ['id' => 101, 'role' => 'owner', 'source' => 'harpp', 'tenant_id' => 42],
    'HARPP admin' => ['id' => 102, 'role' => 'admin', 'source' => 'harpp', 'tenant_id' => 42],
    'kernel superadmin' => ['id' => 103, 'role' => 'superadmin', 'source' => 'kernel', 'tenant_id' => 42],
];

foreach ($expectedRoutes as $routeKey => $capabilityId) {
    [$method, $path] = explode(' ', $routeKey, 2);
    foreach ($identityShapes as $identityLabel => $actor) {
        $decision = AuthorityDispatchGuard::decide('harpp', $method, $path, $path, $manifest, $actor, $resolver);
        $check(
            "MUST-ALLOW {$routeKey}: {$identityLabel}",
            $decision['allowed']
                && $decision['configured']
                && $decision['reason'] === 'allowed'
                && $decision['capability_id'] === $capabilityId,
            json_encode($decision)
        );
    }
}

$scopeProbe = $resolver->resolve(AuthorityScopeResolver::WEB, ['actor' => $identityShapes['HARPP owner']]);
$check(
    'MUST-ALLOW context resolves the verified tenant 42 web authority scope',
    $scopeProbe instanceof AuthorityScope
        && $scopeProbe->tenantId === 42
        && $scopeProbe->entryPoint === AuthorityScopeResolver::WEB
        && count($scopeCalls) === (count($expectedRoutes) * count($identityShapes)) + 1,
    $scopeProbe instanceof AuthorityScope
        ? json_encode(['tenant_id' => $scopeProbe->tenantId, 'entry_point' => $scopeProbe->entryPoint])
        : 'scope unresolved'
);

$refusedRoles = ['member', 'cashier'];
foreach ($expectedRoutes as $routeKey => $capabilityId) {
    [$method, $path] = explode(' ', $routeKey, 2);
    foreach ($refusedRoles as $index => $role) {
        $decision = AuthorityDispatchGuard::decide(
            'harpp',
            $method,
            $path,
            $path,
            $manifest,
            ['id' => 200 + $index, 'role' => $role, 'source' => 'harpp', 'tenant_id' => 42],
            $resolver,
        );
        $check(
            "MUST-REFUSE {$routeKey}: {$role} gets role_not_allowed",
            !$decision['allowed']
                && $decision['configured']
                && $decision['reason'] === 'role_not_allowed'
                && $decision['capability_id'] === $capabilityId,
            json_encode($decision)
        );
    }
}

$undeclaredPath = '/api/v1/harpp/decisions';
$undeclared = AuthorityDispatchGuard::decide(
    'harpp',
    'POST',
    $undeclaredPath,
    $undeclaredPath,
    $manifest,
    ['id' => 301, 'role' => 'member', 'source' => 'harpp', 'tenant_id' => 42],
    $resolver,
);
$check(
    'PARITY: undeclared POST /api/v1/harpp/decisions remains permitted for a member',
    $undeclared['allowed'] && !$undeclared['configured'] && $undeclared['reason'] === 'no_authority_declared',
    json_encode($undeclared)
);

// Full executeModuleHandler proof with a temporary discovered module. The
// marker is written only by the fixture handler body, after the real guard and
// handler resolution points in executeModuleHandler.
$tempRoot = sys_get_temp_dir() . '/harpp-owner-admin-authority-' . bin2hex(random_bytes(6));
$marker = $tempRoot . '/handler-ran';
$fixturePath = '/api/v1/harpp/authority-fixture';
$originalServer = $_SERVER;
$hadDiscoveredModules = array_key_exists('_kernel_discovered_modules', $GLOBALS);
$originalDiscoveredModules = $GLOBALS['_kernel_discovered_modules'] ?? null;
$hadMarkerGlobal = array_key_exists('harpp_owner_admin_authority_fixture_marker', $GLOBALS);
$originalMarkerGlobal = $GLOBALS['harpp_owner_admin_authority_fixture_marker'] ?? null;
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
function harppOwnerAdminAuthorityFixtureHandler(array $params = []): void
{
    file_put_contents((string)$GLOBALS['harpp_owner_admin_authority_fixture_marker'], 'executed');
    echo 'fixture-handler-executed';
}
PHP);

    $fixtureId = 'harpp-owner-admin-authority-fixture';
    $GLOBALS['harpp_owner_admin_authority_fixture_marker'] = $marker;
    $GLOBALS['_kernel_discovered_modules'] = [
        $fixtureId => [
            'id' => $fixtureId,
            'name' => 'HARPP Owner Admin Authority Fixture',
            'version' => '1.0.0',
            'owns_tables' => [],
            'reads_tables' => [],
            'events' => [],
            'routes' => true,
            'capabilities' => [
                'exposes' => [['id' => 'harpp.settings.manage@1']],
                'depends' => [],
                'routes' => [
                    'POST ' . $fixturePath => [
                        'capability' => 'harpp.settings.manage@1',
                        'allowed_roles' => $allowedRoles,
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
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $fixturePath;
    unset($_SERVER['HTTP_ACCEPT']);

    $currentUserProperty->setValue($app, ['id' => 401, 'role' => 'member', 'source' => 'harpp', 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':harppOwnerAdminAuthorityFixtureHandler', [], $fixturePath, 'POST');
    $deniedBody = (string)ob_get_clean();
    $deniedStatus = http_response_code();
    $deniedPayload = json_decode($deniedBody, true);
    $check(
        'PRE-HANDLER: declared member dispatch is refused by executeModuleHandler',
        $deniedStatus === 403
            && is_array($deniedPayload)
            && ($deniedPayload['ok'] ?? null) === false
            && ($deniedPayload['error'] ?? null) === 'Forbidden'
            && ($deniedPayload['state'] ?? null) === 'route_authority_denied',
        'status=' . $deniedStatus . ' body=' . $deniedBody
    );
    $check('PRE-HANDLER: refusal leaves the fixture handler marker unset', !is_file($marker));

    $currentUserProperty->setValue($app, ['id' => 402, 'role' => 'owner', 'source' => 'harpp', 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':harppOwnerAdminAuthorityFixtureHandler', [], $fixturePath, 'POST');
    $allowedBody = (string)ob_get_clean();
    $allowedStatus = http_response_code();
    $check(
        'PRE-HANDLER: declared owner dispatch reaches the fixture handler',
        $allowedStatus === 200 && $allowedBody === 'fixture-handler-executed' && is_file($marker),
        'status=' . $allowedStatus . ' body=' . $allowedBody
    );
} finally {
    $currentUserProperty->setValue($app, $originalUser);
    $tenantResolver->setTenantId(is_numeric($originalTenant) ? (int)$originalTenant : null);
    $_SERVER = $originalServer;
    if ($hadMarkerGlobal) {
        $GLOBALS['harpp_owner_admin_authority_fixture_marker'] = $originalMarkerGlobal;
    } else {
        unset($GLOBALS['harpp_owner_admin_authority_fixture_marker']);
    }
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

$lines[] = 'not applicable: service tokens — these capabilities have no service-token allowlists and these are session routes';
$lines[] = 'not applicable: harpp_bridge daemons — daemons authenticate through bridge-key routes, not these session routes';
$lines[] = 'not applicable: impersonation — these routes have no supported impersonation identity shape';
foreach ($lines as $line) {
    echo $line . "\n";
}
echo "result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
