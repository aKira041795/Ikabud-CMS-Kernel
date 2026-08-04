<?php
/**
 * Cms Akira Profile Headless Module — Scaffold Test
 *
 * Verifies module bootstrap, context availability, and basic contract compliance.
 * Run: php tests/cms_akira_profile_headless_module_test.php
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
=== Cms Akira Profile Headless MODULE TEST ===

";

// ── 1. Manifest ─────────────────────────────────────────────────────
echo "── Manifest ──
";
$manifestPath = BASE_PATH . '/modules/cms-akira/cms-akira-profile-headless/module.json';
t('module.json exists', is_file($manifestPath));

$manifest = json_decode((string)file_get_contents($manifestPath), true);
t('module.json is valid JSON', is_array($manifest));
t('module id matches', ($manifest['id'] ?? '') === 'cms-akira-profile-headless');
t('module name is set', ($manifest['name'] ?? '') !== '');
t('module version is set', ($manifest['version'] ?? '') !== '');

// ── 2. Discovery ────────────────────────────────────────────────────
echo "
── Discovery ──
";
$all = discoverModules();
t('Module discovered by kernel', isset($all['cms-akira-profile-headless']));

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
$modulePath = BASE_PATH . '/modules/cms-akira/cms-akira-profile-headless';
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
t('caphCtx function exists', function_exists('caphCtx'));
t('caphDb function exists', function_exists('caphDb'));
t('caphInput function exists', function_exists('caphInput'));
t('caphRender function exists', function_exists('caphRender'));

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
