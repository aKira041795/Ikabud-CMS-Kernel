<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppDecisionService
{
    public const TRANSITIONS = [
        // Operator convenience: an owner/admin may decide directly from any
        // pre-decision state and close directly from any non-terminal state
        // without cycling through every step. ADRs are still created atomically
        // (see transition()/applyAndClose()) and transitions remain append-only.
        'CREATED' => ['PENDING', 'DECIDED', 'CANCELLED'],
        'PENDING' => ['NOTIFIED', 'VIEWED', 'DECIDED', 'CLOSED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
        'NOTIFIED' => ['VIEWED', 'DECIDED', 'CLOSED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
        'VIEWED' => ['DECIDED', 'CLOSED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
        'DECIDED' => ['ACKNOWLEDGED', 'CLOSED', 'SUPERSEDED', 'CANCELLED'],
        'ACKNOWLEDGED' => ['APPLIED', 'CLOSED', 'SUPERSEDED', 'CANCELLED'],
        'APPLIED' => ['CLOSED'],
        'CLOSED' => [], 'EXPIRED' => [], 'SUPERSEDED' => [], 'CANCELLED' => [],
    ];

    private ?HarppFoundationService $foundation = null;

    public function __construct(private ?ModuleDB $database = null, private ?HarppNotificationService $notifications = null) {}

    private function db(): ModuleDB
    {
        if ($this->database instanceof ModuleDB) return $this->database;
        $db = \module('harpp')->db();
        if (!$db instanceof ModuleDB) throw new \RuntimeException('HARPP module database is unavailable.');
        return $this->database = $db;
    }

    public static function isTransitionAllowed(string $from, string $to): bool
    {
        return in_array(strtoupper($to), self::TRANSITIONS[strtoupper($from)] ?? [], true);
    }

    public function create(array $actor, array $input, ?int $tenantId = null)
    {
        if (!$this->scope($tenantId) || !$this->role($actor, ['owner', 'admin'])) return HarppServiceResult::failure('Owner or admin access is required.', 403);
        $title = trim(strip_tags((string)($input['title'] ?? '')));
        $body = trim((string)($input['body'] ?? ''));
        $context = trim((string)($input['context'] ?? ''));
        $requested = trim((string)($input['requested_decision'] ?? ''));
        $priority = strtolower(trim((string)($input['priority'] ?? 'normal')));
        $source = trim((string)($input['source'] ?? 'harness'));
        $workbench = trim((string)($input['workbench_state'] ?? 'ARCHITECTURE_DECISION_REQUIRED'));
        if ($title === '' || strlen($title) > 255 || $body === '' || strlen($body) > 65535 || $requested === '' || !in_array($priority, ['low', 'normal', 'high', 'critical'], true) || !preg_match('/^[a-zA-Z0-9._:-]{1,100}$/', $source) || !preg_match('/^[A-Z][A-Z0-9_]{2,99}$/', $workbench)) {
            return HarppServiceResult::failure('Valid title, body, requested_decision, priority, source, and workbench_state are required.');
        }
        $conversationId = (int)($input['conversation_id'] ?? 0);
        if($this->foundation()->enabled('approval_policies')&&(int)($input['approval_policy_id']??0)<=0)return HarppServiceResult::failure('approval_policy_id is required while approval policies are enabled.');
        $decisionKey = trim((string)($input['decision_key'] ?? '')) ?: 'HARPP-' . strtoupper(bin2hex(random_bytes(8)));
        if (!preg_match('/^[A-Za-z0-9._:-]{4,191}$/', $decisionKey)) return HarppServiceResult::failure('Invalid decision key.');
        $notificationDeliveries = [];
        try {
            $this->db()->beginTransaction();
            $existing = $this->findByKey($decisionKey, true);
            if ($existing) {
                $this->db()->commit();
                return HarppServiceResult::success($this->identity($existing) + ['already_exists' => true], '', [], 'harpp_decision', (int)$existing['id']);
            }
            if ($conversationId <= 0) {
                $workspaceId=$this->legacyWorkspaceId();
                $stmt = $this->db()->prepare("INSERT INTO harpp_conversations (workspace_id,project_id,visibility,title,harness_session_id,status,version,created_by,created_at,updated_at) VALUES (:workspace,NULL,'workspace',:title,:session,'open',1,:user,NOW(),NOW())");
                $session = trim((string)($input['harness_session_id'] ?? ''));
                $stmt->execute([':workspace'=>$workspaceId,':title'=>$title, ':session'=>$session !== '' ? $session : null, ':user'=>(int)$actor['id']]);
                $conversationId = (int)$this->db()->lastInsertId();
            } elseif (!$this->conversationExists($conversationId)) {
                throw new \InvalidArgumentException('Conversation not found.');
            }
            $scope=$this->conversationScope($conversationId);if(!$this->canAccessConversationForDecision($conversationId,$scope,$actor))throw new \InvalidArgumentException('Conversation not found.');
            $stmt = $this->db()->prepare("INSERT INTO harpp_decisions (workspace_id,project_id,visibility,decision_key,conversation_id,title,body,context,requested_decision,priority,source,workbench_state,created_by,lifecycle_state,version,escalation_class,risk_level,options,payload,created_at) VALUES (:workspace,:project,:visibility,:key,:conversation,:title,:body,:context,:requested,:priority,:source,:workbench,:user,'PENDING',1,:escalation,:risk,:options,:payload,NOW())");
            $stmt->execute([':workspace'=>$scope['workspace_id'],':project'=>$scope['project_id'],':visibility'=>$scope['visibility'],':key'=>$decisionKey, ':conversation'=>$conversationId, ':title'=>$title, ':body'=>$body, ':context'=>$context !== '' ? $context : null, ':requested'=>$requested, ':priority'=>$priority, ':source'=>$source, ':workbench'=>$workbench, ':user'=>(int)$actor['id'], ':escalation'=>$input['escalation_class'] ?? null, ':risk'=>$input['risk_level'] ?? null, ':options'=>$this->json($input['options'] ?? null), ':payload'=>$this->json($input['payload'] ?? null)]);
            $decisionId = (int)$this->db()->lastInsertId();
            $this->recordTransition($decisionId, null, 'CREATED', $actor, 'Decision request created.', $workbench);
            $this->recordTransition($decisionId, 'CREATED', 'PENDING', $actor, (string)($input['rationale'] ?? 'Decision request queued for operator review.'), $workbench);
            if($this->foundation()->enabled('notification_fanout')){$recipients=(new HarppCollaborationService($this->db()))->notificationRecipients((int)$scope['workspace_id'],$conversationId,'decision.created',(int)$actor['id']);}else{$recipient=$this->recipient((int)($input['notify_user_id']??0));$recipients=$recipient>0?[$recipient]:[];}
            foreach ($recipients as $recipient) {
                $notice = ($this->notifications ??= new HarppNotificationService($this->db()))->create($recipient, 'decision', ['event'=>'decision.created','decision_id'=>$decisionId,'title'=>$title,'state'=>'PENDING'], $decisionId, $conversationId, null, false);
                if (empty($notice['ok'])) throw new \RuntimeException((string)$notice['error']);
                $notificationDeliveries[]=['id'=>(int)($notice['data']['notification_id']??0),'user_id'=>$recipient];
            }
            (new HarppCollaborationService($this->db()))->snapshotPolicy($decisionId,(int)($input['approval_policy_id']??0));
            $event = $this->recordDomainEffects('harpp.decision.created', 'decision.created', $actor, $decisionId, null, ['state'=>'PENDING','workbench_state'=>$workbench,'version'=>1,'notification_deliveries'=>$notificationDeliveries]);
            $this->db()->commit();
            $this->supplementalAudit('decision.created', $actor, ['decision_id'=>$decisionId]);
            return HarppServiceResult::success(['decision_id'=>$decisionId,'decision_key'=>$decisionKey,'conversation_id'=>$conversationId,'state'=>'PENDING','already_exists'=>false], '', [$event], 'harpp_decision', $decisionId);
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) $this->db()->rollBack();
            if ($this->isDuplicate($e)) {
                $existing = $this->findByKey($decisionKey, false);
                if ($existing) return HarppServiceResult::success($this->identity($existing) + ['already_exists'=>true], '', [], 'harpp_decision', (int)$existing['id']);
            }
            $this->log('decision create failed', $e);
            return HarppServiceResult::failure($e instanceof \InvalidArgumentException ? $e->getMessage() : 'Unable to create decision request.', $e instanceof \InvalidArgumentException ? 404 : 500);
        }
    }

    public function transition(array $actor, int $decisionId, string $toState, string $rationale, array $changes = [], ?int $tenantId = null)
    {
        $toState = strtoupper(trim($toState)); $rationale = trim($rationale);
        if (!$this->scope($tenantId) || !$this->role($actor, ['owner','admin','member'])) return HarppServiceResult::failure('Forbidden.', 403);
        if (!array_key_exists($toState, self::TRANSITIONS) || $rationale === '' || strlen($rationale) > 10000) return HarppServiceResult::failure('A valid target state and rationale are required.');
        try {
            $this->db()->beginTransaction();
            $stmt=$this->db()->prepare('SELECT * FROM harpp_decisions WHERE id=:id FOR UPDATE'); $stmt->execute([':id'=>$decisionId]); $before=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($before)) throw new \InvalidArgumentException('Decision not found.');
            if(!$this->canAccessDecision($before,$actor))throw new \InvalidArgumentException('Decision not found.');
            $from=(string)$before['lifecycle_state'];
            if (!self::isTransitionAllowed($from, $toState)) { $this->db()->rollBack(); return HarppServiceResult::failure("Illegal decision transition: {$from} -> {$toState}.",409,'illegal_transition'); }
            if (($actor['role'] ?? '') === 'member' && !in_array($toState,['PENDING','NOTIFIED','VIEWED','DECIDED'],true)) { $this->db()->rollBack(); return HarppServiceResult::failure('Owner or admin access is required for this transition.',403); }
            if (($actor['role'] ?? '') === 'member' && $toState==='DECIDED' && (!$this->foundation()->enabled('approval_policies') || !(new HarppCollaborationService($this->db()))->reviewerEligible($decisionId,(int)$actor['id']))) { $this->db()->rollBack(); return HarppServiceResult::failure('An explicit eligible reviewer policy snapshot is required.',403,'reviewer_ineligible'); }
            $decision=trim((string)($changes['decision']??''));
            if ($toState==='DECIDED' && $decision==='') { $this->db()->rollBack(); return HarppServiceResult::failure('Decision text is required when deciding.'); }
            $workbench=trim((string)($changes['workbench_state']??$before['workbench_state']??''));
            if ($workbench!=='' && !preg_match('/^[A-Z][A-Z0-9_]{2,99}$/',$workbench)) { $this->db()->rollBack(); return HarppServiceResult::failure('Invalid workbench state.'); }
            if($toState==='DECIDED'&&!(new HarppCollaborationService($this->db()))->approvalSatisfied($decisionId,null)){$this->db()->rollBack();return HarppServiceResult::failure('The snapshotted approval policy is not satisfied.',409,'approval_required');}
            $expected=(int)($changes['expected_version']??$before['version']);
            if($expected!==(int)$before['version']){$this->db()->rollBack();return HarppServiceResult::failure('Decision version conflict.',409,'version_conflict');}
            // A direct close from a pre-decision state also records the decision
            // (rationale fallback) so the atomic ADR below has durable context.
            $isDirectClose = $toState==='CLOSED' && !in_array($from,['DECIDED','ACKNOWLEDGED','APPLIED','CLOSED'],true);
            $sql='UPDATE harpp_decisions SET lifecycle_state=:state,workbench_state=:workbench,version=version+1'; $params=[':state'=>$toState,':workbench'=>$workbench!==''?$workbench:null,':id'=>$decisionId,':version'=>$expected];
            if($toState==='NOTIFIED')$sql.=',notified_at=NOW()';
            if($toState==='DECIDED' || $isDirectClose){$sql.=',decision=:decision,decided_by=:user,decided_at=COALESCE(decided_at,NOW())';$params[':decision']=$decision!==''?$decision:$rationale;$params[':user']=(int)$actor['id'];}
            if($toState==='APPLIED')$sql.=',applied_at=NOW()';
            if(in_array($toState,['CLOSED','EXPIRED','SUPERSEDED','CANCELLED'],true))$sql.=',closed_at=NOW()';
            $updated=$this->db()->prepare($sql.' WHERE id=:id AND version=:version');$updated->execute($params);if($updated->rowCount()!==1){$this->db()->rollBack();return HarppServiceResult::failure('Decision version conflict.',409,'version_conflict');}
            $this->recordTransition($decisionId,$from,$toState,$actor,$rationale,$workbench);
            $adrId = null;
            $adrEvent = null;
            if ($toState === 'DECIDED' || $isDirectClose) {
                $adrDecision = $decision !== '' ? $decision : $rationale;
                $adrId = $this->ensureAdr($before, $adrDecision, $rationale, (int)$actor['id']);
                $adrEvent = $this->recordAdrEffects($actor, $adrId, $decisionId, $before, $adrDecision, $rationale);
            }
            $notificationDeliveries=[];if($this->foundation()->enabled('notification_fanout')){$recipients=(new HarppCollaborationService($this->db()))->notificationRecipients((int)$before['workspace_id'],(int)$before['conversation_id'],'decision.updated',(int)$actor['id']);}else{$recipient=$this->recipient((int)($before['created_by']??0));$recipients=$recipient>0?[$recipient]:[];}foreach($recipients as $recipient){$notice=($this->notifications??=new HarppNotificationService($this->db()))->create($recipient,'decision',['event'=>'decision.updated','decision_id'=>$decisionId,'state'=>$toState],$decisionId,(int)$before['conversation_id'],null,false);if(empty($notice['ok']))throw new \RuntimeException((string)$notice['error']);if(empty($notice['data']['idempotent_replay']))$notificationDeliveries[]=['id'=>(int)($notice['data']['notification_id']??0),'user_id'=>$recipient];}
            $after=['state'=>$toState,'workbench_state'=>$workbench,'adr_id'=>$adrId,'version'=>$expected+1,'notification_deliveries'=>$notificationDeliveries];
            $event=$this->recordDomainEffects('harpp.decision.transitioned','decision.transitioned',$actor,$decisionId,['state'=>$from,'workbench_state'=>$before['workbench_state']],$after,$rationale);
            $this->db()->commit();
            $this->supplementalAudit('decision.transitioned',$actor,['decision_id'=>$decisionId,'from'=>$from,'to'=>$toState]);
            return HarppServiceResult::success(['decision_id'=>$decisionId,'from_state'=>$from,'state'=>$toState,'workbench_state'=>$workbench,'adr_id'=>$adrId,'version'=>$expected+1], '', array_values(array_filter([$adrEvent, $event])), 'harpp_decision', $decisionId);
        }catch(Throwable $e){if($this->db()->inTransaction())$this->db()->rollBack();$this->log('decision transition failed',$e);return HarppServiceResult::failure($e instanceof \InvalidArgumentException?$e->getMessage():'Unable to transition decision.',$e instanceof \InvalidArgumentException?404:500);}
    }

    /**
     * One-click close for owner/admin from ANY non-terminal state — no step cycling.
     * Fast-forwards through the legal chain atomically (creating the ADR when a
     * pre-DECIDED decision is closed directly), enforcing the approval policy and
     * recording every transition append-only. CLOSED stays idempotent.
     */
    public function applyAndClose(array $actor,int $decisionId,string $applyRationale,string $closeRationale,array $changes=[],?int $tenantId=null)
    {
        if(!$this->scope($tenantId)||!$this->role($actor,['owner','admin']))return HarppServiceResult::failure('Forbidden.',403);
        $applyRationale=trim($applyRationale);$closeRationale=trim($closeRationale);if($applyRationale===''||$closeRationale===''||strlen($applyRationale)>10000||strlen($closeRationale)>10000)return HarppServiceResult::failure('Apply and close rationales are required.');
        try{
            $this->db()->beginTransaction();$s=$this->db()->prepare('SELECT * FROM harpp_decisions WHERE id=:id FOR UPDATE');$s->execute([':id'=>$decisionId]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($row))throw new \InvalidArgumentException('Decision not found.');
            if(!$this->canAccessDecision($row,$actor))throw new \InvalidArgumentException('Decision not found.');
            $from=(string)$row['lifecycle_state'];
            if($from==='CLOSED'){$this->db()->commit();return HarppServiceResult::success(['decision_id'=>$decisionId,'state'=>'CLOSED','applied_state'=>'APPLIED','already_applied'=>true], '', [], 'harpp_decision',$decisionId);}
            if(in_array($from,['EXPIRED','SUPERSEDED','CANCELLED'],true)){$this->db()->rollBack();return HarppServiceResult::failure("Illegal decision transition: {$from} -> APPLIED.",409,'illegal_transition');}
            $decisionText=trim((string)($changes['decision']??$row['decision']??''));
            // Never bypass approval: deciding (even via fast-forward) requires a satisfied policy snapshot.
            if(!in_array($from,['DECIDED','ACKNOWLEDGED','APPLIED'],true)&&!(new HarppCollaborationService($this->db()))->approvalSatisfied($decisionId,null)){$this->db()->rollBack();return HarppServiceResult::failure('The snapshotted approval policy is not satisfied.',409,'approval_required');}
            $workbench=trim((string)($changes['workbench_state']??$row['workbench_state']??''));
            $expected=(int)($changes['expected_version']??$row['version']);if($expected!==(int)$row['version']){$this->db()->rollBack();return HarppServiceResult::failure('Decision version conflict.',409,'version_conflict');}
            $adrId=null;$adrEvent=null;$state=$from;$adrText=$decisionText!==''?$decisionText:$applyRationale;
            if(!in_array($state,['DECIDED','ACKNOWLEDGED','APPLIED'],true)){
                $u=$this->db()->prepare("UPDATE harpp_decisions SET lifecycle_state='DECIDED',decision=:decision,decided_by=:user,decided_at=COALESCE(decided_at,NOW()),version=version+1 WHERE id=:id AND version=:version");$u->execute([':decision'=>$adrText,':user'=>(int)$actor['id'],':id'=>$decisionId,':version'=>$expected]);if($u->rowCount()!==1)throw new \RuntimeException('Decision version conflict.');
                $this->recordTransition($decisionId,$state,'DECIDED',$actor,$applyRationale,$workbench);
                $adrId=$this->ensureAdr($row,$adrText,$applyRationale,(int)$actor['id']);$adrEvent=$this->recordAdrEffects($actor,$adrId,$decisionId,$row,$adrText,$applyRationale);
                $state='DECIDED';$expected++;
            }
            if(!in_array($state,['ACKNOWLEDGED','APPLIED'],true)){
                $u=$this->db()->prepare("UPDATE harpp_decisions SET lifecycle_state='ACKNOWLEDGED',version=version+1 WHERE id=:id AND version=:version");$u->execute([':id'=>$decisionId,':version'=>$expected]);if($u->rowCount()!==1)throw new \RuntimeException('Decision version conflict.');
                $this->recordTransition($decisionId,$state,'ACKNOWLEDGED',$actor,$applyRationale,$workbench);$state='ACKNOWLEDGED';$expected++;
            }
            if($state!=='APPLIED'){
                $u=$this->db()->prepare("UPDATE harpp_decisions SET lifecycle_state='APPLIED',applied_at=COALESCE(applied_at,NOW()),workbench_state=:workbench,version=version+1 WHERE id=:id AND version=:version");$u->execute([':workbench'=>$workbench?:null,':id'=>$decisionId,':version'=>$expected]);if($u->rowCount()!==1)throw new \RuntimeException('Decision version conflict.');
                $this->recordTransition($decisionId,$state,'APPLIED',$actor,$applyRationale,$workbench);$state='APPLIED';$expected++;
            }
            $u=$this->db()->prepare("UPDATE harpp_decisions SET lifecycle_state='CLOSED',closed_at=COALESCE(closed_at,NOW()),version=version+1 WHERE id=:id AND version=:version");$u->execute([':id'=>$decisionId,':version'=>$expected]);if($u->rowCount()!==1)throw new \RuntimeException('Decision version conflict.');
            $this->recordTransition($decisionId,'APPLIED','CLOSED',$actor,$closeRationale,$workbench);
            $event=$this->recordDomainEffects('harpp.decision.applied','decision.applied_and_closed',$actor,$decisionId,['state'=>$from],['state'=>'CLOSED','adr_id'=>$adrId]);$this->db()->commit();$this->supplementalAudit('decision.applied_and_closed',$actor,['decision_id'=>$decisionId]);
            return HarppServiceResult::success(['decision_id'=>$decisionId,'from_state'=>$from,'state'=>'CLOSED','applied_state'=>'APPLIED','already_applied'=>$from==='APPLIED'], '', array_values(array_filter([$adrEvent,$event])), 'harpp_decision',$decisionId);
        }catch(Throwable $e){if($this->db()->inTransaction())$this->db()->rollBack();$this->log('decision apply/close failed',$e);return HarppServiceResult::failure($e instanceof \InvalidArgumentException?$e->getMessage():'Unable to apply and close decision.',$e instanceof \InvalidArgumentException?404:500);}
    }

    /** Compatibility endpoint: terminal decisions are archived, never physically deleted. */
    public function delete(array $actor,int $decisionId,?int $tenantId=null)
    {
        if(!$this->scope($tenantId)||!$this->role($actor,['owner','admin']))return HarppServiceResult::failure('Forbidden.',403);
        if($decisionId<=0)return HarppServiceResult::failure('Decision not found.',404);
        return $this->foundation()->archiveDecision($actor,$decisionId);
    }

    /** Compatibility endpoint: archives all terminal decisions and retains ADR/audit/evidence. */
    public function deleteAllClosed(array $actor, ?int $tenantId = null)
    {
        if (!$this->scope($tenantId) || !$this->role($actor, ['owner', 'admin'])) return HarppServiceResult::failure('Forbidden.', 403);
        try {
            $this->db()->beginTransaction();
            $terminal = "('CLOSED','EXPIRED','SUPERSEDED','CANCELLED')";
            $scope=(string)($actor['role']??'')==='owner'?'':' AND (visibility<>\'private\' OR created_by=:actor)';$s = $this->db()->prepare("UPDATE harpp_decisions SET archived_at=COALESCE(archived_at,NOW(6)),version=version+IF(archived_at IS NULL,1,0) WHERE lifecycle_state IN {$terminal} AND archived_at IS NULL".$scope);
            $s->execute($scope!==''?[':actor'=>(int)$actor['id']]:[]);
            $count = $s->rowCount();
            $event=$this->recordDomainEffects('harpp.decisions.archived','decisions.archived',$actor,0,null,['archived'=>$count,'states'=>['CLOSED','EXPIRED','SUPERSEDED','CANCELLED']],'Bulk immutable archive.');
            $this->db()->commit();
            return HarppServiceResult::success(['archived' => $count, 'deleted'=>0,'states' => ['CLOSED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED']], 'Terminal decisions archived; no records were deleted.',[$event]);
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) $this->db()->rollBack();
            $this->log('bulk decision archive failed', $e);
            return HarppServiceResult::failure('Unable to archive terminal decisions.', 500);
        }
    }

    public function get(array $actor,int $decisionId,?int $tenantId=null)
    {if(!$this->scope($tenantId)||!$this->role($actor,['owner','admin','member']))return HarppServiceResult::failure('Forbidden.',403);$s=$this->db()->prepare('SELECT * FROM harpp_decisions WHERE id=:id');$s->execute([':id'=>$decisionId]);$d=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($d))return HarppServiceResult::failure('Decision not found.',404);if(!$this->canAccessDecision($d,$actor))return HarppServiceResult::failure('Decision not found.',404);$a=$this->db()->prepare('SELECT id,from_state,to_state,actor_user_id,actor_type,rationale,workbench_state,created_at FROM harpp_decision_transitions WHERE decision_id=:id ORDER BY created_at,id');$a->execute([':id'=>$decisionId]);return HarppServiceResult::success(['decision'=>$d,'audit_trail'=>$a->fetchAll(PDO::FETCH_ASSOC)],'',[],'harpp_decision',$decisionId);}

    public function list(array $actor,array $filters=[],?int $tenantId=null)
    {
        if(!$this->scope($tenantId)||!$this->role($actor,['owner','admin','member']))return HarppServiceResult::failure('Forbidden.',403);$where=[];$params=[];
        if(!filter_var($filters['include_archived']??false,FILTER_VALIDATE_BOOLEAN))$where[]='archived_at IS NULL';
        if(($filters['state']??'')!==''){$state=strtoupper((string)$filters['state']);if(!array_key_exists($state,self::TRANSITIONS))return HarppServiceResult::failure('Invalid state filter.');$where[]='lifecycle_state=:state';$params[':state']=$state;}
        if(($filters['priority']??'')!==''){$p=strtolower((string)$filters['priority']);if(!in_array($p,['low','normal','high','critical'],true))return HarppServiceResult::failure('Invalid priority filter.');$where[]='priority=:priority';$params[':priority']=$p;}
        if(($filters['workbench_state']??'')!==''){$w=strtoupper(trim((string)$filters['workbench_state']));if(!preg_match('/^[A-Z][A-Z0-9_]{2,99}$/',$w))return HarppServiceResult::failure('Invalid workbench state filter.');$where[]='workbench_state=:workbench';$params[':workbench']=$w;}
        if((int)($filters['actor_id']??0)>0){$where[]='(created_by=:actor OR decided_by=:actor)';$params[':actor']=(int)$filters['actor_id'];}
        $this->appendDecisionScope($where,$params,$actor);
        $cursorAt=trim((string)($filters['cursor_created_at']??''));$cursorId=(int)($filters['cursor_id']??0);if($cursorAt!==''||$cursorId>0){if($cursorAt===''||$cursorId<=0||strtotime($cursorAt)===false)return HarppServiceResult::failure('Both valid cursor_created_at and cursor_id are required.');$where[]='(created_at<:cursor_before OR (created_at=:cursor_equal AND id<:cursor_id))';$params[':cursor_before']=$cursorAt;$params[':cursor_equal']=$cursorAt;$params[':cursor_id']=$cursorId;}
        $limit=max(1,min(100,(int)($filters['limit']??25)));$offset=($cursorAt===''&&$cursorId===0)?max(0,(int)($filters['offset']??0)):0;$sql='SELECT * FROM harpp_decisions'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY created_at DESC,id DESC LIMIT '.$limit.' OFFSET '.$offset;$s=$this->db()->prepare($sql);$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$last=$rows?end($rows):null;
        return HarppServiceResult::success(['decisions'=>$rows,'limit'=>$limit,'offset'=>$offset,'next_cursor'=>$last?['created_at'=>$last['created_at'],'id'=>(int)$last['id']]:null]);
    }

    private function recordAutomaticAdr(array $source,string $decision,string $rationale,int $actorId): int
    {$key='ADR-'.$source['decision_key'];$s=$this->db()->prepare('INSERT INTO harpp_adrs (adr_key,title,context,body,decision,rationale,decision_ref,decided_by,created_at,decided_at) VALUES (:key,:title,:context,:body,:decision,:rationale,:ref,:actor,NOW(),NOW())');$s->execute([':key'=>$key,':title'=>$source['title'],':context'=>trim((string)($source['context']??''))?:$source['body'],':body'=>$source['body'],':decision'=>$decision,':rationale'=>$rationale,':ref'=>(int)$source['id'],':actor'=>$actorId]);return(int)$this->db()->lastInsertId();}
    private function ensureAdr(array $source,string $decision,string $rationale,int $actorId): int
    {$s=$this->db()->prepare('SELECT id FROM harpp_adrs WHERE decision_ref=:id LIMIT 1');$s->execute([':id'=>(int)$source['id']]);$existing=$s->fetchColumn();return $existing!==false?(int)$existing:$this->recordAutomaticAdr($source,$decision,$rationale,$actorId);}
    private function recordAdrEffects(array $actor,int $adrId,int $decisionId,array $source,string $decision,string $rationale):array{$after=['adr_id'=>$adrId,'decision_id'=>$decisionId,'adr_key'=>'ADR-'.$source['decision_key'],'decision'=>$decision,'rationale'=>$rationale,'decided_by'=>(int)($actor['id']??0)];return$this->foundation()->recordEffect('harpp.adr.recorded','adr.recorded',$actor,'harpp_adr',$adrId,null,$after,$rationale);}
    private function recordTransition(int$id,?string$from,string$to,array$actor,string$rationale,string$workbench):void{$s=$this->db()->prepare('INSERT INTO harpp_decision_transitions(decision_id,from_state,to_state,actor_user_id,actor_type,rationale,workbench_state,created_at) VALUES(:id,:from,:to,:actor,:type,:rationale,:workbench,NOW())');$s->execute([':id'=>$id,':from'=>$from,':to'=>$to,':actor'=>(int)($actor['id']??0)?:null,':type'=>($actor['source']??'harpp')==='harpp_bridge'?'harness':'user',':rationale'=>$rationale,':workbench'=>$workbench?:null]);}
    private function recordDomainEffects(string$eventName,string$action,array$actor,int$id,?array$before,array$after,string$reason=''){return$this->foundation()->recordEffect($eventName,$action,$actor,'harpp_decision',$id,$before,$after,$reason);}
    private function findByKey(string$key,bool$lock):?array{$s=$this->db()->prepare('SELECT id,decision_key,conversation_id,lifecycle_state FROM harpp_decisions WHERE decision_key=:key'.($lock?' FOR UPDATE':''));$s->execute([':key'=>$key]);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
    private function identity(array$row){return['decision_id'=>(int)$row['id'],'decision_key'=>(string)$row['decision_key'],'conversation_id'=>(int)$row['conversation_id'],'state'=>(string)$row['lifecycle_state']];}
    private function isDuplicate(Throwable$e):bool{return(string)$e->getCode()==='23000'||str_contains(strtolower($e->getMessage()),'duplicate');}
    private function recipient(int$p):int{if($p>0){$s=$this->db()->prepare('SELECT id FROM harpp_users WHERE id=:id AND is_active=1');$s->execute([':id'=>$p]);if($s->fetchColumn()!==false)return$p;}$s=$this->db()->query("SELECT id FROM harpp_users WHERE is_active=1 AND role IN ('owner','admin') ORDER BY FIELD(role,'owner','admin'),id LIMIT 1");return(int)($s->fetchColumn()?:0);}
    private function conversationExists(int$id):bool{$s=$this->db()->prepare('SELECT id FROM harpp_conversations WHERE id=:id');$s->execute([':id'=>$id]);return$s->fetchColumn()!==false;}
    private function conversationScope(int$id):array{$s=$this->db()->prepare('SELECT workspace_id,project_id,visibility FROM harpp_conversations WHERE id=:id');$s->execute([':id'=>$id]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($r))throw new \InvalidArgumentException('Conversation not found.');return$r;}
    private function legacyWorkspaceId():int{$s=$this->db()->query("SELECT id FROM harpp_workspaces WHERE workspace_key='legacy' LIMIT 1");$id=(int)($s->fetchColumn()?:0);if($id<=0)throw new \RuntimeException('Legacy workspace is unavailable.');return$id;}
    private function foundation():HarppFoundationService{return$this->foundation??=new HarppFoundationService($this->db());}
    private function appendDecisionScope(array&$where,array&$params,array$actor):void{if(!$this->foundation()->enabled('workspace_enforcement')&&!$this->foundation()->enabled('participant_visibility'))return;$role=(string)($actor['role']??'');$user=(int)$actor['id'];if($role==='owner')return;if($role==='admin'){$where[]="(visibility<>'private' OR created_by=:admin_creator OR EXISTS(SELECT 1 FROM harpp_conversation_participants cp WHERE cp.conversation_id=harpp_decisions.conversation_id AND cp.user_id=:admin_grant AND cp.grant_kind='private_grant' AND cp.revoked_at IS NULL))";$params[':admin_creator']=$user;$params[':admin_grant']=$user;return;}$where[]="EXISTS(SELECT 1 FROM harpp_workspace_memberships wm WHERE wm.workspace_id=harpp_decisions.workspace_id AND wm.user_id=:workspace_user AND wm.status='active')";$where[]="(project_id IS NULL OR EXISTS(SELECT 1 FROM harpp_project_memberships pm WHERE pm.project_id=harpp_decisions.project_id AND pm.user_id=:project_user AND pm.status='active'))";$where[]="(visibility='workspace' OR created_by=:decision_creator OR EXISTS(SELECT 1 FROM harpp_conversation_participants cp WHERE cp.conversation_id=harpp_decisions.conversation_id AND cp.user_id=:decision_participant AND cp.revoked_at IS NULL AND (visibility='participants' OR cp.grant_kind='private_grant')))";$params[':workspace_user']=$user;$params[':project_user']=$user;$params[':decision_creator']=$user;$params[':decision_participant']=$user;}
    private function canAccessDecision(array$row,array$actor):bool{if(!$this->foundation()->enabled('workspace_enforcement')&&!$this->foundation()->enabled('participant_visibility'))return true;$role=(string)($actor['role']??'');if($role==='owner')return true;$user=(int)$actor['id'];if($role==='admin'&&$row['visibility']!=='private')return true;$workspace=$this->db()->prepare("SELECT 1 FROM harpp_workspace_memberships WHERE workspace_id=:workspace AND user_id=:user AND status='active'");$workspace->execute([':workspace'=>$row['workspace_id'],':user'=>$user]);$workspaceOk=$workspace->fetchColumn()!==false;$projectOk=true;if((int)($row['project_id']??0)>0){$p=$this->db()->prepare("SELECT 1 FROM harpp_project_memberships WHERE project_id=:project AND user_id=:user AND status='active'");$p->execute([':project'=>$row['project_id'],':user'=>$user]);$projectOk=$p->fetchColumn()!==false;}$g=$this->db()->prepare('SELECT grant_kind FROM harpp_conversation_participants WHERE conversation_id=:conversation AND user_id=:user AND revoked_at IS NULL');$g->execute([':conversation'=>$row['conversation_id'],':user'=>$user]);$grants=$g->fetchAll(PDO::FETCH_COLUMN);if($role==='admin')return in_array('private_grant',$grants,true);return HarppCollaborationPolicy::canSee((string)$row['visibility'],$user,(int)$row['created_by'],$workspaceOk,$projectOk,in_array('participant',$grants,true),in_array('private_grant',$grants,true));}
    private function canAccessConversationForDecision(int$id,array$scope,array$actor):bool{if(!$this->foundation()->enabled('workspace_enforcement')&&!$this->foundation()->enabled('participant_visibility'))return true;$s=$this->db()->prepare('SELECT created_by FROM harpp_conversations WHERE id=:id');$s->execute([':id'=>$id]);$creator=(int)$s->fetchColumn();return$this->canAccessDecision($scope+['conversation_id'=>$id,'created_by'=>$creator],$actor);}
    private function json(mixed$v):?string{return$v===null?null:json_encode($v,JSON_THROW_ON_ERROR);}
    private function scope(?int$t):bool{$c=(int)(\app()->tenant()->current()??0);return$c>0&&($t===null||$t===$c);}
    private function role(array$a,array$r):bool{return(int)($a['id']??0)>0&&in_array((string)($a['source']??'harpp'),['harpp','harpp_bridge'],true)&&in_array((string)($a['role']??''),$r,true);}
    private function supplementalAudit(string$a,array$actor,array$c):void{if(function_exists('write_log'))\write_log('HARPP audit','info',['module'=>'harpp','action'=>$a,'actor_user_id'=>(int)($actor['id']??0)]+$c);}
    private function log(string$m,Throwable$e):void{if(function_exists('write_log'))\write_log('HARPP '.$m,'error',['module'=>'harpp','error'=>$e->getMessage()]);}
}
