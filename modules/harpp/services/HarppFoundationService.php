<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use Closure;
use PDO;
use Throwable;

/** Phase 0 integrity primitives. Call recordEffect() inside the aggregate transaction. */
final class HarppFoundationService
{
    private ?Closure $externalDispatcher;
    private const DEFAULT_FLAGS = [
        'harpp_lifecycle_v2' => true,
        'harpp_immutable_retention' => true,
        'harpp_outbox' => true,
        'harpp_strict_validation' => true,
        'harpp_workspace_enforcement' => false,
        'harpp_participant_visibility' => false,
        'harpp_per_user_receipts' => false,
        'harpp_approval_policies' => false,
        'harpp_notification_fanout' => false,
    ];

    public function __construct(private ModuleDB $database, ?callable $externalDispatcher=null) {$this->externalDispatcher=$externalDispatcher===null?null:Closure::fromCallable($externalDispatcher);}

    public function enabled(string $flag): bool
    {
        $key = str_starts_with($flag, 'harpp_') ? $flag : 'harpp_' . $flag;
        $stmt = $this->database->prepare('SELECT setting_value FROM harpp_settings WHERE setting_key=:key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? (self::DEFAULT_FLAGS[$key] ?? false) : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function recordEffect(string $eventName, string $action, array $actor, string $aggregateType, string|int $aggregateId, ?array $before, array $after, string $reason = ''): array
    {
        if (!$this->database->inTransaction()) {
            throw new \LogicException('HARPP audit/outbox effects must be recorded inside the aggregate transaction.');
        }
        $id = (string)$aggregateId;
        $seqStmt = $this->database->prepare('SELECT COALESCE(MAX(aggregate_sequence),0)+1 FROM harpp_audit_events WHERE aggregate_type=:type AND aggregate_id=:id');
        $seqStmt->execute([':type' => $aggregateType, ':id' => $id]);
        $sequence = (int)$seqStmt->fetchColumn();
        $eventKey = $aggregateType . ':' . $id . ':' . $sequence;
        $actorId = (int)($actor['id'] ?? 0);
        $actorType = match($actor['source']??'harpp'){'harpp_bridge'=>'harness','system'=>'system',default=>'user'};
        $audit = $this->database->prepare('INSERT INTO harpp_audit_events (event_key,aggregate_type,aggregate_id,aggregate_sequence,actor_user_id,actor_type,action,before_json,after_json,reason,occurred_at) VALUES (:key,:type,:id,:sequence,:actor,:actor_type,:action,:before,:after,:reason,NOW(6))');
        $audit->execute([
            ':key' => $eventKey, ':type' => $aggregateType, ':id' => $id, ':sequence' => $sequence,
            ':actor' => $actorId > 0 ? $actorId : null, ':actor_type' => $actorType, ':action' => $action,
            ':before' => $before === null ? null : $this->json($before), ':after' => $this->json($after),
            ':reason' => $reason !== '' ? $reason : null,
        ]);
        $payload = ['aggregate_type' => $aggregateType, 'aggregate_id' => $id, 'sequence' => $sequence, 'actor_user_id' => $actorId, 'actor_type'=>$actorType, 'action'=>$action, 'reason'=>$reason, 'before' => $before, 'after' => $after];
        if ($this->enabled('outbox')) {
            $outbox = $this->database->prepare("INSERT INTO harpp_outbox (event_key,event_name,aggregate_type,aggregate_id,payload_json,status,attempts,available_at,created_at) VALUES (:key,:name,:type,:id,:payload,'pending',0,NOW(6),NOW(6))");
            $outbox->execute([':key' => $eventKey, ':name' => $eventName, ':type' => $aggregateType, ':id' => $id, ':payload' => $this->json($payload)]);
        }
        return ['name' => $eventName, 'event_key' => $eventKey, 'payload' => $payload];
    }

    /** Same key and same payload replays; same key with another payload conflicts. */
    public function claimIdempotency(string $scope, array $actor, string $operation, string $key, array $request): array
    {
        if ($key === '' || strlen($key) > 191) return ['state' => 'unused'];
        $actorKey = (string)($actor['source'] ?? 'harpp') . ':' . (int)($actor['id'] ?? 0);
        $keyHash = hash('sha256', $key); $requestHash = hash('sha256', $this->canonicalJson($request));
        try {
            $stmt = $this->database->prepare("INSERT INTO harpp_idempotency_keys (scope_key,actor_key,operation_key,idempotency_key_hash,request_hash,status,created_at) VALUES (:scope,:actor,:operation,:key_hash,:request_hash,'processing',NOW(6))");
            $stmt->execute([':scope'=>$scope, ':actor'=>$actorKey, ':operation'=>$operation, ':key_hash'=>$keyHash, ':request_hash'=>$requestHash]);
            return ['state'=>'claimed', 'id'=>(int)$this->database->lastInsertId()];
        } catch (Throwable $e) {
            if ((string)$e->getCode() !== '23000' && !str_contains(strtolower($e->getMessage()), 'duplicate')) throw $e;
            $stmt = $this->database->prepare('SELECT id,request_hash,status,response_code,response_json,created_at FROM harpp_idempotency_keys WHERE scope_key=:scope AND actor_key=:actor AND operation_key=:operation AND idempotency_key_hash=:key_hash');
            $stmt->execute([':scope'=>$scope, ':actor'=>$actorKey, ':operation'=>$operation, ':key_hash'=>$keyHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !hash_equals((string)$row['request_hash'], $requestHash)) return ['state'=>'conflict'];
            if ($row['status'] === 'completed') return ['state'=>'replay', 'code'=>(int)$row['response_code'], 'response'=>json_decode((string)$row['response_json'], true)];
            if($row['status']==='processing'&&strtotime((string)$row['created_at'])<time()-600){$take=$this->database->prepare("UPDATE harpp_idempotency_keys SET created_at=NOW(6) WHERE id=:id AND status='processing' AND created_at<DATE_SUB(NOW(6),INTERVAL 10 MINUTE)");$take->execute([':id'=>$row['id']]);if($take->rowCount()===1)return['state'=>'claimed','id'=>(int)$row['id']];}
            return ['state'=>'in_progress'];
        }
    }

    public function completeIdempotency(int $id, array $response, int $code = 200): void
    {
        if ($id <= 0) return;
        $json = $this->canonicalJson($response);
        $stmt = $this->database->prepare("UPDATE harpp_idempotency_keys SET status='completed',response_code=:code,response_json=:response,response_hash=:hash,completed_at=NOW(6) WHERE id=:id AND status='processing'");
        $stmt->execute([':code'=>$code, ':response'=>$json, ':hash'=>hash('sha256',$json), ':id'=>$id]);
    }

    public function archiveDecision(array $actor, int $decisionId, ?int $expectedVersion=null): HarppServiceResult
    {
        try {
            $this->database->beginTransaction();
            $stmt=$this->database->prepare('SELECT lifecycle_state,archived_at,version,visibility,created_by FROM harpp_decisions WHERE id=:id FOR UPDATE');$stmt->execute([':id'=>$decisionId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)) throw new \InvalidArgumentException('Decision not found.');
            if(($actor['role']??'')==='admin'&&$row['visibility']==='private'&&(int)$row['created_by']!==(int)$actor['id']){$this->database->rollBack();return HarppServiceResult::failure('Private decision requires owner access or an explicit grant.',403,'private_scope');}
            if(!in_array($row['lifecycle_state'],['CLOSED','EXPIRED','SUPERSEDED','CANCELLED'],true)) { $this->database->rollBack(); return HarppServiceResult::failure('Only terminal decisions can be archived.',409,'archive_requires_terminal'); }
            if($expectedVersion!==null&&$expectedVersion!==(int)$row['version']){$this->database->rollBack();return HarppServiceResult::failure('Decision version conflict.',409,'version_conflict');}
            if($row['archived_at']===null){$updated=$this->database->prepare('UPDATE harpp_decisions SET archived_at=NOW(6),version=version+1 WHERE id=:id AND version=:version');$updated->execute([':id'=>$decisionId,':version'=>(int)$row['version']]);if($updated->rowCount()!==1){$this->database->rollBack();return HarppServiceResult::failure('Decision version conflict.',409,'version_conflict');}$event=$this->recordEffect('harpp.decision.archived','decision.archived',$actor,'harpp_decision',$decisionId,['archived_at'=>null],['archived'=>true],'Immutable archive; records remain retained.');}else{$event=null;}
            $this->database->commit();
            return HarppServiceResult::success(['decision_id'=>$decisionId,'archived'=>true,'already_archived'=>$row['archived_at']!==null],'Decision archived.',array_values(array_filter([$event])),'harpp_decision',$decisionId);
        } catch(Throwable $e){if($this->database->inTransaction())$this->database->rollBack();return HarppServiceResult::failure($e instanceof \InvalidArgumentException?$e->getMessage():'Unable to archive decision.',$e instanceof \InvalidArgumentException?404:500);}
    }

    public function requestPurge(array $actor, string $resourceType, string $resourceId, string $reason, int $delaySeconds = 86400): HarppServiceResult
    {
        if(($actor['role']??'')!=='owner')return HarppServiceResult::failure('Owner access is required.',403,'owner_required');
        if(!preg_match('/^[a-z][a-z0-9_]{2,99}$/',$resourceType)||$resourceId===''||$reason==='')return HarppServiceResult::failure('Valid resource_type, resource_id, and reason are required.');
        $notBefore=(new \DateTimeImmutable())->modify('+'.max(3600,$delaySeconds).' seconds')->format('Y-m-d H:i:s.u');
        try{$this->database->beginTransaction();$stmt=$this->database->prepare("INSERT INTO harpp_purge_requests (resource_type,resource_id,state,requested_by,reason,not_before,created_at) VALUES (:type,:id,'requested',:actor,:reason,:not_before,NOW(6))");$stmt->execute([':type'=>$resourceType,':id'=>$resourceId,':actor'=>(int)$actor['id'],':reason'=>$reason,':not_before'=>$notBefore]);$requestId=(int)$this->database->lastInsertId();$event=$this->recordEffect('harpp.purge.requested','purge.requested',$actor,'harpp_purge_request',$requestId,null,['resource_type'=>$resourceType,'resource_id'=>$resourceId,'state'=>'requested','not_before'=>$notBefore],$reason);$this->database->commit();return HarppServiceResult::success(['purge_request_id'=>$requestId,'state'=>'requested','not_before'=>$notBefore],'Purge requested; no records were deleted.',[$event],'harpp_purge_request',$requestId);}catch(Throwable $e){if($this->database->inTransaction())$this->database->rollBack();return HarppServiceResult::failure('Unable to request purge.',409,'purge_request_conflict');}
    }

    public function approvePurge(array $actor, int $requestId): HarppServiceResult
    {
        if(($actor['role']??'')!=='owner')return HarppServiceResult::failure('Owner access is required.',403,'owner_required');
        try{$this->database->beginTransaction();$check=$this->database->prepare("SELECT resource_type,resource_id,requested_by,reason,not_before FROM harpp_purge_requests WHERE id=:id AND state='requested' FOR UPDATE");$check->execute([':id'=>$requestId]);$request=$check->fetch(PDO::FETCH_ASSOC);if(!is_array($request)){$this->database->rollBack();return HarppServiceResult::failure('Open purge request not found.',404);}if((int)$request['requested_by']===(int)$actor['id']){$this->database->rollBack();return HarppServiceResult::failure('Purge approval requires a different owner.',409,'separation_of_duties');}if(strtotime((string)$request['not_before'])>time()){$this->database->rollBack();return HarppServiceResult::failure('The purge approval delay has not elapsed.',409,'purge_delay');}if($request['resource_type']==='harpp_decision'){$hold=$this->database->prepare('SELECT legal_hold_at FROM harpp_decisions WHERE id=:id');$hold->execute([':id'=>$request['resource_id']]);$held=$hold->fetch(PDO::FETCH_ASSOC);if(is_array($held)&&$held['legal_hold_at']!==null){$this->database->rollBack();return HarppServiceResult::failure('A legal hold blocks purge approval.',409,'legal_hold');}}
        $stmt=$this->database->prepare("UPDATE harpp_purge_requests SET state='approved',approved_by=:approver,approved_at=NOW(6) WHERE id=:id AND state='requested'");$stmt->execute([':approver'=>(int)$actor['id'],':id'=>$requestId]);if($stmt->rowCount()!==1)throw new \RuntimeException('Concurrent purge approval conflict.');$event=$this->recordEffect('harpp.purge.approved','purge.approved',$actor,'harpp_purge_request',$requestId,['state'=>'requested'],['state'=>'approved','resource_type'=>$request['resource_type'],'resource_id'=>$request['resource_id']],(string)$request['reason']);$this->database->commit();return HarppServiceResult::success(['purge_request_id'=>$requestId,'state'=>'approved'],'Purge approved; execution is intentionally unavailable in this phase.',[$event],'harpp_purge_request',$requestId);
        }catch(Throwable $e){if($this->database->inTransaction())$this->database->rollBack();return HarppServiceResult::failure('Unable to approve purge.',409,'purge_approval_conflict');}
    }

    public function listAudit(string $type, string $id, int $afterSequence=0, int $limit=100, array $actor=[]): HarppServiceResult
    {
        if(!$this->auditReadable($type,$id,$actor))return HarppServiceResult::failure('Audit stream not found.',404,'audit_scope');
        $limit=max(1,min(200,$limit));$stmt=$this->database->prepare('SELECT event_key,aggregate_type,aggregate_id,aggregate_sequence,actor_user_id,actor_type,action,before_json,after_json,reason,occurred_at FROM harpp_audit_events WHERE aggregate_type=:type AND aggregate_id=:id AND aggregate_sequence>:after ORDER BY aggregate_sequence ASC LIMIT '.$limit);$stmt->execute([':type'=>$type,':id'=>$id,':after'=>$afterSequence]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);return HarppServiceResult::success(['events'=>$rows,'next_sequence'=>$rows?(int)end($rows)['aggregate_sequence']:$afterSequence]);
    }

    /** Dispatch is deliberately separate from domain commits; failures are retried or dead-lettered. */
    public function dispatchOutbox(int $limit=50, bool $prioritizeNotifications=false): HarppServiceResult
    {
        $limit=max(1,min(100,$limit));$delivered=0;$failed=0;
        $this->database->prepare("UPDATE harpp_outbox SET status='pending',claim_token=NULL,claimed_at=NULL,available_at=NOW(6),last_error=COALESCE(last_error,'Recovered stale claim') WHERE status='processing' AND claimed_at<DATE_SUB(NOW(6),INTERVAL 10 MINUTE)")->execute();
        $order=$prioritizeNotifications?'CASE WHEN payload_json LIKE \'%"notification_deliveries":[{%\' THEN 0 ELSE 1 END, id DESC':'id ASC';
        $stmt=$this->database->query("SELECT id,event_name,payload_json,attempts FROM harpp_outbox WHERE status='pending' AND available_at<=NOW(6) ORDER BY ".$order." LIMIT ".$limit);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){$token=$this->uuid();$claim=$this->database->prepare("UPDATE harpp_outbox SET status='processing',claim_token=:token,claimed_at=NOW(6),attempts=attempts+1 WHERE id=:id AND status='pending'");$claim->execute([':token'=>$token,':id'=>$row['id']]);if($claim->rowCount()!==1)continue;try{$payload=json_decode((string)$row['payload_json'],true,512,JSON_THROW_ON_ERROR);if($this->externalDispatcher!==null){($this->externalDispatcher)((string)$row['event_name'],$payload);}elseif(function_exists('app')){\app()->events()->fire((string)$row['event_name'],$payload,'harpp');$audit=\app()->cap()->call('kernel.audit.record@1',['module'=>'harpp','action'=>(string)$payload['action'],'entity_type'=>(string)$payload['aggregate_type'],'entity_id'=>(string)$payload['aggregate_id'],'old_data'=>$payload['before']??null,'new_data'=>$payload['after']??null,'reason'=>(string)($payload['reason']??'')],['mode'=>'first','caller_module'=>'harpp']);if(!is_array($audit)||empty($audit['ok']))throw new \RuntimeException('Kernel audit dispatch failed.');}foreach((array)($payload['after']['notification_deliveries']??[]) as $notice){$result=(new HarppNotificationService($this->database))->dispatch((int)($notice['id']??0),(int)($notice['user_id']??0));if(empty($result['ok']))throw new \RuntimeException((string)($result['error']??'Notification delivery failed.'));}$done=$this->database->prepare("UPDATE harpp_outbox SET status='delivered',delivered_at=NOW(6),claim_token=NULL,last_error=NULL WHERE id=:id AND claim_token=:token");$done->execute([':id'=>$row['id'],':token'=>$token]);$delivered++;}catch(Throwable $e){$attempt=(int)$row['attempts']+1;$status=$attempt>=5?'dead':'pending';$retry=min(3600,2**$attempt)+random_int(0,min(30,$attempt));$fail=$this->database->prepare("UPDATE harpp_outbox SET status=:status,available_at=DATE_ADD(NOW(6),INTERVAL :retry SECOND),claim_token=NULL,last_error=:error WHERE id=:id AND claim_token=:token");$fail->execute([':status'=>$status,':retry'=>$retry,':error'=>substr($e->getMessage(),0,2000),':id'=>$row['id'],':token'=>$token]);$failed++;}}
        $dead=(int)$this->database->query("SELECT COUNT(*) FROM harpp_outbox WHERE status='dead'")->fetchColumn();return HarppServiceResult::success(['delivered'=>$delivered,'failed'=>$failed,'dead'=>$dead]);
    }

    private function canonicalJson(array $value): string { ksort($value); foreach($value as &$item)if(is_array($item))$item=json_decode($this->canonicalJson($item),true);return $this->json($value); }
    private function auditReadable(string $type,string $id,array $actor):bool
    {
        $user=(int)($actor['id']??0);$role=(string)($actor['role']??'');if($user<=0||$id==='')return false;if($role==='owner')return true;
        if($type==='harpp_decision'){$sql="SELECT 1 FROM harpp_decisions d WHERE d.id=:id AND ".($role==='admin'?"(d.visibility<>'private' OR d.created_by=:creator OR EXISTS(SELECT 1 FROM harpp_conversation_participants pg WHERE pg.conversation_id=d.conversation_id AND pg.user_id=:private_user AND pg.grant_kind='private_grant' AND pg.revoked_at IS NULL))":"EXISTS(SELECT 1 FROM harpp_workspace_memberships wm WHERE wm.workspace_id=d.workspace_id AND wm.user_id=:workspace_user AND wm.status='active') AND (d.project_id IS NULL OR EXISTS(SELECT 1 FROM harpp_project_memberships pm WHERE pm.project_id=d.project_id AND pm.user_id=:project_user AND pm.status='active')) AND (d.visibility='workspace' OR d.created_by=:creator OR (d.visibility='participants' AND EXISTS(SELECT 1 FROM harpp_conversation_participants cp WHERE cp.conversation_id=d.conversation_id AND cp.user_id=:participant_user AND cp.revoked_at IS NULL)) OR (d.visibility='private' AND EXISTS(SELECT 1 FROM harpp_conversation_participants pg WHERE pg.conversation_id=d.conversation_id AND pg.user_id=:private_user AND pg.grant_kind='private_grant' AND pg.revoked_at IS NULL)))");}
        elseif($type==='harpp_conversation'){$sql="SELECT 1 FROM harpp_conversations c WHERE c.id=:id AND ".($role==='admin'?"(c.visibility<>'private' OR c.created_by=:creator OR EXISTS(SELECT 1 FROM harpp_conversation_participants pg WHERE pg.conversation_id=c.id AND pg.user_id=:private_user AND pg.grant_kind='private_grant' AND pg.revoked_at IS NULL))":"EXISTS(SELECT 1 FROM harpp_workspace_memberships wm WHERE wm.workspace_id=c.workspace_id AND wm.user_id=:workspace_user AND wm.status='active') AND (c.project_id IS NULL OR EXISTS(SELECT 1 FROM harpp_project_memberships pm WHERE pm.project_id=c.project_id AND pm.user_id=:project_user AND pm.status='active')) AND (c.visibility='workspace' OR c.created_by=:creator OR (c.visibility='participants' AND EXISTS(SELECT 1 FROM harpp_conversation_participants cp WHERE cp.conversation_id=c.id AND cp.user_id=:participant_user AND cp.revoked_at IS NULL)) OR (c.visibility='private' AND EXISTS(SELECT 1 FROM harpp_conversation_participants pg WHERE pg.conversation_id=c.id AND pg.user_id=:private_user AND pg.grant_kind='private_grant' AND pg.revoked_at IS NULL)))");}
        elseif($type==='harpp_workspace'){$sql="SELECT 1 FROM harpp_workspace_memberships wm WHERE wm.workspace_id=:id AND wm.user_id=:workspace_user AND wm.status='active'";}
        elseif($type==='harpp_project'){$sql="SELECT 1 FROM harpp_projects p JOIN harpp_workspace_memberships wm ON wm.workspace_id=p.workspace_id AND wm.user_id=:workspace_user AND wm.status='active' LEFT JOIN harpp_project_memberships pm ON pm.project_id=p.id AND pm.user_id=:project_user AND pm.status='active' WHERE p.id=:id AND (pm.user_id IS NOT NULL OR JSON_CONTAINS(wm.roles,'\"manager\"'))";}
        else return $role==='admin';
        $params=[':id'=>$id];foreach(['creator','private_user','workspace_user','project_user','participant_user']as$key)if(str_contains($sql,':'.$key))$params[':'.$key]=$user;$s=$this->database->prepare($sql);$s->execute($params);return$s->fetchColumn()!==false;
    }
    private function json(mixed $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
    private function uuid(): string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4)); }
}
