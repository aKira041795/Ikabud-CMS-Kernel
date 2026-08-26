<?php

declare(strict_types=1);

require_once __DIR__.'/../services/HarppCollaborationPolicy.php';
require_once __DIR__.'/../services/HarppDecisionService.php';

use Harpp\Services\HarppCollaborationPolicy;
use Harpp\Services\HarppDecisionService;

$checks=0;
$assert=function(bool $condition,string $message)use(&$checks):void{$checks++;if(!$condition)throw new RuntimeException("Contract failure: $message");};
$manifest=json_decode((string)file_get_contents(__DIR__.'/../module.json'),true,512,JSON_THROW_ON_ERROR);
$migration=(string)file_get_contents(__DIR__.'/../database/migrations/007_harpp_integrity_collaboration_foundation.sql');
$decision=(string)file_get_contents(__DIR__.'/../services/HarppDecisionService.php');
$helpers=(string)file_get_contents(__DIR__.'/../helpers.php');
$handlers=(string)file_get_contents(__DIR__.'/../handlers.php');
$detailJs=(string)file_get_contents(__DIR__.'/../assets/decision-detail.js');
$inboxJs=(string)file_get_contents(__DIR__.'/../assets/decisions.js');
$detailTemplate=(string)file_get_contents(dirname(__DIR__,3).'/templates/modules/harpp/decision-detail.disyl');
$inboxTemplate=(string)file_get_contents(dirname(__DIR__,3).'/templates/modules/harpp/decisions.disyl');

$assert($manifest['version']==='2.0.0','manifest semver');
$assert(count($manifest['owns_tables'])===26,'owned-table inventory');
$assert(end($manifest['migrations'])==='database/migrations/007_harpp_integrity_collaboration_foundation.sql','foundation migration registration');
$capabilities=array_column($manifest['capabilities']['exposes'],'id');
foreach(['harpp.lifecycle.transition@2','harpp.archive@1','harpp.purge.request@1','harpp.purge.approve@1','harpp.audit.read@1','harpp.outbox.dispatch@1','harpp.workspace.read@1','harpp.workspace.manage@1','harpp.project.read@1','harpp.project.manage@1','harpp.participant.manage@1','harpp.message.receipt@1','harpp.decision.assign@1','harpp.decision.approve@1','harpp.notification.preferences@1'] as $capability){$assert(in_array($capability,$capabilities,true),"capability $capability");$assert(str_contains($helpers,"'$capability' =>"),"handler mapping $capability");}
foreach(['harpp_workspaces','harpp_workspace_memberships','harpp_projects','harpp_conversation_participants','harpp_message_receipts','harpp_decision_policy_snapshots','harpp_approval_delegations','harpp_audit_events','harpp_outbox','harpp_idempotency_keys','harpp_purge_requests'] as $table){$assert(str_contains($migration,"`$table`"),"migration table $table");}
foreach(['harpp_lifecycle_v2','harpp_immutable_retention','harpp_outbox','harpp_strict_validation','harpp_workspace_enforcement','harpp_participant_visibility','harpp_per_user_receipts','harpp_approval_policies','harpp_notification_fanout'] as $flag){$assert(array_key_exists($flag,$manifest['settings_defaults']),"flag $flag");}

