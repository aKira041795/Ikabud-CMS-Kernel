<?php

declare(strict_types=1);

/**
 * Workbench Comprehension Runner — CLI entry point.
 *
 * Runs the hybrid semantic comprehension engine against a module,
 * collecting runtime evidence, applying all 8 reasoning layers,
 * and outputting a comprehensive report with AI diagnosis.
 *
 * Usage:
 *   php kernel/Workbench/Comprehension/run.php <module-id> [action-id] [options]
 *
 * Options:
 *   --evidence=file.json     Evidence file from ActionObserver
 *   --tenant=N               Tenant ID. Required for DB probes unless provided by evidence _meta.
 *   --entity-type=string     Entity type (e.g. pal.project)
 *   --entity-id=N            Entity ID
 *   --run-id=string          Test run ID for history tracking
 *   --reset-history          Clear Bayesian history for this module/action
 *   --report-card            Output an actionable report card (root cause, timeline, fix plan)
 *   --coverage               Run provider coverage scoring
 *   --store-case=summary     Store this run as a case memory entry (with --changed-files)
 *   --changed-files=list     Comma-separated list of changed files (for --store-case)
 *   --fix-summary=text       Fix summary description (for --store-case)
 *   --stats                  Show case memory statistics
 *   --list-cases             List stored cases for this module
 *   --ai-provider=type       AI provider: heuristic (only supported provider)
 *
 * Examples:
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger pal.job-order.submit
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger --evidence=test_results/evidence/pal-submit.json
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger pal.job-order.submit --reset-history
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger pal.job-order.submit --report-card
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger --coverage
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger --stats
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger --list-cases
 *
 * Output: test_results/ai/comprehension-report.json
 */

if (php_sapi_name() !== 'cli') {
    echo "CLI only.\n"; exit(1);
}

// ── Parse arguments ──────────────────────────────────────────
$moduleId = '';
$actionId = '';
$evidenceFile = null;
$tenantId = null;
$entityType = null;
$entityId = null;
$runId = null;
$resetHistory = false;
$reportCard = false;
$coverage = false;
$storeCase = null;
$changedFiles = [];
$fixSummary = '';
$showStats = false;
$listCases = false;
$aiProvider = 'heuristic';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--evidence=')) {
        $evidenceFile = substr($arg, 11);
    } elseif (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int)substr($arg, 9);
    } elseif (str_starts_with($arg, '--entity-type=')) {
        $entityType = substr($arg, 14);
    } elseif (str_starts_with($arg, '--entity-id=')) {
        $entityId = (int)substr($arg, 12);
    } elseif (str_starts_with($arg, '--run-id=')) {
        $runId = substr($arg, 9);
    } elseif (str_starts_with($arg, '--store-case=')) {
        $storeCase = substr($arg, 13);
    } elseif (str_starts_with($arg, '--changed-files=')) {
        $changedFiles = explode(',', substr($arg, 16));
    } elseif (str_starts_with($arg, '--fix-summary=')) {
        $fixSummary = substr($arg, 14);
    } elseif (str_starts_with($arg, '--ai-provider=')) {
        $aiProvider = substr($arg, 14);
    } elseif ($arg === '--reset-history') {
        $resetHistory = true;
    } elseif ($arg === '--report-card') {
        $reportCard = true;
    } elseif ($arg === '--coverage') {
        $coverage = true;
    } elseif ($arg === '--stats') {
        $showStats = true;
    } elseif ($arg === '--list-cases') {
        $listCases = true;
    } elseif ($moduleId === '') {
        $moduleId = $arg;
    } elseif ($actionId === '') {
        $actionId = $arg;
    }
}

if ($moduleId === '') {
    fwrite(STDERR, "Usage: php kernel/Workbench/Comprehension/run.php <module-id> [action-id] [options]\n");
    exit(1);
}

