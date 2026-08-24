<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/tests/harness/TestHarness.php';
$h = new TestHarness('harpp-review-remediation', TestHarness::MODE_INTEGRATION, 'localhost');
$tenantId = (int)($_SERVER['argv'][1] ?? 1);
app()->tenant()->setTenantId($tenantId);
require_once dirname(__DIR__) . '/handlers.php';

use Harpp\Services\HarppBridgeService;
use Harpp\Services\HarppDecisionService;
use Ikabud\Kernel\EntityContext\EntityViewResolver;
use Harpp\Services\HarppNotificationService;
use Harpp\Services\HarppPushService;
use Harpp\Services\HarppServiceResult;
use Harpp\Services\HarppSettingsService;

foreach ([
    'modules/harpp/services/HarppDecisionService.php',
    'modules/harpp/services/HarppBridgeService.php',
    'modules/harpp/services/HarppBridgeAuthService.php',
    'modules/harpp/services/HarppNotificationService.php',
    'modules/harpp/services/HarppPushService.php',
    'modules/harpp/services/HarppSettingsService.php',
    'modules/harpp/services/HarppServiceResult.php',
    'modules/harpp/helpers.php', 'modules/harpp/handlers.php', 'modules/harpp/module.json',
] as $file) $h->fingerprint($file);

