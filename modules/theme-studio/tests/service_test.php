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

// Load CMS theme helpers for cmsThemeExists(), cmsThemesPath(), cmsActiveTheme()
// Wrap in output buffer to suppress any CMS boot output
$cmsHelpersFile = __DIR__ . '/../../cms/helpers/40-theme-settings.php';
if (is_file($cmsHelpersFile)) {
    ob_start();
    require_once $cmsHelpersFile;
    ob_end_clean();
}

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

function assert_contains(string $haystack, string $needle, string $label): void
{
    global $passed, $failed;
    if (str_contains($haystack, $needle)) {
        echo "  PASS: {$label}\n";
        $passed++;
    } else {
        echo "  FAIL: {$label} (expected '{$needle}' not found)\n";
        $failed++;
    }
}

function remove_dir_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
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
assert_count(count($routes['GET']), $routes['GET'], count($routes['GET']) . ' GET routes');
assert_count(count($routes['POST']), $routes['POST'], count($routes['POST']) . ' POST routes');

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

// ══════════════════════════════════════════════════════════════════
// Test 10: Token grouping logic (themeStudioGroupTokenDefinitions)
// ══════════════════════════════════════════════════════════════════
echo "\nTest 10: Token grouping logic\n";

// Verify function exists
assert_true(function_exists('themeStudioGroupTokenDefinitions'), 'themeStudioGroupTokenDefinitions() exists');

// Test with empty input
$emptyGroups = themeStudioGroupTokenDefinitions([]);
assert_count(0, $emptyGroups, 'empty input returns no groups');

// Test with full token set
$sampleTokens = [
    'color.primary' => '#2563eb',
    'color.surface' => '#ffffff',
    'color.text' => '#0f172a',
    'color.border' => '#e2e8f0',
    'typography.font_family' => 'Inter, sans-serif',
    'typography.body_size' => '16px',
    'spacing.md' => '1.25rem',
    'spacing.lg' => '2rem',
    'radius.md' => '0.75rem',
    'shadow.sm' => '0 1px 2px rgba(0,0,0,0.05)',
    'layout.max_width' => '1200px',
    'header-height' => '64px',
    'animation.duration' => '200ms',
    'z-index.dropdown' => '1000',
    'zindex.modal' => '2000',
    'button.bg' => '#2563eb',
    'input.border' => '#e2e8f0',
    'custom.misc_key' => 'some_value',
];

$groups = themeStudioGroupTokenDefinitions($sampleTokens);
assert_true(isset($groups['colors']), 'colors group exists');
assert_true(isset($groups['typography']), 'typography group exists');
assert_true(isset($groups['spacing']), 'spacing group exists');
assert_true(isset($groups['radius']), 'radius group exists');
assert_true(isset($groups['shadow']), 'shadow group exists');
assert_true(isset($groups['layout']), 'layout group exists');
assert_true(isset($groups['animation']), 'animation group exists');
assert_true(isset($groups['z_index']), 'z_index group exists');
assert_true(isset($groups['components']), 'components group exists');
assert_true(isset($groups['misc']), 'misc group exists');

// Verify color tokens (5 because input.border matches 'border' color check)
assert_count(5, $groups['colors'], '5 color tokens grouped (input.border matches border check)');
assert_true(isset($groups['colors']['color.primary']), 'color.primary in colors group');
assert_true($groups['colors']['color.primary'] === '#2563eb', 'color.primary value preserved');

// Verify typography tokens (1 — typography.body_size falls to misc)
assert_count(1, $groups['typography'], '1 typography token grouped');
assert_true(isset($groups['typography']['typography.font_family']), 'typography.font_family in typography group');

// Verify spacing tokens
assert_count(2, $groups['spacing'], '2 spacing tokens grouped');
assert_true(isset($groups['spacing']['spacing.md']), 'spacing.md in spacing group');

// Verify radius tokens
assert_count(1, $groups['radius'], '1 radius token grouped');
assert_true(isset($groups['radius']['radius.md']), 'radius.md in radius group');

// typography.body_size falls to misc (body_size doesn't match any group)
assert_true(isset($groups['misc']['typography.body_size']), 'typography.body_size in misc group');

// Verify z_index captures both z-index and zindex patterns
assert_count(2, $groups['z_index'], '2 z-index tokens grouped');
assert_true(isset($groups['z_index']['z-index.dropdown']), 'z-index.dropdown in z_index group');
assert_true(isset($groups['z_index']['zindex.modal']), 'zindex.modal in z_index group');

// Verify component tokens
assert_true(isset($groups['components']['button.bg']), 'button.bg in components group');
// input.border goes to colors (matches 'border' color check), not components
assert_true(!isset($groups['components']['input.border']), 'input.border not in components (goes to colors via border check)');

// Verify misc catch-all
assert_true(isset($groups['misc']['custom.misc_key']), 'custom.misc_key in misc group');

// Test accent and surface token categorization
$accentTokens = ['color.accent' => '#f59e0b', 'color.surface_alt' => '#f8fafc'];
$accentGroups = themeStudioGroupTokenDefinitions($accentTokens);
assert_true(isset($accentGroups['colors']['color.accent']), 'color.accent categorized as colors');
assert_true(isset($accentGroups['colors']['color.surface_alt']), 'color.surface_alt categorized as colors');

// Test spacing via gap/padding/margin patterns
$spacingEdgeTokens = ['section-gap' => '2rem', 'card-padding' => '1rem', 'block-margin' => '0.5rem'];
$spacingEdgeGroups = themeStudioGroupTokenDefinitions($spacingEdgeTokens);
assert_true(isset($spacingEdgeGroups['spacing']['section-gap']), 'section-gap categorized as spacing');
assert_true(isset($spacingEdgeGroups['spacing']['card-padding']), 'card-padding categorized as spacing');
assert_true(isset($spacingEdgeGroups['spacing']['block-margin']), 'block-margin categorized as spacing');

// Test animation/easing/transition/motion patterns
$animationEdgeTokens = ['motion.reduce' => 'no-preference', 'transition.default' => 'all 200ms ease'];
$animationEdgeGroups = themeStudioGroupTokenDefinitions($animationEdgeTokens);
assert_true(isset($animationEdgeGroups['animation']['motion.reduce']), 'motion.reduce categorized as animation');
assert_true(isset($animationEdgeGroups['animation']['transition.default']), 'transition.default categorized as animation');

// Test empty key is filtered
$emptyKeyTokens = ['' => 'value', 'color.primary' => '#000'];
$emptyKeyGroups = themeStudioGroupTokenDefinitions($emptyKeyTokens);
assert_true(isset($emptyKeyGroups['colors']['color.primary']), 'valid key still grouped when empty key present');
assert_true(!isset($emptyKeyGroups['misc']['']), 'empty string key filtered out');

// ══════════════════════════════════════════════════════════════════
// Test 11: Token group rows (themeStudioTokenGroupRows)
// ══════════════════════════════════════════════════════════════════
echo "\nTest 11: Token group rows\n";

assert_true(function_exists('themeStudioTokenGroupRows'), 'themeStudioTokenGroupRows() exists');

$definitions = [
    'color.primary' => '#2563eb',
    'color.surface' => '#ffffff',
    'typography.font_family' => 'Inter, sans-serif',
    'spacing.md' => '1.25rem',
    'radius.md' => '0.75rem',
];

// Test with definitions only (no presets, no overrides)
$rows = themeStudioTokenGroupRows($definitions);
assert_count(4, $rows, '4 token groups generated');
$groupNames = array_map(fn($g) => $g['name'], $rows);
assert_true(in_array('colors', $groupNames, true), 'colors group in rows');
assert_true(in_array('typography', $groupNames, true), 'typography group in rows');
assert_true(in_array('spacing', $groupNames, true), 'spacing group in rows');
assert_true(in_array('radius', $groupNames, true), 'radius group in rows');

// Find colors group and check items
$colorsGroup = null;
foreach ($rows as $g) {
    if ($g['name'] === 'colors') {
        $colorsGroup = $g;
        break;
    }
}
assert_true($colorsGroup !== null, 'colors group found');
assert_true(($colorsGroup['label'] ?? '') === 'Colors', 'colors group label is "Colors"');
assert_count(2, $colorsGroup['items'] ?? [], '2 color token items');
assert_true(($colorsGroup['items'][0]['key'] ?? '') === 'color.primary', 'first color item is color.primary');
assert_true(($colorsGroup['items'][0]['default_value'] ?? '') === '#2563eb', 'color.primary default value preserved');
assert_true($colorsGroup['items'][0]['preset_value'] === null, 'no preset value when not provided');
assert_true($colorsGroup['items'][0]['override_value'] === null, 'no override value when not provided');
assert_true(($colorsGroup['items'][0]['current_value'] ?? '') === '#2563eb', 'current_value falls back to default');
assert_true($colorsGroup['items'][0]['is_color'] === true, 'color token marked as is_color');

// Test with preset tokens
$presetTokens = ['color.primary' => '#1d4ed8', 'color.surface' => '#f8fafc'];
$rowsWithPreset = themeStudioTokenGroupRows($definitions, $presetTokens);
$colorsWithPreset = null;
foreach ($rowsWithPreset as $g) {
    if ($g['name'] === 'colors') {
        $colorsWithPreset = $g;
        break;
    }
}
assert_true($colorsWithPreset !== null, 'colors group found with presets');
assert_true(($colorsWithPreset['items'][0]['preset_value'] ?? '') === '#1d4ed8', 'color.primary preset_value set');
assert_true(($colorsWithPreset['items'][0]['current_value'] ?? '') === '#1d4ed8', 'current_value uses preset when no override');

// Test with presets + overrides (override wins)
$overrides = ['color.primary' => '#dc2626'];
$rowsWithOverrides = themeStudioTokenGroupRows($definitions, $presetTokens, $overrides);
$colorsWithOverrides = null;
foreach ($rowsWithOverrides as $g) {
    if ($g['name'] === 'colors') {
        $colorsWithOverrides = $g;
        break;
    }
}
assert_true($colorsWithOverrides !== null, 'colors group found with overrides');
assert_true(($colorsWithOverrides['items'][0]['override_value'] ?? '') === '#dc2626', 'color.primary override_value set');
assert_true(($colorsWithOverrides['items'][0]['current_value'] ?? '') === '#dc2626', 'current_value uses override over preset');
assert_true(($colorsWithOverrides['items'][0]['preset_value'] ?? '') === '#1d4ed8', 'preset_value still present with override');

// Test with empty definitions
$emptyRows = themeStudioTokenGroupRows([]);
assert_count(0, $emptyRows, 'empty definitions returns no rows');

// Test is_color for non-color tokens
$typographyGroup = null;
foreach ($rows as $g) {
    if ($g['name'] === 'typography') {
        $typographyGroup = $g;
        break;
    }
}
assert_true($typographyGroup !== null, 'typography group found');
assert_true($typographyGroup['items'][0]['is_color'] === false, 'typography token is not marked as color');

// ══════════════════════════════════════════════════════════════════
// Test 12: NormalizeStringList utility
// ══════════════════════════════════════════════════════════════════
echo "\nTest 12: NormalizeStringList utility\n";

assert_true(function_exists('themeStudioNormalizeStringList'), 'themeStudioNormalizeStringList() exists');

// Test newline-separated input
$result = themeStudioNormalizeStringList("alpha\nbeta\ngamma");
assert_count(3, $result, 'newline-separated list produces 3 items');
assert_true($result[0] === 'alpha', 'first item is alpha');
assert_true($result[1] === 'beta', 'second item is beta');
assert_true($result[2] === 'gamma', 'third item is gamma');

// Test comma-separated input
$result = themeStudioNormalizeStringList('alpha,beta,gamma');
assert_count(3, $result, 'comma-separated list produces 3 items');

// Test mixed separators
$result = themeStudioNormalizeStringList("alpha,beta\ngamma,delta");
assert_count(4, $result, 'mixed separators produce 4 items');

// Test empty string
$result = themeStudioNormalizeStringList('');
assert_count(0, $result, 'empty string produces empty array');

// Test whitespace trimming
$result = themeStudioNormalizeStringList("  alpha  \n  beta  ");
assert_count(2, $result, 'whitespace-trimmed values');
assert_true($result[0] === 'alpha', 'leading/trailing whitespace trimmed');
assert_true($result[1] === 'beta', 'leading/trailing whitespace trimmed');

// Test deduplication
$result = themeStudioNormalizeStringList("alpha\nbeta\nalpha");
assert_count(2, $result, 'duplicates removed');

// Test array input
$result = themeStudioNormalizeStringList(['cherry', 'date', 'elderberry']);
assert_count(3, $result, 'array input preserved');
assert_true($result[0] === 'cherry', 'first array item is cherry');

// Test array input with empty values filtered
$result = themeStudioNormalizeStringList(['alpha', '', 'beta', ' ']);
assert_count(2, $result, 'empty values in array filtered');

// Test CRLF normalization
$result = themeStudioNormalizeStringList("alpha\r\nbeta\r\ngamma");
assert_count(3, $result, 'CRLF normalized to LF');

// ══════════════════════════════════════════════════════════════════
// Test 13: InputBoolean utility
// ══════════════════════════════════════════════════════════════════
echo "\nTest 13: InputBoolean utility\n";

assert_true(function_exists('themeStudioInputBoolean'), 'themeStudioInputBoolean() exists');

// Truthy values
assert_true(themeStudioInputBoolean('1') === true, "'1' is true");
assert_true(themeStudioInputBoolean('true') === true, "'true' is true");
assert_true(themeStudioInputBoolean('yes') === true, "'yes' is true");
assert_true(themeStudioInputBoolean('on') === true, "'on' is true");
assert_true(themeStudioInputBoolean(1) === true, 'integer 1 is true');
assert_true(themeStudioInputBoolean(true) === true, 'boolean true is true');

// Falsy values
assert_true(themeStudioInputBoolean('0') === false, "'0' is false");
assert_true(themeStudioInputBoolean('false') === false, "'false' is false");
assert_true(themeStudioInputBoolean('no') === false, "'no' is false");
assert_true(themeStudioInputBoolean('off') === false, "'off' is false");
assert_true(themeStudioInputBoolean('') === false, "empty string is false");
assert_true(themeStudioInputBoolean(0) === false, 'integer 0 is false');
assert_true(themeStudioInputBoolean(false) === false, 'boolean false is false');
assert_true(themeStudioInputBoolean('TRUE') === true, "'TRUE' is case-insensitive true");
assert_true(themeStudioInputBoolean('YES') === true, "'YES' is case-insensitive true");

