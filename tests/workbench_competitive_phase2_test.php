<?php

declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/Contracts/WorkbenchTestContract.php';
require_once __DIR__ . '/../kernel/Workbench/Contracts/WorkbenchTestContractMigrator.php';
require_once __DIR__ . '/../kernel/Workbench/Contracts/WorkbenchTestContractValidator.php';
require_once __DIR__ . '/../kernel/Workbench/Contracts/WorkbenchContractService.php';

use Ikabud\Kernel\Workbench\Contracts\WorkbenchContractService;
use Ikabud\Kernel\Workbench\Contracts\WorkbenchTestContract;
use Ikabud\Kernel\Workbench\Contracts\WorkbenchTestContractMigrator;
use Ikabud\Kernel\Workbench\Contracts\WorkbenchTestContractValidator;

$h = new TestHarness('workbench-competitive-phase2');
$root = dirname(__DIR__);
$module = $root . '/modules/project-audit-ledger';
$migrator = new WorkbenchTestContractMigrator();
$first = $migrator->migrate($module);
$second = $migrator->migrate($module);
$h->assertSame(
    WorkbenchTestContract::SCHEMA,
    $first['schema'],
    'uses v1 schema identity'
);
$h->assertSame(
    WorkbenchTestContract::encode($first),
    WorkbenchTestContract::encode($second),
    'legacy migration is deterministic'
);
$h->test(
    'contract covers generic module semantics',
    isset(
        $first['ownership'],
        $first['actors'],
        $first['tenancy'],
        $first['evidence'],
        $first['gates']
    )
);
$h->test(
    'censored evidence is preserved',
    in_array('censored', $first['evidence']['outcomes'], true)
);

$invalid = $first;
$invalid['module']['id'] = 'another-module';
$report = (new WorkbenchTestContractValidator())->validate($invalid, $module, $root);
$h->test('invalid identity fails before browser execution', !$report['ok']);
$h->assertSame(
    'incompatible' === $report['compatibility']['status'] ? 'incompatible' : 'compatible',
    $report['compatibility']['status'],
    'compatibility report is machine-readable'
);

$fixtureRoot = sys_get_temp_dir() . '/ark-workbench-contract-' . bin2hex(random_bytes(4));
mkdir($fixtureRoot . '/modules/inventory', 0777, true);
file_put_contents(
    $fixtureRoot . '/modules/inventory/module.json',
    json_encode([
        'id' => 'inventory',
        'version' => '2.0.0',
        'application_profile' => ['id' => 'ark.workbench'],
        'owns_tables' => ['items'],
        'capabilities' => ['exposes' => [['id' => 'inventory.read@1']]],
    ], JSON_PRETTY_PRINT)
);
file_put_contents(
    $fixtureRoot . '/modules/inventory/routes.php',
    "<?php return ['GET'=>['/inventory'=>'inventory:index'],'POST'=>[]];"
);
$generic = (new WorkbenchTestContractMigrator())->migrate(
    $fixtureRoot . '/modules/inventory'
);
$h->assertSame('inventory', $generic['module']['id'], 'migration has no PAL assumptions');
$h->test(
    'generic routes are discovered',
    in_array('/inventory', $generic['ownership']['routes']['GET'], true)
);

$service = new WorkbenchContractService($fixtureRoot);
$service->initialize('inventory');
$contractPath = $fixtureRoot . '/modules/inventory/workbench-contract.json';
$h->test('init writes canonical contract', is_file($contractPath));
$doctor = $service->doctor('inventory');
$h->test(
    'doctor permits valid contract',
    $doctor['ok'] && $doctor['browser_execution_allowed']
);

$contract = json_decode((string) file_get_contents($contractPath), true);
$contract['ownership']['routes']['GET'][] = '/inventory/missing';
file_put_contents($contractPath, WorkbenchTestContract::encode($contract));
$run = $service->run('inventory');
$h->test('invalid contract blocks browser startup', !$run['browser_started']);
$h->assertSame('blocked', $run['outcome'], 'blocked run has explicit outcome');
$explanation = $service->explain($run['run_id']);
$h->test('blocked run remains explainable', $explanation['causes'] !== []);

$contract['ownership']['routes']['GET'] = ['/inventory'];
file_put_contents($contractPath, WorkbenchTestContract::encode($contract));
$executed = $service->run('inventory');
$h->assertSame('passed', $executed['outcome'], 'valid run executes its declared test plan');

mkdir($fixtureRoot . '/bin', 0777, true);
mkdir($fixtureRoot . '/tests/browser', 0777, true);
file_put_contents(
    $fixtureRoot . '/tests/browser/inventory-showcase.spec.js',
    '// contract fixture'
);
$fakeNpx = $fixtureRoot . '/bin/npx';
file_put_contents(
    $fakeNpx,
    <<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'run_id' => getenv('WB_RUN_ID'),
    'module' => getenv('ARK_MODULE'),
    'legacy_module' => getenv('MODULE'),
    'gate' => getenv('HYBRID_GATE'),
]);
$sleep = (int) (getenv('FAKE_NPX_SLEEP') ?: 0);
if ($sleep > 0) {
    sleep($sleep);
}
PHP
);
chmod($fakeNpx, 0755);

$originalPath = (string) getenv('PATH');
putenv('PATH=' . $fixtureRoot . '/bin:' . $originalPath);
putenv('FAKE_NPX_SLEEP=0');
$contract['test_files']['browser'] = ['tests/browser/inventory-showcase.spec.js'];
$contract['environments']['timeout_seconds'] = ['php' => 5, 'browser' => 2];
file_put_contents($contractPath, WorkbenchTestContract::encode($contract));
$browserRun = $service->run('inventory', 'major');
$browserExecution = $browserRun['executions'][0];
$h->test('valid browser plan starts', $browserRun['browser_started']);
$h->assertSame(
    $browserRun['run_id'],
    $browserExecution['run_id'],
    'canonical run ID propagates to browser execution'
);
$h->assertSame(
    'inventory',
    $browserExecution['module'],
    'canonical module identity propagates to browser execution'
);
$h->assertSame(
    'major',
    $browserExecution['gate'],
    'hybrid gate propagates to browser execution'
);
$h->test(
    'browser process receives both canonical and compatibility module variables',
    str_contains($browserExecution['summary'], '"module":"inventory"')
        && str_contains($browserExecution['summary'], '"legacy_module":"inventory"')
);

putenv('FAKE_NPX_SLEEP=3');
$contract['environments']['timeout_seconds']['browser'] = 1;
file_put_contents($contractPath, WorkbenchTestContract::encode($contract));
$timeoutRun = $service->run('inventory');
$timeoutExecution = $timeoutRun['executions'][0];
$h->assertSame('failed', $timeoutRun['outcome'], 'timed-out browser run fails its gate');
$h->test('browser timeout is explicit', $timeoutExecution['timed_out']);
$h->assertSame(124, $timeoutExecution['exit_code'], 'timeout uses the standard exit code');
$h->test(
    'timeout kills the process without waiting for natural completion',
    $timeoutExecution['duration_ms'] < 2500
);
$timeoutExplanation = $service->explain($timeoutRun['run_id']);
$h->assertSame(
    'execution-timeout',
    $timeoutExplanation['causes'][0]['code'],
    'timeout remains explainable from durable run evidence'
);

putenv('PATH=' . $originalPath);
putenv('FAKE_NPX_SLEEP');

$h->done();
