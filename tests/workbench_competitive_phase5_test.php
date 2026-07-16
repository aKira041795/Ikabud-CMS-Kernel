<?php
declare(strict_types=1);
require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/Visual/ComponentScenarioGovernance.php';
use Ikabud\Kernel\Workbench\Visual\ComponentScenarioGovernance;

$h = new TestHarness('workbench-competitive-phase5');
$source = json_decode((string) file_get_contents(__DIR__ . '/../storage/application-profiles/ark-workbench/scenarios/component-catalog.v1.json'), true);
$governance = new ComponentScenarioGovernance();
$report = $governance->validateCatalog($source);
$h->test('every governed component has required scenarios', $report['ok']);
$h->assertSame(15, $report['governed_components'], 'all Workbench primitives are governed');
$same = $governance->compare('responsive-table', 'populated', str_repeat('a', 64), str_repeat('a', 64));
$h->test('unchanged accessible baseline passes', $same['release_allowed']);
$changed = $governance->compare('responsive-table', 'dense-mobile', str_repeat('a', 64), str_repeat('b', 64));
$h->test('visual change requires approval', $changed['approval_required'] && !$changed['release_allowed']);
$approval = $governance->approve($changed, 'release-owner', 'intentional density correction');
$h->assertSame('ark.visual-baseline-approval.v1', $approval['schema'], 'approval is a durable artifact');
$a11y = $governance->compare('dialog', 'error', str_repeat('c', 64), str_repeat('c', 64), [['impact' => 'critical', 'id' => 'aria-dialog-name']]);
$h->test('critical accessibility regression blocks release', !$a11y['release_allowed']);
$affected = $governance->affectedModules('responsive-table', [['module' => ['id' => 'guidance'], 'pages' => [['required_components' => ['responsive-table']]]], ['module' => ['id' => 'wms'], 'pages' => [['required_components' => ['dialog']]]]]);
$h->assertSame(['guidance'], $affected, 'component changes identify affected modules');
$h->done();
