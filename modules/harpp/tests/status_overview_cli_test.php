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

use Harpp\Services\HarppStatusService;

$manifest=json_decode((string)file_get_contents(dirname(__DIR__).'/module.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=app()->dbForTenant($tenantId);
$pdo->exec((string)file_get_contents(dirname(__DIR__).'/database/migrations/018_harpp_daemon_status.sql'));
$db=new \Ikabud\Kernel\Contracts\ModuleDB($pdo,'harpp',(array)$manifest['owns_tables'],(array)$manifest['reads_tables']);
$owner=$db->query("SELECT id,email,full_name,role FROM harpp_users WHERE role='owner' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!is_array($owner))throw new RuntimeException('HARPP owner missing.');
$owner['id']=(int)$owner['id'];$owner['source']='harpp';
$bridge=$owner;$bridge['source']='harpp_bridge';
$runnerKey='status-test-'.getmypid();

$h = new TestHarness('harpp-status-overview');
$assert = static function(string $name, bool $ok, string $detail = '') use ($h): void { $h->test($name, $ok, $detail); };
try {
    $service=new HarppStatusService($db);
    $reported=$service->reportDaemonStatus($bridge,['runner_key'=>$runnerKey,'daemon_version'=>'2.4.0-test','workflow_counts'=>['done'=>7,'blocked'=>2,'failed'=>1],'recent_workflows'=>[['id'=>'wf-1','title'=>'Status overview','status'=>'done','updated_at'=>date(DATE_ATOM)]]],$tenantId);
    $assert('fresh bridge actor reports daemon status',!empty($reported['ok'])&&($reported['data']['runner_key']??'')===$runnerKey,'reported='.json_encode($reported));
    $invalidKey=$service->reportDaemonStatus($bridge,['runner_key'=>'!','workflow_counts'=>[],'recent_workflows'=>[]],$tenantId);
    $assert('invalid runner key is rejected',empty($invalidKey['ok'])&&(int)($invalidKey['status']??0)===422,'result='.json_encode($invalidKey));
    $invalidCounts=$service->reportDaemonStatus($bridge,['runner_key'=>$runnerKey,'workflow_counts'=>'done','recent_workflows'=>[]],$tenantId);
    $assert('non-array workflow counts are rejected',empty($invalidCounts['ok'])&&(int)($invalidCounts['status']??0)===422,'result='.json_encode($invalidCounts));

    $overview=$service->overview($owner,$tenantId);
    $data=(array)($overview['data']??[]);
    $assert('owner can load status overview',!empty($overview['ok']),'overview='.json_encode($overview));
    $assert('overview includes runner fleet and queue',is_array($data['runners']??null)&&is_int($data['run_queue']['total']??null)&&$data['run_queue']['total']>=0,'data='.json_encode($data));
    $assert('fresh daemon report is online',is_array($data['daemon']??null)&&($data['daemon']['online']??false)===true,'daemon='.json_encode($data['daemon']??null));
    $assert('overview includes recent decisions and runs',is_array($data['recent_decisions']??null)&&is_array($data['recent_runs']??null),'data='.json_encode($data));
} finally {
    $db->prepare('DELETE FROM harpp_daemon_status WHERE runner_key=:key')->execute([':key'=>$runnerKey]);
}

$h->done();