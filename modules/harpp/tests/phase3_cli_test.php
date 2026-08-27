<?php

declare(strict_types=1);

$root=dirname(__DIR__,3);
require $root.'/bootstrap.php';
$tenantId=(int)($_SERVER['argv'][1]??1);app()->tenant()->setTenantId($tenantId);require_once dirname(__DIR__).'/helpers.php';

use Harpp\Services\HarppDecisionService;
use Harpp\Services\HarppMessagingService;
use Harpp\Services\HarppPushService;
use Harpp\Services\HarppAdrService;

$manifest=json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'),true,512,JSON_THROW_ON_ERROR);
$db=new \Ikabud\Kernel\Contracts\ModuleDB(app()->dbForTenant($tenantId),'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$s=$db->query("SELECT id,email,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1");$owner=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');$actor=['id'=>(int)$owner['id'],'email'=>$owner['email'],'role'=>'owner','source'=>'harpp'];
require_once $root . '/tests/harness/TestHarness.php';
ob_start();
$h = new TestHarness('harpp-phase3');
$h->fingerprint('modules/harpp/services/HarppDecisionService.php');
$h->fingerprint('modules/harpp/services/HarppMessagingService.php');
$h->fingerprint('modules/harpp/services/HarppPushService.php');
$h->fingerprint('modules/harpp/services/HarppAdrService.php');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$decisionId=0;$decisionConversation=0;$messageConversation=0;$endpoint='https://example.com/subscription/'.bin2hex(random_bytes(8));
try{
 $decisions=new HarppDecisionService($db);
 $created=$decisions->create($actor,['title'=>'Phase 3 lifecycle test','body'=>'Choose the safe implementation.','context'=>'CLI verification','requested_decision'=>'Approve implementation A','priority'=>'high','source'=>'harness','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED','decision_key'=>'TEST-'.strtoupper(bin2hex(random_bytes(6)))],$tenantId);
 $decisionId=(int)($created['data']['decision_id']??0);$decisionConversation=(int)($created['data']['conversation_id']??0);
 $assert('decision create',!empty($created['ok'])&&$decisionId>0);
 $n=$db->prepare("SELECT COUNT(*) FROM harpp_notifications WHERE decision_id=:id AND notification_type='decision'");$n->execute([':id'=>$decisionId]);$assert('notification on decision PENDING',(int)$n->fetchColumn()===1);
 $chain=['NOTIFIED','VIEWED','DECIDED','ACKNOWLEDGED','APPLIED','CLOSED'];
 foreach($chain as $state){$changes=$state==='DECIDED'?['decision'=>'Approve implementation A']:[];$r=$decisions->transition($actor,$decisionId,$state,'CLI transition to '.$state,$changes,$tenantId);$assert('transition to '.$state,!empty($r['ok']),(string)($r['error']??''));}
 $illegal=$decisions->transition($actor,$decisionId,'VIEWED','Terminal states cannot reopen.',[],$tenantId);$assert('illegal transition rejected',empty($illegal['ok'])&&($illegal['code']??'')==='illegal_transition');
 $detail=$decisions->get($actor,$decisionId,$tenantId);$assert('append-only transition audit',count((array)($detail['data']['audit_trail']??[]))===8);

 $messaging=new HarppMessagingService($db);$conversation=$messaging->createConversation($actor,['title'=>'CLI direct thread','harness_session_id'=>'cli-session-'.bin2hex(random_bytes(4))],$tenantId);$messageConversation=(int)($conversation['data']['conversation_id']??0);
 $sent=$messaging->sendMessage($actor,$messageConversation,['sender_type'=>'harness','body'=>'Harness is waiting for an operator decision.'],$tenantId);$messageId=(int)($sent['data']['message_id']??0);
 $listed=$messaging->listMessages($actor,$messageConversation,['limit'=>10],$tenantId);$messages=(array)($listed['data']['messages']??[]);$assert('message send and deterministic list',!empty($sent['ok'])&&count($messages)===1&&(int)$messages[0]['id']===$messageId);
 $read=$messaging->markRead($actor,$messageConversation,$messageId,$tenantId);$check=$db->prepare('SELECT read_at FROM harpp_messages WHERE id=:id');$check->execute([':id'=>$messageId]);$assert('message mark-read round-trip',!empty($read['ok'])&&$check->fetchColumn()!==null);

 $push=new HarppPushService($db);$clientKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$clientDetails=openssl_pkey_get_details($clientKey);$clientPublic="\x04".(string)$clientDetails['ec']['x'].(string)$clientDetails['ec']['y'];$p256dh=rtrim(strtr(base64_encode($clientPublic),'+/','-_'),'=');$auth=rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=');$subscriptionKeys=['p256dh'=>$p256dh,'auth'=>$auth];$saved=$push->subscribe($actor,['endpoint'=>$endpoint,'keys'=>$subscriptionKeys,'expirationTime'=>(time()+3600)*1000],$tenantId);$request=$push->buildRequest($endpoint,1700000000,$subscriptionKeys,['subject'=>'CLI subject','body'=>'Explicit push details','url'=>'/harpp']);
 $parts=explode('.',(string)$request['jwt']);$decode=static fn(string $v):string=>(string)base64_decode(strtr($v,'-_','+/').str_repeat('=',(4-strlen($v)%4)%4),true);$header=count($parts)===3?json_decode($decode($parts[0]),true):null;$claims=count($parts)===3?json_decode($decode($parts[1]),true):null;$signature=count($parts)===3?$decode($parts[2]):'';$publicResponse=$push->publicKey($tenantId);$publicKey=$decode((string)($publicResponse['data']['public_key']??''));
 $hasEncoding=in_array('Content-Encoding: aes128gcm',$request['headers'],true);$hasLength=count(array_filter($request['headers'],static fn(string $h):bool=>str_starts_with($h,'Content-Length: ')&&$h!=='Content-Length: 0'))===1;$wellFormed=!empty($saved['ok'])&&count($parts)===3&&($header['alg']??'')==='ES256'&&($claims['aud']??'')==='https://example.com'&&strlen($signature)===64&&strlen($publicKey)===65&&$publicKey[0]==="\x04"&&strlen($request['body'])>100&&$hasEncoding&&$hasLength&&!str_contains($request['body'],'Explicit push details');$assert('push subscription and encrypted payload VAPID request',$wellFormed,json_encode(['saved'=>$saved->toArray(),'parts'=>count($parts),'header'=>$header,'claims'=>$claims,'sig'=>strlen($signature),'public'=>strlen($publicKey),'body'=>strlen($request['body'])]));

 $adrService=new HarppAdrService($db);$adrs=$adrService->list($actor,['decision_id'=>$decisionId],$tenantId);$assert('automatic ADR list',count((array)($adrs['data']['adrs']??[]))===1);
}finally{
 $db->prepare('DELETE FROM harpp_push_subscriptions WHERE endpoint_hash=:hash')->execute([':hash'=>hash('sha256',$endpoint)]);
 if($decisionId>0){$db->prepare('DELETE FROM harpp_adrs WHERE decision_ref=:id')->execute([':id'=>$decisionId]);$db->prepare('DELETE FROM harpp_notifications WHERE decision_id=:id OR conversation_id IN (:dc,:mc)')->execute([':id'=>$decisionId,':dc'=>$decisionConversation,':mc'=>$messageConversation]);$db->prepare('DELETE FROM harpp_decisions WHERE id=:id')->execute([':id'=>$decisionId]);}
 foreach(array_unique(array_filter([$decisionConversation,$messageConversation])) as $conversationId){$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$conversationId]);}

}
ob_end_flush();
$h->done();
