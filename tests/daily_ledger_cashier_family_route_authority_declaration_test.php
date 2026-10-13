<?php

/**
 * Daily Ledger cashier-family write-route authority.
 *
 * ROLLBACK FALSIFIER: any caller the handlers admit (a role in the route's
 * declared group) denied under the same valid identity/tenant context requires
 * rolling back these declarations. No-deploy recovery:
 * KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.
 *
 * Guard admission only checks role. Existing downstream discipline remains:
 * role_permissions grants, production_in_charge method/path allowlists,
 * offline enrollment device binding, supervisor/auditor admin-only refusals,
 * and kernel-admin opt-in (off by default).
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
$priorRoutes = [
    'POST /daily-ledger/api/v1/admin/dl-users',
    'POST /daily-ledger/api/v1/admin/dl-users/update',
    'POST /daily-ledger/api/v1/admin/dl-users/delete',
    'POST /daily-ledger/api/v1/admin/dl-users/restore',
    'POST /daily-ledger/api/v1/admin/settings/permissions',
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
    'POST /daily-ledger/api/v1/admin/reopen-day',
    'POST /daily-ledger/api/v1/admin/variances/update',
    'POST /daily-ledger/api/v1/admin/reconciliation/save',
    'POST /daily-ledger/api/v1/production/withdrawal',
    'POST /daily-ledger/api/v1/production/output',
    'POST /daily-ledger/api/v1/production/reverse',
    'POST /daily-ledger/api/v1/production/sync-batch',
    'POST /daily-ledger/api/v1/commissary/run',
    'POST /daily-ledger/api/v1/commissary/material',
    'POST /daily-ledger/api/v1/commissary/dispatch',
    'POST /daily-ledger/api/v1/commissary/carry-beginnings',
    'POST /daily-ledger/api/v1/commissary/finalize-pm',
];
$routeGroups = [
    'POST /daily-ledger/api/v1/cashier/ledger/save' => ['cashier', 'supervisor', 'admin'],
    'POST /daily-ledger/api/v1/cashier/ledger/save-batch' => ['cashier', 'supervisor', 'admin'],
    'POST /daily-ledger/api/v1/cashier/ledger/carry-beginnings' => ['cashier', 'supervisor', 'admin'],
    'POST /daily-ledger/api/v1/cashier/ledger/finalize-pm' => ['cashier', 'supervisor', 'admin'],
    'POST /daily-ledger/api/v1/cashier/ledger/withdrawals/edit' => ['cashier', 'supervisor', 'admin'],
    'POST /daily-ledger/api/v1/cashier/ledger/delivery-edit' => ['cashier', 'supervisor', 'admin'],
    'POST /daily-ledger/api/v1/offline/enroll' => ['cashier', 'supervisor', 'admin'],
    'POST /daily-ledger/api/v1/offline/revoke' => ['cashier', 'supervisor', 'admin'],
    'POST /daily-ledger/api/v1/offline/reconcile' => ['cashier', 'supervisor', 'admin'],
    'POST /daily-ledger/api/v1/cashier/ledger/close-day' => ['cashier', 'supervisor', 'admin', 'production_in_charge'],
    'POST /daily-ledger/api/v1/products/toggle' => ['cashier', 'supervisor', 'production_in_charge', 'admin'],
];
$declaredRoutes = $manifest['capabilities']['routes'] ?? null;
$expectedRoutes = array_merge($priorRoutes, array_keys($routeGroups));
$check(
    'REAL-MANIFEST pins exactly the 44 Daily Ledger authority route keys',
    is_array($declaredRoutes) && array_keys($declaredRoutes) === $expectedRoutes,
    json_encode(array_keys(is_array($declaredRoutes) ? $declaredRoutes : []))
);
foreach ($routeGroups as $routeKey => $allowedRoles) {
    $declaration = is_array($declaredRoutes) ? ($declaredRoutes[$routeKey] ?? null) : null;
    $check(
        "REAL-MANIFEST {$routeKey} pins its exact role group and kernel.auth.authenticate@1",
        is_array($declaration)
            && ($declaration['capability'] ?? null) === 'kernel.auth.authenticate@1'
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

foreach ($routeGroups as $routeKey => $allowedRoles) {
    [$method, $path] = explode(' ', $routeKey, 2);
    foreach ($allowedRoles as $index => $role) {
        $actor = ['id' => 100 + $index, 'role' => $role, 'source' => 'daily-ledger', 'tenant_id' => 42];
        $decision = AuthorityDispatchGuard::decide('daily-ledger', $method, $path, $path, $manifest, $actor, $resolver);
        $check(
            "MUST-ALLOW {$routeKey}: Daily Ledger {$role}",
            $decision['allowed'] && $decision['configured'] && $decision['reason'] === 'allowed',
            json_encode($decision)
        );
    }
    $kernelAdmin = ['id' => 190, 'role' => 'admin', 'source' => 'kernel', 'tenant_id' => 42];
    $decision = AuthorityDispatchGuard::decide('daily-ledger', $method, $path, $path, $manifest, $kernelAdmin, $resolver);
    $check(
        "MUST-ALLOW {$routeKey}: kernel-source admin (opt-in remains downstream)",
        $decision['allowed'] && $decision['configured'] && $decision['reason'] === 'allowed',
        json_encode($decision)
    );
}

$scopeProbe = $resolver->resolve(
    AuthorityScopeResolver::WEB,
    ['actor' => ['id' => 199, 'role' => 'admin', 'source' => 'daily-ledger', 'tenant_id' => 42]],
);
$check(
    'MUST-ALLOW context resolves the verified tenant 42 web authority scope',
    $scopeProbe instanceof AuthorityScope
        && $scopeProbe->tenantId === 42
        && $scopeProbe->entryPoint === AuthorityScopeResolver::WEB
        && count($scopeCalls) === 47,
    $scopeProbe instanceof AuthorityScope
        ? json_encode(['tenant_id' => $scopeProbe->tenantId, 'entry_point' => $scopeProbe->entryPoint, 'calls' => count($scopeCalls)])
        : 'scope unresolved'
);

$moduleRoles = ['admin', 'supervisor', 'cashier', 'production_in_charge', 'auditor', 'viewer', 'employee'];
foreach ($routeGroups as $routeKey => $allowedRoles) {
    [$method, $path] = explode(' ', $routeKey, 2);
    $refusedRoles = array_merge(array_values(array_diff($moduleRoles, $allowedRoles)), ['technician']);
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
            "MUST-REFUSE {$routeKey}: {$role} gets role_not_allowed",
            !$decision['allowed'] && $decision['configured'] && $decision['reason'] === 'role_not_allowed',
            json_encode($decision)
        );
    }
}

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
    'PARITY: undeclared cashier ledger withdrawals write remains permitted',
    $undeclared['allowed'] && !$undeclared['configured'] && $undeclared['reason'] === 'no_authority_declared',
    json_encode($undeclared)
);

// The marker can only be written after executeModuleHandler's real dispatch guard.
$tempRoot = sys_get_temp_dir() . '/daily-ledger-cashier-family-authority-' . bin2hex(random_bytes(6));
$marker = $tempRoot . '/handler-ran';
$originalServer = $_SERVER;
$hadDiscoveredModules = array_key_exists('_kernel_discovered_modules', $GLOBALS);
$originalDiscoveredModules = $GLOBALS['_kernel_discovered_modules'] ?? null;
$hadMarkerGlobal = array_key_exists('daily_ledger_cashier_family_authority_fixture_marker', $GLOBALS);
$originalMarkerGlobal = $GLOBALS['daily_ledger_cashier_family_authority_fixture_marker'] ?? null;
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
function dailyLedgerCashierFamilyAuthorityFixtureHandler(array $params = []): void
{
    file_put_contents((string)$GLOBALS['daily_ledger_cashier_family_authority_fixture_marker'], 'executed');
    echo 'fixture-handler-executed';
}
PHP);

    $fixtureId = 'daily-ledger-cashier-family-authority-fixture';
    $fixturePath = '/daily-ledger/api/v1/cashier-family-authority-fixture';
    $GLOBALS['daily_ledger_cashier_family_authority_fixture_marker'] = $marker;
    $GLOBALS['_kernel_discovered_modules'] = [
        $fixtureId => [
            'id' => $fixtureId,
            'name' => 'Daily Ledger Cashier Family Authority Fixture',
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
                        'allowed_roles' => ['cashier', 'supervisor', 'admin'],
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

    $currentUserProperty->setValue($app, ['id' => 401, 'role' => 'production_in_charge', 'source' => $fixtureId, 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':dailyLedgerCashierFamilyAuthorityFixtureHandler', [], $fixturePath, 'POST');
    $deniedBody = (string)ob_get_clean();
    $deniedStatus = http_response_code();
    $deniedPayload = json_decode($deniedBody, true);
    $check(
        'PRE-HANDLER: out-of-group production_in_charge dispatch is refused with 403',
        $deniedStatus === 403
            && is_array($deniedPayload)
            && ($deniedPayload['ok'] ?? null) === false
            && ($deniedPayload['error'] ?? null) === 'Forbidden'
            && ($deniedPayload['state'] ?? null) === 'route_authority_denied',
        'status=' . $deniedStatus . ' body=' . $deniedBody
    );
    $check('PRE-HANDLER: refusal leaves the fixture handler marker unset', !is_file($marker));

    $currentUserProperty->setValue($app, ['id' => 402, 'role' => 'cashier', 'source' => $fixtureId, 'tenant_id' => 1]);
    http_response_code(200);
    ob_start();
    executeModuleHandler($fixtureId . ':dailyLedgerCashierFamilyAuthorityFixtureHandler', [], $fixturePath, 'POST');
    $allowedBody = (string)ob_get_clean();
    $allowedStatus = http_response_code();
    $check(
        'PRE-HANDLER: in-group cashier dispatch executes the fixture handler',
        $allowedStatus === 200 && $allowedBody === 'fixture-handler-executed' && is_file($marker),
        'status=' . $allowedStatus . ' body=' . $allowedBody
    );
} finally {
    $currentUserProperty->setValue($app, $originalUser);
    $tenantResolver->setTenantId(is_numeric($originalTenant) ? (int)$originalTenant : null);
    $_SERVER = $originalServer;
    if ($hadMarkerGlobal) {
        $GLOBALS['daily_ledger_cashier_family_authority_fixture_marker'] = $originalMarkerGlobal;
    } else {
        unset($GLOBALS['daily_ledger_cashier_family_authority_fixture_marker']);
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
$lines[] = 'not applicable: offline device-token contact path — it uses device binding downstream, not the session actor shape checked by this guard';
foreach ($lines as $line) {
    echo $line . "\n";
}
echo "result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
