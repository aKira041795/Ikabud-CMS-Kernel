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

$args = $argv ?? [];
$moduleId = null;
$runAll = false;
foreach ($args as $i => $arg) {
    if ($i === 0) continue;
    if ($arg === '--all') $runAll = true;
    elseif (!str_starts_with($arg, '--')) $moduleId = $arg;
}
if ($moduleId === null) { echo "Usage: php scan.php <module-id> [--all]\n"; exit(1); }

$providerMap = ['project-audit-ledger' => PalComprehensionProvider::class];
$providerClass = $providerMap[$moduleId] ?? null;
if (!$providerClass) { echo "No provider for {$moduleId}\n"; exit(1); }

echo "\n═══════════════════════════════════════════\n";
echo "  ARK Workbench — Proactive Scanner\n";
echo "  Module: {$moduleId}\n";
echo "═══════════════════════════════════════════\n\n";

// 1. Build graph
echo "[1/3] Building graph...\n";
$provider = new $providerClass();
$builder = new GraphBuilder($provider, $moduleId);
$graph = $builder->build();
echo "  Nodes: " . count($graph->nodes()) . ", Edges: " . count($graph->edges()) . "\n";

// 2. Compute paths from provider data directly
echo "\n[2/3] Computing paths...\n";
$paths = [];
foreach ($provider->entities() as $e) {
    foreach (['entity_list', 'entity_detail', 'entity_edit'] as $t) {
        $paths[] = ['type' => $t, 'entity' => $e->id, 'label' => $e->label, 'table' => $e->table];
    }
}
foreach ($provider->actions() as $a) {
    $paths[] = ['type' => 'action', 'id' => $a->id, 'label' => $a->label, 'entity' => $a->entityType];
}
foreach ($provider->workflows() as $wf) {
    foreach ($wf->transitions as $t) {
        $paths[] = ['type' => 'transition', 'from' => $t['from'], 'to' => $t['to'],
                     'action_id' => $t['action'], 'entity' => $wf->entityType,
                     'label' => "{$t['from']}→{$t['to']}"];
    }
}
echo "  Paths: " . count($paths) . "\n";
foreach ($paths as $i => $p) {
    printf("  [%2d] (%-12s) %s\n", $i, $p['type'], $p['label'] ?? $p['type']);
}

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
