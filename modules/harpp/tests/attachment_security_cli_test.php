<?php

declare(strict_types=1);

$root=dirname(__DIR__,3);
require $root.'/bootstrap.php';
$tenantId=(int)($_SERVER['argv'][1]??0);
if($tenantId<=0)throw new RuntimeException('Explicit isolated HARPP tenant id required.');
app()->tenant()->setTenantId($tenantId);
require_once dirname(__DIR__).'/helpers.php';

use Harpp\Services\HarppAttachmentService;
use Harpp\Services\HarppMessagingService;

$manifest=json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'),true,512,JSON_THROW_ON_ERROR);
$db=new \Ikabud\Kernel\Contracts\ModuleDB(app()->dbForTenant($tenantId),'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$actor=['id'=>(int)$owner['id'],'email'=>$owner['email'],'role'=>'owner','source'=>'harpp'];
$bridge=$actor;$bridge['source']='harpp_bridge';
$testStorage=$root.'/storage/attachment-test-runtime';
$service=new HarppAttachmentService($db,$testStorage);$messaging=new HarppMessagingService($db);
$checks=0;$assert=static function(string$name,bool$ok,string$detail='')use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException("FAIL $name".($detail!==''?": $detail":''));echo "PASS $name\n";};
$conversationId=0;$messageId=0;$stored=[];
$make=static function(string$content,string$name,string$type='application/octet-stream'):array{$tmp=tempnam(sys_get_temp_dir(),'harpp-att-');file_put_contents($tmp,$content);return['name'=>$name,'type'=>$type,'tmp_name'=>$tmp,'error'=>UPLOAD_ERR_OK,'size'=>strlen($content)];};
try{
    $created=$messaging->createConversation($actor,['title'=>'Attachment security round trip','harness_session_id'=>'attachment-test-'.bin2hex(random_bytes(4))],$tenantId);
    $conversationId=(int)($created['data']['conversation_id']??0);$assert('conversation created',$conversationId>0,json_encode($created));
    $sent=$messaging->sendMessage($actor,$conversationId,['body'=>'Owner attached this file.','sender_type'=>'user'],$tenantId);
    $messageId=(int)($sent['data']['message_id']??0);$assert('message created',$messageId>0,json_encode($sent));

    foreach(['../../etc/passwd.txt','..%2f..%2fetc%2fpasswd.txt','/etc/passwd.txt','a/../../b.txt'] as $name){
        $file=$make("safe traversal test\n",$name,'text/x-php');
        $r=$service->upload($actor,$conversationId,$file,$messageId,$tenantId,false);
        $assert("traversal metadata cannot select path: $name",!empty($r['ok']),json_encode($r));
        $id=(int)($r['data']['attachment']['id']??0);$stored[]=$id;
        $q=$db->prepare('SELECT storage_path,claimed_mime,detected_mime FROM harpp_attachments WHERE id=:id');$q->execute([':id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $assert('opaque generated storage path',is_array($row)&&preg_match('#^uploads/harpp/'.preg_quote((string)$tenantId,'#').'/'.$conversationId.'/[a-f0-9]{48}$#',(string)$row['storage_path'])===1&&(string)$row['claimed_mime']==='text/x-php'&&(string)$row['detected_mime']==='text/plain',json_encode($row));
    }

    foreach(['shell.php','shell.phtml','shell.phar','.htaccess','program.exe','script.sh'] as $name){
        $file=$make("not executable bytes\n",$name,'text/plain');$r=$service->upload($actor,$conversationId,$file,null,$tenantId,false);@unlink((string)$file['tmp_name']);
        $assert("dangerous/non-allowlisted type refused: $name",empty($r['ok'])&&($r['code']??'')==='attachment_type_denied',json_encode($r));
    }
    $fake=$make("<?php echo 'x'; ?>",'looks-safe.txt','text/plain');$r=$service->upload($actor,$conversationId,$fake,null,$tenantId,false);@unlink((string)$fake['tmp_name']);
    $assert('byte-derived MIME rejects disguised content',empty($r['ok'])&&($r['code']??'')==='attachment_mime_denied',json_encode($r));

    $empty=$make('','empty.txt','text/plain');$r=$service->upload($actor,$conversationId,$empty,null,$tenantId,false);@unlink((string)$empty['tmp_name']);
    $assert('empty upload refused',empty($r['ok'])&&($r['code']??'')==='attachment_empty',json_encode($r));
    $large=$make('x','large.txt','text/plain');$h=fopen($large['tmp_name'],'wb');for($i=0;$i<HarppAttachmentService::MAX_BYTES+1;$i+=8192)fwrite($h,substr(str_repeat("large text line\n",600),0,min(8192,HarppAttachmentService::MAX_BYTES+1-$i)));fclose($h);$r=$service->upload($actor,$conversationId,$large,null,$tenantId,false);@unlink((string)$large['tmp_name']);
    $assert('oversized upload refused before destination write',empty($r['ok'])&&($r['code']??'')==='attachment_too_large',json_encode($r));
    $missing=$service->upload($actor,$conversationId,[],null,$tenantId,false);$assert('missing upload refused',empty($missing['ok'])&&($missing['code']??'')==='attachment_missing');
    $unauth=$service->upload([], $conversationId, [], null, $tenantId, false);$assert('upload without session actor refused',empty($unauth['ok'])&&($unauth['status']??0)===401,json_encode($unauth));

    $file=$make("HARPP attachment round trip ".bin2hex(random_bytes(12))."\n",'owner-note.txt','application/x-untrusted');$sourceHash=hash_file('sha256',$file['tmp_name']);
    $r=$service->upload($actor,$conversationId,$file,$messageId,$tenantId,false);$assert('round-trip upload accepted',!empty($r['ok']),json_encode($r));$id=(int)$r['data']['attachment']['id'];$stored[]=$id;
    $row=$db->prepare('SELECT storage_path,claimed_mime,detected_mime,sha256 FROM harpp_attachments WHERE id=:id');$row->execute([':id'=>$id]);$meta=$row->fetch(PDO::FETCH_ASSOC);
    $disk=realpath($testStorage.'/'.(string)$meta['storage_path']);$public=realpath($root.'/public');
    $assert('row exists and storage is outside webroot',is_string($disk)&&is_file($disk)&&($public===false||!str_starts_with($disk,$public.DIRECTORY_SEPARATOR)),json_encode($meta));
    $assert('claimed and detected MIME retained',(string)$meta['claimed_mime']==='application/x-untrusted'&&(string)$meta['detected_mime']==='text/plain',json_encode($meta));
    $download=$service->download($bridge,$id,$tenantId);$downloadHash=!empty($download['ok'])?hash_file('sha256',(string)$download['data']['path']):'';
    $assert('bridge byte round trip hash matches',$sourceHash===$downloadHash,"source=$sourceHash download=$downloadHash");
    echo "SOURCE_SHA256=$sourceHash\nDOWNLOAD_SHA256=$downloadHash\nATTACHMENT_ID=$id\n";

    $other=$actor;$other['id']=(int)$actor['id']+1000000;
    $denied=$service->download($other,$id,$tenantId);$assert('different user download refused',empty($denied['ok'])&&($denied['status']??0)===404,json_encode($denied));
    // Simulate a row from another tenant in the same physical schema. The actor
    // tenant guard must reject before that row can be selected.
    $db->prepare('UPDATE harpp_attachments SET tenant_id=:tenant WHERE id=:id')->execute([':tenant'=>$tenantId+1,':id'=>$id]);
    $wrongTenant=$service->download($bridge,$id,$tenantId+1);
    $db->prepare('UPDATE harpp_attachments SET tenant_id=:tenant WHERE id=:id')->execute([':tenant'=>$tenantId,':id'=>$id]);
    $assert('wrong tenant download refused',empty($wrongTenant['ok'])&&($wrongTenant['status']??0)===403,json_encode($wrongTenant));
    $emptyConversation=$service->list($bridge,0,$tenantId);$assert('empty conversation refused',empty($emptyConversation['ok'])&&($emptyConversation['status']??0)===403,json_encode($emptyConversation));
    $missingId=$service->download($bridge,PHP_INT_MAX,$tenantId);$assert('unknown attachment id refused',empty($missingId['ok'])&&($missingId['status']??0)===404,json_encode($missingId));
    echo "ATTACHMENT SECURITY CHECKS PASS ($checks)\n";
}finally{
    if($conversationId>0){$q=$db->prepare('SELECT storage_path FROM harpp_attachments WHERE conversation_id=:id');$q->execute([':id'=>$conversationId]);foreach($q->fetchAll(PDO::FETCH_COLUMN)as$p){if(is_string($p))@unlink($testStorage.'/'.$p);}$db->prepare('DELETE FROM harpp_attachments WHERE conversation_id=:id')->execute([':id'=>$conversationId]);$db->prepare('DELETE FROM harpp_notifications WHERE conversation_id=:id')->execute([':id'=>$conversationId]);$db->prepare('DELETE FROM harpp_messages WHERE conversation_id=:id')->execute([':id'=>$conversationId]);$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$conversationId]);}
}
