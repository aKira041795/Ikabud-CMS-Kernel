<?php

/**
 * Attendance-wage configuration admin write route-authority coverage — slice 9b.
 *
 * ROLLBACK FALSIFIER: any attendance-wage admin caller denied under the same
 * valid identity/tenant context requires rolling back these declarations.
 * No-deploy recovery: KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.
 *
 * The dispatch guard checks role only. Source discipline remains downstream:
 * a foreign-source (including kernel-source) actor with role 'admin' passes
 * dispatch, but attendanceWageGuard/aw_userHasRole still requires source
 * 'attendance-wage' and therefore never receives a grant from this guard.
 * Wrong-role callers intentionally move to 403 route_authority_denied.
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

$manifestPath = __DIR__ . '/../modules/attendance-wage/module.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$slice9aRoutes = [
    'POST /api/v1/attendance/admin-records',
    'POST /api/v1/wage/employees',
    'POST /api/v1/wage/employees/{id}',
    'POST /admin/wage/employees/{id}/delete',
    'POST /admin/wage/employees/{id}/activate',
    'POST /api/v1/wage/periods',
    'POST /api/v1/wage/periods/{id}',
    'POST /api/v1/wage/periods/{id}/delete',
    'POST /api/v1/wage/compute',
    'POST /api/v1/wage/compute/bulk',
    'POST /api/v1/wage/schedules',
    'POST /api/v1/wage/migration/bulk',
];
$routes = [
    'POST /api/v1/wage/holidays',
    'POST /api/v1/wage/holidays/{id}',
    'POST /api/v1/wage/holidays/{id}/delete',
    'POST /api/v1/wage/locations',
    'POST /api/v1/wage/locations/{id}',
    'POST /api/v1/wage/locations/{id}/delete',
    'POST /api/v1/wage/settings',
    'POST /api/v1/wage/settings/change-password',
    'POST /api/v1/wage/settings/add-user',
    'POST /api/v1/wage/settings/update-role',
    'POST /api/v1/wage/settings/toggle-user',
    'POST /api/v1/wage/settings/data-reset',
    'POST /api/v1/wage/settings/backup',
];
$declaredRoutes = $manifest['capabilities']['routes'] ?? null;
$expectedRouteKeys = array_merge($slice9aRoutes, $routes);

// Multi-batch pin rule (decisions 155/157/158, applied to decision 159): this suite owns ITS
// 25 routes (present and unchanged); the exact full-set pin for the module lives in the NEWEST
// batch suite (tests/attendance_wage_manage_approve_route_authority_declaration_test.php, 34 keys).
$check(
    'REAL-MANIFEST contains the 25 slice 9a + 9b route keys (subset pin)',
    is_array($declaredRoutes) && array_diff($expectedRouteKeys, array_keys($declaredRoutes)) === [],
    json_encode(array_keys(is_array($declaredRoutes) ? $declaredRoutes : []))
);

foreach ($routes as $routeKey) {
    $declaration = is_array($declaredRoutes) ? ($declaredRoutes[$routeKey] ?? null) : null;
    $check(
        "REAL-MANIFEST {$routeKey} is declared with exact admin authority",
        is_array($declaration)
            && ($declaration['capability'] ?? null) === 'attendance_wage.admin@1'
            && ($declaration['allowed_roles'] ?? null) === ['admin'],
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
    'attendance-wage admin' => ['id' => 101, 'role' => 'admin', 'source' => 'attendance-wage', 'tenant_id' => 42],
    'kernel-source admin (source discipline remains downstream)' => [
        'id' => 102,
        'role' => 'admin',
        'source' => 'kernel',
        'tenant_id' => 42,
    ],
];

foreach ($routes as $routeKey) {
    [$method, $path] = explode(' ', $routeKey, 2);
    foreach ($identityShapes as $identityLabel => $actor) {
        $decision = AuthorityDispatchGuard::decide('attendance-wage', $method, $path, $path, $manifest, $actor, $resolver);
        $check(
            "MUST-ALLOW {$routeKey}: {$identityLabel}",
            $decision['allowed'] && $decision['configured'] && $decision['reason'] === 'allowed',
            json_encode($decision)
        );
    }
}

$scopeProbe = $resolver->resolve(AuthorityScopeResolver::WEB, ['actor' => $identityShapes['attendance-wage admin']]);
$check(
    'MUST-ALLOW context resolves the verified tenant 42 web authority scope',
    $scopeProbe instanceof AuthorityScope
        && $scopeProbe->tenantId === 42
        && $scopeProbe->entryPoint === AuthorityScopeResolver::WEB
        && count($scopeCalls) === (count($routes) * count($identityShapes)) + 1,
    $scopeProbe instanceof AuthorityScope
        ? json_encode(['tenant_id' => $scopeProbe->tenantId, 'entry_point' => $scopeProbe->entryPoint])
        : 'scope unresolved'
);

$refusedRoles = ['supervisor', 'employee', 'cashier'];
foreach ($routes as $routeKey) {
    [$method, $path] = explode(' ', $routeKey, 2);
    foreach ($refusedRoles as $index => $role) {
        $decision = AuthorityDispatchGuard::decide(
            'attendance-wage',
            $method,
            $path,
            $path,
            $manifest,
            ['id' => 200 + $index, 'role' => $role, 'source' => 'attendance-wage', 'tenant_id' => 42],
            $resolver,
        );
        $check(
            "MUST-REFUSE {$routeKey}: {$role} gets role_not_allowed",
            !$decision['allowed'] && $decision['configured'] && $decision['reason'] === 'role_not_allowed',
            json_encode($decision)
        );
    }
}

$undeclaredPath = '/api/v1/wage/groups';
$undeclared = AuthorityDispatchGuard::decide(
    'attendance-wage',
    'POST',
    $undeclaredPath,
    $undeclaredPath,
    $manifest,
    ['id' => 301, 'role' => 'supervisor', 'source' => 'attendance-wage', 'tenant_id' => 42],
    $resolver,
);
$check(
    'PARITY: undeclared POST /api/v1/wage/groups remains permitted for a supervisor',
    $undeclared['allowed'] && !$undeclared['configured'] && $undeclared['reason'] === 'no_authority_declared',
    json_encode($undeclared)
);

// Full executeModuleHandler proof: the marker can only be written by the
// fixture handler body, after the real dispatch guard and handler resolution.
$tempRoot = sys_get_temp_dir() . '/attendance-wage-config-admin-authority-' . bin2hex(random_bytes(6));
$marker = $tempRoot . '/handler-ran';
$originalServer = $_SERVER;
$hadDiscoveredModules = array_key_exists('_kernel_discovered_modules', $GLOBALS);
$originalDiscoveredModules = $GLOBALS['_kernel_discovered_modules'] ?? null;
$markerGlobal = 'attendance_wage_config_admin_authority_fixture_marker';
$hadMarkerGlobal = array_key_exists($markerGlobal, $GLOBALS);
$originalMarkerGlobal = $GLOBALS[$markerGlobal] ?? null;
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
function attendanceWageConfigAdminAuthorityFixtureHandler(array $params = []): void
{
    file_put_contents((string)$GLOBALS['attendance_wage_config_admin_authority_fixture_marker'], 'executed');
    echo 'fixture-handler-executed';
}
PHP);

    $fixtureId = 'attendance-wage-config-admin-authority-fixture';
    $fixturePath = '/api/v1/wage/config-authority-fixture';
    $GLOBALS[$markerGlobal] = $marker;
    $GLOBALS['_kernel_discovered_modules'] = [
        $fixtureId => [
            'id' => $fixtureId,
            'name' => 'Attendance Wage Config Admin Authority Fixture',
            'version' => '1.0.0',
            'owns_tables' => [],
            'reads_tables' => [],
            'events' => [],
            'routes' => true,
            'capabilities' => [
                'exposes' => [['id' => 'attendance_wage.admin@1']],
                'depends' => [],
                'routes' => [
                    'POST ' . $fixturePath => [
                        'capability' => 'attendance_wage.admin@1',
                        'allowed_roles' => ['admin'],
                    ],
                ],
            ],
            '_path' => $tempRoot,
            '_enabled' => true,
        ],
    ];
    $tenantResolver->setTenantId(1);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $fixturePath;
    unset($_SERVER['HTTP_ACCEPT']);

    $currentUserProperty->setValue($app, ['id' => 401, 'role' => 'supervisor', 'source' => 'attendance-wage', 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':attendanceWageConfigAdminAuthorityFixtureHandler', [], $fixturePath, 'POST');
    $deniedBody = (string)ob_get_clean();
    $deniedStatus = http_response_code();
    $deniedPayload = json_decode($deniedBody, true);
    $check(
        'PRE-HANDLER: declared supervisor dispatch returns route_authority_denied 403',
        $deniedStatus === 403
            && is_array($deniedPayload)
            && ($deniedPayload['ok'] ?? null) === false
            && ($deniedPayload['error'] ?? null) === 'Forbidden'
            && ($deniedPayload['state'] ?? null) === 'route_authority_denied',
        'status=' . $deniedStatus . ' body=' . $deniedBody
    );
    $check('PRE-HANDLER: supervisor refusal leaves fixture marker unset', !is_file($marker));

    $currentUserProperty->setValue($app, ['id' => 402, 'role' => 'admin', 'source' => 'attendance-wage', 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':attendanceWageConfigAdminAuthorityFixtureHandler', [], $fixturePath, 'POST');
    $allowedBody = (string)ob_get_clean();
    $allowedStatus = http_response_code();
    $check(
        'PRE-HANDLER: declared admin dispatch executes the fixture handler',
        $allowedStatus === 200 && $allowedBody === 'fixture-handler-executed' && is_file($marker),
        'status=' . $allowedStatus . ' body=' . $allowedBody
    );
} finally {
    $currentUserProperty->setValue($app, $originalUser);
    $tenantResolver->setTenantId(is_numeric($originalTenant) ? (int)$originalTenant : null);
    $_SERVER = $originalServer;
    if ($hadMarkerGlobal) {
        $GLOBALS[$markerGlobal] = $originalMarkerGlobal;
    } else {
        unset($GLOBALS[$markerGlobal]);
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

$lines[] = 'not applicable: service tokens — attendance-wage has no service-token identity shape for these routes';
$lines[] = 'not applicable: impersonation — attendance-wage has no supported impersonation identity shape for these routes';
foreach ($lines as $line) {
    echo $line . "\n";
}
echo "result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
