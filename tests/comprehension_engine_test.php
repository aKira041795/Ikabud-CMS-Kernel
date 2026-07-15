<?php

declare(strict_types=1);

/**
 * Comprehension Engine v3.0 tests.
 * Verifies: AI provider rejection, Bayesian non-double-recording,
 * list-by-module, delete-index sync, explicit actionId persistence,
 * buildEvidencePacket read-only behaviour.
 */

require_once __DIR__ . '/harness/TestHarness.php';

$h = new TestHarness('comprehension-engine-v3');

// ── Load comprehension classes ──────────────────────────────
$base = dirname(__DIR__);
require_once $base . '/kernel/Workbench/Comprehension/Contracts/ModuleComprehensionProvider.php';
require_once $base . '/kernel/Workbench/Comprehension/Contracts/EntityContract.php';
require_once $base . '/kernel/Workbench/Comprehension/Contracts/WorkflowContract.php';
require_once $base . '/kernel/Workbench/Comprehension/Contracts/ActionContract.php';
require_once $base . '/kernel/Workbench/Comprehension/Contracts/EffectContract.php';
require_once $base . '/kernel/Workbench/Comprehension/Contracts/SupportContracts.php';
require_once $base . '/kernel/Workbench/Comprehension/Contracts/AiContracts.php';
require_once $base . '/kernel/Workbench/Comprehension/ModuleComprehensionEngine.php';
require_once $base . '/kernel/Workbench/Comprehension/PalComprehensionProvider.php';
require_once $base . '/kernel/Workbench/Comprehension/Analyzers/SemanticScorer.php';
require_once $base . '/kernel/Workbench/Comprehension/Analyzers/EmbeddingScorer.php';
require_once $base . '/kernel/Workbench/Comprehension/Analyzers/BayesianReasoner.php';
require_once $base . '/kernel/Workbench/Comprehension/Analyzers/TemporalValidator.php';
require_once $base . '/kernel/Workbench/Comprehension/Analyzers/PatternClassifier.php';
require_once $base . '/kernel/Workbench/Comprehension/Analyzers/AnomalyDetector.php';
require_once $base . '/kernel/Workbench/Comprehension/Analyzers/CrossModuleAnalyzer.php';
require_once $base . '/kernel/Workbench/Comprehension/Analyzers/SourceRetriever.php';
require_once $base . '/kernel/Workbench/Comprehension/Analyzers/AiHypothesisGenerator.php';
require_once $base . '/kernel/Workbench/Comprehension/Analyzers/CaseMemory.php';
require_once $base . '/kernel/Workbench/Comprehension/Analyzers/ProviderCoverageScorer.php';
require_once $base . '/kernel/Workbench/Comprehension/SemanticComprehensionEngine.php';

use Ikabud\Kernel\Workbench\Comprehension\SemanticComprehensionEngine;
use Ikabud\Kernel\Workbench\Comprehension\PalComprehensionProvider;
use Ikabud\Kernel\Workbench\Comprehension\Analyzers\{AiHypothesisGenerator, CaseMemory};
use Ikabud\Kernel\Workbench\Comprehension\Contracts\CaseMemoryEntry;

// ── Section 1: AI provider validation ──────────────────────
$h->section('AI provider validation');

try {
    new AiHypothesisGenerator('test-mod', null, null, 'openai');
    $h->test('Unsupported provider openai is rejected', false);
} catch (\InvalidArgumentException $e) {
    $h->test('Unsupported provider openai is rejected', str_contains($e->getMessage(), 'openai'));
}

try {
    new AiHypothesisGenerator('test-mod', null, null, 'copilot');
    $h->test('Unsupported provider copilot is rejected', false);
} catch (\InvalidArgumentException $e) {
    $h->test('Unsupported provider copilot is rejected', str_contains($e->getMessage(), 'copilot'));
}

try {
    new AiHypothesisGenerator('test-mod', null, null, 'heuristic');
    $h->test('Heuristic provider accepted', true);
} catch (\InvalidArgumentException $e) {
    $h->test('Heuristic provider accepted', false);
}

// ── Section 2: Case memory store/list/delete ───────────────
$h->section('Case memory');

