<?php

declare(strict_types=1);

/**
 * Page-cache skip coverage — every module with per-user pages must be un-cacheable, by construction.
 *
 * MEASURED DEFECT (2026-10-10, again on audit 2026-11-20): `config/page-cache-prefixes.php` is a
 * hand-maintained list. `/daily-ledger/` was missing while every sibling module was registered, so
 * daily-ledger pages stayed page-cacheable and a deploy served the OLD html - the fix was on the
 * server, the operator kept seeing the previous render, and the only way through was a developer-only
 * `?disyl_nocache` flag. The same audit then found five MORE modules with the same gap (/wms/,
 * /bakeshop/, /guidance/, /project-audit-ledger/, /inventory-scanner/).
 *
 * The invariant: a module that declares its own `auth_cookie` has authenticated per-user pages, and
 * none of its route prefixes may be page-cached. This test walks the manifests and the route maps and
 * fails when a prefix is missing, so the next module cannot repeat it silently.
 *
 * WHY NOT DERIVE THE LIST AT RUNTIME: the pre-bootstrap fast-path cache
 * (src/helpers/fast-path-cache.php) must decide whether to serve a cached page BEFORE any helper,
 * manifest or DB is available - that is the whole point of it. A derived list there would have to be
 * a generated file, which is the same staleness risk in a different costume, on the most dangerous
 * path in the application. A coverage test enforces the invariant with no runtime cost or risk.
 *
 * Each assertion names what breaks if it regresses:
 * - a missing prefix means a per-user page can be served from cache to another session, which is
 *   both stale and a data-exposure risk;
 * - an over-broad prefix disables caching for public pages, costing the load time this is meant to
 *   protect - so the test also pins that the public roots stay cacheable.
 */

ob_start();
require_once __DIR__ . '/harness/TestHarness.php';
$h = new TestHarness('page-cache-prefix-coverage', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed, $h): void {
    if ($ok) {
        $passed++;
        $h->test($label, true);
        return;
    }
    $failed++;
    $h->test($label, false, $detail);
};

$prefixes = require $root . '/config/page-cache-prefixes.php';
$check('the prefix config returns a list', is_array($prefixes) && $prefixes !== []);

$covered = static function (string $needle) use ($prefixes): bool {
    foreach ($prefixes as $p) {
        if (!is_string($p) || $p === '') {
            continue;
        }
        if (str_starts_with($needle, $p) || str_starts_with($p, $needle)) {
            return true;
        }
    }
    return false;
};

// Route segments that are never a module's own entry point.
$generic = ['api', 'login', 'logout', 'register', 'assets', 'admin', 'auth', 'uploads', 'health', 'webhooks'];

$manifests = glob($root . '/modules/*/module.json') ?: [];
$manifests = array_merge($manifests, glob($root . '/modules/*/*/module.json') ?: []);
$check('module manifests were discovered', $manifests !== [], 'none found');

$audited = 0;
$missing = [];
foreach ($manifests as $manifestPath) {
    $json = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($json)) {
        continue;
    }
    $id = (string)($json['id'] ?? basename(dirname($manifestPath)));
    $cookie = (string)($json['auth_cookie'] ?? '');
    if ($cookie === '') {
        continue; // public module: page caching is legitimate
    }
    $routesPath = dirname($manifestPath) . '/routes.php';
    if (!is_file($routesPath)) {
        continue;
    }
    $audited++;

    $routeSource = (string)file_get_contents($routesPath);
    // Capture the path literal keys of the route maps: '/something/...'
    preg_match_all("#['\"]/([a-z0-9][a-z0-9_-]*)(?:/[^'\"]*)?['\"]#i", $routeSource, $m);
    $tops = array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
    foreach ($tops as $top) {
        if (in_array($top, $generic, true)) {
            continue;
        }
        if (!$covered('/' . $top . '/')) {
            $missing[$id][] = '/' . $top . '/';
        }
    }
}

$check('at least one auth-owned module was audited', $audited > 0, $audited . ' audited');

$details = [];
foreach ($missing as $id => $needles) {
    $details[] = $id . ' => ' . implode(', ', array_unique($needles));
}
$check(
    'every auth-owned module prefix is excluded from the page cache',
    $missing === [],
    'missing: ' . implode(' | ', $details)
);

// The flip side: caching must still work where it is meant to. If a prefix list grows until it
// swallows these, the page cache is dead weight and nobody notices.
//
// This uses the FORWARD direction only, because that is the runtime rule in pageCacheShouldCache():
// `str_starts_with($uri, $prefix)`. The reverse direction is how a module NEEDLE like '/wms/' counts
// as covered by an entry '/wms' - applying it here would make '/' match every entry and report a
// false failure (which it did on the first run of this test).
$wouldBeSkipped = static function (string $uri) use ($prefixes): bool {
    foreach ($prefixes as $p) {
        if (is_string($p) && $p !== '' && str_starts_with($uri, $p)) {
            return true;
        }
    }
    return false;
};
foreach (['/', '/ecommerce/shop', '/cms/page'] as $publicPath) {
    $check('public path stays cacheable: ' . $publicPath, !$wouldBeSkipped($publicPath));
}

// The developer bypass must stay a bypass (this is what masked the daily-ledger bug for days).
$pc = (string)file_get_contents($root . '/src/helpers/page-cache.php');
$check('page-cache.php still honours ?disyl_nocache', str_contains($pc, "disyl_nocache"));
$check('page-cache.php still honours ?nocache', str_contains($pc, "nocache"));
$check(
    'page-cache.php reads the shared prefix config (no second copy of the list)',
    str_contains($pc, 'config/page-cache-prefixes.php')
);

$h->done();
echo "\n" . str_repeat('─', 60) . "\n";
echo ($failed === 0 ? 'PASS' : 'FAIL') . ": {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
