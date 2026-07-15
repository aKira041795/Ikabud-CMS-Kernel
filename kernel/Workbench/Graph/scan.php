<?php

declare(strict_types=1);

/**
 * ARK Workbench Graph Scanner — proactive gap analysis and test generation.
 *
 * Usage:
 *   php kernel/Workbench/Graph/scan.php project-audit-ledger
 *   php kernel/Workbench/Graph/scan.php project-audit-ledger --generate
 *   php kernel/Workbench/Graph/scan.php project-audit-ledger --run
 */

require_once __DIR__ . '/../../../bootstrap.php';

use Ikabud\Kernel\Workbench\Graph\ModuleGraph;
use Ikabud\Kernel\Workbench\Graph\GraphBuilder;
use Ikabud\Kernel\Workbench\Graph\GapAnalyzer;
use Ikabud\Kernel\Workbench\Graph\SpecGenerator;
use Ikabud\Kernel\Workbench\Comprehension\PalComprehensionProvider;

// Parse args
$args = $argv ?? [];
$moduleId = null;
$generate = false;
$run = false;
$verbose = false;

foreach ($args as $i => $arg) {
    if ($i === 0) continue;
    if ($arg === '--generate') $generate = true;
    elseif ($arg === '--run') $run = true;
    elseif ($arg === '--verbose' || $arg === '-v') $verbose = true;
    elseif (!str_starts_with($arg, '--')) $moduleId = $arg;
}

if ($moduleId === null) {
    echo "Usage: php kernel/Workbench/Graph/scan.php <module-id> [--generate] [--run]\n";
    echo "  --generate   Generate Playwright test specs for uncovered paths\n";
    echo "  --run        Run generated tests after generation\n";
    exit(1);
}

// Map module ID to provider class
$providerMap = [
    'project-audit-ledger' => PalComprehensionProvider::class,
];

$providerClass = $providerMap[$moduleId] ?? null;
if ($providerClass === null) {
    echo "No comprehension provider registered for module: {$moduleId}\n";
    echo "Available modules: " . implode(', ', array_keys($providerMap)) . "\n";
    exit(1);
}

echo "\n═══════════════════════════════════════════\n";
echo "  ARK Workbench — Proactive Graph Scanner\n";
echo "  Module: {$moduleId}\n";
echo "═══════════════════════════════════════════\n\n";

// 1. Build graph
echo "[1/4] Building module graph...\n";
$provider = new $providerClass();
$builder = new GraphBuilder($provider, $moduleId);
$graph = $builder->build();

$nodeCount = count($graph->nodes());
$edgeCount = count($graph->edges());
$entityCount = count($graph->nodesOfType('entity'));
$actionCount = count($graph->nodesOfType('action'));
$stateCount = count($graph->nodesOfType('state'));
$routeCount = count($graph->nodesOfType('route')) + count($graph->nodesOfType('route_pattern'));

echo "      Nodes: {$nodeCount} ({$entityCount} entities, {$actionCount} actions, {$stateCount} states, {$routeCount} routes)\n";
echo "      Edges: {$edgeCount}\n";

if ($verbose) {
    echo "\n  Nodes:\n";
    foreach ($graph->nodes() as $id => $n) {
        echo "    [{$n->type}] {$id}\n";
        if (!empty($n->meta)) {
            $metaStr = json_encode($n->meta, JSON_UNESCAPED_UNICODE);
            echo "      → " . substr($metaStr, 0, 120) . "\n";
        }
    }
}

// 2. Analyze gaps
echo "\n[2/4] Analyzing gaps...\n";
$analyzer = new GapAnalyzer($graph, $moduleId, BASE_PATH);
$result = $analyzer->analyze();

echo "      Paths:    {$result['total_paths']} total\n";
echo "      Covered:  {$result['covered']}\n";
echo "      Uncovered: {$result['uncovered']}\n";
echo "      Score:    " . ($result['score'] * 100) . "%\n";

$existingTests = $result['existing_tests'] ?? [];
echo "      Existing tests: " . count($existingTests) . "\n";
foreach ($existingTests as $t) {
    echo "        - {$t}\n";
}

if (!empty($result['gaps'])) {
    echo "\n  ───────────────────────────────────────\n";
    echo "  GAPS ({$result['uncovered']} uncovered paths):\n";
    echo "  ───────────────────────────────────────\n";
    foreach ($result['gaps'] as $i => $gap) {
        $type = $gap['type'];
        $label = $gap['label'];
        echo "  [{$i}] ({$type}) {$label}\n";
        if ($verbose) {
            echo "       Reason: {$gap['reason']}\n";
            echo "       Nodes: " . implode(', ', $gap['path']['nodes'] ?? []) . "\n";
        }
    }
} else {
    echo "\n  ✓ All paths covered — no gaps found.\n";
}

// 3. Generate specs
$outputDir = BASE_PATH . '/tests/browser/modules/' . str_replace('-', '/', $moduleId) . '/generated';
if ($generate && !empty($result['gaps'])) {
    echo "\n[3/4] Generating test specs...\n";
    $generator = new SpecGenerator($moduleId, BASE_PATH, $outputDir);
    $genFiles = $generator->generate($result['gaps']);
    echo "      Generated files: " . count($genFiles) . "\n";
    foreach ($genFiles as $f) {
        echo "        + " . str_replace(BASE_PATH . '/', '', $f) . "\n";
    }
} elseif ($generate) {
    echo "\n[3/4] No gaps to generate specs for.\n";
} else {
    echo "\n[3/4] Use --generate to emit test specs for uncovered paths.\n";
}

// 4. Run generated tests
if ($run && !empty($result['gaps'])) {
    echo "\n[4/4] Running generated tests...\n";
    $specPattern = $outputDir . '/*.spec.js';
    $specFiles = glob($specPattern) ?: [];
    if (empty($specFiles)) {
        echo "      No spec files found in {$outputDir}\n";
        if (!$generate) {
            echo "      Run with --generate first.\n";
        }
    } else {
        $joinedSpecs = implode(' ', array_map('escapeshellarg', $specFiles));
        $cmd = "cd " . escapeshellarg(BASE_PATH) . " && npx playwright test {$joinedSpecs} --reporter=list 2>&1";
        echo "      Running: npx playwright test " . count($specFiles) . " specs\n";
        flush();
        passthru($cmd, $exitCode);
        echo "\n      Exit code: {$exitCode}\n";
    }
} elseif ($run) {
    echo "\n[4/4] No generated tests to run.\n";
}

$summaryJson = json_encode([
    'module' => $moduleId,
    'graph' => ['nodes' => $nodeCount, 'edges' => $edgeCount],
    'coverage' => ['total' => $result['total_paths'], 'covered' => $result['covered'], 'score' => $result['score']],
    'gaps' => array_slice($result['gaps'], 0, 50),
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

$summaryPath = BASE_PATH . '/test_results/graph-scan-' . $moduleId . '.json';
@file_put_contents($summaryPath, $summaryJson);
echo "\n  Summary: test_results/graph-scan-{$moduleId}.json\n";
echo "═══════════════════════════════════════════\n";
exit(0);
