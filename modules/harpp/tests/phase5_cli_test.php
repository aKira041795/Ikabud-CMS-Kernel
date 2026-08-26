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
$decisionId=0;$decisionConversation=0;$cancelDecisionId=0;$cancelConversation=0;$ownerDecisionId=0;$ownerDecisionConversation=0;$messageConversation=0;$messageId=0;$ownerMessageId=0;$statusNotificationId=0;
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

    $ownerChoice=$bridge->createDecision($actor,['title'=>'Phase 5 owner messenger decision','body'=>'Owner chose via messenger.','context'=>'Messenger decision test','requested_decision'=>'Confirm your choice','priority'=>'normal','source'=>'harness','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED','decision_key'=>'BRIDGE-DECIDE-'.strtoupper(bin2hex(random_bytes(6)))],$tenantId);
    $ownerDecisionId=(int)($ownerChoice['data']['decision_id']??0);$ownerDecisionConversation=(int)($ownerChoice['data']['conversation_id']??0);
    (new HarppDecisionService($db))->transition($owner,$ownerDecisionId,'NOTIFIED','Notify owner',[],$tenantId);
    $viewedViaBridge=$bridge->view($actor,$ownerDecisionId,['rationale'=>'Owner opened the decision via messenger.'],$tenantId);
    $decidedViaBridge=$bridge->decide($actor,$ownerDecisionId,['decision'=>'Option A','rationale'=>'Owner replied Option A via messenger.'],$tenantId);
    $dRow=$db->prepare("SELECT lifecycle_state,decision,decided_by FROM harpp_decisions WHERE id=:id");$dRow->execute([':id'=>$ownerDecisionId]);$d=$dRow->fetch(PDO::FETCH_ASSOC);
    $adrN=$db->prepare("SELECT COUNT(*) FROM harpp_adrs WHERE decision_ref=:id");$adrN->execute([':id'=>$ownerDecisionId]);
    $assert('bridge view marks decision viewed',!empty($viewedViaBridge['ok'])&&($viewedViaBridge['data']['state']??'')==='VIEWED');
    $assert('bridge decide records owner decision via messenger',!empty($decidedViaBridge['ok'])&&($d['lifecycle_state']??'')==='DECIDED'&&($d['decision']??'')==='Option A'&&(int)$d['decided_by']===(int)$owner['id']&&(int)$adrN->fetchColumn()===1);
    $missingDecision=$bridge->decide($actor,$ownerDecisionId,['rationale'=>'No choice text'],$tenantId);
    $assert('bridge decide rejects missing decision text',empty($missingDecision['ok']));

    $stale=$bridge->createDecision($actor,['title'=>'Phase 5 stale bridge decision','body'=>'This request is no longer needed.','requested_decision'=>'Choose an abandoned option','priority'=>'normal','source'=>'harness','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED','decision_key'=>'BRIDGE-CANCEL-'.strtoupper(bin2hex(random_bytes(6)))],$tenantId);
    $cancelDecisionId=(int)($stale['data']['decision_id']??0);$cancelConversation=(int)($stale['data']['conversation_id']??0);
    $notified=(new HarppDecisionService($db))->transition($owner,$cancelDecisionId,'NOTIFIED','Notify owner before request became stale.',[],$tenantId);
    $cancelled=$bridge->cancel($actor,$cancelDecisionId,[],$tenantId);
    $actionable=$bridge->listDecisions($actor,['state'=>'NOTIFIED'],$tenantId);
    $cancelledRows=$bridge->listDecisions($actor,['state'=>'CANCELLED'],$tenantId);
    $assert('bridge cancels stale NOTIFIED decision with default rationale',!empty($notified['ok'])&&!empty($cancelled['ok'])&&($cancelled['data']['state']??'')==='CANCELLED');
    $assert('cancelled decision is no longer actionable by lifecycle',!HarppDecisionService::isTransitionAllowed('CANCELLED','VIEWED')&&count(array_filter((array)($actionable['data']['decisions']??[]),fn($d)=>(int)$d['id']===$cancelDecisionId))===0&&count(array_filter((array)($cancelledRows['data']['decisions']??[]),fn($d)=>(int)$d['id']===$cancelDecisionId))===1);

    $domain=new HarppDecisionService($db);
    foreach(['NOTIFIED','VIEWED','DECIDED'] as $state){$changes=$state==='DECIDED'?['decision'=>'Approve the safe bridge path']:[];$r=$domain->transition($owner,$decisionId,$state,'Owner CLI '.$state,$changes,$tenantId);$assert('owner transition '.$state,!empty($r['ok']),(string)($r['error']??''));}
    $ack=$bridge->acknowledge($actor,$decisionId,[],$tenantId);$applied=$bridge->applied($actor,$decisionId,[],$tenantId);
    $assert('bridge acknowledge succeeds',!empty($ack['ok'])&&($ack['data']['state']??'')==='ACKNOWLEDGED');
    $assert('bridge applied closes decision',!empty($applied['ok'])&&($applied['data']['applied_state']??'')==='APPLIED'&&($applied['data']['state']??'')==='CLOSED');
    $illegal=$bridge->acknowledge($actor,$decisionId,[],$tenantId);
    $illegalCancel=$bridge->cancel($actor,$decisionId,['rationale'=>'Closed decisions must remain closed.'],$tenantId);
    $assert('illegal bridge transition rejected',empty($illegal['ok'])&&($illegal['code']??'')==='illegal_transition');
    $assert('bridge rejects CLOSED to CANCELLED transition',empty($illegalCancel['ok'])&&($illegalCancel['code']??'')==='illegal_transition');
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
    $conversationList=$bridge->listConversations($actor,[],$tenantId);
    $openArchive=$bridge->archiveConversation($actor,$messageConversation,['archived'=>true],$tenantId);
    (new HarppMessagingService($db))->closeConversation($owner,$messageConversation,$tenantId);
    $archived=$bridge->archiveConversation($actor,$messageConversation,['archived'=>true],$tenantId);
    $archivedList=$bridge->listConversations($actor,['archived'=>1],$tenantId);
    $restored=$bridge->archiveConversation($actor,$messageConversation,['archived'=>false],$tenantId);
    $assert('bridge conversation list exposes metadata',!empty($conversationList['ok'])&&count(array_filter((array)($conversationList['data']['conversations']??[]),fn($c)=>(int)$c['id']===$messageConversation&&array_key_exists('status',$c)&&array_key_exists('archived_at',$c)&&array_key_exists('unread',$c)))===1);
    $assert('bridge archive preserves closed-only conflict',empty($openArchive['ok'])&&($openArchive['status']??0)===409);
    $assert('bridge archive filter and restore round-trip',!empty($archived['ok'])&&!empty($restored['ok'])&&count(array_filter((array)($archivedList['data']['conversations']??[]),fn($c)=>(int)$c['id']===$messageConversation&&$c['archived_at']!==null))===1);
    $notificationList=$bridge->listNotifications($actor,['include_read'=>1,'limit'=>100,'offset'=>0],$tenantId);
    $unreadBefore=$bridge->notificationUnreadCount($actor,$tenantId);
    $markedNotification=$bridge->markNotificationRead($actor,$statusNotificationId,$tenantId);
    $unreadAfter=$bridge->notificationUnreadCount($actor,$tenantId);
    $assert('bridge notification list exposes paging',!empty($notificationList['ok'])&&($notificationList['data']['limit']??0)===100&&($notificationList['data']['offset']??-1)===0&&count(array_filter((array)($notificationList['data']['notifications']??[]),fn($n)=>(int)$n['id']===$statusNotificationId))===1);
    $assert('bridge notification mark-read updates unread count',!empty($markedNotification['ok'])&&(int)($unreadAfter['data']['unread']??-1)===(int)($unreadBefore['data']['unread']??0)-1);

    $rotated=$auth->rotate($tenantId);$newKey=(string)($rotated['data']['key']??'');$oldRejected=$auth->validate($key,$tenantId,'phase5-old-after-rotate');$newValid=$auth->validate($newKey,$tenantId,'phase5-new-after-rotate');
    $assert('key rotation invalidates old key',empty($oldRejected['ok'])&&($oldRejected['status']??0)===401&&!empty($newValid['ok']));
} finally {
    $db->prepare("DELETE FROM harpp_settings WHERE setting_key LIKE 'bridge_auth_rate_%'")->execute();
    if($statusNotificationId>0)$db->prepare('DELETE FROM harpp_notifications WHERE id=:id')->execute([':id'=>$statusNotificationId]);
    foreach(array_unique(array_filter([$decisionConversation,$cancelConversation,$ownerDecisionConversation,$messageConversation])) as $cid)$db->prepare('DELETE FROM harpp_notifications WHERE conversation_id=:id')->execute([':id'=>$cid]);
    foreach(array_unique(array_filter([$decisionId,$cancelDecisionId,$ownerDecisionId])) as $id){$db->prepare('DELETE FROM harpp_adrs WHERE decision_ref=:id')->execute([':id'=>$id]);$db->prepare('DELETE FROM harpp_notifications WHERE decision_id=:id')->execute([':id'=>$id]);$db->prepare('DELETE FROM harpp_decisions WHERE id=:id')->execute([':id'=>$id]);}
    foreach(array_unique(array_filter([$decisionConversation,$cancelConversation,$ownerDecisionConversation,$messageConversation])) as $cid)$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$cid]);
    $db->prepare("DELETE FROM harpp_settings WHERE setting_key IN ('bridge_api_key_hash','bridge_api_key_rotated_at')")->execute();
    $restore=$db->prepare('INSERT INTO harpp_settings(setting_key,setting_value,updated_at) VALUES(:key,:value,NOW())');foreach($oldSettings as $keyName=>$value)$restore->execute([':key'=>$keyName,':value'=>$value]);
}
$appLog=is_file($logs[0])?(string)file_get_contents($logs[0]):'';$errorLog=is_file($logs[1])?(string)file_get_contents($logs[1]):'';
$assert('app.log contains expected auth audit only',str_contains($appLog,'HARPP bridge auth failure')&&!str_contains(strtolower($appLog),'[error]'));
$assert('error.log has no findings',trim($errorLog)==='',trim($errorLog));
ob_end_flush();
$h->done();
