<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Analyzers;

/**
 * Layer 3: Bayesian Failure History Reasoner.
 *
 * Uses Beta-Binomial conjugate prior to model per-action per-link
 * failure probability. Each link's history is tracked as (successes, failures).
 * Prior is Beta(1,1) (uniform), posterior is Beta(1+s, 1+f).
 *
 * This lets the engine say:
 *   "Link 3 has failed 4/5 times historically → prior reliability = 0.2"
 *   "Even without evidence, we expect this link to fail with 80% probability"
 *
 * Storage: JSON file per module in storage/private/comprehension/history/
 */
class BayesianReasoner
{
    private string $storagePath;
    private array $cache = [];

    private const PRIOR_ALPHA = 1.0;
    private const PRIOR_BETA = 1.0;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? $this->defaultPath();
        $this->ensureStorage();
    }

    /**
     * Compute the posterior probability that a link will FAIL.
     * Returns 0.0–1.0 where higher = more likely to fail.
     */
    public function priorFailureProbability(string $moduleId, string $actionId, string $step): float
    {
        $record = $this->getRecord($moduleId, $actionId, $step);

        // Beta-Binomial posterior mean for failure = Beta(alpha+failures, beta+successes)
        $alpha = self::PRIOR_ALPHA + ($record['failures'] ?? 0);
        $beta = self::PRIOR_BETA + ($record['successes'] ?? 0);

        // Expected value of Beta distribution
        return round($alpha / ($alpha + $beta), 4);
    }

    /**
     * Compute the posterior probability that a link will SUCCEED.
     */
    public function priorSuccessProbability(string $moduleId, string $actionId, string $step): float
    {
        return round(1.0 - $this->priorFailureProbability($moduleId, $actionId, $step), 4);
    }

    /**
     * Record an outcome for a chain link with metadata.
     *
     * @param array $metadata Optional context (run_id, commit, tenant, test_source)
     */
    public function recordOutcome(string $moduleId, string $actionId, string $step, bool $succeeded, array $metadata = []): void
    {
        $record = $this->getRecord($moduleId, $actionId, $step);
        $field = $succeeded ? 'successes' : 'failures';
        $record[$field]++;
        $record['last_seen'] = date('c');
        $record['total'] = ($record['successes'] ?? 0) + ($record['failures'] ?? 0);

        // Preserve run history (last 20 runs)
        $record['runs'] = $record['runs'] ?? [];
        $record['runs'][] = [
            'succeeded' => $succeeded,
            'recorded_at' => date('c'),
            'run_id' => $metadata['run_id'] ?? null,
            'commit' => $metadata['commit'] ?? null,
            'tenant' => $metadata['tenant'] ?? null,
            'source' => $metadata['source'] ?? 'cli',
        ];
        if (count($record['runs']) > 20) {
            array_shift($record['runs']);
        }

        $this->storeRecord($moduleId, $actionId, $step, $record);
    }

    /**
     * Reset history for a specific action (all its links).
     */
    public function resetAction(string $moduleId, string $actionId): void
    {
        $file = $this->actionFile($moduleId, $actionId);
        if (file_exists($file)) {
            unlink($file);
        }
        unset($this->cache[$file]);
    }

    /**
     * List all actions that have history across all modules.
     *
     * @return array<string, array<string>> Module → action IDs
     */
    public function listModules(): array
    {
        $modules = [];
        $glob = $this->storagePath . '/*';
        foreach (glob($glob, GLOB_ONLYDIR) ?: [] as $dir) {
            $moduleId = basename($dir);
            $files = glob($dir . '/*.json') ?: [];
            $modules[$moduleId] = array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), $files);
        }
        return $modules;
    }

    /**
     * Get the full failure history for an action (all links).
     *
     * @return array<int, array{step: string, successes: int, failures: int, total: int, failure_prob: float, last_seen: string}>
     */
    public function actionHistory(string $moduleId, string $actionId): array
    {
        $history = [];
        $file = $this->actionFile($moduleId, $actionId);

        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true) ?? [];
            foreach ($data as $step => $record) {
                $history[] = [
                    'step' => $step,
                    'successes' => $record['successes'] ?? 0,
                    'failures' => $record['failures'] ?? 0,
                    'total' => ($record['successes'] ?? 0) + ($record['failures'] ?? 0),
                    'failure_prob' => $this->priorFailureProbability($moduleId, $actionId, $step),
                    'last_seen' => $record['last_seen'] ?? 'never',
                ];
            }
        }

        return $history;
    }

    /**
     * Get summary stats for all actions in a module.
     *
     * @return array<string, array{total_links: int, total_runs: int, overall_failure_rate: float, most_unreliable_link: ?string}>
     */
    public function moduleSummary(string $moduleId): array
    {
        $summaries = [];
        $globPattern = $this->moduleGlob($moduleId);

        foreach (glob($globPattern) ?: [] as $file) {
            $actionId = pathinfo($file, PATHINFO_FILENAME);
            $data = json_decode(file_get_contents($file), true) ?? [];

            $totalRuns = 0;
            $totalFailures = 0;
            $worstLink = null;
            $worstProb = 0;

            foreach ($data as $step => $record) {
                $runs = ($record['successes'] ?? 0) + ($record['failures'] ?? 0);
                $totalRuns += $runs;
                $totalFailures += $record['failures'] ?? 0;

                $prob = $this->priorFailureProbability($moduleId, $actionId, $step);
                if ($prob > $worstProb) {
                    $worstProb = $prob;
                    $worstLink = $step;
                }
            }

            $summaries[$actionId] = [
                'total_links' => count($data),
                'total_runs' => $totalRuns,
                'overall_failure_rate' => $totalRuns > 0 ? round($totalFailures / $totalRuns, 4) : 0,
                'most_unreliable_link' => $worstLink,
            ];
        }

        return $summaries;
    }

    private function getRecord(string $moduleId, string $actionId, string $step): array
    {
        $file = $this->actionFile($moduleId, $actionId);
        if (isset($this->cache[$file])) {
            return $this->cache[$file][$step] ?? ['successes' => 0, 'failures' => 0];
        }

        if (file_exists($file)) {
            $this->cache[$file] = json_decode(file_get_contents($file), true) ?? [];
        } else {
            $this->cache[$file] = [];
        }

        return $this->cache[$file][$step] ?? ['successes' => 0, 'failures' => 0];
    }

    private function storeRecord(string $moduleId, string $actionId, string $step, array $record): void
    {
        $file = $this->actionFile($moduleId, $actionId);
        $this->cache[$file] ??= [];

        // Reload to avoid concurrent-write loss
        if (file_exists($file)) {
            $existing = json_decode(file_get_contents($file), true) ?? [];
        } else {
            $existing = [];
        }

        $existing[$step] = $record;
        $this->cache[$file][$step] = $record;

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT));
    }

    private function actionFile(string $moduleId, string $actionId): string
    {
        // Sanitize action ID for filename
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $actionId);
        return $this->storagePath . '/' . $moduleId . '/' . $safe . '.json';
    }

    private function moduleGlob(string $moduleId): string
    {
        return $this->storagePath . '/' . $moduleId . '/*.json';
    }

    private function defaultPath(): string
    {
        $base = defined('STORAGE_PATH') ? STORAGE_PATH : (__DIR__ . '/../../../../storage');
        return rtrim($base, '/') . '/private/comprehension/history';
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0777, true);
        }
    }
}
