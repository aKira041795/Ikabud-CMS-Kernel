<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Evidence;

/** Converts legacy, browser-bridge, and v1 evidence into lossless observations. */
final class EvidenceNormalizer
{
    private const OUTCOMES = ['passed', 'failed', 'unobserved', 'not_applicable', 'probe_error', 'skipped'];

    /** @return array<int, array<string, mixed>> */
    public function normalize(array $payload, string $moduleId, string $defaultAction = 'unknown', ?string $runId = null): array
    {
        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
        $runId = $runId ?: (string)($meta['run_id'] ?? 'run-' . date('YmdHis'));
        $observations = [];

        if (is_array($payload['observations'] ?? null)) {
            foreach ($payload['observations'] as $raw) {
                if (is_array($raw)) $observations[] = $this->make($raw, $moduleId, $defaultAction, $runId, count($observations) + 1);
            }
            return $observations;
        }

        if (is_array($payload['steps'] ?? null)) {
            foreach ($payload['steps'] as $raw) {
                if (!is_array($raw)) continue;
                $raw['outcome'] ??= $this->outcomeFrom($raw);
                $observations[] = $this->make($raw, $moduleId, $defaultAction, $runId, count($observations) + 1);
            }
            return $observations;
        }

        foreach ($payload as $step => $value) {
            if (str_starts_with((string)$step, '_')) continue;
            $observations[] = $this->make([
                'step' => (string)$step,
                'actual' => $value,
                'outcome' => $this->outcomeFrom(['value' => $value]),
                'source' => 'php_test',
            ], $moduleId, $defaultAction, $runId, count($observations) + 1);
        }
        return $observations;
    }

    /** @return array<string, array<string, mixed>> */
    public function evidenceForAction(array $observations, string $actionId): array
    {
        $result = [];
        foreach ($observations as $observation) {
            if (($observation['action_id'] ?? '') !== $actionId) continue;
            $step = (string)$observation['step_id'];
            $outcome = (string)$observation['outcome'];
            $result[$step] = match ($outcome) {
                'passed' => $observation['actual'] ?? true,
                'failed' => $observation['actual'] ?? false,
                default => ['__workbench_outcome' => $outcome, 'detail' => $observation['detail'] ?? ''],
            };
        }
        return $result;
    }

    private function make(array $raw, string $moduleId, string $defaultAction, string $runId, int $attempt): array
    {
        $action = trim((string)($raw['action_id'] ?? $raw['action'] ?? $defaultAction)) ?: $defaultAction;
        $step = trim((string)($raw['step_id'] ?? $raw['step'] ?? 'unknown')) ?: 'unknown';
        $outcome = (string)($raw['outcome'] ?? $this->outcomeFrom($raw));
        if (!in_array($outcome, self::OUTCOMES, true)) $outcome = 'unobserved';
        $source = (string)($raw['source'] ?? 'browser');
        $idMaterial = [$runId, $moduleId, $action, $step, $attempt, $outcome, $raw['detail'] ?? ''];
        return [
            'schema_version' => '1.0',
            'observation_id' => (string)($raw['observation_id'] ?? 'obs-' . substr(hash('sha256', json_encode($idMaterial)), 0, 24)),
            'run_id' => $runId,
            'module_id' => $moduleId,
            'action_id' => $action,
            'step_id' => $step,
            'outcome' => $outcome,
            'source' => $source,
            'observed_at' => (string)($raw['observed_at'] ?? $raw['timestamp'] ?? date('c')),
            'attempt' => max(1, (int)($raw['attempt'] ?? $attempt)),
            'expected' => $raw['expected'] ?? true,
            'actual' => $raw['actual'] ?? $raw['value'] ?? $raw['success'] ?? null,
            'detail' => (string)($raw['detail'] ?? ''),
            'severity' => (string)($raw['severity'] ?? 'none'),
            'references' => array_values(array_unique(array_map('strval', (array)($raw['references'] ?? [])))),
            'redaction' => (string)($raw['redaction'] ?? 'internal'),
            'metadata' => is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [],
        ];
    }

    private function outcomeFrom(array $raw): string
    {
        if (isset($raw['success'])) return $raw['success'] ? 'passed' : 'failed';
        $value = $raw['value'] ?? $raw['actual'] ?? null;
        if ($value === true || $value === 1 || $value === '1') return 'passed';
        if ($value === false || $value === 0 || $value === '0') return 'failed';
        return $value === null ? 'unobserved' : 'passed';
    }
}