$transitionMatrix=['CREATED'=>['PENDING','DECIDED','CANCELLED'],'PENDING'=>['NOTIFIED','VIEWED','DECIDED','CLOSED','EXPIRED','SUPERSEDED','CANCELLED'],'NOTIFIED'=>['VIEWED','DECIDED','CLOSED','EXPIRED','SUPERSEDED','CANCELLED'],'VIEWED'=>['DECIDED','CLOSED','EXPIRED','SUPERSEDED','CANCELLED'],'DECIDED'=>['ACKNOWLEDGED','CLOSED','SUPERSEDED','CANCELLED'],'ACKNOWLEDGED'=>['APPLIED','CLOSED','SUPERSEDED','CANCELLED'],'APPLIED'=>['CLOSED'],'CLOSED'=>[],'EXPIRED'=>[],'SUPERSEDED'=>[],'CANCELLED'=>[]];foreach(array_keys($transitionMatrix)as$from)foreach(array_keys($transitionMatrix)as$to)$assert(HarppDecisionService::isTransitionAllowed($from,$to)===in_array($to,$transitionMatrix[$from],true),"transition matrix $from -> $to");
$assert(!HarppDecisionService::isTransitionAllowed('VIEWED','APPLIED'),'no lifecycle bypass');
$assert(!preg_match('/DELETE\s+FROM\s+harpp_(decisions|adrs)/i',$decision),'ordinary service cannot erase decision or ADR');
$assert(!str_contains($helpers,'FROM harpp_conversations ORDER BY updated_at'),'entity conversation discovery uses scoped messaging service');
$assert(str_contains($migration,"harpp_migration_007_progress','complete"),'migration completion progress marker');
$assert(str_contains($decision,"in_array(\$from,['EXPIRED','SUPERSEDED','CANCELLED'],true)"),'applyAndClose closes from any non-terminal state');$assert(str_contains($decision,'ensureAdr('),'applyAndClose fast-forwards and creates the ADR');
$assert(str_contains($decision,'recordAutomaticAdr'),'DECIDED path creates ADR');
$applyHandlerStart=strpos($handlers,'function harppDecisionApplyClose');$applyHandlerEnd=strpos($handlers,"\nfunction ",$applyHandlerStart+1);$applyHandler=substr($handlers,$applyHandlerStart,$applyHandlerEnd-$applyHandlerStart);$assert(strpos($applyHandler,'harppRequireCsrf()')<strpos($applyHandler,'harppAuthenticated('),'apply endpoint checks CSRF before auth and mutation');
$assert(str_contains($detailJs,"!terminal.includes(state)"),'apply-and-close UI visible for every non-terminal state');
$assert(!str_contains($detailJs,'Permanently delete')&&!str_contains($inboxJs,'Permanently delete')&&!str_contains($detailTemplate,'Permanently removes'),'decision UI does not claim permanent deletion');
$assert(str_contains($inboxTemplate,'name="include_archived"')&&str_contains($inboxTemplate,'Archive all terminal'),'archived decisions have explicit retrieval and archive semantics');
$assert(str_contains($helpers,"foreach (['user', 'actor', 'actor_user_id', 'tenant_id', 'store_id', '_tenant_id']")&&str_contains($helpers,"SELECT id,role FROM harpp_users WHERE id=:id AND is_active=1")&&str_contains($helpers,"app()->cap()->call('kernel.auth.user@1'"),'new capabilities reject caller authority and bind/revalidate infrastructure actor');
$assert(str_contains($helpers,'function harppCapabilityResult')&&str_contains($helpers,"str_ends_with(\$key, '_id')")&&substr_count($helpers,'return harppCapabilityResult(')>=15,'new capability IDs are opaque decimal strings');
$assert(substr_count($migration,"column_name='harpp_conversations'")===0||substr_count($migration,"table_name='harpp_conversations' AND column_name=")>=4,'conversation ALTER steps are independently resumable');
$assert(substr_count($migration,"table_name='harpp_decisions' AND column_name=")>=7,'decision ALTER steps are independently resumable');
$assert(strpos($migration,'conversation backfill incomplete')<strpos($migration,'idx_harpp_conversation_scope'),'backfill validation precedes legacy indexes and foreign keys');
$assert(HarppCollaborationPolicy::validRoles(['manager','reviewer']),'valid multi-role membership');
$assert(!HarppCollaborationPolicy::validRoles(['reviewer','reviewer']),'duplicate role rejected');
$assert(!HarppCollaborationPolicy::canSee('participants',3,1,true,true,false,false),'participant visibility denied without grant');
$assert(!HarppCollaborationPolicy::canSee('private',3,1,false,false,false,true),'private grant cannot broaden workspace/project access');
$assert(HarppCollaborationPolicy::canSee('private',3,1,true,true,false,true),'explicit private grant permits scoped visibility');
$policy=['quorum'=>2,'exclude_creator'=>true,'exclude_executor'=>true,'allow_veto'=>true];
$assert(HarppCollaborationPolicy::approvalSatisfied($policy,[['user_id'=>2,'vote'=>'approve'],['user_id'=>3,'vote'=>'approve']],1,4),'quorum satisfied by distinct eligible actors');
$assert(!HarppCollaborationPolicy::approvalSatisfied($policy,[['user_id'=>1,'vote'=>'approve'],['user_id'=>3,'vote'=>'approve']],1,4),'creator excluded from quorum');
$assert(!HarppCollaborationPolicy::approvalSatisfied($policy,[['user_id'=>2,'vote'=>'approve'],['user_id'=>3,'vote'=>'veto']],1,4),'veto blocks approval');

echo "HARPP integrity/collaboration contract: $checks checks passed\n";
