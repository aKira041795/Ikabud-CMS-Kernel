<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Analyzers;

/**
 * Layer 4: Temporal Ordering Validator.
 *
 * Checks that observed evidence respects the expected causal order:
 *   UI → HTTP → Service → DB → Event → Audit → UI
 *
 * Uses topological constraint satisfaction. Each chain link declares its
 * category, and categories have a fixed partial order. If evidence shows
 * an audit log entry appearing before the DB write that should precede it,
 * that's a temporal violation.
 *
 * Also detects:
 *   - Evidence arriving out of order (reversed causality)
 *   - Evidence gaps (missing intermediate steps)
 *   - Evidence that arrived too quickly (sub-millisecond gaps suggest cache)
 *   - Evidence that took too long (coordinated omission / timeout)
 */
class TemporalValidator
{
    /** Canonical category ordering — lower index = should happen first */
    private const CATEGORY_ORDER = [
        'ui' => 0,
        'http' => 1,
        'service' => 2,
        'db' => 3,
        'event' => 4,
        'audit' => 5,
        'verify' => 6,  // Post-action verification (UI checks, assertions)
    ];

    /**
     * Validate temporal ordering of evidence against a chain.
     *
     * @param array $chainResults Results from the deterministic probe
     * @param array $evidence Raw runtime evidence
     * @param array $timestamps Optional map of step → timestamp (float microtime)
     * @return array{violations: array, order_score: float, anomalies: array}
     */
    public function validate(array $chainResults, array $evidence, array $timestamps = []): array
    {
        $violations = [];
        $anomalies = [];

        // 1. Category ordering check
        $categoryViolations = $this->checkCategoryOrdering($chainResults, $timestamps);
        foreach ($categoryViolations as $v) {
            $violations[] = $v;
        }

        // 2. Timestamp-based causality check (if timestamps provided)
        if (!empty($timestamps)) {
            $tsViolations = $this->checkTimestampCausality($chainResults, $timestamps);
            foreach ($tsViolations as $v) {
                $violations[] = $v;
            }
        }

        // 3. Gap detection — missing intermediate steps
        $gapViolations = $this->checkGaps($chainResults, $evidence);
        foreach ($gapViolations as $v) {
            $violations[] = $v;
        }

        // 4. Timing anomaly detection
        if (!empty($timestamps)) {
            $timingAnomalies = $this->checkTimingAnomalies($chainResults, $timestamps);
            foreach ($timingAnomalies as $a) {
                $anomalies[] = $a;
            }
        }

        // Compute order score: 1.0 = perfect ordering, 0.0 = all violated
        $totalSteps = count($chainResults);
        $orderScore = $totalSteps > 0
            ? round(1.0 - (count($violations) / $totalSteps), 2)
            : 1.0;

        return [
            'violations' => $violations,
            'order_score' => max(0.0, $orderScore),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Check that categories appear in the expected order.
     */
    private function checkCategoryOrdering(array $chainResults, array $timestamps): array
    {
        $violations = [];
        $lastCategory = null;
        $lastCatOrder = -1;

        foreach ($chainResults as $result) {
            $category = $result['category'] ?? 'unknown';
            $step = $result['step'] ?? '?';
            $currentOrder = self::CATEGORY_ORDER[$category] ?? 99;

            if ($currentOrder < $lastCatOrder) {
                // Category regressed — later step has earlier category
                $violations[] = [
                    'type' => 'category_ordering',
                    'description' => "Step '{$step}' (category: {$category}, order: {$currentOrder}) appears after category order {$lastCatOrder}",
                    'severity' => 'warning',
                    'step' => $step,
                ];
            }

            $lastCategory = $category;
            $lastCatOrder = $currentOrder;
        }

        return $violations;
    }

    /**
     * Check timestamp causality: each step should have a timestamp ≥ the previous.
     */
    private function checkTimestampCausality(array $chainResults, array $timestamps): array
    {
        $violations = [];
        $prevTime = null;
        $prevStep = null;

        foreach ($chainResults as $result) {
            $step = $result['step'] ?? '?';
            if (!isset($timestamps[$step])) {
                $prevTime = null;
                continue;
            }

            $currentTime = (float)$timestamps[$step];

            if ($prevTime !== null && $currentTime < $prevTime) {
                $diff = round($prevTime - $currentTime, 4);
                $violations[] = [
                    'type' => 'timestamp_reversal',
                    'description' => "Step '{$step}' timestamp ({$currentTime}) is before previous step '{$prevStep}' ({$prevTime}), diff: {$diff}s",
                    'severity' => 'error',
                    'step' => $step,
                    'time_diff' => -$diff,
                ];
            }

            $prevTime = $currentTime;
            $prevStep = $step;
        }

        return $violations;
    }

    /**
     * Detect gaps: failed steps that break the chain continuity.
     */
    private function checkGaps(array $chainResults, array $evidence): array
    {
        $violations = [];
        $foundGap = false;

        foreach ($chainResults as $i => $result) {
            $ok = $result['ok'] ?? false;
            $step = $result['step'] ?? '?';

            if (!$ok && !$foundGap) {
                $foundGap = true;
                // Check how many subsequent steps also failed
                $consecutiveFailures = 1;
                for ($j = $i + 1; $j < count($chainResults); $j++) {
                    if (!($chainResults[$j]['ok'] ?? false)) {
                        $consecutiveFailures++;
                    } else {
                        break;
                    }
                }

                $violations[] = [
                    'type' => 'chain_gap',
                    'description' => "Chain broken at step '{$step}' — {$consecutiveFailures} consecutive failure(s)",
                    'severity' => 'error',
                    'step' => $step,
                    'gap_size' => $consecutiveFailures,
                ];
            }
        }

        return $violations;
    }

    /**
     * Detect timing anomalies: steps that complete suspiciously fast or slow.
     */
    private function checkTimingAnomalies(array $chainResults, array $timestamps): array
    {
        $anomalies = [];
        $prevTime = null;
        $prevStep = null;

        foreach ($chainResults as $result) {
            $step = $result['step'] ?? '?';
            if (!isset($timestamps[$step])) {
                $prevTime = null;
                continue;
            }

            $currentTime = (float)$timestamps[$step];

            if ($prevTime !== null) {
                $elapsed = $currentTime - $prevTime;

                if ($elapsed < 0.001 && $elapsed >= 0) {
                    // Sub-millisecond gap — suspicious (cache hit?)
                    $anomalies[] = [
                        'type' => 'suspiciously_fast',
                        'description' => "Step '{$step}' completed {$elapsed}s after '{$prevStep}' — possible cache serving stale data",
                        'severity' => 'warning',
                        'step' => $step,
                        'elapsed_ms' => round($elapsed * 1000, 2),
                    ];
                }

                if ($elapsed > 5.0) {
                    // >5s gap — possible timeout or background job
                    $anomalies[] = [
                        'type' => 'suspiciously_slow',
                        'description' => "Step '{$step}' took {$elapsed}s after '{$prevStep}' — possible timeout or async gap",
                        'severity' => 'info',
                        'step' => $step,
                        'elapsed_s' => round($elapsed, 2),
                    ];
                }
            }

            $prevTime = $currentTime;
            $prevStep = $step;
        }

        return $anomalies;
    }
}
