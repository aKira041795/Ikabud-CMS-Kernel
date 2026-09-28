<?php

/**
 * Route authority (P2) — enforcement of declared route authority.
 *
 * The claim under test: authority is a property of the request, not a courtesy
 * of the handler. A route that DECLARES its required capability must have that
 * authority established before the handler body runs; a denial must leave the
 * body unexecuted.
 *
 * Falsifier: the dispatch test asserts the guard's own denial payload
 * (`route_authority_denied`). Remove the guard, or move authorization into the
 * handler, and the handler emits its own response instead — the assertion fails.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

ob_start();

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// The registry is shared runtime state; a full suite deletes it outright, so
// snapshot the exact bytes and restore them no matter how this test exits.
$registryPath = STORAGE_PATH . '/modules.json';
$registryBackup = is_file($registryPath) ? (string)file_get_contents($registryPath) : null;
register_shutdown_function(static function () use ($registryPath, $registryBackup): void {
    if ($registryBackup === null) {
        @unlink($registryPath);
        return;
    }
    @file_put_contents($registryPath, $registryBackup);
});

// Integrity guard. If the route-authority guard is removed, the handler body
// runs instead: it enforces its own CSRF and terminates the process, so the
// assertions below never execute. Without this guard that death exits 0 and the
// suite would report green on a missing enforcement point — exactly the failure
// mode that once made 22 broken tests look healthy. A death is a FAILURE.
$GLOBALS['route_authority_test_complete'] = false;
register_shutdown_function(static function (): void {
    if (($GLOBALS['route_authority_test_complete'] ?? false) === true) {
        return;
    }

    fwrite(STDERR, "\nFAIL: this test terminated before completing.\n");
    fwrite(STDERR, "A death during dispatch means the handler body ran — the route-authority\n");
    fwrite(STDERR, "guard did not prevent it. Enforcement is missing or misplaced.\n");
    exit(1);
});

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== DECLARATION CONTRACT (pure — no disk, no discovery) ===\n";

$owned = [
    'capabilities' => [
        'exposes' => [['id' => 'demo.thing.create@1']],
        'depends' => ['kernel.audit.record@1'],
        'routes' => [
            'POST /a/ok' => 'demo.thing.create@1',
            'post /a/lowercase-method' => 'demo.thing.create@1',
            'POST /a/unversioned' => 'demo.thing.create',
            'POST /a/unknown-capability' => 'demo.thing.missing@1',
            'not-a-route-key' => 'demo.thing.create@1',
            'POST relative-no-slash' => 'demo.thing.create@1',
        ],
    ],
];
$declared = moduleRouteAuthorityManifestBlockFromManifest($owned, 'routes', 'demo');

t(
    'only owned, well-formed declarations survive',
    array_keys($declared) === ['POST /a/ok', 'POST /a/lowercase-method'],
    json_encode($declared)
);
t(
    'method casing is normalized to uppercase',
    isset($declared['POST /a/lowercase-method'])
        && $declared['POST /a/lowercase-method'] === 'demo.thing.create@1',
    json_encode($declared)
);
t(
    'an unversioned capability id is rejected (would authorize against nothing)',
    !isset($declared['POST /a/unversioned']),
    json_encode($declared)
);
t(
    'a stale capability the module does not expose or depend on is rejected',
    !isset($declared['POST /a/unknown-capability']),
    json_encode($declared)
);
t(
    'a malformed route key is rejected',
    !isset($declared['not-a-route-key']) && !isset($declared['POST relative-no-slash']),
    json_encode($declared)
);

$exemptions = moduleRouteAuthorityManifestBlockFromManifest([
    'governance' => [
        'exemptions' => [
            ['method' => 'POST', 'route' => '/x/login', 'reason' => ''],
            ['method' => 'POST', 'route' => '/x/health', 'reason' => '   '],
            ['method' => 'POST', 'route' => '/x/session', 'reason' => 'session establishment'],
            ['method' => 'POST', 'route' => '/x/no-method'],
        ],
    ],
], 'exemptions', 'demo');

t(
    'an exemption without a reason is rejected, not silently accepted',
    !isset($exemptions['POST /x/login']) && !isset($exemptions['POST /x/health']),
    json_encode($exemptions)
);
t(
    'a reasoned exemption is kept with its reason',
    ($exemptions['POST /x/session'] ?? null) === 'session establishment',
    json_encode($exemptions)
);
t(
    'an exemption with no method is rejected',
    !isset($exemptions['POST /x/no-method']) && count($exemptions) === 1,
    json_encode($exemptions)
);

echo "\n=== KEY RESOLUTION ===\n";

$keys = ['POST /api/v1/x/things' => 'a@1', 'DELETE /api/v1/x/things/{id}' => 'b@1'];

t(
    'the router-supplied pattern resolves exactly',
    moduleRouteAuthorityResolveKey('POST', '/api/v1/x/things', '/api/v1/x/things', $keys) === 'POST /api/v1/x/things'
);
t(
    'a concrete URI resolves against a parameterized declaration',
    moduleRouteAuthorityResolveKey('DELETE', null, '/api/v1/x/things/42', $keys) === 'DELETE /api/v1/x/things/{id}',
    (string)moduleRouteAuthorityResolveKey('DELETE', null, '/api/v1/x/things/42', $keys)
);
t(
    'the wrong method does not resolve',
    moduleRouteAuthorityResolveKey('GET', null, '/api/v1/x/things', $keys) === null
);
t(
    'an undeclared route does not resolve',
    moduleRouteAuthorityResolveKey('POST', '/api/v1/x/other', '/api/v1/x/other', $keys) === null
);

echo "\n=== REAL MANIFEST DECLARATIONS ===\n";

$akiraDeclared = moduleRouteAuthorityDeclarations('cms-akira-core');
t(
    'cms-akira-core declares authority for its five post mutations',
    count($akiraDeclared) === 5
        && ($akiraDeclared['POST /api/v1/cms-akira/posts'] ?? null) === 'akira.post.create@1'
        && ($akiraDeclared['DELETE /api/v1/cms-akira/posts/{slug}'] ?? null) === 'akira.post.delete@1',
    json_encode($akiraDeclared)
);
// The subject for this assertion — and for the undeclared-route probes below — must be a module that
// declares NO route authority. It is DISCOVERED, not named. An earlier revision hardcoded
// `gui-settings`, and on 2026-09-14 the P2 route-coverage programme correctly gave gui-settings its two
// write declarations; this test then failed, reporting a correct change as a regression. The premise is
// asserted here so that if the subject ever disappears the failure says so, instead of being a mystery.
$declarationFree = null;
foreach (array_keys(discoverModules()) as $candidateModule) {
    if (moduleRouteAuthorityDeclarations((string) $candidateModule) === []) {
        $declarationFree = (string) $candidateModule;
        break;
    }
}
if ($declarationFree === null) {
    echo "SKIP: no declaration-free module remains; the undeclared-route path cannot be exercised\n";
}

t(
    'a module with no declarations yields an empty map',
    $declarationFree !== null && moduleRouteAuthorityDeclarations($declarationFree) === [],
    $declarationFree === null
        ? 'no declaration-free module found'
        : $declarationFree . ': ' . json_encode(moduleRouteAuthorityDeclarations($declarationFree))
);

echo "\n=== FAIL-CLOSED PROBE (the semantic difference from call()) ===\n";

$probe = app()->cap()->authorize('akira.post.create@1', ['caller_module' => 'cms-akira-core']);

t(
    'the probe never invokes a provider and reports a decision',
    is_array($probe) && array_key_exists('allowed', $probe) && array_key_exists('reason', $probe),
    json_encode($probe)
);
t(
    'an unauthorised actor is refused by the probe',
    ($probe['allowed'] ?? true) === false,
    json_encode($probe)
);
t(
    'the refusal is readable (a named reason, not a silent false)',
    is_string($probe['reason'] ?? null) && $probe['reason'] !== '' && $probe['reason'] !== 'authorized',
    (string)($probe['reason'] ?? '')
);
t(
    'an unresolvable capability is refused, not degraded to allowed',
    (app()->cap()->authorize('no.such.capability@1', ['caller_module' => 'cms-akira-core'])['allowed'] ?? true) === false
);

echo "\n=== DISPATCH: THE HANDLER BODY MUST NOT RUN ===\n";

$originalServer = $_SERVER;

// Enable the suite so the route is reachable through the real dispatcher.
foreach (discoverModules() as $moduleId => $manifest) {
    if (str_starts_with($moduleId, 'cms-akira-') && !isModuleEnabled($moduleId)) {
        enableModule($moduleId);
    }
}
unset($GLOBALS['_kernel_discovered_modules']);

$enabled = getEnabledModules();
$dispatcherUsable = isset($enabled['cms-akira-core']);

t('cms-akira-core is dispatchable for this test', $dispatcherUsable, json_encode(array_keys($enabled)));

if ($dispatcherUsable) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/api/v1/cms-akira/posts';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    http_response_code(200);

    ob_start();
    executeModuleHandler(
        'cms-akira-core:apiCmsAkiraPostCreate',
        [],
        '/api/v1/cms-akira/posts',
        'POST'
    );
    $body = (string)ob_get_clean();
    $status = http_response_code();

    t(
        'a declared route with no authority returns 403',
        $status === 403,
        'status=' . $status . ' body=' . substr($body, 0, 300)
    );
    t(
        'the denial is the guard payload, not the handler response',
        str_contains($body, 'route_authority_denied') && str_contains($body, '"ok":false'),
        substr($body, 0, 300)
    );
    t(
        'the denial names the required authority',
        str_contains($body, 'akira.post.create@1'),
        substr($body, 0, 300)
    );
    t(
        'the denial names a readable reason',
        str_contains($body, '"reason"') && !str_contains($body, '"reason":""'),
        substr($body, 0, 300)
    );
} else {
    t('a declared route with no authority returns 403', false, 'dispatcher unusable');
    t('the denial is the guard payload, not the handler response', false, 'dispatcher unusable');
    t('the denial names the required authority', false, 'dispatcher unusable');
    t('the denial names a readable reason', false, 'dispatcher unusable');
}

echo "\n=== OBSERVATIONS ===\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';

t(
    'the denied decision is recorded with module, route, capability and reason',
    str_contains($appLog, 'route.authority.denied')
        && str_contains($appLog, 'cms-akira-core')
        && str_contains($appLog, 'akira.post.create@1'),
    substr($appLog, 0, 400)
);

// Undeclared routes are compatibility debt: they run, and they are observed.
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
$_SERVER = $originalServer;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/v1/undeclared-probe/action';

$undeclaredAllowed = $declarationFree !== null && moduleRouteAuthorityEnforce(
    $declarationFree,
    'POST',
    '/api/v1/undeclared-probe/action',
    '/api/v1/undeclared-probe/action',
    null
);

$undeclaredLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';

// A public presentation route is not a business operation: observing it once per
// page view would be noise. Compatibility debt is measured over mutations only —
// the same denominator the census uses.
file_put_contents(STORAGE_PATH . '/logs/app.log', '');
$getAllowed = $declarationFree !== null && moduleRouteAuthorityEnforce(
    $declarationFree,
    'GET',
    '/undeclared-probe',
    '/undeclared-probe',
    null
);
$getLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';

t(
    'an undeclared presentation route proceeds without writing an observation',
    $getAllowed === true && !str_contains($getLog, 'route.authority.undeclared'),
    substr($getLog, 0, 300)
);

t(
    'an undeclared route still proceeds (compatibility, not breakage)',
    $undeclaredAllowed === true
);
t(
    'an undeclared route is observed for the census and the gate',
    str_contains($undeclaredLog, 'route.authority.undeclared')
        && $declarationFree !== null
        && str_contains($undeclaredLog, $declarationFree),
    substr($undeclaredLog, 0, 400)
);
t(
    'the observation is not recorded as an exemption',
    !str_contains($undeclaredLog, 'route.authority.exempt'),
    substr($undeclaredLog, 0, 400)
);

$_SERVER = $originalServer;

echo "\n=== LOG CHECK ===\n";

$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
t(
    'no PHP fatal/recoverable errors were logged',
    !preg_match('/Fatal error|Uncaught (Error|Exception)|PHP Fatal/i', $errLog),
    substr($errLog, 0, 400)
);

echo "\n=== RESULT ===\n";
echo "  passed: {$pass}\n";
echo "  failed: {$fail}\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    - {$error}\n";
    }
}
echo "\n";

if (ob_get_level() > 0) {
    ob_end_flush();
}

$GLOBALS['route_authority_test_complete'] = true;

exit($fail === 0 ? 0 : 1);
