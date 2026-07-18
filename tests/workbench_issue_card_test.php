<?php

declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

/**
 * Priority 4 — Actionable Issue Card Tests
 *
 * Validates that every issue renders as an evidence-backed card with:
 *   - classification, severity, deterministic gate impact
 *   - confidence and basis, exact evidence links
 *   - observed versus expected behavior, reproduction command
 *   - environment/fixture identity, suspected cause
 *   - recommended owner, next deterministic test
 *   - JSON, JUnit, and SARIF exports
 */


require_once __DIR__ . '/harness/TestHarness.php';

require_once __DIR__ . '/../kernel/Workbench/Issues/IssueCardRenderer.php';

require_once __DIR__ . '/../kernel/Workbench/Runs/RunExporter.php';

require_once __DIR__ . '/../kernel/Workbench/Scenario/PrerequisiteClassifier.php';

use Ikabud\Kernel\Workbench\Issues\IssueCardRenderer;
use Ikabud\Kernel\Workbench\Runs\RunExporter;

$h = new TestHarness('workbench-issue-card');

// ── Sample provenance and context ─────────────────────────────────
$provenance = [
    'provenance_schema' => 'ark.workbench-run-provenance.v1',
    'run_id' => 'test-run-20260717',
    'git_sha' => 'abc123def456',
    'module_id' => 'pal-workflows',
    'module_version' => '0.5.0',
    'environment_fingerprint' => 'env-fp-001',
    'tenant_identity' => ['tenant_id' => 42, 'tenant_key' => 'demo', 'domain' => 'demo.example.com'],
    'role_fixture_identity' => ['role' => 'admin', 'user_id' => 1, 'fixture_label' => 'Site Admin'],
    'ai_policy' => ['ai_enabled' => true, 'configured_provider' => 'openai', 'configured_model' => 'gpt-5', 'tier' => 'free'],
    'completion_status' => 'complete',
];

$aiResult = [
    'hypotheses' => [
        ['summary' => 'CSRF token validation failed', 'confidence' => 0.91, 'evidence_for' => ['obs-1'], 'evidence_against' => []],
    ],
    'next_tests' => [['id' => 'refresh-csrf-token']],
    'provider_trace' => [
        'provider' => 'openai',
        'model' => 'gpt-5',
        'latency_ms' => 250.0,
        'fallback_reason' => null,
    ],
];

$context = [
    'run_id' => 'test-run-20260717',
    'module' => 'pal-workflows',
    'provenance' => $provenance,
    'ai_result' => $aiResult,
];

$renderer = new IssueCardRenderer(new RunExporter());

// ── 1. Confirmed defect card ─────────────────────────────────────
$h->section('Confirmed defect card');
$defectIssue = [
    'fingerprint' => 'fp-csrf-001',
    'category' => 'csrf',
    'severity' => 'critical',
    'message' => 'CSRF token mismatch on form submission',
    'expected' => 200,
    'actual' => 419,
    'evidence_links' => ['/var/log/app.log:1234'],
];
$defectCard = $renderer->render($defectIssue, $context);

$h->test('card has issue_id from fingerprint',
    ($defectCard['issue_id'] ?? '') === 'fp-csrf-001');
$h->test('confirmed-defect classification',
    ($defectCard['classification'] ?? '') === 'confirmed-defect');
$h->test('severity preserved',
    ($defectCard['severity'] ?? '') === 'critical');
$h->test('is release blocking for critical confirmed-defect',
    ($defectCard['is_release_blocking'] ?? false) === true);
$h->test('not a fixture block',
    ($defectCard['is_fixture_block'] ?? true) === false);
$h->test('deterministic_gate_impact is release-blocking',
    ($defectCard['deterministic_gate_impact'] ?? '') === 'release-blocking');
$h->test('observed_vs_expected has expected',
    isset($defectCard['observed_vs_expected']['expected']));
$h->test('observed_vs_expected has actual',
    isset($defectCard['observed_vs_expected']['actual']));
$h->test('evidence_links includes run link',
    count($defectCard['evidence_links'] ?? []) >= 1);
$h->test('AI label indicates AI-assisted',
    str_contains($defectCard['confidence_and_basis']['ai_label'] ?? '', 'AI-assisted'));
$h->test('AI confidence extracted',
    ($defectCard['confidence_and_basis']['ai_confidence'] ?? 0) === 0.91);
$h->test('reproduction command includes module',
    str_contains($defectCard['reproduction_command'] ?? '', 'pal-workflows'));
$h->test('environment_identity has run_id',
    ($defectCard['environment_identity']['run_id'] ?? '') === 'test-run-20260717');
$h->test('environment_identity has git_sha',
    ($defectCard['environment_identity']['git_sha'] ?? '') === 'abc123def456');
