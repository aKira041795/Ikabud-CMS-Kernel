<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Analyzers;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\{
    AiHypothesis,
    RemediationPlan,
    SourceContext,
    CaseMemoryEntry,
};

/**
 * Layer 7: AI Hypothesis Generator.
 *
 * Receives structured evidence from Layers 1-6 and uses heuristics to
 * generate hypotheses about the root cause, proposed fixes, and "do not
 * change" boundary notes.
 *
 * This layer does NOT have direct access to raw logs or the full repository.
 * It receives a compact, structured packet:
 *   - Action contract (the declared chain links)
 *   - Failed chain links (deterministic + semantic scores + temporal)
 *   - Top anomalies (from AnomalyDetector)
 *   - Relevant source file paths (from SourceRetriever)
 *   - Recent log snippets (filtered by category)
 *   - Bayesian history (prior failure probabilities)
 *   - Similar past cases (from CaseMemory)
 *
 * Current implementation: heuristic rules only. External AI provider
 * integration (Copilot, OpenAI) is planned behind a HypothesisProvider
 * interface but not yet implemented in PHP.
 */
class AiHypothesisGenerator
{
    private string $moduleId;
    private string $providerType; // 'copilot', 'openai', 'heuristic'
    private SourceRetriever $sourceRetriever;
    private CaseMemory $caseMemory;

    /** @var array<string, array{pattern: string, severity: string, suggestion: string}> */
    private array $heuristicRules = [];

    public function __construct(
        string $moduleId,
        ?SourceRetriever $sourceRetriever = null,
        ?CaseMemory $caseMemory = null,
        string $providerType = 'heuristic',
    ) {
        $this->moduleId = $moduleId;
        $this->sourceRetriever = $sourceRetriever ?? new SourceRetriever($moduleId);
        $this->caseMemory = $caseMemory ?? new CaseMemory();
        $this->providerType = $providerType;
        $this->initHeuristicRules();
    }

    /**
     * Generate an AI hypothesis from structured evidence.
     *
     * @param array $analysisResult The full analysis from Layer 1-6
     * @param array $evidence Runtime evidence
     * @param array $bayesianHistory Per-link historical data
     * @return AiHypothesis
     */
    public function generate(array $analysisResult, array $evidence, array $bayesianHistory = []): AiHypothesis
    {
        $breakpoint = $analysisResult['breakpoint'] ?? null;
        $breakCategory = $analysisResult['break_category'] ?? 'unknown';

        // 1. Retrieve source context for the failed step
        $sourceContext = null;
        if ($breakpoint !== null) {
            $sourceContext = $this->sourceRetriever->retrieve($breakpoint, $breakCategory);
        }

        // 2. Find similar past cases
        $similarCases = $this->caseMemory->findSimilar(
            $this->moduleId,
            $analysisResult['action'] ?? '',
            $evidence,
        );

        // 3. Generate hypothesis
        if ($this->providerType === 'heuristic' || !$this->hasAiProvider()) {
            $hypothesis = $this->generateHeuristic($analysisResult, $evidence, $bayesianHistory, $sourceContext, $similarCases);
        } else {
            $hypothesis = $this->generateWithAi($analysisResult, $evidence, $bayesianHistory, $sourceContext, $similarCases);
        }

        return $hypothesis;
    }

    /**
     * Generate a remediation plan for a failing step.
     *
     * @param array $analysisResult Full analysis
     * @param array $evidence Runtime evidence
     * @return RemediationPlan|null
     */
    public function generateRemediationPlan(array $analysisResult, array $evidence, ?SourceContext $sourceContext = null): ?RemediationPlan
    {
        $breakpoint = $analysisResult['breakpoint'] ?? null;
        $breakCategory = $analysisResult['break_category'] ?? null;

        if ($breakpoint === null) {
            return null; // No failure = no remediation needed
        }

        if ($sourceContext === null) {
            $sourceContext = $this->sourceRetriever->retrieve($breakpoint, $breakCategory ?? 'unknown');
        }

        $suspectedFile = $this->determineSuspectedFile($breakpoint, $breakCategory, $sourceContext, $analysisResult);
        $invariantViolated = $this->determineInvariantViolated($breakpoint, $breakCategory, $analysisResult);
        $riskLevel = $this->determineRiskLevel($breakCategory);

        $fixSketch = $this->generateFixSketch($breakpoint, $breakCategory, $invariantViolated, $evidence);

        $testCommand = $this->generateTestCommand($analysisResult['action'] ?? '', $evidence);

        return new RemediationPlan(
            failingStep: $breakpoint,
            suspectedFile: $suspectedFile,
            invariantViolated: $invariantViolated,
            fixSketch: $fixSketch,
            testCommand: $testCommand,
            riskLevel: $riskLevel,
            relatedFiles: $sourceContext->handlerFiles,
        );
    }

