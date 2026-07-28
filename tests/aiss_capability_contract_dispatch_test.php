<?php
declare(strict_types=1);

/**
 * AISS Capability Contract Dispatch Test
 *
 * Verifies all 13 declared AISS capabilities are dispatchable through
 * the capability bus. Catches the regression where 4 capabilities had
 * handler implementations but were missing from the handler map.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/academic_similarity/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $errors;
    if ($ok) { $pass++; echo "  \033[32m✓\033[0m {$label}\n"; return; }
    $fail++; $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  \033[31m✗\033[0m {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== AISS Capability Contract Dispatch ===\n\n";

// ── Verify handler registration completeness ─────────────────────
echo "── 1. Handler Map Completeness ──\n";

$handlers = academic_similarity_capability_handlers();
$handlerCount = count($handlers);
t('13 handlers registered', $handlerCount === 13, "got {$handlerCount}");

// Read declared capabilities from module.json
$moduleJson = json_decode(file_get_contents(__DIR__ . '/../modules/academic_similarity/module.json'), true);
$declared = array_column($moduleJson['capabilities']['exposes'] ?? [], 'id');
$declaredCount = count($declared);
t('13 capabilities declared in module.json', $declaredCount === 13, "got {$declaredCount}");

// Every declared capability must have a handler
$unregistered = array_diff($declared, array_keys($handlers));
t('all declared capabilities have handlers', empty($unregistered), !empty($unregistered) ? 'missing: ' . implode(', ', $unregistered) : '');

// Every registered handler must have a declaration
$undeclared = array_diff(array_keys($handlers), $declared);
t('all registered handlers have declarations', empty($undeclared), !empty($undeclared) ? 'extra: ' . implode(', ', $undeclared) : '');

// ── Verify handler callables ─────────────────────────────────────
echo "\n── 2. Handler Callables ──\n";

foreach ($handlers as $capId => $handlerFunc) {
    $callable = is_callable($handlerFunc);
    t("{$capId} → {$handlerFunc}() is callable", $callable);
}

// ── Verify the 4 previously missing handlers ─────────────────────
echo "\n── 3. Previously Missing Handlers ──\n";

$previouslyMissing = [
    'academic_similarity.citation.analyze@1',
    'academic_similarity.scholarship.profile@1',
    'academic_similarity.lineage.graph@1',
    'academic_similarity.review.workflow.action@1',
];

foreach ($previouslyMissing as $capId) {
    $registered = isset($handlers[$capId]);
    t("{$capId} is registered", $registered);
    if ($registered) {
        t("{$capId} handler is callable", is_callable($handlers[$capId]));
    }
}

// ── Log check ────────────────────────────────────────────────────
echo "\n── Logs ──\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$criticals = array_filter(explode("\n", $appLog), fn($l) => $l !== '' && (stripos($l, '[critical]') !== false || stripos($l, 'PHP Fatal') !== false));
t('no critical errors', count($criticals) === 0, count($criticals) . ' found');

$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
$errLines = array_filter(explode("\n", $errLog), fn($l) => trim($l) !== '');
t('error.log clean', count($errLines) === 0, count($errLines) . ' lines');

echo "\n" . str_repeat('─', 50) . "\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
if ($errors !== []) { echo "\nFailed:\n"; foreach ($errors as $e) { echo "  • {$e}\n"; } }
exit($fail > 0 ? 1 : 0);
