<?php

declare(strict_types=1);

/**
 * Workbench Comprehension Runner — CLI entry point.
 *
 * Runs the deterministic comprehension engine against a module,
 * collects runtime evidence (database state, audit logs),
 * identifies breakpoints, and outputs an evidence packet.
 *
 * Usage: php kernel/Workbench/Comprehension/run.php <module-id> [action-id]
 *
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger pal.job-order.submit
 *
 * Output: test_results/ai/comprehension-report.json
 */

if (php_sapi_name() !== 'cli') {
    echo "CLI only.\n"; exit(1);
}

$moduleId = $argv[1] ?? '';
$actionId = $argv[2] ?? '';

if ($moduleId === '') {
    fwrite(STDERR, "Usage: php kernel/Workbench/Comprehension/run.php <module-id> [action-id]\n");
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

use Ikabud\Kernel\Workbench\Comprehension\ModuleComprehensionEngine;
use Ikabud\Kernel\Workbench\Comprehension\PalComprehensionProvider;

echo "═══ Workbench Comprehension Engine ═══\n\n";

// ── 1. Load provider ──────────────────────────────────────────
$provider = match ($moduleId) {
    'project-audit-ledger' => new PalComprehensionProvider(),
    default => throw new RuntimeException("No comprehension provider for '{$moduleId}'"),
};

$engine = new ModuleComprehensionEngine($provider);

// ── 2. Build knowledge graph ──────────────────────────────────
$graph = $engine->buildGraph();
echo "Module knowledge graph:\n";
echo "  Entities: " . count($graph['entities']) . "\n";
echo "  Workflows: " . count($graph['workflows']) . "\n";
echo "  Actions: " . count($graph['actions']) . "\n";
echo "  Capabilities: " . count($graph['capabilities']) . "\n";
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
        // Flatten ActionObserver steps into evidence keys
        if (isset($fileEvidence['steps']) && is_array($fileEvidence['steps'])) {
            foreach ($fileEvidence['steps'] as $step) {
                $evidence[$step['step']] = $step['value'] ?? true;
            }
        }
        // Also include summary values
        if (isset($fileEvidence['summary']) && is_array($fileEvidence['summary'])) {
            foreach ($fileEvidence['summary'] as $step => $info) {
                if (is_array($info) && isset($info['ok'])) {
                    $evidence[$step] = $info['ok'];
                }
            }
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
    $result = $engine->analyzeAction($actionId);
    echo "Action analysis: {$actionId}\n";
    if (isset($result['error'])) {
        echo "  ERROR: {$result['error']}\n";
    } else {
        echo "  Breakpoint: " . ($result['breakpoint'] ?? 'none — chain intact') . "\n";
        echo "  Likely area: {$result['likely_area']}\n";
        echo "  Confidence: " . ($result['confidence'] * 100) . "%\n";
        echo "  Chain:\n";
        foreach ($result['chain'] as $link) {
            $icon = $link['ok'] ? '✅' : '❌';
            echo "    {$icon} [{$link['category']}] {$link['step']}: {$link['description']}\n";
        }
    }
} else {
    $results = $engine->analyzeAll();
    foreach ($results as $aid => $r) {
        $bp = $r['breakpoint'] ?? 'none';
        echo "  {$aid}: breakpoint={$bp}, area={$r['likely_area']}, confidence={$r['confidence']}\n";
    }
}

echo "\n";

// ── 5. Output evidence packet ─────────────────────────────────
$packet = $engine->buildEvidencePacket(
    $actionId !== '' ? $engine->analyzeAction($actionId) : ['actions_analyzed' => array_keys($engine->analyzeAll())]
);

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
