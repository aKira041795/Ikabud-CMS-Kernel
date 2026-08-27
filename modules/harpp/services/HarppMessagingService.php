<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppMessagingService
{
    private ?HarppFoundationService $foundation = null;
    public function __construct(private ?ModuleDB $database = null, private ?HarppNotificationService $notifications = null) {}
    private function db(): ModuleDB { if ($this->database instanceof ModuleDB) return $this->database; $db=\module('harpp')->db(); if(!$db instanceof ModuleDB) throw new \RuntimeException('HARPP module database is unavailable.'); return $this->database=$db; }

    public function listConversations(array $actor, array $filters = [], ?int $tenantId = null)
    {
        if (!$this->access($actor, $tenantId)) return HarppServiceResult::failure('Forbidden.', 403);
        $archived = array_key_exists('archived', $filters) && filter_var($filters['archived'], FILTER_VALIDATE_BOOLEAN);
        $includeArchived = array_key_exists('include_archived', $filters) && filter_var($filters['include_archived'], FILTER_VALIDATE_BOOLEAN);
        $clauses=[];$params=[];$user=(int)$actor['id'];$role=(string)($actor['role']??'');if(!$includeArchived)$clauses[]=$archived?'c.archived_at IS NOT NULL':'c.archived_at IS NULL';$clauses[]='c.deleted_at IS NULL';if($this->foundation()->enabled('workspace_enforcement')&&$role==='member'){$clauses[]="EXISTS(SELECT 1 FROM harpp_workspace_memberships wm WHERE wm.workspace_id=c.workspace_id AND wm.user_id=:membership_user AND wm.status='active')";$clauses[]="(c.project_id IS NULL OR EXISTS(SELECT 1 FROM harpp_project_memberships pm WHERE pm.project_id=c.project_id AND pm.user_id=:project_user AND pm.status='active'))";$params[':membership_user']=$user;$params[':project_user']=$user;}if($this->foundation()->enabled('participant_visibility')&&$role==='member'){$clauses[]="(c.visibility='workspace' OR c.created_by=:creator_user OR (c.visibility='participants' AND EXISTS(SELECT 1 FROM harpp_conversation_participants cp WHERE cp.conversation_id=c.id AND cp.user_id=:participant_user AND cp.revoked_at IS NULL)) OR (c.visibility='private' AND EXISTS(SELECT 1 FROM harpp_conversation_participants pg WHERE pg.conversation_id=c.id AND pg.user_id=:private_user AND pg.grant_kind='private_grant' AND pg.revoked_at IS NULL)))";$params[':creator_user']=$user;$params[':participant_user']=$user;$params[':private_user']=$user;}elseif($this->foundation()->enabled('participant_visibility')&&$role==='admin'){$clauses[]="(c.visibility<>'private' OR c.created_by=:admin_creator OR EXISTS(SELECT 1 FROM harpp_conversation_participants cp WHERE cp.conversation_id=c.id AND cp.user_id=:admin_grant AND cp.grant_kind='private_grant' AND cp.revoked_at IS NULL))";$params[':admin_creator']=$user;$params[':admin_grant']=$user;}$where=$clauses?' WHERE '.implode(' AND ',$clauses):'';
        $perUserReceipts=$this->foundation()->enabled('per_user_receipts');
        $baseSql="SELECT c.id,c.workspace_id,c.project_id,c.visibility,c.title,c.harness_session_id,c.status,c.version,c.archived_at,c.created_by,c.created_at,c.updated_at FROM harpp_conversations c$where ORDER BY c.updated_at DESC,c.id DESC LIMIT 100";
        $stmt=$this->db()->prepare($baseSql);$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids=array_map(static fn(array $r):int=>(int)$r['id'],$rows);$unreadMap=[];
        if($ids){$in=implode(',',array_fill(0,count($ids),'?'));if($perUserReceipts){$u=$this->db()->prepare("SELECT m.conversation_id,COUNT(*) FROM harpp_messages m LEFT JOIN harpp_message_receipts mr ON mr.message_id=m.id AND mr.user_id=? WHERE m.conversation_id IN ($in) AND mr.id IS NULL AND (m.sender_user_id IS NULL OR m.sender_user_id<>?) GROUP BY m.conversation_id");$u->execute(array_merge([$user],$ids,[$user]));}else{$u=$this->db()->prepare("SELECT conversation_id,COUNT(*) FROM harpp_messages WHERE conversation_id IN ($in) AND read_at IS NULL AND sender_type<>'user' GROUP BY conversation_id");$u->execute($ids);}foreach($u->fetchAll(PDO::FETCH_NUM) as $row){$unreadMap[(int)$row[0]]=(int)$row[1];}}
        foreach($rows as &$row){$row['unread']=$unreadMap[(int)$row['id']]??0;}unset($row);
        return HarppServiceResult::success(['conversations' => $rows]);
    }

    public function createConversation(array $actor, array $input, ?int $tenantId = null)
    {
        if (!$this->access($actor,$tenantId)) return HarppServiceResult::failure('Forbidden.',403);
        $title=trim(strip_tags((string)($input['title']??''))); $session=trim((string)($input['harness_session_id']??''));
        if($title==='' || strlen($title)>255 || $session==='' || strlen($session)>191 || !preg_match('/^[A-Za-z0-9._:-]+$/',$session)) return HarppServiceResult::failure('Valid title and harness_session_id are required.');
        $workspace=(int)($input['workspace_id']??0)?:$this->legacyWorkspaceId();$project=(int)($input['project_id']??0);$visibility=(string)($input['visibility']??'workspace');if(!in_array($visibility,['workspace','participants','private'],true))return HarppServiceResult::failure('Invalid visibility.');if($project>0&&!$this->projectInWorkspace($project,$workspace))return HarppServiceResult::failure('Project does not belong to the selected workspace.',422);if(!$this->tenantOperator($actor)&&(!$this->workspaceMember($workspace,(int)$actor['id'])||!$this->workspaceCanOperate($workspace,(int)$actor['id'])||($project>0&&!$this->projectMember($project,(int)$actor['id']))))return HarppServiceResult::failure('Workspace operator access is required.',403);
        try { $this->db()->beginTransaction();$s=$this->db()->prepare("INSERT INTO harpp_conversations (workspace_id,project_id,visibility,title,harness_session_id,status,version,created_by,created_at,updated_at) VALUES (:workspace,NULLIF(:project,0),:visibility,:title,:session,'open',1,:user,NOW(),NOW())"); $s->execute([':workspace'=>$workspace,':project'=>$project,':visibility'=>$visibility,':title'=>$title,':session'=>$session,':user'=>(int)$actor['id']]); $id=(int)$this->db()->lastInsertId();if($visibility!=='workspace')$this->db()->prepare("INSERT INTO harpp_conversation_participants(conversation_id,user_id,grant_kind,created_by,created_at) VALUES(:conversation,:participant,'private_grant',:creator,NOW(6))")->execute([':conversation'=>$id,':participant'=>(int)$actor['id'],':creator'=>(int)$actor['id']]);$event=$this->effects('harpp.conversation.created','conversation.created',$actor,'harpp_conversation',$id,null,['workspace_id'=>$workspace,'project_id'=>$project?:null,'visibility'=>$visibility,'title'=>$title,'harness_session_id'=>$session]);$this->db()->commit(); return HarppServiceResult::success(['conversation_id'=>$id,'workspace_id'=>$workspace,'project_id'=>$project?:null,'visibility'=>$visibility],'',[$event],'harpp_conversation',$id); }
        catch(Throwable $e){if($this->db()->inTransaction())$this->db()->rollBack();$this->log('conversation create failed',$e);return HarppServiceResult::failure('Unable to create conversation.',500);}
    }

    public function sendMessage(array $actor,int $conversationId,array $input,?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId) || $conversationId<=0) return HarppServiceResult::failure('Forbidden or invalid conversation.',403);
        $body=trim((string)($input['body']??'')); $sender=(string)($input['sender_type']??'user');
        if($body==='' || strlen($body)>65535 || !in_array($sender,['user','harness','system'],true)) return HarppServiceResult::failure('A valid message body and sender type are required.');
        if($sender!=='user' && !in_array((string)$actor['role'],['owner','admin'],true)) return HarppServiceResult::failure('Owner or admin access is required for harness/system messages.',403);
        $idempotencyKey=trim((string)($input['idempotency_key']??''));
        if(strlen($idempotencyKey)>191)return HarppServiceResult::failure('Idempotency key is too long.',422,'idempotency_invalid');
        $notifications=[];
        try {
            $this->db()->beginTransaction();
            $idem=$this->foundation()->claimIdempotency('harpp_message',$actor,'message.send',$idempotencyKey,['conversation_id'=>$conversationId,'sender_type'=>$sender,'body'=>$body,'payload'=>$input['payload']??null]);
            if($idem['state']==='conflict'){$this->db()->rollBack();return HarppServiceResult::failure('Idempotency key reused with a different message.',409,'idempotency_conflict');}
            if($idem['state']==='in_progress'){$this->db()->rollBack();return HarppServiceResult::failure('The same message delivery is already in progress.',409,'idempotency_in_progress');}
            if($idem['state']==='replay'){$this->db()->rollBack();$replay=is_array($idem['response']??null)?$idem['response']:[];$replay['idempotent_replay']=true;return HarppServiceResult::success($replay);}
            $c=$this->db()->prepare("SELECT created_by,workspace_id,project_id,visibility FROM harpp_conversations WHERE id=:id AND status='open' FOR UPDATE");$c->execute([':id'=>$conversationId]);$conversation=$c->fetch(PDO::FETCH_ASSOC);if(!is_array($conversation)) throw new \InvalidArgumentException('Open conversation not found.');if(!$this->canAccessConversation($conversationId,$conversation,$actor))throw new \InvalidArgumentException('Conversation access denied.');if(!$this->tenantOperator($actor)&&!$this->workspaceCanOperate((int)$conversation['workspace_id'],(int)$actor['id']))throw new \InvalidArgumentException('Workspace operator access is required.');
            $sequence=$this->nextMessageSequence($conversationId);$s=$this->db()->prepare('INSERT INTO harpp_messages (conversation_id,aggregate_sequence,sender_type,sender_user_id,body,payload,read_at,created_at) VALUES (:conversation,:sequence,:type,:user,:body,:payload,:read,NOW())');
            $s->execute([':conversation'=>$conversationId,':sequence'=>$sequence,':type'=>$sender,':user'=>$sender==='user'?(int)$actor['id']:null,':body'=>$body,':payload'=>isset($input['payload'])?json_encode($input['payload'],JSON_THROW_ON_ERROR):null,':read'=>$sender==='user'?date('Y-m-d H:i:s'):null]);
            $messageId=(int)$this->db()->lastInsertId();$this->db()->prepare('UPDATE harpp_conversations SET updated_at=NOW() WHERE id=:id')->execute([':id'=>$conversationId]);
            if($this->foundation()->enabled('notification_fanout')){$recipients=(new HarppCollaborationService($this->db()))->notificationRecipients((int)$conversation['workspace_id'],$conversationId,'message.created',(int)$actor['id']);}else{$recipient=$sender==='user'?$this->otherOperator((int)$actor['id']):(int)$conversation['created_by'];$recipients=$recipient>0?[$recipient]:[];}
            $messageType = strtoupper(trim((string)($input['message_type'] ?? 'INFO'))) ?: 'INFO';
            foreach($recipients as $recipient){$notice=($this->notifications??=new HarppNotificationService($this->db()))->create($recipient,'message',['event'=>'message.created','conversation_id'=>$conversationId,'message_id'=>$messageId,'sender_type'=>$sender,'in_app_visible'=>$sender==='user','message_type'=>$messageType],null,$conversationId,$messageId,false);if(empty($notice['ok']))throw new \RuntimeException((string)$notice['error']);if(empty($notice['data']['idempotent_replay']))$notifications[]=['id'=>(int)$notice['data']['notification_id'],'user_id'=>$recipient];}
            $event=$this->effects('harpp.message.sent','message.sent',$actor,'harpp_message',$messageId,null,['conversation_id'=>$conversationId,'sender_type'=>$sender,'notification_deliveries'=>$notifications]);$resultData=['message_id'=>$messageId,'conversation_id'=>$conversationId];if(($idem['state']??'')==='claimed')$this->foundation()->completeIdempotency((int)$idem['id'],$resultData);$this->db()->commit();$this->audit('message.sent',$actor,['conversation_id'=>$conversationId,'message_id'=>$messageId,'sender_type'=>$sender]);
            return HarppServiceResult::success($resultData,'',[$event],'harpp_message',$messageId);
        }catch(Throwable $e){if($this->db()->inTransaction())$this->db()->rollBack();$this->log('message send failed',$e);return HarppServiceResult::failure($e instanceof \InvalidArgumentException?$e->getMessage():'Unable to send message.',$e instanceof \InvalidArgumentException?404:500);}
    }

    public function listMessages(array $actor,int $conversationId,array $page=[],?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId)||$conversationId<=0)return HarppServiceResult::failure('Forbidden.',403);
        $limit=max(1,min(100,(int)($page['limit']??50)));$after=max(0,(int)($page['after_id']??0));
        $check=$this->db()->prepare('SELECT created_by,workspace_id,project_id,visibility FROM harpp_conversations WHERE id=:id');$check->execute([':id'=>$conversationId]);$conversation=$check->fetch(PDO::FETCH_ASSOC);if(!is_array($conversation))return HarppServiceResult::failure('Conversation not found.',404);if(!$this->canAccessConversation($conversationId,$conversation,$actor))return HarppServiceResult::failure('Conversation access denied.',403);
        $s=$this->db()->prepare('SELECT id,conversation_id,aggregate_sequence,sender_type,sender_user_id,body,payload,read_at,created_at FROM harpp_messages WHERE conversation_id=:conversation AND id>:after ORDER BY aggregate_sequence ASC,id ASC LIMIT '.$limit);$s->execute([':conversation'=>$conversationId,':after'=>$after]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        return HarppServiceResult::success(['messages'=>$rows,'limit'=>$limit,'next_after_id'=>$rows?(int)end($rows)['id']:$after,'next_sequence'=>$rows?(int)end($rows)['aggregate_sequence']:0]);
    }

    public function listOwnerMessagesForHarness(array $actor,array $page=[],?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId) || ($actor['source']??'')!=='harpp_bridge') return HarppServiceResult::failure('Forbidden.',403);
        $limit=max(1,min(100,(int)($page['limit']??50)));$after=max(0,(int)($page['cursor']??$page['after_id']??$page['after']??0));$conversation=max(0,(int)($page['conversation_id']??0));
        $sql="SELECT id,conversation_id,sender_type,sender_user_id,body,payload,read_at,created_at FROM harpp_messages WHERE sender_type='user' AND id>:after";$params=[':after'=>$after];
        if($conversation>0){$sql.=' AND conversation_id=:conversation';$params[':conversation']=$conversation;}
        $sql.=' ORDER BY id ASC LIMIT '.$limit;$s=$this->db()->prepare($sql);$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        return HarppServiceResult::success(['messages'=>$rows,'limit'=>$limit,'next_cursor'=>$rows?(int)end($rows)['id']:$after]);
    }

    public function markRead(array $actor,int $conversationId,int $throughId=0,?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId)||$conversationId<=0)return HarppServiceResult::failure('Forbidden.',403);
        $check=$this->db()->prepare('SELECT created_by,workspace_id,project_id,visibility FROM harpp_conversations WHERE id=:id');$check->execute([':id'=>$conversationId]);$conversation=$check->fetch(PDO::FETCH_ASSOC);if(!is_array($conversation)||!$this->canAccessConversation($conversationId,$conversation,$actor))return HarppServiceResult::failure('Conversation not found.',404);
        try{$this->db()->beginTransaction();$perUser=$this->foundation()->enabled('per_user_receipts');if($perUser){$sql='INSERT INTO harpp_message_receipts(message_id,user_id,read_at) SELECT id,:receipt_user,NOW(6) FROM harpp_messages WHERE conversation_id=:conversation AND (sender_user_id IS NULL OR sender_user_id<>:sender_user)';$params=[':receipt_user'=>(int)$actor['id'],':sender_user'=>(int)$actor['id'],':conversation'=>$conversationId];if($throughId>0){$sql.=' AND id<=:through';$params[':through']=$throughId;}$sql.=' ON DUPLICATE KEY UPDATE read_at=VALUES(read_at)';$s=$this->db()->prepare($sql);$s->execute($params);}else{$sql="UPDATE harpp_messages SET read_at=COALESCE(read_at,NOW()) WHERE conversation_id=:conversation AND sender_type<>'user'";$params=[':conversation'=>$conversationId];if($throughId>0){$sql.=' AND id<=:through';$params[':through']=$throughId;}$s=$this->db()->prepare($sql);$s->execute($params);}$count=$s->rowCount();$event=$count>0?$this->effects('harpp.conversation.read','conversation.read',$actor,'harpp_conversation',$conversationId,null,['user_id'=>(int)$actor['id'],'through_message_id'=>$throughId?:null,'marked_read'=>$count,'per_user'=>$perUser]):null;$this->db()->commit();return HarppServiceResult::success(['marked_read'=>$count,'per_user'=>$perUser],'',array_values(array_filter([$event])),'harpp_conversation',$conversationId);}catch(Throwable $e){if($this->db()->inTransaction())$this->db()->rollBack();$this->log('mark read failed',$e);return HarppServiceResult::failure('Unable to mark messages read.',500);}
    }

    public function unreadCounts(array $actor,?int $tenantId=null)
    {
        $listed=$this->listConversations($actor,[],$tenantId);if(empty($listed['ok']))return$listed;$rows=[];$total=0;foreach((array)($listed['data']['conversations']??[])as$row){$count=(int)$row['unread'];$total+=$count;$rows[]=['conversation_id'=>(int)$row['id'],'unread'=>$count];}return HarppServiceResult::success(['total'=>$total,'conversations'=>$rows]);
    }

    public function closeConversation(array $actor, int $conversationId, ?int $tenantId = null)
    {
        if (!$this->access($actor, $tenantId) || $conversationId <= 0) return HarppServiceResult::failure('Forbidden.', 403);
        if (!in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true)) return HarppServiceResult::failure('Owner or admin access is required.', 403);
        $check=$this->db()->prepare('SELECT created_by,workspace_id,project_id,visibility FROM harpp_conversations WHERE id=:id');$check->execute([':id'=>$conversationId]);$conversation=$check->fetch(PDO::FETCH_ASSOC);if(!is_array($conversation)||!$this->canAccessConversation($conversationId,$conversation,$actor))return HarppServiceResult::failure('Conversation not found.',404);
        $s = $this->db()->prepare("UPDATE harpp_conversations SET status='closed', updated_at=NOW() WHERE id=:id");
        $s->execute([':id' => $conversationId]);
        if ($s->rowCount() === 0) return HarppServiceResult::failure('Conversation not found.', 404);
        $this->audit('conversation.closed', $actor, ['conversation_id' => $conversationId]);
        return HarppServiceResult::success(['conversation_id' => $conversationId, 'status' => 'closed']);
    }

    public function archiveConversation(array $actor, int $conversationId, bool $archive = true, ?int $tenantId = null)
    {
        if (!$this->access($actor, $tenantId) || $conversationId <= 0) return HarppServiceResult::failure('Forbidden.', 403);
        if (!in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true)) return HarppServiceResult::failure('Owner or admin access is required.', 403);
        try {
            $this->db()->beginTransaction();
            $c = $this->db()->prepare('SELECT status, archived_at FROM harpp_conversations WHERE id=:id FOR UPDATE');
            $c->execute([':id' => $conversationId]);
            $row = $c->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new \InvalidArgumentException('Conversation not found.');
            $scope=$this->db()->prepare('SELECT created_by,workspace_id,project_id,visibility FROM harpp_conversations WHERE id=:id');$scope->execute([':id'=>$conversationId]);$conversation=$scope->fetch(PDO::FETCH_ASSOC);if(!is_array($conversation)||!$this->canAccessConversation($conversationId,$conversation,$actor))throw new \InvalidArgumentException('Conversation not found.');
            if ($archive && $row['status'] !== 'closed') throw new \InvalidArgumentException('Only done (closed) conversations can be archived.');
            if ($archive && $this->setting('conversation_archiving', '1') !== '1') throw new \InvalidArgumentException('Conversation archiving is disabled.');
            $s = $this->db()->prepare('UPDATE harpp_conversations SET archived_at = :at, updated_at = NOW() WHERE id = :id');
            $s->execute([':at' => $archive ? date('Y-m-d H:i:s') : null, ':id' => $conversationId]);
            $this->audit($archive ? 'conversation.archived' : 'conversation.unarchived', $actor, ['conversation_id' => $conversationId]);
            $this->db()->commit();
            return HarppServiceResult::success(['conversation_id' => $conversationId, 'archived' => $archive]);
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) $this->db()->rollBack();
            $this->log('conversation archive failed', $e);
            return HarppServiceResult::failure($e instanceof \InvalidArgumentException ? $e->getMessage() : 'Unable to update conversation archive state.', $e instanceof \InvalidArgumentException ? 409 : 500);
        }
    }

    public function deleteConversation(array $actor, int $conversationId, ?int $tenantId = null)
    {
        if (!$this->access($actor, $tenantId) || $conversationId <= 0) return HarppServiceResult::failure('Forbidden.', 403);
        if (!in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true)) return HarppServiceResult::failure('Owner or admin access is required.', 403);
        try {
            $this->db()->beginTransaction();
            $c = $this->db()->prepare('SELECT archived_at, deleted_at FROM harpp_conversations WHERE id=:id FOR UPDATE');
            $c->execute([':id' => $conversationId]);
            $row = $c->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new \InvalidArgumentException('Conversation not found.');
            $scope=$this->db()->prepare('SELECT created_by,workspace_id,project_id,visibility FROM harpp_conversations WHERE id=:id');$scope->execute([':id'=>$conversationId]);$conversation=$scope->fetch(PDO::FETCH_ASSOC);if(!is_array($conversation)||!$this->canAccessConversation($conversationId,$conversation,$actor))throw new \InvalidArgumentException('Conversation not found.');
            if ($row['archived_at'] === null) throw new \InvalidArgumentException('Only archived conversations can be deleted.');
            if ($row['deleted_at'] !== null) throw new \InvalidArgumentException('Conversation is already deleted.');
            $s = $this->db()->prepare('UPDATE harpp_conversations SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id');
            $s->execute([':id' => $conversationId]);
            $this->audit('conversation.deleted', $actor, ['conversation_id' => $conversationId]);
            $this->db()->commit();
            return HarppServiceResult::success(['conversation_id' => $conversationId, 'deleted' => true], 'Conversation deleted; history retained.');
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) $this->db()->rollBack();
            $this->log('conversation delete failed', $e);
            return HarppServiceResult::failure($e instanceof \InvalidArgumentException ? $e->getMessage() : 'Unable to delete conversation.', $e instanceof \InvalidArgumentException ? 409 : 500);
        }
    }

    private function setting(string $key, string $default): string
    {
        $stmt = $this->db()->prepare('SELECT setting_value FROM harpp_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    }
    private function effects(string $event,string $action,array $actor,string $type,int $id,?array $before,array $after):array{return$this->foundation()->recordEffect($event,$action,$actor,$type,$id,$before,$after);}
    private function otherOperator(int $exclude): int{$s=$this->db()->prepare("SELECT id FROM harpp_users WHERE is_active=1 AND id<>:id AND role IN ('owner','admin') ORDER BY FIELD(role,'owner','admin'),id LIMIT 1");$s->execute([':id'=>$exclude]);return(int)($s->fetchColumn()?:0);}
    private function access(array $actor,?int $tenantId):bool{$current=(int)(\app()->tenant()->current()??0);return$current>0&&($tenantId===null||$tenantId===$current)&&(int)($actor['id']??0)>0&&in_array((string)($actor['source']??'harpp'),['harpp','harpp_bridge'],true)&&in_array((string)($actor['role']??''),['owner','admin','member'],true);}
    private function foundation():HarppFoundationService{return$this->foundation??=new HarppFoundationService($this->db());}
    private function legacyWorkspaceId():int{$s=$this->db()->query("SELECT id FROM harpp_workspaces WHERE workspace_key='legacy' LIMIT 1");return(int)($s->fetchColumn()?:0);}
    private function workspaceMember(int$workspace,int$user):bool{$s=$this->db()->prepare("SELECT 1 FROM harpp_workspace_memberships WHERE workspace_id=:workspace AND user_id=:user AND status='active'");$s->execute([':workspace'=>$workspace,':user'=>$user]);return$s->fetchColumn()!==false;}
    private function workspaceCanOperate(int$workspace,int$user):bool{$s=$this->db()->prepare("SELECT 1 FROM harpp_workspace_memberships WHERE workspace_id=:workspace AND user_id=:user AND status='active' AND (JSON_CONTAINS(roles,'\"operator\"') OR JSON_CONTAINS(roles,'\"manager\"'))");$s->execute([':workspace'=>$workspace,':user'=>$user]);return$s->fetchColumn()!==false;}
    private function projectInWorkspace(int$project,int$workspace):bool{$s=$this->db()->prepare('SELECT 1 FROM harpp_projects WHERE id=:project AND workspace_id=:workspace');$s->execute([':project'=>$project,':workspace'=>$workspace]);return$s->fetchColumn()!==false;}
    private function projectMember(int$project,int$user):bool{$s=$this->db()->prepare("SELECT 1 FROM harpp_project_memberships WHERE project_id=:project AND user_id=:user AND status='active'");$s->execute([':project'=>$project,':user'=>$user]);return$s->fetchColumn()!==false;}
    private function tenantOperator(array$actor):bool{return in_array((string)($actor['role']??''),['owner','admin'],true);}
    private function nextMessageSequence(int$conversation):int{$s=$this->db()->prepare('SELECT COALESCE(MAX(aggregate_sequence),0)+1 FROM harpp_messages WHERE conversation_id=:conversation');$s->execute([':conversation'=>$conversation]);return(int)$s->fetchColumn();}
    private function canAccessConversation(int$id,array$c,array$actor):bool{if(!$this->foundation()->enabled('workspace_enforcement')&&!$this->foundation()->enabled('participant_visibility'))return true;$role=(string)($actor['role']??'');if($role==='owner')return true;if($role==='admin'&&$c['visibility']!=='private')return true;$user=(int)$actor['id'];$workspace=$this->workspaceMember((int)$c['workspace_id'],$user);$project=true;if((int)($c['project_id']??0)>0){$s=$this->db()->prepare("SELECT 1 FROM harpp_project_memberships WHERE project_id=:project AND user_id=:user AND status='active'");$s->execute([':project'=>(int)$c['project_id'],':user'=>$user]);$project=$s->fetchColumn()!==false;}$s=$this->db()->prepare('SELECT grant_kind FROM harpp_conversation_participants WHERE conversation_id=:conversation AND user_id=:user AND revoked_at IS NULL');$s->execute([':conversation'=>$id,':user'=>$user]);$grants=$s->fetchAll(PDO::FETCH_COLUMN);if($role==='admin')return in_array('private_grant',$grants,true);return HarppCollaborationPolicy::canSee((string)$c['visibility'],$user,(int)$c['created_by'],$workspace,$project,in_array('participant',$grants,true),in_array('private_grant',$grants,true));}
    private function audit(string $action,array $actor,array $context):void{if(function_exists('write_log'))\write_log('HARPP audit','HARPP',['module'=>'harpp','action'=>$action,'actor_user_id'=>(int)$actor['id']]+$context);}
    private function log(string $message,Throwable $e):void{if(function_exists('write_log'))\write_log('HARPP '.$message,'error',['module'=>'harpp','error'=>$e->getMessage()]);}
}
