<?php
declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Benchmark;

use Ikabud\Kernel\Workbench\Comprehension\Analyzers\PatternClassifier;
use RuntimeException;

final class CompetitiveBenchmark
{
    private const IDENTITY_FIELDS = ['module_id', 'action_id', 'step_id', 'tenant_id', 'role', 'environment', 'outcome'];

    public function __construct(private readonly PatternClassifier $classifier) {}

    public function run(array $corpus): array
    {
        $cases = $corpus['cases'] ?? null;
        if (!is_array($cases) || $cases === []) {
            throw new RuntimeException('Competitive benchmark corpus has no cases');
        }

        $started = microtime(true);
        $arkCases = $this->evaluateArk($cases);
        $repeatCases = $this->evaluateArk($cases);
        $plainCases = $this->evaluatePlain($cases);
        $arkMetrics = $this->metrics($arkCases);
        $arkMetrics['elapsed_ms'] = round((microtime(true) - $started) * 1000, 3);
        $plainMetrics = $this->metrics($plainCases);

        $firstDigest = $this->digest($arkCases);
        $repeatDigest = $this->digest($repeatCases);
        $reproducible = hash_equals($firstDigest, $repeatDigest);

        $gates = [
            'critical_detection' => $this->gate($arkMetrics['critical_detection_rate'], 1.0, '>='),
            'root_cause_top3' => $this->gate($arkMetrics['root_cause_top3_accuracy'], 0.85, '>='),
            'false_positive_rate' => $this->gate($arkMetrics['false_positive_rate'], 0.05, '<'),
            'identity_completeness' => $this->gate($arkMetrics['identity_completeness'], 1.0, '>='),
            'reproducibility' => ['actual' => $reproducible, 'target' => true, 'passed' => $reproducible],
        ];

        return [
            'schema' => 'ark.competitive-benchmark-report.v1',
            'corpus_version' => (string)($corpus['version'] ?? 'unknown'),
            'generated_at' => gmdate(DATE_ATOM),
            'case_count' => count($cases),
            'engines' => [
                'plain_outcome_baseline' => $plainMetrics,
                'ark_deterministic' => $arkMetrics,
                'competitive_delta' => [
                    'top3_accuracy' => round($arkMetrics['root_cause_top3_accuracy'] - $plainMetrics['root_cause_top3_accuracy'], 4),
                    'critical_detection' => round($arkMetrics['critical_detection_rate'] - $plainMetrics['critical_detection_rate'], 4),
                ],
            ],
            'gates' => $gates + ['passed' => !in_array(false, array_column($gates, 'passed'), true)],
            'reproducibility_digest' => $firstDigest,
            'cases' => $arkCases,
        ];
    }

    private function evaluateArk(array $cases): array
    {
        $evaluated = [];
        foreach ($cases as $case) {
            if (!is_array($case)) continue;
            $ranked = $this->classifier->classifyTop((string)($case['evidence_text'] ?? ''), 3);
            $categories = array_values(array_column($ranked, 'category'));
            $detected = $categories !== [];
            $evaluated[] = $this->result($case, $detected, $categories);
        }
        return $evaluated;
    }

    private function evaluatePlain(array $cases): array
    {
        $evaluated = [];
        foreach ($cases as $case) {
            if (!is_array($case)) continue;
            $detected = in_array((string)($case['outcome'] ?? ''), ['failed', 'probe_error'], true);
            $evaluated[] = $this->result($case, $detected, []);
        }
        return $evaluated;
    }

    private function result(array $case, bool $detected, array $categories): array
    {
        $identityComplete = true;
        foreach (self::IDENTITY_FIELDS as $field) {
            if (!array_key_exists($field, $case) || trim((string)$case[$field]) === '') {
                $identityComplete = false;
                break;
            }
        }
        $expectedDetected = (bool)($case['expected_detected'] ?? false);
        $expectedCategory = (string)($case['expected_category'] ?? 'none');
        return [
            'id' => (string)($case['id'] ?? ''),
            'module_id' => (string)($case['module_id'] ?? ''),
            'severity' => (string)($case['severity'] ?? 'note'),
            'expected_detected' => $expectedDetected,
            'detected' => $detected,
            'expected_category' => $expectedCategory,
            'ranked_categories' => $categories,
            'top3_match' => $expectedDetected && in_array($expectedCategory, $categories, true),
            'identity_complete' => $identityComplete,
        ];
    }

    private function metrics(array $results): array
    {
        $positive = array_values(array_filter($results, static fn(array $r): bool => $r['expected_detected']));
        $negative = array_values(array_filter($results, static fn(array $r): bool => !$r['expected_detected']));
        $critical = array_values(array_filter($positive, static fn(array $r): bool => $r['severity'] === 'critical'));
        $top3 = count(array_filter($positive, static fn(array $r): bool => $r['top3_match']));
        $falsePositives = count(array_filter($negative, static fn(array $r): bool => $r['detected']));
        $criticalDetected = count(array_filter($critical, static fn(array $r): bool => $r['detected']));
        $complete = count(array_filter($results, static fn(array $r): bool => $r['identity_complete']));

        return [
            'cases' => count($results),
            'detected' => count(array_filter($results, static fn(array $r): bool => $r['detected'])),
            'critical_detection_rate' => $this->rate($criticalDetected, count($critical)),
            'root_cause_top3_accuracy' => $this->rate($top3, count($positive)),
            'false_positive_rate' => $this->rate($falsePositives, count($negative)),
            'identity_completeness' => $this->rate($complete, count($results)),
            'critical_cases' => count($critical),
            'positive_cases' => count($positive),
            'negative_cases' => count($negative),
        ];
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 1.0 : round($numerator / $denominator, 4);
    }

    private function gate(float $actual, float $target, string $operator): array
    {
        $passed = $operator === '<' ? $actual < $target : $actual >= $target;
        return ['actual' => $actual, 'target' => $target, 'operator' => $operator, 'passed' => $passed];
    }

    private function digest(array $cases): string
    {
        return hash('sha256', json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
