<?php

declare(strict_types=1);

// S1 MCP spine CLI test: verifies the bridge surfaces the approved decision's
// artifact bundle (ADR + decision) and run/runner status end-to-end against a
// tenant, exactly as an MCP client would via the bridge endpoints.
//
// Run:  php modules/harpp/tests/mcp_spine_cli_test.php 1

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
// Apply migrations idempotently (013..016) so the runner queue + artifact tables exist.
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/013_harpp_runner_work_queue.sql'));
$cols=array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='harpp_work_runs'")->fetchAll(PDO::FETCH_ASSOC),'COLUMN_NAME');
if(!in_array('attempt_count',$cols,true))$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/014_harpp_runner_reconcile.sql'));
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/015_harpp_context_summary.sql'));
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/016_harpp_artifact_bundle.sql'));
$db=new \Ikabud\Kernel\Contracts\ModuleDB($pdo,'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$owner['id']=(int)$owner['id'];$owner['source']='harpp';

$h = new TestHarness('harpp-mcp-spine');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$bridge = new HarppBridgeService($db);
$bridgeActor = $owner; $bridgeActor['source'] = 'harpp_bridge'; // bridge actor (role owner)

$cleanup=[]; // decisionId, conversationId, runId
try {
    // 1) Decision -> CLOSED auto-builds the artifact bundle; bridge can read it.
    $decisionSvc=new HarppDecisionService($db);
    $dc=$decisionSvc->create($owner,['title'=>'Spine decision','body'=>'body','requested_decision'=>'pick','decision_key'=>'SPINE-'.bin2hex(random_bytes(3)),'priority'=>'normal','source'=>'harness','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED'],$tenantId);
    $decisionId=(int)($dc['data']['decision_id']??0);$dconv=(int)($dc['data']['conversation_id']??0);$cleanup[]=['decisionId'=>$decisionId,'conv'=>$dconv,'run'=>0];
    $cl=$decisionSvc->transition($owner,$decisionId,'CLOSED','close to approve',[],$tenantId);
    $assert('CLOSED decision transitions',!empty($cl['ok'])&&$decisionId>0,'decisionId='.$decisionId);

    $bundleRes=$bridge->artifactBundleForDecision($bridgeActor,$decisionId,$tenantId);
    $bTypes=array_column((array)($bundleRes['data']['artifacts']??[]),'artifact_type');
    $assert('bridge reads approved decision artifact bundle (adr + decision)',
        !empty($bundleRes['ok'])&&!empty($bundleRes['data']['bundle'])&&in_array('adr',$bTypes,true)&&in_array('decision',$bTypes,true),
        'ok='.var_export(!empty($bundleRes['ok']),true).' types='.implode(',',array_values($bTypes)));
    $hasPayload=!empty($bundleRes['data']['artifacts'][0]['payload'])||!empty($bundleRes['data']['artifacts'][1]['payload']);
    $assert('artifact payloads included for owner/agent read',$hasPayload,'payload='.var_export($hasPayload,true));

    // 2) Runner register -> claim -> running -> complete; then status + list.
    $reg=$bridge->registerRunner($bridgeActor,['runner_key'=>'spine-runner','display_name'=>'Spine Runner','capabilities'=>['spine-test']],$tenantId);
    $assert('runner registers online',!empty($reg['ok'])&&($reg['data']['status']??'')==='online','reg='.json_encode($reg));

    $messaging=new HarppMessagingService($db);
    $created=$messaging->createConversation($owner,['title'=>'Spine run','harness_session_id'=>'spine-run'],$tenantId);
    $conv=(int)($created['data']['conversation_id']??0);$cleanup[]=['decisionId'=>0,'conv'=>$conv,'run'=>0];
    $sent=$messaging->sendMessage($owner,$conv,['body'=>'run me','sender_type'=>'user'],$tenantId);
    $msgId=(int)($sent['data']['message_id']??0);
    $q=$bridge->queueMessageRun($bridgeActor,['message_id'=>$msgId,'required_capabilities'=>['spine-test']],$tenantId);
    $runId=(int)(($q['data']['run']??[])['id']??0);$cleanup[count($cleanup)-1]['run']=$runId;
    $assert('run queued for the spine runner',$runId>0,'runId='.$runId);

    $claim=$bridge->claimRun($bridgeActor,['runner_key'=>'spine-runner','lease_seconds'=>120],$tenantId);
    $rToken=(string)($claim['data']['claim_token']??'');
    $assert('runner claims the run',$rToken!==''&&($claim['data']['run']['state']??'')==='CLAIMED','claim='.json_encode($claim));
    $bridge->runRunning($bridgeActor,$runId,['claim_token'=>$rToken,'status'=>'Running.'],$tenantId);
    $done=$bridge->completeRun($bridgeActor,$runId,['claim_token'=>$rToken,'status'=>'Done.','result'=>['marker'=>'HARPP_WAKE_RESULT','contract'=>'status: READY_FOR_IMPLEMENTATION']],$tenantId);
    $assert('run completes as SUCCEEDED',!empty($done['ok'])&&($done['data']['run']['state']??'')==='SUCCEEDED','done='.json_encode($done));

    $rs=$bridge->runStatus($bridgeActor,$runId,$tenantId);
    $assert('bridge run status reports SUCCEEDED',!empty($rs['ok'])&&($rs['data']['run']['state']??'')==='SUCCEEDED','state='.($rs['data']['run']['state']??'?'));

    $lr=$bridge->listRunners($bridgeActor,$tenantId);
    $found=false;$lrStatus='';
    foreach((array)($lr['data']['runners']??[]) as $r){if(($r['runner_key']??'')==='spine-runner'){$found=true;$lrStatus=(string)($r['status']??'');}}
    $assert('bridge lists the runner with status',!empty($lr['ok'])&&$found&&$lrStatus==='online','status='.$lrStatus.' found='.var_export($found,true));

    // 3) Bridge can get a single decision detail (agent "latest approved ADR").
    $gd=$bridge->getDecision($bridgeActor,$decisionId,$tenantId);
    $assert('bridge gets decision detail',!empty($gd['ok'])&&(($gd['data']['decision']['lifecycle_state']??'')==='CLOSED')&&($gd['data']['decision']['title']??'')==='Spine decision','gd='.json_encode($gd));
} finally {
    // Clean up created rows (reverse order).
    $db->prepare("DELETE FROM harpp_runners WHERE runner_key='spine-runner'")->execute();
    foreach (array_reverse($cleanup) as $row) {
        $decisionId=(int)$row['decisionId'];$conv=(int)$row['conv'];$runId=(int)$row['run'];
        if($runId>0){$b=$db->prepare("SELECT id FROM harpp_artifact_bundles WHERE aggregate_type='run' AND aggregate_id=:id");$b->execute([':id'=>$runId]);$bid=(int)$b->fetchColumn();if($bid>0)$db->prepare('DELETE FROM harpp_artifact_bundles WHERE id=:id')->execute([':id'=>$bid]);$db->prepare('DELETE FROM harpp_work_runs WHERE id=:id')->execute([':id'=>$runId]);}
        if($decisionId>0){$b=$db->prepare("SELECT id FROM harpp_artifact_bundles WHERE aggregate_type='decision' AND aggregate_id=:id");$b->execute([':id'=>$decisionId]);$bid=(int)$b->fetchColumn();if($bid>0)$db->prepare('DELETE FROM harpp_artifact_bundles WHERE id=:id')->execute([':id'=>$bid]);$db->prepare('DELETE FROM harpp_adrs WHERE decision_ref=:id')->execute([':id'=>$decisionId]);$db->prepare('DELETE FROM harpp_notifications WHERE decision_id=:id OR conversation_id=:c')->execute([':id'=>$decisionId,':c'=>$conv]);$db->prepare('DELETE FROM harpp_decisions WHERE id=:id')->execute([':id'=>$decisionId]);}
        if($conv>0){$db->prepare('DELETE FROM harpp_notifications WHERE conversation_id=:id')->execute([':id'=>$conv]);$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$conv]);}
    }
}

$h->done();
