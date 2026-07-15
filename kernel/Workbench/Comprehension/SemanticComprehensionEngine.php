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
    EmbeddingScorer,
    BayesianReasoner,
    TemporalValidator,
    PatternClassifier,
    AnomalyDetector,
    CrossModuleAnalyzer,
    SourceRetriever,
    AiHypothesisGenerator,
    CaseMemory,
    ProviderCoverageScorer,
};

/**
 * Hybrid Semantic Comprehension Engine.
 *
 * Combines 8 reasoning layers for deep understanding of module behavior:
 *
 *   Layer 1 — Deterministic Causal Chain (via ModuleComprehensionEngine)
 *   Layer 2 — NLP-Enhanced Semantic Similarity Scoring (embedding + TF-IDF + n-gram)
 *   Layer 3 — Bayesian Failure History (Beta-Binomial conjugate prior)
 *   Layer 4 — Temporal Ordering Validation (causality constraints + timing anomalies)
 *   Layer 5 — Pattern Classification + Anomaly Detection (error diagnosis + missing links)
 *   Layer 6 — Cross-Module Cascade Analysis (dependency impact + drift)
 *   Layer 7 — AI Hypothesis Generation (root cause + fix plan + boundary notes)
 *   Layer 8 — Provider Coverage Scoring (missing/stale chain links, description quality)
 *
 * Plus: Source Retrieval (step → handler/service/template mapping)
 *       Case Memory (similar past fixes for faster diagnosis)
 */
class SemanticComprehensionEngine
{
    private ModuleComprehensionEngine $deterministic;
    private SemanticScorer $scorer;
    private EmbeddingScorer $embeddingScorer;
    private BayesianReasoner $bayesian;
    private TemporalValidator $temporal;
    private PatternClassifier $classifier;
    private AnomalyDetector $anomaly;
    private CrossModuleAnalyzer $crossModule;
    private SourceRetriever $sourceRetriever;
    private AiHypothesisGenerator $aiHypothesis;
    private CaseMemory $caseMemory;
    private ProviderCoverageScorer $coverageScorer;

    private string $moduleId;
    private array $runtimeEvidence = [];
    private array $timestamps = [];
    private ?ModuleComprehensionProvider $provider = null;

