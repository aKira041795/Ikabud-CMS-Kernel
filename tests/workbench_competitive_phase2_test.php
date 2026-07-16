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
$h->assertSame(WorkbenchTestContract::SCHEMA, $first['schema'], 'uses v1 schema identity');
$h->assertSame(WorkbenchTestContract::encode($first), WorkbenchTestContract::encode($second), 'legacy migration is deterministic');
$h->test('contract covers generic module semantics', isset($first['ownership'], $first['actors'], $first['tenancy'], $first['evidence'], $first['gates']));
$h->test('censored evidence is preserved', in_array('censored', $first['evidence']['outcomes'], true));

$invalid = $first;
$invalid['module']['id'] = 'another-module';
$report = (new WorkbenchTestContractValidator())->validate($invalid, $module, $root);
$h->test('invalid identity fails before browser execution', !$report['ok']);
$h->assertSame('incompatible' === $report['compatibility']['status'] ? 'incompatible' : 'compatible', $report['compatibility']['status'], 'compatibility report is machine-readable');

$fixtureRoot = sys_get_temp_dir() . '/ark-workbench-contract-' . bin2hex(random_bytes(4));
mkdir($fixtureRoot . '/modules/inventory', 0777, true);
file_put_contents($fixtureRoot . '/modules/inventory/module.json', json_encode(['id' => 'inventory', 'version' => '2.0.0', 'application_profile' => ['id' => 'ark.workbench'], 'owns_tables' => ['items'], 'capabilities' => ['exposes' => [['id' => 'inventory.read@1']]]], JSON_PRETTY_PRINT));
file_put_contents($fixtureRoot . '/modules/inventory/routes.php', "<?php return ['GET'=>['/inventory'=>'inventory:index'],'POST'=>[]];");
$generic = (new WorkbenchTestContractMigrator())->migrate($fixtureRoot . '/modules/inventory');
$h->assertSame('inventory', $generic['module']['id'], 'migration has no PAL assumptions');
$h->test('generic routes are discovered', in_array('/inventory', $generic['ownership']['routes']['GET'], true));

$service = new WorkbenchContractService($fixtureRoot);
$service->initialize('inventory');
$h->test('init writes canonical contract', is_file($fixtureRoot . '/modules/inventory/workbench-contract.json'));
$doctor = $service->doctor('inventory');
$h->test('doctor permits valid contract', $doctor['ok'] && $doctor['browser_execution_allowed']);
$broken = json_decode((string) file_get_contents($fixtureRoot . '/modules/inventory/workbench-contract.json'), true);
$broken['ownership']['routes']['GET'][] = '/inventory/missing';
file_put_contents($fixtureRoot . '/modules/inventory/workbench-contract.json', WorkbenchTestContract::encode($broken));
$run = $service->run('inventory');
$h->test('invalid contract blocks browser startup', !$run['browser_started']);
$h->assertSame('blocked', $run['outcome'], 'blocked run has explicit outcome');
$explanation = $service->explain($run['run_id']);
$h->test('blocked run remains explainable', $explanation['causes'] !== []);

$broken['ownership']['routes']['GET'] = ['/inventory'];
file_put_contents($fixtureRoot . '/modules/inventory/workbench-contract.json', WorkbenchTestContract::encode($broken));
$executed = $service->run('inventory');
$h->assertSame('passed', $executed['outcome'], 'valid run executes its declared test plan');

$h->done();
