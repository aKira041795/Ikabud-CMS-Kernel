<?php
/**
 * Cms Akira Search Adapter Module — Scaffold Test
 *
 * Verifies module bootstrap, context availability, and basic contract compliance.
 * Run: php tests/cms_akira_search_adapter_module_test.php
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
        echo "  ✓ {$label}
";
    } else {
        $fail++;
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "
";
    }
}

// ── Clear logs ──────────────────────────────────────────────────────
@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "
=== Cms Akira Search Adapter MODULE TEST ===

";

// ── 1. Manifest ─────────────────────────────────────────────────────
echo "── Manifest ──
";
$manifestPath = BASE_PATH . '/modules/cms-akira/cms-akira-search-adapter/module.json';
t('module.json exists', is_file($manifestPath));

$manifest = json_decode((string)file_get_contents($manifestPath), true);
t('module.json is valid JSON', is_array($manifest));
t('module id matches', ($manifest['id'] ?? '') === 'cms-akira-search-adapter');
t('module name is set', ($manifest['name'] ?? '') !== '');
t('module version is set', ($manifest['version'] ?? '') !== '');

// ── 2. Discovery ────────────────────────────────────────────────────
echo "
── Discovery ──
";
$all = discoverModules();
t('Module discovered by kernel', isset($all['cms-akira-search-adapter']));

// ── 3. Capability declarations ──────────────────────────────────────
echo "
── Capabilities ──
";
$capCheck = validateModuleCapabilities($manifest);
t('Capability declarations valid', !empty($capCheck['ok']), ($capCheck['error'] ?? ''));

// ── 4. Routes ───────────────────────────────────────────────────────
echo "
── Routes ──
";
$modulePath = BASE_PATH . '/modules/cms-akira/cms-akira-search-adapter';
$routesFile = $modulePath . '/routes.php';
t('routes.php exists', is_file($routesFile));
$routes = require $routesFile;
t('routes.php returns array', is_array($routes));
t('GET routes defined', isset($routes['GET']) && is_array($routes['GET']));

// ── 5. Helpers load ─────────────────────────────────────────────────
echo "
── Helpers ──
";
$helpersFile = $modulePath . '/helpers.php';
t('helpers.php exists', is_file($helpersFile));
require_once $helpersFile;
t('casaCtx function exists', function_exists('casaCtx'));
t('casaDb function exists', function_exists('casaDb'));
t('casaInput function exists', function_exists('casaInput'));
t('casaRender function exists', function_exists('casaRender'));

// ── 6. Log check ────────────────────────────────────────────────────
echo "
── Logs ──
";
$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('No errors in app.log', $appLog === '' || !str_contains(strtolower($appLog), 'error'));
t('No errors in error.log', $errLog === '');

// ── Summary ─────────────────────────────────────────────────────────
echo "
" . str_repeat('─', 50) . "
";
echo "  Result: {$pass} passed, {$fail} failed
";
if (!empty($errors)) {
    echo "
  Failures:
";
    foreach ($errors as $e) {
        echo "    • {$e}
";
    }
}
echo "
";
exit($fail > 0 ? 1 : 0);
