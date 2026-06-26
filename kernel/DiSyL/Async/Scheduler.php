<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Async;

/**
 * DiSyL 4.5.1 async scheduler — Fibers-based concurrency backend.
 *
 * Drives Promise resolution using PHP 8.1+ Fibers for cooperative
 * multitasking. Multi-curl I/O is ticked between fiber resumptions
 * via HttpClient::tick(). Results always return in source order.
 *
 * The public API is unchanged from 4.5.0 (add/run).
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
     * Run all registered tasks using PHP Fibers for concurrency.
     *
     * Each task runs in its own Fiber. Fibers that return a resolved
     * Promise complete immediately; fibers with pending Promises suspend
     * and are resumed after multi-curl I/O ticks.
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
        $fibers = [];

        // Step 1: Create and start all fibers
        foreach ($this->tasks as $i => $factory) {
            try {
                $promise = $factory();

                if (!($promise instanceof Promise)) {
                    $out[$i] = ['value' => $promise];
                    continue;
                }

                // For already-settled Promises, resolve synchronously
                if ($promise->isFulfilled()) {
                    try {
                        $out[$i] = ['value' => $promise->wait()];
                    } catch (\Throwable $e) {
                        $out[$i] = ['error' => $e];
                    }
                    continue;
                }

                if ($promise->isRejected()) {
                    try {
                        $promise->wait();
                    } catch (\Throwable $e) {
                        $out[$i] = ['error' => $e];
                    }
                    continue;
                }

                // Pending Promise — wrap in a Fiber that suspends
                $fiber = new \Fiber(function () use ($promise) {
                    $resolved = false;
                    $result = null;
                    $error = null;

                    $promise->then(
                        function ($v) use (&$resolved, &$result) { $resolved = true; $result = $v; },
                        function (\Throwable $e) use (&$resolved, &$error) { $resolved = true; $error = $e; },
                    );

                    if ($resolved) {
                        if ($error !== null) throw $error;
                        return $result;
                    }

                    // Suspend — the scheduler loop will tick I/O and resume
                    \Fiber::suspend();

                    // Check again after resume
                    $promise->then(
                        function ($v) use (&$resolved, &$result) { $resolved = true; $result = $v; },
                        function (\Throwable $e) use (&$resolved, &$error) { $resolved = true; $error = $e; },
                    );

                    if (!$resolved) {
                        throw new \RuntimeException('DISYL_AWAIT_TIMEOUT');
                    }
                    if ($error !== null) throw $error;
                    return $result;
                });

                $fiber->start();
                $fibers[$i] = $fiber;

                if ($fiber->isTerminated()) {
                    try {
                        $out[$i] = ['value' => $fiber->getReturn()];
                    } catch (\Throwable $e) {
                        $out[$i] = ['error' => $e];
                    }
                    unset($fibers[$i]);
                }
            } catch (\Throwable $e) {
                $out[$i] = ['error' => $e];
            }
        }

        // Step 2: Round-robin resume pending fibers, ticking I/O
        $maxIterations = 10000;
        $iteration = 0;

        while (!empty($fibers) && $iteration < $maxIterations) {
            $iteration++;
            $anyProgress = false;

            HttpClient::tick();

            foreach ($fibers as $i => $fiber) {
                if (!$fiber->isSuspended()) continue;

                try {
                    $fiber->resume();
                    $anyProgress = true;

                    if ($fiber->isTerminated()) {
                        try {
                            $out[$i] = ['value' => $fiber->getReturn()];
                        } catch (\Throwable $e) {
                            $out[$i] = ['error' => $e];
                        }
                        unset($fibers[$i]);
                    }
                } catch (\Throwable $e) {
                    $out[$i] = ['error' => $e];
                    unset($fibers[$i]);
                    $anyProgress = true;
                }
            }

            if (!$anyProgress && !empty($fibers)) {
                HttpClient::tick();
                usleep(1000); // 1ms — prevent busy-wait
            }
        }

        // Remaining fibers = timeout
        foreach ($fibers as $i => $fiber) {
            $out[$i] = ['error' => new \RuntimeException('DISYL_AWAIT_TIMEOUT')];
        }

        $this->tasks = [];
        ksort($out); // preserve source order
        return $out;
    }

    public function clear(): void { $this->tasks = []; }
    public function count(): int { return count($this->tasks); }
}
