<?php

/**
 * Read authority — does the declaration shape express READ enforcement at all?
 *
 * Measured 2026-09-12: across all 17 module manifests there were 26 declared
 * routes and **zero** GET declarations. The declaration map is keyed
 * "<METHOD> <path>", so the shape permits reads — but nothing had ever declared
 * one, which means read authority was untested rather than merely unused.
 *
 * The claim under test: a route that declares its required authority is checked
 * on ANY method (the guard's own stated contract), so reads are enforceable in
 * exactly the way writes already are.
 *
 * Falsifiers, in order of importance:
 *   1. If the declaration validator drops GET, reads can never be declared.
 *   2. If the guard proceeds for a declared GET with no authority, the guard's
 *      "checked on any method" contract is false.
 *   3. If the guard proceeds for a declared GET *and* for the same route
 *      undeclared, the declaration is not load-bearing and the test proves
 *      nothing — so the negative control below is what makes the positive one
 *      meaningful. Do not remove it.
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

$appLog = STORAGE_PATH . '/logs/app.log';

echo "\n=== 1. DECLARATION SHAPE ACCEPTS GET ===\n";

// The validator must keep a GET declaration whose capability the module owns.
// If it silently drops it, every later assertion is vacuous.
$manifest = [
    'capabilities' => [
        'exposes' => [['id' => 'demo.thing.read@1']],
        'depends' => [],
        'routes' => [
            'GET /a/list' => 'demo.thing.read@1',
            'get /a/lowercase' => 'demo.thing.read@1',
            'GET /a/unversioned' => 'demo.thing.read',
            'GET /a/not-owned' => 'demo.thing.other@1',
        ],
    ],
];

$declared = moduleRouteAuthorityManifestBlockFromManifest($manifest, 'routes', 'demo');

t(
    'a GET declaration survives the validator',
    isset($declared['GET /a/list']) && $declared['GET /a/list'] === 'demo.thing.read@1',
    json_encode($declared)
);
t(
    'GET method casing is normalised like every other method',
    isset($declared['GET /a/lowercase']),
    json_encode($declared)
);
t(
    'a GET declaration with an unversioned capability is still rejected',
    !isset($declared['GET /a/unversioned']),
    json_encode($declared)
);
t(
    'a GET declaration naming a capability the module does not own is still rejected',
    !isset($declared['GET /a/not-owned']),
    json_encode($declared)
);

echo "\n=== 2. A GET RESOLVES BY KEY ===\n";

$keys = ['GET /cms-akira-shell/posts' => 'akira.post.admin.list@1'];
t(
    'the router pattern resolves for GET',
    moduleRouteAuthorityResolveKey('GET', '/cms-akira-shell/posts', '/cms-akira-shell/posts', $keys)
        === 'GET /cms-akira-shell/posts'
);
t(
    'the wrong method does not resolve against a GET declaration',
    moduleRouteAuthorityResolveKey('POST', '/cms-akira-shell/posts', '/cms-akira-shell/posts', $keys) === null
);

// A templated declaration must resolve for a concrete URI, not just the literal
// pattern — the edit form is a {slug} route, so the declaration is only useful
// if the resolver matches a real request against it.
$editKeys = ['GET /cms-akira-shell/posts/{slug}/edit' => 'akira.post.admin.get@1'];
t(
    'a templated GET declaration resolves for a concrete URI',
    moduleRouteAuthorityResolveKey(
        'GET',
        '/cms-akira-shell/posts/{slug}/edit',
        '/cms-akira-shell/posts/some-post/edit',
        $editKeys
    ) === 'GET /cms-akira-shell/posts/{slug}/edit'
);
t(
    'the templated declaration does not resolve for the list route',
    moduleRouteAuthorityResolveKey(
        'GET',
        null,
        '/cms-akira-shell/posts',
        $editKeys
    ) === null
);

echo "\n=== 3. THE REAL MANIFEST DECLARES THE READ ===\n";

$shellDeclared = moduleRouteAuthorityDeclarations('cms-akira-shell');
t(
    'cms-akira-shell declares authority for its admin post list',
    ($shellDeclared['GET /cms-akira-shell/posts'] ?? null) === 'akira.post.admin.list@1',
    json_encode(array_filter(
        (array) $shellDeclared,
        static fn ($k) => str_starts_with((string) $k, 'GET'),
        ARRAY_FILTER_USE_KEY
    ))
);

$expectedReads = [
    'GET /cms-akira-shell' => 'akira.post.admin.list@1',
    'GET /cms-akira-shell/posts' => 'akira.post.admin.list@1',
    'GET /cms-akira-shell/posts/new' => 'akira.taxonomy.list@1',
    'GET /cms-akira-shell/posts/{slug}/edit' => 'akira.post.admin.get@1',
    'GET /cms-akira-shell/categories' => 'akira.taxonomy.list@1',
    'GET /cms-akira-shell/content-types' => 'akira.content_type.list@1',
    'GET /cms-akira-shell/media' => 'akira.media.library@1',
    'GET /cms-akira-shell/permissions' => 'akira.policy.list@1',
    'GET /cms-akira-shell/users' => 'akira.user.list@1',
    // Operator surfaces added by the Akira completion programme. Each was admitted here only after
    // confirming the invariant this assertion exists to protect — a handler gate before dispatch AND
    // an active policy row admitting `administrator` — so the list stays a statement of verified truth
    // rather than a rubber stamp. The excluded-read assertion below is unchanged.
    'GET /cms-akira-shell/authority' => 'akira.policy.list@1',        // akiraShellAuthority — admin gate
    'GET /cms-akira-shell/provenance' => 'kernel.provenance.list@1',  // akiraShellProvenance — akiraShellAuthorize
    'GET /cms-akira-shell/modules' => 'akira.module.list@1',          // akiraShellModules — admin gate
    'GET /cms-akira-shell/workflow' => 'akira.workflow.runs@1',       // akiraShellWorkflowConsole — admin gate
    'GET /cms-akira-shell/search' => 'akira.search.query@1',          // akiraShellSearch — admin gate
    'GET /cms-akira-shell/settings' => 'akira.site.settings.get@1',   // akiraShellSettings — admin gate
    'GET /cms-akira-shell/backups' => 'akira.backup.list@1',          // akiraShellBackups — admin gate
    'GET /cms-akira-shell/redirects' => 'akira.redirect.list@1',       // akiraShellRedirects — admin gate
    'GET /cms-akira-shell/compositions' => 'akira.shell.admin_page@1', // akiraShellCompositions — admin gate
    'GET /cms-akira-shell/compositions/{key}/edit' => 'akira.shell.admin_page@1', // akiraShellCompositionEdit — admin gate
    'GET /cms-akira-shell/health' => 'akira.shell.admin_page@1',       // akiraShellModuleHealth — admin gate
    // Theme Studio moved into the shared shell (CD-58/59). The shell owns the
    // document chrome and routes /cms-akira-theme through akiraShellThemeStudio,
    // which takes the same administrator gate before dispatch and is backed by
    // the existing akira.shell.admin_page@1 policy row — no policy was widened.
    'GET /cms-akira-theme' => 'akira.shell.admin_page@1',             // akiraShellThemeStudio — admin gate
];
$actualReads = array_filter(
    (array) $shellDeclared,
    static fn ($k) => str_starts_with((string) $k, 'GET'),
    ARRAY_FILTER_USE_KEY
);
// The invariant this assertion guards is the SET of declared reads and the capability each is bound
// to — not the order the manifest happens to list them in. Array identity is order-sensitive, so
// normalise both sides instead of hand-maintaining a declaration order that carries no security
// meaning. Every key and every value must still match exactly: this remains a full equality check.
ksort($expectedReads);
ksort($actualReads);
t(
    'cms-akira-shell declares exactly the reads backed by matching handler gates and policy rows',
    $actualReads === $expectedReads,
    json_encode($actualReads)
);

// Authentication infrastructure remains outside the read denominator. Public
// and denial surfaces use reasoned exemptions because anonymous dispatch has no
// actor role; none may be capability-declared or anonymous callers would 403.
$excludedReads = [
    'GET /cms-akira-shell/login',
    'GET /cms-akira-shell/forbidden',
    'GET /',
    'GET /posts',
    'GET /posts/{slug}',
    'GET /sitemap.xml',
    'GET /robots.txt',
];
$declaredExcluded = array_values(array_intersect(array_keys($shellDeclared), $excludedReads));
t(
    'non-declarable and protected entry/denial/public surfaces are NOT declared',
    $declaredExcluded === [],
    json_encode($declaredExcluded)
);

echo "\n=== 4. THE GUARD ENFORCES IT (the actual question) ===\n";

$originalServer = $_SERVER;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cms-akira-shell/posts';
$_SERVER['HTTP_ACCEPT'] = 'application/json';

/**
 * @var list<string> $readLogs
 * @var bool $readAllowed
 */
