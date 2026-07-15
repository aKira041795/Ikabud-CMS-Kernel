<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Contracts;

/**
 * AI-generated hypothesis about a test failure's root cause.
 */
class AiHypothesis
{
    /**
     * @param string $summary One-sentence root cause description
     * @param float $confidence 0.0–1.0 confidence in the hypothesis
     * @param string $severity error|warning|info
     * @param array<int, string> $filesToInspect Relevant source file paths
     * @param string $proposedTest Description of a test that would validate the fix
     * @param array<int, string> $doNotChangeBoundary Notes on what MUST NOT be changed
     * @param array<int, array{step: string, description: string, category: string, probe: ?string}> $suggestedLinks
     *        ChainLink additions that are missing from the provider
     * @param array<string, mixed> $raw Raw AI response data
     */
    public function __construct(
        public readonly string $summary,
        public readonly float $confidence = 0.0,
        public readonly string $severity = 'info',
        public readonly array $filesToInspect = [],
        public readonly string $proposedTest = '',
        public readonly array $doNotChangeBoundary = [],
        public readonly array $suggestedLinks = [],
        public readonly array $raw = [],
    ) {}
}

/**
 * A remediation plan for fixing a failed chain link.
 * Designed for HUMAN review — never auto-applied.
 */
class RemediationPlan
{
    /**
     * @param string $failingStep The chain link step that failed
     * @param string $suspectedFile The file most likely responsible
     * @param string $invariantViolated What invariant was broken
     * @param string $fixSketch Minimal description of the fix
     * @param string $testCommand Command to run to validate the fix
     * @param string $riskLevel low|medium|high — how risky is the change
     * @param array<int, string> $relatedFiles Other files that may need changes
     */
    public function __construct(
        public readonly string $failingStep,
        public readonly string $suspectedFile,
        public readonly string $invariantViolated,
        public readonly string $fixSketch,
        public readonly string $testCommand,
        public readonly string $riskLevel = 'medium',
        public readonly array $relatedFiles = [],
    ) {}
}

/**
 * A stored record of a successful fix for case-based reasoning.
 */
class CaseMemoryEntry
{
    /**
     * @param string $id Unique case identifier
     * @param string $moduleId Module where the fix was applied
     * @param string $actionId Action that was failing
     * @param string $summary Human-readable description of the bug
     * @param array $evidencePacket The evidence packet at time of failure
     * @param array<int, string> $changedFiles Files modified to fix the bug
     * @param string $testCommand The test that validates the fix
     * @param string $fixSummary Short description of what was changed
     * @param string $createdAt ISO date when the fix was applied
     * @param array $tags Keywords for similarity matching
     */
    public function __construct(
        public readonly string $id,
        public readonly string $moduleId,
        public readonly string $actionId,
        public readonly string $summary,
        public readonly array $evidencePacket = [],
        public readonly array $changedFiles = [],
        public readonly string $testCommand = '',
        public readonly string $fixSummary = '',
        public readonly string $createdAt = '',
        public readonly array $tags = [],
    ) {}
}

/**
 * Source context retrieved for a specific failure step.
 * Avoids dumping the whole repository into the AI prompt.
 */
class SourceContext
{
    /**
     * @param string $step The chain link step that triggered retrieval
     * @param string $category The category of the failed step
     * @param array<int, string> $handlerFiles Handler/service files relevant to this step
     * @param array<int, string> $templateFiles Template files relevant to this step
     * @param array<int, string> $routeInfo Route registration details
     * @param array<int, string> $migrationFiles Migration files relevant to this step
     * @param array<int, string> $logSnippets Recent log lines relevant to this step
     */
    public function __construct(
        public readonly string $step,
        public readonly string $category,
        public readonly array $handlerFiles = [],
        public readonly array $templateFiles = [],
        public readonly array $routeInfo = [],
        public readonly array $migrationFiles = [],
        public readonly array $logSnippets = [],
    ) {}
}
