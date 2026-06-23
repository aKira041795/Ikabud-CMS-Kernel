<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function bt(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; return; }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$appLogPath = STORAGE_PATH . '/logs/app.log';
$errorLogPath = STORAGE_PATH . '/logs/error.log';
@file_put_contents($appLogPath, '');
@file_put_contents($errorLogPath, '');
$appLogStart = is_file($appLogPath) ? max(0, (int)@filesize($appLogPath)) : 0;
$errorLogStart = is_file($errorLogPath) ? max(0, (int)@filesize($errorLogPath)) : 0;

echo "\n=== PROJECT AUDIT LEDGER — MANIFEST TEST ===\n\n";

echo "── Manifest ──\n";
$manifestPath = BASE_PATH . '/modules/project-audit-ledger/module.json';
bt('module.json exists', is_file($manifestPath));
$manifest = json_decode((string) file_get_contents($manifestPath), true);
bt('module.json is valid JSON', is_array($manifest));
bt('module id is project-audit-ledger', ($manifest['id'] ?? '') === 'project-audit-ledger');
bt('owns_tables declared', is_array($manifest['owns_tables'] ?? null) && in_array('pal_projects', $manifest['owns_tables'], true));
bt('all 30 owned tables present', count($manifest['owns_tables'] ?? []) === 30);
bt('auth cookie declared', ($manifest['auth_cookie'] ?? '') === 'pal_token');
bt('routes enabled', ($manifest['routes'] ?? false) === true);
bt('depends on kernel.auth.user@1', in_array('kernel.auth.user@1', $manifest['capabilities']['depends'] ?? [], true));
bt('depends on kernel.audit.record@1', in_array('kernel.audit.record@1', $manifest['capabilities']['depends'] ?? [], true));

echo "\n── Auth-Owned ──\n";
$auth = $manifest['auth_owned'] ?? [];
bt('auth_owned declared', is_array($auth));
bt('users_table is pal_users', ($auth['users_table'] ?? '') === 'pal_users');
bt('admin role declared', in_array('admin', $auth['admin_roles'] ?? [], true));
bt('touch_updated_at enabled', ($auth['touch_updated_at'] ?? false) === true);

echo "\n── Capabilities (exposes) ──\n";
$exposes = $manifest['capabilities']['exposes'] ?? [];
$exposeIds = array_column($exposes, 'id');
bt('kernel.auth.authenticate@1 declared', in_array('kernel.auth.authenticate@1', $exposeIds, true));
bt('pal.read@1 declared', in_array('pal.read@1', $exposeIds, true));
bt('pal.manage@1 declared', in_array('pal.manage@1', $exposeIds, true));
bt('pal.projects.read@1 declared', in_array('pal.projects.read@1', $exposeIds, true));
bt('pal.expenses.write@1 declared', in_array('pal.expenses.write@1', $exposeIds, true));
bt('pal.inventory.write@1 declared', in_array('pal.inventory.write@1', $exposeIds, true));
bt('pal.approvals.write@1 declared', in_array('pal.approvals.write@1', $exposeIds, true));
bt('pal.reports.read@1 declared', in_array('pal.reports.read@1', $exposeIds, true));
bt('entity.list.pal_project@1 declared', in_array('entity.list.pal_project@1', $exposeIds, true));
bt('entity.get.pal_project@1 declared', in_array('entity.get.pal_project@1', $exposeIds, true));
bt('entity.list.pal_expense@1 declared', in_array('entity.list.pal_expense@1', $exposeIds, true));
bt('entity.list.pal_material@1 declared', in_array('entity.list.pal_material@1', $exposeIds, true));
bt('entity.list.pal_sale@1 declared', in_array('entity.list.pal_sale@1', $exposeIds, true));
bt('entity.list.pal_audit_log@1 declared', in_array('entity.list.pal_audit_log@1', $exposeIds, true));
bt('at least 30 exposes declared', count($exposes) >= 30);

echo "\n── Events ──\n";
$events = $manifest['events'] ?? [];
bt('pal.project.created declared', in_array('pal.project.created', $events, true));
bt('pal.expense.approved declared', in_array('pal.expense.approved', $events, true));
bt('pal.inventory.stocked_in declared', in_array('pal.inventory.stocked_in', $events, true));
bt('pal.fabrication.payment_recorded declared', in_array('pal.fabrication.payment_recorded', $events, true));
bt('pal.approval.completed declared', in_array('pal.approval.completed', $events, true));
bt('total events count is 18', count($events) === 18);

