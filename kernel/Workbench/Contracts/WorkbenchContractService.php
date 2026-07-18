<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Contracts;

final class WorkbenchContractService
{
    public function __construct(private readonly string $projectRoot) {}

    /** @return array<string,mixed> */
    public function initialize(string $moduleId, bool $force = false): array
    {
        $path = $this->modulePath($moduleId);
        $target = $path . '/' . WorkbenchTestContract::FILE;
        if (is_file($target) && !$force) {
            throw new \RuntimeException("Contract already exists: {$target}");
        }

        $contract = (new WorkbenchTestContractMigrator())->migrate($path);
        if (file_put_contents($target, WorkbenchTestContract::encode($contract)) === false) {
            throw new \RuntimeException("Unable to write {$target}");
        }

        return ['ok' => true, 'module' => $moduleId, 'path' => $target, 'contract' => $contract];
    }

    /** @return array<string,mixed> */
    public function validate(string $moduleId): array
    {
        $path = $this->modulePath($moduleId);
        $contract = WorkbenchTestContract::read($path . '/' . WorkbenchTestContract::FILE);

        return (new WorkbenchTestContractValidator())->validate(
            $contract,
            $path,
            $this->projectRoot
        );
    }

    /** @return array<string,mixed> */
    public function doctor(string $moduleId): array
    {
        try {
            $report = $this->validate($moduleId);
            $report['stage'] = 'preflight';
            $report['browser_execution_allowed'] = $report['ok'];
            return $report;
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'module' => $moduleId,
                'stage' => 'preflight',
                'browser_execution_allowed' => false,
                'errors' => [[
                    'code' => 'contract-unavailable',
                    'message' => $e->getMessage(),
                ]],
                'warnings' => [],
                'checks' => [],
            ];
        }
    }

    /** @return array<string,mixed> */
    public function run(string $moduleId, string $gate = 'critical'): array
    {
        $startedAt = gmdate(DATE_ATOM);
        $preflight = $this->doctor($moduleId);
        $runId = gmdate('YmdHis') . '-' . substr(
            hash('sha256', $moduleId . json_encode($preflight) . random_bytes(16)),
            0,
            12
        );
        $environment = $this->executionEnvironment($runId, $moduleId, $gate);

        // Resolve provenance parameters
        $provenanceBuilder = new \Ikabud\Kernel\Workbench\Runs\RunProvenance();
        $provenanceParams = [
            'run_id' => $runId,
            'module_id' => $moduleId,
            'started_at' => $startedAt,
            'gate_policy' => $gate,
            'project_root' => $this->projectRoot,
        ];

        $report = [
            'schema' => 'ark.workbench-contract-run.v1',
            'run_id' => $runId,
            'module' => $moduleId,
            'gate' => $gate,
            'started_at' => $startedAt,
            'preflight' => $preflight,
            'provenance' => $provenanceBuilder->build($provenanceParams),
            'browser_started' => false,
            'executions' => [],
            'outcome' => $preflight['ok'] ? 'passed' : 'blocked',
        ];

        if ($preflight['ok']) {
            $modulePath = $this->modulePath($moduleId);
            $contract = WorkbenchTestContract::read(
                $modulePath . '/' . WorkbenchTestContract::FILE
            );
            $timeouts = (array) ($contract['environments']['timeout_seconds'] ?? []);
            $phpTimeout = $this->normalizeTimeout($timeouts['php'] ?? 300);
            $browserTimeout = $this->normalizeTimeout($timeouts['browser'] ?? 900);

            foreach ((array) ($contract['test_files']['php'] ?? []) as $file) {
                $report['executions'][] = $this->execute(
                    [PHP_BINARY, $this->projectRoot . '/' . ltrim((string) $file, '/')],
                    'php',
                    (string) $file,
                    $environment,
                    $phpTimeout
                );
            }

            $browserFiles = array_values((array) ($contract['test_files']['browser'] ?? []));
            if ($browserFiles !== []) {
                $report['browser_started'] = true;
                $command = ['npx', 'playwright', 'test'];
                foreach ($browserFiles as $file) {
                    $command[] = (string) $file;
                }
                $report['executions'][] = $this->execute(
                    $command,
                    'browser',
                    implode(', ', $browserFiles),
                    $environment,
                    $browserTimeout
                );
            }

            $hasFailure = count(array_filter(
                $report['executions'],
                static fn(array $execution): bool =>
                    $execution['exit_code'] !== 0 || $execution['timed_out']
            )) > 0;
            $hasTimeout = count(array_filter(
                $report['executions'],
                static fn(array $execution): bool =>
                    !empty($execution['timed_out'])
            )) > 0;
            if ($hasFailure) {
                $report['outcome'] = 'failed';
            }
            if ($hasTimeout) {
                $report['outcome'] = 'interrupted';
            }
        } elseif (!$preflight['ok']) {
            $report['outcome'] = 'blocked';
        }

        $report['finished_at'] = gmdate(DATE_ATOM);
        // Update provenance with completion status and finished_at
        if (isset($report['provenance'])) {
            $report['provenance']['finished_at'] = $report['finished_at'];
            $completionStatus = match ($report['outcome'] ?? '') {
                'passed' => 'complete',
                'failed' => 'complete',
                'blocked' => 'blocked',
                'interrupted' => 'interrupted',
                default => 'failed-before-analysis',
            };
            $report['provenance']['completion_status'] = $completionStatus;
            if ($completionStatus !== 'complete') {
                $report['provenance']['certification_disclaimer'] =
                    'This run did not complete. Results must not be used for release certification.';
            }
        }
        $dir = $this->projectRoot . '/storage/workbench/runs';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create Workbench run directory: {$dir}");
        }
        $this->persistReport($dir . '/' . $runId . '.json', $report);

        return $report;
    }

    /** @return array<string,mixed> */
    public function explain(string $runId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $runId)) {
            throw new \RuntimeException('Invalid run id');
        }

        $report = WorkbenchTestContract::read(
            $this->projectRoot . '/storage/workbench/runs/' . $runId . '.json'
        );
        $errors = $report['preflight']['errors'] ?? [];
        $nonPassed = in_array(($report['outcome'] ?? ''), ['failed', 'interrupted', 'blocked'], true);
        if ($errors === [] && $nonPassed) {
            foreach ((array) ($report['executions'] ?? []) as $execution) {
                if (($execution['exit_code'] ?? 0) !== 0 || !empty($execution['timed_out'])) {
                    $errors[] = [
                        'code' => !empty($execution['timed_out'])
                            ? 'execution-timeout'
                            : 'execution-failed',
                        'message' => (string) ($execution['target'] ?? 'test execution'),
                    ];
                }
            }
        }

        return [
            'run_id' => $runId,
            'module' => $report['module'] ?? '',
            'outcome' => $report['outcome'] ?? 'unknown',
            'summary' => match ($report['outcome'] ?? '') {
                'passed' => 'Contract run passed.',
                'interrupted' => 'Contract run was interrupted (timeout or external kill).',
                'blocked' => 'Contract run was blocked by preflight.',
                default => 'Contract run failed.',
            },
            'causes' => $errors,
            'next_command' => 'php ikabud workbench:doctor ' . ($report['module'] ?? ''),
        ];
    }

    private function modulePath(string $moduleId): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $moduleId)) {
            throw new \RuntimeException('Invalid module id');
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->projectRoot . '/modules',
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'module.json') {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($file->getPathname()), true);
            if (is_array($manifest) && ($manifest['id'] ?? null) === $moduleId) {
                return $file->getPath();
            }
        }

        throw new \RuntimeException("Unknown module: {$moduleId}");
    }

    /** @return array<string,string> */
    private function executionEnvironment(string $runId, string $moduleId, string $gate): array
    {
        $inherited = getenv();
        $environment = is_array($inherited) ? $inherited : [];

        return array_map('strval', array_merge($environment, [
            'WB_RUN_ID' => $runId,
            'ARK_MODULE' => $moduleId,
            'MODULE' => $moduleId,
            'HYBRID_GATE' => $gate,
        ]));
    }

    private function normalizeTimeout(mixed $value): int
    {
        return max(1, min(3600, (int) $value));
    }

    /** @param array<string,mixed> $report */
    private function persistReport(string $path, array $report): void
    {
        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, WorkbenchTestContract::encode($report), LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write Workbench run report: {$tmp}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to publish Workbench run report: {$path}");
        }
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     * @return array<string,mixed>
     */
    private function execute(
        array $command,
        string $kind,
        string $target,
        array $environment,
        int $timeoutSeconds
    ): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $grouped = function_exists('posix_kill') && is_executable('/usr/bin/setsid');
        if ($grouped) {
            array_unshift($command, '/usr/bin/setsid');
        }

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->projectRoot,
            $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return [
                'kind' => $kind,
                'target' => $target,
                'exit_code' => 127,
                'timed_out' => false,
                'timeout_seconds' => $timeoutSeconds,
                'output_digest' => null,
                'summary' => 'unable to start process',
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $exitCode = -1;
        $started = hrtime(true);

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;
            if ($elapsedSeconds >= $timeoutSeconds) {
                $timedOut = true;
                $this->terminateProcessTree($process, (int) $status['pid'], $grouped);
                break;
            }

            usleep(20_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        if (!$timedOut && $exitCode < 0) {
            $exitCode = $closeCode;
        }
        if ($timedOut) {
            $exitCode = 124;
        }

        $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
        return [
            'kind' => $kind,
            'target' => $target,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'timeout_seconds' => $timeoutSeconds,
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 1),
            'run_id' => $environment['WB_RUN_ID'],
            'module' => $environment['ARK_MODULE'],
            'gate' => $environment['HYBRID_GATE'],
            'output_digest' => hash('sha256', $output),
            'summary' => substr($output, -4000),
        ];
    }

    /** @param resource $process */
    private function terminateProcessTree($process, int $pid, bool $grouped): void
    {
        if ($grouped && $pid > 0) {
            @posix_kill(-$pid, defined('SIGTERM') ? SIGTERM : 15);
        }
        @proc_terminate($process);

        $deadline = microtime(true) + 0.5;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return;
            }
            usleep(20_000);
        }

        if ($grouped && $pid > 0) {
            @posix_kill(-$pid, defined('SIGKILL') ? SIGKILL : 9);
        }
        @proc_terminate($process, 9);
    }
}
