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
        if (is_file($target) && !$force) throw new \RuntimeException("Contract already exists: {$target}");
        $contract = (new WorkbenchTestContractMigrator())->migrate($path);
        if (file_put_contents($target, WorkbenchTestContract::encode($contract)) === false) throw new \RuntimeException("Unable to write {$target}");
        return ['ok' => true, 'module' => $moduleId, 'path' => $target, 'contract' => $contract];
    }

    /** @return array<string,mixed> */
    public function validate(string $moduleId): array
    {
        $path = $this->modulePath($moduleId);
        $contract = WorkbenchTestContract::read($path . '/' . WorkbenchTestContract::FILE);
        return (new WorkbenchTestContractValidator())->validate($contract, $path, $this->projectRoot);
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
            return ['ok' => false, 'module' => $moduleId, 'stage' => 'preflight', 'browser_execution_allowed' => false, 'errors' => [['code' => 'contract-unavailable', 'message' => $e->getMessage()]], 'warnings' => [], 'checks' => []];
        }
    }

    /** @return array<string,mixed> */
    public function run(string $moduleId, string $gate = 'critical'): array
    {
        $preflight = $this->doctor($moduleId);
        $runId = gmdate('YmdHis') . '-' . substr(hash('sha256', $moduleId . json_encode($preflight)), 0, 12);
        $report = ['run_id' => $runId, 'module' => $moduleId, 'gate' => $gate, 'preflight' => $preflight, 'browser_started' => false, 'executions' => [], 'outcome' => $preflight['ok'] ? 'passed' : 'blocked'];
        if ($preflight['ok']) {
            $modulePath = $this->modulePath($moduleId);
            $contract = WorkbenchTestContract::read($modulePath . '/' . WorkbenchTestContract::FILE);
            foreach ((array) ($contract['test_files']['php'] ?? []) as $file) {
                $report['executions'][] = $this->execute([PHP_BINARY, $this->projectRoot . '/' . ltrim((string) $file, '/')], 'php', (string) $file);
            }
            $browserFiles = array_values((array) ($contract['test_files']['browser'] ?? []));
            if ($browserFiles !== []) {
                $report['browser_started'] = true;
                $command = ['npx', 'playwright', 'test'];
                foreach ($browserFiles as $file) $command[] = (string) $file;
                $report['executions'][] = $this->execute($command, 'browser', implode(', ', $browserFiles));
            }
            if (count(array_filter($report['executions'], static fn(array $execution): bool => $execution['exit_code'] !== 0)) > 0) $report['outcome'] = 'failed';
        }
        $dir = $this->projectRoot . '/storage/workbench/runs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        file_put_contents($dir . '/' . $runId . '.json', WorkbenchTestContract::encode($report));
        return $report;
    }

    /** @return array<string,mixed> */
    public function explain(string $runId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $runId)) throw new \RuntimeException('Invalid run id');
        $report = WorkbenchTestContract::read($this->projectRoot . '/storage/workbench/runs/' . $runId . '.json');
        $errors = $report['preflight']['errors'] ?? [];
        return ['run_id' => $runId, 'module' => $report['module'] ?? '', 'outcome' => $report['outcome'] ?? 'unknown', 'summary' => $errors === [] ? 'Contract preflight passed; browser execution may proceed.' : 'Contract preflight blocked execution.', 'causes' => $errors, 'next_command' => 'php ikabud workbench:doctor ' . ($report['module'] ?? '')];
    }

    private function modulePath(string $moduleId): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $moduleId)) throw new \RuntimeException('Invalid module id');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->projectRoot . '/modules', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'module.json') continue;
            $manifest = json_decode((string) file_get_contents($file->getPathname()), true);
            if (is_array($manifest) && ($manifest['id'] ?? null) === $moduleId) return $file->getPath();
        }
        throw new \RuntimeException("Unknown module: {$moduleId}");
    }

    /** @param list<string> $command @return array<string,mixed> */
    private function execute(array $command, string $kind, string $target): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot);
        if (!is_resource($process)) return ['kind' => $kind, 'target' => $target, 'exit_code' => 127, 'output_digest' => null, 'summary' => 'unable to start process'];
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process); $output = trim((string) $stdout . ($stderr !== '' ? "\n" . $stderr : ''));
        return ['kind' => $kind, 'target' => $target, 'exit_code' => $exit, 'output_digest' => hash('sha256', $output), 'summary' => substr($output, -4000)];
    }
}