echo "\n── Settings Fields ──\n";
$settings = $manifest['settings_fields'] ?? [];
$settingKeys = array_column($settings, 'key');
bt('company_name declared', in_array('company_name', $settingKeys, true));
bt('default_fabrication_pct declared', in_array('default_fabrication_pct', $settingKeys, true));
bt('budget_warning_pct declared', in_array('budget_warning_pct', $settingKeys, true));
bt('allow_self_approval declared', in_array('allow_self_approval', $settingKeys, true));
bt('total settings fields count is 8', count($settings) === 8);

echo "\n── Navigation ──\n";
$nav = $manifest['nav'] ?? [];
$navUrls = array_column($nav, 'url');
bt('Dashboard nav', in_array('/admin/project-audit-ledger', $navUrls, true));
bt('Projects nav', in_array('/admin/project-audit-ledger/projects', $navUrls, true));
bt('Expenses nav', in_array('/admin/project-audit-ledger/expenses', $navUrls, true));
bt('Inventory nav', in_array('/admin/project-audit-ledger/inventory', $navUrls, true));
bt('Approvals nav', in_array('/admin/project-audit-ledger/approvals', $navUrls, true));
bt('total nav items count is 10', count($nav) === 10);

echo "\n── File Existence ──\n";
bt('routes.php exists', is_file(BASE_PATH . '/modules/project-audit-ledger/routes.php'));
bt('handlers.php exists', is_file(BASE_PATH . '/modules/project-audit-ledger/handlers.php'));
bt('helpers.php exists', is_file(BASE_PATH . '/modules/project-audit-ledger/helpers.php'));
bt('migration 001 exists', is_file(BASE_PATH . '/modules/project-audit-ledger/database/migrations/001_pal_core_schema.sql'));
bt('migration 002 exists', is_file(BASE_PATH . '/modules/project-audit-ledger/database/migrations/002_pal_users.sql'));

$handlerDir = BASE_PATH . '/modules/project-audit-ledger/handlers';
$handlerFiles = scandir($handlerDir);
$handlerFiles = array_values(array_filter($handlerFiles, fn($f) => str_ends_with($f, '.php')));
bt('16 handler files exist', count($handlerFiles) === 16);

$serviceDir = BASE_PATH . '/modules/project-audit-ledger/services';
$serviceFiles = scandir($serviceDir);
$serviceFiles = array_values(array_filter($serviceFiles, fn($f) => str_ends_with($f, '.php')));
bt('9 service files exist', count($serviceFiles) === 9);

echo "\n── PHP Syntax ──\n";
$allPhpFiles = array_merge(
    glob(BASE_PATH . '/modules/project-audit-ledger/*.php'),
    glob(BASE_PATH . '/modules/project-audit-ledger/handlers/*.php'),
    glob(BASE_PATH . '/modules/project-audit-ledger/services/*.php')
);
$syntaxOk = true;
foreach ($allPhpFiles as $f) {
    $out = null;
    $rc = 0;
    exec("php -l " . escapeshellarg($f) . " 2>/dev/null", $out, $rc);
    if ($rc !== 0) {
        bt("Syntax: " . basename($f), false, implode(' ', $out));
        $syntaxOk = false;
    }
}
if ($syntaxOk) {
    bt('All PHP files pass syntax check', true);
}

echo "\n── Results ──\n";
echo "  Passed: {$pass}\n";
echo "  Failed: {$fail}\n";

$appLogSize = is_file($appLogPath) ? max(0, (int)@filesize($appLogPath) - $appLogStart) : 0;
$errorLogSize = is_file($errorLogPath) ? max(0, (int)@filesize($errorLogPath) - $errorLogStart) : 0;
if ($appLogSize > 0 || $errorLogSize > 0) {
    echo "\n  ⚠ Logs generated during test:\n";
    if ($appLogSize > 0) echo "    app.log: {$appLogSize} bytes\n";
    if ($errorLogSize > 0) echo "    error.log: {$errorLogSize} bytes\n";
}

exit($fail > 0 ? 1 : 0);