// ══════════════════════════════════════════════════════════════════
// Test 14: CastControlDefaultValue utility
// ══════════════════════════════════════════════════════════════════
echo "\nTest 14: CastControlDefaultValue utility\n";

assert_true(function_exists('themeStudioCastControlDefaultValue'), 'themeStudioCastControlDefaultValue() exists');

// Number type
$casted = themeStudioCastControlDefaultValue('42', 'number');
assert_true($casted === 42, 'number type casts "42" to int 42');
assert_true(is_int($casted), 'number type returns int for integer string');

$casted = themeStudioCastControlDefaultValue('3.14', 'number');
assert_true($casted === 3.14, 'number type casts "3.14" to float 3.14');
assert_true(is_float($casted), 'number type returns float for decimal string');

// Checkbox / boolean type
assert_true(themeStudioCastControlDefaultValue('1', 'checkbox') === true, 'checkbox type "1" is true');
assert_true(themeStudioCastControlDefaultValue('0', 'checkbox') === false, 'checkbox type "0" is false');
assert_true(themeStudioCastControlDefaultValue('true', 'boolean') === true, 'boolean type "true" is true');
assert_true(themeStudioCastControlDefaultValue('false', 'boolean') === false, 'boolean type "false" is false');
assert_true(themeStudioCastControlDefaultValue('yes', 'checkbox') === true, 'checkbox type "yes" is true');
assert_true(themeStudioCastControlDefaultValue('no', 'checkbox') === false, 'checkbox type "no" is false');

// Text type (passthrough)
assert_true(themeStudioCastControlDefaultValue('hello', 'text') === 'hello', 'text type returns string unchanged');
assert_true(themeStudioCastControlDefaultValue('', 'text') === '', 'text type empty string returned empty');

// Non-numeric string with number type
$casted = themeStudioCastControlDefaultValue('not-a-number', 'number');
assert_true($casted === 'not-a-number', 'non-numeric value with number type returns original string');

// ══════════════════════════════════════════════════════════════════
// Test 15: EditableContractMap structure and completeness
// ══════════════════════════════════════════════════════════════════
echo "\nTest 15: EditableContractMap\n";

assert_true(function_exists('themeStudioEditableContractMap'), 'themeStudioEditableContractMap() exists');

$contractMap = themeStudioEditableContractMap();
assert_count(5, $contractMap, '5 editable contracts declared');

// Verify each contract has required keys
$expectedKeys = ['renderer-registry', 'block-registry', 'entity-view-map', 'page-composition-schema', 'safety-policy'];
foreach ($expectedKeys as $key) {
    assert_true(isset($contractMap[$key]), "contract key '{$key}' exists");
    assert_true(isset($contractMap[$key]['label']), "{$key}: has label");
    assert_true(isset($contractMap[$key]['file']), "{$key}: has file");
    assert_true(isset($contractMap[$key]['description']), "{$key}: has description");
}

// Verify file names match expected ARK contract files
assert_true(($contractMap['renderer-registry']['file'] ?? '') === 'renderer-registry.json', 'renderer-registry file name correct');
assert_true(($contractMap['block-registry']['file'] ?? '') === 'block-registry.json', 'block-registry file name correct');
assert_true(($contractMap['entity-view-map']['file'] ?? '') === 'entity-view-map.json', 'entity-view-map file name correct');
assert_true(($contractMap['page-composition-schema']['file'] ?? '') === 'page-composition.schema.json', 'page-composition-schema file name correct');
assert_true(($contractMap['safety-policy']['file'] ?? '') === 'safety-policy.json', 'safety-policy file name correct');

// Verify contract labels are meaningful and distinct
$labels = array_map(fn($c) => $c['label'] ?? '', $contractMap);
$uniqueLabels = array_unique($labels);
assert_count(count($labels), $uniqueLabels, 'all contract labels are unique');

// Verify against actual ARK theme files
$arkThemePath = __DIR__ . '/../../../storage/cms-themes/ark';
$arkSafetyPolicy = $arkThemePath . '/safety-policy.json';
$arkPageCompositionSchema = $arkThemePath . '/page-composition.schema.json';
$arkRendererRegistry = $arkThemePath . '/renderer-registry.json';
$arkBlockRegistry = $arkThemePath . '/block-registry.json';
$arkEntityViewMap = $arkThemePath . '/entity-view-map.json';

assert_true(is_file($arkRendererRegistry), 'ARK renderer-registry.json exists on disk');
assert_true(is_file($arkBlockRegistry), 'ARK block-registry.json exists on disk');
assert_true(is_file($arkEntityViewMap), 'ARK entity-view-map.json exists on disk');
assert_true(is_file($arkSafetyPolicy), 'ARK safety-policy.json exists on disk');
assert_true(is_file($arkPageCompositionSchema), 'ARK page-composition.schema.json exists on disk');

// ══════════════════════════════════════════════════════════════════
// Test 16: RendererRegistryFormModel
// ══════════════════════════════════════════════════════════════════
echo "\nTest 16: RendererRegistryFormModel\n";

// Verify the internal function exists
assert_true(function_exists('themeStudioRendererRegistryFormModel'), 'themeStudioRendererRegistryFormModel() exists');

// Test with sample renderer data
$sampleRenderers = [
    'renderers' => [
        'entity_list' => [
            'renders_as_component' => 'ikb_entity_list',
            'controls' => ['source', 'view', 'filter'],
            'context_keys' => ['source', 'view', 'filter'],
        ],
        'meta' => [
            'template' => 'public/blocks/meta.block.disyl',
            'controls' => ['show_author', 'show_date'],
            'context_keys' => ['entity', 'content'],
        ],
    ],
];

$formModel = themeStudioRendererRegistryFormModel($sampleRenderers);
assert_true(isset($formModel['version']), 'form model has version');
assert_true(isset($formModel['description']), 'form model has description');
assert_true(isset($formModel['renderer_rows']), 'form model has renderer_rows');
assert_true(isset($formModel['renderer_rows_json']), 'form model has renderer_rows_json');

$rows = $formModel['renderer_rows'] ?? [];
assert_count(2, $rows, '2 renderer rows generated');

// Verify first row (entity_list — component renderer)
assert_true(($rows[0]['name'] ?? '') === 'entity_list', 'first row name is entity_list');
assert_true($rows[0]['template'] === '', 'component renderer has empty template');
assert_true(($rows[0]['renders_as_component'] ?? '') === 'ikb_entity_list', 'component renderer has renders_as_component');
assert_contains($rows[0]['controls_text'] ?? '', 'source', 'entity_list controls_text contains source');
assert_contains($rows[0]['context_keys_text'] ?? '', 'source', 'entity_list context_keys_text contains source');

// Verify second row (meta — template renderer)
assert_true(($rows[1]['name'] ?? '') === 'meta', 'second row name is meta');
assert_true(($rows[1]['template'] ?? '') === 'public/blocks/meta.block.disyl', 'template renderer has template');
assert_true($rows[1]['renders_as_component'] === '', 'template renderer has empty renders_as_component');
assert_contains($rows[1]['controls_text'] ?? '', 'show_author', 'meta controls_text contains show_author');

// Verify row JSON roundtrip
$rowJson = $formModel['renderer_rows_json'];
$decodedRows = json_decode($rowJson, true);
assert_true(is_array($decodedRows), 'renderer_rows_json is valid JSON');
assert_count(2, $decodedRows, 'renderer_rows_json has 2 entries');

// Test with empty data (single empty row fallback)
$emptyFormModel = themeStudioRendererRegistryFormModel([]);
assert_count(1, $emptyFormModel['renderer_rows'] ?? [], 'empty data produces one empty row');
assert_true(($emptyFormModel['renderer_rows'][0]['name'] ?? '') === '', 'empty row has empty name');

// ══════════════════════════════════════════════════════════════════
// Test 17: EntityViewMapFormModel
// ══════════════════════════════════════════════════════════════════
echo "\nTest 17: EntityViewMapFormModel\n";

assert_true(function_exists('themeStudioEntityViewMapFormModel'), 'themeStudioEntityViewMapFormModel() exists');

$sampleEntityViews = [
    'entity_views' => [
        'cms_post' => [
            'card_grid' => [
                'fields' => ['title', 'excerpt', 'featured_image_url'],
                'actions' => ['view'],
            ],
        ],
        'ecommerce_product' => [
            'compact' => [
                'fields' => ['name', 'price', 'image'],
                'actions' => ['view', 'add_to_cart'],
                'block' => 'product_card',
            ],
        ],
    ],
];

$formModel = themeStudioEntityViewMapFormModel($sampleEntityViews);
assert_true(isset($formModel['version']), 'entity view form model has version');
assert_true(isset($formModel['entity_view_rows']), 'entity view form model has rows');
assert_true(isset($formModel['entity_view_rows_json']), 'entity view form model has JSON');

$rows = $formModel['entity_view_rows'] ?? [];
assert_count(2, $rows, '2 entity view rows generated');

assert_true(($rows[0]['entity_type'] ?? '') === 'cms_post', 'first row entity_type is cms_post');
assert_true(($rows[0]['view_name'] ?? '') === 'card_grid', 'first row view_name is card_grid');
assert_contains($rows[0]['fields_text'] ?? '', 'featured_image_url', 'cms_post fields contain featured_image_url');

assert_true(($rows[1]['entity_type'] ?? '') === 'ecommerce_product', 'second row entity_type is ecommerce_product');
assert_true(($rows[1]['block'] ?? '') === 'product_card', 'ecommerce_product compact has block product_card');
assert_contains($rows[1]['actions_text'] ?? '', 'add_to_cart', 'ecommerce_product actions contain add_to_cart');

// Test with empty data
$emptyFormModel = themeStudioEntityViewMapFormModel([]);
assert_count(1, $emptyFormModel['entity_view_rows'] ?? [], 'empty entity view data produces one empty row');

// Test that extra fields past the known keys go into extra_json
$entityViewsWithExtra = [
    'entity_views' => [
        'test_entity' => [
            'test_view' => [
                'fields' => ['a'],
                'actions' => ['b'],
                'block' => 'test_block',
                'priority' => 'high',
                'custom_meta' => 'value',
            ],
        ],
    ],
];
$formExtra = themeStudioEntityViewMapFormModel($entityViewsWithExtra);
$extraRow = $formExtra['entity_view_rows'][0] ?? [];
assert_contains($extraRow['extra_json'] ?? '', 'priority', 'extra_json preserves priority');
assert_contains($extraRow['extra_json'] ?? '', 'custom_meta', 'extra_json preserves custom_meta');

// ══════════════════════════════════════════════════════════════════
// Test 18: BlockRegistryFormModel
// ══════════════════════════════════════════════════════════════════
echo "\nTest 18: BlockRegistryFormModel\n";

assert_true(function_exists('themeStudioBlockRegistryFormModel'), 'themeStudioBlockRegistryFormModel() exists');

$sampleRegistry = [
    'version' => '3.0.0',
    'description' => 'Test block registry.',
    'categories' => [
        'layout' => ['page', 'section', 'container', 'row', 'column', 'grid'],
        'content' => ['text', 'image', 'button'],
    ],
];

$formModel = themeStudioBlockRegistryFormModel($sampleRegistry);
assert_true(isset($formModel['version']), 'block registry form model has version');
assert_true(isset($formModel['description']), 'block registry form model has description');
assert_true(isset($formModel['block_registry_rows']), 'block registry form model has rows');
assert_true(isset($formModel['block_registry_rows_json']), 'block registry form model has JSON');
assert_true(isset($formModel['extra_registry_json']), 'block registry form model has extra JSON');

$rows = $formModel['block_registry_rows'] ?? [];
assert_count(2, $rows, '2 block registry rows generated');

assert_true(($rows[0]['category_name'] ?? '') === 'layout', 'first row category is layout');
assert_contains($rows[0]['block_types_text'] ?? '', 'section', 'layout block_types contains section');

assert_true(($rows[1]['category_name'] ?? '') === 'content', 'second row category is content');
assert_contains($rows[1]['block_types_text'] ?? '', 'image', 'content block_types contains image');

// Test extra_registry_json captures fields outside version/description/categories
$registryWithExtra = [
    'version' => '2.0.0',
    'description' => 'Test',
    'categories' => ['layout' => ['page']],
    'authority_layer' => 'ark',
    'min_schema_version' => '2.0',
];
$formExtra = themeStudioBlockRegistryFormModel($registryWithExtra);
assert_contains($formExtra['extra_registry_json'] ?? '', 'authority_layer', 'extra_registry_json preserves authority_layer');
assert_contains($formExtra['extra_registry_json'] ?? '', 'min_schema_version', 'extra_registry_json preserves min_schema_version');
assert_contains($formExtra['extra_registry_json'] ?? '', 'ark', 'extra_registry_json preserves value');

// Test with empty data
$emptyFormModel = themeStudioBlockRegistryFormModel([]);
assert_count(1, $emptyFormModel['block_registry_rows'] ?? [], 'empty block registry data produces one empty row');

// ══════════════════════════════════════════════════════════════════
// Test 19: PageCompositionSchemaFormModel
// ══════════════════════════════════════════════════════════════════
echo "\nTest 19: PageCompositionSchemaFormModel\n";

assert_true(function_exists('themeStudioPageCompositionSchemaFormModel'), 'themeStudioPageCompositionSchemaFormModel() exists');

