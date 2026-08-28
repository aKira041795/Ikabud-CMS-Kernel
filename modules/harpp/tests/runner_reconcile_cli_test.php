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
use Harpp\Services\HarppRunService;

$manifest=json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=app()->dbForTenant($tenantId);
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/013_harpp_runner_work_queue.sql'));
// 014 is an ALTER; re-apply idempotently so repeated test runs are safe.
$cols=array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='harpp_work_runs'")->fetchAll(PDO::FETCH_ASSOC),'COLUMN_NAME');
if(!in_array('attempt_count',$cols,true)){
    $pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/014_harpp_runner_reconcile.sql'));
}
$db=new \Ikabud\Kernel\Contracts\ModuleDB($pdo,'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$owner['id']=(int)$owner['id'];$owner['source']='harpp';
$actor=$owner;$actor['source']='harpp_bridge';
$db->prepare("DELETE FROM harpp_runners WHERE runner_key IN ('reconcile-test','reconcile-r2')")->execute();

$h = new TestHarness('harpp-runner-reconcile');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$conversationId=0;$messageId=0;$runIds=[];
try {
    $bridge=new HarppBridgeService($db);
    $messaging=new HarppMessagingService($db);
    $created=$messaging->createConversation($owner,['title'=>'Reconcile context','harness_session_id'=>'reconcile-test'],$tenantId);
    $conversationId=(int)($created['data']['conversation_id']??0);
    $sent=$messaging->sendMessage($owner,$conversationId,['body'=>'Reconcile me.','sender_type'=>'user'],$tenantId);
    $messageId=(int)($sent['data']['message_id']??0);

    // P1-2: reconcile marks a RUNNING child not in the healthy set as STALLED,
    // proving a dead child (with no terminal report) cannot remain RUNNING.
    $queued=$bridge->queueMessageRun($actor,['message_id'=>$messageId,'required_capabilities'=>['reconcile-test']],$tenantId);
    $run=(array)($queued['data']['run']??[]);
    $runId=(int)($run['id']??0);
    $runIds[]=$runId;
    $bridge->registerRunner($actor,['runner_key'=>'reconcile-test','display_name'=>'Reconcile','capabilities'=>['reconcile-test','shell']],$tenantId);
    $claim=$bridge->claimRun($actor,['runner_key'=>'reconcile-test','lease_seconds'=>120],$tenantId);
    $token=(string)($claim['data']['claim_token']??'');
    $running=$bridge->runRunning($actor,$runId,['claim_token'=>$token,'status'=>'Child started.'],$tenantId);
    // Runner supervises run, but the child process has died (not in healthy set).
    $reconciled=$bridge->reconcileRuns($actor,['runner_key'=>'reconcile-test','healthy'=>[]],$tenantId);
    $st=($bridge->runStatus($actor,$runId,$tenantId)['data']['run']??[]);
    $assert('reconcile stalls a dead RUNNING child',!empty($running['ok'])&&!empty($reconciled['ok'])&&(int)($reconciled['data']['stalled']??0)===1&&($st['state']??'')==='STALLED','reconciled='.json_encode($reconciled).' state='.json_encode($st));

    // Healthy set keeps the run RUNNING (no false stall).
    $queued2=$bridge->queueMessageRun($actor,['message_id'=>$messageId,'required_capabilities'=>['reconcile-test']],$tenantId);
    // Same source message -> resolves to the same run; use a fresh message for the healthy case.
    $sent2=$messaging->sendMessage($owner,$conversationId,['body'=>'Reconcile healthy.','sender_type'=>'user'],$tenantId);
    $messageId2=(int)($sent2['data']['message_id']??0);
    $q2=$bridge->queueMessageRun($actor,['message_id'=>$messageId2,'required_capabilities'=>['reconcile-test']],$tenantId);
    $run2=(array)($q2['data']['run']??[]);
    $runId2=(int)($run2['id']??0);
    $runIds[]=$runId2;
    $claim2=$bridge->claimRun($actor,['runner_key'=>'reconcile-test','lease_seconds'=>120],$tenantId);
    $token2=(string)($claim2['data']['claim_token']??'');
    $running2=$bridge->runRunning($actor,$runId2,['claim_token'=>$token2,'status'=>'Child started.'],$tenantId);
    $bridge->reconcileRuns($actor,['runner_key'=>'reconcile-test','healthy'=>[$runId2]],$tenantId);
    $st2=($bridge->runStatus($actor,$runId2,$tenantId)['data']['run']??[]);
    $assert('healthy supervised child is not stalled',($st2['state']??'')==='RUNNING','state='.json_encode($st2));

    // P1-2: expired RUNNING lease with exhausted retry budget -> STALLED.
    $q3=$bridge->queueMessageRun($actor,['message_id'=>$messageId2,'required_capabilities'=>['reconcile-test']],$tenantId);
    // fresh message for third run
    $sent3=$messaging->sendMessage($owner,$conversationId,['body'=>'Reconcile expired.','sender_type'=>'user'],$tenantId);
    $messageId3=(int)($sent3['data']['message_id']??0);
    $q3=$bridge->queueMessageRun($actor,['message_id'=>$messageId3,'required_capabilities'=>['reconcile-test']],$tenantId);
    $run3=(array)($q3['data']['run']??[]);
    $runId3=(int)($run3['id']??0);
    $runIds[]=$runId3;
    $claim3=$bridge->claimRun($actor,['runner_key'=>'reconcile-test','lease_seconds'=>30],$tenantId);
    $token3=(string)($claim3['data']['claim_token']??'');
    $running3=$bridge->runRunning($actor,$runId3,['claim_token'=>$token3,'status'=>'Child started.'],$tenantId);
    // exhaust retry budget and expire the lease
    $db->prepare('UPDATE harpp_work_runs SET attempt_count=max_attempts,lease_expires_at=DATE_SUB(NOW(6),INTERVAL 1 SECOND) WHERE id=:id')->execute([':id'=>$runId3]);
    $bridge->claimRun($actor,['runner_key'=>'reconcile-test','lease_seconds'=>30],$tenantId); // triggers recoverExpiredLeases
    $st3=($bridge->runStatus($actor,$runId3,$tenantId)['data']['run']??[]);
    $assert('expired running lease with exhausted budget stalls',($st3['state']??'')==='STALLED'&&($st3['stalled_at']??null)!==null,'state='.json_encode($st3));

    // P1-2: CLAIMED (never started) expired lease requeues for retry.
    $sent4=$messaging->sendMessage($owner,$conversationId,['body'=>'Reconcile claimed.','sender_type'=>'user'],$tenantId);
    $messageId4=(int)($sent4['data']['message_id']??0);
    $q4=$bridge->queueMessageRun($actor,['message_id'=>$messageId4,'required_capabilities'=>['reconcile-test']],$tenantId);
    $run4=(array)($q4['data']['run']??[]);
    $runId4=(int)($run4['id']??0);
    $runIds[]=$runId4;
    $claim4=$bridge->claimRun($actor,['runner_key'=>'reconcile-test','lease_seconds'=>30],$tenantId);
    $db->prepare('UPDATE harpp_work_runs SET lease_expires_at=DATE_SUB(NOW(6),INTERVAL 1 SECOND) WHERE id=:id')->execute([':id'=>$runId4]);
    // Trigger recovery with a runner that cannot claim this run, so the requeued
    // state is observable rather than immediately re-claimed.
    $bridge->registerRunner($actor,['runner_key'=>'reconcile-r2','display_name'=>'Other','capabilities'=>['other']],$tenantId);
    $bridge->claimRun($actor,['runner_key'=>'reconcile-r2','lease_seconds'=>30],$tenantId);
    $st4=($bridge->runStatus($actor,$runId4,$tenantId)['data']['run']??[]);
    $assert('never-started expired claim requeues for retry',($st4['state']??'')==='QUEUED'&&($st4['claim_token']??null)===null,'state='.json_encode($st4));
    // Consume run4 out of the claimable pool so later claims target their own run.
    $bridge->claimRun($actor,['runner_key'=>'reconcile-test','lease_seconds'=>120],$tenantId);

    // P1-4: report delivery separation — terminal run keeps report PENDING; a
    // successful dispatch marks it DELIVERED; failures retry then dead-letter.
    $sent5=$messaging->sendMessage($owner,$conversationId,['body'=>'Report delivery.','sender_type'=>'user'],$tenantId);
    $messageId5=(int)($sent5['data']['message_id']??0);
    $q5=$bridge->queueMessageRun($actor,['message_id'=>$messageId5,'required_capabilities'=>['reconcile-test']],$tenantId);
    $run5=(array)($q5['data']['run']??[]);
    $runId5=(int)($run5['id']??0);
    $runIds[]=$runId5;
    $claim5=$bridge->claimRun($actor,['runner_key'=>'reconcile-test','lease_seconds'=>120],$tenantId);
    $token5=(string)($claim5['data']['claim_token']??'');
    $bridge->runRunning($actor,$runId5,['claim_token'=>$token5,'status'=>'Running.'],$tenantId);
    $comp=$bridge->completeRun($actor,$runId5,['claim_token'=>$token5,'status'=>'Done.','result'=>['marker'=>'OK']],$tenantId);
    $done=(array)($comp['data']['run']??[]);
    $assert('terminal run report is retained as PENDING',($done['state']??'')==='SUCCEEDED'&&($done['report_state']??'')==='PENDING'&&(int)($done['delivery_attempts']??-1)===0,'done='.json_encode($done));

    $delivered=$bridge->reportDelivered($actor,$runId5,[],$tenantId);
    $d=(array)($delivered['data']['run']??[]);
    $assert('successful report delivery is DELIVERED',!empty($delivered['ok'])&&($d['report_state']??'')==='DELIVERED'&&(int)($d['delivery_attempts']??0)>=1,'delivered='.json_encode($d));

    // dispatch with a failing dispatcher -> attempts grow, then dead-letter
    $sent6=$messaging->sendMessage($owner,$conversationId,['body'=>'Dead letter.','sender_type'=>'user'],$tenantId);
    $messageId6=(int)($sent6['data']['message_id']??0);
    $q6=$bridge->queueMessageRun($actor,['message_id'=>$messageId6,'required_capabilities'=>['reconcile-test']],$tenantId);
    $run6=(array)($q6['data']['run']??[]);
    $runId6=(int)($run6['id']??0);
    $runIds[]=$runId6;
    $claim6=$bridge->claimRun($actor,['runner_key'=>'reconcile-test','lease_seconds'=>120],$tenantId);
    $token6=(string)($claim6['data']['claim_token']??'');
    $bridge->runRunning($actor,$runId6,['claim_token'=>$token6,'status'=>'Running.'],$tenantId);
    $bridge->completeRun($actor,$runId6,['claim_token'=>$token6,'status'=>'Done.'],$tenantId);
    $svc=new HarppRunService($db);
    $svc->dispatchRunReports(static fn(): bool => false); // 1 attempt
    $mid=($bridge->runStatus($actor,$runId6,$tenantId)['data']['run']??[]);
    $assert('failed delivery retains report PENDING with attempt tracked',($mid['report_state']??'')==='PENDING'&&(int)($mid['delivery_attempts']??0)===1&&($mid['last_delivery_error']??'')!=='','mid='.json_encode($mid));
    // exhaust remaining attempts (4 more -> total 5) -> DEAD_LETTER
    for ($i=0;$i<4;$i++){ $svc->dispatchRunReports(static fn(): bool => false); }
    $after=($bridge->runStatus($actor,$runId6,$tenantId)['data']['run']??[]);
    $assert('exhausted delivery attempts dead-letter the report',($after['report_state']??'')==='DEAD_LETTER'&&(int)($after['delivery_attempts']??0)>=5,'after='.json_encode($after));

    // dead-letter via explicit API with inspectable error
    $sent7=$messaging->sendMessage($owner,$conversationId,['body'=>'Dead letter api.','sender_type'=>'user'],$tenantId);
    $messageId7=(int)($sent7['data']['message_id']??0);
    $q7=$bridge->queueMessageRun($actor,['message_id'=>$messageId7,'required_capabilities'=>['reconcile-test']],$tenantId);
    $run7=(array)($q7['data']['run']??[]);
    $runId7=(int)($run7['id']??0);
    $runIds[]=$runId7;
    $claim7=$bridge->claimRun($actor,['runner_key'=>'reconcile-test','lease_seconds'=>120],$tenantId);
    $token7=(string)($claim7['data']['claim_token']??'');
    $bridge->runRunning($actor,$runId7,['claim_token'=>$token7,'status'=>'Running.'],$tenantId);
    $bridge->completeRun($actor,$runId7,['claim_token'=>$token7,'status'=>'Done.'],$tenantId);
    $dl=$bridge->reportDeadLetter($actor,$runId7,['error'=>'push channel down'],$tenantId);
    $dlr=(array)($dl['data']['run']??[]);
    $assert('explicit dead-letter records inspectable error',!empty($dl['ok'])&&($dlr['report_state']??'')==='DEAD_LETTER'&&($dlr['last_delivery_error']??'')==='push channel down','dl='.json_encode($dl));
} finally {
    foreach ($runIds as $id) { if($id>0)$db->prepare('DELETE FROM harpp_work_runs WHERE id=:id')->execute([':id'=>$id]); }
    $db->prepare("DELETE FROM harpp_runners WHERE runner_key IN ('reconcile-test','reconcile-r2')")->execute();
    if($conversationId>0)$db->prepare('DELETE FROM harpp_notifications WHERE conversation_id=:id')->execute([':id'=>$conversationId]);
    if($conversationId>0)$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$conversationId]);
}

$h->done();
