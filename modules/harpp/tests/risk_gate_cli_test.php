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
// Apply the schema the run lifecycle needs (013..017), idempotently.
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/013_harpp_runner_work_queue.sql'));
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/015_harpp_context_summary.sql'));
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/016_harpp_artifact_bundle.sql'));
$cols=array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='harpp_work_runs'")->fetchAll(PDO::FETCH_ASSOC),'COLUMN_NAME');
if(!in_array('attempt_count',$cols,true)){
    $pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/014_harpp_runner_reconcile.sql'));
}
// 017 is an ALTER (risk-gate columns + state enum); re-apply idempotently on risk_level.
if(!in_array('risk_level',$cols,true)){
    $pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/017_harpp_risk_gate.sql'));
}
$db=new \Ikabud\Kernel\Contracts\ModuleDB($pdo,'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$owner['id']=(int)$owner['id'];$owner['source']='harpp';
$actor=$owner;$actor['source']='harpp_bridge';
$db->prepare("DELETE FROM harpp_runners WHERE runner_key='risk-gate-test'")->execute();

$h = new TestHarness('harpp-risk-gate');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$conversationId=0;$messageIds=[];$runIds=[];
try {
    $bridge=new HarppBridgeService($db);
    $messaging=new HarppMessagingService($db);
    $svc=new HarppRunService($db);
    $created=$messaging->createConversation($owner,['title'=>'Risk gate context','harness_session_id'=>'risk-gate-test'],$tenantId);
    $conversationId=(int)($created['data']['conversation_id']??0);
    $bridge->registerRunner($actor,['runner_key'=>'risk-gate-test','display_name'=>'Risk Gate','capabilities'=>['risk-gate','shell']],$tenantId);

    // Helper: queue + claim + mark running a fresh message run; returns [runId, token].
    $startRun = static function() use ($messaging, $bridge, $owner, $actor, $conversationId, $tenantId, &$messageIds, &$runIds): array {
        $sent=$messaging->sendMessage($owner,$conversationId,['body'=>'Risk gate run.','sender_type'=>'user'],$tenantId);
        $messageId=(int)($sent['data']['message_id']??0);
        $messageIds[]=$messageId;
        $q=$bridge->queueMessageRun($actor,['message_id'=>$messageId,'required_capabilities'=>['risk-gate']],$tenantId);
        $run=(array)($q['data']['run']??[]);
        $runId=(int)($run['id']??0);
        $runIds[]=$runId;
        $claim=$bridge->claimRun($actor,['runner_key'=>'risk-gate-test','lease_seconds'=>120],$tenantId);
        $token=(string)($claim['data']['claim_token']??'');
        $bridge->runRunning($actor,$runId,['claim_token'=>$token,'status'=>'Running.'],$tenantId);
        return [$runId,$token];
    };

    // S3: CRITICAL completion is parked in AWAITING_APPROVAL, not SUCCEEDED.
    [$runId1,$token1]=$startRun();
    $comp=$bridge->completeRun($actor,$runId1,['claim_token'=>$token1,'status'=>'Done.','result'=>['action'=>'deploy production','risk_level'=>'CRITICAL']],$tenantId);
    $awaiting=(array)($comp['data']['run']??[]);
    $approvalToken=(string)($comp['data']['approval_token']??'');
    $assert('critical completion is parked awaiting approval',
        !empty($comp['ok'])&&($awaiting['state']??'')==='AWAITING_APPROVAL'&&(int)($awaiting['approval_required']??0)===1&&($awaiting['risk_level']??'')==='CRITICAL'&&$approvalToken!==''&&($awaiting['approved_at']??null)===null,
        'comp='.json_encode($comp));

    // Wrong approval token is denied; run stays AWAITING_APPROVAL.
    $wrong=$svc->approveRun($actor,$runId1,['approval_token'=>'deadbeef'],$tenantId);
    $stAfterWrong=($bridge->runStatus($actor,$runId1,$tenantId)['data']['run']??[]);
    $assert('wrong approval token is denied',
        empty($wrong['ok'])&&in_array((string)($wrong['status']??''),['409','422'],true)&&($stAfterWrong['state']??'')==='AWAITING_APPROVAL'&&($stAfterWrong['approved_at']??null)===null,
        'wrong='.json_encode($wrong).' state='.json_encode($stAfterWrong));

    // Correct token promotes to SUCCEEDED with approved_at + artifact bundle.
    $okApprove=$svc->approveRun($actor,$runId1,['approval_token'=>$approvalToken],$tenantId);
    $approved=($okApprove['data']['run']??[]);
    $bundleStmt=$pdo->prepare("SELECT COUNT(*) FROM harpp_artifact_bundles WHERE aggregate_type='run' AND aggregate_id=:id");
    $bundleStmt->execute([':id'=>$runId1]);
    $bundleCount=(int)$bundleStmt->fetchColumn();
    $assert('correct approval token succeeds + builds bundle',
        !empty($okApprove['ok'])&&($approved['state']??'')==='SUCCEEDED'&&($approved['approved_at']??null)!==null&&(int)($approved['approval_required']??1)===0&&$bundleCount===1,
        'approve='.json_encode($okApprove).' bundles='.$bundleCount);

    // LOW result goes straight to SUCCEEDED without approval.
    [$runId2,$token2]=$startRun();
    $comp2=$bridge->completeRun($actor,$runId2,['claim_token'=>$token2,'status'=>'Done.','result'=>['marker'=>'OK']],$tenantId);
    $low=(array)($comp2['data']['run']??[]);
    $assert('low result goes straight to succeeded',
        !empty($comp2['ok'])&&($low['state']??'')==='SUCCEEDED'&&(int)($low['approval_required']??1)===0&&($low['risk_level']??'')==='LOW'&&($low['approved_at']??null)===null,
        'comp2='.json_encode($comp2));

    // Reject a third AWAITING_APPROVAL run via rejectRun -> CANCELLED.
    [$runId3,$token3]=$startRun();
    $comp3=$bridge->completeRun($actor,$runId3,['claim_token'=>$token3,'status'=>'Done.','result'=>['action'=>'purge old records']],$tenantId);
    $aw3=(array)($comp3['data']['run']??[]);
    $rejected=$svc->rejectRun($actor,$runId3,['rationale'=>'Not authorized for this tenant.'],$tenantId);
    $cancelled=($rejected['data']['run']??[]);
    $assert('awaiting run can be rejected to cancelled',
        ($aw3['state']??'')==='AWAITING_APPROVAL'&&!empty($rejected['ok'])&&($cancelled['state']??'')==='CANCELLED'&&($cancelled['finished_at']??null)!==null,
        'aw3='.json_encode($aw3).' rejected='.json_encode($rejected));
} finally {
    foreach ($runIds as $id) { if($id>0)$db->prepare('DELETE FROM harpp_work_runs WHERE id=:id')->execute([':id'=>$id]); }
    foreach ($runIds as $id) { if($id>0)$db->prepare("DELETE FROM harpp_artifact_bundles WHERE aggregate_type='run' AND aggregate_id=:id")->execute([':id'=>$id]); }
    $db->prepare("DELETE FROM harpp_runners WHERE runner_key='risk-gate-test'")->execute();
    if($conversationId>0)$db->prepare('DELETE FROM harpp_notifications WHERE conversation_id=:id')->execute([':id'=>$conversationId]);
    foreach ($messageIds as $id) { if($id>0)$db->prepare('DELETE FROM harpp_messages WHERE id=:id')->execute([':id'=>$id]); }
    if($conversationId>0)$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$conversationId]);
}

$h->done();
