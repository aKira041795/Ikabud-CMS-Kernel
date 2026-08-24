<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$logs = [$root.'/storage/logs/app.log', $root.'/storage/logs/error.log'];
foreach ($logs as $log) { if (is_file($log)) file_put_contents($log, ''); }
require $root.'/bootstrap.php';
$tenantId=(int)($_SERVER['argv'][1]??1);app()->tenant()->setTenantId($tenantId);require_once dirname(__DIR__).'/helpers.php';

use Harpp\Services\HarppBridgeAuthService;
use Harpp\Services\HarppBridgeService;
use Harpp\Services\HarppDecisionService;
use Harpp\Services\HarppMessagingService;

$manifest=json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'),true,512,JSON_THROW_ON_ERROR);
$db=new \Ikabud\Kernel\Contracts\ModuleDB(app()->dbForTenant($tenantId),'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$owner['id']=(int)$owner['id'];$owner['source']='harpp';
$oldSettings=[];$s=$db->query("SELECT setting_key,setting_value FROM harpp_settings WHERE setting_key IN ('bridge_api_key_hash','bridge_api_key_rotated_at')");foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row)$oldSettings[$row['setting_key']]=$row['setting_value'];
require_once $root . '/tests/harness/TestHarness.php';
ob_start();
$h = new TestHarness('harpp-phase5');
$h->fingerprint('modules/harpp/services/HarppBridgeAuthService.php');
$h->fingerprint('modules/harpp/services/HarppBridgeService.php');
$h->fingerprint('modules/harpp/services/HarppDecisionService.php');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$decisionId=0;$decisionConversation=0;$messageConversation=0;$messageId=0;$ownerMessageId=0;$statusNotificationId=0;
try {
    $auth=new HarppBridgeAuthService($db);$issued=$auth->rotate($tenantId);$key=(string)($issued['data']['key']??'');
    $valid=$auth->validate($key,$tenantId,'phase5-valid');$actor=(array)($valid['data']['actor']??[]);
    $assert('valid bridge key authenticates',!empty($valid['ok'])&&($actor['source']??'')==='harpp_bridge');
    $missing=$auth->validate('',$tenantId,'phase5-missing');$wrong=$auth->validate('wrong-key',$tenantId,'phase5-wrong');
    $assert('missing bridge key rejected (401)',empty($missing['ok'])&&($missing['status']??0)===401);
    $assert('wrong bridge key rejected (401)',empty($wrong['ok'])&&($wrong['status']??0)===401);
    $limited=[];for($attempt=0;$attempt<6;$attempt++)$limited=$auth->validate('wrong-key',$tenantId,'phase5-throttle');
    $assert('bridge auth failures are rate limited (429)',empty($limited['ok'])&&($limited['status']??0)===429);
    $appNow=is_file($logs[0])?(string)file_get_contents($logs[0]):'';
    $assert('bridge auth failure logged',str_contains($appNow,'HARPP bridge auth failure'));

    $bridge=new HarppBridgeService($db);$created=$bridge->createDecision($actor,[
        'title'=>'Phase 5 bridge lifecycle','body'=>'Harness requires an owner choice.','context'=>'Bridge CLI verification',
        'requested_decision'=>'Approve the safe bridge path','priority'=>'high','source'=>'pi','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED',
        'decision_key'=>'BRIDGE-'.strtoupper(bin2hex(random_bytes(6)))
    ],$tenantId);
    $decisionId=(int)($created['data']['decision_id']??0);$decisionConversation=(int)($created['data']['conversation_id']??0);
    $assert('bridge decision create returns PENDING',!empty($created['ok'])&&$decisionId>0&&($created['data']['state']??'')==='PENDING');
    $domain=new HarppDecisionService($db);
    foreach(['NOTIFIED','VIEWED','DECIDED'] as $state){$changes=$state==='DECIDED'?['decision'=>'Approve the safe bridge path']:[];$r=$domain->transition($owner,$decisionId,$state,'Owner CLI '.$state,$changes,$tenantId);$assert('owner transition '.$state,!empty($r['ok']),(string)($r['error']??''));}
    $ack=$bridge->acknowledge($actor,$decisionId,[],$tenantId);$applied=$bridge->applied($actor,$decisionId,[],$tenantId);
    $assert('bridge acknowledge succeeds',!empty($ack['ok'])&&($ack['data']['state']??'')==='ACKNOWLEDGED');
    $assert('bridge applied closes decision',!empty($applied['ok'])&&($applied['data']['applied_state']??'')==='APPLIED'&&($applied['data']['state']??'')==='CLOSED');
    $illegal=$bridge->acknowledge($actor,$decisionId,[],$tenantId);
    $assert('illegal bridge transition rejected',empty($illegal['ok'])&&($illegal['code']??'')==='illegal_transition');
    $filtered=$bridge->listDecisions($actor,['state'=>'CLOSED','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED'],$tenantId);
    $assert('bridge decision poll filters state/workbench',!empty($filtered['ok'])&&count(array_filter((array)($filtered['data']['decisions']??[]),fn($d)=>(int)$d['id']===$decisionId))===1);

    $sent=$bridge->sendMessage($actor,['title'=>'Phase 5 bridge thread','harness_session_id'=>'phase5-'.bin2hex(random_bytes(4)),'body'=>'Harness message for the owner.'],$tenantId);
    $messageId=(int)($sent['data']['message_id']??0);$messageConversation=(int)($sent['data']['conversation_id']??0);
    $ownerPoll=(new HarppMessagingService($db))->listMessages($owner,$messageConversation,['after_id'=>0],$tenantId);
    $ownerRows=(array)($ownerPoll['data']['messages']??[]);
    $assert('bridge message send appears in owner poll',!empty($sent['ok'])&&count(array_filter($ownerRows,fn($m)=>(int)$m['id']===$messageId&&$m['sender_type']==='harness'))===1);
    $ownerSent=(new HarppMessagingService($db))->sendMessage($owner,$messageConversation,['body'=>'Owner reply for harness.','sender_type'=>'user'],$tenantId);$ownerMessageId=(int)($ownerSent['data']['message_id']??0);
    $harnessPoll=$bridge->pollMessages($actor,['cursor'=>0,'conversation_id'=>$messageConversation],$tenantId);
    $assert('bridge owner-message poll advances cursor',!empty($harnessPoll['ok'])&&count((array)$harnessPoll['data']['messages'])===1&&(int)$harnessPoll['data']['next_cursor']===$ownerMessageId);

    $status=$bridge->status($actor,['status'=>'waiting','harness_session_id'=>'phase5-status','message'=>'Waiting for next task.'],$tenantId);$statusNotificationId=(int)($status['data']['notification_id']??0);
    $check=$db->prepare("SELECT COUNT(*) FROM harpp_notifications WHERE id=:id AND notification_type='system'");$check->execute([':id'=>$statusNotificationId]);
    $assert('bridge status creates owner notification',!empty($status['ok'])&&$statusNotificationId>0&&(int)$check->fetchColumn()===1);

    $rotated=$auth->rotate($tenantId);$newKey=(string)($rotated['data']['key']??'');$oldRejected=$auth->validate($key,$tenantId,'phase5-old-after-rotate');$newValid=$auth->validate($newKey,$tenantId,'phase5-new-after-rotate');
    $assert('key rotation invalidates old key',empty($oldRejected['ok'])&&($oldRejected['status']??0)===401&&!empty($newValid['ok']));
} finally {
    $db->prepare("DELETE FROM harpp_settings WHERE setting_key LIKE 'bridge_auth_rate_%'")->execute();
    if($statusNotificationId>0)$db->prepare('DELETE FROM harpp_notifications WHERE id=:id')->execute([':id'=>$statusNotificationId]);
    foreach(array_unique(array_filter([$decisionConversation,$messageConversation])) as $cid)$db->prepare('DELETE FROM harpp_notifications WHERE conversation_id=:id')->execute([':id'=>$cid]);
    if($decisionId>0){$db->prepare('DELETE FROM harpp_adrs WHERE decision_ref=:id')->execute([':id'=>$decisionId]);$db->prepare('DELETE FROM harpp_notifications WHERE decision_id=:id')->execute([':id'=>$decisionId]);$db->prepare('DELETE FROM harpp_decisions WHERE id=:id')->execute([':id'=>$decisionId]);}
    foreach(array_unique(array_filter([$decisionConversation,$messageConversation])) as $cid)$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$cid]);
    $db->prepare("DELETE FROM harpp_settings WHERE setting_key IN ('bridge_api_key_hash','bridge_api_key_rotated_at')")->execute();
    $restore=$db->prepare('INSERT INTO harpp_settings(setting_key,setting_value,updated_at) VALUES(:key,:value,NOW())');foreach($oldSettings as $keyName=>$value)$restore->execute([':key'=>$keyName,':value'=>$value]);
}
$appLog=is_file($logs[0])?(string)file_get_contents($logs[0]):'';$errorLog=is_file($logs[1])?(string)file_get_contents($logs[1]):'';
$assert('app.log contains expected auth audit only',str_contains($appLog,'HARPP bridge auth failure')&&!str_contains(strtolower($appLog),'[error]'));
$assert('error.log has no findings',trim($errorLog)==='',trim($errorLog));
ob_end_flush();
$h->done();
