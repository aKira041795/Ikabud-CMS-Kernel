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

use Harpp\Services\HarppRunService;

$manifest=json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=app()->dbForTenant($tenantId);
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/013_harpp_runner_work_queue.sql'));
$db=new \Ikabud\Kernel\Contracts\ModuleDB($pdo,'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$owner['id']=(int)$owner['id'];$owner['source']='harpp';
$bridgeActor=$owner;$bridgeActor['source']='harpp_bridge';
$member=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='member' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($member))throw new RuntimeException('HARPP member missing.');
$member['id']=(int)$member['id'];$member['source']='harpp';

$keys=['runner-fleet-a','runner-fleet-b'];
$db->prepare("DELETE FROM harpp_runners WHERE runner_key IN ('runner-fleet-a','runner-fleet-b')")->execute();

$h = new TestHarness('harpp-runner-fleet');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
$svc = new HarppRunService($db);
try {
    $registeredA=$svc->registerRunner($bridgeActor,['runner_key'=>'runner-fleet-a','display_name'=>'Fleet A','capabilities'=>['desktop','shell']]);
    $registeredB=$svc->registerRunner($bridgeActor,['runner_key'=>'runner-fleet-b','display_name'=>'Fleet B','capabilities'=>['wake-on-lan']]);
    $assert('registerRunner registers both runners',!empty($registeredA['ok'])&&!empty($registeredB['ok']));

    // Mark B stale via SQL (fresh A remains online).
    $db->prepare("UPDATE harpp_runners SET last_heartbeat_at=DATE_SUB(NOW(6),INTERVAL 5 MINUTE) WHERE runner_key='runner-fleet-b'")->execute();

    $list=$svc->listRunnersForOwner($owner,$tenantId);
    $runners=(array)($list['data']['runners']??[]);
    $assert('owner lists both runners',!empty($list['ok'])&&count($runners)===2,'runners='.json_encode($runners));
    $byKey=[];foreach($runners as $r)$byKey[$r['runner_key']]=$r;
    $assert('fresh runner stays online',($byKey['runner-fleet-a']['status']??'')==='online');
    $assert('stale runner marked offline',($byKey['runner-fleet-b']['status']??'')==='offline');
    $assert('capabilities returned decoded',is_array($byKey['runner-fleet-a']['capabilities']??null)&&in_array('desktop',(array)$byKey['runner-fleet-a']['capabilities'],true)&&in_array('wake-on-lan',(array)$byKey['runner-fleet-b']['capabilities'],true));
    $assert('timestamps present',($byKey['runner-fleet-a']['last_heartbeat_at']??'')!==''&&($byKey['runner-fleet-a']['created_at']??'')!=='');

    $denied=$svc->listRunnersForOwner($member,$tenantId);
    $assert('non-owner role is denied',empty($denied['ok'])&&($denied['status']??0)===403);
    $anon=$svc->listRunnersForOwner([],$tenantId);
    $assert('anonymous is denied',empty($anon['ok'])&&($anon['status']??0)===403);
} finally {
    $db->prepare("DELETE FROM harpp_runners WHERE runner_key IN ('runner-fleet-a','runner-fleet-b')")->execute();
}

$h->done();
