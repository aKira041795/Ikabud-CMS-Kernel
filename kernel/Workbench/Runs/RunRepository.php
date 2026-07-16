<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Runs;

final class RunRepository
{
    private readonly string $indexPath;
    private readonly string $lockPath;

    public function __construct(private readonly string $root)
    {
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException("Unable to create run repository: {$root}");
        }

        $this->indexPath = $root . '/index.json';
        $this->lockPath = $root . '/index.lock';
    }

    /** @param array<string,mixed> $run */
    public function save(array $run): string
    {
        $id = (string) ($run['run_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
            throw new \RuntimeException('Invalid run id');
        }

        $run['schema'] = $run['schema'] ?? 'ark.workbench-run.v1';
        $run['recorded_at'] = $run['recorded_at'] ?? gmdate(DATE_ATOM);
        $this->atomic($this->root . '/runs/' . $id . '.json', $run);

        $this->withIndexLock(function () use ($id, $run): void {
            $index = $this->index();
            $index[$id] = $this->summary($run);
            ksort($index);
            $this->atomic($this->indexPath, $index);
        });

        return $id;
    }

    /** @return array<string,mixed> */
    public function get(string $id): array
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
            throw new \RuntimeException('Invalid run id');
        }

        $path = $this->root . '/runs/' . $id . '.json';
        $value = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($value)) {
            throw new \RuntimeException("Run not found: {$id}");
        }

        return $value;
    }

    /** @param array<string,string> $filters @return list<array<string,mixed>> */
    public function query(array $filters = []): array
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
            static fn(array $a, array $b): int =>
                ((string) ($b['recorded_at'] ?? '')) <=> ((string) ($a['recorded_at'] ?? ''))
        );

        return $rows;
    }

    /** @return array<string,mixed> */
    public function compare(string $leftId, string $rightId): array
    {
        $left = $this->get($leftId);
        $right = $this->get($rightId);
        $leftIssues = array_column((array) ($left['issues'] ?? []), null, 'fingerprint');
        $rightIssues = array_column((array) ($right['issues'] ?? []), null, 'fingerprint');

        return [
            'left' => $leftId,
            'right' => $rightId,
            'new' => array_values(array_diff(array_keys($rightIssues), array_keys($leftIssues))),
            'resolved' => array_values(array_diff(array_keys($leftIssues), array_keys($rightIssues))),
            'persistent' => array_values(array_intersect(array_keys($leftIssues), array_keys($rightIssues))),
            'contract_changed' => ($left['contract_digest'] ?? null) !== ($right['contract_digest'] ?? null),
        ];
    }

    /** Raw runs older than cutoff expire; indexed summaries remain queryable. */
    public function expireRawArtifacts(\DateTimeImmutable $cutoff): int
    {
        return $this->withIndexLock(function () use ($cutoff): int {
            $count = 0;
            $index = $this->index();

            foreach ($index as $id => &$summary) {
                $time = new \DateTimeImmutable((string) ($summary['recorded_at'] ?? '@0'));
                $path = $this->root . '/runs/' . $id . '.json';
                if ($time < $cutoff && is_file($path)) {
                    unlink($path);
                    $summary['raw_expired'] = true;
                    $count++;
                }
            }
            unset($summary);

            $this->atomic($this->indexPath, $index);
            return $count;
        });
    }

    /** @return array<string,array<string,mixed>> */
    private function index(): array
    {
        $value = is_file($this->indexPath)
            ? json_decode((string) file_get_contents($this->indexPath), true)
            : [];

        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    private function summary(array $run): array
    {
        return [
            'run_id' => $run['run_id'],
            'module' => $run['module'] ?? '',
            'commit' => $run['commit'] ?? '',
            'tenant' => $run['tenant'] ?? '',
            'role' => $run['role'] ?? '',
            'browser' => $run['browser'] ?? '',
            'environment' => $run['environment'] ?? '',
            'outcome' => $run['outcome'] ?? 'unknown',
            'issue_count' => count((array) ($run['issues'] ?? [])),
            'recorded_at' => $run['recorded_at'],
        ];
    }

    /** @template T @param callable():T $callback @return T */
    private function withIndexLock(callable $callback): mixed
    {
        $handle = fopen($this->lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open run-index lock: {$this->lockPath}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to acquire run-index lock: {$this->lockPath}");
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function atomic(string $path, array $value): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create run directory: {$dir}");
        }

        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $encoded = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write temporary run artifact: {$tmp}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to publish run artifact: {$path}");
        }
    }
}
