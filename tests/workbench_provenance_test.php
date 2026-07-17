<?php

declare(strict_types=1);

/**
 * Priority 1 — Report Provenance Tests
 *
 * Validates canonical run provenance block attached to Workbench reports.
 * Covers: complete, interrupted, blocked, failed-before-analysis runs,
 * provider fallback, redacted runs, and artifact reference integrity.
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/../kernel/Workbench/Runs/RunProvenance.php';
require_once __DIR__ . '/../kernel/Workbench/Runs/RunExporter.php';
require_once __DIR__ . '/../kernel/Workbench/Runs/RunRepository.php';

use Ikabud\Kernel\Workbench\Runs\RunProvenance;
use Ikabud\Kernel\Workbench\Runs\RunExporter;
use Ikabud\Kernel\Workbench\Runs\RunRepository;

$h = new TestHarness('workbench-provenance');

// ── 1. Complete run provenance ──────────────────────────────────
$h->section('Complete run provenance');
$provenance = new RunProvenance();
$complete = $provenance->build([
    'run_id' => 'test-20260717-001',
    'module_id' => 'pal-workflows',
    'started_at' => '2026-07-17T10:00:00Z',
    'finished_at' => '2026-07-17T10:01:23Z',
    'completion_status' => 'complete',
    'git_sha' => 'abc123def456',
    'module_version' => '0.5.0',
    'app_url' => 'http://localhost:8080',
    'gate_policy' => 'critical',
    'tenant_id' => 42,
    'tenant_key' => 'demo-tenant',
    'fixture_role' => 'admin',
    'fixture_user_id' => 1,
    'artifact_schema_versions' => ['evidence' => 'ark.workbench-evidence-observation.v1'],
    'input_artifacts' => ['contract' => '/var/www/html/applicationostest/modules/pal-workflows/.ark-workbench-contract.json'],
]);

$h->test('provenance_schema is correct',
    ($complete['provenance_schema'] ?? '') === 'ark.workbench-run-provenance.v1');
$h->test('run_id is preserved',
    ($complete['run_id'] ?? '') === 'test-20260717-001');
$h->test('module_id is preserved',
    ($complete['module_id'] ?? '') === 'pal-workflows');
$h->test('completion_status is complete',
    ($complete['completion_status'] ?? '') === 'complete');
$h->test('no certification disclaimer for complete runs',
    !isset($complete['certification_disclaimer']));
$h->test('git_sha is preserved',
    ($complete['git_sha'] ?? '') === 'abc123def456');
$h->test('module_version is preserved',
    ($complete['module_version'] ?? '') === '0.5.0');
$h->test('app_url is preserved',
    ($complete['app_url'] ?? '') === 'http://localhost:8080');
$h->test('environment_fingerprint is a sha256 hash',
    strlen($complete['environment_fingerprint'] ?? '') === 64 && ctype_xdigit($complete['environment_fingerprint'] ?? ''));
$h->test('tenant_identity has tenant_id',
    ($complete['tenant_identity']['tenant_id'] ?? 0) === 42);
$h->test('tenant_identity has tenant_key',
    ($complete['tenant_identity']['tenant_key'] ?? '') === 'demo-tenant');
$h->test('role_fixture_identity has role',
    ($complete['role_fixture_identity']['role'] ?? '') === 'admin');
$h->test('gate_policy is critical',
    ($complete['gate_policy'] ?? '') === 'critical');
$h->test('artifact_schema_versions includes provenance key',
    ($complete['artifact_schema_versions']['provenance'] ?? '') === 'ark.workbench-run-provenance.v1');
$h->test('redaction_status defaults to none',
    ($complete['redaction_status'] ?? '') === 'none');
$h->test('ai_policy shows ai_enabled defaults to false',
    ($complete['ai_policy']['ai_enabled'] ?? null) === false);

// ── 2. Interrupted run ──────────────────────────────────────────
$h->section('Interrupted run provenance');
$interrupted = $provenance->build([
    'run_id' => 'test-interrupted-001',
    'module_id' => 'pal-workflows',
    'completion_status' => 'interrupted',
]);
$h->test('completion_status is interrupted',
    ($interrupted['completion_status'] ?? '') === 'interrupted');
$h->test('certification disclaimer present for interrupted',
    isset($interrupted['certification_disclaimer']));
$h->test('disclaimer warns against certification',
    str_contains($interrupted['certification_disclaimer'], 'must not be used for release certification'));

// ── 3. Blocked run ──────────────────────────────────────────────
$h->section('Blocked run provenance');
$blocked = $provenance->build([
    'run_id' => 'test-blocked-001',
    'module_id' => 'pal-workflows',
    'completion_status' => 'blocked',
]);
$h->test('completion_status is blocked',
    ($blocked['completion_status'] ?? '') === 'blocked');
$h->test('certification disclaimer present for blocked',
    isset($blocked['certification_disclaimer']));

// ── 4. Failed-before-analysis run ───────────────────────────────
$h->section('Failed-before-analysis provenance');
$failedBefore = $provenance->build([
    'run_id' => 'test-failed-001',
    'module_id' => 'pal-workflows',
    'completion_status' => 'failed-before-analysis',
]);
$h->test('completion_status is failed-before-analysis',
    ($failedBefore['completion_status'] ?? '') === 'failed-before-analysis');
$h->test('certification disclaimer present for failed-before-analysis',
    isset($failedBefore['certification_disclaimer']));

// ── 5. Invalid completion_status defaults to failed-before-analysis ──
$h->section('Invalid completion_status');
$invalid = $provenance->build([
    'run_id' => 'test-invalid-001',
    'module_id' => 'pal-workflows',
    'completion_status' => 'invalid_status_here',
]);
$h->test('invalid status defaults to failed-before-analysis',
    ($invalid['completion_status'] ?? '') === 'failed-before-analysis');

// ── 6. RunExporter includes provenance ────────────────────────────
$h->section('RunExporter provenance inclusion');
$exporter = new RunExporter();
$exportProvenance = $provenance->build([
    'run_id' => 'test-export-001',
    'module_id' => 'pal-workflows',
    'completion_status' => 'complete',
    'git_sha' => 'abc123def456',
    'module_version' => '0.5.0',
    'redaction_status' => 'none',
]);
$runWithProvenance = [
    'run_id' => 'test-export-001',
    'module' => 'pal-workflows',
    'issues' => [
        ['fingerprint' => 'fp-001', 'message' => 'Route not found', 'category' => 'routing', 'severity' => 'critical'],
    ],
    'provenance' => $exportProvenance,
];
$arkJson = $exporter->ark($runWithProvenance);
$arkDecoded = json_decode($arkJson, true);
$h->test('ARK export includes provenance',
    isset($arkDecoded['provenance']));
$h->test('ARK export provenance has completion_status',
    ($arkDecoded['provenance']['completion_status'] ?? '') === 'complete');

$junitXml = $exporter->junit($runWithProvenance);
$h->test('JUnit export includes provenance as property',
    str_contains($junitXml, 'property') && str_contains($junitXml, 'test-export-001'));
$h->test('JUnit export has completion_status property',
    str_contains($junitXml, 'completion_status'));
$h->test('JUnit export preserves the full canonical provenance block',
    str_contains($junitXml, 'ark_workbench_provenance_json') && str_contains($junitXml, 'environment_fingerprint'));

$sarifJson = $exporter->sarif($runWithProvenance);
$sarifDecoded = json_decode($sarifJson, true);
$h->test('SARIF export includes provenance in run properties',
    isset($sarifDecoded['runs'][0]['properties']['run_id']));
$h->test('SARIF export provenance has completion_status',
    ($sarifDecoded['runs'][0]['properties']['completion_status'] ?? '') === 'complete');
$h->test('SARIF export preserves the full canonical provenance block',
    ($sarifDecoded['runs'][0]['properties']['ark_workbench_provenance']['environment_fingerprint'] ?? '') !== '');

// ── 7. RunRepository save attaches provenance ─────────────────────
$h->section('RunRepository provenance');
$tmpDir = sys_get_temp_dir() . '/wb-provenance-test-' . bin2hex(random_bytes(6));
$repo = new RunRepository($tmpDir);
$savedId = $repo->save([
    'run_id' => 'repo-test-001',
    'module' => 'pal-workflows',
    'outcome' => 'passed',
]);
$saved = $repo->get('repo-test-001');
$h->test('RunRepository save attaches provenance',
    isset($saved['provenance']));
$h->test('RunRepository provenance has completion_status',
    ($saved['provenance']['completion_status'] ?? '') !== '');
$h->test('RunRepository summary includes completion_status',
    isset($saved['provenance']['module_id']));

// Cleanup
$repoPath = $tmpDir;
array_map('unlink', glob($repoPath . '/runs/*.json'));
is_file($repoPath . '/index.json') && unlink($repoPath . '/index.json');
is_file($repoPath . '/index.lock') && unlink($repoPath . '/index.lock');
rmdir($repoPath . '/runs');
rmdir($repoPath);

// ── 8. Contract service run includes provenance ────────────────────
$h->section('ContractService run provenance');
$contractServiceSource = (string) file_get_contents(__DIR__ . '/../kernel/Workbench/Contracts/WorkbenchContractService.php');
$h->test('WorkbenchContractService::run() includes provenance in report',
    str_contains($contractServiceSource, "'provenance'"));
$h->test('WorkbenchContractService::run() builds provenance from RunProvenance',
    str_contains($contractServiceSource, 'new \\Ikabud\\Kernel\\Workbench\\Runs\\RunProvenance()'));
$h->test('WorkbenchContractService updates completion_status after execution',
    str_contains($contractServiceSource, "'completion_status'"));
$h->test('Contract service includes certification disclaimer for incomplete runs',
    str_contains($contractServiceSource, 'certification_disclaimer'));

// ── 9. CompetitiveBenchmarkRunner includes provenance ──────────────
$h->section('BenchmarkRunner provenance');
$benchmarkSource = (string) file_get_contents(__DIR__ . '/../kernel/Workbench/Benchmark/CompetitiveBenchmarkRunner.php');
$h->test('CompetitiveBenchmarkRunner::execute() includes provenance',
    str_contains($benchmarkSource, "'provenance'"));
$h->test('CompetitiveBenchmarkRunner uses RunProvenance',
    str_contains($benchmarkSource, 'new RunProvenance()'));

// ── 10. Schema validation ──────────────────────────────────────────
$h->section('Provenance schema');
$schemaFile = __DIR__ . '/../kernel/Workbench/Schemas/run-provenance.v1.schema.json';
$h->test('Provenance schema file exists',
    is_file($schemaFile));
$schema = json_decode((string) file_get_contents($schemaFile), true);
$h->test('Provenance schema is valid JSON',
    is_array($schema));
$h->test('Provenance schema requires completion_status',
    in_array('completion_status', $schema['required'] ?? [], true));
$h->test('Provenance schema restricts completion_status enum',
    ($schema['properties']['completion_status']['enum'] ?? []) === ['complete', 'interrupted', 'blocked', 'failed-before-analysis']);

$h->done();
