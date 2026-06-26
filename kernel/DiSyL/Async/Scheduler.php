<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Async;

/**
 * DiSyL 4.5 async scheduler — synchronous default backend.
 *
 * Public API is the surface that {parallel}/{await} consume. Results
 * return in source order for template determinism.
 *
 * HttpClient provides multi-curl multiplexing for concurrent HTTP I/O.
 * The Scheduler remains synchronous — proven and sufficient for
 * current workloads.
 */
final class Scheduler
{
    /** @var array<int, callable(): Promise> */
    private array $tasks = [];

    /**
     * Register a task. Returns the index it occupies (preserves source order).
     *
     * @param callable(): Promise $factory
     */
    public function add(callable $factory): int
    {
        $this->tasks[] = $factory;
        return array_key_last($this->tasks);
    }

    /**
     * Run all registered tasks. Returns ordered results: each entry is
     * either ['value' => mixed] or ['error' => Throwable].
     *
     * Cap on concurrent tasks per render = 64 (4.5 acceptance criterion).
     *
     * @return array<int, array{value?: mixed, error?: \Throwable}>
     */
    public function run(int $maxConcurrent = 64): array
    {
        if (count($this->tasks) > $maxConcurrent) {
            throw new \RuntimeException(sprintf(
                'DISYL_PARALLEL_LIMIT: %d tasks exceeds cap of %d',
                count($this->tasks),
                $maxConcurrent,
            ));
        }
        $out = [];
        foreach ($this->tasks as $i => $factory) {
            try {
                $promise = $factory();
                if (!($promise instanceof Promise)) {
                    $out[$i] = ['value' => $promise];
                    continue;
                }
                $resolved = false; $value = null; $err = null;
                $promise->then(
                    static function ($v) use (&$resolved, &$value): void { $resolved = true; $value = $v; },
                    static function (\Throwable $e) use (&$resolved, &$err): void { $resolved = true; $err = $e; },
                );
                if (!$resolved) {
                    $out[$i] = ['error' => new \RuntimeException('DISYL_AWAIT_TIMEOUT: pending promise without driver')];
                } elseif ($err !== null) {
                    $out[$i] = ['error' => $err];
                } else {
                    $out[$i] = ['value' => $value];
                }
            } catch (\Throwable $e) {
                $out[$i] = ['error' => $e];
            }
        }
        $this->tasks = [];
        return $out;
    }

    public function clear(): void { $this->tasks = []; }
    public function count(): int { return count($this->tasks); }
}
