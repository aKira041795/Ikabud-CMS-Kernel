<?php
declare(strict_types=1);
require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/Planning/MatrixPlanner.php';
use Ikabud\Kernel\Workbench\Planning\MatrixPlanner;

$h = new TestHarness('workbench-competitive-phase4');
$planner = new MatrixPlanner();
$dimensions = ['role' => ['admin', 'viewer'], 'tenant' => ['alpha', 'beta'], 'capability' => ['full', 'read'], 'browser' => ['chromium', 'firefox'], 'viewport' => ['desktop', 'mobile'], 'environment' => ['ci', 'production-like']];
$mandatory = [['role' => 'viewer', 'tenant' => 'beta', 'capability' => 'read'], ['browser' => 'firefox', 'environment' => 'production-like']];
$first = $planner->plan($dimensions, $mandatory, 32);
$second = $planner->plan(array_reverse($dimensions, true), $mandatory, 32);
$h->assertSame($first['digest'], $second['digest'], 'matrix plan is deterministic');
$h->assertSame($first['coverage']['critical_combinations'], $first['coverage']['critical_selected'], 'no mandatory critical combination omitted');
$h->assertSame(100.0, $first['coverage']['pairwise_pct'], 'pairwise dimension coverage is complete');
$h->assertCount(8, $first['checks'], 'all isolation surfaces generated');
$h->test('omissions are explicitly explained', $first['omitted'] !== [] && count(array_filter($first['omitted'], static fn(array $o): bool => trim($o['reason']) === '')) === 0);
$leaks = $planner->detectIsolationLeaks([['tenant' => 'alpha', 'observed_tenant' => 'alpha'], ['tenant' => 'alpha', 'observed_tenant' => 'beta', 'surface' => 'export']]);
$h->assertCount(1, $leaks, 'golden cross-tenant leak detected');
$h->assertSame('critical', $leaks[0]['severity'], 'tenant leak is release-critical');
$h->done();
