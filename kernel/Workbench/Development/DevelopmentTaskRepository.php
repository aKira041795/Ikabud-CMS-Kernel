<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Development;

/**
 * Filesystem-backed Development Task repository for the Workbench Development
 * Control Plane. Task records live below <root>/<task-id>/ with immutable
 * contract revisions and append-only lifecycle events. Per-task locks plus
 * checked temporary-file/atomic-rename publication protect concurrent writers.
 */
final class DevelopmentTaskRepository
{
    private const ID_PATTERN = '/^[A-Za-z0-9._-]+$/';
    private const REV_PATTERN = '/^[a-f0-9]{8,64}$/';

    private readonly string $indexPath;
    private readonly string $lockPath;

    public function __construct(private readonly string $root)
    {
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException("Unable to create development task repository: {$root}");
        }
        $this->indexPath = $root . '/index.json';
        $this->lockPath = $root . '/index.lock';
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Create a durable task from a normalized architecture contract.
     * Re-importing unchanged architecture is idempotent (matched by source hash).
     *
     * @param array<string,mixed> $contract
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function createTask(array $contract, array $actor, array $options = []): array
    {
        $contract = DevelopmentTaskContract::normalizeParsed($contract);
        $sourceHash = (string) ($contract['source_hash'] ?? '');
        if ($sourceHash === '') {
            throw new \InvalidArgumentException('Contract source hash required');
        }

        $existing = $this->findBySourceHash($sourceHash);
        if ($existing !== null) {
            return [
                'ok' => true,
                'idempotent' => true,
                'created' => false,
                'task_id' => $existing,
                'task' => $this->getTask($existing),
                'revision' => $this->getTask($existing)['contract_revision'] ?? '',
            ];
        }

        $taskId = (string) ($options['task_id'] ?? '');
        if ($taskId === '' || preg_match(self::ID_PATTERN, $taskId) !== 1) {
            $taskId = 'task-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        }

        return $this->withIndexLock(function () use ($taskId, $contract, $actor, $options, $sourceHash): array {
            if ($this->hasTask($taskId)) {
                throw new \InvalidArgumentException("Task already exists: {$taskId}");
            }
            // Idempotency re-check under the index lock: a concurrent writer may
            // have created the same source hash between the pre-check and the lock.
            $raced = $this->findBySourceHash($sourceHash);
            if ($raced !== null) {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'created' => false,
                    'task_id' => $raced,
                    'task' => $this->getTask($raced),
                    'revision' => $this->getTask($raced)['contract_revision'] ?? '',
                ];
            }

            $revision = DevelopmentTaskContract::revisionId($contract);
            $now = gmdate(DATE_ATOM);
            $dir = $this->taskDir($taskId);

            $this->mkdir($dir . '/revisions');
            $this->mkdir($dir . '/events');

            $this->atomic($dir . '/revisions/' . $revision . '.json', [
                'schema' => 'ark.workbench-development-revision.v1',
                'schema_version' => '1.0',
                'task_id' => $taskId,
                'revision' => $revision,
                'contract' => $contract,
                'created_at' => $now,
                'actor' => $actor,
            ]);

            $this->atomic($dir . '/events/1.json', [
                'schema' => 'ark.workbench-development-event.v1',
                'schema_version' => '1.0',
                'event_id' => bin2hex(random_bytes(8)),
                'task_id' => $taskId,
                'sequence' => 1,
                'timestamp' => $now,
                'actor' => $actor,
                'prior_state' => DevelopmentLifecycle::ARCHITECTING,
                'new_state' => DevelopmentLifecycle::READY_FOR_IMPLEMENTATION,
                'reason' => 'Architecture contract imported and ready for implementation',
                'evidence' => [],
                'scope' => ['approved_changed' => [], 'unexpected_changed' => []],
            ]);

            $task = [
                'schema' => 'ark.workbench-development-task.v1',
                'schema_version' => '1.0',
                'task_id' => $taskId,
                'state' => DevelopmentLifecycle::READY_FOR_IMPLEMENTATION,
                'objective' => $contract['objective'],
                'approved_scope' => [
                    'allowed' => $contract['allowed_scope'] ?? [],
                    'forbidden' => $contract['forbidden_scope'] ?? [],
                ],
                'actual_scope' => [],
                // Approved baseline: pre-existing working-tree changes captured at
                // import so task-attributable scope can be separated from it. The
                // per-path content hashes allow implement to detect baseline files
                // that were modified after import (P1-3).
                'baseline' => [
                    'changed_paths' => array_values(array_unique(array_map(
                        'strval',
                        (array) ($options['baseline']['changed_paths'] ?? [])
                    ))),
                    'covered_paths' => array_values(array_unique(array_map(
                        'strval',
                        (array) ($options['baseline']['covered_paths'] ?? [])
                    ))),
                    'hashes' => (array) ($options['baseline']['hashes'] ?? []),
                    'captured_at' => $now,
                ],
                'contract_revision' => $revision,
                'source' => [
                    'path' => (string) ($options['source_path'] ?? '.ai/current-task.md'),
                    'hash' => $sourceHash,
                    'imported_at' => $now,
                ],
                'actor' => $actor,
                'verification' => ['status' => 'NOT_RUN', 'layers' => []],
                'review' => ['status' => 'not_reviewed', 'findings' => []],
                'release' => ['gate_artifact' => null, 'decision' => null, 'blockers' => []],
                'created_at' => $now,
                'updated_at' => $now,
                'sequence' => 1,
            ];

            $this->atomic($dir . '/task.json', $task);

            // Update the index inside the already-held index lock (no re-lock).
            $index = $this->index();
            $index[$taskId] = $this->indexRow($task);
            ksort($index);
            $this->writeIndex($index);

            return [
                'ok' => true,
                'idempotent' => false,
                'created' => true,
                'task_id' => $taskId,
                'task' => $task,
                'revision' => $revision,
            ];
        });
    }

