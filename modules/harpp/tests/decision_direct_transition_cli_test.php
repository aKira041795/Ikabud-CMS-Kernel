<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$logs = [$root.'/storage/logs/app.log', $root.'/storage/logs/error.log'];
foreach ($logs as $log) { if (is_file($log)) file_put_contents($log, ''); }
require $root.'/bootstrap.php';
$tenantId=(int)($_SERVER['argv'][1]??1);
app()->tenant()->setTenantId($tenantId);
require_once dirname(__DIR__).'/helpers.php';
require_once $root . '/tests/harness/TestHarness.php';

use Harpp\Services\HarppDecisionService;

$manifest=json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=app()->dbForTenant($tenantId);
$db=new \Ikabud\Kernel\Contracts\ModuleDB($pdo,'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$owner['id']=(int)$owner['id'];$owner['source']='harpp';

$h = new TestHarness('harpp-decision-direct-transition');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$svc = new HarppDecisionService($db);
$cleanup = [];
try {
    $make = static function(string $key, string $title) use ($svc, $owner, &$cleanup, $tenantId): int {
        $c = $svc->create($owner, ['title'=>$title,'body'=>'body','requested_decision'=>'pick',
            'decision_key'=>$key,'priority'=>'normal','source'=>'harness',
            'workbench_state'=>'ARCHITECTURE_DECISION_REQUIRED'], $tenantId);
        $id=(int)($c['data']['decision_id']??0); $conv=(int)($c['data']['conversation_id']??0);
        if($id>0)$cleanup[]=[$id,$conv];
        return $id;
    };

    // PENDING -> DECIDED directly (the friction case).
    $d1=$make('DT-DECIDE-'.bin2hex(random_bytes(3)),'Direct decide');
    $dec=$svc->transition($owner,$d1,'DECIDED','Owner decided directly from PENDING.',['decision'=>'Proceed with option B'],$tenantId);
    $st1=$db->prepare('SELECT lifecycle_state,decision FROM harpp_decisions WHERE id=:id');$st1->execute([':id'=>$d1]);
    $row1=$st1->fetch(PDO::FETCH_ASSOC);
    $assert('PENDING -> DECIDED directly succeeds',!empty($dec['ok'])&&($dec['data']['state']??'')==='DECIDED'&&($row1['lifecycle_state']??'')==='DECIDED'&&($row1['decision']??'')==='Proceed with option B','dec='.json_encode($dec));
    $adr1=$db->prepare('SELECT id FROM harpp_adrs WHERE decision_ref=:id');$adr1->execute([':id'=>$d1]);
    $assert('direct DECIDED mints an ADR',(int)$adr1->fetchColumn()>0);

    // PENDING -> CLOSED directly (the other friction case).
    $d2=$make('DT-CLOSE-'.bin2hex(random_bytes(3)),'Direct close');
    $cl=$svc->transition($owner,$d2,'CLOSED','Owner closed directly from PENDING.',[],$tenantId);
    $st2=$db->prepare('SELECT lifecycle_state FROM harpp_decisions WHERE id=:id');$st2->execute([':id'=>$d2]);
    $assert('PENDING -> CLOSED directly succeeds',!empty($cl['ok'])&&($cl['data']['state']??'')==='CLOSED'&&($st2->fetchColumn()?:'')==='CLOSED','cl='.json_encode($cl));

    // PENDING -> DECIDED -> CLOSED combined (owner picks Decide then Close).
    $d3=$make('DT-BOTH-'.bin2hex(random_bytes(3)),'Decide then close');
    $r3a=$svc->transition($owner,$d3,'DECIDED','Decided.',['decision'=>'Option A'],$tenantId);
    $r3b=$svc->transition($owner,$d3,'CLOSED','Closed after deciding.',[],$tenantId);
    $st3=$db->prepare('SELECT lifecycle_state FROM harpp_decisions WHERE id=:id');$st3->execute([':id'=>$d3]);
    $assert('PENDING -> DECIDED -> CLOSED works',!empty($r3a['ok'])&&!empty($r3b['ok'])&&($st3->fetchColumn()?:'')==='CLOSED');

    // Regression: the strict one-click apply-and-close still rejects PENDING.
    $d4=$make('DT-STRICT-'.bin2hex(random_bytes(3)),'Strict reject');
    $strict=$svc->applyAndClose($owner,$d4,'a','c',[],$tenantId);
    $assert('strict apply-and-close still rejects PENDING (illegal_transition)',empty($strict['ok'])&&($strict['code']??'')==='illegal_transition','strict='.json_encode($strict));
} finally {
    foreach ($cleanup as [$decisionId,$conversationId]) {
        if($decisionId>0){
            $db->prepare('DELETE FROM harpp_adrs WHERE decision_ref=:id')->execute([':id'=>$decisionId]);
            $db->prepare('DELETE FROM harpp_notifications WHERE decision_id=:id OR conversation_id=:c')->execute([':id'=>$decisionId,':c'=>$conversationId]);
            $db->prepare('DELETE FROM harpp_decisions WHERE id=:id')->execute([':id'=>$decisionId]);
        }
        if($conversationId>0)$db->prepare('DELETE FROM harpp_conversations WHERE id=:id')->execute([':id'=>$conversationId]);
    }
}

$h->done();
