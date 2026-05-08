<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Experiments;

/**
 * DiSyL 4.3 — Experiment Bucketer (file-backed v1).
 *
 * Deterministic A/B variant assignment with sticky bucketing and basic
 * exposure / conversion tracking persisted as JSON on disk under
 * `storage/cache/disyl-experiments/`.
 *
 * Determinism:
 *   bucket = int( first 16 hex chars of sha256(experimentId ':' subjectId), 16 )
 *            % total_weight
 * — required for SSR stability across servers.
 *
 * Exposure dedupe is *per (experiment, subject, requestId)* in-process; the
 * same render only emits one exposure even if the experiment block appears
 * twice.
 *
 * Out of scope (queued for 4.3.1):
 *   - DB-backed multi-tenant tables (`disyl_experiments`, …)
 *   - Cookie-based subject-id resolution from request cycle
 *   - Stopped/paused experiment status
 */
final class Bucketer
{
    private string $root;

    /** @var array<string, true> */
    private array $exposureSeen = [];

    public function __construct(?string $root = null)
    {
        $this->root = $root
            ?? (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../../../storage')
                . '/cache/disyl-experiments';
        if (!is_dir($this->root)) @mkdir($this->root, 0775, true);
    }

    /**
     * Assign a sticky variant for (experiment, subject) given a weights map.
     *
     * @param array<string,int> $weights variant-name → weight
     */
    public function assign(string $experimentId, string $subjectId, array $weights): string
    {
        if ($weights === []) {
            throw new \InvalidArgumentException('DISYL_EXP_ZERO_WEIGHT');
        }
        $total = array_sum($weights);
        if ($total <= 0) {
            throw new \InvalidArgumentException('DISYL_EXP_ZERO_WEIGHT');
        }

        $bucket = hexdec(substr(hash('sha256', $experimentId . ':' . $subjectId), 0, 15)) % $total;
        $cursor = 0;
        foreach ($weights as $variant => $w) {
            $cursor += $w;
            if ($bucket < $cursor) {
                $this->recordAssignment($experimentId, $subjectId, $variant);
                return $variant;
            }
        }
        // Fallback (shouldn't happen with positive total)
        $names = array_keys($weights);
        return $names[0];
    }

    public function expose(string $experimentId, string $subjectId, string $requestId, string $variant): void
    {
        $dedupe = $experimentId . '|' . $subjectId . '|' . $requestId;
        if (isset($this->exposureSeen[$dedupe])) return;
        $this->exposureSeen[$dedupe] = true;
        $this->append('exposures', [
            'experiment' => $experimentId,
            'subject'    => $subjectId,
            'request'    => $requestId,
            'variant'    => $variant,
            'at'         => time(),
        ]);
    }

    public function convert(string $experimentId, string $subjectId, string $goal): bool
    {
        $variant = $this->lookupAssignment($experimentId, $subjectId);
        if ($variant === null) {
            // No prior assignment — silently ignored per spec; caller may log.
            return false;
        }
        $this->append('conversions', [
            'experiment' => $experimentId,
            'subject'    => $subjectId,
            'variant'    => $variant,
            'goal'       => $goal,
            'at'         => time(),
        ]);
        return true;
    }

    public function lookupAssignment(string $experimentId, string $subjectId): ?string
    {
        $assignments = $this->loadAssignments();
        $key = $experimentId . '|' . $subjectId;
        return $assignments[$key] ?? null;
    }

    public function reset(): void
    {
        foreach (['assignments.json', 'exposures.json', 'conversions.json'] as $f) {
            @unlink($this->root . '/' . $f);
        }
        $this->exposureSeen = [];
    }

    private function recordAssignment(string $experimentId, string $subjectId, string $variant): void
    {
        $assignments = $this->loadAssignments();
        $key = $experimentId . '|' . $subjectId;
        if (isset($assignments[$key])) return;
        $assignments[$key] = $variant;
        @file_put_contents($this->root . '/assignments.json', json_encode($assignments), LOCK_EX);
    }

    /** @return array<string,string> */
    private function loadAssignments(): array
    {
        $f = $this->root . '/assignments.json';
        if (!is_file($f)) return [];
        $raw = @file_get_contents($f);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $row */
    private function append(string $kind, array $row): void
    {
        $f = $this->root . '/' . $kind . '.json';
        $rows = [];
        if (is_file($f)) {
            $raw = @file_get_contents($f);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) $rows = $decoded;
        }
        $rows[] = $row;
        @file_put_contents($f, json_encode($rows), LOCK_EX);
    }

    /** @return list<array<string,mixed>> */
    public function readEvents(string $kind): array
    {
        $f = $this->root . '/' . $kind . '.json';
        if (!is_file($f)) return [];
        $raw = @file_get_contents($f);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}