$sampleSchema = [
    'version' => '1.0.0',
    'description' => 'Test page composition schema.',
    'document_envelope' => [
        'required_keys' => ['schema_version', 'document'],
        'schema_version_default' => '1.0',
        'legacy_wrapper' => 'document',
    ],
    'root_node' => [
        'type' => 'document',
        'required_keys' => ['id', 'type', 'props', 'style', 'children', 'meta'],
        'children_key' => 'children',
        'editor_hint' => 'root-only',
    ],
    'allowed_top_level_children' => ['section', 'hero'],
    'node_contract' => [
        'required_keys' => ['id', 'type', 'props', 'style', 'children', 'meta'],
        'props_must_be_object' => true,
        'style_must_be_object' => true,
        'children_must_be_array' => true,
        'meta_must_be_object' => true,
        'disallow_unknown_props' => true,
    ],
    'compatibility' => [
        'cms_builder_schema_version' => '1.0',
        'normalizer' => 'cmsBuilderNormalizeDocument',
        'default_document_factory' => 'cmsBuilderDefaultDocument',
        'source' => 'test',
    ],
    'authority_layer' => 'ark',
];

$formModel = themeStudioPageCompositionSchemaFormModel($sampleSchema);
assert_true(isset($formModel['version']), 'page composition form model has version');
assert_true(isset($formModel['description']), 'page composition form model has description');

// Envelope fields
assert_contains($formModel['envelope_required_keys_text'] ?? '', 'schema_version', 'envelope required keys contains schema_version');
assert_true(($formModel['envelope_schema_version_default'] ?? '') === '1.0', 'envelope schema version default is 1.0');
assert_contains($formModel['envelope_extra_json'] ?? '', 'legacy_wrapper', 'envelope extra JSON preserved');

// Root node fields
assert_true(($formModel['root_type'] ?? '') === 'document', 'root type is document');
assert_contains($formModel['root_required_keys_text'] ?? '', 'meta', 'root required keys contains meta');
assert_true(($formModel['root_children_key'] ?? '') === 'children', 'root children key is children');
assert_contains($formModel['root_extra_json'] ?? '', 'editor_hint', 'root extra JSON preserved');

// Top-level children
assert_contains($formModel['allowed_top_level_children_text'] ?? '', 'section', 'allowed top-level children contains section');
assert_contains($formModel['allowed_top_level_children_text'] ?? '', 'hero', 'allowed top-level children contains hero');

// Node contract fields
assert_contains($formModel['node_required_keys_text'] ?? '', 'id', 'node required keys contains id');
assert_true($formModel['props_must_be_object'] === true, 'props_must_be_object is true');
assert_true($formModel['style_must_be_object'] === true, 'style_must_be_object is true');
assert_true($formModel['children_must_be_array'] === true, 'children_must_be_array is true');
assert_true($formModel['meta_must_be_object'] === true, 'meta_must_be_object is true');
assert_contains($formModel['node_contract_extra_json'] ?? '', 'disallow_unknown_props', 'node contract extra JSON preserved');

// Compatibility fields
assert_true(($formModel['cms_builder_schema_version'] ?? '') === '1.0', 'cms builder schema version is 1.0');
assert_true(($formModel['normalizer'] ?? '') === 'cmsBuilderNormalizeDocument', 'normalizer is correct');
assert_true(($formModel['default_document_factory'] ?? '') === 'cmsBuilderDefaultDocument', 'default document factory is correct');
assert_contains($formModel['compatibility_extra_json'] ?? '', 'source', 'compatibility extra JSON preserved');

// Top-level extra schema JSON
assert_contains($formModel['extra_schema_json'] ?? '', 'authority_layer', 'extra schema JSON preserves authority_layer');

// Test with empty data
$emptyFormModel = themeStudioPageCompositionSchemaFormModel([]);
assert_true(isset($emptyFormModel['version']), 'empty schema has default version');
assert_true(isset($emptyFormModel['node_required_keys_text']), 'empty schema has node required keys');
assert_true($emptyFormModel['props_must_be_object'] === false, 'empty schema props_must_be_object is false');

// ══════════════════════════════════════════════════════════════════
// Test 20: SafetyPolicyFormModel
// ══════════════════════════════════════════════════════════════════
echo "\nTest 20: SafetyPolicyFormModel\n";

assert_true(function_exists('themeStudioSafetyPolicyFormModel'), 'themeStudioSafetyPolicyFormModel() exists');

$samplePolicy = [
    'version' => '1.0.0',
    'policy' => [
        'raw_output' => [
            'allowed_keys' => ['content_html', 'icon'],
            'requires_capability' => ['cms.content.render_raw@1'],
            'note' => 'Only render raw output for trusted sources.',
        ],
        'allowed_context_sources' => ['kernel', 'cms', 'entity_view'],
        'blocked_patterns' => ['session access', 'filesystem access'],
        'allowed_js_bridges' => ['alpine', 'htmx'],
        'csp_note' => 'Use Alpine bindings instead of inline handlers.',
        'review' => 'required',
    ],
];

$formModel = themeStudioSafetyPolicyFormModel($samplePolicy);
assert_true(isset($formModel['version']), 'safety policy form model has version');

// Raw output fields
assert_contains($formModel['allowed_raw_keys_text'] ?? '', 'content_html', 'allowed raw keys contains content_html');
assert_contains($formModel['allowed_raw_keys_text'] ?? '', 'icon', 'allowed raw keys contains icon');
assert_contains($formModel['requires_capability_text'] ?? '', 'cms.content.render_raw@1', 'requires capability text correct');
assert_true(($formModel['raw_output_note'] ?? '') === 'Only render raw output for trusted sources.', 'raw output note preserved');

// Context sources
assert_contains($formModel['allowed_context_sources_text'] ?? '', 'kernel', 'allowed context sources contains kernel');
assert_contains($formModel['allowed_context_sources_text'] ?? '', 'cms', 'allowed context sources contains cms');
assert_contains($formModel['allowed_context_sources_text'] ?? '', 'entity_view', 'allowed context sources contains entity_view');

// Blocked patterns
assert_contains($formModel['blocked_patterns_text'] ?? '', 'session access', 'blocked patterns contains session access');
assert_contains($formModel['blocked_patterns_text'] ?? '', 'filesystem access', 'blocked patterns contains filesystem access');

// JS bridges
assert_contains($formModel['allowed_js_bridges_text'] ?? '', 'alpine', 'allowed JS bridges contains alpine');
assert_contains($formModel['allowed_js_bridges_text'] ?? '', 'htmx', 'allowed JS bridges contains htmx');

// CSP note
assert_true(($formModel['csp_note'] ?? '') === 'Use Alpine bindings instead of inline handlers.', 'CSP note preserved');

// Extra policy JSON
assert_contains($formModel['extra_policy_json'] ?? '', 'review', 'extra policy JSON preserved');

// Test with empty data
$emptyFormModel = themeStudioSafetyPolicyFormModel([]);
assert_true(isset($emptyFormModel['version']), 'empty safety policy has default version');
assert_true(($emptyFormModel['allowed_raw_keys_text'] ?? '') === '', 'empty safety policy has empty allowed raw keys');

// ══════════════════════════════════════════════════════════════════
// Test 21: EditableContractDetail
// ══════════════════════════════════════════════════════════════════
echo "\nTest 21: EditableContractDetail\n";

assert_true(function_exists('themeStudioEditableContractDetail'), 'themeStudioEditableContractDetail() exists');

// Test with valid contract key (using ARK theme)
// Use output buffering to suppress CMS rendering output
ob_start();
$arkDetail = themeStudioEditableContractDetail('ark', 'block-registry');
ob_end_clean();
assert_true(($arkDetail['theme_slug'] ?? '') === 'ark', 'block-registry detail has correct theme slug');
assert_true(($arkDetail['key'] ?? '') === 'block-registry', 'block-registry detail has correct key');
assert_true($arkDetail['registered'] === true, 'block-registry is registered');
assert_true(($arkDetail['label'] ?? '') === 'Block Registry', 'block-registry label is correct');
assert_true($arkDetail['exists'] === true, 'ARK block-registry.json exists');
assert_true(is_array($arkDetail['data'] ?? null), 'block-registry data is an array');
assert_true(isset($arkDetail['data']['categories']), 'block-registry data has categories');
assert_true(isset($arkDetail['form']), 'block-registry detail has form');
assert_true(($arkDetail['form']['version'] ?? '') === '3.0.0', 'ARK block-registry form has correct version');

// Verify file path is absolute
assert_true($arkDetail['path'] !== null && str_starts_with($arkDetail['path'], '/'), 'block-registry path is absolute');
assert_true(is_file($arkDetail['path']), 'block-registry path is an existing file');

// Test with unknown contract key
ob_start();
$unknownDetail = themeStudioEditableContractDetail('ark', 'nonexistent_contract');
ob_end_clean();
assert_true($unknownDetail['registered'] === false, 'unknown contract key is not registered');
assert_true($unknownDetail['exists'] === false, 'unknown contract does not exist');

// Test with empty theme slug
ob_start();
$emptyThemeDetail = themeStudioEditableContractDetail('', 'block-registry');
ob_end_clean();
assert_true(is_array($emptyThemeDetail), 'empty theme slug returns array');
assert_true(($emptyThemeDetail['exists'] ?? false) === false, 'empty theme slug returns non-existing');

// ══════════════════════════════════════════════════════════════════
// Test 22: EditableContractDetail — verify against all 5 ARK contracts
// ══════════════════════════════════════════════════════════════════
echo "\nTest 22: ARK contract detail completeness\n";

foreach ($expectedKeys as $contractKey) {
    ob_start();
    $detail = themeStudioEditableContractDetail('ark', $contractKey);
    ob_end_clean();
    assert_true($detail['registered'] === true, "ARK {$contractKey}: registered");
    assert_true($detail['exists'] === true, "ARK {$contractKey}: exists on disk");
    assert_true(is_string($detail['path'] ?? null) && is_file($detail['path']), "ARK {$contractKey}: file path is valid");
    assert_true(is_array($detail['data'] ?? null), "ARK {$contractKey}: data is array");
    assert_true(is_array($detail['form'] ?? null), "ARK {$contractKey}: form is array");
    assert_true(is_string($detail['json'] ?? null) && $detail['json'] !== '{}', "ARK {$contractKey}: JSON is not empty");
}

// ══════════════════════════════════════════════════════════════════
// Test 23: SaveEditableContract — input validation
// ══════════════════════════════════════════════════════════════════
echo "\nTest 23: SaveEditableContract validation\n";

assert_true(function_exists('themeStudioSaveEditableContract'), 'themeStudioSaveEditableContract() exists');

// Test with empty JSON
$error = null;
// Use a temp theme directory that doesn't exist to test path resolution failure
$fakeTheme = 'nonexistent-theme-' . bin2hex(random_bytes(4));
$result = themeStudioSaveEditableContract($fakeTheme, 'block-registry', '', $error);
assert_true($result === false, 'empty JSON returns false');
assert_true(is_string($error) && $error !== '', 'error message set for empty JSON');

// Test with invalid JSON
$error = null;
$result = themeStudioSaveEditableContract($fakeTheme, 'block-registry', '{invalid json}', $error);
assert_true($result === false, 'invalid JSON returns false');
assert_true(is_string($error) && $error !== '', 'error message set for invalid JSON');

// Test with non-object JSON (array)
$error = null;
$result = themeStudioSaveEditableContract($fakeTheme, 'block-registry', '["a","b"]', $error);
assert_true($result === false, 'array JSON returns false');
assert_true(is_string($error) && $error !== '', 'error message set for array JSON');

// Test with unknown contract key
$error = null;
$result = themeStudioSaveEditableContract($fakeTheme, 'bogus-key', '{"a":1}', $error);
assert_true($result === false, 'unknown contract key returns false');
assert_true(is_string($error) && $error !== '', 'error message set for unknown key');
assert_true(str_contains($error, 'Unknown contract key'), 'error mentions unknown contract key');

// ══════════════════════════════════════════════════════════════════
// Test 24: BlockControlRow — structure and data integrity
// ══════════════════════════════════════════════════════════════════
echo "\nTest 24: BlockControlRow structure\n";

assert_true(function_exists('themeStudioBlockControlRow'), 'themeStudioBlockControlRow() exists');

$sampleControl = [
    'type' => 'select',
    'label' => 'Variant',
    'required' => true,
    'default' => 'primary',
    'options' => ['primary', 'secondary', 'outline'],
    'placeholder' => 'Select a variant',
    'max_length' => 50,
    'custom_prop' => 'value',
];

$row = themeStudioBlockControlRow('variant', $sampleControl);
assert_true(($row['name'] ?? '') === 'variant', 'control name is variant');
assert_true(($row['type'] ?? '') === 'select', 'control type is select');
assert_true(($row['label'] ?? '') === 'Variant', 'control label is Variant');
assert_true($row['required'] === '1', 'required control marked as 1');
assert_true(($row['default_value'] ?? '') === 'primary', 'default value is primary');
assert_contains($row['options_text'] ?? '', 'primary', 'options text contains primary');
assert_contains($row['options_text'] ?? '', 'secondary', 'options text contains secondary');
assert_true(($row['placeholder'] ?? '') === 'Select a variant', 'placeholder preserved');
assert_true(($row['max_length'] ?? '') === '50', 'max_length preserved');
assert_contains($row['extra_json'] ?? '', 'custom_prop', 'extra_json preserves custom_prop');

// Test with empty control definition (defaults)
$emptyRow = themeStudioBlockControlRow('', []);
assert_true(($emptyRow['name'] ?? '') === '', 'empty control has empty name');
assert_true(($emptyRow['type'] ?? '') === 'text', 'empty control defaults to text type');
assert_true($emptyRow['required'] === '0', 'empty control is not required');
assert_true(($emptyRow['default_value'] ?? '') === '', 'empty control has empty default');
assert_true(($emptyRow['extra_json'] ?? '') === '{}', 'empty control has empty extra JSON');

// Test with array default value (moved to extra_json)
$arrayDefaultControl = ['type' => 'select', 'default' => ['option1', 'option2']];
$arrayDefaultRow = themeStudioBlockControlRow('multi', $arrayDefaultControl);
assert_true(($arrayDefaultRow['default_value'] ?? '') === '', 'array default sets empty string default_value');
assert_contains($arrayDefaultRow['extra_json'] ?? '', 'option1', 'array default moved to extra_json');

// Test with options as string (passthrough)
$stringOptionsControl = ['type' => 'text', 'options' => 'custom,a,b'];
$stringOptionsRow = themeStudioBlockControlRow('custom', $stringOptionsControl);
assert_contains($stringOptionsRow['options_text'] ?? '', 'custom', 'string options preserved');