    /**
     * Generate an actionable report card from the analysis.
     */
    public function generateReportCard(array $analysisResult, array $evidence): array
    {
        $breakpoint = $analysisResult['breakpoint'] ?? null;
        $breakCategory = $analysisResult['break_category'] ?? 'unknown';
        $confidence = $analysisResult['confidence'] ?? ['score' => 0, 'label' => 'none'];
        $diagnosis = $analysisResult['diagnosis'] ?? [];
        $rootCause = $analysisResult['root_cause_hypothesis'] ?? [];

        $sourceContext = null;
        if ($breakpoint !== null) {
            $sourceContext = $this->sourceRetriever->retrieve($breakpoint, $breakCategory);
        }

        $remediation = $this->generateRemediationPlan($analysisResult, $evidence, $sourceContext);

        return [
            'root_cause' => [
                'summary' => $rootCause['summary'] ?? 'No failure detected',
                'severity' => $rootCause['severity'] ?? 'success',
                'confidence' => $confidence,
            ],
            'failing_causal_chain' => $this->buildCausalChainTimeline($analysisResult),
            'inspect_these_files' => $sourceContext !== null
                ? array_merge(
                    $sourceContext->handlerFiles,
                    $sourceContext->templateFiles,
                    $sourceContext->migrationFiles,
                )
                : [],
            'diagnosis' => [
                'primary' => $diagnosis['primary_classification']['category'] ?? 'unknown',
                'details' => $diagnosis['primary_classification']['diagnosis'] ?? '',
                'all_patterns' => $diagnosis['full_classification']['categories'] ?? [],
            ],
            'remediation' => $remediation !== null ? [
                'failing_step' => $remediation->failingStep,
                'suspected_file' => $remediation->suspectedFile,
                'invariant_violated' => $remediation->invariantViolated,
                'fix_sketch' => $remediation->fixSketch,
                'test_command' => $remediation->testCommand,
                'risk_level' => $remediation->riskLevel,
                'related_files' => $remediation->relatedFiles,
            ] : null,
            'suggested_next_test' => $this->suggestNextTest($analysisResult),
            'temporal_insights' => [
                'order_score' => $analysisResult['temporal']['order_score'] ?? 1.0,
                'violations' => $analysisResult['temporal']['violations'] ?? [],
                'timing_anomalies' => $analysisResult['temporal']['anomalies'] ?? [],
            ],
            'anomalies' => $analysisResult['anomalies'] ?? [],
            'cross_module' => $analysisResult['cross_module'] ?? [],
            'generated_at' => date('c'),
        ];
    }

    /**
     * Let AI propose missing chain links by reviewing evidence.
     *
     * @param array $anomalies Anomalies that suggest missing links
     * @param array $evidence Runtime evidence
     * @return array<int, array{step: string, description: string, category: string, probe: ?string}>
     */
    public function proposeMissingLinks(array $anomalies, array $evidence): array
    {
        $suggestions = [];

        // Check each anomaly for missing link patterns
        foreach ($anomalies as $anomaly) {
            $key = $anomaly['evidence_key'] ?? '';
            $value = $anomaly['evidence_value'] ?? '';

            if (is_string($value)) {
                $lower = mb_strtolower($value);

                // Audit pattern
                if (preg_match('/audit|logged|recorded|written/i', $lower)) {
                    $suggestions[] = [
                        'step' => 'audit.log',
                        'description' => 'Audit log entry created for the operation',
                        'category' => 'audit',
                        'probe' => null,
                    ];
                }

                // Event/trigger pattern
                if (preg_match('/event|trigger|fired|dispatch/i', $lower)) {
                    $suggestions[] = [
                        'step' => 'event.fire',
                        'description' => 'Event fired after the operation',
                        'category' => 'event',
                        'probe' => null,
                    ];
                }

                // Email pattern
                if (preg_match('/email|mail.*sent|notification/i', $lower)) {
                    $suggestions[] = [
                        'step' => 'email.send',
                        'description' => 'Email/notification sent after the operation',
                        'category' => 'event',
                        'probe' => null,
                    ];
                }

                // DB pattern (evidence of DB effect without declared step)
                if (preg_match('/insert|update|delete|row.*affected|query/i', $lower)) {
                    $suggestions[] = [
                        'step' => 'db.effect',
                        'description' => 'Database effect observed but not declared in chain',
                        'category' => 'db',
                        'probe' => null,
                    ];
                }
            }
        }

        return $suggestions;
    }

