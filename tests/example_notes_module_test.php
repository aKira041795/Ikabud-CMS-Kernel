<?php
/**
 * Example Notes Module — Test
 *
 * Verifies module bootstrap, context availability, contract compliance,
 * and demonstrates the idiomatic test structure for Ikabud modules.
 *
 * Run: php tests/example_notes_module_test.php
 *
 * This test file itself is a reference — copy it when scaffolding
 * tests for a new module and adapt the module-specific assertions.
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

// ── Clear logs ──────────────────────────────────────────────────────
@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== EXAMPLE NOTES MODULE TEST ===\n\n";

// ── 1. Manifest ──────────────────────────────────────────────────────
echo "── Manifest ──\n";
$manifestPath = BASE_PATH . '/modules/example-notes/module.json';
t('module.json exists', is_file($manifestPath));

$manifest = json_decode((string)file_get_contents($manifestPath), true);
t('module.json is valid JSON', is_array($manifest));
t('module id is example-notes', ($manifest['id'] ?? '') === 'example-notes');
t('module name is set', ($manifest['name'] ?? '') !== '');
t('module version is set', ($manifest['version'] ?? '') !== '');
t('owns_tables declared', is_array($manifest['owns_tables'] ?? null) && in_array('en_notes', $manifest['owns_tables'], true));
t('events declared', is_array($manifest['events'] ?? null) && count($manifest['events']) >= 2);

// ── 2. Discovery ─────────────────────────────────────────────────────
echo "\n── Discovery ──\n";
$all = discoverModules();
t('Module discovered by kernel', isset($all['example-notes']));

// ── 3. Capability declarations ────────────────────────────────────────
echo "\n── Capabilities ──\n";
$capCheck = validateModuleCapabilities($manifest);
t('Capability schema valid', !empty($capCheck['ok']), ($capCheck['error'] ?? ''));

// ── 4. Routes ────────────────────────────────────────────────────────
echo "\n── Routes ──\n";
$routesFile = BASE_PATH . '/modules/example-notes/routes.php';
t('routes.php exists', is_file($routesFile));
$routes = require $routesFile;
t('routes.php returns array', is_array($routes));
t('GET routes defined', isset($routes['GET']) && count($routes['GET']) >= 3);
t('POST routes defined', isset($routes['POST']) && count($routes['POST']) >= 3);

// Verify all handler references use correct format (module-id:function)
$badRefs = [];
foreach ($routes as $method => $map) {
    foreach ((array)$map as $path => $handler) {
        if (is_string($handler) && !str_starts_with($handler, 'example-notes:')) {
            $badRefs[] = $handler;
        }
    }
}
t('All handlers use module-id:function format', empty($badRefs), implode(', ', $badRefs));

// ── 5. Helpers ───────────────────────────────────────────────────────
echo "\n── Helpers ──\n";
$helpersFile = BASE_PATH . '/modules/example-notes/helpers.php';
t('helpers.php exists', is_file($helpersFile));
require_once $helpersFile;
t('enCtx function exists', function_exists('enCtx'));
t('enDb function exists', function_exists('enDb'));
t('enInput function exists', function_exists('enInput'));
t('enRender function exists', function_exists('enRender'));

// ── 6. Migrations ────────────────────────────────────────────────────
echo "\n── Migrations ──\n";
$migFile = BASE_PATH . '/modules/example-notes/database/migrations/001_initial.sql';
t('001_initial.sql exists', is_file($migFile));
$migContent = (string)file_get_contents($migFile);
t('Migration creates en_notes table', str_contains($migContent, 'en_notes'));
t('Migration uses correct engine (InnoDB)', str_contains($migContent, 'InnoDB'));

// ── 7. Templates ─────────────────────────────────────────────────────
echo "\n── Templates ──\n";
$tplBase = BASE_PATH . '/templates/modules/example-notes';
t('pages/list.disyl exists', is_file($tplBase . '/pages/list.disyl'));
t('pages/new.disyl exists', is_file($tplBase . '/pages/new.disyl'));
t('pages/view.disyl exists', is_file($tplBase . '/pages/view.disyl'));
t('partials/note-row.disyl exists', is_file($tplBase . '/partials/note-row.disyl'));

// ── 8. Handlers file structure ───────────────────────────────────────
echo "\n── Handlers ──\n";
$handlersFile = BASE_PATH . '/modules/example-notes/handlers.php';
t('handlers.php exists', is_file($handlersFile));
$handlerContent = (string)file_get_contents($handlersFile);

// Strip comment lines before checking for forbidden patterns
$handlerCodeLines = array_filter(
    preg_split('/\R/', $handlerContent) ?: [],
    fn($line) => !preg_match('/^\s*(\/\/|\*|\/\*)/', $line)
);
$handlerCode = implode("\n", $handlerCodeLines);

// Must use enCtx/enDb/enInput/enRender — not raw app() or superglobals
t('No raw app()->db() in handlers', !str_contains($handlerCode, 'app()->db()'));
t('No $_POST in handlers', !str_contains($handlerCode, '$_POST'));
t('No $_GET in handlers', !str_contains($handlerCode, '$_GET'));
t('Uses enCtx()->requireAnyRole()', str_contains($handlerCode, 'enCtx()->requireAnyRole'));
t('Uses app()->events()->fire()', str_contains($handlerCode, "app()->events()->fire("));

// ── 9. Log check ─────────────────────────────────────────────────────
echo "\n── Logs ──\n";
$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('No errors in app.log', $appLog === '' || !str_contains(strtolower($appLog), 'error'));
t('No errors in error.log', $errLog === '');

// ── Summary ──────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if (!empty($errors)) {
    echo "\n  Failures:\n";
    foreach ($errors as $e) {
        echo "    • {$e}\n";
    }
}
echo "\n";
exit($fail > 0 ? 1 : 0);