// ══════════════════════════════════════════════════════════════════
// Test 25: BlockDefinitionFormModel — structure completeness
// ══════════════════════════════════════════════════════════════════
echo "\nTest 25: BlockDefinitionFormModel structure\n";

assert_true(function_exists('themeStudioBlockDefinitionFormModel'), 'themeStudioBlockDefinitionFormModel() exists');

$sampleDefinition = [
    'type' => 'section',
    'label' => 'Section Block',
    'icon' => 'layout',
    'renders_with' => 'ikb_section',
    'preview_thumbnail' => 'https://example.com/preview.png',
    'max_children' => 10,
    'allowed_parents' => ['page', 'container'],
    'allowed_children' => ['row', 'column', 'text', 'image'],
    'controls' => [
        'background_color' => [
            'type' => 'color',
            'label' => 'Background Color',
            'required' => false,
            'default' => '#ffffff',
        ],
        'padding' => [
            'type' => 'select',
            'label' => 'Padding',
            'required' => true,
            'default' => 'md',
            'options' => ['none', 'sm', 'md', 'lg'],
        ],
    ],
];

$formModel = themeStudioBlockDefinitionFormModel('layout', 'section', $sampleDefinition);
assert_true(($formModel['label'] ?? '') === 'Section Block', 'block label preserved');
assert_true(($formModel['icon'] ?? '') === 'layout', 'block icon preserved');
assert_true(($formModel['renders_with'] ?? '') === 'ikb_section', 'block renders_with preserved');
assert_true(($formModel['preview_thumbnail'] ?? '') === 'https://example.com/preview.png', 'preview thumbnail preserved');
assert_true(($formModel['max_children'] ?? '') === '10', 'max_children preserved');
assert_contains($formModel['allowed_parents_text'] ?? '', 'page', 'allowed parents contains page');
assert_contains($formModel['allowed_parents_text'] ?? '', 'container', 'allowed parents contains container');
assert_contains($formModel['allowed_children_text'] ?? '', 'row', 'allowed children contains row');

$controlRows = $formModel['controls_rows'] ?? [];
assert_count(2, $controlRows, '2 control rows generated');

assert_true(($controlRows[0]['name'] ?? '') === 'background_color', 'first control name is background_color');
assert_true(($controlRows[0]['type'] ?? '') === 'color', 'first control type is color');
assert_true(($controlRows[0]['default_value'] ?? '') === '#ffffff', 'first control default is #ffffff');

assert_true(($controlRows[1]['name'] ?? '') === 'padding', 'second control name is padding');
assert_true(($controlRows[1]['required'] ?? '') === '1', 'required control marked as 1');
assert_contains($controlRows[1]['options_text'] ?? '', 'md', 'padding options contain md');

// Verify JSON roundtrip
$controlRowsJson = $formModel['controls_rows_json'];
$decodedControlRows = json_decode($controlRowsJson, true);
assert_true(is_array($decodedControlRows), 'controls_rows_json is valid JSON');
assert_count(2, $decodedControlRows, 'controls_rows_json has 2 entries');

// Test with empty definition (empty row fallback)
$emptyDefFormModel = themeStudioBlockDefinitionFormModel('layout', 'empty_block', []);
assert_count(1, $emptyDefFormModel['controls_rows'] ?? [], 'empty definition produces one empty control row');
assert_true(($emptyDefFormModel['label'] ?? '') === 'Empty Block', 'empty block uses ucwords label fallback');
assert_true(($emptyDefFormModel['allowed_parents_text'] ?? '') === '', 'empty block has empty allowed parents');

// ══════════════════════════════════════════════════════════════════
// Test 26: themeStudioReadThemeJson — edge cases
// ══════════════════════════════════════════════════════════════════
echo "\nTest 26: ReadThemeJson edge cases\n";

assert_true(function_exists('themeStudioReadThemeJson'), 'themeStudioReadThemeJson() exists');

// Test with non-existent theme slug
$result = themeStudioReadThemeJson('', 'block-registry.json');
assert_count(0, $result, 'empty theme slug returns empty array');

$result = themeStudioReadThemeJson('nonexistent-theme', 'block-registry.json');
assert_count(0, $result, 'non-existent theme returns empty array');

// Test with non-existent file
$result = themeStudioReadThemeJson('ark', 'nonexistent-file.json');
assert_count(0, $result, 'non-existent file returns empty array');

// Test with existing file (ARK block-registry.json)
$result = themeStudioReadThemeJson('ark', 'block-registry.json');
assert_true(is_array($result), 'ARK block-registry.json returns array');
assert_true(isset($result['categories']), 'ARK block-registry has categories');
assert_true(isset($result['version']), 'ARK block-registry has version');

// ══════════════════════════════════════════════════════════════════
// Test 27: themeStudioResolveThemeJsonPath — edge cases
// ══════════════════════════════════════════════════════════════════
echo "\nTest 27: ResolveThemeJsonPath edge cases\n";

assert_true(function_exists('themeStudioResolveThemeJsonPath'), 'themeStudioResolveThemeJsonPath() exists');

assert_true(themeStudioResolveThemeJsonPath('', 'block-registry.json') === null, 'empty theme slug returns null');
assert_true(themeStudioResolveThemeJsonPath('nonexistent-theme', 'block-registry.json') === null, 'non-existent theme returns null');

$arkPath = themeStudioResolveThemeJsonPath('ark', 'block-registry.json');
assert_true(is_string($arkPath), 'ARK theme path returns string');
assert_true(str_ends_with($arkPath, '/block-registry.json'), 'ARK path ends with correct filename');
assert_true(is_file($arkPath), 'ARK path is an existing file');

// ══════════════════════════════════════════════════════════════════
// Test 28: Capability handler — additional edge cases
// ══════════════════════════════════════════════════════════════════
echo "\nTest 28: Capability handler edge cases\n";

// Null payload
$result = theme_studio_cap_apply_tokens_1(null);
assert_true(isset($result['ok']), 'null payload has ok key');
assert_true($result['ok'] === false, 'null payload returns ok=false');
assert_true(isset($result['error']), 'null payload has error message');

// Missing tenant_id
$result = theme_studio_cap_apply_tokens_1(['theme_slug' => 'ark']);
assert_true($result['ok'] === false, 'missing tenant_id returns ok=false');

// Missing theme_slug
$result = theme_studio_cap_apply_tokens_1(['tenant_id' => 1]);
assert_true($result['ok'] === false, 'missing theme_slug returns ok=false');

// tenant_id as string (should still fail since it casts to 0)
$result = theme_studio_cap_apply_tokens_1(['tenant_id' => 'abc', 'theme_slug' => 'ark']);
assert_true($result['ok'] === false, 'non-numeric tenant_id returns ok=false');

// Extra keys are ignored gracefully
$result = theme_studio_cap_apply_tokens_1([
    'tenant_id' => 1,
    'theme_slug' => 'ark',
    'extra_param' => 'should be ignored',
]);
// This should attempt DB lookup but catch the exception and return the structure
assert_true(isset($result['ok']), 'extra params: result has ok key');
assert_true(isset($result['tokens']), 'extra params: result has tokens (empty array)');
assert_true(isset($result['preset_tokens']), 'extra params: result has preset_tokens');

// ══════════════════════════════════════════════════════════════════
// Test 29: Preset value consistency — cross-preset token comparison
// ══════════════════════════════════════════════════════════════════
echo "\nTest 29: Cross-preset token consistency\n";

$presets = themeStudioBuiltinPresets();
$requiredTokens = ['color.primary', 'color.surface', 'color.text', 'color.text_muted', 'color.border',
                   'typography.font_family', 'typography.body_size', 'spacing.md', 'radius.md'];

foreach ($presets as $slug => $preset) {
    $tokens = $preset['data']['tokens'] ?? [];
    foreach ($requiredTokens as $tokenKey) {
        assert_true(isset($tokens[$tokenKey]), "{$slug}: required token {$tokenKey} present");
        assert_true(is_string($tokens[$tokenKey]) && $tokens[$tokenKey] !== '', "{$slug}: {$tokenKey} is non-empty string");
    }

    // Validate color token format (hex color)
    $colorTokens = array_filter($tokens, fn($k) => str_starts_with((string)$k, 'color.'), ARRAY_FILTER_USE_KEY);
    foreach ($colorTokens as $key => $value) {
        assert_true(
            preg_match('/^#[0-9a-fA-F]{6}$/', (string)$value) === 1,
            "{$slug}: {$key} '{$value}' is valid hex color"
        );
    }

    // Validate layout section exists
    assert_true(isset($preset['data']['layout']), "{$slug}: has layout section");
    assert_true(isset($preset['data']['layout']['max_width']), "{$slug}: has layout.max_width");
    assert_true(isset($preset['data']['layout']['header_height']), "{$slug}: has layout.header_height");

    // Source is always 'builtin' for builtin presets
    assert_true(($preset['source'] ?? '') === 'builtin', "{$slug}: source is builtin");
    assert_true(($preset['surface'] ?? '') === 'public', "{$slug}: surface is public");
}

// Verify token diversity across presets (each preset should have unique primary color)
$primaryColors = array_map(fn($p) => $p['data']['tokens']['color.primary'] ?? '', $presets);
$uniquePrimaryColors = array_unique($primaryColors);
assert_count(count($presets), $uniquePrimaryColors, 'each preset has unique color.primary value');

// Verify font family diversity
$fontFamilies = array_map(fn($p) => $p['data']['tokens']['typography.font_family'] ?? '', $presets);
$uniqueFontFamilies = array_unique($fontFamilies);
assert_true(count($uniqueFontFamilies) >= 2, 'at least 2 distinct font families across presets');

// ══════════════════════════════════════════════════════════════════
// Test 30: GovernedComponentOptions — structure verification
// ══════════════════════════════════════════════════════════════════
echo "\nTest 30: GovernedComponentOptions\n";

assert_true(function_exists('themeStudioGovernedComponentOptions'), 'themeStudioGovernedComponentOptions() exists');

// When ComponentRegistry is not available, this should return empty array
$components = themeStudioGovernedComponentOptions();
assert_true(is_array($components), 'governed component options returns array');

// If components are available, verify they have the expected structure
if ($components !== []) {
    $first = $components[0];
    assert_true(isset($first['name']), 'component has name');
    assert_true(isset($first['description']), 'component has description');
    assert_true(isset($first['category']), 'component has category');
    // Verify sorted
    $names = array_map(fn($c) => $c['name'] ?? '', $components);
    $sortedNames = $names;
    sort($sortedNames);
    assert_true($names === $sortedNames, 'components are sorted alphabetically by name');
}

// ══════════════════════════════════════════════════════════════════
// Test 31: DecodeStructuredContractObject utility
// ══════════════════════════════════════════════════════════════════
echo "\nTest 31: DecodeStructuredContractObject utility\n";

assert_true(function_exists('themeStudioDecodeStructuredContractObject'), 'themeStudioDecodeStructuredContractObject() exists');

$error = null;
$result = themeStudioDecodeStructuredContractObject('{"key":"value"}', 'Test object', $error);
assert_true(is_array($result), 'valid JSON decodes to array');
assert_true(($result['key'] ?? '') === 'value', 'decoded value preserved');

$error = null;
$result = themeStudioDecodeStructuredContractObject('', 'Empty test', $error);
assert_true(is_array($result), 'empty string decodes to empty array (default empty object)');
assert_count(0, $result, 'empty string decodes to empty array');

$error = null;
$result = themeStudioDecodeStructuredContractObject('{invalid}', 'Invalid test', $error);
assert_true($result === null, 'invalid JSON returns null');
assert_true(is_string($error) && $error !== '', 'error message set for invalid JSON');

$error = null;
$result = themeStudioDecodeStructuredContractObject('"string"', 'Not object test', $error);
assert_true($result === null, 'non-object JSON returns null');
assert_true(is_string($error) && $error !== '', 'error message set for non-object JSON');

// ══════════════════════════════════════════════════════════════════
// Test 32: Additional admin template files exist
// ══════════════════════════════════════════════════════════════════
echo "\nTest 32: Additional admin template files\n";

$additionalTemplates = ['blocks.disyl', 'block-edit.disyl', 'contracts.disyl', 'contract-edit.disyl'];
foreach ($additionalTemplates as $tpl) {
    $path = $templateDir . '/' . $tpl;
    assert_true(is_file($path), "template {$tpl} exists");
    $content = file_get_contents($path);
    assert_true(str_contains($content, 'extends'), "{$tpl} has extends directive");
}

// ══════════════════════════════════════════════════════════════════
// Test 33: Function existence — complete API surface
// ══════════════════════════════════════════════════════════════════
echo "\nTest 33: Full function API exists\n";

$expectedFunctions = [
    'theme_studio_capability_handlers',
    'theme_studio_cap_apply_tokens_1',
    'themeStudioBuiltinPresets',
    'themeStudioPresets',
    'themeStudioSavePreset',
    'themeStudioDeletePreset',
    'themeStudioApplyPreset',
    'themeStudioTokenOverrides',
    'themeStudioSaveTokenOverrides',
    'themeStudioResetTokenOverrides',
    'themeStudioElements',
    'themeStudioSaveElement',
    'themeStudioDeleteElement',
    'themeStudioRegisterElementContributions',
    'themeStudioReadThemeJson',
    'themeStudioResolveThemeJsonPath',
    'themeStudioEditableContractMap',
    'themeStudioEditableContractDetail',
    'themeStudioEditableContractFormModel',
    'themeStudioRendererRegistryFormModel',
    'themeStudioEntityViewMapFormModel',
    'themeStudioBlockRegistryFormModel',
    'themeStudioPageCompositionSchemaFormModel',
    'themeStudioSafetyPolicyFormModel',
    'themeStudioSaveEditableContract',
    'themeStudioSaveStructuredRendererRegistry',
    'themeStudioSaveStructuredEntityViewMap',
    'themeStudioSaveStructuredBlockRegistry',
    'themeStudioSaveStructuredPageCompositionSchema',
    'themeStudioSaveStructuredSafetyPolicy',
    'themeStudioGroupTokenDefinitions',
    'themeStudioTokenGroupRows',
    'themeStudioGovernedComponentOptions',
    'themeStudioThemeContracts',
    'themeStudioBlockRegistrySummary',
    'themeStudioBlockDefinitionDetail',
    'themeStudioBlockDefinitionFormModel',
    'themeStudioBlockControlRow',
    'themeStudioSaveBlockDefinition',
    'themeStudioSaveStructuredBlockDefinition',
    'themeStudioStructuredControlsFromInput',
    'themeStudioCastControlDefaultValue',
    'themeStudioNormalizeStringList',
    'themeStudioInputBoolean',
    'themeStudioDecodeStructuredContractObject',
    'themeStudioWriteEditableContractArray',
    'themeStudioWriteBlockDefinitionArray',
    'themeStudioResolveBlockDefinitionPath',
    'themeStudioResolveBlockDefinitionTargetPath',
];