$base = dirname(__DIR__, 3);
require_once $base . '/bootstrap.php';
require_once $base . '/src/helpers/module-manager.php';

// Autoload comprehension classes
require_once __DIR__ . '/Contracts/ModuleComprehensionProvider.php';
require_once __DIR__ . '/Contracts/EntityContract.php';
require_once __DIR__ . '/Contracts/WorkflowContract.php';
require_once __DIR__ . '/Contracts/ActionContract.php';
require_once __DIR__ . '/Contracts/EffectContract.php';
require_once __DIR__ . '/Contracts/SupportContracts.php';
require_once __DIR__ . '/Contracts/AiContracts.php';
require_once __DIR__ . '/ModuleComprehensionEngine.php';
require_once __DIR__ . '/PalComprehensionProvider.php';
require_once __DIR__ . '/Analyzers/SemanticScorer.php';
require_once __DIR__ . '/Analyzers/EmbeddingScorer.php';
require_once __DIR__ . '/Analyzers/BayesianReasoner.php';
require_once __DIR__ . '/Analyzers/TemporalValidator.php';
require_once __DIR__ . '/Analyzers/PatternClassifier.php';
require_once __DIR__ . '/Analyzers/AnomalyDetector.php';
require_once __DIR__ . '/Analyzers/CrossModuleAnalyzer.php';
require_once __DIR__ . '/Analyzers/SourceRetriever.php';
require_once __DIR__ . '/Analyzers/AiHypothesisGenerator.php';
require_once __DIR__ . '/Analyzers/CaseMemory.php';
require_once __DIR__ . '/Analyzers/ProviderCoverageScorer.php';
require_once __DIR__ . '/SemanticComprehensionEngine.php';

use Ikabud\Kernel\Workbench\Comprehension\SemanticComprehensionEngine;
use Ikabud\Kernel\Workbench\Comprehension\PalComprehensionProvider;

echo "═══ Hybrid Semantic Comprehension Engine ═══\n";
echo "Engine version: 3.0 (Deterministic + NLP-Embedding + Bayesian + Temporal + Pattern + Cross-Module + AI Hypothesis + Provider Coverage)\n\n";

// ── 1. Load provider ──────────────────────────────────────────
$provider = match ($moduleId) {
    'project-audit-ledger' => new PalComprehensionProvider(),
    default => throw new RuntimeException("No comprehension provider for '{$moduleId}'"),
};

$engine = new SemanticComprehensionEngine(
    $moduleId,
    $provider,
    aiHypothesis: new \Ikabud\Kernel\Workbench\Comprehension\Analyzers\AiHypothesisGenerator(
        $moduleId,
        null,
        null,
        $aiProvider,
    ),
);

// Handle --reset-history
if ($resetHistory) {
    $engine->resetHistory($actionId ?: null);
    echo "  ✅ Bayesian history reset for " . ($actionId ?: "all actions in {$moduleId}") . "\n\n";
    if (!$evidenceFile && $actionId === '') {
        exit(0);
    }
}

// ── 2. List actions (NO analysis — graph only, no history recording) ──
$actionIds = $engine->actionIds();
echo "Module actions: " . implode(', ', $actionIds) . "\n\n";

// ── 3. Collect runtime evidence ─────────────────────────────
$evidence = [];
$evidenceMeta = [];

// Load evidence file if specified
if ($evidenceFile && is_file($evidenceFile)) {
    $fileEvidence = json_decode((string) file_get_contents($evidenceFile), true);
    if (is_array($fileEvidence)) {
        // Extract metadata if present (ActionObserver format)
        $evidenceMeta = $fileEvidence['_meta'] ?? [];

        // Detect format: flat (keys = step names) or structured (has steps/summary keys)
        if (isset($fileEvidence['steps']) && is_array($fileEvidence['steps'])) {
            // Structured ActionObserver format
            foreach ($fileEvidence['steps'] as $step) {
                $evidence[$step['step']] = $step['value'] ?? true;
            }
            if (isset($fileEvidence['summary']) && is_array($fileEvidence['summary'])) {
                foreach ($fileEvidence['summary'] as $step => $info) {
                    if (is_array($info) && isset($info['ok'])) {
                        $evidence[$step] = $info['ok'];
                    }
                }
            }
        } else {
            // Flat format — keys directly map to step names
            $evidence = $fileEvidence;
        }
        echo "Loaded evidence from: {$evidenceFile}\n";
    }
}

