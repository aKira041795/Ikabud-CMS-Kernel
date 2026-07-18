<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

require_once __DIR__.'/harness/TestHarness.php';

require_once __DIR__.'/../kernel/Workbench/Governance/WorkbenchRolloutPolicy.php';

require_once __DIR__.'/../kernel/Workbench/Governance/WorkbenchMetrics.php';

require_once __DIR__.'/../kernel/Workbench/Comprehension/Contracts/ModuleComprehensionProvider.php';

require_once __DIR__.'/../kernel/Workbench/Comprehension/Contracts/EntityContract.php'; require_once __DIR__.'/../kernel/Workbench/Comprehension/Contracts/WorkflowContract.php'; require_once __DIR__.'/../kernel/Workbench/Comprehension/Contracts/ActionContract.php'; require_once __DIR__.'/../kernel/Workbench/Comprehension/Contracts/EffectContract.php'; require_once __DIR__.'/../kernel/Workbench/Comprehension/Contracts/SupportContracts.php';

require_once __DIR__.'/../kernel/Workbench/Comprehension/PalComprehensionProvider.php'; require_once __DIR__.'/../kernel/Workbench/Comprehension/ComprehensionProviderRegistry.php';
use Ikabud\Kernel\Workbench\Governance\WorkbenchRolloutPolicy; use Ikabud\Kernel\Workbench\Governance\WorkbenchMetrics; use Ikabud\Kernel\Workbench\Comprehension\ComprehensionProviderRegistry;
$h=new TestHarness('workbench-phase6');
$h->section('Kill switches and rollout');
$killed=(new WorkbenchRolloutPolicy([],['IKABUD_WORKBENCH_KILL_SWITCH'=>'true']))->decision('pal','openai','r1'); $h->test('global kill switch denies execution',!$killed['allowed']&&$killed['reason']==='global_kill_switch');
$module=(new WorkbenchRolloutPolicy(['workbench_disabled_modules'=>['pal']]))->decision('pal','openai','r1'); $h->test('module kill switch is scoped',!$module['allowed']&&$module['reason']==='module_kill_switch');
$provider=(new WorkbenchRolloutPolicy(['workbench_disabled_providers'=>'openai,groq']))->decision('pal','openai','r1'); $h->test('provider kill switch is scoped',!$provider['allowed']&&$provider['reason']==='provider_kill_switch');
$shadow=(new WorkbenchRolloutPolicy(['workbench_rollout_mode'=>'shadow','workbench_rollout_percent'=>100]))->decision('pal','groq','stable'); $h->test('shadow rollout is deterministic',$shadow['allowed']&&$shadow['mode']==='shadow');
$h->section('Provider registry and metrics'); $registry=new ComprehensionProviderRegistry(dirname(__DIR__)); $h->test('PAL provider resolves through registry',$registry->has('project-audit-ledger')&&$registry->resolve('project-audit-ledger') instanceof \Ikabud\Kernel\Workbench\Comprehension\Contracts\ModuleComprehensionProvider);
$metrics=new WorkbenchMetrics(sys_get_temp_dir().'/wb-metrics-'.bin2hex(random_bytes(6)).'.json'); $metrics->record('ai_call',['provider'=>'fixture'],12); $metrics->record('ai_call',['provider'=>'fixture'],8); $snap=$metrics->snapshot(); $h->test('metrics aggregate count and value',($snap[0]['count']??0)===2&&abs((float)($snap[0]['sum']??0)-20.0)<0.0001);
$h->done();
