<?php

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/AI/WorkbenchAiAnalyzer.php';

use Ikabud\Kernel\Workbench\AI\WorkbenchAiAnalyzer;

$h = new TestHarness('workbench-phase2');
$cache = sys_get_temp_dir() . '/wb-ai-test-' . bin2hex(random_bytes(6));
$captured = [];
$caller = function (array $payload) use (&$captured): array {
    $captured = $payload;
    return ['ok' => true, 'provider' => 'test', 'model' => 'fixture', 'content' => json_encode([
        'hypotheses' => [['summary' => 'CSRF mismatch', 'confidence' => 0.91, 'evidence_for' => ['obs-1'], 'evidence_against' => [], 'suspected_nodes' => ['route:x']]],
        'next_tests' => [['id' => 'refresh-token']], 'graph_suggestions' => [], 'remediation' => null,
    ])];
};
$analyzer = new WorkbenchAiAnalyzer(['enabled' => true, 'tier' => 'free', 'provider' => 'test', 'model' => 'fixture'], $caller, $cache);
$result = $analyzer->analyze(['observations' => [['observation_id' => 'obs-1', 'detail' => 'Bearer abc123', 'csrf_token' => 'secret']]], ['summary' => 'fallback']);
$h->section('Configured provider adapter');
$h->test('schema-valid provider result accepted', ($result['hypotheses'][0]['summary'] ?? '') === 'CSRF mismatch');
$h->test('provider trace is recorded', ($result['provider_trace']['provider'] ?? '') === 'test' && ($result['provider_trace']['model'] ?? '') === 'fixture');
$prompt = $captured['messages'][1]['content'] ?? '';
$h->test('secrets and bearer values are redacted', !str_contains($prompt, 'abc123') && !str_contains($prompt, 'secret'));
$cached = $analyzer->analyze(['observations' => [['observation_id' => 'obs-1', 'detail' => 'Bearer abc123', 'csrf_token' => 'secret']]], ['summary' => 'fallback']);
$h->test('identical requests use cache', ($cached['cache_hit'] ?? false) === true);

$h->section('Safe fallback');
$invalid = new WorkbenchAiAnalyzer(['enabled' => true], fn(array $p): array => ['ok' => true, 'content' => '{}']);
$fallback = $invalid->analyze([], ['summary' => 'deterministic finding', 'confidence' => 0.7]);
$h->test('invalid AI schema falls back', ($fallback['provider_trace']['fallback_reason'] ?? '') === 'schema_validation_failed');
$disabled = (new WorkbenchAiAnalyzer(['enabled' => false]))->analyze([], ['summary' => 'rules']);
$h->test('disabled AI is explicit', ($disabled['provider_trace']['fallback_reason'] ?? '') === 'disabled');

$h->done();