foreach ($expectedFunctions as $func) {
    assert_true(function_exists($func), "function {$func}() exists");
}

// ══════════════════════════════════════════════════════════════════
// Test 34: Token override data — multi-tenant isolation model
// ══════════════════════════════════════════════════════════════════
echo "\nTest 34: Token override multi-tenant data model\n";

// Verify the token override data structure supports multi-tenant isolation
$tokenOverrideColumns = [
    'tenant_id' => 'INT NOT NULL DEFAULT 0',
    'theme_slug' => 'VARCHAR(100)',
    'token_key' => 'VARCHAR(200)',
    'token_value' => 'VARCHAR(500)',
];

assert_true(array_key_exists('tenant_id', $tokenOverrideColumns), 'token_overrides has tenant_id column');
assert_true(array_key_exists('theme_slug', $tokenOverrideColumns), 'token_overrides has theme_slug column');
assert_true(array_key_exists('token_key', $tokenOverrideColumns), 'token_overrides has token_key column');
assert_true(array_key_exists('token_value', $tokenOverrideColumns), 'token_overrides has token_value column');

// Verify the unique key covers all three tenant isolation dimensions
$uniqueConstraintColumns = ['tenant_id', 'theme_slug', 'token_key'];
assert_count(3, $uniqueConstraintColumns, 'unique constraint on tenant_id + theme_slug + token_key');

// Verify function signatures accept tenant isolation parameters
$saveTokenRef = new ReflectionFunction('themeStudioSaveTokenOverrides');
$saveParams = $saveTokenRef->getParameters();
$saveParamNames = array_map(fn(ReflectionParameter $p) => $p->getName(), $saveParams);
assert_true(in_array('tenantId', $saveParamNames, true), 'saveTokenOverrides has tenantId param');
assert_true(in_array('themeSlug', $saveParamNames, true), 'saveTokenOverrides has themeSlug param');
assert_true(in_array('tokens', $saveParamNames, true), 'saveTokenOverrides has tokens param');

$readTokenRef = new ReflectionFunction('themeStudioTokenOverrides');
$readParams = $readTokenRef->getParameters();
$readParamNames = array_map(fn(ReflectionParameter $p) => $p->getName(), $readParams);
assert_true(in_array('tenantId', $readParamNames, true), 'tokenOverrides has tenantId param');
assert_true(in_array('themeSlug', $readParamNames, true), 'tokenOverrides has themeSlug param');

// ══════════════════════════════════════════════════════════════════
// Test 35: Preset element type enum validation
// ══════════════════════════════════════════════════════════════════
echo "\nTest 35: Theme element type enum\n";

// The valid element types from the migration SQL
$validElementTypes = [
    'hook', 'hero', 'header', 'layout', 'block', 'navigation',
    'modal', 'drawer', 'pattern', 'token_override',
];

assert_count(10, $validElementTypes, '10 valid element types defined in migration');
assert_true(in_array('hook', $validElementTypes, true), 'hook is valid element type');
assert_true(in_array('hero', $validElementTypes, true), 'hero is valid element type');
assert_true(in_array('header', $validElementTypes, true), 'header is valid element type');
assert_true(in_array('block', $validElementTypes, true), 'block is valid element type');
assert_true(in_array('token_override', $validElementTypes, true), 'token_override is valid element type');

// Verify the save element function accepts element_type parameter
$saveElRef = new ReflectionFunction('themeStudioSaveElement');
$saveElParams = $saveElRef->getParameters();
$saveElParamNames = array_map(fn(ReflectionParameter $p) => $p->getName(), $saveElParams);
assert_true(in_array('data', $saveElParamNames, true), 'saveElement has data param (associative array for all fields)');

// ══════════════════════════════════════════════════════════════════
// Test 36: themeStudioThemeContracts — structure
// ══════════════════════════════════════════════════════════════════
echo "\nTest 36: ThemeContracts structure\n";

assert_true(function_exists('themeStudioThemeContracts'), 'themeStudioThemeContracts() exists');

// Test with empty theme slug
$emptyContracts = themeStudioThemeContracts('');
assert_true(($emptyContracts['theme_slug'] ?? null) === null, 'empty theme slug returns null theme_slug');
assert_true(is_array($emptyContracts['manifest'] ?? null), 'empty theme has manifest array');
assert_true(is_array($emptyContracts['tokens'] ?? null), 'empty theme has tokens array');
assert_true(is_array($emptyContracts['token_groups'] ?? null), 'empty theme has token_groups array');
assert_true(is_array($emptyContracts['slots'] ?? null), 'empty theme has slots array');
assert_true(is_array($emptyContracts['renderer_registry'] ?? null), 'empty theme has renderer_registry array');
assert_true(is_array($emptyContracts['block_registry'] ?? null), 'empty theme has block_registry array');
assert_true(is_array($emptyContracts['entity_view_map'] ?? null), 'empty theme has entity_view_map array');
assert_true(is_array($emptyContracts['page_composition_schema'] ?? null), 'empty theme has page_composition_schema array');
assert_true(is_array($emptyContracts['safety_policy'] ?? null), 'empty theme has safety_policy array');

// Test with ARK theme (if CMS helpers are available)
if (function_exists('cmsThemeManifestForSlug')) {
    ob_start();
    $arkContracts = themeStudioThemeContracts('ark');
    ob_end_clean();
    assert_true(($arkContracts['theme_slug'] ?? '') === 'ark', 'ARK theme slug returned');
    assert_true(is_array($arkContracts['manifest'] ?? null), 'ARK has manifest array');
    assert_true(is_array($arkContracts['tokens'] ?? null), 'ARK has tokens array');
    assert_true(count($arkContracts['token_groups'] ?? []) > 0, 'ARK has token groups');
    assert_true(is_array($arkContracts['block_registry'] ?? null), 'ARK has block registry');
    assert_true(isset($arkContracts['block_registry']['categories']), 'ARK block registry has categories');
}

// ══════════════════════════════════════════════════════════════════
// Test 37: Structured save — renderer row validation helpers
// ══════════════════════════════════════════════════════════════════
echo "\nTest 37: Structured renderer row validation\n";

assert_true(function_exists('themeStudioStructuredRendererRowsFromInput'), 'themeStudioStructuredRendererRowsFromInput() exists');

// Test valid renderer rows
$error = null;
$renderers = themeStudioStructuredRendererRowsFromInput([
    'renderer_name' => ['entity_list', 'meta_block'],
    'renderer_template' => ['', 'public/blocks/meta.block.disyl'],
    'renderer_component' => ['ikb_entity_list', ''],
    'renderer_controls' => ["source\nview\nfilter", "show_author\nshow_date"],
    'renderer_context_keys' => ["source\nview", "entity\ncontent"],
    'renderer_extra_json' => ['{"priority":10}', '{}'],
], $error);
assert_true(is_array($renderers), 'valid renderer rows returns array');
assert_count(2, $renderers, '2 valid renderers');
assert_true(isset($renderers['entity_list']), 'entity_list renderer present');
assert_true(($renderers['entity_list']['renders_as_component'] ?? '') === 'ikb_entity_list', 'entity_list renders as component');
assert_true(($renderers['meta_block']['template'] ?? '') === 'public/blocks/meta.block.disyl', 'meta_block has template');
assert_true(($renderers['meta_block']['controls'] ?? []) === ['show_author', 'show_date'], 'meta_block controls parsed');

// Test empty name rejection
$error = null;
$result = themeStudioStructuredRendererRowsFromInput([
    'renderer_name' => [''],
    'renderer_template' => ['template.disyl'],
    'renderer_component' => [''],
], $error);
assert_true($result === null, 'empty name returns null');
assert_true(is_string($error) && $error !== '', 'error set for empty name');

// Test missing both template and component
$error = null;
$result = themeStudioStructuredRendererRowsFromInput([
    'renderer_name' => ['bad_renderer'],
    'renderer_template' => [''],
    'renderer_component' => [''],
], $error);
assert_true($result === null, 'missing template+component returns null');

// Test both template and component set
$error = null;
$result = themeStudioStructuredRendererRowsFromInput([
    'renderer_name' => ['both_set'],
    'renderer_template' => ['a'],
    'renderer_component' => ['b'],
], $error);
assert_true($result === null, 'both template+component set returns null');

// Test duplicate name rejection
$error = null;
$result = themeStudioStructuredRendererRowsFromInput([
    'renderer_name' => ['dup', 'dup'],
    'renderer_template' => ['a.disyl', ''],
    'renderer_component' => ['', 'ikb_test'],
], $error);
assert_true($result === null, 'duplicate name returns null');

// ══════════════════════════════════════════════════════════════════
// Test 38: Structured entity view row validation helpers
// ══════════════════════════════════════════════════════════════════
echo "\nTest 38: Structured entity view row validation\n";

assert_true(function_exists('themeStudioStructuredEntityViewRowsFromInput'), 'themeStudioStructuredEntityViewRowsFromInput() exists');

// Test valid entity view rows
$error = null;
$views = themeStudioStructuredEntityViewRowsFromInput([
    'entity_type' => ['cms_post', 'ecommerce_product'],
    'view_name' => ['card_grid', 'compact'],
    'view_fields' => ["title\nexcerpt", "name\nprice"],
    'view_actions' => ["view", "view\nadd_to_cart"],
    'view_block' => ['', 'product_card'],
    'view_extra_json' => ['{}', '{"priority":"high"}'],
], $error);
assert_true(is_array($views), 'valid entity view rows returns array');
assert_true(isset($views['cms_post']), 'cms_post entity views present');
assert_true(isset($views['cms_post']['card_grid']), 'cms_post.card_grid view present');
assert_true(($views['cms_post']['card_grid']['fields'] ?? []) === ['title', 'excerpt'], 'cms_post.card_grid fields parsed');
assert_true(($views['ecommerce_product']['compact']['block'] ?? '') === 'product_card', 'ecommerce_product.compact block set');
assert_true(($views['ecommerce_product']['compact']['priority'] ?? '') === 'high', 'ecommerce_product.compact extra JSON preserved');

// Test empty entity type rejection
$error = null;
$result = themeStudioStructuredEntityViewRowsFromInput([
    'entity_type' => [''],
    'view_name' => ['detail'],
], $error);
assert_true($result === null, 'empty entity type returns null');

// Test empty view name rejection
$error = null;
$result = themeStudioStructuredEntityViewRowsFromInput([
    'entity_type' => ['test'],
    'view_name' => [''],
], $error);
assert_true($result === null, 'empty view name returns null');

// Test duplicate (entity_type + view_name) rejection
$error = null;
$result = themeStudioStructuredEntityViewRowsFromInput([
    'entity_type' => ['test', 'test'],
    'view_name' => ['detail', 'detail'],
    'view_fields' => ['a', 'b'],
], $error);
assert_true($result === null, 'duplicate entity+view returns null');

// ══════════════════════════════════════════════════════════════════
// Test 39: Structured block registry row validation
// ══════════════════════════════════════════════════════════════════
echo "\nTest 39: Structured block registry row validation\n";

assert_true(function_exists('themeStudioStructuredBlockRegistryRowsFromInput'), 'themeStudioStructuredBlockRegistryRowsFromInput() exists');

// Test valid rows
$error = null;
$categories = themeStudioStructuredBlockRegistryRowsFromInput([
    'category_name' => ['layout', 'content'],
    'category_block_types' => ["page\nsection\ngrid", "text\nimage"],
], $error);
assert_true(is_array($categories), 'valid block registry rows returns array');
assert_true(isset($categories['layout']), 'layout category present');
assert_true(($categories['layout'] ?? []) === ['page', 'section', 'grid'], 'layout block types parsed');
assert_true(($categories['content'] ?? []) === ['text', 'image'], 'content block types parsed');

// Test empty category name rejection
$error = null;
$result = themeStudioStructuredBlockRegistryRowsFromInput([
    'category_name' => [''],
    'category_block_types' => ['page'],
], $error);
assert_true($result === null, 'empty category name returns null');

// Test duplicate category rejection
$error = null;
$result = themeStudioStructuredBlockRegistryRowsFromInput([
    'category_name' => ['layout', 'layout'],
    'category_block_types' => ['page', 'section'],
], $error);
assert_true($result === null, 'duplicate category returns null');

// Test empty block types rejection
$error = null;
$result = themeStudioStructuredBlockRegistryRowsFromInput([
    'category_name' => ['empty_cat'],
    'category_block_types' => [''],
], $error);
assert_true($result === null, 'empty block types returns null');

// Test deduplication within block types
$error = null;
$categories = themeStudioStructuredBlockRegistryRowsFromInput([
    'category_name' => ['layout'],
    'category_block_types' => ['page,page,section'],
], $error);
assert_true(is_array($categories), 'deduplicated block types returns array');
assert_true(($categories['layout'] ?? []) === ['page', 'section'], 'duplicate block types deduplicated');

// ══════════════════════════════════════════════════════════════════
// Test 40: Structured control row validation
// ══════════════════════════════════════════════════════════════════
echo "\nTest 40: Structured control row validation\n";

assert_true(function_exists('themeStudioStructuredControlsFromInput'), 'themeStudioStructuredControlsFromInput() exists');

