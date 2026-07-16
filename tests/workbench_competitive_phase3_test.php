<?php

declare(strict_types=1);

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
$h->done();
