<?php

/**
 * Daily Ledger exact-admin write route-authority coverage — batch 2.
 *
 * ROLLBACK FALSIFIER: any caller the handler admits today (role 'admin' from a
 * Daily Ledger session, or kernel admin with allow_kernel_admin enabled) denied
 * at dispatch under the same valid identity/tenant context requires rolling
 * back the declaration. No-deploy recovery:
 * KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.
 *
 * Dispatch changes the existing non-admin refusal shape (401 session_expired
 * JSON or login redirect) to 403 route_authority_denied; this is intentional.
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

$manifestPath = __DIR__ . '/../modules/daily-ledger/module.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$slice3Routes = [
    'POST /daily-ledger/api/v1/admin/dl-users',
    'POST /daily-ledger/api/v1/admin/dl-users/update',
    'POST /daily-ledger/api/v1/admin/dl-users/delete',
    'POST /daily-ledger/api/v1/admin/dl-users/restore',
    'POST /daily-ledger/api/v1/admin/settings/permissions',
];
$batch2Routes = [
    'POST /daily-ledger/api/v1/admin/products',
    'POST /daily-ledger/api/v1/admin/products/update',
    'POST /daily-ledger/api/v1/admin/products/reprice',
    'POST /daily-ledger/api/v1/admin/products/import-csv',
    'POST /daily-ledger/api/v1/admin/branches',
    'POST /daily-ledger/api/v1/admin/branches/update',
    'POST /daily-ledger/api/v1/admin/consignees',
    'POST /daily-ledger/api/v1/admin/consignees/products',
    'POST /daily-ledger/api/v1/admin/branches/products',
    'POST /daily-ledger/api/v1/admin/variances/decide-delivery',
    'POST /daily-ledger/api/v1/admin/deliveries/resolve-origin',
    'POST /daily-ledger/api/v1/admin/receivings/resolve-count',
    'POST /daily-ledger/api/v1/admin/settings/branding-asset',
    'POST /daily-ledger/api/v1/commissary/settle-endings',
    'POST /daily-ledger/api/v1/commissary/verify-endings',
    'POST /daily-ledger/api/v1/commissary/revert-endings',
];
$expectedRoutes = array_merge($slice3Routes, $batch2Routes);
$declaredRoutes = $manifest['capabilities']['routes'] ?? null;

// Multi-batch pin rule: this suite owns ITS 21 routes (present and unchanged); the exact
// full-set pin for the module lives in the NEWEST batch suite
// (tests/daily_ledger_ops_production_route_authority_declaration_test.php, 33 keys).
$check(
    'REAL-MANIFEST contains the 21 Daily Ledger exact-admin write routes (subset pin)',
    is_array($declaredRoutes) && array_diff($expectedRoutes, array_keys($declaredRoutes)) === [],
    json_encode(array_keys(is_array($declaredRoutes) ? $declaredRoutes : []))
);
foreach ($batch2Routes as $routeKey) {
    $declaration = is_array($declaredRoutes) ? ($declaredRoutes[$routeKey] ?? null) : null;
    $check(
        "REAL-MANIFEST {$routeKey} pins admin and kernel.auth.authenticate@1",
        is_array($declaration)
            && ($declaration['allowed_roles'] ?? null) === ['admin']
            && ($declaration['capability'] ?? null) === 'kernel.auth.authenticate@1',
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
    'Daily Ledger admin' => ['id' => 101, 'role' => 'admin', 'source' => 'daily-ledger', 'tenant_id' => 42],
    'kernel-source admin (module kernel-admin opt-in gate remains downstream and unchanged)' => [
        'id' => 102,
        'role' => 'admin',
        'source' => 'kernel',
        'tenant_id' => 42,
    ],
];

foreach ($batch2Routes as $routeKey) {
    [$method, $path] = explode(' ', $routeKey, 2);
    foreach ($identityShapes as $identityLabel => $actor) {
        $decision = AuthorityDispatchGuard::decide('daily-ledger', $method, $path, $path, $manifest, $actor, $resolver);
        $check(
            "MUST-ALLOW {$routeKey}: {$identityLabel}",
            $decision['allowed'] && $decision['configured'] && $decision['reason'] === 'allowed',
            json_encode($decision)
        );
    }
}

$scopeProbe = $resolver->resolve(AuthorityScopeResolver::WEB, ['actor' => $identityShapes['Daily Ledger admin']]);
$check(
    'MUST-ALLOW context resolves the verified tenant 42 web authority scope',
    $scopeProbe instanceof AuthorityScope
        && $scopeProbe->tenantId === 42
        && $scopeProbe->entryPoint === AuthorityScopeResolver::WEB
        && count($scopeCalls) === (count($batch2Routes) * count($identityShapes)) + 1,
    $scopeProbe instanceof AuthorityScope
        ? json_encode(['tenant_id' => $scopeProbe->tenantId, 'entry_point' => $scopeProbe->entryPoint])
        : 'scope unresolved'
);

$refusedRoles = ['supervisor', 'cashier', 'production_in_charge', 'auditor', 'viewer'];
foreach ($batch2Routes as $routeKey) {
    [$method, $path] = explode(' ', $routeKey, 2);
    foreach ($refusedRoles as $index => $role) {
        $decision = AuthorityDispatchGuard::decide(
            'daily-ledger',
            $method,
            $path,
            $path,
            $manifest,
            ['id' => 200 + $index, 'role' => $role, 'source' => 'daily-ledger', 'tenant_id' => 42],
            $resolver,
        );
        $check(
            "MUST-REFUSE {$routeKey}: Daily Ledger {$role} gets role_not_allowed",
            !$decision['allowed'] && $decision['configured'] && $decision['reason'] === 'role_not_allowed',
            json_encode($decision)
        );
    }
}

// Parity-route rule (decision 158): the probe moved from /admin/variances/update to a
// permanently undeclared auth-only route (dlCurrentUser() with no role set — never declared).
$undeclaredPath = '/daily-ledger/api/v1/cashier/ledger/withdrawals';
$undeclared = AuthorityDispatchGuard::decide(
    'daily-ledger',
    'POST',
    $undeclaredPath,
    $undeclaredPath,
    $manifest,
    ['id' => 301, 'role' => 'cashier', 'source' => 'daily-ledger', 'tenant_id' => 42],
    $resolver,
);
$check(
    'PARITY: undeclared POST /daily-ledger/api/v1/cashier/ledger/withdrawals remains permitted for a cashier',
    $undeclared['allowed'] && !$undeclared['configured'] && $undeclared['reason'] === 'no_authority_declared',
    json_encode($undeclared)
);

// Full executeModuleHandler proof with a temporary discovered module. The
// marker is written only by the fixture handler body, after the real guard and
// handler resolution points in executeModuleHandler.
$tempRoot = sys_get_temp_dir() . '/daily-ledger-admin-batch2-authority-' . bin2hex(random_bytes(6));
$marker = $tempRoot . '/handler-ran';
$originalServer = $_SERVER;
$hadDiscoveredModules = array_key_exists('_kernel_discovered_modules', $GLOBALS);
$originalDiscoveredModules = $GLOBALS['_kernel_discovered_modules'] ?? null;
$hadMarkerGlobal = array_key_exists('daily_ledger_admin_batch2_authority_fixture_marker', $GLOBALS);
$originalMarkerGlobal = $GLOBALS['daily_ledger_admin_batch2_authority_fixture_marker'] ?? null;
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
function dailyLedgerAdminBatch2AuthorityFixtureHandler(array $params = []): void
{
    file_put_contents((string)$GLOBALS['daily_ledger_admin_batch2_authority_fixture_marker'], 'executed');
    echo 'fixture-handler-executed';
}
PHP);

    $fixtureId = 'daily-ledger-admin-batch2-authority-fixture';
    $fixturePath = '/daily-ledger/api/v1/authority-fixture';
    $GLOBALS['daily_ledger_admin_batch2_authority_fixture_marker'] = $marker;
    $GLOBALS['_kernel_discovered_modules'] = [
        $fixtureId => [
            'id' => $fixtureId,
            'name' => 'Daily Ledger Admin Batch 2 Authority Fixture',
            'version' => '1.0.0',
            'owns_tables' => [],
            'reads_tables' => [],
            'events' => [],
            'routes' => true,
            'capabilities' => [
                'exposes' => [['id' => 'kernel.auth.authenticate@1']],
                'depends' => [],
                'routes' => [
                    'POST ' . $fixturePath => [
                        'capability' => 'kernel.auth.authenticate@1',
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
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $fixturePath;
    unset($_SERVER['HTTP_ACCEPT']);

    $currentUserProperty->setValue($app, ['id' => 401, 'role' => 'cashier', 'source' => $fixtureId, 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':dailyLedgerAdminBatch2AuthorityFixtureHandler', [], $fixturePath, 'POST');
    $deniedBody = (string)ob_get_clean();
    $deniedStatus = http_response_code();
    $deniedPayload = json_decode($deniedBody, true);
    $check(
        'PRE-HANDLER: declared cashier dispatch is refused by executeModuleHandler',
        $deniedStatus === 403
            && is_array($deniedPayload)
            && ($deniedPayload['ok'] ?? null) === false
            && ($deniedPayload['error'] ?? null) === 'Forbidden'
            && ($deniedPayload['state'] ?? null) === 'route_authority_denied',
        'status=' . $deniedStatus . ' body=' . $deniedBody
    );
    $check('PRE-HANDLER: refusal leaves the fixture handler marker unset', !is_file($marker));

    $currentUserProperty->setValue($app, ['id' => 402, 'role' => 'admin', 'source' => $fixtureId, 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':dailyLedgerAdminBatch2AuthorityFixtureHandler', [], $fixturePath, 'POST');
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
    if ($hadMarkerGlobal) {
        $GLOBALS['daily_ledger_admin_batch2_authority_fixture_marker'] = $originalMarkerGlobal;
    } else {
        unset($GLOBALS['daily_ledger_admin_batch2_authority_fixture_marker']);
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

$lines[] = 'not applicable: service tokens — Daily Ledger has no service-token identity for these routes';
$lines[] = 'not applicable: impersonation — Daily Ledger has no impersonation path for these routes';
foreach ($lines as $line) {
    echo $line . "\n";
}
echo "result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
