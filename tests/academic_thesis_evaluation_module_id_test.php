<?php
declare(strict_types=1);

/**
 * Academic Thesis Evaluation — module identity reconciliation (016).
 *
 * Covers: the manifest id now passes kernel validation under the hyphenated id
 * 'academic-thesis-evaluation', discovery keys the module under that id, routes
 * and capability caller identity use the hyphenated id, capability IDs remain
 * stable, and migration 016 reconciles tenant settings idempotently.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../src/helpers/module-registry.php';
require_once __DIR__ . '/../modules/academic_thesis_evaluation/helpers.php';

$pass = 0;
$fail = 0;
function t(string $description, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  \033[32m✓\033[0m {$description}\n"; }
    else { $fail++; echo "  \033[31m✗\033[0m {$description}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

$modulePath = __DIR__ . '/../modules/academic_thesis_evaluation';

echo "\n=== Academic Thesis Evaluation — Module Identity ===\n";

// ── 1. Manifest validates under the hyphenated id ────────────────
$check = validateModuleManifest($modulePath . '/module.json');
t('manifest validates', !empty($check['ok']), (string)($check['error'] ?? ''));
t('manifest id is hyphenated', ($check['manifest']['id'] ?? '') === 'academic-thesis-evaluation', (string)($check['manifest']['id'] ?? ''));

// ── 2. Discovery keys the module under the hyphenated id ─────────
$modules = discoverModules();
t('module discovered under hyphenated id', isset($modules['academic-thesis-evaluation']));
t('module not discovered under underscore id', !isset($modules['academic_thesis_evaluation']));
t('modulePathForId resolves', modulePathForId('academic-thesis-evaluation') === realpath($modulePath));

// ── 3. Routes use the hyphenated handler prefix ──────────────────
$routes = require $modulePath . '/routes.php';
$routeValues = array_merge($routes['GET'] ?? [], $routes['POST'] ?? []);
$badPrefixes = array_filter($routeValues, static fn(string $h): bool => str_starts_with($h, 'academic_thesis_evaluation:'));
t('no route uses the underscore module prefix', count($badPrefixes) === 0, 'found ' . count($badPrefixes));
$sampleOk = in_array('academic-thesis-evaluation:pageEvidenceReview', $routeValues, true);
t('evidence route uses hyphenated prefix', $sampleOk);

// ── 4. Capability handler map matches manifest exposes ───────────
$manifest = json_decode((string)file_get_contents($modulePath . '/module.json'), true);
$exposed = array_column($manifest['capabilities']['exposes'] ?? [], 'id');
$handlers = academic_thesis_evaluation_capability_handlers();
$missing = array_diff($exposed, array_keys($handlers));
t('every exposed capability has a handler', count($missing) === 0, json_encode(array_values($missing)));
// Capability IDs are preserved (underscore) per the migration contract.
t('capability ids preserved (underscore)', isset($handlers['academic_thesis_evaluation.case.create@1']) && in_array('academic_thesis_evaluation.case.create@1', $exposed, true));

// ── 5. Capability caller identity is hyphenated ──────────────────
$adapterSrc = (string)file_get_contents($modulePath . '/src/Services/AcademicThesisAissAdapter.php');
t('adapter caller_module is hyphenated', str_contains($adapterSrc, "'caller_module' => 'academic-thesis-evaluation'"));
t('adapter does not use underscore caller', !str_contains($adapterSrc, "'caller_module' => 'academic_thesis_evaluation'"));

// ── 6. Migration 016 reconciles tenant settings idempotently ─────
$resolved = 0;
try {
    $stmt = app()->db()->prepare(
        "SELECT t.id FROM kernel_tenants t LEFT JOIN kernel_tenant_domains d ON d.tenant_id = t.id WHERE t.tenant_key = :key OR d.domain = :key LIMIT 1"
    );
    $stmt->execute([':key' => 'aiss.test']);
    $resolved = (int)($stmt->fetchColumn() ?: 0);
} catch (\Throwable $ignored) {
}
if ($resolved <= 0) { $resolved = 582; }
$pdo = app()->dbForTenant($resolved);
// Seed a legacy underscore-id setting row
$pdo->prepare("DELETE FROM tenant_module_settings WHERE module_id IN ('academic_thesis_evaluation','academic-thesis-evaluation')")->execute();
$pdo->prepare("INSERT INTO tenant_module_settings (tenant_id, module_id, setting_key, setting_value, created_at, updated_at) VALUES (:tid, 'academic_thesis_evaluation', '_module_enabled', '1', NOW(), NOW())")
    ->execute([':tid' => $resolved]);

$sql = (string)file_get_contents($modulePath . '/migrations/016_ate_module_id_reconcile.sql');
try {
    $pdo->exec($sql);
    t('migration 016 executes cleanly', true);
} catch (\Throwable $e) {
    t('migration 016 executes cleanly', false, $e->getMessage());
}
$underscoreCount = (int)$pdo->query("SELECT COUNT(*) FROM tenant_module_settings WHERE module_id = 'academic_thesis_evaluation'")->fetchColumn();
$hyphenCount = (int)$pdo->query("SELECT COUNT(*) FROM tenant_module_settings WHERE module_id = 'academic-thesis-evaluation'")->fetchColumn();
t('legacy underscore setting row removed', $underscoreCount === 0, "remaining={$underscoreCount}");
t('setting migrated to hyphenated id', $hyphenCount === 1, "count={$hyphenCount}");

// Idempotent re-run
try {
    $pdo->exec($sql);
    t('migration 016 re-run is idempotent', true);
} catch (\Throwable $e) {
    t('migration 016 re-run is idempotent', false, $e->getMessage());
}
$hyphenCount2 = (int)$pdo->query("SELECT COUNT(*) FROM tenant_module_settings WHERE module_id = 'academic-thesis-evaluation'")->fetchColumn();
t('no duplicate rows after re-run', $hyphenCount2 === 1, "count={$hyphenCount2}");

// Cleanup
$pdo->prepare("DELETE FROM tenant_module_settings WHERE module_id IN ('academic_thesis_evaluation','academic-thesis-evaluation')")->execute();

echo "\n──────────────────────────────────────────────────\n";
echo '  PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
if ($fail > 0) {
    exit(1);
}
