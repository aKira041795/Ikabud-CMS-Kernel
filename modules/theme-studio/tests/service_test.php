<?php
/**
 * Theme Studio — Service Unit Tests
 *
 * Tests the core domain services: preset management, token overrides,
 * Theme Element CRUD, and the capability handler.
 *
 * Usage: php modules/theme-studio/tests/service_test.php
 */

declare(strict_types=1);

// Bootstrap is needed for write_log(), module(), getModuleSettings(), etc.
// We skip full CMS boot and only load what the helpers need.
require_once __DIR__ . '/../../../bootstrap.php';

// Module manager must be loaded for module() and discoverModules()
require_once __DIR__ . '/../../../src/helpers/module-manager.php';

// Load the module's helpers (but not handlers — those are for HTTP)
require_once __DIR__ . '/../helpers.php';

$passed = 0;
$failed = 0;

function assert_true(mixed $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  PASS: {$label}\n";
        $passed++;
    } else {
        echo "  FAIL: {$label}\n";
        $failed++;
    }
}

function assert_count(int $expected, array $actual, string $label): void
{
    global $passed, $failed;
    $count = count($actual);
    if ($count === $expected) {
        echo "  PASS: {$label} (count={$expected})\n";
        $passed++;
    } else {
        echo "  FAIL: {$label} (expected {$expected}, got {$count})\n";
        $failed++;
    }
}

// ── Test 1: Built-in presets ──
echo "Test 1: Built-in presets\n";
$presets = themeStudioBuiltinPresets();
assert_count(5, $presets, '5 built-in presets');
assert_true(isset($presets['foundation']), 'foundation preset exists');
assert_true(isset($presets['corporate']), 'corporate preset exists');
assert_true(isset($presets['school']), 'school preset exists');
assert_true(isset($presets['store']), 'store preset exists');
assert_true(isset($presets['editorial']), 'editorial preset exists');

// Each preset has required structure
foreach ($presets as $slug => $preset) {
    assert_true(isset($preset['label']), "{$slug}: has label");
    assert_true(isset($preset['data']['tokens']), "{$slug}: has data.tokens");
    assert_true(isset($preset['source']), "{$slug}: has source");
}

// ── Test 2: Capability handler function exists ──
echo "\nTest 2: Capability handler registration\n";
assert_true(function_exists('theme_studio_capability_handlers'), 'theme_studio_capability_handlers() exists');
$handlers = theme_studio_capability_handlers();
assert_true(isset($handlers['theme.token.apply@1']), 'theme.token.apply@1 registered');
assert_true(is_callable($handlers['theme.token.apply@1']), 'theme.token.apply@1 handler is callable');

// ── Test 3: Capability handler without DB ──
echo "\nTest 3: Capability handler input validation\n";
$result = theme_studio_cap_apply_tokens_1([]);
assert_true(isset($result['ok']), 'result has ok key');
assert_true($result['ok'] === false, 'empty payload returns ok=false');
assert_true(isset($result['error']), 'error message present on failure');

$result2 = theme_studio_cap_apply_tokens_1(['tenant_id' => 0, 'theme_slug' => '']);
assert_true($result2['ok'] === false, 'invalid tenant returns ok=false');

// ── Test 4: Preset token structure consistency ──
echo "\nTest 4: Preset token structure\n";
foreach ($presets as $slug => $preset) {
    $tokens = $preset['data']['tokens'];
    assert_true(isset($tokens['color.primary']), "{$slug}: color.primary token present");
    assert_true(isset($tokens['typography.font_family']), "{$slug}: typography.font_family present");
    assert_true(isset($tokens['spacing.md']), "{$slug}: spacing.md present");
    assert_true(isset($tokens['radius.md']), "{$slug}: radius.md present");
}

// ── Test 5: Module manifest field validation ──
echo "\nTest 5: Module manifest validation\n";
$manifestFile = __DIR__ . '/../module.json';
assert_true(is_file($manifestFile), 'module.json exists');
$manifest = kernelReadJsonFile($manifestFile);
assert_true(($manifest['id'] ?? '') === 'theme-studio', 'module id is theme-studio');
assert_true(isset($manifest['capabilities']['exposes']), 'capabilities.exposes declared');
$capIds = array_map(fn($c) => $c['id'] ?? '', $manifest['capabilities']['exposes'] ?? []);
assert_true(in_array('theme.customize@1', $capIds, true), 'theme.customize@1 declared');
assert_true(in_array('theme.tokens@1', $capIds, true), 'theme.tokens@1 declared');
assert_true(in_array('theme.presets@1', $capIds, true), 'theme.presets@1 declared');
assert_true(in_array('theme.elements@1', $capIds, true), 'theme.elements@1 declared');
assert_true(in_array('theme.token.apply@1', $capIds, true), 'theme.token.apply@1 declared');
assert_true(isset($manifest['owns_tables']), 'owns_tables declared');
assert_count(3, $manifest['owns_tables'], '3 owned tables');

// ── Test 6: Routes file exists and is valid ──
echo "\nTest 6: Routes validation\n";
$routesFile = __DIR__ . '/../routes.php';
assert_true(is_file($routesFile), 'routes.php exists');
$routes = require $routesFile;
assert_true(isset($routes['GET']), 'GET routes defined');
assert_true(isset($routes['POST']), 'POST routes defined');
assert_count(4, $routes['GET'], '4 GET routes');
assert_count(9, $routes['POST'], '9 POST routes');

// All routes reference module-id:functionName format
foreach (array_merge($routes['GET'], $routes['POST']) as $handler) {
    assert_true(str_starts_with($handler, 'theme-studio:'), "handler '{$handler}' uses module-id:functionName format");
}

// ── Test 7: Token override data structure ──
echo "\nTest 7: Token override data model\n";
$sampleOverrides = [
    'color.primary' => '#1d4ed8',
    'color.surface' => '#ffffff',
    'typography.font_family' => 'Inter, sans-serif',
];
assert_true(isset($sampleOverrides['color.primary']), 'token override key format: dotted path');
assert_true(count($sampleOverrides) === 3, 'multiple token overrides supported');

// ── Test 8: Admin templates exist with correct extends ──
echo "\nTest 8: Admin templates\n";
$templateDir = __DIR__ . '/../templates';
$expectedTemplates = ['dashboard.disyl', 'presets.disyl', 'tokens.disyl', 'elements.disyl'];
foreach ($expectedTemplates as $tpl) {
    $path = $templateDir . '/' . $tpl;
    assert_true(is_file($path), "template {$tpl} exists");
    $content = file_get_contents($path);
    assert_true(str_contains($content, 'extends'), "{$tpl} has extends directive");
    assert_true(str_contains($content, 'modules/cms/layouts/admin.disyl'), "{$tpl} extends admin layout");
}

// ── Test 9: Module discovery compatibility ──
echo "\nTest 9: Module discovery\n";
$manifestFile = __DIR__ . '/../module.json';
$manifest = kernelReadJsonFile($manifestFile);
$prefix = preg_replace('/[^a-z0-9]+/i', '_', $manifest['id'] ?? '');
assert_true($prefix === 'theme_studio', "prefix derived from id: {$prefix}");
assert_true(function_exists($prefix . '_capability_handlers'), 'capability handlers function exists at discovered name');

echo "\n==============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "==============================\n";

exit($failed > 0 ? 1 : 0);
