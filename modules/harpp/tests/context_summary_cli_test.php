<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$logs = [$root.'/storage/logs/app.log', $root.'/storage/logs/error.log'];
foreach ($logs as $log) { if (is_file($log)) file_put_contents($log, ''); }
require $root.'/bootstrap.php';
$tenantId=(int)($_SERVER['argv'][1]??1);
app()->tenant()->setTenantId($tenantId);
require_once dirname(__DIR__).'/helpers.php';
require_once $root . '/tests/harness/TestHarness.php';

use Harpp\Services\HarppBridgeService;
use Harpp\Services\HarppMessagingService;

$manifest=json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=app()->dbForTenant($tenantId);
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/013_harpp_runner_work_queue.sql'));
// 014 is an ALTER; re-apply idempotently so repeated test runs are safe.
$cols=array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='harpp_work_runs'")->fetchAll(PDO::FETCH_ASSOC),'COLUMN_NAME');
if(!in_array('attempt_count',$cols,true)){
    $pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/014_harpp_runner_reconcile.sql'));
}
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/015_harpp_context_summary.sql'));
$db=new \Ikabud\Kernel\Contracts\ModuleDB($pdo,'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$owner['id']=(int)$owner['id'];$owner['source']='harpp';
$actor=$owner;$actor['source']='harpp_bridge';

$h = new TestHarness('harpp-context-summary');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$convs=[];$runIds=[];$decisionIds=[];
try {
    $bridge=new HarppBridgeService($db);
    $messaging=new HarppMessagingService($db);

    // Conversation A — the memory-bearing conversation.
    $created=$messaging->createConversation($owner,['title'=>'Memory conversation','harness_session_id'=>'memory-test'],$tenantId);
    $convA=(int)($created['data']['conversation_id']??0);$convs[]=$convA;
    $messaging->sendMessage($owner,$convA,['body'=>'Please run the task.','sender_type'=>'user'],$tenantId);
    $messaging->sendMessage($owner,$convA,['body'=>'Use approach X.','sender_type'=>'user'],$tenantId);

    // Queue + run + complete one run for conversation A (active/latest run).
    $msg=$db->prepare("SELECT id FROM harpp_messages WHERE conversation_id=:c ORDER BY id LIMIT 1");$msg->execute([':c'=>$convA]);$msgIdA=(int)$msg->fetchColumn();
    $q=$bridge->queueMessageRun($actor,['message_id'=>$msgIdA,'required_capabilities'=>['context-test']],$tenantId);
    $run=(array)($q['data']['run']??[]);$runId=(int)($run['id']??0);$runIds[]=$runId;
    $bridge->registerRunner($actor,['runner_key'=>'ctx-runner','display_name'=>'Ctx','capabilities'=>['context-test','shell']],$tenantId);
    $claim=$bridge->claimRun($actor,['runner_key'=>'ctx-runner','lease_seconds'=>120],$tenantId);
    $token=(string)($claim['data']['claim_token']??'');
    $bridge->runRunning($actor,$runId,['claim_token'=>$token,'status'=>'Running.'],$tenantId);
    $bridge->completeRun($actor,$runId,['claim_token'=>$token,'status'=>'Done.','result'=>['marker'=>'OK']],$tenantId);

    // Record an applicable durable decision for conversation A (DECIDED).
    $dk='DEC-REUSE-'.bin2hex(random_bytes(4));
    $ins=$db->prepare("INSERT INTO harpp_decisions (workspace_id,project_id,visibility,decision_key,conversation_id,title,body,context,requested_decision,priority,source,workbench_state,created_by,lifecycle_state,version,decision,decided_at,created_at) VALUES (NULL,NULL,'workspace',:key,:conv,'Reuse decision','body',NULL,'Pick option','normal','harness','ARCHITECTURE_DECISION_REQUIRED',:user,'DECIDED',1,'Use option B',NOW(),NOW())");
    $ins->execute([':key'=>$dk,':conv'=>$convA,':user'=>(int)$owner['id']]);$decisionIds[]=(int)$db->lastInsertId();

    $ctx=$bridge->conversationContext($actor,$convA,['limit'=>10],$tenantId);
    $data=(array)($ctx['data']??[]);
    $summary=(array)($data['summary']??[]);
    $summaryDecisions=(array)($summary['decisions']??[]);
    $assert('context includes durable bounded summary',!empty($ctx['ok'])&&($summary['title']??'')==='Memory conversation'&&(int)($summary['version']??0)>0&&count((array)($summary['recent']??[]))>=1,'ctx='.json_encode($ctx));
    $assert('run-N+1 reuses an applicable durable decision',count($summaryDecisions)>=1&&in_array($dk,array_column($summaryDecisions,'decision_key'),true)&&($summaryDecisions[0]['decision']??'')==='Use option B','summary='.json_encode($summary));
    $assert('summary version drives cache invalidation',(int)($data['cache']['version']??0)=== (int)($summary['version']??0)&&(int)($data['cache']['version']??0)>0,'cache='.json_encode($data['cache']??[]));
    $assert('context still returns messages and runs',count((array)($data['messages']??[]))>=2&&count((array)($data['runs']??[]))>=1,'data='.json_encode(array_keys($data)));

    $v1=(int)($summary['version']??0);
    $messaging->sendMessage($owner,$convA,['body'=>'New message advances the summary version.','sender_type'=>'user'],$tenantId);
    $ctx2=$bridge->conversationContext($actor,$convA,['limit'=>10],$tenantId);
    $v2=(int)(($ctx2['data']['summary']??[])['version']??0);
    $assert('summary version advances on a new message (cache invalidates)',$v2>$v1,'v1='.$v1.' v2='.$v2);

    // Conversation B — must not see conversation A's decisions (isolation).
    $createdB=$messaging->createConversation($owner,['title'=>'Isolated conversation','harness_session_id'=>'isolation-test'],$tenantId);
    $convB=(int)($createdB['data']['conversation_id']??0);$convs[]=$convB;
    $messaging->sendMessage($owner,$convB,['body'=>'A separate conversation.','sender_type'=>'user'],$tenantId);
    $ctxB=$bridge->conversationContext($actor,$convB,['limit'=>10],$tenantId);
    $summaryB=(array)(($ctxB['data']['summary']??[])??[]);
    $assert('per-conversation isolation: no cross-conversation decision reuse',count((array)($summaryB['decisions']??[]))===0,'summaryB='.json_encode($summaryB));

    // Budget bounds: summary lists are bounded.
    $assert('summary lists are bounded (decisions<=8, recent<=20)',count($summaryDecisions)<=8&&count((array)($summary['recent']??[]))<=20,'decisions='.count($summaryDecisions));
} finally {
    foreach ($runIds as $id) { if($id>0)$db->prepare('DELETE FROM harpp_work_runs WHERE id=:id')->execute([':id'=>$id]); }
    foreach ($decisionIds as $id) { if($id>0)$db->prepare('DELETE FROM harpp_decisions WHERE id=:id')->execute([':id'=>$id]); }
    $db->prepare("DELETE FROM harpp_runners WHERE runner_key='ctx-runner'")->execute();
    foreach ($convs as $c) {
        if($c>0){$db->prepare('DELETE FROM harpp_context_summary WHERE conversation_id=:id')->execute([':id'=>$c]);$db->prepare('DELETE FROM harpp_notifications WHERE conversation_id=:id')->execute([':id'=>$c]);$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$c]);}
    }
}

$h->done();
