<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/helpers/manifest-validation.php';
require_once dirname(__DIR__) . '/kernel/EventTriggers.php';

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
};

$base = [
    'id' => 'schema-fixture',
    'name' => 'Schema Fixture',
    'version' => '1.0.0',
    'owns_tables' => [],
    'reads_tables' => [],
    'routes' => true,
    'capabilities' => ['exposes' => [], 'depends' => []],
    'events' => [['key' => 'schema-fixture.changed']],
];

$tempDir = sys_get_temp_dir() . '/ikabud-manifest-v1-' . bin2hex(random_bytes(6));
mkdir($tempDir, 0777, true);
file_put_contents($tempDir . '/routes.php', "<?php\nreturn ['GET' => [], 'POST' => []];\n");

try {
    $valid = validateModuleManifestV1($base, ['module_path' => $tempDir]);
    $assert($valid['ok'] === true, 'valid schema-v1 fixture passes');
    $assert($valid['schema_version'] === '1', 'result declares schema version 1');

    $badRoutes = $base;
    $badRoutes['routes'] = ['GET' => ['/bad' => 'handler']];
    $routeResult = validateModuleManifestV1($badRoutes, ['module_path' => $tempDir]);
    $routeDiagnostic = $routeResult['diagnostics'][0] ?? [];
    $assert($routeResult['ok'] === false, 'inline route map is rejected');
    $assert(($routeDiagnostic['severity'] ?? '') === 'fatal', 'bad route shape is fatal');
    $assert(($routeDiagnostic['rule'] ?? '') === 'manifest.v1.routes', 'bad route cites schema rule');
    $assert(str_contains((string)($routeDiagnostic['correction'] ?? ''), 'routes.php'), 'bad route explains correction');

    $escapingRoutes = $base;
    $escapingRoutes['routes'] = '../routes.php';
    $escapingRouteResult = validateModuleManifestV1($escapingRoutes, ['module_path' => $tempDir]);
    $escapingRouteDiagnostic = $escapingRouteResult['diagnostics'][0] ?? [];
    $assert($escapingRouteResult['ok'] === false, 'route file cannot escape the module directory');
    $assert(($escapingRouteDiagnostic['rule'] ?? '') === 'manifest.v1.routes.relative-path', 'unsafe route path cites relative-path rule');

    $badEvents = $base;
    $badEvents['events'] = ['schema-fixture.changed'];
    $eventResult = validateModuleManifestV1($badEvents, ['module_path' => $tempDir]);
    $eventDiagnostic = $eventResult['diagnostics'][0] ?? [];
    $assert($eventResult['ok'] === false, 'string event declaration is rejected');
    $assert(($eventDiagnostic['severity'] ?? '') === 'fatal', 'bad event declaration is fatal');
    $assert(($eventDiagnostic['rule'] ?? '') === 'manifest.v1.events-entry', 'bad event cites schema rule');
    $assert(str_contains((string)($eventDiagnostic['message'] ?? ''), 'non-empty key'), 'bad event message names required key');

    $syncFailed = false;
    try {
        kernelRegisterModuleEvents('bad-event-module', [['id' => 'wrong-key']]);
    } catch (RuntimeException $e) {
        $syncFailed = str_contains($e->getMessage(), '[fatal]')
            && str_contains($e->getMessage(), 'bad-event-module')
            && str_contains($e->getMessage(), '/events/0')
            && str_contains($e->getMessage(), 'Correction:');
    }
    $assert($syncFailed, 'malformed event sync fails fatally with module, field, and correction');

    $missing = $base;
    unset($missing['id']);
    $missingResult = validateModuleManifestV1($missing, ['module_path' => $tempDir]);
    $missingDiagnostic = $missingResult['diagnostics'][0] ?? [];
    $assert($missingResult['ok'] === false, 'missing required key is rejected');
    $assert(($missingDiagnostic['severity'] ?? '') === 'fatal', 'missing required key is fatal');
    $assert(($missingDiagnostic['field'] ?? '') === '/id', 'missing required diagnostic identifies field');
} finally {
    @unlink($tempDir . '/routes.php');
    @rmdir($tempDir);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
