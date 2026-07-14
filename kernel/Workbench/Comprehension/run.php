<?php

declare(strict_types=1);

/**
 * Workbench Comprehension Runner — CLI entry point.
 *
 * Runs the hybrid semantic comprehension engine against a module,
 * collecting runtime evidence, applying all 6 reasoning layers,
 * and outputting a comprehensive report.
 *
 * Usage:
 *   php kernel/Workbench/Comprehension/run.php <module-id> [action-id] [options]
 *
 * Options:
 *   --evidence=file.json     Evidence file from ActionObserver
 *   --tenant=N               Tenant ID (default: 502)
 *   --entity-type=string     Entity type (e.g. pal.project)
 *   --entity-id=N            Entity ID
 *   --run-id=string          Test run ID for history tracking
 *   --reset-history          Clear Bayesian history for this module/action
 *
 * Examples:
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger pal.job-order.submit
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger --evidence=test_results/evidence/pal-submit.json
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger pal.job-order.submit --reset-history
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
    } elseif ($arg === '--reset-history') {
        $resetHistory = true;
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
require_once __DIR__ . '/ModuleComprehensionEngine.php';
require_once __DIR__ . '/PalComprehensionProvider.php';
require_once __DIR__ . '/Analyzers/SemanticScorer.php';
require_once __DIR__ . '/Analyzers/BayesianReasoner.php';
require_once __DIR__ . '/Analyzers/TemporalValidator.php';
require_once __DIR__ . '/Analyzers/PatternClassifier.php';
require_once __DIR__ . '/Analyzers/AnomalyDetector.php';
require_once __DIR__ . '/Analyzers/CrossModuleAnalyzer.php';
require_once __DIR__ . '/SemanticComprehensionEngine.php';

use Ikabud\Kernel\Workbench\Comprehension\SemanticComprehensionEngine;
use Ikabud\Kernel\Workbench\Comprehension\PalComprehensionProvider;

echo "═══ Hybrid Semantic Comprehension Engine ═══\n";
echo "Engine version: 2.0 (Deterministic + Bayesian + Semantic + Temporal + Pattern + Cross-Module)\n\n";

// ── 1. Load provider ──────────────────────────────────────────
$provider = match ($moduleId) {
    'project-audit-ledger' => new PalComprehensionProvider(),
    default => throw new RuntimeException("No comprehension provider for '{$moduleId}'"),
};

$engine = new SemanticComprehensionEngine($moduleId, $provider);

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

// Collect DB evidence
try {
    $_SERVER['HTTP_HOST'] = 'palsystem.test';
    $tenantId = 502;
    $db = app()->dbForTenant($tenantId);

    if ($db) {
        // Find the latest project
        $projStmt = $db->query("SELECT id, status, project_id, title FROM pal_projects WHERE tenant_id = {$tenantId} ORDER BY id DESC LIMIT 1");
        $project = $projStmt->fetch(PDO::FETCH_ASSOC);

        if ($project) {
            $pid = (int)$project['id'];
            $evidence['db.project_exists'] = true;
            $evidence['db.project_status'] = $project['status'];
            $evidence['db.project_id'] = $pid;

            // Check approvals for this project
            $apprStmt = $db->prepare("SELECT COUNT(*) FROM pal_approvals WHERE entity_type = 'project' AND entity_id = ? AND tenant_id = ?");
            $apprStmt->execute([$pid, $tenantId]);
            $evidence['db.approval_exists'] = ((int)$apprStmt->fetchColumn()) > 0;

            // Check audit logs
            $auditStmt = $db->prepare("SELECT COUNT(*) FROM pal_audit_logs WHERE tenant_id = ? AND entity_id = ? AND action LIKE 'pal.project%'");
            $auditStmt->execute([$tenantId, (string)$pid]);
            $evidence['db.audit_exists'] = ((int)$auditStmt->fetchColumn()) > 0;

            echo "Runtime evidence from DB:\n";
            echo "  Project #{$pid}: status={$project['status']}\n";
            echo "  Approval exists: " . ($evidence['db.approval_exists'] ? 'YES' : 'NO') . "\n";
            echo "  Audit log exists: " . ($evidence['db.audit_exists'] ? 'YES' : 'NO') . "\n";
        } else {
            echo "No projects found for tenant {$tenantId}.\n";
            $evidence['db.project_exists'] = false;
        }
    }
} catch (\Throwable $e) {
    echo "  ⚠ DB evidence collection failed: " . $e->getMessage() . "\n";
    $evidence['db.error'] = $e->getMessage();
}

echo "\n";

// ── 4. Feed evidence and analyze ──────────────────────────────
$hasEvidence = !empty(array_diff_key($evidence, ['_tenant_id' => true, '_entity_id' => true, '_entity_type' => true, '_run_id' => true]));
$engine->feedEvidence($evidence);

if ($actionId !== '') {
    // Only record history when analyzing a real test run with evidence
    $result = $engine->analyze($actionId, recordHistory: $hasEvidence);
    echo "Action analysis: {$actionId}\n";
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

        echo "\n  Semantic scores:\n";
        foreach ($result['semantic']['per_link_scores'] as $step => $score) {
            echo "    {$step}: score={$score['score']} pattern={$score['matched_pattern']}\n";
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
    }
} else {
    $results = $engine->analyzeAll(recordHistory: $hasEvidence);
    foreach ($results as $aid => $r) {
        $bp = $r['breakpoint'] ?? 'none';
        $conf = $r['confidence']['score'] ?? 0;
        $diag = $r['diagnosis']['primary_classification']['category'] ?? '?';
        echo "  {$aid}: breakpoint={$bp}, diagnosis={$diag}, confidence={$conf}\n";
    }
}

echo "\n";

// ── 5. Output evidence packet ─────────────────────────────────
$packet = $engine->buildEvidencePacket($actionId ?: 'all');

$outDir = $base . '/test_results/ai';
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
