<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Issues;

use Ikabud\Kernel\Workbench\Runs\RunExporter;

/**
 * Renders actionable evidence-backed issue cards for developers.
 *
 * For every issue, renders one card containing:
 *   - classification, severity, deterministic gate impact
 *   - confidence and basis, exact evidence links
 *   - observed versus expected behavior, reproduction command
 *   - environment/fixture identity, suspected cause
 *   - recommended owner, next deterministic test
 *
 * UI requirements:
 *   - AI interpretation labeled as 'AI-assisted; evidence validated' or 'AI unavailable; deterministic fallback'
 *   - Release-blocking failures, non-blocking risks, and fixture/environment blocks clearly distinguished
 *   - Resolved provider/model shown without exposing credentials
 *   - JSON, JUnit, and SARIF exports from same canonical issue identities
 */
final class IssueCardRenderer
{
    public function __construct(
        private readonly ?RunExporter $exporter = null,
    ) {}

    /**
     * Render a single issue as an actionable card.
     *
     * @param array<string,mixed> $issue The issue data
     * @param array<string,mixed> $context Additional context (run_id, provenance, ai_result, etc.)
     * @return array<string,mixed>
     */
    public function render(array $issue, array $context = []): array
    {
        $provenance = (array) ($context['provenance'] ?? []);
        $aiResult = (array) ($context['ai_result'] ?? []);
        $classifier = new \Ikabud\Kernel\Workbench\Scenario\PrerequisiteClassifier();

        // Classify the issue
        $classification = $classifier->classify($issue);
        $classificationLabel = $classification['classification'] ?? 'confirmed-defect';

        // Severity-based impact
        $severity = (string) ($issue['severity'] ?? $classification['original_severity'] ?? 'major');
        $isReleaseBlocking = in_array($severity, ['critical', 'major'], true)
            && $classificationLabel === 'confirmed-defect';
        $isNonBlockingRisk = in_array($severity, ['minor', 'note'], true)
            || in_array($classificationLabel, ['false-positive', 'test-defect'], true);
        $isFixtureBlock = $classificationLabel === 'unmet-prerequisite';
        $isEnvironmentBlock = $classificationLabel === 'environment';

        // AI source label
        $aiSourceLabel = $this->aiSourceLabel($aiResult);

        // Deterministic gate impact
        $gateImpact = $isReleaseBlocking ? 'release-blocking' : ($isNonBlockingRisk ? 'non-blocking-risk' : 'informational');

        // Evidence links
        $evidenceLinks = $this->extractEvidenceLinks($issue, $context);
        $observedExpected = $this->extractObservedExpected($issue);

        // Build reproduction command
        $reproductionCommand = $this->buildReproductionCommand($issue, $context);

        // Environment/fixture identity
        $envIdentity = $this->buildEnvironmentIdentity($provenance, $context);

        // Suspected cause
        $suspectedCause = $this->determineSuspectedCause($issue, $classification, $aiResult);

        // Next deterministic test recommendation
        $nextTest = $this->determineNextTest($issue, $classification, $aiResult, $context);

        return [
            'issue_id' => (string) ($issue['fingerprint'] ?? $issue['id'] ?? ''),
            'classification' => $classificationLabel,
            'classification_detail' => $classification['basis'] ?? '',
            'severity' => $severity,
            'deterministic_gate_impact' => $gateImpact,
            'is_release_blocking' => $isReleaseBlocking,
            'is_non_blocking_risk' => $isNonBlockingRisk,
            'is_fixture_block' => $isFixtureBlock,
            'is_environment_block' => $isEnvironmentBlock,
            'confidence_and_basis' => [
                'classification_confidence' => $classification['basis'] ?? '',
                'ai_confidence' => $this->aiConfidence($aiResult),
                'ai_label' => $aiSourceLabel,
            ],
            'summary' => (string) ($issue['message'] ?? $issue['summary'] ?? $classification['summary'] ?? ''),
            'category' => (string) ($issue['category'] ?? $classification['original_category'] ?? 'unknown'),
            'evidence_links' => $evidenceLinks,
            'observed_vs_expected' => $observedExpected,
            'reproduction_command' => $reproductionCommand,
            'environment_identity' => $envIdentity,
            'suspected_cause' => $suspectedCause,
            'recommended_owner' => $this->determineOwner($issue, $classification, $context),
            'next_deterministic_test' => $nextTest,
            'exporter' => [
                'json' => $this->toJson($issue, $classification, $context),
                'junit' => $this->toJunit($issue, $classification, $context),
                'sarif' => $this->toSarif($issue, $classification, $context),
            ],
        ];
    }

