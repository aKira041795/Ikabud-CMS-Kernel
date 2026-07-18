<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

require_once __DIR__ . '/harness/TestHarness.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/ModuleComprehensionProvider.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/EntityContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/WorkflowContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/ActionContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/EffectContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/SupportContracts.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/PalComprehensionProvider.php';

require_once __DIR__ . '/../kernel/Workbench/Graph/ModuleGraph.php';

require_once __DIR__ . '/../kernel/Workbench/Graph/GraphBuilder.php';
use Ikabud\Kernel\Workbench\Comprehension\PalComprehensionProvider;
use Ikabud\Kernel\Workbench\Graph\GraphBuilder;
$h=new TestHarness('workbench-phase5');
$builder=new GraphBuilder(new PalComprehensionProvider(),'project-audit-ledger'); $graph=$builder->build(); $map=$graph->toArray('project-audit-ledger');
$h->section('Clear canonical map');
$h->test('graph has no validation errors',$graph->validate()===[]);
$h->test('every node has stable identity and type',count(array_filter($map['nodes'],fn($n)=>($n['id']??'')===''||($n['type']??'')===''))===0);
$h->test('every node exposes provenance and confidence',count(array_filter($map['nodes'],fn($n)=>!isset($n['provenance'],$n['confidence'])))===0);
$h->test('ordered step edges exist',count(array_filter($map['edges'],fn($e)=>$e['type']==='next_step'))>0);
$template=(string)file_get_contents(__DIR__.'/../templates/pages/superadmin-workbench.disyl');
$routes=(string)file_get_contents(__DIR__.'/../src/http/core-routes.php');
$h->section('Workbench experience');
$h->test('process map tab and provenance legend exist',str_contains($template,'F. Process Map')&&str_contains($template,'AI inferred'));
$h->test('AI provider policy controls exist',str_contains($template,'wb-ai-provider')&&str_contains($template,'wbSaveAiPolicy'));
$h->test('process map API is registered',str_contains($routes,'workbench/process-map'));
$h->done();