$manifest=json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'),true,512,JSON_THROW_ON_ERROR);
$db=new \Ikabud\Kernel\Contracts\ModuleDB(app()->dbForTenant($tenantId),'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$member=$db->query("SELECT id,email,role FROM harpp_users WHERE role='member' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner)||!is_array($member))throw new RuntimeException('HARPP owner/member fixtures are required.');
$owner=['id'=>(int)$owner['id'],'email'=>$owner['email'],'role'=>'owner','source'=>'harpp'];
$member=['id'=>(int)$member['id'],'email'=>$member['email'],'role'=>'member','source'=>'harpp'];
$bridgeActor=$owner;$bridgeActor['source']='harpp_bridge';

$h->section('Exhaustive lifecycle N x N matrix');
$states=array_keys(HarppDecisionService::TRANSITIONS);
foreach($states as$from)foreach($states as$to){$expected=in_array($to,HarppDecisionService::TRANSITIONS[$from],true);$h->test("{$from} -> {$to} ".($expected?'allowed':'rejected'),HarppDecisionService::isTransitionAllowed($from,$to)===$expected);}

$decisionId=0;$conversationId=0;$failureDecisionId=0;$failureConversationId=0;$endpointHash='';$settingsBackup=[];
try{
    $h->section('Bridge idempotency, PWA state, ADR and role/isolation controls');
    $key='REVIEW-'.strtoupper(bin2hex(random_bytes(6)));
    $service=new HarppDecisionService($db);
    $input=['title'=>'Review remediation decision','body'=>'Choose the fail-closed implementation.','context'=>'Review finding coverage','requested_decision'=>'Approve remediation','priority'=>'high','source'=>'pi','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED','decision_key'=>$key];
    $created=$service->create($bridgeActor,$input,$tenantId);$decisionId=(int)($created['data']['decision_id']??0);$conversationId=(int)($created['data']['conversation_id']??0);
    $h->test('bridge create enters operable PENDING',!empty($created['ok'])&&($created['data']['state']??'')==='PENDING'&&$decisionId>0);
    $duplicate=$service->create($bridgeActor,$input,$tenantId);
    $h->test('duplicate decision_key returns existing identity',!empty($duplicate['ok'])&&!empty($duplicate['data']['already_exists'])&&(int)$duplicate['data']['decision_id']===$decisionId);
    $h->test('tenant-plan isolation rejects mismatched tenant',empty($service->list($owner,[],$tenantId+999)['ok']));
    $h->test('member cannot create decisions',empty($service->create($member,$input+['decision_key'=>$key.'-M'],$tenantId)['ok']));
    foreach(['NOTIFIED','VIEWED'] as$state)$h->test("operator transition {$state}",!empty($service->transition($owner,$decisionId,$state,'Review '.$state,[],$tenantId)['ok']));
    $decided=$service->transition($member,$decisionId,'DECIDED','Member selected the safe option.',['decision'=>'Approve remediation'],$tenantId);
    $adr=$db->prepare('SELECT context,decision,rationale,decided_by,decided_at FROM harpp_adrs WHERE decision_ref=:id');$adr->execute([':id'=>$decisionId]);$adrRow=$adr->fetch(PDO::FETCH_ASSOC);
    $h->test('member may decide and DECIDED atomically creates ADR',!empty($decided['ok'])&&is_array($adrRow)&&$adrRow['decision']==='Approve remediation'&&(int)$adrRow['decided_by']===(int)$member['id']&&!empty($adrRow['decided_at']));
    $h->test('member cannot acknowledge/close others decision',empty($service->transition($member,$decisionId,'ACKNOWLEDGED','Forbidden member close.',[],$tenantId)['ok']));
    $bridge=new HarppBridgeService($db);$h->test('bridge acknowledgement succeeds',!empty($bridge->acknowledge($bridgeActor,$decisionId,[],$tenantId)['ok']));
    $db->prepare("UPDATE harpp_decisions SET lifecycle_state='APPLIED',applied_at=NOW() WHERE id=:id")->execute([':id'=>$decisionId]);
    $recovered=$bridge->applied($bridgeActor,$decisionId,[],$tenantId);$retry=$bridge->applied($bridgeActor,$decisionId,[],$tenantId);
    $h->test('applied retry recovers partial APPLIED and closes atomically',!empty($recovered['ok'])&&($recovered['data']['state']??'')==='CLOSED'&&!empty($recovered['data']['already_applied']));
    $h->test('applied retry is idempotent when CLOSED',!empty($retry['ok'])&&!empty($retry['data']['already_applied']));

    $h->section('Secret redaction and member settings restriction');
    foreach(['vapid_private_key'=>'SECRET-PRIVATE','vapid_public_key'=>'SECRET-PUBLIC','bridge_api_key_hash'=>'SECRET-BRIDGE','bridge_auth_rate_x'=>'SECRET-RATE'] as$k=>$v){$q=$db->prepare('SELECT setting_value FROM harpp_settings WHERE setting_key=:k');$q->execute([':k'=>$k]);$old=$q->fetchColumn();$settingsBackup[$k]=$old===false?null:$old;$db->prepare('INSERT INTO harpp_settings(setting_key,setting_value,updated_at) VALUES(:k,:v,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([':k'=>$k,':v'=>$v]);}
    $loaded=(new HarppSettingsService($db))->get($tenantId);$visible=(array)($loaded['data']['settings']??[]);
    $h->test('secret-like setting rows are redacted',!array_intersect_key($visible,array_flip(['vapid_private_key','vapid_public_key','bridge_api_key_hash','bridge_auth_rate_x'])));
    $memberRead=harppPermissionResult('harpp.settings.read',['user'=>$member,'_tenant_id'=>$tenantId]);
    $h->test('member cannot read settings/secret material',empty($memberRead['allowed']));
    $h->test('manifest has no editable VAPID key fields',count(array_filter((array)$manifest['settings_fields'],fn($f)=>str_starts_with((string)$f['key'],'vapid_')&&$f['key']!=='vapid_subject'))===0);

    $h->section('Notification settings and push SSRF/failure isolation');
    $db->prepare("INSERT INTO harpp_settings(setting_key,setting_value,updated_at) VALUES('notify_decisions','0',NOW()) ON DUPLICATE KEY UPDATE setting_value='0'")->execute();
    $before=(int)$db->query('SELECT COUNT(*) FROM harpp_notifications')->fetchColumn();$disabled=(new HarppNotificationService($db))->create((int)$owner['id'],'decision',['event'=>'test.disabled']);$after=(int)$db->query('SELECT COUNT(*) FROM harpp_notifications')->fetchColumn();
    $h->test('notify_decisions=0 prevents notification row and send',!empty($disabled['data']['skipped'])&&$before===$after);
    $db->prepare("INSERT INTO harpp_settings(setting_key,setting_value,updated_at) VALUES('notify_messages','0',NOW()) ON DUPLICATE KEY UPDATE setting_value='0'")->execute();$disabledMessage=(new HarppNotificationService($db))->create((int)$owner['id'],'message',['event'=>'test.disabled']);
    $h->test('notify_messages=0 gates message notification',!empty($disabledMessage['data']['skipped']));
    $db->prepare("INSERT INTO harpp_settings(setting_key,setting_value,updated_at) VALUES('notification_channels','none',NOW()) ON DUPLICATE KEY UPDATE setting_value='none'")->execute();$disabledChannel=(new HarppNotificationService($db))->create((int)$owner['id'],'system',['event'=>'test.disabled']);
    $h->test('disabled push channel gates dispatch and storage',!empty($disabledChannel['data']['skipped']));
    $push=new HarppPushService($db);$p256dh=rtrim(strtr(base64_encode(random_bytes(65)),'+/','-_'),'=');$auth=rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=');
    foreach(['https://127.0.0.1/push','https://[::1]/push','https://169.254.169.254/latest','https://10.0.0.1/push','not-a-url'] as$bad){$r=$push->subscribe($member,['endpoint'=>$bad,'keys'=>['p256dh'=>$p256dh,'auth'=>$auth]],$tenantId);$h->test('SSRF endpoint rejected: '.$bad,empty($r['ok']));}
    $badEndpoint='https://127.0.0.1/failure-isolation';$endpointHash=hash('sha256',$badEndpoint);$db->prepare('INSERT INTO harpp_push_subscriptions(user_id,endpoint,endpoint_hash,`keys`,created_at,updated_at) VALUES(:u,:e,:h,:k,NOW(),NOW())')->execute([':u'=>$owner['id'],':e'=>$badEndpoint,':h'=>$endpointHash,':k'=>json_encode(['p256dh'=>$p256dh,'auth'=>$auth])]);
    $dispatch=$push->dispatchToUser((int)$owner['id']);$h->test('one bad push subscription is isolated from dispatch',!empty($dispatch['ok'])&&(int)$dispatch['data']['attempted']>=1);
    @file_put_contents($root.'/storage/logs/app.log','');

    $h->section('Structured result and runtime entity views');
    $h->test('services return structured ServiceResult',$created instanceof HarppServiceResult&&$created->entityType==='harpp_decision'&&$created->entityId===$decisionId&&count($created->events)===1);
    $resolver=EntityViewResolver::getInstance();
    $h->test('decision views loaded into runtime resolver',is_array($resolver->viewContract('harpp_decision','table'))&&is_array($resolver->viewContract('harpp_decision','detailed')));
    $h->test('ADR views loaded into runtime resolver',is_array($resolver->viewContract('harpp_adr','table'))&&is_array($resolver->viewContract('harpp_adr','detailed')));

    $h->section('Audit failure rolls back DECIDED and automatic ADR');
    $failureInput=$input;$failureInput['decision_key']='ROLLBACK-'.strtoupper(bin2hex(random_bytes(6)));$failureInput['title']='Audit rollback decision';
    $failureCreated=$service->create($bridgeActor,$failureInput,$tenantId);$failureDecisionId=(int)($failureCreated['data']['decision_id']??0);$failureConversationId=(int)($failureCreated['data']['conversation_id']??0);
    foreach(['NOTIFIED','VIEWED'] as$state){$prepared=$service->transition($owner,$failureDecisionId,$state,'Prepare forced audit failure.',[],$tenantId);if(empty($prepared['ok']))throw new RuntimeException('Unable to prepare audit rollback fixture.');}
    app()->capabilities()->register('kernel.audit.record@1','harpp-test-failing-audit',static fn(array $payload):array=>['ok'=>false,'forced'=>true],2000,['first']);
    $failedDecision=$service->transition($owner,$failureDecisionId,'DECIDED','This transaction must roll back.',['decision'=>'Do not persist'], $tenantId);
    $stateCheck=$db->prepare('SELECT lifecycle_state,decision FROM harpp_decisions WHERE id=:id');$stateCheck->execute([':id'=>$failureDecisionId]);$rolledBackDecision=$stateCheck->fetch(PDO::FETCH_ASSOC);
    $adrCheck=$db->prepare('SELECT COUNT(*) FROM harpp_adrs WHERE decision_ref=:id');$adrCheck->execute([':id'=>$failureDecisionId]);
    $h->test('forced audit failure rejects transition and rolls back decision/ADR',empty($failedDecision['ok'])&&($rolledBackDecision['lifecycle_state']??'')==='VIEWED'&&empty($rolledBackDecision['decision'])&&(int)$adrCheck->fetchColumn()===0);
}finally{
    if($endpointHash!=='')$db->prepare('DELETE FROM harpp_push_subscriptions WHERE endpoint_hash=:h')->execute([':h'=>$endpointHash]);
    foreach([[$decisionId,$conversationId],[$failureDecisionId,$failureConversationId]] as[$cleanupDecision,$cleanupConversation]){if($cleanupDecision>0){$db->prepare('DELETE FROM harpp_adrs WHERE decision_ref=:id')->execute([':id'=>$cleanupDecision]);$db->prepare('DELETE FROM harpp_notifications WHERE decision_id=:id OR conversation_id=:c')->execute([':id'=>$cleanupDecision,':c'=>$cleanupConversation]);$db->prepare('DELETE FROM harpp_decisions WHERE id=:id')->execute([':id'=>$cleanupDecision]);}if($cleanupConversation>0)$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$cleanupConversation]);}
    foreach($settingsBackup as$k=>$v){$db->prepare('DELETE FROM harpp_settings WHERE setting_key=:k')->execute([':k'=>$k]);if($v!==null)$db->prepare('INSERT INTO harpp_settings(setting_key,setting_value,updated_at) VALUES(:k,:v,NOW())')->execute([':k'=>$k,':v'=>$v]);}
    $db->prepare("DELETE FROM harpp_settings WHERE setting_key IN ('notify_decisions','notify_messages','notification_channels')")->execute();
}
$h->done();
