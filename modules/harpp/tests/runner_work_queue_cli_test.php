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
$db=new \Ikabud\Kernel\Contracts\ModuleDB($pdo,'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$owner['id']=(int)$owner['id'];$owner['source']='harpp';
$actor=$owner;$actor['source']='harpp_bridge';
$db->prepare("DELETE FROM harpp_runners WHERE runner_key='desktop-test'")->execute();

$h = new TestHarness('harpp-runner-work-queue');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$conversationId=0;$messageId=0;$runId=0;
try {
    $bridge=new HarppBridgeService($db);
    $messaging=new HarppMessagingService($db);
    $created=$messaging->createConversation($owner,['title'=>'Runner queue context','harness_session_id'=>'runner-queue-test'],$tenantId);
    $conversationId=(int)($created['data']['conversation_id']??0);
    $sent=$messaging->sendMessage($owner,$conversationId,['body'=>'Please run the desktop workflow.','sender_type'=>'user'],$tenantId);
    $messageId=(int)($sent['data']['message_id']??0);

    $queued=$bridge->queueMessageRun($actor,['message_id'=>$messageId,'required_capabilities'=>['runner-test']],$tenantId);
    $again=$bridge->queueMessageRun($actor,['message_id'=>$messageId,'required_capabilities'=>['runner-test']],$tenantId);
    $run=(array)($queued['data']['run']??[]);
    $runId=(int)($run['id']??0);
    $assert('owner message creates exactly one durable run',!empty($queued['ok'])&&!empty($again['ok'])&&$runId>0&&(int)($again['data']['run']['id']??0)===$runId&&!array_key_exists('claim_token',(array)($again['data']['run']??[])),'queued='.json_encode($queued).' again='.json_encode($again));
    $assert('offline desktop is explicit',($run['state']??'')==='WAITING_FOR_RUNNER'&&($run['report_state']??'')==='PENDING','run='.json_encode($run));

    $poll=$bridge->pollMessages($actor,['cursor'=>0,'conversation_id'=>$conversationId],$tenantId);
    $pollMessage=(array)($poll['data']['messages'][0]??[]);
    $assert('poll payload includes title version and run state',!empty($poll['ok'])&&($pollMessage['conversation_title']??'')==='Runner queue context'&&(int)($pollMessage['conversation_version']??0)>0&&(int)($pollMessage['run_id']??0)===$runId&&($pollMessage['run_state']??'')==='WAITING_FOR_RUNNER');

    $registered=$bridge->registerRunner($actor,['runner_key'=>'desktop-test','display_name'=>'Desktop Test','capabilities'=>['runner-test','shell']],$tenantId);
    $claim=$bridge->claimRun($actor,['runner_key'=>'desktop-test','lease_seconds'=>120],$tenantId);
    $token=(string)($claim['data']['claim_token']??'');
    $claimed=(array)($claim['data']['run']??[]);
    $assert('online compatible runner claims queued work',!empty($registered['ok'])&&!empty($claim['ok'])&&(int)($claimed['id']??0)===$runId&&($claimed['state']??'')==='CLAIMED'&&$token!=='');

    $running=$bridge->runRunning($actor,$runId,['claim_token'=>$token,'status'=>'Process started.'],$tenantId);
    $renewed=$bridge->renewRun($actor,$runId,['claim_token'=>$token,'lease_seconds'=>180],$tenantId);
    $complete=$bridge->completeRun($actor,$runId,['claim_token'=>$token,'status'=>'Process complete.','result'=>['marker'=>'HARPP_WAKE_RESULT','replies_sent'=>1]],$tenantId);
    $done=(array)($complete['data']['run']??[]);
    $assert('run lifecycle separates execution and delivery',!empty($running['ok'])&&!empty($renewed['ok'])&&!empty($complete['ok'])&&($done['state']??'')==='SUCCEEDED'&&($done['report_state']??'')==='PENDING'&&!array_key_exists('claim_token',$done));

    $bad=$bridge->failRun($actor,$runId,['claim_token'=>'not-the-token','status'=>'Should be rejected.'],$tenantId);
    $assert('invalid claim token cannot mutate run',empty($bad['ok'])&&($bad['code']??'')==='claim_invalid');

    $context=$bridge->conversationContext($actor,$conversationId,['limit'=>10],$tenantId);
    $assert('context endpoint returns title history run summary and cache version',!empty($context['ok'])&&($context['data']['conversation']['title']??'')==='Runner queue context'&&count((array)($context['data']['messages']??[]))>=1&&count((array)($context['data']['runs']??[]))>=1&&(int)($context['data']['cache']['version']??0)>0);
} finally {
    if($runId>0)$db->prepare('DELETE FROM harpp_work_runs WHERE id=:id')->execute([':id'=>$runId]);
    $db->prepare("DELETE FROM harpp_runners WHERE runner_key='desktop-test'")->execute();
    if($conversationId>0)$db->prepare('DELETE FROM harpp_notifications WHERE conversation_id=:id')->execute([':id'=>$conversationId]);
    if($conversationId>0)$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$conversationId]);
}

$h->done();