    /**
     * Heuristic hypothesis generation (no AI provider needed).
     */
    private function generateHeuristic(
        array $analysisResult,
        array $evidence,
        array $bayesianHistory,
        ?SourceContext $sourceContext,
        array $similarCases,
    ): AiHypothesis {
        $breakpoint = $analysisResult['breakpoint'] ?? null;
        $breakCategory = $analysisResult['break_category'] ?? 'unknown';
        $rootCause = $analysisResult['root_cause_hypothesis'] ?? [];
        $diagnosis = $analysisResult['diagnosis'] ?? [];
        $primaryClass = $diagnosis['primary_classification'] ?? [];

        if ($breakpoint === null) {
            // No deterministic failure — check for latent anomalies
            $anomalies = $analysisResult['anomalies']['unexpected_evidence'] ?? [];
            if (!empty($anomalies)) {
                return new AiHypothesis(
                    summary: 'No chain failure, but ' . count($anomalies) . ' anomaly(ies) detected that may indicate latent issues.',
                    confidence: 0.4,
                    severity: 'info',
                    filesToInspect: $sourceContext ? array_merge($sourceContext->handlerFiles, $sourceContext->templateFiles) : [],
                    proposedTest: 'Review flagged anomalies manually',
                    doNotChangeBoundary: ['No chain links are failing — investigate anomalies before making changes'],
                );
            }
            return new AiHypothesis(
                summary: $rootCause['summary'] ?? 'All chain links passed. No issues detected.',
                confidence: 0.9,
                severity: 'success',
                filesToInspect: [],
                proposedTest: '',
                doNotChangeBoundary: ['All checks pass — no changes needed'],
            );
        }

        // Build file list
        $filesToInspect = [];
        if ($sourceContext) {
            $filesToInspect = array_merge($sourceContext->handlerFiles, $sourceContext->templateFiles);
        }

        // Add Bayesian high-risk links
        $highRiskLinks = [];
        foreach ($bayesianHistory as $step => $stats) {
            if (is_array($stats) && ($stats['prior_failure_probability'] ?? 0) > 0.5) {
                $highRiskLinks[] = $step;
            }
        }

        // Suggest missing links from anomalies
        $missingLinks = $analysisResult['anomalies']['missing_links'] ?? [];
        $suggestedLinks = [];
        foreach ($missingLinks as $ml) {
            $suggestedLinks[] = [
                'step' => $ml['step_suggestion'],
                'description' => $ml['reason'],
                'category' => explode('.', $ml['step_suggestion'])[0] ?? 'unknown',
                'probe' => null,
            ];
        }

        // Similar cases hint
        $caseHint = '';
        if (!empty($similarCases)) {
            $best = $similarCases[0];
            $caseHint = " Similar to past fix '{$best['case']->summary}' (similarity: {$best['similarity']}).";
        }

        // Build severity
        $severity = $breakCategory === 'db' || $breakCategory === 'http' ? 'error' : 'warning';

        // Confidence from existing analysis
        $baseConfidence = $analysisResult['confidence']['score'] ?? 0.5;
        $confidence = min(0.95, $baseConfidence + (empty($similarCases) ? 0 : 0.1));

        return new AiHypothesis(
            summary: $rootCause['summary'] ?? "Failure at step '{$breakpoint}' ({$breakCategory})." . $caseHint,
            confidence: $confidence,
            severity: $severity,
            filesToInspect: $filesToInspect,
            proposedTest: $primaryClass['category'] === 'csrf'
                ? 'Run test with fresh page load (no cached CSRF token)'
                : ($primaryClass['category'] === 'db'
                    ? 'Verify table schema and constraints match migration SQL'
                    : "Debug the '{$breakpoint}' step and verify fix"),
            doNotChangeBoundary: $this->generateBoundaryNotes($breakCategory),
            suggestedLinks: $suggestedLinks,
            raw: [
                'breakpoint' => $breakpoint,
                'break_category' => $breakCategory,
                'diagnosis_category' => $primaryClass['category'] ?? 'unknown',
                'high_risk_links' => $highRiskLinks,
                'similar_cases_found' => count($similarCases),
            ],
        );
    }