    /**
     * Render multiple issues as a list of actionable cards.
     *
     * @param list<array<string,mixed>> $issues
     * @param array<string,mixed> $context
     * @return list<array<string,mixed>>
     */
    public function renderBatch(array $issues, array $context = []): array
    {
        return array_map(fn(array $issue): array => $this->render($issue, $context), $issues);
    }

    /**
     * Generate a summary report grouping cards by classification.
     *
     * @param list<array<string,mixed>> $cards
     * @return array<string,mixed>
     */
    public function summary(array $cards): array
    {
        $groups = [];
        foreach ($cards as $card) {
            $cls = $card['classification'] ?? 'unknown';
            $groups[$cls][] = $card;
        }

        $counts = [];
        $releaseBlockers = 0;
        $fixtureBlocks = 0;
        foreach ($groups as $cls => $group) {
            $counts[$cls] = count($group);
            foreach ($group as $card) {
                if ($card['is_release_blocking'] ?? false) $releaseBlockers++;
                if ($card['is_fixture_block'] ?? false) $fixtureBlocks++;
            }
        }

        return [
            'total_issues' => count($cards),
            'release_blocking' => $releaseBlockers,
            'fixture_blocks' => $fixtureBlocks,
            'non_blocking_risks' => count($cards) - $releaseBlockers - $fixtureBlocks,
            'by_classification' => $counts,
        ];
    }

    /** @param array<string,mixed> $aiResult */
    private function aiSourceLabel(array $aiResult): string
    {
        $trace = (array) ($aiResult['provider_trace'] ?? []);
        $fallback = $trace['fallback_reason'] ?? null;

        if ($fallback !== null && $fallback !== '') {
            return 'AI unavailable; deterministic fallback (' . $fallback . ')';
        }

        $provider = (string) ($trace['provider'] ?? '');
        if ($provider !== '' && $provider !== 'heuristic') {
            $model = (string) ($trace['model'] ?? '');
            return $model !== ''
                ? 'AI-assisted; evidence validated (provider: ' . $provider . ', model: ' . $model . ')'
                : 'AI-assisted; evidence validated (provider: ' . $provider . ')';
        }

        return 'AI unavailable; deterministic fallback';
    }

    /** @param array<string,mixed> $aiResult */
    private function aiConfidence(array $aiResult): ?float
    {
        $hypotheses = (array) ($aiResult['hypotheses'] ?? []);
        if ($hypotheses === []) return null;
        return (float) ($hypotheses[0]['confidence'] ?? 0);
    }

    /** @param array<string,mixed> $issue */
    private function extractEvidenceLinks(array $issue, array $context): array
    {
        $links = [];

        // Direct evidence links from the issue
        $evidenceLinks = (array) ($issue['evidence_links'] ?? $issue['references'] ?? []);
        foreach ($evidenceLinks as $link) {
            if (is_string($link)) {
                $links[] = ['type' => 'evidence', 'url' => $link];
            } elseif (is_array($link) && isset($link['url'])) {
                $links[] = $link;
            }
        }

        // Provenance links
        $provenance = (array) ($context['provenance'] ?? []);
        if (!empty($provenance['run_id'])) {
            $links[] = ['type' => 'run', 'id' => $provenance['run_id']];
        }

        return $links;
    }

    /** @param array<string,mixed> $issue */
    private function extractObservedExpected(array $issue): array
    {
        $expected = $issue['expected'] ?? null;
        $actual = $issue['actual'] ?? $issue['value'] ?? null;

        if ($expected === null && $actual === null) {
            return [];
        }

        return [
            'expected' => $expected,
            'actual' => $actual,
        ];
    }

    /** @param array<string,mixed> $issue */
    private function buildReproductionCommand(array $issue, array $context): string
    {
        $module = (string) ($issue['module_id'] ?? $issue['module'] ?? $context['module'] ?? '');
        $action = (string) ($issue['action_id'] ?? $issue['action'] ?? '');

        if ($module === '') return '';

        // Prefer the test command from context
        $testCommand = (string) ($context['test_command'] ?? '');
        if ($testCommand !== '') return $testCommand;

        // Build a default command
        $parts = ['php', 'ikabud', 'workbench:doctor', $module];
        if ($action !== '') {
            $parts[] = '--action=' . $action;
        }
        return implode(' ', $parts);
    }

