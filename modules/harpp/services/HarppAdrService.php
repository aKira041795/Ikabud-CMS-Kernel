<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppAdrService
{
    public function __construct(private ?ModuleDB $database=null){}
    private function db():ModuleDB{if($this->database instanceof ModuleDB)return$this->database;$db=\module('harpp')->db();if(!$db instanceof ModuleDB)throw new \RuntimeException('HARPP module database is unavailable.');return$this->database=$db;}

    public function record(array $actor,array $input,?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId,['owner','admin']))return HarppServiceResult::failure('Owner or admin access is required.',403);
        $decisionId=(int)($input['decision_id']??0);$context=trim((string)($input['context']??''));$decision=trim((string)($input['decision']??''));$rationale=trim((string)($input['rationale']??''));$decidedBy=(int)($input['decided_by']??$actor['id']);
        if($decisionId<=0||$context===''||$decision===''||$rationale===''||$decidedBy<=0)return HarppServiceResult::failure('decision_id, context, decision, rationale, and decided_by are required.');
        try{
            $s=$this->db()->prepare("SELECT id,decision_key,title,body,lifecycle_state,decided_at FROM harpp_decisions WHERE id=:id AND lifecycle_state IN ('DECIDED','ACKNOWLEDGED','APPLIED','CLOSED')");$s->execute([':id'=>$decisionId]);$source=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($source))return HarppServiceResult::failure('A decided decision is required.',409);
            $u=$this->db()->prepare('SELECT id FROM harpp_users WHERE id=:id AND is_active=1');$u->execute([':id'=>$decidedBy]);if($u->fetchColumn()===false)return HarppServiceResult::failure('decided_by is not an active HARPP user.');
            $decidedAt=trim((string)($input['decided_at']??$source['decided_at']??''));$timestamp=strtotime($decidedAt);if(!$timestamp)return HarppServiceResult::failure('A valid decided_at is required.');
            $key=trim((string)($input['adr_key']??('ADR-'.$source['decision_key'])));if(!preg_match('/^[A-Za-z0-9._:-]{4,191}$/',$key))return HarppServiceResult::failure('Invalid ADR key.');
            $this->db()->beginTransaction();$stmt=$this->db()->prepare('INSERT INTO harpp_adrs (adr_key,title,context,body,decision,rationale,adr_origin,decision_ref,decided_by,created_at,decided_at) VALUES (:key,:title,:context,:body,:decision,:rationale,:origin,:ref,:user,NOW(),:decided)');$stmt->execute([':key'=>$key,':title'=>$source['title'],':context'=>$context,':body'=>$source['body'],':decision'=>$decision,':rationale'=>$rationale,':origin'=>'decision',':ref'=>$decisionId,':user'=>$decidedBy,':decided'=>date('Y-m-d H:i:s',$timestamp)]);$id=(int)$this->db()->lastInsertId();
            $event=(new HarppFoundationService($this->db()))->recordEffect('harpp.adr.recorded','adr.recorded',$actor,'harpp_adr',$id,null,['adr_id'=>$id,'decision_id'=>$decisionId]);$this->db()->commit();
            $this->audit('adr.recorded',$actor,['adr_id'=>$id,'decision_id'=>$decisionId]);return HarppServiceResult::success(['adr_id'=>$id,'adr_key'=>$key],'',[$event],'harpp_adr',$id);
        }catch(Throwable $e){if($this->db()->inTransaction())$this->db()->rollBack();$this->log($e);return HarppServiceResult::failure(str_contains(strtolower($e->getMessage()),'duplicate')?'An ADR already exists for this decision.':'Unable to record ADR.',str_contains(strtolower($e->getMessage()),'duplicate')?409:500);}
    }

    public function get(array $actor,int $adrId,?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId,['owner','admin','member']))return HarppServiceResult::failure('Forbidden.',403);
        $s=$this->db()->prepare('SELECT id,adr_key,title,context,body,decision,rationale,decision_ref,decided_by,created_at,decided_at,superseded_by FROM harpp_adrs WHERE id=:id');$s->execute([':id'=>$adrId]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return HarppServiceResult::failure('ADR not found.',404);if(!$this->decisionVisible($actor,(int)($row['decision_ref']??0),$tenantId))return HarppServiceResult::failure('ADR not found.',404);return HarppServiceResult::success(['adr'=>$row],'',[],'harpp_adr',$adrId);
    }

    public function list(array $actor,array $filters=[],?int $tenantId=null)
    {
        if(!$this->access($actor,$tenantId,['owner','admin','member']))return HarppServiceResult::failure('Forbidden.',403);
        $where=[];$params=[];$search=trim((string)($filters['search']??''));if($search!==''){if(strlen($search)>100)return HarppServiceResult::failure('Search is too long.');$where[]='(title LIKE :search_title OR context LIKE :search_context OR decision LIKE :search_decision OR rationale LIKE :search_rationale)';$term='%'.str_replace(['%','_'],['\\%','\\_'],$search).'%';$params[':search_title']=$term;$params[':search_context']=$term;$params[':search_decision']=$term;$params[':search_rationale']=$term;}
        if((int)($filters['decision_id']??0)>0){$where[]='decision_ref=:decision';$params[':decision']=(int)$filters['decision_id'];}
        $limit=max(1,min(100,(int)($filters['limit']??25)));$offset=max(0,(int)($filters['offset']??0));$scan=min(500,$limit+$offset+100);$sql='SELECT id,adr_key,title,context,body,decision,rationale,decision_ref,decided_by,created_at,decided_at,superseded_by FROM harpp_adrs'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY decided_at DESC,id DESC LIMIT '.$scan;$s=$this->db()->prepare($sql);$s->execute($params);$visible=[];foreach($s->fetchAll(PDO::FETCH_ASSOC)as$row)if($this->decisionVisible($actor,(int)($row['decision_ref']??0),$tenantId))$visible[]=$row;return HarppServiceResult::success(['adrs'=>array_slice($visible,$offset,$limit),'limit'=>$limit,'offset'=>$offset]);
    }
    private function decisionVisible(array $actor,int $decisionId,?int $tenantId):bool{if($decisionId<=0)return in_array((string)($actor['role']??''),['owner','admin'],true);$result=(new HarppDecisionService($this->db()))->get($actor,$decisionId,$tenantId);return!empty($result['ok']);}
    private function access(array $actor,?int $tenantId,array $roles):bool{$current=(int)(\app()->tenant()->current()??0);return$current>0&&($tenantId===null||$tenantId===$current)&&(int)($actor['id']??0)>0&&($actor['source']??'harpp')==='harpp'&&in_array((string)($actor['role']??''),$roles,true);}
    private function audit(string $action,array $actor,array $context):void{if(function_exists('write_log'))\write_log('HARPP audit','HARPP',['module'=>'harpp','action'=>$action,'actor_user_id'=>(int)$actor['id']]+$context);}
    private function log(Throwable $e):void{if(function_exists('write_log'))\write_log('HARPP ADR operation failed','error',['module'=>'harpp','error'=>$e->getMessage()]);}
}