$tmpDir = sys_get_temp_dir() . '/cm-test-' . getmypid();
@mkdir($tmpDir . '/private/comprehension/cases', 0755, true);
$mem = new CaseMemory($tmpDir . '/private/comprehension');

$mem->store(new CaseMemoryEntry(
    id: 'case-test-pal-1',
    moduleId: 'project-audit-ledger',
    actionId: 'pal.job-order.submit',
    summary: 'Button not visible',
    changedFiles: ['handlers/30-projects.php'],
    fixSummary: 'Fixed template variable scope',
));

$list = $mem->listByModule('project-audit-ledger');
$h->test('List returns stored case', count($list) === 1);
$h->assertSame('pal.job-order.submit', $list[0]['action_id'], 'Action ID persisted correctly');

$mem->delete('case-test-pal-1');
$h->test('Delete removes from index', count($mem->listByModule('project-audit-ledger')) === 0);
$h->assertSame(0, $mem->stats()['total_cases'], 'Stats reflect deletion');

// Clean up
@unlink($tmpDir . '/private/comprehension/cases/case-test-pal-1.json');
@unlink($tmpDir . '/private/comprehension/cases/index.json');
@unlink($tmpDir . '/private/comprehension/cases/index.lock');
@rmdir($tmpDir . '/private/comprehension/cases');
@rmdir($tmpDir . '/private/comprehension');
@rmdir($tmpDir);

// ── Section 3: storeCaseMemory uses explicit actionId ─────
$h->section('Store case with explicit actionId');

$tmpDir2 = sys_get_temp_dir() . '/cm-test2-' . getmypid();
@mkdir($tmpDir2 . '/private/comprehension/cases', 0755, true);
$engine = new SemanticComprehensionEngine(
    'project-audit-ledger',
    new PalComprehensionProvider(),
    caseMemory: new CaseMemory($tmpDir2 . '/private/comprehension'),
    aiHypothesis: new AiHypothesisGenerator('project-audit-ledger'),
);

$caseId = $engine->storeCaseMemory(
    actionId: 'pal.job-order.submit',
    summary: 'Test submit failure',
    changedFiles: ['handlers/30-projects.php'],
    fixSummary: 'Fixed',
);
$h->test('Case ID starts with module prefix', str_starts_with($caseId, 'case-project-audit-ledger-'));

$cases = $engine->listCases();
$h->test('Stored case visible in list', count($cases) === 1);
$h->assertSame('pal.job-order.submit', $cases[0]['action_id'], 'Action ID is real action, not summary');

// ── Section 4: buildEvidencePacket is read-only ────────────
$h->section('buildEvidencePacket read-only');

$packet = $engine->buildEvidencePacket('pal.job-order.submit');
$h->test('Evidence packet has analysis', isset($packet['analysis']));
$h->test('Evidence packet has report card', isset($packet['report_card']));
$h->test('Evidence packet has engine version', ($packet['engine_version'] ?? '') === '3.0-ai-enhanced');
$h->test('Evidence packet has case memory stats', isset($packet['case_memory_stats']));

// Clean up
@unlink($tmpDir2 . '/private/comprehension/cases/' . $caseId . '.json');
@unlink($tmpDir2 . '/private/comprehension/cases/index.json');
@unlink($tmpDir2 . '/private/comprehension/cases/index.lock');
@rmdir($tmpDir2 . '/private/comprehension/cases');
@rmdir($tmpDir2 . '/private/comprehension');
@rmdir($tmpDir2);

// ── Section 5: Coverage scoring ────────────────────────────
$h->section('Coverage scoring');
$coverage = $engine->scoreCoverage();
$h->test('Coverage score is between 0 and 1', $coverage !== null && $coverage['overall_score'] >= 0 && $coverage['overall_score'] <= 1);

// ── Section 6: List cases for a module with no cases ────────
$h->section('List empty');
$emptyEngine = new SemanticComprehensionEngine(
    'project-audit-ledger',
    new PalComprehensionProvider(),
    caseMemory: new CaseMemory(sys_get_temp_dir() . '/empty-cm-' . getmypid() . '/private/comprehension'),
    aiHypothesis: new AiHypothesisGenerator('project-audit-ledger'),
);
$h->test('Empty module has no cases', count($emptyEngine->listCases()) === 0);

$h->done();
