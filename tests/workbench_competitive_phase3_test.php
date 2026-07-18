<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';


require_once __DIR__ . '/harness/TestHarness.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/EntityContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/WorkflowContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/ActionContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/EffectContract.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/SupportContracts.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/Contracts/ModuleComprehensionProvider.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/ContractComprehensionProvider.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/PalComprehensionProvider.php';

require_once __DIR__ . '/../kernel/Workbench/Comprehension/ComprehensionProviderRegistry.php';

use Ikabud\Kernel\Workbench\Comprehension\ComprehensionProviderRegistry;

$h = new TestHarness('workbench-competitive-phase3');
$root = dirname(__DIR__);
$registry = new ComprehensionProviderRegistry($root);
foreach (['project-audit-ledger', 'guidance', 'wms', 'ehr'] as $module) {
    $h->test("{$module} provider is discoverable", $registry->has($module));
    $provider = $registry->resolve($module);
    $h->test("{$module} declares routes", $module === 'project-audit-ledger' || count($provider->routes()) > 0);
    $h->test("{$module} declares invariants", count($provider->invariants()) > 0);
    $h->test("{$module} declares scenarios", count($provider->testScenarios()) > 0);
}
$modules = $registry->modules();
$h->test('convention providers appear in registry inventory', count(array_intersect(['guidance', 'wms', 'ehr'], $modules)) === 3);
$wms = $registry->resolve('wms');
$h->test('WMS exposes action/effect chains', count($wms->actions()) > 20 && count($wms->expectedEffects()) === count($wms->actions()));
$ehr = $registry->resolve('ehr');
$ehrInvariantText = strtolower(implode(' ', array_map(static fn($item): string => $item->description, $ehr->invariants())));
$h->test('EHR authority invariant is declared', str_contains($ehrInvariantText, 'capability'));
$h->test('EHR privacy invariant is declared', str_contains($ehrInvariantText, 'another tenant'));
$guidance = $registry->resolve('guidance');
$guidanceScenarioIds = array_map(static fn($scenario): string => $scenario->id, $guidance->testScenarios());
$guidanceContract = json_decode((string) file_get_contents($root . '/modules/guidance/workbench-contract.json'), true, flags: JSON_THROW_ON_ERROR);
$h->test('Guidance declares an independent showcase scenario',
    in_array('guidance-independent-showcase', $guidanceScenarioIds, true));
$h->test('Guidance showcase is a real contract-owned browser target',
    in_array('tests/browser/modules/guidance/showcase.spec.js', $guidanceContract['test_files']['browser'] ?? [], true)
    && is_file($root . '/tests/browser/modules/guidance/showcase.spec.js'));
$h->test('Guidance declares governed browser timeout and correlation evidence',
    ($guidanceContract['environments']['timeout_seconds']['browser'] ?? 0) === 900
    && in_array('run-correlation', $guidanceContract['scenarios'][1]['required_evidence'] ?? [], true));
$guidanceAdapter = (string) file_get_contents($root . '/tests/browser/GuidanceAdapter.js');
$guidanceShowcase = (string) file_get_contents($root . '/tests/browser/modules/guidance/showcase.spec.js');
$h->test('Guidance browser adapter defaults to the shared Kernel host',
    str_contains($guidanceAdapter, "process.env.APP_URL || 'http://palsystem.test'"));
$h->test('Guidance browser proof traverses all scenario pages, not only the manifest root',
    str_contains($guidanceShowcase, 'showcaseScenario.pages')
    && str_contains($guidanceShowcase, 'showcasePages.length'));
$h->done();
