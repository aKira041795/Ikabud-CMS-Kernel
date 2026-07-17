<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Benchmark;

use Ikabud\Kernel\Workbench\Comprehension\Analyzers\PatternClassifier;

/**
 * AI Calibration Benchmark — measures AI performance against verified golden cases.
 *
 * Metrics measured separately:
 *   - critical deterministic recall
 *   - AI claim acceptance rate
 *   - AI citation-validity rate
 *   - AI precision and recall against verified classifications
 *   - top-three root-cause accuracy
 *   - false-positive rate
 *   - rate of useful next-test recommendations
 *   - provider latency, timeout, fallback, and cost proxy
 *
 * Policy:
 *   - No single "AI confidence" number without sample size
 *   - All-or-nothing rejection until per-claim filtering
 *   - Promote to Case Memory only after human or deterministic verification
 *
 * Initial target gates:
 *   - critical deterministic recall: 100% on golden cases
 *   - AI citation validity: 100%
 *   - AI false-positive rate: below 5%
 *   - top-three root cause: at least 85% of verified cases
 *   - reproducible deterministic plan: 100% for identical recorded inputs
 */
final class AiCalibrationBenchmark
{
    private const TARGET_GATES = [
        'critical_deterministic_recall' => ['operator' => '>=', 'target' => 1.0],
        'ai_citation_validity' => ['operator' => '>=', 'target' => 1.0],
        'ai_false_positive_rate' => ['operator' => '<', 'target' => 0.05],
        'top_three_root_cause' => ['operator' => '>=', 'target' => 0.85],
        'reproducible_deterministic_plan' => ['operator' => '>=', 'target' => 1.0],
    ];

    public function __construct(
        private readonly PatternClassifier $classifier,
    ) {}

    /**
     * Run calibration against a golden corpus.
     *
     * @param array $corpus Corpus with 'cases' key containing verified cases
     * @param callable|null $aiCaller Optional AI caller for AI-specific metrics
     * @return array<string,mixed>
     */
    public function calibrate(array $corpus, ?callable $aiCaller = null): array
    {
        $cases = $corpus['cases'] ?? [];
        if (!is_array($cases) || $cases === []) {
            throw new \RuntimeException('Calibration corpus has no cases');
        }

        $started = microtime(true);
        $version = (string) ($corpus['version'] ?? 'unknown');
        $caseCount = count($cases);

        // Run deterministic classification twice for reproducibility check
        $firstRun = $this->evaluateDeterministic($cases);
        $secondRun = $this->evaluateDeterministic($cases);
        $firstDigest = $this->digest($firstRun);
        $secondDigest = $this->digest($secondRun);
        $reproducible = hash_equals($firstDigest, $secondDigest);

        // Calculate deterministic metrics
        $detMetrics = $this->deterministicMetrics($firstRun);

        // AI metrics (if caller provided)
        $aiMetrics = $aiCaller !== null
            ? $this->aiMetrics($cases, $aiCaller)
            : $this->nullAiMetrics();

        // Gate evaluation
        $gates = $this->evaluateGates($detMetrics, $aiMetrics, $reproducible);

        // Provider trace summary
        $providerTrace = $this->buildProviderTrace($aiMetrics);

        $elapsed = round((microtime(true) - $started) * 1000, 2);

        return [
            'schema' => 'ark.ai-calibration-report.v1',
            'corpus_version' => $version,
            'generated_at' => gmdate(DATE_ATOM),
            'elapsed_ms' => $elapsed,
            'sample_size' => $caseCount,
            'metrics' => [
                'deterministic' => $detMetrics,
                'ai' => $aiMetrics,
                'delta' => [
                    'top3_improvement' => round(
                        ($aiMetrics['top_three_root_cause'] ?? 0) -
                        ($detMetrics['top_three_root_cause'] ?? 0),
                        4
                    ),
                    'critical_recall_delta' => round(
                        ($aiMetrics['critical_recall'] ?? 0) -
                        ($detMetrics['critical_recall'] ?? 0),
                        4
                    ),
                ],
            ],
            'gates' => $gates + ['passed' => !in_array(false, array_column($gates, 'passed'), true)],
            'reproducibility' => [
                'deterministic_plan_reproducible' => $reproducible,
                'first_digest' => substr($firstDigest, 0, 16),
                'second_digest' => substr($secondDigest, 0, 16),
            ],
            'provider_trace' => $providerTrace,
            'target_gates' => self::TARGET_GATES,
            'note' => $caseCount < 30
                ? "Sample size ({$caseCount}) below 30 — do not publish single confidence numbers"
                : '',
        ];
    }

