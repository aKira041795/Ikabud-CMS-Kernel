<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\{
    ModuleComprehensionProvider,
    ActionContract,
    ChainLink,
};

use Ikabud\Kernel\Workbench\Comprehension\Analyzers\{
    SemanticScorer,
    BayesianReasoner,
    TemporalValidator,
    PatternClassifier,
    AnomalyDetector,
    CrossModuleAnalyzer,
};

/**
 * Hybrid Semantic Comprehension Engine.
 *
 * Combines 6 reasoning layers for deep understanding of module behavior:
 *
 *   Layer 1 — Deterministic Causal Chain (via ModuleComprehensionEngine)
 *   Layer 2 — Semantic Similarity Scoring (text-based evidence matching)
 *   Layer 3 — Bayesian Failure History (historical probability)
 *   Layer 4 — Temporal Ordering Validation (causality constraints)
 *   Layer 5 — Pattern Classification + Anomaly Detection (error diagnosis)
 *   Layer 6 — Cross-Module Cascade Analysis (dependency impact)
 *
 * Each layer feeds into the next. The final output is a rich evidence
 * packet that the AI Steward can act on.
 */
class SemanticComprehensionEngine
{
    private ModuleComprehensionEngine $deterministic;
    private SemanticScorer $scorer;
    private BayesianReasoner $bayesian;
    private TemporalValidator $temporal;
    private PatternClassifier $classifier;
    private AnomalyDetector $anomaly;
    private CrossModuleAnalyzer $crossModule;

    private string $moduleId;
    private array $runtimeEvidence = [];
    private array $timestamps = [];

    public function __construct(
        string $moduleId,
        ModuleComprehensionProvider $provider,
        ?BayesianReasoner $bayesian = null,
        ?CrossModuleAnalyzer $crossModule = null,
    ) {
        $this->moduleId = $moduleId;
        $this->deterministic = new ModuleComprehensionEngine($provider);
        $this->scorer = new SemanticScorer();
        $this->bayesian = $bayesian ?? new BayesianReasoner();
        $this->temporal = new TemporalValidator();
        $this->classifier = new PatternClassifier();
        $this->anomaly = new AnomalyDetector();
        $this->crossModule = $crossModule ?? new CrossModuleAnalyzer();
    }

    /**
     * Feed runtime evidence.
     *
     * @param array $evidence Key-value pairs of observed evidence
     * @param array $timestamps Optional map of step → microtime for temporal analysis
     */
    public function feedEvidence(array $evidence, array $timestamps = []): void
    {
        $this->runtimeEvidence = $evidence;
        $this->timestamps = $timestamps;
        $this->deterministic->feedEvidence($evidence);
    }

    /**
     * Register a cross-module provider for cascade analysis.
     */
    public function registerProvider(string $moduleId, ModuleComprehensionProvider $provider): void
    {
        $this->crossModule->registerProvider($moduleId, $provider);
    }

    /**
     * Get action names without running analysis (no history recording).
     */
    public function actionIds(): array
    {
        $ref = new \ReflectionClass($this->deterministic);
        $prop = $ref->getProperty('provider');
        $prop->setAccessible(true);
        $provider = $prop->getValue($this->deterministic);

        return array_map(fn($a) => $a->id, $provider->actions());
    }

    /**
     * Reset Bayesian history for an action.
     */
    public function resetHistory(?string $actionId = null): void
    {
        if ($actionId) {
            $this->bayesian->resetAction($this->moduleId, $actionId);
        } else {
            foreach ($this->actionIds() as $aid) {
                $this->bayesian->resetAction($this->moduleId, $aid);
            }
        }
    }

