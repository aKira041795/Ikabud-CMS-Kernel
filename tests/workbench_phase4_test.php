<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

require_once __DIR__ . '/harness/TestHarness.php';

require_once __DIR__ . '/../kernel/Workbench/Graph/ModuleGraph.php';

require_once __DIR__ . '/../kernel/Workbench/Planning/WeightedPathPlanner.php';
use Ikabud\Kernel\Workbench\Graph\ModuleGraph;
use Ikabud\Kernel\Workbench\Planning\WeightedPathPlanner;
$h=new TestHarness('workbench-phase4'); $g=new ModuleGraph();
foreach(['start','fast','risky','end','cause'] as $n)$g->addNode($n,'step');
$g->addEdge('start','fast','flows'); $g->addEdge('fast','end','flows'); $g->addEdge('start','risky','flows'); $g->addEdge('risky','end','flows'); $g->addEdge('end','cause','caused_by');
$keys=array_keys($g->edges()); $stats=[];
foreach($keys as $key)$stats[$key]=['execution_cost'=>1,'risk'=>0.2,'novelty'=>0.1,'gap'=>0.1,'impact'=>0.2,'uncertainty'=>0.1];
$stats['start→risky::flows']=['execution_cost'=>1,'risk'=>1,'novelty'=>1,'gap'=>1,'impact'=>1,'uncertainty'=>1];
$planner=new WeightedPathPlanner(); $paths=$planner->kShortestTestPaths($g,'start','end',$stats,3);
$h->section('Weighted traversal');
$h->test('Dijkstra selects high-value path first', ($paths[0]['nodes'][1]??'')==='risky');
$h->test('Yen alternative retains diverse path', count($paths)>=2 && ($paths[1]['nodes'][1]??'')==='fast');
$h->test('all planning weights remain non-negative', count(array_filter($paths,fn($p)=>$p['cost']<0))===0);
$diag=$planner->diagnosticPaths($g,'end','cause',['end→cause::caused_by'=>0.8]);
$h->test('diagnostic cost maps to likelihood', abs(($diag[0]['likelihood']??0)-0.8)<0.0001);
$suite=$planner->selectSuite($paths,100,['risky']);
$h->test('mandatory path is retained', count(array_filter($suite,fn($p)=>in_array('risky',$p['nodes'],true)))===1);
$h->done();
