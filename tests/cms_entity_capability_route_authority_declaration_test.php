<?php

/**
 * CMS entity-capability route-authority declaration and dispatch coverage.
 *
 * ROLLBACK FALSIFIER: any caller the handler admits today (CMS role >= editor,
 * or kernel admin) denied at dispatch under the same valid identity/tenant
 * context requires rolling back the declaration. No-deploy recovery:
 * KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.
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

$manifestPath = __DIR__ . '/../modules/cms/module.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$expectedRoutes = [
    'POST /api/v1/cms/content/{id}/capabilities',
    'POST /api/v1/cms/content/{id}/capabilities/preset',
    'POST /api/v1/cms/content/{id}/capabilities/{cap_id}/detach',
];
$expectedRoles = ['editor', 'administrator', 'superadmin', 'admin'];
$declaredRoutes = $manifest['capabilities']['routes'] ?? null;

$check(
    'REAL-MANIFEST pins exactly the three CMS entity-capability write routes',
    is_array($declaredRoutes) && array_keys($declaredRoutes) === $expectedRoutes,
    json_encode(array_keys(is_array($declaredRoutes) ? $declaredRoutes : []))
);
foreach ($expectedRoutes as $routeKey) {
    $declaration = is_array($declaredRoutes) ? ($declaredRoutes[$routeKey] ?? null) : null;
    $check(
        "REAL-MANIFEST {$routeKey} pins the handler-equivalent roles and cms.content.update@1",
        is_array($declaration)
            && ($declaration['allowed_roles'] ?? null) === $expectedRoles
            && ($declaration['capability'] ?? null) === 'cms.content.update@1',
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
    'CMS editor' => ['id' => 101, 'role' => 'editor', 'source' => 'cms', 'tenant_id' => 42],
    'CMS administrator' => ['id' => 102, 'role' => 'administrator', 'source' => 'cms', 'tenant_id' => 42],
    'CMS superadmin' => ['id' => 103, 'role' => 'superadmin', 'source' => 'cms', 'tenant_id' => 42],
    'kernel-source admin' => ['id' => 104, 'role' => 'admin', 'source' => 'kernel', 'tenant_id' => 42],
    'service token (normalized actor)' => [
        'id' => 105,
        'role' => 'editor',
        'source' => 'cms',
        'tenant_id' => 42,
        'is_service' => true,
        'service_caps' => ['cms.content.update@1'],
    ],
];

foreach ($expectedRoutes as $routeKey) {
    [$method, $path] = explode(' ', $routeKey, 2);
    foreach ($identityShapes as $identityLabel => $actor) {
        $decision = AuthorityDispatchGuard::decide('cms', $method, $path, $path, $manifest, $actor, $resolver);
        $check(
            "MUST-ALLOW {$routeKey}: {$identityLabel}",
            $decision['allowed'] && $decision['configured'] && $decision['reason'] === 'allowed',
            json_encode($decision)
        );
    }
}

$scopeProbe = $resolver->resolve(AuthorityScopeResolver::WEB, ['actor' => $identityShapes['CMS editor']]);
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

$refusedRoles = ['author', 'contributor', 'subscriber', 'customer'];
foreach ($expectedRoutes as $routeKey) {
    [$method, $path] = explode(' ', $routeKey, 2);
    foreach ($refusedRoles as $index => $role) {
        $decision = AuthorityDispatchGuard::decide(
            'cms',
            $method,
            $path,
            $path,
            $manifest,
            ['id' => 200 + $index, 'role' => $role, 'source' => 'cms', 'tenant_id' => 42],
            $resolver,
        );
        $check(
            "MUST-REFUSE {$routeKey}: CMS {$role} gets role_not_allowed",
            !$decision['allowed'] && $decision['configured'] && $decision['reason'] === 'role_not_allowed',
            json_encode($decision)
        );
    }
}

$undeclared = AuthorityDispatchGuard::decide(
    'cms',
    'POST',
    '/api/v1/cms/settings',
    '/api/v1/cms/settings',
    $manifest,
    ['id' => 301, 'role' => 'author', 'source' => 'cms', 'tenant_id' => 42],
    $resolver,
);
$check(
    'PARITY: undeclared POST /api/v1/cms/settings remains permitted for a CMS author',
    $undeclared['allowed'] && !$undeclared['configured'] && $undeclared['reason'] === 'no_authority_declared',
    json_encode($undeclared)
);

// Full executeModuleHandler proof with a temporary discovered module. The
// marker is written only by the fixture handler body, after the real guard and
// handler resolution points in executeModuleHandler.
$tempRoot = sys_get_temp_dir() . '/cms-entity-capability-authority-' . bin2hex(random_bytes(6));
$marker = $tempRoot . '/handler-ran';
$originalServer = $_SERVER;
$hadDiscoveredModules = array_key_exists('_kernel_discovered_modules', $GLOBALS);
$originalDiscoveredModules = $GLOBALS['_kernel_discovered_modules'] ?? null;
$hadMarkerGlobal = array_key_exists('cms_entity_capability_authority_fixture_marker', $GLOBALS);
$originalMarkerGlobal = $GLOBALS['cms_entity_capability_authority_fixture_marker'] ?? null;
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
function cmsEntityCapabilityAuthorityFixtureHandler(array $params = []): void
{
    file_put_contents((string)$GLOBALS['cms_entity_capability_authority_fixture_marker'], 'executed');
    echo 'fixture-handler-executed';
}
PHP);

    $fixtureId = 'cms-entity-capability-authority-fixture';
    $fixturePath = '/api/v1/cms-authority-fixture';
    $GLOBALS['cms_entity_capability_authority_fixture_marker'] = $marker;
    $GLOBALS['_kernel_discovered_modules'] = [
        $fixtureId => [
            'id' => $fixtureId,
            'name' => 'CMS Entity Capability Authority Fixture',
            'version' => '1.0.0',
            'owns_tables' => [],
            'reads_tables' => [],
            'events' => [],
            'routes' => true,
            'capabilities' => [
                'exposes' => [['id' => 'cms.content.update@1']],
                'depends' => [],
                'routes' => [
                    'POST ' . $fixturePath => [
                        'capability' => 'cms.content.update@1',
                        'allowed_roles' => $expectedRoles,
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

    $currentUserProperty->setValue($app, ['id' => 401, 'role' => 'author', 'source' => 'cms', 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':cmsEntityCapabilityAuthorityFixtureHandler', [], $fixturePath, 'POST');
    $deniedBody = (string)ob_get_clean();
    $deniedStatus = http_response_code();
    $deniedPayload = json_decode($deniedBody, true);
    $check(
        'PRE-HANDLER: declared below-minimum actor is refused by executeModuleHandler',
        $deniedStatus === 403
            && is_array($deniedPayload)
            && ($deniedPayload['ok'] ?? null) === false
            && ($deniedPayload['error'] ?? null) === 'Forbidden'
            && ($deniedPayload['state'] ?? null) === 'route_authority_denied',
        'status=' . $deniedStatus . ' body=' . $deniedBody
    );
    $check('PRE-HANDLER: refusal leaves the fixture handler marker unset', !is_file($marker));

    $currentUserProperty->setValue($app, ['id' => 402, 'role' => 'editor', 'source' => 'cms', 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':cmsEntityCapabilityAuthorityFixtureHandler', [], $fixturePath, 'POST');
    $allowedBody = (string)ob_get_clean();
    $allowedStatus = http_response_code();
    $check(
        'PRE-HANDLER: declared editor dispatch reaches the fixture handler',
        $allowedStatus === 200 && $allowedBody === 'fixture-handler-executed' && is_file($marker),
        'status=' . $allowedStatus . ' body=' . $allowedBody
    );
} finally {
    $currentUserProperty->setValue($app, $originalUser);
    $tenantResolver->setTenantId(is_numeric($originalTenant) ? (int)$originalTenant : null);
    $_SERVER = $originalServer;
    if ($hadMarkerGlobal) {
        $GLOBALS['cms_entity_capability_authority_fixture_marker'] = $originalMarkerGlobal;
    } else {
        unset($GLOBALS['cms_entity_capability_authority_fixture_marker']);
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

$lines[] = 'not applicable: raw session cookies, bearer strings, and impersonation controls are not guard inputs; executeModuleHandler passes their normalized actor array, and the required normalized service-token actor is covered above';
foreach ($lines as $line) {
    echo $line . "\n";
}
echo "result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