    /**
     * Revise the architecture for a task that is still ready for implementation.
     * A changed architecture creates a new immutable revision and event; it cannot
     * silently replace the revision bound to active implementation.
     *
     * @param array<string,mixed> $contract
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function reviseArchitecture(string $taskId, array $contract, array $actor): array
    {
        $this->assertId($taskId);
        $contract = DevelopmentTaskContract::normalizeParsed($contract);
        $newRevision = DevelopmentTaskContract::revisionId($contract);

        return $this->withTaskLock($taskId, function (array $task) use ($contract, $actor, $newRevision, $taskId): array {
            if ($task['state'] !== DevelopmentLifecycle::READY_FOR_IMPLEMENTATION) {
                return [
                    'ok' => false,
                    'reason' => 'Architecture revision rejected: task is not ready for implementation (state=' . $task['state'] . ')',
                    'task_id' => $task['task_id'],
                ];
            }
            if ($newRevision === $task['contract_revision']) {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'task_id' => $task['task_id'],
                    'revision' => $newRevision,
                    'task' => $task,
                ];
            }

            $now = gmdate(DATE_ATOM);
            $dir = $this->taskDir($taskId);
            $this->mkdir($dir . '/revisions');

            $this->atomic($dir . '/revisions/' . $newRevision . '.json', [
                'schema' => 'ark.workbench-development-revision.v1',
                'schema_version' => '1.0',
                'task_id' => $taskId,
                'revision' => $newRevision,
                'contract' => $contract,
                'created_at' => $now,
                'actor' => $actor,
            ]);

            $seq = (int) ($task['sequence'] ?? 0) + 1;
            $event = [
                'schema' => 'ark.workbench-development-event.v1',
                'schema_version' => '1.0',
                'event_id' => bin2hex(random_bytes(8)),
                'task_id' => $taskId,
                'sequence' => $seq,
                'timestamp' => $now,
                'actor' => $actor,
                'prior_state' => $task['state'],
                'new_state' => $task['state'],
                'reason' => 'Architecture revised to revision ' . $newRevision,
                'evidence' => ['revision:' . $newRevision],
                'scope' => ['approved_changed' => [], 'unexpected_changed' => []],
            ];
            $this->atomic($dir . '/events/' . $seq . '.json', $event);

            $task['contract_revision'] = $newRevision;
            $task['source'] = [
                'path' => (string) ($task['source']['path'] ?? '.ai/current-task.md'),
                'hash' => (string) ($contract['source_hash'] ?? ''),
                'imported_at' => $now,
            ];
            // A revised architecture must also become the enforced scope so a
            // scope-changing revision is honored (not just recorded).
            $task['approved_scope'] = [
                'allowed' => $contract['allowed_scope'] ?? [],
                'forbidden' => $contract['forbidden_scope'] ?? [],
            ];
            $task['updated_at'] = $now;
            $task['sequence'] = $seq;
            $this->atomic($dir . '/task.json', $task);
            $this->updateIndex($taskId, $this->indexRow($task));

            return [
                'ok' => true,
                'idempotent' => false,
                'task_id' => $taskId,
                'revision' => $newRevision,
                'task' => $task,
                'event' => $event,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function getTask(string $taskId): array
    {
        $this->assertId($taskId);
        $value = $this->readJson($this->taskDir($taskId) . '/task.json');
        if ($value === null) {
            throw new \RuntimeException("Task not found: {$taskId}");
        }

        return $value;
    }

    /** @return array<string,mixed> */
    public function getRevision(string $taskId, string $revision): array
    {
        $this->assertId($taskId);
        if (preg_match(self::REV_PATTERN, $revision) !== 1) {
            throw new \InvalidArgumentException("Invalid revision id: {$revision}");
        }
        $value = $this->readJson($this->taskDir($taskId) . '/revisions/' . $revision . '.json');
        if ($value === null) {
            throw new \RuntimeException("Revision not found: {$revision}");
        }

        return $value;
    }