    /**
     * Full semantic analysis of an action.
     *
     * @param bool $recordHistory When false, does NOT update Bayesian history
     * @param array $metadata Context for Bayesian history (run_id, commit, tenant, source)
     *
     * Returns:
     *   - breakpoint: where it broke (or null)
     *   - chain_scores: per-link semantic scores
     *   - temporal: ordering violations and anomalies
     *   - bayesian: historical failure probabilities
     *   - diagnosis: error pattern classification
     *   - anomalies: unexpected evidence
     *   - cross_module: cross-module impact analysis
     *   - confidence: overall confidence in the analysis
     *   - root_cause_hypothesis: synthesized root cause
     */
    public function analyze(string $actionId, bool $recordHistory = true, array $metadata = []): array
    {
        // Layer 1: Deterministic chain probe
        $deterministicResult = $this->deterministic->analyzeAction($actionId);
        $chainResults = $deterministicResult['chain'] ?? [];
        $breakpoint = $deterministicResult['breakpoint'] ?? null;
        $breakCategory = $breakpoint ? $this->findBreakCategory($chainResults, $breakpoint) : null;

        // Layer 2: Semantic scoring for each link
        $semanticScores = [];
        $action = $this->findAction($actionId);
        if ($action) {
            foreach ($action->chain as $link) {
                $semanticScores[$link->step] = $this->scorer->scoreLink($link, $this->runtimeEvidence);
            }
        }

        // Layer 3: Bayesian historical probability
        $bayesianAnalysis = [];
        if ($action) {
            foreach ($action->chain as $link) {
                $priorFail = $this->bayesian->priorFailureProbability($this->moduleId, $actionId, $link->step);
                $bayesianAnalysis[$link->step] = [
                    'prior_failure_probability' => $priorFail,
                    'prior_success_probability' => round(1.0 - $priorFail, 4),
                ];
            }
            // Record outcomes (only when explicitly analyzing real data)
            if ($recordHistory) {
                foreach ($chainResults as $result) {
                    $this->bayesian->recordOutcome(
                        $this->moduleId, $actionId,
                        $result['step'] ?? '?',
                        $result['ok'] ?? false,
                        $metadata
                    );
                }
            }
        }

        // Layer 4: Temporal ordering validation
        $temporalAnalysis = $this->temporal->validate(
            $chainResults,
            $this->runtimeEvidence,
            $this->timestamps
        );

        // Layer 5a: Pattern classification on error evidence
        $errorText = $this->collectErrorText($chainResults, $this->runtimeEvidence);
        $classification = $this->classifier->classify($errorText);
        $fullClassification = $this->classifier->classifyAll($this->runtimeEvidence);

        // Layer 5b: Anomaly detection
        $declaredSteps = $action ? array_map(fn(ChainLink $l) => $l->step, $action->chain) : [];
        $declaredCategories = $action ? array_map(fn(ChainLink $l) => $l->category, $action->chain) : [];
        $anomalies = $this->anomaly->detect($this->runtimeEvidence, $declaredSteps, $declaredCategories);
        $missingLinks = $this->anomaly->suggestMissingLinks($this->runtimeEvidence);

        // Layer 6: Cross-module cascade analysis
        $crossModuleAnalysis = $this->crossModule->analyzeImpact(
            $this->moduleId,
            $actionId,
            ['category' => $breakCategory, 'breakpoint' => $breakpoint]
        );

        // Synthesize root cause hypothesis
        $rootCause = $this->synthesizeRootCause(
            $breakpoint,
            $breakCategory,
            $classification,
            $temporalAnalysis,
            $bayesianAnalysis,
            $crossModuleAnalysis
        );

        // Overall confidence
        $confidence = $this->computeOverallConfidence(
            $deterministicResult,
            $semanticScores,
            $temporalAnalysis,
            $classification,
            $breakpoint
        );

        return [
            'module' => $this->moduleId,
            'action' => $actionId,
            'breakpoint' => $breakpoint,
            'break_category' => $breakCategory,
            'deterministic' => $deterministicResult,
            'semantic' => [
                'per_link_scores' => $semanticScores,
            ],
            'bayesian' => [
                'per_link' => $bayesianAnalysis,
                'action_history' => $this->bayesian->actionHistory($this->moduleId, $actionId),
            ],
            'temporal' => $temporalAnalysis,
            'diagnosis' => [
                'primary_classification' => $classification,
                'full_classification' => $fullClassification,
            ],
            'anomalies' => [
                'unexpected_evidence' => $anomalies,
                'missing_links' => $missingLinks,
            ],
            'cross_module' => $crossModuleAnalysis,
            'root_cause_hypothesis' => $rootCause,
            'confidence' => $confidence,
        ];
    }