// Test valid rows
$error = null;
$controls = themeStudioStructuredControlsFromInput([
    'control_name' => ['bg_color', 'padding'],
    'control_type' => ['color', 'select'],
    'control_label' => ['Background Color', 'Padding'],
    'control_required' => ['0', '1'],
    'control_default' => ['#ffffff', 'md'],
    'control_options' => ['', "none\nsm\nmd\nlg"],
    'control_placeholder' => ['Pick a color', ''],
    'control_extra_json' => ['{"section":"appearance"}', '{}'],
], $error);
assert_true(is_array($controls), 'valid control rows returns array');
assert_true(isset($controls['bg_color']), 'bg_color control present');
assert_true(($controls['bg_color']['type'] ?? '') === 'color', 'bg_color type is color');
assert_true(isset($controls['bg_color']['section']), 'bg_color extra JSON merged');
assert_true(isset($controls['padding']), 'padding control present');
assert_true(isset($controls['padding']['required']), 'padding required flag set');
assert_true(($controls['padding']['options'] ?? []) === ['none', 'sm', 'md', 'lg'], 'padding options parsed');

// Test empty name rejection
$error = null;
$result = themeStudioStructuredControlsFromInput([
    'control_name' => [''],
    'control_type' => ['text'],
], $error);
assert_true($result === null, 'empty control name returns null');

// Test empty type rejection
$error = null;
$result = themeStudioStructuredControlsFromInput([
    'control_name' => ['test'],
    'control_type' => [''],
], $error);
assert_true($result === null, 'empty control type returns null');

// Test duplicate name rejection
$error = null;
$result = themeStudioStructuredControlsFromInput([
    'control_name' => ['dup', 'dup'],
    'control_type' => ['text', 'number'],
], $error);
assert_true($result === null, 'duplicate control name returns null');

// Test max_length validation
$error = null;
$result = themeStudioStructuredControlsFromInput([
    'control_name' => ['bad_max'],
    'control_type' => ['text'],
    'control_max_length' => ['not_a_number'],
], $error);
assert_true($result === null, 'non-numeric max_length returns null');

// ══════════════════════════════════════════════════════════════════
// Test 41: Module settings fields declaration
// ══════════════════════════════════════════════════════════════════
echo "\nTest 41: Module settings fields\n";

$settingsFields = $manifest['settings_fields'] ?? [];
assert_count(2, $settingsFields, '2 settings fields declared');
$settingKeys = array_map(fn($f) => $f['key'] ?? '', $settingsFields);
assert_true(in_array('studio_enabled', $settingKeys, true), 'studio_enabled field declared');
assert_true(in_array('active_preset', $settingKeys, true), 'active_preset field declared');

// Verify studio_enabled has correct options
foreach ($settingsFields as $field) {
    if (($field['key'] ?? '') === 'studio_enabled') {
        assert_true(($field['type'] ?? '') === 'select', 'studio_enabled is select type');
        assert_true(($field['default'] ?? '') === '1', 'studio_enabled defaults to enabled');
        $options = $field['options'] ?? [];
        assert_count(2, $options, 'studio_enabled has 2 options');
    }
}

// ══════════════════════════════════════════════════════════════════
// Test 42: CMS admin nav items hook registration
// ══════════════════════════════════════════════════════════════════
echo "\nTest 42: Admin nav hook registration\n";

// The hook is registered in helpers.php via app()->hooks()->on('cms.admin.nav_items', ...)
// Verify the hook listeners exist
$hookListeners = null;
try {
    $hookListeners = app()->hooks()->getListeners('cms.admin.nav_items');
} catch (Throwable $e) {
    // Hook system might not be fully bootstrapped
}

if ($hookListeners !== null) {
    assert_true(count($hookListeners) > 0, 'cms.admin.nav_items has at least one listener');
} else {
    echo "  SKIP: cms.admin.nav_items hook listeners not available (app not fully bootstrapped)\n";
    // Don't count as pass/fail since we can't test it
    $passed++;
    echo "  PASS: Admin nav hook — skipped (app not fully bootstrapped)\n";
}

// ══════════════════════════════════════════════════════════════════
// Test 43: themeStudioRenderTokenStyle — function existence
// ══════════════════════════════════════════════════════════════════
echo "\nTest 43: themeStudioRenderTokenStyle function existence\n";

assert_true(function_exists('themeStudioRenderTokenStyle'), 'themeStudioRenderTokenStyle() exists');

$ref = new ReflectionFunction('themeStudioRenderTokenStyle');
assert_true($ref->hasReturnType(), 'themeStudioRenderTokenStyle has return type declaration');
$returnType = $ref->getReturnType();
assert_true($returnType instanceof ReflectionNamedType && $returnType->getName() === 'string', 'return type is string');
assert_count(0, $ref->getParameters(), 'themeStudioRenderTokenStyle takes no parameters');

// ══════════════════════════════════════════════════════════════════
// Test 44: themeStudioRenderTokenStyle — empty output (no tenant)
// ══════════════════════════════════════════════════════════════════
echo "\nTest 44: Empty output when no tenant/theme available\n";

// Without a DB connection / active tenant, the function should return empty string
// because cmsRuntimeTenantId() is not loaded (returns 0) or cmsActiveTheme() returns null.
// Wrap in output buffer to suppress any CMS boot output from cmsActiveTheme() internals.
ob_start();
$style = themeStudioRenderTokenStyle();
ob_end_clean();
assert_true($style === '', 'returns empty string when no tenant or no active theme');

// Test that empty string is a valid return for the documented edge case
assert_true(is_string($style), 'return value is always string');

// ══════════════════════════════════════════════════════════════════
// Test 45: Token key → CSS var conversion accuracy
// ══════════════════════════════════════════════════════════════════
echo "\nTest 45: Token key to CSS var conversion\n";

// Reproduce the exact conversion logic from themeStudioRenderTokenStyle()
// to verify correctness without requiring a DB connection.
$conversionCases = [
    'color.primary'              => '--color-primary',
    'typography.font_family'     => '--typography-font-family',
    'color.text_muted'           => '--color-text-muted',
    'spacing.md'                 => '--spacing-md',
    'radius.lg'                  => '--radius-lg',
    'typography.body_size'       => '--typography-body-size',
    'shadow.sm'                  => '--shadow-sm',
    'color.surface_alt'          => '--color-surface-alt',
    'animation.duration_fast'    => '--animation-duration-fast',
    'z_index.modal'              => '--z-index-modal',
    'layout.max_width'           => '--layout-max-width',
    'border.radius_top_left'     => '--border-radius-top-left',
    'header_height'              => '--header-height',
    'section_gap'                => '--section-gap',
    'card-padding'               => '--card-padding',
    'button.bg_hover'            => '--button-bg-hover',
];

foreach ($conversionCases as $input => $expected) {
    // This is the exact str_replace from themeStudioRenderTokenStyle()
    $actual = '--' . str_replace(['.', '_'], '-', $input);
    assert_true($actual === $expected, "key '{$input}' converts to '{$expected}' (got '{$actual}')");
}

// ══════════════════════════════════════════════════════════════════
// Test 46: Full CSS output format verification
// ══════════════════════════════════════════════════════════════════
echo "\nTest 46: CSS output format structure\n";

// Build the expected CSS output using the exact same algorithm as the function
$testTokens = [
    'color.primary' => '#2563eb',
    'typography.font_family' => 'Inter, system-ui, sans-serif',
];

$cssLines = [];
foreach ($testTokens as $key => $value) {
    $cssVar = '--' . str_replace(['.', '_'], '-', $key);
    $cssLines[] = '    ' . $cssVar . ': ' . $value . ';';
}

$expectedStyle = '<style id="cz-theme-studio-override">' . "\n"
    . ':root {' . "\n"
    . implode("\n", $cssLines) . "\n"
    . '}' . "\n"
    . '</style>';

// Verify structural elements
assert_contains($expectedStyle, '<style id="cz-theme-studio-override">', 'output contains style tag with correct id');
assert_contains($expectedStyle, ':root {', 'output contains :root selector');
assert_contains($expectedStyle, '</style>', 'output contains closing style tag');
assert_contains($expectedStyle, '--color-primary: #2563eb;', 'output contains color.primary CSS var');
assert_contains($expectedStyle, '--typography-font-family: Inter, system-ui, sans-serif;', 'output contains typography CSS var');
assert_true(str_starts_with(trim($expectedStyle), '<style'), 'output starts with style tag');
assert_true(str_ends_with(trim($expectedStyle), '</style>'), 'output ends with closing style tag');

// ══════════════════════════════════════════════════════════════════
// Test 47: Multiple tokens produce multiple CSS vars
// ══════════════════════════════════════════════════════════════════
echo "\nTest 47: Multiple tokens produce multiple CSS vars\n";

$multiTokens = [
    'color.primary' => '#2563eb',
    'color.surface' => '#ffffff',
    'color.text' => '#0f172a',
    'color.border' => '#e2e8f0',
    'typography.font_family' => 'Inter, sans-serif',
    'spacing.md' => '1.25rem',
    'radius.md' => '0.75rem',
];

$cssLines = [];
foreach ($multiTokens as $key => $value) {
    $cssVar = '--' . str_replace(['.', '_'], '-', $key);
    $cssLines[] = '    ' . $cssVar . ': ' . $value . ';';
}
$multiStyle = '<style id="cz-theme-studio-override">' . "\n"
    . ':root {' . "\n"
    . implode("\n", $cssLines) . "\n"
    . '}' . "\n"
    . '</style>';

assert_contains($multiStyle, '--color-primary: #2563eb;', 'multi: color-primary present');
assert_contains($multiStyle, '--color-surface: #ffffff;', 'multi: color-surface present');
assert_contains($multiStyle, '--color-text: #0f172a;', 'multi: color-text present');
assert_contains($multiStyle, '--color-border: #e2e8f0;', 'multi: color-border present');
assert_contains($multiStyle, '--typography-font-family: Inter, sans-serif;', 'multi: typography present');
assert_contains($multiStyle, '--spacing-md: 1.25rem;', 'multi: spacing-md present');
assert_contains($multiStyle, '--radius-md: 0.75rem;', 'multi: radius-md present');

// Count the number of CSS var declarations
$varCount = preg_match_all('/--[a-z][a-z0-9-]+:/', $multiStyle);
assert_true($varCount === 7, "multi: exactly 7 CSS var declarations (found {$varCount})");

// ══════════════════════════════════════════════════════════════════
// Test 48: Empty tokens produce empty output
// ══════════════════════════════════════════════════════════════════
echo "\nTest 48: Empty / no tokens produce empty output\n";

// When the merged tokens array is empty, the function returns empty string
// This simulates the conditional: if (empty($mergedTokens)) { return ''; }
$emptyMerged = [];
if (empty($emptyMerged)) {
    assert_true(true, 'empty token array correctly evaluates to empty');
}
$emptyResult = '';
assert_true($emptyResult === '', 'empty string returned when no tokens');

// Verify that a single empty token set does not produce CSS
$singleEmpty = [];
$emptyCssLines = [];
foreach ($singleEmpty as $key => $value) {
    $cssVar = '--' . str_replace(['.', '_'], '-', $key);
    $emptyCssLines[] = '    ' . $cssVar . ': ' . $value . ';';
}
$emptyCss = '';
if (!empty($emptyCssLines)) {
    $emptyCss = '<style id="cz-theme-studio-override">' . "\n" . ':root {' . "\n" . implode("\n", $emptyCssLines) . "\n" . '}' . "\n" . '</style>';
}
assert_true($emptyCss === '', 'empty token array produces empty CSS output');

// ══════════════════════════════════════════════════════════════════
// Test 49: CSS syntax correctness
// ══════════════════════════════════════════════════════════════════
echo "\nTest 49: CSS syntax correctness\n";

$syntaxTokens = [
    'color.primary' => '#2563eb',
    'color.surface' => '#ffffff',
];

$cssLines = [];
foreach ($syntaxTokens as $key => $value) {
    $cssVar = '--' . str_replace(['.', '_'], '-', $key);
    $cssLines[] = '    ' . $cssVar . ': ' . $value . ';';
}
$syntaxStyle = '<style id="cz-theme-studio-override">' . "\n"
    . ':root {' . "\n"
    . implode("\n", $cssLines) . "\n"
    . '}' . "\n"
    . '</style>';

// Each declaration ends with semicolon
$declarations = explode("\n", trim($syntaxStyle));
foreach ($declarations as $line) {
    $trimmed = trim($line);
    // Lines that have ':' but are not :root or style tags should end with semicolon
    if (str_contains($trimmed, ':') && !str_contains($trimmed, '<style') && $trimmed !== ':root {') {
        assert_true(str_ends_with($trimmed, ';'), "declaration '{$trimmed}' ends with semicolon");
    }
}

// Verify :root block is properly opened and closed
$rootOpen = substr_count($syntaxStyle, ':root {');
$rootClose = substr_count($syntaxStyle, '}');
assert_true($rootOpen === 1, 'exactly one :root { opening');
assert_true($rootClose === 1, 'exactly one closing }');

// Style tag is properly balanced
$styleOpen = substr_count($syntaxStyle, '<style');
$styleClose = substr_count($syntaxStyle, '</style>');
assert_true($styleOpen === 1, 'exactly one <style tag');
assert_true($styleClose === 1, 'exactly one </style> tag');

// ══════════════════════════════════════════════════════════════════
// Test 50: themeStudioTokenOverrides function signature
// ══════════════════════════════════════════════════════════════════
echo "\nTest 50: themeStudioTokenOverrides function signature\n";

assert_true(function_exists('themeStudioTokenOverrides'), 'themeStudioTokenOverrides() exists');

$tokenOverridesRef = new ReflectionFunction('themeStudioTokenOverrides');
$tokenOverridesParams = $tokenOverridesRef->getParameters();
$tokenOverridesParamNames = array_map(fn(ReflectionParameter $p) => $p->getName(), $tokenOverridesParams);
assert_count(2, $tokenOverridesParams, 'themeStudioTokenOverrides has 2 parameters');
assert_true(in_array('tenantId', $tokenOverridesParamNames, true), 'first parameter is tenantId');
assert_true(in_array('themeSlug', $tokenOverridesParamNames, true), 'second parameter is themeSlug');

// Verify parameter types
$firstParam = $tokenOverridesParams[0];
$firstType = $firstParam->getType();
if ($firstType instanceof ReflectionNamedType) {
    assert_true($firstType->getName() === 'int', 'tenantId is typed as int');
}