file_put_contents($appLog, '');
ob_start();
$readAllowed = moduleRouteAuthorityEnforce(
    'cms-akira-shell',
    'GET',
    '/cms-akira-shell/posts',
    '/cms-akira-shell/posts',
    null
);
$readBody = (string) ob_get_clean();
$readLogs = (string) @file_get_contents($appLog);

t(
    'a DECLARED read with no authority is refused at dispatch',
    $readAllowed === false,
    'guard returned ' . var_export($readAllowed, true)
);
t(
    'the refusal is the guard payload, not a handler response',
    str_contains($readBody, 'route_authority_denied') || str_contains($readBody, '403'),
    substr($readBody, 0, 200)
);
t(
    'the refusal is recorded as a denial, not as an observation',
    str_contains($readLogs, 'route.authority.denied') && !str_contains($readLogs, 'route.authority.undeclared'),
    substr($readLogs, 0, 300)
);

// The edit form is the second declared read. Its capability
// (akira.post.admin.get@1) has a policy row, so the guard can actually check
// it, and an unauthorised actor must be refused at dispatch exactly like the
// list. A declared route that is NOT enforced would make the whole slice a
// false positive.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cms-akira-shell/posts/some-post/edit';
file_put_contents($appLog, '');
ob_start();
$editAllowed = moduleRouteAuthorityEnforce(
    'cms-akira-shell',
    'GET',
    '/cms-akira-shell/posts/{slug}/edit',
    '/cms-akira-shell/posts/some-post/edit',
    null
);
$editBody = (string) ob_get_clean();
$editLogs = (string) @file_get_contents($appLog);