    /**
     * AI-powered hypothesis generation (requires external AI provider).
     * Falls back to heuristic if provider unavailable.
     */
    private function generateWithAi(
        array $analysisResult,
        array $evidence,
        array $bayesianHistory,
        ?SourceContext $sourceContext,
        array $similarCases,
    ): AiHypothesis {
        // For environments without an AI API client in PHP,
        // fall back to heuristic. The aiDiagnose() function
        // in ArkTestSteward.js handles Copilot API integration.
        return $this->generateHeuristic($analysisResult, $evidence, $bayesianHistory, $sourceContext, $similarCases);
    }

    /**
     * Determine the most likely responsible file for a failure.
     */
    private function determineSuspectedFile(string $breakpoint, ?string $breakCategory, SourceContext $sourceContext, array $analysisResult): string
    {
        // If source retriever found handler files, use the first one
        if (!empty($sourceContext->handlerFiles)) {
            return $sourceContext->handlerFiles[0];
        }

        // If template files, use first template
        if (!empty($sourceContext->templateFiles)) {
            return $sourceContext->templateFiles[0];
        }

        // If migration files, use first migration
        if (!empty($sourceContext->migrationFiles)) {
            return $sourceContext->migrationFiles[0];
        }

        // Fallback based on category
        return match ($breakCategory) {
            'ui' => 'templates/modules/' . $this->moduleId . '/pages/ (unknown template)',
            'http' => 'modules/' . $this->moduleId . '/handlers/ (unknown handler)',
            'service' => 'modules/' . $this->moduleId . '/services/ (unknown service)',
            'db' => 'modules/' . $this->moduleId . '/database/migrations/ (unknown migration)',
            'event' => 'modules/' . $this->moduleId . '/handlers/ (event handler)',
            'audit' => 'kernel/ audit service',
            default => 'modules/' . $this->moduleId . '/ (unknown file)',
        };
    }

    /**
     * Determine what invariant was violated.
     */
    private function determineInvariantViolated(?string $breakpoint, ?string $breakCategory, array $analysisResult): string
    {
        $diagnosis = $analysisResult['diagnosis'] ?? [];

        // Check classification for hints
        $primaryClass = $diagnosis['primary_classification'] ?? [];
        $className = $primaryClass['category'] ?? '';

        if ($className === 'csrf') {
            return 'CSRF token must match session — page cache serving stale token to different session';
        }
        if ($className === 'permission') {
            return 'User must have required capability/role for the operation';
        }
        if ($className === 'validation') {
            return 'Input data must satisfy field constraints';
        }
        if ($className === 'missing_record') {
            return 'Referenced entity must exist in the database';
        }
        if ($className === 'db') {
            return 'Database operation must succeed (table exists, constraints met, no drift)';
        }

        return match ($breakCategory) {
            'ui' => 'UI element must be visible and interactive',
            'http' => 'HTTP response must indicate success (2xx)',
            'service' => 'Service operation must complete without exception',
            'db' => 'Database state must match expected after operation',
            'event' => 'Event must be fired and listeners must execute',
            'audit' => 'Audit log entry must be created',
            'verify' => 'Post-condition must be verifiable in the UI',
            default => 'Unknown invariant — manual inspection required',
        };
    }

    /**
     * Determine risk level of a fix based on category.
     */
    private function determineRiskLevel(?string $breakCategory): string
    {
        return match ($breakCategory) {
            'ui', 'verify' => 'low',
            'http' => 'medium',
            'service' => 'medium',
            'db' => 'high',
            'event', 'audit' => 'low',
            default => 'medium',
        };
    }