    public function __construct(
        string $moduleId,
        ModuleComprehensionProvider $provider,
        ?BayesianReasoner $bayesian = null,
        ?CrossModuleAnalyzer $crossModule = null,
        ?SourceRetriever $sourceRetriever = null,
        ?AiHypothesisGenerator $aiHypothesis = null,
        ?CaseMemory $caseMemory = null,
    ) {
        $this->moduleId = $moduleId;
        $this->provider = $provider;
        $this->deterministic = new ModuleComprehensionEngine($provider);
        $this->scorer = new SemanticScorer();
        $this->embeddingScorer = new EmbeddingScorer();
        $this->bayesian = $bayesian ?? new BayesianReasoner();
        $this->temporal = new TemporalValidator();
        $this->classifier = new PatternClassifier();
        $this->anomaly = new AnomalyDetector();
        $this->crossModule = $crossModule ?? new CrossModuleAnalyzer();
        $this->sourceRetriever = $sourceRetriever ?? new SourceRetriever($moduleId);
        $this->caseMemory = $caseMemory ?? new CaseMemory();
        $this->aiHypothesis = $aiHypothesis ?? new AiHypothesisGenerator(
            $moduleId,
            $this->sourceRetriever,
            $this->caseMemory,
        );
        $this->coverageScorer = new ProviderCoverageScorer();
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

        // Layer 7: AI Hypothesis Generation (heuristic fallback)
        $aiHypothesis = $this->aiHypothesis->generate(
            [
                'module' => $this->moduleId,
                'action' => $actionId,
                'breakpoint' => $breakpoint,
                'break_category' => $breakCategory,
                'deterministic' => $deterministicResult,
                'diagnosis' => ['primary_classification' => $classification, 'full_classification' => $fullClassification],
                'anomalies' => ['unexpected_evidence' => $anomalies, 'missing_links' => $missingLinks],
                'cross_module' => $crossModuleAnalysis,
                'root_cause_hypothesis' => $rootCause,
                'temporal' => $temporalAnalysis,
                'confidence' => ['score' => 0, 'label' => 'none'], // computed below
            ],
            $this->runtimeEvidence,
            $bayesianAnalysis,
        );

        // Source retrieval for the failed step
        $sourceContext = null;
        if ($breakpoint !== null) {
            $sourceContext = $this->sourceRetriever->retrieve($breakpoint, $breakCategory ?? 'unknown');
        }

        // Layer 8: Provider coverage scoring (when we have evidence)
        $coverageScore = null;
        $hasEvidence = !empty(array_diff_key($this->runtimeEvidence, ['_tenant_id' => true, '_entity_id' => true, '_entity_type' => true, '_run_id' => true]));
        if ($hasEvidence && $this->provider !== null) {
            $coverageScore = $this->coverageScorer->score($this->provider, $this->runtimeEvidence);
        }

        // NLP-enhanced semantic scoring (embedding-based)
        $embeddingScores = [];
        $action = $this->findAction($actionId);
        if ($action) {
            foreach ($action->chain as $link) {
                $linkHistory = $bayesianAnalysis[$link->step] ?? [];
                $embeddingScores[$link->step] = $this->embeddingScorer->scoreLink(
                    $link,
                    $this->runtimeEvidence,
                    $linkHistory,
                );
            }
        }

        // Generate remediation plan for the failed step
        $remediationPlan = $this->aiHypothesis->generateRemediationPlan(
            [
                'module' => $this->moduleId,
                'action' => $actionId,
                'breakpoint' => $breakpoint,
                'break_category' => $breakCategory,
                'deterministic' => $deterministicResult,
                'diagnosis' => ['primary_classification' => $classification],
                'confidence' => ['score' => 0, 'label' => 'none'],
            ],
            $this->runtimeEvidence,
            $sourceContext,
        );

        // AI-proposed missing chain links
        $proposedLinks = $this->aiHypothesis->proposeMissingLinks(
            $anomalies,
            $this->runtimeEvidence,
        );

        // Overall confidence (updated with embedding scores)
        $confidence = $this->computeOverallConfidence(
            $deterministicResult,
            $embeddingScores,
            $temporalAnalysis,
            $classification,
            $breakpoint,
        );

        return [
            'module' => $this->moduleId,
            'action' => $actionId,
            'engine_version' => '3.0-ai-enhanced',
            'breakpoint' => $breakpoint,
            'break_category' => $breakCategory,
            'deterministic' => $deterministicResult,
            'semantic' => [
                'per_link_scores' => $semanticScores,
                'embedding_scores' => $embeddingScores,
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
            'ai_hypothesis' => [
                'summary' => $aiHypothesis->summary,
                'confidence' => $aiHypothesis->confidence,
                'severity' => $aiHypothesis->severity,
                'files_to_inspect' => $aiHypothesis->filesToInspect,
                'proposed_test' => $aiHypothesis->proposedTest,
                'do_not_change_boundary' => $aiHypothesis->doNotChangeBoundary,
                'suggested_links' => $aiHypothesis->suggestedLinks,
            ],
            'remediation_plan' => $remediationPlan !== null ? [
                'failing_step' => $remediationPlan->failingStep,
                'suspected_file' => $remediationPlan->suspectedFile,
                'invariant_violated' => $remediationPlan->invariantViolated,
                'fix_sketch' => $remediationPlan->fixSketch,
                'test_command' => $remediationPlan->testCommand,
                'risk_level' => $remediationPlan->riskLevel,
                'related_files' => $remediationPlan->relatedFiles,
            ] : null,
            'proposed_chain_links' => $proposedLinks,
            'source_context' => $sourceContext !== null ? [
                'step' => $sourceContext->step,
                'category' => $sourceContext->category,
                'handler_files' => $sourceContext->handlerFiles,
                'template_files' => $sourceContext->templateFiles,
                'route_files' => $sourceContext->routeInfo,
                'migration_files' => $sourceContext->migrationFiles,
            ] : null,
            'coverage_score' => $coverageScore,
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
     *
     * @param string $actionId The action to build the packet for
     * @param array|null $analysis Pre-computed analysis result (avoids duplicate Bayesian recording)
     */
    public function buildEvidencePacket(string $actionId, ?array $analysis = null): array
    {
        $analysis = $analysis ?? $this->analyze($actionId, recordHistory: false);
        $graph = $this->deterministic->buildGraph();
        $reportCard = $this->generateReportCard($actionId, $analysis);
        $similarCases = $this->caseMemory->findSimilar(
            $this->moduleId,
            $actionId,
            $this->runtimeEvidence,
        );

        return [
            'module' => $graph,
            'analysis' => $analysis,
            'report_card' => $reportCard,
            'runtime' => $this->runtimeEvidence,
            'timestamps' => $this->timestamps,
            'bayesian_history' => $this->bayesian->actionHistory($this->moduleId, $actionId),
            'similar_cases' => array_map(fn($c) => [
                'id' => $c['case']->id,
                'summary' => $c['case']->summary,
                'fix_summary' => $c['case']->fixSummary,
                'changed_files' => $c['case']->changedFiles,
                'similarity' => $c['similarity'],
            ], $similarCases),
            'case_memory_stats' => $this->caseMemory->stats(),
            'generated_at' => date('c'),
            'engine_version' => '3.0-ai-enhanced',
        ];
    }

    /**
     * Generate an actionable report card with root cause, timeline, and fix suggestions.
     *
     * @param string $actionId The action to generate a report for
     * @param array|null $analysis Pre-computed analysis (avoids duplicate Bayesian recording)
     */
    public function generateReportCard(string $actionId, ?array $analysis = null): array
    {
        $analysis = $analysis ?? $this->analyze($actionId, recordHistory: false);
        return $this->aiHypothesis->generateReportCard($analysis, $this->runtimeEvidence);
    }

    /**
     * Store a successful fix outcome as a case memory entry.
     *
     * @param string $actionId The action that was failing (e.g. 'pal.job-order.submit')
     * @param string $summary Human-readable bug description
     * @param array $changedFiles Files modified to fix the bug
     * @param string $fixSummary Description of what was changed
     * @param string $testCommand The test that validates the fix
     * @param array $tags Optional tags for similarity matching
     * @return string The case ID
     */
    public function storeCaseMemory(
        string $actionId,
        string $summary,
        array $changedFiles,
        string $fixSummary,
        string $testCommand = '',
        array $tags = [],
    ): string {
        $caseId = 'case-' . $this->moduleId . '-' . substr(md5($actionId . $summary . implode(',', $changedFiles) . date('c')), 0, 12);

        $this->caseMemory->store(new \Ikabud\Kernel\Workbench\Comprehension\Contracts\CaseMemoryEntry(
            id: $caseId,
            moduleId: $this->moduleId,
            actionId: $actionId,
            summary: $summary,
            evidencePacket: $this->runtimeEvidence,
            changedFiles: $changedFiles,
            testCommand: $testCommand,
            fixSummary: $fixSummary,
            createdAt: date('c'),
            tags: $tags,
        ));

        return $caseId;
    }

    /**
     * List all stored cases for this module.
     */
    public function listCases(): array
    {
        return $this->caseMemory->listByModule($this->moduleId);
    }

    /**
     * Get case memory stats.
     */
    public function caseMemoryStats(): array
    {
        return $this->caseMemory->stats();
    }

    /**
     * Find similar cases to the current evidence.
     */
    public function findSimilarCases(string $actionId = '', int $maxResults = 5): array
    {
        return $this->caseMemory->findSimilar($this->moduleId, $actionId, $this->runtimeEvidence, $maxResults);
    }

    /**
     * Score provider coverage against current evidence.
     */
    public function scoreCoverage(): ?array
    {
        if ($this->provider === null) {
            return null;
        }
        return $this->coverageScorer->score($this->provider, $this->runtimeEvidence);
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
        array $embeddingScores,
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

        // Embedding score quality (NLP-enhanced)
        if (!empty($embeddingScores)) {
            $avgScore = array_sum(array_map(fn($s) => $s['score'] ?? 0, $embeddingScores)) / count($embeddingScores);
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

        // AI hypothesis confidence (from Layer 7)
        $factors['ai_confidence'] = 0.5; // neutral baseline

        // Weighted average
        $weights = ['coverage' => 0.20, 'semantic_quality' => 0.20, 'temporal_order' => 0.12,
                     'classification' => 0.18, 'has_breakpoint' => 0.15, 'ai_confidence' => 0.15];

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