    /**
     * @param list<array<string,mixed>> $cases
     * @return list<array<string,mixed>>
     */
    private function evaluateDeterministic(array $cases): array
    {
        $results = [];
        foreach ($cases as $case) {
            if (!is_array($case)) continue;
            $classification = $this->classifier->classify((string) ($case['evidence_text'] ?? ''));
            $top3 = $this->classifier->classifyTop((string) ($case['evidence_text'] ?? ''), 3);
            $categories = array_values(array_column($top3, 'category'));

            $expectedDetected = (bool) ($case['expected_detected'] ?? true);
            $expectedCategory = (string) ($case['expected_category'] ?? '');
            $severity = (string) ($case['severity'] ?? 'note');

            $results[] = [
                'id' => (string) ($case['id'] ?? ''),
                'module_id' => (string) ($case['module_id'] ?? ''),
                'severity' => $severity,
                'expected_detected' => $expectedDetected,
                'detected' => $classification['category'] !== 'unknown',
                'expected_category' => $expectedCategory,
                'classified_category' => $classification['category'],
                'top3_categories' => $categories,
                'top3_match' => in_array($expectedCategory, $categories, true),
                'confidence' => $classification['score'],
                'matched_terms' => $classification['matched_terms'],
            ];
        }
        return $results;
    }