    /**
     * Generate a fix sketch description.
     */
    private function generateFixSketch(string $breakpoint, ?string $breakCategory, string $invariant, array $evidence): string
    {
        return match ($breakCategory) {
            'ui' => "Ensure '{$breakpoint}' element is present in the template and visible after render",
            'http' => "Check route handler for '{$breakpoint}' — verify request/response handling",
            'service' => "Review service logic for '{$breakpoint}' — {$invariant}",
            'db' => "Verify DB query and schema for '{$breakpoint}' — {$invariant}",
            'event' => "Check event listener registration for '{$breakpoint}'",
            'audit' => "Verify audit log configuration for '{$breakpoint}'",
            'verify' => "Check post-condition assertion for '{$breakpoint}'",
            default => "Investigate '{$breakpoint}' — {$invariant}",
        };
    }

    /**
     * Generate a test command based on the failure.
     */
    private function generateTestCommand(string $actionId, array $evidence): string
    {
        $tenantId = $evidence['_tenant_id'] ?? '{tenant}';
        $entityId = $evidence['_entity_id'] ?? '{entity}';

        return "php kernel/Workbench/Comprehension/run.php {$this->moduleId} {$actionId}" .
               " --tenant={$tenantId} --entity-id={$entityId}" .
               (isset($evidence['_entity_type']) ? " --entity-type={$evidence['_entity_type']}" : '');
    }

    /**
     * Build a causal chain timeline showing what happened in order.
     */
    private function buildCausalChainTimeline(array $analysisResult): array
    {
        $chain = $analysisResult['deterministic']['chain'] ?? [];
        $timeline = [];

        foreach ($chain as $link) {
            $timeline[] = [
                'step' => $link['step'],
                'category' => $link['category'],
                'description' => $link['description'],
                'status' => $link['ok'] ? 'passed' : 'failed',
            ];
        }

        return $timeline;
    }

    /**
     * Generate "do not change" boundary notes based on category.
     */
    private function generateBoundaryNotes(?string $breakCategory): array
    {
        return match ($breakCategory) {
            'db' => [
                'Do not change existing migration files — create a new migration if schema changes are needed',
                'Do not remove existing columns or change column types without a deprecation strategy',
            ],
            'http' => [
                'Do not change the route signature (path, method, parameter names) without updating all callers',
                'Do not remove CSRF protection from the route',
            ],
            'service' => [
                'Do not change the service method signature without updating all callers',
                'Do not bypass capability checks in the service layer',
            ],
            'ui' => [
                'Do not change the entity view contract — fix the template or presenter instead',
                'Do not remove accessibility attributes from UI elements',
            ],
            default => [
                'Verify the fix does not introduce breaking changes to the module contract',
            ],
        };
    }

    /**
     * Suggest the next test to run after a fix.
     */
    private function suggestNextTest(array $analysisResult): string
    {
        $breakpoint = $analysisResult['breakpoint'] ?? null;
        if ($breakpoint === null) {
            return 'Run the same test suite to confirm no regressions';
        }

        return match ($analysisResult['break_category'] ?? '') {
            'ui' => "After fixing {$breakpoint}, run the browser spec again and verify all UI assertions pass",
            'http' => "After fixing the handler, run the integration test and verify HTTP 200 response",
            'db' => "After fixing the DB issue, run the migration and verify the query returns expected results",
            'service' => "After fixing the service, run the unit test for the affected method",
            default => "Run the comprehension engine again with --run-id=<id> to verify the fix",
        };
    }

    /**
     * Initialize heuristic rules for quick diagnosis.
     */
    private function initHeuristicRules(): void
    {
        $this->heuristicRules = [
            'csrf' => [
                'pattern' => '/csrf|token.*mismatch|419.*expired/i',
                'severity' => 'error',
                'suggestion' => 'Check page cache — the cached HTML may contain a stale CSRF token for a different session',
            ],
            'db_drift' => [
                'pattern' => '/drift|missing.*column|table.*not.*exist/i',
                'severity' => 'error',
                'suggestion' => 'Run migrations for the tenant: php ikabud tenant:migrate <tenant_id> <module_id>',
            ],
            'permission' => [
                'pattern' => '/403|access.*denied|unauthorized|forbidden/i',
                'severity' => 'warning',
                'suggestion' => 'Verify user has the required capability for this action',
            ],
        ];
    }

    /**
     * Check if an AI provider is available.
     */
    private function hasAiProvider(): bool
    {
        return in_array($this->providerType, ['copilot', 'openai'], true)
            && function_exists('curl_init');
    }
}
