<?php

declare(strict_types=1);

/**
 * Priority 3 — AI Calibration Tests
 *
 * Validates the AI calibration benchmark: deterministic recall, AI metrics,
 * citation validity, target gates, reproducibility, and small-sample warnings.
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/Comprehension/Analyzers/PatternClassifier.php';
require_once __DIR__ . '/../kernel/Workbench/Benchmark/AiCalibrationBenchmark.php';

use Ikabud\Kernel\Workbench\Comprehension\Analyzers\PatternClassifier;
use Ikabud\Kernel\Workbench\Benchmark\AiCalibrationBenchmark;

$h = new TestHarness('workbench-ai-calibration');

// Load the golden corpus
$corpusFile = __DIR__ . '/ai/golden/competitive-benchmark-cases.v1.json';
$corpus = json_decode((string) file_get_contents($corpusFile), true);

$h->section('Calibration corpus');
$h->test('corpus file exists', is_file($corpusFile));
$h->test('corpus is valid JSON', is_array($corpus));
$h->test('corpus has cases', count($corpus['cases'] ?? []) >= 40);
$h->test('corpus has version', isset($corpus['version']));

// ── 1. Deterministic calibration (no AI) ──────────────────────────
$h->section('Deterministic calibration');
$calibrator = new AiCalibrationBenchmark(new PatternClassifier());
$report = $calibrator->calibrate($corpus);

$h->test('calibration report has schema',
    ($report['schema'] ?? '') === 'ark.ai-calibration-report.v1');
$h->test('calibration report has sample_size',
    ($report['sample_size'] ?? 0) >= 40);
$h->test('generated_at timestamp present',
    isset($report['generated_at']));
$h->test('corpus_version is preserved',
    isset($report['corpus_version']));

// Deterministic metrics
$det = $report['metrics']['deterministic'];
$h->test('deterministic metrics present',
    isset($det['cases']));
$h->test('deterministic critical_recall >= 0',
    ($det['critical_recall'] ?? -1) >= 0);
$h->test('deterministic top_three_root_cause >= 0',
    ($det['top_three_root_cause'] ?? -1) >= 0);
$h->test('deterministic false_positive_rate >= 0',
    ($det['false_positive_rate'] ?? -1) >= 0);

// ── 2. Gates evaluation ──────────────────────────────────────────
$h->section('Target gates');
$gates = $report['gates'];
$h->test('critical_deterministic_recall gate present',
    isset($gates['critical_deterministic_recall']));
$h->test('top_three_root_cause gate present',
    isset($gates['top_three_root_cause']));
$h->test('false_positive_rate gate present',
    isset($gates['false_positive_rate']));
$h->test('reproducible_deterministic_plan gate present',
    isset($gates['reproducible_deterministic_plan']));
$h->test('gates have passed status',
    isset($gates['passed']));

// ── 3. Reproducibility ──────────────────────────────────────────
$h->section('Reproducibility');
$h->test('deterministic plan is reproducible',
    ($report['reproducibility']['deterministic_plan_reproducible'] ?? false) === true);
$h->test('reproducibility has digests',
    isset($report['reproducibility']['first_digest'])
    && isset($report['reproducibility']['second_digest']));

// Run second calibration to verify cross-run reproducibility
$report2 = $calibrator->calibrate($corpus);
$h->test('identical corpus produces identical digest',
    $report2['reproducibility']['first_digest'] === $report['reproducibility']['first_digest']);
$h->test('identical corpus produces identical metrics',
    $report2['metrics']['deterministic']['critical_recall'] === $report['metrics']['deterministic']['critical_recall']
    && $report2['metrics']['deterministic']['top_three_root_cause'] === $report['metrics']['deterministic']['top_three_root_cause']
    && $report2['metrics']['deterministic']['false_positive_rate'] === $report['metrics']['deterministic']['false_positive_rate']);

// ── 4. AI-optional metrics ────────────────────────────────────────
$h->section('AI-optional metrics');
$h->test('AI metrics present (disabled by default)',
    isset($report['metrics']['ai']));
$h->test('AI metrics show disabled when no caller',
    ($report['metrics']['ai']['enabled'] ?? true) === false);
$h->test('AI note present when disabled',
    isset($report['metrics']['ai']['note']));

// ── 5. AI metrics with mock caller ────────────────────────────────
$h->section('AI metrics with mock provider');
$aiCaller = function (array $case): array {
    $id = (string) ($case['id'] ?? '');
    return [
        'schema_version' => '1.0',
        'hypotheses' => [[
            'summary' => 'Detected: ' . ($case['expected_category'] ?? 'unknown'),
            'confidence' => 0.85,
            'evidence_for' => ['obs-' . $id],
            'evidence_against' => [],
            'suspected_nodes' => ['route:' . ($case['step_id'] ?? 'unknown')],
        ]],
        'next_tests' => [['id' => 'verify-fix']],
        'graph_suggestions' => [],
        'remediation' => 'Check ' . ($case['expected_category'] ?? '') . ' handling',
        'provider_trace' => [
            'provider' => 'test',
            'model' => 'fixture-v1',
            'prompt_version' => 'workbench-diagnosis-v1',
            'latency_ms' => 150.0,
            'fallback_reason' => null,
        ],
    ];
};
$aiReport = $calibrator->calibrate($corpus, $aiCaller);

$h->test('AI metrics enabled when caller provided',
    ($aiReport['metrics']['ai']['enabled'] ?? false) === true);
$h->test('AI acceptance_rate > 0',
    ($aiReport['metrics']['ai']['acceptance_rate'] ?? 0) > 0);
$h->test('AI citation_validity_rate > 0',
    ($aiReport['metrics']['ai']['citation_validity_rate'] ?? 0) > 0);
$h->test('AI average_latency_ms >= 0',
    ($aiReport['metrics']['ai']['average_latency_ms'] ?? -1) >= 0);
$h->test('AI delta metrics present',
    isset($aiReport['metrics']['delta']['top3_improvement']));

// ── 6. AI with invalid citations (empty evidence_for) ─────────────
$h->section('AI citation validity rejection');
$badAiCaller = function (array $case): array {
    return [
        'schema_version' => '1.0',
        'hypotheses' => [[
            'summary' => 'Unsupported claim',
            'confidence' => 0.9,
            'evidence_for' => [],   // Empty — no evidence citation
            'evidence_against' => [],
            'suspected_nodes' => ['route:x'],
        ]],
        'next_tests' => [],
        'graph_suggestions' => [],
        'remediation' => null,
        'provider_trace' => [
            'provider' => 'test',
            'model' => 'fixture-v1',
            'latency_ms' => 50.0,
            'fallback_reason' => null,
        ],
    ];
};
$badReport = $calibrator->calibrate($corpus, $badAiCaller);
$h->test('AI with empty citations has validity rate < 1.0',
    ($badReport['metrics']['ai']['citation_validity_rate'] ?? 1.0) < 1.0);

// ── 7. Provider trace ────────────────────────────────────────────
$h->section('Provider trace');
$h->test('provider trace present in AI report',
    isset($aiReport['provider_trace']));
$h->test('provider trace shows ai_enabled',
    ($aiReport['provider_trace']['ai_enabled'] ?? false) === true);
$h->test('provider trace has total_cases',
    ($aiReport['provider_trace']['total_cases'] ?? 0) > 0);
$h->test('provider trace has cost_proxy',
    isset($aiReport['provider_trace']['cost_proxy']));

// ── 8. Target gates reference ────────────────────────────────────
$h->section('Target gates reference');
$h->test('target_gates are documented in report',
    isset($report['target_gates']));
$h->test('critical_deterministic_recall target is 1.0',
    ($report['target_gates']['critical_deterministic_recall']['target'] ?? 0) === 1.0);
$h->test('ai_citation_validity target is 1.0',
    ($report['target_gates']['ai_citation_validity']['target'] ?? 0) === 1.0);
$h->test('top_three_root_cause target is 0.85',
    ($report['target_gates']['top_three_root_cause']['target'] ?? 0) === 0.85);

// ── 9. Schema validation ─────────────────────────────────────────
$h->section('Calibration schema');
$schemaFile = __DIR__ . '/../kernel/Workbench/Schemas/ai-calibration-report.v1.schema.json';
$h->test('calibration schema file exists',
    is_file($schemaFile));
$schema = json_decode((string) file_get_contents($schemaFile), true);
$h->test('calibration schema is valid JSON',
    is_array($schema));
$h->test('schema requires metrics',
    in_array('metrics', $schema['required'] ?? [], true));
$h->test('schema requires gates',
    in_array('gates', $schema['required'] ?? [], true));

$h->done();
