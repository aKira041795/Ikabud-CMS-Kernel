<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Comprehension\Analyzers;

use Ikabud\Kernel\Workbench\Comprehension\Contracts\CaseMemoryEntry;

/**
 * Case Memory — stores and retrieves successful fix outcomes.
 *
 * After a bug is fixed, the original evidence packet, changed files,
 * and fix notes are stored. Future diagnoses can query similar cases
 * to find analogous fixes faster.
 *
 * Storage: JSON file per case in storage/private/comprehension/cases/
 * Index: memory-index.json for fast lookup by tags, module, action.
 */
class CaseMemory
{
    private string $storagePath;
    private string $indexPath;
    private string $lockPath;
    private ?array $indexCache = null;

    private const MAX_CASES = 100;
    private const MAX_TAGS = 10;

    public function __construct(?string $storagePath = null)
    {
        $base = $storagePath ?? $this->defaultPath();
        $this->storagePath = $base . '/cases';
        $this->indexPath = $base . '/cases/index.json';
        $this->lockPath = $base . '/cases/index.lock';
        $this->ensureStorage();
    }

    /**
     * Store a new case entry atomically with index locking.
     *
     * @throws \RuntimeException on write or rename failure
     */
    public function store(CaseMemoryEntry $entry): void
    {
        $file = $this->caseFile($entry->id);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tmp = $file . '.' . getmypid() . '.tmp';
        $written = file_put_contents($tmp, json_encode([
            'id' => $entry->id,
            'module_id' => $entry->moduleId,
            'action_id' => $entry->actionId,
            'summary' => $entry->summary,
            'evidence_packet' => $entry->evidencePacket,
            'changed_files' => $entry->changedFiles,
            'test_command' => $entry->testCommand,
            'fix_summary' => $entry->fixSummary,
            'created_at' => $entry->createdAt ?: date('c'),
            'tags' => array_slice($entry->tags, 0, self::MAX_TAGS),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if ($written === false) {
            throw new \RuntimeException("Unable to write case file: {$tmp}");
        }

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to commit case file: {$file}");
        }

        $this->updateIndex($entry);
    }

    /**
     * Retrieve a case by ID.
     */
    public function retrieve(string $id): ?CaseMemoryEntry
    {
        $file = $this->caseFile($id);
        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data) {
            return null;
        }

        return new CaseMemoryEntry(
            id: $data['id'],
            moduleId: $data['module_id'],
            actionId: $data['action_id'],
            summary: $data['summary'],
            evidencePacket: $data['evidence_packet'] ?? [],
            changedFiles: $data['changed_files'] ?? [],
            testCommand: $data['test_command'] ?? '',
            fixSummary: $data['fix_summary'] ?? '',
            createdAt: $data['created_at'] ?? '',
            tags: $data['tags'] ?? [],
        );
    }

    /**
     * Find similar cases by module, action, and text similarity.
     *
     * @param string $moduleId Current module
     * @param string $actionId Current action (or empty for any)
     * @param array $evidencePacket Current evidence for comparison
     * @param int $maxResults Max cases to return
     * @return array<int, array{case: CaseMemoryEntry, similarity: float}>
     */
    public function findSimilar(string $moduleId, string $actionId = '', array $evidencePacket = [], int $maxResults = 5): array
    {
        $index = $this->getIndex();
        $candidates = [];

        foreach ($index as $entry) {
            // Same module = strong signal
            if ($entry['module_id'] !== $moduleId) {
                continue;
            }

            // Same action = very strong signal
            $actionMatch = $actionId !== '' && $entry['action_id'] === $actionId;

            // Evidence similarity (if available)
            $evidenceSim = 0.0;
            if (!empty($evidencePacket) && !empty($entry['evidence_packet'])) {
                $evidenceSim = $this->computeEvidenceSimilarity(
                    $evidencePacket,
                    $entry['evidence_packet']
                );
            }

            $similarity = $actionMatch ? 0.5 + ($evidenceSim * 0.5) : $evidenceSim;

            if ($similarity > 0.1) {
                $case = $this->retrieve($entry['id']);
                if ($case !== null) {
                    $candidates[] = [
                        'case' => $case,
                        'similarity' => round($similarity, 4),
                    ];
                }
            }
        }

        // Sort by similarity descending
        usort($candidates, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($candidates, 0, $maxResults);
    }

    /**
     * List all stored cases for a module.
     *
     * @return array<int, array{id: string, action_id: string, summary: string, created_at: string}>
     */
    public function listByModule(string $moduleId): array
    {
        $index = $this->getIndex();
        return array_values(array_filter($index, fn($e) => $e['module_id'] === $moduleId));
    }

    /**
     * Get summary statistics.
     *
     * @return array{total_cases: int, modules: array<string, int>, oldest: ?string, newest: ?string}
     */
    public function stats(): array
    {
        $index = $this->getIndex();

        $modules = [];
        $dates = [];
        foreach ($index as $entry) {
            $mod = $entry['module_id'] ?? 'unknown';
            $modules[$mod] = ($modules[$mod] ?? 0) + 1;
            $dates[] = $entry['created_at'] ?? '';
        }

        sort($dates);

        return [
            'total_cases' => count($index),
            'modules' => $modules,
            'oldest' => $dates[0] ?? null,
            'newest' => $dates[count($dates) - 1] ?? null,
        ];
    }

    /**
     * Delete a case and atomically update the index.
     * Forces disk reload under lock to prevent stale-cache overwrites.
     *
     * @throws \RuntimeException if index locking fails
     */
    public function delete(string $id): bool
    {
        $file = $this->caseFile($id);
        if (!file_exists($file)) {
            return false;
        }

        unlink($file);

        // Atomically update index under lock — reload from disk first
        $this->withIndexLock(function () use ($id): void {
            $index = $this->loadIndexForWriteUnlocked();
            $index = array_values(array_filter(
                $index,
                fn (array $entry): bool => ($entry['id'] ?? '') !== $id
            ));
            $this->indexCache = $index;
            $this->saveIndexUnlocked();
        });

        return true;
    }

    /**
     * Compute similarity between two evidence packets.
     * Uses key overlap + value text similarity.
     */
    private function computeEvidenceSimilarity(array $a, array $b): float
    {
        $keysA = array_keys($a);
        $keysB = array_keys($b);

        if (empty($keysA) || empty($keysB)) {
            return 0.0;
        }

        // Key overlap
        $keyIntersection = array_intersect($keysA, $keysB);
        $keyUnion = array_unique(array_merge($keysA, $keysB));
        $keyJaccard = count($keyUnion) > 0 ? count($keyIntersection) / count($keyUnion) : 0;

        // For shared keys, compare string values
        $valueScore = 0.0;
        $valueCount = 0;
        foreach ($keyIntersection as $key) {
            $valA = is_string($a[$key]) ? $a[$key] : json_encode($a[$key]);
            $valB = is_string($b[$key]) ? $b[$key] : json_encode($b[$key]);
            if (is_string($valA) && is_string($valB)) {
                // Simple string equality for now
                if ($valA === $valB) {
                    $valueScore += 1.0;
                } else {
                    // Partial match via common words
                    $wordsA = explode(' ', $valA);
                    $wordsB = explode(' ', $valB);
                    $common = array_intersect($wordsA, $wordsB);
                    $valueScore += count($common) / max(1, count(array_unique(array_merge($wordsA, $wordsB))));
                }
                $valueCount++;
            }
        }

        $avgValueScore = $valueCount > 0 ? $valueScore / $valueCount : 0;

        // Weighted: keys matter more than values
        return ($keyJaccard * 0.6) + ($avgValueScore * 0.4);
    }

    /**
     * Get the case index (read-only — no lock needed).
     */
    private function getIndex(): array
    {
        return $this->loadIndexUnlocked();
    }

    /**
     * Update the index with a new entry. Protected by shared index lock.
     * Forces disk reload before mutation to prevent stale-cache overwrites.
     */
    private function updateIndex(CaseMemoryEntry $entry): void
    {
        $this->withIndexLock(function () use ($entry): void {
            $index = $this->loadIndexForWriteUnlocked();

            // Remove existing entry with same ID
            $index = array_values(array_filter($index, fn($e) => ($e['id'] ?? '') !== $entry->id));

            $index[] = [
                'id' => $entry->id,
                'module_id' => $entry->moduleId,
                'action_id' => $entry->actionId,
                'summary' => $entry->summary,
                'evidence_packet' => $entry->evidencePacket,
                'created_at' => $entry->createdAt ?: date('c'),
            ];

            // Trim to max
            if (count($index) > self::MAX_CASES) {
                usort($index, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
                $index = array_slice($index, 0, self::MAX_CASES);
            }

            $this->indexCache = $index;
            $this->saveIndexUnlocked();
        });
    }

    /**
     * Execute a closure while holding the exclusive index lock.
     *
     * @throws \RuntimeException if the lock cannot be acquired
     */
    private function withIndexLock(callable $fn): void
    {
        $handle = fopen($this->lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open case-memory lock: {$this->lockPath}");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException("Unable to acquire case-memory lock: {$this->lockPath}");
        }

        try {
            $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Load index from disk, ignoring in-memory cache.
     * MUST be used inside withIndexLock for write operations to prevent
     * a stale process-local cache from overwriting another process's commit.
     */
    private function loadIndexForWriteUnlocked(): array
    {
        $this->indexCache = null; // Force disk reload
        return $this->loadIndexUnlocked();
    }

    /**
     * Load index from disk or cache (read-only — ok for listing/search).
     */
    private function loadIndexUnlocked(): array
    {
        if ($this->indexCache !== null) {
            return $this->indexCache;
        }

        if (file_exists($this->indexPath)) {
            $raw = file_get_contents($this->indexPath);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $this->indexCache = $decoded;
                    return $this->indexCache;
                }
            }
        }

        // Rebuild from case files
        $this->indexCache = [];
        $files = glob($this->storagePath . '/*.json') ?: [];
        foreach ($files as $file) {
            if (basename($file) === 'index.json' || basename($file) === 'index.lock') continue;
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data) && isset($data['id'])) {
                $this->indexCache[] = [
                    'id' => $data['id'],
                    'module_id' => $data['module_id'] ?? 'unknown',
                    'action_id' => $data['action_id'] ?? '',
                    'summary' => $data['summary'] ?? '',
                    'evidence_packet' => $data['evidence_packet'] ?? [],
                    'created_at' => $data['created_at'] ?? '',
                ];
            }
        }

        // Trim to max
        if (count($this->indexCache) > self::MAX_CASES) {
            usort($this->indexCache, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
            $this->indexCache = array_slice($this->indexCache, 0, self::MAX_CASES);
        }

        return $this->indexCache;
    }

    /**
     * Save index to disk with atomic rename (caller must hold lock).
     *
     * @throws \RuntimeException on write or rename failure
     */
    private function saveIndexUnlocked(): void
    {
        $dir = dirname($this->indexPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $this->indexPath . '.' . getmypid() . '.tmp';
        $written = file_put_contents(
            $tmp,
            json_encode($this->indexCache ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        if ($written === false) {
            throw new \RuntimeException("Unable to write case-memory index: {$tmp}");
        }
        if (!rename($tmp, $this->indexPath)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to commit case-memory index: {$this->indexPath}");
        }
    }

    private function caseFile(string $id): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $id);
        return $this->storagePath . '/' . $safe . '.json';
    }

    private function defaultPath(): string
    {
        $base = defined('STORAGE_PATH') ? STORAGE_PATH : (__DIR__ . '/../../../../storage');
        return rtrim($base, '/') . '/private/comprehension';
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
        if (!is_dir(dirname($this->indexPath))) {
            @mkdir(dirname($this->indexPath), 0755, true);
        }
    }
}
