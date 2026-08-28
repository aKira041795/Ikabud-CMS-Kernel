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
$runBundleId=0; // unknown run-bundle artifact (no decision linkage)
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

    // === RAG keystone: authority-ranked, fail-closed retrieval (additive) ===

    // Current ADR result carries authority/status/revision.
    $curRow=null;
    foreach ($rows as $r) {
        if (($r['matched_on']??'')==='adr' && (int)($r['decision_id']??0)===$approvedId) { $curRow=$r; break; }
    }
    $assert('current ADR result has authority/status/revision',is_array($curRow)&&($curRow['authority']??'')==='adr_current'&&($curRow['status']??'')==='current'&&isset($curRow['revision'])&&(string)$curRow['revision']!=='','cur='.json_encode($curRow));

    // Second approved decision whose ADR is superseded -> historical.
    $sup=$decisionSvc->create($owner,['title'=>'Superseded '.$term.' rule','body'=>'supersede about '.$term,'requested_decision'=>'pick','decision_key'=>'MEM-'.bin2hex(random_bytes(3)),'priority'=>'normal','source'=>'harness','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED','conversation_id'=>$convA],$tenantId);
    $supersededId=(int)($sup['data']['decision_id']??0);$cleanup[]=['decisionId'=>$supersededId,'conv'=>$convA];
    $sptr=$decisionSvc->transition($owner,$supersededId,'CLOSED','approve superseded',['decision'=>'Superseded '.$term.' approach'],$tenantId);
    $supAdrId=(int)($sptr['data']['adr_id']??0);
    // Third decision (no term) provides a valid superseder ADR target (never the current first ADR).
    $td=$decisionSvc->create($owner,['title'=>'Superseder target','body'=>'unrelated body','requested_decision'=>'pick','decision_key'=>'MEM-'.bin2hex(random_bytes(3)),'priority'=>'normal','source'=>'harness','workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED','conversation_id'=>$convA],$tenantId);
    $thirdId=(int)($td['data']['decision_id']??0);$cleanup[]=['decisionId'=>$thirdId,'conv'=>$convA];
    $tdtr=$decisionSvc->transition($owner,$thirdId,'CLOSED','approve target',['decision'=>'target'],$tenantId);
    $thirdAdrId=(int)($tdtr['data']['adr_id']??0);
    if($supAdrId>0&&$thirdAdrId>0){$db->prepare('UPDATE harpp_adrs SET superseded_by=:by WHERE id=:id')->execute([':by'=>$thirdAdrId,':id'=>$supAdrId]);}
    $assert('second ADR marked superseded via superseded_by',$supAdrId>0&&$thirdAdrId>0,'supAdr='.$supAdrId.' thirdAdr='.$thirdAdrId);

    // Re-run default search: current first ADR present, superseded second ADR excluded.
    $res2=$memory->search($owner,['q'=>$term],$tenantId);
    $rows2=(array)($res2['data']['results']??[]);
    $ids2=array_map('intval',array_column($rows2,'decision_id'));
    $assert('superseded (historical) ADR excluded by default',in_array($approvedId,$ids2,true)&&!in_array($supersededId,$ids2,true),'ids2='.implode(',',$ids2));
    $curRow2=null;
    foreach ($rows2 as $r) { if (($r['matched_on']??'')==='adr'&&(int)($r['decision_id']??0)===$approvedId){$curRow2=$r;break;} }
    $assert('current ADR still adr_current after supersession',is_array($curRow2)&&($curRow2['status']??'')==='current'&&($curRow2['authority']??'')==='adr_current','cur2='.json_encode($curRow2));

    // Unknown artifact (run bundle, no decision linkage) carrying both the main term and a stale-only term.
    $staleTerm='zephyrstale';
    $db->prepare("INSERT INTO harpp_artifact_bundles (aggregate_type,aggregate_id,status,created_by,created_at) VALUES ('run',:aid,'ready',:user,NOW())")->execute([':aid'=>999999,':user'=>(int)$owner['id']]);
    $runBundleId=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO harpp_artifacts (bundle_id,artifact_type,filename,payload,created_by,created_at) VALUES (:bid,'file','stale.txt',:payload,:user,NOW())")->execute([':bid'=>$runBundleId,':payload'=>'unknown stale artifact '.$term.' '.$staleTerm,':user'=>(int)$owner['id']]);

    // Default search excludes unknown artifact hits.
    $assert('unknown artifact excluded by default',!in_array('unknown',array_column($rows2,'status'),true),'statuses='.json_encode(array_column($rows2,'status')));

    // include_historical=true returns historical tagged, ranked after current; unknown always last.
    $assert('superseded decision actually created',$supersededId>0,'supersededId='.$supersededId);
    $inc=$memory->search($owner,['q'=>$term,'limit'=>20,'include_historical'=>true],$tenantId);
    $incRows=(array)($inc['data']['results']??[]);
    $histPos=null;$curPos=null;
    foreach ($incRows as $i=>$r) {
        if (($r['matched_on']??'')==='adr' && (int)($r['decision_id']??0)===$supersededId) $histPos=$i;
        if (($r['matched_on']??'')==='adr' && (int)($r['decision_id']??0)===$approvedId) $curPos=$i;
    }
    $histRow=$histPos!==null?$incRows[$histPos]:null;
    $assert('include_historical returns historical tagged after current',$histPos!==null&&$curPos!==null&&$histPos>$curPos&&is_array($histRow)&&($histRow['status']??'')==='historical'&&($histRow['authority']??'')==='decision_current','histPos='.var_export($histPos,true).' curPos='.var_export($curPos,true).' hist='.json_encode($histRow));
    $unknownIdx=null;$lastCurrentIdx=-1;
    foreach ($incRows as $i=>$r) { if(($r['status']??'')==='unknown'){$unknownIdx=$i;} if(($r['status']??'')==='current'){$lastCurrentIdx=$i;} }
    $assert('include_historical ranks unknown last (never outranks current)',$unknownIdx!==null&&$unknownIdx>$lastCurrentIdx&&$unknownIdx===count($incRows)-1,'unknownIdx='.var_export($unknownIdx,true).' lastCurrent='.$lastCurrentIdx.' n='.count($incRows));

    // Stale-is-worse-than-none: query matching only unknown -> empty + low confidence.
    $stale=$memory->search($owner,['q'=>$staleTerm],$tenantId);
    $assert('stale-is-worse-than-none: only unknown -> empty + low',count((array)($stale['data']['results']??[]))===0&&($stale['data']['confidence']??'')==='low','stale='.json_encode($stale['data']??[]));

    // Shared token budget caps results and reports consumed.
    $bud=$memory->search($owner,['q'=>$term,'limit'=>20,'budget_limit'=>500],$tenantId);
    $budData=(array)$bud['data'];$budRows=(array)($budData['results']??[]);$budget=(array)($budData['budget']??[]);
    $assert('budget caps results and reports consumed',isset($budData['confidence'])&&(int)($budget['limit']??0)===500&&isset($budget['consumed'])&&(int)$budget['consumed']<=500&&count($budRows)<=20,'budget='.json_encode($budget).' n='.count($budRows));
    $assert('budget.consumed within limit',(int)($budget['consumed']??0)<=500,'consumed='.($budget['consumed']??'?'));
    $assert('budget query keeps high confidence',($budData['confidence']??'')==='high','conf='.($budData['confidence']??'?'));

    // integrate() never emits unknown/historical; approved decision item carries authority/status.
    $mem2=$memory->integrate($convA);
    $mem2Arr=$mem2===null?[]:$mem2;
    $bad=array_filter($mem2Arr,static fn($m)=>in_array(($m['status']??''),['unknown','historical'],true));
    $approvedMem=array_values(array_filter($mem2Arr,static fn($m)=>(int)($m['decision_id']??0)===$approvedId));
    $assert('integrate emits only current/authoritative (no unknown/historical)',$mem2!==null&&count($bad)===0,'bad='.json_encode(array_values($bad)));
    $assert('integrate emits approved decision item with authority/status',count($approvedMem)===1&&($approvedMem[0]['status']??'')==='current'&&($approvedMem[0]['authority']??'')==='adr_current'&&isset($approvedMem[0]['revision']),'mem2='.json_encode($mem2Arr));
    $mem2Ids=array_map('intval',array_column($mem2Arr,'decision_id'));
    $assert('integrate excludes superseded (historical) decision',!in_array($supersededId,$mem2Ids,true),'memIds='.implode(',',$mem2Ids));
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
    if($runBundleId>0){
        try { $db->prepare('DELETE FROM harpp_artifact_bundles WHERE id=:id')->execute([':id'=>$runBundleId]); } catch (\Throwable $e) { echo "\nCLEANUP ERR runBundle=$runBundleId: ".$e->getMessage()."\n"; }
    }
}

$h->done();