    /** @param array<string,mixed> $provenance */
    private function buildEnvironmentIdentity(array $provenance, array $context): array
    {
        return [
            'run_id' => $provenance['run_id'] ?? $context['run_id'] ?? '',
            'git_sha' => $provenance['git_sha'] ?? '',
            'module_id' => $provenance['module_id'] ?? $context['module'] ?? '',
            'environment_fingerprint' => $provenance['environment_fingerprint'] ?? '',
            'tenant_identity' => $provenance['tenant_identity'] ?? [],
            'role_fixture_identity' => $provenance['role_fixture_identity'] ?? [],
            'ai_policy' => $provenance['ai_policy'] ?? [],
        ];
    }

    /** @param array<string,mixed> $issue */
    private function determineSuspectedCause(array $issue, array $classification, array $aiResult): string
    {
        // Prefer AI-determined cause
        $hypotheses = (array) ($aiResult['hypotheses'] ?? []);
        if ($hypotheses !== []) {
            $top = $hypotheses[0];
            return (string) ($top['summary'] ?? '');
        }

        // Fall back to heuristic
        $cls = $classification['classification'] ?? '';
        return match ($cls) {
            'confirmed-defect' => 'Deterministic failure: ' . ($classification['basis'] ?? ''),
            'unmet-prerequisite' => 'Missing scenario fixture: ' . ($classification['basis'] ?? ''),
            'environment' => 'Environment issue: ' . ($classification['basis'] ?? ''),
            default => $classification['summary'] ?? 'Unknown',
        };
    }

    /** @param array<string,mixed> $issue */
    private function determineNextTest(array $issue, array $classification, array $aiResult, array $context): ?array
    {
        // Prefer AI-recommended next tests
        $nextTests = (array) ($aiResult['next_tests'] ?? []);
        if ($nextTests !== []) {
            return [
                'source' => 'ai',
                'tests' => $nextTests,
            ];
        }

        $cls = $classification['classification'] ?? '';
        return match ($cls) {
            'confirmed-defect' => [
                'source' => 'deterministic',
                'tests' => [['id' => 'verify-fix-' . ($issue['fingerprint'] ?? 'issue')]],
            ],
            'unmet-prerequisite' => [
                'source' => 'deterministic',
                'tests' => [['id' => 'provide-fixture-' . ($classification['original_category'] ?? 'data')]],
            ],
            'environment' => [
                'source' => 'deterministic',
                'tests' => [['id' => 'retry-after-env-fix']],
            ],
            default => null,
        };
    }

    /** @param array<string,mixed> $issue */
    private function determineOwner(array $issue, array $classification, array $context): string
    {
        $owner = (string) ($context['recommended_owner'] ?? '');
        if ($owner !== '') return $owner;

        $module = (string) ($issue['module_id'] ?? $issue['module'] ?? $context['module'] ?? '');
        if ($module !== '') return 'module:' . $module;

        return 'unassigned';
    }

    /** @param array<string,mixed> $issue */
    private function toJson(array $issue, array $classification, array $context): string
    {
        $exporter = $this->exporter ?? new RunExporter();
        return $exporter->ark([
            'run_id' => $context['run_id'] ?? '',
            'module' => $context['module'] ?? '',
            'issues' => [$issue],
            'provenance' => $context['provenance'] ?? [],
        ]);
    }

    /** @param array<string,mixed> $issue */
    private function toJunit(array $issue, array $classification, array $context): string
    {
        $exporter = $this->exporter ?? new RunExporter();
        return $exporter->junit([
            'run_id' => $context['run_id'] ?? '',
            'module' => $context['module'] ?? '',
            'issues' => [$issue],
            'provenance' => $context['provenance'] ?? [],
        ]);
    }

    /** @param array<string,mixed> $issue */
    private function toSarif(array $issue, array $classification, array $context): string
    {
        $exporter = $this->exporter ?? new RunExporter();
        return $exporter->sarif([
            'run_id' => $context['run_id'] ?? '',
            'module' => $context['module'] ?? '',
            'issues' => [$issue],
            'provenance' => $context['provenance'] ?? [],
        ]);
    }
}
