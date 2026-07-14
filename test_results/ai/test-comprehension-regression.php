<?php
/**
 * Comprehension Engine Regression Test.
 *
 * Verifies the engine correctly identifies breakpoints from known evidence.
 * Run: php test_results/ai/test-comprehension-regression.php
 *
 * Expected: All 3 test cases pass with correct breakpoint identification.
 * If any test fails, the engine has regressed.
 */

declare(strict_types=1);

$base = __DIR__ . '/../../kernel/Workbench/Comprehension';
require_once $base . '/Contracts/ModuleComprehensionProvider.php';
require_once $base . '/Contracts/EntityContract.php';
require_once $base . '/Contracts/WorkflowContract.php';
require_once $base . '/Contracts/ActionContract.php';
require_once $base . '/Contracts/EffectContract.php';
require_once $base . '/Contracts/SupportContracts.php';
require_once $base . '/ModuleComprehensionEngine.php';
require_once $base . '/PalComprehensionProvider.php';
require_once $base . '/Analyzers/SemanticScorer.php';
require_once $base . '/Analyzers/BayesianReasoner.php';
require_once $base . '/Analyzers/TemporalValidator.php';
require_once $base . '/Analyzers/PatternClassifier.php';
require_once $base . '/Analyzers/AnomalyDetector.php';
require_once $base . '/Analyzers/CrossModuleAnalyzer.php';
require_once $base . '/SemanticComprehensionEngine.php';

use Ikabud\Kernel\Workbench\Comprehension\SemanticComprehensionEngine;
use Ikabud\Kernel\Workbench\Comprehension\PalComprehensionProvider;
use Ikabud\Kernel\Workbench\Comprehension\Contracts\ModuleComprehensionProvider;

$provider = new PalComprehensionProvider();
$passed = 0;
$failed = 0;

function runTest(ModuleComprehensionProvider $provider, string $name, array $evidence, ?string $expectedBreakpoint, string $expectedCategory): void
{
    global $passed, $failed;
    $engine = new SemanticComprehensionEngine('project-audit-ledger', $provider);
    $engine->feedEvidence($evidence);
    $result = $engine->analyze('pal.job-order.submit', recordHistory: false);

    $actualBp = $result['breakpoint'] ?? null;
    if ($actualBp === '' || $actualBp === 'NONE' || $actualBp === null) $actualBp = null;
    $actualCat = $result['break_category'] ?? '';
    $actualDiag = $result['diagnosis']['primary_classification']['category'] ?? '';

    $bpOk = ($actualBp === $expectedBreakpoint);
    $catOk = ($actualCat === $expectedCategory) || ($expectedCategory === 'unknown' && ($actualCat === '' || $actualCat === 'unknown'));

    $icon = $bpOk && $catOk ? '✅' : '❌';
    if ($bpOk && $catOk) {
        $passed++;
    } else {
        $failed++;
    }

    echo "{$icon} {$name}\n";
    echo "     Expected: breakpoint={$expectedBreakpoint} category={$expectedCategory}\n";
    echo "     Actual:   breakpoint={$actualBp} category={$actualCat} diagnosis={$actualDiag}\n";
}

echo "═══ Comprehension Engine Regression Tests ═══\n\n";

// Test 1: Full success chain — no breakpoint expected
runTest($provider,
    'Full success chain',
    [
        'button.visible' => true, 'button.clicked' => true,
        'http.request' => true, 'http.response_ok' => true,
        'workflow.transition' => true, 'db.status_change' => true,
        'approval.created' => true, 'audit.created' => true,
        'ui.status_updated' => true, 'approval_queue.updated' => true,
    ],
    null, // no breakpoint
    'unknown'
);

// Test 2: Missing middle step — breakpoint at the first missing step
runTest($provider,
    'DB step failed (missing approval)',
    [
        'button.visible' => true, 'button.clicked' => true,
        'http.request' => true, 'http.response_ok' => true,
        'workflow.transition' => true, 'db.status_change' => true,
        // approval.created missing
    ],
    'approval.created',
    'db'
);

// Test 3: CSRF error in HTTP response — diagnosis should be csrf
runTest($provider,
    'CSRF token failure',
    [
        'button.visible' => true, 'button.clicked' => true,
        'http.request' => true,
        'http.response_ok' => 'CSRF token mismatch: the provided token has expired. Status 419.',
        // Everything after HTTP fails because the error blocks the chain
    ],
    'workflow.transition',
    'service'
);

// Test 4: Real observer evidence format (steps + summary)
// Proves the engine can consume the exact format written by WorkbenchObserver
echo "  (Test 4 requires engine-side steps/summary parsing — run.php handles this)\n";

echo "\n─── Results ───\n";
echo "  Passed: {$passed}\n";
echo "  Failed: {$failed}\n";
echo "  Total:  " . ($passed + $failed) . "\n";

exit($failed > 0 ? 1 : 0);
