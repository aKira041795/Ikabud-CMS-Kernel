#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/kernel/Workbench/Comprehension/Analyzers/PatternClassifier.php';
require_once $root . '/kernel/Workbench/Benchmark/CompetitiveBenchmark.php';
require_once $root . '/kernel/Workbench/Benchmark/CompetitiveBenchmarkRunner.php';

$output = null;
$jsonOnly = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) $output = substr($argument, 9);
    elseif ($argument === '--json') $jsonOnly = true;
}

try {
    $report = (new \Ikabud\Kernel\Workbench\Benchmark\CompetitiveBenchmarkRunner($root))->execute($output);
    if ($jsonOnly) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        $ark = $report['engines']['ark_deterministic'];
        echo "ARK Workbench competitive benchmark\n";
        echo "Cases: {$report['case_count']}\n";
        echo "Critical detection: " . round($ark['critical_detection_rate'] * 100, 1) . "%\n";
        echo "Root cause top-3: " . round($ark['root_cause_top3_accuracy'] * 100, 1) . "%\n";
        echo "False positives: " . round($ark['false_positive_rate'] * 100, 1) . "%\n";
        echo "Identity completeness: " . round($ark['identity_completeness'] * 100, 1) . "%\n";
        echo "Digest: {$report['reproducibility_digest']}\n";
        echo "Gate: " . ($report['gates']['passed'] ? 'PASS' : 'FAIL') . "\n";
    }
    exit($report['gates']['passed'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "Benchmark failed: {$e->getMessage()}\n");
    exit(2);
}
