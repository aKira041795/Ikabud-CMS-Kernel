<?php
declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/Comprehension/Analyzers/PatternClassifier.php';
require_once __DIR__ . '/../kernel/Workbench/Benchmark/CompetitiveBenchmark.php';
require_once __DIR__ . '/../kernel/Workbench/Benchmark/CompetitiveBenchmarkRunner.php';

use Ikabud\Kernel\Workbench\Benchmark\CompetitiveBenchmark;
use Ikabud\Kernel\Workbench\Comprehension\Analyzers\PatternClassifier;

$h = new TestHarness('workbench-competitive-phase1');
$root = dirname(__DIR__);
$corpus = json_decode((string)file_get_contents(__DIR__ . '/ai/golden/competitive-benchmark-cases.v1.json'), true);

$h->section('Competitive corpus');
$h->test('corpus has at least forty cross-domain cases', count($corpus['cases'] ?? []) >= 40);
$modules = array_values(array_unique(array_column($corpus['cases'] ?? [], 'module_id')));
$h->test('corpus spans PAL Guidance WMS and EHR', count(array_intersect(['project-audit-ledger', 'guidance', 'wms', 'ehr'], $modules)) === 4);
$ids = array_column($corpus['cases'] ?? [], 'id');
$h->test('case identities are unique', count($ids) === count(array_unique($ids)));

$h->section('Real deterministic diagnosis');
$benchmark = new CompetitiveBenchmark(new PatternClassifier());
$report = $benchmark->run($corpus);
$ark = $report['engines']['ark_deterministic'];
$h->test('all critical golden defects are detected', $ark['critical_detection_rate'] === 1.0);
$h->test('root cause top three accuracy meets gate', $ark['root_cause_top3_accuracy'] >= 0.85);
$h->test('false positive rate meets gate', $ark['false_positive_rate'] < 0.05);
$h->test('all observations have complete identity', $ark['identity_completeness'] === 1.0);
$h->test('all competitive gates pass', $report['gates']['passed'] === true);

$repeat = $benchmark->run($corpus);
$h->test('recorded inputs produce identical deterministic digest', $repeat['reproducibility_digest'] === $report['reproducibility_digest']);
$h->test('ARK diagnosis improves on plain outcome baseline', $report['engines']['competitive_delta']['top3_accuracy'] >= 0.85);

$h->section('Versioned schemas');
foreach (['competitive-benchmark-corpus.v1.schema.json', 'competitive-benchmark-report.v1.schema.json'] as $schema) {
    $decoded = json_decode((string)file_get_contents($root . '/kernel/Workbench/Schemas/' . $schema), true);
    $h->test($schema . ' is valid JSON', is_array($decoded) && json_last_error() === JSON_ERROR_NONE);
}

$h->done();