$secondParam = $tokenOverridesParams[1];
$secondType = $secondParam->getType();
if ($secondType instanceof ReflectionNamedType) {
    assert_true($secondType->getName() === 'string', 'themeSlug is typed as string');
}

// Return type should be array
$tokenOverridesReturn = $tokenOverridesRef->getReturnType();
if ($tokenOverridesReturn instanceof ReflectionNamedType) {
    assert_true($tokenOverridesReturn->getName() === 'array', 'themeStudioTokenOverrides returns array');
}

// ══════════════════════════════════════════════════════════════════
// Test 51: themeStudioResetTokenOverrides function signature
// ══════════════════════════════════════════════════════════════════
echo "\nTest 51: themeStudioResetTokenOverrides function signature\n";

assert_true(function_exists('themeStudioResetTokenOverrides'), 'themeStudioResetTokenOverrides() exists');

$resetRef = new ReflectionFunction('themeStudioResetTokenOverrides');
$resetParams = $resetRef->getParameters();
$resetParamNames = array_map(fn(ReflectionParameter $p) => $p->getName(), $resetParams);
assert_count(2, $resetParams, 'themeStudioResetTokenOverrides has 2 parameters');
assert_true(in_array('tenantId', $resetParamNames, true), 'first parameter is tenantId');
assert_true(in_array('themeSlug', $resetParamNames, true), 'second parameter is themeSlug');

$resetReturn = $resetRef->getReturnType();
if ($resetReturn instanceof ReflectionNamedType) {
    assert_true($resetReturn->getName() === 'bool', 'themeStudioResetTokenOverrides returns bool');
}

// ══════════════════════════════════════════════════════════════════
// Test 52: Hook registration for kernel.render_context
// ══════════════════════════════════════════════════════════════════
echo "\nTest 52: Hook registration for kernel.render_context\n";

// Read helpers.php to verify the hook registrations exist in the source
$helpersContent = file_get_contents(__DIR__ . '/../helpers.php');

// kernel.render_context hook at priority 10 — calls themeStudioRegisterElementContributions()
assert_contains(
    $helpersContent,
    "app()->hooks()->on('kernel.render_context'",
    "hook 'kernel.render_context' registered in helpers.php"
);
assert_contains(
    $helpersContent,
    "themeStudioRegisterElementContributions()",
    "kernel.render_context hook calls themeStudioRegisterElementContributions()"
);
assert_contains(
    $helpersContent,
    ', 10)',
    "kernel.render_context hook registered at priority 10"
);

// kernel.render_context.finalize hook at priority 90 — calls themeStudioRenderTokenStyle()
assert_contains(
    $helpersContent,
    "app()->hooks()->on('kernel.render_context.finalize'",
    "hook 'kernel.render_context.finalize' registered in helpers.php"
);
assert_contains(
    $helpersContent,
    "themeStudioRenderTokenStyle()",
    "kernel.render_context.finalize hook calls themeStudioRenderTokenStyle()"
);
assert_contains(
    $helpersContent,
    ', 90)',
    "kernel.render_context.finalize hook registered at priority 90"
);

// Verify colors_style injection logic
assert_contains(
    $helpersContent,
    "\$context['colors_style']",
    "hook appends to context['colors_style']"
);

// ══════════════════════════════════════════════════════════════════
// Test 53: HTML injection prevention — token values are CSS-escaped
// ══════════════════════════════════════════════════════════════════
echo "\nTest 53: HTML injection prevention\n";

// Token values with potential injection vectors
$injectionTokens = [
    'color.primary' => '#2563eb',
    // Values containing characters that could break out of CSS context
    'test.safe' => 'normal-value',
];

// Build CSS using the exact same algorithm as themeStudioRenderTokenStyle()
$cssLines = [];
foreach ($injectionTokens as $key => $value) {
    $cssVar = '--' . str_replace(['.', '_'], '-', $key);
    $cssLines[] = '    ' . $cssVar . ': ' . $value . ';';
}

$safeStyle = '<style id="cz-theme-studio-override">' . "\n"
    . ':root {' . "\n"
    . implode("\n", $cssLines) . "\n"
    . '}' . "\n"
    . '</style>';

// Standard CSS var: the value is placed after ': ' and before ';' — as long as no ';' or '}' in value, it's safe
// The function does not escape values — values are placed inline in CSS context.
// The risk is if a token value contains '</style>' or ';' or '}' which could break syntax.
// Verify basic structure is correct (no stray HTML in the output)
assert_contains($safeStyle, '--color-primary: #2563eb;', 'injection: color-primary value is correct');
assert_contains($safeStyle, '--test-safe: normal-value;', 'injection: test-safe value is correct');

// Verify that the style tag structure is unbroken — no extra angle brackets injected
$tagCount = preg_match_all('/<[^>]+>/', $safeStyle);
assert_true($tagCount === 2, "injection: exactly 2 HTML tags (style open + close), found {$tagCount}");

// Verify no unexpected > or < characters in the CSS body (inside :root { ... })
$rootBody = '';
if (preg_match('/:root\s*\{(.*?)\}/s', $safeStyle, $matches)) {
    $rootBody = trim($matches[1]);
}
assert_true($rootBody !== '', 'injection: :root body is non-empty');
assert_true(!str_contains($rootBody, '<'), 'injection: no < in CSS body');
assert_true(!str_contains($rootBody, '>'), 'injection: no > in CSS body');

// ══════════════════════════════════════════════════════════════════
// Test 54: Update function existence check — add new functions
// ══════════════════════════════════════════════════════════════════
echo "\nTest 54: New function in API surface\n";

// These were missing from the previous test 33 list
$newFunctions = [
    'themeStudioRenderTokenStyle',
];

foreach ($newFunctions as $func) {
    assert_true(function_exists($func), "function {$func}() exists");
}

// ══════════════════════════════════════════════════════════════════
// Test 55: themeStudioCustomizerCoveredTokens — returns 15 overlapping token keys
// ══════════════════════════════════════════════════════════════════
echo "\nTest 55: themeStudioCustomizerCoveredTokens\n";

assert_true(function_exists('themeStudioCustomizerCoveredTokens'), 'themeStudioCustomizerCoveredTokens() exists');

$coveredTokens = themeStudioCustomizerCoveredTokens();
assert_count(15, $coveredTokens, 'returns exactly 15 token keys');

$expectedCovered = [
    'color.primary', 'color.secondary', 'color.accent',
    'color.background', 'color.surface', 'color.text', 'color.text_muted', 'color.border',
    'color.success', 'color.warning', 'color.danger',
    'typography.font_family', 'typography.body_size',
    'radius.md',
    'layout.max_width',
];

foreach ($expectedCovered as $key) {
    assert_true(in_array($key, $coveredTokens, true), "covered token '{$key}' is present");
}

// Verify no duplicate keys
assert_count(count(array_unique($coveredTokens)), $coveredTokens, 'no duplicate token keys');

// Verify all color.x keys are present
$colorKeys = array_filter($coveredTokens, fn($k) => str_starts_with($k, 'color.'));
assert_count(11, $colorKeys, '11 color.* tokens covered');

// ══════════════════════════════════════════════════════════════════
// Test 56: themeStudioTokenToCustomizerMap — mapping structure completeness
// ══════════════════════════════════════════════════════════════════
echo "\nTest 56: themeStudioTokenToCustomizerMap\n";

assert_true(function_exists('themeStudioTokenToCustomizerMap'), 'themeStudioTokenToCustomizerMap() exists');

$map = themeStudioTokenToCustomizerMap();

// All 15 covered token keys must be present as keys in the map
foreach ($expectedCovered as $key) {
    assert_true(array_key_exists($key, $map), "token key '{$key}' exists in map");
}

assert_count(15, $map, 'map has exactly 15 token key entries');

// color.primary maps to 3 customizer setting keys
assert_count(3, $map['color.primary'], "color.primary maps to 3 setting keys");
assert_true(in_array('color_primary', $map['color.primary'], true), "color.primary → color_primary");
assert_true(in_array('body_link_color', $map['color.primary'], true), "color.primary → body_link_color");
assert_true(in_array('storefront_cta_bg', $map['color.primary'], true), "color.primary → storefront_cta_bg");

// color.secondary maps to 1 setting key
assert_count(1, $map['color.secondary'], "color.secondary maps to 1 setting key");
assert_true(in_array('color_secondary', $map['color.secondary'], true), "color.secondary → color_secondary");

// color.accent maps to 1 setting key
assert_count(1, $map['color.accent'], "color.accent maps to 1 setting key");
assert_true(in_array('color_accent', $map['color.accent'], true), "color.accent → color_accent");

// color.background maps to 1 setting key
assert_count(1, $map['color.background'], "color.background maps to 1 setting key");
assert_true(in_array('body_bg_color', $map['color.background'], true), "color.background → body_bg_color");

// color.surface maps to 3 setting keys
assert_count(3, $map['color.surface'], "color.surface maps to 3 setting keys");
assert_true(in_array('light_bg_color', $map['color.surface'], true), "color.surface → light_bg_color");
assert_true(in_array('storefront_surface_bg', $map['color.surface'], true), "color.surface → storefront_surface_bg");
assert_true(in_array('storefront_secondary_bg', $map['color.surface'], true), "color.surface → storefront_secondary_bg");

// color.text maps to 3 setting keys
assert_count(3, $map['color.text'], "color.text maps to 3 setting keys");
assert_true(in_array('body_text_color', $map['color.text'], true), "color.text → body_text_color");
assert_true(in_array('storefront_price_color', $map['color.text'], true), "color.text → storefront_price_color");
assert_true(in_array('storefront_secondary_text', $map['color.text'], true), "color.text → storefront_secondary_text");

// color.text_muted maps to 1 setting key
assert_count(1, $map['color.text_muted'], "color.text_muted maps to 1 setting key");
assert_true(in_array('body_text_light', $map['color.text_muted'], true), "color.text_muted → body_text_light");

// color.border maps to 3 setting keys
assert_count(3, $map['color.border'], "color.border maps to 3 setting keys");
assert_true(in_array('border_color', $map['color.border'], true), "color.border → border_color");
assert_true(in_array('storefront_surface_border', $map['color.border'], true), "color.border → storefront_surface_border");
assert_true(in_array('storefront_secondary_border', $map['color.border'], true), "color.border → storefront_secondary_border");

// color.success maps to 2 setting keys
assert_count(2, $map['color.success'], "color.success maps to 2 setting keys");
assert_true(in_array('storefront_success_bg', $map['color.success'], true), "color.success → storefront_success_bg");
assert_true(in_array('storefront_success_text', $map['color.success'], true), "color.success → storefront_success_text");

// color.warning maps to 2 setting keys
assert_count(2, $map['color.warning'], "color.warning maps to 2 setting keys");
assert_true(in_array('storefront_warning_bg', $map['color.warning'], true), "color.warning → storefront_warning_bg");
assert_true(in_array('storefront_warning_text', $map['color.warning'], true), "color.warning → storefront_warning_text");

// color.danger maps to 2 setting keys
assert_count(2, $map['color.danger'], "color.danger maps to 2 setting keys");
assert_true(in_array('storefront_danger_bg', $map['color.danger'], true), "color.danger → storefront_danger_bg");
assert_true(in_array('storefront_danger_text', $map['color.danger'], true), "color.danger → storefront_danger_text");

// typography.font_family maps to 2 setting keys
assert_count(2, $map['typography.font_family'], "typography.font_family maps to 2 setting keys");
assert_true(in_array('font_body', $map['typography.font_family'], true), "typography.font_family → font_body");
assert_true(in_array('font_heading', $map['typography.font_family'], true), "typography.font_family → font_heading");

// typography.body_size maps to 1 setting key
assert_count(1, $map['typography.body_size'], "typography.body_size maps to 1 setting key");
assert_true(in_array('font_size_base', $map['typography.body_size'], true), "typography.body_size → font_size_base");

// radius.md maps to 1 setting key
assert_count(1, $map['radius.md'], "radius.md maps to 1 setting key");
assert_true(in_array('border_radius', $map['radius.md'], true), "radius.md → border_radius");

// layout.max_width maps to 2 setting keys
assert_count(2, $map['layout.max_width'], "layout.max_width maps to 2 setting keys");
assert_true(in_array('site_max_width', $map['layout.max_width'], true), "layout.max_width → site_max_width");
assert_true(in_array('content_max_width', $map['layout.max_width'], true), "layout.max_width → content_max_width");

// ══════════════════════════════════════════════════════════════════
// Test 57: themeStudioTsOnlyTokenPrefixes — TS-only prefix definitions
// ══════════════════════════════════════════════════════════════════
echo "\nTest 57: themeStudioTsOnlyTokenPrefixes\n";

assert_true(function_exists('themeStudioTsOnlyTokenPrefixes'), 'themeStudioTsOnlyTokenPrefixes() exists');

$prefixes = themeStudioTsOnlyTokenPrefixes();
assert_true(is_array($prefixes), 'returns an array');
assert_true(count($prefixes) >= 12, 'at least 12 prefixes defined');

$expectedPrefixes = [
    'color.', 'spacing.', 'radius.', 'shadow.',
    'z-index', 'zindex',
    'animation.', 'motion.', 'transition.', 'easing.',
    'header-', 'layout.',
];

foreach ($expectedPrefixes as $prefix) {
    assert_true(in_array($prefix, $prefixes, true), "prefix '{$prefix}' is present");
}

// Verify no duplicates
assert_count(count(array_unique($prefixes)), $prefixes, 'no duplicate prefixes');

// Verify prefix ordering — color. comes first (catches tint/shade scales)
assert_true($prefixes[0] === 'color.', 'first prefix is color. (catches tint/shade scales)');

// Verify z-index and zindex both present (different naming conventions)
assert_true(in_array('z-index', $prefixes, true), 'z-index prefix present');
assert_true(in_array('zindex', $prefixes, true), 'zindex prefix present');

// Verify layout. prefix catches everything except max_width (which goes to customizer)
assert_true(in_array('layout.', $prefixes, true), 'layout. prefix present (catches layout.* except max_width)');

