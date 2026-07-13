<?php
/**
 * Module Test Scaffold Generator
 *
 * Auto-generates PHP + Playwright test stubs for any module.
 * Reads module.json, detects state machines, extracts routes.
 *
 * Usage:
 *   php scripts/generate-module-test.php guidance
 *   php scripts/generate-module-test.php bakeshop
 *   php scripts/generate-module-test.php wms --playwright
 *   php scripts/generate-module-test.php guidance --force
 *
 * Output:
 *   tests/<module>/manifest_test.php     — module.json contract tests
 *   tests/<module>/state_machine_test.php — if state machine detected
 *   tests/<module>/integration_test.php  — DB-backed integration stub
 *   tests/browser/modules/<module>/      — Playwright spec stubs (with --playwright)
 */

declare(strict_types=1);

// ── Config ────────────────────────────────────────────────────
$basePath = dirname(__DIR__);
$moduleId = $argv[1] ?? '';
$flags = array_slice($argv, 2);
$force = in_array('--force', $flags, true);
$withPlaywright = in_array('--playwright', $flags, true);

if ($moduleId === '' || $moduleId === '--help') {
    echo "Usage: php scripts/generate-module-test.php <module-id> [--playwright] [--force]\n";
    echo "  --playwright  Also generate Playwright browser test stubs\n";
    echo "  --force       Overwrite existing files\n";
    exit(1);
}

$modulePath = "{$basePath}/modules/{$moduleId}";
$manifestPath = "{$modulePath}/module.json";

if (!is_dir($modulePath)) {
    echo "ERROR: Module '{$moduleId}' not found at {$modulePath}\n";
    exit(1);
}
if (!is_file($manifestPath)) {
    echo "ERROR: No module.json found at {$manifestPath}\n";
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!$manifest) {
    echo "ERROR: Invalid module.json\n";
    exit(1);
}

$moduleName = $manifest['name'] ?? $moduleId;
$moduleVersion = $manifest['version'] ?? '1.0.0';
$ownsTables = $manifest['owns_tables'] ?? [];
$authOwned = $manifest['auth_owned'] ?? null;
$exposedCaps = $manifest['capabilities']['exposes'] ?? [];
$dependsCaps = $manifest['capabilities']['depends'] ?? [];
$events = $manifest['events'] ?? [];
$settings = $manifest['settings_fields'] ?? [];
$nav = $manifest['nav'] ?? [];
$routes = [];
$routesFile = "{$modulePath}/routes.php";
if (is_file($routesFile)) {
    $routes = require $routesFile;
}
$hasStateMachine = false;
$stateMachineDetails = [];
$handlerFiles = glob("{$modulePath}/handlers/*.php");
$serviceFiles = glob("{$modulePath}/services/*.php");

// ── Detect state machines ─────────────────────────────────────
echo "\n🔍 Scanning for state machines...\n";
foreach (array_merge(
    [$modulePath . '/helpers.php', $modulePath . '/handlers.php'],
    $handlerFiles ?: [],
    $serviceFiles ?: [],
) as $file) {
    if (!is_file($file)) continue;
    $content = file_get_contents($file);
    $relPath = str_replace($basePath . '/', '', $file);

    // Look for status arrays with allowed/forbidden transitions
    if (preg_match('/\$allowed\s*=\s*\[([^\]]+)\]\s*;/', $content, $m)) {
        $statuses = array_map('trim', explode(',', $m[1]));
        $statuses = array_filter($statuses, fn($s) => preg_match('/^[\'"]\w+[\'"]$/', $s));
        if (count($statuses) >= 3) {
            $statusList = array_map(fn($s) => trim($s, "'\""), $statuses);
            $hasStateMachine = true;
            $stateMachineDetails[] = [
                'file' => $relPath,
                'statuses' => $statusList,
            ];
        }
    }
    // Also check for forbidden transitions
    if (preg_match('/forbiddenTransitions\s*=\s*\[([^\]]+)\]\s*;/s', $content, $m)) {
        if (!$hasStateMachine) {
            $hasStateMachine = true;
            $stateMachineDetails[] = [
                'file' => $relPath,
                'statuses' => ['detected via forbiddenTransitions'],
            ];
        }
    }
}

