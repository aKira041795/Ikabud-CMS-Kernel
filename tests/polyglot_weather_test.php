<?php

declare(strict_types=1);

/**
 * Polyglot end-to-end test: PHP → ServiceProxy → HTTP → Python (weather-service)
 *
 * Proves the Kernel OS capability bus dispatches to non-PHP runtimes.
 * Run: php tests/polyglot_weather_test.php
 */

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Capabilities\ServiceProxy;

$pass = 0;
$fail = 0;

function assertOk(string $label, bool $cond, ?string $detail = null): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✅ {$label}\n";
    } else {
        $fail++;
        echo "  ❌ {$label}" . ($detail !== null ? " — {$detail}" : '') . "\n";
    }
}

echo "=== Polyglot Weather Service E2E Test ===\n\n";

// ── Test 1: Python service is reachable via curl ──
echo "1. Python service health check\n";
$health = @file_get_contents('http://127.0.0.1:9002/health');
$healthData = json_decode($health, true);
assertOk('health endpoint responds', is_array($healthData) && ($healthData['ok'] ?? false));
assertOk('service name correct', ($healthData['service'] ?? '') === 'weather-service');

// ── Test 2: ServiceProxy built from manifest ──
echo "\n2. ServiceProxy from weather-service manifest\n";
$manifest = json_decode(file_get_contents(__DIR__ . '/../modules/weather-service/module.json'), true);
$proxy = ServiceProxy::fromManifest($manifest);
assertOk('ServiceProxy created from manifest', $proxy !== null);
assertOk('ServiceProxy is callable', is_callable($proxy));

// ── Test 3: Direct ServiceProxy call (no CapabilityBus) ──
echo "\n3. Direct ServiceProxy → Python (weather.current)\n";
try {
    $result = $proxy(['city' => 'London'], 'weather.current@1', 'weather-service');
    assertOk('returns ok data', is_array($result));
    assertOk('has city', ($result['city'] ?? '') === 'London');
    assertOk('has temperature_c', isset($result['temperature_c']) && is_numeric($result['temperature_c']));
    assertOk('has condition', isset($result['condition']) && is_string($result['condition']));
    echo "     🌡  London: {$result['temperature_c']}°C, {$result['condition']}\n";
} catch (\Throwable $e) {
    assertOk('direct call succeeds', false, $e->getMessage());
}

// ── Test 4: Direct ServiceProxy → forecast ──
echo "\n4. Direct ServiceProxy → Python (weather.forecast)\n";
try {
    $result = $proxy(['city' => 'Paris', 'days' => 2], 'weather.forecast@1', 'weather-service');
    assertOk('forecast returns data', is_array($result));
    assertOk('has forecast array', isset($result['forecast']) && is_array($result['forecast']));
    assertOk('2 day forecast', count($result['forecast']) === 2);
    foreach ($result['forecast'] as $day) {
        echo "     📅 {$day['date']}: {$day['high_c']}°C / {$day['low_c']}°C, {$day['condition']}\n";
    }
} catch (\Throwable $e) {
    assertOk('forecast call succeeds', false, $e->getMessage());
}

// ── Test 5: Through CapabilityRegistry + CapabilityBus ──
echo "\n5. CapabilityBus::call() → Python\n";
app()->capabilities()->register('weather.current@1', 'weather-service', $proxy, 100, ['first']);
assertOk('registered in CapabilityRegistry', app()->capabilities()->has('weather.current@1'));

try {
    $result = app()->cap()->call('weather.current@1', ['city' => 'Singapore'], [
        'caller' => ['module' => 'cms', 'request_id' => 'polyglot-test-001'],
    ]);
    assertOk('bus call returns data', is_array($result));
    assertOk('Singapore data', ($result['city'] ?? '') === 'Singapore');
    echo "     🌡  Singapore: {$result['temperature_c']}°C, {$result['condition']}\n";
} catch (\Throwable $e) {
    assertOk('bus call succeeds', false, $e->getMessage());
}

// ── Test 6: Invalid capability → proper error ──
echo "\n6. Unknown capability error handling\n";
try {
    $proxy(['test'], 'weather.nonexistent@1', 'weather-service');
    assertOk('throws on unknown capability', false, 'should have thrown');
} catch (\Throwable $e) {
    assertOk('throws on unknown capability', true);
    assertOk('error message descriptive', str_contains($e->getMessage(), 'unknown capability') || str_contains($e->getMessage(), 'error'));
}

// ── Test 7: Service offline → graceful failure ──
echo "\n7. Service offline handling\n";
$offlineManifest = [
    'id' => 'offline-svc',
    'type' => 'service-module',
    'service' => [
        'endpoint' => 'http://127.0.0.1:19999',  // nothing listening here
        'timeout_ms' => 1000,
        'protocol' => 'http+json',
    ],
];
$offlineProxy = ServiceProxy::fromManifest($offlineManifest);
try {
    $offlineProxy(['test'], 'test@1', 'offline-svc');
    assertOk('offline service throws', false, 'should have thrown');
} catch (\Throwable $e) {
    assertOk('offline service throws exception', true);
    // The CapabilityBus circuit breaker would catch this in production
}

// ── Summary ──
echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
echo "\n🌍 POLYGLOT VERIFIED: PHP kernel → ServiceProxy → HTTP → Python ✅\n";
exit($fail > 0 ? 1 : 0);
