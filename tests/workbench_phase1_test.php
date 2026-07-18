<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';


require_once __DIR__ . '/harness/TestHarness.php';

require_once __DIR__ . '/../kernel/Workbench/Evidence/EvidenceNormalizer.php';

require_once __DIR__ . '/../kernel/Workbench/Graph/ModuleGraph.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/ModuleComprehensionProvider.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/EntityContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/WorkflowContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/ActionContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/EffectContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/SupportContracts.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/ModuleComprehensionEngine.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/PalComprehensionProvider.php';

use Ikabud\Kernel\Workbench\Evidence\EvidenceNormalizer;
use Ikabud\Kernel\Workbench\Graph\ModuleGraph;
use Ikabud\Kernel\Workbench\Comprehension\ModuleComprehensionEngine;
use Ikabud\Kernel\Workbench\Comprehension\PalComprehensionProvider;

$h = new TestHarness('workbench-phase1');

$h->section('Evidence identity');
$normalizer = new EvidenceNormalizer();
$observations = $normalizer->normalize([
    '_meta' => ['run_id' => 'run-1'],
    'steps' => [
        ['action' => 'a.one', 'step' => 'http.request', 'success' => true, 'source' => 'browser'],
        ['action' => 'a.two', 'step' => 'http.request', 'success' => false, 'source' => 'browser'],
        ['action' => 'a.one', 'step' => 'db.effect', 'outcome' => 'unobserved', 'source' => 'database_probe'],
    ],
], 'module-x');
$h->test('all structured observations are preserved', count($observations) === 3);
$h->test('same step remains separated by action', $normalizer->evidenceForAction($observations, 'a.one')['http.request'] === true && $normalizer->evidenceForAction($observations, 'a.two')['http.request'] === false);
$h->test('unobserved is represented explicitly', ($normalizer->evidenceForAction($observations, 'a.one')['db.effect']['__workbench_outcome'] ?? '') === 'unobserved');
$ids = array_column($observations, 'observation_id');
$h->test('observation IDs are unique', count($ids) === count(array_unique($ids)));

$h->section('Canonical graph invariants');
$graph = new ModuleGraph();
$action = $graph->addNode('m:action:a', 'action', ['label' => 'Action A']);
$step = $graph->addNode('m:step:a:one', 'chain_step');
$graph->addEdge($action->id, $step->id, 'has_step');
$graph->addNode('m:action:a', 'action', ['confidence' => 0.9]);
$h->test('node merge preserves adjacency', count($graph->node('m:action:a')->edgesOut) === 1);
$h->test('canonical graph validates', $graph->validate() === []);
$serialized = $graph->toArray('module-x');
$h->test('serialized graph is provenance-aware', ($serialized['nodes'][0]['provenance'] ?? '') === 'declared');

$rejected = false;
try { $graph->addEdge('missing', 'm:action:a', 'invalid'); } catch (InvalidArgumentException) { $rejected = true; }
$h->test('dangling edges are rejected', $rejected);

$h->section('Censored deterministic outcomes');
$deterministic = new ModuleComprehensionEngine(new PalComprehensionProvider());
$withoutEvidence = $deterministic->analyzeAction('pal.job-order.submit');
$h->test('missing evidence does not create a breakpoint', ($withoutEvidence['breakpoint'] ?? null) === null);
$h->test('missing links are marked unobserved', count(array_filter($withoutEvidence['chain'], fn(array $link): bool => ($link['outcome'] ?? '') === 'unobserved')) === count($withoutEvidence['chain']));

$h->done();
