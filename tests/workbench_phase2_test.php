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
$systemPrompt = $captured['messages'][0]['content'] ?? '';
$h->test('provider prompt declares the machine-validated diagnosis schema',
    str_contains($systemPrompt, '"hypotheses"')
    && str_contains($systemPrompt, '"next_tests"')
    && str_contains($systemPrompt, '"graph_suggestions"')
    && str_contains($systemPrompt, '"remediation"'));
$h->test('provider prompt restricts citations to the validator allowlist',
    str_contains($systemPrompt, 'allowed_evidence_ids'));
$analyzerSource = (string) file_get_contents(__DIR__ . '/../kernel/Workbench/AI/WorkbenchAiAnalyzer.php');
$timeoutForwarded = str_contains($analyzerSource, "'timeout_ms' => max(1000");
$h->test('provider capability call inherits the governed Workbench timeout',
$timeoutForwarded);
$promptBoundCache = str_contains($analyzerSource, 'json_encode($messages,')
    && str_contains($analyzerSource, '$cacheMaterial ?: $encoded');
$h->test('AI cache identity includes the complete governed prompt',
    $promptBoundCache);
$cached = $analyzer->analyze(['observations' => [['observation_id' => 'obs-1', 'detail' => 'Bearer abc123', 'csrf_token' => 'secret']]], ['summary' => 'fallback']);
$h->test('identical requests use cache', ($cached['cache_hit'] ?? false) === true);

$h->section('Safe fallback');
$invalid = new WorkbenchAiAnalyzer(['enabled' => true], fn(array $p): array => ['ok' => true, 'content' => '{}']);
$fallback = $invalid->analyze([], ['summary' => 'deterministic finding', 'confidence' => 0.7]);
$h->test('invalid AI schema falls back', ($fallback['provider_trace']['fallback_reason'] ?? '') === 'schema_validation_failed');
$disabled = (new WorkbenchAiAnalyzer(['enabled' => false]))->analyze([], ['summary' => 'rules']);
$h->test('disabled AI is explicit', ($disabled['provider_trace']['fallback_reason'] ?? '') === 'disabled');

$intelligenceRunner = (string) file_get_contents(__DIR__ . '/../kernel/Workbench/Intelligence/run.php');
$comprehensionRunner = (string) file_get_contents(__DIR__ . '/../kernel/Workbench/Comprehension/run.php');
$h->test('CLI intelligence runner loads persisted AI provider settings',
    str_contains($intelligenceRunner, "/src/helpers/module-manager.php")
    && str_contains($intelligenceRunner, "/modules/ai/helpers.php")
    && strpos($intelligenceRunner, "/src/helpers/module-manager.php") < strpos($intelligenceRunner, "/modules/ai/helpers.php"));
$h->test('pattern intelligence shares its evidence allowlist with the AI provider',
    str_contains($intelligenceRunner, "'allowed_evidence_ids' => \$knownIds")
    && strpos($intelligenceRunner, '$knownIds =') < strpos($intelligenceRunner, '$rawAi ='));
$h->test('citation allowlist precedes truncatable evidence in the provider packet',
    strpos($intelligenceRunner, "'allowed_evidence_ids' => \$knownIds")
        < strpos($intelligenceRunner, "'final_evidence' => \$preliminary['final_evidence']"));
$h->test('CLI comprehension runner loads persisted AI provider settings',
    str_contains($comprehensionRunner, "/modules/ai/helpers.php"));
$h->test('CLI AI runners register provider capabilities for headless execution',
    str_contains($intelligenceRunner, 'aiRegisterHeadlessCapabilities()')
    && str_contains($comprehensionRunner, 'aiRegisterHeadlessCapabilities()'));
$groqSource = (string) file_get_contents(__DIR__ . '/../modules/ai/helpers/groq.php');
$h->test('Groq provider trace retains its resolved model identity',
    str_contains($groqSource, "'provider' => 'groq'")
    && str_contains($groqSource, "'model' => \$model"));

$h->done();
