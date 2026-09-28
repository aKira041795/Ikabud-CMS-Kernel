<?php

/** Regression tests for C3b capability checks across PHP and polyglot modules. */

declare(strict_types=1);

$root = dirname(__DIR__);
$errorLogPath = $root . '/storage/logs/error.log';
$errorLogBefore = is_file($errorLogPath) ? (string) file_get_contents($errorLogPath) : null;

require_once $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';

$passed = 0;
$failed = 0;

function c3b_service_test(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
}

/** @param array<string, mixed> $manifest
 *  @return array<string, mixed>|null
 */
function c3b_service_check(array $manifest): ?array
{
    foreach (validateModuleCertification($manifest)['checks'] as $check) {
        if (($check['check'] ?? null) === 'C3b: Capability handlers') {
            return $check;
        }
    }
    return null;
}

function c3b_service_convention_fixture_cap_fixture_work_1(): array
{
    return ['ok' => true];
}

$serviceBase = [
    'name' => 'C3b Service Fixture',
    'version' => '1.0.0',
    'type' => 'service-module',
];

// No exposes remains an unconditional no-work case.
foreach ([
    'omitted exposes' => $serviceBase + ['id' => 'c3b-service-omitted-exposes-fixture'],
    'empty exposes' => $serviceBase + [
        'id' => 'c3b-service-empty-exposes-fixture',
        'capabilities' => ['exposes' => []],
    ],
] as $label => $manifest) {
    $check = c3b_service_check($manifest);
    c3b_service_test(
        "MUST-ALLOW: service-module with {$label} needs no handlers",
        ($check['passed'] ?? false) === true && ($check['detail'] ?? null) === 'No handlers required',
        json_encode($check, JSON_UNESCAPED_SLASHES)
    );
}

$exposes = ['capabilities' => ['exposes' => [['id' => 'fixture.remote@1']]]];

// The two fail-closed cases prove this is not a blanket service-module skip.
$missingEndpoint = $serviceBase + ['id' => 'c3b-service-missing-endpoint-fixture'] + $exposes + [
    'service' => ['protocol' => 'http+json'],
];
$check = c3b_service_check($missingEndpoint);
c3b_service_test(
    'MUST-REFUSE: service-module expose without service.endpoint fails and names the expose',
    ($check['passed'] ?? true) === false
        && str_contains((string)($check['detail'] ?? ''), 'fixture.remote@1')
        && str_contains((string)($check['detail'] ?? ''), 'service.endpoint + service.protocol'),
    json_encode($check, JSON_UNESCAPED_SLASHES)
);

$missingProtocol = $serviceBase + ['id' => 'c3b-service-missing-protocol-fixture'] + $exposes + [
    'service' => ['endpoint' => 'http://127.0.0.1:9998'],
];
$check = c3b_service_check($missingProtocol);
c3b_service_test(
    'MUST-REFUSE: service-module expose without service.protocol fails and names the expose',
    ($check['passed'] ?? true) === false
        && str_contains((string)($check['detail'] ?? ''), 'fixture.remote@1')
        && str_contains((string)($check['detail'] ?? ''), 'service.endpoint + service.protocol'),
    json_encode($check, JSON_UNESCAPED_SLASHES)
);

$validService = $serviceBase + ['id' => 'c3b-service-valid-binding-fixture'] + $exposes + [
    'service' => ['endpoint' => 'http://127.0.0.1:9999', 'protocol' => 'http+json'],
];
$check = c3b_service_check($validService);
c3b_service_test(
    'MUST-ALLOW: service-module expose with endpoint and protocol uses its service binding',
    ($check['passed'] ?? false) === true
        && ($check['detail'] ?? null) === 'Served via service endpoint http://127.0.0.1:9999',
    json_encode($check, JSON_UNESCAPED_SLASHES)
);

// Preserve both non-service PHP resolution outcomes from the earlier change.
$conventionExpose = [
    'id' => 'c3b-service-convention-fixture',
    'name' => 'C3b PHP Fixture',
    'version' => '1.0.0',
    'type' => 'module',
    'capabilities' => ['exposes' => [['id' => 'fixture.work@1']]],
];
$check = c3b_service_check($conventionExpose);
c3b_service_test(
    'MUST-ALLOW: non-service module convention handler still resolves',
    ($check['passed'] ?? false) === true
        && ($check['detail'] ?? null) === 'All declared capability handlers resolve',
    json_encode($check, JSON_UNESCAPED_SLASHES)
);

$missingExpose = $conventionExpose;
$missingExpose['id'] = 'c3b-php-missing-fixture';
$missingExpose['capabilities']['exposes'] = [['id' => 'fixture.missing@1']];
$check = c3b_service_check($missingExpose);
c3b_service_test(
    'MUST-REFUSE: non-service module unresolved expose still fails',
    ($check['passed'] ?? true) === false
        && str_contains((string)($check['detail'] ?? ''), 'fixture.missing@1'),
    json_encode($check, JSON_UNESCAPED_SLASHES)
);

$realEndpoints = [
    'ai-orchestrator' => 'http://127.0.0.1:9001',
    'weather-service' => 'http://127.0.0.1:9002',
    'academic-similarity-semantic-service' => 'http://127.0.0.1:9003',
];
foreach ($realEndpoints as $moduleId => $endpoint) {
    $manifest = loadModuleManifestForId($moduleId);
    $check = is_array($manifest) ? c3b_service_check($manifest) : null;
    c3b_service_test(
        "MUST-ALLOW: {$moduleId} resolves through its polyglot service binding",
        ($check['passed'] ?? false) === true
            && ($check['detail'] ?? null) === "Served via service endpoint {$endpoint}",
        json_encode($check, JSON_UNESCAPED_SLASHES)
    );
}

$errorLogAfter = is_file($errorLogPath) ? (string) file_get_contents($errorLogPath) : null;
c3b_service_test(
    'storage/logs/error.log gains no lines',
    $errorLogAfter === $errorLogBefore,
    'before_bytes=' . strlen((string)$errorLogBefore) . ' after_bytes=' . strlen((string)$errorLogAfter)
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
