<?php

declare(strict_types=1);

/**
 * ServiceProxy integration test — validates polyglot capability dispatch.
 *
 * Run: php tests/service_proxy_test.php
 */

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Capabilities\ServiceProxy;
use Ikabud\Kernel\Capabilities\CapabilityCallException;

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

echo "=== ServiceProxy Tests ===\n\n";

// ── Test 1: fromManifest with valid config ──
echo "1. fromManifest with valid service config\n";
$manifest = [
    'id' => 'ai-orchestrator',
    'type' => 'service-module',
    'service' => [
        'endpoint' => 'http://127.0.0.1:9001',
        'protocol' => 'http+json',
        'timeout_ms' => 5000,
        'auth' => ['type' => 'signed_token', 'token_env' => 'TEST_SERVICE_TOKEN'],
    ],
];
$_ENV['TEST_SERVICE_TOKEN'] = 'tok_test_abc123';

$proxy = ServiceProxy::fromManifest($manifest);
assertOk('fromManifest returns ServiceProxy', $proxy !== null);
assertOk('ServiceProxy is callable', is_callable($proxy));

// ── Test 2: fromManifest with no service config ──
echo "\n2. fromManifest with missing service config\n";
$bareManifest = ['id' => 'no-service', 'type' => 'php-module'];
$proxy2 = ServiceProxy::fromManifest($bareManifest);
assertOk('returns null for non-service module', $proxy2 === null);

$emptyService = ['id' => 'bad', 'type' => 'service-module', 'service' => []];
$proxy3 = ServiceProxy::fromManifest($emptyService);
assertOk('returns null when endpoint missing', $proxy3 === null);

// ── Test 3: HTTP dispatch (via test handler) ──
echo "\n3. HTTP dispatch with test handler\n";
$proxy4 = ServiceProxy::fromManifest($manifest);
assertOk('proxy created', $proxy4 !== null);

$capturedUrl = null;
$capturedBody = null;
$capturedHeaders = null;

$proxy4->setHttpHandler(function (string $url, array $opts) use (&$capturedUrl, &$capturedBody, &$capturedHeaders): array {
    $capturedUrl = $url;
    $capturedBody = $opts['body'] ?? '';
    $capturedHeaders = $opts['headers'] ?? [];
    return [
        'status' => 200,
        'body' => json_encode(['ok' => true, 'data' => ['summary' => 'AI generated summary']]),
    ];
});

$result = $proxy4(['text' => 'Hello world'], 'ai.summarize@1', 'ai-orchestrator');
assertOk('returns data payload', ($result['summary'] ?? '') === 'AI generated summary');
assertOk('POSTs to /capability/call', str_ends_with($capturedUrl ?? '', '/capability/call'));
assertOk('sends JSON body', str_contains($capturedBody ?? '', '"capability_id":"ai.summarize@1"'));
assertOk('includes Authorization header', in_array('Authorization: Bearer tok_test_abc123', $capturedHeaders ?? [], true));

// ── Test 4: HTTP error response ──
echo "\n4. HTTP error handling\n";
$proxy5 = ServiceProxy::fromManifest($manifest);
$proxy5->setHttpHandler(fn() => ['status' => 500, 'body' => 'Internal Server Error']);
try {
    $proxy5(['test'], 'ai.summarize@1', 'ai-orchestrator');
    assertOk('throws on 500', false, 'should have thrown');
} catch (CapabilityCallException $e) {
    assertOk('throws CapabilityCallException on 500', true);
    assertOk('error message contains status', str_contains($e->getMessage(), '500'));
}

// ── Test 5: Service error response (ok=false) ──
echo "\n5. Service-level error (ok=false)\n";
$proxy6 = ServiceProxy::fromManifest($manifest);
$proxy6->setHttpHandler(fn() => ['status' => 200, 'body' => json_encode(['ok' => false, 'error' => 'Model overloaded'])]);
try {
    $proxy6(['test'], 'ai.summarize@1', 'ai-orchestrator');
    assertOk('throws on ok=false', false, 'should have thrown');
} catch (CapabilityCallException $e) {
    assertOk('throws CapabilityCallException on service error', true);
    assertOk('error message contains service error', str_contains($e->getMessage(), 'Model overloaded'));
}

// ── Test 6: Invalid JSON response ──
echo "\n6. Invalid JSON response\n";
$proxy7 = ServiceProxy::fromManifest($manifest);
$proxy7->setHttpHandler(fn() => ['status' => 200, 'body' => 'not json']);
try {
    $proxy7(['test'], 'ai.summarize@1', 'ai-orchestrator');
    assertOk('throws on invalid JSON', false, 'should have thrown');
} catch (CapabilityCallException $e) {
    assertOk('throws on non-JSON response', true);
}

// ── Test 7: CapabilityRegistry integration ──
echo "\n7. CapabilityRegistry accepts ServiceProxy\n";
$proxy8 = ServiceProxy::fromManifest($manifest);
$proxy8->setHttpHandler(fn() => ['status' => 200, 'body' => json_encode(['ok' => true, 'data' => 'registered'])]);
app()->capabilities()->register('ai.summarize@1', 'ai-orchestrator', $proxy8, 100, ['first']);
assertOk('register() accepts ServiceProxy', app()->capabilities()->has('ai.summarize@1'));

$providers = app()->capabilities()->providers('ai.summarize@1');
assertOk('provider is registered', count($providers) > 0);

// ── Test 8: CapabilityBus::call() with ServiceProxy ──
echo "\n8. CapabilityBus::call() through ServiceProxy\n";
$result8 = app()->cap()->call('ai.summarize@1', ['text' => 'Test'], ['caller' => ['module' => 'cms']]);
assertOk('bus call returns data', $result8 === 'registered');

// ── Test 9: fromManifest with retry config ──
echo "\n9. Service config with retry/breaker\n";
$retryManifest = [
    'id' => 'resilient-svc',
    'type' => 'service-module',
    'service' => [
        'endpoint' => 'http://127.0.0.1:9002',
        'timeout_ms' => 10000,
        'retry' => ['max_attempts' => 3, 'backoff_ms' => 1000, 'backoff_multiplier' => 2],
        'circuit_breaker' => ['failure_threshold' => 5, 'cooldown_seconds' => 60],
    ],
];
$proxy9 = ServiceProxy::fromManifest($retryManifest);
assertOk('handles retry+breaker config', $proxy9 !== null);

// ── Test 10: No endpoint → throws ──
echo "\n10. Missing endpoint throws\n";
$noEndpointProxy = new ServiceProxy(['endpoint' => '', 'timeout_ms' => 1000]);
try {
    $noEndpointProxy(['test'], 'cap@1', 'mod');
    assertOk('throws on empty endpoint', false, 'should have thrown');
} catch (CapabilityCallException $e) {
    assertOk('throws on empty endpoint', true);
}

// ── Test 11: Null data response (ok=true, no data key) ──
echo "\n11. Response with ok=true and no data key\n";
$proxy11 = ServiceProxy::fromManifest($manifest);
$proxy11->setHttpHandler(fn() => ['status' => 200, 'body' => json_encode(['ok' => true])]);
$result11 = $proxy11(['test'], 'cap@1', 'mod');
assertOk('null data returns null', $result11 === null);

// ── Summary ──
echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