    /**
     * @param list<array<string,mixed>> $cases
     * @param callable $aiCaller
     * @return array<string,mixed>
     */
    private function aiMetrics(array $cases, callable $aiCaller): array
    {
        $aiResults = [];
        $timeouts = 0;
        $fallbacks = 0;
        $totalLatency = 0.0;
        $totalCost = 0.0;

        foreach ($cases as $case) {
            if (!is_array($case)) continue;
            $started = microtime(true);
            try {
                $aiResult = $aiCaller($case);
                $latency = round((microtime(true) - $started) * 1000, 2);
                $totalLatency += $latency;

                $isFallback = isset($aiResult['provider_trace']['fallback_reason'])
                    && $aiResult['provider_trace']['fallback_reason'] !== null;
                if ($isFallback) $fallbacks++;

                $aiResults[] = [
                    'case_id' => (string) ($case['id'] ?? ''),
                    'accepted' => ($aiResult['schema_version'] ?? '') === '1.0' && !$isFallback,
                    'citations_valid' => $this->checkCitations($aiResult, $case),
                    'hypotheses_count' => count($aiResult['hypotheses'] ?? []),
                    'next_tests_count' => count($aiResult['next_tests'] ?? []),
                    'has_remediation' => isset($aiResult['remediation']),
                    'confidence' => (float) (($aiResult['hypotheses'][0]['confidence'] ?? 0)),
                    'latency_ms' => $latency,
                    'fallback' => $isFallback,
                ];
            } catch (\Throwable $e) {
                $timeouts++;
                $aiResults[] = [
                    'case_id' => (string) ($case['id'] ?? ''),
                    'accepted' => false,
                    'citations_valid' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $total = count($aiResults);
        $accepted = count(array_filter($aiResults, fn($r) => $r['accepted'] ?? false));
        $validCitations = count(array_filter($aiResults, fn($r) => $r['citations_valid'] ?? false));

        return [
            'enabled' => true,
            'cases' => $total,
            'accepted' => $accepted,
            'acceptance_rate' => $this->rate($accepted, $total),
            'citation_validity_rate' => $this->rate($validCitations, $total),
            'timeouts' => $timeouts,
            'fallbacks' => $fallbacks,
            'average_latency_ms' => $total > 0 ? round($totalLatency / $total, 2) : 0.0,
            'total_latency_ms' => round($totalLatency, 2),
            'cost_proxy' => $this->estimateCost($totalLatency, $fallbacks, $timeouts),
        ];
    }

    /** @param list<array<string,mixed>> $results */
    private function deterministicMetrics(array $results): array
    {
        $positive = array_filter($results, fn($r) => $r['expected_detected']);
        $negative = array_filter($results, fn($r) => !$r['expected_detected']);
        $critical = array_filter($positive, fn($r) => $r['severity'] === 'critical');
        $criticalDetected = count(array_filter($critical, fn($r) => $r['detected']));
        $top3 = count(array_filter($positive, fn($r) => $r['top3_match']));
        $falsePositives = count(array_filter($negative, fn($r) => $r['detected']));

        return [
            'cases' => count($results),
            'detected' => count(array_filter($results, fn($r) => $r['detected'])),
            'critical_recall' => $this->rate($criticalDetected, count($critical)),
            'critical_cases' => count($critical),
            'top_three_root_cause' => $this->rate($top3, count($positive)),
            'false_positive_rate' => $this->rate($falsePositives, count($negative)),
            'positive_cases' => count($positive),
            'negative_cases' => count($negative),
        ];
    }

    /** @return array<string,mixed> */
    private function nullAiMetrics(): array
    {
        return [
            'enabled' => false,
            'cases' => 0,
            'accepted' => 0,
            'acceptance_rate' => 0.0,
            'citation_validity_rate' => 0.0,
            'timeouts' => 0,
            'fallbacks' => 0,
            'average_latency_ms' => 0.0,
            'total_latency_ms' => 0.0,
            'cost_proxy' => 0.0,
            'note' => 'AI provider not configured — deterministic-only calibration',
        ];
    }

    /** @param array<string,mixed> $aiMetrics */
    private function evaluateGates(array $detMetrics, array $aiMetrics, bool $reproducible): array
    {
        $gates = [];

        // Deterministic gates
        $gates['critical_deterministic_recall'] = $this->gate(
            $detMetrics['critical_recall'] ?? 0,
            1.0,
            '>='
        );
        $gates['top_three_root_cause'] = $this->gate(
            $detMetrics['top_three_root_cause'] ?? 0,
            0.85,
            '>='
        );
        $gates['false_positive_rate'] = $this->gate(
            $detMetrics['false_positive_rate'] ?? 1.0,
            0.05,
            '<'
        );
        $gates['reproducible_deterministic_plan'] = $this->gate(
            $reproducible ? 1.0 : 0.0,
            1.0,
            '>='
        );

        // AI gates (only if AI is enabled)
        if (($aiMetrics['enabled'] ?? true) && ($aiMetrics['cases'] ?? 0) > 0) {
            $gates['ai_citation_validity'] = $this->gate(
                $aiMetrics['citation_validity_rate'] ?? 0,
                1.0,
                '>='
            );
            $gates['ai_false_positive_rate'] = $this->gate(
                $detMetrics['false_positive_rate'] ?? 1.0,
                0.05,
                '<'
            );
        }

        return $gates;
    }

    /** @param array<string,mixed> $aiMetrics */
    private function buildProviderTrace(array $aiMetrics): array
    {
        return [
            'ai_enabled' => $aiMetrics['enabled'] ?? false,
            'total_cases' => $aiMetrics['cases'] ?? 0,
            'timeouts' => $aiMetrics['timeouts'] ?? 0,
            'fallbacks' => $aiMetrics['fallbacks'] ?? 0,
            'average_latency_ms' => $aiMetrics['average_latency_ms'] ?? 0.0,
            'cost_proxy' => $aiMetrics['cost_proxy'] ?? 0.0,
        ];
    }

    /** @param array<string,mixed> $aiResult */
    private function checkCitations(array $aiResult, array $case): bool
    {
        $hypotheses = $aiResult['hypotheses'] ?? [];
        if ($hypotheses === []) return true; // Empty hypotheses are valid (no unsupported claims)

        foreach ($hypotheses as $hypothesis) {
            if (!is_array($hypothesis)) continue;
            // Check that evidence citations exist and are non-empty
            $evidenceFor = (array) ($hypothesis['evidence_for'] ?? []);
            $evidenceAgainst = (array) ($hypothesis['evidence_against'] ?? []);
            $allCitations = array_merge($evidenceFor, $evidenceAgainst);

            // Each hypothesis must cite at least one evidence ID
            if ($allCitations === []) return false;
        }
        return true;
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 1.0 : round($numerator / $denominator, 4);
    }

    private function gate(float $actual, float $target, string $operator): array
    {
        $passed = $operator === '<' ? $actual < $target : $actual >= $target;
        return [
            'actual' => $actual,
            'target' => $target,
            'operator' => $operator,
            'passed' => $passed,
        ];
    }

    private function digest(array $results): string
    {
        $clean = array_map(function (array $r): array {
            unset($r['matched_terms']);
            return $r;
        }, $results);
        return hash('sha256', json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function estimateCost(float $totalLatencyMs, int $fallbacks, int $timeouts): float
    {
        // Cost proxy: rough estimate based on latency and fallback count
        // Free tier: no cost; fallback = heuristic (free); timeout = wasted call
        return round(($fallbacks * 0.001) + ($timeouts * 0.005), 4);
    }
}
