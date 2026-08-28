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

use Harpp\Services\HarppArtifactService;
use Harpp\Services\HarppBridgeService;
use Harpp\Services\HarppDecisionService;
use Harpp\Services\HarppMessagingService;

$manifest=json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=app()->dbForTenant($tenantId);
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/013_harpp_runner_work_queue.sql'));
$cols=array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='harpp_work_runs'")->fetchAll(PDO::FETCH_ASSOC),'COLUMN_NAME');
if(!in_array('attempt_count',$cols,true))$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/014_harpp_runner_reconcile.sql'));
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/015_harpp_context_summary.sql'));
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/016_harpp_artifact_bundle.sql'));
$db=new \Ikabud\Kernel\Contracts\ModuleDB($pdo,'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$owner['id']=(int)$owner['id'];$owner['source']='harpp';

// Disposable reviewer (member) user.
$reviewerEmail='reviewer-'.bin2hex(random_bytes(3)).'@harpp.local';
$db->prepare("INSERT INTO harpp_users (email,full_name,password_hash,role,is_active,created_at) VALUES (:e,'Reviewer',:p,'member',1,NOW())")->execute([':e'=>$reviewerEmail,':p'=>password_hash('x',PASSWORD_BCRYPT)]);
$reviewer=$db->prepare("SELECT id,role FROM harpp_users WHERE email=:e");$reviewer->execute([':e'=>$reviewerEmail]);
$reviewerRow=$reviewer->fetch(PDO::FETCH_ASSOC);$reviewerId=(int)$reviewerRow['id'];
$reviewerActor=['id'=>$reviewerId,'role'=>'member','source'=>'harpp'];

$h = new TestHarness('harpp-artifact-bundle');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$artifacts=new HarppArtifactService($db);
$cleanup=[]; // decisionId, conversationId, runId
try {
    $decisionSvc=new HarppDecisionService($db);
    $dc=$decisionSvc->create($owner,['title'=>'Artifact decision','body'=>'body','requested_decision'=>'pick','decision_key'=>'ART-'.bin2hex(random_bytes(3)),'priority'=>'normal','source'=>'harness','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED'],$tenantId);
    $decisionId=(int)($dc['data']['decision_id']??0);$dconv=(int)($dc['data']['conversation_id']??0);$cleanup[]=['decisionId'=>$decisionId,'conv'=>$dconv,'run'=>0];
    $cl=$decisionSvc->transition($owner,$decisionId,'CLOSED','close to approve',[],$tenantId);
    $bundleRow=$db->prepare("SELECT id FROM harpp_artifact_bundles WHERE aggregate_type='decision' AND aggregate_id=:id");$bundleRow->execute([':id'=>$decisionId]);$bundleId=(int)$bundleRow->fetchColumn();
    $assert('CLOSED decision auto-builds an artifact bundle',!empty($cl['ok'])&&$bundleId>0,'bundleId='.$bundleId);
    $view=$artifacts->view($bundleId,$owner,$tenantId);
    $types=array_column((array)($view['data']['artifacts']??[]),'artifact_type');
    $assert('decision bundle contains adr + decision artifacts',$view['ok']&&in_array('adr',$types,true)&&in_array('decision',$types,true),'types='.implode(',',array_values($types)));

    // Owner attaches a downloadable file artifact.
    $fileContent='def review(): return 42  # generated file body';
    $att=$artifacts->attachFile($bundleId,$owner,['filename'=>'review.txt','mime'=>'text/plain','content'=>$fileContent],$tenantId);
    $fileArtifactId=(int)($att['data']['artifact_id']??0);
    $assert('owner attaches a downloadable file artifact',!empty($att['ok'])&&$fileArtifactId>0&&($att['data']['file_size']??0)===strlen($fileContent),'att='.json_encode($att));
    $dl=$artifacts->downloadFile($fileArtifactId,$owner,$tenantId);
    $assert('owner downloads the file content',!empty($dl['ok'])&&($dl['data']['content']??'')===$fileContent&&($dl['data']['filename']??'')==='review.txt','dl='.json_encode($dl));

    // Addressed share + view-only resolve + reviewer file download.
    $share=$artifacts->createShare($bundleId,$owner,['reviewer_user_id'=>$reviewerId,'ttl_hours'=>24],$tenantId);
    $token=(string)($share['data']['token']??'');
    $shareId=(int)($share['data']['share_id']??0);
    $assert('owner creates an addressed share with a token',!empty($share['ok'])&&$token!==''&&$shareId>0,'share='.json_encode($share));
    $resolved=$artifacts->resolveShare($token,$reviewerActor,$tenantId);
    $rTypes=array_column((array)($resolved['data']['artifacts']??[]),'artifact_type');
    $assert('addressed reviewer resolves the share view-only',!empty($resolved['ok'])&&in_array('file',$rTypes,true)&&in_array('adr',$rTypes,true),'resolved='.json_encode($resolved));
    $shareDl=$artifacts->shareDownloadFile($token,$fileArtifactId,$reviewerActor,$tenantId);
    $assert('reviewer downloads the shared file',!empty($shareDl['ok'])&&($shareDl['data']['content']??'')===$fileContent,'shareDl='.json_encode($shareDl));

    // Revocation + wrong reviewer denial.
    $revoke=$artifacts->revokeShare($shareId,$owner,$tenantId);
    $afterRevoke=$artifacts->resolveShare($token,$reviewerActor,$tenantId);
    $assert('revoked share is denied',!empty($revoke['ok'])&&empty($afterRevoke['ok'])&&($afterRevoke['code']??'')==='share_revoked','afterRevoke='.json_encode($afterRevoke));
    $share2=$artifacts->createShare($bundleId,$owner,['reviewer_user_id'=>$reviewerId,'ttl_hours'=>24],$tenantId);
    $token2=(string)($share2['data']['token']??'');
    $otherActor=['id'=>max(2,$reviewerId+1),'role'=>'member','source'=>'harpp'];
    $wrong=$artifacts->resolveShare($token2,$otherActor,$tenantId);
    $assert('non-addressed user cannot resolve a share',empty($wrong['ok'])&&($wrong['code']??'')==='share_not_addressed','wrong='.json_encode($wrong));

    // SUCCEEDED run auto-builds a run bundle with stage_result.
    $bridge=new HarppBridgeService($db);$messaging=new HarppMessagingService($db);
    $bridgeActor=$owner;$bridgeActor['source']='harpp_bridge'; // bridge run APIs require this source
    $created=$messaging->createConversation($owner,['title'=>'Artifact run','harness_session_id'=>'art-run'],$tenantId);
    $conv=(int)($created['data']['conversation_id']??0);$cleanup[]=['decisionId'=>0,'conv'=>$conv,'run'=>0];
    $sent=$messaging->sendMessage($owner,$conv,['body'=>'run me','sender_type'=>'user'],$tenantId);
    $msgId=(int)($sent['data']['message_id']??0);
    $q=$bridge->queueMessageRun($bridgeActor,['message_id'=>$msgId,'required_capabilities'=>['art-test']],$tenantId);
    $runId=(int)(($q['data']['run']??[])['id']??0);$cleanup[count($cleanup)-1]['run']=$runId;
    $bridge->registerRunner($bridgeActor,['runner_key'=>'art-runner','display_name'=>'Art','capabilities'=>['art-test','shell']],$tenantId);
    $claim=$bridge->claimRun($bridgeActor,['runner_key'=>'art-runner','lease_seconds'=>120],$tenantId);
    $rToken=(string)($claim['data']['claim_token']??'');
    $bridge->runRunning($bridgeActor,$runId,['claim_token'=>$rToken,'status'=>'Running.'],$tenantId);
    $bridge->completeRun($bridgeActor,$runId,['claim_token'=>$rToken,'status'=>'Done.','result'=>['marker'=>'HARPP_WAKE_RESULT','contract'=>'status: READY_FOR_IMPLEMENTATION']],$tenantId);
    $rb=$db->prepare("SELECT id FROM harpp_artifact_bundles WHERE aggregate_type='run' AND aggregate_id=:id");$rb->execute([':id'=>$runId]);$runBundleId=(int)$rb->fetchColumn();
    $rv=$artifacts->view($runBundleId,$owner,$tenantId);
    $rTypes2=array_column((array)($rv['data']['artifacts']??[]),'artifact_type');
    $assert('SUCCEEDED run auto-builds a run bundle with stage_result',!empty($rv['ok'])&&$runBundleId>0&&in_array('stage_result',$rTypes2,true)&&in_array('contract',$rTypes2,true),'rTypes='.implode(',',$rTypes2));
} finally {
    $db->prepare("DELETE FROM harpp_runners WHERE runner_key='art-runner'")->execute();
    foreach (array_reverse($cleanup) as $row) {
        $decisionId=(int)$row['decisionId'];$conv=(int)$row['conv'];$runId=(int)$row['run'];
        if($runId>0){$b=$db->prepare("SELECT id FROM harpp_artifact_bundles WHERE aggregate_type='run' AND aggregate_id=:id");$b->execute([':id'=>$runId]);$bid=(int)$b->fetchColumn();if($bid>0)$db->prepare('DELETE FROM harpp_artifact_bundles WHERE id=:id')->execute([':id'=>$bid]);$db->prepare('DELETE FROM harpp_work_runs WHERE id=:id')->execute([':id'=>$runId]);}
        if($decisionId>0){$b=$db->prepare("SELECT id FROM harpp_artifact_bundles WHERE aggregate_type='decision' AND aggregate_id=:id");$b->execute([':id'=>$decisionId]);$bid=(int)$b->fetchColumn();if($bid>0)$db->prepare('DELETE FROM harpp_artifact_bundles WHERE id=:id')->execute([':id'=>$bid]);$db->prepare('DELETE FROM harpp_adrs WHERE decision_ref=:id')->execute([':id'=>$decisionId]);$db->prepare('DELETE FROM harpp_notifications WHERE decision_id=:id OR conversation_id=:c')->execute([':id'=>$decisionId,':c'=>$conv]);$db->prepare('DELETE FROM harpp_decisions WHERE id=:id')->execute([':id'=>$decisionId]);}
        if($conv>0){$db->prepare('DELETE FROM harpp_notifications WHERE conversation_id=:id')->execute([':id'=>$conv]);$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$conv]);}
    }
    $db->prepare('DELETE FROM harpp_users WHERE email=:e')->execute([':e'=>$reviewerEmail]);
}

$h->done();
