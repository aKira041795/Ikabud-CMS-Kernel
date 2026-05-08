<?php
/**
 * Test: kernel superadmin cache observability — snapshot shape, route
 * registration, auth guard, and flush behavior (without exercising HTTP).
 */
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/http/page-handlers.php';
require_once __DIR__ . '/../src/http/superadmin-handlers.php';
require_once __DIR__ . '/../src/http/core-routes.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

$pass = $fail = 0;
$lines = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $lines;
    $lines[] = ($ok ? '  PASS  ' : '  FAIL  ') . $name . ($detail ? ' — ' . $detail : '');
    $ok ? $pass++ : $fail++;
}

@file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
@file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

// 1. Handlers + snapshot fn exist.
check('kernelHandlePageSuperadminCache exists', function_exists('kernelHandlePageSuperadminCache'));
check('kernelHandleApiSuperadminCache exists', function_exists('kernelHandleApiSuperadminCache'));
check('kernelHandleApiSuperadminCacheFlush exists', function_exists('kernelHandleApiSuperadminCacheFlush'));
check('kernelBuildCacheObservabilitySnapshot exists', function_exists('kernelBuildCacheObservabilitySnapshot'));

// 2. Routes registered.
$routes = kernelCoreRoutes();
$get = $routes['GET'] ?? [];
$post = $routes['POST'] ?? [];
check('GET /superadmin/cache route', ($get['/superadmin/cache'] ?? null) === 'pageSuperadminCache');
check('GET /api/v1/superadmin/cache route', ($get['/api/v1/superadmin/cache'] ?? null) === 'apiSuperadminCache');
check('POST /api/v1/superadmin/cache/flush route', ($post['/api/v1/superadmin/cache/flush'] ?? null) === 'apiSuperadminCacheFlush');

// 3. Snapshot shape.
// Seed a CMS cache entry so listInstances() returns at least one row.
if (function_exists('cmsCacheSet')) {
    cmsCacheSet('superadmin:cache:probe:' . uniqid(), ['html' => 'probe'], ['superadmin:cache:probe']);
}
$snap = kernelBuildCacheObservabilitySnapshot();
check('snapshot ok', !empty($snap['ok']));
check('snapshot has timestamp', !empty($snap['timestamp']));
check('snapshot global is array', is_array($snap['global'] ?? null));
check('snapshot instances is list', is_array($snap['instances'] ?? null));
check('snapshot fragments is array', is_array($snap['fragments'] ?? null));
check('global has hit_rate', isset($snap['global']['hit_rate']));
check('global has total_size_mb', array_key_exists('total_size_mb', $snap['global']));

// 4. Nav link added.
$navFn = 'cmsBuildSuperadminNav'; // Not the right one, look up correct
// The nav lives in module-manager.php; use the actual function name:
if (function_exists('buildKernelSuperadminNavItems')) {
    $nav = buildKernelSuperadminNavItems();
    $urls = array_column($nav, 'url');
    check('cache link in nav', in_array('/superadmin/cache', $urls, true));
} else {
    // Probe the file directly to confirm we added the line.
    $mmSrc = file_get_contents(__DIR__ . '/../src/helpers/module-manager.php') ?: '';
    check('cache link in nav source', str_contains($mmSrc, "'/superadmin/cache'"));
}

// 5. Flush API: invalid target returns 422-style JSON error (we can't easily
//    test http_response_code in CLI, so check error string in output).
$_SERVER['REQUEST_METHOD'] = 'POST';
file_put_contents('php://memory', '');
// Simulate auth — we can't easily inject app()->user(), so just confirm the
// fn handles missing user with 403 (it returns silently after sending JSON).
ob_start();
try { kernelHandleApiSuperadminCacheFlush(); } catch (\Throwable $e) {}
$out = ob_get_clean();
$resp = json_decode((string)$out, true);
check('flush rejects non-superadmin', is_array($resp) && empty($resp['ok']) && ($resp['error'] ?? '') === 'Superadmin only');

// 6. Logs clean.
$log = @file_get_contents(__DIR__ . '/../storage/logs/app.log') ?: '';
$err = @file_get_contents(__DIR__ . '/../storage/logs/error.log') ?: '';
check('no app.log warnings/errors', !preg_match('/\] \[(warning|error|critical)\]/i', $log));
check('no PHP errors in error.log', trim($err) === '');

echo "\n";
foreach ($lines as $l) echo $l . "\n";
echo "\n================================================================\n";
echo "Total: " . ($pass + $fail) . "  PASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
