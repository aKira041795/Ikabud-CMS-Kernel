<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';


require_once __DIR__ . '/../kernel/Workbench/Scenario/ScenarioEngine.php';

require_once __DIR__ . '/../modules/project-audit-ledger/testing/ScenarioCapabilityProvider.php';

use Ikabud\Kernel\Workbench\Scenario\CapabilityScenarioDataProvider;
use Ikabud\Kernel\Workbench\Scenario\JsonSandboxDataProvider;
use Ikabud\Kernel\Workbench\Scenario\ScenarioCompiler;
use Ikabud\Kernel\Workbench\Scenario\ScenarioContract;
use Ikabud\Kernel\Workbench\Scenario\ScenarioEngine;
use Ikabud\Kernel\Workbench\Scenario\ScenarioStore;

$passed=0; $failed=0;
$check=function(bool $condition,string $message)use(&$passed,&$failed){if($condition){$passed++;echo "✅ {$message}\n";}else{$failed++;echo "❌ {$message}\n";}};
$root=sys_get_temp_dir().'/ark-scenario-'.bin2hex(random_bytes(4));
$scenario=(new ScenarioCompiler())->compile([
    'module'=>'sample-module','title'=>'Investigate approval clarity','fronts'=>['logic','semantics'],
    'questions'=>['Can a reviewer understand why approval is blocked?'],
    'directions'=>[['front'=>'logic','statement'=>'Approval route responds','check'=>'route_available','route'=>'/admin/approvals']],
    'data'=>['entities'=>['request'=>[['status'=>'pending'],['status'=>'approved']]],'relationships'=>[]],
]);
$check((new ScenarioContract())->validate($scenario)['valid'],'human input compiles into a valid versioned scenario');
$check($scenario['questions'][0]!=='' && count($scenario['directions'])===1,'questions and directions remain explicit');
$store=new ScenarioStore($root.'/store'); $store->save($scenario);
$check($store->load('sample-module',$scenario['scenario_id'])['title']===$scenario['title'],'scenario is persisted and reloadable');
$engine=new ScenarioEngine(new JsonSandboxDataProvider($root.'/sandboxes'));
$run=$engine->prepare($scenario,'run-001');
$check($run['status']==='ready' && $run['seed_receipt']['entity_count']===2,'isolated deterministic data is seeded and verified');
file_put_contents($run['seed_receipt']['file'],"\n",FILE_APPEND);
$drift=(new JsonSandboxDataProvider($root.'/sandboxes'))->verify($scenario,$run['seed_receipt']);
$check(!$drift['valid'] && $drift['drift']==='seed_changed','seed drift is detected before human fatigue can mask it');
$final=$engine->finalize($run);
$check($final['status']==='completed' && $final['cleanup_result']['clean'],'tracked scenario data is cleaned after evidence capture');
$invalid=$scenario; $invalid['directions'][0]['route']='https://outside.test';
$check(!(new ScenarioContract())->validate($invalid)['valid'],'external or malformed route directions are rejected');
$capScenario=(new ScenarioCompiler())->compile([
    'scenario_id'=>'pal-domain-contract-check','module'=>'project-audit-ledger','title'=>'PAL domain-owned scenario',
    'directions'=>[],'questions'=>[],
    'data'=>['entities'=>['pal_expense'=>[['expense_scope'=>'operating','expense_date'=>'2026-07-16','category_id'=>1,'description'=>'Office rent','amount'=>1000]]]],
]);
$capCaller=function(string $id,array $payload,array $context):array {
    return match($id) {
        'workbench.scenario.describe@1'=>palWorkbenchScenarioDescribe($payload),
        'workbench.scenario.seed@1'=>palWorkbenchScenarioSeed($payload),
        'workbench.scenario.verify@1'=>palWorkbenchScenarioVerify($payload),
        'workbench.scenario.cleanup@1'=>palWorkbenchScenarioCleanup($payload),
        default=>['ok'=>false],
    };
};
$capProvider=new CapabilityScenarioDataProvider($capCaller,'project-audit-ledger');
$check($capProvider->describe()['domain_contract_version']==='1.0.0','Kernel adapter discovers the PAL-owned domain contract through capabilities');
$capRun=(new ScenarioEngine($capProvider))->prepare($capScenario,'cap-run-001');
$check($capRun['status']==='ready' && $capRun['seed_receipt']['provider']==='project-audit-ledger','scenario lifecycle consumes the module capability provider');
$capFinal=(new ScenarioEngine($capProvider))->finalize($capRun);
$check($capFinal['cleanup_result']['clean'],'module capability owns cleanup behind the Kernel contract');
$badDomain=$capScenario; $badDomain['data']['entities']['pal_expense'][0]=['expense_scope'=>'project','amount'=>1000];
$check(palWorkbenchScenarioSeed(['module'=>'project-audit-ledger','run_id'=>'bad-run','scenario'=>$badDomain])['ok']===false,'PAL rejects scenario data that violates its domain contract');
$unknownDomain=$capScenario; $unknownDomain['data']['entities']=['invented_table'=>[['id'=>1]]];
$check(palWorkbenchScenarioSeed(['module'=>'project-audit-ledger','run_id'=>'unknown-run','scenario'=>$unknownDomain])['ok']===false,'PAL rejects undeclared scenario entity types');
echo "RESULTS: {$passed} passed, {$failed} failed\n";
exit($failed===0?0:1);
