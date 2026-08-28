<?php

declare(strict_types=1);

// S2 approved-memory retrieval CLI test: verifies HarppMemoryService::search
// returns APPROVED decisions/ADRs only (never PENDING/unapproved), is bounded and
// truncated, and that integrate() surfaces the approved-memory block for a
// conversation — exactly as an MCP client would via the bridge endpoint.
//
// Run:  php modules/harpp/tests/memory_search_cli_test.php 1

$root = dirname(__DIR__, 3);
$logs = [$root.'/storage/logs/app.log', $root.'/storage/logs/error.log'];
foreach ($logs as $log) { if (is_file($log)) file_put_contents($log, ''); }
require $root.'/bootstrap.php';
$tenantId=(int)($_SERVER['argv'][1]??1);
app()->tenant()->setTenantId($tenantId);
require_once dirname(__DIR__).'/helpers.php';
require_once $root . '/tests/harness/TestHarness.php';

use Harpp\Services\HarppMemoryService;
use Harpp\Services\HarppDecisionService;
use Harpp\Services\HarppMessagingService;

$manifest=json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=app()->dbForTenant($tenantId);
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/013_harpp_runner_work_queue.sql'));
$cols=array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='harpp_work_runs'")->fetchAll(PDO::FETCH_ASSOC),'COLUMN_NAME');
if(!in_array('attempt_count',$cols,true))$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/014_harpp_runner_reconcile.sql'));
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/015_harpp_context_summary.sql'));
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/016_harpp_artifact_bundle.sql'));
$db=new \Ikabud\Kernel\Contracts\ModuleDB($pdo,'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$owner['id']=(int)$owner['id'];$owner['source']='harpp';

$h = new TestHarness('harpp-memory-search');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$memory = new HarppMemoryService($db);
$decisionSvc = new HarppDecisionService($db);
$messaging = new HarppMessagingService($db);

$term = 'zephyrmemory';
$cleanup=[]; // decisionId, conv
try {
    // Conversation A — holds the approved + the PENDING decision.
    $ca=$messaging->createConversation($owner,['title'=>'Memory conversation A','harness_session_id'=>'mem-A'],$tenantId);
    $convA=(int)($ca['data']['conversation_id']??0);$cleanup[]=['decisionId'=>0,'conv'=>$convA];

    // Approved decision (conv A) with the distinctive term -> CLOSED mints ADR + artifact bundle.
    $dc=$decisionSvc->create($owner,['title'=>'Approved '.$term.' choice','body'=>'body about '.$term,'requested_decision'=>'pick','decision_key'=>'MEM-'.bin2hex(random_bytes(3)),'priority'=>'normal','source'=>'harness','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED','conversation_id'=>$convA],$tenantId);
    $approvedId=(int)($dc['data']['decision_id']??0);$cleanup[]=['decisionId'=>$approvedId,'conv'=>$convA];
    $tr=$decisionSvc->transition($owner,$approvedId,'CLOSED','approve the '.$term.' choice',['decision'=>'We approve the '.$term.' approach'],$tenantId);
    $assert('approved decision transitions to CLOSED with ADR',!empty($tr['ok'])&&($tr['data']['state']??'')==='CLOSED'&&(int)($tr['data']['adr_id']??0)>0,'tr='.json_encode($tr));

    // PENDING (NOT approved) decision in the same conversation with the same term.
    $pp=$decisionSvc->create($owner,['title'=>'Pending '.$term.' draft','body'=>'still open about '.$term,'requested_decision'=>'pick','decision_key'=>'MEM-'.bin2hex(random_bytes(3)),'priority'=>'normal','source'=>'harness','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED','conversation_id'=>$convA],$tenantId);
    $pendingId=(int)($pp['data']['decision_id']??0);$cleanup[]=['decisionId'=>$pendingId,'conv'=>$convA];
    $assert('PENDING decision stays unapproved',($pp['data']['state']??'')==='PENDING','pending='.($pp['data']['state']??'?'));

    // Different conversation's approved decision with the same term (tenant-scoped: in-scope).
    $cb=$messaging->createConversation($owner,['title'=>'Memory conversation B','harness_session_id'=>'mem-B'],$tenantId);
    $convB=(int)($cb['data']['conversation_id']??0);$cleanup[]=['decisionId'=>0,'conv'=>$convB];
    $ob=$decisionSvc->create($owner,['title'=>'Other '.$term.' decision','body'=>'another '.$term,'requested_decision'=>'pick','decision_key'=>'MEM-'.bin2hex(random_bytes(3)),'priority'=>'normal','source'=>'harness','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED','conversation_id'=>$convB],$tenantId);
    $otherId=(int)($ob['data']['decision_id']??0);$cleanup[]=['decisionId'=>$otherId,'conv'=>$convB];
    $decisionSvc->transition($owner,$otherId,'CLOSED','approve too',['decision'=>'Also approve '.$term],$tenantId);

    // search returns the approved decision's ADR/decision, excludes PENDING, carries approved id.
    $res=$memory->search($owner,['q'=>$term],$tenantId);
    $rows=(array)($res['data']['results']??[]);
    $ids=array_map('intval',array_column($rows,'decision_id'));
    $assert('search returns approved decision(s)',!empty($res['ok'])&&count($rows)>0,'rows='.json_encode($rows));
    $assert('approved decision id present in results',in_array($approvedId,$ids,true),'ids='.implode(',',$ids));
    $assert('PENDING (unapproved) decision excluded from results',!in_array($pendingId,$ids,true),'ids='.implode(',',$ids));
    // Tenant-scoped: approved memory is reusable across conversations in the same tenant.
    $assert('other-conversation approved decision is in tenant scope (reusable)',in_array($otherId,$ids,true),'ids='.implode(',',$ids));

    // limit bounds results.
    $bounded=$memory->search($owner,['q'=>$term,'limit'=>1],$tenantId);
    $assert('limit bounds results',count((array)($bounded['data']['results']??[]))<=1,'n='.count((array)($bounded['data']['results']??[])));

    // payloads are truncated (<= 500 chars) on every returned field.
    $maxLen=0;
    foreach ($rows as $r) {
        foreach (['title','decision','rationale','snippet'] as $f) {
            if (isset($r[$f])) $maxLen=max($maxLen,mb_strlen((string)$r[$f]));
        }
    }
    $assert('result payloads are truncated (<=500 chars)',$maxLen>0&&$maxLen<=500,'maxLen='.$maxLen);

    // artifact-bundle payload search returns bounded snippets.
    $art=$memory->search($owner,['q'=>$term,'limit'=>10],$tenantId);
    $artRows=(array)($art['data']['results']??[]);
    $artifactRows=array_values(array_filter($artRows,static fn($r)=>($r['matched_on']??'')==='artifact'));
    $snippetOk=true;
    foreach ($artifactRows as $r) { if (isset($r['snippet']) && mb_strlen((string)$r['snippet'])>500) $snippetOk=false; }
    $assert('artifact payload search returns truncated snippets',count($artifactRows)>=1&&$snippetOk,'n='.count($artifactRows));

    // integrate() returns the approved snippet(s) for the conversation.
    $mem=$memory->integrate($convA);
    $memIds=$mem===null?[]:array_map('intval',array_column($mem,'decision_id'));
    $assert('integrate returns approved memory for the conversation',$mem!==null&&in_array($approvedId,$memIds,true)&&!in_array($pendingId,$memIds,true),'mem='.json_encode($mem));
} finally {
    foreach (array_reverse($cleanup) as $row) {
        $decisionId=(int)$row['decisionId'];$conv=(int)$row['conv'];
        try {
        if($decisionId>0){
            $b=$db->prepare("SELECT id FROM harpp_artifact_bundles WHERE aggregate_type='decision' AND aggregate_id=:id");$b->execute([':id'=>$decisionId]);$bid=(int)$b->fetchColumn();
            if($bid>0)$db->prepare('DELETE FROM harpp_artifact_bundles WHERE id=:id')->execute([':id'=>$bid]);
            $db->prepare('DELETE FROM harpp_adrs WHERE decision_ref=:id')->execute([':id'=>$decisionId]);
            $db->prepare('DELETE FROM harpp_decision_transitions WHERE decision_id=:id')->execute([':id'=>$decisionId]);
            $db->prepare('DELETE FROM harpp_notifications WHERE decision_id=:id OR conversation_id=:c')->execute([':id'=>$decisionId,':c'=>$conv]);
            $db->prepare('DELETE FROM harpp_decisions WHERE id=:id')->execute([':id'=>$decisionId]);
        }
        if($conv>0){
            $db->prepare('DELETE FROM harpp_context_summary WHERE conversation_id=:id')->execute([':id'=>$conv]);
            $db->prepare('DELETE FROM harpp_notifications WHERE conversation_id=:id')->execute([':id'=>$conv]);
            $db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$conv]);
        }
        } catch (\Throwable $e) { echo "\nCLEANUP ERR decisionId=$decisionId conv=$conv: ".$e->getMessage()."\n"; }
    }
}

$h->done();