    /**
     * Analyze all actions in the module.
     *
     * @param bool $recordHistory When false, does NOT update Bayesian history
     * @param array $metadata Context for Bayesian history
     */
    public function analyzeAll(bool $recordHistory = true, array $metadata = []): array
    {
        $results = [];
        foreach ($this->actionIds() as $actionId) {
            $results[$actionId] = $this->analyze($actionId, $recordHistory, $metadata);
        }
        return $results;
    }

    /**
     * Build a complete evidence packet for the AI Steward.
     */
    public function buildEvidencePacket(string $actionId): array
    {
        $analysis = $this->analyze($actionId);
        $graph = $this->deterministic->buildGraph();

        return [
            'module' => $graph,
            'analysis' => $analysis,
            'runtime' => $this->runtimeEvidence,
            'timestamps' => $this->timestamps,
            'bayesian_history' => $this->bayesian->actionHistory($this->moduleId, $actionId),
            'generated_at' => date('c'),
            'engine_version' => '2.0-semantic',
        ];
    }

    private function findAction(string $actionId): ?ActionContract
    {
        // Access the provider directly via reflection
        $ref = new \ReflectionClass($this->deterministic);
        $prop = $ref->getProperty('provider');
        $prop->setAccessible(true);
        $provider = $prop->getValue($this->deterministic);

        foreach ($provider->actions() as $action) {
            if ($action->id === $actionId) {
                return $action;
            }
        }

        return null;
    }

    private function findBreakCategory(array $chainResults, string $breakpoint): ?string
    {
        foreach ($chainResults as $result) {
            if (($result['step'] ?? '') === $breakpoint) {
                return $result['category'] ?? null;
            }
        }
        return null;
    }

    private function collectErrorText(array $chainResults, array $evidence): string
    {
        $texts = [];

        // From failed chain links
        foreach ($chainResults as $result) {
            if (!($result['ok'] ?? true)) {
                $texts[] = $result['description'] ?? '';
                $step = $result['step'] ?? '';
                if (isset($evidence[$step]) && is_string($evidence[$step])) {
                    $texts[] = $evidence[$step];
                }
            }
        }

        // From evidence matching failure patterns (even if step passed at deterministic layer)
        foreach ($evidence as $key => $value) {
            if (is_string($value) && preg_match('/error|fail|exception|denied|expired|invalid|mismatch|419|403|422|500|csrf|token/i', $value)) {
                $texts[] = $value;
            }
        }

        return implode(' ', array_unique(array_filter($texts)));
    }

