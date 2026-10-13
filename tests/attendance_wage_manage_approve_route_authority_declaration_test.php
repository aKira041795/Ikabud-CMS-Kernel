<?php

/**
 * Attendance-wage management and approval write route-authority coverage — slice 10.
 *
 * ROLLBACK FALSIFIER: any caller the handlers admit (manage: admin+supervisor;
 * approve: admin) denied under the same valid identity/tenant context requires
 * rolling back these declarations. No-deploy recovery:
 * KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.
 *
 * The dispatch guard checks role only. Source discipline remains downstream:
 * a foreign-source (including kernel-source) admin passes dispatch but is still
 * refused by attendanceWageGuard/aw_userHasRole exactly as before. Supervisor is
 * deliberately admitted only at the manage boundary and refused at approval.
 * Auth-only wage/groups routes and self-service profile/clock, read-gated cash-
 * advance create, and benefits calculate routes are excluded because role mode
 * cannot faithfully represent auth-only/self-service semantics; kiosk/auth
 * routes retain their own mechanisms.
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

$manifest = json_decode((string)file_get_contents(__DIR__ . '/../modules/attendance-wage/module.json'), true, 512, JSON_THROW_ON_ERROR);
$existingRoutes = [
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
$groups = [
    'manage' => [
        'capability' => 'attendance_wage.manage@1',
        'roles' => ['admin', 'supervisor'],
        'routes' => [
            'POST /api/v1/wage/adjustments',
            'POST /api/v1/wage/deductions',
            'POST /api/v1/wage/deductions/{id}/status',
        ],
    ],
    'approve' => [
        'capability' => 'attendance_wage.approve@1',
        'roles' => ['admin'],
        'routes' => [
            'POST /api/v1/wage/computations/{id}/approve',
            'POST /api/v1/wage/computations/{id}/pay',
            'POST /api/v1/wage/computations/batch/approve',
            'POST /api/v1/wage/computations/batch/pay',
            'POST /api/v1/wage/adjustments/{id}/approve',
            'POST /api/v1/wage/cash-advances/{id}/approve',
        ],
    ],
];
$newRoutes = array_merge($groups['manage']['routes'], $groups['approve']['routes']);
$declaredRoutes = $manifest['capabilities']['routes'] ?? null;
$check(
    'REAL-MANIFEST capabilities.routes contains exactly the 34 slice 9a + 9b + 10 keys',
    is_array($declaredRoutes) && array_keys($declaredRoutes) === array_merge($existingRoutes, $newRoutes),
    json_encode(is_array($declaredRoutes) ? array_keys($declaredRoutes) : $declaredRoutes)
);
foreach ($groups as $group => $spec) {
    foreach ($spec['routes'] as $routeKey) {
        $declaration = is_array($declaredRoutes) ? ($declaredRoutes[$routeKey] ?? null) : null;
        $check(
            "REAL-MANIFEST {$routeKey} has exact {$group} capability and roles",
            is_array($declaration)
                && ($declaration['capability'] ?? null) === $spec['capability']
                && ($declaration['allowed_roles'] ?? null) === $spec['roles'],
            json_encode($declaration)
        );
    }
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
$adminShapes = [
    'attendance-wage admin' => ['id' => 101, 'role' => 'admin', 'source' => 'attendance-wage', 'tenant_id' => 42],
    'kernel-source admin (guard-admitted; source discipline remains downstream)' => [
        'id' => 102, 'role' => 'admin', 'source' => 'kernel', 'tenant_id' => 42,
    ],
];
$supervisor = ['id' => 103, 'role' => 'supervisor', 'source' => 'attendance-wage', 'tenant_id' => 42];
$allowChecks = 0;
foreach ($groups as $group => $spec) {
    $shapes = $adminShapes;
    if ($group === 'manage') {
        $shapes['attendance-wage supervisor'] = $supervisor;
    }
    foreach ($spec['routes'] as $routeKey) {
        [$method, $path] = explode(' ', $routeKey, 2);
        foreach ($shapes as $identityLabel => $actor) {
            ++$allowChecks;
            $decision = AuthorityDispatchGuard::decide('attendance-wage', $method, $path, $path, $manifest, $actor, $resolver);
            $check(
                "MUST-ALLOW {$routeKey}: {$identityLabel}",
                $decision['allowed'] && $decision['configured'] && $decision['reason'] === 'allowed',
                json_encode($decision)
            );
        }
    }
}
$scopeProbe = $resolver->resolve(AuthorityScopeResolver::WEB, ['actor' => $adminShapes['attendance-wage admin']]);
$check(
    'MUST-ALLOW context resolves the verified tenant 42 web authority scope',
    $scopeProbe instanceof AuthorityScope
        && $scopeProbe->tenantId === 42
        && $scopeProbe->entryPoint === AuthorityScopeResolver::WEB
        && count($scopeCalls) === $allowChecks + 1,
    $scopeProbe instanceof AuthorityScope ? json_encode(['tenant_id' => $scopeProbe->tenantId, 'entry_point' => $scopeProbe->entryPoint]) : 'scope unresolved'
);

foreach ($groups as $group => $spec) {
    $refusedRoles = ['employee', 'cashier'];
    if ($group === 'approve') {
        $refusedRoles[] = 'supervisor';
    }
    foreach ($spec['routes'] as $routeKey) {
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
}

$undeclaredPath = '/api/v1/wage/groups';
$undeclared = AuthorityDispatchGuard::decide(
    'attendance-wage', 'POST', $undeclaredPath, $undeclaredPath, $manifest, $supervisor, $resolver
);
$check(
    'PARITY: undeclared POST /api/v1/wage/groups remains permitted for a supervisor',
    $undeclared['allowed'] && !$undeclared['configured'] && $undeclared['reason'] === 'no_authority_declared',
    json_encode($undeclared)
);

// Full executeModuleHandler proof: only the fixture handler body writes the marker.
$tempRoot = sys_get_temp_dir() . '/attendance-wage-manage-approve-authority-' . bin2hex(random_bytes(6));
$marker = $tempRoot . '/handler-ran';
$originalServer = $_SERVER;
$hadDiscoveredModules = array_key_exists('_kernel_discovered_modules', $GLOBALS);
$originalDiscoveredModules = $GLOBALS['_kernel_discovered_modules'] ?? null;
$markerGlobal = 'attendance_wage_manage_approve_authority_fixture_marker';
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
function attendanceWageManageApproveAuthorityFixtureHandler(array $params = []): void
{
    file_put_contents((string)$GLOBALS['attendance_wage_manage_approve_authority_fixture_marker'], 'executed');
    echo 'fixture-handler-executed';
}
PHP);
    $fixtureId = 'attendance-wage-manage-approve-authority-fixture';
    $fixturePath = '/api/v1/wage/manage-authority-fixture';
    $GLOBALS[$markerGlobal] = $marker;
    $GLOBALS['_kernel_discovered_modules'] = [
        $fixtureId => [
            'id' => $fixtureId,
            'name' => 'Attendance Wage Manage Approve Authority Fixture',
            'version' => '1.0.0',
            'owns_tables' => [],
            'reads_tables' => [],
            'events' => [],
            'routes' => true,
            'capabilities' => [
                'exposes' => [['id' => 'attendance_wage.manage@1']],
                'depends' => [],
                'routes' => [
                    'POST ' . $fixturePath => [
                        'capability' => 'attendance_wage.manage@1',
                        'allowed_roles' => ['admin', 'supervisor'],
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

    $currentUserProperty->setValue($app, ['id' => 401, 'role' => 'employee', 'source' => 'attendance-wage', 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':attendanceWageManageApproveAuthorityFixtureHandler', [], $fixturePath, 'POST');
    $deniedBody = (string)ob_get_clean();
    $deniedStatus = http_response_code();
    $deniedPayload = json_decode($deniedBody, true);
    $check(
        'PRE-HANDLER: declared below-minimum employee dispatch returns 403',
        $deniedStatus === 403
            && is_array($deniedPayload)
            && ($deniedPayload['ok'] ?? null) === false
            && ($deniedPayload['error'] ?? null) === 'Forbidden'
            && ($deniedPayload['state'] ?? null) === 'route_authority_denied',
        'status=' . $deniedStatus . ' body=' . $deniedBody
    );
    $check('PRE-HANDLER: employee refusal leaves fixture marker unset', !is_file($marker));

    $currentUserProperty->setValue($app, ['id' => 402, 'role' => 'supervisor', 'source' => 'attendance-wage', 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':attendanceWageManageApproveAuthorityFixtureHandler', [], $fixturePath, 'POST');
    $allowedBody = (string)ob_get_clean();
    $allowedStatus = http_response_code();
    $check(
        'PRE-HANDLER: authorized supervisor dispatch executes the fixture handler',
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
