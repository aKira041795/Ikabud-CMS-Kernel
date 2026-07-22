<?php

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────

$root = dirname(__DIR__);

require_once $root . '/kernel/Workbench/Contracts/WorkbenchTestContract.php';
require_once $root . '/kernel/Workbench/Contracts/WorkbenchTestContractValidator.php';
require_once $root . '/kernel/Workbench/Contracts/WorkbenchContractService.php';
require_once $root . '/kernel/Workbench/Benchmark/CompetitiveBenchmark.php';
require_once $root . '/kernel/Workbench/Comprehension/Analyzers/PatternClassifier.php';
require_once $root . '/kernel/Workbench/Runs/RunProvenance.php';
require_once $root . '/kernel/Workbench/Benchmark/CompetitiveBenchmarkRunner.php';

use Ikabud\Kernel\Workbench\Benchmark\CompetitiveBenchmarkRunner;
use Ikabud\Kernel\Workbench\Contracts\WorkbenchContractService;

// ── Input ────────────────────────────────────────────────────────────

$moduleInput = (string) (
    getenv('ARK_MODULES') ?: 'project-audit-ledger,guidance,wms,ehr'
);
$modules = array_values(array_unique(array_filter(array_map(
    'trim',
    explode(',', $moduleInput)
))));
if ($modules === []) {
    fwrite(STDERR, "ARK Workbench CI: ARK_MODULES did not contain a module ID.\n");
    exit(2);
}

// ── Contract doctor ──────────────────────────────────────────────────

$service = new WorkbenchContractService($root);
$moduleReports = [];
$passed = true;

foreach ($modules as $moduleId) {
    $report = $service->doctor($moduleId);
    $moduleReports[$moduleId] = $report;

    if ($report['ok']) {
        continue;
    }

    $passed = false;
    foreach ((array) ($report['errors'] ?? []) as $error) {
        $message = str_replace(
            ["\r", "\n"],
            ' ',
            (string) ($error['message'] ?? 'Contract doctor failed')
        );
        fwrite(
            STDOUT,
            "::error title=ARK Workbench {$moduleId}::{$message}\n"
        );
    }
}

// ── Competitive benchmark ────────────────────────────────────────────

$outputDirectory = $root . '/storage/workbench/ci';
if (!is_dir($outputDirectory)
    && !mkdir($outputDirectory, 0775, true)
    && !is_dir($outputDirectory)
) {
    fwrite(STDERR, "ARK Workbench CI: unable to create evidence directory.\n");
    exit(2);
}

$benchmark = (new CompetitiveBenchmarkRunner($root))->execute(
    $outputDirectory . '/benchmark.json'
);
if (!$benchmark['gates']['passed']) {
    $passed = false;
    fwrite(
        STDOUT,
        "::error title=ARK Workbench benchmark::Competitive quality gate failed\n"
    );
}

// ── Durable summary ──────────────────────────────────────────────────

$summary = [
    'schema' => 'ark.workbench-ci-summary.v1',
    'ok' => $passed,
    'modules' => $moduleReports,
    'benchmark' => $benchmark,
    'recorded_at' => gmdate(DATE_ATOM),
];
$summaryPath = $outputDirectory . '/summary.json';
$tempPath = $summaryPath . '.' . getmypid() . '.tmp';
$encoded = json_encode(
    $summary,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . "\n";

if (file_put_contents($tempPath, $encoded, LOCK_EX) === false
    || !rename($tempPath, $summaryPath)
) {
    @unlink($tempPath);
    fwrite(STDERR, "ARK Workbench CI: unable to publish summary evidence.\n");
    exit(2);
}

// ── Exit gate ────────────────────────────────────────────────────────

fwrite(
    STDOUT,
    $passed ? "ARK Workbench CI: PASS\n" : "ARK Workbench CI: BLOCKED\n"
);
exit($passed ? 0 : 1);
