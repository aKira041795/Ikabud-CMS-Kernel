<?php

declare(strict_types=1);

/**
 * ARK Workbench Proactive Scanner — reads module graph, generates tests for
 * EVERY path, runs them, reports failures as real gaps.
 *
 * Usage:
 *   php kernel/Workbench/Graph/scan.php project-audit-ledger          # analyze
 *   php kernel/Workbench/Graph/scan.php project-audit-ledger --all   # generate + run
 */

require_once __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\Workbench\Graph\GraphBuilder;
use Ikabud\Kernel\Workbench\Graph\SpecGenerator;
use Ikabud\Kernel\Workbench\Comprehension\PalComprehensionProvider;
use Ikabud\Kernel\Workbench\Comprehension\ComprehensionProviderRegistry;
use Ikabud\Kernel\Workbench\Planning\WeightedPathPlanner;

require_once __DIR__ . '/../Planning/WeightedPathPlanner.php';
require_once __DIR__ . '/../Comprehension/ComprehensionProviderRegistry.php';

$args = $argv ?? [];
$moduleId = null;
$runAll = false;
foreach ($args as $i => $arg) {
    if ($i === 0) continue;
    if ($arg === '--all') $runAll = true;
    elseif (!str_starts_with($arg, '--')) $moduleId = $arg;
}
if ($moduleId === null) { echo "Usage: php scan.php <module-id> [--all]\n"; exit(1); }

$registry = new ComprehensionProviderRegistry(dirname(__DIR__, 3));
if (!$registry->has($moduleId)) { echo "No provider for {$moduleId}\n"; exit(1); }

echo "\n═══════════════════════════════════════════\n";
echo "  ARK Workbench — Proactive Scanner\n";
echo "  Module: {$moduleId}\n";
echo "═══════════════════════════════════════════\n\n";

// 1. Build graph
echo "[1/3] Building graph...\n";
$provider = $registry->resolve($moduleId);
$builder = new GraphBuilder($provider, $moduleId);
$graph = $builder->build();
echo "  Nodes: " . count($graph->nodes()) . ", Edges: " . count($graph->edges()) . "\n";

// 2. Compute paths from the canonical graph
echo "\n[2/3] Computing paths...\n";
$paths = $builder->computePaths();
echo "  Paths: " . count($paths) . "\n";
foreach ($paths as $i => $p) {
    printf("  [%2d] (%-12s) %s\n", $i, $p['type'], $p['label'] ?? $p['type']);
}

// Shadow planner: persist reproducible weighted paths without changing test gating.
$planner = new WeightedPathPlanner();
$plannedPaths = [];
foreach ($provider->actions() as $action) {
    if ($action->chain === []) continue;
    $routeId = 'route:' . $action->method . ':' . $action->route;
    $last = $action->chain[count($action->chain) - 1];
    $targetId = $action->id . ':' . $last->step;
    foreach ($planner->kShortestTestPaths($graph, $routeId, $targetId, [], 3) as $path) {
        $plannedPaths[] = ['action_id' => $action->id] + $path;
    }
}
$planDir = BASE_PATH . '/test_results/ai';
if (!is_dir($planDir)) mkdir($planDir, 0770, true);
file_put_contents($planDir . '/test-plan.json', json_encode([
    'schema_version' => '1.0', 'mode' => 'shadow', 'module_id' => $moduleId,
    'graph_version' => hash('sha256', json_encode($graph->toArray($moduleId))),
    'policy_version' => 'weighted-planner-v1', 'generated_at' => date('c'),
    'selected_paths' => $plannedPaths,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
echo "  Shadow weighted paths: " . count($plannedPaths) . " (test_results/ai/test-plan.json)\n";

// 3. Generate + run
$outputDir = BASE_PATH . '/tests/browser/modules/pal/workflows/generated';
$generator = new SpecGenerator($moduleId, BASE_PATH, $outputDir);

if ($runAll) {
    echo "\n[3/3] Generating ALL path specs and running...\n";
    // Clean previous generated specs
    $existing = glob($outputDir . '/*.spec.js');
    foreach ($existing as $f) { @unlink($f); }

    $genFiles = $generator->generateAll($paths);
    echo "  Generated: " . count($genFiles) . " spec files\n";

    if (empty($genFiles)) { echo "  No specs generated.\n"; exit(0); }

    $specArgs = implode(' ', array_map('escapeshellarg', $genFiles));
    $cmd = "cd " . escapeshellarg(BASE_PATH)
         . " && ADMIN_USER=pAladmin ADMIN_PASS=pal123456 PAL_TEST_TENANT=502"
         . " npx playwright test {$specArgs} --reporter=list 2>&1";
    echo "\n  Running...\n";
    flush();
    passthru($cmd, $exitCode);
    echo "\n  ───────────────────────────────────────\n";
    if ($exitCode === 0) {
        echo "  ALL PATHS PASS — no gaps\n";
    } else {
        echo "  FAILURES DETECTED — real gaps found\n";
    }
}
echo "\n═══════════════════════════════════════════\n";
exit(0);
