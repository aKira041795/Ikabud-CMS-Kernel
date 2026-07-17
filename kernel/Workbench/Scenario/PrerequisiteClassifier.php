<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Scenario;

/**
 * Classifies test issues into distinct categories:
 *   - confirmed-defect: deterministic test failure with clear expected/actual mismatch
 *   - false-positive: test infrastructure issue, not a product bug
 *   - test-defect: the test itself is wrong
 *   - environment: infrastructure/environment issue (DB down, network, etc.)
 *   - unmet-prerequisite: missing scenario data/fixture
 *
 * Never collapses them into generic failures.
 */
final class PrerequisiteClassifier
{
    private const CLASSIFICATIONS = [
        'confirmed-defect',
        'false-positive',
        'test-defect',
        'environment',
        'unmet-prerequisite',
    ];

    /** @param array<string,mixed> $issue */
    public function classify(array $issue, ?ScenarioFixtureDeclaration $fixtureDecl = null): array
    {
        $issue = $this->normalize($issue);
        $classification = $this->determine($issue, $fixtureDecl);

        return [
            'classification' => $classification,
            'original_category' => $issue['category'] ?? 'unknown',
            'original_severity' => $issue['severity'] ?? 'note',
            'summary' => $issue['summary'] ?? 'Unknown issue',
            'evidence' => $this->extractEvidence($issue),
            'basis' => $this->classificationBasis($classification, $issue),
        ];
    }

    /** @param list<array<string,mixed>> $issues @return list<array<string,mixed>> */
    public function classifyBatch(array $issues, ?ScenarioFixtureDeclaration $fixtureDecl = null): array
    {
        return array_map(fn(array $issue): array => $this->classify($issue, $fixtureDecl), $issues);
    }

    /** @param list<array<string,mixed>> $classifications */
    public function counts(array $classifications): array
    {
        $counts = array_fill_keys(self::CLASSIFICATIONS, 0);
        foreach ($classifications as $c) {
            $cls = $c['classification'] ?? 'unmet-prerequisite';
            if (isset($counts[$cls])) {
                $counts[$cls]++;
            }
        }
        return $counts;
    }

    /** @param array<string,mixed> $issue */
    private function normalize(array $issue): array
    {
        return [
            'run_id' => (string) ($issue['run_id'] ?? ''),
            'module_id' => (string) ($issue['module_id'] ?? $issue['module'] ?? ''),
            'action_id' => (string) ($issue['action_id'] ?? $issue['action'] ?? ''),
            'step_id' => (string) ($issue['step_id'] ?? $issue['step'] ?? $issue['failing_node'] ?? ''),
            'category' => (string) ($issue['category'] ?? $issue['kind'] ?? 'unknown'),
            'severity' => (string) ($issue['severity'] ?? 'major'),
            'summary' => (string) ($issue['summary'] ?? $issue['detail'] ?? $issue['message'] ?? ''),
            'expected' => $issue['expected'] ?? null,
            'actual' => $issue['actual'] ?? $issue['value'] ?? null,
            'outcome' => (string) ($issue['outcome'] ?? ''),
            'source' => (string) ($issue['source'] ?? ''),
            'evidence_links' => (array) ($issue['evidence_links'] ?? $issue['references'] ?? []),
        ];
    }

    /** @param array<string,mixed> $issue */
    private function determine(array $issue, ?ScenarioFixtureDeclaration $fixtureDecl = null): string
    {
        // 1. Environment issues
        $envPatterns = [
            '/connection refused/i', '/could not connect/i', '/timeout.*database/i',
            '/no such host/i', '/failed to open stream/i', '/disk full/i',
            '/out of memory/i', '/max execution time/i', '/MaxRetryError/i',
            '/connection reset/i', '/network (unreachable|error)/i',
        ];
        foreach ($envPatterns as $pattern) {
            if (preg_match($pattern, $issue['summary'])) {
                return 'environment';
            }
        }

        // 2. Test infrastructure issues
        $testInfraPatterns = [
            '/test harness (error|failed)/i', '/bootstrap (error|failed)/i',
            '/fixture (load|setup) (error|failed)/i', '/TestHarness.*error/i',
        ];
        foreach ($testInfraPatterns as $pattern) {
            if (preg_match($pattern, $issue['summary'])) {
                return 'test-defect';
            }
        }

        // 3. Unmet prerequisite — missing data
        // Deterministic: provider returned 'blocked', seed failed, or entity count 0
        if ($issue['outcome'] === 'unobserved') {
            return 'unmet-prerequisite';
        }
        if (in_array($issue['category'], ['scenario', 'fixture', 'seed'], true)) {
            return 'unmet-prerequisite';
        }
        if (preg_match('/no (record|data|entity|result)s? found/i', $issue['summary'])) {
            return 'unmet-prerequisite';
        }
        if (preg_match('/fixture.*(missing|unavailable|not found)/i', $issue['summary'])) {
            return 'unmet-prerequisite';
        }

        // Check fixture declaration for entity-level unmets
        if ($fixtureDecl !== null) {
            $validation = $fixtureDecl->validate();
            $entityTypes = array_column($validation['normalized']['required_entities'] ?? [], 'type');
            foreach ($entityTypes as $type) {
                if ($type !== '' && stripos($issue['step_id'], $type) !== false && $issue['outcome'] !== 'passed') {
                    return 'unmet-prerequisite';
                }
            }
        }

        // 4. False positive — test infrastructure detected issue but not a product bug
        if (in_array($issue['category'], ['false-positive', 'flake', 'timeout'], true)) {
            return 'false-positive';
        }

        // 5. Confirmed defect — deterministic failure with clear expected vs actual
        if ($issue['expected'] !== null && $issue['actual'] !== null && $issue['expected'] !== $issue['actual']) {
            return 'confirmed-defect';
        }
        if ($issue['outcome'] === 'failed') {
            return 'confirmed-defect';
        }

        // 6. Default: test-defect if test-related, otherwise confirmed-defect
        if (in_array($issue['category'], ['test', 'assertion'], true)) {
            return 'test-defect';
        }

        return 'confirmed-defect';
    }

    /** @param array<string,mixed> $issue */
    private function extractEvidence(array $issue): array
    {
        $evidence = [];
        if ($issue['expected'] !== null) {
            $evidence['expected'] = $issue['expected'];
        }
        if ($issue['actual'] !== null) {
            $evidence['actual'] = $issue['actual'];
        }
        if ($issue['evidence_links'] !== []) {
            $evidence['links'] = $issue['evidence_links'];
        }
        return $evidence;
    }

    private function classificationBasis(string $classification, array $issue): string
    {
        return match ($classification) {
            'confirmed-defect' => 'Deterministic test failure: expected=' . json_encode($issue['expected'] ?? '?') . ', actual=' . json_encode($issue['actual'] ?? '?'),
            'unmet-prerequisite' => 'Required scenario fixture not available: ' . $issue['step_id'],
            'false-positive' => 'Test infrastructure issue, not a product defect: ' . $issue['summary'],
            'test-defect' => 'The test itself has a defect: ' . $issue['summary'],
            'environment' => 'Environment/infrastructure issue: ' . $issue['summary'],
            default => 'Unclassified',
        };
    }
}
