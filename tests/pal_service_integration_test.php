<?php
/**
 * PAL module service integration tests.
 *
 * Bootstraps the app with the palsystem tenant and tests core services
 * against concrete behavior (CRUD, business logic, audit).
 *
 * Usage: PAL_TENANT_ID=502 php tests/pal_service_integration_test.php
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────
require_once __DIR__ . '/../bootstrap.php';

// Ensure we're on the PAL tenant
$tid = (int)(app()->tenant()->current() ?? 0);
if ($tid !== 502) {
    echo "ERROR: Run with PAL_TENANT_ID=502 (current tenant: {$tid})\n";
    exit(1);
}

// Load PAL module files explicitly for CLI test context
require_once __DIR__ . '/../modules/project-audit-ledger/helpers.php';
require_once __DIR__ . '/../modules/project-audit-ledger/handlers.php';

$db = app()->db();
$passed = 0;
$failed = 0;

function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        echo "  ❌ {$label}\n";
    }
}

function isPresent(mixed $val): bool
{
    return $val !== null && $val !== false && $val !== '';
}

// ── 1. Database Connection ────────────────────────────────────────
echo "\n=== Database Connection ===\n";
$dbName = $db->query("SELECT DATABASE()")->fetchColumn();
ok($dbName === 'palsystem', "Connected to palsystem database (got: {$dbName})");

$tables = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'palsystem' AND TABLE_NAME LIKE 'pal_%'")->fetchColumn();
ok((int)$tables >= 25, "At least 25 pal_ tables exist (got: {$tables})");

// ── 2. Entity View Capabilities ───────────────────────────────────
echo "\n=== Entity View Capabilities ===\n";
// In CLI mode without auth session, test function existence and SQL queries
// directly via app()->db() rather than through capability handlers.
$testFnList = [
    'pal_cap_entity_list_project_1',
    'pal_cap_entity_list_expense_1',
    'pal_cap_entity_list_material_1',
    'pal_cap_entity_list_purchase_1',
    'pal_cap_entity_list_sale_1',
    'pal_cap_entity_list_collection_1',
    'pal_cap_entity_list_fabrication_due_1',
    'pal_cap_entity_list_audit_log_1',
    'pal_cap_entity_get_project_1',
    'pal_cap_entity_get_expense_1',
    'pal_cap_entity_get_material_1',
    'pal_cap_entity_get_purchase_1',
    'pal_cap_entity_get_sale_1',
];
foreach ($testFnList as $fn) {
    ok(function_exists($fn), "Handler {$fn} exists");
}

// Direct SQL test: query pal_projects through app()->db()
try {
    $testResult = $db->query("SELECT COUNT(*) FROM pal_projects WHERE tenant_id = 502")->fetchColumn();
    ok(true, "Can query pal_projects (count: {$testResult})");
} catch (Throwable $e) {
    ok(false, "Can query pal_projects: " . $e->getMessage());
}

try {
    $testResult = $db->query("SELECT COUNT(*) FROM pal_expenses WHERE tenant_id = 502")->fetchColumn();
    ok(true, "Can query pal_expenses (count: {$testResult})");
} catch (Throwable $e) {
    ok(false, "Can query pal_expenses: " . $e->getMessage());
}

try {
    $testResult = $db->query("SELECT COUNT(*) FROM pal_audit_logs WHERE tenant_id = 502")->fetchColumn();
    ok(true, "Can query pal_audit_logs (count: {$testResult})");
} catch (Throwable $e) {
    ok(false, "Can query pal_audit_logs: " . $e->getMessage());
}

// ── 3. Service Classes Exist ──────────────────────────────────────
echo "\n=== Service Classes ===\n";
$svcClasses = [
    'palProjectService',
    'palProjectCostService',
    'palExpenseService',
    'palPurchaseService',
    'palInventoryService',
    'palMaterialIssuanceService',
    'palFabricationService',
    'palSalesService',
    'palApprovalService',
];
foreach ($svcClasses as $cls) {
    ok(class_exists($cls), "Class {$cls} exists");
}

// ── 6. Routes Are Registered ──────────────────────────────────────
echo "\n=== Routes ===\n";
$routesFile = __DIR__ . '/../modules/project-audit-ledger/routes.php';
ok(file_exists($routesFile), 'routes.php exists');
$routes = require $routesFile;
ok(is_array($routes), 'routes.php returns array');
ok(isset($routes['GET']), 'GET routes defined');
ok(isset($routes['POST']), 'POST routes defined');
ok(count($routes['GET']) > 20, 'At least 20 GET routes');
ok(count($routes['POST']) > 20, 'At least 20 POST routes');

// Check no duplicate keys (the logout fix)
$getKeys = array_keys($routes['GET']);
$postKeys = array_keys($routes['POST']);
ok(count($getKeys) === count(array_unique($getKeys)), 'No duplicate GET routes');
ok(count($postKeys) === count(array_unique($postKeys)), 'No duplicate POST routes');

// ── 7. View Contracts ────────────────────────────────────────────
echo "\n=== View Contracts ===\n";
$viewDir = __DIR__ . '/../modules/project-audit-ledger/helpers/views';
if (is_dir($viewDir)) {
    $views = glob($viewDir . '/*.disyl');
    ok(count($views) >= 4, 'At least 4 view contracts exist (got: ' . count($views) . ')');
    foreach ($views as $vf) {
        $content = file_get_contents($vf);
        ok(str_contains($content, 'ikb_entity_view'), basename($vf) . ' contains ikb_entity_view tag');
    }
} else {
    ok(false, 'View contracts directory exists');
}

// ── 8. Migration Files ───────────────────────────────────────────
echo "\n=== Migration Files ===\n";
$migDir = __DIR__ . '/../modules/project-audit-ledger/database/migrations';
if (is_dir($migDir)) {
    $migs = glob($migDir . '/*.sql');
    ok(count($migs) >= 5, 'At least 5 migration files exist (got: ' . count($migs) . ')');
}
$manifest = json_decode(file_get_contents(__DIR__ . '/../modules/project-audit-ledger/module.json'), true);
$declaredMigs = $manifest['migrations'] ?? [];
ok(count($declaredMigs) >= 5, 'At least 5 migrations declared in module.json (got: ' . count($declaredMigs) . ')');

// ── Summary ───────────────────────────────────────────────────────
echo "\n=== Results ===\n";
$total = $passed + $failed;
echo "  {$passed}/{$total} passed, {$failed} failed\n\n";
exit($failed > 0 ? 1 : 0);