    /** @return list<array<string,mixed>> */
    public function timeline(string $taskId): array
    {
        $this->assertId($taskId);
        $eventsDir = $this->taskDir($taskId) . '/events';
        $events = [];
        if (is_dir($eventsDir)) {
            $files = glob($eventsDir . '/*.json') ?: [];
            foreach ($files as $file) {
                $value = $this->readJson($file);
                if (is_array($value)) {
                    $events[] = $value;
                }
            }
        }
        usort(
            $events,
            static fn(array $a, array $b): int => (int) ($a['sequence'] ?? 0) <=> (int) ($b['sequence'] ?? 0)
        );

        return $events;
    }

    /**
     * Phase 2: resolve an evidence citation by id against the task's durable
     * evidence projection (and, as a fallback, the append-only timeline event
     * evidence). Returns the matching evidence entry or null when the citation
     * is not present. Citations are verifiable by id; fabricated/cross-task
     * references fail closed.
     *
     * @return array{kind:string,ref:string,hash:string}|null
     */
    public function resolveEvidence(string $taskId, string $ref): ?array
    {
        $this->assertId($taskId);
        $task = $this->getTask($taskId);

        foreach ((array) ($task['evidence'] ?? []) as $entry) {
            if (is_array($entry) && (string) ($entry['ref'] ?? '') === $ref) {
                return [
                    'kind' => (string) ($entry['kind'] ?? 'artifact'),
                    'ref' => $ref,
                    'hash' => (string) ($entry['hash'] ?? ''),
                ];
            }
        }
        // Fallback: timeline event evidence may carry `ref` or `ref#hash`.
        foreach ($this->timeline($taskId) as $event) {
            foreach ((array) ($event['evidence'] ?? []) as $entry) {
                if (is_string($entry) && ($entry === $ref || str_starts_with($entry, $ref . '#'))) {
                    return ['kind' => 'timeline', 'ref' => $ref, 'hash' => ''];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,string> $filters
     * @return list<array<string,mixed>>
     */
    public function listTasks(array $filters = []): array
    {
        $rows = array_values($this->index());
        $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            foreach ($filters as $key => $value) {
                if ((string) ($row[$key] ?? '') !== (string) $value) {
                    return false;
                }
            }
            return true;
        }));
        usort(
            $rows,
            static fn(array $a, array $b): int => ((string) ($b['updated_at'] ?? '')) <=> ((string) ($a['updated_at'] ?? ''))
        );

        return $rows;
    }

    /**
     * Execute an allowed state transition under the per-task lock. Fails closed
     * when the transition is not allow-listed or release prerequisites fail.
     *
     * @param array<string,mixed> $context reason|evidence|scope|projection keys
     * @return array<string,mixed>
     */
    public function transition(string $taskId, string $toState, array $context = []): array
    {
        $this->assertId($taskId);

        return $this->withTaskLock($taskId, function (array $task) use ($toState, $context, $taskId): array {
            // Preview the incoming projection so release prerequisites see the
            // stage result's release-gate, review, and verification evidence.
            $preview = $task;
            foreach ((array) ($context['projection'] ?? []) as $key => $value) {
                if (in_array($key, ['actual_scope', 'verification', 'review', 'release', 'evidence'], true)) {
                    $preview[$key] = $value;
                }
            }
            $lifecycle = DevelopmentLifecycle::transition(
                $task['state'],
                $toState,
                $preview,
                ($context['git_resolver'] ?? null) instanceof GitEvidenceResolver
                    ? $context['git_resolver']
                    : null
            );
            if (!$lifecycle['ok']) {
                return [
                    'ok' => false,
                    'task_id' => $taskId,
                    'state' => $task['state'],
                    'new_state' => $toState,
                    'reason' => $lifecycle['reason'],
                    'blockers' => $lifecycle['blockers'],
                ];
            }

            $now = gmdate(DATE_ATOM);
            $seq = (int) ($task['sequence'] ?? 0) + 1;
            $scope = (array) ($context['scope'] ?? []);
            $event = [
                'schema' => 'ark.workbench-development-event.v1',
                'schema_version' => '1.0',
                'event_id' => bin2hex(random_bytes(8)),
                'task_id' => $taskId,
                'sequence' => $seq,
                'timestamp' => $now,
                'actor' => (array) ($context['actor'] ?? $task['actor'] ?? []),
                'prior_state' => $task['state'],
                'new_state' => $toState,
                'reason' => (string) ($context['reason'] ?? 'Stage result ingested'),
                'evidence' => (array) ($context['evidence'] ?? []),
                'scope' => [
                    'approved_changed' => (array) ($scope['approved_changed'] ?? []),
                    'unexpected_changed' => (array) ($scope['unexpected_changed'] ?? []),
                ],
            ];

            $task['state'] = $toState;
            $task['updated_at'] = $now;
            $task['sequence'] = $seq;
            foreach ((array) ($context['projection'] ?? []) as $key => $value) {
                if (in_array($key, ['actual_scope', 'verification', 'review', 'release', 'evidence', 'actor', 'git'], true)) {
                    $task[$key] = $value;
                }
            }

            $dir = $this->taskDir($taskId);
            // Append-only event first; if the projection write fails, remove the
            // orphan event so a failed write is never reported as persisted.
            $this->atomic($dir . '/events/' . $seq . '.json', $event);
            try {
                $this->atomic($dir . '/task.json', $task);
            } catch (\Throwable $e) {
                @unlink($dir . '/events/' . $seq . '.json');
                throw $e;
            }
            $this->updateIndex($taskId, $this->indexRow($task));

            return [
                'ok' => true,
                'task_id' => $taskId,
                'state' => $toState,
                'new_state' => $toState,
                'reason' => $event['reason'],
                'task' => $task,
                'event' => $event,
                'blockers' => [],
            ];
        });
    }

    /** @return array<string,mixed> */
    private function indexRow(array $task): array
    {
        return [
            'task_id' => $task['task_id'],
            'state' => $task['state'],
            'source_path' => (string) ($task['source']['path'] ?? ''),
            'source_hash' => (string) ($task['source']['hash'] ?? ''),
            'contract_revision' => (string) ($task['contract_revision'] ?? ''),
            'actor_role' => (string) ($task['actor']['role'] ?? ''),
            'created_at' => $task['created_at'],
            'updated_at' => $task['updated_at'],
            'sequence' => $task['sequence'],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function index(): array
    {
        if (!is_file($this->indexPath)) {
            return [];
        }
        $value = json_decode((string) file_get_contents($this->indexPath), true);
        if (!is_array($value)) {
            // Fail closed: a corrupt index must surface as repository corruption,
            // never as an empty (healthy-looking) task list.
            throw new \RuntimeException("Development task index is corrupt: {$this->indexPath}");
        }

        return $value;
    }

    private function updateIndex(string $taskId, array $row): void
    {
        $this->withIndexLock(function () use ($taskId, $row): void {
            $index = $this->index();
            $index[$taskId] = $row;
            ksort($index);
            $this->writeIndex($index);
        });
    }

    /** @param array<string,array<string,mixed>> $index */
    private function writeIndex(array $index): void
    {
        $this->atomic($this->indexPath, $index);
    }

    private function findBySourceHash(string $sourceHash): ?string
    {
        foreach ($this->index() as $taskId => $row) {
            if ((string) ($row['source_hash'] ?? '') === $sourceHash) {
                return $taskId;
            }
        }

        return null;
    }

    public function findBySourcePath(string $sourcePath): ?string
    {
        foreach ($this->index() as $taskId => $row) {
            if ((string) ($row['source_path'] ?? '') === $sourcePath) {
                return $taskId;
            }
        }

        return null;
    }

    private function hasTask(string $taskId): bool
    {
        return is_file($this->taskDir($taskId) . '/task.json');
    }

    private function taskDir(string $taskId): string
    {
        return $this->root . '/' . $taskId;
    }

    private function assertId(string $taskId): void
    {
        if ($taskId === '.' || $taskId === '..' || preg_match(self::ID_PATTERN, $taskId) !== 1) {
            throw new \InvalidArgumentException("Invalid task id: {$taskId}");
        }
    }

    /**
     * @template T
     * @param callable(array<string,mixed>):T $callback
     * @return T
     */
    private function withTaskLock(string $taskId, callable $callback): mixed
    {
        $dir = $this->taskDir($taskId);
        $this->mkdir($dir);
        $lockPath = $dir . '/.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open task lock: {$lockPath}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to acquire task lock: {$lockPath}");
            }

            $task = $this->readJson($dir . '/task.json');
            if (!is_array($task)) {
                throw new \RuntimeException("Task not found: {$taskId}");
            }

            return $callback($task);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withIndexLock(callable $callback): mixed
    {
        $handle = fopen($this->lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open development index lock: {$this->lockPath}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to acquire development index lock: {$this->lockPath}");
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create directory: {$dir}");
        }
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $value = json_decode((string) file_get_contents($path), true);

        return is_array($value) ? $value : null;
    }

    /** @param array<string,mixed> $value */
    private function atomic(string $path, array $value): void
    {
        $this->mkdir(dirname($path));
        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write temporary artifact: {$tmp}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to publish artifact: {$path}");
        }
    }
}
