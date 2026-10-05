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
$mbKey=$runnerKey.'-mb';

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

    // Root cause 2026-10-05: the daemon report was rejected 422 on every cycle because the
    // validator measured BYTES (strlen) while the column stores CHARACTERS (varchar(255)).
    // A real workflow title of 255 characters / 257 bytes starved the Status page silently.
    $mbTitle='w'.str_repeat('a',249).str_repeat('—',5); // 255 characters, 265 bytes
    $mbReport=$service->reportDaemonStatus($bridge,[
        'runner_key'=>$mbKey,'daemon_version'=>'2.4.0-test',
        'workflow_counts'=>['done'=>7],
        'recent_workflows'=>[['id'=>'wf-mb','title'=>$mbTitle,'status'=>'done','updated_at'=>date(DATE_ATOM)]],
    ],$tenantId);
    $assert('a 255-character multibyte title is accepted',!empty($mbReport['ok']),'result='.json_encode($mbReport));

    $afterMb=(array)($service->overview($owner,$tenantId)['data']??[]);
    $assert('the daemon report actually reaches the status page',
        (($afterMb['daemon']['runner_key']??'')===$mbKey)&&(($afterMb['daemon']['online']??false)===true),
        'daemon='.json_encode($afterMb['daemon']??null));

    $overlong=$service->reportDaemonStatus($bridge,[
        'runner_key'=>$mbKey,'workflow_counts'=>[],
        'recent_workflows'=>[['id'=>'wf-big','title'=>str_repeat('a',300),'status'=>'done','updated_at'=>date(DATE_ATOM)]],
    ],$tenantId);
    $assert('a genuinely oversized title is still rejected',empty($overlong['ok'])&&(int)($overlong['status']??0)===422,'result='.json_encode($overlong));
} finally {
    $db->prepare('DELETE FROM harpp_daemon_status WHERE runner_key IN (:key,:mb)')->execute([':key'=>$runnerKey,':mb'=>$mbKey]);
}

$h->done();