<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppMessagingService
{
    public function __construct(private ?ModuleDB $database = null, private ?HarppNotificationService $notifications = null) {}
    private function db(): ModuleDB { if ($this->database instanceof ModuleDB) return $this->database; $db=\module('harpp')->db(); if(!$db instanceof ModuleDB) throw new \RuntimeException('HARPP module database is unavailable.'); return $this->database=$db; }

    public function listConversations(array $actor, ?int $tenantId = null)
    {
        if (!$this->access($actor, $tenantId)) return HarppServiceResult::failure('Forbidden.', 403);
        $stmt = $this->db()->query("SELECT c.id,c.title,c.harness_session_id,c.status,c.created_by,c.created_at,c.updated_at,COUNT(CASE WHEN m.read_at IS NULL AND m.sender_type<>'user' THEN 1 END) AS unread FROM harpp_conversations c LEFT JOIN harpp_messages m ON m.conversation_id=c.id GROUP BY c.id,c.title,c.harness_session_id,c.status,c.created_by,c.created_at,c.updated_at ORDER BY c.updated_at DESC,c.id DESC LIMIT 100");
        return HarppServiceResult::success(['conversations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function createConversation(array $actor, array $input, ?int $tenantId = null)
    {
        if (!$this->access($actor,$tenantId)) return HarppServiceResult::failure('Forbidden.',403);
        $title=trim(strip_tags((string)($input['title']??''))); $session=trim((string)($input['harness_session_id']??''));
        if($title==='' || strlen($title)>255 || $session==='' || strlen($session)>191 || !preg_match('/^[A-Za-z0-9._:-]+$/',$session)) return HarppServiceResult::failure('Valid title and harness_session_id are required.');
        try { $this->db()->beginTransaction();$s=$this->db()->prepare("INSERT INTO harpp_conversations (title,harness_session_id,status,created_by,created_at,updated_at) VALUES (:title,:session,'open',:user,NOW(),NOW())"); $s->execute([':title'=>$title,':session'=>$session,':user'=>(int)$actor['id']]); $id=(int)$this->db()->lastInsertId();$event=$this->effects('harpp.conversation.created','conversation.created',$actor,'harpp_conversation',$id,null,['title'=>$title,'harness_session_id'=>$session]);$this->db()->commit(); $this->audit('conversation.created',$actor,['conversation_id'=>$id]); return HarppServiceResult::success(['conversation_id'=>$id],'',[$event],'harpp_conversation',$id); }
        catch(Throwable $e){if($this->db()->inTransaction())$this->db()->rollBack();$this->log('conversation create failed',$e);return HarppServiceResult::failure('Unable to create conversation.',500);}
    }

    public function sendMessage(array $actor,int $conversationId,array $input,?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId) || $conversationId<=0) return HarppServiceResult::failure('Forbidden or invalid conversation.',403);
        $body=trim((string)($input['body']??'')); $sender=(string)($input['sender_type']??'user');
        if($body==='' || strlen($body)>65535 || !in_array($sender,['user','harness','system'],true)) return HarppServiceResult::failure('A valid message body and sender type are required.');
        if($sender!=='user' && !in_array((string)$actor['role'],['owner','admin'],true)) return HarppServiceResult::failure('Owner or admin access is required for harness/system messages.',403);
        $notificationId=0;$recipient=0;
        try {
            $this->db()->beginTransaction();
            $c=$this->db()->prepare("SELECT created_by FROM harpp_conversations WHERE id=:id AND status='open' FOR UPDATE");$c->execute([':id'=>$conversationId]);$creator=$c->fetchColumn();if($creator===false) throw new \InvalidArgumentException('Open conversation not found.');
            $s=$this->db()->prepare('INSERT INTO harpp_messages (conversation_id,sender_type,sender_user_id,body,payload,read_at,created_at) VALUES (:conversation,:type,:user,:body,:payload,:read,NOW())');
            $s->execute([':conversation'=>$conversationId,':type'=>$sender,':user'=>$sender==='user'?(int)$actor['id']:null,':body'=>$body,':payload'=>isset($input['payload'])?json_encode($input['payload'],JSON_THROW_ON_ERROR):null,':read'=>$sender==='user'?date('Y-m-d H:i:s'):null]);
            $messageId=(int)$this->db()->lastInsertId();$this->db()->prepare('UPDATE harpp_conversations SET updated_at=NOW() WHERE id=:id')->execute([':id'=>$conversationId]);
            $recipient=$sender==='user'?$this->otherOperator((int)$actor['id']):(int)$creator;
            if($recipient>0){$notice=($this->notifications??=new HarppNotificationService($this->db()))->create($recipient,'message',['event'=>'message.created','conversation_id'=>$conversationId,'message_id'=>$messageId],null,$conversationId,$messageId,false);if(empty($notice['ok']))throw new \RuntimeException((string)$notice['error']);$notificationId=(int)$notice['data']['notification_id'];}
            $event=$this->effects('harpp.message.sent','message.sent',$actor,'harpp_message',$messageId,null,['conversation_id'=>$conversationId,'sender_type'=>$sender]);$this->db()->commit();$this->audit('message.sent',$actor,['conversation_id'=>$conversationId,'message_id'=>$messageId,'sender_type'=>$sender]);
            if($notificationId>0)($this->notifications??=new HarppNotificationService($this->db()))->dispatch($notificationId,$recipient);
            return HarppServiceResult::success(['message_id'=>$messageId,'conversation_id'=>$conversationId],'',[$event],'harpp_message',$messageId);
        }catch(Throwable $e){if($this->db()->inTransaction())$this->db()->rollBack();$this->log('message send failed',$e);return HarppServiceResult::failure($e instanceof \InvalidArgumentException?$e->getMessage():'Unable to send message.',$e instanceof \InvalidArgumentException?404:500);}
    }

    public function listMessages(array $actor,int $conversationId,array $page=[],?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId)||$conversationId<=0)return HarppServiceResult::failure('Forbidden.',403);
        $limit=max(1,min(100,(int)($page['limit']??50)));$after=max(0,(int)($page['after_id']??0));
        $check=$this->db()->prepare('SELECT id FROM harpp_conversations WHERE id=:id');$check->execute([':id'=>$conversationId]);if($check->fetchColumn()===false)return HarppServiceResult::failure('Conversation not found.',404);
        $s=$this->db()->prepare('SELECT id,conversation_id,sender_type,sender_user_id,body,payload,read_at,created_at FROM harpp_messages WHERE conversation_id=:conversation AND id>:after ORDER BY created_at ASC,id ASC LIMIT '.$limit);$s->execute([':conversation'=>$conversationId,':after'=>$after]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        return HarppServiceResult::success(['messages'=>$rows,'limit'=>$limit,'next_after_id'=>$rows?(int)end($rows)['id']:$after]);
    }

    public function listOwnerMessagesForHarness(array $actor,array $page=[],?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId) || ($actor['source']??'')!=='harpp_bridge') return HarppServiceResult::failure('Forbidden.',403);
        $limit=max(1,min(100,(int)($page['limit']??50)));$after=max(0,(int)($page['cursor']??$page['after_id']??0));$conversation=max(0,(int)($page['conversation_id']??0));
        $sql="SELECT id,conversation_id,sender_type,sender_user_id,body,payload,read_at,created_at FROM harpp_messages WHERE sender_type='user' AND id>:after";$params=[':after'=>$after];
        if($conversation>0){$sql.=' AND conversation_id=:conversation';$params[':conversation']=$conversation;}
        $sql.=' ORDER BY id ASC LIMIT '.$limit;$s=$this->db()->prepare($sql);$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        return HarppServiceResult::success(['messages'=>$rows,'limit'=>$limit,'next_cursor'=>$rows?(int)end($rows)['id']:$after]);
    }

    public function markRead(array $actor,int $conversationId,int $throughId=0,?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId)||$conversationId<=0)return HarppServiceResult::failure('Forbidden.',403);
        $sql="UPDATE harpp_messages SET read_at=COALESCE(read_at,NOW()) WHERE conversation_id=:conversation AND sender_type<>'user'";$params=[':conversation'=>$conversationId];if($throughId>0){$sql.=' AND id<=:through';$params[':through']=$throughId;}$s=$this->db()->prepare($sql);$s->execute($params);$this->audit('messages.read',$actor,['conversation_id'=>$conversationId,'count'=>$s->rowCount()]);return HarppServiceResult::success(['marked_read'=>$s->rowCount()]);
    }

    public function unreadCounts(array $actor,?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId))return HarppServiceResult::failure('Forbidden.',403);
        $s=$this->db()->query("SELECT conversation_id,COUNT(*) unread FROM harpp_messages WHERE read_at IS NULL AND sender_type<>'user' GROUP BY conversation_id ORDER BY conversation_id ASC");$rows=$s->fetchAll(PDO::FETCH_ASSOC);$total=0;foreach($rows as $row)$total+=(int)$row['unread'];return HarppServiceResult::success(['total'=>$total,'conversations'=>$rows]);
    }

    private function effects(string $event,string $action,array $actor,string $type,int $id,?array $before,array $after):array{if(function_exists('app')){\app()->events()->fire($event,['entity_id'=>$id,'actor_user_id'=>(int)($actor['id']??0),'after'=>$after],'harpp');$r=\app()->cap()->call('kernel.audit.record@1',['module'=>'harpp','action'=>$action,'entity_type'=>$type,'entity_id'=>(string)$id,'old_data'=>$before,'new_data'=>$after],['mode'=>'first','caller_module'=>'harpp']);if(!is_array($r)||empty($r['ok']))throw new \RuntimeException('Kernel audit recording failed.');}return['name'=>$event,'payload'=>['entity_id'=>$id]+$after];}
    private function otherOperator(int $exclude): int{$s=$this->db()->prepare("SELECT id FROM harpp_users WHERE is_active=1 AND id<>:id AND role IN ('owner','admin') ORDER BY FIELD(role,'owner','admin'),id LIMIT 1");$s->execute([':id'=>$exclude]);return(int)($s->fetchColumn()?:0);}
    private function access(array $actor,?int $tenantId):bool{$current=(int)(\app()->tenant()->current()??0);return$current>0&&($tenantId===null||$tenantId===$current)&&(int)($actor['id']??0)>0&&in_array((string)($actor['source']??'harpp'),['harpp','harpp_bridge'],true)&&in_array((string)($actor['role']??''),['owner','admin','member'],true);}
    private function audit(string $action,array $actor,array $context):void{if(function_exists('write_log'))\write_log('HARPP audit','info',['module'=>'harpp','action'=>$action,'actor_user_id'=>(int)$actor['id']]+$context);}
    private function log(string $message,Throwable $e):void{if(function_exists('write_log'))\write_log('HARPP '.$message,'error',['module'=>'harpp','error'=>$e->getMessage()]);}
}
