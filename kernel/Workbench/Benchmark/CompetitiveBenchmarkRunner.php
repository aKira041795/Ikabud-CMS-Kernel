<?php
declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Benchmark;

use Ikabud\Kernel\Workbench\Runs\RunProvenance;
use RuntimeException;

final class CompetitiveBenchmarkRunner
{
    public function __construct(private readonly string $projectRoot) {}

    public function execute(?string $outputFile = null): array
    {
        $corpusFile = $this->projectRoot . '/tests/ai/golden/competitive-benchmark-cases.v1.json';
        $corpus = is_file($corpusFile) ? json_decode((string)file_get_contents($corpusFile), true) : null;
        if (!is_array($corpus)) throw new RuntimeException('Competitive benchmark corpus is missing or invalid');

        $startedAt = gmdate(DATE_ATOM);
        $classifier = new \Ikabud\Kernel\Workbench\Comprehension\Analyzers\PatternClassifier();
        $report = (new CompetitiveBenchmark($classifier))->run($corpus);
        $finishedAt = gmdate(DATE_ATOM);

        // Attach canonical provenance
        $provenance = new RunProvenance();
        $report['provenance'] = $provenance->build([
            'run_id' => 'benchmark-' . substr($report['reproducibility_digest'] ?? hash('sha256', $startedAt), 0, 16),
            'module_id' => 'kernel.workbench.benchmark',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'completion_status' => 'complete',
            'git_sha' => '',
            'project_root' => $this->projectRoot,
            'scenario_version' => (string)($corpus['version'] ?? 'unknown'),
            'test_plan_version' => 'competitive-benchmark-v1',
            'gate_policy' => 'benchmark',
            'artifact_schema_versions' => [
                'benchmark' => 'ark.competitive-benchmark-report.v1',
                'provenance' => 'ark.workbench-run-provenance.v1',
            ],
        ]);

        $outputFile ??= $this->projectRoot . '/test_results/benchmark/competitive-latest.json';
        $this->writeAtomic($outputFile, $report);

        $historyFile = dirname($outputFile) . '/competitive-' . substr($report['reproducibility_digest'], 0, 16) . '.json';
        if (!is_file($historyFile)) $this->writeAtomic($historyFile, $report);
        return $report;
    }

    private function writeAtomic(string $file, array $report): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create benchmark report directory');
        }
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to write benchmark report');
        }
    }
}