// ── Create output directories ─────────────────────────────────
$testDir = "{$basePath}/tests/{$moduleId}";
$browserDir = "{$basePath}/tests/browser/modules/{$moduleId}";
$browserPagesDir = "{$browserDir}/pages";
$browserWorkflowsDir = "{$browserDir}/workflows";

if (!is_dir($testDir)) mkdir($testDir, 0777, true);
if ($withPlaywright && !is_dir($browserPagesDir)) mkdir($browserPagesDir, 0777, true);
if ($withPlaywright && !is_dir($browserWorkflowsDir)) mkdir($browserWorkflowsDir, 0777, true);

$generated = [];
$skipped = [];

// ────────────────────────────────────────────────────────────────
// 1. MANIFEST CONTRACT TEST
// ────────────────────────────────────────────────────────────────
$manifestTest = "{$testDir}/manifest_test.php";
$target = $manifestTest;
if (is_file($target) && !$force) {
    $skipped[] = basename($target);
} else {
    $navUrls = array_column($nav, 'url');
    $capIds = array_column($exposedCaps, 'id');
    $settingsKeys = array_column($settings, 'key');
    $depIds = $dependsCaps;
    $eventIds = is_array($events) ? (isset($events[0]) ? $events : array_keys($events)) : [];

    $content = <<<PHP
<?php
/**
 * {$moduleName} — Manifest Contract Test
 *
 * Auto-generated by generate-module-test.php
 * Validates module.json structure and file existence.
 *
 * Usage: php tests/{$moduleId}/manifest_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

\$h = new TestHarness('{$moduleId}-manifest');
\$h->fingerprint('modules/{$moduleId}/module.json');

\$manifestPath = \$h->basePath() . '/modules/{$moduleId}/module.json';
\$manifest = json_decode((string) file_get_contents(\$manifestPath), true);

\$h->section('Module identity');
\$h->test('module id is {$moduleId}', (\$manifest['id'] ?? '') === '{$moduleId}');
\$h->test('module.json is valid JSON', is_array(\$manifest));

\$h->section('Owned tables');
\$h->test('owns_tables declared', is_array(\$manifest['owns_tables'] ?? null));
\$h->test(count(\$ownsTables) . ' owned tables', count(\$manifest['owns_tables'] ?? []) >= 1);

\$h->section('Capabilities');
\$h->test('depends on kernel.auth.user@1', in_array('kernel.auth.user@1', \$manifest['capabilities']['depends'] ?? [], true));

\$h->section('File existence');
\$h->test('routes.php exists', is_file(\$h->basePath() . '/modules/{$moduleId}/routes.php'));
\$h->test('handlers.php exists', is_file(\$h->basePath() . '/modules/{$moduleId}/handlers.php'));
\$h->test('helpers.php exists', is_file(\$h->basePath() . '/modules/{$moduleId}/helpers.php'));

\$h->section('PHP syntax');
\$allPhp = array_merge(
    glob(\$h->basePath() . '/modules/{$moduleId}/*.php'),
    glob(\$h->basePath() . '/modules/{$moduleId}/handlers/*.php'),
);
\$syntaxOk = true;
foreach (\$allPhp as \$f) {
    \$output = shell_exec('php -l ' . escapeshellarg(\$f) . ' 2>/dev/null');
    if (\$output === null || !str_contains(\$output, 'No syntax errors')) {
        \$h->test('Syntax: ' . basename(\$f), false, \$output ?? 'exec failed');
        \$syntaxOk = false;
    }
}
if (\$syntaxOk) \$h->test('All PHP files pass syntax check', true);

\$h->section('Gap analysis');
\$h->gap('Auth-owned user table CRUD operations');
\$h->gap('Settings read/write through manifest contract');
\$h->gap('Navigation items resolve to valid handlers');
\$h->gap('Entity view contracts in helpers/views/');

\$h->done();
PHP;

    file_put_contents($target, $content);
    $generated[] = basename($target);
}

// ────────────────────────────────────────────────────────────────
// 2. STATE MACHINE TEST (if detected)
// ────────────────────────────────────────────────────────────────
if ($hasStateMachine) {
    foreach ($stateMachineDetails as $sm) {
        $statuses = $sm['statuses'];
        $safeName = preg_replace('/[^a-z0-9_]/', '_', $moduleId);
        $smTest = "{$testDir}/{$safeName}_state_machine_test.php";
        $target = $smTest;

        if (is_file($target) && !$force) {
            $skipped[] = basename($target);
            continue;
        }

        $statusList = var_export($statuses, true);

        $content = <<<PHP
<?php
/**
 * {$moduleName} — State Machine Test
 *
 * Auto-generated by generate-module-test.php
 * Tests status transitions: {$statusList}
 * Source: {$sm['file']}
 *
 * Pure logic — no bootstrap, no DB required.
 * Replicable pattern from PAL JobOrderWorkflow test.
 *
 * Usage: php tests/{$moduleId}/{$safeName}_state_machine_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

\$h = new TestHarness('{$moduleId}-state-machine');

// Known allowed statuses (extracted from source)
\$statuses = {$statusList};

// Define allowed transitions based on source code analysis.
// KEY: from status → array of allowed target statuses.
// ALL other combinations in the N×N matrix must return false.
\$allowed = [];
foreach (\$statuses as \$s) {
    \$allowed[\$s] = \$statuses; // Placeholder — replace with actual transition rules
}
// Remove self-transitions
foreach (\$allowed as \$from => \$tos) {
    \$allowed[\$from] = array_values(array_filter(\$tos, fn(\$t) => \$t !== \$from));
}

// Add forbidden transitions (extracted from source):
// \$forbidden = ['closed' => ['open', 'in_progress', 'on_hold']];

\$h->section('Exhaustive ' . count(\$statuses) . '×' . count(\$statuses) . ' transition matrix');

\$asserted = 0;
foreach (\$statuses as \$from) {
    foreach (\$statuses as \$to) {
        // Replace this logic with actual isAllowed() call to the service
        \$expected = in_array(\$to, \$allowed[\$from] ?? [], true);
        \$label = "{\$from} → {\$to} = " . (\$expected ? 'ALLOWED' : 'FORBIDDEN');
        \$h->test(\$label, true); // Placeholder — replace with actual assertion
        \$asserted++;
    }
}

\$h->section('Label mapping');
foreach (\$statuses as \$s) {
    \$label = ucfirst(str_replace('_', ' ', \$s));
    \$h->test("{\$s} → '{\$label}'", true); // Placeholder
}

\$h->section('Gap analysis — integration tests needed');
\$h->gap('DB: actual status transitions persist correctly');
\$h->gap('DB: forbidden transitions throw/return error');
\$h->gap('DB: version conflict detection');
\$h->gap('DB: audit trail written on transition');
\$h->gap('DB: status history recorded');

\$h->done();
PHP;

        file_put_contents($target, $content);
        $generated[] = basename($target);
    }
} else {
    echo "  ℹ️  No state machine detected — skipping state machine test\n";
}

// ────────────────────────────────────────────────────────────────
// 3. INTEGRATION TEST STUB
// ────────────────────────────────────────────────────────────────
$integrationTest = "{$testDir}/{$moduleId}_integration_test.php";
$target = $integrationTest;
if (is_file($target) && !$force) {
    $skipped[] = basename($target);
} else {
    $tenantHost = "{$moduleId}.test"; // convention
    $dbName = $manifest['application_profile']['id'] ?? $moduleId;
    $tableList = var_export($ownsTables, true);

    $content = <<<PHP
<?php
/**
 * {$moduleName} — Integration Test
 *
 * Auto-generated by generate-module-test.php
 * Tests DB-backed business logic.
 *
 * Prerequisites: tenant host '{$tenantHost}' must resolve
 * with a database containing {$moduleId} tables.
 *
 * Usage: php tests/{$moduleId}/{$moduleId}_integration_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

\$h = new TestHarness('{$moduleId}-integration', TestHarness::MODE_INTEGRATION, '{$tenantHost}');
\$h->fingerprint('modules/{$moduleId}/module.json');
\$h->fingerprint('modules/{$moduleId}/helpers.php');

// ── Load module ───────────────────────────────────────────────
\$h->loadModule('modules/{$moduleId}/helpers.php');
\$h->loadModule('modules/{$moduleId}/handlers.php');

\$db = app()->db();
\$testTenantId = (int)(app()->tenant()->current() ?? 999990);
\$ownsTables = {$tableList};
\$moduleDb = new \Ikabud\Kernel\Contracts\ModuleDB(\$db, '{$moduleId}', \$ownsTables, []);

// ── Cleanup ───────────────────────────────────────────────────
foreach (\$ownsTables as \$t) {
    try { \$db->exec("DELETE FROM {\$t} WHERE tenant_id = {\$testTenantId}"); } catch (\Throwable \$e) {}
}

\$h->section('Database connection');
try {
    \$dbName = \$db->query('SELECT DATABASE()')->fetchColumn();
    \$h->test('connected to database', \$dbName !== false && \$dbName !== '');
} catch (\Exception \$e) {
    \$h->test('database connection', false, \$e->getMessage());
}

\$h->section('Core tables exist');
\$coreTables = array_slice(\$ownsTables, 0, 5);
foreach (\$coreTables as \$t) {
    try {
        \$db->query("SELECT COUNT(*) FROM {\$t} LIMIT 1");
        \$h->test("table {\$t} accessible", true);
    } catch (\Exception \$e) {
        \$h->test("table {\$t} accessible", false, \$e->getMessage());
    }
}

\$h->section('Gap analysis');
\$h->gap('CRUD operations on core entities');
\$h->gap('Business logic validation (unique constraints, required fields)');
\$h->gap('Event emission on create/update/delete');
\$h->gap('Audit trail on mutations');

\$h->done();
PHP;

    file_put_contents($target, $content);
    $generated[] = basename($target);
}

// ────────────────────────────────────────────────────────────────
// 4. PLAYWRIGHT SPEC STUBS (with --playwright flag)
// ────────────────────────────────────────────────────────────────
if ($withPlaywright) {
    $pageRoutes = [];
    if (isset($routes['GET'])) {
        foreach ($routes['GET'] as $url => $handler) {
            if (!str_contains($url, '{') && !str_contains($url, '/api/')) {
                $pageRoutes[] = $url;
            }
        }
    }

    // ── Dashboard spec ──────────────────────────────────────────
    $dashboardSpec = "{$browserPagesDir}/dashboard.spec.js";
    $target = $dashboardSpec;
    if (is_file($target) && !$force) {
        $skipped[] = basename($target);
    } else {
        $homeUrl = $nav[0]['url'] ?? "/admin/{$moduleId}";
        $loginUrl = str_replace('/admin/', '/', $homeUrl) . '/login';
        $authTable = $authOwned['users_table'] ?? "{$moduleId}_users";

        $content = <<<JS
/**
 * Browser tests for {$moduleName} Dashboard
 *
 * Auto-generated by generate-module-test.php
 *
 * Run: npx playwright test tests/browser/modules/{$moduleId}/
 *
 * @see modules/{$moduleId}
 */

// @ts-check
const { test, expect } = require('../WorkbenchFixture');

test.describe('{$moduleId}:dashboard', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto(`{$loginUrl}`);
        await page.fill('input[name="username"]', 'admin');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        await page.waitForURL('**{$homeUrl}');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
    });

    test('renders with app shell', async ({ page, shell }) => {
        await shell.expectVisible();
        await shell.expectAppName();
    });

    test('dashboard page title is visible', async ({ page }) => {
        await expect(page.locator('h1')).toBeVisible();
    });

    // ── Gaps ──
    test('known coverage gaps', async ({ integrity }) => {
        integrity.gap('Sidebar navigation items reflect {$moduleId} routes');
        integrity.gap('Summary cards display correct counts');
        integrity.gap('Quick-action buttons navigate to create pages');
        integrity.gap('Mobile responsive layout at 375px');
        integrity.gap('Page title matches nav item label');
    });
});
JS;
        file_put_contents($target, $content);
        $generated[] = basename($target);
    }

    // ── Navigation spec ──────────────────────────────────────────
    $navSpec = "{$browserWorkflowsDir}/navigation.spec.js";
    $target = $navSpec;
    if (is_file($target) && !$force) {
        $skipped[] = basename($target);
    } else {
        $navItems = array_map(fn($n) => "'{$n['url']}'", $nav);
        $navList = implode(", ", $navItems);

        $content = <<<JS
/**
 * Browser tests for {$moduleName} Navigation
 *
 * Auto-generated by generate-module-test.php
 *
 * Run: npx playwright test tests/browser/modules/{$moduleId}/
 */

// @ts-check
const { test, expect } = require('../WorkbenchFixture');

const NAV_URLS = [{$navList}];

test.describe('{$moduleId}:navigation', () => {

    test('all nav items render in sidebar', async ({ page, shell }) => {
        for (const url of NAV_URLS) {
            const link = page.locator('a[href="' + url + '"]');
            await expect(link.first()).toBeVisible();
        }
    });

    test('each nav item navigates to correct page', async ({ page, shell }) => {
        for (const url of NAV_URLS) {
            await page.goto(url);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            await expect(page).toHaveURL(new RegExp(url.replace(/\\//g, '\\\\/')));
        }
    });

    test('known coverage gaps', async ({ integrity }) => {
        integrity.gap('Form validation on create/edit pages');
        integrity.gap('List views show entity data');
        integrity.gap('Detail views for each entity type');
        integrity.gap('Cross-page navigation preserves state');
        integrity.gap('Mobile bottom-nav for key pages');
    });
});
JS;
        file_put_contents($target, $content);
        $generated[] = basename($target);
    }
}

// ────────────────────────────────────────────────────────────────
// Summary
// ────────────────────────────────────────────────────────────────
echo "\n══════════════════════════════════════\n";
echo "  Module: {$moduleId} ({$moduleName})\n";
echo "  Version: {$moduleVersion}\n";
echo "  State machine: " . ($hasStateMachine ? '✅ detected' : '❌ not found') . "\n";
echo "  Owned tables: " . count($ownsTables) . "\n";
echo "  Handlers: " . count($handlerFiles ?: []) . " files, " . count($serviceFiles ?: []) . " services\n";
echo "══════════════════════════════════════\n\n";

echo "Generated:\n";
foreach ($generated as $g) echo "  ✅ tests/{$moduleId}/{$g}\n";
if ($withPlaywright) {
    foreach ($generated as $g) {
        if (str_contains($g, '.spec.js')) echo "  ✅ tests/browser/modules/{$moduleId}/{$g}\n";
    }
}
foreach ($skipped as $s) echo "  ⏭ {$s} (exists, use --force to overwrite)\n";

echo "\n  📄 Run: php tests/{$moduleId}/manifest_test.php\n";
if ($hasStateMachine) echo "  📄 Run: php tests/{$moduleId}/{$safeName}_state_machine_test.php\n";
echo "  📄 Run: php tests/{$moduleId}/{$moduleId}_integration_test.php\n";

// ── Next steps ─────────────────────────────────────────────────
echo "\n─── Manual steps required ───\n";
echo "  1. Edit state_machine_test.php: replace placeholders with actual isAllowed() calls\n";
echo "  2. Edit integration_test.php: add seed data and service assertions\n";
echo "  3. For browser tests: update credentials, selectors from module's actual login\n";
echo "  4. Add the module's tenant host to your hosts file / DNS\n";
echo "\n";
