<?php

/**
 * CMS delegated settings-route executable authorization proof.
 *
 * ROLLBACK FALSIFIER: any previously admitted caller (administrator+, a
 * service token holding settings.manage, or a tenant-override-authorized role)
 * denied under the same valid identity/tenant context requires rolling back
 * the declaration.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

use Ikabud\Kernel\Capabilities\AuthorityScopeResolver;
use Ikabud\Kernel\Http\AuthorityDispatchGuard;

$passed = 0;
$failed = 0;
$lines = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed, &$lines): void {
    $ok ? ++$passed : ++$failed;
    $lines[] = ($ok ? 'PASS: ' : 'FAIL: ') . $label . (!$ok && $detail !== '' ? ' — ' . $detail : '');
};

$manifest = json_decode(
    (string)file_get_contents(__DIR__ . '/../modules/cms/module.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$routes = $manifest['capabilities']['routes'] ?? null;
$delegatedKey = 'POST /api/v1/cms/settings';
$delegatedDeclaration = is_array($routes) ? ($routes[$delegatedKey] ?? null) : null;
$expectedDelegated = ['mode' => 'module-decides', 'capability' => 'settings.manage'];
$check(
    'DECLARATION: settings route is exactly delegated {mode, capability}, with no allowed_roles',
    $delegatedDeclaration === $expectedDelegated && !array_key_exists('allowed_roles', $delegatedDeclaration),
    json_encode($delegatedDeclaration, JSON_UNESCAPED_SLASHES),
);

$roleRoutes = [
    'POST /api/v1/cms/content/{id}/capabilities',
    'POST /api/v1/cms/content/{id}/capabilities/preset',
    'POST /api/v1/cms/content/{id}/capabilities/{cap_id}/detach',
];
$expectedRoleDeclaration = [
    'capability' => 'cms.content.update@1',
    'allowed_roles' => ['editor', 'administrator', 'superadmin', 'admin'],
];
$expectedRoutes = [...$roleRoutes, $delegatedKey];
$check(
    'DECLARATION: the three slice-2 role routes and slice-4b delegated route remain a subset',
    is_array($routes) && array_diff($expectedRoutes, array_keys($routes)) === [],
    json_encode(array_keys(is_array($routes) ? $routes : []), JSON_UNESCAPED_SLASHES),
);
foreach ($roleRoutes as $routeKey) {
    $check(
        "ANTI-REGRESSION: {$routeKey} retains its role-mode capability and allowlist",
        ($routes[$routeKey] ?? null) === $expectedRoleDeclaration,
        json_encode($routes[$routeKey] ?? null, JSON_UNESCAPED_SLASHES),
    );
}

$previousEnv = getenv(AuthorityDispatchGuard::ENV_FLAG);
$hadEnvArray = array_key_exists(AuthorityDispatchGuard::ENV_FLAG, $_ENV);
$previousEnvArray = $_ENV[AuthorityDispatchGuard::ENV_FLAG] ?? null;
putenv(AuthorityDispatchGuard::ENV_FLAG . '=true');
$_ENV[AuthorityDispatchGuard::ENV_FLAG] = 'true';
$resolver = new AuthorityScopeResolver(
    static fn(int $tenantId): ?PDO => null,
    static fn(?array $actor): ?int => ($actor['tenant_id'] ?? null) === 42 ? 42 : null,
);
$author = ['id' => 701, 'source' => 'cms', 'role' => 'author', 'tenant_id' => 42];
$guardDecision = AuthorityDispatchGuard::decide(
    'cms',
    'POST',
    '/api/v1/cms/settings',
    '/api/v1/cms/settings',
    $manifest,
    $author,
    $resolver,
);
$check(
    'GUARD TRANSPARENCY (correct delegation, not a denial gap): handler-denied author gets no dispatch decision',
    $guardDecision['allowed'] === true
        && $guardDecision['configured'] === false
        && $guardDecision['reason'] === 'missing_authority_configuration',
    json_encode($guardDecision, JSON_UNESCAPED_SLASHES),
);

$tenantId = cmsRuntimeTenantId();
$settingsCachedKey = 'cms_settings_cached_t' . $tenantId;
$settingsValueKey = 'cms_settings_value_t' . $tenantId;
$capCachedKey = 'cms_cap_map_cached_t' . $tenantId;
$capValueKey = 'cms_cap_map_t' . $tenantId;
$runtimeGlobals = [];
foreach ([$settingsCachedKey, $settingsValueKey, $capCachedKey, $capValueKey] as $key) {
    $runtimeGlobals[$key] = [
        'exists' => array_key_exists($key, $GLOBALS),
        'value' => $GLOBALS[$key] ?? null,
    ];
}
$registryPath = moduleRegistryPath();
$registryExisted = is_file($registryPath);
$registryBytes = $registryExisted ? (string)file_get_contents($registryPath) : '';
$originalSettings = getModuleSettings('cms');
$rawPermissions = $originalSettings['role_permissions'] ?? '';
$baselinePermissions = is_string($rawPermissions) ? json_decode($rawPermissions, true) : null;
$baselinePermissions = is_array($baselinePermissions) ? $baselinePermissions : [];
unset($baselinePermissions['settings.manage']);

$administrator = ['id' => 702, 'source' => 'cms', 'role' => 'administrator', 'tenant_id' => 42];
$serviceToken = [
    'id' => 0,
    'source' => 'cms',
    'role' => 'author',
    'tenant_id' => 42,
    'is_service' => true,
    'service_caps' => ['settings.manage'],
];
$editor = ['id' => 703, 'source' => 'cms', 'role' => 'editor', 'tenant_id' => 42];

try {
    // Pin the default minimum without disturbing any unrelated tenant overrides.
    saveModuleSettings('cms', [
        'role_permissions' => json_encode($baselinePermissions, JSON_UNESCAPED_SLASHES),
    ]);
    cmsResetSettingsCache();
    cmsClearCapMapCache();

    $check(
        'PREDICATE: CMS administrator is admitted for settings.manage',
        cmsUserCan($administrator, 'settings.manage') === true,
    );
    $check(
        'PREDICATE: CMS author is denied for settings.manage',
        cmsUserCan($author, 'settings.manage') === false,
    );
    $check(
        'PREDICATE: author-role service token is admitted by its settings.manage allowlist',
        cmsUserCan($serviceToken, 'settings.manage') === true,
    );

    $manifestWithoutDeclaration = $manifest;
    unset($manifestWithoutDeclaration['capabilities']['routes'][$delegatedKey]);
    $predicateOutcomes = static function (array $ignoredManifest) use ($administrator, $author, $serviceToken): array {
        // Deliberately exercise the real predicate: capabilities.routes is not an input.
        return [
            'administrator' => cmsUserCan($administrator, 'settings.manage'),
            'author' => cmsUserCan($author, 'settings.manage'),
            'service_token' => cmsUserCan($serviceToken, 'settings.manage'),
        ];
    };
    $withDeclaration = $predicateOutcomes($manifest);
    $withoutDeclaration = $predicateOutcomes($manifestWithoutDeclaration);
    $check(
        'DOWNSTREAM-INTACT: base predicate outcomes are identical with declaration present or removed',
        $withDeclaration === $withoutDeclaration
            && $withoutDeclaration === ['administrator' => true, 'author' => false, 'service_token' => true],
        json_encode(['with' => $withDeclaration, 'without' => $withoutDeclaration]),
    );
    $check(
        'DOWNSTREAM-INTACT: removing the declaration does not make the author denial disappear',
        $withoutDeclaration['author'] === false,
    );

    // Invoke the real route handler in an isolated PHP process. The author is
    // stopped by cmsRequireCap before CSRF, input parsing, or saveModuleSettings.
    $storeBeforeDeniedHandler = is_file($registryPath) ? (string)file_get_contents($registryPath) : '';
    $probeCode = 'chdir(' . var_export(BASE_PATH, true) . ');'
        . 'require "bootstrap.php";'
        . 'require_once "src/helpers/module-manager.php";'
        . 'require_once "modules/cms/helpers.php";'
        . 'require_once "modules/cms/handlers.php";'
        . 'app()->setUser(["id"=>704,"source"=>"cms","role"=>"author","tenant_id"=>42]);'
        . '$_SERVER["REQUEST_URI"]="/api/v1/cms/settings";'
        . '$_SERVER["REQUEST_METHOD"]="POST";'
        . 'register_shutdown_function(static function (): void { fwrite(STDERR, "STATUS=" . http_response_code()); });'
        . 'cmsApiSettingsSave([]);';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-r', $probeCode],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        BASE_PATH,
    );
    $deniedBody = '';
    $deniedError = '';
    $probeRc = -1;
    if (is_resource($process)) {
        $deniedBody = (string)stream_get_contents($pipes[1]);
        $deniedError = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $probeRc = proc_close($process);
    }
    $deniedPayload = json_decode(trim($deniedBody), true);
    $check(
        'REAL HANDLER DENIAL: cmsApiSettingsSave returns 403 JSON requiring settings.manage',
        $probeRc === 0
            && $deniedError === 'STATUS=403'
            && is_array($deniedPayload)
            && ($deniedPayload['ok'] ?? null) === false
            && ($deniedPayload['error'] ?? null) === 'Permission denied'
            && ($deniedPayload['required'] ?? null) === 'settings.manage',
        'rc=' . $probeRc . ' stdout=' . trim($deniedBody) . ' stderr=' . trim($deniedError),
    );
    $storeAfterDeniedHandler = is_file($registryPath) ? (string)file_get_contents($registryPath) : '';
    $check(
        'NO MUTATION: denied real handler leaves the CMS settings store byte-identical',
        hash_equals($storeBeforeDeniedHandler, $storeAfterDeniedHandler),
    );

    $overridePermissions = $baselinePermissions;
    $overridePermissions['settings.manage'] = 'editor';
    saveModuleSettings('cms', [
        'role_permissions' => json_encode($overridePermissions, JSON_UNESCAPED_SLASHES),
    ]);
    cmsResetSettingsCache();
    cmsClearCapMapCache();
    $editorPredicate = static function (array $ignoredManifest) use ($editor): bool {
        return cmsUserCan($editor, 'settings.manage');
    };
    $editorWithDeclaration = $editorPredicate($manifest);
    $editorWithoutDeclaration = $editorPredicate($manifestWithoutDeclaration);
    $check(
        'TENANT OVERRIDE: settings store override lowering settings.manage to editor is effective',
        $editorWithDeclaration === true,
    );
    $check(
        'DOWNSTREAM-INTACT: override-authorized editor outcome is identical after declaration removal',
        $editorWithDeclaration === $editorWithoutDeclaration && $editorWithoutDeclaration === true,
    );
} finally {
    // Restore persistent settings bytes first, then restore the exact in-process
    // settings/capability cache state observed on entry.
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

$lines[] = 'PROOF SCOPE: the denied case executed cmsApiSettingsSave through its real cmsRequireCap gate and proved the settings store unchanged; it intentionally did not execute post-gate CSRF or save logic.';
foreach ($lines as $line) {
    echo $line . "\n";
}
echo "result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
