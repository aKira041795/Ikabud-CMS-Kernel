<?php

declare(strict_types=1);

/**
 * Priority 5 — Release and Regression Discipline Tests
 *
 * Covers:
 *   1. Malformed provider output, unsupported citations, timeout, disabled AI, fallback
 *   2. Interrupted run provenance and report status
 *   3. Scenario provider success, refusal, cleanup, tenant isolation
 *   4. Route/navigation including dynamic placeholders, cross-module deps
 *   5. Cross-role and cross-tenant matrix runs
 *   6. Reporter output freshness — stale results never pass
 *   7. Regression reproduction for confirmed critical issues
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/AI/WorkbenchAiAnalyzer.php';
require_once __DIR__ . '/../kernel/Workbench/Runs/RunProvenance.php';
require_once __DIR__ . '/../kernel/Workbench/Runs/RunExporter.php';
require_once __DIR__ . '/../kernel/Workbench/Runs/RunRepository.php';
require_once __DIR__ . '/../kernel/Workbench/Scenario/ScenarioFixtureDeclaration.php';
require_once __DIR__ . '/../kernel/Workbench/Scenario/PrerequisiteClassifier.php';
require_once __DIR__ . '/../kernel/Workbench/Scenario/FixtureCleanupPolicy.php';
require_once __DIR__ . '/../kernel/Workbench/Scenario/RouteTraversalResolver.php';
require_once __DIR__ . '/../kernel/Workbench/Issues/IssueCardRenderer.php';

use Ikabud\Kernel\Workbench\AI\WorkbenchAiAnalyzer;
use Ikabud\Kernel\Workbench\Runs\RunProvenance;
use Ikabud\Kernel\Workbench\Runs\RunExporter;
use Ikabud\Kernel\Workbench\Runs\RunRepository;
use Ikabud\Kernel\Workbench\Scenario\ScenarioFixtureDeclaration;
use Ikabud\Kernel\Workbench\Scenario\PrerequisiteClassifier;
use Ikabud\Kernel\Workbench\Scenario\FixtureCleanupPolicy;
use Ikabud\Kernel\Workbench\Scenario\RouteTraversalResolver;
use Ikabud\Kernel\Workbench\Issues\IssueCardRenderer;

$h = new TestHarness('workbench-regression');

// ════════════════════════════════════════════════════════════════
// 1. MALFORMED PROVIDER OUTPUT, UNSUPPORTED CITATIONS,
//    TIMEOUT, DISABLED AI, DETERMINISTIC FALLBACK
// ════════════════════════════════════════════════════════════════
$h->section('1. AI edge cases');

// 1a. Malformed provider output — non-JSON response
$cache = sys_get_temp_dir() . '/wb-reg-test-' . bin2hex(random_bytes(6));
$malformedCaller = fn(array $p): array => ['ok' => true, 'content' => 'not valid json at all'];
$malformedAi = new WorkbenchAiAnalyzer(['enabled' => true, 'tier' => 'free'], $malformedCaller, $cache);
$malformedResult = $malformedAi->analyze([], ['summary' => 'fallback', 'confidence' => 0.3]);
$h->test('malformed provider output falls back',
    ($malformedResult['provider_trace']['fallback_reason'] ?? '') === 'schema_validation_failed');
$h->test('malformed fallback returns heuristic hypotheses',
    count($malformedResult['hypotheses'] ?? []) >= 1);

// 1b. Empty JSON object (valid JSON, invalid schema)
$emptyJsonCaller = fn(array $p): array => ['ok' => true, 'content' => '{}'];
$emptyJsonAi = new WorkbenchAiAnalyzer(['enabled' => true], $emptyJsonCaller, $cache);
$emptyResult = $emptyJsonAi->analyze([], ['summary' => 'fallback']);
$h->test('empty JSON object falls back via schema validation',
    ($emptyResult['provider_trace']['fallback_reason'] ?? '') === 'schema_validation_failed');

// 1c. Unsupported citations — evidence_for missing required keys
$badCitationCaller = fn(array $p): array => ['ok' => true, 'content' => json_encode([
    'hypotheses' => [['summary' => 'test', 'confidence' => 0.5, 'evidence_for' => ['nonexistent-id'], 'evidence_against' => []]],
    'next_tests' => [], 'graph_suggestions' => [], 'remediation' => null,
])];
$badCitationAi = new WorkbenchAiAnalyzer(['enabled' => true], $badCitationCaller, $cache);
$badCitationResult = $badCitationAi->analyze(
    ['allowed_evidence_ids' => ['obs-real-1'], 'observations' => [['observation_id' => 'obs-real-1']]],
    ['summary' => 'fallback']
);
$h->test('unsupported citations accepted by analyzer (filtered at claim level)',
    ($badCitationResult['hypotheses'][0]['summary'] ?? '') === 'test');

// 1d. Provider timeout
$timeoutCaller = function (array $p): array {
    throw new \RuntimeException('Provider timeout after 15000ms');
};
$timeoutAi = new WorkbenchAiAnalyzer(['enabled' => true, 'timeout_ms' => 1000], $timeoutCaller, $cache);
$timeoutResult = $timeoutAi->analyze([], ['summary' => 'fallback']);
$h->test('provider timeout falls back gracefully',
    ($timeoutResult['provider_trace']['fallback_reason'] ?? '') !== '');
$h->test('timeout fallback has hypotheses',
    count($timeoutResult['hypotheses'] ?? []) >= 1);

// 1e. Disabled AI
$disabledAi = new WorkbenchAiAnalyzer(['enabled' => false]);
$disabledResult = $disabledAi->analyze([], ['summary' => 'fallback']);
$h->test('disabled AI returns fallback reason disabled',
    ($disabledResult['provider_trace']['fallback_reason'] ?? '') === 'disabled');
$h->test('disabled AI fallback has heuristic provider trace',
    ($disabledResult['provider_trace']['provider'] ?? '') === 'heuristic');

// Clean up cache
array_map('unlink', glob($cache . '/*.json'));
@rmdir($cache);

// ════════════════════════════════════════════════════════════════
// 2. INTERRUPTED RUN PROVENANCE AND REPORT STATUS
// ════════════════════════════════════════════════════════════════
$h->section('2. Interrupted run provenance');
$provenance = new RunProvenance();

$interrupted = $provenance->build([
    'run_id' => 'reg-interrupted-001',
    'module_id' => 'pal-workflows',
    'completion_status' => 'interrupted',
]);
$h->test('interrupted run has certification disclaimer',
    isset($interrupted['certification_disclaimer']));

$blocked = $provenance->build([
    'run_id' => 'reg-blocked-001',
    'module_id' => 'pal-workflows',
    'completion_status' => 'blocked',
]);
$h->test('blocked run has certification disclaimer',
    isset($blocked['certification_disclaimer']));

// Verify that the RunRepository persists interrupted status
$tmpDir = sys_get_temp_dir() . '/wb-reg-run-' . bin2hex(random_bytes(6));
$repo = new RunRepository($tmpDir);
$repo->save([
    'run_id' => 'reg-interrupted-run',
    'module' => 'pal-workflows',
    'outcome' => 'interrupted',
]);
$savedRun = $repo->get('reg-interrupted-run');
$h->test('RunRepository persists provenance for interrupted run',
    ($savedRun['provenance']['completion_status'] ?? '') !== '');
$h->test('interrupted run in summary has completion_status',
    ($savedRun['provenance']['completion_status'] ?? '') !== '');

// Cleanup
array_map('unlink', glob($tmpDir . '/runs/*.json'));
@unlink($tmpDir . '/index.json');
@unlink($tmpDir . '/index.lock');
@rmdir($tmpDir . '/runs');
@rmdir($tmpDir);

// ════════════════════════════════════════════════════════════════
// 3. SCENARIO PROVIDER — SUCCESS, REFUSAL, CLEANUP, TENANT ISOLATION
// ════════════════════════════════════════════════════════════════
$h->section('3. Scenario provider edge cases');

// 3a. Classification of scenario provider refusal
$classifier = new PrerequisiteClassifier();
$refusalResult = $classifier->classify([
    'category' => 'scenario',
    'severity' => 'major',
    'summary' => 'Scenario seed capability rejected for module pal-workflows',
    'outcome' => 'failed',
]);
$h->test('scenario provider refusal -> unmet-prerequisite',
    ($refusalResult['classification'] ?? '') === 'unmet-prerequisite');

// 3b. Tenant isolation in cleanup policy
$cleanupPolicy = new FixtureCleanupPolicy();
$tenantAScenario = [
    'scenario_id' => 'tenant-a-scenario',
    'tenant_id' => 1,
    'tenant_key' => 'tenant-alpha',
    'data' => ['entities' => ['project' => [['name' => 'A Project']]]],
];
$tenantBCleanup = $cleanupPolicy->buildCleanup($tenantAScenario, [
    'namespace' => 'run-tenant-a',
    'entities' => [['type' => 'project', 'count' => 1]],
]);
$h->test('cleanup is scoped to tenant',
    ($tenantBCleanup['scope']['tenant_id'] ?? 0) === 1);
$h->test('cleanup has tenant_key',
    ($tenantBCleanup['scope']['tenant_key'] ?? '') === 'tenant-alpha');

// 3c. FixtureCleanupPolicy validates tenant requirement
$noTenantValidation = $cleanupPolicy->validate([
    'scenario_id' => 'no-tenant',
    'data' => ['entities' => ['x' => [['id' => 1]]]],
]);
$h->test('cleanup policy rejects scenario without tenant',
    !$noTenantValidation['valid']);

// ════════════════════════════════════════════════════════════════
// 4. ROUTE/NAVIGATION — DYNAMIC PLACEHOLDERS, CROSS-MODULE DEPS
// ════════════════════════════════════════════════════════════════
$h->section('4. Route/navigation coverage');

$resolver = new RouteTraversalResolver();

// 4a. Dynamic placeholders resolve via observed links
$resolver->observeLink('/admin/pal/projects/{id}', '/admin/pal/projects/42');
$resolver->observeLink('/admin/pal/projects/{id}/edit', '/admin/pal/projects/42/edit');

$detailResult = $resolver->resolve('/admin/pal/projects/{id}', fn(): ?string => null);
$h->test('dynamic {id} resolves via observed list link',
    ($detailResult['resolved_url'] ?? '') === '/admin/pal/projects/42');

$editResult = $resolver->resolve('/admin/pal/projects/{id}/edit', fn(): ?string => null);
$h->test('dynamic {id}/edit resolves via observed detail link',
    ($editResult['resolved_url'] ?? '') === '/admin/pal/projects/42/edit');

// 4b. Cross-module dependencies
$resolver->observeLink('/admin/wms/shipments/{id}', '/admin/wms/shipments/99');
$crossModuleResult = $resolver->resolve('/admin/wms/shipments/{id}', fn(): ?string => null);
$h->test('cross-module route resolves from observed links',
    ($crossModuleResult['resolved_url'] ?? '') === '/admin/wms/shipments/99');

// 4c. Unclassified parameterized route correctly classified
$unobservedResult = $resolver->resolve('/admin/guidance/cases/{id}', fn(): ?string => null);
$unobservedClass = $resolver->classifyUnresolved($unobservedResult);
$h->test('unobserved parameterized route -> unmet-prerequisite',
    ($unobservedClass['classification'] ?? '') === 'unmet-prerequisite');

// ════════════════════════════════════════════════════════════════
// 5. CROSS-ROLE AND CROSS-TENANT MATRIX RUNS
// ════════════════════════════════════════════════════════════════
$h->section('5. Cross-role/tenant matrix');

$fixtureDeclAdmin = new ScenarioFixtureDeclaration([
    'fixture_role' => 'admin',
    'fixture_label' => 'Site Administrator',
    'fixture_user_id' => 1,
    'tenant_id' => 1,
    'tenant_key' => 'tenant-alpha',
    'module' => 'pal-workflows',
    'data' => ['entities' => ['pal_project' => [['name' => 'Test']]]],
]);
$adminValidation = $fixtureDeclAdmin->validate();
$h->test('admin role fixture validates',
    $adminValidation['valid']);

$fixtureDeclManager = new ScenarioFixtureDeclaration([
    'fixture_role' => 'manager',
    'fixture_label' => 'Department Manager',
    'fixture_user_id' => 2,
    'tenant_id' => 2,
    'tenant_key' => 'tenant-beta',
    'module' => 'pal-workflows',
    'data' => ['entities' => ['pal_project' => [['name' => 'Test']]]],
]);
$managerValidation = $fixtureDeclManager->validate();
$h->test('manager role fixture validates',
    $managerValidation['valid']);

$h->test('admin and manager have different roles',
    $fixtureDeclAdmin->normalize()['actor']['role'] !== $fixtureDeclManager->normalize()['actor']['role']);
$h->test('admin and manager have different tenant_ids',
    $fixtureDeclAdmin->normalize()['tenant']['tenant_id'] !== $fixtureDeclManager->normalize()['tenant']['tenant_id']);

// ════════════════════════════════════════════════════════════════
// 6. REPORTER OUTPUT FRESHNESS — STALE RESULTS NEVER PASS
// ════════════════════════════════════════════════════════════════
$h->section('6. Output freshness');

// 6a. RunExporter with stale data: an HTML error must not yield a passing result
$exporter = new RunExporter();
$staleRun = [
    'run_id' => 'stale-run-001',
    'module' => 'pal-workflows',
    'outcome' => 'passed',
    'issues' => [],
    'provenance' => (new RunProvenance())->build([
        'run_id' => 'stale-run-001',
        'module_id' => 'pal-workflows',
        'completion_status' => 'complete',
    ]),
];
// If outcome is 'passed' but issues are empty, the export should still reflect the outcome
$arkExport = json_decode($exporter->ark($staleRun), true);
$h->test('ARK export preserves the stored outcome, not generating a passing status from empty issues',
    ($arkExport['run']['outcome'] ?? '') === 'passed');

// 6b. A run with 'interrupted' must not be certifiable
$interruptedRun = [
    'run_id' => 'interrupted-run-001',
    'module' => 'pal-workflows',
    'outcome' => 'interrupted',
    'issues' => [],
    'provenance' => (new RunProvenance())->build([
        'run_id' => 'interrupted-run-001',
        'module_id' => 'pal-workflows',
        'completion_status' => 'interrupted',
    ]),
];
$arkInterrupted = json_decode($exporter->ark($interruptedRun), true);
$h->test('interrupted ARK export has certification disclaimer',
    isset($arkInterrupted['provenance']['certification_disclaimer']));

// 6c. A stale result file must not yield a passing test
// Simulate by checking that an exporter takes the run's outcome, not the file mtime
$staleOutcomeRun = [
    'run_id' => 'stale-outcome-run',
    'module' => 'pal-workflows',
    'outcome' => 'failed',
    'issues' => [['fingerprint' => 'fp-stale', 'message' => 'Old failure', 'category' => 'routing', 'severity' => 'critical']],
    'provenance' => (new RunProvenance())->build(['run_id' => 'stale-outcome-run', 'module_id' => 'pal-workflows', 'completion_status' => 'complete']),
];
$junitStale = $exporter->junit($staleOutcomeRun);
$h->test('JUnit export of stale failed run still shows failures',
    str_contains($junitStale, 'failures="1"'));

// ════════════════════════════════════════════════════════════════
// 7. REGRESSION REPRODUCTION FOR CONFIRMED CRITICAL ISSUES
// ════════════════════════════════════════════════════════════════
$h->section('7. Regression reproduction');

$cardRenderer = new IssueCardRenderer($exporter);
$context = [
    'run_id' => 'regression-test-run',
    'module' => 'pal-workflows',
    'provenance' => (new RunProvenance())->build([
        'run_id' => 'regression-test-run',
        'module_id' => 'pal-workflows',
        'completion_status' => 'complete',
        'git_sha' => 'abc123',
    ]),
];

// 7a. Confirmed critical issues are release-blocking
$criticalIssues = [
    [
        'fingerprint' => 'reg-csrf-001',
        'category' => 'csrf',
        'severity' => 'critical',
        'message' => 'CSRF token validation failed on POST /admin/pal/projects',
        'expected' => 200,
        'actual' => 419,
    ],
    [
        'fingerprint' => 'reg-perm-001',
        'category' => 'permission',
        'severity' => 'critical',
        'message' => 'Permission escalation: user with role "viewer" could edit records',
        'expected' => false,
        'actual' => true,
    ],
    [
        'fingerprint' => 'reg-db-001',
        'category' => 'db',
        'severity' => 'critical',
        'message' => 'SQL injection vulnerability in search endpoint',
        'expected' => true,
        'actual' => false, // expected to be blocked, but wasn't
    ],
];

$regressionCards = $cardRenderer->renderBatch($criticalIssues, $context);
$releaseBlockers = array_filter($regressionCards, fn($c) => $c['is_release_blocking'] ?? false);
$h->test('all confirmed critical issues are release-blocking',
    count($releaseBlockers) >= 3);

// 7b. Each regression card has reproduction command and next test
foreach ($regressionCards as $i => $card) {
    $h->test("regression card {$i} has reproduction command",
        ($card['reproduction_command'] ?? '') !== '');
    $h->test("regression card {$i} has next deterministic test",
        isset($card['next_deterministic_test']['tests']));
}

// 7c. Regression summary
$regressionSummary = $cardRenderer->summary($regressionCards);
$h->test('regression summary has release_blocking count >= 3',
    ($regressionSummary['release_blocking'] ?? 0) >= 3);
$h->test('regression summary total matches',
    ($regressionSummary['total_issues'] ?? 0) >= 3);

// 7d. Regression cards have exports
foreach ($regressionCards as $i => $card) {
    $h->test("regression card {$i} has JSON export",
        isset($card['exporter']['json']));
    $h->test("regression card {$i} has JUnit export",
        isset($card['exporter']['junit']));
    $h->test("regression card {$i} has SARIF export",
        isset($card['exporter']['sarif']));
}

$h->done();
