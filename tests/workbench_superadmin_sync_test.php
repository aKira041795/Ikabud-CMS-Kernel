<?php
declare(strict_types=1);

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../src/http/superadmin-handlers.php';

$h = new TestHarness('workbench-superadmin-sync');
$root = dirname(__DIR__);

$h->section('Canonical ARK run assembly');
$runs = workbenchHybridRuns($root);
$h->test('run-scoped ARK artifacts are discovered', $runs !== []);
$correlated = array_filter($runs, static fn(array $run): bool => count($run['artifacts'] ?? []) >= 2);
$h->test('artifacts from multiple engines correlate by run id', $correlated !== []);
$latest = $correlated !== [] ? reset($correlated) : [];
$h->test('assembled run exposes module and unified status',
    isset($latest['module'], $latest['status'], $latest['summary'])
    && in_array($latest['status'], ['passed', 'failed'], true));
$h->test('invalid run ids cannot address artifacts', workbenchHybridRunDetail($root, '../secret') === null);
$h->test('observed route matching supports dynamic placeholders without estimates',
    workbenchObservedClaimedRoutes(
        ['routes_claimed' => ['GET' => ['/admin/example/{id}', '/admin/missing']]],
        ['pages' => [['url' => '/admin/example/42?inspect=1']]]
    ) === 1);

$h->section('Superadmin consumer contracts');
$handler = (string)file_get_contents($root . '/src/http/superadmin-handlers.php');
$template = (string)file_get_contents($root . '/templates/pages/superadmin-workbench.disyl');
$reporter = (string)file_get_contents($root . '/tests/browser/WorkbenchReporter.js');
$h->test('reporter manifest preserves timeout and interruption gates',
    str_contains($reporter, 'timed_out: t') && str_contains($reporter, 'interrupted: x'));
$h->test('module release certification is exposed',
    str_contains($handler, "validateModuleCertification(\$manifest)") && str_contains($template, 'Release Gate'));
$h->test('process map resolves through provider registry',
    str_contains($handler, 'ComprehensionProviderRegistry') && str_contains($template, 'wb-map-module'));
$h->test('issue filters retain API data outside async local scope', str_contains($template, 'window._wbIssueData'));
$h->test('coverage declares observed evidence mode', str_contains($handler, "'evidence_mode' => 'observed'"));
$h->test('Superadmin view loads published Workbench assets without raw Tailwind directives',
    str_contains($template, '/assets/workbench/workbench.css')
    && str_contains($template, '/assets/workbench/workbench-core.js')
    && !str_contains($template, '@apply'));
$h->test('module-scoped ARK Hybrid execution uses the guarded launcher',
    str_contains($handler, "\$target === 'ark-hybrid'")
    && str_contains($handler, '/tests/browser/run-workbench.js')
    && str_contains($template, 'wbRunArkHybrid'));

$h->done();
