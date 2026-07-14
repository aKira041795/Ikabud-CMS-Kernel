<?php

declare(strict_types=1);

/**
 * Workbench Comprehension Runner — CLI entry point.
 *
 * Runs the hybrid semantic comprehension engine against a module,
 * collecting runtime evidence, applying all 6 reasoning layers,
 * and outputting a comprehensive report.
 *
 * Usage: php kernel/Workbench/Comprehension/run.php <module-id> [action-id] [--evidence=file.json]
 *
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger pal.job-order.submit
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger --evidence=test-results/evidence.json
 *
 * Output: test_results/ai/comprehension-report.json
 */

if (php_sapi_name() !== 'cli') {
    echo "CLI only.\n"; exit(1);
}

$moduleId = $argv[1] ?? '';
$actionId = $argv[2] ?? '';

// Check if second arg is --evidence flag
if (str_starts_with($actionId, '--')) {
    $actionId = '';
}

if ($moduleId === '') {
    fwrite(STDERR, "Usage: php kernel/Workbench/Comprehension/run.php <module-id> [action-id] [--evidence=file.json]\n");
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

// ── 2. Build knowledge graph ──────────────────────────────────
$graph = $engine->analyzeAll();
if (!empty($graph)) {
    $firstAction = reset($graph);
    $kg = $firstAction['deterministic'] ?? [];
} else {
    $kg = [];
}

echo "Module knowledge graph:\n";
$actions = $graph;
echo "  Actions: " . count($actions) . "\n";
echo "\n";

// ── 3. Collect runtime evidence ─────────────────────────────
$evidence = [];

// Load evidence file if specified
$evidenceFile = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--evidence=')) {
        $evidenceFile = substr($arg, 11);
    }
}

if ($evidenceFile && is_file($evidenceFile)) {
    $fileEvidence = json_decode((string) file_get_contents($evidenceFile), true);
    if (is_array($fileEvidence)) {
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
$engine->feedEvidence($evidence);

if ($actionId !== '') {
    $result = $engine->analyze($actionId);
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
    $results = $engine->analyzeAll();
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
