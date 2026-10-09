<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-routes.php';
require_once __DIR__ . '/../src/http/core-routes.php';

$pass = 0;
$fail = 0;

function routeGuardAssert(string $name, bool $condition, string $detail = ''): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    $condition ? $pass++ : $fail++;
}

function routeGuardRunPhp(string $code, array $arguments = []): array
{
    $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return [$exitCode, implode("\n", $output)];
}

$routes = loadModuleRoutes(kernelCoreRoutes());
$routeCount = array_sum(array_map('count', $routes));
$sameMethodPairs = 0;
$mayConflict = 0;
$distinctCompareZero = 0;

foreach ($routes as $methodRoutes) {
    $patterns = array_keys($methodRoutes);
    $count = count($patterns);
    $sameMethodPairs += intdiv($count * ($count - 1), 2);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            if (!routePatternsMayConflict($patterns[$i], $patterns[$j])) {
                continue;
            }
            $mayConflict++;
            if (compareRoutePatternsForMatching($patterns[$i], $patterns[$j]) === 0) {
                $distinctCompareZero++;
            }
        }
    }
}

$adversarialPatterns = [];
for ($i = 1; $i <= 18; $i++) {
    $prefix = '/route-guard-' . $i;
    $adversarialPatterns[] = $prefix . '/{value}';
    $adversarialPatterns[] = $prefix . '/fixed';
}
$adversarialCompareZero = 0;
for ($i = 0; $i < count($adversarialPatterns); $i++) {
    for ($j = $i + 1; $j < count($adversarialPatterns); $j++) {
        if (
            routePatternsMayConflict($adversarialPatterns[$i], $adversarialPatterns[$j])
            && compareRoutePatternsForMatching($adversarialPatterns[$i], $adversarialPatterns[$j]) === 0
        ) {
            $adversarialCompareZero++;
        }
    }
}

routeGuardAssert('real route corpus contains 1,968 patterns', $routeCount === 1968, "routes={$routeCount}");
routeGuardAssert('all same-method pairs were enumerated', $sameMethodPairs === 927972, "pairs={$sameMethodPairs}");
routeGuardAssert('real corpus contains the measured conflicting pairs', $mayConflict === 109, "may_conflict={$mayConflict}");
routeGuardAssert('no distinct conflicting real patterns compare equal', $distinctCompareZero === 0, "compare_zero={$distinctCompareZero}");
routeGuardAssert('36 adversarial distinct patterns have no equal conflicting pair', $adversarialCompareZero === 0, "compare_zero={$adversarialCompareZero}");

$realMapCode = <<<'PHP'
$_ENV['APP_ROUTE_AMBIGUITY_MODE'] = $argv[1];
putenv('APP_ROUTE_AMBIGUITY_MODE=' . $argv[1]);
require $argv[2] . '/bootstrap.php';
require_once $argv[2] . '/src/helpers/module-manager.php';
require_once $argv[2] . '/src/helpers/module-routes.php';
require_once $argv[2] . '/src/http/core-routes.php';
$routes = loadModuleRoutes(kernelCoreRoutes());
echo json_encode([
    'count' => array_sum(array_map('count', $routes)),
    'hash' => substr(hash('sha256', json_encode($routes)), 0, 16),
]);
PHP;

$realModeMaps = [];
foreach (['warn', 'block'] as $mode) {
    [$exitCode, $output] = routeGuardRunPhp($realMapCode, [$mode, dirname(__DIR__)]);
    $decoded = json_decode($output, true);
    $realModeMaps[$mode] = is_array($decoded) ? $decoded : null;
    routeGuardAssert("{$mode} mode real route map loads", $exitCode === 0 && is_array($decoded), $output);
}
routeGuardAssert(
    'warn and block produce the unchanged real route map',
    $realModeMaps['warn'] === ['count' => 1968, 'hash' => 'cf47a110930f5733']
        && $realModeMaps['block'] === $realModeMaps['warn'],
    json_encode($realModeMaps, JSON_UNESCAPED_SLASHES)
);

