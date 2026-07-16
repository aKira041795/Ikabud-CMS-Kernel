<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/kernel/Workbench/Contracts/WorkbenchTestContract.php';
require_once $root . '/kernel/Workbench/Contracts/WorkbenchTestContractValidator.php';
require_once $root . '/kernel/Workbench/Contracts/WorkbenchContractService.php';
require_once $root . '/kernel/Workbench/Benchmark/CompetitiveBenchmark.php';
require_once $root . '/kernel/Workbench/Comprehension/Analyzers/PatternClassifier.php';
require_once $root . '/kernel/Workbench/Benchmark/CompetitiveBenchmarkRunner.php';

use Ikabud\Kernel\Workbench\Contracts\WorkbenchContractService;
use Ikabud\Kernel\Workbench\Benchmark\CompetitiveBenchmarkRunner;

$modules = array_values(array_filter(explode(',', (string) (getenv('ARK_MODULES') ?: 'project-audit-ledger,guidance,wms,ehr'))));
$service = new WorkbenchContractService($root); $reports = []; $ok = true;
foreach ($modules as $module) {
    $report = $service->doctor(trim($module)); $reports[$module] = $report;
    if (!$report['ok']) {
        $ok = false;
        foreach ($report['errors'] as $error) fwrite(STDOUT, '::error title=ARK Workbench ' . $module . '::' . str_replace(["\r", "\n"], ' ', (string) $error['message']) . "\n");
    }
}
$benchmark = (new CompetitiveBenchmarkRunner($root))->execute($root . '/storage/workbench/ci/benchmark.json');
if (!$benchmark['gates']['passed']) { $ok = false; fwrite(STDOUT, "::error title=ARK Workbench benchmark::Competitive quality gate failed\n"); }
$summary = ['schema' => 'ark.workbench-ci-summary.v1', 'ok' => $ok, 'modules' => $reports, 'benchmark' => $benchmark, 'recorded_at' => gmdate(DATE_ATOM)];
$dir = $root . '/storage/workbench/ci'; if (!is_dir($dir)) mkdir($dir, 0775, true);
file_put_contents($dir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
fwrite(STDOUT, $ok ? "ARK Workbench CI: PASS\n" : "ARK Workbench CI: BLOCKED\n"); exit($ok ? 0 : 1);