$h->test('suspected cause from AI hypothesis',
    ($defectCard['suspected_cause'] ?? '') === 'CSRF token validation failed');
$h->test('next deterministic test from AI',
    ($defectCard['next_deterministic_test']['tests'][0]['id'] ?? '') === 'refresh-csrf-token');
$h->test('recommended owner from module',
    ($defectCard['recommended_owner'] ?? '') === 'module:pal-workflows');
$h->test('has JSON export',
    isset($defectCard['exporter']['json']));
$h->test('has JUnit export',
    isset($defectCard['exporter']['junit']));
$h->test('has SARIF export',
    isset($defectCard['exporter']['sarif']));

// ── 2. Unmet prerequisite card ───────────────────────────────────
$h->section('Unmet prerequisite card');
$fixtureIssue = [
    'fingerprint' => 'fp-fixture-001',
    'category' => 'fixture',
    'severity' => 'major',
    'message' => 'No pal_project records found for tenant',
    'outcome' => 'unobserved',
    'step_id' => 'pal_project',
];
$fixtureCard = $renderer->render($fixtureIssue, $context);
$h->test('unmet-prerequisite classification',
    ($fixtureCard['classification'] ?? '') === 'unmet-prerequisite');
$h->test('is fixture block',
    ($fixtureCard['is_fixture_block'] ?? false) === true);
$h->test('not release blocking',
    ($fixtureCard['is_release_blocking'] ?? true) === false);
$h->test('gate impact is informational for fixture blocks',
    ($fixtureCard['deterministic_gate_impact'] ?? '') === 'informational');

// ── 3. Environment issue card ─────────────────────────────────────
$h->section('Environment issue card');
$envIssue = [
    'fingerprint' => 'fp-env-001',
    'category' => 'connection',
    'severity' => 'critical',
    'message' => 'Connection refused: database.example.com:3306',
    'outcome' => 'failed',
];
$envCard = $renderer->render($envIssue, $context);
$h->test('environment classification',
    ($envCard['classification'] ?? '') === 'environment');
$h->test('is environment block',
    ($envCard['is_environment_block'] ?? false) === true);

// ── 4. AI unavailable fallback label ──────────────────────────────
$h->section('AI fallback label');
$noAiContext = $context;
$noAiContext['ai_result'] = [
    'provider_trace' => ['provider' => 'heuristic', 'model' => 'rules-v1', 'fallback_reason' => 'disabled'],
];
$noAiCard = $renderer->render($defectIssue, $noAiContext);
$h->test('AI label indicates fallback when disabled',
    str_contains($noAiCard['confidence_and_basis']['ai_label'] ?? '', 'AI unavailable'));

// ── 5. Non-blocking risk card ────────────────────────────────────
$h->section('Non-blocking risk card');
$minorIssue = [
    'fingerprint' => 'fp-minor-001',
    'category' => 'performance',
    'severity' => 'minor',
    'message' => 'Page load time exceeds budget by 200ms',
    'expected' => 2000,
    'actual' => 2200,
];
$minorCard = $renderer->render($minorIssue, $context);
$h->test('non-blocking risk for minor severity',
    ($minorCard['is_non_blocking_risk'] ?? false) === true);
$h->test('gate impact is non-blocking-risk',
    ($minorCard['deterministic_gate_impact'] ?? '') === 'non-blocking-risk');

// ── 6. Batch rendering ────────────────────────────────────────────
$h->section('Batch rendering');
$cards = $renderer->renderBatch([$defectIssue, $fixtureIssue, $envIssue], $context);
$h->test('batch returns all cards',
    count($cards) === 3);
$summary = $renderer->summary($cards);
$h->test('summary has total_issues',
    ($summary['total_issues'] ?? 0) === 3);
$h->test('summary has release_blocking count',
    ($summary['release_blocking'] ?? 0) >= 1);
$h->test('summary has fixture_blocks count',
    ($summary['fixture_blocks'] ?? 0) >= 1);
$h->test('summary by_classification has entries',
    count($summary['by_classification'] ?? []) >= 1);

// ── 7. JSON export validity ──────────────────────────────────────
$h->section('Export validity');
$jsonContent = $defectCard['exporter']['json'];
$decoded = json_decode($jsonContent, true);
$h->test('JSON export is valid JSON',
    is_array($decoded));
$h->test('JSON export has run data',
    isset($decoded['run']));
$h->test('JSON export has provenance',
    isset($decoded['provenance']));

$junitContent = $defectCard['exporter']['junit'];
$h->test('JUnit export is valid XML',
    str_contains($junitContent, '<?xml'));

$sarifContent = $defectCard['exporter']['sarif'];
$sarifDecoded = json_decode($sarifContent, true);
$h->test('SARIF export is valid JSON',
    is_array($sarifDecoded));
$h->test('SARIF export has runs',
    isset($sarifDecoded['runs']));

$h->done();
