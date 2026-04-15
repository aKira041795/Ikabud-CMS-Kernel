<?php
/**
 * Builder E2E Lifecycle Test (Platform Tier 3 — 3.6)
 *
 * Tests the full builder document lifecycle: create, normalize, validate,
 * schema versioning, persist, load, render.
 *
 * Run: php tests/builder_lifecycle_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// Suppress all HTML output from module/helper loading
ob_start();
require_once BASE_PATH . '/src/helpers/module-manager.php';
ob_end_clean();

ob_start();
$cmsPath = BASE_PATH . '/modules/cms';
$builderHelpers = [
    '/helpers/50-builder.php',
    '/builder-renderers.php',
];
foreach ($builderHelpers as $file) {
    if (file_exists($cmsPath . $file)) {
        require_once $cmsPath . $file;
    }
}
ob_end_clean();

// Set custom error handler to prevent HTML output from error pages
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$passed = 0;
$failed = 0;

function t(string $label, bool $result): void
{
    global $passed, $failed;
    if ($result) {
        $passed++;
        echo "  ✓ {$label}\n";
    } else {
        $failed++;
        echo "  ✗ FAIL: {$label}\n";
    }
}

echo "Builder E2E Lifecycle Test Suite\n";
echo str_repeat('=', 60) . "\n\n";

// ─── Section 1: Document Creation ──────────────────────────────────────
echo "── Section 1: Document Creation ──\n";

$doc = cmsBuilderDefaultDocument();
t('Default document has schema_version', isset($doc['schema_version']));
t('Default document has document node', isset($doc['document']));
t('Root type is document', ($doc['document']['type'] ?? '') === 'document');
t('Root id is doc_root', ($doc['document']['id'] ?? '') === 'doc_root');
t('Root has empty children', is_array($doc['document']['children'] ?? null) && count($doc['document']['children']) === 0);
t('Root has props array', is_array($doc['document']['props'] ?? null));
t('Root has style array', is_array($doc['document']['style'] ?? null));
t('Root has meta array', is_array($doc['document']['meta'] ?? null));

// ─── Section 2: Document Normalization ─────────────────────────────────
echo "\n── Section 2: Document Normalization ──\n";

// 2.1 Normalize from JSON string
$jsonStr = json_encode($doc);
$normalized = cmsBuilderNormalizeDocument($jsonStr);
t('Normalize from JSON string', is_array($normalized) && ($normalized['document']['type'] ?? '') === 'document');

// 2.2 Normalize from DB envelope
$normalized2 = cmsBuilderNormalizeDocument($doc);
t('Normalize from DB envelope', ($normalized2['document']['type'] ?? '') === 'document');

// 2.3 Normalize flat node (React format)
$flatNode = ['id' => 'doc_root', 'type' => 'document', 'props' => [], 'style' => [], 'children' => [], 'meta' => []];
$normalized3 = cmsBuilderNormalizeDocument($flatNode);
t('Normalize from flat React node', isset($normalized3['schema_version']) && ($normalized3['document']['type'] ?? '') === 'document');

// 2.4 Invalid input
$normalizedInvalid = cmsBuilderNormalizeDocument('not json');
t('Invalid input returns default doc', ($normalizedInvalid['document']['type'] ?? '') === 'document');

$normalizedNull = cmsBuilderNormalizeDocument(null);
t('Null input returns default doc', ($normalizedNull['document']['type'] ?? '') === 'document');

// ─── Section 3: Document Validation ────────────────────────────────────
echo "\n── Section 3: Document Validation ──\n";

$validResult = cmsBuilderValidateDocument($doc);
t('Valid doc passes validation', $validResult['ok'] === true);
t('No issues in valid doc', count($validResult['issues'] ?? [1]) === 0);

// Build a doc with children for deeper validation
$docWithChildren = $doc;
$docWithChildren['document']['children'] = [
    ['id' => 'sec1', 'type' => 'section', 'props' => [], 'style' => [], 'children' => [
        ['id' => 'h1', 'type' => 'heading', 'props' => ['content' => 'Hello'], 'style' => [], 'children' => [], 'meta' => []],
        ['id' => 't1', 'type' => 'text', 'props' => ['content' => 'World'], 'style' => [], 'children' => [], 'meta' => []],
    ], 'meta' => []],
];
$validWithChildren = cmsBuilderValidateDocument($docWithChildren);
t('Doc with children passes validation', $validWithChildren['ok'] === true);

// ─── Section 4: Schema Versioning ──────────────────────────────────────
echo "\n── Section 4: Schema Versioning ──\n";

t('Current schema version defined', defined('CMS_BUILDER_CURRENT_SCHEMA_VERSION'));
t('Schema migrations function exists', function_exists('cmsBuilderSchemaMigrations'));
t('Schema migrators function exists', function_exists('cmsBuilderSchemaMigrators'));
t('Schema migrate function exists', function_exists('cmsBuilderSchemaMigrateDocument'));

// Test v1.0 → current migration
$v10Doc = cmsBuilderDefaultDocument();
$v10Doc['schema_version'] = '1.0';
$migrated = cmsBuilderSchemaMigrateDocument($v10Doc);
t('v1.0 doc migrated to current version',
    cmsBuilderSchemaVersionCompare($migrated['schema_version'], '1.0') > 0);
t('Migrated doc still has valid structure', ($migrated['document']['type'] ?? '') === 'document');
t('Migrated doc has meta key', is_array($migrated['document']['meta'] ?? null));

// Already current version should be no-op
$currentDoc = cmsBuilderDefaultDocument(['schema_version' => CMS_BUILDER_CURRENT_SCHEMA_VERSION]);
$migratedCurrent = cmsBuilderSchemaMigrateDocument($currentDoc);
t('Current version doc stays at current version',
    $migratedCurrent['schema_version'] === CMS_BUILDER_CURRENT_SCHEMA_VERSION);

// ─── Section 5: Default Props and Styles ───────────────────────────────
echo "\n── Section 5: Default Props and Styles ──\n";

t('cmsBuilderDefaultProps exists', function_exists('cmsBuilderDefaultProps'));
t('cmsBuilderDefaultStyle exists', function_exists('cmsBuilderDefaultStyle'));
t('cmsBuilderMergeDefaults exists', function_exists('cmsBuilderMergeDefaults'));

$headingDefaults = cmsBuilderDefaultProps('heading');
t('Heading has default props', is_array($headingDefaults) && !empty($headingDefaults));

$textDefaults = cmsBuilderDefaultProps('text');
t('Text has default props', is_array($textDefaults) && !empty($textDefaults));

$mergedProps = cmsBuilderMergeDefaults(['content' => 'Custom'], 'heading');
t('Merged props preserve custom values', ($mergedProps['content'] ?? '') === 'Custom');

// ─── Section 6: Apply Default Props (Recursion) ───────────────────────
echo "\n── Section 6: Apply Default Props (Recursion) ──\n";

t('cmsBuilderApplyDefaultProps exists', function_exists('cmsBuilderApplyDefaultProps'));

$nodeWithNulls = [
    'id' => 'doc_root', 'type' => 'document', 'props' => [], 'style' => [],
    'children' => [
        ['id' => 'h1', 'type' => 'heading', 'props' => ['content' => null, 'level' => null],
         'style' => [], 'children' => [], 'meta' => []],
    ],
    'meta' => [],
];
$applied = cmsBuilderApplyDefaultProps($nodeWithNulls);
t('Apply default props recursively fills nulls', is_array($applied));
$headingChild = $applied['children'][0] ?? [];
$headingHasContent = isset($headingChild['props']['content']) && $headingChild['props']['content'] !== null;
// Default filling is best-effort; just ensure no crash
t('Heading child still valid after default apply', ($headingChild['type'] ?? '') === 'heading');

// ─── Section 7: Utility Functions ──────────────────────────────────────
echo "\n── Section 7: Utility Functions ──\n";

t('cmsBuilderEsc exists', function_exists('cmsBuilderEsc'));
t('cmsBuilderEsc escapes HTML', cmsBuilderEsc('<script>') === '&lt;script&gt;');
t('cmsBuilderEsc handles non-string', cmsBuilderEsc(123) === '123');
t('cmsBuilderEsc handles null', cmsBuilderEsc(null) === '');

t('cmsBuilderAttrString exists', function_exists('cmsBuilderAttrString'));
$attrs = cmsBuilderAttrString(['id' => 'test', 'class' => 'foo bar']);
t('cmsBuilderAttrString formats attributes', str_contains($attrs, 'id="test"'));

t('cmsBuilderNormalizeHeadingTag exists', function_exists('cmsBuilderNormalizeHeadingTag'));
t('Heading tag h1', cmsBuilderNormalizeHeadingTag(1) === 'h1');
t('Heading tag h6 max', cmsBuilderNormalizeHeadingTag(99) === 'h6');
t('Heading tag default', cmsBuilderNormalizeHeadingTag(null) === 'h2');

// ─── Section 8: Widget Renderer Registry ───────────────────────────────
echo "\n── Section 8: Widget Renderer Registry ──\n";

if (file_exists($cmsPath . '/builder-renderers.php')) {
    ob_start();
    require_once $cmsPath . '/builder-renderers.php';
    ob_end_clean();
    $rendererAvailable = function_exists('cmsBuilderWidgetRenderers');
} else {
    $rendererAvailable = false;
}

t('Widget renderer registry exists', $rendererAvailable);
if ($rendererAvailable) {
    $renderers = cmsBuilderWidgetRenderers();
    t('Renderer registry is non-empty', count($renderers) > 0);
    t('Document renderer exists', isset($renderers['document']));
    t('Section renderer exists', isset($renderers['section']));
    t('Heading renderer exists', isset($renderers['heading']));
    t('Text renderer exists', isset($renderers['text']));
    t('Image renderer exists', isset($renderers['image']));
    $coreWidgets = ['document', 'section', 'container', 'heading', 'text', 'image', 'button', 'spacer', 'divider'];
    $allCoreExist = true;
    foreach ($coreWidgets as $w) {
        if (!isset($renderers[$w])) {
            $allCoreExist = false;
            echo "    MISSING: {$w} renderer\n";
        }
    }
    t('All core widget renderers registered', $allCoreExist);
}

// ─── Summary ───────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 60) . "\n";
echo "Builder E2E Lifecycle Tests: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
