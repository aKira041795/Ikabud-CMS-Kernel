<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

$base = dirname(__DIR__, 3);
$runId = $argv[1] ?? '';
$module = $argv[2] ?? 'unknown';
if ($runId === '') { fwrite(STDERR, "Usage: run.php <run-id> <module>\n"); exit(1); }

require_once $base . '/bootstrap.php';
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/ai/helpers.php';
aiRegisterHeadlessCapabilities();
require_once __DIR__ . '/PatternIntelligence.php';
require_once dirname(__DIR__) . '/AI/WorkbenchAiAnalyzer.php';

use Ikabud\Kernel\Workbench\AI\WorkbenchAiAnalyzer;
use Ikabud\Kernel\Workbench\Intelligence\AiGovernancePolicy;
use Ikabud\Kernel\Workbench\Intelligence\ClaimContract;
use Ikabud\Kernel\Workbench\Intelligence\PatternIntelligenceEngine;

$read = static function (string $file): array {
    if (!is_file($file)) return [];
    $value = json_decode((string)file_get_contents($file), true);
    return is_array($value) ? $value : [];
};

$browserDir = $base . '/test_results/browser/runs/' . $runId;
$analyst = $read($base . '/test_results/analyst/' . $runId . '/system-analyst-report.json');
$issues = $read($browserDir . '/issue-report.json');
$manifest = $read($browserDir . '/manifest.json');
$comprehension = $read($base . '/test_results/ai/runs/' . $runId . '/comprehension-report.json');
$scenarioGuidance = $read($browserDir . '/scenario-guidance.json');

$failedTests = 0;
foreach ((array)($manifest['suites'] ?? []) as $suite) {
    $failedTests += (int)($suite['failed'] ?? 0)
        + (int)($suite['timed_out'] ?? 0)
        + (int)($suite['interrupted'] ?? 0);
}
$issueGate = strtolower((string)(getenv('WB_ISSUE_GATE') ?: getenv('HYBRID_GATE') ?: ($manifest['gate'] ?? 'off')));
$blockingIssues = 0;
foreach ((array)($issues['issues'] ?? []) as $issue) {
    $severity = strtolower((string)($issue['severity'] ?? ''));
    if ($severity === 'critical' || ($issueGate === 'major' && $severity === 'major')) {
        $blockingIssues++;
    }
}
$conformanceVerdict = ($failedTests > 0 || ($issueGate !== 'off' && $blockingIssues > 0))
    ? 'fail'
    : (!empty($manifest['suites']) ? 'pass' : 'unknown');

$settings = function_exists('aiResolvedSettings') ? aiResolvedSettings() : [];
$effective = (new AiGovernancePolicy())->effective([
    'enabled' => (bool)($settings['workbench_ai_enabled'] ?? false),
    'provider' => (string)($settings['workbench_ai_provider'] ?? $settings['provider'] ?? ''),
    'model' => (string)($settings['workbench_ai_model'] ?? ''),
    'authority_level' => (int)($settings['workbench_ai_authority_level'] ?? 2),
    'modules' => (array)($settings['workbench_ai_modules'] ?? ['*']),
    'data_classifications' => (array)($settings['workbench_ai_data_classifications'] ?? ['internal']),
    'source_allowlist' => (array)($settings['workbench_ai_source_allowlist'] ?? []),
    'max_tokens' => (int)($settings['workbench_ai_max_tokens'] ?? 2000),
    'timeout_ms' => (int)($settings['workbench_ai_timeout_ms'] ?? 15000),
], $module, 'internal');

$preliminary = (new PatternIntelligenceEngine())->analyze([
    'analyst' => $analyst,
    'reporter' => $issues,
    'comprehension' => $comprehension,
], [
    'module' => $module,
    'analyst_report' => $analyst,
    'conformance_verdict' => $conformanceVerdict,
]);

$heuristic = [
    'summary' => 'Final evidence requires evidence-grounded latent-quality assessment',
    'confidence' => 0.3,
    'evidence_for' => array_column($preliminary['final_evidence']['issues'] ?? [], 'fingerprint'),
    'suspected_nodes' => [],
];
$knownIds = array_values(array_filter(array_merge(
    array_column($preliminary['final_evidence']['issues'] ?? [], 'fingerprint'),
    array_map(fn($v) => is_array($v) ? (string)($v['id'] ?? '') : '', $preliminary['final_evidence']['successful_checks'] ?? [])
)));
$ai = new WorkbenchAiAnalyzer([
    'enabled' => $effective['allowed'] && $effective['authority_level'] >= 2,
    'provider' => $effective['provider'], 'model' => $effective['model'],
    'timeout_ms' => $effective['budgets']['timeout_ms'], 'max_tokens' => $effective['budgets']['tokens'],
    'max_evidence_bytes' => (int)($settings['workbench_ai_max_evidence_bytes'] ?? 32768),
    'prompt_version' => 'workbench-pattern-intelligence-v1',
    'metrics_path' => $base . '/storage/private/workbench/metrics.json',
], null, $base . '/storage/private/comprehension/ai-cache');
$rawAi = $ai->analyze([
    'task' => 'latent_quality_assessment',
    // Keep the validator's authoritative citation contract ahead of the potentially
    // truncated evidence body so providers always see it within the byte budget.
    'allowed_evidence_ids' => $knownIds,
    'final_evidence' => $preliminary['final_evidence'],
    'grain_signature' => $preliminary['grain_signature'],
    'latent_quality' => $preliminary['latent_quality'],
    'constraints' => ['citations_required' => true, 'no_invented_routes' => true, 'conformance_immutable' => true],
], $heuristic);

$claims = [];
foreach (($rawAi['hypotheses'] ?? []) as $index => $hypothesis) {
    $claims[] = [
        'claim_id' => 'ai-hyp-' . $index,
        'claim_type' => 'inferred',
        'text' => (string)($hypothesis['summary'] ?? ''),
        'evidence_ids' => array_values((array)($hypothesis['evidence_for'] ?? [])),
        'confidence' => (float)($hypothesis['confidence'] ?? 0),
    ];
}
$validation = (new ClaimContract())->validate(['claims' => $claims], $knownIds);
$assessment = [
    'accepted' => $validation['valid'], 'claims' => $claims, 'validation' => $validation,
    'mode' => ($rawAi['provider_trace']['fallback_reason'] ?? null) === null ? 'configured-provider' : 'deterministic-fallback',
    'provider_trace' => $rawAi['provider_trace'] ?? [], 'raw_schema_version' => $rawAi['schema_version'] ?? null,
];

$result = (new PatternIntelligenceEngine())->analyze([
    'analyst' => $analyst, 'reporter' => $issues, 'comprehension' => $comprehension,
], [
    'module' => $module, 'analyst_report' => $analyst,
    'conformance_verdict' => $conformanceVerdict,
    'ai_assessment' => $assessment, 'effective_ai_policy' => $effective,
]);
$result['run_id'] = $runId;
$result['generated_at'] = gmdate(DATE_ATOM);
if ($scenarioGuidance !== []) $result['scenario_guidance'] = $scenarioGuidance;

$file = $browserDir . '/pattern-intelligence.json';
if (!is_dir($browserDir)) mkdir($browserDir, 0770, true);
file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
echo $file . "\n";
