<?php

declare(strict_types=1);

/**
 * Regression test: module login POST routes must stay CSRF-exempt from the
 * automatic CSRF enforcement safety net in public/index.php.
 *
 * The auto-enforce block (public/index.php) rejects every non-API
 * POST/PUT/PATCH/DELETE that carries a session unless the route declares
 * `stateless` or `csrf_exempt` in kernelRouteMeta(). Pre-auth login POSTs
 * cannot carry a session CSRF token, so every module login endpoint that is
 * fetched/JSON-posted to a non-API route must be registered as csrf_exempt.
 *
 * Guards against the 2026-08-06 regression where daily-ledger (and bakeshop,
 * dc-cafe, inventory-scanner, wms) login returned 419 "Invalid CSRF token".
 *
 * @see src/http/core-routes.php kernelRouteMeta()
 * @see public/index.php  Automatic CSRF enforcement
 */

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'applicationos.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/daily-ledger/login';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/http/core-routes.php';

$pass = 0;
$fail = 0;
$errors = [];

function mlt(string $label, bool $ok, string $detail = ''): void
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

$meta = kernelRouteMeta();

// Module login endpoints that are JSON/JS-posted to non-API routes and cannot
// carry a session CSRF token pre-auth. All must be csrf_exempt so the
// auto-enforce safety net does not 419 them.
$loginRoutes = [
    'POST:/daily-ledger/auth/login',
    'POST:/bakeshop/auth/login',
    'POST:/dc-cafe/auth/login',
    'POST:/inventory-scanner/auth/login',
    'POST:/wms/auth/login',
];

foreach ($loginRoutes as $routeKey) {
    $entry = $meta[$routeKey] ?? null;
    $isArray = is_array($entry);
    mlt(
        $routeKey . ' is registered in kernelRouteMeta()',
        $isArray,
        $isArray ? json_encode($entry) : 'missing'
    );
    if (!$isArray) {
        continue;
    }
    mlt(
        $routeKey . ' is csrf_exempt',
        !empty($entry['csrf_exempt']),
        'csrf_exempt=' . (empty($entry['csrf_exempt']) ? 'false' : 'true')
    );
}

// Guard against over-exemption: an authenticated module POST route that is
// NOT a login endpoint must NOT be csrf_exempt — it still relies on the
// module's own token (X-CSRF-Token / _token). Auto-enforce must keep
// covering it as the safety net.
$protectedRoute = 'POST:/daily-ledger/api/v1/cashier/ledger/save';
$protected = $meta[$protectedRoute] ?? null;
mlt(
    $protectedRoute . ' is NOT csrf_exempt (safety net still applies)',
    !is_array($protected) || empty($protected['csrf_exempt']),
    is_array($protected) ? json_encode($protected) : 'not registered'
);

// The kernel auth login stays stateless as before.
$kernelLogin = $meta['POST:/api/v1/auth/login'] ?? null;
mlt(
    'POST:/api/v1/auth/login stays stateless',
    is_array($kernelLogin) && !empty($kernelLogin['stateless']),
    is_array($kernelLogin) ? json_encode($kernelLogin) : 'missing'
);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);