    private function synthesizeRootCause(
        ?string $breakpoint,
        ?string $breakCategory,
        array $classification,
        array $temporalAnalysis,
        array $bayesianAnalysis,
        array $crossModuleAnalysis
    ): array {
        if ($breakpoint === null) {
            // No deterministic failure — check semantic layer for latent issues
            $isClean = $classification['category'] === 'unknown' || $classification['score'] < 0.3;
            if ($isClean) {
                return [
                    'summary' => 'No failure detected — all chain links passed.',
                    'severity' => 'success',
                    'action' => 'none',
                ];
            }
            return [
                'summary' => 'No deterministic breakpoint, but semantic analysis detected: ' . ($classification['diagnosis'] ?? 'unusual patterns'),
                'severity' => 'info',
                'action' => 'Review flagged anomalies for latent issues',
            ];
        }

        $parts = [];

        // 1. What broke (deterministic)
        $parts[] = "Break at step '{$breakpoint}' ({$breakCategory})";

        // 2. Classification insight (check if there's a better-signaled failure earlier)
        $diagnosis = $classification['diagnosis'] ?? '';
        if ($diagnosis) {
            $parts[] = $diagnosis;
        }

        // 3. Semantic signal — check if earlier link had failure pattern
        if ($classification['score'] >= 0.3) {
            // The classification found a meaningful signal
        }

        // 4. Temporal insight
        $orderScore = $temporalAnalysis['order_score'] ?? 1.0;
        if ($orderScore < 0.8) {
            $parts[] = 'Temporal ordering anomaly detected — evidence arrived out of expected sequence.';
            $violations = $temporalAnalysis['violations'] ?? [];
            foreach ($violations as $v) {
                if (($v['severity'] ?? '') === 'error') {
                    $parts[] = "  - {$v['description']}";
                }
            }
        }

        // 5. Bayesian insight
        if (!empty($bayesianAnalysis)) {
            $highRiskLinks = [];
            foreach ($bayesianAnalysis as $step => $stats) {
                if (($stats['prior_failure_probability'] ?? 0) > 0.5 && $step === $breakpoint) {
                    $highRiskLinks[] = "{$step} (" . round($stats['prior_failure_probability'] * 100) . "% historical failure rate)";
                }
            }
            if (!empty($highRiskLinks)) {
                $parts[] = 'Historically unreliable: ' . implode(', ', $highRiskLinks);
            }
        }

        // 6. Cross-module insight
        if ($crossModuleAnalysis['cross_module'] ?? false) {
            $parts[] = 'Cross-module dependency involved — check upstream module health.';
            foreach ($crossModuleAnalysis['recommendations'] ?? [] as $rec) {
                $parts[] = "  - {$rec}";
            }
        }

        return [
            'summary' => implode("\n", $parts),
            'severity' => $breakCategory === 'db' || $breakCategory === 'http' ? 'error' : 'warning',
            'action' => match ($breakCategory) {
                'ui' => 'Check template rendering and JavaScript execution',
                'http' => 'Check route handler, CSRF token, and request data',
                'service' => 'Check service layer logic and parameters',
                'db' => 'Check SQL query, table schema, and constraints',
                'event' => 'Check event listener registration and trigger conditions',
                'audit' => 'Check audit log configuration and permissions',
                'capability' => 'Check capability registration and module dependencies',
                default => 'Manual inspection required',
            },
        ];
    }

    private function computeOverallConfidence(
        array $deterministic,
        array $semanticScores,
        array $temporalAnalysis,
        array $classification,
        ?string $breakpoint
    ): array {
        $factors = [];

        // Deterministic chain completeness
        $chainResults = $deterministic['chain'] ?? [];
        $totalLinks = count($chainResults);
        $observedLinks = count(array_filter($chainResults, fn($r) => $r['actual'] !== false));
        $factors['coverage'] = $totalLinks > 0 ? $observedLinks / $totalLinks : 0;

        // Semantic score quality
        if (!empty($semanticScores)) {
            $avgScore = array_sum(array_map(fn($s) => $s['score'] ?? 0, $semanticScores)) / count($semanticScores);
            $factors['semantic_quality'] = $avgScore;
        } else {
            $factors['semantic_quality'] = 0.5;
        }

        // Temporal order score
        $factors['temporal_order'] = $temporalAnalysis['order_score'] ?? 1.0;

        // Classification confidence
        $factors['classification'] = $classification['confidence'] === 'high' ? 0.9
            : ($classification['confidence'] === 'medium' ? 0.6
            : ($classification['confidence'] === 'low' ? 0.3 : 0.0));

        // Breakpoint presence
        $factors['has_breakpoint'] = $breakpoint !== null ? 1.0 : 0.8;

        // Weighted average
        $weights = ['coverage' => 0.25, 'semantic_quality' => 0.25, 'temporal_order' => 0.15,
                     'classification' => 0.20, 'has_breakpoint' => 0.15];

        $weightedSum = 0;
        $weightTotal = 0;
        foreach ($weights as $factor => $weight) {
            $value = $factors[$factor] ?? 0;
            $weightedSum += $value * $weight;
            $weightTotal += $weight;
        }

        $overall = $weightTotal > 0 ? $weightedSum / $weightTotal : 0.5;

        return [
            'score' => round($overall, 2),
            'factors' => $factors,
            'label' => $overall >= 0.8 ? 'high' : ($overall >= 0.5 ? 'medium' : 'low'),
        ];
    }
}
