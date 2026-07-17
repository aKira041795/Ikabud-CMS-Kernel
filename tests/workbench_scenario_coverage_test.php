<?php

declare(strict_types=1);

/**
 * Priority 2 — Governed Scenario Coverage Tests
 *
 * Validates scenario fixture declarations, prerequisite classification,
 * route traversal resolution, and fixture cleanup policy.
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/Scenario/ScenarioFixtureDeclaration.php';
require_once __DIR__ . '/../kernel/Workbench/Scenario/PrerequisiteClassifier.php';
require_once __DIR__ . '/../kernel/Workbench/Scenario/FixtureCleanupPolicy.php';
require_once __DIR__ . '/../kernel/Workbench/Scenario/RouteTraversalResolver.php';

use Ikabud\Kernel\Workbench\Scenario\ScenarioFixtureDeclaration;
use Ikabud\Kernel\Workbench\Scenario\PrerequisiteClassifier;
use Ikabud\Kernel\Workbench\Scenario\FixtureCleanupPolicy;
use Ikabud\Kernel\Workbench\Scenario\RouteTraversalResolver;

$h = new TestHarness('workbench-scenario-coverage');

// ── 1. ScenarioFixtureDeclaration ──────────────────────────────
$h->section('ScenarioFixtureDeclaration');

$fixtureDecl = new ScenarioFixtureDeclaration([
    'fixture_role' => 'admin',
    'fixture_user_id' => 1,
    'tenant_id' => 42,
    'tenant_key' => 'demo-tenant',
    'module' => 'pal-workflows',
    'data' => [
        'entities' => [
            'pal_project' => [
                ['name' => 'Test Project'],
                ['name' => 'Another Project'],
            ],
            'pal_expense' => [
                ['amount' => 100],
            ],
        ],
    ],
]);
$validation = $fixtureDecl->validate();
$normalized = $validation['normalized'];

$h->test('fixture validates with actor role',
    $validation['valid']);
$h->test('normalized actor has role',
    ($normalized['actor']['role'] ?? '') === 'admin');
$h->test('normalized tenant has tenant_id',
    ($normalized['tenant']['tenant_id'] ?? 0) === 42);
$h->test('normalized tenant has tenant_key',
    ($normalized['tenant']['tenant_key'] ?? '') === 'demo-tenant');
$h->test('required_entities includes pal_project',
    in_array('pal_project', array_column($normalized['required_entities'], 'type'), true));
$h->test('required_entities includes pal_expense',
    in_array('pal_expense', array_column($normalized['required_entities'], 'type'), true));
$h->test('pal_project min_count is 2',
    (array_column($normalized['required_entities'], 'min_count', 'type')['pal_project'] ?? 0) === 2);

// ── 2. FixtureDeclaration validation rejects missing role/tenant ──
$h->section('FixtureDeclaration validation');
$incomplete = new ScenarioFixtureDeclaration(['module' => 'test']);
$incompleteValidation = $incomplete->validate();
$h->test('missing role and tenant is invalid',
    !$incompleteValidation['valid']);
$h->test('missing role produces error',
    in_array('missing:actor.role', $incompleteValidation['errors'], true));
$h->test('missing tenant produces error',
    in_array('missing:tenant.identity', $incompleteValidation['errors'], true));

// ── 3. Classify unmet prerequisite ─────────────────────────────
$h->section('Classify unmet prerequisite');
$unmet = $fixtureDecl->classifyUnmet(
    ['type' => 'pal_project', 'min_count' => 1],
    'No pal_project records found for tenant'
);
$h->test('classification is unmet-prerequisite',
    ($unmet['classification'] ?? '') === 'unmet-prerequisite');
$h->test('unmet has owner from provider_module',
    ($unmet['owner'] ?? '') === 'pal-workflows');
$h->test('unmet has recommended capability',
    isset($unmet['recommended_provider_capability']));
$h->test('unmet has fixture requirements role',
    ($unmet['fixture_requirements']['role'] ?? '') === 'admin');

// Test unmet with explicit fixture_label
$fixtureDeclWithLabel = new ScenarioFixtureDeclaration([
    'fixture_role' => 'admin',
    'fixture_label' => 'Site Administrator',
    'fixture_user_id' => 1,
    'tenant_id' => 42,
    'tenant_key' => 'demo-tenant',
    'module' => 'pal-workflows',
    'data' => ['entities' => ['pal_project' => [['name' => 'Test']]]],
]);
$unmetWithLabel = $fixtureDeclWithLabel->classifyUnmet(
    ['type' => 'pal_project', 'min_count' => 1],
    'No records found'
);
$h->test('unmet with fixture_label uses label as role',
    ($unmetWithLabel['fixture_requirements']['role'] ?? '') === 'Site Administrator');
$h->test('unmet has tenant in fixture requirements',
    ($unmet['fixture_requirements']['tenant'] ?? '') === 'demo-tenant');

// ── 4. PrerequisiteClassifier ───────────────────────────────────
$h->section('PrerequisiteClassifier');
$classifier = new PrerequisiteClassifier();

// 4a. Confirmed defect
$defectResult = $classifier->classify([
    'category' => 'routing',
    'severity' => 'critical',
    'summary' => 'Route /admin/pal/projects returned 500',
    'expected' => 200,
    'actual' => 500,
    'outcome' => 'failed',
]);
$h->test('confirmed-defect with expected vs actual mismatch',
    ($defectResult['classification'] ?? '') === 'confirmed-defect');

// 4b. Unmet prerequisite via outcome
$unmetResult = $classifier->classify([
    'category' => 'fixture',
    'severity' => 'major',
    'summary' => 'No pal_project records found',
    'outcome' => 'unobserved',
    'step_id' => 'pal_project',
]);
$h->test('unmet-prerequisite via outcome=unobserved',
    ($unmetResult['classification'] ?? '') === 'unmet-prerequisite');

// 4c. Unmet prerequisite via category
$fixtureResult = $classifier->classify([
    'category' => 'scenario',
    'severity' => 'major',
    'summary' => 'Seed data not available',
    'outcome' => 'failed',
]);
$h->test('unmet-prerequisite via scenario category',
    ($fixtureResult['classification'] ?? '') === 'unmet-prerequisite');

// 4d. Environment issue
$envResult = $classifier->classify([
    'category' => 'connection',
    'severity' => 'critical',
    'summary' => 'Connection refused: database.example.com:3306',
    'outcome' => 'failed',
]);
$h->test('environment classification for connection refused',
    ($envResult['classification'] ?? '') === 'environment');

// 4e. False positive
$fpResult = $classifier->classify([
    'category' => 'flake',
    'severity' => 'minor',
    'summary' => 'Timed out waiting for selector',
    'outcome' => 'failed',
]);
$h->test('false-positive for flake category',
    ($fpResult['classification'] ?? '') === 'false-positive');

// 4f. Classify with fixture declaration context
$fixtureContextResult = $classifier->classify(
    [
        'category' => 'routing',
        'severity' => 'major',
        'summary' => 'Could not navigate to detail page',
        'outcome' => 'failed',
        'step_id' => 'pal_project',
    ],
    $fixtureDecl
);
$h->test('unmet-prerequisite when step_id matches fixture entity',
    ($fixtureContextResult['classification'] ?? '') === 'unmet-prerequisite');

// 4g. Batch classification
$batchResults = $classifier->classifyBatch([
    ['category' => 'routing', 'severity' => 'critical', 'summary' => '500 error', 'expected' => 200, 'actual' => 500, 'outcome' => 'failed'],
    ['category' => 'fixture', 'severity' => 'major', 'summary' => 'No data', 'outcome' => 'unobserved'],
    ['category' => 'connection', 'severity' => 'critical', 'summary' => 'Connection refused: db:3306', 'outcome' => 'failed'],
]);
$counts = $classifier->counts($batchResults);
$h->test('batch has confirmed-defect',
    ($counts['confirmed-defect'] ?? 0) === 1);
$h->test('batch has unmet-prerequisite',
    ($counts['unmet-prerequisite'] ?? 0) === 1);
$h->test('batch has environment',
    ($counts['environment'] ?? 0) === 1);

// ── 5. FixtureCleanupPolicy ─────────────────────────────────────
$h->section('FixtureCleanupPolicy');
$cleanupPolicy = new FixtureCleanupPolicy();

$validScenario = [
    'scenario_id' => 'test-scenario-001',
    'module' => 'pal-workflows',
    'tenant_id' => 42,
    'tenant_key' => 'demo-tenant',
    'data' => [
        'entities' => [
            'pal_project' => [
                ['name' => 'Test Project'],
            ],
        ],
    ],
];
$cleanupValidation = $cleanupPolicy->validate($validScenario);
$h->test('cleanup policy validates valid scenario',
    $cleanupValidation['valid']);

$noTenantScenario = [
    'scenario_id' => 'test-no-tenant',
    'data' => ['entities' => ['test' => [['id' => 1]]]],
];
$noTenantValidation = $cleanupPolicy->validate($noTenantScenario);
$h->test('cleanup policy rejects missing tenant',
    !$noTenantValidation['valid']);
$h->test('cleanup error mentions tenant scope',
    str_contains(implode(' ', $noTenantValidation['errors']), 'tenant'));

// 5b. Build cleanup plan
$receipt = [
    'namespace' => 'run-001',
    'entities' => [
        ['type' => 'pal_project', 'count' => 2],
        ['type' => 'pal_expense', 'count' => 1],
    ],
];
$cleanupPlan = $cleanupPolicy->buildCleanup($validScenario, $receipt);
$h->test('cleanup plan has schema',
    ($cleanupPlan['schema'] ?? '') === 'ark.fixture-cleanup.v1');
$h->test('cleanup plan never deletes user-owned data',
    ($cleanupPlan['policy']['never_delete_user_owned_data'] ?? false) === true);
$h->test('cleanup plan is tenant-scoped',
    ($cleanupPlan['policy']['tenant_scoped'] ?? false) === true);
$h->test('cleanup plan is idempotent',
    ($cleanupPlan['policy']['idempotent'] ?? false) === true);
$h->test('cleanup plan uses marker-based deletion',
    ($cleanupPlan['policy']['method'] ?? '') === 'marker-based-deletion');
$h->test('cleanup plan has entities from receipt',
    count($cleanupPlan['entities'] ?? []) === 2);

// 5c. Can cleanup check
$safetyCheck = $cleanupPolicy->canCleanup($cleanupPlan);
$h->test('canCleanup allows valid cleanup plan',
    ($safetyCheck['allowed'] ?? false) === true);

$tenantlessPlan = $cleanupPlan;
$tenantlessPlan['scope']['tenant_id'] = 0;
$tenantlessPlan['scope']['tenant_key'] = '';
$tenantlessSafety = $cleanupPolicy->canCleanup($tenantlessPlan);
$h->test('canCleanup rejects cleanup plans without tenant scope',
    ($tenantlessSafety['allowed'] ?? true) === false);

$unmarkedPlan = $cleanupPlan;
$unmarkedPlan['entities'][0]['marker_value'] = '';
$unmarkedSafety = $cleanupPolicy->canCleanup($unmarkedPlan);
$h->test('canCleanup rejects fixture entities without a marker',
    ($unmarkedSafety['allowed'] ?? true) === false);

$emptyScopePlan = $cleanupPolicy->buildCleanup(
    ['scenario_id' => 'test', 'data' => ['entities' => ['x' => [['id' => 1]]]]],
    ['namespace' => '']
);
$emptySafety = $cleanupPolicy->canCleanup($emptyScopePlan);
$h->test('canCleanup rejects empty run_id',
    ($emptySafety['allowed'] ?? true) === false);

// ── 6. RouteTraversalResolver ───────────────────────────────────
$h->section('RouteTraversalResolver');
$resolver = new RouteTraversalResolver();

// 6a. Observe links
$resolver->observeLink('/admin/pal/projects/{id}', '/admin/pal/projects/42');
$resolver->observeLink('/admin/pal/projects/{id}/edit', '/admin/pal/projects/42/edit');
$resolver->observeLinks([
    '/admin/pal/expenses/{id}' => '/admin/pal/expenses/7',
]);

// 6b. Resolve via observed link
$observedResult = $resolver->resolve(
    '/admin/pal/projects/{id}',
    fn(string $r): ?string => null
);
$h->test('resolve uses observed link for pattern match',
    ($observedResult['resolved_url'] ?? '') === '/admin/pal/projects/42');
$h->test('resolve source is observed',
    ($observedResult['source'] ?? '') === 'observed');

// 6c. Resolve expense via observed link
$expenseResult = $resolver->resolve(
    '/admin/pal/expenses/{id}',
    fn(string $r): ?string => null
);
$h->test('resolve expense via observed links',
    ($expenseResult['resolved_url'] ?? '') === '/admin/pal/expenses/7');

$resolver->observeLink('/admin/pal/projects/{project_uuid}/edit', '/admin/pal/projects/uuid-42/edit');
$namedParameterResult = $resolver->resolve(
    '/admin/pal/projects/{project_uuid}/edit',
    fn(string $r): ?string => null
);
$h->test('resolve supports arbitrary named route placeholders',
    ($namedParameterResult['resolved_url'] ?? '') === '/admin/pal/projects/uuid-42/edit');

// 6d. Fallback to provider (route that does NOT match any observed pattern)
$providerResult = $resolver->resolve(
    '/admin/pal/settings',
    fn(string $r): ?string => '/admin/pal/settings'
);
$h->test('resolve falls back to provider when no observed link',
    ($providerResult['resolved_url'] ?? '') === '/admin/pal/settings');
$h->test('resolve source is provider',
    ($providerResult['source'] ?? '') === 'provider');

// 6e. Unresolved parameterized route → unmet-prerequisite
$unresolvedResult = $resolver->resolve(
    '/admin/pal/reports/{id}',
    fn(string $r): ?string => null
);
$h->test('unresolved parameterized route returns null url',
    $unresolvedResult['resolved_url'] === null);
$unresolvedClassification = $resolver->classifyUnresolved($unresolvedResult);
$h->test('unresolved parameterized route -> unmet-prerequisite',
    ($unresolvedClassification['classification'] ?? '') === 'unmet-prerequisite');
$h->test('unresolved note mentions parameterized route',
    str_contains($unresolvedClassification['note'] ?? '', 'parameterized'));

// 6f. Unresolved non-parameterized route → confirmed-defect
$missingResult = $resolver->resolve(
    '/admin/pal/missing-route',
    fn(string $r): ?string => null
);
$missingClassification = $resolver->classifyUnresolved($missingResult);
$h->test('unresolved non-parameterized route -> confirmed-defect',
    ($missingClassification['classification'] ?? '') === 'confirmed-defect');

$h->done();