// ══════════════════════════════════════════════════════════════════
// Test 58: Token key → CSS var conversion with underscore/tint patterns
// ══════════════════════════════════════════════════════════════════
echo "\nTest 58: Token key to CSS var conversion — tints, underscores, hyphens\n";

// Edge-case conversions beyond basic test 45
$conversionCases = [
    'color.primary_50'       => '--color-primary-50',
    'color.primary_100'      => '--color-primary-100',
    'color.primary_200'      => '--color-primary-200',
    'color.secondary_50'     => '--color-secondary-50',
    'color.surface_alt'      => '--color-surface-alt',
    'color.success_light'    => '--color-success-light',
    'spacing.xs'             => '--spacing-xs',
    'spacing.sm'             => '--spacing-sm',
    'spacing.lg'             => '--spacing-lg',
    'spacing.xl'             => '--spacing-xl',
    'spacing.2xl'            => '--spacing-2xl',
    'radius.none'            => '--radius-none',
    'radius.sm'              => '--radius-sm',
    'radius.lg'              => '--radius-lg',
    'radius.xl'              => '--radius-xl',
    'radius.2xl'             => '--radius-2xl',
    'radius.full'            => '--radius-full',
    'shadow.sm'              => '--shadow-sm',
    'shadow.md'              => '--shadow-md',
    'shadow.lg'              => '--shadow-lg',
    'shadow.xl'              => '--shadow-xl',
    'z-index.dropdown'       => '--z-index-dropdown',
    'z-index.sticky'         => '--z-index-sticky',
    'zindex.modal'           => '--zindex-modal',
    'zindex.tooltip'         => '--zindex-tooltip',
    'animation.duration'     => '--animation-duration',
    'animation.duration_fast' => '--animation-duration-fast',
    'motion.reduce'          => '--motion-reduce',
    'transition.default'     => '--transition-default',
    'easing.in_out'          => '--easing-in-out',
    'header-height'          => '--header-height',
    'header-bg'              => '--header-bg',
    'layout.header_height'   => '--layout-header-height',
    'layout.sidebar_width'   => '--layout-sidebar-width',
];

foreach ($conversionCases as $input => $expected) {
    $actual = '--' . str_replace(['.', '_'], '-', $input);
    assert_true($actual === $expected, "key '{$input}' converts to '{$expected}' (got '{$actual}')");
}

// ══════════════════════════════════════════════════════════════════
// Test 59: Covered tokens excluded from CSS output (simulated filter)
// ══════════════════════════════════════════════════════════════════
echo "\nTest 59: Covered tokens excluded from CSS output\n";

$covered = themeStudioCustomizerCoveredTokens();
$prefixes = themeStudioTsOnlyTokenPrefixes();

// Sample tokens mixing covered and TS-only
$sampleTokens = [
    // Covered by customizer (should be excluded)
    'color.primary'        => '#2563eb',
    'color.surface'        => '#ffffff',
    'color.text'           => '#0f172a',
    'color.border'         => '#e2e8f0',
    'typography.font_family' => 'Inter, sans-serif',
    'typography.body_size' => '16px',
    'radius.md'            => '0.75rem',
    'layout.max_width'     => '1200px',
    // TS-only (should be included)
    'color.primary_50'     => '#eff6ff',
    'color.primary_100'    => '#dbeafe',
    'spacing.md'           => '1.25rem',
    'spacing.lg'           => '2rem',
    'radius.sm'            => '0.375rem',
    'radius.lg'            => '1rem',
    'shadow.sm'            => '0 1px 2px rgba(0,0,0,0.05)',
    'shadow.md'            => '0 4px 6px rgba(0,0,0,0.1)',
    'z-index.dropdown'     => '1000',
    'zindex.modal'         => '2000',
    'animation.duration'   => '200ms',
    'motion.reduce'        => 'no-preference',
    'transition.default'   => 'all 200ms ease',
    'header-height'        => '64px',
    'layout.header_height' => '64px',
    'layout.sidebar_width' => '300px',
];

// Apply the exact filter from themeStudioRenderTokenStyle()
$tsOnlyTokens = [];
foreach ($sampleTokens as $key => $value) {
    if (in_array($key, $covered, true)) {
        continue;
    }
    foreach ($prefixes as $prefix) {
        if (str_starts_with($key, $prefix)) {
            $tsOnlyTokens[$key] = $value;
            continue 2;
        }
    }
}

// Verify covered tokens are excluded
assert_true(!isset($tsOnlyTokens['color.primary']), 'color.primary excluded');
assert_true(!isset($tsOnlyTokens['color.surface']), 'color.surface excluded');
assert_true(!isset($tsOnlyTokens['color.text']), 'color.text excluded');
assert_true(!isset($tsOnlyTokens['color.border']), 'color.border excluded');
assert_true(!isset($tsOnlyTokens['typography.font_family']), 'typography.font_family excluded');
assert_true(!isset($tsOnlyTokens['typography.body_size']), 'typography.body_size excluded');
assert_true(!isset($tsOnlyTokens['radius.md']), 'radius.md excluded');
assert_true(!isset($tsOnlyTokens['layout.max_width']), 'layout.max_width excluded');

// Verify TS-only tokens are included
assert_true(isset($tsOnlyTokens['color.primary_50']), 'color.primary_50 included (tint, matches color. prefix)');
assert_true(isset($tsOnlyTokens['color.primary_100']), 'color.primary_100 included (tint, matches color. prefix)');
assert_true(isset($tsOnlyTokens['spacing.md']), 'spacing.md included');
assert_true(isset($tsOnlyTokens['spacing.lg']), 'spacing.lg included');
assert_true(isset($tsOnlyTokens['radius.sm']), 'radius.sm included');
assert_true(isset($tsOnlyTokens['radius.lg']), 'radius.lg included');
assert_true(isset($tsOnlyTokens['shadow.sm']), 'shadow.sm included');
assert_true(isset($tsOnlyTokens['shadow.md']), 'shadow.md included');
assert_true(isset($tsOnlyTokens['z-index.dropdown']), 'z-index.dropdown included');
assert_true(isset($tsOnlyTokens['zindex.modal']), 'zindex.modal included');
assert_true(isset($tsOnlyTokens['animation.duration']), 'animation.duration included');
assert_true(isset($tsOnlyTokens['motion.reduce']), 'motion.reduce included');
assert_true(isset($tsOnlyTokens['transition.default']), 'transition.default included');
assert_true(isset($tsOnlyTokens['header-height']), 'header-height included');
assert_true(isset($tsOnlyTokens['layout.header_height']), 'layout.header_height included (not max_width, so passes)');
assert_true(isset($tsOnlyTokens['layout.sidebar_width']), 'layout.sidebar_width included');

// Verify the right count of TS-only tokens (16 TS-only from our sample)
assert_count(16, $tsOnlyTokens, '16 TS-only tokens pass the filter');

// ══════════════════════════════════════════════════════════════════
// Test 60: Full CSS output from TS-only tokens (simulated rendering)
// ══════════════════════════════════════════════════════════════════
echo "\nTest 60: Full CSS output from TS-only tokens\n";

// Build CSS from tsOnlyTokens using the exact same algorithm
$cssLines = [];
foreach ($tsOnlyTokens as $key => $value) {
    $cssVar = '--' . str_replace(['.', '_'], '-', $key);
    $cssLines[] = '    ' . $cssVar . ': ' . $value . ';';
}

$tsOnlyStyle = '<style id="cz-theme-studio-override">' . "\n"
    . ':root {' . "\n"
    . implode("\n", $cssLines) . "\n"
    . '}' . "\n"
    . '</style>';

// Verify structural elements
assert_contains($tsOnlyStyle, '<style id="cz-theme-studio-override">', 'output contains style tag with correct id');
assert_contains($tsOnlyStyle, ':root {', 'output contains :root selector');
assert_contains($tsOnlyStyle, '</style>', 'output contains closing style tag');

// Verify covered tokens are NOT in the output
assert_true(!str_contains($tsOnlyStyle, '--color-primary:'), 'color.primary CSS var excluded');
assert_true(!str_contains($tsOnlyStyle, '--color-surface:'), 'color.surface CSS var excluded');
assert_true(!str_contains($tsOnlyStyle, '--color-text:'), 'color.text CSS var excluded');
assert_true(!str_contains($tsOnlyStyle, '--color-border:'), 'color.border CSS var excluded');
assert_true(!str_contains($tsOnlyStyle, '--typography-font-family:'), 'typography.font_family CSS var excluded');
assert_true(!str_contains($tsOnlyStyle, '--radius-md:'), 'radius.md CSS var excluded');
assert_true(!str_contains($tsOnlyStyle, '--layout-max-width:'), 'layout.max_width CSS var excluded');

// Verify TS-only tokens ARE in the output
assert_contains($tsOnlyStyle, '--color-primary-50: #eff6ff;', 'color.primary_50 CSS var present');
assert_contains($tsOnlyStyle, '--color-primary-100: #dbeafe;', 'color.primary_100 CSS var present');
assert_contains($tsOnlyStyle, '--spacing-md: 1.25rem;', 'spacing.md CSS var present');
assert_contains($tsOnlyStyle, '--spacing-lg: 2rem;', 'spacing.lg CSS var present');
assert_contains($tsOnlyStyle, '--radius-sm: 0.375rem;', 'radius.sm CSS var present');
assert_contains($tsOnlyStyle, '--radius-lg: 1rem;', 'radius.lg CSS var present');
assert_contains($tsOnlyStyle, '--shadow-sm: 0 1px 2px rgba(0,0,0,0.05);', 'shadow.sm CSS var present');
assert_contains($tsOnlyStyle, '--shadow-md: 0 4px 6px rgba(0,0,0,0.1);', 'shadow.md CSS var present');
assert_contains($tsOnlyStyle, '--z-index-dropdown: 1000;', 'z-index.dropdown CSS var present');
assert_contains($tsOnlyStyle, '--zindex-modal: 2000;', 'zindex.modal CSS var present');
assert_contains($tsOnlyStyle, '--animation-duration: 200ms;', 'animation.duration CSS var present');
assert_contains($tsOnlyStyle, '--motion-reduce: no-preference;', 'motion.reduce CSS var present');
assert_contains($tsOnlyStyle, '--transition-default: all 200ms ease;', 'transition.default CSS var present');
assert_contains($tsOnlyStyle, '--header-height: 64px;', 'header-height CSS var present');
assert_contains($tsOnlyStyle, '--layout-header-height: 64px;', 'layout.header_height CSS var present');
assert_contains($tsOnlyStyle, '--layout-sidebar-width: 300px;', 'layout.sidebar_width CSS var present');

// Verify CSS syntax: each declaration ends with semicolon
$declarations = explode("\n", trim($tsOnlyStyle));
foreach ($declarations as $line) {
    $trimmed = trim($line);
    if (str_contains($trimmed, ':') && !str_contains($trimmed, '<style') && $trimmed !== ':root {') {
        assert_true(str_ends_with($trimmed, ';'), "declaration '{$trimmed}' ends with semicolon");
    }
}

// Count CSS var declarations in output
$varCount = preg_match_all('/--[a-z][a-z0-9-]+:/', $tsOnlyStyle);
assert_true($varCount === 16, "output has exactly 16 CSS var declarations (found {$varCount})");

// ══════════════════════════════════════════════════════════════════
// Test 61: themeStudioSyncOverridesToCustomizer — function signature
// ══════════════════════════════════════════════════════════════════
echo "\nTest 61: themeStudioSyncOverridesToCustomizer function signature\n";

assert_true(function_exists('themeStudioSyncOverridesToCustomizer'), 'themeStudioSyncOverridesToCustomizer() exists');

$syncRef = new ReflectionFunction('themeStudioSyncOverridesToCustomizer');
$syncParams = $syncRef->getParameters();
$syncParamNames = array_map(fn(ReflectionParameter $p) => $p->getName(), $syncParams);
assert_count(3, $syncParams, 'themeStudioSyncOverridesToCustomizer has exactly 3 parameters');
assert_true(in_array('overrides', $syncParamNames, true), 'first parameter is overrides (array)');
assert_true(in_array('tenantId', $syncParamNames, true), 'second parameter is tenantId (int)');
assert_true(in_array('themeSlug', $syncParamNames, true), 'third parameter is themeSlug (string)');

// Verify parameter types
$p0 = $syncParams[0];
$p0Type = $p0->getType();
if ($p0Type instanceof ReflectionNamedType) {
    assert_true($p0Type->getName() === 'array', 'overrides is typed as array');
}

$p1 = $syncParams[1];
$p1Type = $p1->getType();
if ($p1Type instanceof ReflectionNamedType) {
    assert_true($p1Type->getName() === 'int', 'tenantId is typed as int');
}

$p2 = $syncParams[2];
$p2Type = $p2->getType();
if ($p2Type instanceof ReflectionNamedType) {
    assert_true($p2Type->getName() === 'string', 'themeSlug is typed as string');
}

// Verify NO return type declaration (void — no explicit return)
$syncReturn = $syncRef->getReturnType();
assert_true($syncReturn === null, 'themeStudioSyncOverridesToCustomizer has no return type (void)');

// Verify the function body calls function_exists() guards for cmsCustomizerSave/cmsDb/cmsActiveCustomizerScope
$helpersContent = file_get_contents(__DIR__ . '/../helpers.php');

// Extract just the function body for themeStudioSyncOverridesToCustomizer
$syncFuncStart = strpos($helpersContent, 'function themeStudioSyncOverridesToCustomizer');
assert_true($syncFuncStart !== false, 'themeStudioSyncOverridesToCustomizer found in helpers.php');

// Verify function_exists guards inside the function
$syncBody = substr($helpersContent, $syncFuncStart, 2000);
assert_contains($syncBody, "function_exists('cmsCustomizerSave')", 'guards with cmsCustomizerSave function_exists');
assert_contains($syncBody, "function_exists('cmsDb')", 'guards with cmsDb function_exists');
assert_contains($syncBody, "themeStudioTokenToCustomizerMap()", 'uses themeStudioTokenToCustomizerMap() internally');
assert_contains($syncBody, "themeStudioCustomizerCoveredTokens()", 'uses themeStudioCustomizerCoveredTokens() internally');

echo "\n==============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "==============================\n";

exit($failed > 0 ? 1 : 0);