t(
    'a DECLARED edit read with no authority is refused at dispatch',
    $editAllowed === false,
    'guard returned ' . var_export($editAllowed, true)
);
t(
    'the edit refusal is the guard denial (HTTP 403), not an allowed response',
    str_contains($editBody, '403') && $editAllowed === false,
    substr($editBody, 0, 240)
);
t(
    'the edit denial names the required capability in the authority log',
    str_contains($editLogs, 'akira.post.admin.get@1'),
    substr($editLogs, 0, 400)
);
t(
    'the edit refusal is recorded as a denial, not as an observation',
    str_contains($editLogs, 'route.authority.denied') && !str_contains($editLogs, 'route.authority.undeclared'),
    substr($editLogs, 0, 300)
);

echo "\n=== 5. NEGATIVE CONTROL — is the declaration load-bearing? ===\n";

// Same method, same shape, but a route that declares nothing. If this were also
// refused, the positive result above would prove only that the test harness
// refuses things — not that the declaration caused the enforcement.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cms-akira-shell/login';
file_put_contents($appLog, '');
ob_start();
$undeclaredRead = moduleRouteAuthorityEnforce(
    'cms-akira-shell',
    'GET',
    '/cms-akira-shell/login',
    '/cms-akira-shell/login',
    null
);
ob_end_clean();
$undeclaredLogs = (string) @file_get_contents($appLog);

t(
    'an UNDECLARED read still proceeds (compatibility, not breakage)',
    $undeclaredRead === true,
    'guard returned ' . var_export($undeclaredRead, true)
);
t(
    'the undeclared read is not logged as a denial',
    !str_contains($undeclaredLogs, 'route.authority.denied'),
    substr($undeclaredLogs, 0, 300)
);

// The pair is the actual evidence: identical method and actor, different
// declaration, opposite outcome. That difference is the declaration.
t(
    'the declaration alone decides the outcome (load-bearing)',
    $readAllowed === false && $undeclaredRead === true
);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cms-akira-shell/media';
file_put_contents($appLog, '');
ob_start();
$mediaAllowed = moduleRouteAuthorityEnforce(
    'cms-akira-shell',
    'GET',
    '/cms-akira-shell/media',
    '/cms-akira-shell/media',
    null
);
$mediaBody = (string) ob_get_clean();
$mediaLogs = (string) @file_get_contents($appLog);
t(
    'the declared media library refuses a caller with no authority at dispatch',
    $mediaAllowed === false && str_contains($mediaBody, '403')
        && str_contains($mediaLogs, 'akira.media.library@1'),
    substr($mediaBody . $mediaLogs, 0, 400)
);

$_SERVER = $originalServer;

echo "\n=== 6. LOG CHECK ===\n";

$errLog = (string) @file_get_contents(STORAGE_PATH . '/logs/error.log');
t(
    'no PHP fatal/recoverable errors were logged',
    !preg_match('/Fatal error|Uncaught (Error|Exception)|PHP Fatal/i', $errLog),
    substr($errLog, 0, 300)
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

exit($fail === 0 ? 0 : 1);