$fixtureRoot = sys_get_temp_dir() . '/kernel-route-conflict-guard-' . bin2hex(random_bytes(6));
$firstModule = $fixtureRoot . '/first';
$secondModule = $fixtureRoot . '/second';
mkdir($firstModule, 0777, true);
mkdir($secondModule, 0777, true);
file_put_contents($firstModule . '/routes.php', <<<'PHP'
<?php
return ['GET' => [
    '/guard/shared' => 'firstSharedHandler',
    '/guard/first' => 'firstOnlyHandler',
]];
PHP);
file_put_contents($secondModule . '/routes.php', <<<'PHP'
<?php
return ['GET' => [
    '/guard/shared' => 'secondSharedHandler',
    '/guard/second' => 'secondOnlyHandler',
]];
PHP);

$syntheticCode = <<<'PHP'
$mode = $argv[1];
$firstModule = $argv[2];
$secondModule = $argv[3];
$moduleRoutesFile = $argv[4];
function config(string $key, mixed $default = null): mixed { global $mode; return $key === 'app.modules.route_ambiguity_mode' ? $mode : $default; }
function getEnabledModules(): array { global $firstModule, $secondModule; return [
    ['id' => 'guard-first', '_path' => $firstModule, 'routes' => true],
    ['id' => 'guard-second', '_path' => $secondModule, 'routes' => true],
]; }
function loadModuleHelpers(array $module): void {}
function validateModuleCapabilities(array $module): array { return ['ok' => false]; }
function validateModuleEntityContexts(array $module): array { return ['ok' => false]; }
function kernelCheckReadContractDrift(): void {}
function write_log(string $message, string $level = 'info', array $context = []): void { $GLOBALS['guard_logs'][] = $message; }
require $moduleRoutesFile;
$routes = loadModuleRoutes(['GET' => [], 'POST' => [], 'PUT' => [], 'PATCH' => [], 'DELETE' => []]);
echo json_encode(['routes' => $routes, 'logs' => $GLOBALS['guard_logs'] ?? []]);
PHP;

$syntheticModeMaps = [];
foreach (['warn', 'block'] as $mode) {
    [$exitCode, $output] = routeGuardRunPhp($syntheticCode, [
        $mode,
        $firstModule,
        $secondModule,
        dirname(__DIR__) . '/src/helpers/module-routes.php',
    ]);
    $decoded = json_decode($output, true);
    $syntheticModeMaps[$mode] = is_array($decoded) ? ($decoded['routes'] ?? null) : null;
    routeGuardAssert("{$mode} mode synthetic route map loads", $exitCode === 0 && is_array($decoded), $output);
}

$syntheticRoutes = $syntheticModeMaps['warn'];
$getRoutes = is_array($syntheticRoutes) ? ($syntheticRoutes['GET'] ?? []) : [];
routeGuardAssert(
    'identical same-method pattern rejects the second owner',
    ($getRoutes['/guard/shared'] ?? null) === 'firstSharedHandler'
        && !in_array('secondSharedHandler', $getRoutes, true),
    json_encode($getRoutes, JSON_UNESCAPED_SLASHES)
);
routeGuardAssert(
    'different patterns from both modules are retained',
    ($getRoutes['/guard/first'] ?? null) === 'firstOnlyHandler'
        && ($getRoutes['/guard/second'] ?? null) === 'secondOnlyHandler',
    json_encode($getRoutes, JSON_UNESCAPED_SLASHES)
);
routeGuardAssert(
    'warn and block produce identical synthetic maps',
    is_array($syntheticModeMaps['warn']) && $syntheticModeMaps['warn'] === $syntheticModeMaps['block']
);

@unlink($firstModule . '/routes.php');
@unlink($secondModule . '/routes.php');
@rmdir($firstModule);
@rmdir($secondModule);
@rmdir($fixtureRoot);

echo "PROOF pairs={$sameMethodPairs} may_conflict={$mayConflict} distinct_compare_zero={$distinctCompareZero} adversarial_compare_zero={$adversarialCompareZero}\n";
echo 'Total: ' . ($pass + $fail) . " PASS: {$pass} FAIL: {$fail}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