// Override entity context from CLI args (highest priority)
if ($tenantId !== null) $evidence['_tenant_id'] = $tenantId;
elseif (isset($evidenceMeta['tenant_id'])) $evidence['_tenant_id'] = $evidenceMeta['tenant_id'];
if ($entityId !== null) $evidence['_entity_id'] = $entityId;
elseif (isset($evidenceMeta['entity_id'])) $evidence['_entity_id'] = $evidenceMeta['entity_id'];
if ($entityType !== null) $evidence['_entity_type'] = $entityType;
elseif (isset($evidenceMeta['entity_type'])) $evidence['_entity_type'] = $evidenceMeta['entity_type'];
if ($runId !== null) $evidence['_run_id'] = $runId;
elseif (isset($evidenceMeta['run_id'])) $evidence['_run_id'] = $evidenceMeta['run_id'];

// Collect DB evidence if entity context is available
if (!empty($evidence['_tenant_id']) && !empty($evidence['_entity_id'])) {
    try {
        $_SERVER['HTTP_HOST'] = 'palsystem.test';
        $db = app()->dbForTenant((int)$evidence['_tenant_id']);

        if ($db) {
            $entityType = $evidence['_entity_type'] ?? 'pal.project';
            $entityTable = match ($entityType) {
                'pal.project' => 'pal_projects',
                default => str_replace('.', '_', $entityType),
            };

            // Try to fetch the specific entity
            $eid = (int)$evidence['_entity_id'];
            $s = $db->prepare("SELECT * FROM {$entityTable} WHERE id = ? AND tenant_id = ?");
            $s->execute([$eid, (int)$evidence['_tenant_id']]);
            $entity = $s->fetch(PDO::FETCH_ASSOC);

            if ($entity) {
                $evidence['db.entity_exists'] = true;
                $evidence['db.entity_status'] = $entity['status'] ?? null;

                // Check approvals
                $apprStmt = $db->prepare("SELECT COUNT(*) FROM pal_approvals WHERE entity_type = 'project' AND entity_id = ? AND tenant_id = ?");
                $apprStmt->execute([$eid, (int)$evidence['_tenant_id']]);
                $evidence['db.approval_exists'] = ((int)$apprStmt->fetchColumn()) > 0;

                echo "  DB probe: {$entityType}#{$eid} status={$entity['status']}\n";
            } else {
                echo "  DB probe: {$entityType}#{$eid} NOT FOUND\n";
            }
        }
    } catch (\Throwable $e) {
        echo "  ⚠ DB evidence: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// ── 4. Feed evidence and analyze ──────────────────────────────
$hasEvidence = !empty(array_diff_key($evidence, ['_tenant_id' => true, '_entity_id' => true, '_entity_type' => true, '_run_id' => true]));

// Build metadata for Bayesian history
$meta = [
    'run_id' => $evidence['_run_id'] ?? null,
    'tenant' => $evidence['_tenant_id'] ?? null,
    'source' => 'cli',
];
if ($hasEvidence) {
    // Try to get commit hash
    $commitFile = __DIR__ . '/../../.git/HEAD';
    if (file_exists($commitFile)) {
        $meta['commit'] = trim(file_get_contents($commitFile));
    }
}

$engine->feedEvidence($evidence);

// ── Handle quick commands (no analysis needed) ────────────────
if ($showStats) {
    $stats = $engine->caseMemoryStats();
    echo "  📊 Case Memory Statistics:\n";
    echo "    Total cases: {$stats['total_cases']}\n";
    echo "    Oldest: {$stats['oldest']}\n";
    echo "    Newest: {$stats['newest']}\n";
    if (!empty($stats['modules'])) {
        echo "    By module:\n";
        foreach ($stats['modules'] as $mod => $count) {
            echo "      {$mod}: {$count} case(s)\n";
        }
    }
    echo "\n";
}

if ($listCases) {
    $cases = $engine->listCases();
    if (empty($cases)) {
        echo "  No stored cases for module '{$moduleId}'.\n";
    } else {
        echo "  📚 Stored Cases for '{$moduleId}':\n";
        foreach ($cases as $c) {
            echo "    [{$c['id']}] {$c['summary']} ({$c['created_at']})\n";
            echo "      Action: {$c['action_id']}\n";
        }
    }
    echo "\n";
}

if ($coverage) {
    $coverageResult = $engine->scoreCoverage();
    if ($coverageResult) {
        echo "  📋 Provider Coverage Score: {$coverageResult['overall_score']}\n";
        foreach ($coverageResult['dimensions'] as $dim => $info) {
            $icon = $info['score'] >= 0.8 ? '✅' : ($info['score'] >= 0.5 ? '⚠️' : '❌');
            echo "    {$icon} {$dim}: {$info['score']} — {$info['details']}\n";
        }
        if (!empty($coverageResult['suggestions'])) {
            echo "  💡 Suggestions:\n";
            foreach ($coverageResult['suggestions'] as $s) {
                echo "    - {$s}\n";
            }
        }
    }
    echo "\n";
}

// Abort here if we're only doing stats/list/coverage without evidence
if ($showStats || $listCases || $coverage) {
    if (!$evidenceFile && $actionId === '') {
        exit(0);
    }
}

if ($actionId !== '') {
    // Only record history when analyzing a real test run with evidence
    $result = $engine->analyze($actionId, recordHistory: $hasEvidence, metadata: $meta);
    echo "Action analysis: {$actionId}\n";
    echo "Engine: {$result['engine_version']}\n";
    if (isset($result['deterministic']['error'])) {
        echo "  ERROR: {$result['deterministic']['error']}\n";
    } else {
        $bp = $result['breakpoint'] ?? null;
        echo "  Breakpoint: " . ($bp ?: 'none — chain intact') . "\n";
        echo "  Break category: {$result['break_category']}\n";
        echo "  Root cause: {$result['root_cause_hypothesis']['summary']}\n";
        echo "  Confidence: {$result['confidence']['score']} ({$result['confidence']['label']})\n";
        echo "  Diagnosis: {$result['diagnosis']['primary_classification']['category']} ({$result['diagnosis']['primary_classification']['confidence']})\n";

        echo "\n  Deterministic chain:\n";
        foreach ($result['deterministic']['chain'] as $link) {
            $icon = $link['ok'] ? '✅' : '❌';
            echo "    {$icon} [{$link['category']}] {$link['step']}: {$link['description']}\n";
        }

        echo "\n  NLP-Enhanced semantic scores (embedding + TF-IDF + n-gram):\n";
        foreach ($result['semantic']['embedding_scores'] as $step => $score) {
            $comp = $score['components'] ?? [];
            echo "    {$step}: score={$score['score']} (regex={$comp['regex']}, tfidf={$comp['tfidf']}, ngram={$comp['ngram']}, vector={$comp['vector']}, hist={$comp['historical']})\n";
        }

        echo "\n  Bayesian priors:\n";
        foreach ($result['bayesian']['per_link'] as $step => $stats) {
            echo "    {$step}: prior_failure={$stats['prior_failure_probability']} prior_success={$stats['prior_success_probability']}\n";
        }

        $orderScore = $result['temporal']['order_score'] ?? 1.0;
        echo "\n  Temporal order score: {$orderScore}\n";
        if (!empty($result['temporal']['violations'])) {
            echo "  Temporal violations:\n";
            foreach ($result['temporal']['violations'] as $v) {
                echo "    ⚠ [{$v['severity']}] {$v['description']}\n";
            }
        }

        if (!empty($result['anomalies']['unexpected_evidence'])) {
            echo "\n  Anomalies:\n";
            foreach ($result['anomalies']['unexpected_evidence'] as $a) {
                echo "    ⚡ [{$a['severity']}] {$a['reason']}\n";
            }
        }

        if (!empty($result['anomalies']['missing_links'])) {
            echo "\n  Missing link suggestions:\n";
            foreach ($result['anomalies']['missing_links'] as $ml) {
                echo "    💡 Suggested step: '{$ml['step_suggestion']}' — {$ml['reason']}\n";
            }
        }

        if ($result['cross_module']['cross_module']) {
            echo "\n  Cross-module cascade:\n";
            foreach ($result['cross_module']['cascade'] as $c) {
                echo "    🔗 [{$c['severity']}] {$c['description']}\n";
            }
        }

        // ── Layer 7: AI Hypothesis ──────────────────────────────
        $aiHyp = $result['ai_hypothesis'] ?? [];
        if (!empty($aiHyp)) {
            echo "\n  ── Layer 7: AI Hypothesis ──\n";
            echo "  🧠 Summary: {$aiHyp['summary']}\n";
            echo "  Confidence: {$aiHyp['confidence']}\n";
            echo "  Severity: {$aiHyp['severity']}\n";
            if (!empty($aiHyp['files_to_inspect'])) {
                echo "  📁 Files to inspect:\n";
                foreach ($aiHyp['files_to_inspect'] as $f) {
                    echo "    - {$f}\n";
                }
            }
            if ($aiHyp['proposed_test']) {
                echo "  🧪 Proposed test: {$aiHyp['proposed_test']}\n";
            }
            if (!empty($aiHyp['do_not_change_boundary'])) {
                echo "  🚫 Do not change:\n";
                foreach ($aiHyp['do_not_change_boundary'] as $b) {
                    echo "    - {$b}\n";
                }
            }
            if (!empty($aiHyp['suggested_links'])) {
                echo "  💡 Suggested chain links to add:\n";
                foreach ($aiHyp['suggested_links'] as $sl) {
                    echo "    - {$sl['step']}: {$sl['description']}\n";
                }
            }
        }

        // ── Remediation Plan ────────────────────────────────────
        $remediation = $result['remediation_plan'] ?? null;
        if ($remediation !== null) {
            echo "\n  ── Remediation Plan ──\n";
            echo "  🔧 Failing step: {$remediation['failing_step']}\n";
            echo "  📄 Suspected file: {$remediation['suspected_file']}\n";
            echo "  ⚠ Invariant: {$remediation['invariant_violated']}\n";
            echo "  ✏ Fix sketch: {$remediation['fix_sketch']}\n";
            echo "  🧪 Test: {$remediation['test_command']}\n";
            echo "  📊 Risk: {$remediation['risk_level']}\n";
            if (!empty($remediation['related_files'])) {
                echo "  🔗 Related files:\n";
                foreach ($remediation['related_files'] as $rf) {
                    echo "    - {$rf}\n";
                }
            }
        }

        // ── Source Context ──────────────────────────────────────
        $srcCtx = $result['source_context'] ?? null;
        if ($srcCtx !== null) {
            echo "\n  ── Source Context (Layer 7) ──\n";
            echo "  Step: {$srcCtx['step']} ({$srcCtx['category']})\n";
            if (!empty($srcCtx['handler_files'])) {
                echo "  Handlers:\n";
                foreach ($srcCtx['handler_files'] as $hf) {
                    echo "    - {$hf}\n";
                }
            }
            if (!empty($srcCtx['template_files'])) {
                echo "  Templates:\n";
                foreach ($srcCtx['template_files'] as $tf) {
                    echo "    - {$tf}\n";
                }
            }
        }

        // ── Proposed Chain Links ───────────────────────────────
        $proposedLinks = $result['proposed_chain_links'] ?? [];
        if (!empty($proposedLinks)) {
            echo "\n  ── AI-Proposed Chain Links ──\n";
            foreach ($proposedLinks as $pl) {
                echo "  ➕ {$pl['step']}: {$pl['description']} [{$pl['category']}]\n";
            }
        }

        // ── Coverage Score ─────────────────────────────────────
        $cov = $result['coverage_score'] ?? null;
        if ($cov !== null) {
            echo "\n  ── Layer 8: Provider Coverage ──\n";
            echo "  Overall score: {$cov['overall_score']}\n";
            foreach ($cov['suggestions'] as $sug) {
                echo "  💡 {$sug}\n";
            }
        }

        // ── Report Card (if requested) ─────────────────────────
        if ($reportCard) {
            echo "\n  ── Report Card ──\n";
            $reportCardData = $engine->generateReportCard($actionId, $result);
            $rc = $reportCardData['root_cause'] ?? [];
            echo "  🎯 Root cause: {$rc['summary']}\n";
            echo "  Severity: {$rc['severity']}\n\n";
            echo "  Failing chain timeline:\n";
            foreach ($reportCardData['failing_causal_chain'] as $fc) {
                $icon = $fc['status'] === 'passed' ? '✅' : '❌';
                echo "    {$icon} [{$fc['category']}] {$fc['step']}: {$fc['description']}\n";
            }
            echo "\n  📁 Inspect files:\n";
            foreach ($reportCardData['inspect_these_files'] as $f) {
                echo "    - {$f}\n";
            }
            $rem = $reportCardData['remediation'] ?? null;
            if ($rem) {
                echo "\n  🔧 Suggested next test: {$rem['test_command']}\n";
            }
        }
    }
} else {
    $results = $engine->analyzeAll(recordHistory: $hasEvidence);
    foreach ($results as $aid => $r) {
        $bp = $r['breakpoint'] ?? 'none';
        $conf = $r['confidence']['score'] ?? 0;
        $diag = $r['diagnosis']['primary_classification']['category'] ?? '?';
        $aiSummary = $r['ai_hypothesis']['summary'] ?? '';
        $summary = $aiSummary ? " ({$aiSummary})" : '';
        echo "  {$aid}: breakpoint={$bp}, diagnosis={$diag}, confidence={$conf}{$summary}\n";
    }
}

echo "\n";

// ── Store case memory (if requested) ──────────────────────────
if ($storeCase !== null && $actionId !== '') {
    $caseId = $engine->storeCaseMemory(
        actionId: $actionId,
        summary: $storeCase,
        changedFiles: $changedFiles,
        fixSummary: $fixSummary,
        testCommand: "php {$argv[0]} {$moduleId} {$actionId} --tenant=" . ($tenantId ?? '{tenant}'),
        tags: [$moduleId, $actionId],
    );
    echo "  ✅ Case stored: {$caseId}\n\n";
}

// ── 5. Output evidence packet ─────────────────────────────────
$packet = $engine->buildEvidencePacket(
    actionId: $actionId ?: 'all',
    analysis: $result ?? null,
);

$outDir = $base . '/test_results/ai';

// Use run-scoped output when a run_id is available
$runId = $evidence['_run_id'] ?? $runId ?? null;
if ($runId) {
    $outDir = $outDir . '/runs/' . preg_replace('/[^a-zA-Z0-9._-]/', '', $runId);
}

if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$outFile = $outDir . '/comprehension-report.json';
file_put_contents(
    $outFile,
    json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
echo "📄 Evidence packet: {$outFile}\n";
echo "═══ Done ═══\n";
