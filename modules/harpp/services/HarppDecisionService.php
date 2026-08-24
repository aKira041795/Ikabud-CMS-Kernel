<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppDecisionService
{
    public const TRANSITIONS = [
        'CREATED' => ['PENDING', 'CANCELLED'],
        'PENDING' => ['NOTIFIED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
        'NOTIFIED' => ['VIEWED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
        'VIEWED' => ['DECIDED', 'EXPIRED', 'SUPERSEDED', 'CANCELLED'],
        'DECIDED' => ['ACKNOWLEDGED', 'SUPERSEDED', 'CANCELLED'],
        'ACKNOWLEDGED' => ['APPLIED', 'SUPERSEDED', 'CANCELLED'],
        'APPLIED' => ['CLOSED'],
        'CLOSED' => [], 'EXPIRED' => [], 'SUPERSEDED' => [], 'CANCELLED' => [],
    ];

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
        $decisionKey = trim((string)($input['decision_key'] ?? '')) ?: 'HARPP-' . strtoupper(bin2hex(random_bytes(8)));
        if (!preg_match('/^[A-Za-z0-9._:-]{4,191}$/', $decisionKey)) return HarppServiceResult::failure('Invalid decision key.');
        $notificationId = 0; $recipient = 0;
        try {
            $this->db()->beginTransaction();
            $existing = $this->findByKey($decisionKey, true);
            if ($existing) {
                $this->db()->commit();
                return HarppServiceResult::success($this->identity($existing) + ['already_exists' => true], '', [], 'harpp_decision', (int)$existing['id']);
            }
            if ($conversationId <= 0) {
                $stmt = $this->db()->prepare("INSERT INTO harpp_conversations (title,harness_session_id,status,created_by,created_at,updated_at) VALUES (:title,:session,'open',:user,NOW(),NOW())");
                $session = trim((string)($input['harness_session_id'] ?? ''));
                $stmt->execute([':title'=>$title, ':session'=>$session !== '' ? $session : null, ':user'=>(int)$actor['id']]);
                $conversationId = (int)$this->db()->lastInsertId();
            } elseif (!$this->conversationExists($conversationId)) {
                throw new \InvalidArgumentException('Conversation not found.');
            }
            $stmt = $this->db()->prepare("INSERT INTO harpp_decisions (decision_key,conversation_id,title,body,context,requested_decision,priority,source,workbench_state,created_by,lifecycle_state,escalation_class,risk_level,options,payload,created_at) VALUES (:key,:conversation,:title,:body,:context,:requested,:priority,:source,:workbench,:user,'PENDING',:escalation,:risk,:options,:payload,NOW())");
            $stmt->execute([':key'=>$decisionKey, ':conversation'=>$conversationId, ':title'=>$title, ':body'=>$body, ':context'=>$context !== '' ? $context : null, ':requested'=>$requested, ':priority'=>$priority, ':source'=>$source, ':workbench'=>$workbench, ':user'=>(int)$actor['id'], ':escalation'=>$input['escalation_class'] ?? null, ':risk'=>$input['risk_level'] ?? null, ':options'=>$this->json($input['options'] ?? null), ':payload'=>$this->json($input['payload'] ?? null)]);
            $decisionId = (int)$this->db()->lastInsertId();
            $this->recordTransition($decisionId, null, 'CREATED', $actor, 'Decision request created.', $workbench);
            $this->recordTransition($decisionId, 'CREATED', 'PENDING', $actor, (string)($input['rationale'] ?? 'Decision request queued for operator review.'), $workbench);
            $recipient = $this->recipient((int)($input['notify_user_id'] ?? 0));
            if ($recipient > 0) {
                $notice = ($this->notifications ??= new HarppNotificationService($this->db()))->create($recipient, 'decision', ['event'=>'decision.created','decision_id'=>$decisionId,'title'=>$title,'state'=>'PENDING'], $decisionId, $conversationId, null, false);
                if (empty($notice['ok'])) throw new \RuntimeException((string)$notice['error']);
                $notificationId = (int)($notice['data']['notification_id'] ?? 0);
            }
            $event = $this->recordDomainEffects('harpp.decision.created', 'decision.created', $actor, $decisionId, null, ['state'=>'PENDING','workbench_state'=>$workbench]);
            $this->db()->commit();
            $this->supplementalAudit('decision.created', $actor, ['decision_id'=>$decisionId]);
            if ($notificationId > 0) ($this->notifications ??= new HarppNotificationService($this->db()))->dispatch($notificationId, $recipient);
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
            $from=(string)$before['lifecycle_state'];
            if (!self::isTransitionAllowed($from, $toState)) { $this->db()->rollBack(); return HarppServiceResult::failure("Illegal decision transition: {$from} -> {$toState}.",409,'illegal_transition'); }
            if (($actor['role'] ?? '') === 'member' && !in_array($toState,['PENDING','NOTIFIED','VIEWED','DECIDED'],true)) { $this->db()->rollBack(); return HarppServiceResult::failure('Owner or admin access is required for this transition.',403); }
            $decision=trim((string)($changes['decision']??''));
            if ($toState==='DECIDED' && $decision==='') { $this->db()->rollBack(); return HarppServiceResult::failure('Decision text is required when deciding.'); }
            $workbench=trim((string)($changes['workbench_state']??$before['workbench_state']??''));
            if ($workbench!=='' && !preg_match('/^[A-Z][A-Z0-9_]{2,99}$/',$workbench)) { $this->db()->rollBack(); return HarppServiceResult::failure('Invalid workbench state.'); }
            $sql='UPDATE harpp_decisions SET lifecycle_state=:state,workbench_state=:workbench'; $params=[':state'=>$toState,':workbench'=>$workbench!==''?$workbench:null,':id'=>$decisionId];
            if($toState==='NOTIFIED')$sql.=',notified_at=NOW()';
            if($toState==='DECIDED'){$sql.=',decision=:decision,decided_by=:user,decided_at=NOW()';$params[':decision']=$decision;$params[':user']=(int)$actor['id'];}
            if($toState==='APPLIED')$sql.=',applied_at=NOW()';
            if(in_array($toState,['CLOSED','EXPIRED','SUPERSEDED','CANCELLED'],true))$sql.=',closed_at=NOW()';
            $this->db()->prepare($sql.' WHERE id=:id')->execute($params);
            $this->recordTransition($decisionId,$from,$toState,$actor,$rationale,$workbench);
            $adrId = null;
            $adrEvent = null;
            if ($toState === 'DECIDED') {
                $adrId = $this->recordAutomaticAdr($before, $decision, $rationale, (int)$actor['id']);
                $adrEvent = $this->recordAdrEffects($actor, $adrId, $decisionId, $before, $decision, $rationale);
            }
            $notificationId=0; $recipient=$this->recipient((int)($before['created_by']??0));
            if($recipient>0){$notice=($this->notifications??=new HarppNotificationService($this->db()))->create($recipient,'decision',['event'=>'decision.updated','decision_id'=>$decisionId,'state'=>$toState],$decisionId,(int)$before['conversation_id'],null,false);if(empty($notice['ok']))throw new \RuntimeException((string)$notice['error']);$notificationId=(int)($notice['data']['notification_id']??0);}
            $after=['state'=>$toState,'workbench_state'=>$workbench,'adr_id'=>$adrId];
            $event=$this->recordDomainEffects('harpp.decision.transitioned','decision.transitioned',$actor,$decisionId,['state'=>$from,'workbench_state'=>$before['workbench_state']],$after,$rationale);
            $this->db()->commit();
            $this->supplementalAudit('decision.transitioned',$actor,['decision_id'=>$decisionId,'from'=>$from,'to'=>$toState]);
            if($notificationId>0)($this->notifications??=new HarppNotificationService($this->db()))->dispatch($notificationId,$recipient);
            return HarppServiceResult::success(['decision_id'=>$decisionId,'from_state'=>$from,'state'=>$toState,'workbench_state'=>$workbench,'adr_id'=>$adrId], '', array_values(array_filter([$adrEvent, $event])), 'harpp_decision', $decisionId);
        }catch(Throwable $e){if($this->db()->inTransaction())$this->db()->rollBack();$this->log('decision transition failed',$e);return HarppServiceResult::failure($e instanceof \InvalidArgumentException?$e->getMessage():'Unable to transition decision.',$e instanceof \InvalidArgumentException?404:500);}
    }

    /** Retry-safe ACKNOWLEDGED/APPLIED -> APPLIED -> CLOSED transaction. */
    public function applyAndClose(array $actor,int $decisionId,string $applyRationale,string $closeRationale,array $changes=[],?int $tenantId=null)
    {
        if(!$this->scope($tenantId)||!$this->role($actor,['owner','admin']))return HarppServiceResult::failure('Forbidden.',403);
        try{
            $this->db()->beginTransaction();$s=$this->db()->prepare('SELECT * FROM harpp_decisions WHERE id=:id FOR UPDATE');$s->execute([':id'=>$decisionId]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($row))throw new \InvalidArgumentException('Decision not found.');
            $from=(string)$row['lifecycle_state'];
            if($from==='CLOSED'){$this->db()->commit();return HarppServiceResult::success(['decision_id'=>$decisionId,'state'=>'CLOSED','applied_state'=>'APPLIED','already_applied'=>true], '', [], 'harpp_decision',$decisionId);}
            if(!in_array($from,['ACKNOWLEDGED','APPLIED'],true)){$this->db()->rollBack();return HarppServiceResult::failure("Illegal decision transition: {$from} -> APPLIED.",409,'illegal_transition');}
            $workbench=trim((string)($changes['workbench_state']??$row['workbench_state']??''));
            if($from==='ACKNOWLEDGED'){$this->db()->prepare("UPDATE harpp_decisions SET lifecycle_state='APPLIED',applied_at=COALESCE(applied_at,NOW()),workbench_state=:workbench WHERE id=:id")->execute([':workbench'=>$workbench?:null,':id'=>$decisionId]);$this->recordTransition($decisionId,'ACKNOWLEDGED','APPLIED',$actor,$applyRationale,$workbench);}
            $this->db()->prepare("UPDATE harpp_decisions SET lifecycle_state='CLOSED',closed_at=COALESCE(closed_at,NOW()) WHERE id=:id")->execute([':id'=>$decisionId]);$this->recordTransition($decisionId,'APPLIED','CLOSED',$actor,$closeRationale,$workbench);
            $event=$this->recordDomainEffects('harpp.decision.applied','decision.applied_and_closed',$actor,$decisionId,['state'=>$from],['state'=>'CLOSED']);$this->db()->commit();$this->supplementalAudit('decision.applied_and_closed',$actor,['decision_id'=>$decisionId]);
            return HarppServiceResult::success(['decision_id'=>$decisionId,'from_state'=>$from,'state'=>'CLOSED','applied_state'=>'APPLIED','already_applied'=>$from==='APPLIED'], '', [$event], 'harpp_decision',$decisionId);
        }catch(Throwable $e){if($this->db()->inTransaction())$this->db()->rollBack();$this->log('decision apply/close failed',$e);return HarppServiceResult::failure($e instanceof \InvalidArgumentException?$e->getMessage():'Unable to apply and close decision.',$e instanceof \InvalidArgumentException?404:500);}
    }

    public function get(array $actor,int $decisionId,?int $tenantId=null)
    {if(!$this->scope($tenantId)||!$this->role($actor,['owner','admin','member']))return HarppServiceResult::failure('Forbidden.',403);$s=$this->db()->prepare('SELECT * FROM harpp_decisions WHERE id=:id');$s->execute([':id'=>$decisionId]);$d=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($d))return HarppServiceResult::failure('Decision not found.',404);$a=$this->db()->prepare('SELECT id,from_state,to_state,actor_user_id,actor_type,rationale,workbench_state,created_at FROM harpp_decision_transitions WHERE decision_id=:id ORDER BY created_at,id');$a->execute([':id'=>$decisionId]);return HarppServiceResult::success(['decision'=>$d,'audit_trail'=>$a->fetchAll(PDO::FETCH_ASSOC)],'',[],'harpp_decision',$decisionId);}

    public function list(array $actor,array $filters=[],?int $tenantId=null)
    {if(!$this->scope($tenantId)||!$this->role($actor,['owner','admin','member']))return HarppServiceResult::failure('Forbidden.',403);$where=[];$params=[];if(($filters['state']??'')!==''){$state=strtoupper((string)$filters['state']);if(!array_key_exists($state,self::TRANSITIONS))return HarppServiceResult::failure('Invalid state filter.');$where[]='lifecycle_state=:state';$params[':state']=$state;}if(($filters['priority']??'')!==''){$p=strtolower((string)$filters['priority']);if(!in_array($p,['low','normal','high','critical'],true))return HarppServiceResult::failure('Invalid priority filter.');$where[]='priority=:priority';$params[':priority']=$p;}if(($filters['workbench_state']??'')!==''){$w=strtoupper(trim((string)$filters['workbench_state']));if(!preg_match('/^[A-Z][A-Z0-9_]{2,99}$/',$w))return HarppServiceResult::failure('Invalid workbench state filter.');$where[]='workbench_state=:workbench';$params[':workbench']=$w;}if((int)($filters['actor_id']??0)>0){$where[]='(created_by=:actor OR decided_by=:actor)';$params[':actor']=(int)$filters['actor_id'];}$limit=max(1,min(100,(int)($filters['limit']??25)));$offset=max(0,(int)($filters['offset']??0));$sql='SELECT * FROM harpp_decisions'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY created_at DESC,id DESC LIMIT '.$limit.' OFFSET '.$offset;$s=$this->db()->prepare($sql);$s->execute($params);return HarppServiceResult::success(['decisions'=>$s->fetchAll(PDO::FETCH_ASSOC),'limit'=>$limit,'offset'=>$offset]);}

    private function recordAutomaticAdr(array $source,string $decision,string $rationale,int $actorId): int
    {$key='ADR-'.$source['decision_key'];$s=$this->db()->prepare('INSERT INTO harpp_adrs (adr_key,title,context,body,decision,rationale,decision_ref,decided_by,created_at,decided_at) VALUES (:key,:title,:context,:body,:decision,:rationale,:ref,:actor,NOW(),NOW())');$s->execute([':key'=>$key,':title'=>$source['title'],':context'=>trim((string)($source['context']??''))?:$source['body'],':body'=>$source['body'],':decision'=>$decision,':rationale'=>$rationale,':ref'=>(int)$source['id'],':actor'=>$actorId]);return(int)$this->db()->lastInsertId();}
    private function recordAdrEffects(array $actor,int $adrId,int $decisionId,array $source,string $decision,string $rationale):array{$after=['adr_id'=>$adrId,'decision_id'=>$decisionId,'adr_key'=>'ADR-'.$source['decision_key'],'decision'=>$decision,'rationale'=>$rationale,'decided_by'=>(int)($actor['id']??0)];$payload=$after+['actor_user_id'=>(int)($actor['id']??0)];if(function_exists('app')){\app()->events()->fire('harpp.adr.recorded',$payload,'harpp');$audit=\app()->cap()->call('kernel.audit.record@1',['module'=>'harpp','action'=>'adr.recorded','entity_type'=>'harpp_adr','entity_id'=>(string)$adrId,'new_data'=>$after,'reason'=>$rationale],['mode'=>'first','caller_module'=>'harpp']);if(!is_array($audit)||empty($audit['ok']))throw new \RuntimeException('Kernel audit recording failed.');}return['name'=>'harpp.adr.recorded','payload'=>$payload];}
    private function recordTransition(int$id,?string$from,string$to,array$actor,string$rationale,string$workbench):void{$s=$this->db()->prepare('INSERT INTO harpp_decision_transitions(decision_id,from_state,to_state,actor_user_id,actor_type,rationale,workbench_state,created_at) VALUES(:id,:from,:to,:actor,:type,:rationale,:workbench,NOW())');$s->execute([':id'=>$id,':from'=>$from,':to'=>$to,':actor'=>(int)($actor['id']??0)?:null,':type'=>($actor['source']??'harpp')==='harpp_bridge'?'harness':'user',':rationale'=>$rationale,':workbench'=>$workbench?:null]);}
    private function recordDomainEffects(string$eventName,string$action,array$actor,int$id,?array$before,array$after,string$reason=''){$payload=['decision_id'=>$id,'actor_user_id'=>(int)($actor['id']??0),'before'=>$before,'after'=>$after];if(function_exists('app')){\app()->events()->fire($eventName,$payload,'harpp');$audit=\app()->cap()->call('kernel.audit.record@1',['module'=>'harpp','action'=>$action,'entity_type'=>'harpp_decision','entity_id'=>(string)$id,'old_data'=>$before,'new_data'=>$after,'reason'=>$reason],['mode'=>'first','caller_module'=>'harpp']);if(!is_array($audit)||empty($audit['ok']))throw new \RuntimeException('Kernel audit recording failed.');}return ['name'=>$eventName,'payload'=>$payload];}
    private function findByKey(string$key,bool$lock):?array{$s=$this->db()->prepare('SELECT id,decision_key,conversation_id,lifecycle_state FROM harpp_decisions WHERE decision_key=:key'.($lock?' FOR UPDATE':''));$s->execute([':key'=>$key]);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
    private function identity(array$row){return['decision_id'=>(int)$row['id'],'decision_key'=>(string)$row['decision_key'],'conversation_id'=>(int)$row['conversation_id'],'state'=>(string)$row['lifecycle_state']];}
    private function isDuplicate(Throwable$e):bool{return(string)$e->getCode()==='23000'||str_contains(strtolower($e->getMessage()),'duplicate');}
    private function recipient(int$p):int{if($p>0){$s=$this->db()->prepare('SELECT id FROM harpp_users WHERE id=:id AND is_active=1');$s->execute([':id'=>$p]);if($s->fetchColumn()!==false)return$p;}$s=$this->db()->query("SELECT id FROM harpp_users WHERE is_active=1 AND role IN ('owner','admin') ORDER BY FIELD(role,'owner','admin'),id LIMIT 1");return(int)($s->fetchColumn()?:0);}
    private function conversationExists(int$id):bool{$s=$this->db()->prepare('SELECT id FROM harpp_conversations WHERE id=:id');$s->execute([':id'=>$id]);return$s->fetchColumn()!==false;}
    private function json(mixed$v):?string{return$v===null?null:json_encode($v,JSON_THROW_ON_ERROR);}
    private function scope(?int$t):bool{$c=(int)(\app()->tenant()->current()??0);return$c>0&&($t===null||$t===$c);}
    private function role(array$a,array$r):bool{return(int)($a['id']??0)>0&&in_array((string)($a['source']??'harpp'),['harpp','harpp_bridge'],true)&&in_array((string)($a['role']??''),$r,true);}
    private function supplementalAudit(string$a,array$actor,array$c):void{if(function_exists('write_log'))\write_log('HARPP audit','info',['module'=>'harpp','action'=>$a,'actor_user_id'=>(int)($actor['id']??0)]+$c);}
    private function log(string$m,Throwable$e):void{if(function_exists('write_log'))\write_log('HARPP '.$m,'error',['module'=>'harpp','error'=>$e->getMessage()]);}
}
