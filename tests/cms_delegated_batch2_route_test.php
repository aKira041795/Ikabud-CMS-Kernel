<?php

/**
 * CMS delegated batch-2 route authority executable proof.
 *
 * This suite deliberately runs the real scoped GovernanceCensus::scan('cms'):
 * it is slow, but proves static handler binding with the production parser and
 * classifier rather than duplicating census logic in this test.
 *
 * ROLLBACK FALSIFIER: roll back if a delegated route's handler gate stops
 * deciding — either a caller the module predicate admits is refused at
 * dispatch, or refusal no longer comes from the capability layer. Delegated
 * declarations make no dispatch decision by design; safety comes from the
 * handler predicate. No-deploy recovery: KERNEL_AUTHORITY_DISPATCH_ENFORCE=false.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../kernel/Workbench/Governance/GovernanceCensus.php';

use Ikabud\Kernel\Capabilities\AuthorityScopeResolver;
use Ikabud\Kernel\Http\AuthorityDispatchGuard;
use Ikabud\Kernel\Workbench\Governance\GovernanceCensus;

$passed = 0;
$failed = 0;
$lines = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed, &$lines): void {
    $ok ? ++$passed : ++$failed;
    $lines[] = ($ok ? 'PASS: ' : 'FAIL: ') . $label . (!$ok && $detail !== '' ? ' — ' . $detail : '');
};

$manifest = json_decode(
    (string) file_get_contents(__DIR__ . '/../modules/cms/module.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$declared = $manifest['capabilities']['routes'] ?? null;

$roleRoutes = [
    'POST /api/v1/cms/content/{id}/capabilities',
    'POST /api/v1/cms/content/{id}/capabilities/preset',
    'POST /api/v1/cms/content/{id}/capabilities/{cap_id}/detach',
];
$delegatedRoutes = [
    'POST /api/v1/cms/settings' => 'settings.manage',
    'POST /api/v1/cms/settings/reset' => 'settings.manage',
    'POST /api/v1/cms/permissions' => 'settings.manage',
    'POST /api/v1/cms/permissions/reset' => 'settings.manage',
    'POST /api/v1/cms/themes/upload' => 'settings.manage',
    'POST /api/v1/cms/themes/activate' => 'settings.manage',
    'POST /api/v1/cms/themes/{slug}/delete' => 'settings.manage',
    'POST /api/v1/cms/customizer/presets/apply' => 'customizer.manage',
    'POST /api/v1/cms/customizer/{scope}/{section}' => 'customizer.manage',
    'POST /api/v1/cms/customizer/{section}' => 'customizer.manage',
];
$batch2Handlers = [
    'POST /api/v1/cms/settings/reset' => 'cmsApiSettingsReset',
    'POST /api/v1/cms/permissions' => 'cmsApiPermissionsSave',
    'POST /api/v1/cms/permissions/reset' => 'cmsApiPermissionsReset',
    'POST /api/v1/cms/themes/upload' => 'cmsApiThemeUpload',
    'POST /api/v1/cms/themes/activate' => 'cmsApiThemeActivate',
    'POST /api/v1/cms/themes/{slug}/delete' => 'cmsApiThemeDelete',
    'POST /api/v1/cms/customizer/presets/apply' => 'cmsApiThemePresetApply',
    'POST /api/v1/cms/customizer/{scope}/{section}' => 'cmsApiCustomizerSave',
    'POST /api/v1/cms/customizer/{section}' => 'cmsApiCustomizerSave',
];

foreach ($batch2Handlers as $routeKey => $_handler) {
    $expected = ['mode' => 'module-decides', 'capability' => $delegatedRoutes[$routeKey]];
    $actual = is_array($declared) ? ($declared[$routeKey] ?? null) : null;
    $check(
        "REAL MANIFEST: {$routeKey} is exactly delegated to {$delegatedRoutes[$routeKey]} with no allowed_roles",
        $actual === $expected && !array_key_exists('allowed_roles', $actual),
        json_encode($actual, JSON_UNESCAPED_SLASHES),
    );
}

$expectedKeys = [...$roleRoutes, ...array_keys($delegatedRoutes)];
$check(
    'REAL MANIFEST TOTALITY: capabilities.routes contains exactly the owned 13 keys',
    is_array($declared) && array_keys($declared) === $expectedKeys,
    json_encode(array_keys(is_array($declared) ? $declared : []), JSON_UNESCAPED_SLASHES),
);

$expectedRoleDeclaration = [
    'capability' => 'cms.content.update@1',
    'allowed_roles' => ['editor', 'administrator', 'superadmin', 'admin'],
];
foreach ($roleRoutes as $routeKey) {
    $check(
        "ANTI-REGRESSION: {$routeKey} retains its exact role declaration",
        ($declared[$routeKey] ?? null) === $expectedRoleDeclaration,
        json_encode($declared[$routeKey] ?? null, JSON_UNESCAPED_SLASHES),
    );
}
$check(
    'ANTI-REGRESSION: slice-4b settings declaration remains exact',
    ($declared['POST /api/v1/cms/settings'] ?? null) === ['mode' => 'module-decides', 'capability' => 'settings.manage'],
    json_encode($declared['POST /api/v1/cms/settings'] ?? null, JSON_UNESCAPED_SLASHES),
);

$previousEnv = getenv(AuthorityDispatchGuard::ENV_FLAG);
$hadEnvArray = array_key_exists(AuthorityDispatchGuard::ENV_FLAG, $_ENV);
$previousEnvArray = $_ENV[AuthorityDispatchGuard::ENV_FLAG] ?? null;
putenv(AuthorityDispatchGuard::ENV_FLAG . '=true');
$_ENV[AuthorityDispatchGuard::ENV_FLAG] = 'true';

$resolver = new AuthorityScopeResolver(
    static fn (int $tenantId): ?PDO => null,
    static fn (?array $actor): ?int => ($actor['tenant_id'] ?? null) === 42 ? 42 : null,
);
$author = ['id' => 1201, 'source' => 'cms', 'role' => 'author', 'tenant_id' => 42];

try {
    foreach ($batch2Handlers as $routeKey => $_handler) {
        [, $path] = explode(' ', $routeKey, 2);
        $decision = AuthorityDispatchGuard::decide('cms', 'POST', $path, $path, $manifest, $author, $resolver);
        $check(
            "GUARD TRANSPARENCY: {$routeKey} makes no dispatch decision",
            $decision['allowed'] === true
                && $decision['configured'] === false
                && $decision['reason'] === 'missing_authority_configuration',
            json_encode($decision, JSON_UNESCAPED_SLASHES),
        );
    }

    $parity = AuthorityDispatchGuard::decide(
        'cms',
        'POST',
        '/api/v1/cms/redirects',
        '/api/v1/cms/redirects',
        $manifest,
        ['id' => 1202, 'source' => 'cms', 'role' => 'editor', 'tenant_id' => 42],
        $resolver,
    );
    $check(
        'PARITY: undeclared POST /api/v1/cms/redirects remains permitted for an editor',
        $parity['allowed'] === true && $parity['configured'] === false,
        json_encode($parity, JSON_UNESCAPED_SLASHES),
    );

    $census = (new GovernanceCensus(BASE_PATH))->scan('cms');
    $summary = $census['modules'][0]['summary'] ?? [];
    $check(
        'CENSUS CLASSIFICATION: scoped production scan reports 10 bound, 0 unbound/invalid, and 3 dispatch-enforced',
        ($summary['delegated_bound'] ?? null) === 10
            && ($summary['delegated_unbound'] ?? null) === 0
            && ($summary['delegated_invalid'] ?? null) === 0
            && ($summary['dispatch_enforced'] ?? null) === 3,
        json_encode($summary, JSON_UNESCAPED_SLASHES),
    );

    $tenantId = cmsRuntimeTenantId();
    $stateKeys = [
        'cms_settings_cached_t' . $tenantId,
        'cms_settings_value_t' . $tenantId,
        'cms_cap_map_cached_t' . $tenantId,
        'cms_cap_map_t' . $tenantId,
    ];
    $runtimeGlobals = [];
    foreach ($stateKeys as $key) {
        $runtimeGlobals[$key] = ['exists' => array_key_exists($key, $GLOBALS), 'value' => $GLOBALS[$key] ?? null];
    }
    $registryPath = moduleRegistryPath();
    $registryExisted = is_file($registryPath);
    $registryBytes = $registryExisted ? (string) file_get_contents($registryPath) : '';
    $settings = getModuleSettings('cms');
    $rawPermissions = $settings['role_permissions'] ?? '';
    $baselinePermissions = is_string($rawPermissions) ? json_decode($rawPermissions, true) : null;
    $baselinePermissions = is_array($baselinePermissions) ? $baselinePermissions : [];
    unset($baselinePermissions['settings.manage'], $baselinePermissions['customizer.manage']);

    try {
        saveModuleSettings('cms', [
            'role_permissions' => json_encode($baselinePermissions, JSON_UNESCAPED_SLASHES),
        ]);
        cmsResetSettingsCache();
        cmsClearCapMapCache();

        foreach (['settings.manage', 'customizer.manage'] as $capability) {
            $check(
                "CAPABILITY TEETH: ordinary CMS author lacks {$capability}",
                cmsUserCan($author, $capability) === false,
            );
            $serviceActor = [
                'id' => 0,
                'source' => 'cms',
                'role' => 'author',
                'tenant_id' => 42,
                'is_service' => true,
                'service_caps' => [$capability],
            ];
            $check(
                "CAPABILITY TEETH: service caller holding {$capability} is admitted by the registry predicate",
                cmsUserCan($serviceActor, $capability) === true,
            );
        }

        foreach ($batch2Handlers as $routeKey => $handler) {
            [, $path] = explode(' ', $routeKey, 2);
            $probeCode = 'chdir(' . var_export(BASE_PATH, true) . ');'
                . 'require "bootstrap.php";'
                . 'require_once "src/helpers/module-manager.php";'
                . 'require_once "modules/cms/helpers.php";'
                . 'require_once "modules/cms/handlers.php";'
                . 'app()->setUser(["id"=>1203,"source"=>"cms","role"=>"author","tenant_id"=>42]);'
                . '$_SERVER["REQUEST_URI"]=' . var_export($path, true) . ';'
                . '$_SERVER["REQUEST_METHOD"]="POST";'
                . 'register_shutdown_function(static function (): void { fwrite(STDERR, "STATUS=" . http_response_code()); });'
                . $handler . '([]);';
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-r', $probeCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH);
            $stdout = '';
            $stderr = '';
            $rc = -1;
            if (is_resource($process)) {
                $stdout = (string) stream_get_contents($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $rc = proc_close($process);
            }
            $payload = json_decode(trim($stdout), true);
            $required = $delegatedRoutes[$routeKey];
            $check(
                "REAL HANDLER DENIAL: {$routeKey} refuses author at {$required}",
                $rc === 0
                    && $stderr === 'STATUS=403'
                    && is_array($payload)
                    && ($payload['ok'] ?? null) === false
                    && ($payload['error'] ?? null) === 'Permission denied'
                    && ($payload['required'] ?? null) === $required,
                'rc=' . $rc . ' stdout=' . trim($stdout) . ' stderr=' . trim($stderr),
            );
        }

        foreach (['settings.manage', 'customizer.manage'] as $capability) {
            $probeCode = 'chdir(' . var_export(BASE_PATH, true) . ');'
                . 'require "bootstrap.php";'
                . 'require_once "src/helpers/module-manager.php";'
                . 'require_once "modules/cms/helpers.php";'
                . 'app()->setUser(["id"=>0,"source"=>"cms","role"=>"author","tenant_id"=>42,"is_service"=>true,"service_caps"=>[' . var_export($capability, true) . ']]);'
                . '$_SERVER["REQUEST_URI"]="/api/v1/cms/delegated-proof";'
                . 'cmsRequireCap(' . var_export($capability, true) . ');'
                . 'echo "GATE-PASSED";';
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-r', $probeCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH);
            $stdout = '';
            $stderr = '';
            $rc = -1;
            if (is_resource($process)) {
                $stdout = (string) stream_get_contents($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $rc = proc_close($process);
            }
            $check(
                "CAPABILITY GATE PASS: caller holding {$capability} passes cmsRequireCap",
                $rc === 0 && $stdout === 'GATE-PASSED' && $stderr === '',
                'rc=' . $rc . ' stdout=' . trim($stdout) . ' stderr=' . trim($stderr),
            );
        }
    } finally {
        if ($registryExisted) {
            file_put_contents($registryPath, $registryBytes, LOCK_EX);
        } elseif (is_file($registryPath)) {
            unlink($registryPath);
        }
        $GLOBALS['_kernel_module_registry_dirty'] = true;
        if (function_exists('apcu_delete')) {
            apcu_delete('ikabud:module_registry_v1');
        }
        cmsClearPersistentSettingsCache();
        foreach ($runtimeGlobals as $key => $state) {
            if ($state['exists']) {
                $GLOBALS[$key] = $state['value'];
            } else {
                unset($GLOBALS[$key]);
            }
        }
    }
} finally {
    if ($previousEnv === false) {
        putenv(AuthorityDispatchGuard::ENV_FLAG);
    } else {
        putenv(AuthorityDispatchGuard::ENV_FLAG . '=' . $previousEnv);
    }
    if ($hadEnvArray) {
        $_ENV[AuthorityDispatchGuard::ENV_FLAG] = $previousEnvArray;
    } else {
        unset($_ENV[AuthorityDispatchGuard::ENV_FLAG]);
    }
}

$lines[] = 'NOT APPLICABLE: authorized full handler traversal requires route-specific CSRF, upload/input fixtures, and mutations; admission is therefore proven at the shared cmsRequireCap gate, while every denied route executes its real handler path.';
foreach ($lines as $line) {
    echo $line . "\n";
}
echo "result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
